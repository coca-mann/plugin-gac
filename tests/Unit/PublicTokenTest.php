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

use GlpiPlugin\Gac\Monitor\PublicToken;
use PHPUnit\Framework\TestCase;

final class PublicTokenTest extends TestCase
{
    public function testGenerateProducesAWellFormedToken(): void
    {
        $this->assertTrue(PublicToken::isWellFormed(PublicToken::generate()));
    }

    public function testGenerateIsNotConstant(): void
    {
        $this->assertNotSame(PublicToken::generate(), PublicToken::generate());
    }

    public function testIsWellFormedRejectsGarbage(): void
    {
        $this->assertFalse(PublicToken::isWellFormed(''));
        $this->assertFalse(PublicToken::isWellFormed('abc'));
        $this->assertFalse(PublicToken::isWellFormed(str_repeat('g', 48)));
        $this->assertFalse(PublicToken::isWellFormed(str_repeat('a', 47)));
    }
}
