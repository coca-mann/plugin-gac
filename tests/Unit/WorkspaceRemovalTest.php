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

use GlpiPlugin\Gac\Sso\WorkspaceRegistry;
use GlpiPlugin\Gac\Sso\WorkspaceRemoval;
use PHPUnit\Framework\TestCase;

final class WorkspaceRemovalTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private static function rows(): array
    {
        return [
            ['key' => 'principal', 'name' => 'Principal', 'domains' => 'a.com', 'admin_subject' => 'x@a.com', 'is_active' => true],
            ['key' => 'metropolitana', 'name' => 'Metropolitana', 'domains' => 'b.com', 'admin_subject' => 'x@b.com', 'is_active' => true],
        ];
    }

    public function testARemovedWorkspaceIsReportedByItsKey(): void
    {
        $stored    = WorkspaceRegistry::fromRows(self::rows());
        $submitted = WorkspaceRegistry::fromRows([self::rows()[0]], ['principal', 'metropolitana']);

        $this->assertSame(['metropolitana'], WorkspaceRemoval::removedKeys($stored, $submitted));
    }

    public function testRenamingIsNotARemoval(): void
    {
        $rows            = self::rows();
        $rows[0]['name'] = 'Outro nome';
        $stored          = WorkspaceRegistry::fromRows(self::rows());
        $submitted       = WorkspaceRegistry::fromRows($rows, ['principal', 'metropolitana']);

        $this->assertSame([], WorkspaceRemoval::removedKeys($stored, $submitted));
    }

    public function testDeactivatingIsNotARemoval(): void
    {
        $rows                 = self::rows();
        $rows[1]['is_active'] = false;
        $stored               = WorkspaceRegistry::fromRows(self::rows());
        $submitted            = WorkspaceRegistry::fromRows($rows, ['principal', 'metropolitana']);

        $this->assertSame([], WorkspaceRemoval::removedKeys($stored, $submitted));
    }

    public function testAddingAWorkspaceIsNotARemoval(): void
    {
        $rows      = self::rows();
        $rows[]    = ['name' => 'Nova', 'domains' => 'c.com', 'admin_subject' => 'x@c.com'];
        $stored    = WorkspaceRegistry::fromRows(self::rows());
        $submitted = WorkspaceRegistry::fromRows($rows, ['principal', 'metropolitana']);

        $this->assertSame([], WorkspaceRemoval::removedKeys($stored, $submitted));
    }

    public function testEmptyListsAndRemovingEverything(): void
    {
        $stored = WorkspaceRegistry::fromRows(self::rows());
        $empty  = WorkspaceRegistry::fromRows([]);

        $this->assertSame([], WorkspaceRemoval::removedKeys($empty, $empty));
        $this->assertSame([], WorkspaceRemoval::removedKeys($empty, $stored));
        $this->assertSame(['principal', 'metropolitana'], WorkspaceRemoval::removedKeys($stored, $empty));
    }
}
