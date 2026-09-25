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

use GlpiPlugin\Gac\Pre\Destination;
use GlpiPlugin\Gac\Pre\ItemStatus;
use GlpiPlugin\Gac\Pre\Outcome;
use GlpiPlugin\Gac\Pre\ProtocolStatus;
use GlpiPlugin\Gac\Pre\StateMachine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StateMachineTest extends TestCase
{
    public function testDraftAndCanceledNeverChangeByDerivation(): void
    {
        $lines = [ItemStatus::Returned];
        $this->assertSame(ProtocolStatus::Draft, StateMachine::deriveProtocolStatus(ProtocolStatus::Draft, $lines));
        $this->assertSame(ProtocolStatus::Canceled, StateMachine::deriveProtocolStatus(ProtocolStatus::Canceled, $lines));
    }

    #[DataProvider('derivationCases')]
    public function testDeriveProtocolStatus(array $lines, ProtocolStatus $expected): void
    {
        $this->assertSame($expected, StateMachine::deriveProtocolStatus(ProtocolStatus::Sent, $lines));
    }

    public static function derivationCases(): array
    {
        return [
            'nothing returned yet'      => [[ItemStatus::AtSupplier, ItemStatus::PendingSend], ProtocolStatus::Sent],
            'one returned, one open'    => [[ItemStatus::Returned, ItemStatus::AtSupplier], ProtocolStatus::Partial],
            'one lost, one open'        => [[ItemStatus::Lost, ItemStatus::AtSupplier], ProtocolStatus::Partial],
            'all final'                 => [[ItemStatus::Returned, ItemStatus::Lost], ProtocolStatus::Closed],
            'a line still sending'      => [[ItemStatus::Returned, ItemStatus::Sending], ProtocolStatus::Partial],
            'no lines keeps current'    => [[], ProtocolStatus::Sent],
        ];
    }

    public function testItemStatusFlags(): void
    {
        $this->assertTrue(ItemStatus::PendingSend->isActive());
        $this->assertTrue(ItemStatus::Sending->isActive());
        $this->assertTrue(ItemStatus::AtSupplier->isActive());
        $this->assertFalse(ItemStatus::Returned->isActive());
        $this->assertFalse(ItemStatus::Lost->isActive());
        $this->assertTrue(ItemStatus::Returned->isFinal());
        $this->assertTrue(ItemStatus::Lost->isFinal());
        $this->assertFalse(ItemStatus::AtSupplier->isFinal());
    }

    public function testOutcomeDefectiveFlag(): void
    {
        $this->assertFalse(Outcome::Repaired->isDefective());
        $this->assertFalse(Outcome::NoFault->isDefective());
        $this->assertTrue(Outcome::Unrepairable->isDefective());
        $this->assertTrue(Outcome::QuoteRejected->isDefective());
    }

    public function testImportAndSendRules(): void
    {
        $this->assertTrue(StateMachine::canImportLines(ProtocolStatus::Draft));
        $this->assertFalse(StateMachine::canImportLines(ProtocolStatus::Sent));
        $this->assertTrue(StateMachine::canStartSend(ProtocolStatus::Draft));
        $this->assertFalse(StateMachine::canStartSend(ProtocolStatus::Sent));
        $this->assertTrue(StateMachine::canSendLine(ProtocolStatus::Sent, ItemStatus::PendingSend));
        $this->assertFalse(StateMachine::canSendLine(ProtocolStatus::Draft, ItemStatus::PendingSend));
        $this->assertFalse(StateMachine::canSendLine(ProtocolStatus::Sent, ItemStatus::AtSupplier));
    }

    public function testRemoveRules(): void
    {
        $this->assertTrue(StateMachine::canRemoveLine(ProtocolStatus::Draft, ItemStatus::PendingSend));
        $this->assertTrue(StateMachine::canRemoveLine(ProtocolStatus::Sent, ItemStatus::PendingSend));
        $this->assertFalse(StateMachine::canRemoveLine(ProtocolStatus::Sent, ItemStatus::AtSupplier));
        $this->assertFalse(StateMachine::canRemoveLine(ProtocolStatus::Partial, ItemStatus::PendingSend));
        $this->assertFalse(StateMachine::removeRequiresReason(ProtocolStatus::Draft));
        $this->assertTrue(StateMachine::removeRequiresReason(ProtocolStatus::Sent));
    }

    public function testReturnReopenCancelRules(): void
    {
        $this->assertTrue(StateMachine::canRegisterReturn(ProtocolStatus::Sent, ItemStatus::AtSupplier));
        $this->assertTrue(StateMachine::canRegisterReturn(ProtocolStatus::Partial, ItemStatus::AtSupplier));
        $this->assertFalse(StateMachine::canRegisterReturn(ProtocolStatus::Closed, ItemStatus::AtSupplier));
        $this->assertFalse(StateMachine::canRegisterReturn(ProtocolStatus::Sent, ItemStatus::PendingSend));
        $this->assertTrue(StateMachine::canReopen(ProtocolStatus::Closed));
        $this->assertFalse(StateMachine::canReopen(ProtocolStatus::Partial));
        $this->assertTrue(StateMachine::canCancel(ProtocolStatus::Draft));
        $this->assertFalse(StateMachine::canCancel(ProtocolStatus::Sent));
    }

    #[DataProvider('destinationCases')]
    public function testNormalizeDestination(Outcome $o, ?Destination $d, ?Destination $expected): void
    {
        if ($expected === null) {
            $this->expectException(\InvalidArgumentException::class);
            StateMachine::normalizeDestination($o, $d);
            return;
        }
        $this->assertSame($expected, StateMachine::normalizeDestination($o, $d));
    }

    public static function destinationCases(): array
    {
        return [
            'repaired, none given'          => [Outcome::Repaired, null, Destination::None],
            'repaired, none explicit'       => [Outcome::Repaired, Destination::None, Destination::None],
            'repaired, writeoff is invalid' => [Outcome::Repaired, Destination::Writeoff, null],
            'no fault, none'                => [Outcome::NoFault, Destination::None, Destination::None],
            'unrepairable, writeoff'        => [Outcome::Unrepairable, Destination::Writeoff, Destination::Writeoff],
            'unrepairable, keep'            => [Outcome::Unrepairable, Destination::KeepDefective, Destination::KeepDefective],
            'unrepairable, missing'         => [Outcome::Unrepairable, null, null],
            'unrepairable, none is invalid' => [Outcome::Unrepairable, Destination::None, null],
            'quote rejected, keep'          => [Outcome::QuoteRejected, Destination::KeepDefective, Destination::KeepDefective],
            'quote rejected, missing'       => [Outcome::QuoteRejected, null, null],
        ];
    }
}
