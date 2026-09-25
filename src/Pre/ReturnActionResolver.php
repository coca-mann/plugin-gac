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
 * Turns (outcome, destination) into the concrete ticket and asset actions configured in the
 * PRE settings (spec 6.4, D8). Pure.
 */
final class ReturnActionResolver
{
    public static function actionKey(Outcome $outcome, Destination $destination): string
    {
        return match (true) {
            $outcome === Outcome::Repaired => 'repaired',
            $outcome === Outcome::NoFault => 'no_fault',
            $destination === Destination::Writeoff => 'writeoff',
            default => 'keep_defective',
        };
    }

    /**
     * @param array<string, string> $settings normalized PreSettings array
     * @return array{ticket: array{type: string, pendingreasons_id: int}, asset: array{type: string, states_id: int}}
     * @throws \InvalidArgumentException unknown action key
     * @throws \DomainException          a role needed by the configured action has no mapping
     */
    public static function resolve(string $actionKey, array $settings): array
    {
        if (!in_array($actionKey, PreSettings::ACTION_KEYS, true)) {
            throw new \InvalidArgumentException("Unknown return action key: $actionKey");
        }
        $missing = PreSettings::missingRolesForReturn($settings, $actionKey);
        if ($missing !== []) {
            throw new \DomainException('Missing configuration: ' . implode(', ', $missing));
        }

        $action = PreSettings::actions($settings)[$actionKey];
        $reasonId = $action['ticket']['type'] === 'keep_pending'
            ? PreSettings::reasonId($settings, $action['ticket']['reason'])
            : 0;
        $stateId = $action['asset']['type'] === 'set_state'
            ? PreSettings::stateId($settings, $action['asset']['state'])
            : 0;

        return [
            'ticket' => ['type' => $action['ticket']['type'], 'pendingreasons_id' => $reasonId],
            'asset'  => ['type' => $action['asset']['type'], 'states_id' => $stateId],
        ];
    }
}
