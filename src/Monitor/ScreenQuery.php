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

use Config;
use Html;
use SavedSearch;
use Search;
use Ticket;
use User;

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
     * @return array{columns: list<array{key: string, label: string}>, rows: list<array<string, string>>, priority_colors: array<int, string>}
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

        $sortMode = TicketSortOrder::isValidMode((string) ($screen->fields['sort_mode'] ?? ''))
            ? (string) $screen->fields['sort_mode']
            : TicketSortOrder::DEFAULT_MODE;
        // Priority (3) is always fetched, regardless of whether "priority" is a chosen display
        // column: it drives the row highlight color, a visual cue independent of the text
        // column. Urgency (10), status (12) and opening date (15) are likewise always needed to
        // sort even when the Tela does not display them.
        $forcedisplay = array_values(array_unique([...ColumnCatalog::searchOptionIdsFor($columns), 3, 10, 12, 15]));

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

            // Built while the session (service account or real) is still alive: columnValue()
            // formats dates through Html::convDateTime(), which reads the session's configured
            // date format — ServiceSession::logout() below wipes $_SESSION entirely, so building
            // rows after it would silently fall back to GLPI's hardcoded Y-m-d default.
            $entries = [];
            foreach ($data['data']['rows'] ?? [] as $row) {
                $idParts = self::cellParts($row, 2);
                if ($idParts === []) {
                    continue;
                }
                $id  = $idParts[0];
                $out = ['id' => $id, 'priority_raw' => (int) (self::cellParts($row, 3)[0] ?? 0)];
                foreach ($columns as $key) {
                    if ($key === 'elapsed') {
                        $openedParts = self::cellParts($row, 15);
                        $out[$key]   = $openedParts === []
                            ? ''
                            : ElapsedTimeLabel::format($openedParts[0], new \DateTimeImmutable());
                        continue;
                    }
                    $out[$key] = self::columnValue($row, $key);
                }
                $entries[] = [
                    'out'     => $out,
                    'urgency' => (int) (self::cellParts($row, 10)[0] ?? 0),
                    'status'  => (int) (self::cellParts($row, 12)[0] ?? 0),
                    'date'    => (string) (self::cellParts($row, 15)[0] ?? ''),
                ];
            }
        } finally {
            if ($asServiceAccount) {
                ServiceSession::logout();
            }
        }

        if ($sortMode === TicketSortOrder::MODE_PRIORITY) {
            usort($entries, static fn(array $a, array $b): int => TicketSortOrder::compare($a, $b));
        }
        $rows = array_map(static fn(array $entry): array => $entry['out'], $entries);

        $labels = [];
        foreach ($columns as $key) {
            $labels[] = ['key' => $key, 'label' => MonitorLabels::column($key)];
        }

        return ['columns' => $labels, 'rows' => $rows, 'priority_colors' => self::priorityColors()];
    }

    /**
     * The admin-configured priority colors (Configurações > Valores padrão > Cores das
     * Prioridades in GLPI's own UI) — a plain glpi_configs read, no session involved, so it
     * works identically for the authenticated and the service-account path. Reusing these
     * instead of a Monitor-specific palette keeps the board's priority colors consistent with
     * the rest of GLPI.
     *
     * @return array<int, string> priority value (1-6) => hex color
     */
    private static function priorityColors(): array
    {
        $raw = Config::getConfigurationValues('core', array_map(
            static fn(int $p): string => 'priority_' . $p,
            range(1, 6)
        ));
        $colors = [];
        foreach (range(1, 6) as $priority) {
            $value = $raw['priority_' . $priority] ?? '';
            if ($value !== '') {
                $colors[$priority] = (string) $value;
            }
        }
        return $colors;
    }

    /**
     * Reads one column's display value out of a search row. Three columns need their raw value
     * translated before display, all found with the probe script (plan Task 6 Step 1, Task 9
     * Step "verificar manualmente"):
     * - "status"/"priority" are raw integer codes on glpi_tickets; their search-engine "name"
     *   cell is the code itself, not a label, so they go through Ticket's own label helpers.
     * - "requester"/"technician" are actor fields (CommonITILObject search options 4/5,
     *   forcegroupby+use_subquery): their "name" cell is the linked user's **id**, not the
     *   username — GLPI only resolves it to a readable name in the pre-rendered HTML
     *   "displayname" cell (tooltips, avatars), unusable for a plain-text board — so each id is
     *   resolved through User::getFriendlyName().
     * - "opening_date" is the raw MySQL datetime; goes through Html::convDateTime() so it
     *   respects whichever date format GLPI itself is configured to show (session
     *   "glpidate_format", safe to read even without a real login — see Html::convDate()).
     *
     * @param array<string, mixed> $row
     */
    private static function columnValue(array $row, string $key): string
    {
        $optionId = ColumnCatalog::searchOptionId($key);
        if ($optionId === null) {
            return '';
        }
        $parts = self::cellParts($row, $optionId);
        if ($parts === []) {
            return '';
        }
        if ($key === 'status') {
            return (string) Ticket::getStatus((int) $parts[0]);
        }
        if ($key === 'priority') {
            return (string) Ticket::getPriorityName((int) $parts[0]);
        }
        if ($key === 'opening_date') {
            return (string) Html::convDateTime($parts[0], null, true);
        }
        if ($key === 'requester' || $key === 'technician') {
            $names = [];
            foreach ($parts as $userId) {
                $names[] = self::userFriendlyName((int) $userId);
            }
            return implode(', ', array_filter($names, static fn(string $n): bool => $n !== ''));
        }
        return implode(', ', $parts);
    }

    private static function userFriendlyName(int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }
        $user = new User();
        return $user->getFromDB($userId) ? $user->getFriendlyName() : '';
    }

    /**
     * Reads one search option's raw values out of a Search::getDatas() row, one per linked
     * record (e.g. several requesters). Legacy row format "ITEM_Ticket_<id>", which
     * Search::getDatas() parses into the key "Ticket_<id>" with each value under
     * [0..count-1]['name'] — verified against the GLPI 11 core source and the probe script
     * (plan Task 6, Step 1).
     *
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private static function cellParts(array $row, int $searchOptionId): array
    {
        $cell = $row['Ticket_' . $searchOptionId] ?? null;
        if (!is_array($cell)) {
            return [];
        }
        $count = (int) ($cell['count'] ?? 1);
        $parts = [];
        for ($i = 0; $i < $count; $i++) {
            $value = $cell[$i]['name'] ?? null;
            if ($value !== null && $value !== '') {
                $parts[] = (string) $value;
            }
        }
        return $parts;
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
