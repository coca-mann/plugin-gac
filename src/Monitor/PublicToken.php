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
 * The opaque token that authenticates a public (no-login) Tela display. Pure: no GLPI, no
 * database — a Tela's public_token column is just this format. See spec M6 and section 6.3.
 */
final class PublicToken
{
    private const LENGTH_HEX_CHARS = 48;

    public static function generate(): string
    {
        return bin2hex(random_bytes((int) (self::LENGTH_HEX_CHARS / 2)));
    }

    public static function isWellFormed(string $token): bool
    {
        return (bool) preg_match('/^[0-9a-f]{' . self::LENGTH_HEX_CHARS . '}$/', $token);
    }
}
