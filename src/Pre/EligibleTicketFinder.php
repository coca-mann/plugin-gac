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

use Glpi\RichText\RichText;
use Ticket;

/**
 * Tickets (and their assets) that can enter a PRE (spec D2): category in the configured list
 * (with subcategories), ticket not solved/closed and not deleted, at least one linked asset,
 * ticket in the PRE's entity or its sub-entities, and the ticket+asset pair without an active
 * line in any PRE. Only linked items that are assets count (not, for instance, Forms answers).
 */
final class EligibleTicketFinder
{
    /** @return list<array<string, mixed>> */
    public static function find(RepairProtocol $protocol): array
    {
        global $DB;

        $settings    = PreConfig::load();
        $categoryIds = self::categoryScope($settings);
        if ($categoryIds === []) {
            return [];
        }

        $entityIds  = array_values(getSonsOf('glpi_entities', (int) $protocol->fields['entities_id']));
        $active     = self::activeKeys();
        $assetTypes = self::assetTypes();
        if ($assetTypes === []) {
            return [];
        }

        $rows = [];
        foreach ($DB->request([
            'SELECT' => [
                'glpi_tickets.id AS tickets_id',
                'glpi_tickets.name AS ticket_name',
                'glpi_tickets.content AS ticket_content',
                'glpi_items_tickets.itemtype AS itemtype',
                'glpi_items_tickets.items_id AS items_id',
            ],
            'FROM'       => 'glpi_tickets',
            'INNER JOIN' => [
                'glpi_items_tickets' => [
                    'ON' => ['glpi_items_tickets' => 'tickets_id', 'glpi_tickets' => 'id'],
                ],
            ],
            'WHERE' => [
                'glpi_tickets.is_deleted'        => 0,
                'glpi_tickets.status'            => Ticket::getNotSolvedStatusArray(),
                'glpi_tickets.itilcategories_id' => $categoryIds,
                'glpi_tickets.entities_id'       => $entityIds,
                // Only assets: tickets also link other items, such as the Forms answers.
                'glpi_items_tickets.itemtype'    => $assetTypes,
            ],
            'ORDER' => ['glpi_tickets.id DESC'],
        ]) as $r) {
            $key = $r['tickets_id'] . '|' . $r['itemtype'] . '|' . $r['items_id'];
            if (isset($active[$key])) {
                continue;
            }

            $asset = getItemForItemtype($r['itemtype']);
            if (!$asset || !$asset->getFromDB((int) $r['items_id'])) {
                continue;
            }

            $observation = TicketObservationExtractor::extract(RichText::getSafeHtml((string) $r['ticket_content']));

            $rows[] = [
                'key'                  => $key,
                'tickets_id'           => (int) $r['tickets_id'],
                'ticket_name'          => (string) $r['ticket_name'],
                'itemtype'             => (string) $r['itemtype'],
                'items_id'             => (int) $r['items_id'],
                'item_name'            => (string) ($asset->fields['name'] ?? ''),
                'item_type_label'      => $asset::getTypeName(1),
                'serial'               => (string) ($asset->fields['serial'] ?? ''),
                'otherserial'          => (string) ($asset->fields['otherserial'] ?? ''),
                'item_entities_id'     => (int) ($asset->fields['entities_id'] ?? 0),
                'ticket_observation'   => $observation,
                // Editable text printed in the PDF; falls back to the ticket title (plan decision 2).
                'description_supplier' => $observation !== '' ? $observation : (string) $r['ticket_name'],
            ];
        }
        return $rows;
    }

    /**
     * GLPI's own list of asset classes: the native ones plus every custom asset definition.
     *
     * @return list<string>
     */
    private static function assetTypes(): array
    {
        global $CFG_GLPI;

        return array_values(array_unique(array_map('strval', (array) ($CFG_GLPI['asset_types'] ?? []))));
    }

    /** @param array<string, string> $settings @return list<int> */
    private static function categoryScope(array $settings): array
    {
        $ids = PreSettings::categoryIds($settings);
        if (!PreSettings::includeSubcategories($settings)) {
            return $ids;
        }
        $all = [];
        foreach ($ids as $id) {
            foreach (getSonsOf('glpi_itilcategories', $id) as $son) {
                $all[(int) $son] = (int) $son;
            }
        }
        return array_values($all);
    }

    /** @return array<string, true> keys "<tickets_id>|<itemtype>|<items_id>" of pairs with an active line */
    private static function activeKeys(): array
    {
        global $DB;

        $active = [];
        foreach ($DB->request([
            'SELECT' => ['tickets_id', 'itemtype', 'items_id'],
            'FROM'   => RepairProtocolItem::getTable(),
            'WHERE'  => ['status' => [
                ItemStatus::PendingSend->value,
                ItemStatus::Sending->value,
                ItemStatus::AtSupplier->value,
            ]],
        ]) as $r) {
            $active[$r['tickets_id'] . '|' . $r['itemtype'] . '|' . $r['items_id']] = true;
        }
        return $active;
    }
}
