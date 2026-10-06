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
 * Pure helpers over Google Workspace org unit paths (spec S4, S9). A path is compared
 * case-insensitively and by whole segments: "/a/b" contains "/a/b/c" but not "/a/bc".
 */
final class OuPath
{
    public static function normalize(string $path): string
    {
        $p = mb_strtolower(trim($path));
        $p = str_replace('\\', '/', $p);
        $p = (string) preg_replace('#/+#', '/', $p);

        return '/' . trim($p, '/');
    }

    /** @return list<string> */
    public static function segments(string $path): array
    {
        $normalized = self::normalize($path);

        return $normalized === '/' ? [] : explode('/', substr($normalized, 1));
    }

    /**
     * The path and every ancestor, shallowest first: "/a/b/c" gives ["/a", "/a/b", "/a/b/c"].
     * The root OU gives ["/"].
     *
     * @return list<string>
     */
    public static function ancestors(string $path): array
    {
        $segments = self::segments($path);
        if ($segments === []) {
            return ['/'];
        }

        $out = [];
        $acc = '';
        foreach ($segments as $segment) {
            $acc .= '/' . $segment;
            $out[] = $acc;
        }

        return $out;
    }

    /** True when $path is $base itself or sits below it, comparing whole segments. */
    public static function isUnder(string $path, string $base): bool
    {
        $p = self::normalize($path);
        $b = self::normalize($base);

        return $b === '/' || $p === $b || str_starts_with($p, $b . '/');
    }
}
