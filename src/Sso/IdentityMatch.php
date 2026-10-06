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

/** Result of IdentityMatcher::decide(). Named IdentityMatch because "match" is a PHP keyword. */
final class IdentityMatch
{
    public const USE_LINKED    = 'use_linked';
    public const LINK_EXISTING = 'link_existing';
    public const CREATE        = 'create';
    public const DENY          = 'deny';

    private function __construct(
        public readonly string $action,
        public readonly ?int $userId,
        public readonly ?string $outcome
    ) {}

    public static function useLinked(int $userId): self
    {
        return new self(self::USE_LINKED, $userId, null);
    }

    public static function linkExisting(int $userId): self
    {
        return new self(self::LINK_EXISTING, $userId, null);
    }

    public static function create(): self
    {
        return new self(self::CREATE, null, null);
    }

    public static function deny(string $outcome): self
    {
        return new self(self::DENY, null, $outcome);
    }
}
