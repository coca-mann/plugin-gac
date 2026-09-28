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

use GlpiPlugin\Gac\Ltbp\LtbpReason;

/**
 * The plugin's shared "Configurações" sidebar entry. A module that manages a standalone
 * catalog from inside the config page (LTBP's reasons, for instance) registers its own "add"
 * link here as a named option, so front/<module>/<catalog>.php can pass that option to
 * Html::header() and get GLPI's native "+" context-link button and an extra breadcrumb segment
 * for that screen, without every other config screen also showing them (both read off
 * menu[sector]['content'][item]['options'][option]: see templates/layout/parts/breadcrumbs.html.twig
 * and context_links.html.twig).
 */
class ConfigMenu
{
    public static function getMenuName($nb = 0): string
    {
        return __('Configurações', 'gac');
    }

    public static function getMenuContent(): array
    {
        if (!Features::canConfigureAny()) {
            return [];
        }
        $menu = [
            'title' => __('Configurações', 'gac'),
            'page'  => '/plugins/gac/front/config.php',
            'icon'  => 'ti ti-settings',
        ];
        if (LtbpReason::canView()) {
            // "page" (with "title") is what makes breadcrumbs.html.twig add the extra
            // "Configurações > Motivos de baixa" segment; "links.add" is the separate
            // "+" context-link button, shown only when the user can also create one.
            $option = [
                'title' => LtbpReason::getTypeName(2),
                'page'  => LtbpReason::getSearchURL(false),
                'icon'  => LtbpReason::getIcon(),
            ];
            if (LtbpReason::canCreate()) {
                $option['links'] = ['add' => LtbpReason::getFormURL(false)];
            }
            $menu['options']['ltbpreason'] = $option;
        }
        return $menu;
    }
}
