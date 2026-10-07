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
 * Reads a user's org unit (and photo, spec S33) from the Google Admin SDK Directory API, using a
 * service account with domain-wide delegation that impersonates a read-only admin (spec S3).
 */
final class DirectoryClient
{
    private const USERS_URI = 'https://admin.googleapis.com/admin/directory/v1/users/';
    private const SCOPES    = ['https://www.googleapis.com/auth/admin.directory.user.readonly'];

    /** The access token of this client, requested once and reused by its next calls. */
    private ?string $token = null;

    /**
     * @param array<string, string> $settings     SsoConfig::load() (the shared service account)
     * @param string                $adminSubject the read-only admin of the user's workspace (spec S24)
     */
    public function __construct(private readonly array $settings, private readonly string $adminSubject) {}

    /**
     * What the login needs from the user's Directory record: the org unit and the etag of the photo
     * (empty when the account has none). One call, as before.
     *
     * @return array{ou: string, photoEtag: string}
     * @throws SsoException
     */
    public function lookup(string $email): array
    {
        $client = \Toolbox::getGuzzleClient();
        $token  = $this->token($client);

        try {
            $response = $client->get(self::USERS_URI . rawurlencode($email), [
                'headers'     => ['Authorization' => 'Bearer ' . $token],
                'query'       => ['fields' => 'orgUnitPath,suspended,thumbnailPhotoEtag'],
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

        return [
            'ou'        => $path,
            'photoEtag' => is_string($body['thumbnailPhotoEtag'] ?? null) ? $body['thumbnailPhotoEtag'] : '',
        ];
    }

    /** @throws SsoException */
    public function orgUnitPath(string $email): string
    {
        return $this->lookup($email)['ou'];
    }

    /**
     * The user's thumbnail photo (spec S33), or null when the account has none.
     *
     * @throws SsoException
     */
    public function photo(string $email): ?string
    {
        $client = \Toolbox::getGuzzleClient();
        $token  = $this->token($client);

        try {
            $response = $client->get(self::USERS_URI . rawurlencode($email) . '/photos/thumbnail', [
                'headers'     => ['Authorization' => 'Bearer ' . $token],
                'timeout'     => 10,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            throw new SsoException('Directory API unreachable: ' . $e->getMessage());
        }

        $status = $response->getStatusCode();
        if ($status === 404) {
            return null;
        }

        $body = json_decode((string) $response->getBody(), true);
        if ($status !== 200 || !is_array($body)) {
            throw new SsoException('Directory API returned HTTP ' . $status . self::errorDetail($body));
        }

        $bytes = PhotoImage::decode(is_string($body['photoData'] ?? null) ? $body['photoData'] : '');

        return $bytes === '' ? null : $bytes;
    }

    /** @throws SsoException */
    private function token(\GuzzleHttp\Client $client): string
    {
        return $this->token ??= $this->accessToken($client);
    }

    /**
     * The reason and message Google puts in an error body (for example "forbidden: Not Authorized
     * to access this resource/api" or "accessNotConfigured"). They never carry tokens or keys, and
     * they are what tells a missing admin privilege from a disabled API.
     */
    public static function errorDetail(mixed $body): string
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
        return GoogleServiceToken::fetch($client, $this->settings, $this->adminSubject, self::SCOPES);
    }
}
