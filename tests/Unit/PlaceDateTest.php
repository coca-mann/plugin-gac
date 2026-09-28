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

use GlpiPlugin\Gac\Ltbp\PlaceDate;
use PHPUnit\Framework\TestCase;

final class PlaceDateTest extends TestCase
{
    public function testTownStateAndDate(): void
    {
        $this->assertSame('Porto Velho/RO, 26/09/2026', PlaceDate::format('Porto Velho', 'RO', '2026-09-26'));
    }

    public function testTownWithoutState(): void
    {
        $this->assertSame('Porto Velho, 26/09/2026', PlaceDate::format(' Porto Velho ', '', '2026-09-26'));
    }

    public function testStateWithoutTown(): void
    {
        $this->assertSame('RO, 26/09/2026', PlaceDate::format('', 'RO', '2026-09-26'));
    }

    public function testNoPlacePrintsOnlyTheDate(): void
    {
        $this->assertSame('26/09/2026', PlaceDate::format('', '', '2026-09-26'));
    }

    public function testAnInvalidDateIsReturnedAsIs(): void
    {
        $this->assertSame('Porto Velho/RO, sem data', PlaceDate::format('Porto Velho', 'RO', 'sem data'));
    }
}
