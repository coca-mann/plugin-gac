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

/**
 * Adds the "OU do Google Workspace" criterion to GLPI's authorization rules (RuleRight) and feeds
 * its value to the engine (spec S4). The value is the list of ancestor paths of the user's OU, so
 * a rule "OU do Google is /a/b" matches /a/b and everything below it. Rules must use only the
 * "is" condition on this criterion (spec V15). The "Workspace do Google" criterion (spec S26) is
 * matched against the workspace key.
 */
final class RuleHooks
{
    public const CRITERION           = RuleInput::OU_CRITERION;
    public const WORKSPACE_CRITERION = RuleInput::WORKSPACE_CRITERION;

    /**
     * Hook getRuleCriteria. Receives ['rule_itemtype' => ..., 'values' => current criteria].
     *
     * @param array<string, mixed> $params
     * @return array<string, array<string, mixed>>
     */
    public static function criteria(array $params): array
    {
        if (($params['rule_itemtype'] ?? '') !== \RuleRight::class) {
            return [];
        }

        return [
            self::CRITERION => [
                'name'      => __('OU do Google Workspace', 'gac'),
                'field'     => '',
                'table'     => '',
                'linkfield' => '',
                'virtual'   => true,
                'id'        => 'google_ou',
            ],
            self::WORKSPACE_CRITERION => [
                'name'            => __('Workspace do Google', 'gac'),
                'field'           => '',
                'table'           => '',
                'linkfield'       => '',
                'virtual'         => true,
                'id'              => 'google_workspace',
                'allow_condition' => [\Rule::PATTERN_IS],
            ],
        ];
    }

    /**
     * Hook ruleCollectionPrepareInputDataForProcess. Receives
     * ['rule_itemtype' => ..., 'values' => ['input' => ..., 'params' => the processAllRules() params]].
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function inputData(array $params): array
    {
        if (($params['rule_itemtype'] ?? '') !== \RuleRight::class) {
            return [];
        }

        $ruleParams = $params['values']['params'] ?? null;

        return RuleInput::engineInput(is_array($ruleParams) ? $ruleParams : []);
    }
}
