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

use GlpiPlugin\Gac\Pre\ItemStatus as PreItemStatus;
use GlpiPlugin\Gac\Pre\RepairProtocolItem;
use GlpiPlugin\Gac\Shared\ServiceResult;

/** Draft-time line operations: add assets, remove, choose the reason of each line. */
final class LineService
{
    /**
     * @param list<array{itemtype: string, items_id: int}> $assets
     * @param array<string, array<string, mixed>> $origins keyed "itemtype|items_id": pre_items_id, pre_number, tickets_id, outcome
     */
    public static function addAssets(Ltbp $laudo, array $assets, array $origins = []): ServiceResult
    {
        if (!StateMachine::canEditDraft($laudo->getStatus())) {
            return ServiceResult::fail(__('Só é possível adicionar ativos a um laudo em rascunho.', 'gac'));
        }

        $settings = LtbpConfig::load();
        $added    = 0;
        $problems = [];
        foreach ($assets as $a) {
            $key = $a['itemtype'] . '|' . $a['items_id'];
            try {
                self::addOne($laudo, $a['itemtype'], (int) $a['items_id'], $origins[$key] ?? [], $settings);
                $added++;
            } catch (\RuntimeException $e) {
                $problems[] = $e->getMessage();
            }
        }

        if ($added === 0) {
            return ServiceResult::fail(implode(' ', $problems) ?: __('Nenhum ativo válido foi selecionado.', 'gac'));
        }

        $message = sprintf(_n('%d ativo adicionado.', '%d ativos adicionados.', $added, 'gac'), $added);
        if ($problems !== []) {
            $message .= ' ' . __('Não adicionados:', 'gac') . ' ' . implode(' ', $problems);
        }
        return ServiceResult::ok($message);
    }

    /**
     * @param array<string, mixed> $origin
     * @param array<string, string> $settings
     * @throws \RuntimeException with a translated message when the asset cannot enter the laudo
     */
    private static function addOne(Ltbp $laudo, string $itemtype, int $itemsId, array $origin, array $settings): void
    {
        if (!AssetTypes::isAsset($itemtype)) {
            throw new \RuntimeException(sprintf(__('#%d: tipo de item inválido.', 'gac'), $itemsId));
        }
        $asset = getItemForItemtype($itemtype);
        if (!$asset || !$asset->getFromDB($itemsId) || !$asset->canViewItem()) {
            throw new \RuntimeException(sprintf(__('#%d: ativo não encontrado.', 'gac'), $itemsId));
        }

        $label    = (string) ($asset->fields['name'] ?? '') ?: '#' . $itemsId;
        $entities = array_map('intval', getSonsOf('glpi_entities', (int) $laudo->fields['entities_id']));
        if (!in_array((int) ($asset->fields['entities_id'] ?? 0), $entities, true)) {
            throw new \RuntimeException(sprintf(__('%s: está fora da entidade do laudo.', 'gac'), $label));
        }
        if (!empty($asset->fields['is_deleted']) || !empty($asset->fields['is_template'])) {
            throw new \RuntimeException(sprintf(__('%s: está na lixeira ou é um modelo.', 'gac'), $label));
        }
        $writtenOff = LtbpSettings::stateId($settings, 'written_off');
        if ($writtenOff > 0 && (int) ($asset->fields['states_id'] ?? 0) === $writtenOff) {
            throw new \RuntimeException(sprintf(__('%s: já está baixado.', 'gac'), $label));
        }

        AssetClaim::run($itemtype, $itemsId, static function () use ($laudo, $itemtype, $itemsId, $asset, $origin, $settings, $label): void {
            $other = Ltbp::activeLaudoFor($itemtype, $itemsId);
            if ($other !== null) {
                throw new \RuntimeException(sprintf(__('%1$s: já está no laudo %2$s.', 'gac'), $label, $other['number']));
            }
            if (self::hasActivePreLine($itemtype, $itemsId)) {
                throw new \RuntimeException(sprintf(__('%s: está em um PRE ativo (aguardando envio, em envio ou na assistência).', 'gac'), $label));
            }

            $id = (new LtbpItem())->add([
                'plugin_gac_ltbps_id'       => (int) $laudo->getID(),
                'itemtype'                  => $itemtype,
                'items_id'                  => $itemsId,
                'plugin_gac_ltbpreasons_id' => self::defaultReasonFor($origin, $settings),
                'pre_items_id'              => (int) ($origin['pre_items_id'] ?? 0),
                'pre_number'                => (string) ($origin['pre_number'] ?? ''),
                'tickets_id'                => (int) ($origin['tickets_id'] ?? 0),
            ] + AssetSnapshot::take($asset));
            if (!$id) {
                throw new \RuntimeException(sprintf(__('%s: não foi possível adicionar.', 'gac'), $label));
            }

            LtbpEvent::log((int) $laudo->getID(), 'line_added', '', [], (int) $id);
        });
    }

    /** True when the asset is in a PRE line that is still active (the emission checks this again). */
    public static function hasActivePreLine(string $itemtype, int $itemsId): bool
    {
        return countElementsInTable(RepairProtocolItem::getTable(), [
            'itemtype' => $itemtype,
            'items_id' => $itemsId,
            'status'   => [
                PreItemStatus::PendingSend->value,
                PreItemStatus::Sending->value,
                PreItemStatus::AtSupplier->value,
            ],
        ]) > 0;
    }

    /**
     * The default reason of a line that came from the PRE (spec L24): the one configured for the
     * PRE outcome, when it exists and is active. Zero means "choose one".
     *
     * @param array<string, mixed> $origin
     * @param array<string, string> $settings
     */
    private static function defaultReasonFor(array $origin, array $settings): int
    {
        $id = LtbpSettings::defaultReasonId($settings, (string) ($origin['outcome'] ?? ''));
        if ($id <= 0) {
            return 0;
        }
        $row = LtbpReason::row($id);
        return ($row !== null && (int) $row['is_active'] === 1) ? $id : 0;
    }

    public static function removeLine(Ltbp $laudo, int $lineId): ServiceResult
    {
        if (!StateMachine::canEditDraft($laudo->getStatus())) {
            return ServiceResult::fail(__('Só é possível remover ativos de um laudo em rascunho.', 'gac'));
        }
        $line = new LtbpItem();
        if (!$line->getFromDB($lineId) || (int) $line->fields['plugin_gac_ltbps_id'] !== (int) $laudo->getID()) {
            return ServiceResult::fail(__('Linha não encontrada.', 'gac'));
        }

        // Logged first: the event text is built from the line, which is gone after the delete.
        LtbpEvent::log((int) $laudo->getID(), 'line_removed', '', [], $lineId);
        $line->delete(['id' => $lineId], true);

        return ServiceResult::ok(__('Ativo removido do laudo.', 'gac'));
    }

    /** @param array<int|string, int|string> $reasons line id => reason id (0 clears it) */
    public static function saveReasons(Ltbp $laudo, array $reasons): ServiceResult
    {
        global $DB;

        if (!StateMachine::canEditDraft($laudo->getStatus())) {
            return ServiceResult::fail(__('Os motivos só podem ser alterados em rascunho.', 'gac'));
        }

        $active = LtbpReason::choices();
        foreach ($reasons as $lineId => $reasonId) {
            $reasonId = (int) $reasonId;
            if ($reasonId !== 0 && !isset($active[$reasonId])) {
                continue;
            }
            $DB->update(
                LtbpItem::getTable(),
                ['plugin_gac_ltbpreasons_id' => $reasonId, 'date_mod' => $_SESSION['glpi_currenttime']],
                ['id' => (int) $lineId, 'plugin_gac_ltbps_id' => (int) $laudo->getID()]
            );
        }
        return ServiceResult::ok(__('Motivos salvos.', 'gac'));
    }
}
