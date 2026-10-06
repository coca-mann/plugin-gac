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

use GlpiPlugin\Gac\Sso\OuPath;
use PHPUnit\Framework\TestCase;

final class OuPathTest extends TestCase
{
    public function testNormalizeLowercasesAndFixesSlashes(): void
    {
        $this->assertSame('/fimca/fimca.com.br', OuPath::normalize('/FIMCA/Fimca.com.br/'));
        $this->assertSame('/fimca/x', OuPath::normalize('fimca/x'));
        $this->assertSame('/a/b', OuPath::normalize('//a///b'));
        $this->assertSame('/a/b', OuPath::normalize('  /A\\B '));
    }

    public function testRootNormalizesToSingleSlash(): void
    {
        $this->assertSame('/', OuPath::normalize(''));
        $this->assertSame('/', OuPath::normalize('/'));
        $this->assertSame('/', OuPath::normalize('   '));
    }

    public function testSegments(): void
    {
        $this->assertSame(['fimca', 'fimca.com.br', 'ies-pvh'], OuPath::segments('/FIMCA/fimca.com.br/IES-PVH'));
        $this->assertSame([], OuPath::segments('/'));
    }

    public function testAncestorsAreShallowestFirstAndIncludeTheOuItself(): void
    {
        $this->assertSame(
            ['/fimca', '/fimca/fimca.com.br', '/fimca/fimca.com.br/ies-pvh'],
            OuPath::ancestors('/FIMCA/fimca.com.br/IES-PVH')
        );
        $this->assertSame(['/a'], OuPath::ancestors('/a'));
    }

    public function testAncestorsOfTheRootIsJustTheRoot(): void
    {
        $this->assertSame(['/'], OuPath::ancestors('/'));
    }

    public function testIsUnderMatchesSelfAndDescendants(): void
    {
        $this->assertTrue(OuPath::isUnder('/a/b', '/a/b'));
        $this->assertTrue(OuPath::isUnder('/A/B/C', '/a/b'));
        $this->assertTrue(OuPath::isUnder('/a/b', '/'));
    }

    public function testIsUnderRespectsSegmentBoundaries(): void
    {
        $this->assertFalse(OuPath::isUnder('/a/bc', '/a/b'));
        $this->assertFalse(OuPath::isUnder('/a', '/a/b'));
        $this->assertFalse(OuPath::isUnder('/x/b', '/a/b'));
    }
}
