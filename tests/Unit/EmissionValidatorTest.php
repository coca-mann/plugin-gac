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

use GlpiPlugin\Gac\Ltbp\EmissionValidator;
use PHPUnit\Framework\TestCase;

final class EmissionValidatorTest extends TestCase
{
    /** @return array{destination: string, line_count: int, lines_without_reason: int, missing_state_roles: list<string>, directors_missing: bool, conflicts: int} */
    private static function valid(): array
    {
        return [
            'destination'          => 'disposal',
            'line_count'           => 3,
            'lines_without_reason' => 0,
            'missing_state_roles'  => [],
            'directors_missing'    => false,
            'conflicts'            => 0,
        ];
    }

    public function testAValidLaudoHasNoErrors(): void
    {
        $this->assertSame([], EmissionValidator::validate(self::valid()));
        $this->assertSame([], EmissionValidator::validate(['destination' => 'donation'] + self::valid()));
    }

    public function testEachRuleReportsItsOwnCode(): void
    {
        $this->assertSame(['destination'], EmissionValidator::validate(['destination' => ''] + self::valid()));
        $this->assertSame(['destination'], EmissionValidator::validate(['destination' => 'sale'] + self::valid()));
        $this->assertSame(['no_lines'], EmissionValidator::validate(['line_count' => 0] + self::valid()));
        $this->assertSame(['reason'], EmissionValidator::validate(['lines_without_reason' => 2] + self::valid()));
        $this->assertSame(['states'], EmissionValidator::validate(['missing_state_roles' => ['in_process']] + self::valid()));
        $this->assertSame(['directors'], EmissionValidator::validate(['directors_missing' => true] + self::valid()));
        $this->assertSame(['conflicts'], EmissionValidator::validate(['conflicts' => 1] + self::valid()));
    }

    public function testEveryProblemIsReportedInAFixedOrder(): void
    {
        $facts = [
            'destination'          => '',
            'line_count'           => 0,
            'lines_without_reason' => 1,
            'missing_state_roles'  => ['written_off'],
            'directors_missing'    => true,
            'conflicts'            => 2,
        ];
        $this->assertSame(
            ['destination', 'no_lines', 'reason', 'states', 'directors', 'conflicts'],
            EmissionValidator::validate($facts)
        );
    }
}
