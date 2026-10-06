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
 * Typed view over the raw SSO configuration stored in glpi_configs (context plugin:gac, keys
 * prefixed sso_). Pure: no GLPI calls. See spec section 5.3.
 */
final class SsoSettings
{
    public const CALLBACK_PATH = '/plugins/gac/front/sso/callback.php';

    /** Keys GLPI encrypts at rest (Hooks::SECURED_CONFIGS in setup.php). */
    public const SECURED_KEYS = ['sso_client_secret', 'sso_sa_private_key'];

    /** Keys of the first (single workspace) version, migrated to sso_workspaces on install. */
    public const LEGACY_KEYS = ['sso_allowed_domains', 'sso_sa_admin_subject'];

    private const MIN_RETENTION_DAYS = 7;
    private const DEFAULT_BUTTON     = 'Entrar com Google';

    /** @return array<string, string> */
    public static function defaults(): array
    {
        return [
            'sso_enabled'              => '0',
            'sso_client_id'            => '',
            'sso_client_secret'        => '',
            'sso_redirect_uri'         => '',
            'sso_workspaces'           => '[]',
            'sso_sa_client_email'      => '',
            'sso_sa_private_key'       => '',
            'sso_blocked_ou_paths'     => '',
            'sso_auto_create'          => '1',
            'sso_hide_local_form'      => '1',
            'sso_domain_segment'       => '0',
            'sso_pilot_only'           => '0',
            'sso_pilot_emails'         => '',
            'sso_revoke_on_deny'       => '1',
            'sso_event_retention_days' => '180',
            'sso_button_label'         => self::DEFAULT_BUTTON,
        ];
    }

    private static function flag(mixed $value): string
    {
        if (is_string($value)) {
            $value = strtolower(trim($value));

            return in_array($value, ['1', 'on', 'true', 'yes', 'sim'], true) ? '1' : '0';
        }

        return $value ? '1' : '0';
    }

    /**
     * Completes missing keys with defaults, coerces types and drops unknown keys.
     *
     * @param array<string, mixed> $raw
     * @return array<string, string>
     */
    public static function normalize(array $raw): array
    {
        $out = self::defaults();

        foreach (['sso_enabled', 'sso_auto_create', 'sso_hide_local_form', 'sso_pilot_only', 'sso_revoke_on_deny'] as $key) {
            if (array_key_exists($key, $raw)) {
                $out[$key] = self::flag($raw[$key]);
            }
        }

        foreach ([
            'sso_client_id', 'sso_redirect_uri', 'sso_sa_client_email', 'sso_blocked_ou_paths', 'sso_pilot_emails',
        ] as $key) {
            if (array_key_exists($key, $raw)) {
                $out[$key] = trim((string) $raw[$key]);
            }
        }

        // The workspaces are kept as one canonical JSON string (spec S24); a list given as an
        // array (a form) is normalized the same way.
        if (array_key_exists('sso_workspaces', $raw)) {
            $out['sso_workspaces'] = (is_array($raw['sso_workspaces'])
                ? WorkspaceRegistry::fromRows($raw['sso_workspaces'])
                : WorkspaceRegistry::fromJson((string) $raw['sso_workspaces']))->toJson();
        }

        if (array_key_exists('sso_button_label', $raw)) {
            $label = trim((string) $raw['sso_button_label']);
            $out['sso_button_label'] = $label === '' ? self::DEFAULT_BUTTON : $label;
        }

        // Secrets are encrypted at rest by GLPI and decrypted before they reach normalize(), so
        // they are passed through untouched, except for the PEM line breaks: a key copied out of
        // the service account JSON file carries literal "\n" sequences instead of real newlines.
        if (array_key_exists('sso_client_secret', $raw)) {
            $out['sso_client_secret'] = (string) $raw['sso_client_secret'];
        }
        if (array_key_exists('sso_sa_private_key', $raw)) {
            $out['sso_sa_private_key'] = str_replace('\\n', "\n", (string) $raw['sso_sa_private_key']);
        }

        if (array_key_exists('sso_domain_segment', $raw)) {
            $out['sso_domain_segment'] = (string) max(0, is_numeric($raw['sso_domain_segment']) ? (int) $raw['sso_domain_segment'] : 0);
        }
        if (array_key_exists('sso_event_retention_days', $raw)) {
            $days = is_numeric($raw['sso_event_retention_days']) ? (int) $raw['sso_event_retention_days'] : 180;
            $out['sso_event_retention_days'] = (string) max(self::MIN_RETENTION_DAYS, $days);
        }

        return $out;
    }

    /** @param array<string, string> $s */
    public static function enabled(array $s): bool
    {
        return ($s['sso_enabled'] ?? '0') === '1';
    }

    /** @param array<string, string> $s */
    public static function clientId(array $s): string
    {
        return (string) ($s['sso_client_id'] ?? '');
    }

    /** @param array<string, string> $s */
    public static function clientSecret(array $s): string
    {
        return (string) ($s['sso_client_secret'] ?? '');
    }

    /** @param array<string, string> $s */
    public static function workspaces(array $s): WorkspaceRegistry
    {
        return WorkspaceRegistry::fromJson((string) ($s['sso_workspaces'] ?? '[]'));
    }

    /**
     * The domains the login accepts: the union of the usable workspaces' domains.
     *
     * @param array<string, string> $s
     * @return list<string>
     */
    public static function allowedDomains(array $s): array
    {
        return self::workspaces($s)->allowedDomains();
    }

    /** @param array<string, string> $s */
    public static function saClientEmail(array $s): string
    {
        return (string) ($s['sso_sa_client_email'] ?? '');
    }

    /** @param array<string, string> $s */
    public static function saPrivateKey(array $s): string
    {
        return (string) ($s['sso_sa_private_key'] ?? '');
    }

    /** @param array<string, string> $s */
    public static function blockedOus(array $s): OuBlocklist
    {
        return OuBlocklist::fromText((string) ($s['sso_blocked_ou_paths'] ?? ''));
    }

    /** @param array<string, string> $s */
    public static function autoCreate(array $s): bool
    {
        return ($s['sso_auto_create'] ?? '1') === '1';
    }

    /** @param array<string, string> $s */
    public static function hideLocalForm(array $s): bool
    {
        return ($s['sso_hide_local_form'] ?? '1') === '1';
    }

    /** @param array<string, string> $s */
    public static function domainSegment(array $s): int
    {
        return (int) ($s['sso_domain_segment'] ?? 0);
    }

    /** @param array<string, string> $s */
    public static function pilotOnly(array $s): bool
    {
        return ($s['sso_pilot_only'] ?? '0') === '1';
    }

    /**
     * @param array<string, string> $s
     * @return list<string>
     */
    public static function pilotEmails(array $s): array
    {
        return DomainPolicy::parseList((string) ($s['sso_pilot_emails'] ?? ''));
    }

    /** @param array<string, string> $s */
    public static function revokeOnDeny(array $s): bool
    {
        return ($s['sso_revoke_on_deny'] ?? '1') === '1';
    }

    /** @param array<string, string> $s */
    public static function eventRetentionDays(array $s): int
    {
        return (int) ($s['sso_event_retention_days'] ?? 180);
    }

    /** @param array<string, string> $s */
    public static function buttonLabel(array $s): string
    {
        return (string) ($s['sso_button_label'] ?? self::DEFAULT_BUTTON);
    }

    /**
     * Whether the Google login can run at all: switched on and every credential present.
     *
     * @param array<string, string> $s
     */
    public static function isConfigured(array $s): bool
    {
        return self::enabled($s)
            && self::clientId($s) !== ''
            && self::clientSecret($s) !== ''
            && self::workspaces($s)->isUsable()
            && self::saClientEmail($s) !== ''
            && self::saPrivateKey($s) !== '';
    }

    /**
     * The OAuth redirect URI: the explicit override, or GLPI's url_base plus the callback path.
     *
     * @param array<string, string> $s
     */
    public static function redirectUri(array $s, string $urlBase): string
    {
        $override = trim((string) ($s['sso_redirect_uri'] ?? ''));

        return $override !== '' ? $override : rtrim($urlBase, '/') . self::CALLBACK_PATH;
    }
}
