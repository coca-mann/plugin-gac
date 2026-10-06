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

use GlpiPlugin\Gac\Sso\GoogleButton;
use PHPUnit\Framework\TestCase;

final class GoogleButtonTest extends TestCase
{
    public function testRendersTheLinkTheLabelAndTheFourColourLogo(): void
    {
        $html = GoogleButton::render('/plugins/gac/front/sso/start.php', 'Entrar com Google');

        self::assertStringContainsString("href='/plugins/gac/front/sso/start.php'", $html);
        self::assertStringContainsString('<span>Entrar com Google</span>', $html);
        foreach (['#EA4335', '#4285F4', '#FBBC05', '#34A853'] as $colour) {
            self::assertStringContainsString($colour, $html);
        }
        self::assertStringContainsString("aria-hidden='true'", $html);
    }

    public function testEscapesTheUrlAndTheLabel(): void
    {
        $html = GoogleButton::render("/start.php?a=1&redirect='x", "<b>Entrar</b>");

        self::assertStringContainsString('a=1&amp;redirect=&#039;x', $html);
        self::assertStringContainsString('&lt;b&gt;Entrar&lt;/b&gt;', $html);
        self::assertStringNotContainsString('<b>', $html);
    }
}
