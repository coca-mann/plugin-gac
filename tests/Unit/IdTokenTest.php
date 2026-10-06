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

use GlpiPlugin\Gac\Sso\IdToken;
use PHPUnit\Framework\TestCase;

final class IdTokenTest extends TestCase
{
    private const CLIENT = 'client-123.apps.googleusercontent.com';
    private const NOW    = 1_800_000_000;

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** @param array<string, mixed> $header @param array<string, mixed> $claims */
    private static function jwt(array $header, array $claims, string $signature = 'sig'): string
    {
        return self::b64((string) json_encode($header)) . '.' . self::b64((string) json_encode($claims)) . '.' . self::b64($signature);
    }

    /** @return array<string, mixed> */
    private static function goodClaims(): array
    {
        return [
            'iss' => 'https://accounts.google.com', 'aud' => self::CLIENT, 'exp' => self::NOW + 600,
            'iat' => self::NOW - 5, 'nonce' => 'n-1', 'sub' => '1100', 'email' => 'ana@fimca.com.br',
            'email_verified' => true, 'hd' => 'fimca.com.br',
        ];
    }

    public function testDecodeSplitsAValidToken(): void
    {
        $decoded = IdToken::decode(self::jwt(['alg' => 'RS256', 'kid' => 'k1'], self::goodClaims()));

        $this->assertNotNull($decoded);
        $this->assertSame('k1', $decoded['header']['kid']);
        $this->assertSame('ana@fimca.com.br', $decoded['claims']['email']);
        $this->assertSame('sig', $decoded['signature']);
        $this->assertStringContainsString('.', $decoded['signing_input']);
    }

    public function testDecodeRejectsMalformedTokens(): void
    {
        $this->assertNull(IdToken::decode('abc'));
        $this->assertNull(IdToken::decode('a.b'));
        $this->assertNull(IdToken::decode('!!.!!.!!'));
        $this->assertNull(IdToken::decode(self::b64('not json') . '.' . self::b64('{}') . '.' . self::b64('s')));
    }

    public function testValidateClaimsAcceptsAGoodToken(): void
    {
        $this->assertNull(IdToken::validateClaims(self::goodClaims(), self::CLIENT, 'n-1', self::NOW));
    }

    public function testValidateClaimsAcceptsTheBareIssuerAndAnAudienceList(): void
    {
        $claims        = self::goodClaims();
        $claims['iss'] = 'accounts.google.com';
        $claims['aud'] = ['other', self::CLIENT];

        $this->assertNull(IdToken::validateClaims($claims, self::CLIENT, 'n-1', self::NOW));
    }

    public function testValidateClaimsNamesTheFailingClaim(): void
    {
        $base = self::goodClaims();

        $this->assertSame('iss', IdToken::validateClaims(['iss' => 'https://evil.test'] + $base, self::CLIENT, 'n-1', self::NOW));
        $this->assertSame('aud', IdToken::validateClaims(['aud' => 'someone-else'] + $base, self::CLIENT, 'n-1', self::NOW));
        $this->assertSame('exp', IdToken::validateClaims(['exp' => self::NOW - 600] + $base, self::CLIENT, 'n-1', self::NOW));
        $this->assertSame('iat', IdToken::validateClaims(['iat' => self::NOW + 3600] + $base, self::CLIENT, 'n-1', self::NOW));
        $this->assertSame('nonce', IdToken::validateClaims($base, self::CLIENT, 'other-nonce', self::NOW));
        $this->assertSame('nonce', IdToken::validateClaims($base, self::CLIENT, '', self::NOW));
        $this->assertSame('sub', IdToken::validateClaims(['sub' => ''] + $base, self::CLIENT, 'n-1', self::NOW));
        $this->assertSame('email', IdToken::validateClaims(['email' => ''] + $base, self::CLIENT, 'n-1', self::NOW));
    }

    public function testValidateClaimsToleratesSmallClockSkew(): void
    {
        $claims = ['exp' => self::NOW - 30] + self::goodClaims();

        $this->assertNull(IdToken::validateClaims($claims, self::CLIENT, 'n-1', self::NOW));
    }

    public function testEmailVerifiedAcceptsBooleanAndStringTrue(): void
    {
        $this->assertTrue(IdToken::emailVerified(['email_verified' => true]));
        $this->assertTrue(IdToken::emailVerified(['email_verified' => 'true']));
        $this->assertFalse(IdToken::emailVerified(['email_verified' => false]));
        $this->assertFalse(IdToken::emailVerified(['email_verified' => 'false']));
        $this->assertFalse(IdToken::emailVerified([]));
    }

    public function testVerifySignatureAcceptsAValidRs256Token(): void
    {
        [$private, $publicPem] = $this->keyPair();
        $header  = ['alg' => 'RS256', 'kid' => 'k1'];
        $payload = self::b64((string) json_encode($header)) . '.' . self::b64((string) json_encode(self::goodClaims()));
        openssl_sign($payload, $signature, $private, OPENSSL_ALGO_SHA256);
        $decoded = IdToken::decode($payload . '.' . self::b64($signature));

        $this->assertNotNull($decoded);
        $this->assertTrue(IdToken::verifySignature($decoded, ['k1' => $publicPem]));
    }

    public function testVerifySignatureRejectsTamperingWrongKeyAndWrongAlgorithm(): void
    {
        [$private, $publicPem] = $this->keyPair();
        [, $otherPublicPem]    = $this->keyPair();
        $payload = self::b64((string) json_encode(['alg' => 'RS256', 'kid' => 'k1'])) . '.' . self::b64((string) json_encode(self::goodClaims()));
        openssl_sign($payload, $signature, $private, OPENSSL_ALGO_SHA256);
        $good = IdToken::decode($payload . '.' . self::b64($signature));
        $this->assertNotNull($good);

        // Different key.
        $this->assertFalse(IdToken::verifySignature($good, ['k1' => $otherPublicPem]));
        // Unknown kid.
        $this->assertFalse(IdToken::verifySignature($good, ['zzz' => $publicPem]));
        // Tampered payload.
        $tampered                  = $good;
        $tampered['signing_input'] = $good['signing_input'] . 'x';
        $this->assertFalse(IdToken::verifySignature($tampered, ['k1' => $publicPem]));
        // "none"/HS256 style algorithms are never accepted.
        $none                  = $good;
        $none['header']['alg'] = 'none';
        $this->assertFalse(IdToken::verifySignature($none, ['k1' => $publicPem]));
    }

    /** @return array{0: \OpenSSLAsymmetricKey, 1: string} */
    private function keyPair(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            $this->markTestSkipped('openssl key generation unavailable (try OPENSSL_CONF=C:/xampp/apache/conf/openssl.cnf)');
        }
        $details = openssl_pkey_get_details($key);

        return [$key, (string) $details['key']];
    }
}
