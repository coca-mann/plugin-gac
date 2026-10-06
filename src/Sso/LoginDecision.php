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
 * The order of checks of spec S8 as three pure phases. Each returns null to keep going or the
 * Outcome code that denies the login.
 */
final class LoginDecision
{
    /**
     * Phase 1, before the Directory API is called: domain, verified e-mail and pilot mode.
     *
     * @param list<string> $allowedDomains
     * @param list<string> $pilotEmails lowercase
     */
    public static function beforeDirectory(
        string $email,
        bool $emailVerified,
        string $hostedDomain,
        array $allowedDomains,
        bool $pilotOnly,
        array $pilotEmails
    ): ?string {
        $denied = DomainPolicy::check($email, $emailVerified, $hostedDomain, $allowedDomains);
        if ($denied !== null) {
            return $denied;
        }

        if ($pilotOnly && !in_array(mb_strtolower(trim($email)), $pilotEmails, true)) {
            return Outcome::PILOT_BLOCKED;
        }

        return null;
    }

    /** Phase 2, once the OU is known: hard block (S9), then the optional domain x OU check (S18). */
    public static function afterDirectory(string $ouPath, string $emailDomain, OuBlocklist $blocklist, int $domainSegment): ?string
    {
        if ($blocklist->matches($ouPath) !== null) {
            return Outcome::OU_BLOCKED;
        }

        if ($domainSegment > 0 && !DomainPolicy::domainSegmentMatches($ouPath, $emailDomain, $domainSegment)) {
            return Outcome::DOMAIN_MISMATCH;
        }

        return null;
    }

    /** Phase 3, with the rules engine's verdict. A deny action wins over any grant. */
    public static function afterRules(bool $denied, bool $hasGrants): ?string
    {
        if ($denied) {
            return Outcome::OU_DENIED;
        }

        return $hasGrants ? null : Outcome::OU_UNMAPPED;
    }
}
