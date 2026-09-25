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

namespace GlpiPlugin\Gac\Tests\Unit;

use GlpiPlugin\Gac\Pre\ReportFormatter;
use PHPUnit\Framework\TestCase;

final class ReportFormatterTest extends TestCase
{
    public function testFullAddress(): void
    {
        $this->assertSame(
            'Av. Tancredo Neves, 1234 — 76870-000 — Ariquemes/RO',
            ReportFormatter::addressLine('Av. Tancredo Neves, 1234', '76870-000', 'Ariquemes', 'RO')
        );
    }

    public function testSkipsEmptyParts(): void
    {
        $this->assertSame('76870-000 — Ariquemes', ReportFormatter::addressLine('', '76870-000', 'Ariquemes', ''));
        $this->assertSame('Rua A', ReportFormatter::addressLine(' Rua A ', '', '', ''));
        $this->assertSame('', ReportFormatter::addressLine('', '', '', ''));
    }

    public function testStateWithoutTownStandsAlone(): void
    {
        $this->assertSame('RO', ReportFormatter::addressLine('', '', '', 'RO'));
    }

    public function testBrazilianDate(): void
    {
        $this->assertSame('25/09/2026', ReportFormatter::date('2026-09-25'));
        $this->assertSame('01/01/2027', ReportFormatter::date('2027-01-01'));
    }

    public function testInvalidOrEmptyDateIsReturnedAsIs(): void
    {
        $this->assertSame('', ReportFormatter::date(''));
        $this->assertSame('não é data', ReportFormatter::date('não é data'));
        $this->assertSame('2026-13-45', ReportFormatter::date('2026-13-45'));
    }
}
