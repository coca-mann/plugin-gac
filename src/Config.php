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

namespace GlpiPlugin\Gac;

use GlpiPlugin\Gac\Pre\PreConfigSection;
use Html;

/**
 * Builds the plugin configuration page. Knows nothing about any module's keys: it only
 * walks the registered sections.
 */
final class Config
{
    /** @return list<ConfigSection> */
    public static function sections(): array
    {
        return [
            new PreConfigSection(),
        ];
    }

    public static function handlePost(array $post): void
    {
        foreach (self::sections() as $section) {
            if (($post['section'] ?? '') === $section->key()) {
                $section->handlePost($post);
                return;
            }
        }
    }

    public static function renderPage(): void
    {
        echo "<div class='container-fluid'>";
        foreach (self::sections() as $section) {
            echo "<form method='post' action='" . htmlescape(self::pageUrl()) . "' class='card mb-4'>";
            echo "<div class='card-header'><h3 class='card-title'>" . htmlescape($section->title()) . '</h3></div>';
            echo "<div class='card-body'>";
            echo $section->render();
            echo '</div>';
            echo "<div class='card-footer'>";
            echo "<input type='hidden' name='section' value='" . htmlescape($section->key()) . "'>";
            echo Html::submit(_sx('button', 'Save'), ['class' => 'btn btn-primary', 'name' => 'save', 'icon' => 'ti ti-device-floppy']);
            echo '</div>';
            Html::closeForm();
        }
        echo '</div>';
    }

    private static function pageUrl(): string
    {
        global $CFG_GLPI;
        return $CFG_GLPI['root_doc'] . '/plugins/gac/front/config.php';
    }
}
