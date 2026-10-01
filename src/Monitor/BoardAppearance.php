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

/** Pure: the Tela's visual customization — theme (M-decision pending) and table font size. */
final class BoardAppearance
{
    public const THEME_DARK  = 'dark';
    public const THEME_LIGHT = 'light';

    /** @var list<string> */
    public const THEMES = [self::THEME_DARK, self::THEME_LIGHT];

    public const DEFAULT_THEME = self::THEME_DARK;

    public const MIN_FONT_SIZE     = 1;
    public const MAX_FONT_SIZE     = 5;
    public const DEFAULT_FONT_SIZE = 3;

    /** @var array<int, string> font size level => CSS rem value, table rows only */
    private const FONT_SIZE_REM = [
        1 => '0.85rem',
        2 => '1rem',
        3 => '1.15rem',
        4 => '1.4rem',
        5 => '1.7rem',
    ];

    public static function isValidTheme(string $theme): bool
    {
        return in_array($theme, self::THEMES, true);
    }

    public static function isValidFontSize(int $size): bool
    {
        return $size >= self::MIN_FONT_SIZE && $size <= self::MAX_FONT_SIZE;
    }

    public static function fontSizeRem(int $size): string
    {
        return self::FONT_SIZE_REM[$size] ?? self::FONT_SIZE_REM[self::DEFAULT_FONT_SIZE];
    }
}
