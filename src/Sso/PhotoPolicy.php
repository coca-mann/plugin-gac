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

/**
 * Whether the login should copy the Google photo to the GLPI user (spec S33). It never replaces a
 * photo chosen by hand, never puts back one the user removed and does nothing when Google has none.
 * Pure.
 */
final class PhotoPolicy
{
    public const FETCH = 'fetch';
    public const SKIP  = 'skip';

    /**
     * @param string $googleEtag     thumbnailPhotoEtag of the Google account, empty when it has no photo
     * @param string $storedEtag     the etag the identity remembers from the last copy
     * @param string $currentPicture glpi_users.picture now (a path relative to the pictures folder)
     * @param string $ownedPicture   the path the module wrote last time, empty if it never wrote one
     */
    public static function decide(string $googleEtag, string $storedEtag, string $currentPicture, string $ownedPicture): string
    {
        // Google has no photo: leave whatever GLPI has.
        if ($googleEtag === '') {
            return self::SKIP;
        }

        // Nothing new since the last copy (this also respects a photo the user removed afterwards).
        if ($googleEtag === $storedEtag) {
            return self::SKIP;
        }

        // Only an empty picture or the one the module wrote may be written over.
        $isOurs = $ownedPicture !== '' && $currentPicture === $ownedPicture;

        return $currentPicture === '' || $isOurs ? self::FETCH : self::SKIP;
    }
}
