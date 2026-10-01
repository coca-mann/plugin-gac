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

namespace GlpiPlugin\Gac\Monitor;

/** Pure: formats the "elapsed time" column from a raw GLPI datetime and the current instant. */
final class ElapsedTimeLabel
{
    public static function format(string $openedAt, \DateTimeImmutable $now): string
    {
        $opened = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $openedAt);
        if ($opened === false) {
            return '';
        }

        $seconds = $now->getTimestamp() - $opened->getTimestamp();
        if ($seconds < 0) {
            $seconds = 0;
        }

        $days    = intdiv($seconds, 86400);
        $hours   = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return $hours > 0 ? $days . 'd ' . $hours . 'h' : $days . 'd';
        }
        if ($hours > 0) {
            return $minutes > 0 ? $hours . 'h' . $minutes . 'min' : $hours . 'h';
        }
        if ($minutes > 0) {
            return $minutes . 'min';
        }
        return '<1min';
    }
}
