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

use GlpiPlugin\Gac\Sso\WorkspaceKey;
use PHPUnit\Framework\TestCase;

final class WorkspaceKeyTest extends TestCase
{
    public function testFromNameMakesALowercaseSlug(): void
    {
        $this->assertSame('principal', WorkspaceKey::fromName('Principal'));
        $this->assertSame('metropolitana', WorkspaceKey::fromName('Metropolitana'));
    }

    public function testFromNameRemovesAccentsAndPunctuation(): void
    {
        $this->assertSame('grupo-aparicio-carvalho', WorkspaceKey::fromName('Grupo Aparício Carvalho'));
        $this->assertSame('fimca', WorkspaceKey::fromName('  --Fimca!!  '));
        $this->assertSame('coracao-nacao', WorkspaceKey::fromName('Coração Nação'));
    }

    public function testFromNameFallsBackWhenNothingUsableIsLeft(): void
    {
        $this->assertSame(WorkspaceKey::FALLBACK, WorkspaceKey::fromName(''));
        $this->assertSame(WorkspaceKey::FALLBACK, WorkspaceKey::fromName('???'));
        $this->assertTrue(WorkspaceKey::isValid(WorkspaceKey::FALLBACK));
    }

    public function testFromNameNeverExceedsTheLimitNorEndsWithAHyphen(): void
    {
        $key = WorkspaceKey::fromName(str_repeat('a b ', 30));

        $this->assertLessThanOrEqual(WorkspaceKey::MAX_LENGTH, strlen($key));
        $this->assertTrue(WorkspaceKey::isValid($key), $key);
    }

    public function testIsValid(): void
    {
        foreach (['principal', 'a-b-2', 'x1', str_repeat('a', 40)] as $good) {
            $this->assertTrue(WorkspaceKey::isValid($good), $good);
        }
        foreach (['', 'Principal', '-a', 'a-', 'a--b', 'a_b', 'a b', 'acento-é', str_repeat('a', 41)] as $bad) {
            $this->assertFalse(WorkspaceKey::isValid($bad), $bad);
        }
    }

    public function testUniqueAddsASuffixOnCollision(): void
    {
        $this->assertSame('principal', WorkspaceKey::unique('principal', []));
        $this->assertSame('principal-2', WorkspaceKey::unique('principal', ['principal']));
        $this->assertSame('principal-3', WorkspaceKey::unique('principal', ['principal', 'principal-2']));
    }

    public function testUniqueKeepsTheSuffixInsideTheLimit(): void
    {
        $base   = str_repeat('a', 40);
        $unique = WorkspaceKey::unique($base, [$base]);

        $this->assertSame(40, strlen($unique));
        $this->assertStringEndsWith('-2', $unique);
        $this->assertTrue(WorkspaceKey::isValid($unique));
    }
}
