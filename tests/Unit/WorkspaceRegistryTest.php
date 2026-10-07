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
use PHPUnit\Framework\TestCase;

final class WorkspaceRegistryTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private static function twoWorkspaces(): array
    {
        return [
            ['name' => 'Fimca', 'domains' => "fimca.com.br\n@GrupoAparicioCarvalho.com.br", 'admin_subject' => 'Leitor@fimca.com.br', 'is_active' => true],
            ['name' => 'Metropolitana', 'domains' => ['metropolitana-ro.com.br'], 'admin_subject' => 'leitor@metropolitana-ro.com.br', 'is_active' => true],
        ];
    }

    public function testNormalizesNamesDomainsAndAdmin(): void
    {
        $all = WorkspaceRegistry::fromRows(self::twoWorkspaces())->all();

        $this->assertCount(2, $all);
        $this->assertSame('Fimca', $all[0]->name);
        $this->assertSame(['fimca.com.br', 'grupoapariciocarvalho.com.br'], $all[0]->domains);
        $this->assertSame('leitor@fimca.com.br', $all[0]->adminSubject);
        $this->assertSame(['metropolitana-ro.com.br'], $all[1]->domains);
    }

    public function testAllowedDomainsIsTheUnionOfTheUsableWorkspaces(): void
    {
        $registry = WorkspaceRegistry::fromRows(self::twoWorkspaces());

        $this->assertSame(
            ['fimca.com.br', 'grupoapariciocarvalho.com.br', 'metropolitana-ro.com.br'],
            $registry->allowedDomains()
        );
    }

    public function testForEmailPicksTheWorkspaceByDomain(): void
    {
        $registry = WorkspaceRegistry::fromRows(self::twoWorkspaces());

        $this->assertSame('Fimca', $registry->forEmail('Ana@GrupoAparicioCarvalho.com.br')?->name);
        $this->assertSame('Metropolitana', $registry->forEmail('x@metropolitana-ro.com.br')?->name);
        $this->assertNull($registry->forEmail('x@outro.com'));
        $this->assertNull($registry->forEmail('sem-arroba'));
    }

    public function testInactiveOrIncompleteWorkspacesAreNotUsable(): void
    {
        $registry = WorkspaceRegistry::fromRows([
            ['name' => 'Desligado', 'domains' => 'a.com', 'admin_subject' => 'x@a.com', 'is_active' => false],
            ['name' => 'Sem admin', 'domains' => 'b.com', 'admin_subject' => '', 'is_active' => true],
            ['name' => 'Sem dominio', 'domains' => '', 'admin_subject' => 'x@c.com', 'is_active' => true],
        ]);

        $this->assertFalse($registry->isUsable());
        $this->assertSame([], $registry->allowedDomains());
        $this->assertNull($registry->forEmail('u@a.com'));
        $this->assertNull($registry->forEmail('u@b.com'));
    }

    public function testRowsWithoutNameAndDomainsAreDropped(): void
    {
        $registry = WorkspaceRegistry::fromRows([
            ['name' => '', 'domains' => '', 'admin_subject' => '', 'is_active' => true],
            'not an array',
            ['name' => 'Fimca', 'domains' => 'fimca.com.br', 'admin_subject' => 'a@fimca.com.br'],
        ]);

        $this->assertCount(1, $registry->all());
        $this->assertTrue($registry->all()[0]->active, 'is_active defaults to true when absent');
    }

    public function testDuplicatedDomainsAcrossWorkspacesAreReportedEvenIfInactive(): void
    {
        $registry = WorkspaceRegistry::fromRows([
            ['name' => 'A', 'domains' => "x.com\ny.com", 'admin_subject' => 'a@x.com', 'is_active' => true],
            ['name' => 'B', 'domains' => "y.com\nz.com", 'admin_subject' => 'b@z.com', 'is_active' => false],
        ]);

        $this->assertSame(['y.com'], $registry->duplicatedDomains());
    }

    public function testNoDuplicatesInsideTheSameWorkspace(): void
    {
        $registry = WorkspaceRegistry::fromRows([
            ['name' => 'A', 'domains' => "x.com\nX.com", 'admin_subject' => 'a@x.com'],
        ]);

        $this->assertSame([], $registry->duplicatedDomains());
        $this->assertSame(['x.com'], $registry->all()[0]->domains);
    }

    public function testJsonRoundTrip(): void
    {
        $json     = WorkspaceRegistry::fromRows(self::twoWorkspaces())->toJson();
        $restored = WorkspaceRegistry::fromJson($json);

        $this->assertSame($json, $restored->toJson());
        $this->assertSame(['fimca.com.br', 'grupoapariciocarvalho.com.br', 'metropolitana-ro.com.br'], $restored->allowedDomains());
    }

    public function testInvalidJsonIsAnEmptyRegistry(): void
    {
        $this->assertSame([], WorkspaceRegistry::fromJson('{not json')->all());
        $this->assertSame([], WorkspaceRegistry::fromJson('')->all());
        $this->assertSame('[]', WorkspaceRegistry::fromJson('"texto"')->toJson());
    }

    public function testLegacySingleWorkspaceSettingsBecomeThePrincipalWorkspace(): void
    {
        $registry = WorkspaceRegistry::fromLegacy([
            'sso_allowed_domains'  => "fimca.com.br\nmetropolitana-ro.com.br",
            'sso_sa_admin_subject' => 'Leitor@fimca.com.br',
        ]);

        $this->assertNotNull($registry);
        $this->assertCount(1, $registry->all());
        $this->assertSame('Principal', $registry->all()[0]->name);
        $this->assertSame(['fimca.com.br', 'metropolitana-ro.com.br'], $registry->all()[0]->domains);
        $this->assertSame('leitor@fimca.com.br', $registry->all()[0]->adminSubject);
    }

    public function testNoLegacyDomainsMeansNothingToMigrate(): void
    {
        $this->assertNull(WorkspaceRegistry::fromLegacy([]));
        $this->assertNull(WorkspaceRegistry::fromLegacy(['sso_allowed_domains' => '  ', 'sso_sa_admin_subject' => 'a@b.com']));
    }

    public function testKeysAreGeneratedFromTheNames(): void
    {
        $registry = WorkspaceRegistry::fromRows(self::twoWorkspaces());

        $this->assertSame(['fimca', 'metropolitana'], $registry->keys());
        $this->assertSame('Metropolitana', $registry->byKey('metropolitana')?->name);
        $this->assertNull($registry->byKey('nada'));
    }

    public function testAGivenKeyWinsOverTheNameSoRenamingKeepsIt(): void
    {
        $registry = WorkspaceRegistry::fromRows([
            ['key' => 'principal', 'name' => 'Fimca Matriz', 'domains' => 'a.com', 'admin_subject' => 'x@a.com'],
        ]);

        $this->assertSame(['principal'], $registry->keys());
        $this->assertSame('Fimca Matriz', $registry->byKey('principal')?->name);
    }

    public function testAKeyThatIsNotTrustedIsReplaced(): void
    {
        $registry = WorkspaceRegistry::fromRows([
            ['key' => 'invented', 'name' => 'Principal', 'domains' => 'a.com', 'admin_subject' => 'x@a.com'],
            ['key' => 'principal', 'name' => 'Outro', 'domains' => 'b.com', 'admin_subject' => 'x@b.com'],
        ], ['principal']);

        $this->assertSame(['principal-2', 'principal'], $registry->keys(), 'untrusted key regenerated; the trusted one is kept');
    }

    public function testInvalidAndDuplicatedKeysAreReplaced(): void
    {
        $registry = WorkspaceRegistry::fromRows([
            ['key' => 'Bad Key!', 'name' => 'Alfa', 'domains' => 'a.com', 'admin_subject' => 'x@a.com'],
            ['key' => 'same', 'name' => 'Beta', 'domains' => 'b.com', 'admin_subject' => 'x@b.com'],
            ['key' => 'same', 'name' => 'Gama', 'domains' => 'c.com', 'admin_subject' => 'x@c.com'],
        ]);

        $this->assertSame(['alfa', 'same', 'gama'], $registry->keys());
    }

    public function testStoredWorkspacesWithoutKeyGetOneAndItIsStable(): void
    {
        $legacy = '[{"name":"Principal","domains":["a.com"],"admin_subject":"x@a.com","is_active":true}]';

        $first  = WorkspaceRegistry::fromJson($legacy);
        $second = WorkspaceRegistry::fromJson($first->toJson());

        $this->assertSame(['principal'], $first->keys());
        $this->assertSame($first->toJson(), $second->toJson());
        $this->assertStringStartsWith('[{"key":"principal","name":"Principal"', $first->toJson());
    }

    public function testRowWithoutNameUsesItsFirstDomainForTheKey(): void
    {
        $registry = WorkspaceRegistry::fromRows([['name' => '', 'domains' => 'fimca.com.br', 'admin_subject' => '']]);

        $this->assertSame(['fimca-com-br'], $registry->keys());
    }
}
