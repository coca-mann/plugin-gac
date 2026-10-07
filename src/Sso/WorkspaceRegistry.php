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
 * The list of Google Workspaces (spec S24): finds the workspace of an e-mail by its domain and
 * gives the union of the accepted domains. Pure: stored as JSON in one configuration key.
 */
final class WorkspaceRegistry
{
    /** @param list<Workspace> $workspaces */
    private function __construct(private readonly array $workspaces) {}

    /**
     * Normalizes raw rows (from a form or from JSON). Domains may be a text (one per line) or a
     * list; rows with neither a name nor a domain are dropped; a missing "is_active" means active.
     *
     * Keys (spec S25): a row keeps its "key" when it is valid, not used by a previous row and, when
     * $trustedKeys is a list (a form post), one of those keys; every other row gets a new key from
     * its name. Stored JSON is read with $trustedKeys = null (it is trusted).
     *
     * @param array<int|string, mixed> $rows
     * @param ?list<string>            $trustedKeys
     */
    public static function fromRows(array $rows, ?array $trustedKeys = null): self
    {
        $parsed = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $domainsRaw = $row['domains'] ?? '';
            $domains    = DomainPolicy::parseDomains(is_array($domainsRaw) ? implode("\n", array_map('strval', $domainsRaw)) : (string) $domainsRaw);
            $name       = trim((string) ($row['name'] ?? ''));
            if ($name === '' && $domains === []) {
                continue;
            }

            $parsed[] = [
                'key'     => trim((string) ($row['key'] ?? '')),
                'name'    => $name,
                'domains' => $domains,
                'admin'   => mb_strtolower(trim((string) ($row['admin_subject'] ?? ''))),
                'active'  => !array_key_exists('is_active', $row) || self::truthy($row['is_active']),
            ];
        }

        // Pass 1 reserves the keys that can be kept, so a generated key never takes one of them.
        $taken = [];
        foreach ($parsed as $i => $p) {
            $key        = $p['key'];
            $acceptable = $key !== ''
                && WorkspaceKey::isValid($key)
                && !in_array($key, $taken, true)
                && ($trustedKeys === null || in_array($key, $trustedKeys, true));
            $parsed[$i]['key'] = $acceptable ? $key : '';
            if ($acceptable) {
                $taken[] = $key;
            }
        }

        $workspaces = [];
        foreach ($parsed as $p) {
            $key = $p['key'];
            if ($key === '') {
                $key     = WorkspaceKey::unique(WorkspaceKey::fromName($p['name'] !== '' ? $p['name'] : $p['domains'][0]), $taken);
                $taken[] = $key;
            }
            $workspaces[] = new Workspace($key, $p['name'], $p['domains'], $p['admin'], $p['active']);
        }

        return new self($workspaces);
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);

        return new self(is_array($data) ? self::fromRows($data)->workspaces : []);
    }

    /**
     * What the single-workspace settings of the first version become: one workspace called
     * "Principal". Null when there is nothing to migrate.
     *
     * @param array<string, mixed> $raw raw glpi_configs values
     */
    public static function fromLegacy(array $raw): ?self
    {
        $domains = DomainPolicy::parseDomains((string) ($raw['sso_allowed_domains'] ?? ''));
        if ($domains === []) {
            return null;
        }

        return self::fromRows([[
            'name'          => 'Principal',
            'domains'       => $domains,
            'admin_subject' => (string) ($raw['sso_sa_admin_subject'] ?? ''),
            'is_active'     => true,
        ]]);
    }

    private static function truthy(mixed $value): bool
    {
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'on', 'true', 'yes', 'sim'], true);
        }

        return (bool) $value;
    }

    public function toJson(): string
    {
        return json_encode(array_map(static fn (Workspace $w): array => $w->toArray(), $this->workspaces), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @return list<Workspace> */
    public function all(): array
    {
        return $this->workspaces;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_map(static fn (Workspace $w): string => $w->key, $this->workspaces);
    }

    public function byKey(string $key): ?Workspace
    {
        foreach ($this->workspaces as $workspace) {
            if ($workspace->key === $key) {
                return $workspace;
            }
        }

        return null;
    }

    /** @return list<Workspace> */
    public function usable(): array
    {
        return array_values(array_filter($this->workspaces, static fn (Workspace $w): bool => $w->isUsable()));
    }

    public function isUsable(): bool
    {
        return $this->usable() !== [];
    }

    /** Union of the domains of the usable workspaces. @return list<string> */
    public function allowedDomains(): array
    {
        $domains = [];
        foreach ($this->usable() as $workspace) {
            foreach ($workspace->domains as $domain) {
                $domains[$domain] = true;
            }
        }

        return array_keys($domains);
    }

    /** The usable workspace that owns the domain of the e-mail, if any. */
    public function forEmail(string $email): ?Workspace
    {
        $domain = DomainPolicy::emailDomain($email);
        if ($domain === '') {
            return null;
        }

        foreach ($this->usable() as $workspace) {
            if ($workspace->hasDomain($domain)) {
                return $workspace;
            }
        }

        return null;
    }

    /** Domains that appear in more than one workspace (inactive ones count). @return list<string> */
    public function duplicatedDomains(): array
    {
        $seen       = [];
        $duplicated = [];
        foreach ($this->workspaces as $workspace) {
            foreach ($workspace->domains as $domain) {
                if (isset($seen[$domain]) && $seen[$domain] !== $workspace) {
                    $duplicated[$domain] = true;
                }
                $seen[$domain] = $workspace;
            }
        }

        return array_keys($duplicated);
    }
}
