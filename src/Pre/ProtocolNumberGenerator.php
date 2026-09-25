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

/**
 * Atomic per-year counter: INSERT ... ON DUPLICATE KEY UPDATE with LAST_INSERT_ID(expr)
 * makes the increment and the read one race-free step on this connection (plan decision 1).
 */
final class ProtocolNumberGenerator
{
    public static function next(?int $year = null): string
    {
        global $DB;

        $year ??= (int) date('Y');
        $DB->doQuery(sprintf(
            'INSERT INTO `glpi_plugin_gac_protocolsequences` (`year`, `last`) VALUES (%d, LAST_INSERT_ID(1))'
            . ' ON DUPLICATE KEY UPDATE `last` = LAST_INSERT_ID(`last` + 1)',
            $year
        ));
        $row = $DB->doQuery('SELECT LAST_INSERT_ID() AS seq')->fetch_assoc();

        return ProtocolNumber::format($year, (int) $row['seq']);
    }
}
