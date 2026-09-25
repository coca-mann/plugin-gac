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
use TicketCost;
use Toolbox;

/**
 * Reopening a closed PRE (spec 7.5, D9). Reopening only unlocks the correction of the return
 * data; it never redoes the ticket or asset actions. The PRE stays "Retorno parcial" until
 * "Concluir correções" (plan decision 3).
 */
final class ReopenService
{
    public static function reopen(RepairProtocol $p, string $reason): ServiceResult
    {
        $reason = trim($reason);
        if ($reason === '') {
            return ServiceResult::fail(__('Informe o motivo da reabertura.', 'gac'));
        }
        if (!StateMachine::canReopen($p->getStatus())) {
            return ServiceResult::fail(__('Só é possível reabrir um PRE encerrado.', 'gac'));
        }

        $p->changeStatus(ProtocolStatus::Partial, ['date_closed' => null]);
        RepairProtocolEvent::log((int) $p->getID(), 'reopened', $reason);

        return ServiceResult::ok(__('PRE reaberto para correção.', 'gac'));
    }

    /** @param array<string, mixed> $data */
    public static function correctLine(int $lineId, array $data): ServiceResult
    {
        global $DB;

        $line = new RepairProtocolItem();
        if (!$line->getFromDB($lineId)) {
            return ServiceResult::fail(__('Linha não encontrada.', 'gac'));
        }
        $protocol = new RepairProtocol();
        $protocol->getFromDB((int) $line->fields['plugin_gac_repairprotocols_id']);

        if (
            $protocol->getStatus() !== ProtocolStatus::Partial
            || !RepairProtocolEvent::isReopened((int) $protocol->getID())
            || $line->getStatus() !== ItemStatus::Returned
        ) {
            return ServiceResult::fail(__('Esta linha só pode ser corrigida em um PRE reaberto.', 'gac'));
        }

        $date = self::date((string) ($data['date_return'] ?? '')) ?? (string) $line->fields['date_return'];
        $warranty = self::date((string) ($data['warranty_until'] ?? ''));
        $costRaw = trim(str_replace(',', '.', (string) ($data['cost'] ?? '')));
        if ($costRaw !== '' && (!is_numeric($costRaw) || (float) $costRaw < 0)) {
            return ServiceResult::fail(__('Custo inválido.', 'gac'));
        }
        $cost = $costRaw === '' ? null : (float) $costRaw;

        $after = [
            'date_return'         => $date,
            'service_description' => trim((string) ($data['service_description'] ?? '')),
            'cost'                => $cost,
            'supplier_ref'        => trim((string) ($data['supplier_ref'] ?? '')),
            'warranty_until'      => $warranty,
        ];
        $before = array_intersect_key($line->fields, $after);

        try {
            $DB->beginTransaction();

            // Keep the ticket cost in sync (spec 7.5).
            $costId = (int) $line->fields['ticketcosts_id'];
            $ticket = new Ticket();
            $ticket->getFromDB((int) $line->fields['tickets_id']);
            // The cost name carries the OS/NF number (D18), so a corrected number renames it.
            $costName = CostLabel::name(
                (string) $protocol->fields['supplier_name'],
                $after['supplier_ref'],
                (string) $line->fields['item_name']
            );
            if ($costId > 0) {
                $ok = (new TicketCost())->update([
                    'id'         => $costId,
                    'name'       => $costName,
                    'cost_fixed' => $cost ?? 0,
                    'begin_date' => $date,
                    'end_date'   => $date,
                ]);
                if (!$ok) {
                    throw new \RuntimeException(__('Não foi possível atualizar o custo do ticket.', 'gac'));
                }
            } elseif ($cost !== null && $cost > 0) {
                $costId = TicketOps::addCost($ticket, $costName, $cost, $date);
            }

            $DB->update(
                RepairProtocolItem::getTable(),
                $after + ['ticketcosts_id' => $costId, 'date_mod' => $_SESSION['glpi_currenttime']],
                ['id' => $lineId]
            );

            RepairProtocolEvent::log(
                (int) $protocol->getID(),
                'line_corrected',
                '',
                ['before' => $before, 'after' => $after],
                $lineId
            );

            $DB->commit();
        } catch (\Throwable $e) {
            try {
                $DB->rollBack();
            } catch (\Throwable) {
                // not in a transaction
            }
            Toolbox::logInFile('gac', sprintf("correctLine %d failed: %s\n", $lineId, $e->getMessage()));
            return ServiceResult::fail($e->getMessage());
        }

        return ServiceResult::ok(__('Dados corrigidos.', 'gac'));
    }

    public static function finishCorrections(RepairProtocol $p): ServiceResult
    {
        if (
            $p->getStatus() !== ProtocolStatus::Partial
            || !RepairProtocolEvent::isReopened((int) $p->getID())
        ) {
            return ServiceResult::fail(__('O PRE não está em correção.', 'gac'));
        }
        ReturnService::recalc($p);
        return ServiceResult::ok(__('Correções concluídas. PRE encerrado.', 'gac'));
    }

    private static function date(string $value): ?string
    {
        $value = trim($value);
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        return ($d !== false && $d->format('Y-m-d') === $value) ? $value : null;
    }
}
