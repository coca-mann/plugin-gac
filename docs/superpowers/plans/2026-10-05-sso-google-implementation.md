# SSO Google Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Adicionar ao plugin `gac` um login "Entrar com Google" na tela de login do GLPI, com lista de domínios permitidos, leitura da OU do usuário no Google Workspace e mapeamento OU → entidade/perfil pelas regras de autorização nativas do GLPI.

**Architecture:** Módulo isolado `src/Sso/` (namespace `GlpiPlugin\Gac\Sso`). Regras puras (caminho de OU, política de domínio, validação do ID token, decisão de login, configurações) ficam sem dependência do GLPI e são testadas no harness standalone. As classes ligadas ao GLPI fazem o fluxo OAuth (`league/oauth2-google` do `vendor/` do GLPI), a leitura da OU (Directory API por conta de serviço) e acrescentam o critério `GOOGLE_OU` ao `RuleRight` por hook de plugin; as autorizações são aplicadas pelo `User::applyRightRules()` do núcleo e a sessão é aberta pelo padrão `Auth` + `Session::init()` do próprio GLPI.

**Tech Stack:** PHP 8.2, GLPI 11.0.x, PHPUnit 11 (phar standalone), `league/oauth2-google` 4.0 + `league/oauth2-client` 2.9 (já no GLPI), Guzzle via `Toolbox::getGuzzleClient()`, OpenSSL (assinatura RS256).

**Spec:** `docs/superpowers/specs/2026-10-05-sso-google-design.md` (decisões S1 a S23, verificações V1 a V16). Leia a seção 12 (spike) antes de começar: ela registra o que já foi provado no GLPI local.

## Global Constraints

- **Git:** nenhum commit é feito pelo agente. Os commits do dono usam a skill `/commit` (tipos `feat`, `fix`, `chore`, títulos em inglês, descrição em lista, **sem** trailer `Co-Authored-By`/`Claude-Session` nem menção a Claude). O trabalho fica na branch `dev`, nada em `main`, nada é enviado ao remoto. Onde este plano diz **Checkpoint**, rode os testes e deixe as mudanças sem commit.
- **Versão:** não alterar `PLUGIN_GAC_VERSION` nem `gac.xml` aqui; o bump para `0.7.0` (minor) e o CHANGELOG ficam para o fluxo de release do dono (`/changelog-pr`, `/changelog-release`). **Atenção:** como `plugin_gac_install()` muda, uma instalação existente só roda a migração quando a versão sobe; em dev use o script `var/tools/gac-install.php` da Tarefa 6.
- **Cabeçalho de licença:** todo arquivo PHP novo começa com o docblock de `tools/HEADER`. Os blocos de código deste plano começam em `<?php` sem o cabeçalho; depois de criar cada arquivo PHP rode `/c/xampp/php/php.exe var/tools/add-header.php <arquivo>` (script da Tarefa 1).
- **Comandos:** `php` não está no PATH; use `/c/xampp/php/php.exe`. Testes standalone: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml` na pasta do plugin (`C:\Users\juliano\VSCode\plugin-gac`). Linha de base antes de começar: 164 testes passando.
- **Strings de interface** em português do Brasil, dentro de `__('...', 'gac')`. Data no formato `dd/mm/aaaa` quando houver.
- **Sub-namespace:** toda classe de dados em `Sso` sobrescreve `getTable()` (convenção do `CLAUDE.md`), e as páginas ficam em `front/sso/`, AJAX em `ajax/sso/`.
- **AJAX:** endpoints não chamam `Session::checkCSRF()` (o kernel do GLPI 11 valida o cabeçalho `X-Glpi-Csrf-Token` de XHR). Os endpoints de leitura usam GET.
- **Formulários POST em páginas PHP** fecham com `Html::closeForm()`, que injeta o token CSRF.
- **Segredos:** `sso_client_secret` e `sso_sa_private_key` ficam criptografados (`Hooks::SECURED_CONFIGS`), nunca vão para log, evento ou mensagem de erro. Campo de segredo em branco no formulário significa "manter o atual".
- **Condição das regras:** regras com o critério "OU do Google" usam **somente** a condição "é" (a "começa com" não funciona com barras, spike da seção 12 da spec).
- **Escopo Google:** só `https://www.googleapis.com/auth/admin.directory.user.readonly`.
- **GLPI local:** `C:\Users\juliano\VSCode\glpi-xampp-dev-plugin` (shell: `/c/Users/juliano/VSCode/glpi-xampp-dev-plugin`), banco em `127.0.0.1:3307`, plugin por junction `plugins\gac`. O GLPI roda em `production` e o `Twig` não é usado neste módulo (HTML montado em PHP), então `cache:clear` não é necessário.

---

## File Structure

**Criar (puras, sem GLPI), com testes em `tests/Unit/`:**

| Arquivo | Responsabilidade |
|---|---|
| `src/Sso/Outcome.php` | Constantes dos resultados de login (`ok`, `domain_denied`, ...). |
| `src/Sso/OuPath.php` | Normalizar caminho de OU, listar ancestrais, testar "está sob". |
| `src/Sso/OuBlocklist.php` | Bloqueio duro de OUs (lista de caminhos, casamento por segmento). |
| `src/Sso/DomainPolicy.php` | Listas de domínios/e-mails, checagem de domínio e e-mail verificado, consistência domínio × OU. |
| `src/Sso/IdToken.php` | Decodificar o ID token, validar claims, verificar assinatura RS256. |
| `src/Sso/IdentityMatch.php` | Valor de retorno da decisão de identidade. |
| `src/Sso/IdentityMatcher.php` | Decidir usar/vincular/criar/negar o usuário. |
| `src/Sso/RuleResult.php` | Resultado do motor de regras (negado, autorizações, entidade padrão). |
| `src/Sso/LoginDecision.php` | A ordem de decisão da S8 em três fases puras. |
| `src/Sso/SsoSettings.php` | Visão tipada das configurações `sso_*`. |
| `src/Sso/ServiceAccountJwt.php` | Montar e assinar o JWT da conta de serviço. |
| `src/Sso/SsoException.php` | Exceção para falhas de Google/Directory. |

**Criar (ligadas ao GLPI):**

| Arquivo | Responsabilidade |
|---|---|
| `src/Sso/SsoConfig.php` | Ler/gravar as configurações (descriptografa os segredos). |
| `src/Sso/SsoIdentity.php` | Modelo (direito `plugin_gac_sso`) e acesso à tabela de identidades. |
| `src/Sso/SsoEvent.php` | Gravar eventos, OUs pendentes, expurgo (cron). |
| `src/Sso/SsoMenu.php` | Entrada do menu. |
| `src/Sso/RuleHooks.php` | Critério `GOOGLE_OU` e injeção do valor no motor. |
| `src/Sso/RuleRunner.php` | Roda a `RuleRightCollection` para um e-mail e uma OU. |
| `src/Sso/DirectoryClient.php` | Lê o `orgUnitPath` na Directory API. |
| `src/Sso/GoogleClient.php` | URL de autorização (state, nonce, PKCE) e troca do código por claims. |
| `src/Sso/UserProvisioner.php` | Acha candidatos por e-mail, cria, converte, aplica regras, revoga, desfaz. |
| `src/Sso/SessionStarter.php` | Abre a sessão GLPI. |
| `src/Sso/LoginService.php` | Orquestra o callback. |
| `src/Sso/SsoLoginButton.php` | HTML do hook `display_login`. |
| `src/Sso/OutcomeLabels.php` | Textos pt-BR dos resultados. |
| `src/Sso/DryRun.php` | Teste a seco. |
| `src/Sso/SsoConfigSection.php` | Seção de configuração (inclui o teste a seco). |
| `src/Sso/SsoPages.php` | HTML das listas (identidades, eventos, OUs pendentes) e da barra de navegação. |
| `front/sso/start.php`, `front/sso/callback.php` | Início e retorno do OAuth. |
| `front/sso/identities.php`, `events.php`, `pending.php`, `undo.php` | Telas de administração. |
| `ajax/sso/dry_run.php` | Endpoint do teste a seco. |
| `docs/sso-manual-tests.md` | Roteiro de testes manuais. |

**Modificar:** `setup.php`, `hook.php`, `src/Features.php`, `src/GacMenu.php`, `src/Config.php`, `CLAUDE.md`.

**Ferramentas de dev (ignoradas pelo git, em `var/tools/`):** `add-header.php`, `gac-eval.php`, `gac-install.php`, `sso-rules-check.php`.

---

### Task 1: OuPath e OuBlocklist (puras)

**Files:**
- Create: `var/tools/add-header.php`
- Create: `src/Sso/OuPath.php`, `src/Sso/OuBlocklist.php`
- Test: `tests/Unit/OuPathTest.php`, `tests/Unit/OuBlocklistTest.php`

**Interfaces:**
- Produces: `OuPath::normalize(string): string`, `OuPath::segments(string): list<string>`, `OuPath::ancestors(string): list<string>`, `OuPath::isUnder(string $path, string $base): bool`; `new OuBlocklist(list<string>)`, `OuBlocklist::fromText(string): self`, `->matches(string): ?string`, `->isEmpty(): bool`, `->paths(): list<string>`.

- [ ] **Step 1: Criar o script de cabeçalho**

Crie `var/tools/add-header.php`:

```php
<?php

// Uso: php var/tools/add-header.php arquivo.php [arquivo.php ...]
// Insere o docblock de licença (linhas 3 a 32 de setup.php) logo após "<?php" se ainda não existir.
$setup  = file(__DIR__ . '/../../setup.php');
$header = implode('', array_slice($setup, 2, 30));

foreach (array_slice($argv, 1) as $file) {
    $content = (string) file_get_contents($file);
    if (str_contains($content, 'Gac plugin for GLPI')) {
        continue;
    }
    if (!str_starts_with($content, "<?php\n")) {
        fwrite(STDERR, "ignorado (não começa com <?php): $file\n");
        continue;
    }
    file_put_contents($file, "<?php\n\n" . $header . substr($content, 6));
    echo "cabeçalho adicionado: $file\n";
}
```

- [ ] **Step 2: Escrever os testes que falham**

Crie `tests/Unit/OuPathTest.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Tests\Unit;

use GlpiPlugin\Gac\Sso\OuPath;
use PHPUnit\Framework\TestCase;

final class OuPathTest extends TestCase
{
    public function testNormalizeLowercasesAndFixesSlashes(): void
    {
        $this->assertSame('/fimca/fimca.com.br', OuPath::normalize('/FIMCA/Fimca.com.br/'));
        $this->assertSame('/fimca/x', OuPath::normalize('fimca/x'));
        $this->assertSame('/a/b', OuPath::normalize('//a///b'));
        $this->assertSame('/a/b', OuPath::normalize('  /A\\B '));
    }

    public function testRootNormalizesToSingleSlash(): void
    {
        $this->assertSame('/', OuPath::normalize(''));
        $this->assertSame('/', OuPath::normalize('/'));
        $this->assertSame('/', OuPath::normalize('   '));
    }

    public function testSegments(): void
    {
        $this->assertSame(['fimca', 'fimca.com.br', 'ies-pvh'], OuPath::segments('/FIMCA/fimca.com.br/IES-PVH'));
        $this->assertSame([], OuPath::segments('/'));
    }

    public function testAncestorsAreShallowestFirstAndIncludeTheOuItself(): void
    {
        $this->assertSame(
            ['/fimca', '/fimca/fimca.com.br', '/fimca/fimca.com.br/ies-pvh'],
            OuPath::ancestors('/FIMCA/fimca.com.br/IES-PVH')
        );
        $this->assertSame(['/a'], OuPath::ancestors('/a'));
    }

    public function testAncestorsOfTheRootIsJustTheRoot(): void
    {
        $this->assertSame(['/'], OuPath::ancestors('/'));
    }

    public function testIsUnderMatchesSelfAndDescendants(): void
    {
        $this->assertTrue(OuPath::isUnder('/a/b', '/a/b'));
        $this->assertTrue(OuPath::isUnder('/A/B/C', '/a/b'));
        $this->assertTrue(OuPath::isUnder('/a/b', '/'));
    }

    public function testIsUnderRespectsSegmentBoundaries(): void
    {
        $this->assertFalse(OuPath::isUnder('/a/bc', '/a/b'));
        $this->assertFalse(OuPath::isUnder('/a', '/a/b'));
        $this->assertFalse(OuPath::isUnder('/x/b', '/a/b'));
    }
}
```

Crie `tests/Unit/OuBlocklistTest.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Tests\Unit;

use GlpiPlugin\Gac\Sso\OuBlocklist;
use PHPUnit\Framework\TestCase;

final class OuBlocklistTest extends TestCase
{
    public function testMatchesTheBlockedOuAndItsDescendants(): void
    {
        $list = OuBlocklist::fromText("/FIMCA/fimca.com.br/IES-PVH/Professores\n");

        $this->assertSame('/fimca/fimca.com.br/ies-pvh/professores', $list->matches('/FIMCA/fimca.com.br/IES-PVH/Professores'));
        $this->assertSame('/fimca/fimca.com.br/ies-pvh/professores', $list->matches('/fimca/fimca.com.br/ies-pvh/professores/medicina'));
    }

    public function testDoesNotMatchSiblingsOrPrefixesOfASegment(): void
    {
        $list = OuBlocklist::fromText('/fimca/ies-pvh/professores');

        $this->assertNull($list->matches('/fimca/ies-pvh/professores-visitantes'));
        $this->assertNull($list->matches('/fimca/ies-pvh/financeiro'));
        $this->assertNull($list->matches('/fimca/ies-pvh'));
    }

    public function testIgnoresBlankLinesCommentsAndTheRoot(): void
    {
        $list = OuBlocklist::fromText("# professores\n\n/a/b\n/\n   \n");

        $this->assertSame(['/a/b'], $list->paths());
        $this->assertNull($list->matches('/qualquer/coisa'));
    }

    public function testEmptyList(): void
    {
        $list = OuBlocklist::fromText('');

        $this->assertTrue($list->isEmpty());
        $this->assertNull($list->matches('/a'));
    }

    public function testDuplicatesCollapse(): void
    {
        $list = new OuBlocklist(['/A/B', '/a/b/', '/a/b']);

        $this->assertSame(['/a/b'], $list->paths());
    }
}
```

- [ ] **Step 3: Rodar os testes e ver falhar**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml --filter "OuPathTest|OuBlocklistTest"`
Expected: FAIL com `Class "GlpiPlugin\Gac\Sso\OuPath" not found`.

- [ ] **Step 4: Implementar**

Crie `src/Sso/OuPath.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Sso;

/**
 * Pure helpers over Google Workspace org unit paths (spec S4, S9). A path is compared
 * case-insensitively and by whole segments: "/a/b" contains "/a/b/c" but not "/a/bc".
 */
final class OuPath
{
    public static function normalize(string $path): string
    {
        $p = mb_strtolower(trim($path));
        $p = str_replace('\\', '/', $p);
        $p = (string) preg_replace('#/+#', '/', $p);

        return '/' . trim($p, '/');
    }

    /** @return list<string> */
    public static function segments(string $path): array
    {
        $normalized = self::normalize($path);

        return $normalized === '/' ? [] : explode('/', substr($normalized, 1));
    }

    /**
     * The path and every ancestor, shallowest first: "/a/b/c" gives ["/a", "/a/b", "/a/b/c"].
     * The root OU gives ["/"].
     *
     * @return list<string>
     */
    public static function ancestors(string $path): array
    {
        $segments = self::segments($path);
        if ($segments === []) {
            return ['/'];
        }

        $out = [];
        $acc = '';
        foreach ($segments as $segment) {
            $acc .= '/' . $segment;
            $out[] = $acc;
        }

        return $out;
    }

    /** True when $path is $base itself or sits below it, comparing whole segments. */
    public static function isUnder(string $path, string $base): bool
    {
        $p = self::normalize($path);
        $b = self::normalize($base);

        return $b === '/' || $p === $b || str_starts_with($p, $b . '/');
    }
}
```

Crie `src/Sso/OuBlocklist.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Sso;

/**
 * "OUs sempre bloqueadas" (spec S9): evaluated before the rules engine, so the company rule that
 * teachers cannot log in does not depend on rule ordering being right.
 */
final class OuBlocklist
{
    /** @var list<string> */
    private array $paths;

    /** @param list<string> $paths */
    public function __construct(array $paths)
    {
        $unique = [];
        foreach ($paths as $path) {
            $normalized = OuPath::normalize($path);
            // A blocked root would lock everybody out of the Google login.
            if ($normalized !== '/') {
                $unique[$normalized] = true;
            }
        }
        $this->paths = array_keys($unique);
    }

    /** One path per line; blank lines and lines starting with "#" are ignored. */
    public static function fromText(string $text): self
    {
        $paths = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $paths[] = $line;
        }

        return new self($paths);
    }

    /** @return ?string the blocked base path that matched (normalized), or null */
    public function matches(string $ouPath): ?string
    {
        foreach ($this->paths as $base) {
            if (OuPath::isUnder($ouPath, $base)) {
                return $base;
            }
        }

        return null;
    }

    public function isEmpty(): bool
    {
        return $this->paths === [];
    }

    /** @return list<string> */
    public function paths(): array
    {
        return $this->paths;
    }
}
```

- [ ] **Step 5: Adicionar o cabeçalho e rodar os testes**

Run:
```bash
/c/xampp/php/php.exe var/tools/add-header.php src/Sso/OuPath.php src/Sso/OuBlocklist.php tests/Unit/OuPathTest.php tests/Unit/OuBlocklistTest.php
/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml --filter "OuPathTest|OuBlocklistTest"
```
Expected: `OK (12 tests, ...)` (7 do OuPath + 5 do OuBlocklist). Se o `var/tools/phpunit.phar` não existir, baixe-o como descrito no `CLAUDE.md`.

- [ ] **Step 6: Checkpoint (sem commit)**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml`
Expected: todos passam (164 anteriores + 12 novos).

---

### Task 2: Outcome e DomainPolicy (puras)

**Files:**
- Create: `src/Sso/Outcome.php`, `src/Sso/DomainPolicy.php`
- Test: `tests/Unit/DomainPolicyTest.php`

**Interfaces:**
- Consumes: `OuPath::segments()` (Tarefa 1).
- Produces: `Outcome::*` (constantes string) e `Outcome::all(): list<string>`; `DomainPolicy::parseList(string): list<string>`, `DomainPolicy::parseDomains(string): list<string>`, `DomainPolicy::emailDomain(string): string`, `DomainPolicy::check(string $email, bool $emailVerified, string $hostedDomain, array $allowedDomains): ?string`, `DomainPolicy::domainSegmentMatches(string $ouPath, string $emailDomain, int $position): bool`.

- [ ] **Step 1: Escrever o teste que falha**

Crie `tests/Unit/DomainPolicyTest.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Tests\Unit;

use GlpiPlugin\Gac\Sso\DomainPolicy;
use GlpiPlugin\Gac\Sso\Outcome;
use PHPUnit\Framework\TestCase;

final class DomainPolicyTest extends TestCase
{
    public function testParseListSplitsLowercasesAndDeduplicates(): void
    {
        $this->assertSame(
            ['a@x.com', 'b@x.com'],
            DomainPolicy::parseList("A@X.com, b@x.com;\n a@x.com  ")
        );
        $this->assertSame([], DomainPolicy::parseList("  \n "));
    }

    public function testParseDomainsStripsLeadingAt(): void
    {
        $this->assertSame(
            ['fimca.com.br', 'grupoaparicio.com.br'],
            DomainPolicy::parseDomains("@Fimca.com.br\ngrupoaparicio.com.br")
        );
    }

    public function testEmailDomain(): void
    {
        $this->assertSame('fimca.com.br', DomainPolicy::emailDomain('Ana@Fimca.com.br'));
        $this->assertSame('', DomainPolicy::emailDomain('sem-arroba'));
        $this->assertSame('', DomainPolicy::emailDomain('a@b@c.com'));
        $this->assertSame('', DomainPolicy::emailDomain('@x.com'));
        $this->assertSame('', DomainPolicy::emailDomain('x@'));
    }

    public function testCheckPassesForAnAllowedVerifiedWorkspaceAccount(): void
    {
        $this->assertNull(DomainPolicy::check('ana@fimca.com.br', true, 'fimca.com.br', ['fimca.com.br']));
    }

    public function testCheckRejectsUnverifiedEmailFirst(): void
    {
        $this->assertSame(
            Outcome::EMAIL_UNVERIFIED,
            DomainPolicy::check('ana@outro.com', false, '', ['fimca.com.br'])
        );
    }

    public function testCheckRejectsDomainsOutsideTheList(): void
    {
        $this->assertSame(
            Outcome::DOMAIN_DENIED,
            DomainPolicy::check('ana@outro.com', true, 'outro.com', ['fimca.com.br'])
        );
    }

    public function testCheckRejectsConsumerAccountsWithoutAHostedDomain(): void
    {
        $this->assertSame(
            Outcome::DOMAIN_DENIED,
            DomainPolicy::check('ana@fimca.com.br', true, '', ['fimca.com.br'])
        );
    }

    public function testCheckAcceptsASecondaryDomainOfTheSameWorkspace(): void
    {
        // The hd claim is the Workspace's domain, which can differ from the e-mail's domain.
        $this->assertNull(DomainPolicy::check('ana@grupoaparicio.com.br', true, 'fimca.com.br', ['fimca.com.br', 'grupoaparicio.com.br']));
    }

    public function testDomainSegmentMatches(): void
    {
        $ou = '/FIMCA/fimca.com.br/IES-PVH';

        $this->assertTrue(DomainPolicy::domainSegmentMatches($ou, 'Fimca.com.br', 2));
        $this->assertFalse(DomainPolicy::domainSegmentMatches($ou, 'grupoaparicio.com.br', 2));
        $this->assertFalse(DomainPolicy::domainSegmentMatches('/FIMCA', 'fimca.com.br', 2));
        $this->assertFalse(DomainPolicy::domainSegmentMatches($ou, 'fimca.com.br', 0));
    }

    public function testOutcomeAllListsEveryConstantOnce(): void
    {
        $all = Outcome::all();

        $this->assertContains(Outcome::OK, $all);
        $this->assertContains(Outcome::LOCAL_ACCOUNT, $all);
        $this->assertSame($all, array_values(array_unique($all)));
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml --filter DomainPolicyTest`
Expected: FAIL com `Class "GlpiPlugin\Gac\Sso\DomainPolicy" not found`.

- [ ] **Step 3: Implementar**

Crie `src/Sso/Outcome.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Sso;

/** Outcome codes recorded in glpi_plugin_gac_ssoevents (spec section 5.2). */
final class Outcome
{
    public const OK               = 'ok';
    public const DOMAIN_DENIED    = 'domain_denied';
    public const EMAIL_UNVERIFIED = 'email_unverified';
    public const OU_BLOCKED       = 'ou_blocked';
    public const OU_DENIED        = 'ou_denied';
    public const OU_UNMAPPED      = 'ou_unmapped';
    public const DOMAIN_MISMATCH  = 'domain_mismatch';
    public const EMAIL_AMBIGUOUS  = 'email_ambiguous';
    public const PILOT_BLOCKED    = 'pilot_blocked';
    public const CREATE_DISABLED  = 'create_disabled';
    public const USER_INACTIVE    = 'user_inactive';
    public const LOCAL_ACCOUNT    = 'local_account';
    public const STATE_INVALID    = 'state_invalid';
    public const TOKEN_INVALID    = 'token_invalid';
    public const API_ERROR        = 'api_error';
    public const REVOKED          = 'revoked';
    public const UNDONE           = 'undone';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::OK, self::DOMAIN_DENIED, self::EMAIL_UNVERIFIED, self::OU_BLOCKED, self::OU_DENIED,
            self::OU_UNMAPPED, self::DOMAIN_MISMATCH, self::EMAIL_AMBIGUOUS, self::PILOT_BLOCKED,
            self::CREATE_DISABLED, self::USER_INACTIVE, self::LOCAL_ACCOUNT, self::STATE_INVALID,
            self::TOKEN_INVALID, self::API_ERROR, self::REVOKED, self::UNDONE,
        ];
    }
}
```

Crie `src/Sso/DomainPolicy.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Sso;

/** Domain allow-list rules (spec S8 step 1, S18). Pure. */
final class DomainPolicy
{
    /**
     * Splits on whitespace, commas and semicolons; lowercases; drops empties and duplicates.
     *
     * @return list<string>
     */
    public static function parseList(string $text): array
    {
        $parts = preg_split('/[\s,;]+/', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique($parts));
    }

    /** @return list<string> */
    public static function parseDomains(string $text): array
    {
        $domains = [];
        foreach (self::parseList($text) as $item) {
            $domain = ltrim($item, '@');
            if ($domain !== '') {
                $domains[$domain] = true;
            }
        }

        return array_keys($domains);
    }

    public static function emailDomain(string $email): string
    {
        $email = mb_strtolower(trim($email));
        if (substr_count($email, '@') !== 1) {
            return '';
        }
        [$local, $domain] = explode('@', $email);

        return ($local === '' || $domain === '') ? '' : $domain;
    }

    /**
     * @param list<string> $allowedDomains lowercase
     * @return ?string null when the account may continue, or the Outcome code that denies it
     */
    public static function check(string $email, bool $emailVerified, string $hostedDomain, array $allowedDomains): ?string
    {
        if (!$emailVerified) {
            return Outcome::EMAIL_UNVERIFIED;
        }

        $domain = self::emailDomain($email);
        // The "hd" claim only exists for Workspace accounts: no hd means a consumer account.
        // It is the Workspace's domain, which can differ from the e-mail's domain, so only
        // its presence is required; the e-mail's domain is what must be on the list.
        if ($domain === '' || trim($hostedDomain) === '' || !in_array($domain, $allowedDomains, true)) {
            return Outcome::DOMAIN_DENIED;
        }

        return null;
    }

    /** Position is 1-based (spec S18); 0 or less is always false. */
    public static function domainSegmentMatches(string $ouPath, string $emailDomain, int $position): bool
    {
        $segments = OuPath::segments($ouPath);
        $index    = $position - 1;

        return $index >= 0 && isset($segments[$index]) && $segments[$index] === mb_strtolower($emailDomain);
    }
}
```

- [ ] **Step 4: Cabeçalho e testes**

Run:
```bash
/c/xampp/php/php.exe var/tools/add-header.php src/Sso/Outcome.php src/Sso/DomainPolicy.php tests/Unit/DomainPolicyTest.php
/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml --filter DomainPolicyTest
```
Expected: `OK (10 tests, ...)`.

- [ ] **Step 5: Checkpoint (sem commit)**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml`
Expected: todos passam.

---

### Task 3: IdToken (pura)

**Files:**
- Create: `src/Sso/IdToken.php`
- Test: `tests/Unit/IdTokenTest.php`

**Interfaces:**
- Produces: `IdToken::decode(string $jwt): ?array{header: array, claims: array, signing_input: string, signature: string}`, `IdToken::validateClaims(array $claims, string $clientId, string $nonce, int $now, int $leeway = 60): ?string` (null = válido; senão o nome da claim que falhou), `IdToken::emailVerified(array $claims): bool`, `IdToken::verifySignature(array $decoded, array $pemByKid): bool`.

- [ ] **Step 1: Escrever o teste que falha**

Crie `tests/Unit/IdTokenTest.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Tests\Unit;

use GlpiPlugin\Gac\Sso\IdToken;
use PHPUnit\Framework\TestCase;

final class IdTokenTest extends TestCase
{
    private const CLIENT = 'client-123.apps.googleusercontent.com';
    private const NOW    = 1_800_000_000;

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** @param array<string, mixed> $header @param array<string, mixed> $claims */
    private static function jwt(array $header, array $claims, string $signature = 'sig'): string
    {
        return self::b64((string) json_encode($header)) . '.' . self::b64((string) json_encode($claims)) . '.' . self::b64($signature);
    }

    /** @return array<string, mixed> */
    private static function goodClaims(): array
    {
        return [
            'iss' => 'https://accounts.google.com', 'aud' => self::CLIENT, 'exp' => self::NOW + 600,
            'iat' => self::NOW - 5, 'nonce' => 'n-1', 'sub' => '1100', 'email' => 'ana@fimca.com.br',
            'email_verified' => true, 'hd' => 'fimca.com.br',
        ];
    }

    public function testDecodeSplitsAValidToken(): void
    {
        $decoded = IdToken::decode(self::jwt(['alg' => 'RS256', 'kid' => 'k1'], self::goodClaims()));

        $this->assertNotNull($decoded);
        $this->assertSame('k1', $decoded['header']['kid']);
        $this->assertSame('ana@fimca.com.br', $decoded['claims']['email']);
        $this->assertSame('sig', $decoded['signature']);
        $this->assertStringContainsString('.', $decoded['signing_input']);
    }

    public function testDecodeRejectsMalformedTokens(): void
    {
        $this->assertNull(IdToken::decode('abc'));
        $this->assertNull(IdToken::decode('a.b'));
        $this->assertNull(IdToken::decode('!!.!!.!!'));
        $this->assertNull(IdToken::decode(self::b64('not json') . '.' . self::b64('{}') . '.' . self::b64('s')));
    }

    public function testValidateClaimsAcceptsAGoodToken(): void
    {
        $this->assertNull(IdToken::validateClaims(self::goodClaims(), self::CLIENT, 'n-1', self::NOW));
    }

    public function testValidateClaimsAcceptsTheBareIssuerAndAnAudienceList(): void
    {
        $claims        = self::goodClaims();
        $claims['iss'] = 'accounts.google.com';
        $claims['aud'] = ['other', self::CLIENT];

        $this->assertNull(IdToken::validateClaims($claims, self::CLIENT, 'n-1', self::NOW));
    }

    public function testValidateClaimsNamesTheFailingClaim(): void
    {
        $base = self::goodClaims();

        $this->assertSame('iss', IdToken::validateClaims(['iss' => 'https://evil.test'] + $base, self::CLIENT, 'n-1', self::NOW));
        $this->assertSame('aud', IdToken::validateClaims(['aud' => 'someone-else'] + $base, self::CLIENT, 'n-1', self::NOW));
        $this->assertSame('exp', IdToken::validateClaims(['exp' => self::NOW - 600] + $base, self::CLIENT, 'n-1', self::NOW));
        $this->assertSame('iat', IdToken::validateClaims(['iat' => self::NOW + 3600] + $base, self::CLIENT, 'n-1', self::NOW));
        $this->assertSame('nonce', IdToken::validateClaims($base, self::CLIENT, 'other-nonce', self::NOW));
        $this->assertSame('nonce', IdToken::validateClaims($base, self::CLIENT, '', self::NOW));
        $this->assertSame('sub', IdToken::validateClaims(['sub' => ''] + $base, self::CLIENT, 'n-1', self::NOW));
        $this->assertSame('email', IdToken::validateClaims(['email' => ''] + $base, self::CLIENT, 'n-1', self::NOW));
    }

    public function testValidateClaimsToleratesSmallClockSkew(): void
    {
        $claims = ['exp' => self::NOW - 30] + self::goodClaims();

        $this->assertNull(IdToken::validateClaims($claims, self::CLIENT, 'n-1', self::NOW));
    }

    public function testEmailVerifiedAcceptsBooleanAndStringTrue(): void
    {
        $this->assertTrue(IdToken::emailVerified(['email_verified' => true]));
        $this->assertTrue(IdToken::emailVerified(['email_verified' => 'true']));
        $this->assertFalse(IdToken::emailVerified(['email_verified' => false]));
        $this->assertFalse(IdToken::emailVerified(['email_verified' => 'false']));
        $this->assertFalse(IdToken::emailVerified([]));
    }

    public function testVerifySignatureAcceptsAValidRs256Token(): void
    {
        [$private, $publicPem] = $this->keyPair();
        $header  = ['alg' => 'RS256', 'kid' => 'k1'];
        $payload = self::b64((string) json_encode($header)) . '.' . self::b64((string) json_encode(self::goodClaims()));
        openssl_sign($payload, $signature, $private, OPENSSL_ALGO_SHA256);
        $decoded = IdToken::decode($payload . '.' . self::b64($signature));

        $this->assertNotNull($decoded);
        $this->assertTrue(IdToken::verifySignature($decoded, ['k1' => $publicPem]));
    }

    public function testVerifySignatureRejectsTamperingWrongKeyAndWrongAlgorithm(): void
    {
        [$private, $publicPem] = $this->keyPair();
        [, $otherPublicPem]    = $this->keyPair();
        $payload = self::b64((string) json_encode(['alg' => 'RS256', 'kid' => 'k1'])) . '.' . self::b64((string) json_encode(self::goodClaims()));
        openssl_sign($payload, $signature, $private, OPENSSL_ALGO_SHA256);
        $good = IdToken::decode($payload . '.' . self::b64($signature));
        $this->assertNotNull($good);

        // Different key.
        $this->assertFalse(IdToken::verifySignature($good, ['k1' => $otherPublicPem]));
        // Unknown kid.
        $this->assertFalse(IdToken::verifySignature($good, ['zzz' => $publicPem]));
        // Tampered payload.
        $tampered                  = $good;
        $tampered['signing_input'] = $good['signing_input'] . 'x';
        $this->assertFalse(IdToken::verifySignature($tampered, ['k1' => $publicPem]));
        // "none"/HS256 style algorithms are never accepted.
        $none                   = $good;
        $none['header']['alg']  = 'none';
        $this->assertFalse(IdToken::verifySignature($none, ['k1' => $publicPem]));
    }

    /** @return array{0: \OpenSSLAsymmetricKey, 1: string} */
    private function keyPair(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            $this->markTestSkipped('openssl key generation unavailable (try OPENSSL_CONF=C:/xampp/apache/conf/openssl.cnf)');
        }
        $details = openssl_pkey_get_details($key);

        return [$key, (string) $details['key']];
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml --filter IdTokenTest`
Expected: FAIL com `Class "GlpiPlugin\Gac\Sso\IdToken" not found`.

- [ ] **Step 3: Implementar**

Crie `src/Sso/IdToken.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Sso;

/**
 * Pure handling of the Google ID token (a JWT): decoding, claim validation and RS256 signature
 * verification against Google's published certificates. See spec section 6.1 step 2 and V7.
 */
final class IdToken
{
    public const ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];

    private static function base64UrlDecode(string $value): string|false
    {
        $b64 = strtr($value, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad !== 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }

        return base64_decode($b64, true);
    }

    /**
     * @return ?array{header: array<string, mixed>, claims: array<string, mixed>, signing_input: string, signature: string}
     */
    public static function decode(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }

        $header    = self::base64UrlDecode($parts[0]);
        $claims    = self::base64UrlDecode($parts[1]);
        $signature = self::base64UrlDecode($parts[2]);
        if ($header === false || $claims === false || $signature === false) {
            return null;
        }

        $headerData = json_decode($header, true);
        $claimsData = json_decode($claims, true);
        if (!is_array($headerData) || !is_array($claimsData)) {
            return null;
        }

        return [
            'header'        => $headerData,
            'claims'        => $claimsData,
            'signing_input' => $parts[0] . '.' . $parts[1],
            'signature'     => $signature,
        ];
    }

    /**
     * @param array<string, mixed> $claims
     * @return ?string null when valid, otherwise the name of the claim that failed
     */
    public static function validateClaims(array $claims, string $clientId, string $nonce, int $now, int $leeway = 60): ?string
    {
        if (!in_array($claims['iss'] ?? null, self::ISSUERS, true)) {
            return 'iss';
        }

        $aud       = $claims['aud'] ?? null;
        $audiences = is_array($aud) ? $aud : [$aud];
        if (!in_array($clientId, $audiences, true)) {
            return 'aud';
        }

        if (!isset($claims['exp']) || !is_numeric($claims['exp']) || (int) $claims['exp'] + $leeway < $now) {
            return 'exp';
        }

        if (isset($claims['iat']) && is_numeric($claims['iat']) && (int) $claims['iat'] - $leeway > $now) {
            return 'iat';
        }

        $tokenNonce = $claims['nonce'] ?? null;
        if ($nonce === '' || !is_string($tokenNonce) || !hash_equals($nonce, $tokenNonce)) {
            return 'nonce';
        }

        if (!is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            return 'sub';
        }

        if (!is_string($claims['email'] ?? null) || $claims['email'] === '') {
            return 'email';
        }

        return null;
    }

    /** @param array<string, mixed> $claims */
    public static function emailVerified(array $claims): bool
    {
        $value = $claims['email_verified'] ?? false;

        return $value === true || $value === 'true';
    }

    /**
     * @param array{header: array<string, mixed>, signing_input: string, signature: string} $decoded
     * @param array<string, string> $pemByKid key id => certificate or public key in PEM form
     */
    public static function verifySignature(array $decoded, array $pemByKid): bool
    {
        if (($decoded['header']['alg'] ?? null) !== 'RS256') {
            return false;
        }

        $kid = $decoded['header']['kid'] ?? null;
        if (!is_string($kid) || !isset($pemByKid[$kid])) {
            return false;
        }

        return openssl_verify($decoded['signing_input'], $decoded['signature'], $pemByKid[$kid], OPENSSL_ALGO_SHA256) === 1;
    }
}
```

- [ ] **Step 4: Cabeçalho e testes**

Run:
```bash
/c/xampp/php/php.exe var/tools/add-header.php src/Sso/IdToken.php tests/Unit/IdTokenTest.php
/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml --filter IdTokenTest
```
Expected: `OK (9 tests, ...)`. Se os dois testes de assinatura saírem como *skipped*, rode com `OPENSSL_CONF=C:/xampp/apache/conf/openssl.cnf` na frente do comando; testes pulados não contam como aprovados.

- [ ] **Step 5: Checkpoint (sem commit)**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml`
Expected: todos passam.

---

### Task 4: IdentityMatcher, RuleResult e LoginDecision (puras)

**Files:**
- Create: `src/Sso/IdentityMatch.php`, `src/Sso/IdentityMatcher.php`, `src/Sso/RuleResult.php`, `src/Sso/LoginDecision.php`
- Test: `tests/Unit/IdentityMatcherTest.php`, `tests/Unit/RuleResultTest.php`, `tests/Unit/LoginDecisionTest.php`

**Interfaces:**
- Consumes: `Outcome`, `DomainPolicy::check`, `DomainPolicy::domainSegmentMatches`, `DomainPolicy::parseList`, `OuBlocklist::matches` (Tarefas 1 e 2).
- Produces:
  - `IdentityMatch` com constantes `USE_LINKED`, `LINK_EXISTING`, `CREATE`, `DENY`; propriedades `public readonly string $action`, `?int $userId`, `?string $outcome`; fábricas `useLinked(int)`, `linkExisting(int)`, `create()`, `deny(string)`.
  - `IdentityMatcher::decide(?int $linkedUserId, array $emailCandidateIds, bool $createAllowed): IdentityMatch` e `IdentityMatcher::isConvertible(int $authtype): bool`.
  - `RuleResult` (`public readonly bool $denied`, `array $grants` lista de `{entities_id:int, profiles_id:int, is_recursive:int}`, `?int $defaultEntityId`), `RuleResult::fromOutput(array): self`, `->hasGrants(): bool`.
  - `LoginDecision::beforeDirectory(string $email, bool $emailVerified, string $hostedDomain, array $allowedDomains, bool $pilotOnly, array $pilotEmails): ?string`, `LoginDecision::afterDirectory(string $ouPath, string $emailDomain, OuBlocklist $blocklist, int $domainSegment): ?string`, `LoginDecision::afterRules(bool $denied, bool $hasGrants): ?string`.

- [ ] **Step 1: Escrever os testes que falham**

Crie `tests/Unit/IdentityMatcherTest.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Tests\Unit;

use GlpiPlugin\Gac\Sso\IdentityMatch;
use GlpiPlugin\Gac\Sso\IdentityMatcher;
use GlpiPlugin\Gac\Sso\Outcome;
use PHPUnit\Framework\TestCase;

final class IdentityMatcherTest extends TestCase
{
    public function testALinkedSubWinsEvenIfTheEmailPointsElsewhere(): void
    {
        $match = IdentityMatcher::decide(7, [9], false);

        $this->assertSame(IdentityMatch::USE_LINKED, $match->action);
        $this->assertSame(7, $match->userId);
    }

    public function testASingleEmailCandidateIsLinked(): void
    {
        $match = IdentityMatcher::decide(null, [12], false);

        $this->assertSame(IdentityMatch::LINK_EXISTING, $match->action);
        $this->assertSame(12, $match->userId);
    }

    public function testDuplicateIdsOfTheSameUserCountOnce(): void
    {
        $match = IdentityMatcher::decide(null, [12, 12], false);

        $this->assertSame(IdentityMatch::LINK_EXISTING, $match->action);
    }

    public function testMoreThanOneCandidateIsDeniedNeverGuessed(): void
    {
        $match = IdentityMatcher::decide(null, [12, 13], true);

        $this->assertSame(IdentityMatch::DENY, $match->action);
        $this->assertSame(Outcome::EMAIL_AMBIGUOUS, $match->outcome);
    }

    public function testNoCandidateCreatesWhenAllowed(): void
    {
        $this->assertSame(IdentityMatch::CREATE, IdentityMatcher::decide(null, [], true)->action);
    }

    public function testNoCandidateIsDeniedWhenCreationIsOff(): void
    {
        $match = IdentityMatcher::decide(null, [], false);

        $this->assertSame(IdentityMatch::DENY, $match->action);
        $this->assertSame(Outcome::CREATE_DISABLED, $match->outcome);
    }

    public function testLocalAccountsAreNeverConvertible(): void
    {
        $this->assertFalse(IdentityMatcher::isConvertible(1)); // Auth::DB_GLPI
        $this->assertTrue(IdentityMatcher::isConvertible(3));  // Auth::LDAP
        $this->assertTrue(IdentityMatcher::isConvertible(4));  // Auth::EXTERNAL
        $this->assertTrue(IdentityMatcher::isConvertible(2));  // Auth::MAIL
    }
}
```

Crie `tests/Unit/RuleResultTest.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Tests\Unit;

use GlpiPlugin\Gac\Sso\RuleResult;
use PHPUnit\Framework\TestCase;

final class RuleResultTest extends TestCase
{
    public function testReadsEntityProfileRecursiveTriples(): void
    {
        $result = RuleResult::fromOutput([
            '_ldap_rules' => ['rules_entities_rights' => [['3', '3', '0'], ['4', '5', '1']]],
        ]);

        $this->assertFalse($result->denied);
        $this->assertTrue($result->hasGrants());
        $this->assertSame([
            ['entities_id' => 3, 'profiles_id' => 3, 'is_recursive' => 0],
            ['entities_id' => 4, 'profiles_id' => 5, 'is_recursive' => 1],
        ], $result->grants);
    }

    public function testDuplicateTriplesCollapse(): void
    {
        $result = RuleResult::fromOutput([
            '_ldap_rules' => ['rules_entities_rights' => [['3', '3', '0'], ['4', '5', '0'], ['3', '3', '0']]],
        ]);

        $this->assertCount(2, $result->grants);
    }

    public function testAMultiEntityRowExpands(): void
    {
        $result = RuleResult::fromOutput([
            '_ldap_rules' => ['rules_entities_rights' => [[['3', '4'], '2', '0']]],
        ]);

        $this->assertSame([
            ['entities_id' => 3, 'profiles_id' => 2, 'is_recursive' => 0],
            ['entities_id' => 4, 'profiles_id' => 2, 'is_recursive' => 0],
        ], $result->grants);
    }

    public function testEntityOnlyRulesCombineWithProfileOnlyRules(): void
    {
        $result = RuleResult::fromOutput([
            '_ldap_rules' => ['rules_entities' => [['3', '1']], 'rules_rights' => ['5', '6']],
        ]);

        $this->assertSame([
            ['entities_id' => 3, 'profiles_id' => 5, 'is_recursive' => 1],
            ['entities_id' => 3, 'profiles_id' => 6, 'is_recursive' => 1],
        ], $result->grants);
    }

    public function testEntityOnlyWithoutProfileUsesProfileZeroMeaningGlpiDefault(): void
    {
        $result = RuleResult::fromOutput(['_ldap_rules' => ['rules_entities' => [['3', '0']]]]);

        $this->assertSame([['entities_id' => 3, 'profiles_id' => 0, 'is_recursive' => 0]], $result->grants);
    }

    public function testDenyAndEmptyOutput(): void
    {
        $denied = RuleResult::fromOutput(['_deny_login' => '1']);
        $empty  = RuleResult::fromOutput(['_no_rule_matches' => true]);

        $this->assertTrue($denied->denied);
        $this->assertFalse($denied->hasGrants());
        $this->assertFalse($empty->denied);
        $this->assertFalse($empty->hasGrants());
    }

    public function testDefaultEntityComesFromTheEntitiesIdOutputKey(): void
    {
        $this->assertSame(3, RuleResult::fromOutput(['entities_id' => '3'])->defaultEntityId);
        $this->assertNull(RuleResult::fromOutput([])->defaultEntityId);
    }
}
```

Crie `tests/Unit/LoginDecisionTest.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Tests\Unit;

use GlpiPlugin\Gac\Sso\LoginDecision;
use GlpiPlugin\Gac\Sso\OuBlocklist;
use GlpiPlugin\Gac\Sso\Outcome;
use PHPUnit\Framework\TestCase;

final class LoginDecisionTest extends TestCase
{
    private const ALLOWED = ['fimca.com.br', 'grupoaparicio.com.br'];

    public function testBeforeDirectoryPassesAnAllowedVerifiedAccount(): void
    {
        $this->assertNull(LoginDecision::beforeDirectory('ana@fimca.com.br', true, 'fimca.com.br', self::ALLOWED, false, []));
    }

    public function testBeforeDirectoryChecksVerificationBeforeDomain(): void
    {
        $this->assertSame(
            Outcome::EMAIL_UNVERIFIED,
            LoginDecision::beforeDirectory('ana@outro.com', false, '', self::ALLOWED, false, [])
        );
    }

    public function testBeforeDirectoryDeniesForeignDomains(): void
    {
        $this->assertSame(
            Outcome::DOMAIN_DENIED,
            LoginDecision::beforeDirectory('ana@outro.com', true, 'outro.com', self::ALLOWED, false, [])
        );
    }

    public function testPilotModeOnlyLetsListedEmailsIn(): void
    {
        $this->assertSame(
            Outcome::PILOT_BLOCKED,
            LoginDecision::beforeDirectory('ana@fimca.com.br', true, 'fimca.com.br', self::ALLOWED, true, ['ti@fimca.com.br'])
        );
        $this->assertNull(
            LoginDecision::beforeDirectory('TI@Fimca.com.br', true, 'fimca.com.br', self::ALLOWED, true, ['ti@fimca.com.br'])
        );
    }

    public function testPilotModeDoesNotBypassTheDomainCheck(): void
    {
        $this->assertSame(
            Outcome::DOMAIN_DENIED,
            LoginDecision::beforeDirectory('ti@outro.com', true, 'outro.com', self::ALLOWED, true, ['ti@outro.com'])
        );
    }

    public function testAfterDirectoryBlocksListedOus(): void
    {
        $blocklist = OuBlocklist::fromText('/fimca/fimca.com.br/ies-pvh/professores');

        $this->assertSame(
            Outcome::OU_BLOCKED,
            LoginDecision::afterDirectory('/FIMCA/fimca.com.br/IES-PVH/Professores/Medicina', 'fimca.com.br', $blocklist, 0)
        );
        $this->assertNull(
            LoginDecision::afterDirectory('/FIMCA/fimca.com.br/IES-PVH/Financeiro', 'fimca.com.br', $blocklist, 0)
        );
    }

    public function testAfterDirectoryChecksTheDomainSegmentOnlyWhenEnabled(): void
    {
        $empty = new OuBlocklist([]);

        $this->assertSame(
            Outcome::DOMAIN_MISMATCH,
            LoginDecision::afterDirectory('/fimca/grupoaparicio.com.br/x', 'fimca.com.br', $empty, 2)
        );
        $this->assertNull(
            LoginDecision::afterDirectory('/fimca/grupoaparicio.com.br/x', 'fimca.com.br', $empty, 0)
        );
        $this->assertNull(
            LoginDecision::afterDirectory('/fimca/fimca.com.br/x', 'fimca.com.br', $empty, 2)
        );
    }

    public function testTheBlocklistWinsOverTheDomainMismatch(): void
    {
        $blocklist = OuBlocklist::fromText('/fimca');

        $this->assertSame(
            Outcome::OU_BLOCKED,
            LoginDecision::afterDirectory('/fimca/grupoaparicio.com.br/x', 'fimca.com.br', $blocklist, 2)
        );
    }

    public function testAfterRules(): void
    {
        $this->assertSame(Outcome::OU_DENIED, LoginDecision::afterRules(true, true));
        $this->assertSame(Outcome::OU_DENIED, LoginDecision::afterRules(true, false));
        $this->assertSame(Outcome::OU_UNMAPPED, LoginDecision::afterRules(false, false));
        $this->assertNull(LoginDecision::afterRules(false, true));
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml --filter "IdentityMatcherTest|RuleResultTest|LoginDecisionTest"`
Expected: FAIL com `Class ... not found`.

- [ ] **Step 3: Implementar**

Crie `src/Sso/IdentityMatch.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Sso;

/** Result of IdentityMatcher::decide(). Named IdentityMatch because "match" is a PHP keyword. */
final class IdentityMatch
{
    public const USE_LINKED    = 'use_linked';
    public const LINK_EXISTING = 'link_existing';
    public const CREATE        = 'create';
    public const DENY          = 'deny';

    private function __construct(
        public readonly string $action,
        public readonly ?int $userId,
        public readonly ?string $outcome
    ) {}

    public static function useLinked(int $userId): self
    {
        return new self(self::USE_LINKED, $userId, null);
    }

    public static function linkExisting(int $userId): self
    {
        return new self(self::LINK_EXISTING, $userId, null);
    }

    public static function create(): self
    {
        return new self(self::CREATE, null, null);
    }

    public static function deny(string $outcome): self
    {
        return new self(self::DENY, null, $outcome);
    }
}
```

Crie `src/Sso/IdentityMatcher.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Sso;

/** Decides which GLPI user a Google login belongs to (spec S10, S11, S12). Pure. */
final class IdentityMatcher
{
    /** GLPI's Auth::DB_GLPI: a local account, with its own password. */
    private const AUTHTYPE_LOCAL = 1;

    /**
     * @param ?int      $linkedUserId       user already bound to this Google "sub", if any
     * @param list<int> $emailCandidateIds  non-deleted users whose e-mail matches
     */
    public static function decide(?int $linkedUserId, array $emailCandidateIds, bool $createAllowed): IdentityMatch
    {
        if ($linkedUserId !== null) {
            return IdentityMatch::useLinked($linkedUserId);
        }

        $candidates = array_values(array_unique($emailCandidateIds));
        if (count($candidates) === 1) {
            return IdentityMatch::linkExisting($candidates[0]);
        }
        if (count($candidates) > 1) {
            return IdentityMatch::deny(Outcome::EMAIL_AMBIGUOUS);
        }

        return $createAllowed ? IdentityMatch::create() : IdentityMatch::deny(Outcome::CREATE_DISABLED);
    }

    /** Local accounts (e.g. "glpi") are never converted automatically: they are the break-glass access. */
    public static function isConvertible(int $authtype): bool
    {
        return $authtype !== self::AUTHTYPE_LOCAL;
    }
}
```

Crie `src/Sso/RuleResult.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Sso;

/**
 * What the RuleRight engine produced for a login, in a form the rest of the module can reason
 * about without touching GLPI. Built from the engine's output array (see User::applyRightRules()
 * for the same shapes).
 */
final class RuleResult
{
    /** @param list<array{entities_id: int, profiles_id: int, is_recursive: int}> $grants */
    public function __construct(
        public readonly bool $denied,
        public readonly array $grants,
        public readonly ?int $defaultEntityId
    ) {}

    public function hasGrants(): bool
    {
        return $this->grants !== [];
    }

    /** @param array<string, mixed> $output */
    public static function fromOutput(array $output): self
    {
        $rules  = is_array($output['_ldap_rules'] ?? null) ? $output['_ldap_rules'] : [];
        $unique = [];

        $add = static function (int $entity, int $profile, int $recursive) use (&$unique): void {
            $unique[$entity . '-' . $profile . '-' . $recursive] = [
                'entities_id'  => $entity,
                'profiles_id'  => $profile,
                'is_recursive' => $recursive,
            ];
        };

        foreach ($rules['rules_entities_rights'] ?? [] as $row) {
            foreach ((array) $row[0] as $entityId) {
                $add((int) $entityId, (int) $row[1], (int) $row[2]);
            }
        }

        // Entity-only and profile-only actions combine; without any profile action GLPI uses its
        // default profile, which is reported here as profile 0.
        $profiles = $rules['rules_rights'] ?? [];
        foreach ($rules['rules_entities'] ?? [] as $row) {
            foreach ($profiles === [] ? [0] : $profiles as $profileId) {
                $add((int) $row[0], (int) $profileId, (int) $row[1]);
            }
        }

        $default = isset($output['entities_id']) && is_numeric($output['entities_id'])
            ? (int) $output['entities_id']
            : null;

        return new self(!empty($output['_deny_login']), array_values($unique), $default);
    }
}
```

Crie `src/Sso/LoginDecision.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Sso;

/**
 * The order of checks of spec S8 as three pure phases. Each returns null to keep going or the
 * Outcome code that denies the login.
 */
final class LoginDecision
{
    /**
     * Phase 1, before the Directory API is called: domain, verified e-mail and pilot mode.
     *
     * @param list<string> $allowedDomains
     * @param list<string> $pilotEmails lowercase
     */
    public static function beforeDirectory(
        string $email,
        bool $emailVerified,
        string $hostedDomain,
        array $allowedDomains,
        bool $pilotOnly,
        array $pilotEmails
    ): ?string {
        $denied = DomainPolicy::check($email, $emailVerified, $hostedDomain, $allowedDomains);
        if ($denied !== null) {
            return $denied;
        }

        if ($pilotOnly && !in_array(mb_strtolower(trim($email)), $pilotEmails, true)) {
            return Outcome::PILOT_BLOCKED;
        }

        return null;
    }

    /** Phase 2, once the OU is known: hard block (S9), then the optional domain x OU check (S18). */
    public static function afterDirectory(string $ouPath, string $emailDomain, OuBlocklist $blocklist, int $domainSegment): ?string
    {
        if ($blocklist->matches($ouPath) !== null) {
            return Outcome::OU_BLOCKED;
        }

        if ($domainSegment > 0 && !DomainPolicy::domainSegmentMatches($ouPath, $emailDomain, $domainSegment)) {
            return Outcome::DOMAIN_MISMATCH;
        }

        return null;
    }

    /** Phase 3, with the rules engine's verdict. A deny action wins over any grant. */
    public static function afterRules(bool $denied, bool $hasGrants): ?string
    {
        if ($denied) {
            return Outcome::OU_DENIED;
        }

        return $hasGrants ? null : Outcome::OU_UNMAPPED;
    }
}
```

- [ ] **Step 4: Cabeçalho e testes**

Run:
```bash
/c/xampp/php/php.exe var/tools/add-header.php src/Sso/IdentityMatch.php src/Sso/IdentityMatcher.php src/Sso/RuleResult.php src/Sso/LoginDecision.php tests/Unit/IdentityMatcherTest.php tests/Unit/RuleResultTest.php tests/Unit/LoginDecisionTest.php
/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml --filter "IdentityMatcherTest|RuleResultTest|LoginDecisionTest"
```
Expected: `OK (23 tests, ...)` (7 do IdentityMatcher + 7 do RuleResult + 9 do LoginDecision; confira que não há falhas).

- [ ] **Step 5: Checkpoint (sem commit)**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml`
Expected: todos passam.

---

### Task 5: SsoSettings, ServiceAccountJwt e SsoException (puras)

**Files:**
- Create: `src/Sso/SsoSettings.php`, `src/Sso/ServiceAccountJwt.php`, `src/Sso/SsoException.php`
- Test: `tests/Unit/SsoSettingsTest.php`, `tests/Unit/ServiceAccountJwtTest.php`

**Interfaces:**
- Consumes: `DomainPolicy::parseDomains/parseList`, `OuBlocklist::fromText` (Tarefas 1 e 2).
- Produces:
  - `SsoSettings::defaults(): array<string,string>`, `normalize(array): array<string,string>`, `isConfigured(array): bool`, `redirectUri(array $s, string $urlBase): string`, getters `enabled`, `clientId`, `clientSecret`, `allowedDomains` (`list<string>`), `saClientEmail`, `saPrivateKey`, `saAdminSubject`, `blockedOus` (`OuBlocklist`), `autoCreate`, `hideLocalForm`, `domainSegment` (`int`), `pilotOnly`, `pilotEmails` (`list<string>`), `revokeOnDeny`, `eventRetentionDays` (`int`), `buttonLabel`; constantes `CALLBACK_PATH`, chaves de segredo `SECURED_KEYS`.
  - `ServiceAccountJwt::build(string $clientEmail, string $privateKeyPem, string $subject, array $scopes, int $now, string $audience = 'https://oauth2.googleapis.com/token'): ?string`.
  - `SsoException extends \RuntimeException`.

- [ ] **Step 1: Escrever os testes que falham**

Crie `tests/Unit/SsoSettingsTest.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Tests\Unit;

use GlpiPlugin\Gac\Sso\SsoSettings;
use PHPUnit\Framework\TestCase;

final class SsoSettingsTest extends TestCase
{
    public function testDefaultsAreSafe(): void
    {
        $s = SsoSettings::defaults();

        $this->assertFalse(SsoSettings::enabled($s));
        $this->assertTrue(SsoSettings::autoCreate($s));
        $this->assertTrue(SsoSettings::hideLocalForm($s));
        $this->assertFalse(SsoSettings::pilotOnly($s));
        $this->assertTrue(SsoSettings::revokeOnDeny($s));
        $this->assertSame(0, SsoSettings::domainSegment($s));
        $this->assertSame(180, SsoSettings::eventRetentionDays($s));
        $this->assertSame('Entrar com Google', SsoSettings::buttonLabel($s));
        $this->assertFalse(SsoSettings::isConfigured($s));
    }

    public function testNormalizeCoercesFlagsAndNumbers(): void
    {
        $s = SsoSettings::normalize([
            'sso_enabled' => 'on', 'sso_auto_create' => '0', 'sso_hide_local_form' => 0,
            'sso_pilot_only' => true, 'sso_domain_segment' => '-4', 'sso_event_retention_days' => '2',
        ]);

        $this->assertTrue(SsoSettings::enabled($s));
        $this->assertFalse(SsoSettings::autoCreate($s));
        $this->assertFalse(SsoSettings::hideLocalForm($s));
        $this->assertTrue(SsoSettings::pilotOnly($s));
        $this->assertSame(0, SsoSettings::domainSegment($s));
        $this->assertSame(7, SsoSettings::eventRetentionDays($s));
    }

    public function testNormalizeDropsUnknownKeys(): void
    {
        $s = SsoSettings::normalize(['sso_nope' => 'x', 'monitor_alert_sound_url' => 'y']);

        $this->assertArrayNotHasKey('sso_nope', $s);
        $this->assertArrayNotHasKey('monitor_alert_sound_url', $s);
    }

    public function testPrivateKeyLiteralBackslashNBecomesRealNewlines(): void
    {
        $s = SsoSettings::normalize(['sso_sa_private_key' => '-----BEGIN PRIVATE KEY-----\nAAA\n-----END PRIVATE KEY-----\n']);

        $this->assertSame("-----BEGIN PRIVATE KEY-----\nAAA\n-----END PRIVATE KEY-----\n", SsoSettings::saPrivateKey($s));
    }

    public function testSecretsPassThroughUntouched(): void
    {
        $s = SsoSettings::normalize(['sso_client_secret' => '  GOCSPX-abc ']);

        $this->assertSame('  GOCSPX-abc ', SsoSettings::clientSecret($s));
    }

    public function testListGettersParseTheTextareas(): void
    {
        $s = SsoSettings::normalize([
            'sso_allowed_domains' => "@Fimca.com.br\ngrupoaparicio.com.br",
            'sso_pilot_emails'    => "TI@fimca.com.br, ana@fimca.com.br",
            'sso_blocked_ou_paths' => "/FIMCA/x/Professores\n# comentario\n",
        ]);

        $this->assertSame(['fimca.com.br', 'grupoaparicio.com.br'], SsoSettings::allowedDomains($s));
        $this->assertSame(['ti@fimca.com.br', 'ana@fimca.com.br'], SsoSettings::pilotEmails($s));
        $this->assertSame(['/fimca/x/professores'], SsoSettings::blockedOus($s)->paths());
    }

    public function testIsConfiguredNeedsEveryCredential(): void
    {
        $full = [
            'sso_enabled' => '1', 'sso_client_id' => 'id', 'sso_client_secret' => 'secret',
            'sso_allowed_domains' => 'fimca.com.br', 'sso_sa_client_email' => 'sa@p.iam.gserviceaccount.com',
            'sso_sa_private_key' => 'KEY', 'sso_sa_admin_subject' => 'admin@fimca.com.br',
        ];
        $this->assertTrue(SsoSettings::isConfigured(SsoSettings::normalize($full)));

        foreach (array_keys($full) as $missing) {
            $partial = $full;
            unset($partial[$missing]);
            $this->assertFalse(SsoSettings::isConfigured(SsoSettings::normalize($partial)), "missing $missing");
        }
    }

    public function testRedirectUriUsesTheOverrideOrTheUrlBase(): void
    {
        $default  = SsoSettings::normalize([]);
        $override = SsoSettings::normalize(['sso_redirect_uri' => ' http://localhost/plugins/gac/front/sso/callback.php ']);

        $this->assertSame(
            'https://glpi.exemplo.com.br/plugins/gac/front/sso/callback.php',
            SsoSettings::redirectUri($default, 'https://glpi.exemplo.com.br/')
        );
        $this->assertSame(
            'http://localhost/plugins/gac/front/sso/callback.php',
            SsoSettings::redirectUri($override, 'https://glpi.exemplo.com.br')
        );
    }
}
```

Crie `tests/Unit/ServiceAccountJwtTest.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Tests\Unit;

use GlpiPlugin\Gac\Sso\ServiceAccountJwt;
use PHPUnit\Framework\TestCase;

final class ServiceAccountJwtTest extends TestCase
{
    private static function decode(string $part): string
    {
        $b64 = strtr($part, '-_', '+/');
        $b64 .= str_repeat('=', (4 - strlen($b64) % 4) % 4);

        return (string) base64_decode($b64, true);
    }

    public function testBuildsASignedRs256Assertion(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            $this->markTestSkipped('openssl key generation unavailable (try OPENSSL_CONF=C:/xampp/apache/conf/openssl.cnf)');
        }
        openssl_pkey_export($key, $pem);
        $public = openssl_pkey_get_details($key)['key'];

        $jwt = ServiceAccountJwt::build(
            'sa@proj.iam.gserviceaccount.com',
            $pem,
            'admin@fimca.com.br',
            ['https://www.googleapis.com/auth/admin.directory.user.readonly'],
            1_800_000_000
        );

        $this->assertNotNull($jwt);
        [$h, $c, $s] = explode('.', $jwt);
        $header = json_decode(self::decode($h), true);
        $claims = json_decode(self::decode($c), true);

        $this->assertSame(['alg' => 'RS256', 'typ' => 'JWT'], $header);
        $this->assertSame('sa@proj.iam.gserviceaccount.com', $claims['iss']);
        $this->assertSame('admin@fimca.com.br', $claims['sub']);
        $this->assertSame('https://www.googleapis.com/auth/admin.directory.user.readonly', $claims['scope']);
        $this->assertSame('https://oauth2.googleapis.com/token', $claims['aud']);
        $this->assertSame(1_800_000_000, $claims['iat']);
        $this->assertSame(1_800_003_600, $claims['exp']);
        $this->assertSame(1, openssl_verify($h . '.' . $c, self::decode($s), $public, OPENSSL_ALGO_SHA256));
    }

    public function testJoinsScopesWithASpace(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            $this->markTestSkipped('openssl key generation unavailable');
        }
        openssl_pkey_export($key, $pem);

        $jwt = (string) ServiceAccountJwt::build('a', $pem, 'b', ['s1', 's2'], 1);
        $claims = json_decode(self::decode(explode('.', $jwt)[1]), true);

        $this->assertSame('s1 s2', $claims['scope']);
    }

    public function testAnInvalidKeyYieldsNull(): void
    {
        $this->assertNull(ServiceAccountJwt::build('a', 'not a key', 'b', ['s'], 1));
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml --filter "SsoSettingsTest|ServiceAccountJwtTest"`
Expected: FAIL com `Class ... not found`.

- [ ] **Step 3: Implementar**

Crie `src/Sso/SsoException.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Sso;

/** A Google or Directory API failure. The message is safe to log (never carries tokens or keys). */
final class SsoException extends \RuntimeException {}
```

Crie `src/Sso/SsoSettings.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Sso;

/**
 * Typed view over the raw SSO configuration stored in glpi_configs (context plugin:gac, keys
 * prefixed sso_). Pure: no GLPI calls. See spec section 5.3.
 */
final class SsoSettings
{
    public const CALLBACK_PATH = '/plugins/gac/front/sso/callback.php';

    /** Keys GLPI encrypts at rest (Hooks::SECURED_CONFIGS in setup.php). */
    public const SECURED_KEYS = ['sso_client_secret', 'sso_sa_private_key'];

    private const MIN_RETENTION_DAYS = 7;
    private const DEFAULT_BUTTON     = 'Entrar com Google';

    /** @return array<string, string> */
    public static function defaults(): array
    {
        return [
            'sso_enabled'              => '0',
            'sso_client_id'            => '',
            'sso_client_secret'        => '',
            'sso_redirect_uri'         => '',
            'sso_allowed_domains'      => '',
            'sso_sa_client_email'      => '',
            'sso_sa_private_key'       => '',
            'sso_sa_admin_subject'     => '',
            'sso_blocked_ou_paths'     => '',
            'sso_auto_create'          => '1',
            'sso_hide_local_form'      => '1',
            'sso_domain_segment'       => '0',
            'sso_pilot_only'           => '0',
            'sso_pilot_emails'         => '',
            'sso_revoke_on_deny'       => '1',
            'sso_event_retention_days' => '180',
            'sso_button_label'         => self::DEFAULT_BUTTON,
        ];
    }

    private static function flag(mixed $value): string
    {
        if (is_string($value)) {
            $value = strtolower(trim($value));

            return in_array($value, ['1', 'on', 'true', 'yes', 'sim'], true) ? '1' : '0';
        }

        return $value ? '1' : '0';
    }

    /**
     * Completes missing keys with defaults, coerces types and drops unknown keys.
     *
     * @param array<string, mixed> $raw
     * @return array<string, string>
     */
    public static function normalize(array $raw): array
    {
        $out = self::defaults();

        foreach (['sso_enabled', 'sso_auto_create', 'sso_hide_local_form', 'sso_pilot_only', 'sso_revoke_on_deny'] as $key) {
            if (array_key_exists($key, $raw)) {
                $out[$key] = self::flag($raw[$key]);
            }
        }

        foreach ([
            'sso_client_id', 'sso_redirect_uri', 'sso_allowed_domains', 'sso_sa_client_email',
            'sso_sa_admin_subject', 'sso_blocked_ou_paths', 'sso_pilot_emails',
        ] as $key) {
            if (array_key_exists($key, $raw)) {
                $out[$key] = trim((string) $raw[$key]);
            }
        }

        if (array_key_exists('sso_button_label', $raw)) {
            $label = trim((string) $raw['sso_button_label']);
            $out['sso_button_label'] = $label === '' ? self::DEFAULT_BUTTON : $label;
        }

        // Secrets are encrypted at rest by GLPI and decrypted before they reach normalize(), so
        // they are passed through untouched, except for the PEM line breaks: a key copied out of
        // the service account JSON file carries literal "\n" sequences instead of real newlines.
        if (array_key_exists('sso_client_secret', $raw)) {
            $out['sso_client_secret'] = (string) $raw['sso_client_secret'];
        }
        if (array_key_exists('sso_sa_private_key', $raw)) {
            $out['sso_sa_private_key'] = str_replace('\\n', "\n", (string) $raw['sso_sa_private_key']);
        }

        if (array_key_exists('sso_domain_segment', $raw)) {
            $out['sso_domain_segment'] = (string) max(0, is_numeric($raw['sso_domain_segment']) ? (int) $raw['sso_domain_segment'] : 0);
        }
        if (array_key_exists('sso_event_retention_days', $raw)) {
            $days = is_numeric($raw['sso_event_retention_days']) ? (int) $raw['sso_event_retention_days'] : 180;
            $out['sso_event_retention_days'] = (string) max(self::MIN_RETENTION_DAYS, $days);
        }

        return $out;
    }

    /** @param array<string, string> $s */
    public static function enabled(array $s): bool
    {
        return ($s['sso_enabled'] ?? '0') === '1';
    }

    /** @param array<string, string> $s */
    public static function clientId(array $s): string
    {
        return (string) ($s['sso_client_id'] ?? '');
    }

    /** @param array<string, string> $s */
    public static function clientSecret(array $s): string
    {
        return (string) ($s['sso_client_secret'] ?? '');
    }

    /**
     * @param array<string, string> $s
     * @return list<string>
     */
    public static function allowedDomains(array $s): array
    {
        return DomainPolicy::parseDomains((string) ($s['sso_allowed_domains'] ?? ''));
    }

    /** @param array<string, string> $s */
    public static function saClientEmail(array $s): string
    {
        return (string) ($s['sso_sa_client_email'] ?? '');
    }

    /** @param array<string, string> $s */
    public static function saPrivateKey(array $s): string
    {
        return (string) ($s['sso_sa_private_key'] ?? '');
    }

    /** @param array<string, string> $s */
    public static function saAdminSubject(array $s): string
    {
        return (string) ($s['sso_sa_admin_subject'] ?? '');
    }

    /** @param array<string, string> $s */
    public static function blockedOus(array $s): OuBlocklist
    {
        return OuBlocklist::fromText((string) ($s['sso_blocked_ou_paths'] ?? ''));
    }

    /** @param array<string, string> $s */
    public static function autoCreate(array $s): bool
    {
        return ($s['sso_auto_create'] ?? '1') === '1';
    }

    /** @param array<string, string> $s */
    public static function hideLocalForm(array $s): bool
    {
        return ($s['sso_hide_local_form'] ?? '1') === '1';
    }

    /** @param array<string, string> $s */
    public static function domainSegment(array $s): int
    {
        return (int) ($s['sso_domain_segment'] ?? 0);
    }

    /** @param array<string, string> $s */
    public static function pilotOnly(array $s): bool
    {
        return ($s['sso_pilot_only'] ?? '0') === '1';
    }

    /**
     * @param array<string, string> $s
     * @return list<string>
     */
    public static function pilotEmails(array $s): array
    {
        return DomainPolicy::parseList((string) ($s['sso_pilot_emails'] ?? ''));
    }

    /** @param array<string, string> $s */
    public static function revokeOnDeny(array $s): bool
    {
        return ($s['sso_revoke_on_deny'] ?? '1') === '1';
    }

    /** @param array<string, string> $s */
    public static function eventRetentionDays(array $s): int
    {
        return (int) ($s['sso_event_retention_days'] ?? 180);
    }

    /** @param array<string, string> $s */
    public static function buttonLabel(array $s): string
    {
        return (string) ($s['sso_button_label'] ?? self::DEFAULT_BUTTON);
    }

    /** Whether the Google login can run at all: switched on and every credential present. */
    public static function isConfigured(array $s): bool
    {
        return self::enabled($s)
            && self::clientId($s) !== ''
            && self::clientSecret($s) !== ''
            && self::allowedDomains($s) !== []
            && self::saClientEmail($s) !== ''
            && self::saPrivateKey($s) !== ''
            && self::saAdminSubject($s) !== '';
    }

    /** The OAuth redirect URI: the explicit override, or GLPI's url_base plus the callback path. */
    public static function redirectUri(array $s, string $urlBase): string
    {
        $override = trim((string) ($s['sso_redirect_uri'] ?? ''));

        return $override !== '' ? $override : rtrim($urlBase, '/') . self::CALLBACK_PATH;
    }
}
```

Crie `src/Sso/ServiceAccountJwt.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Sso;

/**
 * Builds the signed JWT assertion a Google service account trades for an access token
 * (OAuth 2.0 JWT bearer grant, with domain-wide delegation through the "sub" claim). Pure.
 */
final class ServiceAccountJwt
{
    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * @param list<string> $scopes
     * @return ?string the signed JWT, or null when the private key cannot be used
     */
    public static function build(
        string $clientEmail,
        string $privateKeyPem,
        string $subject,
        array $scopes,
        int $now,
        string $audience = 'https://oauth2.googleapis.com/token'
    ): ?string {
        $header = self::b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $claims = self::b64(json_encode([
            'iss'   => $clientEmail,
            'sub'   => $subject,
            'scope' => implode(' ', $scopes),
            'aud'   => $audience,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ], JSON_THROW_ON_ERROR));

        $input = $header . '.' . $claims;
        $key   = openssl_pkey_get_private($privateKeyPem);
        if ($key === false || !openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) {
            return null;
        }

        return $input . '.' . self::b64($signature);
    }
}
```

- [ ] **Step 4: Cabeçalho e testes**

Run:
```bash
/c/xampp/php/php.exe var/tools/add-header.php src/Sso/SsoException.php src/Sso/SsoSettings.php src/Sso/ServiceAccountJwt.php tests/Unit/SsoSettingsTest.php tests/Unit/ServiceAccountJwtTest.php
/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml --filter "SsoSettingsTest|ServiceAccountJwtTest"
```
Expected: `OK (11 tests, ...)` (8 + 3).

- [ ] **Step 5: Checkpoint (sem commit)**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml`
Expected: todos passam. A camada pura está completa.

---

### Task 6: Armazenamento, tabelas, direito, modelos e menu (GLPI)

**Files:**
- Create: `var/tools/gac-eval.php`, `var/tools/gac-install.php`
- Create: `src/Sso/SsoConfig.php`, `src/Sso/SsoIdentity.php`, `src/Sso/SsoEvent.php`, `src/Sso/SsoMenu.php`
- Modify: `setup.php` (SECURED_CONFIGS), `hook.php` (tabelas, direito, config padrão, cron, desinstalação), `src/Features.php`, `src/GacMenu.php`

**Interfaces:**
- Consumes: `SsoSettings::defaults()`, `SsoSettings::normalize()`, `SsoSettings::SECURED_KEYS` (Tarefa 5), `Outcome::UNDONE`.
- Produces:
  - `SsoConfig::CONTEXT`, `SsoConfig::load(): array<string,string>`, `SsoConfig::save(array): void`.
  - `SsoIdentity::$rightname = 'plugin_gac_sso'`, `SsoIdentity::RIGHT_CONFIG`, `SsoIdentity::findBySub(string): ?array`, `findByUserId(int): ?array`, `findById(int): ?array`, `link(int $usersId, string $sub, string $email, int $prevAuthtype, int $prevAuthsId, array $removed): int`, `touch(string $sub, string $ouPath): void`, `unlink(int $id): void`.
  - `SsoEvent::record(string $outcome, string $email, ?int $usersId, string $ouPath, string $detail = ''): int`, `SsoEvent::pendingOus(): list<array{ou_path: string, attempts: int, last_at: string}>`, `SsoEvent::purgeOlderThan(int $days): int`, `SsoEvent::cronSsoPurge(CronTask): int`, `SsoEvent::cronInfo(string): array`.
  - `SsoMenu::getMenuContent(): array`; `GacMenu::ITEM_SSO = 'sso'`.

- [ ] **Step 1: Criar os utilitários de desenvolvimento**

Crie `var/tools/gac-eval.php` (executa código PHP dentro do GLPI local):

```php
<?php

// Uso: php var/tools/gac-eval.php 'código php'
// Sobe o kernel do GLPI local e executa o código recebido. Só para desenvolvimento.
chdir('C:/Users/juliano/VSCode/glpi-xampp-dev-plugin');
require 'vendor/autoload.php';
$kernel = new \Glpi\Kernel\Kernel(null);
$kernel->boot();

global $DB, $PLUGIN_HOOKS, $CFG_GLPI;
$_SESSION['glpiactive_entity']           = 0;
$_SESSION['glpiactive_entity_recursive'] = 1;
$_SESSION['glpiactiveentities']          = [0];
$_SESSION['glpiactiveentities_string']   = '0';
$_SESSION['glpicronuserrunning']         = 'gac-eval';
$_SESSION['glpiname']                    = 'gac-eval';
$_SESSION['glpi_currenttime']            = date('Y-m-d H:i:s');

eval($argv[1] ?? '');
echo "\n";
```

Crie `var/tools/gac-install.php` (roda a instalação idempotente do plugin sem reinstalar):

```php
<?php

// Uso: php var/tools/gac-install.php
// Roda plugin_gac_install() no GLPI local. É idempotente, então os dados existentes ficam.
chdir('C:/Users/juliano/VSCode/glpi-xampp-dev-plugin');
require 'vendor/autoload.php';
$kernel = new \Glpi\Kernel\Kernel(null);
$kernel->boot();

$_SESSION['glpiactive_entity'] = 0;
$_SESSION['glpicronuserrunning'] = 'gac-install';
$_SESSION['glpiname'] = 'gac-install';

require_once 'C:/Users/juliano/VSCode/plugin-gac/hook.php';
var_dump(plugin_gac_install());
```

- [ ] **Step 2: Segredos no `setup.php`**

Em `setup.php`, troque a linha do `SECURED_CONFIGS`:

```php
        $PLUGIN_HOOKS[Hooks::SECURED_CONFIGS]['gac'] = ['monitor_service_password'];
```

por:

```php
        $PLUGIN_HOOKS[Hooks::SECURED_CONFIGS]['gac'] = ['monitor_service_password', 'sso_client_secret', 'sso_sa_private_key'];
```

- [ ] **Step 3: `SsoConfig`**

Crie `src/Sso/SsoConfig.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Sso;

use GLPIKey;

/** Storage of the SSO settings: glpi_configs, context plugin:gac, keys prefixed sso_. */
final class SsoConfig
{
    public const CONTEXT = 'plugin:gac';

    /** @return array<string, string> normalized settings (see SsoSettings) */
    public static function load(): array
    {
        $raw = \Config::getConfigurationValues(self::CONTEXT);
        // Encryption (SECURED_CONFIGS hook, setup.php) is only applied on write by
        // Config::setConfigurationValues(); the read side has to decrypt explicitly.
        foreach (SsoSettings::SECURED_KEYS as $key) {
            if (!empty($raw[$key])) {
                $raw[$key] = (string) (new GLPIKey())->decrypt($raw[$key]);
            }
        }

        return SsoSettings::normalize($raw);
    }

    /**
     * Merges the given keys over the current settings, so a partial save (for example from a
     * script or a form that omits a secret) never resets the other keys to their defaults.
     *
     * @param array<string, mixed> $raw
     */
    public static function save(array $raw): void
    {
        \Config::setConfigurationValues(self::CONTEXT, SsoSettings::normalize(array_merge(self::load(), $raw)));
    }
}
```

- [ ] **Step 4: `SsoIdentity`**

Crie `src/Sso/SsoIdentity.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Sso;

use CommonDBTM;
use GlpiPlugin\Gac\Features;

/**
 * Link between a GLPI user and a Google "sub" (spec S10, S11). It is also the item the
 * "Login com Google" profile right (plugin_gac_sso) hangs on; lists are drawn by SsoPages.
 */
class SsoIdentity extends CommonDBTM
{
    public static $rightname = 'plugin_gac_sso';

    public const RIGHT_CONFIG = Features::RIGHT_CONFIG;

    public $dohistory = false;

    /** Explicit: the class sits in a sub-namespace, GLPI's derived name would be wrong. */
    public static function getTable($classname = null)
    {
        if ($classname !== null && $classname !== static::class) {
            return parent::getTable($classname);
        }

        return 'glpi_plugin_gac_ssoidentities';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Identidade Google', 'Identidades Google', $nb, 'gac');
    }

    public static function getIcon()
    {
        return 'ti ti-brand-google';
    }

    /** Read-only data: the only rights that mean anything are "Ler" and "Configurar". */
    public function getRights($interface = 'central')
    {
        return [
            READ              => __('Read'),
            self::RIGHT_CONFIG => __('Configurar', 'gac'),
        ];
    }

    /** @return ?array<string, mixed> */
    public static function findBySub(string $sub): ?array
    {
        return self::findOne(['google_sub' => $sub]);
    }

    /** @return ?array<string, mixed> */
    public static function findByUserId(int $usersId): ?array
    {
        return self::findOne(['users_id' => $usersId]);
    }

    /** @return ?array<string, mixed> */
    public static function findById(int $id): ?array
    {
        return self::findOne(['id' => $id]);
    }

    /**
     * @param array<string, scalar> $where
     * @return ?array<string, mixed>
     */
    private static function findOne(array $where): ?array
    {
        global $DB;

        $rows = $DB->request(['FROM' => self::getTable(), 'WHERE' => $where, 'LIMIT' => 1]);
        foreach ($rows as $row) {
            return $row;
        }

        return null;
    }

    /**
     * @param list<array{entities_id: int, profiles_id: int, is_recursive: int}> $removed dynamic authorizations the user had before
     * @return int the new identity id
     */
    public static function link(int $usersId, string $sub, string $email, int $prevAuthtype, int $prevAuthsId, array $removed): int
    {
        global $DB;

        $DB->insert(self::getTable(), [
            'users_id'               => $usersId,
            'google_sub'             => $sub,
            'email_at_link'          => mb_substr($email, 0, 255),
            'prev_authtype'          => $prevAuthtype,
            'prev_auths_id'          => $prevAuthsId,
            'removed_authorizations' => json_encode($removed, JSON_THROW_ON_ERROR),
            'linked_at'              => date('Y-m-d H:i:s'),
            'last_login_at'          => date('Y-m-d H:i:s'),
        ]);

        return (int) $DB->insertId();
    }

    public static function touch(string $sub, string $ouPath): void
    {
        global $DB;

        $DB->update(
            self::getTable(),
            ['last_login_at' => date('Y-m-d H:i:s'), 'last_ou_path' => mb_substr($ouPath, 0, 500)],
            ['google_sub' => $sub]
        );
    }

    public static function unlink(int $id): void
    {
        global $DB;

        $DB->delete(self::getTable(), ['id' => $id]);
    }
}
```

- [ ] **Step 5: `SsoEvent`**

Crie `src/Sso/SsoEvent.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Sso;

use CronTask;

/**
 * Audit log of every Google login attempt (spec 5.2). Not a CommonDBTM on purpose: events are
 * written while nobody is logged in, so the usual add() rights checks do not apply. The id of an
 * event is the correlation code shown to the user on failure (S20).
 */
final class SsoEvent
{
    public static function getTable(): string
    {
        return 'glpi_plugin_gac_ssoevents';
    }

    /** @return int the event id (0 when the insert failed) */
    public static function record(string $outcome, string $email, ?int $usersId, string $ouPath, string $detail = ''): int
    {
        global $DB;

        $ok = $DB->insert(self::getTable(), [
            'date'     => date('Y-m-d H:i:s'),
            'email'    => mb_substr($email, 0, 255),
            'users_id' => (int) $usersId,
            'ou_path'  => mb_substr($ouPath, 0, 500),
            'outcome'  => mb_substr($outcome, 0, 40),
            'detail'   => mb_substr($detail, 0, 1000),
        ]);

        return $ok ? (int) $DB->insertId() : 0;
    }

    /**
     * OUs whose logins were denied for lack of a rule, most recent first.
     *
     * @return list<array{ou_path: string, attempts: int, last_at: string}>
     */
    public static function pendingOus(): array
    {
        global $DB;

        // Raw SQL: the query builder has no plain GROUP BY with aggregates that is worth the
        // guesswork. Only constants are interpolated.
        $table  = self::getTable();
        $result = $DB->doQuery(
            "SELECT `ou_path`, COUNT(*) AS `attempts`, MAX(`date`) AS `last_at`
             FROM `$table`
             WHERE `outcome` = '" . Outcome::OU_UNMAPPED . "' AND `ou_path` <> ''
             GROUP BY `ou_path`
             ORDER BY `last_at` DESC
             LIMIT 200"
        );

        $out = [];
        while ($row = $DB->fetchAssoc($result)) {
            $out[] = [
                'ou_path'  => (string) $row['ou_path'],
                'attempts' => (int) $row['attempts'],
                'last_at'  => (string) $row['last_at'],
            ];
        }

        return $out;
    }

    public static function purgeOlderThan(int $days): int
    {
        global $DB;

        $limit = date('Y-m-d H:i:s', time() - $days * 86400);
        $count = countElementsInTable(self::getTable(), ['date' => ['<', $limit]]);
        if ($count > 0) {
            $DB->delete(self::getTable(), ['date' => ['<', $limit]]);
        }

        return $count;
    }

    /** @return array<string, string> */
    public static function cronInfo(string $name): array
    {
        return ['description' => __('Expurgar eventos antigos do login com Google', 'gac')];
    }

    /** Automatic action "SsoPurge": removes events older than the configured retention. */
    public static function cronSsoPurge(CronTask $task): int
    {
        $days    = SsoSettings::eventRetentionDays(SsoConfig::load());
        $removed = self::purgeOlderThan($days);
        $task->addVolume($removed);

        return $removed > 0 ? 1 : 0;
    }
}
```

- [ ] **Step 6: `SsoMenu` e `GacMenu`**

Crie `src/Sso/SsoMenu.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Sso;

class SsoMenu
{
    public static function getMenuName($nb = 0): string
    {
        return __('Login com Google', 'gac');
    }

    public static function getMenuContent(): array
    {
        if (!SsoIdentity::canView()) {
            return [];
        }

        $page = \Plugin::getWebDir('gac', false) . '/front/sso/identities.php';

        return [
            'title' => self::getMenuName(),
            'page'  => $page,
            'icon'  => SsoIdentity::getIcon(),
            'links' => ['search' => $page],
        ];
    }
}
```

Em `src/GacMenu.php`: adicione `use GlpiPlugin\Gac\Sso\SsoMenu;` junto aos outros `use`; adicione a constante `public const ITEM_SSO = 'sso';` depois de `ITEM_MONITOR`; e em `getMenuContent()`, depois do bloco do `$monitor` e antes do `$config`, insira:

```php
        $sso = SsoMenu::getMenuContent();
        if ($sso !== []) {
            $entries[self::ITEM_SSO] = $sso;
        }
```

- [ ] **Step 7: Linha de direitos em `Features`**

Em `src/Features.php`: adicione `use GlpiPlugin\Gac\Sso\SsoIdentity;` e, no array de `all()`, depois da linha do `MonitorScreen`, acrescente:

```php
            [
                'itemtype' => SsoIdentity::class,
                'label'    => __('Login com Google', 'gac'),
                'field'    => SsoIdentity::$rightname,
            ],
```

- [ ] **Step 8: Tabelas, direito, configuração padrão e cron no `hook.php`**

Em `hook.php`, adicione ao bloco de `use` do topo (somente classes com namespace; as do GLPI como `CronTask`, `Config` e `ProfileRight` já são globais e **não** levam `use` neste arquivo):

```php
use GlpiPlugin\Gac\Sso\SsoEvent;
use GlpiPlugin\Gac\Sso\SsoIdentity;
use GlpiPlugin\Gac\Sso\SsoSettings;
```

Em `plugin_gac_install()`, logo **antes** da linha `$migration->executeMigration();` (no final da função), insira:

```php
    // SSO Google (spec seção 5): identities and the audit log. The rules themselves are native
    // RuleRight rules, so there are no mapping tables.
    $ssoIdentities = 'glpi_plugin_gac_ssoidentities';
    if (!$DB->tableExists($ssoIdentities)) {
        $DB->doQuery("CREATE TABLE `$ssoIdentities` (
            `id` INT {$sign} NOT NULL AUTO_INCREMENT,
            `users_id` INT {$sign} NOT NULL DEFAULT '0',
            `google_sub` VARCHAR(255) NOT NULL DEFAULT '',
            `email_at_link` VARCHAR(255) NOT NULL DEFAULT '',
            `prev_authtype` TINYINT NOT NULL DEFAULT '0',
            `prev_auths_id` INT {$sign} NOT NULL DEFAULT '0',
            `removed_authorizations` MEDIUMTEXT DEFAULT NULL,
            `linked_at` TIMESTAMP NULL DEFAULT NULL,
            `last_login_at` TIMESTAMP NULL DEFAULT NULL,
            `last_ou_path` VARCHAR(500) NOT NULL DEFAULT '',
            PRIMARY KEY (`id`),
            UNIQUE KEY `users_id` (`users_id`),
            UNIQUE KEY `google_sub` (`google_sub`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    }

    $ssoEvents = 'glpi_plugin_gac_ssoevents';
    if (!$DB->tableExists($ssoEvents)) {
        $DB->doQuery("CREATE TABLE `$ssoEvents` (
            `id` INT {$sign} NOT NULL AUTO_INCREMENT,
            `date` TIMESTAMP NULL DEFAULT NULL,
            `email` VARCHAR(255) NOT NULL DEFAULT '',
            `users_id` INT {$sign} NOT NULL DEFAULT '0',
            `ou_path` VARCHAR(500) NOT NULL DEFAULT '',
            `outcome` VARCHAR(40) NOT NULL DEFAULT '',
            `detail` VARCHAR(1000) NOT NULL DEFAULT '',
            PRIMARY KEY (`id`),
            KEY `date` (`date`),
            KEY `outcome` (`outcome`),
            KEY `ou_path` (`ou_path`(191))
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    }

    // SSO right: profiles that can UPDATE the native 'config' right get "Ler" + "Configurar";
    // every other profile starts without access.
    $ssoRight = SsoIdentity::$rightname;
    if (countElementsInTable(ProfileRight::getTable(), ['name' => $ssoRight]) === 0) {
        ProfileRight::addProfileRights([$ssoRight]);

        $sso_admin_profiles = array_column(
            iterator_to_array($DB->request([
                'SELECT' => 'profiles_id',
                'FROM'   => ProfileRight::getTable(),
                'WHERE'  => ['name' => 'config', 'rights' => ['&', UPDATE]],
            ])),
            'profiles_id'
        );
        if ($sso_admin_profiles !== []) {
            $DB->update(
                ProfileRight::getTable(),
                ['rights' => READ | SsoIdentity::RIGHT_CONFIG],
                ['name' => $ssoRight, 'profiles_id' => $sso_admin_profiles]
            );
        }
    }

    // Automatic action that purges old events (retention is a setting).
    if (countElementsInTable('glpi_crontasks', ['itemtype' => SsoEvent::class, 'name' => 'SsoPurge']) === 0) {
        CronTask::register(SsoEvent::class, 'SsoPurge', DAY_TIMESTAMP, [
            'comment' => 'Expurgar eventos antigos do login com Google',
            'mode'    => CronTask::MODE_EXTERNAL,
            'state'   => CronTask::STATE_WAITING,
        ]);
    }

```

No mesmo arquivo, na linha que monta os padrões de configuração, troque:

```php
        PreSettings::defaults() + LtbpSettings::defaults() + MonitorSettings::defaults(),
```

por:

```php
        PreSettings::defaults() + LtbpSettings::defaults() + MonitorSettings::defaults() + SsoSettings::defaults(),
```

Em `plugin_gac_uninstall()`: acrescente `'glpi_plugin_gac_ssoidentities'` e `'glpi_plugin_gac_ssoevents'` ao array de tabelas (depois de `'glpi_plugin_gac_monitorscreens'`); depois da linha `$DB->delete('glpi_displaypreferences', ['itemtype' => MonitorScreen::class]);` acrescente:

```php
    $DB->delete(ProfileRight::getTable(), ['name' => SsoIdentity::$rightname]);
    $DB->delete('glpi_crontasks', ['itemtype' => SsoEvent::class]);
```

e no `array_merge` de `Config::deleteConfigurationValues`, acrescente `array_keys(SsoSettings::defaults()),` depois da linha do `MonitorSettings`.

- [ ] **Step 9: Cabeçalho, sintaxe e instalação**

Run:
```bash
/c/xampp/php/php.exe var/tools/add-header.php src/Sso/SsoConfig.php src/Sso/SsoIdentity.php src/Sso/SsoEvent.php src/Sso/SsoMenu.php
for f in src/Sso/SsoConfig.php src/Sso/SsoIdentity.php src/Sso/SsoEvent.php src/Sso/SsoMenu.php hook.php setup.php src/Features.php src/GacMenu.php; do /c/xampp/php/php.exe -l $f; done
/c/xampp/php/php.exe var/tools/gac-install.php
```
Expected: `No syntax errors detected` em cada arquivo e `bool(true)` na instalação.

- [ ] **Step 10: Verificar no banco**

Run:
```bash
/c/xampp/php/php.exe var/tools/gac-eval.php 'global $DB; foreach (["glpi_plugin_gac_ssoidentities","glpi_plugin_gac_ssoevents"] as $t) { echo $t, ": ", $DB->tableExists($t) ? "ok" : "FALTA", "\n"; } echo "direitos: ", countElementsInTable("glpi_profilerights", ["name" => "plugin_gac_sso"]), " linhas\n"; echo "cron: ", countElementsInTable("glpi_crontasks", ["name" => "SsoPurge"]), "\n"; echo "sso_enabled: ", var_export(Config::getConfigurationValues("plugin:gac")["sso_enabled"] ?? null, true), "\n"; $id = GlpiPlugin\Gac\Sso\SsoEvent::record("ok", "teste@x.test", null, "/a", "smoke"); echo "evento: ", $id, "\n"; echo "removidos: ", GlpiPlugin\Gac\Sso\SsoEvent::purgeOlderThan(-1), "\n"; echo "restantes: ", countElementsInTable("glpi_plugin_gac_ssoevents"), "\n";'
```
Expected: as duas tabelas `ok`; `direitos:` igual ao número de perfis (6 no GLPI local); `cron: 1`; `sso_enabled: '0'`; `evento:` um id maior que 0; `removidos: 1` e `restantes: 0` (`purgeOlderThan(-1)` usa um limite no futuro, então apaga tudo, inclusive o evento recém-criado).

- [ ] **Step 11: Verificar o menu e o direito no navegador**

Faça login em `http://glpi11local.test/` como `glpi`/`glpi` (perfil Super-Admin), vá em Administração > Perfis > Super-Admin > aba do Gac e confirme que existe a linha "Login com Google" com as opções "Ler" e "Configurar" marcadas. Como o menu é cacheado na sessão (`$_SESSION['glpimenu']`), faça logout e login de novo e confirme que o item "Login com Google" aparece no menu do plugin (a página ainda dará 404 até a Tarefa 13; isso é esperado).
Expected: linha de direitos presente; item de menu presente.

- [ ] **Step 12: Checkpoint (sem commit)**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml`
Expected: todos passam (nenhum teste novo nesta tarefa; a verificação foi no GLPI).

---

### Task 7: Critério `GOOGLE_OU` e execução das regras (GLPI)

**Files:**
- Create: `src/Sso/RuleHooks.php`, `src/Sso/RuleRunner.php`, `var/tools/sso-rules-check.php`
- Modify: `setup.php` (registro dos hooks), `hook.php` (as duas funções de hook)

**Interfaces:**
- Consumes: `RuleResult::fromOutput()` (Tarefa 4).
- Produces: `RuleHooks::CRITERION = 'GOOGLE_OU'`, `RuleHooks::criteria(array): array`, `RuleHooks::inputData(array): array`; `RuleRunner::run(string $email, array $ancestors): array<string,mixed>` (saída crua do motor) e `RuleRunner::result(string $email, array $ancestors): RuleResult`; funções globais `plugin_gac_getRuleCriteria(array)` e `plugin_gac_ruleCollectionPrepareInputDataForProcess(array)`.

- [ ] **Step 1: `RuleHooks`**

Crie `src/Sso/RuleHooks.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Sso;

/**
 * Adds the "OU do Google Workspace" criterion to GLPI's authorization rules (RuleRight) and feeds
 * its value to the engine (spec S4). The value is the list of ancestor paths of the user's OU, so
 * a rule "OU do Google is /a/b" matches /a/b and everything below it. Rules must use only the
 * "is" condition on this criterion (spec V15).
 */
final class RuleHooks
{
    public const CRITERION = 'GOOGLE_OU';

    /**
     * Hook getRuleCriteria. Receives ['rule_itemtype' => ..., 'values' => current criteria].
     *
     * @param array<string, mixed> $params
     * @return array<string, array<string, mixed>>
     */
    public static function criteria(array $params): array
    {
        if (($params['rule_itemtype'] ?? '') !== \RuleRight::class) {
            return [];
        }

        return [
            self::CRITERION => [
                'name'      => __('OU do Google Workspace', 'gac'),
                'field'     => '',
                'table'     => '',
                'linkfield' => '',
                'virtual'   => true,
                'id'        => 'google_ou',
            ],
        ];
    }

    /**
     * Hook ruleCollectionPrepareInputDataForProcess. Receives
     * ['rule_itemtype' => ..., 'values' => ['input' => ..., 'params' => the processAllRules() params]].
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function inputData(array $params): array
    {
        if (($params['rule_itemtype'] ?? '') !== \RuleRight::class) {
            return [];
        }

        $ancestors = $params['values']['params']['google_ou'] ?? null;

        return is_array($ancestors) ? [self::CRITERION => $ancestors] : [];
    }
}
```

- [ ] **Step 2: `RuleRunner`**

Crie `src/Sso/RuleRunner.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Sso;

/** Runs GLPI's authorization rules for a Google login. */
final class RuleRunner
{
    /**
     * @param list<string> $ancestors OuPath::ancestors() of the user's OU
     * @return array<string, mixed> the engine's output array
     */
    public static function run(string $email, array $ancestors): array
    {
        $collection = new \RuleRightCollection();

        // The output is seeded with the name only: if "entities_id" shows up in it afterwards, a
        // rule set the default entity (the _entities_id_default action).
        return $collection->processAllRules([], ['name' => $email], [
            'type'      => \Auth::EXTERNAL,
            'login'     => $email,
            'email'     => $email,
            'google_ou' => $ancestors,
        ]);
    }

    /** @param list<string> $ancestors */
    public static function result(string $email, array $ancestors): RuleResult
    {
        return RuleResult::fromOutput(self::run($email, $ancestors));
    }
}
```

- [ ] **Step 3: Registro nos arquivos do plugin**

Em `setup.php`, dentro do `if ($plugin->isInstalled('gac') && $plugin->isActivated('gac')) { ... }`, depois da linha do `SECURED_CONFIGS`, acrescente:

```php

        // SSO Google (spec S4): the plugin adds a criterion to the native authorization rules.
        // The value is an array of rule types, not "true".
        $PLUGIN_HOOKS[Hooks::USE_RULES]['gac'] = [RuleRight::class];
```

Em `hook.php`, no fim do arquivo, depois de `plugin_gac_uninstall()`, acrescente (e adicione `use GlpiPlugin\Gac\Sso\RuleHooks;` ao bloco de `use` do topo):

```php

/**
 * Hook "getRuleCriteria": adds the "OU do Google Workspace" criterion to RuleRight (SSO, spec S4).
 *
 * @param array<string, mixed> $params
 * @return array<string, array<string, mixed>>
 */
function plugin_gac_getRuleCriteria(array $params): array
{
    return RuleHooks::criteria($params);
}

/**
 * Hook "ruleCollectionPrepareInputDataForProcess": hands the OU ancestors to the rules engine.
 *
 * @param array<string, mixed> $params
 * @return array<string, mixed>
 */
function plugin_gac_ruleCollectionPrepareInputDataForProcess(array $params): array
{
    return RuleHooks::inputData($params);
}
```

- [ ] **Step 4: Script de verificação contra regras reais**

Crie `var/tools/sso-rules-check.php` (cria regras temporárias, roda o `RuleRunner` e apaga tudo):

```php
<?php

// Uso: php var/tools/sso-rules-check.php
// Cria regras de autorização temporárias "SSOCHECK", exercita RuleRunner e apaga tudo.
chdir('C:/Users/juliano/VSCode/glpi-xampp-dev-plugin');
require 'vendor/autoload.php';
$kernel = new \Glpi\Kernel\Kernel(null);
$kernel->boot();

global $DB;
$_SESSION['glpiactive_entity']           = 0;
$_SESSION['glpiactive_entity_recursive'] = 1;
$_SESSION['glpiactiveentities']          = [0];
$_SESSION['glpiactiveentities_string']   = '0';
$_SESSION['glpicronuserrunning']         = 'sso-check';
$_SESSION['glpiname']                    = 'sso-check';
$_SESSION['glpi_currenttime']            = date('Y-m-d H:i:s');

use GlpiPlugin\Gac\Sso\OuPath;
use GlpiPlugin\Gac\Sso\RuleRunner;

echo 'plugin ativo: ', var_export(Plugin::isPluginActive('gac'), true), "\n";
echo 'critério registrado: ', var_export(isset((new RuleRight())->getAllCriteria()['GOOGLE_OU']), true), "\n";

$ents  = array_keys(iterator_to_array($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_entities', 'LIMIT' => 4]), true));
$entA  = (int) ($ents[1] ?? 0);
$entB  = (int) ($ents[2] ?? 0);
$ids   = [];

$mk = function (string $name, array $criteria, array $actions, int $rank) use (&$ids, $DB): void {
    $rule = new RuleRight();
    $id   = $rule->add([
        'name' => 'SSOCHECK ' . $name, 'sub_type' => 'RuleRight', 'match' => 'AND',
        'is_active' => 1, 'entities_id' => 0, 'is_recursive' => 1, 'ranking' => $rank,
    ]);
    $DB->update('glpi_rules', ['ranking' => $rank], ['id' => $id]);
    foreach ($criteria as [$field, $cond, $pattern]) {
        (new RuleCriteria())->add(['rules_id' => $id, 'criteria' => $field, 'condition' => $cond, 'pattern' => $pattern]);
    }
    foreach ($actions as [$field, $type, $value]) {
        (new RuleAction())->add(['rules_id' => $id, 'action_type' => $type, 'field' => $field, 'value' => $value]);
    }
    $ids[] = $id;
};

try {
    $mk('deny professores', [['GOOGLE_OU', Rule::PATTERN_IS, '/fimca/fimca.com.br/ies-pvh/professores']],
        [['_deny_login', 'assign', 1], ['_stop_rules_processing', 'assign', 1]], 9001);
    $mk('financeiro', [['GOOGLE_OU', Rule::PATTERN_IS, '/fimca/fimca.com.br/ies-pvh/financeiro']],
        [['entities_id', 'assign', $entA], ['profiles_id', 'assign', 1], ['is_recursive', 'assign', 0], ['_entities_id_default', 'assign', $entA], ['_stop_rules_processing', 'assign', 1]], 9002);
    $mk('unidade PVH', [['GOOGLE_OU', Rule::PATTERN_IS, '/FIMCA/Fimca.com.br/IES-PVH']],
        [['entities_id', 'assign', $entB], ['profiles_id', 'assign', 1], ['is_recursive', 'assign', 0], ['_stop_rules_processing', 'assign', 1]], 9003);
    $mk('TI por e-mail', [['MAIL_EMAIL', Rule::PATTERN_IS, 'ti@example.test']],
        [['entities_id', 'assign', 0], ['profiles_id', 'assign', 4], ['is_recursive', 'assign', 1]], 9000);

    $cases = [
        'professor (esperado: negado)'                    => ['/FIMCA/fimca.com.br/IES-PVH/Professores', 'a@example.test', true, 0],
        'financeiro (esperado: 1 autorização, padrão ' . $entA . ')' => ['/FIMCA/fimca.com.br/IES-PVH/Financeiro', 'b@example.test', false, 1],
        'biblioteca (herda a unidade, entidade ' . $entB . ')'       => ['/FIMCA/fimca.com.br/IES-PVH/Biblioteca', 'c@example.test', false, 1],
        'OU sem regra (esperado: nenhuma autorização)'    => ['/FIMCA/outro/xyz', 'd@example.test', false, 0],
        'TI por e-mail (esperado: 1 autorização)'         => ['/FIMCA/outro/xyz', 'ti@example.test', false, 1],
    ];
    $failed = 0;
    foreach ($cases as $label => [$ou, $email, $denied, $grants]) {
        $result = RuleRunner::result($email, OuPath::ancestors($ou));
        $ok = $result->denied === $denied && count($result->grants) === $grants;
        $failed += $ok ? 0 : 1;
        echo ($ok ? 'OK    ' : 'FALHOU'), ' ', $label, ' => denied=', var_export($result->denied, true),
            ' grants=', json_encode($result->grants), ' default=', var_export($result->defaultEntityId, true), "\n";
    }
    echo $failed === 0 ? "TUDO CERTO\n" : "$failed CASO(S) FALHARAM\n";
} finally {
    foreach ($ids as $id) {
        $DB->delete('glpi_ruleactions', ['rules_id' => $id]);
        $DB->delete('glpi_rulecriterias', ['rules_id' => $id]);
        $DB->delete('glpi_rules', ['id' => $id]);
    }
    echo 'regras temporárias removidas: ', count($ids), "\n";
}
```

- [ ] **Step 5: Cabeçalho, sintaxe e verificação**

Run:
```bash
/c/xampp/php/php.exe var/tools/add-header.php src/Sso/RuleHooks.php src/Sso/RuleRunner.php
for f in src/Sso/RuleHooks.php src/Sso/RuleRunner.php hook.php setup.php; do /c/xampp/php/php.exe -l $f; done
/c/xampp/php/php.exe var/tools/sso-rules-check.php
```
Expected:
```
plugin ativo: true
critério registrado: true
OK     professor (esperado: negado) => denied=true grants=[] default=NULL
OK     financeiro (...) => denied=false grants=[{...}] default=<id da entidade>
OK     biblioteca (...) => ...
OK     OU sem regra (...) => denied=false grants=[] default=NULL
OK     TI por e-mail (...) => ...
TUDO CERTO
regras temporárias removidas: 4
```
Se `critério registrado: false`, confira que a linha `$PLUGIN_HOOKS[Hooks::USE_RULES]['gac'] = [RuleRight::class];` está dentro do `if` ativado do `setup.php` e que a função `plugin_gac_getRuleCriteria` está em `hook.php`.

- [ ] **Step 6: Verificar a tela de regras no navegador**

Em `http://glpi11local.test/front/rule.right.php` (Administração > Regras > Regras de autorização), crie uma regra de teste com **"Adicionar um critério"** e confirme que a lista de critérios inclui **"OU do Google Workspace"** e que o campo de valor aceita texto livre. Salve a regra e apague-a em seguida.
Expected: critério visível e utilizável (verificação V13 da spec). Se o campo de valor não aparecer, registre o comportamento e pare: o desenho do critério precisa de ajuste (por exemplo `'type' => 'text'`) antes de seguir.

- [ ] **Step 7: Checkpoint (sem commit)**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml`
Expected: todos passam.

---

### Task 8: DirectoryClient e GoogleClient (GLPI)

**Files:**
- Create: `src/Sso/DirectoryClient.php`, `src/Sso/GoogleClient.php`

**Interfaces:**
- Consumes: `ServiceAccountJwt::build()`, `SsoSettings::*`, `IdToken::*`, `SsoException` (Tarefas 3 e 5).
- Produces:
  - `new DirectoryClient(array $settings)`, `->orgUnitPath(string $email): string` (lança `SsoException`).
  - `new GoogleClient(array $settings, string $redirectUri)`, `->begin(): array{url: string, state: string, nonce: string, pkce: string}`, `->claimsFromCode(string $code, string $pkceVerifier, string $nonce): array<string,mixed>` (lança `SsoException`).

- [ ] **Step 1: `DirectoryClient`**

Crie `src/Sso/DirectoryClient.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Sso;

/**
 * Reads a user's org unit from the Google Admin SDK Directory API, using a service account with
 * domain-wide delegation that impersonates a read-only admin (spec S3).
 */
final class DirectoryClient
{
    private const TOKEN_URI = 'https://oauth2.googleapis.com/token';
    private const USERS_URI = 'https://admin.googleapis.com/admin/directory/v1/users/';
    private const SCOPES    = ['https://www.googleapis.com/auth/admin.directory.user.readonly'];

    /** @param array<string, string> $settings SsoConfig::load() */
    public function __construct(private readonly array $settings) {}

    /** @throws SsoException */
    public function orgUnitPath(string $email): string
    {
        $client = \Toolbox::getGuzzleClient();
        $token  = $this->accessToken($client);

        try {
            $response = $client->get(self::USERS_URI . rawurlencode($email), [
                'headers'     => ['Authorization' => 'Bearer ' . $token],
                'query'       => ['fields' => 'orgUnitPath,suspended'],
                'timeout'     => 10,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            throw new SsoException('Directory API unreachable: ' . $e->getMessage());
        }

        $status = $response->getStatusCode();
        $body   = json_decode((string) $response->getBody(), true);

        if ($status === 404) {
            throw new SsoException('Directory user not found');
        }
        if ($status !== 200 || !is_array($body)) {
            throw new SsoException('Directory API returned HTTP ' . $status);
        }
        if (!empty($body['suspended'])) {
            throw new SsoException('Directory user is suspended');
        }

        $path = $body['orgUnitPath'] ?? null;
        if (!is_string($path) || $path === '') {
            throw new SsoException('Directory user has no orgUnitPath');
        }

        return $path;
    }

    /** @throws SsoException */
    private function accessToken(\GuzzleHttp\Client $client): string
    {
        $jwt = ServiceAccountJwt::build(
            SsoSettings::saClientEmail($this->settings),
            SsoSettings::saPrivateKey($this->settings),
            SsoSettings::saAdminSubject($this->settings),
            self::SCOPES,
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

- [ ] **Step 2: `GoogleClient`**

Crie `src/Sso/GoogleClient.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Sso;

use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Google;

/**
 * The OAuth 2.0 / OpenID Connect side of the login with Google: builds the authorization URL
 * (state, nonce, PKCE S256) and trades the returned code for verified ID token claims.
 * Uses league/oauth2-google, which GLPI itself ships for the mail collector.
 */
final class GoogleClient
{
    private const CERTS_URL = 'https://www.googleapis.com/oauth2/v1/certs';

    /** @param array<string, string> $settings SsoConfig::load() */
    public function __construct(
        private readonly array $settings,
        private readonly string $redirectUri
    ) {}

    private function provider(): Google
    {
        return new Google(
            [
                'clientId'     => SsoSettings::clientId($this->settings),
                'clientSecret' => SsoSettings::clientSecret($this->settings),
                'redirectUri'  => $this->redirectUri,
                'pkceMethod'   => AbstractProvider::PKCE_METHOD_S256,
            ],
            ['httpClient' => \Toolbox::getGuzzleClient()]
        );
    }

    /** @return array{url: string, state: string, nonce: string, pkce: string} */
    public function begin(): array
    {
        $provider = $this->provider();
        $nonce    = bin2hex(random_bytes(16));

        $url = $provider->getAuthorizationUrl([
            'scope'  => ['openid', 'email', 'profile'],
            // "*" restricts the account chooser to Google Workspace accounts of any domain; the
            // real allow-list is enforced after the login (DomainPolicy).
            'hd'     => '*',
            'prompt' => 'select_account',
            'nonce'  => $nonce,
        ]);

        return [
            'url'   => $url,
            'state' => (string) $provider->getState(),
            'nonce' => $nonce,
            'pkce'  => (string) $provider->getPkceCode(),
        ];
    }

    /**
     * @return array<string, mixed> the verified ID token claims
     * @throws SsoException
     */
    public function claimsFromCode(string $code, string $pkceVerifier, string $nonce): array
    {
        $provider = $this->provider();
        $provider->setPkceCode($pkceVerifier);

        try {
            $token = $provider->getAccessToken('authorization_code', ['code' => $code]);
        } catch (\Throwable $e) {
            throw new SsoException('Token exchange failed: ' . $e->getMessage());
        }

        $raw = $token->getValues()['id_token'] ?? null;
        if (!is_string($raw)) {
            throw new SsoException('Token response has no id_token');
        }

        $decoded = IdToken::decode($raw);
        if ($decoded === null) {
            throw new SsoException('Malformed ID token');
        }
        if (!IdToken::verifySignature($decoded, $this->certificates())) {
            throw new SsoException('ID token signature invalid');
        }

        $invalid = IdToken::validateClaims($decoded['claims'], SsoSettings::clientId($this->settings), $nonce, time());
        if ($invalid !== null) {
            throw new SsoException('ID token claim invalid: ' . $invalid);
        }

        return $decoded['claims'];
    }

    /**
     * Google's current signing certificates, key id => PEM certificate.
     *
     * @return array<string, string>
     * @throws SsoException
     */
    private function certificates(): array
    {
        try {
            $response = \Toolbox::getGuzzleClient()->get(self::CERTS_URL, ['timeout' => 10, 'http_errors' => false]);
        } catch (\Throwable $e) {
            throw new SsoException('Google certificates unreachable: ' . $e->getMessage());
        }

        $certs = json_decode((string) $response->getBody(), true);
        if ($response->getStatusCode() !== 200 || !is_array($certs) || $certs === []) {
            throw new SsoException('Google certificates unavailable: HTTP ' . $response->getStatusCode());
        }

        return array_map('strval', $certs);
    }
}
```

- [ ] **Step 3: Cabeçalho e sintaxe**

Run:
```bash
/c/xampp/php/php.exe var/tools/add-header.php src/Sso/DirectoryClient.php src/Sso/GoogleClient.php
for f in src/Sso/DirectoryClient.php src/Sso/GoogleClient.php; do /c/xampp/php/php.exe -l $f; done
```
Expected: `No syntax errors detected`.

- [ ] **Step 4: Verificar o `GoogleClient::begin()` sem rede**

Run:
```bash
/c/xampp/php/php.exe var/tools/gac-eval.php '$c = new GlpiPlugin\Gac\Sso\GoogleClient(["sso_client_id" => "cid.apps.googleusercontent.com", "sso_client_secret" => "x"], "http://localhost/plugins/gac/front/sso/callback.php"); $r = $c->begin(); echo $r["url"], "\n"; echo "state=", strlen($r["state"]), " nonce=", strlen($r["nonce"]), " pkce=", strlen($r["pkce"]), "\n";'
```
Expected: uma URL `https://accounts.google.com/o/oauth2/v2/auth?...` contendo `scope=openid email profile`, `hd=*`, `nonce=`, `code_challenge=` e `code_challenge_method=S256`, e a linha `state=32 nonce=32 pkce=` com um número maior que 40. Se faltar `code_challenge`, a opção `pkceMethod` não foi aceita pelo provider; confira `League\OAuth2\Client\Provider\AbstractProvider` no `vendor/` do GLPI e ajuste o nome da opção.

- [ ] **Step 5: Checkpoint (sem commit)**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml`
Expected: todos passam. A verificação real contra o Google (Directory API e troca do código) acontece na Tarefa 11, com credenciais.

---

### Task 9: UserProvisioner (GLPI)

**Files:**
- Create: `src/Sso/UserProvisioner.php`

**Interfaces:**
- Consumes: `SsoIdentity::link/findById/unlink`, `SsoEvent::record`, `Outcome::UNDONE`, `RuleResult` (Tarefas 4 e 6).
- Produces:
  - `UserProvisioner::candidateIdsByEmail(string $email): list<int>`
  - `UserProvisioner::load(int $id): ?\User`
  - `UserProvisioner::convertExisting(\User $user, string $sub, string $email): int` (devolve o id da identidade)
  - `UserProvisioner::create(array $claims, ?int $defaultEntityId): ?\User`
  - `UserProvisioner::applyRules(\User $user, array $ruleOutput): void`
  - `UserProvisioner::revokeDynamic(int $usersId): int`
  - `UserProvisioner::undo(int $identityId): bool`

- [ ] **Step 1: Implementar**

Crie `src/Sso/UserProvisioner.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Sso;

/**
 * Everything that changes a GLPI user because of a Google login: finding candidates, creating,
 * converting an AD user (with a snapshot to undo it), applying the rules through GLPI's own
 * User::applyRightRules(), and revoking dynamic authorizations (spec S11 to S15).
 */
final class UserProvisioner
{
    /**
     * Non-deleted users whose e-mail or login equals the address.
     *
     * @return list<int>
     */
    public static function candidateIdsByEmail(string $email): array
    {
        global $DB;

        $ids = [];

        $byMail = $DB->request([
            'SELECT'     => 'glpi_useremails.users_id',
            'FROM'       => 'glpi_useremails',
            'INNER JOIN' => [
                'glpi_users' => ['ON' => ['glpi_useremails' => 'users_id', 'glpi_users' => 'id']],
            ],
            'WHERE'      => ['glpi_useremails.email' => $email, 'glpi_users.is_deleted' => 0],
        ]);
        foreach ($byMail as $row) {
            $ids[(int) $row['users_id']] = true;
        }

        $byName = $DB->request([
            'SELECT' => 'id',
            'FROM'   => 'glpi_users',
            'WHERE'  => ['name' => $email, 'is_deleted' => 0],
        ]);
        foreach ($byName as $row) {
            $ids[(int) $row['id']] = true;
        }

        return array_keys($ids);
    }

    public static function load(int $id): ?\User
    {
        $user = new \User();

        return $user->getFromDB($id) ? $user : null;
    }

    /** @return list<array{entities_id: int, profiles_id: int, is_recursive: int}> */
    private static function dynamicSnapshot(int $usersId): array
    {
        global $DB;

        $rows = [];
        $iterator = $DB->request([
            'SELECT' => ['entities_id', 'profiles_id', 'is_recursive'],
            'FROM'   => 'glpi_profiles_users',
            'WHERE'  => ['users_id' => $usersId, 'is_dynamic' => 1],
        ]);
        foreach ($iterator as $row) {
            $rows[] = [
                'entities_id'  => (int) $row['entities_id'],
                'profiles_id'  => (int) $row['profiles_id'],
                'is_recursive' => (int) $row['is_recursive'],
            ];
        }

        return $rows;
    }

    /**
     * Binds an existing user (typically from the AD) to the Google "sub" and switches its
     * authentication to external. The previous authtype and dynamic authorizations are stored so
     * "Desfazer conversão" can restore them (S11).
     *
     * @return int the new identity id
     */
    public static function convertExisting(\User $user, string $sub, string $email): int
    {
        global $DB;

        $id       = $user->getID();
        $snapshot = self::dynamicSnapshot($id);
        $previous = [(int) $user->fields['authtype'], (int) $user->fields['auths_id']];

        $DB->update('glpi_users', ['authtype' => \Auth::EXTERNAL, 'auths_id' => 0], ['id' => $id]);

        return SsoIdentity::link($id, $sub, $email, $previous[0], $previous[1], $snapshot);
    }

    /**
     * Creates a user for a Google account (S12). The login (name) is the full e-mail.
     *
     * @param array<string, mixed> $claims verified ID token claims
     */
    public static function create(array $claims, ?int $defaultEntityId): ?\User
    {
        $email = mb_strtolower((string) $claims['email']);
        $user  = new \User();

        $id = $user->add([
            'name'         => $email,
            'realname'     => (string) ($claims['family_name'] ?? ''),
            'firstname'    => (string) ($claims['given_name'] ?? ''),
            'authtype'     => \Auth::EXTERNAL,
            'auths_id'     => 0,
            'is_active'    => 1,
            'entities_id'  => $defaultEntityId ?? 0,
            '_extauth'     => 1,
            '_useremails'  => [$email],
        ]);

        return $id ? self::load((int) $id) : null;
    }

    /**
     * Applies the engine's result with GLPI's own pipeline (the same one the LDAP login uses):
     * it creates, updates and deletes only the dynamic authorizations and leaves the manual ones.
     * The default entity (the _entities_id_default action) is stored on the user.
     *
     * @param array<string, mixed> $ruleOutput RuleRunner::run() output
     */
    public static function applyRules(\User $user, array $ruleOutput): void
    {
        global $DB;

        $user->input = $ruleOutput;
        $user->willProcessRuleRight();
        $user->applyRightRules();

        if (isset($ruleOutput['entities_id']) && is_numeric($ruleOutput['entities_id'])) {
            $DB->update('glpi_users', ['entities_id' => (int) $ruleOutput['entities_id']], ['id' => $user->getID()]);
        }
    }

    /** Removes the user's dynamic authorizations (S15). @return int how many were removed */
    public static function revokeDynamic(int $usersId): int
    {
        $count = countElementsInTable('glpi_profiles_users', ['users_id' => $usersId, 'is_dynamic' => 1]);
        if ($count > 0) {
            (new \Profile_User())->deleteByCriteria(['users_id' => $usersId, 'is_dynamic' => 1], true);
        }

        return $count;
    }

    /**
     * "Desfazer conversão": puts back the previous authtype and the dynamic authorizations stored
     * when the account was linked, and drops the identity.
     */
    public static function undo(int $identityId): bool
    {
        global $DB;

        $identity = SsoIdentity::findById($identityId);
        if ($identity === null) {
            return false;
        }

        $usersId = (int) $identity['users_id'];
        $DB->update('glpi_users', [
            'authtype' => (int) $identity['prev_authtype'],
            'auths_id' => (int) $identity['prev_auths_id'],
        ], ['id' => $usersId]);

        self::revokeDynamic($usersId);
        $snapshot = json_decode((string) $identity['removed_authorizations'], true);
        foreach (is_array($snapshot) ? $snapshot : [] as $row) {
            (new \Profile_User())->add([
                'users_id'     => $usersId,
                'entities_id'  => (int) $row['entities_id'],
                'profiles_id'  => (int) $row['profiles_id'],
                'is_recursive' => (int) $row['is_recursive'],
                'is_dynamic'   => 1,
            ]);
        }

        SsoIdentity::unlink($identityId);
        SsoEvent::record(Outcome::UNDONE, (string) $identity['email_at_link'], $usersId, (string) $identity['last_ou_path'], 'conversion undone');

        return true;
    }
}
```

- [ ] **Step 2: Cabeçalho e sintaxe**

Run:
```bash
/c/xampp/php/php.exe var/tools/add-header.php src/Sso/UserProvisioner.php
/c/xampp/php/php.exe -l src/Sso/UserProvisioner.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 3: Verificar o ciclo criar, aplicar, converter e desfazer**

Crie um usuário LDAP de mentira e rode o ciclo completo. Run:

```bash
/c/xampp/php/php.exe var/tools/gac-eval.php '
use GlpiPlugin\Gac\Sso\{UserProvisioner, SsoIdentity, RuleRunner, OuPath};
$pu = new Profile_User();
$ent = (int) array_keys(iterator_to_array($DB->request(["SELECT" => "id", "FROM" => "glpi_entities", "LIMIT" => 3]), true))[1];

// regra temporária: OU /prov/x -> entidade $ent, perfil 1, padrão $ent
$rule = new RuleRight(); $rid = $rule->add(["name" => "PROVCHECK", "sub_type" => "RuleRight", "match" => "AND", "is_active" => 1, "entities_id" => 0, "is_recursive" => 1, "ranking" => 9500]);
$DB->update("glpi_rules", ["ranking" => 9500], ["id" => $rid]);
(new RuleCriteria())->add(["rules_id" => $rid, "criteria" => "GOOGLE_OU", "condition" => Rule::PATTERN_IS, "pattern" => "/prov/x"]);
foreach ([["entities_id", $ent], ["profiles_id", 1], ["is_recursive", 0], ["_entities_id_default", $ent]] as [$f, $v]) { (new RuleAction())->add(["rules_id" => $rid, "action_type" => "assign", "field" => $f, "value" => $v]); }

try {
  // 1) usuário "do AD" com uma autorização dinâmica antiga
  $u = new User(); $uid = $u->add(["name" => "provcheck.ad", "authtype" => Auth::LDAP, "auths_id" => 0, "is_active" => 1, "_extauth" => 1, "_useremails" => ["provcheck@x.test"]]);
  $DB->delete("glpi_profiles_users", ["users_id" => $uid]);
  $pu->add(["users_id" => $uid, "entities_id" => 0, "profiles_id" => 2, "is_recursive" => 0, "is_dynamic" => 1]);
  $pu->add(["users_id" => $uid, "entities_id" => 0, "profiles_id" => 3, "is_recursive" => 0, "is_dynamic" => 0]);
  echo "candidatos: ", json_encode(UserProvisioner::candidateIdsByEmail("PROVCHECK@x.test")), " (esperado [$uid])\n";

  // 2) converte
  $user = UserProvisioner::load($uid);
  $iid = UserProvisioner::convertExisting($user, "sub-123", "provcheck@x.test");
  $user = UserProvisioner::load($uid);
  echo "authtype após converter: ", $user->fields["authtype"], " (esperado ", Auth::EXTERNAL, "), identidade $iid\n";

  // 3) aplica as regras
  $out = RuleRunner::run("provcheck@x.test", OuPath::ancestors("/PROV/X"));
  UserProvisioner::applyRules($user, $out);
  $dump = fn() => json_encode(array_values(iterator_to_array($DB->request(["SELECT" => ["entities_id", "profiles_id", "is_dynamic"], "FROM" => "glpi_profiles_users", "WHERE" => ["users_id" => $uid], "ORDER" => ["is_dynamic", "profiles_id"]]))));
  echo "autorizações após aplicar: ", $dump(), " (esperado: manual perfil 3 + dinâmica entidade $ent perfil 1)\n";
  echo "entidade padrão do usuário: ", UserProvisioner::load($uid)->fields["entities_id"], " (esperado $ent)\n";

  // 4) desfaz
  echo "undo: ", var_export(UserProvisioner::undo($iid), true), "\n";
  $user = UserProvisioner::load($uid);
  echo "authtype após desfazer: ", $user->fields["authtype"], " (esperado ", Auth::LDAP, ")\n";
  echo "autorizações após desfazer: ", $dump(), " (esperado: manual perfil 3 + dinâmica entidade 0 perfil 2)\n";
  echo "identidade removida: ", var_export(SsoIdentity::findById($iid) === null, true), "\n";

  // 5) criar usuário novo
  $new = UserProvisioner::create(["email" => "Novo.Usuario@X.test", "given_name" => "Novo", "family_name" => "Usuario"], $ent);
  echo "novo usuário: ", $new ? $new->fields["name"] . " authtype=" . $new->fields["authtype"] . " entidade=" . $new->fields["entities_id"] : "FALHOU", "\n";
  echo "e-mail do novo: ", json_encode(UserProvisioner::candidateIdsByEmail("novo.usuario@x.test")), "\n";
} finally {
  foreach (["provcheck.ad", "novo.usuario@x.test"] as $n) { if ($row = $DB->request(["FROM" => "glpi_users", "WHERE" => ["name" => $n]])->current()) { $DB->delete("glpi_profiles_users", ["users_id" => $row["id"]]); $DB->delete("glpi_useremails", ["users_id" => $row["id"]]); $DB->delete("glpi_users", ["id" => $row["id"]]); } }
  $DB->delete("glpi_ruleactions", ["rules_id" => $rid]); $DB->delete("glpi_rulecriterias", ["rules_id" => $rid]); $DB->delete("glpi_rules", ["id" => $rid]);
  $DB->delete("glpi_plugin_gac_ssoevents", ["detail" => "conversion undone"]);
  echo "limpeza feita\n";
}'
```
Expected (os números de entidade variam):
```
candidatos: [<uid>] (esperado [<uid>])
authtype após converter: 4 (esperado 4), identidade <n>
autorizações após aplicar: [{"entities_id":0,"profiles_id":3,"is_dynamic":0},{"entities_id":<ent>,"profiles_id":1,"is_dynamic":1}] ...
entidade padrão do usuário: <ent> (esperado <ent>)
undo: true
authtype após desfazer: 3 (esperado 3)
autorizações após desfazer: [{"entities_id":0,"profiles_id":3,"is_dynamic":0},{"entities_id":0,"profiles_id":2,"is_dynamic":1}] ...
identidade removida: true
novo usuário: novo.usuario@x.test authtype=4 entidade=<ent>
e-mail do novo: [<id>]
limpeza feita
```
Se `candidatos` vier vazio, o e-mail não foi gravado em `glpi_useremails` pelo `User::add` com `_useremails`; nesse caso inspecione `$DB->request(["FROM"=>"glpi_useremails","WHERE"=>["users_id"=>$uid]])` e ajuste o formato (o `Auth::login` do núcleo usa `_useremails` como lista de strings, o que este plano replica).

- [ ] **Step 4: Checkpoint (sem commit)**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml`
Expected: todos passam.

---

### Task 10: SessionStarter e LoginService (GLPI)

**Files:**
- Create: `src/Sso/SessionStarter.php`, `src/Sso/LoginService.php`

**Interfaces:**
- Consumes: tudo das Tarefas 2 a 9. `ServiceResult` de `GlpiPlugin\Gac\Shared`.
- Produces:
  - `SessionStarter::start(\User $user): bool` (false quando o usuário não ficou autenticado).
  - `LoginService::handleCallback(array $query, ?array $state): ServiceResult` — em sucesso `ok('', ['redirect' => string, 'user_id' => int])`; em falha `fail($outcome, ['event_id' => int])`. O `$state` é o array guardado em `$_SESSION['gac_sso']` por `start.php`: `['state' => string, 'nonce' => string, 'pkce' => string, 'redirect' => string, 't' => int]`.

- [ ] **Step 1: `SessionStarter`**

Crie `src/Sso/SessionStarter.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Sso;

/**
 * Opens the GLPI session for a user already verified by Google, with the pattern the GLPI core
 * itself uses for impersonation and token login (spec S2). It skips Auth::login(), so the login
 * event and last_login are written here, and GLPI's own TOTP is not asked (S17).
 */
final class SessionStarter
{
    /** @return bool false when GLPI refused to open the session (e.g. the user has no usable profile) */
    public static function start(\User $user): bool
    {
        global $DB;

        $DB->update('glpi_users', ['last_login' => date('Y-m-d H:i:s')], ['id' => $user->getID()]);
        $user->getFromDB($user->getID());

        $auth                = new \Auth();
        $auth->auth_succeded = true;
        $auth->user          = $user;
        \Session::init($auth);

        if (!\Session::getLoginUserID()) {
            return false;
        }

        $ip = getenv('HTTP_X_FORWARDED_FOR') ?: getenv('REMOTE_ADDR');
        \Event::log(0, 'system', 3, 'login', sprintf(
            __('%1$s log in from IP %2$s'),
            $user->fields['name'],
            $ip
        ));

        return true;
    }
}
```

- [ ] **Step 2: `LoginService`**

Crie `src/Sso/LoginService.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Sso;

use GlpiPlugin\Gac\Shared\ServiceResult;

/**
 * Orchestrates the return from Google (spec section 6.1, steps 2 to 8). Every failure is recorded
 * in glpi_plugin_gac_ssoevents; the id of that event is the code the user is shown (S20).
 */
final class LoginService
{
    /**
     * @param array<string, mixed>      $query the callback's GET parameters
     * @param ?array<string, mixed>     $state what start.php stored in $_SESSION['gac_sso']
     */
    public static function handleCallback(array $query, ?array $state): ServiceResult
    {
        global $CFG_GLPI;

        $settings = SsoConfig::load();
        $email    = '';
        $ou       = '';
        $usersId  = null;

        $fail = static function (string $outcome, string $detail = '') use (&$email, &$ou, &$usersId): ServiceResult {
            $eventId = SsoEvent::record($outcome, $email, $usersId, $ou, $detail);

            return ServiceResult::fail($outcome, ['event_id' => $eventId]);
        };

        try {
            if (!SsoSettings::isConfigured($settings)) {
                return $fail(Outcome::API_ERROR, 'module not configured');
            }

            // The state is single use and short lived.
            if (
                $state === null
                || time() - (int) ($state['t'] ?? 0) > 600
                || !isset($query['state'])
                || !hash_equals((string) ($state['state'] ?? ''), (string) $query['state'])
            ) {
                return $fail(Outcome::STATE_INVALID);
            }
            if (isset($query['error'])) {
                return $fail(Outcome::TOKEN_INVALID, 'provider error: ' . substr((string) $query['error'], 0, 80));
            }
            if (!isset($query['code']) || !is_string($query['code']) || $query['code'] === '') {
                return $fail(Outcome::TOKEN_INVALID, 'missing code');
            }

            // 2. Token and claims.
            try {
                $google = new GoogleClient($settings, SsoSettings::redirectUri($settings, (string) $CFG_GLPI['url_base']));
                $claims = $google->claimsFromCode($query['code'], (string) $state['pkce'], (string) $state['nonce']);
            } catch (SsoException $e) {
                return $fail(Outcome::TOKEN_INVALID, $e->getMessage());
            }
            $email = mb_strtolower(trim((string) $claims['email']));
            $sub   = (string) $claims['sub'];

            // 3. Domain, verified e-mail, pilot mode.
            $denied = LoginDecision::beforeDirectory(
                $email,
                IdToken::emailVerified($claims),
                (string) ($claims['hd'] ?? ''),
                SsoSettings::allowedDomains($settings),
                SsoSettings::pilotOnly($settings),
                SsoSettings::pilotEmails($settings)
            );
            if ($denied !== null) {
                return $fail($denied);
            }

            // 4. The user's OU, then the hard block and the optional domain x OU check.
            try {
                $ou = OuPath::normalize((new DirectoryClient($settings))->orgUnitPath($email));
            } catch (SsoException $e) {
                return $fail(Outcome::API_ERROR, $e->getMessage());
            }

            $identity = SsoIdentity::findBySub($sub);
            $linkedId = $identity === null ? null : (int) $identity['users_id'];

            $denied = LoginDecision::afterDirectory(
                $ou,
                DomainPolicy::emailDomain($email),
                SsoSettings::blockedOus($settings),
                SsoSettings::domainSegment($settings)
            );
            if ($denied !== null) {
                self::revokeIfLinked($linkedId, $settings, $email, $ou);

                return $fail($denied);
            }

            // 5. The authorization rules.
            $ruleOutput = RuleRunner::run($email, OuPath::ancestors($ou));
            $rules      = RuleResult::fromOutput($ruleOutput);
            $denied     = LoginDecision::afterRules($rules->denied, $rules->hasGrants());
            if ($denied !== null) {
                self::revokeIfLinked($linkedId, $settings, $email, $ou);

                return $fail($denied);
            }

            // 6. Which GLPI user is this?
            $match = IdentityMatcher::decide(
                $linkedId,
                UserProvisioner::candidateIdsByEmail($email),
                SsoSettings::autoCreate($settings)
            );
            if ($match->action === IdentityMatch::DENY) {
                return $fail((string) $match->outcome);
            }

            // 7. Provision.
            $detail = 'login';
            if ($match->action === IdentityMatch::CREATE) {
                $user = UserProvisioner::create($claims, $rules->defaultEntityId);
                if ($user === null) {
                    return $fail(Outcome::API_ERROR, 'user creation failed');
                }
                SsoIdentity::link($user->getID(), $sub, $email, \Auth::EXTERNAL, 0, []);
                $detail = 'created';
            } else {
                $user = UserProvisioner::load((int) $match->userId);
                if ($user === null || !$user->fields['is_active'] || $user->fields['is_deleted']) {
                    $usersId = $match->userId;

                    return $fail(Outcome::USER_INACTIVE);
                }
                if ($match->action === IdentityMatch::LINK_EXISTING) {
                    if (!IdentityMatcher::isConvertible((int) $user->fields['authtype'])) {
                        $usersId = $user->getID();

                        return $fail(Outcome::LOCAL_ACCOUNT);
                    }
                    UserProvisioner::convertExisting($user, $sub, $email);
                    $detail = 'linked';
                }
            }
            $usersId = $user->getID();

            UserProvisioner::applyRules($user, $ruleOutput);
            SsoIdentity::touch($sub, $ou);

            // 8. Session.
            if (!SessionStarter::start($user)) {
                return $fail(Outcome::OU_UNMAPPED, 'no usable authorization after applying the rules');
            }

            SsoEvent::record(Outcome::OK, $email, $usersId, $ou, $detail);

            return ServiceResult::ok('', [
                'redirect' => (string) ($state['redirect'] ?? ''),
                'user_id'  => $usersId,
            ]);
        } catch (\Throwable $e) {
            \Toolbox::logInFile('gac', 'sso callback: ' . $e::class . ': ' . $e->getMessage() . "\n");

            return $fail(Outcome::API_ERROR, 'unexpected ' . $e::class);
        }
    }

    /** S15: a user already bound to Google who now falls under a block or a deny loses the dynamic authorizations. */
    private static function revokeIfLinked(?int $linkedId, array $settings, string $email, string $ou): void
    {
        if ($linkedId === null || !SsoSettings::revokeOnDeny($settings)) {
            return;
        }

        $removed = UserProvisioner::revokeDynamic($linkedId);
        if ($removed > 0) {
            SsoEvent::record(Outcome::REVOKED, $email, $linkedId, $ou, $removed . ' dynamic authorization(s) removed');
        }
    }
}
```

- [ ] **Step 3: Cabeçalho e sintaxe**

Run:
```bash
/c/xampp/php/php.exe var/tools/add-header.php src/Sso/SessionStarter.php src/Sso/LoginService.php
for f in src/Sso/SessionStarter.php src/Sso/LoginService.php; do /c/xampp/php/php.exe -l $f; done
```
Expected: `No syntax errors detected`.

- [ ] **Step 4: Verificar os caminhos de falha sem rede**

Run:
```bash
/c/xampp/php/php.exe var/tools/gac-eval.php '
use GlpiPlugin\Gac\Sso\{LoginService, SsoConfig, SsoEvent};
// módulo não configurado
$r = LoginService::handleCallback(["state" => "a", "code" => "b"], ["state" => "a", "t" => time()]);
echo "não configurado: ", var_export($r->ok, true), " ", $r->message, " evento=", $r->data["event_id"], "\n";
// configura o suficiente para passar do isConfigured
SsoConfig::save(["sso_enabled" => 1, "sso_client_id" => "cid", "sso_client_secret" => "sec", "sso_allowed_domains" => "fimca.com.br", "sso_sa_client_email" => "sa@p.iam.gserviceaccount.com", "sso_sa_private_key" => "KEY", "sso_sa_admin_subject" => "admin@fimca.com.br"]);
$r = LoginService::handleCallback(["state" => "a", "code" => "b"], null);
echo "sem estado: ", $r->message, "\n";
$r = LoginService::handleCallback(["state" => "errado", "code" => "b"], ["state" => "a", "t" => time()]);
echo "state errado: ", $r->message, "\n";
$r = LoginService::handleCallback(["state" => "a", "code" => "b"], ["state" => "a", "t" => time() - 700]);
echo "state expirado: ", $r->message, "\n";
$r = LoginService::handleCallback(["state" => "a", "error" => "access_denied"], ["state" => "a", "t" => time()]);
echo "usuário negou no Google: ", $r->message, "\n";
$r = LoginService::handleCallback(["state" => "a"], ["state" => "a", "t" => time()]);
echo "sem code: ", $r->message, "\n";
echo "eventos gravados: ", countElementsInTable("glpi_plugin_gac_ssoevents"), "\n";
// restaura a configuração e limpa
SsoConfig::save(["sso_enabled" => 0, "sso_client_id" => "", "sso_client_secret" => "", "sso_allowed_domains" => "", "sso_sa_client_email" => "", "sso_sa_private_key" => "", "sso_sa_admin_subject" => ""]);
SsoEvent::purgeOlderThan(-1);
echo "eventos após limpeza: ", countElementsInTable("glpi_plugin_gac_ssoevents"), "\n";'
```
Expected:
```
não configurado: false api_error evento=<n>
sem estado: state_invalid
state errado: state_invalid
state expirado: state_invalid
usuário negou no Google: token_invalid
sem code: token_invalid
eventos gravados: 6
eventos após limpeza: 0
```

- [ ] **Step 5: Checkpoint (sem commit)**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml`
Expected: todos passam.

---

### Task 11: Páginas de login, botão e hook `display_login` (GLPI)

**Files:**
- Create: `src/Sso/SsoLoginButton.php`, `front/sso/start.php`, `front/sso/callback.php`
- Modify: `setup.php` (Firewall e hook `display_login`), `hook.php` (função do hook)

**Interfaces:**
- Consumes: `GoogleClient::begin()`, `LoginService::handleCallback()`, `SsoConfig::load()`, `SsoSettings::*`.
- Produces: `SsoLoginButton::render(): string`; função global `plugin_gac_display_login($params = null): void`; as duas páginas `front/sso/start.php` e `front/sso/callback.php`; parâmetro `sso_error` na URL de login.

- [ ] **Step 1: `SsoLoginButton`**

Crie `src/Sso/SsoLoginButton.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Sso;

/**
 * What the display_login hook prints in the panel beside the login form: the error box (when a
 * login just failed), the "Entrar com Google" button and, in hidden-form mode, the script that
 * hides the password form unless the URL has ?local=1 (spec S16). Everything here fails open: a
 * module that is off or not configured prints nothing, and the form is only ever hidden by
 * script that this same output carries.
 */
final class SsoLoginButton
{
    public static function render(): string
    {
        global $CFG_GLPI;

        $settings = SsoConfig::load();
        if (!SsoSettings::isConfigured($settings)) {
            return '';
        }

        $startUrl = $CFG_GLPI['root_doc'] . '/plugins/gac/front/sso/start.php';
        $redirect = $_GET['redirect'] ?? '';
        if (is_string($redirect) && $redirect !== '') {
            $startUrl .= '?redirect=' . rawurlencode($redirect);
        }

        $hide = SsoSettings::hideLocalForm($settings) && !isset($_GET['local']);
        $html = '';

        $errorId = $_GET['sso_error'] ?? '';
        if (is_string($errorId) && ctype_digit($errorId) && $errorId !== '') {
            $html .= "<div class='alert alert-danger text-start' role='alert'>"
                . htmlescape(sprintf(
                    __('Não foi possível entrar com o Google. Procure o DTI informando o código %s.', 'gac'),
                    $errorId
                ))
                . '</div>';
        }

        $html .= "<div class='gac-sso-login mb-3'>"
            . "<a class='btn btn-outline-primary w-100' href='" . htmlescape($startUrl) . "'>"
            . "<i class='ti ti-brand-google me-2'></i>" . htmlescape(SsoSettings::buttonLabel($settings)) . '</a>';

        if ($hide) {
            $localUrl = '?' . http_build_query(array_merge(array_diff_key($_GET, ['sso_error' => 1]), ['local' => 1]));
            $html .= "<div class='mt-2'><a class='small text-muted' href='" . htmlescape($localUrl) . "'>"
                . htmlescape(__('Entrar com usuário e senha', 'gac')) . '</a></div>';
        }
        $html .= '</div>';

        if ($hide) {
            // The login fields sit in the sibling column; hide it. Without this script (or with
            // JS off) the normal form simply stays visible.
            $html .= "<script>(function () {"
                . "var field = document.getElementById('login_name');"
                . "var column = field ? field.closest('.col-md-5') : null;"
                . "if (column) { column.style.display = 'none'; }"
                . '})();</script>';
        }

        return $html;
    }
}
```

- [ ] **Step 2: Registro no `setup.php` e função do hook**

Em `setup.php`, adicione ao bloco de `use` do topo: `use Glpi\Http\Firewall;`. Dentro do `if ($plugin->isInstalled('gac') && $plugin->isActivated('gac'))`, depois da linha do `USE_RULES`, acrescente:

```php

        // SSO Google: the start and callback scripts are reached before any login, but they need
        // a real session (OAuth state, then the login itself), unlike the Monitor's stateless
        // public page. Same strategy GLPI gives its own front/login.php.
        Firewall::addPluginStrategyForLegacyScripts('gac', '#^/front/sso/(start|callback)\.php$#', Firewall::STRATEGY_NO_CHECK);

        // Button and error box on the login page.
        $PLUGIN_HOOKS[Hooks::DISPLAY_LOGIN]['gac'] = 'plugin_gac_display_login';
```

Em `hook.php`, no fim do arquivo, acrescente (e `use GlpiPlugin\Gac\Sso\SsoLoginButton;` no topo):

```php

/**
 * Hook "display_login": prints the Google login button beside the login form (SSO, spec S16).
 * GLPI calls hooks with one argument and expects the hook to echo its HTML.
 *
 * @param mixed $params
 */
function plugin_gac_display_login($params = null): void
{
    echo SsoLoginButton::render();
}
```

- [ ] **Step 3: `start.php`**

Crie `front/sso/start.php`:

```php
<?php

use GlpiPlugin\Gac\Sso\GoogleClient;
use GlpiPlugin\Gac\Sso\SsoConfig;
use GlpiPlugin\Gac\Sso\SsoSettings;

global $CFG_GLPI;

$settings = SsoConfig::load();
if (!SsoSettings::isConfigured($settings)) {
    Html::redirect($CFG_GLPI['root_doc'] . '/front/login.php');
}

$client = new GoogleClient($settings, SsoSettings::redirectUri($settings, (string) $CFG_GLPI['url_base']));
$flow   = $client->begin();

$redirect = $_GET['redirect'] ?? '';
$_SESSION['gac_sso'] = [
    'state'    => $flow['state'],
    'nonce'    => $flow['nonce'],
    'pkce'     => $flow['pkce'],
    'redirect' => is_string($redirect) ? substr($redirect, 0, 2000) : '',
    't'        => time(),
];

Html::redirect($flow['url']);
```

- [ ] **Step 4: `callback.php`**

Crie `front/sso/callback.php`:

```php
<?php

use GlpiPlugin\Gac\Sso\LoginService;

global $CFG_GLPI;

// Same trick as GLPI's own OAuth callback (front/smtp_oauth2_callback.php): with
// session.cookie_samesite = strict the session cookie is not sent on the redirect back from
// Google, so bounce once through a same-site refresh before reading the session.
if (!array_key_exists('cookie_refresh', $_GET)) {
    $url = htmlescape(
        $_SERVER['REQUEST_URI']
        . (str_contains($_SERVER['REQUEST_URI'], '?') ? '&' : '?')
        . 'cookie_refresh'
    );
    echo "<html><head><meta http-equiv=\"refresh\" content=\"0;URL='{$url}'\"/></head><body></body></html>";
    return;
}

// Single use: read and drop the state before the login replaces the session.
$state = isset($_SESSION['gac_sso']) && is_array($_SESSION['gac_sso']) ? $_SESSION['gac_sso'] : null;
unset($_SESSION['gac_sso']);

$result = LoginService::handleCallback($_GET, $state);

if ($result->ok) {
    // Toolbox::manageRedirect() (called by this method) only follows local destinations.
    Auth::redirectIfAuthenticated($result->data['redirect'] !== '' ? $result->data['redirect'] : null);
}

Html::redirect($CFG_GLPI['root_doc'] . '/front/login.php?sso_error=' . (int) ($result->data['event_id'] ?? 0));
```

- [ ] **Step 5: Cabeçalho e sintaxe**

Run:
```bash
/c/xampp/php/php.exe var/tools/add-header.php src/Sso/SsoLoginButton.php front/sso/start.php front/sso/callback.php
for f in src/Sso/SsoLoginButton.php front/sso/start.php front/sso/callback.php setup.php hook.php; do /c/xampp/php/php.exe -l $f; done
```
Expected: `No syntax errors detected`. Se `add-header.php` ignorar os arquivos `front/sso/*.php` por não começarem com `<?php\n` seguido de linha em branco, confira que eles começam exatamente com `<?php` e uma quebra de linha (os blocos acima começam assim). Compare com `front/monitor/public.php`, que tem o cabeçalho.

- [ ] **Step 6: Verificar o botão sem configuração**

Run:
```bash
/c/xampp/php/php.exe var/tools/gac-eval.php 'echo "vazio quando não configurado: ", var_export(GlpiPlugin\Gac\Sso\SsoLoginButton::render() === "", true);'
```
Expected: `vazio quando não configurado: true`.

- [ ] **Step 7: Verificar a tela de login no navegador**

1. Abra `http://glpi11local.test/` deslogado: o formulário normal aparece e **não há** botão do Google (módulo desligado).
2. Configure temporariamente pelo script:
   `/c/xampp/php/php.exe var/tools/gac-eval.php 'GlpiPlugin\Gac\Sso\SsoConfig::save(["sso_enabled"=>1,"sso_client_id"=>"cid","sso_client_secret"=>"sec","sso_allowed_domains"=>"fimca.com.br","sso_sa_client_email"=>"sa@p.iam.gserviceaccount.com","sso_sa_private_key"=>"KEY","sso_sa_admin_subject"=>"admin@fimca.com.br"]);'`
3. Recarregue a página de login: aparece o botão "Entrar com Google", **o formulário de senha fica oculto** e há o link "Entrar com usuário e senha". Clique no link: a URL ganha `?local=1`, o formulário volta e o botão continua.
4. Acesse `http://glpi11local.test/?sso_error=42`: aparece a caixa vermelha "Não foi possível entrar com o Google. Procure o DTI informando o código 42." Acesse `?sso_error=<script>`: nada é injetado (só dígitos passam).
5. Desligue de novo: `/c/xampp/php/php.exe var/tools/gac-eval.php 'GlpiPlugin\Gac\Sso\SsoConfig::save(["sso_enabled"=>0]);'` e confirme que o botão some e o formulário volta.
Expected: tudo como descrito. Se o formulário não ficar oculto no passo 3, abra o console do navegador e confira se há bloqueio de script inline (CSP); nesse caso o comportamento "falha aberto" está correto, mas registre o achado e ajuste o script (por exemplo carregando-o de `public/js/` por `add_javascript`).

- [ ] **Step 8: Checkpoint (sem commit)**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml`
Expected: todos passam.

---

### Task 12: Seção de configuração e teste a seco (GLPI)

**Files:**
- Create: `src/Sso/OutcomeLabels.php`, `src/Sso/DryRun.php`, `src/Sso/SsoConfigSection.php`, `ajax/sso/dry_run.php`
- Modify: `src/Config.php`

**Interfaces:**
- Consumes: `SsoConfig`, `SsoSettings`, `Features::canConfigure`, `RuleRunner::run`, `RuleResult`, `DirectoryClient`, `LoginDecision`, `Outcome`.
- Produces: `OutcomeLabels::of(string $outcome): string`; `DryRun::run(string $email): array{email: string, ou: string, ancestors: list<string>, outcome: string, message: string, grants: list<array{entity: string, profile: string, is_recursive: bool}>, default_entity: ?string}`; `SsoConfigSection implements ConfigSection` com a chave `'sso'`.

- [ ] **Step 1: `OutcomeLabels`**

Crie `src/Sso/OutcomeLabels.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Sso;

/** Portuguese explanations of the outcome codes, for the dry run and the event list. */
final class OutcomeLabels
{
    public static function of(string $outcome): string
    {
        return match ($outcome) {
            Outcome::OK               => __('Login permitido.', 'gac'),
            Outcome::DOMAIN_DENIED    => __('O domínio do e-mail não está na lista de domínios permitidos (ou a conta não é do Workspace).', 'gac'),
            Outcome::EMAIL_UNVERIFIED => __('O Google não confirmou o e-mail.', 'gac'),
            Outcome::OU_BLOCKED       => __('A OU está na lista de OUs sempre bloqueadas.', 'gac'),
            Outcome::OU_DENIED        => __('Uma regra de autorização nega o login para esta OU.', 'gac'),
            Outcome::OU_UNMAPPED      => __('Nenhuma regra de autorização concede acesso a esta OU.', 'gac'),
            Outcome::DOMAIN_MISMATCH  => __('O domínio do e-mail não corresponde ao domínio no caminho da OU.', 'gac'),
            Outcome::EMAIL_AMBIGUOUS  => __('Mais de um usuário do GLPI tem este e-mail.', 'gac'),
            Outcome::PILOT_BLOCKED    => __('Modo piloto: o e-mail não está na lista do piloto.', 'gac'),
            Outcome::CREATE_DISABLED  => __('O usuário não existe e a criação automática está desligada.', 'gac'),
            Outcome::USER_INACTIVE    => __('O usuário do GLPI está inativo ou excluído.', 'gac'),
            Outcome::LOCAL_ACCOUNT    => __('O e-mail pertence a uma conta local do GLPI, que não é convertida automaticamente.', 'gac'),
            Outcome::STATE_INVALID    => __('O estado do login é inválido ou expirou.', 'gac'),
            Outcome::TOKEN_INVALID    => __('O Google não devolveu um token válido.', 'gac'),
            Outcome::API_ERROR        => __('Falha ao consultar o Google ou erro interno.', 'gac'),
            Outcome::REVOKED          => __('Autorizações dinâmicas removidas.', 'gac'),
            Outcome::UNDONE           => __('Conversão desfeita.', 'gac'),
            default                   => $outcome,
        };
    }
}
```

- [ ] **Step 2: `DryRun`**

Crie `src/Sso/DryRun.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Sso;

/**
 * "Teste a seco" (spec 6.2): runs the same checks as a login for an e-mail, minus the Google
 * sign-in, and reports what would happen. Creates no session, user or login event and changes
 * no authorization.
 */
final class DryRun
{
    /**
     * @return array{email: string, ou: string, ancestors: list<string>, outcome: string, message: string,
     *               grants: list<array{entity: string, profile: string, is_recursive: bool}>, default_entity: ?string}
     */
    public static function run(string $email): array
    {
        $email    = mb_strtolower(trim($email));
        $settings = SsoConfig::load();
        $report   = [
            'email' => $email, 'ou' => '', 'ancestors' => [], 'outcome' => Outcome::OK,
            'message' => '', 'grants' => [], 'default_entity' => null,
        ];

        $finish = static function (string $outcome, string $detail = '') use (&$report): array {
            $report['outcome'] = $outcome;
            $report['message'] = OutcomeLabels::of($outcome) . ($detail !== '' ? ' ' . $detail : '');

            return $report;
        };

        if (!SsoSettings::isConfigured($settings)) {
            return $finish(Outcome::API_ERROR, __('O módulo não está configurado.', 'gac'));
        }

        // The "hd" claim is unknown without a real sign-in; assume a Workspace account.
        $denied = LoginDecision::beforeDirectory(
            $email,
            true,
            'dry-run',
            SsoSettings::allowedDomains($settings),
            SsoSettings::pilotOnly($settings),
            SsoSettings::pilotEmails($settings)
        );
        if ($denied !== null) {
            return $finish($denied);
        }

        try {
            $ou = OuPath::normalize((new DirectoryClient($settings))->orgUnitPath($email));
        } catch (SsoException $e) {
            return $finish(Outcome::API_ERROR, '(' . $e->getMessage() . ')');
        }
        $report['ou']        = $ou;
        $report['ancestors'] = OuPath::ancestors($ou);

        $denied = LoginDecision::afterDirectory(
            $ou,
            DomainPolicy::emailDomain($email),
            SsoSettings::blockedOus($settings),
            SsoSettings::domainSegment($settings)
        );
        if ($denied !== null) {
            return $finish($denied);
        }

        $rules = RuleRunner::result($email, $report['ancestors']);
        foreach ($rules->grants as $grant) {
            $report['grants'][] = [
                'entity'       => \Dropdown::getDropdownName('glpi_entities', $grant['entities_id']),
                'profile'      => $grant['profiles_id'] === 0
                    ? __('(perfil padrão do GLPI)', 'gac')
                    : \Dropdown::getDropdownName('glpi_profiles', $grant['profiles_id']),
                'is_recursive' => $grant['is_recursive'] === 1,
            ];
        }
        $report['default_entity'] = $rules->defaultEntityId === null
            ? null
            : \Dropdown::getDropdownName('glpi_entities', $rules->defaultEntityId);

        $denied = LoginDecision::afterRules($rules->denied, $rules->hasGrants());

        return $finish($denied ?? Outcome::OK);
    }
}
```

- [ ] **Step 3: Endpoint AJAX**

Crie `ajax/sso/dry_run.php`:

```php
<?php

// Somente leitura, GET: não precisa de Session::checkCSRF().
use GlpiPlugin\Gac\Features;
use GlpiPlugin\Gac\Sso\DryRun;
use GlpiPlugin\Gac\Sso\SsoIdentity;

header('Content-Type: application/json; charset=utf-8');

if (!Features::canConfigure(SsoIdentity::$rightname)) {
    http_response_code(403);
    echo json_encode(['error' => __('Acesso negado.', 'gac')], JSON_UNESCAPED_UNICODE);
    exit;
}

$email = trim((string) ($_GET['email'] ?? ''));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => __('Informe um e-mail válido.', 'gac')], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    echo json_encode(DryRun::run($email), JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    Toolbox::logInFile('gac', 'sso dry_run.php: ' . $e->getMessage() . "\n");
    http_response_code(500);
    echo json_encode(['error' => __('Erro ao executar o teste.', 'gac')], JSON_UNESCAPED_UNICODE);
}
```

- [ ] **Step 4: `SsoConfigSection`**

Crie `src/Sso/SsoConfigSection.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Sso;

use Dropdown;
use GlpiPlugin\Gac\ConfigSection;
use GlpiPlugin\Gac\Features;
use Html;
use Session;

final class SsoConfigSection implements ConfigSection
{
    public function key(): string
    {
        return 'sso';
    }

    public function canConfigure(): bool
    {
        return Features::canConfigure(SsoIdentity::$rightname);
    }

    public function title(): string
    {
        return __('Login com Google', 'gac');
    }

    public function render(): string
    {
        global $CFG_GLPI;

        $s = SsoConfig::load();

        $redirectUri = SsoSettings::redirectUri($s, (string) $CFG_GLPI['url_base']);

        $general = $this->row(__('Login com Google habilitado', 'gac'), Dropdown::showYesNo('sso_enabled', SsoSettings::enabled($s) ? 1 : 0, -1, ['display' => false]))
            . $this->row(__('Texto do botão', 'gac'), Html::input('sso_button_label', ['value' => SsoSettings::buttonLabel($s)]))
            . $this->row(__('Ocultar o formulário de usuário e senha (acessível com ?local=1)', 'gac'), Dropdown::showYesNo('sso_hide_local_form', SsoSettings::hideLocalForm($s) ? 1 : 0, -1, ['display' => false]))
            . $this->row(__('Domínios permitidos (um por linha)', 'gac'), $this->textarea('sso_allowed_domains', (string) $s['sso_allowed_domains'], 3));

        $oauth = "<div class='alert alert-info'><i class='ti ti-info-circle me-1'></i>"
            . htmlescape(__('URI de redirecionamento a cadastrar no cliente OAuth do Google:', 'gac'))
            . " <code>" . htmlescape($redirectUri) . '</code></div>'
            . $this->row(__('ID do cliente OAuth', 'gac'), Html::input('sso_client_id', ['value' => SsoSettings::clientId($s)]))
            . $this->row(
                SsoSettings::clientSecret($s) !== '' ? __('Segredo do cliente (já configurado; deixe em branco para manter)', 'gac') : __('Segredo do cliente', 'gac'),
                Html::input('sso_client_secret', ['type' => 'password', 'value' => ''])
            )
            . $this->row(__('URI de redirecionamento (opcional; em branco usa a URL base do GLPI)', 'gac'), Html::input('sso_redirect_uri', ['value' => (string) $s['sso_redirect_uri']]));

        $service = "<div class='alert alert-info'><i class='ti ti-info-circle me-1'></i>"
            . htmlescape(__('Conta de serviço do Google Cloud com delegação em todo o domínio e somente o escopo admin.directory.user.readonly. O GLPI representa o administrador abaixo apenas para ler a OU do usuário.', 'gac'))
            . '</div>'
            . $this->row(__('E-mail da conta de serviço', 'gac'), Html::input('sso_sa_client_email', ['value' => SsoSettings::saClientEmail($s)]))
            . $this->row(
                SsoSettings::saPrivateKey($s) !== '' ? __('Chave privada (já configurada; deixe em branco para manter)', 'gac') : __('Chave privada (cole o campo private_key do JSON)', 'gac'),
                $this->textarea('sso_sa_private_key', '', 4)
            )
            . $this->row(__('E-mail do administrador representado', 'gac'), Html::input('sso_sa_admin_subject', ['value' => SsoSettings::saAdminSubject($s)]));

        $rules = "<div class='alert alert-warning'><i class='ti ti-alert-triangle me-1'></i>"
            . htmlescape(__('O mapeamento de OU para entidade e perfil é feito em Administração > Regras > Regras de autorização, com o critério "OU do Google Workspace". Use somente a condição "é" nesse critério; a regra vale para a OU e todas as OUs abaixo dela. Regras mais específicas devem ter a ação "Parar o processamento" e ficar acima da regra padrão da unidade.', 'gac'))
            . '</div>'
            . $this->row(__('OUs sempre bloqueadas (uma por linha; vale para a OU e as abaixo dela)', 'gac'), $this->textarea('sso_blocked_ou_paths', (string) $s['sso_blocked_ou_paths'], 4))
            . $this->row(__('Criar o usuário automaticamente no primeiro login', 'gac'), Dropdown::showYesNo('sso_auto_create', SsoSettings::autoCreate($s) ? 1 : 0, -1, ['display' => false]))
            . $this->row(__('Remover autorizações dinâmicas de quem cair em OU bloqueada ou negada', 'gac'), Dropdown::showYesNo('sso_revoke_on_deny', SsoSettings::revokeOnDeny($s) ? 1 : 0, -1, ['display' => false]))
            . $this->row(__('Posição do domínio no caminho da OU (0 desliga a checagem; 2 para /FIMCA/dominio/...)', 'gac'), Html::input('sso_domain_segment', ['type' => 'number', 'min' => 0, 'value' => SsoSettings::domainSegment($s)]));

        $pilot = $this->row(__('Modo piloto: só os e-mails abaixo entram pelo Google', 'gac'), Dropdown::showYesNo('sso_pilot_only', SsoSettings::pilotOnly($s) ? 1 : 0, -1, ['display' => false]))
            . $this->row(__('E-mails do piloto (um por linha)', 'gac'), $this->textarea('sso_pilot_emails', (string) $s['sso_pilot_emails'], 3))
            . $this->row(__('Dias de retenção dos eventos', 'gac'), Html::input('sso_event_retention_days', ['type' => 'number', 'min' => 7, 'value' => SsoSettings::eventRetentionDays($s)]));

        return $this->block('ti-brand-google', __('Geral', 'gac'), $general)
            . $this->block('ti-key', __('Cliente OAuth', 'gac'), $oauth)
            . $this->block('ti-server', __('Conta de serviço', 'gac'), $service)
            . $this->block('ti-route', __('Regras e bloqueios', 'gac'), $rules)
            . $this->block('ti-flask', __('Piloto e retenção', 'gac'), $pilot)
            . $this->dryRunBlock();
    }

    private function dryRunBlock(): string
    {
        global $CFG_GLPI;

        $url = htmlescape($CFG_GLPI['root_doc'] . '/plugins/gac/ajax/sso/dry_run.php');

        $body = "<p class='text-muted'>" . htmlescape(__('Simula um login para o e-mail informado, sem criar sessão nem alterar nada. Salve a configuração antes de testar.', 'gac')) . '</p>'
            . "<div class='input-group mb-3'><input type='email' class='form-control' id='gac-sso-dry-email' placeholder='nome@dominio.com.br'>"
            . "<button type='button' class='btn btn-outline-primary' id='gac-sso-dry-run'>" . htmlescape(__('Testar', 'gac')) . '</button></div>'
            . "<div id='gac-sso-dry-result'></div>"
            . <<<HTML
<script>
(function () {
    var button = document.getElementById('gac-sso-dry-run');
    var input = document.getElementById('gac-sso-dry-email');
    var out = document.getElementById('gac-sso-dry-result');
    if (!button) { return; }
    // Enter in this field must run the test, not submit the surrounding configuration form.
    input.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') { event.preventDefault(); button.click(); }
    });
    function esc(text) { var d = document.createElement('div'); d.textContent = text; return d.innerHTML; }
    button.addEventListener('click', function () {
        out.innerHTML = '<span class="text-muted">...</span>';
        fetch('{$url}?email=' + encodeURIComponent(input.value), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.error) { out.innerHTML = '<div class="alert alert-danger">' + esc(d.error) + '</div>'; return; }
                var ok = d.outcome === 'ok';
                var html = '<div class="alert alert-' + (ok ? 'success' : 'danger') + '">' + esc(d.message || (ok ? 'Login permitido.' : d.outcome)) + '</div>';
                if (d.ou) { html += '<div><strong>OU:</strong> <code>' + esc(d.ou) + '</code></div>'; }
                if (d.grants && d.grants.length) {
                    html += '<table class="table table-sm mt-2"><thead><tr><th>Entidade</th><th>Perfil</th><th>Recursivo</th></tr></thead><tbody>';
                    d.grants.forEach(function (g) {
                        html += '<tr><td>' + esc(g.entity) + '</td><td>' + esc(g.profile) + '</td><td>' + (g.is_recursive ? 'sim' : 'não') + '</td></tr>';
                    });
                    html += '</tbody></table>';
                }
                if (d.default_entity) { html += '<div><strong>Entidade padrão:</strong> ' + esc(d.default_entity) + '</div>'; }
                out.innerHTML = html;
            })
            .catch(function () { out.innerHTML = '<div class="alert alert-danger">Falha ao executar o teste.</div>'; });
    });
})();
</script>
HTML;

        return $this->block('ti-player-play', __('Teste a seco', 'gac'), $body);
    }

    public function handlePost(array $post): void
    {
        if (!$this->canConfigure()) {
            return;
        }

        $raw = SsoConfig::load();
        foreach ([
            'sso_enabled', 'sso_button_label', 'sso_hide_local_form', 'sso_allowed_domains', 'sso_client_id',
            'sso_redirect_uri', 'sso_sa_client_email', 'sso_sa_admin_subject', 'sso_blocked_ou_paths',
            'sso_auto_create', 'sso_revoke_on_deny', 'sso_domain_segment', 'sso_pilot_only',
            'sso_pilot_emails', 'sso_event_retention_days',
        ] as $key) {
            if (array_key_exists($key, $post)) {
                $raw[$key] = is_string($post[$key]) ? $post[$key] : (string) $post[$key];
            }
        }

        // A blank secret on submit means "keep the current one": the fields are never pre-filled.
        foreach (SsoSettings::SECURED_KEYS as $key) {
            if (trim((string) ($post[$key] ?? '')) !== '') {
                $raw[$key] = (string) $post[$key];
            }
        }

        SsoConfig::save($raw);
        Session::addMessageAfterRedirect(__('Configuração do login com Google salva.', 'gac'));
    }

    private function textarea(string $name, string $value, int $rows): string
    {
        return "<textarea class='form-control' name='" . htmlescape($name) . "' rows='" . $rows . "'>" . htmlescape($value) . '</textarea>';
    }

    private function block(string $icon, string $title, string $content): string
    {
        return "<div class='card border mb-4'><div class='card-header bg-body-tertiary'>"
            . "<h4 class='card-title mb-0'><i class='ti " . htmlescape($icon) . " me-2'></i>" . htmlescape($title) . '</h4>'
            . "</div><div class='card-body'>" . $content . '</div></div>';
    }

    private function row(string $label, string $control): string
    {
        return "<div class='row mb-3'><label class='col-sm-4 col-form-label'>" . htmlescape($label)
            . "</label><div class='col-sm-8'>" . $control . '</div></div>';
    }
}
```

- [ ] **Step 5: Registrar a seção**

Em `src/Config.php`: adicione `use GlpiPlugin\Gac\Sso\SsoConfigSection;` e, em `sections()`, depois de `new MonitorConfigSection(),`, acrescente `new SsoConfigSection(),`.

- [ ] **Step 6: Cabeçalho e sintaxe**

Run:
```bash
/c/xampp/php/php.exe var/tools/add-header.php src/Sso/OutcomeLabels.php src/Sso/DryRun.php src/Sso/SsoConfigSection.php ajax/sso/dry_run.php
for f in src/Sso/OutcomeLabels.php src/Sso/DryRun.php src/Sso/SsoConfigSection.php ajax/sso/dry_run.php src/Config.php; do /c/xampp/php/php.exe -l $f; done
```
Expected: `No syntax errors detected`.

- [ ] **Step 7: Verificar a tela no navegador**

Em `http://glpi11local.test/plugins/gac/front/config.php` (logado como `glpi`): a seção "Login com Google" aparece com os cinco blocos e o "Teste a seco". Preencha só os campos de texto (domínio `example.test`, ID do cliente falso etc.), clique em **Salvar** e confirme a mensagem "Configuração do login com Google salva.". Recarregue: o segredo do cliente continua "já configurado" (campo vazio com o rótulo de manutenção) e os outros valores persistem. Em seguida clique em **Testar** com um e-mail qualquer: como as credenciais são falsas, deve aparecer um alerta vermelho de falha (por exemplo "Falha ao consultar o Google ou erro interno. (Invalid service account private key)"), e **não** um erro 500 ou página em branco.
Expected: persistência correta, segredos preservados, falha tratada com mensagem.

- [ ] **Step 8: Checkpoint (sem commit)**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml`
Expected: todos passam. Deixe o módulo desligado (`sso_enabled` = 0) ao terminar.

---

### Task 13: Listas, OUs pendentes e desfazer conversão (GLPI)

**Files:**
- Create: `src/Sso/SsoPages.php`, `front/sso/identities.php`, `front/sso/events.php`, `front/sso/pending.php`, `front/sso/undo.php`

**Interfaces:**
- Consumes: `SsoIdentity`, `SsoEvent::pendingOus()`, `UserProvisioner::undo()`, `OutcomeLabels::of()`, `Features::canConfigure()`, `GacMenu::SECTOR`, `GacMenu::ITEM_SSO`.
- Produces: `SsoPages::nav(string $active): string` (valores `identities`, `events`, `pending`), `SsoPages::identities(): string`, `SsoPages::events(array $filters): string`, `SsoPages::pending(): string`.

- [ ] **Step 1: `SsoPages`**

Crie `src/Sso/SsoPages.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Sso;

use GlpiPlugin\Gac\Features;
use Html;

/** HTML of the SSO administration lists (identities, events, pending OUs). Spec section 6 and S19. */
final class SsoPages
{
    private static function base(): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/gac/front/sso';
    }

    public static function nav(string $active): string
    {
        $items = [
            'identities' => [__('Identidades', 'gac'), 'identities.php', 'ti-users'],
            'events'     => [__('Eventos', 'gac'), 'events.php', 'ti-list-details'],
            'pending'    => [__('OUs pendentes', 'gac'), 'pending.php', 'ti-hourglass'],
        ];

        $html = "<ul class='nav nav-pills mb-3'>";
        foreach ($items as $key => [$label, $page, $icon]) {
            $html .= "<li class='nav-item'><a class='nav-link" . ($key === $active ? ' active' : '') . "' href='"
                . htmlescape(self::base() . '/' . $page) . "'><i class='ti " . $icon . " me-1'></i>" . htmlescape($label) . '</a></li>';
        }

        return $html . '</ul>';
    }

    public static function identities(): string
    {
        global $DB, $CFG_GLPI;

        $canUndo = Features::canConfigure(SsoIdentity::$rightname);
        $rows    = $DB->request([
            'SELECT'    => [
                'glpi_plugin_gac_ssoidentities.id', 'glpi_plugin_gac_ssoidentities.users_id',
                'glpi_plugin_gac_ssoidentities.email_at_link', 'glpi_plugin_gac_ssoidentities.linked_at',
                'glpi_plugin_gac_ssoidentities.last_login_at', 'glpi_plugin_gac_ssoidentities.last_ou_path',
                'glpi_plugin_gac_ssoidentities.prev_authtype', 'glpi_users.name AS login',
            ],
            'FROM'      => 'glpi_plugin_gac_ssoidentities',
            'LEFT JOIN' => ['glpi_users' => ['ON' => ['glpi_plugin_gac_ssoidentities' => 'users_id', 'glpi_users' => 'id']]],
            'ORDER'     => ['glpi_plugin_gac_ssoidentities.last_login_at DESC'],
            'LIMIT'     => 300,
        ]);

        $html = "<div class='table-responsive'><table class='table table-hover'><thead><tr>"
            . '<th>' . htmlescape(__('Usuário', 'gac')) . '</th><th>' . htmlescape(__('E-mail na vinculação', 'gac')) . '</th>'
            . '<th>' . htmlescape(__('Vinculado em', 'gac')) . '</th><th>' . htmlescape(__('Último login', 'gac')) . '</th>'
            . '<th>' . htmlescape(__('Última OU', 'gac')) . '</th>' . ($canUndo ? '<th></th>' : '') . '</tr></thead><tbody>';

        $count = 0;
        foreach ($rows as $row) {
            ++$count;
            $userUrl = $CFG_GLPI['root_doc'] . '/front/user.form.php?id=' . (int) $row['users_id'];
            $html .= '<tr><td><a href="' . htmlescape($userUrl) . '">' . htmlescape((string) $row['login']) . '</a></td>'
                . '<td>' . htmlescape((string) $row['email_at_link']) . '</td>'
                . '<td>' . htmlescape(Html::convDateTime((string) $row['linked_at'])) . '</td>'
                . '<td>' . htmlescape(Html::convDateTime((string) $row['last_login_at'])) . '</td>'
                . '<td><code>' . htmlescape((string) $row['last_ou_path']) . '</code></td>';
            if ($canUndo) {
                $html .= '<td>' . self::undoForm((int) $row['id']) . '</td>';
            }
            $html .= '</tr>';
        }
        if ($count === 0) {
            $html .= "<tr><td colspan='6' class='text-center text-muted'>" . htmlescape(__('Nenhuma identidade vinculada ainda.', 'gac')) . '</td></tr>';
        }

        return $html . '</tbody></table></div>';
    }

    private static function undoForm(int $id): string
    {
        $confirm = htmlescape(__('Desfazer a conversão? O usuário volta ao método de autenticação anterior e às autorizações dinâmicas que tinha.', 'gac'));

        return "<form method='post' action='" . htmlescape(self::base() . '/undo.php') . "' class='d-inline'"
            . " onsubmit=\"return confirm('" . $confirm . "');\">"
            . "<input type='hidden' name='id' value='" . $id . "'>"
            . "<button type='submit' class='btn btn-sm btn-outline-danger' name='undo' value='1'>"
            . htmlescape(__('Desfazer conversão', 'gac')) . '</button>'
            . Html::closeForm(false);
    }

    /** @param array{outcome?: string, email?: string} $filters */
    public static function events(array $filters): string
    {
        global $DB;

        $where = [];
        $outcome = (string) ($filters['outcome'] ?? '');
        if ($outcome !== '' && in_array($outcome, Outcome::all(), true)) {
            $where['outcome'] = $outcome;
        }
        $email = trim((string) ($filters['email'] ?? ''));
        if ($email !== '') {
            $where['email'] = ['LIKE', '%' . $email . '%'];
        }

        $options = '<option value="">' . htmlescape(__('Todos os resultados', 'gac')) . '</option>';
        foreach (Outcome::all() as $code) {
            $options .= '<option value="' . htmlescape($code) . '"' . ($code === $outcome ? ' selected' : '') . '>'
                . htmlescape($code) . '</option>';
        }
        $html = "<form method='get' class='row g-2 mb-3'>"
            . "<div class='col-md-3'><select class='form-select' name='outcome'>" . $options . '</select></div>'
            . "<div class='col-md-4'><input class='form-control' name='email' placeholder='" . htmlescape(__('E-mail contém...', 'gac')) . "' value='" . htmlescape($email) . "'></div>"
            . "<div class='col-auto'><button class='btn btn-primary' type='submit'>" . htmlescape(__('Filtrar', 'gac')) . '</button></div></form>';

        $rows = $DB->request([
            'FROM'  => SsoEvent::getTable(),
            'WHERE' => $where,
            'ORDER' => ['id DESC'],
            'LIMIT' => 200,
        ]);

        $html .= "<div class='table-responsive'><table class='table table-sm table-hover'><thead><tr>"
            . '<th>#</th><th>' . htmlescape(__('Data', 'gac')) . '</th><th>' . htmlescape(__('E-mail', 'gac')) . '</th>'
            . '<th>' . htmlescape(__('OU', 'gac')) . '</th><th>' . htmlescape(__('Resultado', 'gac')) . '</th>'
            . '<th>' . htmlescape(__('Detalhe', 'gac')) . '</th></tr></thead><tbody>';
        $count = 0;
        foreach ($rows as $row) {
            ++$count;
            $ok = $row['outcome'] === Outcome::OK;
            $html .= '<tr><td>' . (int) $row['id'] . '</td><td>' . htmlescape(Html::convDateTime((string) $row['date'])) . '</td>'
                . '<td>' . htmlescape((string) $row['email']) . '</td><td><code>' . htmlescape((string) $row['ou_path']) . '</code></td>'
                . "<td><span class='badge " . ($ok ? 'bg-success' : 'bg-secondary') . "' title='" . htmlescape(OutcomeLabels::of((string) $row['outcome'])) . "'>"
                . htmlescape((string) $row['outcome']) . '</span></td>'
                . '<td>' . htmlescape((string) $row['detail']) . '</td></tr>';
        }
        if ($count === 0) {
            $html .= "<tr><td colspan='6' class='text-center text-muted'>" . htmlescape(__('Nenhum evento.', 'gac')) . '</td></tr>';
        }

        return $html . '</tbody></table></div>';
    }

    public static function pending(): string
    {
        global $CFG_GLPI;

        $ruleUrl = $CFG_GLPI['root_doc'] . '/front/rule.right.php';
        $html    = "<div class='alert alert-info'><i class='ti ti-info-circle me-1'></i>"
            . htmlescape(__('OUs em que alguém tentou entrar e nenhuma regra de autorização concedeu acesso. Para liberar, crie uma regra com o critério "OU do Google Workspace" igual ao caminho abaixo (condição "é").', 'gac'))
            . " <a href='" . htmlescape($ruleUrl) . "'>" . htmlescape(__('Abrir as regras de autorização', 'gac')) . '</a></div>';

        $html .= "<div class='table-responsive'><table class='table table-hover'><thead><tr>"
            . '<th>' . htmlescape(__('OU', 'gac')) . '</th><th>' . htmlescape(__('Tentativas', 'gac')) . '</th>'
            . '<th>' . htmlescape(__('Última tentativa', 'gac')) . '</th></tr></thead><tbody>';
        $pending = SsoEvent::pendingOus();
        foreach ($pending as $row) {
            $html .= '<tr><td><code>' . htmlescape($row['ou_path']) . '</code></td><td>' . $row['attempts'] . '</td>'
                . '<td>' . htmlescape(Html::convDateTime($row['last_at'])) . '</td></tr>';
        }
        if ($pending === []) {
            $html .= "<tr><td colspan='3' class='text-center text-muted'>" . htmlescape(__('Nenhuma OU pendente.', 'gac')) . '</td></tr>';
        }

        return $html . '</tbody></table></div>';
    }
}
```

- [ ] **Step 2: As quatro páginas**

Crie `front/sso/identities.php`:

```php
<?php

use GlpiPlugin\Gac\GacMenu;
use GlpiPlugin\Gac\Sso\SsoIdentity;
use GlpiPlugin\Gac\Sso\SsoPages;

if (!SsoIdentity::canView()) {
    Html::displayRightError();
}

Html::header(SsoIdentity::getTypeName(2), $_SERVER['PHP_SELF'], GacMenu::SECTOR, GacMenu::ITEM_SSO);
echo SsoPages::nav('identities') . SsoPages::identities();
Html::footer();
```

Crie `front/sso/events.php`:

```php
<?php

use GlpiPlugin\Gac\GacMenu;
use GlpiPlugin\Gac\Sso\SsoIdentity;
use GlpiPlugin\Gac\Sso\SsoPages;

if (!SsoIdentity::canView()) {
    Html::displayRightError();
}

Html::header(__('Eventos do login com Google', 'gac'), $_SERVER['PHP_SELF'], GacMenu::SECTOR, GacMenu::ITEM_SSO);
echo SsoPages::nav('events') . SsoPages::events([
    'outcome' => (string) ($_GET['outcome'] ?? ''),
    'email'   => (string) ($_GET['email'] ?? ''),
]);
Html::footer();
```

Crie `front/sso/pending.php`:

```php
<?php

use GlpiPlugin\Gac\GacMenu;
use GlpiPlugin\Gac\Sso\SsoIdentity;
use GlpiPlugin\Gac\Sso\SsoPages;

if (!SsoIdentity::canView()) {
    Html::displayRightError();
}

Html::header(__('OUs pendentes', 'gac'), $_SERVER['PHP_SELF'], GacMenu::SECTOR, GacMenu::ITEM_SSO);
echo SsoPages::nav('pending') . SsoPages::pending();
Html::footer();
```

Crie `front/sso/undo.php`:

```php
<?php

use GlpiPlugin\Gac\Features;
use GlpiPlugin\Gac\Sso\SsoIdentity;
use GlpiPlugin\Gac\Sso\UserProvisioner;

if (!Features::canConfigure(SsoIdentity::$rightname)) {
    Html::displayRightError();
}

if (isset($_POST['undo'])) {
    $ok = UserProvisioner::undo((int) ($_POST['id'] ?? 0));
    Session::addMessageAfterRedirect(
        $ok ? __('Conversão desfeita.', 'gac') : __('Não foi possível desfazer a conversão.', 'gac'),
        false,
        $ok ? INFO : ERROR
    );
}

Html::back();
```

- [ ] **Step 3: Cabeçalho e sintaxe**

Run:
```bash
/c/xampp/php/php.exe var/tools/add-header.php src/Sso/SsoPages.php front/sso/identities.php front/sso/events.php front/sso/pending.php front/sso/undo.php
for f in src/Sso/SsoPages.php front/sso/identities.php front/sso/events.php front/sso/pending.php front/sso/undo.php; do /c/xampp/php/php.exe -l $f; done
```
Expected: `No syntax errors detected`.

- [ ] **Step 4: Verificar as telas no navegador**

Antes, semeie dados de teste:
```bash
/c/xampp/php/php.exe var/tools/gac-eval.php 'use GlpiPlugin\Gac\Sso\{SsoEvent,Outcome}; SsoEvent::record(Outcome::OU_UNMAPPED, "ana@fimca.com.br", null, "/fimca/fimca.com.br/ies-pvh/novo-setor", "seed"); SsoEvent::record(Outcome::OU_UNMAPPED, "bia@fimca.com.br", null, "/fimca/fimca.com.br/ies-pvh/novo-setor", "seed"); SsoEvent::record(Outcome::OK, "ti@fimca.com.br", null, "/fimca/fimca.com.br/ti", "seed");'
```
Faça logout/login (o menu é cacheado) e abra o menu do plugin > "Login com Google":
1. **Identidades** abre sem erro, com a mensagem "Nenhuma identidade vinculada ainda." e a barra de abas (Identidades, Eventos, OUs pendentes).
2. **Eventos** lista os 3 eventos semeados; o filtro por resultado `ou_unmapped` mostra 2; o filtro por e-mail `ti@` mostra 1.
3. **OUs pendentes** mostra `/fimca/fimca.com.br/ies-pvh/novo-setor` com 2 tentativas e o link para as regras de autorização.
4. Com um perfil **sem** o direito (crie ou use um perfil sem a linha "Login com Google"), as páginas devem negar acesso.
Para testar o "Desfazer conversão" ponta a ponta, rode antes, no terminal, uma conversão de mentira e depois clique no botão:
```bash
/c/xampp/php/php.exe var/tools/gac-eval.php 'use GlpiPlugin\Gac\Sso\{UserProvisioner,SsoIdentity}; $u = new User(); $id = $u->add(["name" => "undocheck.ad", "authtype" => Auth::LDAP, "auths_id" => 0, "is_active" => 1, "_extauth" => 1, "_useremails" => ["undocheck@x.test"]]); UserProvisioner::convertExisting(UserProvisioner::load($id), "sub-undo", "undocheck@x.test"); echo "usuário $id convertido\n";'
```
Em "Identidades" aparece `undocheck.ad`; clique em **Desfazer conversão**, confirme: volta com a mensagem "Conversão desfeita." e a linha some. Confira com `gac-eval` que o `authtype` voltou a 3.
Por fim limpe:
```bash
/c/xampp/php/php.exe var/tools/gac-eval.php 'global $DB; if ($r = $DB->request(["FROM" => "glpi_users", "WHERE" => ["name" => "undocheck.ad"]])->current()) { $DB->delete("glpi_profiles_users", ["users_id" => $r["id"]]); $DB->delete("glpi_useremails", ["users_id" => $r["id"]]); $DB->delete("glpi_users", ["id" => $r["id"]]); } $DB->delete("glpi_plugin_gac_ssoevents", ["detail" => ["seed", "conversion undone"]]); echo "limpo\n";'
```
Expected: tudo como descrito.

- [ ] **Step 5: Checkpoint (sem commit)**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml`
Expected: todos passam.

---

### Task 14: Teste de ponta a ponta com o Google real, roteiro manual e documentação

**Files:**
- Create: `docs/sso-manual-tests.md`
- Modify: `CLAUDE.md`

Esta tarefa precisa de credenciais reais e do dono do Workspace. Os passos de preparação no Google são do dono (spec seção 11); o agente guia e registra os resultados.

- [ ] **Step 1: Servir o GLPI local em `http://localhost/`**

O Google aceita `localhost` como URI de redirecionamento, mas não `glpi11local.test` (spec V5). No XAMPP, acrescente `ServerAlias localhost` ao `VirtualHost` do GLPI em `C:\xampp\apache\conf\extra\httpd-vhosts.conf` (o mesmo bloco onde está `ServerName glpi11local.test`), reinicie o Apache e abra `http://localhost/`: deve aparecer a tela de login do GLPI. Se `localhost` já responde a outro site, registre isso e use o `sso_redirect_uri` com a porta ou o host que funcionar.
Expected: `http://localhost/` mostra o login do GLPI.

- [ ] **Step 2: Preparar o Google (dono)**

O dono executa, no Google Cloud e no Admin Console: projeto + Admin SDK API; tela de consentimento **Interna**; cliente OAuth tipo Web com o URI `http://localhost/plugins/gac/front/sso/callback.php`; conta de serviço com delegação em todo o domínio e o escopo `https://www.googleapis.com/auth/admin.directory.user.readonly`; administrador dedicado somente leitura; chave JSON. Em seguida configura a seção "Login com Google" no GLPI local: domínios permitidos, ID e segredo do cliente, `sso_redirect_uri` = `http://localhost/plugins/gac/front/sso/callback.php`, e-mail da conta de serviço, `private_key` do JSON, e-mail do admin representado.

- [ ] **Step 3: Teste a seco com um e-mail real**

Na seção de configuração, "Teste a seco" com o e-mail do dono. Expected: a OU real do dono aparece em minúsculas, os ancestrais fazem sentido e o resultado é "Nenhuma regra concede acesso" (ainda não há regra). Corrija credenciais se aparecer erro de API (`unauthorized_client` indica delegação ou escopo faltando no Admin Console).

- [ ] **Step 4: Criar as regras e ligar em modo piloto**

Crie, em Regras de autorização, uma regra "SSO Google - teste" com o critério "OU do Google Workspace" **é** a OU do dono e as ações: entidade, perfil, recursivo, entidade padrão e "Parar o processamento". Ative o modo piloto com o e-mail do dono. Rode de novo o teste a seco: deve mostrar a autorização esperada.

- [ ] **Step 5: Login real**

Deslogado, abra `http://localhost/`, clique em "Entrar com Google" e entre com a conta do dono. Expected: volta ao GLPI já logado (`/front/central.php` ou `/Helpdesk`), com a entidade e o perfil da regra. Em "Eventos" há um `ok`; em "Identidades" há o vínculo. Faça logout e entre de novo: o segundo login reusa a identidade (`detail = login`).

- [ ] **Step 6: Escrever o roteiro manual**

Crie `docs/sso-manual-tests.md`:

```markdown
# Login com Google — roteiro de testes manuais

Roteiro do módulo SSO (spec `docs/superpowers/specs/2026-10-05-sso-google-design.md`). Rodar no GLPI
local servido em `http://localhost/` (o Google não aceita `glpi11local.test` como URI de
redirecionamento). Coluna **Resultado**: preencher com a data e `OK`/`FALHOU`/`N/A` a cada rodada.

## Preparação

1. Configurar a seção "Login com Google" (domínios, cliente OAuth, conta de serviço, URI de redirecionamento).
2. Ter ao menos: uma regra "SSO Google - ..." para a OU de teste; uma OU de professores no bloqueio duro; um usuário do GLPI com `authtype` LDAP e o e-mail de uma conta de teste.

## Roteiro

| # | Cenário | Passos | Esperado | Resultado |
|---|---|---|---|---|
| 1 | Módulo desligado | `sso_enabled` = não; abrir o login | Sem botão; formulário normal | — |
| 2 | Botão e formulário oculto | Ligar; abrir o login | Botão do Google; formulário oculto; link "Entrar com usuário e senha" leva a `?local=1` e mostra o formulário | — |
| 3 | Login de usuário novo | Conta cuja OU tem regra e que não existe no GLPI | Usuário criado com login = e-mail completo; entidade/perfil da regra; evento `ok` com `detail=created` | — |
| 4 | Vincular usuário do AD | Conta com o mesmo e-mail de um usuário LDAP | Usa o usuário existente; `authtype` vira externo; identidade gravada; evento `detail=linked`; autorizações dinâmicas antigas substituídas, manuais preservadas | — |
| 5 | Segundo login | Repetir o 3 ou o 4 | Reusa a identidade (`detail=login`) | — |
| 6 | Domínio não permitido | Conta de domínio fora da lista | Volta ao login com o código; evento `domain_denied` | — |
| 7 | OU sem regra | Conta em OU sem regra | Negado; evento `ou_unmapped`; a OU aparece em "OUs pendentes" | — |
| 8 | OU bloqueada (professores) | Conta na OU do bloqueio duro | Negado; evento `ou_blocked`, mesmo havendo regra `allow` herdada acima | — |
| 9 | Regra com negar | Regra com `_deny_login` para a OU | Negado; evento `ou_denied` | — |
| 10 | Modo piloto | Ligar o piloto sem listar o e-mail | Negado; `pilot_blocked`; listando o e-mail, entra | — |
| 11 | Conta local | Conta com o e-mail de um usuário de banco (ex.: `glpi`) | Negado; `local_account`; o `glpi` continua entrando por senha | — |
| 12 | E-mail ambíguo | Dois usuários com o mesmo e-mail | Negado; `email_ambiguous` | — |
| 13 | Usuário inativo | Vincular e desativar o usuário no GLPI | Negado; `user_inactive` | — |
| 14 | Revogação no bloqueio | Usuário já vinculado, mover a conta do Google para a OU bloqueada, tentar entrar | Negado; evento `revoked`; autorizações dinâmicas removidas, manuais mantidas | — |
| 15 | Multi-entidade | Duas regras para a mesma OU | O usuário recebe as duas entidades | — |
| 16 | Herança por ordem | Regra da unidade abaixo da específica, ambas com "Parar" | Só a específica se aplica | — |
| 17 | Desfazer conversão | Identidades > Desfazer conversão | `authtype` e autorizações dinâmicas voltam; identidade removida; evento `undone` | — |
| 18 | Falha de API | Quebrar a chave privada e tentar entrar | Negado com código; `api_error`; nada de erro 500 | — |
| 19 | Estado inválido | Abrir `callback.php?state=x&code=y&cookie_refresh` direto | Volta ao login com código; `state_invalid` | — |
| 20 | Usuário cancela no Google | Fechar a tela de consentimento | Volta ao login com código; `token_invalid` | — |
| 21 | Teste a seco | Seção de configuração, e-mail de cada caso acima | Reflete o resultado esperado sem criar sessão nem alterar dados | — |
| 22 | Retenção | `SsoEvent::purgeOlderThan(0)` via `var/tools/gac-eval.php` | Eventos antigos removidos | — |
| 23 | Redirecionamento | Abrir um link profundo deslogado, entrar pelo Google | Cai no link original; um `redirect` externo é ignorado | — |
| 24 | Desligar | `sso_enabled` = não depois de usar | Botão some; formulário volta; quem já está logado não é afetado | — |

## Pendências conhecidas de verificação (spec seção 12)

V2 (regeneração do id da sessão após o login), V4 (cookie de sessão no callback com `SameSite=strict`), V6 (efeito do `authtype` externo no formulário do usuário), V8 (latência da Directory API), V12 (token pessoal da API e "lembrar de mim" após a revogação), V14 (regras reais do AD não casam num login Google) e V16 (`_deny_login` com usuário novo). Registrar o resultado de cada uma aqui.
```

- [ ] **Step 7: Rodar o roteiro e registrar os resultados**

Execute os cenários 1 a 24 que forem possíveis com a conta do dono e preencha a coluna **Resultado** com a data e `OK`/`FALHOU`/`N/A`. Qualquer `FALHOU` vira correção antes de seguir; se um cenário depender de recursos que o dono não tem (por exemplo duas contas no mesmo e-mail), marque `N/A` com o motivo.

- [ ] **Step 8: Atualizar o `CLAUDE.md`**

Em `CLAUDE.md`, na seção "What exists today", depois do parágrafo do Monitor, acrescente:

```markdown
The fourth module is the **SSO Google** (login with Google Workspace), in `src/Sso/`. It adds an "Entrar com Google" button to the login page and maps the user's Google Workspace **organizational unit (OU)** to an entity and profile through GLPI's own **authorization rules** (`RuleRight`): the plugin only adds a criterion, "OU do Google Workspace" (`GOOGLE_OU`), via the `use_rules` plugin hooks, and GLPI's engine and `User::applyRightRules()` do the rest. The OU is read from the Directory API with a service account (domain-wide delegation, one read-only scope) on each login. Existing AD users are linked by e-mail and then by the Google `sub`, converted to external auth with a snapshot so the conversion can be undone; professors are kept out by a hard block list evaluated before the rules. Its design is in `docs/superpowers/specs/2026-10-05-sso-google-design.md` (decisions S1 to S23, the source of truth) and its plan in `docs/superpowers/plans/2026-10-05-sso-google-implementation.md`; `docs/sso-manual-tests.md` is the manual test script. **If the code diverges from the spec, one of them is wrong: fix it.**
```

Na seção "Plugin structure conventions", acrescente um item:

```markdown
- **Layout of the SSO module:** pure rules in `src/Sso/` (`OuPath`, `OuBlocklist`, `DomainPolicy`, `IdToken`, `IdentityMatcher`, `LoginDecision`, `RuleResult`, `SsoSettings`, `ServiceAccountJwt`, `Outcome`) with tests in `tests/Unit/`; GLPI-bound classes next to them (`SsoIdentity`, `SsoEvent`, `RuleHooks`, `RuleRunner`, `GoogleClient`, `DirectoryClient`, `UserProvisioner`, `SessionStarter`, `LoginService`, `SsoLoginButton`, `SsoConfigSection`, `SsoPages`). Pages in `front/sso/`, AJAX in `ajax/sso/`, tables `glpi_plugin_gac_sso*`, settings `sso_*` (the client secret and the service account key are in `Hooks::SECURED_CONFIGS`). `start.php` and `callback.php` are registered with `Firewall::STRATEGY_NO_CHECK` and use a **real session** (the OAuth state lives in `$_SESSION['gac_sso']`), unlike the Monitor's stateless public page. Authorization-rule criteria for the OU use only the condition "é" (the engine's "começa com" does not work with slashes).
```

E em "Still to fix", acrescente:

```markdown
- The SSO module's manual tests (`docs/sso-manual-tests.md`) need a real Google Workspace; results of each run are recorded in that file. Until the owner runs them, the Google round trip (code exchange, Directory API) has only been verified at unit level and with the paths that fail before reaching Google. Releasing it needs the version bump to `0.7.0` (minor) so GLPI runs the new `plugin_gac_install()`.
```

- [ ] **Step 9: Checkpoint final (sem commit)**

Run:
```bash
/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml
git status --short
```
Expected: todos os testes passam (164 anteriores + os do módulo) e o `git status` mostra apenas os arquivos novos e modificados deste plano (mais `docs/ltbp-user-manual.md` que já estava pendente); nada de `var/` (ignorado). Deixe tudo sem commit para o dono chamar `/commit`. Sugestão de divisão em commits para o dono: `feat(sso)` com a camada pura e testes; `feat(sso)` com o fluxo de login; `feat(sso)` com a administração; `chore(docs)` com spec, plano, roteiro e `CLAUDE.md`.

---

## Self-Review

**1. Cobertura da spec**

| Spec | Tarefa |
|---|---|
| S1 (plugin necessário) | contexto; nenhuma tarefa de código |
| S2 (fluxo próprio, `Session::init`) | 8 (`GoogleClient`), 10 (`SessionStarter`), 11 (páginas) |
| S3 (Directory API, escopo mínimo, falha fechada) | 5 (`ServiceAccountJwt`), 8 (`DirectoryClient`), 10 (`API_ERROR`) |
| S4 (critério `GOOGLE_OU` por hook) | 7 |
| S5 (soma + ordem, parar) | 7 (script de verificação), 12 (aviso na tela), 14 (cenário 16) |
| S6 (perfil explícito) | 4 (`RuleResult` perfil 0), 12 (texto), 12 (`DryRun` mostra "perfil padrão") |
| S7 (exceção TI = regra `MAIL_EMAIL`) | 7 (caso "TI por e-mail"), 14 (passo 4) |
| S8 (ordem de decisão) | 4 (`LoginDecision`), 10 (`LoginService`) |
| S9 (bloqueio duro) | 1 (`OuBlocklist`), 4, 5 (`sso_blocked_ou_paths`), 12 (tela) |
| S10 (identidade por `sub`) | 4 (`IdentityMatcher`), 6 (`SsoIdentity`), 10 |
| S11 (conversão + reversão + conta local) | 4 (`isConvertible`), 9, 10, 13 (desfazer) |
| S12 (usuário novo, login = e-mail) | 9 (`create`), 10 |
| S13 (autorizações via núcleo) | 9 (`applyRules`) |
| S14 (multi-entidade) | 7 (script), 14 (cenário 15) |
| S15 (revogação) | 9 (`revokeDynamic`), 10 (`revokeIfLinked`), 14 (cenário 14) |
| S16 (formulário oculto) | 11 |
| S17 (sem 2FA do GLPI) | 10 (`SessionStarter`, comentário) |
| S18 (consistência domínio × OU) | 2 (`domainSegmentMatches`), 4, 5, 12 |
| S19 (modo piloto) | 4, 5, 12 |
| S20 (mensagem genérica e código) | 6 (`SsoEvent`), 10, 11 (`sso_error`) |
| S21 (segredos) | 5, 6 (`SsoConfig`, `setup.php`) |
| S22 (professores) | 1, 4, 14 (cenário 8) |
| S23 (convivência com o AD) | 14 (V14 no roteiro); o critério `TYPE` é orientação de uso das regras, sem código |
| Seção 5 (tabelas, config) | 6 |
| Seção 6.2 (teste a seco) | 12 |
| Seção 7 (rotas, segurança) | 11 (Firewall `NO_CHECK`, `cookie_refresh`, `manageRedirect`) |
| Seção 8 (permissões) | 6 (`Features`, direito), 12 e 13 (`canConfigure`) |
| Fase 3 (OUs pendentes, identidades, eventos) | 13 |
| Retenção de eventos | 6 (`cronSsoPurge`) |
| Bump de versão e CHANGELOG | fora do plano, por regra do dono (ver Global Constraints) |

Lacunas conscientes: a **página de árvore de OUs** está fora da primeira entrega (spec seção 2). O critério `TYPE` das regras (S23) é uma recomendação operacional, não código.

**2. Varredura de placeholders:** nenhum "TBD"/"TODO" no plano. O único conteúdo omitido nos blocos é o cabeçalho de licença, gerado por `var/tools/add-header.php` (Tarefa 1) e citado em cada tarefa.

**3. Consistência de tipos:** `RuleResult` (propriedades `denied`, `grants`, `defaultEntityId`; métodos `hasGrants()` e `fromOutput()`) é usado igual em `RuleRunner`, `LoginService` e `DryRun`. `IdentityMatch::action/userId/outcome` e as constantes batem entre Tarefas 4 e 10. `LoginDecision::beforeDirectory/afterDirectory/afterRules` têm as mesmas assinaturas nas Tarefas 4, 10 e 12. `SsoSettings` expõe os getters usados em todas as tarefas (`blockedOus`, `pilotEmails`, `domainSegment`, `redirectUri`...). `SsoIdentity::link()` tem a mesma lista de parâmetros em `UserProvisioner::convertExisting` e `LoginService`. `Outcome::LOCAL_ACCOUNT`, `USER_INACTIVE` e `UNDONE` estão definidos na Tarefa 2 e usados depois. O estado de sessão `gac_sso` tem as mesmas chaves (`state`, `nonce`, `pkce`, `redirect`, `t`) em `start.php`, `callback.php` e `LoginService`.
