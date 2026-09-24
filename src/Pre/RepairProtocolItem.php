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

use CommonDBChild;
use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use Ticket;

class RepairProtocolItem extends CommonDBChild
{
    public static $itemtype = RepairProtocol::class;
    public static $items_id = 'plugin_gac_repairprotocols_id';
    public static $rightname = 'plugin_gac_pre';
    public $dohistory       = false;

    public static function getTable($classname = null)
    {
        if ($classname !== null && $classname !== static::class) {
            return parent::getTable($classname);
        }
        return 'glpi_plugin_gac_repairprotocolitems';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Item', 'Itens', $nb, 'gac');
    }

    public function getStatus(): ItemStatus
    {
        return ItemStatus::from($this->fields['status']);
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof RepairProtocol) {
            $count = countElementsInTable(self::getTable(), ['plugin_gac_repairprotocols_id' => $item->getID()]);
            return self::createTabEntry(__('Itens', 'gac'), $count);
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof RepairProtocol) {
            return false;
        }
        self::showForProtocol($item);
        return true;
    }

    public static function showForProtocol(RepairProtocol $protocol): void
    {
        $lines = [];
        foreach ($protocol->lines() as $row) {
            $lines[] = $row + [
                'ticket_url'   => Ticket::getFormURLWithID((int) $row['tickets_id']),
                'status_label' => Labels::itemStatus(ItemStatus::from($row['status'])),
            ];
        }

        TemplateRenderer::getInstance()->display('@gac/pre/items_tab.html.twig', [
            'protocol' => $protocol,
            'lines'    => $lines,
        ]);
    }
}
