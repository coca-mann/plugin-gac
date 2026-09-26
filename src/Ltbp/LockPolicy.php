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
 * Pure: which columns of an asset update are refused once the asset is written off (spec L14).
 * Everything is refused except the free comment and GLPI's own bookkeeping columns.
 */
final class LockPolicy
{
    private const ALLOWED = ['id', 'comment', 'date_mod', 'date_creation'];

    /**
     * @param array<string, mixed> $input  the update input ($item->input)
     * @param array<string, mixed> $fields the current values ($item->fields)
     * @return list<string> input keys that would change a locked column
     */
    public static function blockedFields(array $input, array $fields): array
    {
        $blocked = [];
        foreach ($input as $key => $value) {
            $key = (string) $key;
            if ($key === '' || $key[0] === '_' || in_array($key, self::ALLOWED, true) || !array_key_exists($key, $fields)) {
                continue;
            }
            if (is_array($value)) {
                continue;
            }
            if (!self::same($value, $fields[$key])) {
                $blocked[] = $key;
            }
        }
        return $blocked;
    }

    /**
     * NULL and '' are the same. Only decimal-looking strings ('10.0000', '10.50') are canonicalised
     * by stripping trailing zeros, so '10.0000' equals '10.00' and '10'. Everything else compares as
     * a plain string: never as floats, which would hide changes in serials ('000123' vs '123',
     * 20-digit values, '1e3' vs '1000').
     */
    private static function same(mixed $a, mixed $b): bool
    {
        $a = $a === null ? '' : trim((string) $a);
        $b = $b === null ? '' : trim((string) $b);
        $c = static fn(string $v): string => preg_match('/^-?\d+\.\d+$/', $v) === 1 ? rtrim(rtrim($v, '0'), '.') : $v;
        return $c($a) === $c($b);
    }
}
