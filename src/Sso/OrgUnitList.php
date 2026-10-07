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
 * The org unit list of the workspaces (spec S28, S29): reads the Directory API answer, builds the
 * endpoint payload and the cache envelope. Pure.
 */
final class OrgUnitList
{
    private const CACHE_VERSION = 1;

    /**
     * The org unit paths of an `orgunits.list` answer, without the root, sorted so that a child
     * comes right after its parent. The Google's letter case is kept.
     *
     * @param array<string, mixed> $body
     * @return list<string>
     */
    public static function pathsFromApi(array $body): array
    {
        $units = $body['organizationUnits'] ?? null;
        if (!is_array($units)) {
            return [];
        }

        $paths = [];
        foreach ($units as $unit) {
            $path = is_array($unit) ? trim((string) ($unit['orgUnitPath'] ?? '')) : '';
            if ($path === '' || $path === '/') {
                continue;
            }
            if ($path[0] !== '/') {
                $path = '/' . $path;
            }
            $paths[$path] = true;
        }

        $list = array_keys($paths);
        // "/" sorts before every other character, so "/a/b" stays next to "/a" and not after "/a-b".
        usort($list, static fn (string $a, string $b): int => strcmp(
            strtr(mb_strtolower($a), '/', "\x01") . "\0" . $a,
            strtr(mb_strtolower($b), '/', "\x01") . "\0" . $b
        ));

        return $list;
    }

    /** What the picker shows: the workspace name, then the path (spec S29). */
    public static function label(string $workspaceName, string $path): string
    {
        return $workspaceName . ' - ' . $path;
    }

    /**
     * The JSON body of the orgunits endpoint. A workspace whose list could not be read keeps its
     * error and has no OUs, so one failure never hides the others.
     *
     * @param list<array{key: string, name: string, paths: ?list<string>, error: string}> $groups
     * @return array{workspaces: list<array{key: string, name: string, error: string, ous: list<array{path: string, label: string, repeated: bool}>}>}
     */
    public static function payload(array $groups): array
    {
        $count = [];
        foreach ($groups as $group) {
            foreach (array_unique(array_map('mb_strtolower', $group['paths'] ?? [])) as $lower) {
                $count[$lower] = ($count[$lower] ?? 0) + 1;
            }
        }

        $workspaces = [];
        foreach ($groups as $group) {
            $ous = [];
            foreach ($group['paths'] ?? [] as $path) {
                $ous[] = [
                    'path'     => $path,
                    'label'    => self::label($group['name'], $path),
                    'repeated' => ($count[mb_strtolower($path)] ?? 0) > 1,
                ];
            }
            $workspaces[] = ['key' => $group['key'], 'name' => $group['name'], 'error' => $group['error'], 'ous' => $ous];
        }

        return ['workspaces' => $workspaces];
    }

    /**
     * @param list<string> $paths
     * @return array{v: int, at: int, paths: list<string>}
     */
    public static function pack(array $paths, int $now): array
    {
        return ['v' => self::CACHE_VERSION, 'at' => $now, 'paths' => array_values($paths)];
    }

    /** @return ?array{at: int, paths: list<string>} null when the cached value is not a valid envelope */
    public static function unpack(mixed $data): ?array
    {
        if (!is_array($data) || ($data['v'] ?? null) !== self::CACHE_VERSION || !is_array($data['paths'] ?? null)) {
            return null;
        }

        $paths = [];
        foreach ($data['paths'] as $path) {
            if (!is_string($path) || $path === '' || $path[0] !== '/') {
                return null;
            }
            $paths[] = $path;
        }

        return ['at' => (int) ($data['at'] ?? 0), 'paths' => $paths];
    }
}
