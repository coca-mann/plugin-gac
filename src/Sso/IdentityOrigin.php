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

/**
 * Where a linked user came from, read from the authentication method the user had before the
 * link: external (created by the Google login itself), LDAP (an AD user converted) or any other
 * method (converted).
 */
final class IdentityOrigin
{
    public const CREATED   = 'created';
    public const CONVERTED = 'converted';
    public const LDAP      = 'ldap';

    private const AUTH_LDAP     = 3;
    private const AUTH_EXTERNAL = 4;

    public static function of(int $prevAuthtype): string
    {
        return match ($prevAuthtype) {
            self::AUTH_EXTERNAL => self::CREATED,
            self::AUTH_LDAP     => self::LDAP,
            default             => self::CONVERTED,
        };
    }

    public static function isConversion(string $origin): bool
    {
        return $origin !== self::CREATED;
    }

    /** "05-10-2026 22:33" into ["05-10-2026", "22:33"]; a value without a time keeps an empty time. */
    public static function splitDateTime(string $formatted): array
    {
        $parts = explode(' ', trim($formatted), 2);

        return [$parts[0], $parts[1] ?? ''];
    }
}
