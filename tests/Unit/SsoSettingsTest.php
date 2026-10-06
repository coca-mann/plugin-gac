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

use GlpiPlugin\Gac\Sso\SsoSettings;
use PHPUnit\Framework\TestCase;

final class SsoSettingsTest extends TestCase
{
    public function testDefaultsAreSafe(): void
    {
        $s = SsoSettings::defaults();

        $this->assertFalse(SsoSettings::enabled($s));
        $this->assertTrue(SsoSettings::autoCreate($s));
        $this->assertTrue(SsoSettings::hideLocalForm($s));
        $this->assertFalse(SsoSettings::pilotOnly($s));
        $this->assertTrue(SsoSettings::revokeOnDeny($s));
        $this->assertSame(0, SsoSettings::domainSegment($s));
        $this->assertSame(180, SsoSettings::eventRetentionDays($s));
        $this->assertSame('Entrar com Google', SsoSettings::buttonLabel($s));
        $this->assertFalse(SsoSettings::isConfigured($s));
    }

    public function testNormalizeCoercesFlagsAndNumbers(): void
    {
        $s = SsoSettings::normalize([
            'sso_enabled' => 'on', 'sso_auto_create' => '0', 'sso_hide_local_form' => 0,
            'sso_pilot_only' => true, 'sso_domain_segment' => '-4', 'sso_event_retention_days' => '2',
        ]);

        $this->assertTrue(SsoSettings::enabled($s));
        $this->assertFalse(SsoSettings::autoCreate($s));
        $this->assertFalse(SsoSettings::hideLocalForm($s));
        $this->assertTrue(SsoSettings::pilotOnly($s));
        $this->assertSame(0, SsoSettings::domainSegment($s));
        $this->assertSame(7, SsoSettings::eventRetentionDays($s));
    }

    public function testNormalizeDropsUnknownKeys(): void
    {
        $s = SsoSettings::normalize(['sso_nope' => 'x', 'monitor_alert_sound_url' => 'y']);

        $this->assertArrayNotHasKey('sso_nope', $s);
        $this->assertArrayNotHasKey('monitor_alert_sound_url', $s);
    }

    public function testPrivateKeyLiteralBackslashNBecomesRealNewlines(): void
    {
        $s = SsoSettings::normalize(['sso_sa_private_key' => '-----BEGIN PRIVATE KEY-----\nAAA\n-----END PRIVATE KEY-----\n']);

        $this->assertSame("-----BEGIN PRIVATE KEY-----\nAAA\n-----END PRIVATE KEY-----\n", SsoSettings::saPrivateKey($s));
    }

    public function testSecretsPassThroughUntouched(): void
    {
        $s = SsoSettings::normalize(['sso_client_secret' => '  GOCSPX-abc ']);

        $this->assertSame('  GOCSPX-abc ', SsoSettings::clientSecret($s));
    }

    public function testListGettersParseTheTextareas(): void
    {
        $s = SsoSettings::normalize([
            'sso_workspaces'       => [['name' => 'Fimca', 'domains' => "@Fimca.com.br\ngrupoaparicio.com.br", 'admin_subject' => 'a@fimca.com.br']],
            'sso_pilot_emails'     => 'TI@fimca.com.br, ana@fimca.com.br',
            'sso_blocked_ou_paths' => "/FIMCA/x/Professores\n# comentario\n",
        ]);

        $this->assertSame(['fimca.com.br', 'grupoaparicio.com.br'], SsoSettings::allowedDomains($s));
        $this->assertSame(['ti@fimca.com.br', 'ana@fimca.com.br'], SsoSettings::pilotEmails($s));
        $this->assertSame(['/fimca/x/professores'], SsoSettings::blockedOus($s)->paths());
    }

    public function testIsConfiguredNeedsEveryCredential(): void
    {
        $full = [
            'sso_enabled' => '1', 'sso_client_id' => 'id', 'sso_client_secret' => 'secret',
            'sso_workspaces' => '[{"name":"Fimca","domains":["fimca.com.br"],"admin_subject":"admin@fimca.com.br","is_active":true}]',
            'sso_sa_client_email' => 'sa@p.iam.gserviceaccount.com', 'sso_sa_private_key' => 'KEY',
        ];
        $this->assertTrue(SsoSettings::isConfigured(SsoSettings::normalize($full)));

        foreach (array_keys($full) as $missing) {
            $partial = $full;
            unset($partial[$missing]);
            $this->assertFalse(SsoSettings::isConfigured(SsoSettings::normalize($partial)), "missing $missing");
        }
    }

    public function testWorkspacesAreKeptAsCanonicalJsonAndAnIncompleteOneIsNotConfigured(): void
    {
        $base = [
            'sso_enabled' => '1', 'sso_client_id' => 'id', 'sso_client_secret' => 'secret',
            'sso_sa_client_email' => 'sa@p.iam.gserviceaccount.com', 'sso_sa_private_key' => 'KEY',
        ];
        // A workspace with no admin to impersonate cannot read org units, so it is not usable.
        $s = SsoSettings::normalize($base + ['sso_workspaces' => [['name' => 'A', 'domains' => 'a.com', 'admin_subject' => '']]]);

        $this->assertFalse(SsoSettings::isConfigured($s));
        $this->assertSame([], SsoSettings::allowedDomains($s));
        $this->assertSame('[]', SsoSettings::defaults()['sso_workspaces']);
        $this->assertSame(
            '[{"name":"A","domains":["a.com"],"admin_subject":"","is_active":true}]',
            $s['sso_workspaces']
        );
    }

    public function testTwoWorkspacesGiveTheUnionOfDomainsAndTheRightAdminPerEmail(): void
    {
        $s = SsoSettings::normalize(['sso_workspaces' => [
            ['name' => 'Fimca', 'domains' => 'fimca.com.br', 'admin_subject' => 'a@fimca.com.br'],
            ['name' => 'Metro', 'domains' => 'metropolitana-ro.com.br', 'admin_subject' => 'a@metropolitana-ro.com.br'],
        ]]);

        $this->assertSame(['fimca.com.br', 'metropolitana-ro.com.br'], SsoSettings::allowedDomains($s));
        $this->assertSame('a@metropolitana-ro.com.br', SsoSettings::workspaces($s)->forEmail('x@metropolitana-ro.com.br')?->adminSubject);
    }

    public function testTheLegacyKeysAreNoLongerSettings(): void
    {
        $this->assertArrayNotHasKey('sso_allowed_domains', SsoSettings::defaults());
        $this->assertArrayNotHasKey('sso_sa_admin_subject', SsoSettings::defaults());
        $this->assertSame(['sso_allowed_domains', 'sso_sa_admin_subject'], SsoSettings::LEGACY_KEYS);
    }

    public function testRedirectUriUsesTheOverrideOrTheUrlBase(): void
    {
        $default  = SsoSettings::normalize([]);
        $override = SsoSettings::normalize(['sso_redirect_uri' => ' http://localhost/plugins/gac/front/sso/callback.php ']);

        $this->assertSame(
            'https://glpi.exemplo.com.br/plugins/gac/front/sso/callback.php',
            SsoSettings::redirectUri($default, 'https://glpi.exemplo.com.br/')
        );
        $this->assertSame(
            'http://localhost/plugins/gac/front/sso/callback.php',
            SsoSettings::redirectUri($override, 'https://glpi.exemplo.com.br')
        );
    }
}
