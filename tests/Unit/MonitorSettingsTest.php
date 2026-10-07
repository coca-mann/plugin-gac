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

namespace GlpiPlugin\Gac\Tests\Unit;

use GlpiPlugin\Gac\Monitor\MonitorSettings;
use PHPUnit\Framework\TestCase;

final class MonitorSettingsTest extends TestCase
{
    public function testDefaults(): void
    {
        $s = MonitorSettings::normalize([]);
        $this->assertSame(15, MonitorSettings::defaultPollIntervalSeconds($s));
        $this->assertSame(20, MonitorSettings::defaultRotationSeconds($s));
        $this->assertSame(60, MonitorSettings::slaWarningMinutes($s));
        $this->assertSame(10, MonitorSettings::bannerSeconds($s));
        $this->assertTrue(MonitorSettings::bannerShowDescription($s));
        $this->assertSame('', MonitorSettings::alertSoundUrl($s));
        $this->assertSame('', MonitorSettings::alertSoundFile($s));
        $this->assertSame('', MonitorSettings::alertSoundFileName($s));
        $this->assertSame('', MonitorSettings::serviceUsername($s));
        $this->assertSame('', MonitorSettings::servicePassword($s));
        $this->assertFalse(MonitorSettings::hasServiceAccount($s));
    }

    public function testServiceAccountFields(): void
    {
        $s = MonitorSettings::normalize([
            'monitor_service_username' => '  monitor-bot  ',
            'monitor_service_password' => 'whatever-the-encrypted-blob-is',
        ]);
        $this->assertSame('monitor-bot', MonitorSettings::serviceUsername($s));
        $this->assertSame('whatever-the-encrypted-blob-is', MonitorSettings::servicePassword($s));
        $this->assertTrue(MonitorSettings::hasServiceAccount($s));
    }

    public function testHasServiceAccountRequiresBothFields(): void
    {
        $this->assertFalse(MonitorSettings::hasServiceAccount(
            MonitorSettings::normalize(['monitor_service_username' => 'monitor-bot'])
        ));
        $this->assertFalse(MonitorSettings::hasServiceAccount(
            MonitorSettings::normalize(['monitor_service_password' => 'secret'])
        ));
    }

    public function testRotationAndSlaWindowAreCoerced(): void
    {
        $s = MonitorSettings::normalize([
            'monitor_default_rotation_seconds' => '1',
            'monitor_sla_warning_minutes'      => '0',
        ]);
        $this->assertSame(5, MonitorSettings::defaultRotationSeconds($s));
        $this->assertSame(1, MonitorSettings::slaWarningMinutes($s));

        $s = MonitorSettings::normalize([
            'monitor_default_rotation_seconds' => 'abc',
            'monitor_sla_warning_minutes'      => 'abc',
        ]);
        $this->assertSame(20, MonitorSettings::defaultRotationSeconds($s));
        $this->assertSame(60, MonitorSettings::slaWarningMinutes($s));

        $s = MonitorSettings::normalize([
            'monitor_default_rotation_seconds' => '30',
            'monitor_sla_warning_minutes'      => '90',
        ]);
        $this->assertSame(30, MonitorSettings::defaultRotationSeconds($s));
        $this->assertSame(90, MonitorSettings::slaWarningMinutes($s));
    }

    public function testAlertSoundFileIsKeptOnlyWhenItsStoredNameIsValid(): void
    {
        $s = MonitorSettings::normalize([
            'monitor_alert_sound_file' => 'alert-0123456789ab.mp3',
            'monitor_alert_sound_name' => '  beep.mp3  ',
        ]);
        $this->assertSame('alert-0123456789ab.mp3', MonitorSettings::alertSoundFile($s));
        $this->assertSame('beep.mp3', MonitorSettings::alertSoundFileName($s));

        foreach (['../etc/passwd', 'alert-0123456789ab.php', 'x.mp3', ''] as $bad) {
            $s = MonitorSettings::normalize(['monitor_alert_sound_file' => $bad, 'monitor_alert_sound_name' => 'beep.mp3']);
            $this->assertSame('', MonitorSettings::alertSoundFile($s), $bad);
            $this->assertSame('', MonitorSettings::alertSoundFileName($s), 'no name without a valid file');
        }
    }

    public function testBannerSecondsIsClampedToItsRange(): void
    {
        $this->assertSame(3, MonitorSettings::bannerSeconds(MonitorSettings::normalize(['monitor_banner_seconds' => '1'])));
        $this->assertSame(3, MonitorSettings::bannerSeconds(MonitorSettings::normalize(['monitor_banner_seconds' => '-5'])));
        $this->assertSame(120, MonitorSettings::bannerSeconds(MonitorSettings::normalize(['monitor_banner_seconds' => '9999'])));
        $this->assertSame(25, MonitorSettings::bannerSeconds(MonitorSettings::normalize(['monitor_banner_seconds' => '25'])));
        $this->assertSame(10, MonitorSettings::bannerSeconds(MonitorSettings::normalize(['monitor_banner_seconds' => 'abc'])));
    }

    public function testBannerDescriptionFlag(): void
    {
        $off = MonitorSettings::normalize(['monitor_banner_show_description' => '0']);
        $this->assertFalse(MonitorSettings::bannerShowDescription($off));
        $on = MonitorSettings::normalize(['monitor_banner_show_description' => '1']);
        $this->assertTrue(MonitorSettings::bannerShowDescription($on));
        // Anything that is not an explicit "0" keeps the default (shown).
        $weird = MonitorSettings::normalize(['monitor_banner_show_description' => 'maybe']);
        $this->assertTrue(MonitorSettings::bannerShowDescription($weird));
    }

    public function testEveryDefaultKeyIsPrefixed(): void
    {
        foreach (array_keys(MonitorSettings::defaults()) as $key) {
            $this->assertStringStartsWith('monitor_', $key);
        }
    }

    public function testNormalizeCoercesTypesAndDropsUnknownKeys(): void
    {
        $s = MonitorSettings::normalize([
            'monitor_default_poll_interval_seconds' => '42',
            'monitor_alert_sound_url'                 => ' https://x/y.mp3 ',
            'garbage'                                   => 'x',
        ]);
        $this->assertSame(42, MonitorSettings::defaultPollIntervalSeconds($s));
        $this->assertSame('https://x/y.mp3', MonitorSettings::alertSoundUrl($s));
        $this->assertArrayNotHasKey('garbage', $s);
    }

    public function testDefaultPollIntervalHasAFloorOfFiveSeconds(): void
    {
        $s = MonitorSettings::normalize(['monitor_default_poll_interval_seconds' => '1']);
        $this->assertSame(5, MonitorSettings::defaultPollIntervalSeconds($s));
    }

    public function testClampPollIntervalKeepsNullAsNull(): void
    {
        $this->assertNull(MonitorSettings::clampPollInterval(null));
    }

    public function testClampPollIntervalEnforcesTheFloor(): void
    {
        $this->assertSame(5, MonitorSettings::clampPollInterval(1));
        $this->assertSame(5, MonitorSettings::clampPollInterval(0));
        $this->assertSame(30, MonitorSettings::clampPollInterval(30));
    }
}
