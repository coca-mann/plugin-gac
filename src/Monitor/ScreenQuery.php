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

namespace GlpiPlugin\Gac\Monitor;

use SavedSearch;
use Search;
use Ticket;

/**
 * Runs a MonitorScreen's query: the SavedSearch's own criteria, entity scope forced from the
 * Tela (never from a session), and the Tela's chosen columns. See spec section 6.4 and the
 * plan's "Decisões de implementação" items 2-5.
 */
final class ScreenQuery
{
    private const LIST_LIMIT = 200;

    /**
     * @param bool $asServiceAccount Public, session-less path only (Task 8): Search::getDatas()
     *     needs a real logged-in session shape (profile, groups — not just entities, see spec
     *     R-1/R-3), so this logs in as the Monitor service account for the duration of the call
     *     and logs back out before returning. Never true for the authenticated display, which
     *     already has the technician's own real session.
     * @return array{columns: list<array{key: string, label: string}>, rows: list<array<string, string>>}
     */
    public static function run(MonitorScreen $screen, bool $asServiceAccount = false): array
    {
        $columns = $screen->displayColumns();

        $saved    = new SavedSearch();
        $hasSaved = (int) $screen->fields['savedsearches_id'] > 0
            && $saved->getFromDB((int) $screen->fields['savedsearches_id']);

        $params = [];
        if ($hasSaved) {
            parse_str((string) $saved->fields['query'], $params);
        }
        $params['reset']      = 'reset';
        $params['is_deleted'] = 0;
        $params['start']      = 0;
        $params['list_limit'] = self::LIST_LIMIT;
        $params['criteria']   = $params['criteria'] ?? [];

        $forcedisplay = ColumnCatalog::searchOptionIdsFor($columns);

        if ($asServiceAccount) {
            if (!ServiceSession::login(MonitorConfig::load())) {
                throw new \RuntimeException('Monitor service account is not configured or login failed.');
            }
        }

        try {
            $previousEntities       = $_SESSION['glpiactiveentities'] ?? null;
            $previousEntitiesString = $_SESSION['glpiactiveentities_string'] ?? null;
            $previousShowAll        = $_SESSION['glpishowallentities'] ?? null;
            self::forceEntityScope((int) $screen->fields['entities_id'], (bool) $screen->fields['is_recursive']);

            try {
                $data = Search::getDatas('Ticket', $params, $forcedisplay);
            } finally {
                // Never leaves a real session (the authenticated display, or the service
                // account below) scoped to the Tela's entity — restore exactly what was there
                // before, or clear it if there was nothing.
                if ($previousEntities === null) {
                    unset($_SESSION['glpiactiveentities'], $_SESSION['glpiactiveentities_string']);
                } else {
                    $_SESSION['glpiactiveentities']        = $previousEntities;
                    $_SESSION['glpiactiveentities_string'] = $previousEntitiesString;
                }
                if ($previousShowAll === null) {
                    unset($_SESSION['glpishowallentities']);
                } else {
                    $_SESSION['glpishowallentities'] = $previousShowAll;
                }
            }
        } finally {
            if ($asServiceAccount) {
                ServiceSession::logout();
            }
        }

        $rows = [];
        foreach ($data['data']['rows'] ?? [] as $row) {
            $id = self::cellValue($row, 2);
            if ($id === '') {
                continue;
            }
            $out = ['id' => $id];
            foreach ($columns as $key) {
                if ($key === 'elapsed') {
                    $opened    = self::cellValue($row, 15);
                    $out[$key] = $opened === '' ? '' : ElapsedTimeLabel::format($opened, new \DateTimeImmutable());
                    continue;
                }
                $out[$key] = self::columnValue($row, $key);
            }
            $rows[] = $out;
        }

        $labels = [];
        foreach ($columns as $key) {
            $labels[] = ['key' => $key, 'label' => MonitorLabels::column($key)];
        }

        return ['columns' => $labels, 'rows' => $rows];
    }

    /**
     * Reads one column's display value out of a search row. "status" and "priority" are stored
     * as raw integer codes on glpi_tickets (verified with the probe script, plan Task 6 Step 1):
     * their search-engine "name" cell is the code itself, not a label, so they go through
     * Ticket's own label helpers instead of being shown as a bare number.
     *
     * @param array<string, mixed> $row
     */
    private static function columnValue(array $row, string $key): string
    {
        $optionId = ColumnCatalog::searchOptionId($key);
        if ($optionId === null) {
            return '';
        }
        $raw = self::cellValue($row, $optionId);
        if ($raw === '') {
            return '';
        }
        return match ($key) {
            'status'   => (string) Ticket::getStatus((int) $raw),
            'priority' => (string) Ticket::getPriorityName((int) $raw),
            default    => $raw,
        };
    }

    /**
     * Reads one search option's raw value out of a Search::getDatas() row. Legacy row format
     * "ITEM_Ticket_<id>", which Search::getDatas() parses into the key "Ticket_<id>" with each
     * value under [0..count-1]['name'] — verified against the GLPI 11 core source and the probe
     * script (plan Task 6, Step 1).
     *
     * @param array<string, mixed> $row
     */
    private static function cellValue(array $row, int $searchOptionId): string
    {
        $cell = $row['Ticket_' . $searchOptionId] ?? null;
        if (!is_array($cell)) {
            return '';
        }
        $count = (int) ($cell['count'] ?? 1);
        $parts = [];
        for ($i = 0; $i < $count; $i++) {
            $value = $cell[$i]['name'] ?? null;
            if ($value !== null && $value !== '') {
                $parts[] = (string) $value;
            }
        }
        return implode(', ', $parts);
    }

    private static function forceEntityScope(int $entitiesId, bool $recursive): void
    {
        $ids = [$entitiesId => $entitiesId];
        if ($recursive) {
            foreach (array_keys(getSonsOf('glpi_entities', $entitiesId)) as $son) {
                $ids[$son] = $son;
            }
        }
        $_SESSION['glpiactiveentities']        = $ids;
        $_SESSION['glpiactiveentities_string'] = "'" . implode("', '", $ids) . "'";
        // DbUtils::getEntitiesRestrictRequest() skips entity restriction entirely when this
        // flag is set (verified with the probe, plan Task 6 Step 1) — a technician whose
        // profile can see every entity would otherwise see every entity on the Tela too,
        // regardless of the Tela's own entities_id/is_recursive.
        $_SESSION['glpishowallentities'] = 0;
    }
}
