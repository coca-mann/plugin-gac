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

/** Domain allow-list rules (spec S8 step 1, S18). Pure. */
final class DomainPolicy
{
    /**
     * Splits on whitespace, commas and semicolons; lowercases; drops empties and duplicates.
     *
     * @return list<string>
     */
    public static function parseList(string $text): array
    {
        $parts = preg_split('/[\s,;]+/', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique($parts));
    }

    /** @return list<string> */
    public static function parseDomains(string $text): array
    {
        $domains = [];
        foreach (self::parseList($text) as $item) {
            $domain = ltrim($item, '@');
            if ($domain !== '') {
                $domains[$domain] = true;
            }
        }

        return array_keys($domains);
    }

    public static function emailDomain(string $email): string
    {
        $email = mb_strtolower(trim($email));
        if (substr_count($email, '@') !== 1) {
            return '';
        }
        [$local, $domain] = explode('@', $email);

        return ($local === '' || $domain === '') ? '' : $domain;
    }

    /**
     * @param list<string> $allowedDomains lowercase
     * @return ?string null when the account may continue, or the Outcome code that denies it
     */
    public static function check(string $email, bool $emailVerified, string $hostedDomain, array $allowedDomains): ?string
    {
        if (!$emailVerified) {
            return Outcome::EMAIL_UNVERIFIED;
        }

        $domain = self::emailDomain($email);
        // The "hd" claim only exists for Workspace accounts: no hd means a consumer account.
        // It is the Workspace's domain, which can differ from the e-mail's domain, so only
        // its presence is required; the e-mail's domain is what must be on the list.
        if ($domain === '' || trim($hostedDomain) === '' || !in_array($domain, $allowedDomains, true)) {
            return Outcome::DOMAIN_DENIED;
        }

        return null;
    }

    /** Position is 1-based (spec S18); 0 or less is always false. */
    public static function domainSegmentMatches(string $ouPath, string $emailDomain, int $position): bool
    {
        $segments = OuPath::segments($ouPath);
        $index    = $position - 1;

        return $index >= 0 && isset($segments[$index]) && $segments[$index] === mb_strtolower($emailDomain);
    }
}
