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

/**
 * Draft-time line operations: import, edit the supplier description, remove.
 */
final class LineService
{
    /** @param list<string> $selectedKeys keys from EligibleTicketFinder */
    public static function import(RepairProtocol $p, array $selectedKeys): ServiceResult
    {
        if (!StateMachine::canImportLines($p->getStatus())) {
            return ServiceResult::fail(__('Só é possível importar tickets em um PRE em rascunho.', 'gac'));
        }

        // Never trust the client: re-resolve every selected key against the current candidates.
        $candidates = [];
        foreach (EligibleTicketFinder::find($p) as $c) {
            $candidates[$c['key']] = $c;
        }

        $added = 0;
        foreach ($selectedKeys as $key) {
            $c = $candidates[$key] ?? null;
            if ($c === null) {
                continue;
            }
            $id = (new RepairProtocolItem())->add([
                'plugin_gac_repairprotocols_id' => $p->getID(),
                'tickets_id'           => $c['tickets_id'],
                'itemtype'             => $c['itemtype'],
                'items_id'             => $c['items_id'],
                'item_entities_id'     => $c['item_entities_id'],
                'item_name'            => $c['item_name'],
                'item_type_label'      => $c['item_type_label'],
                'serial'               => $c['serial'],
                'otherserial'          => $c['otherserial'],
                'ticket_title'         => $c['ticket_name'],
                'ticket_observation'   => $c['ticket_observation'],
                'description_supplier' => $c['description_supplier'],
                'status'               => ItemStatus::PendingSend->value,
            ]);
            if ($id) {
                $added++;
            }
        }

        return $added > 0
            ? ServiceResult::ok(sprintf(_n('%d linha importada.', '%d linhas importadas.', $added, 'gac'), $added))
            : ServiceResult::fail(__('Nenhum item válido foi selecionado.', 'gac'));
    }

    /** @param array<int, string> $descriptions line id => text */
    public static function saveDescriptions(RepairProtocol $p, array $descriptions): ServiceResult
    {
        global $DB;

        if (!StateMachine::canImportLines($p->getStatus())) {
            return ServiceResult::fail(__('As descrições só podem ser editadas em rascunho.', 'gac'));
        }
        foreach ($descriptions as $lineId => $text) {
            $DB->update(
                RepairProtocolItem::getTable(),
                ['description_supplier' => trim((string) $text), 'date_mod' => $_SESSION['glpi_currenttime']],
                ['id' => (int) $lineId, 'plugin_gac_repairprotocols_id' => $p->getID()]
            );
        }
        return ServiceResult::ok(__('Descrições salvas.', 'gac'));
    }

    public static function removeDraftLine(RepairProtocol $p, int $lineId): ServiceResult
    {
        $line = new RepairProtocolItem();
        if (
            !$line->getFromDB($lineId)
            || (int) $line->fields['plugin_gac_repairprotocols_id'] !== (int) $p->getID()
        ) {
            return ServiceResult::fail(__('Linha não encontrada.', 'gac'));
        }
        $status = $p->getStatus();
        if (
            !StateMachine::canRemoveLine($status, $line->getStatus())
            || StateMachine::removeRequiresReason($status)
        ) {
            return ServiceResult::fail(__('Esta linha não pode ser removida.', 'gac'));
        }
        $line->delete(['id' => $lineId], true);
        return ServiceResult::ok(__('Linha removida.', 'gac'));
    }

    /** Used when a draft PRE is canceled: its lines must stop blocking the ticket+asset pairs. */
    public static function deleteAllLines(RepairProtocol $p): void
    {
        global $DB;
        $DB->delete(RepairProtocolItem::getTable(), ['plugin_gac_repairprotocols_id' => $p->getID()]);
    }
}
