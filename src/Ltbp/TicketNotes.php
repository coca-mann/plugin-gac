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

namespace GlpiPlugin\Gac\Ltbp;

use GlpiPlugin\Gac\Pre\ItemStatus as PreItemStatus;
use GlpiPlugin\Gac\Pre\RepairProtocolItem;
use GlpiPlugin\Gac\Shared\TicketOps;
use Ticket;
use Toolbox;

/**
 * Follow-ups on the source ticket at the laudo's milestones, and the optional solution when the
 * laudo completes (spec L25). Only lines that came from a PRE have a ticket. It runs after the
 * commit of each step, so a ticket problem is a warning, never an undo (PRE D19 principle).
 */
final class TicketNotes
{
    /** @return list<string> warnings */
    public static function milestone(Ltbp $laudo, string $kind): array
    {
        $warnings = [];
        $settings = LtbpConfig::load();

        foreach (self::assetsByTicket($laudo) as $ticketId => $assets) {
            try {
                $ticket = new Ticket();
                if (!$ticket->getFromDB($ticketId) || self::isSolvedOrClosed($ticket)) {
                    continue; // a ticket already closed by hand is ignored without error (plan decision 16)
                }

                TicketOps::followup($ticket, self::text($laudo, $kind, $assets));

                if (
                    $kind === 'completed'
                    && TicketSolvePolicy::shouldSolve(
                        LtbpSettings::solveTicketOnCompletion($settings),
                        self::otherOpenLines($laudo, $ticketId)
                    )
                ) {
                    TicketOps::solve($ticket, sprintf(
                        __('Baixa patrimonial concluída no laudo %1$s: %2$s.', 'gac'),
                        $laudo->fields['number'],
                        implode(', ', $assets)
                    ));
                }
            } catch (\Throwable $e) {
                Toolbox::logInFile('gac', sprintf("ticket note #%d (%s) failed: %s\n", $ticketId, $kind, $e->getMessage()));
                $warnings[] = sprintf(__('Ticket #%1$d: %2$s', 'gac'), $ticketId, $e->getMessage());
            }
        }

        return $warnings;
    }

    /** @return array<int, list<string>> ticket id => names of the laudo's assets that came from it */
    private static function assetsByTicket(Ltbp $laudo): array
    {
        $byTicket = [];
        foreach ($laudo->lines() as $line) {
            if ((int) $line['tickets_id'] > 0) {
                $byTicket[(int) $line['tickets_id']][] = (string) $line['item_name'];
            }
        }
        return $byTicket;
    }

    private static function isSolvedOrClosed(Ticket $ticket): bool
    {
        return in_array((int) $ticket->fields['status'], [Ticket::SOLVED, Ticket::CLOSED], true);
    }

    /** @param list<string> $assets */
    private static function text(Ltbp $laudo, string $kind, array $assets): string
    {
        $list   = implode(', ', $assets);
        $number = (string) $laudo->fields['number'];

        return match ($kind) {
            'issued'      => sprintf(__('Equipamento(s) %1$s incluído(s) no laudo de baixa patrimonial %2$s, emitido para assinatura.', 'gac'), $list, $number),
            'written_off' => sprintf(__('Baixa patrimonial confirmada pelo patrimônio para %1$s (laudo %2$s).', 'gac'), $list, $number),
            'completed'   => sprintf(
                __('Destinação concluída para %1$s (laudo %2$s): %3$s. Beneficiário: %4$s.', 'gac'),
                $list,
                $number,
                $laudo->getDestination() === null ? '' : Labels::destination($laudo->getDestination()),
                (string) $laudo->fields['supplier_name']
            ),
            default       => $list,
        };
    }

    /**
     * Lines of the same ticket that are still open elsewhere: active PRE lines (any PRE) and lines
     * of other laudos that are not completed or canceled. While there are any, the ticket is not
     * solved (same idea as PRE D17).
     */
    private static function otherOpenLines(Ltbp $laudo, int $ticketId): int
    {
        global $DB;

        $preLines = countElementsInTable(RepairProtocolItem::getTable(), [
            'tickets_id' => $ticketId,
            'status'     => [
                PreItemStatus::PendingSend->value,
                PreItemStatus::Sending->value,
                PreItemStatus::AtSupplier->value,
            ],
        ]);

        $notFinal = array_values(array_map(
            static fn(Status $s): string => $s->value,
            array_filter(Status::cases(), static fn(Status $s): bool => !$s->isFinal())
        ));
        $items  = LtbpItem::getTable();
        $laudos = Ltbp::getTable();
        $row = $DB->request([
            'COUNT'      => 'cpt',
            'FROM'       => $items,
            'INNER JOIN' => [
                $laudos => ['ON' => [$items => 'plugin_gac_ltbps_id', $laudos => 'id']],
            ],
            'WHERE' => [
                "$items.tickets_id" => $ticketId,
                "$laudos.id"        => ['<>', (int) $laudo->getID()],
                "$laudos.status"    => $notFinal,
            ],
        ])->current();

        return $preLines + (int) ($row['cpt'] ?? 0);
    }
}
