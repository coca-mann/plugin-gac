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

namespace GlpiPlugin\Gac\Tests\Unit;

use GlpiPlugin\Gac\Ltbp\LtbpSettings;
use PHPUnit\Framework\TestCase;

final class LtbpSettingsTest extends TestCase
{
    public function testDefaultsMatchTheSpec(): void
    {
        $s = LtbpSettings::normalize([]);

        $this->assertTrue(LtbpSettings::requireCompletionDocument($s), 'L12: the proof is required by default');
        $this->assertFalse(LtbpSettings::solveTicketOnCompletion($s), 'L25: off by default');
        $this->assertSame(0, LtbpSettings::logoCategoryId($s));
        $this->assertSame(0, LtbpSettings::defaultReasonId($s, 'unrepairable'));
        $this->assertSame(0, LtbpSettings::defaultReasonId($s, 'quote_rejected'));
        $this->assertSame(['awaiting_writeoff', 'in_process', 'written_off'], LtbpSettings::missingStateRoles($s));
        $this->assertTrue(LtbpSettings::directorsMissing($s));
    }

    public function testEveryDefaultKeyIsPrefixed(): void
    {
        foreach (array_keys(LtbpSettings::defaults()) as $key) {
            $this->assertStringStartsWith('ltbp_', $key);
        }
    }

    public function testNormalizeCoercesTypesAndDropsUnknownKeys(): void
    {
        $s = LtbpSettings::normalize([
            'ltbp_state_in_process'             => '12',
            'ltbp_state_written_off'            => 'abc',
            'ltbp_default_reason_unrepairable'  => '5',
            'ltbp_default_reason_quote_rejected' => '-3',
            'ltbp_completion_require_document'  => '0',
            'ltbp_solve_ticket_on_completion'   => '1',
            'ltbp_logo_documentcategories_id'   => '7',
            'pre_state_at_supplier'             => '9',
            'garbage'                           => 'x',
        ]);

        $this->assertSame(12, LtbpSettings::stateId($s, 'in_process'));
        $this->assertSame(0, LtbpSettings::stateId($s, 'written_off'));
        $this->assertSame(5, LtbpSettings::defaultReasonId($s, 'unrepairable'));
        $this->assertSame(0, LtbpSettings::defaultReasonId($s, 'quote_rejected'));
        $this->assertFalse(LtbpSettings::requireCompletionDocument($s));
        $this->assertTrue(LtbpSettings::solveTicketOnCompletion($s));
        $this->assertSame(7, LtbpSettings::logoCategoryId($s));
        $this->assertArrayNotHasKey('garbage', $s);
        $this->assertArrayNotHasKey('pre_state_at_supplier', $s, 'the module never keeps another module\'s keys');
    }

    public function testMissingStateRolesShrinksAsRolesAreMapped(): void
    {
        $s = LtbpSettings::normalize(['ltbp_state_in_process' => '3']);
        $this->assertSame(['awaiting_writeoff', 'written_off'], LtbpSettings::missingStateRoles($s));

        $s = LtbpSettings::normalize([
            'ltbp_state_awaiting_writeoff' => '1',
            'ltbp_state_in_process'        => '2',
            'ltbp_state_written_off'       => '3',
        ]);
        $this->assertSame([], LtbpSettings::missingStateRoles($s));
    }

    public function testDirectorsAreTrimmedAndAllFourFieldsAreRequired(): void
    {
        $s = LtbpSettings::normalize([
            'ltbp_director_ti_name'   => '  Fulano de Tal ',
            'ltbp_director_ti_role'   => 'Diretor de TI',
            'ltbp_director_adm_name'  => 'Beltrana',
        ]);
        $this->assertTrue(LtbpSettings::directorsMissing($s), 'the administrative role is empty');

        $s = LtbpSettings::normalize([
            'ltbp_director_ti_name'   => '  Fulano de Tal ',
            'ltbp_director_ti_role'   => 'Diretor de TI',
            'ltbp_director_adm_name'  => 'Beltrana',
            'ltbp_director_adm_role'  => 'Diretora Administrativa',
        ]);
        $this->assertFalse(LtbpSettings::directorsMissing($s));
        $this->assertSame(
            ['ti' => ['name' => 'Fulano de Tal', 'role' => 'Diretor de TI'], 'adm' => ['name' => 'Beltrana', 'role' => 'Diretora Administrativa']],
            LtbpSettings::directors($s)
        );
    }

    public function testDefaultReasonIsZeroForAnUnknownOutcome(): void
    {
        $s = LtbpSettings::normalize(['ltbp_default_reason_unrepairable' => '5']);
        $this->assertSame(0, LtbpSettings::defaultReasonId($s, 'repaired'));
        $this->assertSame(0, LtbpSettings::defaultReasonId($s, ''));
    }
}
