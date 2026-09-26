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

use GlpiPlugin\Gac\Pre\ConfigMenu;
use GlpiPlugin\Gac\Pre\PreMenu;
use Plugin;

/**
 * The plugin's own top-level entry in the sidebar. Each module contributes its entries here,
 * keyed by the lowercase item name its pages pass to Html::header().
 */
class GacMenu
{
    /** Sector key in GLPI's menu array (see Hooks::MENU_TOADD). */
    public const SECTOR = 'gac';

    /** Item keys, used by the pages as the third argument of Html::header(). */
    public const ITEM_PRE = 'pre';
    public const ITEM_CONFIG = 'config';

    /** Single source of the plugin name: the "name" field of plugin_version_gac(). */
    public static function pluginName(): string
    {
        return (string) Plugin::getInfo('gac', 'name');
    }

    public static function getMenuName($nb = 0): string
    {
        return self::pluginName();
    }

    public static function getIcon(): string
    {
        return 'ti ti-tools';
    }

    public static function getMenuContent(): array
    {
        $entries = [];
        $pre = PreMenu::getMenuContent();
        if ($pre !== []) {
            $entries[self::ITEM_PRE] = $pre;
        }
        $config = ConfigMenu::getMenuContent();
        if ($config !== []) {
            $entries[self::ITEM_CONFIG] = $config;
        }
        if ($entries === []) {
            return [];
        }

        // "title" names the sidebar entry; "is_multi_entries" makes GLPI merge the entries
        // below as the sub-items of that entry.
        return ['title' => self::pluginName(), 'is_multi_entries' => true] + $entries;
    }
}
