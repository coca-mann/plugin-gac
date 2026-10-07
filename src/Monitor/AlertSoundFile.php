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

namespace GlpiPlugin\Gac\Monitor;

/**
 * Pure: the rules for the alert sound file an administrator uploads (spec M17) — which types are
 * accepted, how big it may be, how it is named on disk. No GLPI calls and no filesystem access:
 * the caller passes in the size and the MIME type it detected from the file's content.
 */
final class AlertSoundFile
{
    /** Half a megabyte is minutes of beeps: a notification sound is a second or two. */
    public const MAX_BYTES = 524288;

    public const ERROR_EXTENSION = 'extension';
    public const ERROR_EMPTY     = 'empty';
    public const ERROR_SIZE      = 'size';
    public const ERROR_CONTENT   = 'content';

    /** @var array<string, list<string>> extension => MIME types the content detection may report */
    private const TYPES = [
        'mp3' => ['audio/mpeg', 'audio/mp3'],
        'ogg' => ['audio/ogg', 'application/ogg', 'audio/vorbis'],
        'wav' => ['audio/wav', 'audio/x-wav', 'audio/vnd.wave', 'audio/wave'],
    ];

    /** @var array<string, string> extension => the type the sound is served with */
    private const SERVED_AS = [
        'mp3' => 'audio/mpeg',
        'ogg' => 'audio/ogg',
        'wav' => 'audio/wav',
    ];

    /** The accepted extension of an uploaded file name, lower-cased; null when not accepted. */
    public static function extensionOf(string $name): ?string
    {
        $dot = strrpos($name, '.');
        if ($dot === false) {
            return null;
        }
        $extension = strtolower(substr($name, $dot + 1));
        return array_key_exists($extension, self::TYPES) ? $extension : null;
    }

    /**
     * @param string $detectedMime the type found by looking at the file's content, not the one
     *                             the browser announced (that one is user-controlled)
     * @return string|null one of the ERROR_* codes, null when the file is acceptable
     */
    public static function validate(string $originalName, int $size, string $detectedMime): ?string
    {
        $extension = self::extensionOf($originalName);
        if ($extension === null) {
            return self::ERROR_EXTENSION;
        }
        if ($size <= 0) {
            return self::ERROR_EMPTY;
        }
        if ($size > self::MAX_BYTES) {
            return self::ERROR_SIZE;
        }
        if (!in_array(strtolower($detectedMime), self::TYPES[$extension], true)) {
            return self::ERROR_CONTENT;
        }
        return null;
    }

    /** The name the file gets on disk: fixed prefix + 12 hex characters of its hash + extension. */
    public static function storedName(string $extension, string $hash): string
    {
        $hex = substr(str_pad(preg_replace('/[^0-9a-f]/', '', strtolower($hash)) ?? '', 12, '0'), 0, 12);
        return 'alert-' . $hex . '.' . $extension;
    }

    /** Whether a stored name (from the settings) is one this class could have produced. */
    public static function isValidStoredName(string $name): bool
    {
        return preg_match('/^alert-[0-9a-f]{12}\.(mp3|ogg|wav)$/', $name) === 1;
    }

    /** The Content-Type to serve a stored file with. */
    public static function mimeFor(string $storedName): string
    {
        $extension = strtolower(substr($storedName, (int) strrpos($storedName, '.') + 1));
        return self::SERVED_AS[$extension] ?? 'application/octet-stream';
    }

    /** The original file name as shown in the configuration page: base name only, no control characters. */
    public static function displayName(string $original): string
    {
        $base = basename(str_replace('\\', '/', $original));
        $base = preg_replace('/[\x00-\x1f\x7f]/', '', $base) ?? '';
        return substr($base, 0, 120);
    }
}
