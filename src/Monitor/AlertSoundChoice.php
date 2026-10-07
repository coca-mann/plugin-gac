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
 * Pure: which alert sound a Tela uses (spec M19). The caller passes, for each source, the value
 * only when it is really usable (a stored file name only when the file exists on disk), and gets
 * back which source wins: the Tela's own file, then the plugin's file, then the plugin's URL.
 */
final class AlertSoundChoice
{
    public const SCREEN_FILE = 'screen_file';
    public const PLUGIN_FILE = 'plugin_file';
    public const PLUGIN_URL  = 'plugin_url';
    public const NONE        = 'none';

    public static function choose(string $screenFile, string $pluginFile, string $pluginUrl): string
    {
        if (trim($screenFile) !== '') {
            return self::SCREEN_FILE;
        }
        if (trim($pluginFile) !== '') {
            return self::PLUGIN_FILE;
        }
        if (trim($pluginUrl) !== '') {
            return self::PLUGIN_URL;
        }
        return self::NONE;
    }
}
