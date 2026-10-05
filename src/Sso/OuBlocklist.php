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
 * "OUs sempre bloqueadas" (spec S9): evaluated before the rules engine, so the company rule that
 * teachers cannot log in does not depend on rule ordering being right.
 */
final class OuBlocklist
{
    /** @var list<string> */
    private array $paths;

    /** @param list<string> $paths */
    public function __construct(array $paths)
    {
        $unique = [];
        foreach ($paths as $path) {
            $normalized = OuPath::normalize($path);
            // A blocked root would lock everybody out of the Google login.
            if ($normalized !== '/') {
                $unique[$normalized] = true;
            }
        }
        $this->paths = array_keys($unique);
    }

    /** One path per line; blank lines and lines starting with "#" are ignored. */
    public static function fromText(string $text): self
    {
        $paths = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $paths[] = $line;
        }

        return new self($paths);
    }

    /** @return ?string the blocked base path that matched (normalized), or null */
    public function matches(string $ouPath): ?string
    {
        foreach ($this->paths as $base) {
            if (OuPath::isUnder($ouPath, $base)) {
                return $base;
            }
        }

        return null;
    }

    public function isEmpty(): bool
    {
        return $this->paths === [];
    }

    /** @return list<string> */
    public function paths(): array
    {
        return $this->paths;
    }
}
