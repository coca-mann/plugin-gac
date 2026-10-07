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
 * Pure: finds the address of the real client of a request that may have passed through reverse
 * proxies such as nginx (spec M21). The X-Forwarded-For header is only believed when the
 * connection comes from a proxy the administrator listed as trusted, because anyone talking
 * straight to the server can write that header themselves.
 */
final class ClientIp
{
    /**
     * @param string       $remoteAddr   the address of the TCP peer (REMOTE_ADDR)
     * @param string       $forwardedFor the X-Forwarded-For header, '' when absent
     * @param list<string> $trusted      addresses or CIDR ranges of the trusted proxies (see parseTrusted())
     */
    public static function resolve(string $remoteAddr, string $forwardedFor, array $trusted): string
    {
        $remote = self::normalize($remoteAddr);
        if ($remote === null) {
            return trim($remoteAddr);
        }
        if ($trusted === [] || !self::isTrusted($remote, $trusted) || trim($forwardedFor) === '') {
            return $remote;
        }

        // Each proxy appends the address it saw on its right, so the chain is read from the right
        // and the first hop that is not a trusted proxy is the client. Everything to its left was
        // written by the client and cannot be trusted.
        $current = $remote;
        $chain   = array_map('trim', explode(',', $forwardedFor));
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            $ip = self::normalize($chain[$i]);
            if ($ip === null) {
                return $current;
            }
            if (!self::isTrusted($ip, $trusted)) {
                return $ip;
            }
            $current = $ip;
        }
        return $current;
    }

    /**
     * Reads the administrator's list (commas, semicolons or whitespace) and keeps only valid
     * addresses and CIDR ranges, in canonical form.
     *
     * @return list<string>
     */
    public static function parseTrusted(string $setting): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', trim($setting)) ?: [] as $entry) {
            if ($entry === '') {
                continue;
            }
            $parsed = self::parseEntry($entry);
            if ($parsed !== null && !in_array($parsed['text'], $out, true)) {
                $out[] = $parsed['text'];
            }
        }
        return $out;
    }

    /** @return string|null the canonical text of an IP address, null when it is not one */
    private static function normalize(string $ip): ?string
    {
        $ip = trim($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $binary = inet_pton($ip);
        if ($binary === false) {
            return null;
        }
        // ::ffff:a.b.c.d is the same host as a.b.c.d.
        if (strlen($binary) === 16 && str_starts_with($binary, str_repeat("\0", 10) . "\xff\xff")) {
            $binary = substr($binary, 12);
        }
        $text = inet_ntop($binary);
        return $text === false ? null : $text;
    }

    /** @return array{text: string, binary: string, bits: int}|null */
    private static function parseEntry(string $entry): ?array
    {
        $prefix = null;
        if (str_contains($entry, '/')) {
            [$entry, $length] = explode('/', $entry, 2);
            if ($length === '' || !ctype_digit($length)) {
                return null;
            }
            $prefix = (int) $length;
        }
        $ip = self::normalize($entry);
        if ($ip === null) {
            return null;
        }
        $binary = (string) inet_pton($ip);
        $max    = strlen($binary) * 8;
        if ($prefix !== null && $prefix > $max) {
            return null;
        }
        $bits = $prefix ?? $max;
        return ['text' => $prefix === null ? $ip : $ip . '/' . $prefix, 'binary' => $binary, 'bits' => $bits];
    }

    /** @param list<string> $trusted */
    private static function isTrusted(string $ip, array $trusted): bool
    {
        $binary = (string) inet_pton($ip);
        foreach ($trusted as $entry) {
            $parsed = self::parseEntry($entry);
            if ($parsed === null || strlen($parsed['binary']) !== strlen($binary)) {
                continue;
            }
            if (self::samePrefix($binary, $parsed['binary'], $parsed['bits'])) {
                return true;
            }
        }
        return false;
    }

    private static function samePrefix(string $a, string $b, int $bits): bool
    {
        $whole = intdiv($bits, 8);
        if (substr($a, 0, $whole) !== substr($b, 0, $whole)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($a[$whole]) & $mask) === (ord($b[$whole]) & $mask);
    }
}
