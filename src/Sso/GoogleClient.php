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
 * The OAuth 2.0 / OpenID Connect side of the login with Google: builds the authorization URL
 * (state, nonce, PKCE S256) and trades the returned code for verified ID token claims.
 * Uses league/oauth2-google, which GLPI itself ships for the mail collector.
 */
final class GoogleClient
{
    private const CERTS_URL = 'https://www.googleapis.com/oauth2/v1/certs';

    /** @param array<string, string> $settings SsoConfig::load() */
    public function __construct(
        private readonly array $settings,
        private readonly string $redirectUri
    ) {}

    private function provider(): GooglePkceProvider
    {
        return new GooglePkceProvider(
            [
                'clientId'     => SsoSettings::clientId($this->settings),
                'clientSecret' => SsoSettings::clientSecret($this->settings),
                'redirectUri'  => $this->redirectUri,
            ],
            ['httpClient' => \Toolbox::getGuzzleClient()]
        );
    }

    /** @return array{url: string, state: string, nonce: string, pkce: string} */
    public function begin(): array
    {
        $provider = $this->provider();
        $nonce    = bin2hex(random_bytes(16));

        $url = $provider->getAuthorizationUrl([
            'scope'  => ['openid', 'email', 'profile'],
            // "*" restricts the account chooser to Google Workspace accounts of any domain; the
            // real allow-list is enforced after the login (DomainPolicy).
            'hd'     => '*',
            'prompt' => 'select_account',
            'nonce'  => $nonce,
        ]);

        return [
            'url'   => $url,
            'state' => (string) $provider->getState(),
            'nonce' => $nonce,
            'pkce'  => (string) $provider->getPkceCode(),
        ];
    }

    /**
     * @return array<string, mixed> the verified ID token claims
     * @throws SsoException
     */
    public function claimsFromCode(string $code, string $pkceVerifier, string $nonce): array
    {
        $provider = $this->provider();
        $provider->setPkceCode($pkceVerifier);

        try {
            $token = $provider->getAccessToken('authorization_code', ['code' => $code]);
        } catch (\Throwable $e) {
            throw new SsoException('Token exchange failed: ' . $e->getMessage());
        }

        $raw = $token->getValues()['id_token'] ?? null;
        if (!is_string($raw)) {
            throw new SsoException('Token response has no id_token');
        }

        $decoded = IdToken::decode($raw);
        if ($decoded === null) {
            throw new SsoException('Malformed ID token');
        }
        if (!IdToken::verifySignature($decoded, $this->certificates())) {
            throw new SsoException('ID token signature invalid');
        }

        $invalid = IdToken::validateClaims($decoded['claims'], SsoSettings::clientId($this->settings), $nonce, time());
        if ($invalid !== null) {
            throw new SsoException('ID token claim invalid: ' . $invalid);
        }

        return $decoded['claims'];
    }

    /**
     * Google's current signing certificates, key id => PEM certificate.
     *
     * @return array<string, string>
     * @throws SsoException
     */
    private function certificates(): array
    {
        try {
            $response = \Toolbox::getGuzzleClient()->get(self::CERTS_URL, ['timeout' => 10, 'http_errors' => false]);
        } catch (\Throwable $e) {
            throw new SsoException('Google certificates unreachable: ' . $e->getMessage());
        }

        $certs = json_decode((string) $response->getBody(), true);
        if ($response->getStatusCode() !== 200 || !is_array($certs) || $certs === []) {
            throw new SsoException('Google certificates unavailable: HTTP ' . $response->getStatusCode());
        }

        return array_map('strval', $certs);
    }
}
