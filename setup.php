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

use Glpi\Plugin\Hooks;
use GlpiPlugin\Gac\GacMenu;
use GlpiPlugin\Gac\ProfileRights;

/** @phpstan-ignore theCodingMachineSafe.function (safe to assume this isn't already defined) */
define('PLUGIN_GAC_VERSION', '0.3.0');

// Minimal GLPI version, inclusive
/** @phpstan-ignore theCodingMachineSafe.function (safe to assume this isn't already defined) */
define("PLUGIN_GAC_MIN_GLPI_VERSION", "11.0.0");

// Maximum GLPI version, exclusive
/** @phpstan-ignore theCodingMachineSafe.function (safe to assume this isn't already defined) */
define("PLUGIN_GAC_MAX_GLPI_VERSION", "11.0.99");

/**
 * Init hooks of the plugin.
 * REQUIRED
 */
function plugin_init_gac(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['gac'] = true;

    $plugin = new Plugin();
    if ($plugin->isInstalled('gac') && $plugin->isActivated('gac')) {
        // A new sector key needs an array of classes to be created as a top-level sidebar entry.
        $PLUGIN_HOOKS[Hooks::MENU_TOADD]['gac'] = [
            GacMenu::SECTOR => [GacMenu::class],
        ];

        // Gear icon on the plugin's row in Configurar > Plugins.
        $PLUGIN_HOOKS['config_page']['gac'] = 'front/config.php';

        // Value has no "public/" prefix: GLPI's router adds it for plugin assets.
        $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['gac'] = 'js/pre.js';

        // Plugin rights are invisible in Perfis unless the plugin adds its own tab.
        Plugin::registerClass(ProfileRights::class, ['addtabon' => Profile::class]);
    }
}

/**
 * Get the name and the version of the plugin
 * REQUIRED
 *
 * @return array{
 *      name: string,
 *      version: string,
 *      author: string,
 *      license: string,
 *      homepage: string,
 *      requirements: array{
 *          glpi: array{
 *              min: string,
 *              max: string,
 *          }
 *      }
 * }
 */
function plugin_version_gac(): array
{
    return [
        'name'           => 'Plugin - DTI GAC',
        'version'        => PLUGIN_GAC_VERSION,
        'author'         => 'Juliano Ostroski',
        'license'        => 'MIT',
        'homepage'       => 'https://github.com/coca-mann/plugin-gac',
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_GAC_MIN_GLPI_VERSION,
                'max' => PLUGIN_GAC_MAX_GLPI_VERSION,
            ],
        ],
    ];
}

/**
 * Check pre-requisites before install
 * OPTIONAL
 */
function plugin_gac_check_prerequisites(): bool
{
    if (!is_file(__DIR__ . '/vendor/autoload.php')) {
        echo __('Dependências ausentes (mPDF): use o pacote de release do plugin ou rode "composer install --no-dev" na pasta do plugin.', 'gac');
        return false;
    }
    return true;
}

/**
 * Check configuration process
 * OPTIONAL
 *
 * @param bool $verbose Whether to display message on failure. Defaults to false.
 */
function plugin_gac_check_config(bool $verbose = false): bool
{
    // Your configuration check
    return true;

    // Example:
    // if ($verbose) {
    //    echo __('Installed / not configured', 'gac');
    // }
    // return false;
}
