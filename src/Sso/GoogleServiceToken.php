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
 * An access token of the shared service account (spec S24), obtained with domain-wide delegation
 * for the given scopes while impersonating the workspace's read-only admin (spec S3, S28).
 */
final class GoogleServiceToken
{
    private const TOKEN_URI = 'https://oauth2.googleapis.com/token';

    /**
     * @param array<string, string> $settings SsoConfig::load()
     * @param list<string>          $scopes
     * @throws SsoException
     */
    public static function fetch(\GuzzleHttp\Client $client, array $settings, string $adminSubject, array $scopes): string
    {
        $jwt = ServiceAccountJwt::build(
            SsoSettings::saClientEmail($settings),
            SsoSettings::saPrivateKey($settings),
            $adminSubject,
            $scopes,
            time()
        );
        if ($jwt === null) {
            throw new SsoException('Invalid service account private key');
        }

        try {
            $response = $client->post(self::TOKEN_URI, [
                'form_params' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion'  => $jwt,
                ],
                'timeout'     => 10,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            throw new SsoException('Service account token endpoint unreachable: ' . $e->getMessage());
        }

        $body = json_decode((string) $response->getBody(), true);
        if ($response->getStatusCode() !== 200 || !is_array($body) || !is_string($body['access_token'] ?? null)) {
            $error = is_array($body) ? (string) ($body['error'] ?? '') . ' ' . (string) ($body['error_description'] ?? '') : '';
            throw new SsoException('Service account token request failed: HTTP ' . $response->getStatusCode() . ' ' . trim($error));
        }

        return $body['access_token'];
    }
}
