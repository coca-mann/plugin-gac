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

namespace GlpiPlugin\Gac\Ltbp;

/**
 * Typed view over the raw LTBP configuration array stored in glpi_configs (context plugin:gac,
 * keys prefixed ltbp_). Pure: no GLPI calls. See spec section 5.5.
 */
final class LtbpSettings
{
    public const STATE_ROLES = ['awaiting_writeoff', 'in_process', 'written_off'];

    private const DIRECTOR_KEYS = [
        'ltbp_director_ti_name', 'ltbp_director_ti_role', 'ltbp_director_adm_name', 'ltbp_director_adm_role',
    ];
    private const DEFAULT_REASON_OUTCOMES = ['unrepairable', 'quote_rejected'];
    private const TEXT_MAX = 255;

    /** @return array<string, string> */
    public static function defaults(): array
    {
        $raw = [
            'ltbp_completion_require_document' => '1',
            'ltbp_solve_ticket_on_completion'  => '0',
            'ltbp_logo_documentcategories_id'  => '0',
        ];
        foreach (self::STATE_ROLES as $role) {
            $raw['ltbp_state_' . $role] = '0';
        }
        foreach (self::DIRECTOR_KEYS as $key) {
            $raw[$key] = '';
        }
        foreach (self::DEFAULT_REASON_OUTCOMES as $outcome) {
            $raw['ltbp_default_reason_' . $outcome] = '0';
        }
        return $raw;
    }

    /**
     * Completes missing keys with defaults, coerces types and drops unknown keys.
     *
     * @param array<string, mixed> $raw
     * @return array<string, string>
     */
    public static function normalize(array $raw): array
    {
        $out = self::defaults();

        $intKeys = ['ltbp_logo_documentcategories_id'];
        foreach (self::STATE_ROLES as $role) {
            $intKeys[] = 'ltbp_state_' . $role;
        }
        foreach (self::DEFAULT_REASON_OUTCOMES as $outcome) {
            $intKeys[] = 'ltbp_default_reason_' . $outcome;
        }
        foreach ($intKeys as $key) {
            if (array_key_exists($key, $raw)) {
                $out[$key] = (string) (is_numeric($raw[$key]) && (int) $raw[$key] > 0 ? (int) $raw[$key] : 0);
            }
        }

        foreach (['ltbp_completion_require_document', 'ltbp_solve_ticket_on_completion'] as $key) {
            if (array_key_exists($key, $raw)) {
                $out[$key] = ((string) $raw[$key]) === '1' ? '1' : '0';
            }
        }

        foreach (self::DIRECTOR_KEYS as $key) {
            if (array_key_exists($key, $raw)) {
                $out[$key] = mb_substr(trim((string) $raw[$key]), 0, self::TEXT_MAX);
            }
        }

        return $out;
    }

    /** @param array<string, string> $s */
    public static function stateId(array $s, string $role): int
    {
        return in_array($role, self::STATE_ROLES, true) ? (int) ($s['ltbp_state_' . $role] ?? 0) : 0;
    }

    /**
     * Roles without a mapped State: "Emitir" stays blocked while this is not empty (plan decision 17).
     *
     * @param array<string, string> $s
     * @return list<string>
     */
    public static function missingStateRoles(array $s): array
    {
        return array_values(array_filter(
            self::STATE_ROLES,
            static fn(string $role): bool => self::stateId($s, $role) <= 0
        ));
    }

    /**
     * @param array<string, string> $s
     * @return array{ti: array{name: string, role: string}, adm: array{name: string, role: string}}
     */
    public static function directors(array $s): array
    {
        return [
            'ti'  => ['name' => (string) ($s['ltbp_director_ti_name'] ?? ''), 'role' => (string) ($s['ltbp_director_ti_role'] ?? '')],
            'adm' => ['name' => (string) ($s['ltbp_director_adm_name'] ?? ''), 'role' => (string) ($s['ltbp_director_adm_role'] ?? '')],
        ];
    }

    /** @param array<string, string> $s */
    public static function directorsMissing(array $s): bool
    {
        foreach (self::DIRECTOR_KEYS as $key) {
            if (trim((string) ($s[$key] ?? '')) === '') {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, string> $s */
    public static function requireCompletionDocument(array $s): bool
    {
        return ($s['ltbp_completion_require_document'] ?? '1') === '1';
    }

    /** @param array<string, string> $s */
    public static function defaultReasonId(array $s, string $outcome): int
    {
        return in_array($outcome, self::DEFAULT_REASON_OUTCOMES, true)
            ? (int) ($s['ltbp_default_reason_' . $outcome] ?? 0)
            : 0;
    }

    /** @param array<string, string> $s */
    public static function solveTicketOnCompletion(array $s): bool
    {
        return ($s['ltbp_solve_ticket_on_completion'] ?? '0') === '1';
    }

    /** @param array<string, string> $s */
    public static function logoCategoryId(array $s): int
    {
        return (int) ($s['ltbp_logo_documentcategories_id'] ?? 0);
    }
}
