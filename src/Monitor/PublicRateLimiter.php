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

namespace GlpiPlugin\Gac\Monitor;

/**
 * Rate limit of the session-less public endpoints (spec M21): the board page, its data and the alert
 * sound. Counters live in GLPI's own cache (no table, no migration), one per client address and one per
 * public link, in fixed one-minute windows. The counting is not atomic (the cache has no atomic
 * increment), so the limit is approximate: it is there to stop a flood, not to be an exact quota.
 *
 * It fails open: if the cache is unavailable the request goes through, because refusing every TV is
 * worse than not limiting for a moment.
 */
final class PublicRateLimiter
{
    /**
     * The address of the client of this request, honouring X-Forwarded-For only for the trusted
     * proxies listed in the settings (nginx in front of GLPI, for example).
     *
     * @param array<string, string> $settings
     */
    public static function clientIp(array $settings): string
    {
        return ClientIp::resolve(
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''),
            ClientIp::parseTrusted(MonitorSettings::trustedProxies($settings))
        );
    }

    /**
     * Counts one request against a counter.
     *
     * @return int|null seconds to wait when over the limit, null when the request may go ahead
     */
    public static function hit(string $scope, string $subject, int $limit): ?int
    {
        if ($limit <= 0) {
            return null;
        }

        global $GLPI_CACHE;
        $now = time();
        try {
            $key   = FixedWindow::key($scope, $subject, $now);
            $count = (int) $GLPI_CACHE->get($key, 0) + 1;
            $GLPI_CACHE->set($key, $count, FixedWindow::WINDOW_SECONDS * 2);
        } catch (\Throwable) {
            return null;
        }

        return FixedWindow::isLimited($count, $limit) ? FixedWindow::retryAfter($now) : null;
    }

    /**
     * Limit per client address, shared by all the public endpoints.
     *
     * @param array<string, string> $settings
     * @param bool                  $json     answer with JSON (data endpoints) or plain text (page, audio)
     */
    public static function enforceIp(array $settings, bool $json = true): void
    {
        $retry = self::hit('ip', self::clientIp($settings), MonitorSettings::publicRateIp($settings));
        if ($retry !== null) {
            self::reject($retry, $json);
        }
    }

    /**
     * Limit per public link. Call it only for a token that exists: counting every token a client
     * invents would let it fill the cache with counters.
     *
     * @param array<string, string> $settings
     */
    public static function enforceToken(array $settings, string $token): void
    {
        $retry = self::hit('token', $token, MonitorSettings::publicRateToken($settings));
        if ($retry !== null) {
            self::reject($retry, true);
        }
    }

    private static function reject(int $retryAfter, bool $json): never
    {
        http_response_code(429);
        header('Retry-After: ' . $retryAfter);
        header('Cache-Control: no-store');
        $message = __('Muitas requisições. Tente novamente em instantes.', 'gac');
        if ($json) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => $message, 'code' => 'rate_limited'], JSON_UNESCAPED_UNICODE);
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo $message;
        }
        exit;
    }
}
