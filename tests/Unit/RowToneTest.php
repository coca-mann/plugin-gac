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

namespace GlpiPlugin\Gac\Tests\Unit;

use DateTimeImmutable;
use GlpiPlugin\Gac\Monitor\RowTone;
use PHPUnit\Framework\TestCase;

final class RowToneTest extends TestCase
{
    private const NOW = '2026-10-06 12:00:00';

    private function tone(string $mode, int $status = 2, int $priority = 3, ?string $ttr = null, ?string $tto = null, int $warn = 60): string
    {
        return RowTone::compute($mode, $status, $priority, $ttr, $tto, new DateTimeImmutable(self::NOW), $warn);
    }

    public function testIsValidMode(): void
    {
        foreach (['none', 'status', 'priority', 'sla'] as $mode) {
            $this->assertTrue(RowTone::isValidMode($mode));
        }
        $this->assertFalse(RowTone::isValidMode('elapsed'));
        $this->assertSame('priority', RowTone::DEFAULT_MODE);
    }

    public function testNoneNeverColors(): void
    {
        $this->assertSame('', $this->tone('none', 1, 5, '2020-01-01 00:00:00'));
    }

    public function testPriorityModeUsesThePriorityNumber(): void
    {
        $this->assertSame('priority-1', $this->tone('priority', 2, 1));
        $this->assertSame('priority-6', $this->tone('priority', 2, 6));
        $this->assertSame('', $this->tone('priority', 2, 0));
        $this->assertSame('', $this->tone('priority', 2, 7));
    }

    public function testStatusModeMapsTheKnownStatuses(): void
    {
        $this->assertSame('status-new', $this->tone('status', 1));
        $this->assertSame('status-processing', $this->tone('status', 2));
        $this->assertSame('status-planned', $this->tone('status', 3));
        $this->assertSame('status-pending', $this->tone('status', 4));
        $this->assertSame('status-solved', $this->tone('status', 5));
        $this->assertSame('status-approval', $this->tone('status', 10));
        $this->assertSame('', $this->tone('status', 6));
        $this->assertSame('', $this->tone('status', 99));
    }

    public function testSlaLateWhenTheDeadlineHasPassed(): void
    {
        $this->assertSame('sla-late', $this->tone('sla', 2, 3, '2026-10-06 11:59:59'));
        $this->assertSame('sla-late', $this->tone('sla', 2, 3, self::NOW));
    }

    public function testSlaWarningInsideTheWindowAndOkOutsideIt(): void
    {
        $this->assertSame('sla-warning', $this->tone('sla', 2, 3, '2026-10-06 12:30:00'));
        $this->assertSame('sla-warning', $this->tone('sla', 2, 3, '2026-10-06 13:00:00'));
        $this->assertSame('sla-ok', $this->tone('sla', 2, 3, '2026-10-06 13:00:01'));
        $this->assertSame('sla-ok', $this->tone('sla', 2, 3, '2026-10-07 12:00:00'));
    }

    public function testSlaWarningWindowIsConfigurable(): void
    {
        $this->assertSame('sla-ok', $this->tone('sla', 2, 3, '2026-10-06 12:30:00', null, 15));
        $this->assertSame('sla-warning', $this->tone('sla', 2, 3, '2026-10-06 12:10:00', null, 15));
    }

    public function testSlaNoneWhenThereIsNoDeadline(): void
    {
        $this->assertSame('sla-none', $this->tone('sla', 2, 3, null, null));
        $this->assertSame('sla-none', $this->tone('sla', 2, 3, '', ''));
        $this->assertSame('sla-none', $this->tone('sla', 2, 3, '0000-00-00 00:00:00'));
        $this->assertSame('sla-none', $this->tone('sla', 2, 3, 'not a date'));
    }

    public function testSlaPendingIsPausedEvenWithAStaleDeadline(): void
    {
        $this->assertSame('sla-paused', $this->tone('sla', 4, 3, '2020-01-01 00:00:00'));
        $this->assertSame('sla-paused', $this->tone('sla', 4, 3, null));
    }

    public function testSlaSolvedAndClosedHaveNoTone(): void
    {
        $this->assertSame('', $this->tone('sla', 5, 3, '2020-01-01 00:00:00'));
        $this->assertSame('', $this->tone('sla', 6, 3, '2020-01-01 00:00:00'));
    }

    public function testTimeToOwnCountsOnlyWhileTheTicketIsNew(): void
    {
        // New ticket: the own-deadline (already late) is the nearest one.
        $this->assertSame('sla-late', $this->tone('sla', 1, 3, '2026-10-07 12:00:00', '2026-10-06 11:00:00'));
        // In progress: the own-deadline is ignored, only the resolve-deadline counts.
        $this->assertSame('sla-ok', $this->tone('sla', 2, 3, '2026-10-07 12:00:00', '2026-10-06 11:00:00'));
        // New ticket with only an own-deadline.
        $this->assertSame('sla-warning', $this->tone('sla', 1, 3, null, '2026-10-06 12:20:00'));
        // In progress with only an own-deadline: no usable deadline.
        $this->assertSame('sla-none', $this->tone('sla', 2, 3, null, '2026-10-06 12:20:00'));
    }

    public function testTheNearestDeadlineWins(): void
    {
        $this->assertSame('sla-warning', $this->tone('sla', 1, 3, '2026-10-07 12:00:00', '2026-10-06 12:30:00'));
        $this->assertSame('sla-warning', $this->tone('sla', 1, 3, '2026-10-06 12:30:00', '2026-10-07 12:00:00'));
    }
}
