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

namespace GlpiPlugin\Gac\Tests\Unit;

use GlpiPlugin\Gac\Pre\PreSettings;
use PHPUnit\Framework\TestCase;

final class PreSettingsTest extends TestCase
{
    public function testDefaultsMatchTheSpecTable(): void
    {
        $s = PreSettings::normalize([]);
        $actions = PreSettings::actions($s);

        $this->assertSame(['ticket' => ['type' => 'reopen', 'reason' => ''], 'asset' => ['type' => 'restore_previous', 'state' => '']], $actions['repaired']);
        $this->assertSame(['ticket' => ['type' => 'reopen', 'reason' => ''], 'asset' => ['type' => 'restore_previous', 'state' => '']], $actions['no_fault']);
        $this->assertSame(['ticket' => ['type' => 'keep_pending', 'reason' => 'awaiting_writeoff'], 'asset' => ['type' => 'set_state', 'state' => 'awaiting_writeoff']], $actions['writeoff']);
        $this->assertSame(['ticket' => ['type' => 'keep_pending', 'reason' => 'awaiting_decision'], 'asset' => ['type' => 'set_state', 'state' => 'defective']], $actions['keep_defective']);
        $this->assertTrue(PreSettings::includeSubcategories($s));
    }

    public function testNormalizeCoercesTypesAndDropsUnknownKeys(): void
    {
        $s = PreSettings::normalize([
            'pre_category_ids'        => '[3,"7",0,-2,"x"]',
            'pre_include_subcategories' => '0',
            'pre_state_at_supplier'   => '12',
            'pre_reason_at_supplier'  => 'abc',
            'garbage'                 => 'x',
        ]);
        $this->assertSame([3, 7], PreSettings::categoryIds($s));
        $this->assertFalse(PreSettings::includeSubcategories($s));
        $this->assertSame(12, PreSettings::stateId($s, 'at_supplier'));
        $this->assertSame(0, PreSettings::reasonId($s, 'at_supplier'));
        $this->assertArrayNotHasKey('garbage', $s);
    }

    public function testNormalizeRepairsBrokenActionsJson(): void
    {
        $s = PreSettings::normalize(['pre_actions' => '{not json']);
        $this->assertSame(PreSettings::actions(PreSettings::normalize([])), PreSettings::actions($s));
    }

    public function testNormalizeRejectsInvalidActionTypes(): void
    {
        $s = PreSettings::normalize(['pre_actions' => json_encode([
            'repaired' => ['ticket' => ['type' => 'explode', 'reason' => ''], 'asset' => ['type' => 'restore_previous', 'state' => '']],
        ])]);
        $this->assertSame('reopen', PreSettings::actions($s)['repaired']['ticket']['type']);
    }

    public function testMissingRolesForSend(): void
    {
        $s = PreSettings::normalize([]);
        $this->assertSame(['state:at_supplier', 'reason:at_supplier'], PreSettings::missingRolesForSend($s));

        $s = PreSettings::normalize(['pre_state_at_supplier' => '5', 'pre_reason_at_supplier' => '9']);
        $this->assertSame([], PreSettings::missingRolesForSend($s));
    }

    public function testMissingRolesForReturnFollowsTheConfiguredAction(): void
    {
        $s = PreSettings::normalize([]);
        $this->assertSame([], PreSettings::missingRolesForReturn($s, 'repaired'));
        $this->assertSame(['reason:awaiting_writeoff', 'state:awaiting_writeoff'], PreSettings::missingRolesForReturn($s, 'writeoff'));

        $s = PreSettings::normalize(['pre_reason_awaiting_writeoff' => '4', 'pre_state_awaiting_writeoff' => '6']);
        $this->assertSame([], PreSettings::missingRolesForReturn($s, 'writeoff'));
    }
}
