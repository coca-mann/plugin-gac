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

/**
 * Pure: the colour "tone" of a board row, chosen per Tela (spec M15/M16). The server decides the
 * tone (a meaning, never a colour); the JS only maps it to CSS. See spec section 6.8.
 */
final class RowTone
{
    public const MODE_NONE     = 'none';
    public const MODE_STATUS   = 'status';
    public const MODE_PRIORITY = 'priority';
    public const MODE_SLA      = 'sla';

    /** @var list<string> */
    public const MODES = [self::MODE_NONE, self::MODE_STATUS, self::MODE_PRIORITY, self::MODE_SLA];

    public const DEFAULT_MODE = self::MODE_PRIORITY;

    private const STATUS_NEW     = 1;
    private const STATUS_PENDING = 4;
    private const STATUS_SOLVED  = 5;
    private const STATUS_CLOSED  = 6;

    /** GLPI ticket status => tone. Unlisted statuses get no tone. */
    private const STATUS_TONES = [
        1  => 'status-new',
        2  => 'status-processing',
        3  => 'status-planned',
        4  => 'status-pending',
        5  => 'status-solved',
        10 => 'status-approval',
    ];

    public static function isValidMode(string $mode): bool
    {
        return in_array($mode, self::MODES, true);
    }

    /**
     * @param string|null $timeToResolve GLPI "time_to_resolve" (datetime string), null/empty without SLA
     * @param string|null $timeToOwn     GLPI "time_to_own", only considered while the ticket is New
     * @return string '' (no colour), 'priority-N', 'status-*' or 'sla-*'
     */
    public static function compute(
        string $mode,
        int $status,
        int $priority,
        ?string $timeToResolve,
        ?string $timeToOwn,
        \DateTimeImmutable $now,
        int $warningMinutes
    ): string {
        return match ($mode) {
            self::MODE_STATUS   => self::STATUS_TONES[$status] ?? '',
            self::MODE_PRIORITY => ($priority >= 1 && $priority <= 6) ? 'priority-' . $priority : '',
            self::MODE_SLA      => self::slaTone($status, $timeToResolve, $timeToOwn, $now, $warningMinutes),
            default             => '',
        };
    }

    private static function slaTone(
        int $status,
        ?string $timeToResolve,
        ?string $timeToOwn,
        \DateTimeImmutable $now,
        int $warningMinutes
    ): string {
        if ($status === self::STATUS_SOLVED || $status === self::STATUS_CLOSED) {
            return '';
        }
        // The SLA clock is stopped while Pending: the stored deadline is stale and would read as
        // "late" without being so.
        if ($status === self::STATUS_PENDING) {
            return 'sla-paused';
        }

        $deadlines = array_filter([
            self::parse($timeToResolve),
            $status === self::STATUS_NEW ? self::parse($timeToOwn) : null,
        ]);
        if ($deadlines === []) {
            return 'sla-none';
        }

        $nearest = min(array_map(static fn(\DateTimeImmutable $d): int => $d->getTimestamp(), $deadlines));
        $left    = $nearest - $now->getTimestamp();

        if ($left <= 0) {
            return 'sla-late';
        }
        return $left <= $warningMinutes * 60 ? 'sla-warning' : 'sla-ok';
    }

    private static function parse(?string $value): ?\DateTimeImmutable
    {
        $value = trim((string) $value);
        if ($value === '' || str_starts_with($value, '0000')) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
