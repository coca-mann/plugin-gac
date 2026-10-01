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
 * The curated set of Ticket columns a Tela can show, in the catalog's own order (not the
 * display order a Tela chooses). Pure: no GLPI calls. Search option ids verified against the
 * GLPI 11 core source (CommonITILObject::rawSearchOptions()); see the plan's "Decisões de
 * implementação" item 1. See spec section 5.3 and decision M4.
 */
final class ColumnCatalog
{
    private const CATALOG = [
        'id'           => ['search_option' => 2,  'computed' => false],
        'title'        => ['search_option' => 1,  'computed' => false],
        'entity'       => ['search_option' => 80, 'computed' => false],
        'status'       => ['search_option' => 12, 'computed' => false],
        'priority'     => ['search_option' => 3,  'computed' => false],
        'category'     => ['search_option' => 7,  'computed' => false],
        'requester'    => ['search_option' => 4,  'computed' => false],
        'technician'   => ['search_option' => 5,  'computed' => false],
        'group'        => ['search_option' => 8,  'computed' => false],
        'opening_date' => ['search_option' => 15, 'computed' => false],
        // Computed from the same raw date as 'opening_date', not its own search option value.
        'elapsed'      => ['search_option' => 15, 'computed' => true],
    ];

    /** @var list<string> */
    public const DEFAULT_COLUMNS = [
        'id', 'title', 'entity', 'status', 'priority', 'requester', 'technician', 'opening_date', 'elapsed',
    ];

    /** @return list<string> */
    public static function allKeys(): array
    {
        return array_keys(self::CATALOG);
    }

    public static function isValidKey(string $key): bool
    {
        return array_key_exists($key, self::CATALOG);
    }

    public static function isComputed(string $key): bool
    {
        return self::CATALOG[$key]['computed'] ?? false;
    }

    public static function searchOptionId(string $key): ?int
    {
        return self::CATALOG[$key]['search_option'] ?? null;
    }

    /** Stable key for a GLPI-bound label lookup (MonitorLabels::column()); identity for now. */
    public static function labelKey(string $key): string
    {
        return $key;
    }

    /**
     * Keeps only valid keys, dedupes, preserves the caller's order. Falls back to
     * DEFAULT_COLUMNS when nothing valid survives (a Tela is never left with an empty table).
     *
     * @param array<mixed> $keys
     * @return list<string>
     */
    public static function sanitize(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            if (is_string($key) && self::isValidKey($key) && !in_array($key, $out, true)) {
                $out[] = $key;
            }
        }
        return $out === [] ? self::DEFAULT_COLUMNS : $out;
    }

    /**
     * Search option ids to force-display for a chosen set of columns: always includes "id" (2,
     * needed to key each result row), plus each column's own option, plus the date behind
     * "elapsed" (15) even when "opening_date" itself was not chosen.
     *
     * @param list<string> $keys
     * @return list<int>
     */
    public static function searchOptionIdsFor(array $keys): array
    {
        $ids = [2];
        foreach ($keys as $key) {
            $id = self::searchOptionId($key);
            if ($id !== null) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }
}
