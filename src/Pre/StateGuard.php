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

namespace GlpiPlugin\Gac\Pre;

use PendingReason;
use State;

/**
 * A State or a PendingReason belongs to an entity and is visible to that entity, and to its
 * descendants only when recursive (spec section 10). The global mapping must be valid for the
 * entity where it is applied: the asset's entity for a State, the ticket's for a PendingReason.
 */
final class StateGuard
{
    public static function isUsable(int $statesId, int $entityId): bool
    {
        if ($statesId <= 0) {
            return false;
        }
        $state = new State();
        if (!$state->getFromDB($statesId)) {
            return false;
        }
        return self::isVisible((int) $state->fields['entities_id'], (bool) $state->fields['is_recursive'], $entityId);
    }

    public static function isReasonUsable(int $reasonId, int $entityId): bool
    {
        if ($reasonId <= 0) {
            return false;
        }
        $reason = new PendingReason();
        if (!$reason->getFromDB($reasonId)) {
            return false;
        }
        return self::isVisible((int) $reason->fields['entities_id'], (bool) $reason->fields['is_recursive'], $entityId);
    }

    private static function isVisible(int $ownerEntity, bool $recursive, int $entityId): bool
    {
        if ($ownerEntity === $entityId) {
            return true;
        }
        if (!$recursive) {
            return false;
        }
        return in_array($ownerEntity, array_map('intval', getAncestorsOf('glpi_entities', $entityId)), true);
    }
}
