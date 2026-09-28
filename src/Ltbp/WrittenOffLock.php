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

/**
 * Whether an asset is locked (its laudo is Baixado or Concluído, spec L14). Cached per request;
 * flush() after a service changes a laudo's status.
 */
final class WrittenOffLock
{
    /** @var array<string, bool> */
    private static array $cache = [];

    public static function isLocked(string $itemtype, int $itemsId): bool
    {
        global $DB;

        $key = $itemtype . '|' . $itemsId;
        if (!isset(self::$cache[$key])) {
            $items  = LtbpItem::getTable();
            $laudos = Ltbp::getTable();
            $row = $DB->request([
                'COUNT'      => 'cpt',
                'FROM'       => $items,
                'INNER JOIN' => [
                    $laudos => ['ON' => [$items => 'plugin_gac_ltbps_id', $laudos => 'id']],
                ],
                'WHERE' => [
                    "$items.itemtype" => $itemtype,
                    "$items.items_id" => $itemsId,
                    "$laudos.status"  => Status::lockingValues(),
                ],
            ])->current();
            self::$cache[$key] = (int) ($row['cpt'] ?? 0) > 0;
        }
        return self::$cache[$key];
    }

    public static function flush(): void
    {
        self::$cache = [];
    }
}
