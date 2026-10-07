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

use GlpiPlugin\Gac\Monitor\TicketSortOrder;
use PHPUnit\Framework\TestCase;

final class TicketSortOrderTest extends TestCase
{
    public function testIsValidMode(): void
    {
        $this->assertTrue(TicketSortOrder::isValidMode('priority'));
        $this->assertTrue(TicketSortOrder::isValidMode('id'));
        $this->assertTrue(TicketSortOrder::isValidMode('elapsed'));
        $this->assertFalse(TicketSortOrder::isValidMode('bogus'));
    }

    public function testStatusPriorityMatchesTheDjangoOrder(): void
    {
        $this->assertSame(1, TicketSortOrder::statusPriority(1));  // Novo
        $this->assertSame(2, TicketSortOrder::statusPriority(2));  // Em atendimento
        $this->assertSame(3, TicketSortOrder::statusPriority(3));  // Em atendimento (planejado)
        $this->assertSame(4, TicketSortOrder::statusPriority(4));  // Pendente
        $this->assertSame(5, TicketSortOrder::statusPriority(10)); // Aprovação
        $this->assertSame(6, TicketSortOrder::statusPriority(5));  // Solucionado
        $this->assertSame(999, TicketSortOrder::statusPriority(6)); // unlisted (e.g. Fechado)
    }

    public function testHigherPrioritySortsFirst(): void
    {
        $low  = ['priority' => 1, 'status' => 1, 'date' => '2026-10-01 10:00:00'];
        $high = ['priority' => 6, 'status' => 1, 'date' => '2026-10-01 10:00:00'];
        $this->assertSame(1, TicketSortOrder::compare($low, $high));
        $this->assertSame(-1, TicketSortOrder::compare($high, $low));
    }

    public function testSamePriorityOrdersByStatusPriority(): void
    {
        $novo          = ['priority' => 3, 'status' => 1, 'date' => '2026-10-01 10:00:00'];
        $emAtendimento = ['priority' => 3, 'status' => 2, 'date' => '2026-10-01 10:00:00'];
        $this->assertLessThan(0, TicketSortOrder::compare($novo, $emAtendimento));
        $this->assertGreaterThan(0, TicketSortOrder::compare($emAtendimento, $novo));
    }

    public function testSamePriorityAndStatusOrdersByNewestDateFirst(): void
    {
        $older = ['priority' => 3, 'status' => 2, 'date' => '2026-09-01 10:00:00'];
        $newer = ['priority' => 3, 'status' => 2, 'date' => '2026-10-01 10:00:00'];
        $this->assertGreaterThan(0, TicketSortOrder::compare($older, $newer));
        $this->assertLessThan(0, TicketSortOrder::compare($newer, $older));
    }

    public function testFullSortPutsTheHighestPriorityOnTop(): void
    {
        $tickets = [
            'A' => ['priority' => 3, 'status' => 5, 'date' => '2026-10-01 10:00:00'], // solved, medium
            'B' => ['priority' => 6, 'status' => 2, 'date' => '2026-09-01 10:00:00'], // critical, in progress
            'C' => ['priority' => 6, 'status' => 1, 'date' => '2026-10-01 10:00:00'], // critical, new
            'D' => ['priority' => 1, 'status' => 1, 'date' => '2026-10-01 10:00:00'], // very low, new
            'E' => ['priority' => 4, 'status' => 2, 'date' => '2026-10-01 10:00:00'], // high
        ];

        $order = array_keys($tickets);
        usort($order, static fn(string $a, string $b): int => TicketSortOrder::compare($tickets[$a], $tickets[$b]));

        $this->assertSame(['C', 'B', 'E', 'A', 'D'], $order);
    }

    public function testElapsedModeOrdersOldestFirst(): void
    {
        $older = ['priority' => 1, 'status' => 2, 'date' => '2026-09-01 10:00:00'];
        $newer = ['priority' => 6, 'status' => 1, 'date' => '2026-10-01 10:00:00'];
        $this->assertLessThan(0, TicketSortOrder::compareElapsed($older, $newer));
        $this->assertGreaterThan(0, TicketSortOrder::compareElapsed($newer, $older));
    }

    public function testElapsedModeBreaksTiesByPriority(): void
    {
        $low  = ['priority' => 2, 'status' => 2, 'date' => '2026-09-01 10:00:00'];
        $high = ['priority' => 5, 'status' => 2, 'date' => '2026-09-01 10:00:00'];
        $this->assertGreaterThan(0, TicketSortOrder::compareElapsed($low, $high));
    }

    public function testResolveUsesThePageModeWhenValid(): void
    {
        $this->assertSame('elapsed', TicketSortOrder::resolve('elapsed', 'priority'));
        $this->assertSame('id', TicketSortOrder::resolve('id', 'elapsed'));
    }

    public function testResolveFallsBackToTheScreenWhenThePageHasNone(): void
    {
        $this->assertSame('elapsed', TicketSortOrder::resolve('', 'elapsed'));
        $this->assertSame('id', TicketSortOrder::resolve('bogus', 'id'));
        $this->assertSame('id', TicketSortOrder::resolve(null, 'id'));
    }

    public function testResolveFallsBackToTheDefaultWhenBothAreInvalid(): void
    {
        $this->assertSame(TicketSortOrder::DEFAULT_MODE, TicketSortOrder::resolve('', ''));
        $this->assertSame(TicketSortOrder::DEFAULT_MODE, TicketSortOrder::resolve(null, 'bogus'));
    }
}
