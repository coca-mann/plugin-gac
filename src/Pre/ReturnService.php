<?php

/**
 * -------------------------------------------------------------------------
 * Gac plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * MIT License
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2026 by the Gac plugin team.
 * @license   MIT https://opensource.org/licenses/mit-license.php
 * @link      https://github.com/coca-mann/plugin-gac
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Gac\Pre;

use Ticket;
use Toolbox;

/**
 * Return registration per line (spec 7.4): apply the configured ticket and asset actions,
 * store the cost, mark the line, recalculate the PRE.
 */
final class ReturnService
{
    /**
     * @param array<string, mixed> $data
     * @param list<array{name: string, tmp_name: string, error: int}> $files uploads from ReturnAttachments::collect()
     */
    public static function registerReturn(int $lineId, array $data, array $files = []): ServiceResult
    {
        global $DB;

        [$line, $protocol] = self::load($lineId);
        if ($line === null) {
            return ServiceResult::fail(__('Linha não encontrada.', 'gac'));
        }
        if (!StateMachine::canRegisterReturn($protocol->getStatus(), $line->getStatus())) {
            return ServiceResult::fail(__('Esta linha não está na assistência.', 'gac'));
        }

        $outcome = Outcome::tryFrom((string) ($data['outcome'] ?? ''));
        if ($outcome === null) {
            return ServiceResult::fail(__('Informe o resultado.', 'gac'));
        }
        try {
            $destination = StateMachine::normalizeDestination(
                $outcome,
                Destination::tryFrom((string) ($data['destination'] ?? ''))
            );
        } catch (\InvalidArgumentException) {
            return ServiceResult::fail($outcome->isDefective()
                ? __('Informe o destino do equipamento com defeito.', 'gac')
                : __('Este resultado não admite destino.', 'gac'));
        }

        $dateReturn = self::date((string) ($data['date_return'] ?? '')) ?? date('Y-m-d');
        $warranty   = self::date((string) ($data['warranty_until'] ?? ''));
        $cost       = self::cost((string) ($data['cost'] ?? ''));
        if ($cost === false) {
            return ServiceResult::fail(__('Custo inválido.', 'gac'));
        }

        $settings  = PreConfig::load();
        $actionKey = ReturnActionResolver::actionKey($outcome, $destination);
        try {
            $actions = ReturnActionResolver::resolve($actionKey, $settings);
        } catch (\DomainException $e) {
            return ServiceResult::fail($e->getMessage());
        }
        if (
            $actions['asset']['type'] === 'set_state'
            && !StateGuard::isUsable($actions['asset']['states_id'], (int) $line->fields['item_entities_id'])
        ) {
            return ServiceResult::fail(__('O status configurado não é válido para a entidade do ativo.', 'gac'));
        }

        $summary = self::summary($outcome, $destination, $dateReturn, (string) ($data['service_description'] ?? ''), $cost, (string) ($data['supplier_ref'] ?? ''), $warranty);

        try {
            $DB->beginTransaction();

            $ticket = new Ticket();
            if (!$ticket->getFromDB((int) $line->fields['tickets_id'])) {
                throw new \RuntimeException(__('Ticket não encontrado.', 'gac'));
            }
            $asset = getItemForItemtype($line->fields['itemtype']);
            if (!$asset || !$asset->getFromDB((int) $line->fields['items_id'])) {
                throw new \RuntimeException(__('Ativo não encontrado.', 'gac'));
            }

            // Asset status
            $newState = $actions['asset']['type'] === 'restore_previous'
                ? (int) ($line->fields['states_id_before'] ?? 0)
                : $actions['asset']['states_id'];
            if ((int) $asset->fields['states_id'] !== $newState) {
                if (!$asset->update(['id' => $asset->getID(), 'states_id' => $newState])) {
                    throw new \RuntimeException(__('Não foi possível alterar o status do ativo.', 'gac'));
                }
            }

            // Ticket action. While another line of the same ticket is still out (another asset
            // of a multi-asset ticket), the ticket keeps its status and reason: only the
            // summary follow-up is written. The last line to come back decides the status.
            if (self::hasOtherActiveLines($line)) {
                TicketOps::followup($ticket, $summary);
            } else {
                match ($actions['ticket']['type']) {
                    'reopen'       => TicketOps::leavePending($ticket, $summary),
                    'solve'        => TicketOps::solve($ticket, $summary),
                    'keep_pending' => TicketOps::keepPendingWithReason($ticket, $actions['ticket']['pendingreasons_id'], $summary),
                };
            }

            $costId = 0;
            if ($cost !== null && $cost > 0) {
                $costId = TicketOps::addCost(
                    $ticket,
                    CostLabel::name(
                        (string) $protocol->fields['supplier_name'],
                        (string) ($data['supplier_ref'] ?? ''),
                        (string) $line->fields['item_name']
                    ),
                    $cost,
                    $dateReturn
                );
            }

            $DB->update(
                RepairProtocolItem::getTable(),
                [
                    'status'              => ItemStatus::Returned->value,
                    'outcome'             => $outcome->value,
                    'destination'         => $destination->value,
                    'date_return'         => $dateReturn,
                    'service_description' => trim((string) ($data['service_description'] ?? '')),
                    'cost'                => $cost,
                    'supplier_ref'        => trim((string) ($data['supplier_ref'] ?? '')),
                    'warranty_until'      => $warranty,
                    'ticketcosts_id'      => $costId,
                    'last_error'          => null,
                    'date_mod'            => $_SESSION['glpi_currenttime'],
                ],
                ['id' => $lineId]
            );

            RepairProtocolEvent::log(
                (int) $protocol->getID(),
                'line_returned',
                '',
                ['outcome' => $outcome->value, 'destination' => $destination->value],
                $lineId
            );

            self::recalc($protocol);

            $DB->commit();
        } catch (\Throwable $e) {
            try {
                $DB->rollBack();
            } catch (\Throwable) {
                // not in a transaction
            }
            Toolbox::logInFile('gac', sprintf("registerReturn %d failed: %s\n", $lineId, $e->getMessage()));
            return ServiceResult::fail($e->getMessage());
        }

        // After the commit: a rejected file must not undo a return that already changed the ticket.
        $problems = $files === [] ? [] : ReturnAttachments::attach($files, $protocol, $line, $ticket);
        if ($problems !== []) {
            return ServiceResult::ok(
                __('Retorno registrado, mas nem todos os documentos foram anexados:', 'gac') . ' ' . implode(' ', $problems)
            );
        }

        return ServiceResult::ok(__('Retorno registrado.', 'gac'));
    }

    public static function markLost(int $lineId, string $reason): ServiceResult
    {
        global $DB;

        $reason = trim($reason);
        if ($reason === '') {
            return ServiceResult::fail(__('Informe a justificativa do extravio.', 'gac'));
        }
        [$line, $protocol] = self::load($lineId);
        if ($line === null) {
            return ServiceResult::fail(__('Linha não encontrada.', 'gac'));
        }
        if (!StateMachine::canRegisterReturn($protocol->getStatus(), $line->getStatus())) {
            return ServiceResult::fail(__('Esta linha não está na assistência.', 'gac'));
        }

        try {
            $DB->beginTransaction();

            $ticket = new Ticket();
            if (!$ticket->getFromDB((int) $line->fields['tickets_id'])) {
                throw new \RuntimeException(__('Ticket não encontrado.', 'gac'));
            }
            // Plain follow-up: the ticket stays as it is (pending).
            TicketOps::followup($ticket, sprintf(
                __('Equipamento %1$s marcado como extraviado no fornecedor (protocolo %2$s). Justificativa: %3$s', 'gac'),
                $line->fields['item_name'],
                $protocol->fields['number'],
                $reason
            ));

            $DB->update(
                RepairProtocolItem::getTable(),
                [
                    'status'      => ItemStatus::Lost->value,
                    'lost_reason' => $reason,
                    'date_mod'    => $_SESSION['glpi_currenttime'],
                ],
                ['id' => $lineId]
            );
            RepairProtocolEvent::log((int) $protocol->getID(), 'line_lost', $reason, [], $lineId);
            self::recalc($protocol);

            $DB->commit();
        } catch (\Throwable $e) {
            try {
                $DB->rollBack();
            } catch (\Throwable) {
                // not in a transaction
            }
            Toolbox::logInFile('gac', sprintf("markLost %d failed: %s\n", $lineId, $e->getMessage()));
            return ServiceResult::fail($e->getMessage());
        }

        return ServiceResult::ok(__('Linha marcada como extraviada.', 'gac'));
    }

    /** True when the same ticket still has another active line (in any PRE). */
    private static function hasOtherActiveLines(RepairProtocolItem $line): bool
    {
        return countElementsInTable(RepairProtocolItem::getTable(), [
            'tickets_id' => (int) $line->fields['tickets_id'],
            'status'     => [
                ItemStatus::PendingSend->value,
                ItemStatus::Sending->value,
                ItemStatus::AtSupplier->value,
            ],
            ['NOT' => ['id' => (int) $line->getID()]],
        ]) > 0;
    }

    /** Recomputes the PRE status from its lines; closing is automatic (spec D9). */
    public static function recalc(RepairProtocol $p): void
    {
        $p->getFromDB((int) $p->getID());
        $statuses = array_map(
            static fn(array $l): ItemStatus => ItemStatus::from($l['status']),
            $p->lines()
        );
        $new = StateMachine::deriveProtocolStatus($p->getStatus(), $statuses);
        if ($new === $p->getStatus()) {
            return;
        }
        if ($new === ProtocolStatus::Closed) {
            $p->changeStatus($new, ['date_closed' => $_SESSION['glpi_currenttime']]);
            RepairProtocolEvent::log((int) $p->getID(), 'closed');
            return;
        }
        $p->changeStatus($new);
    }

    /** @return array{0: ?RepairProtocolItem, 1: ?RepairProtocol} */
    private static function load(int $lineId): array
    {
        $line = new RepairProtocolItem();
        if (!$line->getFromDB($lineId)) {
            return [null, null];
        }
        $protocol = new RepairProtocol();
        $protocol->getFromDB((int) $line->fields['plugin_gac_repairprotocols_id']);
        return [$line, $protocol];
    }

    private static function date(string $value): ?string
    {
        $value = trim($value);
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        return ($d !== false && $d->format('Y-m-d') === $value) ? $value : null;
    }

    /** @return float|null|false null = empty, false = invalid */
    private static function cost(string $value): float|null|false
    {
        $value = trim(str_replace(',', '.', $value));
        if ($value === '') {
            return null;
        }
        return (is_numeric($value) && (float) $value >= 0) ? (float) $value : false;
    }

    private static function summary(
        Outcome $outcome,
        Destination $destination,
        string $date,
        string $service,
        ?float $cost,
        string $ref,
        ?string $warranty
    ): string {
        $parts = [
            sprintf(__('Retorno da assistência: %s.', 'gac'), Labels::outcome($outcome)),
            sprintf(__('Data: %s.', 'gac'), $date),
        ];
        if ($destination !== Destination::None) {
            $parts[] = sprintf(__('Destino: %s.', 'gac'), Labels::destination($destination));
        }
        if (trim($service) !== '') {
            $parts[] = sprintf(__('Serviço: %s.', 'gac'), trim($service));
        }
        if ($cost !== null) {
            $parts[] = sprintf(__('Custo: R$ %s.', 'gac'), number_format($cost, 2, ',', '.'));
        }
        if (trim($ref) !== '') {
            $parts[] = sprintf(__('OS/Nota do fornecedor: %s.', 'gac'), trim($ref));
        }
        if ($warranty !== null) {
            $parts[] = sprintf(__('Garantia até: %s.', 'gac'), $warranty);
        }
        return implode(' ', $parts);
    }
}
