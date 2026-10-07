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
 * Pure: the row orders a Tela can opt into. "priority" comes from the Django app's
 * get_panel_data() (apps/dbcom/glpi_queries.py): GLPI priority (highest first), then status in
 * a hand-picked priority (not GLPI's numeric status value), then opening date (newest first).
 * It sorts on the ticket's *priority* (the column and colour the board shows), not on its
 * urgency: priority also depends on impact, so tickets of equal urgency can differ in priority.
 * "elapsed" puts the oldest open ticket first.
 */
final class TicketSortOrder
{
    public const MODE_PRIORITY = 'priority';
    public const MODE_ID       = 'id';
    public const MODE_ELAPSED  = 'elapsed';

    /** @var list<string> */
    public const MODES = [self::MODE_PRIORITY, self::MODE_ELAPSED, self::MODE_ID];

    public const DEFAULT_MODE = self::MODE_PRIORITY;

    /** GLPI status value => display priority (lower sorts first). Unlisted statuses sort last. */
    private const STATUS_PRIORITY = [
        1  => 1, // Novo
        2  => 2, // Em atendimento
        3  => 3, // Em atendimento (planejado)
        4  => 4, // Pendente
        10 => 5, // Aprovação
        5  => 6, // Solucionado
    ];

    public static function isValidMode(string $mode): bool
    {
        return in_array($mode, self::MODES, true);
    }

    public static function statusPriority(int $status): int
    {
        return self::STATUS_PRIORITY[$status] ?? 999;
    }

    /**
     * Comparator for usort(): priority DESC, then status priority ASC, then opening date DESC.
     *
     * @param array{priority: int, status: int, date: string} $a
     * @param array{priority: int, status: int, date: string} $b
     */
    public static function compare(array $a, array $b): int
    {
        return ($b['priority'] <=> $a['priority'])
            ?: (self::statusPriority($a['status']) <=> self::statusPriority($b['status']))
            ?: ($b['date'] <=> $a['date']);
    }

    /**
     * Comparator for usort(): opening date ASC (longest elapsed first), then priority DESC.
     *
     * @param array{priority: int, status: int, date: string} $a
     * @param array{priority: int, status: int, date: string} $b
     */
    public static function compareElapsed(array $a, array $b): int
    {
        return ($a['date'] <=> $b['date'])
            ?: ($b['priority'] <=> $a['priority']);
    }
}
