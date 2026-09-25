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

use GlpiPlugin\Gac\Pre\EntityChain;
use PHPUnit\Framework\TestCase;

final class EntityChainTest extends TestCase
{
    public function testAChildGoesToItsParent(): void
    {
        self::assertSame(1, EntityChain::parentOf(6, '1'));
        self::assertSame(0, EntityChain::parentOf(1, 0));
    }

    public function testTheRootWithAMinusOneParentEndsTheChain(): void
    {
        self::assertNull(EntityChain::parentOf(0, '-1'));
        self::assertNull(EntityChain::parentOf(0, -1));
    }

    public function testTheRootWithANullParentEndsTheChain(): void
    {
        // Databases migrated from older GLPI versions: the root's parent is NULL, not -1.
        self::assertNull(EntityChain::parentOf(0, null));
    }

    public function testAnEntityPointingAtItselfEndsTheChain(): void
    {
        self::assertNull(EntityChain::parentOf(0, '0'));
        self::assertNull(EntityChain::parentOf(5, 5));
    }

    public function testANonNumericParentEndsTheChain(): void
    {
        self::assertNull(EntityChain::parentOf(3, ''));
        self::assertNull(EntityChain::parentOf(3, 'abc'));
    }
}
