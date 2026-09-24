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
use Session;

/**
 * Event log of a PRE (spec 5.2.1). Also the tab "Histórico".
 */
class RepairProtocolEvent extends CommonDBChild
{
    public static $itemtype = RepairProtocol::class;
    public static $items_id = 'plugin_gac_repairprotocols_id';
    public $dohistory       = false;

    public static function getTable($classname = null)
    {
        if ($classname !== null && $classname !== static::class) {
            return parent::getTable($classname);
        }
        return 'glpi_plugin_gac_repairprotocolevents';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Evento', 'Eventos', $nb, 'gac');
    }

    public static function log(
        int $protocolId,
        string $event,
        string $reason = '',
        array $details = [],
        int $lineId = 0
    ): void {
        (new self())->add([
            'plugin_gac_repairprotocols_id'     => $protocolId,
            'plugin_gac_repairprotocolitems_id' => $lineId,
            'event'                             => $event,
            'users_id'                          => (int) Session::getLoginUserID(),
            'reason'                            => $reason,
            'details'                           => $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof RepairProtocol) {
            return self::createTabEntry(__('Histórico', 'gac'));
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof RepairProtocol) {
            return false;
        }

        global $DB;
        $rows = [];
        foreach ($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['plugin_gac_repairprotocols_id' => $item->getID()],
            'ORDER' => ['id DESC'],
        ]) as $row) {
            $rows[] = [
                'date'   => $row['date_creation'],
                'user'   => getUserName((int) $row['users_id']),
                'event'  => Labels::event($row['event']),
                'line'   => (int) $row['plugin_gac_repairprotocolitems_id'],
                'reason' => (string) $row['reason'],
            ];
        }

        TemplateRenderer::getInstance()->display('@gac/pre/events_tab.html.twig', ['events' => $rows]);
        return true;
    }
}
