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

/** Decides which GLPI user a Google login belongs to (spec S10, S11, S12). Pure. */
final class IdentityMatcher
{
    /** GLPI's Auth::DB_GLPI: a local account, with its own password. */
    private const AUTHTYPE_LOCAL = 1;

    /**
     * @param ?int      $linkedUserId       user already bound to this Google "sub", if any
     * @param list<int> $emailCandidateIds  non-deleted users whose e-mail matches
     */
    public static function decide(?int $linkedUserId, array $emailCandidateIds, bool $createAllowed): IdentityMatch
    {
        if ($linkedUserId !== null) {
            return IdentityMatch::useLinked($linkedUserId);
        }

        $candidates = array_values(array_unique($emailCandidateIds));
        if (count($candidates) === 1) {
            return IdentityMatch::linkExisting($candidates[0]);
        }
        if (count($candidates) > 1) {
            return IdentityMatch::deny(Outcome::EMAIL_AMBIGUOUS);
        }

        return $createAllowed ? IdentityMatch::create() : IdentityMatch::deny(Outcome::CREATE_DISABLED);
    }

    /** Local accounts (e.g. "glpi") are never converted automatically: they are the break-glass access. */
    public static function isConvertible(int $authtype): bool
    {
        return $authtype !== self::AUTHTYPE_LOCAL;
    }
}
