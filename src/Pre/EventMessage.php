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

namespace GlpiPlugin\Gac\Pre;

/**
 * Pure: the one-line text written to GLPI's native history for each PRE event. The native
 * history only stores plain text of up to 255 characters, so the text is built here and cut.
 */
final class EventMessage
{
    private const MAX_LENGTH = 255;

    /** "Label (line): detail", each part optional; cut to the native history limit. */
    public static function compose(string $label, string $line = '', string $detail = ''): string
    {
        $text = trim($label);
        if (trim($line) !== '') {
            $text .= ' (' . trim($line) . ')';
        }
        if (trim($detail) !== '') {
            $text .= ': ' . trim($detail);
        }

        if (mb_strlen($text) > self::MAX_LENGTH) {
            $text = mb_substr($text, 0, self::MAX_LENGTH - 1) . '…';
        }
        return $text;
    }

    /**
     * "Field: old → new" for every field whose value changed, in the order of $labels.
     * Empty values read "—". Returns '' when nothing changed.
     *
     * @param array<string, string> $before values already formatted as text
     * @param array<string, string> $after  values already formatted as text
     * @param array<string, string> $labels field key => label
     */
    public static function diff(array $before, array $after, array $labels): string
    {
        $parts = [];
        foreach ($labels as $key => $label) {
            $old = trim((string) ($before[$key] ?? ''));
            $new = trim((string) ($after[$key] ?? ''));
            if ($old === $new) {
                continue;
            }
            $parts[] = sprintf('%s %s → %s', $label, $old === '' ? '—' : $old, $new === '' ? '—' : $new);
        }
        return implode('; ', $parts);
    }
}
