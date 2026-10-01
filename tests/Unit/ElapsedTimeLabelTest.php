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

use GlpiPlugin\Gac\Monitor\ElapsedTimeLabel;
use PHPUnit\Framework\TestCase;

final class ElapsedTimeLabelTest extends TestCase
{
    public function testUnderOneMinute(): void
    {
        $now = new \DateTimeImmutable('2026-10-01 10:00:30');
        $this->assertSame('<1min', ElapsedTimeLabel::format('2026-10-01 10:00:00', $now));
    }

    public function testMinutesOnly(): void
    {
        $now = new \DateTimeImmutable('2026-10-01 10:15:00');
        $this->assertSame('15min', ElapsedTimeLabel::format('2026-10-01 10:00:00', $now));
    }

    public function testHoursAndMinutes(): void
    {
        $now = new \DateTimeImmutable('2026-10-01 12:15:00');
        $this->assertSame('2h15min', ElapsedTimeLabel::format('2026-10-01 10:00:00', $now));
    }

    public function testHoursOnly(): void
    {
        $now = new \DateTimeImmutable('2026-10-01 12:00:00');
        $this->assertSame('2h', ElapsedTimeLabel::format('2026-10-01 10:00:00', $now));
    }

    public function testDaysAndHours(): void
    {
        $now = new \DateTimeImmutable('2026-10-03 12:00:00');
        $this->assertSame('2d 2h', ElapsedTimeLabel::format('2026-10-01 10:00:00', $now));
    }

    public function testDaysOnly(): void
    {
        $now = new \DateTimeImmutable('2026-10-03 10:00:00');
        $this->assertSame('2d', ElapsedTimeLabel::format('2026-10-01 10:00:00', $now));
    }

    public function testMalformedDateReturnsEmptyString(): void
    {
        $this->assertSame('', ElapsedTimeLabel::format('not-a-date', new \DateTimeImmutable()));
    }

    public function testFutureDateIsTreatedAsZero(): void
    {
        $now = new \DateTimeImmutable('2026-10-01 10:00:00');
        $this->assertSame('<1min', ElapsedTimeLabel::format('2026-10-01 11:00:00', $now));
    }
}
