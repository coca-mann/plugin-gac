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

use GlpiPlugin\Gac\Ltbp\FilePolicy;
use PHPUnit\Framework\TestCase;

final class LtbpFilePolicyTest extends TestCase
{
    public function testSignedAcceptsOnlyPdfAndImages(): void
    {
        $this->assertTrue(FilePolicy::signedAllowed('laudo.PDF', 'application/pdf'));
        $this->assertTrue(FilePolicy::signedAllowed('scan.jpeg', 'image/jpeg'));
        $this->assertTrue(FilePolicy::signedAllowed('scan.png', null));
        $this->assertFalse(FilePolicy::signedAllowed('x.html', 'text/html'));
        $this->assertFalse(FilePolicy::signedAllowed('x.svg', 'image/svg+xml'));
        $this->assertFalse(FilePolicy::signedAllowed('noext', null));
    }

    public function testSignedRejectsAMismatchedRealMime(): void
    {
        $this->assertFalse(FilePolicy::signedAllowed('x.pdf', 'text/html'));
        $this->assertFalse(FilePolicy::signedAllowed('x.png', 'application/pdf'));
    }

    public function testOnlySafeTypesAreShownInline(): void
    {
        $this->assertSame('inline', FilePolicy::disposition('application/pdf'));
        $this->assertSame('inline', FilePolicy::disposition('image/png'));
        $this->assertSame('inline', FilePolicy::disposition('image/jpeg'));
        $this->assertSame('attachment', FilePolicy::disposition('text/html'));
        $this->assertSame('attachment', FilePolicy::disposition('image/svg+xml'));
        $this->assertSame('attachment', FilePolicy::disposition('application/xml'));
        $this->assertSame('attachment', FilePolicy::disposition(''));
    }
}
