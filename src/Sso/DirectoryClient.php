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
 * Reads a user's org unit from the Google Admin SDK Directory API, using a service account with
 * domain-wide delegation that impersonates a read-only admin (spec S3).
 */
final class DirectoryClient
{
    private const TOKEN_URI = 'https://oauth2.googleapis.com/token';
    private const USERS_URI = 'https://admin.googleapis.com/admin/directory/v1/users/';
    private const SCOPES    = ['https://www.googleapis.com/auth/admin.directory.user.readonly'];

    /** @param array<string, string> $settings SsoConfig::load() */
    public function __construct(private readonly array $settings) {}

    /** @throws SsoException */
    public function orgUnitPath(string $email): string
    {
        $client = \Toolbox::getGuzzleClient();
        $token  = $this->accessToken($client);

        try {
            $response = $client->get(self::USERS_URI . rawurlencode($email), [
                'headers'     => ['Authorization' => 'Bearer ' . $token],
                'query'       => ['fields' => 'orgUnitPath,suspended'],
                'timeout'     => 10,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            throw new SsoException('Directory API unreachable: ' . $e->getMessage());
        }

        $status = $response->getStatusCode();
        $body   = json_decode((string) $response->getBody(), true);

        if ($status === 404) {
            throw new SsoException('Directory user not found');
        }
        if ($status !== 200 || !is_array($body)) {
            throw new SsoException('Directory API returned HTTP ' . $status . self::errorDetail($body));
        }
        if (!empty($body['suspended'])) {
            throw new SsoException('Directory user is suspended');
        }

        $path = $body['orgUnitPath'] ?? null;
        if (!is_string($path) || $path === '') {
            throw new SsoException('Directory user has no orgUnitPath');
        }

        return $path;
    }

    /**
     * The reason and message Google puts in an error body (for example "forbidden: Not Authorized
     * to access this resource/api" or "accessNotConfigured"). They never carry tokens or keys, and
     * they are what tells a missing admin privilege from a disabled API.
     */
    private static function errorDetail(mixed $body): string
    {
        $error = is_array($body) ? ($body['error'] ?? null) : null;
        if (!is_array($error)) {
            return '';
        }

        $reason  = is_array($error['errors'][0] ?? null) ? (string) ($error['errors'][0]['reason'] ?? '') : '';
        $message = (string) ($error['message'] ?? '');
        $detail  = trim($reason . ' ' . $message);

        return $detail === '' ? '' : ' (' . mb_substr($detail, 0, 200) . ')';
    }

    /** @throws SsoException */
    private function accessToken(\GuzzleHttp\Client $client): string
    {
        $jwt = ServiceAccountJwt::build(
            SsoSettings::saClientEmail($this->settings),
            SsoSettings::saPrivateKey($this->settings),
            SsoSettings::saAdminSubject($this->settings),
            self::SCOPES,
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
