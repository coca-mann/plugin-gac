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

use CronTask;

/**
 * Audit log of every Google login attempt (spec 5.2). Not a CommonDBTM on purpose: events are
 * written while nobody is logged in, so the usual add() rights checks do not apply. The id of an
 * event is the correlation code shown to the user on failure (S20).
 */
final class SsoEvent
{
    public static function getTable(): string
    {
        return 'glpi_plugin_gac_ssoevents';
    }

    /** @return int the event id (0 when the insert failed) */
    public static function record(string $outcome, string $email, ?int $usersId, string $ouPath, string $detail = ''): int
    {
        global $DB;

        $ok = $DB->insert(self::getTable(), [
            'date'     => date('Y-m-d H:i:s'),
            'email'    => mb_substr($email, 0, 255),
            'users_id' => (int) $usersId,
            'ou_path'  => mb_substr($ouPath, 0, 500),
            'outcome'  => mb_substr($outcome, 0, 40),
            'detail'   => mb_substr($detail, 0, 1000),
        ]);

        return $ok ? (int) $DB->insertId() : 0;
    }

    /**
     * OUs whose logins were denied for lack of a rule, most recent first.
     *
     * @return list<array{ou_path: string, attempts: int, last_at: string}>
     */
    public static function pendingOus(): array
    {
        global $DB;

        // Raw SQL: the query builder has no plain GROUP BY with aggregates that is worth the
        // guesswork. Only constants are interpolated.
        $table  = self::getTable();
        $result = $DB->doQuery(
            "SELECT `ou_path`, COUNT(*) AS `attempts`, MAX(`date`) AS `last_at`
             FROM `$table`
             WHERE `outcome` = '" . Outcome::OU_UNMAPPED . "' AND `ou_path` <> ''
             GROUP BY `ou_path`
             ORDER BY `last_at` DESC
             LIMIT 200"
        );

        $out = [];
        while ($row = $DB->fetchAssoc($result)) {
            $out[] = [
                'ou_path'  => (string) $row['ou_path'],
                'attempts' => (int) $row['attempts'],
                'last_at'  => (string) $row['last_at'],
            ];
        }

        return $out;
    }

    public static function purgeOlderThan(int $days): int
    {
        global $DB;

        $limit = date('Y-m-d H:i:s', time() - $days * 86400);
        $count = countElementsInTable(self::getTable(), ['date' => ['<', $limit]]);
        if ($count > 0) {
            $DB->delete(self::getTable(), ['date' => ['<', $limit]]);
        }

        return $count;
    }

    /** @return array<string, string> */
    public static function cronInfo(string $name): array
    {
        return ['description' => __('Expurgar eventos antigos do login com Google', 'gac')];
    }

    /** Automatic action "SsoPurge": removes events older than the configured retention. */
    public static function cronSsoPurge(CronTask $task): int
    {
        $days    = SsoSettings::eventRetentionDays(SsoConfig::load());
        $removed = self::purgeOlderThan($days);
        $task->addVolume($removed);

        return $removed > 0 ? 1 : 0;
    }
}
