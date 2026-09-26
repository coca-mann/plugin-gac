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

namespace GlpiPlugin\Gac\Ltbp;

/**
 * What may be uploaded as the signed laudo and how a stored file may be served. Pure rules:
 * the signed file is streamed back from the GLPI origin, so an html/svg upload must never be
 * displayed inline (stored XSS).
 */
final class FilePolicy
{
    /** extension => the real mime types accepted for it */
    private const SIGNED = [
        'pdf'  => ['application/pdf'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
    ];

    private const INLINE_MIMES = ['application/pdf', 'image/png', 'image/jpeg'];

    /** @param string|null $detectedMime the real mime of the content, or null when it could not be read */
    public static function signedAllowed(string $filename, ?string $detectedMime): bool
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!isset(self::SIGNED[$ext])) {
            return false;
        }
        return $detectedMime === null || in_array(strtolower($detectedMime), self::SIGNED[$ext], true);
    }

    /** "inline" only for the types a browser renders without running script; everything else downloads. */
    public static function disposition(string $mime): string
    {
        return in_array(strtolower(trim(explode(';', $mime)[0])), self::INLINE_MIMES, true) ? 'inline' : 'attachment';
    }
}
