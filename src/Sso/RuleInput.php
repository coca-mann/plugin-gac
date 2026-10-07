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
 * What the Google login hands to the authorization rules engine (spec S4, S26): the OU ancestors
 * for the "OU do Google Workspace" criterion and the workspace key for the "Workspace do Google"
 * criterion. Pure.
 */
final class RuleInput
{
    public const OU_CRITERION        = 'GOOGLE_OU';
    public const WORKSPACE_CRITERION = 'GOOGLE_WORKSPACE';

    private const OU_PARAM        = 'google_ou';
    private const WORKSPACE_PARAM = 'google_workspace';

    /**
     * The Google part of the params given to processAllRules(). Without a workspace key the
     * workspace param is left out, so rules that never mention it behave as before (S26).
     *
     * @param list<string> $ancestors OuPath::ancestors() of the user's OU
     * @return array<string, mixed>
     */
    public static function googleParams(array $ancestors, string $workspaceKey): array
    {
        $params = [self::OU_PARAM => $ancestors];
        if ($workspaceKey !== '') {
            $params[self::WORKSPACE_PARAM] = $workspaceKey;
        }

        return $params;
    }

    /**
     * The value of each criterion, from the params of processAllRules() (hook
     * ruleCollectionPrepareInputDataForProcess).
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function engineInput(array $params): array
    {
        $input = [];

        $ou = $params[self::OU_PARAM] ?? null;
        if (is_array($ou)) {
            $input[self::OU_CRITERION] = $ou;
        }

        $workspace = $params[self::WORKSPACE_PARAM] ?? null;
        if (is_string($workspace) && $workspace !== '') {
            $input[self::WORKSPACE_CRITERION] = $workspace;
        }

        return $input;
    }
}
