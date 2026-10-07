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

use GlpiPlugin\Gac\Monitor\PageRotation;
use PHPUnit\Framework\TestCase;

final class PageRotationTest extends TestCase
{
    public function testCanAddPageUpToTheLimit(): void
    {
        $this->assertTrue(PageRotation::canAddPage(0));
        $this->assertTrue(PageRotation::canAddPage(PageRotation::MAX_PAGES - 1));
        $this->assertFalse(PageRotation::canAddPage(PageRotation::MAX_PAGES));
        $this->assertFalse(PageRotation::canAddPage(PageRotation::MAX_PAGES + 3));
    }

    public function testClampRotationKeepsNullAndEnforcesTheMinimum(): void
    {
        $this->assertNull(PageRotation::clampRotation(null));
        $this->assertSame(PageRotation::MIN_ROTATION_SECONDS, PageRotation::clampRotation(1));
        $this->assertSame(PageRotation::MIN_ROTATION_SECONDS, PageRotation::clampRotation(-30));
        $this->assertSame(45, PageRotation::clampRotation(45));
    }

    public function testNextPosition(): void
    {
        $this->assertSame(1, PageRotation::nextPosition([]));
        $this->assertSame(4, PageRotation::nextPosition([1, 3, 2]));
        $this->assertSame(8, PageRotation::nextPosition([7]));
    }

    public function testPageTitleFallsBackToTheSavedSearchName(): void
    {
        $this->assertSame('Novos', PageRotation::pageTitle('Novos', 'Pesquisa X'));
        $this->assertSame('Novos', PageRotation::pageTitle('  Novos  ', 'Pesquisa X'));
        $this->assertSame('Pesquisa X', PageRotation::pageTitle('', 'Pesquisa X'));
        $this->assertSame('Pesquisa X', PageRotation::pageTitle("   \t", 'Pesquisa X'));
    }
}
