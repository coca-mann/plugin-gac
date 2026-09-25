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
 * "Enviar" (spec 7.3, D15): pre-checks first, then one line per call, then the final step.
 */
final class SendService
{
    private const STALE_SENDING_SECONDS = 300;

    public static function start(RepairProtocol $p): ServiceResult
    {
        if (!StateMachine::canStartSend($p->getStatus())) {
            return ServiceResult::fail(__('Só é possível enviar um PRE em rascunho.', 'gac'));
        }
        if ((int) $p->fields['suppliers_id'] === 0) {
            return ServiceResult::fail(__('Defina o fornecedor antes de enviar.', 'gac'));
        }

        $lines = $p->lines();
        if ($lines === []) {
            return ServiceResult::fail(__('O PRE não tem itens.', 'gac'));
        }

        $settings = PreConfig::load();
        $missing  = PreSettings::missingRolesForSend($settings);
        if ($missing !== []) {
            return ServiceResult::fail(
                sprintf(__('Configuração incompleta: %s.', 'gac'), implode(', ', $missing))
            );
        }

        $errors = self::preCheckLines($p, $lines, $settings);
        if ($errors !== []) {
            return ServiceResult::fail(implode(' ', $errors));
        }

        $p->changeStatus(ProtocolStatus::Sent, ['date_sent' => $_SESSION['glpi_currenttime']]);
        RepairProtocolEvent::log((int) $p->getID(), 'sent');

        return ServiceResult::ok('', ['line_ids' => array_map(static fn(array $l): int => (int) $l['id'], $lines)]);
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @param array<string, string>      $settings
     * @return list<string>
     */
    private static function preCheckLines(RepairProtocol $p, array $lines, array $settings): array
    {
        $errors     = [];
        $stateId    = PreSettings::stateId($settings, 'at_supplier');
        $reasonId   = PreSettings::reasonId($settings, 'at_supplier');
        $activeElse = self::activeElsewhere((int) $p->getID());

        foreach ($lines as $line) {
            $label = sprintf('#%d (%s)', $line['tickets_id'], $line['item_name']);

            $ticket = new Ticket();
            if (!$ticket->getFromDB((int) $line['tickets_id'])) {
                $errors[] = sprintf(__('Ticket %s não existe mais.', 'gac'), $label);
                continue;
            }
            if (in_array((int) $ticket->fields['status'], [Ticket::SOLVED, Ticket::CLOSED], true)) {
                $errors[] = sprintf(__('Ticket %s já está solucionado ou fechado.', 'gac'), $label);
            }
            if (!StateGuard::isReasonUsable($reasonId, (int) $ticket->fields['entities_id'])) {
                $errors[] = sprintf(
                    __('O motivo de pendência "Ticket na assistência" não é válido para a entidade do ticket %s. Crie-o na entidade raiz com recursividade.', 'gac'),
                    $label
                );
            }

            $asset = getItemForItemtype($line['itemtype']);
            if (!$asset || !$asset->getFromDB((int) $line['items_id'])) {
                $errors[] = sprintf(__('O ativo do ticket %s não existe mais.', 'gac'), $label);
            }

            if (!StateGuard::isUsable($stateId, (int) $line['item_entities_id'])) {
                $errors[] = sprintf(
                    __('O status "Ativo na assistência" não é válido para a entidade do ativo do ticket %s. Crie-o na entidade raiz com recursividade.', 'gac'),
                    $label
                );
            }

            if (isset($activeElse[$line['tickets_id'] . '|' . $line['itemtype'] . '|' . $line['items_id']])) {
                $errors[] = sprintf(__('O par ticket/ativo %s já está em outro PRE ativo.', 'gac'), $label);
            }
        }
        return array_values(array_unique($errors));
    }

    /** @return array<string, true> */
    private static function activeElsewhere(int $protocolId): array
    {
        global $DB;

        $active = [];
        foreach ($DB->request([
            'SELECT' => ['tickets_id', 'itemtype', 'items_id'],
            'FROM'   => RepairProtocolItem::getTable(),
            'WHERE'  => [
                'status' => [ItemStatus::PendingSend->value, ItemStatus::Sending->value, ItemStatus::AtSupplier->value],
                ['NOT' => ['plugin_gac_repairprotocols_id' => $protocolId]],
            ],
        ]) as $r) {
            $active[$r['tickets_id'] . '|' . $r['itemtype'] . '|' . $r['items_id']] = true;
        }
        return $active;
    }

    public static function sendLine(int $lineId): ServiceResult
    {
        global $DB;

        $line = new RepairProtocolItem();
        if (!$line->getFromDB($lineId)) {
            return ServiceResult::fail(__('Linha não encontrada.', 'gac'));
        }
        $protocol = new RepairProtocol();
        $protocol->getFromDB((int) $line->fields['plugin_gac_repairprotocols_id']);

        self::recoverStale((int) $protocol->getID());
        $line->getFromDB($lineId);

        // Idempotent: a line that already went out is not an error.
        if ($line->getStatus() === ItemStatus::AtSupplier || $line->getStatus() === ItemStatus::Sending) {
            return ServiceResult::ok(__('Linha já enviada ou em envio.', 'gac'));
        }
        if (!StateMachine::canSendLine($protocol->getStatus(), $line->getStatus())) {
            return ServiceResult::fail(__('Esta linha não pode ser enviada.', 'gac'));
        }

        // Atomic claim: only one caller flips pending_send -> sending.
        $DB->update(
            RepairProtocolItem::getTable(),
            ['status' => ItemStatus::Sending->value, 'last_error' => null, 'date_mod' => $_SESSION['glpi_currenttime']],
            ['id' => $lineId, 'status' => ItemStatus::PendingSend->value]
        );
        if ($DB->affectedRows() !== 1) {
            return ServiceResult::ok(__('Linha já está sendo processada.', 'gac'));
        }

        $settings = PreConfig::load();
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

            $before = (int) $asset->fields['states_id'];
            if (!$asset->update(['id' => $asset->getID(), 'states_id' => PreSettings::stateId($settings, 'at_supplier')])) {
                throw new \RuntimeException(__('Não foi possível alterar o status do ativo.', 'gac'));
            }

            TicketOps::followup(
                $ticket,
                sprintf(
                    __('Equipamento %1$s enviado à assistência técnica do fornecedor %2$s. Protocolo %3$s.', 'gac'),
                    $line->fields['item_name'],
                    $protocol->fields['supplier_name'],
                    $protocol->fields['number']
                ),
                PreSettings::reasonId($settings, 'at_supplier')
            );

            $DB->update(
                RepairProtocolItem::getTable(),
                [
                    'status'           => ItemStatus::AtSupplier->value,
                    'states_id_before' => $before,
                    'last_error'       => null,
                    'date_mod'         => $_SESSION['glpi_currenttime'],
                ],
                ['id' => $lineId]
            );

            $DB->commit();
        } catch (\Throwable $e) {
            try {
                $DB->rollBack();
            } catch (\Throwable) {
                // not in a transaction: nothing to undo
            }
            $DB->update(
                RepairProtocolItem::getTable(),
                [
                    'status'     => ItemStatus::PendingSend->value,
                    'last_error' => mb_substr($e->getMessage(), 0, 1000),
                    'date_mod'   => $_SESSION['glpi_currenttime'],
                ],
                ['id' => $lineId]
            );
            Toolbox::logInFile('gac', sprintf("sendLine %d failed: %s\n", $lineId, $e->getMessage()));
            return ServiceResult::fail($e->getMessage());
        }

        return ServiceResult::ok();
    }

    /** A line stuck in "sending" (interrupted request) goes back to "pending_send". */
    private static function recoverStale(int $protocolId): void
    {
        global $DB;

        $limit = date('Y-m-d H:i:s', time() - self::STALE_SENDING_SECONDS);
        $DB->update(
            RepairProtocolItem::getTable(),
            ['status' => ItemStatus::PendingSend->value],
            [
                'plugin_gac_repairprotocols_id' => $protocolId,
                'status'   => ItemStatus::Sending->value,
                'date_mod' => ['<', $limit],
            ]
        );
    }

    /** Last step: every line is at the supplier; generate and attach the definitive PDF (D11). */
    public static function finalize(RepairProtocol $p): ServiceResult
    {
        if ($p->getStatus() !== ProtocolStatus::Sent) {
            return ServiceResult::fail(__('O PRE não está em envio.', 'gac'));
        }
        // Idempotent: the document already exists.
        if ((int) $p->fields['documents_id_sent'] > 0) {
            return ServiceResult::ok();
        }

        $pending = 0;
        foreach ($p->lines() as $line) {
            if ($line['status'] !== ItemStatus::AtSupplier->value) {
                $pending++;
            }
        }
        if ($pending > 0) {
            return ServiceResult::fail(sprintf(
                _n('Ainda há %d linha pendente.', 'Ainda há %d linhas pendentes.', $pending, 'gac'),
                $pending
            ));
        }

        try {
            $documentId = PdfRenderer::attachFinal($p);
        } catch (\Throwable $e) {
            Toolbox::logInFile('gac', sprintf("finalize %d failed: %s\n", $p->getID(), $e->getMessage()));
            return ServiceResult::fail(__('Não foi possível gerar o PDF. Tente "Concluir envio" novamente.', 'gac'));
        }

        $p->changeStatus(ProtocolStatus::Sent, ['documents_id_sent' => $documentId]);
        RepairProtocolEvent::log((int) $p->getID(), 'send_finalized');

        return ServiceResult::ok();
    }

    /** "Remover linha com falha" (spec 6.2): only a pending_send line of a PRE already sent. */
    public static function removeFailedLine(int $lineId, string $reason): ServiceResult
    {
        $reason = trim($reason);
        if ($reason === '') {
            return ServiceResult::fail(__('Informe o motivo da remoção.', 'gac'));
        }
        $line = new RepairProtocolItem();
        if (!$line->getFromDB($lineId)) {
            return ServiceResult::fail(__('Linha não encontrada.', 'gac'));
        }
        $protocol = new RepairProtocol();
        $protocol->getFromDB((int) $line->fields['plugin_gac_repairprotocols_id']);

        if (
            !StateMachine::canRemoveLine($protocol->getStatus(), $line->getStatus())
            || !StateMachine::removeRequiresReason($protocol->getStatus())
        ) {
            return ServiceResult::fail(__('Esta linha não pode ser removida.', 'gac'));
        }

        RepairProtocolEvent::log(
            (int) $protocol->getID(),
            'line_removed',
            $reason,
            ['tickets_id' => $line->fields['tickets_id'], 'item' => $line->fields['item_name']],
            $lineId
        );
        $line->delete(['id' => $lineId], true);

        // A PRE left with no lines never sent anything: it is canceled (plan decision 9).
        if ($protocol->lines() === []) {
            $protocol->changeStatus(ProtocolStatus::Canceled);
            RepairProtocolEvent::log((int) $protocol->getID(), 'canceled', __('Todas as linhas foram removidas.', 'gac'));
        }

        return ServiceResult::ok(__('Linha removida.', 'gac'));
    }
}
