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

use GlpiPlugin\Gac\Monitor\BannerText;
use PHPUnit\Framework\TestCase;

final class BannerTextTest extends TestCase
{
    public function testStripsPlainHtml(): void
    {
        $this->assertSame(
            'Descrição do problema O equipamento não liga.',
            BannerText::summarize('<p><b>Descrição do problema</b></p><p>O equipamento não liga.</p>', 200)
        );
    }

    public function testStripsHtmlStoredAsEntities(): void
    {
        // GLPI keeps the ticket content HTML-encoded in the database.
        $this->assertSame(
            'Nobreak desliga na falta de energia.',
            BannerText::summarize('&lt;p&gt;Nobreak desliga na falta de energia.&lt;/p&gt;', 200)
        );
        $this->assertSame('a & b', BannerText::summarize('&lt;p&gt;a &amp;amp; b&lt;/p&gt;', 200));
    }

    public function testBlockTagsBecomeSpacesSoWordsDoNotGlue(): void
    {
        $this->assertSame('um dois três', BannerText::summarize('<p>um</p><p>dois</p><br>três', 200));
        $this->assertSame('a b', BannerText::summarize("<div>a</div>\n\n<div>b</div>", 200));
    }

    public function testCollapsesWhitespaceAndTrims(): void
    {
        $this->assertSame('a b c', BannerText::summarize("  a \t b \n\n c  ", 200));
    }

    public function testTruncatesAtAWordBoundaryWithAnEllipsis(): void
    {
        $this->assertSame('uma duas…', BannerText::summarize('uma duas três quatro', 12));
        $this->assertSame('uma duas três quatro', BannerText::summarize('uma duas três quatro', 20));
    }

    public function testTruncatesALongWordWithoutASpaceToTheLimit(): void
    {
        $this->assertSame('aaaaa…', BannerText::summarize(str_repeat('a', 50), 6));
    }

    public function testIsSafeForAnEmptyOrTagOnlyContent(): void
    {
        $this->assertSame('', BannerText::summarize('', 100));
        $this->assertSame('', BannerText::summarize('<p> </p><br>', 100));
    }

    public function testNeverReturnsMarkup(): void
    {
        $out = BannerText::summarize('<script>alert(1)</script><p>texto</p>', 100);
        $this->assertStringNotContainsString('<', $out);
        $this->assertStringContainsString('texto', $out);
    }
}
