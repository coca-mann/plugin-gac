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

use GlpiPlugin\Gac\Pre\RepairProtocol;
use Session;

/**
 * The plugin's features as seen by the rights system: one row per feature in the profile tab
 * (each with its own right name) and a "Configurar" bit that gates that feature's settings.
 * A new module adds one row to all().
 */
final class Features
{
    /** Bit every feature's right uses for "Configurar" (above the standard rights and the module's own). */
    public const RIGHT_CONFIG = 2048;

    /** @return list<array{itemtype: class-string, label: string, field: string}> */
    public static function all(): array
    {
        return [
            [
                'itemtype' => RepairProtocol::class,
                'label'    => RepairProtocol::getTypeName(2),
                'field'    => RepairProtocol::$rightname,
            ],
        ];
    }

    public static function canConfigure(string $rightname): bool
    {
        return (bool) Session::haveRight($rightname, self::RIGHT_CONFIG);
    }

    public static function canConfigureAny(): bool
    {
        foreach (self::all() as $feature) {
            if (self::canConfigure($feature['field'])) {
                return true;
            }
        }
        return false;
    }
}
