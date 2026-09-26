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

use GlpiPlugin\Gac\Ltbp\LockPolicy;
use PHPUnit\Framework\TestCase;

final class LockPolicyTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function fields(): array
    {
        return [
            'id' => '5', 'name' => 'NB-01', 'serial' => 'ABC123', 'states_id' => '7',
            'comment' => 'old note', 'ticket_tco' => '10.0000', 'buy_date' => null, 'date_mod' => '2026-01-01 10:00:00',
        ];
    }

    public function testUnchangedInputBlocksNothing(): void
    {
        $input = ['id' => '5', 'name' => 'NB-01', 'serial' => 'ABC123', 'states_id' => '7', 'ticket_tco' => '10', 'buy_date' => ''];
        $this->assertSame([], LockPolicy::blockedFields($input, self::fields()));
    }

    public function testChangedColumnsAreBlocked(): void
    {
        $input = ['id' => '5', 'name' => 'NB-02', 'states_id' => '9', 'serial' => 'ABC123'];
        $this->assertSame(['name', 'states_id'], LockPolicy::blockedFields($input, self::fields()));
    }

    public function testCommentAndBookkeepingFieldsAreAllowed(): void
    {
        $input = ['id' => '5', 'comment' => 'new note', 'date_mod' => '2026-09-26 10:00:00', 'date_creation' => 'x'];
        $this->assertSame([], LockPolicy::blockedFields($input, self::fields()));
    }

    public function testUnderscoreKeysAndUnknownKeysAreIgnored(): void
    {
        $input = ['_no_history' => true, '_glpi_csrf_token' => 'abc', 'update' => '1', 'not_a_column' => 'x'];
        $this->assertSame([], LockPolicy::blockedFields($input, self::fields()));
    }

    public function testNumericFormattingAndEmptyValuesDoNotCountAsChanges(): void
    {
        $this->assertSame([], LockPolicy::blockedFields(['ticket_tco' => '10.00'], self::fields()));
        $this->assertSame([], LockPolicy::blockedFields(['buy_date' => ''], self::fields()), 'NULL and empty string are the same');
        $this->assertSame(['ticket_tco'], LockPolicy::blockedFields(['ticket_tco' => '11'], self::fields()));
    }

    public function testLeadingZeroSerialChangeIsBlocked(): void
    {
        $fields = ['serial' => '000123'];
        $this->assertSame(['serial'], LockPolicy::blockedFields(['serial' => '123'], $fields));
        $this->assertSame(['serial'], LockPolicy::blockedFields(['serial' => '000123'], ['serial' => '123']));
    }

    public function testLongNumericSerialChangeIsBlocked(): void
    {
        $fields = ['serial' => '89550123456789012345'];
        $this->assertSame(['serial'], LockPolicy::blockedFields(['serial' => '89550123456789012346'], $fields));
    }

    public function testExponentNotationChangeIsBlocked(): void
    {
        $this->assertSame(['serial'], LockPolicy::blockedFields(['serial' => '1e3'], ['serial' => '1000']));
        $this->assertSame(['serial'], LockPolicy::blockedFields(['serial' => '1000'], ['serial' => '1e3']));
    }

    public function testDecimalFormattingStillEqual(): void
    {
        $this->assertSame([], LockPolicy::blockedFields(['ticket_tco' => '10.0'], ['ticket_tco' => '10.0000']));
        $this->assertSame([], LockPolicy::blockedFields(['ticket_tco' => '10'], ['ticket_tco' => '10.0000']));
        $this->assertSame(['ticket_tco'], LockPolicy::blockedFields(['ticket_tco' => '10.5'], ['ticket_tco' => '10.0000']));
    }

    public function testArrayInputsAreIgnored(): void
    {
        $this->assertSame([], LockPolicy::blockedFields(['name' => ['a', 'b']], self::fields()));
    }
}
