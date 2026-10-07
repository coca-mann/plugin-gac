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
 * The org unit list of a workspace, read from the Directory API and cached (spec S28). It asks for
 * the orgunit.readonly scope only here: the login keeps using the user scope alone (spec S3).
 */
final class OrgUnitDirectory
{
    public const TTL = 600;

    private const URI    = 'https://admin.googleapis.com/admin/directory/v1/customer/my_customer/orgunits';
    private const SCOPES = ['https://www.googleapis.com/auth/admin.directory.orgunit.readonly'];

    /**
     * @param array<string, string> $settings SsoConfig::load()
     * @return array{paths: ?list<string>, error: string} paths is null when the list could not be read
     */
    public static function forWorkspace(array $settings, Workspace $workspace, bool $refresh): array
    {
        global $GLPI_CACHE;

        $cacheKey = 'gac_sso_orgunits_' . $workspace->key;
        if (!$refresh) {
            $hit = OrgUnitList::unpack($GLPI_CACHE->get($cacheKey));
            if ($hit !== null) {
                return ['paths' => $hit['paths'], 'error' => ''];
            }
        }

        try {
            $paths = self::fetchPaths($settings, $workspace->adminSubject);
        } catch (SsoException $e) {
            \Toolbox::logInFile('gac', 'sso orgunits (' . $workspace->key . '): ' . $e->getMessage() . "\n");

            return ['paths' => null, 'error' => $e->getMessage()];
        }

        $GLPI_CACHE->set($cacheKey, OrgUnitList::pack($paths, time()), self::TTL);

        return ['paths' => $paths, 'error' => ''];
    }

    /**
     * @param array<string, string> $settings
     * @return list<string>
     * @throws SsoException
     */
    public static function fetchPaths(array $settings, string $adminSubject): array
    {
        $client = \Toolbox::getGuzzleClient();
        $token  = GoogleServiceToken::fetch($client, $settings, $adminSubject, self::SCOPES);

        try {
            $response = $client->get(self::URI, [
                'headers'     => ['Authorization' => 'Bearer ' . $token],
                'query'       => ['type' => 'all'],
                'timeout'     => 15,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            throw new SsoException('Directory API unreachable: ' . $e->getMessage());
        }

        $status = $response->getStatusCode();
        $body   = json_decode((string) $response->getBody(), true);
        if ($status !== 200 || !is_array($body)) {
            throw new SsoException('Directory API returned HTTP ' . $status . DirectoryClient::errorDetail($body));
        }

        return OrgUnitList::pathsFromApi($body);
    }
}
