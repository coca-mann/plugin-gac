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
 * Atomic claim of an asset (spec L8, plan decision 18): MySQL has no partial unique index, so
 * "the asset is in no other laudo" plus the insert of the line run under a named lock, and two
 * technicians never take the same asset at the same time.
 */
final class AssetClaim
{
    private const WAIT_SECONDS = 5;

    public static function run(string $itemtype, int $itemsId, callable $fn): mixed
    {
        global $DB;

        $name = 'gac_ltbp_' . md5($itemtype . '|' . $itemsId);
        $row  = $DB->doQuery(sprintf("SELECT GET_LOCK('%s', %d) AS got", $name, self::WAIT_SECONDS))->fetch_assoc();
        if ((int) ($row['got'] ?? 0) !== 1) {
            throw new \RuntimeException(__('Outro técnico está usando este ativo agora. Tente de novo.', 'gac'));
        }
        try {
            return $fn();
        } finally {
            $DB->doQuery(sprintf("SELECT RELEASE_LOCK('%s')", $name));
        }
    }
}
