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

/** The authorization rules that refer to a workspace key (spec S27, S29). */
final class WorkspaceRuleUsage
{
    /**
     * The rules that use one of the keys in a "Workspace do Google" criterion, in any condition and
     * active or not (a rule can be switched on again later). A rule that is deleted no longer exists.
     *
     * @param list<string> $keys
     * @return list<array{rule_id: int, name: string, is_active: bool, key: string}>
     */
    public static function rulesUsing(array $keys): array
    {
        global $DB;

        if ($keys === []) {
            return [];
        }

        $found = [];
        foreach ($DB->request([
            'SELECT'     => ['glpi_rules.id AS rule_id', 'glpi_rules.name', 'glpi_rules.is_active', 'glpi_rulecriterias.pattern AS ws_key'],
            'FROM'       => 'glpi_rulecriterias',
            'INNER JOIN' => ['glpi_rules' => ['ON' => ['glpi_rulecriterias' => 'rules_id', 'glpi_rules' => 'id']]],
            'WHERE'      => [
                'glpi_rulecriterias.criteria' => RuleInput::WORKSPACE_CRITERION,
                'glpi_rulecriterias.pattern'  => $keys,
                'glpi_rules.sub_type'         => \RuleRight::class,
            ],
            'ORDER'      => ['glpi_rules.name', 'glpi_rules.id'],
        ]) as $row) {
            $found[(int) $row['rule_id'] . '|' . $row['ws_key']] = [
                'rule_id'   => (int) $row['rule_id'],
                'name'      => (string) $row['name'],
                'is_active' => (int) $row['is_active'] === 1,
                'key'       => (string) $row['ws_key'],
            ];
        }

        return array_values($found);
    }

    /** The workspace key the rule already has in a "Workspace do Google" criterion, if any. */
    public static function workspaceKeyOfRule(int $ruleId): ?string
    {
        global $DB;

        if ($ruleId <= 0) {
            return null;
        }

        foreach ($DB->request([
            'SELECT' => ['pattern'],
            'FROM'   => 'glpi_rulecriterias',
            'WHERE'  => ['rules_id' => $ruleId, 'criteria' => RuleInput::WORKSPACE_CRITERION],
            'ORDER'  => ['id'],
            'LIMIT'  => 1,
        ]) as $row) {
            $key = trim((string) $row['pattern']);

            return $key === '' ? null : $key;
        }

        return null;
    }
}
