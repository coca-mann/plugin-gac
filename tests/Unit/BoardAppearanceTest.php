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

use GlpiPlugin\Gac\Monitor\BoardAppearance;
use PHPUnit\Framework\TestCase;

final class BoardAppearanceTest extends TestCase
{
    public function testIsValidTheme(): void
    {
        $this->assertTrue(BoardAppearance::isValidTheme('dark'));
        $this->assertTrue(BoardAppearance::isValidTheme('light'));
        $this->assertFalse(BoardAppearance::isValidTheme('bogus'));
    }

    public function testIsValidFontSize(): void
    {
        $this->assertTrue(BoardAppearance::isValidFontSize(1));
        $this->assertTrue(BoardAppearance::isValidFontSize(5));
        $this->assertFalse(BoardAppearance::isValidFontSize(0));
        $this->assertFalse(BoardAppearance::isValidFontSize(6));
    }

    public function testFontSizeRemIsMonotonicallyIncreasing(): void
    {
        $previous = 0.0;
        foreach (range(1, 5) as $size) {
            $rem = (float) rtrim(BoardAppearance::fontSizeRem($size), 'rem');
            $this->assertGreaterThan($previous, $rem);
            $previous = $rem;
        }
    }

    public function testFontSizeRemFallsBackToDefaultForUnknownSize(): void
    {
        $this->assertSame(BoardAppearance::fontSizeRem(BoardAppearance::DEFAULT_FONT_SIZE), BoardAppearance::fontSizeRem(42));
    }
}
