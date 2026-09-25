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
 * Port of the legacy Django extractor: pulls the "Informações adicionais" text out of the
 * ticket HTML. Only used to pre-fill the editable supplier description (decision 2 of the plan).
 */
final class TicketObservationExtractor
{
    private const TITLE = 'Informações adicionais';

    public static function extract(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $doc->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($doc);
        $titles = $xpath->query('//b|//strong');
        $title = null;
        foreach ($titles as $candidate) {
            if (str_contains($candidate->textContent, self::TITLE)) {
                $title = $candidate;
                break;
            }
        }
        if ($title === null) {
            return '';
        }

        // Main strategy: the first <p> after the title anywhere in the document order.
        $following = $xpath->query('following::p', $title);
        foreach ($following as $p) {
            if (!self::isInside($p, $title)) {
                $text = self::clean($p->textContent);
                if ($text !== '') {
                    return $text;
                }
            }
        }

        // Fallback: loose text right after the title.
        for ($node = $title->nextSibling; $node !== null; $node = $node->nextSibling) {
            $text = self::clean($node->textContent);
            if ($text === '' || $text === ':') {
                continue;
            }
            return ltrim($text, ": \t\n\r");
        }
        return '';
    }

    private static function isInside(\DOMNode $node, \DOMNode $ancestor): bool
    {
        for ($p = $node->parentNode; $p !== null; $p = $p->parentNode) {
            if ($p->isSameNode($ancestor)) {
                return true;
            }
        }
        return false;
    }

    private static function clean(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }
}
