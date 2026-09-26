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

use CommonDBChild;
use CommonGLPI;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;

/** One asset inside a laudo (spec section 5.2), with the "Itens" tab of the laudo. */
class LtbpItem extends CommonDBChild
{
    public static $itemtype  = Ltbp::class;
    public static $items_id  = 'plugin_gac_ltbps_id';
    public static $rightname = 'plugin_gac_ltbp';
    public $dohistory        = false;

    /** The line has no "name" column; this keeps the native history readable when a line is added or removed. */
    public function getName($options = [])
    {
        if (empty($this->fields['item_name'])) {
            return parent::getName($options);
        }
        return sprintf('%s · %s', $this->fields['item_type_label'] ?? '', $this->fields['item_name']);
    }

    public static function getTable($classname = null)
    {
        if ($classname !== null && $classname !== static::class) {
            return parent::getTable($classname);
        }
        return 'glpi_plugin_gac_ltbpitems';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Item', 'Itens', $nb, 'gac');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Ltbp) {
            $count = countElementsInTable(self::getTable(), ['plugin_gac_ltbps_id' => $item->getID()]);
            return self::createTabEntry(__('Itens', 'gac'), $count, null, 'ti ti-list-details');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof Ltbp) {
            return false;
        }
        self::showForLaudo($item);
        return true;
    }

    public static function showForLaudo(Ltbp $laudo): void
    {
        $status  = $laudo->getStatus();
        $isDraft = StateMachine::canEditDraft($status);
        $canEdit = $laudo->canUpdateItem();
        $editing = $isDraft && $canEdit;

        $lines = [];
        foreach ($laudo->lines() as $row) {
            $lines[] = $row + [
                'ticket_url' => $row['tickets_id'] ? \Ticket::getFormURLWithID((int) $row['tickets_id']) : '',
            ];
        }
        // Draft: the live catalog (a select per line). After the emission: the snapshot stored in
        // the line, never the live catalog, so editing a reason does not change an issued laudo.
        $catalog = LtbpReason::choices(false);
        foreach ($lines as &$line) {
            $line['reason_text'] = $isDraft
                ? ($catalog[(int) $line['plugin_gac_ltbpreasons_id']] ?? '')
                : ($line['reason_code'] ? sprintf('%s: %s', $line['reason_code'], $line['reason_title']) : '');
        }
        unset($line);

        $settings   = LtbpConfig::load();
        $candidates = $editing ? CandidateFinder::find($laudo) : [];
        $picker     = '';
        if ($editing) {
            $picker = Dropdown::showSelectItemFromItemtypes([
                'itemtypes'       => AssetTypes::all(),
                'itemtype_name'   => 'asset_itemtype',
                'items_id_name'   => 'asset_items_id',
                'entity_restrict' => -1,
                'checkright'      => true,
                'width'           => '100%',
                'display'         => false,
            ]);
        }

        TemplateRenderer::getInstance()->display('@gac/ltbp/items_tab.html.twig', [
            'laudo'           => $laudo,
            'lines'           => $lines,
            'is_draft'        => $isDraft,
            'editing'         => $editing,
            'candidates'      => $candidates,
            'candidates_hint' => $editing && LtbpSettings::stateId($settings, 'awaiting_writeoff') <= 0,
            'reason_choices'  => [0 => Dropdown::EMPTY_VALUE] + LtbpReason::choices(),
            'asset_picker'    => $picker,
            'form_url'        => self::getFormURL(),
            'pdf_url'         => str_replace('.form.php', '.pdf.php', Ltbp::getFormURL()) . '?id=' . (int) $laudo->getID(),
            'can_pdf'         => $laudo->canViewItem()
                && ($isDraft ? $lines !== [] : (int) $laudo->fields['documents_id_frozen'] > 0),
        ]);
    }
}
