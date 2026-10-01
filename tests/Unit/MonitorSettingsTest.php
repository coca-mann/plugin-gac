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
        $this->assertSame('', MonitorSettings::alertSoundUrl($s));
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
