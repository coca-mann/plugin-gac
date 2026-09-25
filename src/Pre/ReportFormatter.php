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

declare(strict_types=1);

namespace GlpiPlugin\Gac\Pre;

/** Pure text formatting for the report header. */
final class ReportFormatter
{
    /** "2026-09-25" -> "25/09/2026"; anything that is not a valid Y-m-d date is returned as is. */
    public static function date(string $ymd): string
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $ymd);
        return ($d !== false && $d->format('Y-m-d') === $ymd) ? $d->format('d/m/Y') : $ymd;
    }

    public static function addressLine(string $address, string $postcode, string $town, string $state): string
    {
        $address  = trim($address);
        $postcode = trim($postcode);
        $town     = trim($town);
        $state    = trim($state);

        $place = $town !== '' && $state !== '' ? $town . '/' . $state : $town . $state;

        return implode(' — ', array_filter([$address, $postcode, $place], static fn(string $p): bool => $p !== ''));
    }
}
