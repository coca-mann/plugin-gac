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

use GlpiPlugin\Gac\Sso\OrgUnitList;
use PHPUnit\Framework\TestCase;

final class OrgUnitListTest extends TestCase
{
    public function testPathsFromApiAreSortedWithChildrenRightAfterTheirParent(): void
    {
        $paths = OrgUnitList::pathsFromApi(['organizationUnits' => [
            ['orgUnitPath' => '/fimca.com.br/IES-PVH/DTI'],
            ['orgUnitPath' => '/fimca.com.br'],
            ['orgUnitPath' => '/fimca.com.br-extra'],
            ['orgUnitPath' => '/Sistemas'],
            ['orgUnitPath' => '/fimca.com.br/IES-PVH'],
        ]]);

        // A plain byte sort would put "/fimca.com.br-extra" between the parent and its children.
        $this->assertSame(
            ['/fimca.com.br', '/fimca.com.br/IES-PVH', '/fimca.com.br/IES-PVH/DTI', '/fimca.com.br-extra', '/Sistemas'],
            $paths
        );
    }

    public function testPathsFromApiKeepTheGooglesCaseSpacesAndBrackets(): void
    {
        $paths = OrgUnitList::pathsFromApi(['organizationUnits' => [
            ['orgUnitPath' => '/[desativados]'],
            ['orgUnitPath' => '/fimca.com.br/IES-PVH/Almoxarifado e Compras'],
        ]]);

        $this->assertContains('/[desativados]', $paths);
        $this->assertContains('/fimca.com.br/IES-PVH/Almoxarifado e Compras', $paths);
    }

    public function testPathsFromApiDropBadEntriesAndDuplicates(): void
    {
        $paths = OrgUnitList::pathsFromApi(['organizationUnits' => [
            ['orgUnitPath' => '/a'], ['orgUnitPath' => '/a'], ['orgUnitPath' => '/'], ['orgUnitPath' => ''],
            ['orgUnitPath' => 'sem-barra'], ['name' => 'sem caminho'], 'texto',
        ]]);

        $this->assertSame(['/a', '/sem-barra'], $paths);
    }

    public function testPathsFromApiHandlesAnEmptyOrWrongBody(): void
    {
        $this->assertSame([], OrgUnitList::pathsFromApi([]));
        $this->assertSame([], OrgUnitList::pathsFromApi(['organizationUnits' => 'x']));
        $this->assertSame([], OrgUnitList::pathsFromApi(['organizationUnits' => []]));
    }

    public function testLabelPutsTheWorkspaceNameBeforeThePath(): void
    {
        $this->assertSame('Principal - /fimca.com.br/IES-PVH', OrgUnitList::label('Principal', '/fimca.com.br/IES-PVH'));
    }

    public function testPayloadListsEachWorkspaceAndMarksPathsRepeatedBetweenThem(): void
    {
        $payload = OrgUnitList::payload([
            ['key' => 'principal', 'name' => 'Principal', 'paths' => ['/Sistemas', '/fimca.com.br'], 'error' => ''],
            ['key' => 'metropolitana', 'name' => 'Metropolitana', 'paths' => ['/sistemas', '/pvh'], 'error' => ''],
        ]);

        $this->assertSame('principal', $payload['workspaces'][0]['key']);
        $this->assertSame(
            [
                ['path' => '/Sistemas', 'label' => 'Principal - /Sistemas', 'repeated' => true],
                ['path' => '/fimca.com.br', 'label' => 'Principal - /fimca.com.br', 'repeated' => false],
            ],
            $payload['workspaces'][0]['ous']
        );
        $this->assertTrue($payload['workspaces'][1]['ous'][0]['repeated'], 'comparison ignores case, like the rule engine');
        $this->assertFalse($payload['workspaces'][1]['ous'][1]['repeated']);
    }

    public function testPayloadKeepsAFailedWorkspaceWithItsErrorAndNoOus(): void
    {
        $payload = OrgUnitList::payload([
            ['key' => 'principal', 'name' => 'Principal', 'paths' => ['/a'], 'error' => ''],
            ['key' => 'metropolitana', 'name' => 'Metropolitana', 'paths' => null, 'error' => 'Directory API returned HTTP 401'],
        ]);

        $this->assertSame([], $payload['workspaces'][1]['ous']);
        $this->assertSame('Directory API returned HTTP 401', $payload['workspaces'][1]['error']);
        $this->assertSame('', $payload['workspaces'][0]['error']);
    }

    public function testCacheEnvelopeRoundTrips(): void
    {
        $packed = OrgUnitList::pack(['/a', '/b'], 1000);

        $this->assertSame(['at' => 1000, 'paths' => ['/a', '/b']], OrgUnitList::unpack($packed));
    }

    public function testUnpackRejectsAnythingThatIsNotAValidEnvelope(): void
    {
        $this->assertNull(OrgUnitList::unpack(null));
        $this->assertNull(OrgUnitList::unpack('texto'));
        $this->assertNull(OrgUnitList::unpack(['v' => 2, 'at' => 1, 'paths' => ['/a']]));
        $this->assertNull(OrgUnitList::unpack(['v' => 1, 'at' => 1]));
        $this->assertNull(OrgUnitList::unpack(['v' => 1, 'at' => 1, 'paths' => ['sem-barra']]));
        $this->assertNull(OrgUnitList::unpack(['v' => 1, 'at' => 1, 'paths' => [5]]));
    }
}
