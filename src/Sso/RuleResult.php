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
 * What the RuleRight engine produced for a login, in a form the rest of the module can reason
 * about without touching GLPI. Built from the engine's output array (see User::applyRightRules()
 * for the same shapes).
 */
final class RuleResult
{
    /** @param list<array{entities_id: int, profiles_id: int, is_recursive: int}> $grants */
    public function __construct(
        public readonly bool $denied,
        public readonly array $grants,
        public readonly ?int $defaultEntityId
    ) {}

    public function hasGrants(): bool
    {
        return $this->grants !== [];
    }

    /** @param array<string, mixed> $output */
    public static function fromOutput(array $output): self
    {
        $rules  = is_array($output['_ldap_rules'] ?? null) ? $output['_ldap_rules'] : [];
        $unique = [];

        $add = static function (int $entity, int $profile, int $recursive) use (&$unique): void {
            $unique[$entity . '-' . $profile . '-' . $recursive] = [
                'entities_id'  => $entity,
                'profiles_id'  => $profile,
                'is_recursive' => $recursive,
            ];
        };

        foreach ($rules['rules_entities_rights'] ?? [] as $row) {
            foreach ((array) $row[0] as $entityId) {
                $add((int) $entityId, (int) $row[1], (int) $row[2]);
            }
        }

        // Entity-only and profile-only actions combine; without any profile action GLPI uses its
        // default profile, which is reported here as profile 0.
        $profiles = $rules['rules_rights'] ?? [];
        foreach ($rules['rules_entities'] ?? [] as $row) {
            foreach ($profiles === [] ? [0] : $profiles as $profileId) {
                $add((int) $row[0], (int) $profileId, (int) $row[1]);
            }
        }

        $default = isset($output['entities_id']) && is_numeric($output['entities_id'])
            ? (int) $output['entities_id']
            : null;

        return new self(!empty($output['_deny_login']), array_values($unique), $default);
    }
}
