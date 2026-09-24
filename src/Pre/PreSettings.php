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
 * Typed view over the raw PRE configuration array stored in glpi_configs (context plugin:gac,
 * keys prefixed pre_). Pure: no GLPI calls. See spec sections 5.3, 6.4 and 10.
 */
final class PreSettings
{
    public const STATE_ROLES  = ['at_supplier', 'defective', 'awaiting_writeoff'];
    public const REASON_ROLES = ['at_supplier', 'awaiting_writeoff', 'awaiting_decision'];
    public const ACTION_KEYS  = ['repaired', 'no_fault', 'writeoff', 'keep_defective'];

    public const TICKET_ACTIONS = ['reopen', 'solve', 'keep_pending'];
    public const ASSET_ACTIONS  = ['restore_previous', 'set_state'];

    /** @return array<string, string> */
    public static function defaults(): array
    {
        $raw = [
            'pre_category_ids'               => '[]',
            'pre_include_subcategories'      => '1',
            'pre_logo_documentcategories_id' => '0',
            'pre_actions'                    => json_encode(self::defaultActions(), JSON_THROW_ON_ERROR),
        ];
        foreach (self::STATE_ROLES as $role) {
            $raw['pre_state_' . $role] = '0';
        }
        foreach (self::REASON_ROLES as $role) {
            $raw['pre_reason_' . $role] = '0';
        }
        return $raw;
    }

    /** @return array<string, array{ticket: array{type: string, reason: string}, asset: array{type: string, state: string}}> */
    private static function defaultActions(): array
    {
        $reopen = ['type' => 'reopen', 'reason' => ''];
        $restore = ['type' => 'restore_previous', 'state' => ''];
        return [
            'repaired'       => ['ticket' => $reopen, 'asset' => $restore],
            'no_fault'       => ['ticket' => $reopen, 'asset' => $restore],
            'writeoff'       => [
                'ticket' => ['type' => 'keep_pending', 'reason' => 'awaiting_writeoff'],
                'asset'  => ['type' => 'set_state', 'state' => 'awaiting_writeoff'],
            ],
            'keep_defective' => [
                'ticket' => ['type' => 'keep_pending', 'reason' => 'awaiting_decision'],
                'asset'  => ['type' => 'set_state', 'state' => 'defective'],
            ],
        ];
    }

    /**
     * Completes missing keys with defaults, coerces types, drops unknown keys and repairs
     * invalid action definitions.
     *
     * @param array<string, mixed> $raw
     * @return array<string, string>
     */
    public static function normalize(array $raw): array
    {
        $out = self::defaults();

        $ids = [];
        $decoded = json_decode((string) ($raw['pre_category_ids'] ?? '[]'), true);
        if (is_array($decoded)) {
            foreach ($decoded as $value) {
                if (is_numeric($value) && (int) $value > 0) {
                    $ids[] = (int) $value;
                }
            }
        }
        $out['pre_category_ids'] = json_encode(array_values(array_unique($ids)), JSON_THROW_ON_ERROR);

        if (array_key_exists('pre_include_subcategories', $raw)) {
            $out['pre_include_subcategories'] = ((string) $raw['pre_include_subcategories']) === '1' ? '1' : '0';
        }

        $intKeys = ['pre_logo_documentcategories_id'];
        foreach (self::STATE_ROLES as $role) {
            $intKeys[] = 'pre_state_' . $role;
        }
        foreach (self::REASON_ROLES as $role) {
            $intKeys[] = 'pre_reason_' . $role;
        }
        foreach ($intKeys as $key) {
            if (array_key_exists($key, $raw)) {
                $out[$key] = (string) (is_numeric($raw[$key]) && (int) $raw[$key] > 0 ? (int) $raw[$key] : 0);
            }
        }

        $out['pre_actions'] = json_encode(self::normalizeActions($raw['pre_actions'] ?? null), JSON_THROW_ON_ERROR);

        return $out;
    }

    /** @return array<string, array{ticket: array{type: string, reason: string}, asset: array{type: string, state: string}}> */
    private static function normalizeActions(mixed $rawActions): array
    {
        $actions = self::defaultActions();
        if (!is_string($rawActions)) {
            return $actions;
        }
        $decoded = json_decode($rawActions, true);
        if (!is_array($decoded)) {
            return $actions;
        }
        foreach (self::ACTION_KEYS as $key) {
            $entry = $decoded[$key] ?? null;
            if (!is_array($entry)) {
                continue;
            }
            $ticketType = $entry['ticket']['type'] ?? null;
            $ticketReason = (string) ($entry['ticket']['reason'] ?? '');
            $assetType = $entry['asset']['type'] ?? null;
            $assetState = (string) ($entry['asset']['state'] ?? '');

            if (!in_array($ticketType, self::TICKET_ACTIONS, true) || !in_array($assetType, self::ASSET_ACTIONS, true)) {
                continue;
            }
            if ($ticketType === 'keep_pending' && !in_array($ticketReason, self::REASON_ROLES, true)) {
                continue;
            }
            if ($assetType === 'set_state' && !in_array($assetState, self::STATE_ROLES, true)) {
                continue;
            }
            $actions[$key] = [
                'ticket' => ['type' => $ticketType, 'reason' => $ticketType === 'keep_pending' ? $ticketReason : ''],
                'asset'  => ['type' => $assetType, 'state' => $assetType === 'set_state' ? $assetState : ''],
            ];
        }
        return $actions;
    }

    /** @param array<string, string> $s @return list<int> */
    public static function categoryIds(array $s): array
    {
        $decoded = json_decode($s['pre_category_ids'] ?? '[]', true);
        return is_array($decoded) ? array_values(array_map('intval', $decoded)) : [];
    }

    /** @param array<string, string> $s */
    public static function includeSubcategories(array $s): bool
    {
        return ($s['pre_include_subcategories'] ?? '1') === '1';
    }

    /** @param array<string, string> $s */
    public static function logoCategoryId(array $s): int
    {
        return (int) ($s['pre_logo_documentcategories_id'] ?? 0);
    }

    /** @param array<string, string> $s */
    public static function stateId(array $s, string $role): int
    {
        return (int) ($s['pre_state_' . $role] ?? 0);
    }

    /** @param array<string, string> $s */
    public static function reasonId(array $s, string $role): int
    {
        return (int) ($s['pre_reason_' . $role] ?? 0);
    }

    /**
     * @param array<string, string> $s
     * @return array<string, array{ticket: array{type: string, reason: string}, asset: array{type: string, state: string}}>
     */
    public static function actions(array $s): array
    {
        return self::normalizeActions($s['pre_actions'] ?? null);
    }

    /**
     * Roles the "Enviar" action always needs (spec D5): the at-supplier state and pending reason.
     *
     * @param array<string, string> $s
     * @return list<string>
     */
    public static function missingRolesForSend(array $s): array
    {
        $missing = [];
        if (self::stateId($s, 'at_supplier') === 0) {
            $missing[] = 'state:at_supplier';
        }
        if (self::reasonId($s, 'at_supplier') === 0) {
            $missing[] = 'reason:at_supplier';
        }
        return $missing;
    }

    /**
     * Roles a given return action needs, according to its configured ticket/asset actions.
     *
     * @param array<string, string> $s
     * @return list<string>
     */
    public static function missingRolesForReturn(array $s, string $actionKey): array
    {
        $action = self::actions($s)[$actionKey] ?? null;
        if ($action === null) {
            return [];
        }
        $missing = [];
        if ($action['ticket']['type'] === 'keep_pending' && self::reasonId($s, $action['ticket']['reason']) === 0) {
            $missing[] = 'reason:' . $action['ticket']['reason'];
        }
        if ($action['asset']['type'] === 'set_state' && self::stateId($s, $action['asset']['state']) === 0) {
            $missing[] = 'state:' . $action['asset']['state'];
        }
        return $missing;
    }
}
