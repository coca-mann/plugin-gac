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

use GlpiPlugin\Gac\Monitor\EntityLevels;
use PHPUnit\Framework\TestCase;

final class EntityLevelsTest extends TestCase
{
    public function testIsValid(): void
    {
        $this->assertTrue(EntityLevels::isValid(1));
        $this->assertTrue(EntityLevels::isValid(3));
        $this->assertFalse(EntityLevels::isValid(0));
        $this->assertFalse(EntityLevels::isValid(4));
    }

    public function testSanitizeFallsBackToDefaultForInvalidValue(): void
    {
        $this->assertSame(EntityLevels::DEFAULT_LEVELS, EntityLevels::sanitize(0));
        $this->assertSame(EntityLevels::DEFAULT_LEVELS, EntityLevels::sanitize(99));
        $this->assertSame(2, EntityLevels::sanitize(2));
    }

    public function testTruncateKeepsOnlyTrailingSegments(): void
    {
        $full = 'Grupo Aparício Carvalho > Fimca > Campus Norte > Setor de TI';

        $this->assertSame('Setor de TI', EntityLevels::truncate($full, 1));
        $this->assertSame('Campus Norte > Setor de TI', EntityLevels::truncate($full, 2));
        $this->assertSame('Fimca > Campus Norte > Setor de TI', EntityLevels::truncate($full, 3));
    }

    public function testTruncateReturnsUnchangedWhenPathIsShorterThanLevels(): void
    {
        $this->assertSame('Root', EntityLevels::truncate('Root', 3));
    }

    public function testTruncateHandlesEmptyString(): void
    {
        $this->assertSame('', EntityLevels::truncate('', 2));
        $this->assertSame('', EntityLevels::truncate('   ', 2));
    }

    public function testTruncateSanitizesAnInvalidLevelCount(): void
    {
        $full = 'A > B > C > D';
        $this->assertSame(EntityLevels::truncate($full, EntityLevels::DEFAULT_LEVELS), EntityLevels::truncate($full, 99));
    }
}
