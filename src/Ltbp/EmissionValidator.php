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

namespace GlpiPlugin\Gac\Ltbp;

/**
 * Pure: the pre-checks of "Emitir" (spec 6.1), as codes. The service turns each code into a
 * translated message, so this stays free of GLPI.
 */
final class EmissionValidator
{
    /**
     * @param array{destination: string, line_count: int, lines_without_reason: int, missing_state_roles: list<string>, directors_missing: bool, conflicts: int} $facts
     * @return list<string> codes: destination, no_lines, reason, states, directors, conflicts
     */
    public static function validate(array $facts): array
    {
        $errors = [];
        if (Destination::tryFrom($facts['destination']) === null) {
            $errors[] = 'destination';
        }
        if ($facts['line_count'] <= 0) {
            $errors[] = 'no_lines';
        }
        if ($facts['lines_without_reason'] > 0) {
            $errors[] = 'reason';
        }
        if ($facts['missing_state_roles'] !== []) {
            $errors[] = 'states';
        }
        if ($facts['directors_missing']) {
            $errors[] = 'directors';
        }
        if ($facts['conflicts'] > 0) {
            $errors[] = 'conflicts';
        }
        return $errors;
    }
}
