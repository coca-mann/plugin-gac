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

use GlpiPlugin\Gac\Sso\ServiceAccountJwt;
use PHPUnit\Framework\TestCase;

final class ServiceAccountJwtTest extends TestCase
{
    private static function decode(string $part): string
    {
        $b64 = strtr($part, '-_', '+/');
        $b64 .= str_repeat('=', (4 - strlen($b64) % 4) % 4);

        return (string) base64_decode($b64, true);
    }

    public function testBuildsASignedRs256Assertion(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            $this->markTestSkipped('openssl key generation unavailable (try OPENSSL_CONF=C:/xampp/apache/conf/openssl.cnf)');
        }
        openssl_pkey_export($key, $pem);
        $public = openssl_pkey_get_details($key)['key'];

        $jwt = ServiceAccountJwt::build(
            'sa@proj.iam.gserviceaccount.com',
            $pem,
            'admin@fimca.com.br',
            ['https://www.googleapis.com/auth/admin.directory.user.readonly'],
            1_800_000_000
        );

        $this->assertNotNull($jwt);
        [$h, $c, $s] = explode('.', $jwt);
        $header = json_decode(self::decode($h), true);
        $claims = json_decode(self::decode($c), true);

        $this->assertSame(['alg' => 'RS256', 'typ' => 'JWT'], $header);
        $this->assertSame('sa@proj.iam.gserviceaccount.com', $claims['iss']);
        $this->assertSame('admin@fimca.com.br', $claims['sub']);
        $this->assertSame('https://www.googleapis.com/auth/admin.directory.user.readonly', $claims['scope']);
        $this->assertSame('https://oauth2.googleapis.com/token', $claims['aud']);
        $this->assertSame(1_800_000_000, $claims['iat']);
        $this->assertSame(1_800_003_600, $claims['exp']);
        $this->assertSame(1, openssl_verify($h . '.' . $c, self::decode($s), $public, OPENSSL_ALGO_SHA256));
    }

    public function testJoinsScopesWithASpace(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            $this->markTestSkipped('openssl key generation unavailable');
        }
        openssl_pkey_export($key, $pem);

        $jwt    = (string) ServiceAccountJwt::build('a', $pem, 'b', ['s1', 's2'], 1);
        $claims = json_decode(self::decode(explode('.', $jwt)[1]), true);

        $this->assertSame('s1 s2', $claims['scope']);
    }

    public function testAnInvalidKeyYieldsNull(): void
    {
        $this->assertNull(ServiceAccountJwt::build('a', 'not a key', 'b', ['s'], 1));
    }
}
