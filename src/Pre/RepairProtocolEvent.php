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
use Session;

/**
 * Event log of a PRE (spec 5.2.1). It is the source of truth for the reopening state; every event
 * is also written as a text line to GLPI's native history, which is the only history tab (D24).
 */
class RepairProtocolEvent extends CommonDBChild
{
    public static $itemtype = RepairProtocol::class;
    public static $items_id = 'plugin_gac_repairprotocols_id';
    public $dohistory       = false;

    /** Events are mirrored into the PRE's native history explicitly (see log()), not by GLPI. */
    public static $logs_for_parent = false;

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

        // "created" already has GLPI's own "item created" line in the native history.
        if ($event !== 'created') {
            // A normal (update) entry on a named field, so the history tab fills its "Campo" column.
            \Log::history(
                $protocolId,
                RepairProtocol::class,
                [RepairProtocol::HISTORY_OPTION_EVENT, '', self::message($event, $reason, $details, $lineId)]
            );
        }
    }

    /** The text of an event in the native history. */
    public static function message(string $event, string $reason, array $details, int $lineId): string
    {
        $detail = match ($event) {
            'line_returned'   => self::returnDetail($details),
            'line_corrected'  => self::correctionDetail($details),
            'line_lost'       => $reason === '' ? '' : sprintf(__('Justificativa: %s', 'gac'), $reason),
            'line_removed'    => $reason === '' ? '' : sprintf(__('Motivo: %s', 'gac'), $reason),
            default           => $reason,
        };

        return EventMessage::compose(Labels::event($event), self::lineLabel($lineId), $detail);
    }

    /** "#ticket · asset" of a line, empty when there is no line (or it no longer exists). */
    private static function lineLabel(int $lineId): string
    {
        global $DB;

        if ($lineId <= 0) {
            return '';
        }
        $row = $DB->request([
            'SELECT' => ['tickets_id', 'item_name'],
            'FROM'   => RepairProtocolItem::getTable(),
            'WHERE'  => ['id' => $lineId],
        ])->current();

        return $row === null ? '' : sprintf('#%d · %s', (int) $row['tickets_id'], (string) $row['item_name']);
    }

    private static function returnDetail(array $details): string
    {
        $parts = [];
        $outcome = Outcome::tryFrom((string) ($details['outcome'] ?? ''));
        if ($outcome !== null) {
            $parts[] = Labels::outcome($outcome);
        }
        $destination = Destination::tryFrom((string) ($details['destination'] ?? ''));
        if ($destination !== null && $destination !== Destination::None) {
            $parts[] = Labels::destination($destination);
        }
        return implode(' · ', $parts);
    }

    private static function correctionDetail(array $details): string
    {
        $format = static function (array $values): array {
            $out = [];
            foreach ($values as $key => $value) {
                $value = $value === null ? '' : (string) $value;
                if ($key === 'cost' && $value !== '') {
                    $value = number_format((float) $value, 2, ',', '');
                } elseif (in_array($key, ['date_return', 'warranty_until'], true) && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
                    $value = $m[3] . '/' . $m[2] . '/' . $m[1];
                }
                $out[$key] = $value;
            }
            return $out;
        };

        return EventMessage::diff(
            $format((array) ($details['before'] ?? [])),
            $format((array) ($details['after'] ?? [])),
            [
                'date_return'         => __('Data do retorno', 'gac'),
                'service_description' => __('Serviço executado', 'gac'),
                'cost'                => __('Custo', 'gac'),
                'supplier_ref'        => __('Nº da OS ou nota do fornecedor', 'gac'),
                'warranty_until'      => __('Garantia até', 'gac'),
            ]
        );
    }

    /**
     * True when the latest closing-related event is a reopening: the PRE is being corrected
     * and has not been closed again (plan decision 3).
     */
    public static function isReopened(int $protocolId): bool
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['event'],
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'plugin_gac_repairprotocols_id' => $protocolId,
                'event' => ['reopened', 'closed'],
            ],
            'ORDER' => ['id DESC'],
            'LIMIT' => 1,
        ])->current();

        return $row !== null && $row['event'] === 'reopened';
    }
}
