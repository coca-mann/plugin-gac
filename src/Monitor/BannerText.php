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
 * Pure: turns a ticket's description (rich text HTML, which GLPI usually stores HTML-encoded)
 * into the short plain text the new-ticket banner shows (spec M18). The result is meant for
 * textContent, never for innerHTML.
 */
final class BannerText
{
    /** Block-level tags become a space, so "<p>a</p><p>b</p>" reads "a b" and not "ab". */
    private const BLOCK_TAGS = '#</?(?:p|br|div|li|ul|ol|h[1-6]|tr|td|th|table|blockquote|hr)\b[^>]*>#i';

    public static function summarize(string $html, int $max): string
    {
        if ($max < 2) {
            return '';
        }

        // GLPI keeps the content as "&lt;p&gt;...", so decode once to get back to real tags.
        $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('#<(script|style)\b.*?</\1>#is', ' ', $text) ?? '';
        $text = preg_replace(self::BLOCK_TAGS, ' ', $text) ?? '';
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        if (mb_strlen($text) <= $max) {
            return $text;
        }

        // Leaves room for the ellipsis and prefers to end on a whole word.
        $cut   = mb_substr($text, 0, $max - 1);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > 0) {
            $cut = mb_substr($cut, 0, $space);
        }
        return rtrim($cut) . '…';
    }
}
