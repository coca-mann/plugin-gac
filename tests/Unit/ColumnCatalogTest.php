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

use GlpiPlugin\Gac\Monitor\ColumnCatalog;
use PHPUnit\Framework\TestCase;

final class ColumnCatalogTest extends TestCase
{
    public function testAllKeysMatchesTheCuratedCount(): void
    {
        $this->assertCount(11, ColumnCatalog::allKeys());
    }

    public function testDefaultColumnsAreAllValid(): void
    {
        foreach (ColumnCatalog::DEFAULT_COLUMNS as $key) {
            $this->assertTrue(ColumnCatalog::isValidKey($key));
        }
    }

    public function testElapsedIsComputed(): void
    {
        $this->assertTrue(ColumnCatalog::isComputed('elapsed'));
    }

    public function testRegularColumnsAreNotComputed(): void
    {
        $this->assertFalse(ColumnCatalog::isComputed('status'));
    }

    public function testSearchOptionIdsMatchTheRealGlpiTicketSearchOptions(): void
    {
        $this->assertSame(2, ColumnCatalog::searchOptionId('id'));
        $this->assertSame(1, ColumnCatalog::searchOptionId('title'));
        $this->assertSame(80, ColumnCatalog::searchOptionId('entity'));
        $this->assertSame(12, ColumnCatalog::searchOptionId('status'));
        $this->assertSame(3, ColumnCatalog::searchOptionId('priority'));
        $this->assertSame(7, ColumnCatalog::searchOptionId('category'));
        $this->assertSame(4, ColumnCatalog::searchOptionId('requester'));
        $this->assertSame(5, ColumnCatalog::searchOptionId('technician'));
        $this->assertSame(8, ColumnCatalog::searchOptionId('group'));
        $this->assertSame(15, ColumnCatalog::searchOptionId('opening_date'));
        $this->assertNull(ColumnCatalog::searchOptionId('bogus'));
    }

    public function testSanitizeKeepsOnlyValidKeysPreservesOrderAndDedupes(): void
    {
        $this->assertSame(
            ['status', 'id'],
            ColumnCatalog::sanitize(['status', 'bogus', 'id', 'status'])
        );
    }

    public function testSanitizeFallsBackToDefaultsWhenNothingValid(): void
    {
        $this->assertSame(ColumnCatalog::DEFAULT_COLUMNS, ColumnCatalog::sanitize(['bogus', 123, null]));
    }

    public function testSearchOptionIdsForAlwaysIncludesId(): void
    {
        $this->assertSame([2, 12], ColumnCatalog::searchOptionIdsFor(['status']));
    }

    public function testSearchOptionIdsForIncludesTheDateBehindElapsedEvenWithoutOpeningDateColumn(): void
    {
        $ids = ColumnCatalog::searchOptionIdsFor(['elapsed']);
        $this->assertContains(15, $ids);
        $this->assertContains(2, $ids);
    }

    public function testSearchOptionIdsForDedupes(): void
    {
        $this->assertSame([2, 15], ColumnCatalog::searchOptionIdsFor(['opening_date', 'elapsed']));
    }
}
