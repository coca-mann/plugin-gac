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

use GlpiPlugin\Gac\Ltbp\LtbpNumber;
use PHPUnit\Framework\TestCase;

final class LtbpNumberTest extends TestCase
{
    public function testFormatPadsSequenceToThreeDigits(): void
    {
        $this->assertSame('LTBP-2026-001', LtbpNumber::format(2026, 1));
        $this->assertSame('LTBP-2026-047', LtbpNumber::format(2026, 47));
    }

    public function testFormatKeepsLongSequencesUntruncated(): void
    {
        $this->assertSame('LTBP-2026-1234', LtbpNumber::format(2026, 1234));
    }

    public function testParseRoundTrips(): void
    {
        $this->assertSame(['year' => 2026, 'seq' => 47], LtbpNumber::parse('LTBP-2026-047'));
        $this->assertSame(['year' => 2027, 'seq' => 1234], LtbpNumber::parse('LTBP-2027-1234'));
    }

    public function testParseRejectsGarbageAndOtherPrefixes(): void
    {
        $this->assertNull(LtbpNumber::parse('LTBP-26-1'));
        $this->assertNull(LtbpNumber::parse('PRE-2026-001'));
        $this->assertNull(LtbpNumber::parse('LT-2026-001'));
        $this->assertNull(LtbpNumber::parse(''));
    }
}
