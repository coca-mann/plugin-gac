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

use GlpiPlugin\Gac\Sso\PhotoImage;
use PHPUnit\Framework\TestCase;

final class PhotoImageTest extends TestCase
{
    private static function image(string $format): string
    {
        if (!function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD is not available');
        }

        $img = imagecreatetruecolor(16, 16);
        ob_start();
        match ($format) {
            'jpeg' => imagejpeg($img),
            'png'  => imagepng($img),
            'gif'  => imagegif($img),
        };

        return (string) ob_get_clean();
    }

    public function testDecodeReadsWebSafeBase64(): void
    {
        $raw = "\xff\xfe\xfd\xfc>>??~~\xfb";
        $web = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        $this->assertMatchesRegularExpression('/[-_]/', $web, 'the sample must use the web-safe characters');
        $this->assertSame($raw, PhotoImage::decode($web));
    }

    public function testDecodeOfGarbageIsEmpty(): void
    {
        $this->assertSame('', PhotoImage::decode(''));
        $this->assertSame('', PhotoImage::decode('***'));
    }

    public function testJpegAndPngAreAccepted(): void
    {
        $this->assertSame('jpg', PhotoImage::extension(self::image('jpeg')));
        $this->assertSame('png', PhotoImage::extension(self::image('png')));
    }

    public function testOtherImageTypesAreRefused(): void
    {
        $this->assertNull(PhotoImage::extension(self::image('gif')));
    }

    public function testNonImagesAreRefused(): void
    {
        $this->assertNull(PhotoImage::extension(''));
        $this->assertNull(PhotoImage::extension('<?php echo 1;'));
        $this->assertNull(PhotoImage::extension('<svg xmlns="http://www.w3.org/2000/svg"></svg>'));
        $this->assertNull(PhotoImage::extension(substr(self::image('jpeg'), 0, 10)), 'truncated');
    }

    public function testAnImageOverTheLimitIsRefused(): void
    {
        $big = self::image('jpeg') . str_repeat("\0", PhotoImage::MAX_BYTES);

        $this->assertNull(PhotoImage::extension($big));
    }

    public function testTheContentDecidesNotTheName(): void
    {
        $this->assertSame('jpg', PhotoImage::extension(self::image('jpeg')), 'a JPEG is a JPEG whatever mime the caller claims');
    }
}
