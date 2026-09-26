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
use GlpiPlugin\Gac\Shared\EventMessage;
use Session;

/**
 * Event log of a laudo (spec 5.4). Every event is also written as a text line to GLPI's native
 * history, which is the only history tab (same approach as the PRE, D24).
 */
class LtbpEvent extends CommonDBChild
{
    public static $itemtype = Ltbp::class;
    public static $items_id = 'plugin_gac_ltbps_id';
    public $dohistory       = false;

    /** Events are mirrored into the laudo's native history explicitly (see log()), not by GLPI. */
    public static $logs_for_parent = false;

    public static function getTable($classname = null)
    {
        if ($classname !== null && $classname !== static::class) {
            return parent::getTable($classname);
        }
        return 'glpi_plugin_gac_ltbpevents';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Evento', 'Eventos', $nb, 'gac');
    }

    /** @param array<string, mixed> $details */
    public static function log(
        int $ltbpId,
        string $event,
        string $reason = '',
        array $details = [],
        int $lineId = 0
    ): void {
        (new self())->add([
            'plugin_gac_ltbps_id'     => $ltbpId,
            'plugin_gac_ltbpitems_id' => $lineId,
            'event'                   => $event,
            'users_id'                => (int) Session::getLoginUserID(),
            'reason'                  => $reason,
            'details'                 => $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE),
        ]);

        // "created" already has GLPI's own "item created" line in the native history.
        if ($event !== 'created') {
            \Log::history(
                $ltbpId,
                Ltbp::class,
                [Ltbp::HISTORY_OPTION_EVENT, '', self::message($event, $reason, $lineId)]
            );
        }
    }

    /** The text of an event in the native history (plain text, at most 255 characters). */
    public static function message(string $event, string $reason, int $lineId): string
    {
        $detail = match ($event) {
            'canceled', 'line_removed' => $reason === '' ? '' : sprintf(__('Motivo: %s', 'gac'), $reason),
            default                    => $reason,
        };

        return EventMessage::compose(Labels::event($event), self::lineLabel($lineId), $detail);
    }

    /** "type · asset" of a line, empty when there is no line (or it no longer exists). */
    private static function lineLabel(int $lineId): string
    {
        global $DB;

        if ($lineId <= 0) {
            return '';
        }
        $row = $DB->request([
            'SELECT' => ['item_type_label', 'item_name'],
            'FROM'   => LtbpItem::getTable(),
            'WHERE'  => ['id' => $lineId],
        ])->current();

        return $row === null ? '' : sprintf('%s · %s', (string) $row['item_type_label'], (string) $row['item_name']);
    }
}
