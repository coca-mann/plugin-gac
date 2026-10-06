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

namespace GlpiPlugin\Gac\Sso;

use GLPIKey;

/** Storage of the SSO settings: glpi_configs, context plugin:gac, keys prefixed sso_. */
final class SsoConfig
{
    public const CONTEXT = 'plugin:gac';

    /** @return array<string, string> normalized settings (see SsoSettings) */
    public static function load(): array
    {
        $raw = \Config::getConfigurationValues(self::CONTEXT);
        // Encryption (SECURED_CONFIGS hook, setup.php) is only applied on write by
        // Config::setConfigurationValues(); the read side has to decrypt explicitly.
        foreach (SsoSettings::SECURED_KEYS as $key) {
            if (!empty($raw[$key])) {
                $raw[$key] = (string) (new GLPIKey())->decrypt($raw[$key]);
            }
        }

        return SsoSettings::normalize($raw);
    }

    /**
     * Merges the given keys over the current settings, so a partial save (for example from a
     * script or a form that omits a secret) never resets the other keys to their defaults.
     *
     * @param array<string, mixed> $raw
     */
    public static function save(array $raw): void
    {
        \Config::setConfigurationValues(self::CONTEXT, SsoSettings::normalize(array_merge(self::load(), $raw)));
    }
}
