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

/** Pure: limits and normalization of a Tela's page rotation (spec M11, M12). */
final class PageRotation
{
    public const MAX_PAGES = 8;

    public const MIN_ROTATION_SECONDS     = 5;
    public const DEFAULT_ROTATION_SECONDS = 20;

    public static function canAddPage(int $currentCount): bool
    {
        return $currentCount < self::MAX_PAGES;
    }

    /** A Tela's own rotation override; null (use the global default) stays null. */
    public static function clampRotation(?int $seconds): ?int
    {
        if ($seconds === null) {
            return null;
        }
        return max(self::MIN_ROTATION_SECONDS, $seconds);
    }

    /** @param list<int> $positions positions already used by the Tela's pages */
    public static function nextPosition(array $positions): int
    {
        return $positions === [] ? 1 : max($positions) + 1;
    }

    /** The label shown in the page selector: the page's own title, else its saved search's name. */
    public static function pageTitle(string $title, string $savedSearchName): string
    {
        $title = trim($title);
        return $title !== '' ? $title : $savedSearchName;
    }
}
