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

/** Runs GLPI's authorization rules for a Google login. */
final class RuleRunner
{
    /**
     * @param list<string> $ancestors OuPath::ancestors() of the user's OU
     * @return array<string, mixed> the engine's output array
     */
    public static function run(string $email, array $ancestors): array
    {
        $collection = new \RuleRightCollection();

        // The output is seeded with the name only: if "entities_id" shows up in it afterwards, a
        // rule set the default entity (the _entities_id_default action).
        return $collection->processAllRules([], ['name' => $email], [
            'type'      => \Auth::EXTERNAL,
            'login'     => $email,
            'email'     => $email,
            'google_ou' => $ancestors,
        ]);
    }

    /** @param list<string> $ancestors */
    public static function result(string $email, array $ancestors): RuleResult
    {
        return RuleResult::fromOutput(self::run($email, $ancestors));
    }
}
