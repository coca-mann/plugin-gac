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
use GlpiPlugin\Gac\Pre\RepairProtocol;
use GlpiPlugin\Gac\Pre\RepairProtocolItem;
use GlpiPlugin\Gac\Shared\ServiceResult;
use Session;

/**
 * Entry point of the PRE integration (spec L19 to L22): takes returned PRE lines with destination
 * Baixa into a draft laudo. All the rules live here and in LineService; the PRE only shows the
 * button. This never writes to the PRE tables.
 */
final class LtbpLinker
{
    /** @return list<array{id: int, number: string, destination_label: string}> drafts the user can add to */
    public static function openDrafts(): array
    {
        global $DB;

        $drafts = [];
        foreach ($DB->request([
            'FROM'  => Ltbp::getTable(),
            'WHERE' => ['status' => Status::Draft->value] + getEntitiesRestrictCriteria(Ltbp::getTable()),
            'ORDER' => ['id DESC'],
        ]) as $r) {
            $destination = Destination::tryFrom((string) $r['destination']);
            $drafts[] = [
                'id'                => (int) $r['id'],
                'number'            => (string) $r['number'],
                'destination_label' => $destination === null ? '' : Labels::destination($destination),
            ];
        }
        return $drafts;
    }

    /**
     * @param list<int> $preItemIds ids of the PRE lines
     * @param int $ltbpId 0 creates a new draft with $newDestination
     */
    public static function addFromPre(array $preItemIds, int $ltbpId, string $newDestination): ServiceResult
    {
        global $DB;

        $ids = array_values(array_filter(array_map('intval', $preItemIds), static fn(int $id): bool => $id > 0));
        if ($ids === []) {
            return ServiceResult::fail(__('Marque ao menos uma linha aguardando laudo.', 'gac'));
        }

        // Never trust the client: only returned lines with destination Baixa count.
        $preItems     = RepairProtocolItem::getTable();
        $preProtocols = RepairProtocol::getTable();
        $assets       = [];
        $origins      = [];
        $entityId     = null;
        foreach ($DB->request([
            'SELECT'     => [
                "$preItems.id AS pre_item_id",
                "$preItems.itemtype AS itemtype",
                "$preItems.items_id AS items_id",
                "$preItems.tickets_id AS ticket_id",
                "$preItems.outcome AS outcome",
                "$preProtocols.number AS pre_number",
                "$preProtocols.entities_id AS pre_entities_id",
            ],
            'FROM'       => $preItems,
            'INNER JOIN' => [
                $preProtocols => ['ON' => [$preItems => 'plugin_gac_repairprotocols_id', $preProtocols => 'id']],
            ],
            'WHERE' => [
                "$preItems.id"          => $ids,
                "$preItems.status"      => PreItemStatus::Returned->value,
                "$preItems.destination" => PreDestination::Writeoff->value,
            ],
        ]) as $r) {
            $key           = $r['itemtype'] . '|' . $r['items_id'];
            $assets[]      = ['itemtype' => (string) $r['itemtype'], 'items_id' => (int) $r['items_id']];
            $origins[$key] = [
                'pre_items_id' => (int) $r['pre_item_id'],
                'pre_number'   => (string) $r['pre_number'],
                'tickets_id'   => (int) $r['ticket_id'],
                'outcome'      => (string) $r['outcome'],
            ];
            $entityId ??= (int) $r['pre_entities_id'];
        }
        if ($assets === []) {
            return ServiceResult::fail(__('Nenhuma das linhas marcadas está devolvida com destino Baixa.', 'gac'));
        }

        $laudo   = new Ltbp();
        $created = false;
        if ($ltbpId > 0) {
            if (!Ltbp::canUpdate() || !$laudo->getFromDB($ltbpId) || !StateMachine::canEditDraft($laudo->getStatus()) || !$laudo->canUpdateItem()) {
                return ServiceResult::fail(__('Escolha um laudo em rascunho que você possa editar.', 'gac'));
            }
        } else {
            if (!Ltbp::canCreate()) {
                return ServiceResult::fail(__('Você não tem permissão para criar laudos.', 'gac'));
            }
            if (Destination::tryFrom($newDestination) === null) {
                return ServiceResult::fail(__('Escolha a destinação do novo laudo.', 'gac'));
            }
            if ($entityId === null || !Session::haveAccessToEntity($entityId)) {
                return ServiceResult::fail(__('Você não tem acesso à entidade destes ativos.', 'gac'));
            }
            $newId = $laudo->add(['destination' => $newDestination, 'entities_id' => $entityId]);
            if (!$newId || !$laudo->getFromDB((int) $newId)) {
                return ServiceResult::fail(__('Não foi possível criar o laudo.', 'gac'));
            }
            $created = true;
        }

        $result = LineService::addAssets($laudo, $assets, $origins);
        if (!$result->ok && $created) {
            // Do not leave an empty draft behind when nothing could be added to it.
            $laudo->delete(['id' => (int) $laudo->getID()], true);
            return $result;
        }
        if (!$result->ok) {
            return $result;
        }

        return ServiceResult::ok(
            $result->message . ' ' . sprintf(__('Laudo %s.', 'gac'), (string) $laudo->fields['number']),
            ['created' => $created, 'ltbp_id' => (int) $laudo->getID()]
        );
    }
}
