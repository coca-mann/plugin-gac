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

namespace GlpiPlugin\Gac\Pre;

/**
 * Pure transition rules for the PRE (spec sections 6.1 to 6.3). No GLPI dependency.
 */
final class StateMachine
{
    /**
     * Status the protocol should have given its lines. Draft and Canceled never change by
     * derivation; Closed and Partial are computed, never typed (spec 6.1).
     *
     * @param list<ItemStatus> $itemStatuses
     */
    public static function deriveProtocolStatus(ProtocolStatus $current, array $itemStatuses): ProtocolStatus
    {
        if ($current === ProtocolStatus::Draft || $current === ProtocolStatus::Canceled) {
            return $current;
        }
        if ($itemStatuses === []) {
            return $current;
        }
        $final = 0;
        foreach ($itemStatuses as $status) {
            if ($status->isFinal()) {
                $final++;
            }
        }
        if ($final === count($itemStatuses)) {
            return ProtocolStatus::Closed;
        }
        return $final > 0 ? ProtocolStatus::Partial : ProtocolStatus::Sent;
    }

    public static function canImportLines(ProtocolStatus $p): bool
    {
        return $p === ProtocolStatus::Draft;
    }

    public static function canStartSend(ProtocolStatus $p): bool
    {
        return $p === ProtocolStatus::Draft;
    }

    public static function canSendLine(ProtocolStatus $p, ItemStatus $i): bool
    {
        return $p === ProtocolStatus::Sent && $i === ItemStatus::PendingSend;
    }

    /** Removal is allowed in Draft, plus the "remove failed line" exception in Sent (spec 6.2). */
    public static function canRemoveLine(ProtocolStatus $p, ItemStatus $i): bool
    {
        return $i === ItemStatus::PendingSend
            && ($p === ProtocolStatus::Draft || $p === ProtocolStatus::Sent);
    }

    public static function removeRequiresReason(ProtocolStatus $p): bool
    {
        return $p !== ProtocolStatus::Draft;
    }

    public static function canRegisterReturn(ProtocolStatus $p, ItemStatus $i): bool
    {
        return $i === ItemStatus::AtSupplier
            && ($p === ProtocolStatus::Sent || $p === ProtocolStatus::Partial);
    }

    public static function canReopen(ProtocolStatus $p): bool
    {
        return $p === ProtocolStatus::Closed;
    }

    public static function canCancel(ProtocolStatus $p): bool
    {
        return $p === ProtocolStatus::Draft;
    }

    /**
     * Repaired / no-fault outcomes always end with destination None; defective outcomes need
     * an explicit Writeoff or KeepDefective (spec 6.3).
     *
     * @throws \InvalidArgumentException
     */
    public static function normalizeDestination(Outcome $outcome, ?Destination $destination): Destination
    {
        if (!$outcome->isDefective()) {
            if ($destination !== null && $destination !== Destination::None) {
                throw new \InvalidArgumentException('A non-defective outcome cannot have a destination.');
            }
            return Destination::None;
        }
        if ($destination !== Destination::Writeoff && $destination !== Destination::KeepDefective) {
            throw new \InvalidArgumentException('A defective outcome requires writeoff or keep_defective.');
        }
        return $destination;
    }
}
