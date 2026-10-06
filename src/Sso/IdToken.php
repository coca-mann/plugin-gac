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
 * Pure handling of the Google ID token (a JWT): decoding, claim validation and RS256 signature
 * verification against Google's published certificates. See spec section 6.1 step 2 and V7.
 */
final class IdToken
{
    public const ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];

    private static function base64UrlDecode(string $value): string|false
    {
        $b64 = strtr($value, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad !== 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }

        return base64_decode($b64, true);
    }

    /**
     * @return ?array{header: array<string, mixed>, claims: array<string, mixed>, signing_input: string, signature: string}
     */
    public static function decode(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }

        $header    = self::base64UrlDecode($parts[0]);
        $claims    = self::base64UrlDecode($parts[1]);
        $signature = self::base64UrlDecode($parts[2]);
        if ($header === false || $claims === false || $signature === false) {
            return null;
        }

        $headerData = json_decode($header, true);
        $claimsData = json_decode($claims, true);
        if (!is_array($headerData) || !is_array($claimsData)) {
            return null;
        }

        return [
            'header'        => $headerData,
            'claims'        => $claimsData,
            'signing_input' => $parts[0] . '.' . $parts[1],
            'signature'     => $signature,
        ];
    }

    /**
     * @param array<string, mixed> $claims
     * @return ?string null when valid, otherwise the name of the claim that failed
     */
    public static function validateClaims(array $claims, string $clientId, string $nonce, int $now, int $leeway = 60): ?string
    {
        if (!in_array($claims['iss'] ?? null, self::ISSUERS, true)) {
            return 'iss';
        }

        $aud       = $claims['aud'] ?? null;
        $audiences = is_array($aud) ? $aud : [$aud];
        if (!in_array($clientId, $audiences, true)) {
            return 'aud';
        }

        if (!isset($claims['exp']) || !is_numeric($claims['exp']) || (int) $claims['exp'] + $leeway < $now) {
            return 'exp';
        }

        if (isset($claims['iat']) && is_numeric($claims['iat']) && (int) $claims['iat'] - $leeway > $now) {
            return 'iat';
        }

        $tokenNonce = $claims['nonce'] ?? null;
        if ($nonce === '' || !is_string($tokenNonce) || !hash_equals($nonce, $tokenNonce)) {
            return 'nonce';
        }

        if (!is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            return 'sub';
        }

        if (!is_string($claims['email'] ?? null) || $claims['email'] === '') {
            return 'email';
        }

        return null;
    }

    /** @param array<string, mixed> $claims */
    public static function emailVerified(array $claims): bool
    {
        $value = $claims['email_verified'] ?? false;

        return $value === true || $value === 'true';
    }

    /**
     * @param array{header: array<string, mixed>, signing_input: string, signature: string} $decoded
     * @param array<string, string> $pemByKid key id => certificate or public key in PEM form
     */
    public static function verifySignature(array $decoded, array $pemByKid): bool
    {
        if (($decoded['header']['alg'] ?? null) !== 'RS256') {
            return false;
        }

        $kid = $decoded['header']['kid'] ?? null;
        if (!is_string($kid) || !isset($pemByKid[$kid])) {
            return false;
        }

        return openssl_verify($decoded['signing_input'], $decoded['signature'], $pemByKid[$kid], OPENSSL_ALGO_SHA256) === 1;
    }
}
