# SSO: seletor de OU e workspace nas regras — Plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Entregar a S25 a S30 da spec do SSO: chave estável por workspace, critério de regra `GOOGLE_WORKSPACE`, trava na remoção de workspace, lista de OUs lida direto do Google (com cache) e um seletor de OU/workspace na tela de regras e no campo de OUs bloqueadas.

**Architecture:** A lógica que é regra de negócio fica em classes PHP puras e em funções puras de JS (testáveis sem o GLPI); o que depende do GLPI (Guzzle, banco, cache, AJAX, DOM do GLPI) fica fino em classes/arquivos separados. Nada de tabela nova: a lista de OUs vem de `orgunits.list` e fica em `$GLPI_CACHE`. O seletor é um JS carregado pelo hook `add_javascript` que troca o `<input name="pattern">` do formulário de critérios.

**Tech Stack:** PHP 8.2, GLPI 11.0.8, Guzzle (via `Toolbox::getGuzzleClient()`), PHPUnit 11 (suíte standalone), Node 24 (`node --test`), select2 (já embutido no GLPI).

**Spec:** `docs/superpowers/specs/2026-10-05-sso-google-design.md` (decisões S25 a S30, seção 12 "Testes da entrega S25 a S30"). **Se o código divergir da spec, um dos dois está errado: corrija.**

## Global Constraints

- **Cabeçalho de licença:** todo arquivo PHP novo começa com `<?php`, o docblock de licença de 32 linhas copiado de `src/Sso/OuCopy.php` (linhas 1 a 32) e `declare(strict_types=1);`. Nos blocos de código abaixo isso aparece como `// <cabeçalho padrão>`: copie o docblock real, não deixe o comentário.
- **Namespace:** classes em `GlpiPlugin\Gac\Sso`, testes em `GlpiPlugin\Gac\Tests\Unit`.
- **Textos de tela em pt-BR**, em `__('...', 'gac')` no PHP. O JS não tem tradução: usa o objeto `TEXT` em pt-BR (o plugin não tem `locales/`).
- **PHP:** `/c/xampp/php/php.exe`. Suíte unitária: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml` (linha de base antes deste plano: **317 testes, 898 asserções, OK**). JS: `node --test "tests/js/*.test.js"`.
- **Classes puras** (`WorkspaceKey`, `WorkspaceRemoval`, `OrgUnitList`, `RuleInput`) não podem chamar `__()`, `htmlescape()`, banco, cache nem Guzzle: o harness unitário não os tem.
- **Commits:** só com a skill `/commit` (tipos `feat`, `fix`, `chore`; título em inglês; descrição em lista; **sem** `Co-Authored-By` nem `Claude-Session`). Todo trabalho na branch `dev`. **Quem decide o momento do commit é o dono**: onde o plano diz "Ponto de commit", pare, mostre o `git status` e deixe o dono rodar `/commit`. Nunca `git commit` à mão e nunca `git push`.
- **GLPI local:** `http://localhost:8080/`, banco em `127.0.0.1:3307`. Depois de editar `.twig`: `bin/console cache:clear`. O JS do plugin é servido com cache de 30 dias: **Ctrl+F5** depois de editar JS.
- **Padrões de segurança:** nenhum HTML montado com texto vindo do Google ou do usuário sem escape; a resposta do endpoint nunca contém chave de conta de serviço, token nem o e-mail do administrador representado.
- **Fora do escopo:** bloqueio por workspace (S30), tabela do plugin para OUs, tela de árvore de OUs, conversão automática de regras antigas.

## Mapa de arquivos

| Arquivo | Ação | Responsabilidade |
|---|---|---|
| `src/Sso/WorkspaceKey.php` | criar | Gerar, validar e desduplicar a chave do workspace (pura) |
| `src/Sso/Workspace.php` | modificar | Ganha `key` |
| `src/Sso/WorkspaceRegistry.php` | modificar | Atribui chaves, `keys()`, `byKey()` |
| `src/Sso/RuleInput.php` | criar | Monta os parâmetros do motor e a entrada dos critérios (pura) |
| `src/Sso/RuleHooks.php` | modificar | Critério `GOOGLE_WORKSPACE`; `inputData` delega ao `RuleInput` |
| `src/Sso/RuleRunner.php` | modificar | Recebe a chave do workspace |
| `src/Sso/LoginService.php`, `src/Sso/DryRun.php` | modificar | Passam `$workspace->key` ao `RuleRunner` |
| `src/Sso/WorkspaceRemoval.php` | criar | Quais chaves foram removidas (pura) |
| `src/Sso/WorkspaceRuleUsage.php` | criar | Regras de autorização que usam uma chave (banco) |
| `src/Sso/OrgUnitList.php` | criar | Ler `orgunits.list`, ordenar, montar o payload do endpoint e o envelope do cache (pura) |
| `src/Sso/GoogleServiceToken.php` | criar | Token da conta de serviço (extraído do `DirectoryClient`) |
| `src/Sso/DirectoryClient.php` | modificar | Usa o `GoogleServiceToken`; `errorDetail` pública |
| `src/Sso/OrgUnitDirectory.php` | criar | `orgunits.list` + cache por workspace |
| `ajax/sso/orgunits.php` | criar | Endpoint JSON da lista de OUs |
| `public/js/sso-ou-picker.js` | criar | Funções puras + cola de DOM dos dois seletores |
| `tests/js/sso-ou-picker.test.js` | criar | Testes das funções puras do JS |
| `setup.php` | modificar | `add_javascript` passa a ser uma lista |
| `src/Sso/SsoConfigSection.php` | modificar | Chave no formulário, trava na remoção, seletor das bloqueadas, textos |
| `tests/Unit/*Test.php` | criar/modificar | Camada A da spec |
| `tests/integration/*.php` | criar | Camada C da spec (rodam contra o GLPI local) |
| `docs/sso-manual-tests.md`, `CLAUDE.md` | modificar | Roteiro do navegador e resumo do módulo |

---

### Task 1: WorkspaceKey (chave estável, pura)

**Files:**
- Create: `src/Sso/WorkspaceKey.php`
- Test: `tests/Unit/WorkspaceKeyTest.php`

**Interfaces:**
- Produces: `WorkspaceKey::isValid(string): bool`, `WorkspaceKey::fromName(string): string`, `WorkspaceKey::unique(string $base, array $taken): string`, constantes `MAX_LENGTH = 40`, `FALLBACK = 'workspace'`.

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php

// <cabeçalho padrão>

declare(strict_types=1);

namespace GlpiPlugin\Gac\Tests\Unit;

use GlpiPlugin\Gac\Sso\WorkspaceKey;
use PHPUnit\Framework\TestCase;

final class WorkspaceKeyTest extends TestCase
{
    public function testFromNameMakesALowercaseSlug(): void
    {
        $this->assertSame('principal', WorkspaceKey::fromName('Principal'));
        $this->assertSame('metropolitana', WorkspaceKey::fromName('Metropolitana'));
    }

    public function testFromNameRemovesAccentsAndPunctuation(): void
    {
        $this->assertSame('grupo-aparicio-carvalho', WorkspaceKey::fromName('Grupo Aparício Carvalho'));
        $this->assertSame('fimca', WorkspaceKey::fromName('  --Fimca!!  '));
        $this->assertSame('coracao-nacao', WorkspaceKey::fromName('Coração Nação'));
    }

    public function testFromNameFallsBackWhenNothingUsableIsLeft(): void
    {
        $this->assertSame(WorkspaceKey::FALLBACK, WorkspaceKey::fromName(''));
        $this->assertSame(WorkspaceKey::FALLBACK, WorkspaceKey::fromName('???'));
        $this->assertTrue(WorkspaceKey::isValid(WorkspaceKey::FALLBACK));
    }

    public function testFromNameNeverExceedsTheLimitNorEndsWithAHyphen(): void
    {
        $key = WorkspaceKey::fromName(str_repeat('a b ', 30));

        $this->assertLessThanOrEqual(WorkspaceKey::MAX_LENGTH, strlen($key));
        $this->assertTrue(WorkspaceKey::isValid($key), $key);
    }

    public function testIsValid(): void
    {
        foreach (['principal', 'a-b-2', 'x1', str_repeat('a', 40)] as $good) {
            $this->assertTrue(WorkspaceKey::isValid($good), $good);
        }
        foreach (['', 'Principal', '-a', 'a-', 'a--b', 'a_b', 'a b', 'acento-é', str_repeat('a', 41)] as $bad) {
            $this->assertFalse(WorkspaceKey::isValid($bad), $bad);
        }
    }

    public function testUniqueAddsASuffixOnCollision(): void
    {
        $this->assertSame('principal', WorkspaceKey::unique('principal', []));
        $this->assertSame('principal-2', WorkspaceKey::unique('principal', ['principal']));
        $this->assertSame('principal-3', WorkspaceKey::unique('principal', ['principal', 'principal-2']));
    }

    public function testUniqueKeepsTheSuffixInsideTheLimit(): void
    {
        $base   = str_repeat('a', 40);
        $unique = WorkspaceKey::unique($base, [$base]);

        $this->assertSame(40, strlen($unique));
        $this->assertStringEndsWith('-2', $unique);
        $this->assertTrue(WorkspaceKey::isValid($unique));
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml --filter WorkspaceKeyTest`
Expected: erro `Class "GlpiPlugin\Gac\Sso\WorkspaceKey" not found`.

- [ ] **Step 3: Implementar**

```php
<?php

// <cabeçalho padrão>

declare(strict_types=1);

namespace GlpiPlugin\Gac\Sso;

/**
 * The stable identifier of a Google Workspace (spec S25): lowercase `[a-z0-9-]`, at most 40
 * characters. Rules refer to it, so unlike the name it never changes once saved. Pure.
 */
final class WorkspaceKey
{
    public const MAX_LENGTH = 40;
    public const FALLBACK   = 'workspace';

    private const ACCENTS = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ç' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y',
    ];

    public static function isValid(string $key): bool
    {
        return strlen($key) <= self::MAX_LENGTH && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $key) === 1;
    }

    /** The key a workspace gets from its name when it is created. */
    public static function fromName(string $name): string
    {
        $text = strtr(mb_strtolower($name, 'UTF-8'), self::ACCENTS);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $text), '-');
        $slug = rtrim(substr($slug, 0, self::MAX_LENGTH), '-');

        return $slug === '' ? self::FALLBACK : $slug;
    }

    /**
     * $base, or $base with the first free "-2", "-3"... suffix.
     *
     * @param list<string> $taken
     */
    public static function unique(string $base, array $taken): string
    {
        if (!in_array($base, $taken, true)) {
            return $base;
        }

        for ($n = 2;; $n++) {
            $suffix    = '-' . $n;
            $candidate = rtrim(substr($base, 0, self::MAX_LENGTH - strlen($suffix)), '-') . $suffix;
            if (!in_array($candidate, $taken, true)) {
                return $candidate;
            }
        }
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml --filter WorkspaceKeyTest`
Expected: `OK (7 tests, ...)`.

- [ ] **Step 5: Ponto de commit**

Arquivos: `src/Sso/WorkspaceKey.php`, `tests/Unit/WorkspaceKeyTest.php`. Mostre `git status` e pare: o dono roda `/commit` (sugestão: `feat(sso): add stable workspace key`).

---

### Task 2: Chave no Workspace, no registro, nas configurações e no formulário

**Files:**
- Modify: `src/Sso/Workspace.php`, `src/Sso/WorkspaceRegistry.php`, `src/Sso/SsoConfigSection.php`
- Test: `tests/Unit/WorkspaceRegistryTest.php`, `tests/Unit/SsoSettingsTest.php`

**Interfaces:**
- Consumes: `WorkspaceKey::isValid/fromName/unique` (Task 1).
- Produces: `new Workspace(string $key, string $name, array $domains, string $adminSubject, bool $active)`; `Workspace->key`; `Workspace::toArray()` com `key` primeiro; `WorkspaceRegistry::fromRows(array $rows, ?array $trustedKeys = null)`; `WorkspaceRegistry::keys(): list<string>`; `WorkspaceRegistry::byKey(string): ?Workspace`.

- [ ] **Step 1: Escrever os testes que falham**

Acrescente ao final de `tests/Unit/WorkspaceRegistryTest.php` (antes do `}` da classe):

```php
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
```

Em `tests/Unit/SsoSettingsTest.php`, troque a asserção do JSON canônico (linhas 136 a 139) por:

```php
        $this->assertSame(
            '[{"key":"a","name":"A","domains":["a.com"],"admin_subject":"","is_active":true}]',
            $s['sso_workspaces']
        );
```

e acrescente antes do `}` final da classe:

```php
    public function testNormalizingTwiceGivesTheSameWorkspaces(): void
    {
        $once  = SsoSettings::normalize(['sso_workspaces' => [['name' => 'Fimca', 'domains' => 'fimca.com.br', 'admin_subject' => 'a@fimca.com.br']]]);
        $twice = SsoSettings::normalize($once);

        $this->assertSame($once['sso_workspaces'], $twice['sso_workspaces']);
        $this->assertSame(['fimca'], SsoSettings::workspaces($twice)->keys());
    }
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml --filter "WorkspaceRegistryTest|SsoSettingsTest"`
Expected: FAIL (`Call to undefined method ...::keys()`).

- [ ] **Step 3: Implementar `Workspace`**

Em `src/Sso/Workspace.php`, substitua o construtor e `toArray()`:

```php
    /** @param list<string> $domains lowercase */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly array $domains,
        public readonly string $adminSubject,
        public readonly bool $active
    ) {}
```

```php
    /** @return array{key: string, name: string, domains: list<string>, admin_subject: string, is_active: bool} */
    public function toArray(): array
    {
        return [
            'key'           => $this->key,
            'name'          => $this->name,
            'domains'       => $this->domains,
            'admin_subject' => $this->adminSubject,
            'is_active'     => $this->active,
        ];
    }
```

Atualize também o docblock da classe acrescentando: `* The key (spec S25) is the stable identifier the authorization rules refer to; the name can be edited.`

- [ ] **Step 4: Implementar `WorkspaceRegistry`**

Substitua o método `fromRows` inteiro (e seu docblock) por:

```php
    /**
     * Normalizes raw rows (from a form or from JSON). Domains may be a text (one per line) or a
     * list; rows with neither a name nor a domain are dropped; a missing "is_active" means active.
     *
     * Keys (spec S25): a row keeps its "key" when it is valid, not used by a previous row and, when
     * $trustedKeys is a list (a form post), one of those keys; every other row gets a new key from
     * its name. Stored JSON is read with $trustedKeys = null (it is trusted).
     *
     * @param array<int|string, mixed> $rows
     * @param ?list<string>            $trustedKeys
     */
    public static function fromRows(array $rows, ?array $trustedKeys = null): self
    {
        $parsed = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $domainsRaw = $row['domains'] ?? '';
            $domains    = DomainPolicy::parseDomains(is_array($domainsRaw) ? implode("\n", array_map('strval', $domainsRaw)) : (string) $domainsRaw);
            $name       = trim((string) ($row['name'] ?? ''));
            if ($name === '' && $domains === []) {
                continue;
            }

            $parsed[] = [
                'key'     => trim((string) ($row['key'] ?? '')),
                'name'    => $name,
                'domains' => $domains,
                'admin'   => mb_strtolower(trim((string) ($row['admin_subject'] ?? ''))),
                'active'  => !array_key_exists('is_active', $row) || self::truthy($row['is_active']),
            ];
        }

        // Pass 1 reserves the keys that can be kept, so a generated key never takes one of them.
        $taken = [];
        foreach ($parsed as $i => $p) {
            $key        = $p['key'];
            $acceptable = $key !== ''
                && WorkspaceKey::isValid($key)
                && !in_array($key, $taken, true)
                && ($trustedKeys === null || in_array($key, $trustedKeys, true));
            $parsed[$i]['key'] = $acceptable ? $key : '';
            if ($acceptable) {
                $taken[] = $key;
            }
        }

        $workspaces = [];
        foreach ($parsed as $p) {
            $key = $p['key'];
            if ($key === '') {
                $key     = WorkspaceKey::unique(WorkspaceKey::fromName($p['name'] !== '' ? $p['name'] : $p['domains'][0]), $taken);
                $taken[] = $key;
            }
            $workspaces[] = new Workspace($key, $p['name'], $p['domains'], $p['admin'], $p['active']);
        }

        return new self($workspaces);
    }
```

E acrescente depois de `all()`:

```php
    /** @return list<string> */
    public function keys(): array
    {
        return array_map(static fn (Workspace $w): string => $w->key, $this->workspaces);
    }

    public function byKey(string $key): ?Workspace
    {
        foreach ($this->workspaces as $workspace) {
            if ($workspace->key === $key) {
                return $workspace;
            }
        }

        return null;
    }
```

- [ ] **Step 5: Rodar e ver passar**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml`
Expected: `OK` com 317 + 7 (Task 1) + 7 (esta tarefa: 6 do registro, 1 das configurações) = 331 testes. Se algum teste existente falhar por causa do JSON agora ter `key`, corrija a expectativa dele (só `SsoSettingsTest` tinha).

- [ ] **Step 6: Formulário da configuração**

Em `src/Sso/SsoConfigSection.php`:

1. Em `workspacesBlock()`, troque a linha da linha vazia por:

```php
        $workspaces[] = new Workspace('', '', [], '', true); // one empty row to add a workspace
```

2. Em `workspaceRow()`, troque a primeira `<td>` (a do nome) por:

```php
            . "<td><input class='form-control' name='ws_name[]' value='" . htmlescape($workspace->name) . "'>"
            . "<input type='hidden' name='ws_key[]' value='" . htmlescape($workspace->key) . "'>"
            . "<div class='form-text font-monospace'>" . htmlescape(__('Chave:', 'gac')) . " <span class='gac-ws-key'>" . htmlescape($workspace->key) . '</span></div></td>'
```

3. No script do bloco, em `clearRow`, acrescente a limpeza do texto da chave:

```js
    function clearRow(row) {
        row.querySelectorAll('input, textarea').forEach(function (el) { el.value = ''; });
        row.querySelectorAll('select').forEach(function (el) { el.value = '1'; });
        row.querySelectorAll('.gac-ws-key').forEach(function (el) { el.textContent = ''; });
    }
```

4. Em `th()` da coluna Nome, troque a ajuda para `__('Só para identificar o workspace. Pode ser renomeado: a chave abaixo é o que as regras usam e nunca muda.', 'gac')`.

5. Em `handlePost()`, troque a montagem das linhas e do registro por:

```php
        $current = SsoSettings::workspaces($raw);
        $rows    = [];
        $names   = is_array($post['ws_name'] ?? null) ? array_values($post['ws_name']) : [];
        foreach ($names as $i => $name) {
            $rows[] = [
                'key'           => (string) ($post['ws_key'][$i] ?? ''),
                'name'          => (string) $name,
                'domains'       => (string) ($post['ws_domains'][$i] ?? ''),
                'admin_subject' => (string) ($post['ws_admin'][$i] ?? ''),
                'is_active'     => ($post['ws_active'][$i] ?? '1') === '1',
            ];
        }
        // Only a key the stored workspaces already have is kept; anything else is regenerated (S25).
        $registry   = WorkspaceRegistry::fromRows($rows, $current->keys());
```

(a linha seguinte, `$duplicated = $registry->duplicatedDomains();`, continua como está; remova a linha antiga `$raw = SsoConfig::load();` duplicada só se ficar repetida: ela já existe antes do bloco e continua sendo usada por `$current`.)

- [ ] **Step 7: Verificar sintaxe e a suíte**

Run: `/c/xampp/php/php.exe -l src/Sso/SsoConfigSection.php && /c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml`
Expected: `No syntax errors detected` e `OK`.

- [ ] **Step 8: Conferir no GLPI local**

Run: `/c/xampp/php/php.exe var/tools/gac-eval.php 'foreach (GlpiPlugin\Gac\Sso\SsoSettings::workspaces(GlpiPlugin\Gac\Sso\SsoConfig::load())->all() as $w) { echo $w->key, " | ", $w->name, "\n"; }'`
Expected: `principal | Principal` e `metropolitana | Metropolitana`. Depois abra a configuração do SSO no navegador (`http://localhost:8080/`), confirme que cada linha mostra "Chave: ..." em fonte monoespaçada, renomeie "Principal" para "Principal X", salve, e rode o comando de novo: a chave continua `principal`. **Volte o nome para "Principal" e salve.**

- [ ] **Step 9: Ponto de commit**

Arquivos: `src/Sso/Workspace.php`, `src/Sso/WorkspaceRegistry.php`, `src/Sso/SsoConfigSection.php`, `tests/Unit/WorkspaceRegistryTest.php`, `tests/Unit/SsoSettingsTest.php`. Pare para o dono rodar `/commit` (sugestão: `feat(sso): give each workspace a stable key`).

---

### Task 3: Critério `GOOGLE_WORKSPACE` e o motor de regras

**Files:**
- Create: `src/Sso/RuleInput.php`, `tests/Unit/RuleInputTest.php`, `tests/integration/boot.php`, `tests/integration/support.php`, `tests/integration/engine_compat.php`
- Modify: `src/Sso/RuleHooks.php`, `src/Sso/RuleRunner.php`, `src/Sso/LoginService.php`, `src/Sso/DryRun.php`

**Interfaces:**
- Consumes: `Workspace->key` (Task 2).
- Produces: `RuleInput::OU_CRITERION = 'GOOGLE_OU'`, `RuleInput::WORKSPACE_CRITERION = 'GOOGLE_WORKSPACE'`; `RuleInput::googleParams(array $ancestors, string $workspaceKey): array` (chaves `google_ou`, `google_workspace`); `RuleInput::engineInput(array $params): array` (chaves de critério → valores); `RuleRunner::run(string $email, array $ancestors, string $workspaceKey = ''): array` e `RuleRunner::result(...)` com a mesma assinatura.

- [ ] **Step 1: Escrever o teste que falha**

`tests/Unit/RuleInputTest.php`:

```php
<?php

// <cabeçalho padrão>

declare(strict_types=1);

namespace GlpiPlugin\Gac\Tests\Unit;

use GlpiPlugin\Gac\Sso\RuleInput;
use PHPUnit\Framework\TestCase;

final class RuleInputTest extends TestCase
{
    public function testGoogleParamsCarryTheAncestorsAndTheWorkspaceKey(): void
    {
        $this->assertSame(
            ['google_ou' => ['/a', '/a/b'], 'google_workspace' => 'principal'],
            RuleInput::googleParams(['/a', '/a/b'], 'principal')
        );
    }

    public function testNoWorkspaceKeyMeansNoWorkspaceParam(): void
    {
        $this->assertSame(['google_ou' => ['/a']], RuleInput::googleParams(['/a'], ''));
    }

    public function testEngineInputMapsTheParamsToTheCriteria(): void
    {
        $input = RuleInput::engineInput(['google_ou' => ['/a'], 'google_workspace' => 'principal', 'other' => 1]);

        $this->assertSame(['GOOGLE_OU' => ['/a'], 'GOOGLE_WORKSPACE' => 'principal'], $input);
    }

    public function testRulesWithoutTheWorkspaceCriterionSeeTheSameOuInputAsBefore(): void
    {
        $this->assertSame(['GOOGLE_OU' => ['/a']], RuleInput::engineInput(['google_ou' => ['/a']]));
    }

    public function testEngineInputIgnoresValuesOfTheWrongType(): void
    {
        $this->assertSame([], RuleInput::engineInput(['google_ou' => '/a', 'google_workspace' => ['x']]));
        $this->assertSame([], RuleInput::engineInput(['google_workspace' => '']));
        $this->assertSame([], RuleInput::engineInput([]));
    }

    public function testCriterionNamesAreTheOnesStoredInTheRules(): void
    {
        $this->assertSame('GOOGLE_OU', RuleInput::OU_CRITERION);
        $this->assertSame('GOOGLE_WORKSPACE', RuleInput::WORKSPACE_CRITERION);
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml --filter RuleInputTest`
Expected: `Class "GlpiPlugin\Gac\Sso\RuleInput" not found`.

- [ ] **Step 3: Implementar `RuleInput`**

```php
<?php

// <cabeçalho padrão>

declare(strict_types=1);

namespace GlpiPlugin\Gac\Sso;

/**
 * What the Google login hands to the authorization rules engine (spec S4, S26): the OU ancestors
 * for the "OU do Google Workspace" criterion and the workspace key for the "Workspace do Google"
 * criterion. Pure.
 */
final class RuleInput
{
    public const OU_CRITERION        = 'GOOGLE_OU';
    public const WORKSPACE_CRITERION = 'GOOGLE_WORKSPACE';

    private const OU_PARAM        = 'google_ou';
    private const WORKSPACE_PARAM = 'google_workspace';

    /**
     * The Google part of the params given to processAllRules(). Without a workspace key the
     * workspace param is left out, so rules that never mention it behave as before (S26).
     *
     * @param list<string> $ancestors OuPath::ancestors() of the user's OU
     * @return array<string, mixed>
     */
    public static function googleParams(array $ancestors, string $workspaceKey): array
    {
        $params = [self::OU_PARAM => $ancestors];
        if ($workspaceKey !== '') {
            $params[self::WORKSPACE_PARAM] = $workspaceKey;
        }

        return $params;
    }

    /**
     * The value of each criterion, from the params of processAllRules() (hook
     * ruleCollectionPrepareInputDataForProcess).
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function engineInput(array $params): array
    {
        $input = [];

        $ou = $params[self::OU_PARAM] ?? null;
        if (is_array($ou)) {
            $input[self::OU_CRITERION] = $ou;
        }

        $workspace = $params[self::WORKSPACE_PARAM] ?? null;
        if (is_string($workspace) && $workspace !== '') {
            $input[self::WORKSPACE_CRITERION] = $workspace;
        }

        return $input;
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml --filter RuleInputTest`
Expected: `OK (6 tests, ...)`.

- [ ] **Step 5: Ligar ao GLPI**

`src/Sso/RuleHooks.php`: troque `public const CRITERION = 'GOOGLE_OU';` por

```php
    public const CRITERION           = RuleInput::OU_CRITERION;
    public const WORKSPACE_CRITERION = RuleInput::WORKSPACE_CRITERION;
```

Em `criteria()`, troque o `return [...]` por:

```php
        return [
            self::CRITERION => [
                'name'      => __('OU do Google Workspace', 'gac'),
                'field'     => '',
                'table'     => '',
                'linkfield' => '',
                'virtual'   => true,
                'id'        => 'google_ou',
            ],
            self::WORKSPACE_CRITERION => [
                'name'            => __('Workspace do Google', 'gac'),
                'field'           => '',
                'table'           => '',
                'linkfield'       => '',
                'virtual'         => true,
                'id'              => 'google_workspace',
                'allow_condition' => [\Rule::PATTERN_IS],
            ],
        ];
```

Em `inputData()`, troque as duas últimas linhas do corpo por:

```php
        $ruleParams = $params['values']['params'] ?? null;

        return RuleInput::engineInput(is_array($ruleParams) ? $ruleParams : []);
```

Atualize o docblock da classe acrescentando uma frase: `The "Workspace do Google" criterion (spec S26) is matched against the workspace key.`

`src/Sso/RuleRunner.php`: troque `run()` e `result()` por:

```php
    /**
     * @param list<string> $ancestors   OuPath::ancestors() of the user's OU
     * @param string       $workspaceKey Workspace::$key of the e-mail's workspace (spec S26)
     * @return array<string, mixed> the engine's output array
     */
    public static function run(string $email, array $ancestors, string $workspaceKey = ''): array
    {
        $collection = new \RuleRightCollection();

        // The output is seeded with the name only: if "entities_id" shows up in it afterwards, a
        // rule set the default entity (the _entities_id_default action).
        return $collection->processAllRules([], ['name' => $email], [
            'type'  => \Auth::EXTERNAL,
            'login' => $email,
            'email' => $email,
        ] + RuleInput::googleParams($ancestors, $workspaceKey));
    }

    /** @param list<string> $ancestors */
    public static function result(string $email, array $ancestors, string $workspaceKey = ''): RuleResult
    {
        return RuleResult::fromOutput(self::run($email, $ancestors, $workspaceKey));
    }
```

`src/Sso/LoginService.php` (linha 138): `$ruleOutput = RuleRunner::run($email, OuPath::ancestors($ou), $workspace->key);`

`src/Sso/DryRun.php` (linha 106): `$rules = RuleRunner::result($email, $report['ancestors'], $workspace->key);`

- [ ] **Step 6: Scripts de integração (suporte)**

`tests/integration/boot.php`:

```php
<?php

// Sobe o kernel do GLPI local para os scripts de integração (só desenvolvimento).
// Uso: /c/xampp/php/php.exe tests/integration/<script>.php
$glpi = getenv('GLPI_DIR') ?: 'C:/Users/juliano/VSCode/glpi-xampp-dev-plugin';
chdir($glpi);
require 'vendor/autoload.php';
$kernel = new \Glpi\Kernel\Kernel(null);
$kernel->boot();

global $DB;
$_SESSION['glpiactive_entity']           = 0;
$_SESSION['glpiactive_entity_recursive'] = 1;
$_SESSION['glpiactiveentities']          = [0];
$_SESSION['glpiactiveentities_string']   = '0';
$_SESSION['glpicronuserrunning']         = 'gac-integration';
$_SESSION['glpiname']                    = 'gac-integration';
$_SESSION['glpi_currenttime']            = date('Y-m-d H:i:s');

require_once __DIR__ . '/support.php';
```

`tests/integration/support.php`:

```php
<?php

// Asserções mínimas dos scripts de integração.
$GLOBALS['gac_checks'] = ['ok' => 0, 'failed' => 0];

function check(bool $ok, string $label): void
{
    $GLOBALS['gac_checks'][$ok ? 'ok' : 'failed']++;
    echo ($ok ? 'OK     ' : 'FALHOU '), $label, "\n";
}

function finish(): never
{
    $c = $GLOBALS['gac_checks'];
    echo "\n", $c['ok'], ' ok, ', $c['failed'], " falharam\n";
    exit($c['failed'] === 0 ? 0 : 1);
}

/** Cria uma regra de autorização temporária com critérios e ações; devolve o id. */
function make_rule(string $name, array $criteria, array $actions, int $rank, string $class = 'RuleRight', bool $active = true): int
{
    global $DB;
    $rule = new $class();
    $id   = $rule->add([
        'name' => 'GACTEST ' . $name, 'sub_type' => $class, 'match' => 'AND',
        'is_active' => $active ? 1 : 0, 'entities_id' => 0, 'is_recursive' => 1, 'ranking' => $rank,
    ]);
    $DB->update('glpi_rules', ['ranking' => $rank], ['id' => $id]);
    foreach ($criteria as [$field, $cond, $pattern]) {
        (new RuleCriteria())->add(['rules_id' => $id, 'criteria' => $field, 'condition' => $cond, 'pattern' => $pattern]);
    }
    foreach ($actions as [$field, $type, $value]) {
        (new RuleAction())->add(['rules_id' => $id, 'action_type' => $type, 'field' => $field, 'value' => $value]);
    }

    return $id;
}

function drop_rule(int $id): void
{
    global $DB;
    $DB->delete('glpi_ruleactions', ['rules_id' => $id]);
    $DB->delete('glpi_rulecriterias', ['rules_id' => $id]);
    $DB->delete('glpi_rules', ['id' => $id]);
}
```

- [ ] **Step 7: Script de compatibilidade do motor**

`tests/integration/engine_compat.php`:

```php
<?php

// Regras antigas (só GOOGLE_OU) continuam valendo em qualquer workspace; regras com GOOGLE_WORKSPACE
// só no workspace delas (spec S26). Cria regras GACTEST, exercita o RuleRunner e apaga tudo.
require __DIR__ . '/boot.php';

use GlpiPlugin\Gac\Sso\OuPath;
use GlpiPlugin\Gac\Sso\RuleRunner;

check(isset((new RuleRight())->getAllCriteria()['GOOGLE_WORKSPACE']), 'critério GOOGLE_WORKSPACE registrado');

$ids = [];
try {
    // R1: regra antiga, só a OU -> perfil 1.
    $ids[] = make_rule('antiga', [['GOOGLE_OU', Rule::PATTERN_IS, '/gactest/x']],
        [['entities_id', 'assign', 0], ['profiles_id', 'assign', 1], ['is_recursive', 'assign', 0]], 9100);
    // R2: OU + workspace principal -> perfil 4.
    $ids[] = make_rule('so principal', [['GOOGLE_OU', Rule::PATTERN_IS, '/gactest/x'], ['GOOGLE_WORKSPACE', Rule::PATTERN_IS, 'principal']],
        [['entities_id', 'assign', 0], ['profiles_id', 'assign', 4], ['is_recursive', 'assign', 0]], 9101);

    $profiles = static function (string $workspaceKey): array {
        $result = RuleRunner::result('a@gactest.example', OuPath::ancestors('/gactest/x'), $workspaceKey);
        $found  = array_unique(array_map(static fn (array $g): int => $g['profiles_id'], $result->grants));
        sort($found);

        return $found;
    };

    check($profiles('principal') === [1, 4], 'workspace principal: casa a regra antiga e a nova (perfis 1 e 4)');
    check($profiles('metropolitana') === [1], 'workspace metropolitana: só a regra antiga (perfil 1)');
    check($profiles('') === [1], 'sem chave de workspace: só a regra antiga (perfil 1)');
} finally {
    array_map('drop_rule', $ids);
    echo 'regras temporárias removidas: ', count($ids), "\n";
}

finish();
```

- [ ] **Step 8: Rodar tudo**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml && /c/xampp/php/php.exe tests/integration/engine_compat.php`
Expected: suíte `OK`; o script imprime os quatro `OK` e `4 ok, 0 falharam`.
Se `critério GOOGLE_WORKSPACE registrado` falhar: o GLPI não recarregou os hooks. Rode `/c/xampp/php/php.exe var/tools/gac-install.php` (ou `bin/console cache:clear` na pasta do GLPI) e repita.

- [ ] **Step 9: Conferir no navegador**

Abra Administração > Regras > Regras de autorização > uma regra > Critérios > Adicionar. Em "Critério" deve aparecer **"Workspace do Google"**, e ao escolhê-lo só a condição "é" deve ser oferecida (o campo ainda é texto livre; o seletor vem na Task 8). Rode também o "Testar" da configuração do SSO com um e-mail real e confirme que ele ainda funciona.

- [ ] **Step 10: Ponto de commit**

Arquivos: `src/Sso/RuleInput.php`, `src/Sso/RuleHooks.php`, `src/Sso/RuleRunner.php`, `src/Sso/LoginService.php`, `src/Sso/DryRun.php`, `tests/Unit/RuleInputTest.php`, `tests/integration/boot.php`, `tests/integration/support.php`, `tests/integration/engine_compat.php`. Pare para o dono rodar `/commit` (sugestão: `feat(sso): add workspace criterion to authorization rules`).

---

### Task 4: Trava na remoção de um workspace

**Files:**
- Create: `src/Sso/WorkspaceRemoval.php`, `src/Sso/WorkspaceRuleUsage.php`, `tests/Unit/WorkspaceRemovalTest.php`, `tests/integration/workspace_removal_guard.php`
- Modify: `src/Sso/SsoConfigSection.php` (`handlePost`), `docs/superpowers/specs/2026-10-05-sso-google-design.md`

**Interfaces:**
- Consumes: `WorkspaceRegistry::keys()` (Task 2), `RuleInput::WORKSPACE_CRITERION` (Task 3).
- Produces: `WorkspaceRemoval::removedKeys(WorkspaceRegistry $stored, WorkspaceRegistry $submitted): list<string>`; `WorkspaceRuleUsage::rulesUsing(array $keys): list<array{rule_id: int, name: string, is_active: bool, key: string}>`; `WorkspaceRuleUsage::workspaceKeyOfRule(int $ruleId): ?string`.

- [x] **Step 1: Corrigir a spec (qualquer condição bloqueia)** (já aplicado ao escrever este plano; só conferir com o `grep` abaixo)

Na seção "Testes da entrega" da spec, no item da varredura da trava, troque o trecho `com o critério em outra condição, e uma regra apagada. Esperado: só as duas primeiras bloqueiam, e a\n  mensagem traz o nome e o link de cada uma.` por: `com o critério em outra condição (por exemplo "não é"), e uma regra apagada. Esperado: bloqueiam a\n  ativa, a desativada e a de outra condição; não bloqueiam a de outro tipo, a de outra chave nem a\n  apagada. A mensagem traz o nome e o link de cada uma.` E em S27, depois de `com \`criteria = 'GOOGLE_WORKSPACE'\`, \`pattern\` igual à chave`, acrescente `(qualquer condição: uma regra "não é" também depende do workspace)`. Confira com `grep -n "qualquer condição" docs/superpowers/specs/2026-10-05-sso-google-design.md`.

- [ ] **Step 2: Escrever o teste que falha**

`tests/Unit/WorkspaceRemovalTest.php`:

```php
<?php

// <cabeçalho padrão>

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
        $rows        = self::rows();
        $rows[0]['name'] = 'Outro nome';
        $stored    = WorkspaceRegistry::fromRows(self::rows());
        $submitted = WorkspaceRegistry::fromRows($rows, ['principal', 'metropolitana']);

        $this->assertSame([], WorkspaceRemoval::removedKeys($stored, $submitted));
    }

    public function testDeactivatingIsNotARemoval(): void
    {
        $rows              = self::rows();
        $rows[1]['is_active'] = false;
        $stored    = WorkspaceRegistry::fromRows(self::rows());
        $submitted = WorkspaceRegistry::fromRows($rows, ['principal', 'metropolitana']);

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
```

- [ ] **Step 3: Rodar e ver falhar**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml --filter WorkspaceRemovalTest`
Expected: `Class ... WorkspaceRemoval not found`.

- [ ] **Step 4: Implementar**

`src/Sso/WorkspaceRemoval.php`:

```php
<?php

// <cabeçalho padrão>

declare(strict_types=1);

namespace GlpiPlugin\Gac\Sso;

/** Which workspaces a configuration save would remove (spec S27). Pure. */
final class WorkspaceRemoval
{
    /**
     * The keys that are stored but missing from the submitted list. Renaming, deactivating and
     * adding never remove a key.
     *
     * @return list<string>
     */
    public static function removedKeys(WorkspaceRegistry $stored, WorkspaceRegistry $submitted): array
    {
        $kept = $submitted->keys();

        return array_values(array_filter(
            $stored->keys(),
            static fn (string $key): bool => !in_array($key, $kept, true)
        ));
    }
}
```

`src/Sso/WorkspaceRuleUsage.php`:

```php
<?php

// <cabeçalho padrão>

declare(strict_types=1);

namespace GlpiPlugin\Gac\Sso;

/** The authorization rules that refer to a workspace key (spec S27, S29). */
final class WorkspaceRuleUsage
{
    /**
     * The rules that use one of the keys in a "Workspace do Google" criterion, in any condition and
     * active or not (a rule can be switched on again later). A rule that is deleted no longer exists.
     *
     * @param list<string> $keys
     * @return list<array{rule_id: int, name: string, is_active: bool, key: string}>
     */
    public static function rulesUsing(array $keys): array
    {
        global $DB;

        if ($keys === []) {
            return [];
        }

        $found = [];
        foreach ($DB->request([
            'SELECT'     => ['glpi_rules.id AS rule_id', 'glpi_rules.name', 'glpi_rules.is_active', 'glpi_rulecriterias.pattern AS ws_key'],
            'FROM'       => 'glpi_rulecriterias',
            'INNER JOIN' => ['glpi_rules' => ['ON' => ['glpi_rulecriterias' => 'rules_id', 'glpi_rules' => 'id']]],
            'WHERE'      => [
                'glpi_rulecriterias.criteria' => RuleInput::WORKSPACE_CRITERION,
                'glpi_rulecriterias.pattern'  => $keys,
                'glpi_rules.sub_type'         => \RuleRight::class,
            ],
            'ORDER'      => ['glpi_rules.name', 'glpi_rules.id'],
        ]) as $row) {
            $found[(int) $row['rule_id'] . '|' . $row['ws_key']] = [
                'rule_id'   => (int) $row['rule_id'],
                'name'      => (string) $row['name'],
                'is_active' => (int) $row['is_active'] === 1,
                'key'       => (string) $row['ws_key'],
            ];
        }

        return array_values($found);
    }

    /** The workspace key the rule already has in a "Workspace do Google" criterion, if any. */
    public static function workspaceKeyOfRule(int $ruleId): ?string
    {
        global $DB;

        if ($ruleId <= 0) {
            return null;
        }

        foreach ($DB->request([
            'SELECT' => ['pattern'],
            'FROM'   => 'glpi_rulecriterias',
            'WHERE'  => ['rules_id' => $ruleId, 'criteria' => RuleInput::WORKSPACE_CRITERION],
            'ORDER'  => ['id'],
            'LIMIT'  => 1,
        ]) as $row) {
            $key = trim((string) $row['pattern']);

            return $key === '' ? null : $key;
        }

        return null;
    }
}
```

- [ ] **Step 5: Rodar e ver passar**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml --filter WorkspaceRemovalTest`
Expected: `OK (5 tests, ...)`.

- [ ] **Step 6: Ligar a trava no `handlePost`**

Em `src/Sso/SsoConfigSection.php`, logo depois do bloco que recusa domínios duplicados (o `if ($duplicated !== []) { ... return; }`) e antes do `foreach ($registry->all() as $workspace)`, insira:

```php
        // A workspace that authorization rules still use cannot be removed (spec S27): saving is
        // refused as a whole, so nothing is written halfway.
        $using = WorkspaceRuleUsage::rulesUsing(WorkspaceRemoval::removedKeys($current, $registry));
        if ($using !== []) {
            $lines = [];
            foreach ($using as $rule) {
                $lines[] = sprintf(
                    '<a href="%s">%s</a> (%s)',
                    htmlescape(\RuleRight::getFormURLWithID($rule['rule_id'])),
                    htmlescape($rule['name']),
                    htmlescape($rule['key'])
                );
            }
            Session::addMessageAfterRedirect(
                htmlescape(__('Nada foi salvo: há regras de autorização que usam um workspace que seria removido. Tire o critério "Workspace do Google" delas, ou mantenha o workspace:', 'gac'))
                    . '<br>' . implode('<br>', $lines),
                false,
                ERROR
            );

            return;
        }
```

(`Session::addMessageAfterRedirect` imprime a mensagem **sem escapar**, por isso os dois `htmlescape`.) Atualize o texto do `$note` do bloco de workspaces: `Para remover um, use "Limpar" e salve.` vira `Para remover um, use "Limpar" e salve; se alguma regra de autorização ainda usa o workspace, o GLPI recusa e lista as regras.`

- [ ] **Step 7: Script de integração da trava**

`tests/integration/workspace_removal_guard.php`:

```php
<?php

// Varredura das regras que usam um workspace e recusa do salvamento (spec S27).
// Cria regras GACTEST, guarda e restaura a configuração dos workspaces e apaga tudo no fim.
require __DIR__ . '/boot.php';

use GlpiPlugin\Gac\Sso\SsoConfig;
use GlpiPlugin\Gac\Sso\SsoConfigSection;
use GlpiPlugin\Gac\Sso\SsoSettings;
use GlpiPlugin\Gac\Sso\WorkspaceRuleUsage;

$ids      = [];
$original = SsoConfig::load()['sso_workspaces'];

try {
    $crit = static fn (string $key, int $cond = Rule::PATTERN_IS): array => [['GOOGLE_WORKSPACE', $cond, $key]];
    $act  = [['entities_id', 'assign', 0], ['profiles_id', 'assign', 1]];

    $active   = make_rule('ativa', $crit('gac-a'), $act, 9200);
    $inactive = make_rule('desativada', $crit('gac-a'), $act, 9201, 'RuleRight', false);
    $isNot    = make_rule('nao e', $crit('gac-a', Rule::PATTERN_IS_NOT), $act, 9202);
    $other    = make_rule('outra chave', $crit('gac-b'), $act, 9203);
    $ticket   = make_rule('outro tipo', $crit('gac-a'), [], 9204, 'RuleTicket');
    $deleted  = make_rule('apagada', $crit('gac-a'), $act, 9205);
    $ids      = [$active, $inactive, $isNot, $other, $ticket, $deleted];
    drop_rule($deleted);

    $idsFor = static fn (array $keys): array => array_values(array_unique(array_map(
        static fn (array $r): int => $r['rule_id'],
        WorkspaceRuleUsage::rulesUsing($keys)
    )));
    $sorted = static function (array $list): array { sort($list); return $list; };

    check($sorted($idsFor(['gac-a'])) === $sorted([$active, $inactive, $isNot]), 'gac-a: bloqueiam a ativa, a desativada e a de outra condição');
    check($idsFor(['gac-b']) === [$other], 'gac-b: só a regra da outra chave');
    check($idsFor(['gac-zzz']) === [], 'chave sem regra: nada');
    check(WorkspaceRuleUsage::rulesUsing([]) === [], 'lista vazia: nada');
    check(WorkspaceRuleUsage::workspaceKeyOfRule($active) === 'gac-a', 'workspaceKeyOfRule devolve a chave do critério');
    check(WorkspaceRuleUsage::workspaceKeyOfRule(0) === null, 'workspaceKeyOfRule(0) é null');

    // O salvamento: dois workspaces gravados, um deles usado por uma regra.
    $json = static fn (array $rows): string => json_encode($rows, JSON_UNESCAPED_UNICODE);
    $ws   = static fn (string $key): array => ['key' => $key, 'name' => 'T ' . $key, 'domains' => [$key . '.gactest.example'], 'admin_subject' => 'a@' . $key . '.gactest.example', 'is_active' => true];
    SsoConfig::save(['sso_workspaces' => $json([$ws('gac-a'), $ws('gac-b')])]);

    $post = static fn (array $keys): array => [
        'ws_key'     => $keys,
        'ws_name'    => array_map(static fn (string $k): string => 'T ' . $k, $keys),
        'ws_domains' => array_map(static fn (string $k): string => $k . '.gactest.example', $keys),
        'ws_admin'   => array_map(static fn (string $k): string => 'a@' . $k . '.gactest.example', $keys),
        'ws_active'  => array_fill(0, count($keys), '1'),
    ];

    $before = SsoConfig::load()['sso_workspaces'];
    (new SsoConfigSection())->handlePost($post(['gac-b']));       // remove gac-a, que tem regras
    check(SsoConfig::load()['sso_workspaces'] === $before, 'remoção de workspace em uso: nada foi gravado');

    (new SsoConfigSection())->handlePost($post(['gac-a']));       // remove gac-b, que tem 1 regra (outra chave)
    check(SsoConfig::load()['sso_workspaces'] === $before, 'remoção de gac-b (usado pela regra "outra chave"): nada foi gravado');

    foreach ([$active, $inactive, $isNot, $other, $ticket] as $id) {
        drop_rule($id);
    }
    $ids = [];
    (new SsoConfigSection())->handlePost($post(['gac-b']));       // agora nenhuma regra usa gac-a
    check(array_map(static fn ($w) => $w->key, SsoSettings::workspaces(SsoConfig::load())->all()) === ['gac-b'], 'sem regras: a remoção é gravada');
} finally {
    foreach ($ids as $id) {
        drop_rule($id);
    }
    SsoConfig::save(['sso_workspaces' => $original]);
    echo "configuração dos workspaces restaurada\n";
}

finish();
```

- [ ] **Step 8: Rodar**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml && /c/xampp/php/php.exe tests/integration/workspace_removal_guard.php`
Expected: suíte `OK`; o script imprime os 9 `OK`, `9 ok, 0 falharam` e a linha de restauração. Em seguida confira que a configuração voltou: `/c/xampp/php/php.exe var/tools/gac-eval.php 'echo implode(",", GlpiPlugin\Gac\Sso\SsoSettings::workspaces(GlpiPlugin\Gac\Sso\SsoConfig::load())->keys());'` deve imprimir `principal,metropolitana`.
Se `handlePost` não gravar nem no último caso, o direito de configuração não passou no CLI: rode o script com o usuário `glpiname` real (veja `canConfigure()`); não afrouxe a checagem de direito.

- [ ] **Step 9: Ponto de commit**

Arquivos: `src/Sso/WorkspaceRemoval.php`, `src/Sso/WorkspaceRuleUsage.php`, `src/Sso/SsoConfigSection.php`, `tests/Unit/WorkspaceRemovalTest.php`, `tests/integration/workspace_removal_guard.php`, a spec. Pare para o dono rodar `/commit` (sugestão: `feat(sso): block removing a workspace used by rules`).

---

### Task 5: OrgUnitList (leitura, rótulos, payload e cache; pura)

**Files:**
- Create: `src/Sso/OrgUnitList.php`, `tests/Unit/OrgUnitListTest.php`

**Interfaces:**
- Produces: `OrgUnitList::pathsFromApi(array $body): list<string>`; `OrgUnitList::label(string $workspaceName, string $path): string`; `OrgUnitList::payload(array $groups): array` onde cada grupo é `['key' => string, 'name' => string, 'paths' => ?list<string>, 'error' => string]` e o retorno é `['workspaces' => list<['key','name','error','ous' => list<['path','label','repeated']>]>]`; `OrgUnitList::pack(array $paths, int $now): array`; `OrgUnitList::unpack(mixed $data): ?array{at: int, paths: list<string>}`.

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php

// <cabeçalho padrão>

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
            ['orgUnitPath' => '/fimca-extra'],
            ['orgUnitPath' => '/Sistemas'],
            ['orgUnitPath' => '/fimca.com.br/IES-PVH'],
        ]]);

        $this->assertSame(
            ['/fimca.com.br', '/fimca.com.br/IES-PVH', '/fimca.com.br/IES-PVH/DTI', '/fimca-extra', '/Sistemas'],
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
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml --filter OrgUnitListTest`
Expected: `Class ... OrgUnitList not found`.

- [ ] **Step 3: Implementar**

```php
<?php

// <cabeçalho padrão>

declare(strict_types=1);

namespace GlpiPlugin\Gac\Sso;

/**
 * The org unit list of the workspaces (spec S28, S29): reads the Directory API answer, builds the
 * endpoint payload and the cache envelope. Pure.
 */
final class OrgUnitList
{
    private const CACHE_VERSION = 1;

    /**
     * The org unit paths of an `orgunits.list` answer, without the root, sorted so that a child
     * comes right after its parent. The Google's letter case is kept.
     *
     * @param array<string, mixed> $body
     * @return list<string>
     */
    public static function pathsFromApi(array $body): array
    {
        $units = $body['organizationUnits'] ?? null;
        if (!is_array($units)) {
            return [];
        }

        $paths = [];
        foreach ($units as $unit) {
            $path = is_array($unit) ? trim((string) ($unit['orgUnitPath'] ?? '')) : '';
            if ($path === '' || $path === '/') {
                continue;
            }
            if ($path[0] !== '/') {
                $path = '/' . $path;
            }
            $paths[$path] = true;
        }

        $list = array_keys($paths);
        // "/" sorts before every other character, so "/a/b" stays next to "/a" and not after "/a-b".
        usort($list, static fn (string $a, string $b): int => strcmp(
            strtr(mb_strtolower($a), '/', "\x01") . "\0" . $a,
            strtr(mb_strtolower($b), '/', "\x01") . "\0" . $b
        ));

        return $list;
    }

    /** What the picker shows: the workspace name, then the path (spec S29). */
    public static function label(string $workspaceName, string $path): string
    {
        return $workspaceName . ' - ' . $path;
    }

    /**
     * The JSON body of the orgunits endpoint. A workspace whose list could not be read keeps its
     * error and has no OUs, so one failure never hides the others.
     *
     * @param list<array{key: string, name: string, paths: ?list<string>, error: string}> $groups
     * @return array{workspaces: list<array{key: string, name: string, error: string, ous: list<array{path: string, label: string, repeated: bool}>}>}
     */
    public static function payload(array $groups): array
    {
        $count = [];
        foreach ($groups as $group) {
            foreach (array_unique(array_map('mb_strtolower', $group['paths'] ?? [])) as $lower) {
                $count[$lower] = ($count[$lower] ?? 0) + 1;
            }
        }

        $workspaces = [];
        foreach ($groups as $group) {
            $ous = [];
            foreach ($group['paths'] ?? [] as $path) {
                $ous[] = [
                    'path'     => $path,
                    'label'    => self::label($group['name'], $path),
                    'repeated' => ($count[mb_strtolower($path)] ?? 0) > 1,
                ];
            }
            $workspaces[] = ['key' => $group['key'], 'name' => $group['name'], 'error' => $group['error'], 'ous' => $ous];
        }

        return ['workspaces' => $workspaces];
    }

    /**
     * @param list<string> $paths
     * @return array{v: int, at: int, paths: list<string>}
     */
    public static function pack(array $paths, int $now): array
    {
        return ['v' => self::CACHE_VERSION, 'at' => $now, 'paths' => array_values($paths)];
    }

    /** @return ?array{at: int, paths: list<string>} null when the cached value is not a valid envelope */
    public static function unpack(mixed $data): ?array
    {
        if (!is_array($data) || ($data['v'] ?? null) !== self::CACHE_VERSION || !is_array($data['paths'] ?? null)) {
            return null;
        }

        $paths = [];
        foreach ($data['paths'] as $path) {
            if (!is_string($path) || $path === '' || $path[0] !== '/') {
                return null;
            }
            $paths[] = $path;
        }

        return ['at' => (int) ($data['at'] ?? 0), 'paths' => $paths];
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml`
Expected: `OK`. Se o teste de ordenação falhar, confira o `strtr` com `"\x01"` (aspas duplas) e que `mb_strtolower` está disponível (`php -m | grep mbstring`).

- [ ] **Step 5: Ponto de commit**

Arquivos: `src/Sso/OrgUnitList.php`, `tests/Unit/OrgUnitListTest.php`. Pare para o dono rodar `/commit` (sugestão: `feat(sso): add org unit list reader`).

---

### Task 6: Chamada ao Google, cache e endpoint

**Files:**
- Create: `src/Sso/GoogleServiceToken.php`, `src/Sso/OrgUnitDirectory.php`, `ajax/sso/orgunits.php`, `tests/integration/orgunits.php`
- Modify: `src/Sso/DirectoryClient.php`

**Interfaces:**
- Consumes: `OrgUnitList::*` (Task 5), `WorkspaceRuleUsage::workspaceKeyOfRule` (Task 4), `Workspace->key/name/adminSubject`.
- Produces: `GoogleServiceToken::fetch(\GuzzleHttp\Client $client, array $settings, string $adminSubject, array $scopes): string` (lança `SsoException`); `DirectoryClient::errorDetail(mixed $body): string` pública e estática; `OrgUnitDirectory::forWorkspace(array $settings, Workspace $workspace, bool $refresh): array{paths: ?list<string>, error: string}`; endpoint `GET /plugins/gac/ajax/sso/orgunits.php?rule_id=N&refresh=1` com resposta `{workspaces: [...], filter_workspace: ?string}`.

- [ ] **Step 1: Extrair o token**

`src/Sso/GoogleServiceToken.php`:

```php
<?php

// <cabeçalho padrão>

declare(strict_types=1);

namespace GlpiPlugin\Gac\Sso;

/**
 * An access token of the shared service account (spec S24), obtained with domain-wide delegation
 * for the given scopes while impersonating the workspace's read-only admin (spec S3, S28).
 */
final class GoogleServiceToken
{
    private const TOKEN_URI = 'https://oauth2.googleapis.com/token';

    /**
     * @param array<string, string> $settings    SsoConfig::load()
     * @param list<string>          $scopes
     * @throws SsoException
     */
    public static function fetch(\GuzzleHttp\Client $client, array $settings, string $adminSubject, array $scopes): string
    {
        $jwt = ServiceAccountJwt::build(
            SsoSettings::saClientEmail($settings),
            SsoSettings::saPrivateKey($settings),
            $adminSubject,
            $scopes,
            time()
        );
        if ($jwt === null) {
            throw new SsoException('Invalid service account private key');
        }

        try {
            $response = $client->post(self::TOKEN_URI, [
                'form_params' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion'  => $jwt,
                ],
                'timeout'     => 10,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            throw new SsoException('Service account token endpoint unreachable: ' . $e->getMessage());
        }

        $body = json_decode((string) $response->getBody(), true);
        if ($response->getStatusCode() !== 200 || !is_array($body) || !is_string($body['access_token'] ?? null)) {
            $error = is_array($body) ? (string) ($body['error'] ?? '') . ' ' . (string) ($body['error_description'] ?? '') : '';
            throw new SsoException('Service account token request failed: HTTP ' . $response->getStatusCode() . ' ' . trim($error));
        }

        return $body['access_token'];
    }
}
```

Em `src/Sso/DirectoryClient.php`: apague a constante `TOKEN_URI`, troque o corpo de `accessToken()` por

```php
    /** @throws SsoException */
    private function accessToken(\GuzzleHttp\Client $client): string
    {
        return GoogleServiceToken::fetch($client, $this->settings, $this->adminSubject, self::SCOPES);
    }
```

e troque `private static function errorDetail(` por `public static function errorDetail(`.

Run: `/c/xampp/php/php.exe -l src/Sso/DirectoryClient.php && /c/xampp/php/php.exe -l src/Sso/GoogleServiceToken.php`
Expected: sem erros de sintaxe. Em seguida, no GLPI local, o teste a seco ainda tem que funcionar (ele usa o `DirectoryClient`): `/c/xampp/php/php.exe var/tools/gac-eval.php 'echo json_encode(GlpiPlugin\Gac\Sso\DryRun::run("SEU_EMAIL_REAL@fimca.com.br"));'` (use um e-mail real do Principal). Expected: `"outcome":"ok"` ou o mesmo resultado que dava antes, com a OU lida.

- [ ] **Step 2: `OrgUnitDirectory`**

```php
<?php

// <cabeçalho padrão>

declare(strict_types=1);

namespace GlpiPlugin\Gac\Sso;

/**
 * The org unit list of a workspace, read from the Directory API and cached (spec S28). It asks for
 * the orgunit.readonly scope only here: the login keeps using the user scope alone (spec S3).
 */
final class OrgUnitDirectory
{
    public const TTL = 600;

    private const URI    = 'https://admin.googleapis.com/admin/directory/v1/customer/my_customer/orgunits';
    private const SCOPES = ['https://www.googleapis.com/auth/admin.directory.orgunit.readonly'];

    /**
     * @param array<string, string> $settings SsoConfig::load()
     * @return array{paths: ?list<string>, error: string} paths is null when the list could not be read
     */
    public static function forWorkspace(array $settings, Workspace $workspace, bool $refresh): array
    {
        global $GLPI_CACHE;

        $cacheKey = 'gac_sso_orgunits_' . $workspace->key;
        if (!$refresh) {
            $hit = OrgUnitList::unpack($GLPI_CACHE->get($cacheKey));
            if ($hit !== null) {
                return ['paths' => $hit['paths'], 'error' => ''];
            }
        }

        try {
            $paths = self::fetchPaths($settings, $workspace->adminSubject);
        } catch (SsoException $e) {
            \Toolbox::logInFile('gac', 'sso orgunits (' . $workspace->key . '): ' . $e->getMessage() . "\n");

            return ['paths' => null, 'error' => $e->getMessage()];
        }

        $GLPI_CACHE->set($cacheKey, OrgUnitList::pack($paths, time()), self::TTL);

        return ['paths' => $paths, 'error' => ''];
    }

    /**
     * @param array<string, string> $settings
     * @return list<string>
     * @throws SsoException
     */
    public static function fetchPaths(array $settings, string $adminSubject): array
    {
        $client = \Toolbox::getGuzzleClient();
        $token  = GoogleServiceToken::fetch($client, $settings, $adminSubject, self::SCOPES);

        try {
            $response = $client->get(self::URI, [
                'headers'     => ['Authorization' => 'Bearer ' . $token],
                'query'       => ['type' => 'all'],
                'timeout'     => 15,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            throw new SsoException('Directory API unreachable: ' . $e->getMessage());
        }

        $status = $response->getStatusCode();
        $body   = json_decode((string) $response->getBody(), true);
        if ($status !== 200 || !is_array($body)) {
            throw new SsoException('Directory API returned HTTP ' . $status . DirectoryClient::errorDetail($body));
        }

        return OrgUnitList::pathsFromApi($body);
    }
}
```

Run: `/c/xampp/php/php.exe -l src/Sso/OrgUnitDirectory.php`. Expected: sem erros.

- [ ] **Step 3: Endpoint**

`ajax/sso/orgunits.php` (comece com as mesmas linhas de cabeçalho de `ajax/sso/dry_run.php`, linhas 1 a 33; elas incluem o aviso `// Somente leitura, GET: não precisa de Session::checkCSRF().`):

```php
use GlpiPlugin\Gac\Features;
use GlpiPlugin\Gac\Sso\OrgUnitDirectory;
use GlpiPlugin\Gac\Sso\OrgUnitList;
use GlpiPlugin\Gac\Sso\SsoConfig;
use GlpiPlugin\Gac\Sso\SsoIdentity;
use GlpiPlugin\Gac\Sso\SsoSettings;
use GlpiPlugin\Gac\Sso\WorkspaceRuleUsage;

header('Content-Type: application/json; charset=utf-8');

// Used by the rule screen (who can read authorization rules) and by the SSO configuration.
$canRules  = Session::haveRight(RuleRight::$rightname, READ);
$canConfig = Features::canConfigure(SsoIdentity::$rightname);
if (!$canRules && !$canConfig) {
    http_response_code(403);
    echo json_encode(['error' => __('Acesso negado.', 'gac')], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $settings = SsoConfig::load();
    $refresh  = !empty($_GET['refresh']);

    $groups = [];
    foreach (SsoSettings::workspaces($settings)->usable() as $workspace) {
        $read     = OrgUnitDirectory::forWorkspace($settings, $workspace, $refresh);
        $groups[] = ['key' => $workspace->key, 'name' => $workspace->name, 'paths' => $read['paths'], 'error' => $read['error']];
    }

    $body = OrgUnitList::payload($groups);
    // The workspace of the rule being edited narrows the OU options (spec S29).
    $body['filter_workspace'] = $canRules ? WorkspaceRuleUsage::workspaceKeyOfRule((int) ($_GET['rule_id'] ?? 0)) : null;

    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (\Throwable $e) {
    Toolbox::logInFile('gac', 'sso orgunits.php: ' . $e->getMessage() . "\n");
    http_response_code(500);
    echo json_encode(['error' => __('Erro ao listar as OUs.', 'gac')], JSON_UNESCAPED_UNICODE);
}
```

(O texto de erro de cada workspace é só o que `DirectoryClient::errorDetail` já filtra: razão e mensagem curta do Google, sem token nem chave.)

- [ ] **Step 4: Script de integração**

`tests/integration/orgunits.php`:

```php
<?php

// Lista de OUs de cada workspace usável: leitura real no Google (spec S28), cache e falha isolada.
require __DIR__ . '/boot.php';

use GlpiPlugin\Gac\Sso\OrgUnitDirectory;
use GlpiPlugin\Gac\Sso\OrgUnitList;
use GlpiPlugin\Gac\Sso\SsoConfig;
use GlpiPlugin\Gac\Sso\SsoSettings;
use GlpiPlugin\Gac\Sso\Workspace;

global $GLPI_CACHE;

$settings = SsoConfig::load();
foreach (SsoSettings::workspaces($settings)->usable() as $workspace) {
    $GLPI_CACHE->delete('gac_sso_orgunits_' . $workspace->key);

    $t0    = microtime(true);
    $first = OrgUnitDirectory::forWorkspace($settings, $workspace, false);
    $ms    = (int) ((microtime(true) - $t0) * 1000);

    if ($first['paths'] === null) {
        // Escopo não delegado ou papel sem leitura de OUs naquele workspace: avisa, não derruba.
        echo "AVISO  {$workspace->key}: {$first['error']}\n";
        continue;
    }

    check($first['paths'] !== [], "{$workspace->key}: " . count($first['paths']) . " OUs lidas em {$ms} ms");
    check(!in_array('/', $first['paths'], true) && $first['paths'] === array_values(array_unique($first['paths'])), "{$workspace->key}: sem raiz e sem repetidas");

    $t0     = microtime(true);
    $second = OrgUnitDirectory::forWorkspace($settings, $workspace, false);
    $cached = (int) ((microtime(true) - $t0) * 1000);
    check($second['paths'] === $first['paths'] && $cached < 100, "{$workspace->key}: segunda leitura veio do cache ({$cached} ms)");
    check(OrgUnitList::unpack($GLPI_CACHE->get('gac_sso_orgunits_' . $workspace->key)) !== null, "{$workspace->key}: envelope do cache válido");

    $forced = OrgUnitDirectory::forWorkspace($settings, $workspace, true);
    check($forced['paths'] === $first['paths'], "{$workspace->key}: \"atualizar\" ignora o cache e devolve a mesma lista");
}

// Um workspace com administrador inexistente falha sozinho, sem exceção.
$bogus  = new Workspace('gac-bogus', 'Bogus', ['bogus.example'], 'ninguem@bogus.example', true);
$result = OrgUnitDirectory::forWorkspace($settings, $bogus, true);
check($result['paths'] === null && $result['error'] !== '', 'administrador inexistente: erro legível, sem exceção (' . $result['error'] . ')');
check($GLPI_CACHE->get('gac_sso_orgunits_gac-bogus') === null, 'falha não é guardada no cache');
check(!str_contains($result['error'], 'PRIVATE KEY') && !str_contains($result['error'], 'access_token'), 'a mensagem de erro não vaza chave nem token');

finish();
```

Run: `/c/xampp/php/php.exe tests/integration/orgunits.php`
Expected: para o **Principal**, linhas `OK` (91 OUs, cerca de 1 a 2 s na primeira leitura, cache abaixo de 100 ms); para a **Metropolitana**, `OK` se o escopo e o papel já estiverem configurados lá, ou `AVISO Metropolitana: ... HTTP 401` (isso é a verificação **V19** da spec: se for o caso, avise o dono para autorizar o escopo `admin.directory.orgunit.readonly` e dar ao papel o privilégio "Unidades organizacionais: Ler" no Admin Console dela; não é falha do código). O final deve ser `0 falharam`.

- [ ] **Step 5: Conferir o endpoint**

Sem sessão: `curl -s -o /dev/null -w "%{http_code}\n" --ssl-no-revoke http://localhost:8080/plugins/gac/ajax/sso/orgunits.php`
Expected: um código que não seja 200 (redirecionamento ao login ou 401/403).

Com sessão, no navegador logado como super-admin em `http://localhost:8080/`, no console do DevTools:

```js
fetch(CFG_GLPI.root_doc + '/plugins/gac/ajax/sso/orgunits.php?rule_id=0').then(r => r.text()).then(t => {
    console.log(JSON.parse(t).workspaces.map(w => [w.key, w.error, w.ous.length]));
    console.log('vaza segredo?', /PRIVATE KEY|access_token|private_key|glpi-serviceuser|iam\.gserviceaccount/i.test(t));
});
```

Expected: uma linha por workspace usável (`['principal', '', 91]`) e `vaza segredo? false`. Repita com `?refresh=1` e confira que continua válido.

- [ ] **Step 6: Ponto de commit**

Arquivos: `src/Sso/GoogleServiceToken.php`, `src/Sso/DirectoryClient.php`, `src/Sso/OrgUnitDirectory.php`, `ajax/sso/orgunits.php`, `tests/integration/orgunits.php`. Pare para o dono rodar `/commit` (sugestão: `feat(sso): list org units from the Directory API`).

---

### Task 7: Funções puras do seletor (JS) e testes

**Files:**
- Create: `public/js/sso-ou-picker.js`, `tests/js/sso-ou-picker.test.js`

**Interfaces:**
- Produces (exportadas por `module.exports` e em `window.GacSsoPicker`): `escapeHtml(value): string`, `optionLabel(workspaceName, path, repeated): string`, `buildOptions(payload, onlyWorkspaceKey): Array<{id, text, workspace}>`, `appendPathLine(text, path): string`.

- [ ] **Step 1: Escrever o teste que falha**

`tests/js/sso-ou-picker.test.js`:

```js
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const picker = require('../../public/js/sso-ou-picker.js');

const payload = {
    workspaces: [
        { key: 'principal', name: 'Principal', error: '', ous: [
            { path: '/Sistemas', label: 'Principal - /Sistemas', repeated: true },
            { path: '/fimca.com.br', label: 'Principal - /fimca.com.br', repeated: false },
        ] },
        { key: 'metropolitana', name: 'Metropolitana', error: '', ous: [
            { path: '/sistemas', label: 'Metropolitana - /sistemas', repeated: true },
        ] },
        { key: 'quebrado', name: 'Quebrado', error: 'HTTP 401', ous: [] },
    ],
};

test('optionLabel puts the workspace name before the path', () => {
    assert.equal(picker.optionLabel('Principal', '/a/b', false), 'Principal - /a/b');
});

test('optionLabel warns when the path exists in more than one workspace', () => {
    assert.equal(picker.optionLabel('Principal', '/a', true), 'Principal - /a (repetida)');
});

test('buildOptions stores only the path as the value', () => {
    const options = picker.buildOptions(payload, '');

    assert.deepEqual(options[0], { id: '/Sistemas', text: 'Principal - /Sistemas (repetida)', workspace: 'principal' });
    assert.equal(options[1].id, '/fimca.com.br');
    assert.ok(options.every((o) => o.id.startsWith('/') && !o.id.includes(' - ')));
});

test('buildOptions skips workspaces that failed', () => {
    const options = picker.buildOptions(payload, '');

    assert.equal(options.length, 3);
    assert.ok(!options.some((o) => o.workspace === 'quebrado'));
});

test('buildOptions narrows to one workspace when asked', () => {
    const options = picker.buildOptions(payload, 'metropolitana');

    assert.deepEqual(options.map((o) => o.id), ['/sistemas']);
});

test('buildOptions copes with an empty or broken payload', () => {
    assert.deepEqual(picker.buildOptions(null, ''), []);
    assert.deepEqual(picker.buildOptions({}, ''), []);
    assert.deepEqual(picker.buildOptions({ workspaces: [{ key: 'a', name: 'A' }] }, ''), []);
});

test('appendPathLine adds the path as a new line', () => {
    assert.equal(picker.appendPathLine('', '/a'), '/a');
    assert.equal(picker.appendPathLine('/a\n/b', '/c'), '/a\n/b\n/c');
});

test('appendPathLine does not leave a blank line when the text ends with a newline', () => {
    assert.equal(picker.appendPathLine('/a\n/b\n', '/c'), '/a\n/b\n/c');
    assert.equal(picker.appendPathLine('/a\r\n/b\r\n', '/c'), '/a\r\n/b\n/c');
});

test('appendPathLine does not duplicate a line, ignoring case and spaces', () => {
    assert.equal(picker.appendPathLine('/Fimca/Docentes', '/fimca/docentes'), '/Fimca/Docentes');
    assert.equal(picker.appendPathLine('  /a  \n/b', '/a'), '  /a  \n/b');
});

test('appendPathLine does not treat a comment line as an existing entry', () => {
    assert.equal(picker.appendPathLine('# /a', '/a'), '# /a\n/a');
});

test('appendPathLine keeps what was typed by hand and ignores an empty path', () => {
    assert.equal(picker.appendPathLine('# docentes\n/x', '/y'), '# docentes\n/x\n/y');
    assert.equal(picker.appendPathLine('/x', '   '), '/x');
});

test('escapeHtml neutralises quotes, angle brackets and ampersands', () => {
    assert.equal(picker.escapeHtml('<b a="1" b=\'2\'>&'), '&lt;b a=&quot;1&quot; b=&#39;2&#39;&gt;&amp;');
    assert.equal(picker.escapeHtml('/Ana & "Bia"'), '/Ana &amp; &quot;Bia&quot;');
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `node --test "tests/js/*.test.js"`
Expected: erro `Cannot find module '../../public/js/sso-ou-picker.js'`.

- [ ] **Step 3: Implementar só a parte pura**

`public/js/sso-ou-picker.js`:

```js
/**
 * Pickers of Google org units and workspaces for the SSO module (spec S29, S30).
 *
 * The first half is pure (no DOM) and is unit tested with `node --test "tests/js/*.test.js"`. The second half
 * (added in the next tasks) wires it to the GLPI authorization rule form and to the plugin
 * configuration page.
 */
(function (root) {
    'use strict';

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    /** The text of an option: the workspace name, then the path. The stored value is only the path. */
    function optionLabel(workspaceName, path, repeated) {
        return workspaceName + ' - ' + path + (repeated ? ' (repetida)' : '');
    }

    /**
     * @param {{workspaces: Array}} payload the orgunits endpoint answer
     * @param {string} onlyWorkspaceKey narrows to one workspace; empty keeps all
     * @returns {Array<{id: string, text: string, workspace: string}>}
     */
    function buildOptions(payload, onlyWorkspaceKey) {
        var options = [];
        ((payload && payload.workspaces) || []).forEach(function (workspace) {
            if (workspace.error) { return; }
            if (onlyWorkspaceKey && workspace.key !== onlyWorkspaceKey) { return; }
            (workspace.ous || []).forEach(function (ou) {
                options.push({
                    id: ou.path,
                    text: optionLabel(workspace.name, ou.path, ou.repeated),
                    workspace: workspace.key
                });
            });
        });

        return options;
    }

    /** Adds a path as a new line of the blocked OUs text, unless a line already has it. */
    function appendPathLine(text, path) {
        var wanted = String(path).trim();
        var current = String(text);
        if (wanted === '') { return current; }

        var lower = wanted.toLowerCase();
        var present = current.split(/\r?\n/).some(function (line) {
            var trimmed = line.trim();
            return trimmed !== '' && trimmed.charAt(0) !== '#' && trimmed.toLowerCase() === lower;
        });
        if (present) { return current; }

        var base = current.replace(/\s+$/, '');

        return (base === '' ? '' : base + '\n') + wanted;
    }

    var api = {
        escapeHtml: escapeHtml,
        optionLabel: optionLabel,
        buildOptions: buildOptions,
        appendPathLine: appendPathLine
    };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
    root.GacSsoPicker = api;
})(typeof window !== 'undefined' ? window : globalThis);
```

- [ ] **Step 4: Rodar e ver passar**

Run: `node --test "tests/js/*.test.js"`
Expected: todos os testes `pass`, `fail 0`.

- [ ] **Step 5: Ponto de commit**

Arquivos: `public/js/sso-ou-picker.js`, `tests/js/sso-ou-picker.test.js`. Pare para o dono rodar `/commit` (sugestão: `feat(sso): add pure helpers of the org unit picker`).

---

### Task 8: Seletor na tela de regras

**Files:**
- Modify: `public/js/sso-ou-picker.js`, `setup.php`

**Interfaces:**
- Consumes: `buildOptions`, `escapeHtml` (Task 7), endpoint `orgunits.php` (Task 6; `filter_workspace` na resposta).
- Produces: nenhum export novo; a cola de DOM roda sozinha na página.

- [ ] **Step 1: Registrar o JS**

Em `setup.php`, a linha 73 `$PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['gac'] = 'js/pre.js';` vira:

```php
        $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['gac'] = ['js/pre.js', 'js/sso-ou-picker.js'];
```

Confirme que o GLPI aceita lista: `grep -n "ADD_JAVASCRIPT" -A 8 /c/Users/juliano/VSCode/glpi-xampp-dev-plugin/src/Html.php | head -30` deve mostrar o tratamento de valor em array (se o GLPI só aceitar string, registre `js/sso-ou-picker.js` carregado a partir de `pre.js`; não avance sem resolver).

- [ ] **Step 2: Cola de DOM da tela de regras**

Em `public/js/sso-ou-picker.js`, antes do bloco `var api = {` (e mantendo o `use strict`), acrescente:

```js
    // ---- DOM wiring (not unit tested: browser checklist in docs/sso-manual-tests.md) ----

    var TEXT = {
        placeholderOu: 'Escolha uma OU ou digite o caminho',
        refresh: 'Atualizar a lista de OUs',
        unavailable: 'Lista de OUs indisponível agora: digite o caminho da OU.',
        chooseWorkspace: 'Escolha o workspace'
    };

    var cache = {};

    function hasSelect2() {
        return !!(root.jQuery && root.jQuery.fn && root.jQuery.fn.select2);
    }

    function endpoint() {
        return ((root.CFG_GLPI && root.CFG_GLPI.root_doc) || '') + '/plugins/gac/ajax/sso/orgunits.php';
    }

    function fetchData(ruleId, refresh) {
        var key = String(ruleId || 0);
        if (!refresh && cache[key]) { return cache[key]; }

        var url = endpoint() + '?rule_id=' + encodeURIComponent(key) + (refresh ? '&refresh=1' : '');
        cache[key] = root.fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (response) {
                if (!response.ok) { throw new Error('http ' + response.status); }
                return response.json();
            })
            .catch(function (error) {
                delete cache[key];
                throw error;
            });

        return cache[key];
    }

    function currentRuleId() {
        var field = root.document.querySelector('input[name="rules_id"]');
        return field ? (parseInt(field.value, 10) || 0) : 0;
    }

    function showUnavailable(input) {
        if (!input.isConnected || input.parentNode.querySelector('.gac-picker-note')) { return; }
        var note = root.document.createElement('div');
        note.className = 'form-text gac-picker-note';
        note.textContent = TEXT.unavailable;
        input.insertAdjacentElement('afterend', note);
    }

    /** Fills a select with an empty option, the options and, if missing, the value already saved. */
    function fillSelect(select, options, current) {
        select.innerHTML = '';
        select.add(new Option('', '', false, current === ''));
        var seen = false;
        options.forEach(function (option) {
            var selected = option.id === current;
            seen = seen || selected;
            select.add(new Option(option.text, option.id, false, selected));
        });
        if (current !== '' && !seen) {
            select.add(new Option(current, current, true, true));
        }
    }

    function buildOuSelect(input, payload, rid) {
        var current = input.value;
        var wrapper = root.document.createElement('div');
        wrapper.className = 'd-flex gap-1 w-100 align-items-start';
        var select = root.document.createElement('select');
        select.name = input.name;
        select.className = 'form-select';
        wrapper.appendChild(select);

        var narrowed = function (data) { return data.filter_workspace || ''; };
        fillSelect(select, buildOptions(payload, narrowed(payload)), current);
        input.replaceWith(wrapper);

        var $ = root.jQuery;
        $(select).select2({
            width: '100%',
            tags: true,
            placeholder: TEXT.placeholderOu,
            createTag: function (params) {
                var term = String(params.term || '').trim();
                return term === '' ? null : { id: term, text: term };
            }
        });

        var button = root.document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-sm btn-ghost-secondary';
        button.title = TEXT.refresh;
        button.setAttribute('aria-label', TEXT.refresh);
        button.innerHTML = '<i class="ti ti-refresh"></i>';
        button.addEventListener('click', function () {
            button.disabled = true;
            fetchData(rid, true)
                .then(function (fresh) {
                    fillSelect(select, buildOptions(fresh, narrowed(fresh)), select.value);
                    $(select).trigger('change.select2');
                })
                .catch(function () {})
                .then(function () { button.disabled = false; });
        });
        wrapper.appendChild(button);
    }

    function buildWorkspaceSelect(input, payload) {
        var current = input.value;
        var select = root.document.createElement('select');
        select.name = input.name;
        select.className = 'form-select';
        select.required = true;
        select.add(new Option(TEXT.chooseWorkspace, '', current === '', current === ''));
        var seen = false;
        (payload.workspaces || []).forEach(function (workspace) {
            var selected = workspace.key === current;
            seen = seen || selected;
            select.add(new Option(workspace.name + ' (' + workspace.key + ')', workspace.key, false, selected));
        });
        if (current !== '' && !seen) {
            select.add(new Option(current, current, true, true));
        }
        input.replaceWith(select);
    }

    function enhancePattern(input, criterion) {
        input.setAttribute('data-gac-picker', '1');
        var rid = currentRuleId();

        fetchData(rid, false)
            .then(function (payload) {
                if (!input.isConnected) { return; }
                if (criterion === 'GOOGLE_WORKSPACE') {
                    buildWorkspaceSelect(input, payload);
                } else {
                    buildOuSelect(input, payload, rid);
                }
            })
            .catch(function () { showUnavailable(input); });
    }

    function enhanceRuleForm() {
        var criteria = root.document.querySelector('select[name="criteria"]');
        var input = root.document.querySelector('input[name="pattern"]');
        if (!criteria || !input || input.getAttribute('data-gac-picker')) { return; }

        var criterion = criteria.value;
        if (criterion !== 'GOOGLE_OU' && criterion !== 'GOOGLE_WORKSPACE') { return; }
        if (!hasSelect2()) { return; }

        enhancePattern(input, criterion);
    }

    function scan() {
        enhanceRuleForm();
    }

    if (typeof document !== 'undefined') {
        var scheduled = false;
        var schedule = function () {
            if (scheduled) { return; }
            scheduled = true;
            setTimeout(function () { scheduled = false; scan(); }, 0);
        };
        document.addEventListener('DOMContentLoaded', schedule);
        new MutationObserver(schedule).observe(document.documentElement, { childList: true, subtree: true });
        schedule();
    }
```

(A palavra `Option` e `select.add(new Option(...))` são DOM padrão; não são exercitados pelo Node.)

- [ ] **Step 3: Os testes puros continuam passando**

Run: `node --test "tests/js/*.test.js"`
Expected: `fail 0` (a cola está dentro de `if (typeof document !== 'undefined')` e `root = globalThis`, então nada dela roda no Node; se `ReferenceError` aparecer, `Option`, `MutationObserver` ou `setTimeout` estão fora dessa guarda).

- [ ] **Step 4: Verificação no navegador (V17, V18)**

1. Ctrl+F5 em `http://localhost:8080/` (JS em cache de 30 dias).
2. Administração > Regras > Regras de autorização > abra uma regra > Critérios > Adicionar. Escolha **"OU do Google Workspace"**: o campo do valor vira um seletor com busca; digite `docentes` e confira as opções "Principal - /…" e "Metropolitana - /…". Escolha uma e **salve**: a lista de critérios da regra deve mostrar **só o caminho** (`/fimca.com.br/IES-PVH/…`), nunca "Principal - …".
3. Repita digitando um caminho que não está na lista (texto livre) e salve: tem que aceitar.
4. Troque a condição e depois o critério para outro (ex.: "Nome") e de volta: o campo certo reaparece e o de texto normal volta nos outros critérios.
5. Escolha **"Workspace do Google"**: aparece um `<select>` com "Principal (principal)" e "Metropolitana (metropolitana)", com a condição "é" apenas. Salve e confira que o valor gravado é a **chave** (`principal`).
6. Edite o critério já gravado (clique nele): o seletor abre **com o valor preenchido**. Numa regra que já tem o critério de workspace `principal`, ao adicionar um critério de OU, as opções vêm **só do Principal** (`filter_workspace`).
7. Botão de atualizar (ícone ao lado do seletor): recarrega a lista sem recarregar a página.
8. Fallback: em `http://localhost:8080/`, com o endpoint indisponível (renomeie temporariamente `ajax/sso/orgunits.php` para `.off`), o campo de texto original permanece e aparece "Lista de OUs indisponível agora…". **Devolva o nome do arquivo.**
9. Abra uma regra de outro tipo (ex.: Regras de negócio de chamados) e confira que nenhum seletor aparece.
10. Se algum passo falhar porque o GLPI usa outro nome de campo (por exemplo `criteria` ou `rules_id` não existirem), inspecione o DOM, ajuste só os seletores em `enhanceRuleForm()`/`currentRuleId()` e registre o que mudou na V18 da spec.

Registre o resultado de cada passo (data e OK/FALHOU) para a Task 10.

- [ ] **Step 5: Ponto de commit**

Arquivos: `public/js/sso-ou-picker.js`, `setup.php`. Pare para o dono rodar `/commit` (sugestão: `feat(sso): add org unit and workspace pickers to the rule form`).

---

### Task 9: Seletor "Adicionar OU" nas OUs bloqueadas e textos da configuração

**Files:**
- Modify: `public/js/sso-ou-picker.js`, `src/Sso/SsoConfigSection.php`
- Test: `tests/Unit/OuBlocklistTest.php`

**Interfaces:**
- Consumes: `appendPathLine`, `buildOptions`, `fetchData` (Tasks 7 e 8).

- [ ] **Step 1: Teste do texto das bloqueadas (PHP)**

Acrescente a `tests/Unit/OuBlocklistTest.php`, antes do `}` final:

```php
    public function testTextWithLinesAppendedByTheOuPickerGivesTheSameSet(): void
    {
        $byHand = OuBlocklist::fromText("/Fimca/Docentes\n# professores da Metropolitana\n/Metro/Professores");
        $picker = OuBlocklist::fromText("/Fimca/Docentes\n# professores da Metropolitana\n/Metro/Professores\n/fimca/docentes\n/Sistemas");

        $this->assertSame(['/fimca/docentes', '/metro/professores'], $byHand->paths());
        $this->assertSame(['/fimca/docentes', '/metro/professores', '/sistemas'], $picker->paths(), 'a repeated line in another case is one path');
    }
```

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml --filter OuBlocklistTest`
Expected: `OK`. (Este teste já passa com o código atual: ele fixa o contrato de que o formato do texto não muda.)

- [ ] **Step 2: Marcação do seletor na configuração**

Em `src/Sso/SsoConfigSection.php`, no bloco "Regras e bloqueios" (linha 126 a 128), troque o controle da linha "OUs bloqueadas" por:

```php
                $this->blockedPicker() . $this->textarea('sso_blocked_ou_paths', (string) $s['sso_blocked_ou_paths'], 4),
```

e acrescente o método:

```php
    /** Where the JS (public/js/sso-ou-picker.js) puts the "Adicionar OU" selector (spec S30). */
    private function blockedPicker(): string
    {
        return "<div id='gac-sso-blocked-picker' class='mb-2'></div>"
            . "<div class='form-text mb-2'>" . htmlescape(__('A OU escolhida é acrescentada ao texto abaixo. O bloqueio vale para o caminho em todos os workspaces.', 'gac')) . '</div>';
    }
```

- [ ] **Step 3: Cola de DOM**

Em `public/js/sso-ou-picker.js`, antes de `function scan()`, acrescente:

```js
    function initBlockedPicker() {
        var host = root.document.getElementById('gac-sso-blocked-picker');
        var area = root.document.querySelector('textarea[name="sso_blocked_ou_paths"]');
        if (!host || !area || host.getAttribute('data-ready') || !hasSelect2()) { return; }
        host.setAttribute('data-ready', '1');

        fetchData(0, false)
            .then(function (payload) {
                var select = root.document.createElement('select');
                select.className = 'form-select';
                fillSelect(select, buildOptions(payload, ''), '');
                host.appendChild(select);

                var $ = root.jQuery;
                $(select).select2({ width: '100%', placeholder: TEXT.placeholderBlocked, allowClear: false });
                $(select).on('select2:select', function (event) {
                    area.value = appendPathLine(area.value, event.params.data.id);
                    area.dispatchEvent(new Event('input', { bubbles: true }));
                    $(select).val('').trigger('change');
                });
            })
            .catch(function () {
                var note = root.document.createElement('div');
                note.className = 'form-text';
                note.textContent = TEXT.unavailable;
                host.appendChild(note);
            });
    }
```

Acrescente `placeholderBlocked: 'Adicionar uma OU à lista de bloqueio...'` ao objeto `TEXT` e troque `function scan() { enhanceRuleForm(); }` por:

```js
    function scan() {
        enhanceRuleForm();
        initBlockedPicker();
    }
```

- [ ] **Step 4: Texto do administrador e dos escopos (S28)**

Em `src/Sso/SsoConfigSection.php`:
- Linha 107 (texto da conta de serviço): substitua `com somente o escopo admin.directory.user.readonly, autorizada no Admin Console de cada workspace` por `com os escopos admin.directory.user.readonly (login) e admin.directory.orgunit.readonly (lista de OUs nos seletores), autorizados no Admin Console de cada workspace`.
- Na coluna "Administrador do Google" do `th()` (linha 237): troque `Precisa ser super administrador; o acesso é só de leitura.` por `Pode ser um administrador delegado, com um papel que leia usuários e unidades organizacionais (Unidades organizacionais: Ler). O acesso é só de leitura.`

- [ ] **Step 5: Rodar**

Run: `/c/xampp/php/php.exe -l src/Sso/SsoConfigSection.php && /c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml && node --test "tests/js/*.test.js"`
Expected: sintaxe OK, suíte `OK`, JS `fail 0`.

- [ ] **Step 6: Verificação no navegador (V22)**

Ctrl+F5 na configuração do SSO (aba "Regras e bloqueios"). Acima do texto das OUs bloqueadas aparece o seletor com a ajuda sobre "todos os workspaces". Escolha uma OU: o caminho entra **como nova linha**, o seletor volta ao vazio, e escolher a mesma OU de novo **não duplica**. Digite uma linha à mão e um comentário (`# teste`), escolha outra OU: nada do que foi digitado some. Salve e confira com `/c/xampp/php/php.exe var/tools/gac-eval.php 'echo GlpiPlugin\Gac\Sso\SsoConfig::load()["sso_blocked_ou_paths"];'`. **Restaure o texto original das bloqueadas** depois do teste. Com o endpoint indisponível, só o texto fica, com o aviso.

- [ ] **Step 7: Ponto de commit**

Arquivos: `public/js/sso-ou-picker.js`, `src/Sso/SsoConfigSection.php`, `tests/Unit/OuBlocklistTest.php`. Pare para o dono rodar `/commit` (sugestão: `feat(sso): add org unit selector to the blocked OUs field`).

---

### Task 10: Documentação, roteiro de testes e regressão final

**Files:**
- Modify: `docs/sso-manual-tests.md`, `CLAUDE.md`, `docs/superpowers/specs/2026-10-05-sso-google-design.md`

- [ ] **Step 1: Roteiro do navegador**

Em `docs/sso-manual-tests.md`, depois da linha 35 da tabela (cenário 35), acrescente as linhas abaixo (a coluna **Resultado** recebe a data e o resultado reais dos passos feitos na Task 8, 9 e nos scripts; o que não foi executado fica `—`):

```markdown
| 36 | Chave do workspace | Configuração > Workspaces; renomear um workspace e salvar | A chave ("Chave: principal") não muda; o nome muda | — |
| 37 | Critério "Workspace do Google" | Regras de autorização > Critérios > Adicionar > "Workspace do Google" | Só a condição "é"; o valor vira um seletor com os nomes; a regra grava a **chave** | — |
| 38 | Seletor de OU | Critérios > "OU do Google Workspace" | Seletor com busca, opções "Workspace - caminho"; a regra grava só o caminho; texto livre aceito; editar um critério gravado abre com o valor | — |
| 39 | OU filtrada pelo workspace da regra | Regra que já tem o critério de workspace `principal`; adicionar critério de OU | Só OUs do Principal | — |
| 40 | Lista de OUs indisponível | Tornar `orgunits.php` indisponível | O campo de texto original fica, com o aviso; o login não é afetado | — |
| 41 | Atualizar a lista | Botão de atualizar ao lado do seletor | A lista é relida do Google, sem recarregar a página | — |
| 42 | Trava na remoção | Regra com o critério de workspace; "Limpar" o workspace e salvar | Nada é salvo; a mensagem lista a regra com link; sem a regra, a remoção funciona | — |
| 43 | OUs bloqueadas | Configuração > Regras e bloqueios > "Adicionar OU" | Acrescenta a linha, não duplica, preserva o que foi digitado e os comentários | — |
| 44 | Regra por workspace no login | Conta do Principal e da Metropolitana na mesma OU `/Sistemas`, regra só para o Principal | Só a conta do Principal recebe o perfil da regra; regras antigas (sem workspace) valem nos dois | — |
| 45 | Nome de OU perigoso | OU com `<`, aspas ou `&` no nome (ou `GacSsoPicker.escapeHtml` no console) | Aparece como texto, nunca como HTML | — |
| 46 | Escopo ou papel faltando | Workspace sem o escopo `orgunit.readonly` ou sem o privilégio de ler OUs | Só esse workspace mostra erro no seletor; os outros continuam | — |
```

E, na seção "Verificações pendentes da spec (seção 12)" do mesmo arquivo, acrescente uma linha por verificação V17 a V22 da spec com o estado real (`resolvida` com a data, ou `pendente`).

- [ ] **Step 2: Resumo do módulo no CLAUDE.md**

No parágrafo do módulo SSO Google de `CLAUDE.md` (o "fourth module"), acrescente no fim: `Rules can also match the workspace (criterion "Workspace do Google", spec S25 to S30): each workspace has a stable, immutable key, removing a workspace that rules still use is refused, and the rule screen and the blocked OUs field have an org unit picker fed by orgunits.list (scope admin.directory.orgunit.readonly, cached in $GLPI_CACHE, no table), implemented in public/js/sso-ou-picker.js and ajax/sso/orgunits.php.` E em "Plugin structure conventions", no layout do SSO, acrescente as classes novas (`WorkspaceKey`, `RuleInput`, `WorkspaceRemoval`, `OrgUnitList` puras; `WorkspaceRuleUsage`, `GoogleServiceToken`, `OrgUnitDirectory`, `OuCopy` ligadas ao GLPI) e a pasta `tests/integration/` (scripts contra o GLPI local) e `tests/js/` (`node --test`).

- [ ] **Step 3: Estado final da spec**

Na spec, em "Status das decisões", troque a linha "Decidido em 07/10/2026" para acrescentar `implementado e testado em 07/10/2026` **somente depois** de todas as verificações V17 a V22 estarem resolvidas; se alguma ficar pendente, deixe como está e liste o que falta.

- [ ] **Step 4: Regressão completa**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml && node --test "tests/js/*.test.js" && /c/xampp/php/php.exe tests/integration/engine_compat.php && /c/xampp/php/php.exe tests/integration/workspace_removal_guard.php && /c/xampp/php/php.exe tests/integration/orgunits.php`
Expected: a suíte PHP `OK` (**317 testes originais + 7 + 7 + 6 + 5 + 9 + 1 = 352**; confira o número real e use o que o PHPUnit imprimir), JS `fail 0`, e os três scripts terminando em `0 falharam` (o `AVISO` da Metropolitana, se existir, não conta como falha, mas deve estar registrado na V19).

Repita no navegador os cenários do roteiro do SSO que tocam o login (3, 5, 7, 8, 21 do "Testar" com um e-mail real), porque `RuleRunner` e `RuleHooks` mudaram. Se um login real do Google não puder ser feito agora, registre `—` e diga isso ao dono.

- [ ] **Step 5: Verificar o que ficou no git**

Run: `git status --short`
Expected: só arquivos deste plano. Nenhum arquivo de `var/` (ignorado), nenhum segredo. Pare para o dono rodar `/commit` (sugestão: `chore(sso): document the org unit picker and its tests`).

---

## Auto-revisão

**Cobertura da spec**

| Spec | Tarefa |
|---|---|
| S25 chave estável (geração, imutável, tela somente leitura, backfill, registro recusa duplicada) | 1, 2 |
| S26 critério `GOOGLE_WORKSPACE`, compatibilidade das regras antigas | 3 |
| S27 trava na remoção (servidor, atômica, regras desativadas contam, link) | 4 |
| S28 `orgunits.list` direto, cache 10 min, "Atualizar", falha isolada, texto do administrador | 5, 6, 9 (textos) |
| S29 seletor na regra (combobox com texto livre, rótulo "workspace - caminho", valor só o caminho, filtro pelo workspace da regra, fallback) | 7, 8 |
| S30 seletor nas bloqueadas (acrescenta linha, sem duplicar, texto continua fonte de verdade, global) | 7, 9 |
| Testes A (PHP puro), B (JS), C (scripts no GLPI), D (navegador), regressão | 1 a 5, 7 (A, B); 3, 4, 6 (C); 8, 9, 10 (D e regressão) |
| V17 a V22 | 8 (V17, V18), 6 e 10 (V19), 4 (V20), 6 (V21 via `repeated`), 9 (V22) |

**Pontos de atenção para o executor**
- Task 2: `WorkspaceRegistry::fromRows` ganhou o segundo parâmetro; `fromJson` e o caminho de `SsoSettings::normalize()` não o passam (JSON salvo é confiável). Só o `handlePost` passa as chaves já gravadas.
- Task 3: `RuleHooks::CRITERION` continua existindo (alias de `RuleInput::OU_CRITERION`) para não quebrar quem já o usa.
- Task 4: a spec ganha a correção "qualquer condição bloqueia"; a tabela `glpi_rules` não tem `is_deleted` (corrigido na spec antes deste plano).
- Task 8: o nome dos campos do formulário do GLPI (`criteria`, `pattern`, `rules_id`) vem da leitura de `ajax/rulecriteriavalue.php` e do template `criteria.html.twig`; a V18 confirma no navegador. O componente é o **select2 do GLPI** (V17 resolvida com essa escolha); se ele não existir na página, o campo de texto original fica intacto.
- O JS fica em cache de 30 dias no GLPI de produção-like: depois de qualquer edição, Ctrl+F5; o hash muda com a versão do plugin (o dono decide o bump na hora da release).
