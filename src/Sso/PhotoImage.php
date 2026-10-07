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

namespace GlpiPlugin\Gac\Sso;

/** Reads and checks the photo bytes the Directory API returns (spec S33). Pure. */
final class PhotoImage
{
    public const MAX_BYTES = 1048576;

    /** The Directory API sends the photo as web-safe base64 ("-" and "_"); empty when it is not valid. */
    public static function decode(string $photoData): string
    {
        $raw = base64_decode(strtr($photoData, '-_', '+/'), true);

        return $raw === false ? '' : $raw;
    }

    /**
     * The file extension for a JPEG or PNG within the size limit, null for anything else. The bytes
     * decide, not the mime type the caller was told.
     */
    public static function extension(string $bytes): ?string
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            return null;
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            return null;
        }

        return match ($info[2]) {
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG  => 'png',
            default        => null,
        };
    }
}
