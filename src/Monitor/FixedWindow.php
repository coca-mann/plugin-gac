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

namespace GlpiPlugin\Gac\Monitor;

/**
 * Pure: the arithmetic of the fixed one-minute window the public endpoints are rate limited by
 * (spec M21). Nothing here touches the cache or the clock: the caller passes the time in.
 */
final class FixedWindow
{
    public const WINDOW_SECONDS = 60;

    public static function windowStart(int $now): int
    {
        return $now - ($now % self::WINDOW_SECONDS);
    }

    /**
     * The cache key of one counter. The subject (an address, a token) is user-controlled, so it is
     * hashed: PSR-16 keys cannot hold {}()/\@: and a hash also keeps the key short.
     */
    public static function key(string $scope, string $subject, int $now): string
    {
        return 'gac_rl_' . sha1($scope . '|' . $subject) . '_' . self::windowStart($now);
    }

    /** @param int $countAfterHit the counter value including the request being judged */
    public static function isLimited(int $countAfterHit, int $limit): bool
    {
        return $limit > 0 && $countAfterHit > $limit;
    }

    /** Seconds until the window ends and the counter starts over; never less than one. */
    public static function retryAfter(int $now): int
    {
        return max(1, self::windowStart($now) + self::WINDOW_SECONDS - $now);
    }
}
