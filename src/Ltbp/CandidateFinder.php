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

namespace GlpiPlugin\Gac\Ltbp;

use GlpiPlugin\Gac\Pre\Destination as PreDestination;
use GlpiPlugin\Gac\Pre\ItemStatus as PreItemStatus;
use GlpiPlugin\Gac\Pre\Labels as PreLabels;
use GlpiPlugin\Gac\Pre\Outcome as PreOutcome;
use GlpiPlugin\Gac\Pre\RepairProtocol;
use GlpiPlugin\Gac\Pre\RepairProtocolItem;

/**
 * Assets that can enter a laudo (spec L7): the ones in the "Aguardando baixa" status, in the
 * laudo's entity or its sub-entities, that are in no laudo (not canceled) and in no active PRE
 * line. Each candidate carries where it came from in the PRE, when it did (spec L23).
 * This module only reads the PRE tables (spec L22).
 */
final class CandidateFinder
{
    /** @return list<array<string, mixed>> */
    public static function find(Ltbp $laudo): array
    {
        global $DB;

        $stateId = LtbpSettings::stateId(LtbpConfig::load(), 'awaiting_writeoff');
        if ($stateId <= 0) {
            return [];
        }

        $entityIds = array_values(getSonsOf('glpi_entities', (int) $laudo->fields['entities_id']));
        $held      = self::heldKeys();

        $rows = [];
        foreach (AssetTypes::all() as $itemtype) {
            $asset = getItemForItemtype($itemtype);
            if (!$asset) {
                continue;
            }
            $table = $asset::getTable();
            if (!$DB->tableExists($table) || !$DB->fieldExists($table, 'states_id')) {
                continue;
            }

            $where = ['states_id' => $stateId, 'entities_id' => $entityIds];
            if ($DB->fieldExists($table, 'is_deleted')) {
                $where['is_deleted'] = 0;
            }
            if ($DB->fieldExists($table, 'is_template')) {
                $where['is_template'] = 0;
            }

            foreach ($DB->request(['FROM' => $table, 'WHERE' => $where, 'ORDER' => ['name ASC']]) as $r) {
                $key = $itemtype . '|' . $r['id'];
                if (isset($held[$key])) {
                    continue;
                }
                $rows[$key] = [
                    'key'                 => $key,
                    'itemtype'            => $itemtype,
                    'items_id'            => (int) $r['id'],
                    'name'                => (string) ($r['name'] ?? ''),
                    'type_label'          => $asset::getTypeName(1),
                    'serial'              => (string) ($r['serial'] ?? ''),
                    'otherserial'         => (string) ($r['otherserial'] ?? ''),
                    'pre_items_id'        => 0,
                    'pre_number'          => '',
                    'tickets_id'          => 0,
                    'outcome'             => '',
                    'outcome_label'       => '',
                    'service_description' => '',
                ];
            }
        }

        self::addOrigin($rows);

        return array_values($rows);
    }

    /** @return array<string, true> keys "<itemtype>|<items_id>" of assets in a laudo (not canceled) or in an active PRE line */
    private static function heldKeys(): array
    {
        global $DB;

        $items  = LtbpItem::getTable();
        $laudos = Ltbp::getTable();
        $held   = [];

        foreach ($DB->request([
            'SELECT'     => ["$items.itemtype", "$items.items_id"],
            'FROM'       => $items,
            'INNER JOIN' => [
                $laudos => ['ON' => [$items => 'plugin_gac_ltbps_id', $laudos => 'id']],
            ],
            'WHERE' => ["$laudos.status" => Status::holdingValues()],
        ]) as $r) {
            $held[$r['itemtype'] . '|' . $r['items_id']] = true;
        }

        foreach ($DB->request([
            'SELECT' => ['itemtype', 'items_id'],
            'FROM'   => RepairProtocolItem::getTable(),
            'WHERE'  => ['status' => [
                PreItemStatus::PendingSend->value,
                PreItemStatus::Sending->value,
                PreItemStatus::AtSupplier->value,
            ]],
        ]) as $r) {
            $held[$r['itemtype'] . '|' . $r['items_id']] = true;
        }

        return $held;
    }

    /**
     * Fills the PRE origin of each candidate: the latest returned line with destination Baixa.
     *
     * @param array<string, array<string, mixed>> $rows
     */
    private static function addOrigin(array &$rows): void
    {
        global $DB;

        if ($rows === []) {
            return;
        }

        $byType = [];
        foreach ($rows as $row) {
            $byType[$row['itemtype']][] = $row['items_id'];
        }

        $preItems     = RepairProtocolItem::getTable();
        $preProtocols = RepairProtocol::getTable();

        foreach ($byType as $itemtype => $ids) {
            foreach ($DB->request([
                'SELECT'     => [
                    "$preItems.id AS pre_item_id",
                    "$preItems.items_id AS asset_id",
                    "$preItems.tickets_id AS ticket_id",
                    "$preItems.outcome AS outcome",
                    "$preItems.service_description AS service_description",
                    "$preProtocols.number AS pre_number",
                ],
                'FROM'       => $preItems,
                'INNER JOIN' => [
                    $preProtocols => ['ON' => [$preItems => 'plugin_gac_repairprotocols_id', $preProtocols => 'id']],
                ],
                'WHERE' => [
                    "$preItems.itemtype"    => $itemtype,
                    "$preItems.items_id"    => $ids,
                    "$preItems.status"      => PreItemStatus::Returned->value,
                    "$preItems.destination" => PreDestination::Writeoff->value,
                ],
                // Ascending: a later line for the same asset overwrites the earlier one.
                'ORDER' => ["$preItems.id ASC"],
            ]) as $r) {
                $key = $itemtype . '|' . $r['asset_id'];
                if (!isset($rows[$key])) {
                    continue;
                }
                $outcome = PreOutcome::tryFrom((string) $r['outcome']);
                $rows[$key]['pre_items_id']        = (int) $r['pre_item_id'];
                $rows[$key]['pre_number']          = (string) $r['pre_number'];
                $rows[$key]['tickets_id']          = (int) $r['ticket_id'];
                $rows[$key]['outcome']             = (string) $r['outcome'];
                $rows[$key]['outcome_label']       = $outcome === null ? '' : PreLabels::outcome($outcome);
                $rows[$key]['service_description'] = (string) ($r['service_description'] ?? '');
            }
        }
    }
}
