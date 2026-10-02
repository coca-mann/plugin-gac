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
 * Pure: how many trailing segments of a GLPI entity's `completename` (the standard " > "
 * separated breadcrumb, e.g. "Grupo > Fimca > Campus > Setor") a Tela shows in its "Entidade"
 * column. A deep entity tree is unreadable on a TV, so the admin picks 1 to 3 segments counted
 * from the entity itself upward, instead of always showing the full path.
 */
final class EntityLevels
{
    public const MIN_LEVELS     = 1;
    public const MAX_LEVELS     = 3;
    public const DEFAULT_LEVELS = 3;

    public static function isValid(int $levels): bool
    {
        return $levels >= self::MIN_LEVELS && $levels <= self::MAX_LEVELS;
    }

    /** Clamps an untrusted/stored value to a valid level, falling back to the default. */
    public static function sanitize(int $levels): int
    {
        return self::isValid($levels) ? $levels : self::DEFAULT_LEVELS;
    }

    /**
     * Keeps only the last $levels segments of $completename (the entity itself plus however many
     * ancestors fit), joined back with the same " > " separator. A path shorter than $levels is
     * returned unchanged.
     */
    public static function truncate(string $completename, int $levels): string
    {
        $levels = self::sanitize($levels);
        $trimmed = trim($completename);
        if ($trimmed === '') {
            return '';
        }
        $segments = explode(' > ', $trimmed);
        return implode(' > ', array_slice($segments, -$levels));
    }
}
