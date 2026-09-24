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

use ITILFollowup;
use ITILSolution;
use PendingReason_Item;
use Ticket;
use TicketCost;

/**
 * Ticket-side operations of the PRE. Notes on GLPI 11 behaviour (verified in core source):
 * - entering Pending = follow-up with pending=1 + pendingreasons_id;
 * - leaving Pending = ticket status update (core drops the pending reason on its own);
 * - re-pending with a new reason overwrites previous_status with "Pending", so it is restored.
 */
final class TicketOps
{
    public static function followup(Ticket $ticket, string $content, ?int $pendingReasonsId = null): void
    {
        $input = [
            'itemtype'   => 'Ticket',
            'items_id'   => $ticket->getID(),
            'content'    => $content,
            'is_private' => 0,
        ];
        if ($pendingReasonsId !== null && $pendingReasonsId > 0) {
            $input['pending']           = 1;
            $input['pendingreasons_id'] = $pendingReasonsId;
        }
        if (!(new ITILFollowup())->add($input)) {
            throw new \RuntimeException(sprintf(__('Não foi possível registrar o acompanhamento no ticket #%d.', 'gac'), $ticket->getID()));
        }
    }

    public static function keepPendingWithReason(Ticket $ticket, int $reasonId, string $content): void
    {
        $existing = PendingReason_Item::getForItem($ticket);
        $original = $existing ? ($existing->fields['previous_status'] ?? null) : null;

        self::followup($ticket, $content, $reasonId);

        if ($original !== null) {
            $ticket->getFromDB($ticket->getID());
            PendingReason_Item::updateForItem($ticket, ['previous_status' => $original]);
        }
    }

    public static function leavePending(Ticket $ticket, string $content): void
    {
        $ticket->getFromDB($ticket->getID());
        if ((int) $ticket->fields['status'] === Ticket::WAITING) {
            $pending = PendingReason_Item::getForItem($ticket);
            $back    = $pending ? (int) ($pending->fields['previous_status'] ?: Ticket::ASSIGNED) : Ticket::ASSIGNED;
            if ($back === Ticket::WAITING) {
                $back = Ticket::ASSIGNED;
            }
            if (!$ticket->update(['id' => $ticket->getID(), 'status' => $back])) {
                throw new \RuntimeException(sprintf(__('Não foi possível alterar o status do ticket #%d.', 'gac'), $ticket->getID()));
            }
        }
        self::followup($ticket, $content);
    }

    public static function solve(Ticket $ticket, string $content): void
    {
        $id = (new ITILSolution())->add([
            'itemtype' => 'Ticket',
            'items_id' => $ticket->getID(),
            'content'  => $content,
        ]);
        if (!$id) {
            throw new \RuntimeException(sprintf(__('Não foi possível solucionar o ticket #%d.', 'gac'), $ticket->getID()));
        }
    }

    public static function addCost(Ticket $ticket, string $name, float $cost, string $date): int
    {
        $id = (new TicketCost())->add([
            'tickets_id'  => $ticket->getID(),
            'name'        => $name,
            'cost_fixed'  => $cost,
            'begin_date'  => $date,
            'end_date'    => $date,
            'entities_id' => (int) $ticket->fields['entities_id'],
        ]);
        if (!$id) {
            throw new \RuntimeException(sprintf(__('Não foi possível registrar o custo no ticket #%d.', 'gac'), $ticket->getID()));
        }
        return (int) $id;
    }
}
