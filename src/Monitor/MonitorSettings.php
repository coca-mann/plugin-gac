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
 * Typed view over the raw Monitor configuration array stored in glpi_configs (context
 * plugin:gac, keys prefixed monitor_). Pure: no GLPI calls. See spec section 5.2.
 */
final class MonitorSettings
{
    private const MIN_POLL_INTERVAL_SECONDS = 5;

    public const MIN_BANNER_SECONDS     = 3;
    public const MAX_BANNER_SECONDS     = 120;
    public const DEFAULT_BANNER_SECONDS = 10;

    /** @return array<string, string> */
    public static function defaults(): array
    {
        return [
            'monitor_default_poll_interval_seconds' => '15',
            'monitor_default_rotation_seconds'      => (string) PageRotation::DEFAULT_ROTATION_SECONDS,
            'monitor_sla_warning_minutes'             => '60',
            'monitor_banner_seconds'                  => (string) self::DEFAULT_BANNER_SECONDS,
            'monitor_banner_show_description'         => '1',
            'monitor_alert_sound_url'                 => '',
            'monitor_alert_sound_file'                => '',
            'monitor_alert_sound_name'                => '',
            'monitor_service_username'                => '',
            'monitor_service_password'                => '',
        ];
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

        if (array_key_exists('monitor_default_poll_interval_seconds', $raw)) {
            $seconds = is_numeric($raw['monitor_default_poll_interval_seconds'])
                ? (int) $raw['monitor_default_poll_interval_seconds']
                : self::MIN_POLL_INTERVAL_SECONDS;
            $out['monitor_default_poll_interval_seconds'] = (string) max(self::MIN_POLL_INTERVAL_SECONDS, $seconds);
        }

        if (array_key_exists('monitor_default_rotation_seconds', $raw)) {
            $seconds = is_numeric($raw['monitor_default_rotation_seconds'])
                ? (int) $raw['monitor_default_rotation_seconds']
                : PageRotation::DEFAULT_ROTATION_SECONDS;
            $out['monitor_default_rotation_seconds'] = (string) max(PageRotation::MIN_ROTATION_SECONDS, $seconds);
        }

        if (array_key_exists('monitor_sla_warning_minutes', $raw)) {
            $minutes = is_numeric($raw['monitor_sla_warning_minutes']) ? (int) $raw['monitor_sla_warning_minutes'] : 60;
            $out['monitor_sla_warning_minutes'] = (string) max(1, $minutes);
        }

        // New-ticket banner (spec M18): how long each banner stays, and whether it may show the
        // ticket's free-text description (which a public TV would display to anyone looking at it).
        if (array_key_exists('monitor_banner_seconds', $raw)) {
            $seconds = is_numeric($raw['monitor_banner_seconds']) ? (int) $raw['monitor_banner_seconds'] : self::DEFAULT_BANNER_SECONDS;
            $out['monitor_banner_seconds'] = (string) min(self::MAX_BANNER_SECONDS, max(self::MIN_BANNER_SECONDS, $seconds));
        }
        if (array_key_exists('monitor_banner_show_description', $raw)) {
            $out['monitor_banner_show_description'] = ((string) $raw['monitor_banner_show_description']) === '0' ? '0' : '1';
        }

        if (array_key_exists('monitor_alert_sound_url', $raw)) {
            $out['monitor_alert_sound_url'] = trim((string) $raw['monitor_alert_sound_url']);
        }

        // The uploaded sound (spec M17): the stored name is only trusted when it is one AlertSoundFile
        // could have produced, so a tampered setting can never point outside the sound directory.
        if (array_key_exists('monitor_alert_sound_file', $raw) && AlertSoundFile::isValidStoredName((string) $raw['monitor_alert_sound_file'])) {
            $out['monitor_alert_sound_file'] = (string) $raw['monitor_alert_sound_file'];
            $out['monitor_alert_sound_name'] = AlertSoundFile::displayName(trim((string) ($raw['monitor_alert_sound_name'] ?? '')));
        }

        if (array_key_exists('monitor_service_username', $raw)) {
            $out['monitor_service_username'] = trim((string) $raw['monitor_service_username']);
        }

        // The password is encrypted at rest by GLPI (SECURED_CONFIGS hook, setup.php) and
        // decrypted by Config::getConfigurationValues() before it ever reaches normalize(), so
        // this is just a pass-through — never trim/alter it (encrypted strings are not text).
        if (array_key_exists('monitor_service_password', $raw)) {
            $out['monitor_service_password'] = (string) $raw['monitor_service_password'];
        }

        return $out;
    }

    /** @param array<string, string> $s */
    public static function defaultPollIntervalSeconds(array $s): int
    {
        return (int) ($s['monitor_default_poll_interval_seconds'] ?? 15);
    }

    /** @param array<string, string> $s */
    public static function defaultRotationSeconds(array $s): int
    {
        return (int) ($s['monitor_default_rotation_seconds'] ?? PageRotation::DEFAULT_ROTATION_SECONDS);
    }

    /** @param array<string, string> $s */
    public static function slaWarningMinutes(array $s): int
    {
        return (int) ($s['monitor_sla_warning_minutes'] ?? 60);
    }

    /** @param array<string, string> $s */
    public static function alertSoundUrl(array $s): string
    {
        return (string) ($s['monitor_alert_sound_url'] ?? '');
    }

    /** @param array<string, string> $s */
    public static function bannerSeconds(array $s): int
    {
        return (int) ($s['monitor_banner_seconds'] ?? self::DEFAULT_BANNER_SECONDS);
    }

    /** @param array<string, string> $s */
    public static function bannerShowDescription(array $s): bool
    {
        return ($s['monitor_banner_show_description'] ?? '1') !== '0';
    }

    /** @param array<string, string> $s the stored name of the uploaded sound, '' when none */
    public static function alertSoundFile(array $s): string
    {
        return (string) ($s['monitor_alert_sound_file'] ?? '');
    }

    /** @param array<string, string> $s the original name of the uploaded sound, for display only */
    public static function alertSoundFileName(array $s): string
    {
        return (string) ($s['monitor_alert_sound_name'] ?? '');
    }

    /** @param array<string, string> $s */
    public static function serviceUsername(array $s): string
    {
        return (string) ($s['monitor_service_username'] ?? '');
    }

    /** @param array<string, string> $s */
    public static function servicePassword(array $s): string
    {
        return (string) ($s['monitor_service_password'] ?? '');
    }

    /** Whether a service account is configured at all, for the public (stateless) search path. */
    public static function hasServiceAccount(array $s): bool
    {
        return self::serviceUsername($s) !== '' && self::servicePassword($s) !== '';
    }

    /** Clamps a Tela's own poll interval override; null (use the global default) stays null. */
    public static function clampPollInterval(?int $seconds): ?int
    {
        if ($seconds === null) {
            return null;
        }
        return max(self::MIN_POLL_INTERVAL_SECONDS, $seconds);
    }
}
