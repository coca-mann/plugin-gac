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

use GlpiPlugin\Gac\Ltbp\Destination;
use GlpiPlugin\Gac\Ltbp\Status;
use PHPUnit\Framework\TestCase;

final class LtbpStatusTest extends TestCase
{
    public function testStoredValuesAreStable(): void
    {
        $this->assertSame('draft', Status::Draft->value);
        $this->assertSame('awaiting_signatures', Status::AwaitingSignatures->value);
        $this->assertSame('signed', Status::Signed->value);
        $this->assertSame('at_patrimony', Status::AtPatrimony->value);
        $this->assertSame('written_off', Status::WrittenOff->value);
        $this->assertSame('completed', Status::Completed->value);
        $this->assertSame('canceled', Status::Canceled->value);
        $this->assertSame('disposal', Destination::Disposal->value);
        $this->assertSame('donation', Destination::Donation->value);
    }

    public function testEveryStatusExceptCanceledHoldsItsAssets(): void
    {
        foreach (Status::cases() as $status) {
            $this->assertSame($status !== Status::Canceled, $status->holdsAssets(), $status->value);
        }
        $this->assertSame(
            ['draft', 'awaiting_signatures', 'signed', 'at_patrimony', 'written_off', 'completed'],
            Status::holdingValues()
        );
    }

    public function testOnlyWrittenOffAndCompletedLockTheAssets(): void
    {
        $this->assertSame(['written_off', 'completed'], Status::lockingValues());
        $this->assertTrue(Status::WrittenOff->locksAssets());
        $this->assertTrue(Status::Completed->locksAssets());
        $this->assertFalse(Status::AtPatrimony->locksAssets());
        $this->assertFalse(Status::Canceled->locksAssets());
    }

    public function testFinalStatuses(): void
    {
        $this->assertTrue(Status::Completed->isFinal());
        $this->assertTrue(Status::Canceled->isFinal());
        $this->assertFalse(Status::WrittenOff->isFinal());
        $this->assertFalse(Status::Draft->isFinal());
    }
}
