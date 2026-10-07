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
 * The stable identifier of a Google Workspace (spec S25): lowercase `[a-z0-9-]`, at most 40
 * characters. Rules refer to it, so unlike the name it never changes once saved. Pure.
 */
final class WorkspaceKey
{
    public const MAX_LENGTH = 40;
    public const FALLBACK   = 'workspace';

    private const ACCENTS = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ç' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y',
    ];

    public static function isValid(string $key): bool
    {
        return strlen($key) <= self::MAX_LENGTH && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $key) === 1;
    }

    /** The key a workspace gets from its name when it is created. */
    public static function fromName(string $name): string
    {
        $text = strtr(mb_strtolower($name, 'UTF-8'), self::ACCENTS);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $text), '-');
        $slug = rtrim(substr($slug, 0, self::MAX_LENGTH), '-');

        return $slug === '' ? self::FALLBACK : $slug;
    }

    /**
     * $base, or $base with the first free "-2", "-3"... suffix.
     *
     * @param list<string> $taken
     */
    public static function unique(string $base, array $taken): string
    {
        if (!in_array($base, $taken, true)) {
            return $base;
        }

        for ($n = 2;; $n++) {
            $suffix    = '-' . $n;
            $candidate = rtrim(substr($base, 0, self::MAX_LENGTH - strlen($suffix)), '-') . $suffix;
            if (!in_array($candidate, $taken, true)) {
                return $candidate;
            }
        }
    }
}
