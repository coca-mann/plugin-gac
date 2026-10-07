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

use GlpiPlugin\Gac\Monitor\FixedWindow;
use PHPUnit\Framework\TestCase;

final class FixedWindowTest extends TestCase
{
    public function testWindowStartRoundsDownToTheMinute(): void
    {
        $this->assertSame(1200, FixedWindow::windowStart(1200));
        $this->assertSame(1200, FixedWindow::windowStart(1259));
        $this->assertSame(1260, FixedWindow::windowStart(1260));
    }

    public function testKeyChangesWithTheWindowTheScopeAndTheSubject(): void
    {
        $a = FixedWindow::key('ip', '203.0.113.9', 1230);
        $this->assertSame($a, FixedWindow::key('ip', '203.0.113.9', 1259));
        $this->assertNotSame($a, FixedWindow::key('ip', '203.0.113.9', 1260));
        $this->assertNotSame($a, FixedWindow::key('token', '203.0.113.9', 1230));
        $this->assertNotSame($a, FixedWindow::key('ip', '203.0.113.10', 1230));
    }

    public function testKeyIsSafeForTheCache(): void
    {
        // PSR-16 forbids {}()/\@: in keys, and a subject is user-controlled.
        $key = FixedWindow::key('ip', '{}()/\\@: weird', 1230);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_.]+$/', $key);
        $this->assertLessThan(64, strlen($key));
    }

    public function testIsLimitedOnlyAboveTheLimit(): void
    {
        $this->assertFalse(FixedWindow::isLimited(1, 120));
        $this->assertFalse(FixedWindow::isLimited(120, 120));
        $this->assertTrue(FixedWindow::isLimited(121, 120));
    }

    public function testAZeroOrNegativeLimitDisablesTheLimiter(): void
    {
        $this->assertFalse(FixedWindow::isLimited(999999, 0));
        $this->assertFalse(FixedWindow::isLimited(999999, -5));
    }

    public function testRetryAfterIsTheTimeLeftInTheWindowAndAtLeastOne(): void
    {
        $this->assertSame(30, FixedWindow::retryAfter(1230));
        $this->assertSame(1, FixedWindow::retryAfter(1259));
        $this->assertSame(60, FixedWindow::retryAfter(1260));
    }
}
