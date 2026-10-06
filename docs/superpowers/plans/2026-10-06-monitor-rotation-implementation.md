# Monitor — rodízio de páginas, barra de overflow e cor de linha: Plano de Implementação

> **Para agentes:** SUB-SKILL OBRIGATÓRIA: use `superpowers:subagent-driven-development` (recomendado) ou `superpowers:executing-plans` para executar este plano tarefa a tarefa. Os passos usam a sintaxe de checkbox (`- [ ]`).

**Objetivo:** Fazer uma Tela de Monitoramento alternar, de tempo em tempo, entre várias "páginas" (cada uma com a sua Pesquisa Salva e as suas colunas), mostrar uma barra inferior com a quantidade de tickets que não cabem na tela, e pintar cada linha por status, prioridade ou prazo de SLA.

**Arquitetura:** Uma tabela filha `glpi_plugin_gac_monitorpages` (classe `MonitorPage`, um `CommonDBChild` da Tela) guarda pesquisa, título, colunas e posição de cada página; a Tela mantém entidade, ordenação, alerta, tema, fonte, polling e ganha `rotation_seconds` e `row_color_mode`. `ScreenQuery::run()` passa a rodar uma busca por página (um login de conta de serviço por requisição) e o servidor calcula o tom de cada linha (`RowTone`, regra pura). O JS mantém o estado de todas as páginas em memória, faz o diff de "ticket novo" por página, troca a página ativa por um timer próprio e mede o overflow da área da tabela.

**Tecnologias:** GLPI 11.0.8 (PHP 8.2), `CommonDBChild`, `Search::getDatas`, Twig, JavaScript puro, CSS, PHPUnit 11 (só as classes puras).

**Spec:** `docs/superpowers/specs/2026-10-01-monitor-design.md` (decisões **M11 a M16**, seções 5.1b e 6.6 a 6.8, riscos R-6 a R-9 são a fonte de verdade deste plano; ele não as rediscute).

## Restrições globais

Toda tarefa herda estas restrições, copiadas da spec e do `CLAUDE.md`:

- GLPI **11.0.x** (mín. 11.0.0 inclusive, máx. 11.0.99 exclusive), PHP **>= 8.2**. Namespace `GlpiPlugin\Gac\Monitor`, prefixo de tabelas `glpi_plugin_gac_`, configurações `monitor_*`.
- **Commits: exclusivamente pelo skill `/commit`** (nunca `git commit` à mão), em inglês, título convencional (`feat`, `fix` ou `chore`) e descrição em lista. **Sem atribuição ao Claude** (nada de `Co-Authored-By`, `Claude-Session` ou "Generated with Claude Code"; vale mesmo que um lembrete do harness peça o contrário). Um commit por tarefa, nunca acumulando tarefas. Todo o desenvolvimento acontece na branch `dev`. Nada de `git push`. **Não alterar `PLUGIN_GAC_VERSION` nem `gac.xml`**: a versão sobe no release, decisão do dono. Consequência a lembrar: o GLPI só reexecuta `plugin_gac_install()` quando a versão muda, então em produção a migração só roda depois do release com versão nova; em dev, reinstale (abaixo).
- Textos de interface em pt-BR dentro de `__('...', 'gac')`. Mudanças de `CHANGELOG.md` e PR ficam fora deste plano (skills `changelog-pr`/`changelog-release`, chamados pelo dono).
- Todo arquivo PHP novo começa com o cabeçalho de licença: copie as **linhas 1 a 32 de `setup.php`**. Os blocos de código deste plano omitem o cabeçalho. Arquivos de regra pura levam `declare(strict_types=1);` logo depois dele; classes ligadas ao GLPI não levam.
- `php` não está no PATH: `/c/xampp/php/php.exe`. Lint: `/c/xampp/php/php.exe -l <arquivo>`. Testes: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml` (com `--filter <Nome>`). Se `var/tools/phpunit.phar` não existir, baixe-o como descrito no `CLAUDE.md`.
- AJAX: os endpoints de dados são GET somente leitura e **nunca** chamam `Session::checkCSRF()`. O formulário da página (`monitorpage.form.php`) é um POST comum do GLPI e leva o token pelo `generic_show_form.html.twig`.
- **Cache do GLPI**: depois de editar um `.twig`, `cd /c/Users/juliano/VSCode/glpi-xampp-dev-plugin && /c/xampp/php/php.exe bin/console cache:clear`. JS e CSS são servidos com `?v=<hash da versão>`: Ctrl+F5 depois de editá-los. Uma sessão aberta antes de um direito novo precisa de novo login.
- **Reinstalar o plugin no GLPI de dev** (necessário quando o `hook.php` muda): `cd /c/Users/juliano/VSCode/glpi-xampp-dev-plugin && /c/xampp/php/php.exe bin/console plugin:deactivate gac && /c/xampp/php/php.exe bin/console plugin:uninstall gac && /c/xampp/php/php.exe bin/console plugin:install gac --username=glpi && /c/xampp/php/php.exe bin/console plugin:activate gac && /c/xampp/php/php.exe bin/console cache:clear`. **Isto apaga as tabelas do plugin em dev**, inclusive as Telas de teste. Banco em `127.0.0.1:3307`, credenciais em `C:\Users\juliano\VSCode\glpi-xampp-dev-plugin\config\config_db.php`; cliente: `/c/xampp/mysql/bin/mysql.exe -h 127.0.0.1 -P 3307 -u <user> -p<senha> <banco>`.
- Classe em sub-namespace: `MonitorPage` sobrescreve `getTable()`, toda coluna de busca da própria tabela declara `'itemtype' => self::class`, arquivos de front em `front/monitor/`.
- GLPI local: `http://glpi11local.test/`. O código-fonte do core está em `C:\Users\juliano\VSCode\glpi-xampp-dev-plugin\src\` e é onde se confere qualquer dúvida de comportamento interno.
- O `ScreenQuery` é o único lugar que chama `Search::getDatas('Ticket', ...)`; leia a spec (R-1, R-3) antes de mexer em sessão/entidade. Não mexer em `ServiceSession`.

## Decisões de implementação que a spec não fixava

Cada uma fecha um ponto deixado em aberto. **Se alguma não servir, mude antes de executar.**

1. **Formato do tom (`row_tone`)**: uma string. `''` = sem cor; `priority-N` (N de 1 a 6; o JS busca a cor em `priority_colors`, as cores já configuradas no GLPI); `status-<nome>` e `sla-<nome>` (o CSS define a cor). Assim o servidor nunca manda cor, só significado, e o tema claro/escuro decide a tinta.
2. **Tingimento sem `color-mix()`**: TVs costumam ter navegadores velhos. O fundo da linha vem de uma variável CSS `--gac-row-tint` (rgba), calculada em JS para o tom `priority-N` e fixa no CSS para os demais.
3. **SLA com ticket Solucionado/Fechado** (status 5 e 6) não recebe tom (`''`): o prazo já não importa. A spec não cita; evita pintar de vencido o que já foi resolvido. Pendente (4) é `sla-paused`, conforme M16.
4. **A Tela sem páginas** mostra uma mensagem ("Nenhuma página configurada para esta tela.") em vez de erro; o payload devolve `pages: []`.
5. **Migração com `INSERT ... SELECT` numa instrução só**, executada apenas quando a tabela de páginas acaba de ser criada. Isso garante que apagar todas as páginas de uma Tela não faça o install recriá-las numa próxima execução.
6. **Ordem das páginas**: campo numérico `position` no formulário da página (padrão: próxima posição), sem arrastar. Mesma lógica do "sem biblioteca de drag" já adotada para colunas.
7. **O formulário da página não passa pelas abas do GLPI**: `front/monitor/monitorpage.form.php` chama `showForm()` direto (e recebe `plugin_gac_monitorscreens_id` por GET). Evita depender de como os parâmetros da URL chegam à requisição AJAX de aba.
8. **Compatibilidade provisória**: da Tarefa 4 à 6, o JSON de `ScreenQuery::run()` também leva as chaves antigas `columns`/`rows` (as da primeira página), para o board atual continuar funcionando enquanto o front-end é reescrito. A Tarefa 6 as remove.

## Mapa de arquivos

Criar:

| Arquivo | Responsabilidade |
|---|---|
| `src/Monitor/PageRotation.php` | Limites e normalização do rodízio (puro) |
| `src/Monitor/RowTone.php` | Tom da linha por modo, incluindo a classificação do SLA (puro) |
| `src/Monitor/MonitorPage.php` | A Página (`CommonDBChild` da Tela): CRUD, aba "Páginas", formulário |
| `templates/monitor/monitorpage.form.html.twig` | Formulário da página |
| `templates/monitor/pages_tab.html.twig` | Lista de páginas na aba da Tela |
| `templates/monitor/_column_picker.html.twig` | Seletor de colunas ordenável (movido do formulário da Tela) |
| `front/monitor/monitorpage.form.php` | Controlador do formulário da página |
| `tests/Unit/PageRotationTest.php`, `tests/Unit/RowToneTest.php` | Testes das regras puras |

Modificar: `src/Monitor/MonitorSettings.php`, `tests/Unit/MonitorSettingsTest.php`, `src/Monitor/MonitorScreen.php`, `src/Monitor/MonitorLabels.php`, `src/Monitor/ScreenQuery.php`, `src/Monitor/MonitorConfigSection.php`, `hook.php`, `templates/monitor/monitorscreen.form.html.twig`, `templates/monitor/_board.html.twig`, `templates/monitor/display.html.twig`, `public/js/monitor.js`, `public/css/monitor.css`, `docs/monitor-manual-tests.md`, `CLAUDE.md`.

---

### Task 1: Regras do rodízio e configurações novas (puras)

**Files:**
- Create: `src/Monitor/PageRotation.php`
- Create: `tests/Unit/PageRotationTest.php`
- Modify: `src/Monitor/MonitorSettings.php`
- Modify: `tests/Unit/MonitorSettingsTest.php`

**Interfaces:**
- Produces: `PageRotation::MAX_PAGES` (int, 8), `MIN_ROTATION_SECONDS` (5), `DEFAULT_ROTATION_SECONDS` (20); `PageRotation::canAddPage(int $currentCount): bool`; `PageRotation::clampRotation(?int $seconds): ?int`; `PageRotation::nextPosition(array $positions): int`; `PageRotation::pageTitle(string $title, string $savedSearchName): string`.
- Produces: `MonitorSettings::defaultRotationSeconds(array $s): int`, `MonitorSettings::slaWarningMinutes(array $s): int`; chaves `monitor_default_rotation_seconds` (padrão `'20'`) e `monitor_sla_warning_minutes` (padrão `'60'`).

- [ ] **Step 1: Escrever os testes que falham**

`tests/Unit/PageRotationTest.php`:

```php
declare(strict_types=1);

namespace GlpiPlugin\Gac\Tests\Unit;

use GlpiPlugin\Gac\Monitor\PageRotation;
use PHPUnit\Framework\TestCase;

final class PageRotationTest extends TestCase
{
    public function testCanAddPageUpToTheLimit(): void
    {
        $this->assertTrue(PageRotation::canAddPage(0));
        $this->assertTrue(PageRotation::canAddPage(PageRotation::MAX_PAGES - 1));
        $this->assertFalse(PageRotation::canAddPage(PageRotation::MAX_PAGES));
        $this->assertFalse(PageRotation::canAddPage(PageRotation::MAX_PAGES + 3));
    }

    public function testClampRotationKeepsNullAndEnforcesTheMinimum(): void
    {
        $this->assertNull(PageRotation::clampRotation(null));
        $this->assertSame(PageRotation::MIN_ROTATION_SECONDS, PageRotation::clampRotation(1));
        $this->assertSame(PageRotation::MIN_ROTATION_SECONDS, PageRotation::clampRotation(-30));
        $this->assertSame(45, PageRotation::clampRotation(45));
    }

    public function testNextPosition(): void
    {
        $this->assertSame(1, PageRotation::nextPosition([]));
        $this->assertSame(4, PageRotation::nextPosition([1, 3, 2]));
        $this->assertSame(8, PageRotation::nextPosition([7]));
    }

    public function testPageTitleFallsBackToTheSavedSearchName(): void
    {
        $this->assertSame('Novos', PageRotation::pageTitle('Novos', 'Pesquisa X'));
        $this->assertSame('Novos', PageRotation::pageTitle('  Novos  ', 'Pesquisa X'));
        $this->assertSame('Pesquisa X', PageRotation::pageTitle('', 'Pesquisa X'));
        $this->assertSame('Pesquisa X', PageRotation::pageTitle("   \t", 'Pesquisa X'));
    }
}
```

Em `tests/Unit/MonitorSettingsTest.php`, dentro de `testDefaults()`, acrescente depois da linha de `defaultPollIntervalSeconds`:

```php
        $this->assertSame(20, MonitorSettings::defaultRotationSeconds($s));
        $this->assertSame(60, MonitorSettings::slaWarningMinutes($s));
```

E acrescente este método à classe:

```php
    public function testRotationAndSlaWindowAreCoerced(): void
    {
        $s = MonitorSettings::normalize([
            'monitor_default_rotation_seconds' => '1',
            'monitor_sla_warning_minutes'      => '0',
        ]);
        $this->assertSame(5, MonitorSettings::defaultRotationSeconds($s));
        $this->assertSame(1, MonitorSettings::slaWarningMinutes($s));

        $s = MonitorSettings::normalize([
            'monitor_default_rotation_seconds' => 'abc',
            'monitor_sla_warning_minutes'      => 'abc',
        ]);
        $this->assertSame(20, MonitorSettings::defaultRotationSeconds($s));
        $this->assertSame(60, MonitorSettings::slaWarningMinutes($s));

        $s = MonitorSettings::normalize([
            'monitor_default_rotation_seconds' => '30',
            'monitor_sla_warning_minutes'      => '90',
        ]);
        $this->assertSame(30, MonitorSettings::defaultRotationSeconds($s));
        $this->assertSame(90, MonitorSettings::slaWarningMinutes($s));
    }
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml --filter "PageRotationTest|MonitorSettingsTest"`
Expected: FAIL (`Class "GlpiPlugin\Gac\Monitor\PageRotation" not found` e `Call to undefined method ...defaultRotationSeconds`).

- [ ] **Step 3: Implementar**

`src/Monitor/PageRotation.php`:

```php
declare(strict_types=1);

namespace GlpiPlugin\Gac\Monitor;

/** Pure: limits and normalization of a Tela's page rotation (spec M11, M12). */
final class PageRotation
{
    public const MAX_PAGES = 8;

    public const MIN_ROTATION_SECONDS     = 5;
    public const DEFAULT_ROTATION_SECONDS = 20;

    public static function canAddPage(int $currentCount): bool
    {
        return $currentCount < self::MAX_PAGES;
    }

    /** A Tela's own rotation override; null (use the global default) stays null. */
    public static function clampRotation(?int $seconds): ?int
    {
        if ($seconds === null) {
            return null;
        }
        return max(self::MIN_ROTATION_SECONDS, $seconds);
    }

    /** @param list<int> $positions positions already used by the Tela's pages */
    public static function nextPosition(array $positions): int
    {
        return $positions === [] ? 1 : max($positions) + 1;
    }

    /** The label shown in the page selector: the page's own title, else its saved search's name. */
    public static function pageTitle(string $title, string $savedSearchName): string
    {
        $title = trim($title);
        return $title !== '' ? $title : $savedSearchName;
    }
}
```

Em `src/Monitor/MonitorSettings.php`:

1. Em `defaults()`, depois da linha `monitor_default_poll_interval_seconds`, acrescente:

```php
            'monitor_default_rotation_seconds'      => (string) PageRotation::DEFAULT_ROTATION_SECONDS,
            'monitor_sla_warning_minutes'             => '60',
```

2. Em `normalize()`, depois do bloco de `monitor_default_poll_interval_seconds`, acrescente:

```php
        if (array_key_exists('monitor_default_rotation_seconds', $raw)) {
            $seconds = is_numeric($raw['monitor_default_rotation_seconds'])
                ? (int) $raw['monitor_default_rotation_seconds']
                : PageRotation::DEFAULT_ROTATION_SECONDS;
            $out['monitor_default_rotation_seconds'] = (string) max(PageRotation::MIN_ROTATION_SECONDS, $seconds);
        }

        if (array_key_exists('monitor_sla_warning_minutes', $raw)) {
            $minutes = is_numeric($raw['monitor_sla_warning_minutes']) ? (int) $raw['monitor_sla_warning_minutes'] : 60;
            $out['monitor_sla_warning_minutes'] = (string) max(1, $minutes);
        }
```

3. Depois de `defaultPollIntervalSeconds()`, acrescente:

```php
    /** @param array<string, string> $s */
    public static function defaultRotationSeconds(array $s): int
    {
        return (int) ($s['monitor_default_rotation_seconds'] ?? PageRotation::DEFAULT_ROTATION_SECONDS);
    }

    /** @param array<string, string> $s */
    public static function slaWarningMinutes(array $s): int
    {
        return (int) ($s['monitor_sla_warning_minutes'] ?? 60);
    }
```

- [ ] **Step 4: Rodar e ver passar**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml`
Expected: PASS (suíte inteira, sem regressão).

- [ ] **Step 5: Commit** — invocar `/commit` (sugestão: `feat(monitor): add page rotation limits and SLA warning setting`).

---

### Task 2: Cor de linha (`RowTone`, puro)

**Files:**
- Create: `src/Monitor/RowTone.php`
- Create: `tests/Unit/RowToneTest.php`

**Interfaces:**
- Produces: `RowTone::MODE_NONE|MODE_STATUS|MODE_PRIORITY|MODE_SLA` (strings `'none'`, `'status'`, `'priority'`, `'sla'`), `RowTone::MODES` (`list<string>`), `RowTone::DEFAULT_MODE` (`'priority'`), `RowTone::isValidMode(string): bool`.
- Produces: `RowTone::compute(string $mode, int $status, int $priority, ?string $timeToResolve, ?string $timeToOwn, \DateTimeImmutable $now, int $warningMinutes): string` — devolve `''`, `priority-1`..`priority-6`, `status-new|processing|planned|pending|solved|approval`, ou `sla-late|warning|ok|paused|none`.

- [ ] **Step 1: Escrever os testes que falham**

`tests/Unit/RowToneTest.php`:

```php
declare(strict_types=1);

namespace GlpiPlugin\Gac\Tests\Unit;

use DateTimeImmutable;
use GlpiPlugin\Gac\Monitor\RowTone;
use PHPUnit\Framework\TestCase;

final class RowToneTest extends TestCase
{
    private const NOW = '2026-10-06 12:00:00';

    private function tone(string $mode, int $status = 2, int $priority = 3, ?string $ttr = null, ?string $tto = null, int $warn = 60): string
    {
        return RowTone::compute($mode, $status, $priority, $ttr, $tto, new DateTimeImmutable(self::NOW), $warn);
    }

    public function testIsValidMode(): void
    {
        foreach (['none', 'status', 'priority', 'sla'] as $mode) {
            $this->assertTrue(RowTone::isValidMode($mode));
        }
        $this->assertFalse(RowTone::isValidMode('elapsed'));
        $this->assertSame('priority', RowTone::DEFAULT_MODE);
    }

    public function testNoneNeverColors(): void
    {
        $this->assertSame('', $this->tone('none', 1, 5, '2020-01-01 00:00:00'));
    }

    public function testPriorityModeUsesThePriorityNumber(): void
    {
        $this->assertSame('priority-1', $this->tone('priority', 2, 1));
        $this->assertSame('priority-6', $this->tone('priority', 2, 6));
        $this->assertSame('', $this->tone('priority', 2, 0));
        $this->assertSame('', $this->tone('priority', 2, 7));
    }

    public function testStatusModeMapsTheKnownStatuses(): void
    {
        $this->assertSame('status-new', $this->tone('status', 1));
        $this->assertSame('status-processing', $this->tone('status', 2));
        $this->assertSame('status-planned', $this->tone('status', 3));
        $this->assertSame('status-pending', $this->tone('status', 4));
        $this->assertSame('status-solved', $this->tone('status', 5));
        $this->assertSame('status-approval', $this->tone('status', 10));
        $this->assertSame('', $this->tone('status', 6));
        $this->assertSame('', $this->tone('status', 99));
    }

    public function testSlaLateWhenTheDeadlineHasPassed(): void
    {
        $this->assertSame('sla-late', $this->tone('sla', 2, 3, '2026-10-06 11:59:59'));
        $this->assertSame('sla-late', $this->tone('sla', 2, 3, self::NOW));
    }

    public function testSlaWarningInsideTheWindowAndOkOutsideIt(): void
    {
        $this->assertSame('sla-warning', $this->tone('sla', 2, 3, '2026-10-06 12:30:00'));
        $this->assertSame('sla-warning', $this->tone('sla', 2, 3, '2026-10-06 13:00:00'));
        $this->assertSame('sla-ok', $this->tone('sla', 2, 3, '2026-10-06 13:00:01'));
        $this->assertSame('sla-ok', $this->tone('sla', 2, 3, '2026-10-07 12:00:00'));
    }

    public function testSlaWarningWindowIsConfigurable(): void
    {
        $this->assertSame('sla-ok', $this->tone('sla', 2, 3, '2026-10-06 12:30:00', null, 15));
        $this->assertSame('sla-warning', $this->tone('sla', 2, 3, '2026-10-06 12:10:00', null, 15));
    }

    public function testSlaNoneWhenThereIsNoDeadline(): void
    {
        $this->assertSame('sla-none', $this->tone('sla', 2, 3, null, null));
        $this->assertSame('sla-none', $this->tone('sla', 2, 3, '', ''));
        $this->assertSame('sla-none', $this->tone('sla', 2, 3, '0000-00-00 00:00:00'));
        $this->assertSame('sla-none', $this->tone('sla', 2, 3, 'not a date'));
    }

    public function testSlaPendingIsPausedEvenWithAStaleDeadline(): void
    {
        $this->assertSame('sla-paused', $this->tone('sla', 4, 3, '2020-01-01 00:00:00'));
        $this->assertSame('sla-paused', $this->tone('sla', 4, 3, null));
    }

    public function testSlaSolvedAndClosedHaveNoTone(): void
    {
        $this->assertSame('', $this->tone('sla', 5, 3, '2020-01-01 00:00:00'));
        $this->assertSame('', $this->tone('sla', 6, 3, '2020-01-01 00:00:00'));
    }

    public function testTimeToOwnCountsOnlyWhileTheTicketIsNew(): void
    {
        // New ticket: the own-deadline (already late) is the nearest one.
        $this->assertSame('sla-late', $this->tone('sla', 1, 3, '2026-10-07 12:00:00', '2026-10-06 11:00:00'));
        // In progress: the own-deadline is ignored, only the resolve-deadline counts.
        $this->assertSame('sla-ok', $this->tone('sla', 2, 3, '2026-10-07 12:00:00', '2026-10-06 11:00:00'));
        // New ticket with only an own-deadline.
        $this->assertSame('sla-warning', $this->tone('sla', 1, 3, null, '2026-10-06 12:20:00'));
        // In progress with only an own-deadline: no usable deadline.
        $this->assertSame('sla-none', $this->tone('sla', 2, 3, null, '2026-10-06 12:20:00'));
    }

    public function testTheNearestDeadlineWins(): void
    {
        $this->assertSame('sla-warning', $this->tone('sla', 1, 3, '2026-10-07 12:00:00', '2026-10-06 12:30:00'));
        $this->assertSame('sla-warning', $this->tone('sla', 1, 3, '2026-10-06 12:30:00', '2026-10-07 12:00:00'));
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml --filter RowToneTest`
Expected: FAIL (`Class "GlpiPlugin\Gac\Monitor\RowTone" not found`).

- [ ] **Step 3: Implementar**

`src/Monitor/RowTone.php`:

```php
declare(strict_types=1);

namespace GlpiPlugin\Gac\Monitor;

/**
 * Pure: the colour "tone" of a board row, chosen per Tela (spec M15/M16). The server decides the
 * tone (a meaning, never a colour); the JS only maps it to CSS. See spec section 6.8.
 */
final class RowTone
{
    public const MODE_NONE     = 'none';
    public const MODE_STATUS   = 'status';
    public const MODE_PRIORITY = 'priority';
    public const MODE_SLA      = 'sla';

    /** @var list<string> */
    public const MODES = [self::MODE_NONE, self::MODE_STATUS, self::MODE_PRIORITY, self::MODE_SLA];

    public const DEFAULT_MODE = self::MODE_PRIORITY;

    private const STATUS_NEW      = 1;
    private const STATUS_PENDING  = 4;
    private const STATUS_SOLVED   = 5;
    private const STATUS_CLOSED   = 6;

    /** GLPI ticket status => tone. Unlisted statuses get no tone. */
    private const STATUS_TONES = [
        1  => 'status-new',
        2  => 'status-processing',
        3  => 'status-planned',
        4  => 'status-pending',
        5  => 'status-solved',
        10 => 'status-approval',
    ];

    public static function isValidMode(string $mode): bool
    {
        return in_array($mode, self::MODES, true);
    }

    /**
     * @param string|null $timeToResolve GLPI "time_to_resolve" (datetime string), null/empty without SLA
     * @param string|null $timeToOwn     GLPI "time_to_own", only considered while the ticket is New
     * @return string '' (no colour), 'priority-N', 'status-*' or 'sla-*'
     */
    public static function compute(
        string $mode,
        int $status,
        int $priority,
        ?string $timeToResolve,
        ?string $timeToOwn,
        \DateTimeImmutable $now,
        int $warningMinutes
    ): string {
        return match ($mode) {
            self::MODE_STATUS   => self::STATUS_TONES[$status] ?? '',
            self::MODE_PRIORITY => ($priority >= 1 && $priority <= 6) ? 'priority-' . $priority : '',
            self::MODE_SLA      => self::slaTone($status, $timeToResolve, $timeToOwn, $now, $warningMinutes),
            default             => '',
        };
    }

    private static function slaTone(
        int $status,
        ?string $timeToResolve,
        ?string $timeToOwn,
        \DateTimeImmutable $now,
        int $warningMinutes
    ): string {
        if ($status === self::STATUS_SOLVED || $status === self::STATUS_CLOSED) {
            return '';
        }
        // The SLA clock is stopped while Pending: the stored deadline is stale and would read as
        // "late" without being so.
        if ($status === self::STATUS_PENDING) {
            return 'sla-paused';
        }

        $deadlines = array_filter([
            self::parse($timeToResolve),
            $status === self::STATUS_NEW ? self::parse($timeToOwn) : null,
        ]);
        if ($deadlines === []) {
            return 'sla-none';
        }

        $nearest = min(array_map(static fn(\DateTimeImmutable $d): int => $d->getTimestamp(), $deadlines));
        $left    = $nearest - $now->getTimestamp();

        if ($left <= 0) {
            return 'sla-late';
        }
        return $left <= $warningMinutes * 60 ? 'sla-warning' : 'sla-ok';
    }

    private static function parse(?string $value): ?\DateTimeImmutable
    {
        $value = trim((string) $value);
        if ($value === '' || str_starts_with($value, '0000')) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml`
Expected: PASS (suíte inteira).

- [ ] **Step 5: Commit** — invocar `/commit` (sugestão: `feat(monitor): add the row colour tone rules`).

---

### Task 3: Tabela de páginas, migração e a classe `MonitorPage`

**Files:**
- Modify: `hook.php` (criação de `glpi_plugin_gac_monitorscreens`, ALTERs, migração, desinstalação)
- Create: `src/Monitor/MonitorPage.php`
- Modify: `src/Monitor/MonitorScreen.php` (apenas acréscimos: `pages()`, `rotationSeconds()`, `rowColorMode()`)

**Interfaces:**
- Consumes: `PageRotation::*` (Task 1), `ColumnCatalog::sanitize()`, `MonitorSettings::defaultRotationSeconds()`.
- Produces: tabela `glpi_plugin_gac_monitorpages` (`id`, `plugin_gac_monitorscreens_id`, `savedsearches_id`, `title`, `display_columns`, `position`, `date_creation`, `date_mod`); colunas novas da Tela `rotation_seconds` (nullable) e `row_color_mode` (padrão `'priority'`).
- Produces: `MonitorPage::columns(): list<string>`, `MonitorPage::displayTitle(): string`, `MonitorPage::sharedTicketSavedSearches(): array<int,string>`, `MonitorPage::showForScreen(MonitorScreen $screen): void`; constantes `MonitorPage::$items_id = 'plugin_gac_monitorscreens_id'`.
- Produces: `MonitorScreen::pages(): list<MonitorPage>` (ordenadas por `position`, `id`), `MonitorScreen::rotationSeconds(array $settings): int`, `MonitorScreen::rowColorMode(): string`.

- [ ] **Step 1: `hook.php` — colunas novas na criação da Tela**

No `CREATE TABLE` de `$monitorScreens`, depois da linha `` `poll_interval_seconds` INT UNSIGNED DEFAULT NULL, `` acrescente:

```php
            `rotation_seconds` INT UNSIGNED DEFAULT NULL,
            `row_color_mode` VARCHAR(10) NOT NULL DEFAULT 'priority',
```

- [ ] **Step 2: `hook.php` — ALTERs, tabela de páginas e migração**

Logo depois do `if (!$DB->fieldExists($monitorScreens, 'entity_levels')) { ... }` e antes do comentário `// Default configuration`, acrescente:

```php
    if (!$DB->fieldExists($monitorScreens, 'rotation_seconds')) {
        $DB->doQuery("ALTER TABLE `$monitorScreens` ADD COLUMN `rotation_seconds` INT UNSIGNED DEFAULT NULL AFTER `poll_interval_seconds`");
    }
    if (!$DB->fieldExists($monitorScreens, 'row_color_mode')) {
        $DB->doQuery("ALTER TABLE `$monitorScreens` ADD COLUMN `row_color_mode` VARCHAR(10) NOT NULL DEFAULT 'priority' AFTER `sort_mode`");
    }

    // Pages of a Tela (spec M11). The Tela's own savedsearches_id/display_columns columns stay in
    // the table but are no longer read; they are dropped in a later version.
    $monitorPages     = 'glpi_plugin_gac_monitorpages';
    $pagesJustCreated = false;
    if (!$DB->tableExists($monitorPages)) {
        $DB->doQuery("CREATE TABLE `$monitorPages` (
            `id` INT {$sign} NOT NULL AUTO_INCREMENT,
            `plugin_gac_monitorscreens_id` INT {$sign} NOT NULL DEFAULT '0',
            `savedsearches_id` INT {$sign} NOT NULL DEFAULT '0',
            `title` VARCHAR(255) NOT NULL DEFAULT '',
            `display_columns` TEXT DEFAULT NULL,
            `position` INT UNSIGNED NOT NULL DEFAULT '1',
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `plugin_gac_monitorscreens_id` (`plugin_gac_monitorscreens_id`),
            KEY `savedsearches_id` (`savedsearches_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
        $pagesJustCreated = true;
    }

    // Only on the run that creates the pages table: every existing Tela gets its page 1 from its
    // old single saved search + columns. Not repeated afterwards, so deleting all of a Tela's
    // pages is never undone by a later install run.
    if ($pagesJustCreated) {
        $DB->doQuery("INSERT INTO `$monitorPages`
            (`plugin_gac_monitorscreens_id`, `savedsearches_id`, `title`, `display_columns`, `position`, `date_creation`, `date_mod`)
            SELECT `id`, `savedsearches_id`, '', `display_columns`, 1, NOW(), NOW()
            FROM `$monitorScreens`
            WHERE `savedsearches_id` > 0");
    }
```

- [ ] **Step 3: `hook.php` — desinstalação e configuração padrão**

Na lista de tabelas de `plugin_gac_uninstall()`, acrescente `'glpi_plugin_gac_monitorpages',` logo depois de `'glpi_plugin_gac_monitorscreens',`.

Confira que o bloco "Default configuration" usa `MonitorSettings::defaults()` (assim as duas chaves novas entram sozinhas):

Run: `grep -n "MonitorSettings::defaults\|array_diff_key" hook.php`
Expected: uma linha com `MonitorSettings::defaults()` dentro do `array_diff_key`. Se não houver, acrescente `MonitorSettings::defaults()` ao mesmo `array_merge` das outras defaults do módulo.

- [ ] **Step 4: A classe `MonitorPage`**

`src/Monitor/MonitorPage.php`:

```php
namespace GlpiPlugin\Gac\Monitor;

use CommonDBChild;
use CommonGLPI;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use SavedSearch;
use Session;

/** One page of a MonitorScreen's rotation: its own saved search and columns (spec M11). */
class MonitorPage extends CommonDBChild
{
    public static $itemtype  = MonitorScreen::class;
    public static $items_id  = 'plugin_gac_monitorscreens_id';
    public static $rightname = 'plugin_gac_monitor';
    public $dohistory        = false;

    /** Explicit: the class sits in a sub-namespace, GLPI's derived name would be wrong. */
    public static function getTable($classname = null)
    {
        if ($classname !== null && $classname !== static::class) {
            return parent::getTable($classname);
        }
        return 'glpi_plugin_gac_monitorpages';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Página', 'Páginas', $nb, 'gac');
    }

    public static function getIcon()
    {
        return 'ti ti-layout-list';
    }

    /** The form has no "name" column; without this GLPI logs "Item (N/A (id))". */
    public function getName($options = [])
    {
        $title = trim((string) ($this->fields['title'] ?? ''));
        return $title !== '' ? $title : parent::getName($options);
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof MonitorScreen && !$item->isNewItem()) {
            $count = countElementsInTable(self::getTable(), [self::$items_id => $item->getID()]);
            return self::createTabEntry(self::getTypeName(2), $count, null, 'ti ti-layout-list');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof MonitorScreen) {
            return false;
        }
        self::showForScreen($item);
        return true;
    }

    public static function showForScreen(MonitorScreen $screen): void
    {
        $rows = [];
        foreach ($screen->pages() as $page) {
            $rows[] = [
                'url'           => self::getFormURLWithID((int) $page->getID()),
                'position'      => (int) $page->fields['position'],
                'title'         => $page->displayTitle(),
                'saved_search'  => $page->savedSearchName(),
                'columns_count' => count($page->columns()),
            ];
        }

        $canEdit = $screen->canUpdateItem();
        TemplateRenderer::getInstance()->display('@gac/monitor/pages_tab.html.twig', [
            'pages'    => $rows,
            'can_add'  => $canEdit && PageRotation::canAddPage(count($rows)),
            'is_full'  => !PageRotation::canAddPage(count($rows)),
            'max'      => PageRotation::MAX_PAGES,
            'add_url'  => self::getFormURL() . '?' . self::$items_id . '=' . $screen->getID(),
        ]);
    }

    public function prepareInputForAdd($input)
    {
        $input = parent::prepareInputForAdd($input);
        if ($input === false) {
            return false;
        }

        $screenId  = (int) ($input[self::$items_id] ?? 0);
        $positions = [];
        global $DB;
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => [self::$items_id => $screenId]]) as $row) {
            $positions[] = (int) $row['position'];
        }
        if (!PageRotation::canAddPage(count($positions))) {
            Session::addMessageAfterRedirect(
                sprintf(__('Uma Tela aceita no máximo %d páginas.', 'gac'), PageRotation::MAX_PAGES),
                false,
                ERROR
            );
            return false;
        }
        if (!isset($input['position']) || $input['position'] === '') {
            $input['position'] = PageRotation::nextPosition($positions);
        }

        return $this->prepareCommonInput($input);
    }

    public function prepareInputForUpdate($input)
    {
        return $this->prepareCommonInput($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    private function prepareCommonInput(array $input)
    {
        if (array_key_exists('savedsearches_id', $input)) {
            $id = (int) $input['savedsearches_id'];
            if ($id <= 0 || !self::isSharedTicketSavedSearch($id)) {
                Session::addMessageAfterRedirect(
                    __('Escolha uma Pesquisa Salva de Ticket compartilhada.', 'gac'),
                    false,
                    ERROR
                );
                return false;
            }
        }

        if (array_key_exists('display_columns', $input)) {
            $raw = $input['display_columns'];
            $input['display_columns'] = json_encode(
                ColumnCatalog::sanitize(is_array($raw) ? $raw : []),
                JSON_THROW_ON_ERROR
            );
        }

        if (array_key_exists('title', $input)) {
            $input['title'] = mb_substr(trim((string) $input['title']), 0, 255);
        }

        if (array_key_exists('position', $input)) {
            $input['position'] = max(1, (int) $input['position']);
        }

        return $input;
    }

    private static function isSharedTicketSavedSearch(int $id): bool
    {
        $saved = new SavedSearch();
        if (!$saved->getFromDB($id)) {
            return false;
        }
        return $saved->fields['itemtype'] === 'Ticket'
            && (int) $saved->fields['is_private'] === 0
            && (int) $saved->fields['type'] === SavedSearch::SEARCH;
    }

    /** @return array<int, string> id => name, Ticket SavedSearches shared (not private) */
    public static function sharedTicketSavedSearches(): array
    {
        global $DB;
        $options = [];
        foreach ($DB->request([
            'FROM'  => SavedSearch::getTable(),
            'WHERE' => ['itemtype' => 'Ticket', 'is_private' => 0, 'type' => SavedSearch::SEARCH],
            'ORDER' => ['name ASC'],
        ]) as $row) {
            $options[(int) $row['id']] = (string) $row['name'];
        }
        return $options;
    }

    /** @return list<string> */
    public function columns(): array
    {
        $decoded = json_decode((string) ($this->fields['display_columns'] ?? '[]'), true);
        return ColumnCatalog::sanitize(is_array($decoded) ? $decoded : []);
    }

    public function savedSearchName(): string
    {
        $saved = new SavedSearch();
        $id    = (int) ($this->fields['savedsearches_id'] ?? 0);
        return ($id > 0 && $saved->getFromDB($id)) ? (string) $saved->fields['name'] : '';
    }

    public function displayTitle(): string
    {
        return PageRotation::pageTitle((string) ($this->fields['title'] ?? ''), $this->savedSearchName());
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);

        $screenId = $this->isNewItem()
            ? (int) ($options[self::$items_id] ?? 0)
            : (int) $this->fields[self::$items_id];
        if ($this->isNewItem()) {
            // CommonDBChild::canCreateItem() asks the parent through this field; without it the
            // form would render without the "Adicionar" button.
            $this->fields[self::$items_id] = $screenId;
        }

        $columnChoices = [];
        foreach (ColumnCatalog::allKeys() as $key) {
            $columnChoices[$key] = MonitorLabels::column($key);
        }
        // Chosen columns first, in their saved order, then the rest of the catalog.
        $chosen         = $this->isNewItem() ? ColumnCatalog::DEFAULT_COLUMNS : $this->columns();
        $orderedColumns = array_values(array_unique([...$chosen, ...ColumnCatalog::allKeys()]));

        global $DB;
        $positions = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => [self::$items_id => $screenId]]) as $row) {
            $positions[] = (int) $row['position'];
        }

        TemplateRenderer::getInstance()->display('@gac/monitor/monitorpage.form.html.twig', [
            'item'            => $this,
            'params'          => $options,
            'screen_id'       => $screenId,
            'next_position'   => PageRotation::nextPosition($positions),
            'columns_ordered' => $orderedColumns,
            'chosen'          => $chosen,
            'column_choices'  => $columnChoices,
            'saved_searches'  => ['' => Dropdown::EMPTY_VALUE] + self::sharedTicketSavedSearches(),
        ]);

        return true;
    }
}
```

- [ ] **Step 5: `MonitorScreen` — acréscimos (nada é removido nesta tarefa)**

Em `src/Monitor/MonitorScreen.php`, logo depois do método `pollIntervalSeconds()`, acrescente:

```php
    /** @param array<string, string> $settings MonitorSettings-normalized global settings */
    public function rotationSeconds(array $settings): int
    {
        $own = (int) ($this->fields['rotation_seconds'] ?? 0);
        return $own > 0 ? $own : MonitorSettings::defaultRotationSeconds($settings);
    }

    public function rowColorMode(): string
    {
        $mode = (string) ($this->fields['row_color_mode'] ?? '');
        return RowTone::isValidMode($mode) ? $mode : RowTone::DEFAULT_MODE;
    }

    /** @return list<MonitorPage> the Tela's pages in rotation order */
    public function pages(): array
    {
        global $DB;
        $pages = [];
        foreach ($DB->request([
            'FROM'  => MonitorPage::getTable(),
            'WHERE' => [MonitorPage::$items_id => $this->getID()],
            'ORDER' => ['position ASC', 'id ASC'],
        ]) as $row) {
            $page         = new MonitorPage();
            $page->fields = $row;
            $pages[]      = $page;
        }
        return $pages;
    }
```

- [ ] **Step 6: Lint e reinstalar em dev**

Run: `for f in hook.php src/Monitor/MonitorPage.php src/Monitor/MonitorScreen.php; do /c/xampp/php/php.exe -l $f; done`
Expected: `No syntax errors detected` nos três.

Antes de reinstalar, teste a migração contra dados reais (a Tela de demo existente ainda tem os campos antigos):

```sql
-- no cliente mysql, banco do GLPI de dev
SELECT id, savedsearches_id, display_columns FROM glpi_plugin_gac_monitorscreens WHERE savedsearches_id > 0;
```

Anote essas linhas; depois reinstale o plugin (comando "Reinstalar" nas Restrições globais). A reinstalação apaga as Telas, então o INSERT…SELECT roda sobre tabela vazia aqui; para exercitá-lo de verdade, depois da reinstalação:

```sql
INSERT INTO glpi_plugin_gac_monitorscreens (name, entities_id, savedsearches_id, display_columns, is_active)
VALUES ('legado', 0, (SELECT MIN(id) FROM glpi_savedsearches WHERE itemtype='Ticket' AND is_private=0), '["id","title"]', 1);
INSERT INTO glpi_plugin_gac_monitorpages
  (plugin_gac_monitorscreens_id, savedsearches_id, title, display_columns, position, date_creation, date_mod)
  SELECT id, savedsearches_id, '', display_columns, 1, NOW(), NOW() FROM glpi_plugin_gac_monitorscreens WHERE savedsearches_id > 0;
SELECT * FROM glpi_plugin_gac_monitorpages;
```

Expected: a segunda consulta termina sem erro e o `SELECT` final mostra uma página com `position = 1` e `display_columns = ["id","title"]` para a Tela `legado` (a mesma instrução do `hook.php`, copiada como texto). Depois: `DELETE FROM glpi_plugin_gac_monitorpages; DELETE FROM glpi_plugin_gac_monitorscreens;`.

Verifique também o schema criado pelo install:

```sql
SHOW COLUMNS FROM glpi_plugin_gac_monitorpages;
SHOW COLUMNS FROM glpi_plugin_gac_monitorscreens LIKE 'rotation_seconds';
SHOW COLUMNS FROM glpi_plugin_gac_monitorscreens LIKE 'row_color_mode';
```

Expected: as 8 colunas da tabela de páginas; `rotation_seconds` nullable; `row_color_mode` com padrão `priority`.

- [ ] **Step 7: Commit** — invocar `/commit` (sugestão: `feat(monitor): add the pages table, its migration and the MonitorPage class`).

---

### Task 4: `ScreenQuery` roda uma busca por página e calcula o tom

**Files:**
- Modify: `src/Monitor/ScreenQuery.php` (substituir `run()`; novo `runPage()`; constantes)

**Interfaces:**
- Consumes: `MonitorScreen::pages()`, `rotationSeconds()`, `rowColorMode()` (Task 3); `MonitorPage::columns()`, `displayTitle()`, `$fields['savedsearches_id']`; `RowTone::compute()` (Task 2); `MonitorSettings::slaWarningMinutes()` (Task 1).
- Produces: `ScreenQuery::run(MonitorScreen $screen, bool $asServiceAccount = false): array` com as chaves `pages` (`list<array{id: int, title: string, columns: list<array{key: string, label: string}>, rows: list<array<string, string>>}>`), `priority_colors`, `theme`, `font_size_rem`, `poll_interval_seconds`, `rotation_seconds`, `row_color_mode`, e, provisoriamente, `columns`/`rows` (decisão 8). Cada linha de `rows` ganha `row_tone` (string).

- [ ] **Step 1: Sonda do SLA no GLPI de dev (R-7)**

Crie (ou reaproveite) uma Tela e uma página (via SQL, pois o formulário só chega na Tarefa 5) e confirme que a busca devolve os prazos como data:

```sql
-- escolha 3 tickets abertos da entidade da Tela de teste
UPDATE glpi_tickets SET time_to_resolve = DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE id = <A>;
UPDATE glpi_tickets SET time_to_resolve = DATE_SUB(NOW(), INTERVAL 2 HOUR)  WHERE id = <B>;
UPDATE glpi_tickets SET time_to_resolve = NULL, time_to_own = NULL           WHERE id = <C>;
```

Cole **temporariamente** no topo de `front/monitor/monitorscreen.php` (depois dos `use`/includes):

```php
if (isset($_GET['probe'])) {
    global $DB;
    $p = [];
    $saved = new SavedSearch();
    $saved->getFromDB((int) $_GET['probe']);
    parse_str((string) $saved->fields['query'], $p);
    $p['reset'] = 'reset'; $p['is_deleted'] = 0; $p['start'] = 0; $p['list_limit'] = 5;
    $data = Search::getDatas('Ticket', $p, [2, 12, 18, 155]);
    echo '<pre>'; var_dump(array_map(static fn($r) => [$r['Ticket_2'][0]['name'] ?? null, $r['Ticket_12'][0]['name'] ?? null, $r['Ticket_18'][0]['name'] ?? null, $r['Ticket_155'][0]['name'] ?? null], $data['data']['rows'] ?? [])); echo '</pre>'; exit;
}
```

Abra `http://glpi11local.test/plugins/gac/front/monitor/monitorscreen.php?probe=<id de uma Pesquisa Salva de Ticket compartilhada>` logado.
Expected: para o ticket A, a 3ª coluna é uma data `YYYY-MM-DD HH:MM:SS` no futuro; para B, no passado; para C, `null`. Se vier em outro formato (ex.: já formatada em `dd-mm-aaaa`, ou HTML), **pare**: o parse de `RowTone` precisa mudar (ajuste `RowTone::parse` e os testes antes de seguir). **Remova o código temporário** depois.

- [ ] **Step 2: Substituir `run()` e acrescentar `runPage()`**

Em `src/Monitor/ScreenQuery.php`, acrescente as constantes logo depois de `private const LIST_LIMIT = 200;`:

```php
    private const SEARCH_OPTION_TIME_TO_RESOLVE = 18;
    private const SEARCH_OPTION_TIME_TO_OWN     = 155;
```

Substitua o método `run()` inteiro (do docblock `/** @param bool $asServiceAccount ...` até o `}` que o fecha, antes do docblock de `priorityColors()`) por:

```php
    /**
     * @param bool $asServiceAccount Public, session-less path only (Task 8): Search::getDatas()
     *     needs a real logged-in session shape (profile, groups — not just entities, see spec
     *     R-1/R-3), so this logs in as the Monitor service account for the duration of the call
     *     and logs back out before returning. One login per request, not per page. Never true for
     *     the authenticated display, which already has the technician's own real session.
     * @return array<string, mixed> see spec section 6.4
     */
    public static function run(MonitorScreen $screen, bool $asServiceAccount = false): array
    {
        $settings  = MonitorConfig::load();
        $sortMode  = TicketSortOrder::isValidMode((string) ($screen->fields['sort_mode'] ?? ''))
            ? (string) $screen->fields['sort_mode']
            : TicketSortOrder::DEFAULT_MODE;
        $colorMode = $screen->rowColorMode();

        if ($asServiceAccount) {
            if (!ServiceSession::login($settings)) {
                throw new \RuntimeException('Monitor service account is not configured or login failed.');
            }
        }

        try {
            $pages = [];
            foreach ($screen->pages() as $page) {
                $pages[] = self::runPage($screen, $page, $sortMode, $colorMode, $settings);
            }
        } finally {
            if ($asServiceAccount) {
                ServiceSession::logout();
            }
        }

        return [
            'pages'                 => $pages,
            // Transitional (plan decision 8, removed in Task 6): the first page in the shape the
            // single-page board used to read.
            'columns'               => $pages[0]['columns'] ?? [],
            'rows'                  => $pages[0]['rows'] ?? [],
            'priority_colors'       => self::priorityColors(),
            // Sent on every poll (not just the initial page render) so a theme/font-size/interval
            // change made to the Tela while a screen is already open (e.g. a TV left running)
            // takes effect on the next cycle instead of requiring a manual reload.
            'theme'                 => (string) $screen->fields['theme'],
            'font_size_rem'         => BoardAppearance::fontSizeRem((int) $screen->fields['font_size']),
            'poll_interval_seconds' => $screen->pollIntervalSeconds($settings),
            'rotation_seconds'      => $screen->rotationSeconds($settings),
            'row_color_mode'        => $colorMode,
        ];
    }

    /**
     * One page's search. Runs inside the session `run()` already established.
     *
     * @param array<string, string> $settings
     * @return array{id: int, title: string, columns: list<array{key: string, label: string}>, rows: list<array<string, string>>}
     */
    private static function runPage(MonitorScreen $screen, MonitorPage $page, string $sortMode, string $colorMode, array $settings): array
    {
        $columns = $page->columns();

        $saved    = new SavedSearch();
        $hasSaved = (int) $page->fields['savedsearches_id'] > 0
            && $saved->getFromDB((int) $page->fields['savedsearches_id']);

        $params = [];
        if ($hasSaved) {
            parse_str((string) $saved->fields['query'], $params);
        }
        $params['reset']      = 'reset';
        $params['is_deleted'] = 0;
        $params['start']      = 0;
        $params['list_limit'] = self::LIST_LIMIT;
        $params['criteria']   = $params['criteria'] ?? [];

        // Priority (3) is always fetched, regardless of whether "priority" is a chosen display
        // column: it drives the row tone and the badge, a visual cue independent of the text
        // column. Urgency (10), status (12) and opening date (15) are likewise always needed to
        // sort even when the page does not display them. The SLA deadlines are only fetched for
        // the "sla" colour mode.
        $forcedisplay = [...ColumnCatalog::searchOptionIdsFor($columns), 3, 10, 12, 15];
        if ($colorMode === RowTone::MODE_SLA) {
            $forcedisplay[] = self::SEARCH_OPTION_TIME_TO_RESOLVE;
            $forcedisplay[] = self::SEARCH_OPTION_TIME_TO_OWN;
        }
        $forcedisplay = array_values(array_unique($forcedisplay));

        $previousEntities       = $_SESSION['glpiactiveentities'] ?? null;
        $previousEntitiesString = $_SESSION['glpiactiveentities_string'] ?? null;
        $previousShowAll        = $_SESSION['glpishowallentities'] ?? null;
        self::forceEntityScope((int) $screen->fields['entities_id'], (bool) $screen->fields['is_recursive']);

        try {
            $data = Search::getDatas('Ticket', $params, $forcedisplay);
        } finally {
            // Never leaves a real session (the authenticated display, or the service account)
            // scoped to the Tela's entity — restore exactly what was there before, or clear it
            // if there was nothing.
            if ($previousEntities === null) {
                unset($_SESSION['glpiactiveentities'], $_SESSION['glpiactiveentities_string']);
            } else {
                $_SESSION['glpiactiveentities']        = $previousEntities;
                $_SESSION['glpiactiveentities_string'] = $previousEntitiesString;
            }
            if ($previousShowAll === null) {
                unset($_SESSION['glpishowallentities']);
            } else {
                $_SESSION['glpishowallentities'] = $previousShowAll;
            }
        }

        // Built while the session is still alive: columnValue() formats dates through
        // Html::convDateTime(), which reads the session's configured date format.
        $now     = new \DateTimeImmutable();
        $warning = MonitorSettings::slaWarningMinutes($settings);
        $entries = [];
        foreach ($data['data']['rows'] ?? [] as $row) {
            $idParts = self::cellParts($row, 2);
            if ($idParts === []) {
                continue;
            }
            $id          = $idParts[0];
            $status      = (int) (self::cellParts($row, 12)[0] ?? 0);
            $priorityRaw = (int) (self::cellParts($row, 3)[0] ?? 0);
            $out         = ['id' => $id, 'priority_raw' => $priorityRaw];
            foreach ($columns as $key) {
                if ($key === 'elapsed') {
                    $openedParts = self::cellParts($row, 15);
                    $out[$key]   = $openedParts === []
                        ? ''
                        : ElapsedTimeLabel::format($openedParts[0], $now);
                    continue;
                }
                if ($key === 'entity') {
                    $out[$key] = EntityLevels::truncate(
                        self::columnValue($row, $key),
                        (int) ($screen->fields['entity_levels'] ?? EntityLevels::DEFAULT_LEVELS)
                    );
                    continue;
                }
                $out[$key] = self::columnValue($row, $key);
            }
            $out['row_tone'] = RowTone::compute(
                $colorMode,
                $status,
                $priorityRaw,
                self::cellParts($row, self::SEARCH_OPTION_TIME_TO_RESOLVE)[0] ?? null,
                self::cellParts($row, self::SEARCH_OPTION_TIME_TO_OWN)[0] ?? null,
                $now,
                $warning
            );
            $entries[] = [
                'out'     => $out,
                'urgency' => (int) (self::cellParts($row, 10)[0] ?? 0),
                'status'  => $status,
                'date'    => (string) (self::cellParts($row, 15)[0] ?? ''),
            ];
        }

        if ($sortMode === TicketSortOrder::MODE_PRIORITY) {
            usort($entries, static fn(array $a, array $b): int => TicketSortOrder::compare($a, $b));
        }
        $rows = array_map(static fn(array $entry): array => $entry['out'], $entries);

        $labels = [];
        foreach ($columns as $key) {
            $labels[] = ['key' => $key, 'label' => MonitorLabels::column($key)];
        }

        return [
            'id'      => (int) $page->getID(),
            'title'   => $page->displayTitle(),
            'columns' => $labels,
            'rows'    => $rows,
        ];
    }
```

- [ ] **Step 3: Lint e teste manual contra o GLPI**

Run: `/c/xampp/php/php.exe -l src/Monitor/ScreenQuery.php`
Expected: `No syntax errors detected`.

Com a Tela de teste e a página da Sonda (insira a página por SQL: `INSERT INTO glpi_plugin_gac_monitorpages (plugin_gac_monitorscreens_id, savedsearches_id, title, display_columns, position) VALUES (<tela>, <pesquisa>, 'Teste', '["id","title","status","priority"]', 1);` e `UPDATE glpi_plugin_gac_monitorscreens SET row_color_mode='sla' WHERE id=<tela>;`), abra logado `http://glpi11local.test/plugins/gac/ajax/monitor/data.php?id=<tela>`.
Expected: JSON com `pages` (1 item com `id`, `title` = "Teste", `columns`, `rows`), `rotation_seconds` = 20, `row_color_mode` = `sla`, e cada linha com `row_tone`: `sla-warning` para o ticket A, `sla-late` para o B, `sla-none` para o C (nenhum desses em Pendente/Solucionado). Troque `row_color_mode` para `status`, `priority` e `none` e confira os tons (`status-*`, `priority-N`, `''`). As chaves `columns`/`rows` de topo existem e iguais às da página 1. Repita contra `ajax/monitor/public_data.php?token=<token>` (Tela pública) para confirmar o caminho da conta de serviço com 2 páginas (insira uma segunda página por SQL): `pages` com 2 itens.

- [ ] **Step 4: Commit** — invocar `/commit` (sugestão: `feat(monitor): query one search per page and compute the row tone`).

---

### Task 5: Formulários — páginas, Tela e seletor de colunas

**Files:**
- Create: `templates/monitor/_column_picker.html.twig`, `templates/monitor/monitorpage.form.html.twig`, `templates/monitor/pages_tab.html.twig`, `front/monitor/monitorpage.form.php`
- Modify: `templates/monitor/monitorscreen.form.html.twig`, `src/Monitor/MonitorScreen.php`, `src/Monitor/MonitorLabels.php`

**Interfaces:**
- Consumes: `MonitorPage::showForm()`, `showForScreen()`, `MonitorPage::getFormURL()` (Task 3); `RowTone::MODES` (Task 2); `PageRotation::clampRotation()` (Task 1).
- Produces: `MonitorLabels::rowColorMode(string): string`; aba "Páginas" na Tela; campos `rotation_seconds` e `row_color_mode` no formulário da Tela.

- [ ] **Step 1: Mover o seletor de colunas para um parcial**

Run (corta o bloco do formulário da Tela, do `<hr>` antes de "Colunas exibidas" até antes do `{% endblock %}`, e o grava como parcial):

```bash
python - <<'EOF'
p = 'templates/monitor/monitorscreen.form.html.twig'
s = open(p, encoding='utf-8', newline='').read()
nl = '\r\n' if '\r\n' in s else '\n'
t = s.replace('\r\n', '\n')
start = t.index("    <hr>\n    <h4>{{ __('Colunas exibidas', 'gac') }}</h4>")
end = t.rindex('{% endblock %}')
block = t[start:end]
open('templates/monitor/_column_picker.html.twig', 'w', encoding='utf-8', newline='').write(block.replace('\n', nl))
open(p, 'w', encoding='utf-8', newline='').write((t[:start] + t[end:]).replace('\n', nl))
EOF
head -3 templates/monitor/_column_picker.html.twig; tail -3 templates/monitor/monitorscreen.form.html.twig
```

Expected: o parcial começa em `    <hr>` / `<h4>... Colunas exibidas` e termina no `</script>` do arrastar; o formulário da Tela termina em `{% endif %}` seguido de `{% endblock %}` (sem a lista de colunas).

- [ ] **Step 2: Formulário da Tela — tirar a pesquisa salva, pôr rodízio e cor**

Em `templates/monitor/monitorscreen.form.html.twig`:

1. Apague as três linhas do dropdown `savedsearches_id` (`{{ fields.dropdownArrayField('savedsearches_id', ...) }}` com o `required: true` e o `}) }}` que a fecha).
2. Logo depois da linha do `poll_interval_seconds` (o `numberField` de várias linhas), acrescente:

```twig
    {{ fields.numberField('rotation_seconds', item.fields['rotation_seconds'], __('Tempo de cada página no rodízio (segundos)', 'gac'), {
        min: 5,
        placeholder: __('Em branco usa o padrão da configuração global', 'gac'),
    }) }}

    {{ fields.dropdownArrayField('row_color_mode', item.fields['row_color_mode']|default('priority'), row_color_choices, __('Cor das linhas', 'gac')) }}
```

- [ ] **Step 3: `MonitorLabels::rowColorMode()`**

Em `src/Monitor/MonitorLabels.php`, depois de `sortMode()`:

```php
    public static function rowColorMode(string $mode): string
    {
        return match ($mode) {
            RowTone::MODE_NONE     => __('Sem cor', 'gac'),
            RowTone::MODE_STATUS   => __('Status do chamado', 'gac'),
            RowTone::MODE_PRIORITY => __('Prioridade do chamado (recomendado)', 'gac'),
            RowTone::MODE_SLA      => __('Prazo do SLA (vencido, perto de vencer, no prazo)', 'gac'),
            default                => $mode,
        };
    }
```

- [ ] **Step 4: `MonitorScreen` — remover o legado, validar os campos novos, aba e purge**

Em `src/Monitor/MonitorScreen.php`:

1. Em `prepareCommonInput()`, apague o bloco `if (array_key_exists('savedsearches_id', $input)) { ... }` e o bloco `if (array_key_exists('display_columns', $input)) { ... }`. Depois do bloco de `sort_mode`, acrescente:

```php
        if (array_key_exists('row_color_mode', $input)) {
            $mode = (string) $input['row_color_mode'];
            $input['row_color_mode'] = RowTone::isValidMode($mode) ? $mode : RowTone::DEFAULT_MODE;
        }
```

e, depois do bloco de `poll_interval_seconds`:

```php
        if (array_key_exists('rotation_seconds', $input)) {
            $seconds = ($input['rotation_seconds'] !== '' && is_numeric($input['rotation_seconds']))
                ? (int) $input['rotation_seconds']
                : null;
            $input['rotation_seconds'] = PageRotation::clampRotation($seconds);
        }
```

2. Apague os métodos `isSharedTicketSavedSearch()`, `displayColumns()` e `sharedTicketSavedSearches()` (agora vivem em `MonitorPage`).
3. `defineTabs()` fica:

```php
    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs);
        $this->addStandardTab(MonitorPage::class, $tabs, $options);
        $this->addStandardTab('Log', $tabs, $options);
        return $tabs;
    }

    public function cleanDBonPurge()
    {
        $this->deleteChildrenAndRelationsFromDb([MonitorPage::class]);
    }
```

4. Em `showForm()`: apague a montagem de `$columnChoices`, de `$chosen`/`$orderedColumns` e as chaves `columns_ordered`, `chosen`, `column_choices`, `saved_searches` do array passado ao Twig. Antes do `TemplateRenderer`, acrescente:

```php
        $rowColorChoices = [];
        foreach (RowTone::MODES as $mode) {
            $rowColorChoices[$mode] = MonitorLabels::rowColorMode($mode);
        }
```

e passe `'row_color_choices' => $rowColorChoices,` no array.
5. Em `rawSearchOptions()`: troque a opção `id => 3` (Pesquisa Salva) por duas opções novas, e acrescente `row_color_mode` ao `getSpecificValueToDisplay`:

```php
            [
                'id' => 11, 'table' => $t, 'field' => 'rotation_seconds', 'name' => __('Tempo de cada página (s)', 'gac'),
                'datatype' => 'number', 'massiveaction' => false,
            ],
            [
                'id' => 12, 'table' => $t, 'field' => 'row_color_mode', 'name' => __('Cor das linhas', 'gac'),
                'datatype' => 'specific', 'massiveaction' => false,
            ],
```

e em `getSpecificValueToDisplay()`, dentro do `match`, acrescente `'row_color_mode' => htmlescape(MonitorLabels::rowColorMode((string) $values[$field])),`.
6. Remova os `use Dropdown;` e `use SavedSearch;` se nenhum outro ponto do arquivo os usar (`grep -n "Dropdown\|SavedSearch" src/Monitor/MonitorScreen.php`).

- [ ] **Step 5: Templates e controlador da página**

`templates/monitor/monitorpage.form.html.twig`:

```twig
{% extends 'generic_show_form.html.twig' %}
{% import 'components/form/fields_macros.html.twig' as fields %}

{% block form_fields %}
    <input type="hidden" name="plugin_gac_monitorscreens_id" value="{{ screen_id }}">

    {{ fields.dropdownArrayField('savedsearches_id', item.fields['savedsearches_id'], saved_searches, __('Pesquisa salva (Ticket, compartilhada)', 'gac'), {
        required: true,
    }) }}

    {{ fields.textField('title', item.fields['title'], __('Título da página (opcional)', 'gac'), {
        placeholder: __('Em branco usa o nome da pesquisa salva', 'gac'),
    }) }}

    {{ fields.numberField('position', item.fields['position']|default(next_position), __('Posição no rodízio', 'gac'), {
        min: 1,
    }) }}

    {% include '@gac/monitor/_column_picker.html.twig' %}
{% endblock %}
```

`templates/monitor/pages_tab.html.twig`:

```twig
<div class="m-3">
    <p class="text-muted">
        {{ __('Cada página tem a sua pesquisa salva e as suas colunas. Com mais de uma página, a tela alterna entre elas no tempo configurado.', 'gac') }}
    </p>

    {% if pages is empty %}
        <div class="alert alert-warning">{{ __('Esta Tela ainda não tem páginas: ela não mostra tickets até que você adicione pelo menos uma.', 'gac') }}</div>
    {% else %}
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>{{ __('Posição', 'gac') }}</th>
                    <th>{{ __('Título', 'gac') }}</th>
                    <th>{{ __('Pesquisa salva', 'gac') }}</th>
                    <th>{{ __('Colunas', 'gac') }}</th>
                </tr>
            </thead>
            <tbody>
                {% for page in pages %}
                    <tr>
                        <td>{{ page.position }}</td>
                        <td><a href="{{ page.url }}">{{ page.title|default(__('(sem título)', 'gac')) }}</a></td>
                        <td>{{ page.saved_search }}</td>
                        <td>{{ page.columns_count }}</td>
                    </tr>
                {% endfor %}
            </tbody>
        </table>
    {% endif %}

    {% if can_add %}
        <a class="btn btn-primary" href="{{ add_url }}"><i class="ti ti-plus"></i> {{ __('Adicionar página', 'gac') }}</a>
    {% elseif is_full %}
        <div class="text-muted small">{{ __('Limite de %d páginas por Tela atingido.', 'gac')|format(max) }}</div>
    {% endif %}
</div>
```

`front/monitor/monitorpage.form.php` (depois do cabeçalho de licença e dos `use`):

```php
use GlpiPlugin\Gac\GacMenu;
use GlpiPlugin\Gac\Monitor\MonitorPage;
use GlpiPlugin\Gac\Monitor\MonitorScreen;

$item = new MonitorPage();

if (isset($_POST['add'])) {
    $item->check(-1, CREATE, $_POST);
    if ($item->add($_POST)) {
        Html::redirect(MonitorScreen::getFormURLWithID((int) $_POST[MonitorPage::$items_id]));
    }
    Html::back();
} elseif (isset($_POST['update'])) {
    $item->check($_POST['id'], UPDATE);
    $item->update($_POST);
    Html::redirect(MonitorScreen::getFormURLWithID((int) $item->fields[MonitorPage::$items_id]));
} elseif (isset($_POST['purge'])) {
    $item->check($_POST['id'], PURGE);
    $screenId = (int) $item->fields[MonitorPage::$items_id];
    $item->delete($_POST, 1);
    Html::redirect(MonitorScreen::getFormURLWithID($screenId));
} else {
    Html::header(
        MonitorPage::getTypeName(1),
        $_SERVER['PHP_SELF'],
        GacMenu::SECTOR,
        GacMenu::ITEM_MONITOR
    );
    // showForm() directly, not display(): this page is reached from the Tela's "Páginas" tab, so
    // it needs no tab strip of its own (plan decision 7).
    $item->showForm((int) ($_GET['id'] ?? -1), [
        MonitorPage::$items_id => (int) ($_GET[MonitorPage::$items_id] ?? 0),
    ]);
    Html::footer();
}
```

- [ ] **Step 6: Lint, cache e verificação no navegador**

Run: `for f in src/Monitor/MonitorScreen.php src/Monitor/MonitorLabels.php front/monitor/monitorpage.form.php; do /c/xampp/php/php.exe -l $f; done; cd /c/Users/juliano/VSCode/glpi-xampp-dev-plugin && /c/xampp/php/php.exe bin/console cache:clear`
Expected: `No syntax errors detected` ×3; cache limpo.

No navegador (logado como super-admin, Claude in Chrome): crie uma Tela nova; abra a aba "Páginas" → mensagem "ainda não tem páginas" e botão "Adicionar página". Clique → formulário com pesquisa, título, posição (preenchida com 1) e a lista de colunas arrastável; adicione a página 1 → volta à Tela com 1 página listada; adicione a 2 (posição preenchida com 2); edite a 1 (mude o título) e confirme a volta à Tela; exclua a 2 (confirma o `purge`). Tente adicionar uma 9ª página (via SQL deixe 8) → mensagem de limite e botão ausente. Tente salvar uma página sem pesquisa → erro "Escolha uma Pesquisa Salva...". No formulário da Tela, confira o campo "Cor das linhas" e "Tempo de cada página", sem o dropdown de pesquisa salva e sem lista de colunas. Exclua a Tela e confirme por SQL (`SELECT COUNT(*) FROM glpi_plugin_gac_monitorpages WHERE plugin_gac_monitorscreens_id = <id>`) que as páginas sumiram (`cleanDBonPurge`). Confirme também que o botão "Adicionar" aparece no formulário da página nova (risco do `canCreateItem`, Task 3 Step 4).

- [ ] **Step 7: Commit** — invocar `/commit` (sugestão: `feat(monitor): manage a screen's pages and choose the row colour mode`).

---

### Task 6: Front-end — rodízio, seletor, overflow e cor de linha

**Files:**
- Modify: `templates/monitor/_board.html.twig`, `templates/monitor/display.html.twig`, `public/js/monitor.js` (reescrita de `boot()` e dos renderizadores), `public/css/monitor.css`
- Modify: `src/Monitor/ScreenQuery.php` (remover as chaves provisórias `columns`/`rows`)

**Interfaces:**
- Consumes: JSON `{pages: [{id, title, columns, rows}], priority_colors, theme, font_size_rem, poll_interval_seconds, rotation_seconds, row_color_mode, generated_at}`; cada linha com `id`, `priority_raw`, `row_tone`, e uma chave por coluna.
- Produces: atributos `data-gac-monitor-pager`, `data-gac-monitor-viewport`, `data-gac-monitor-empty`, `data-gac-monitor-overflow` no `_board.html.twig`.

- [ ] **Step 1: Template do board**

Substitua `templates/monitor/_board.html.twig` por:

```twig
<div class="gac-monitor-board{{ theme == 'light' ? ' gac-theme-light' : '' }}{{ embedded|default(false) ? ' gac-monitor-embedded' : '' }}"
     style="--gac-table-font-size: {{ font_size_rem }};"
     data-gac-monitor
     data-ajax-url="{{ ajax_url }}"
     data-poll-interval="{{ poll_interval }}"
     data-alert-enabled="{{ alert_enabled ? '1' : '0' }}">
    <div class="gac-monitor-topbar">
        <h1 class="gac-monitor-title">{{ screen.fields.name }}</h1>
        <div class="gac-monitor-topbar-right">
            <div class="gac-monitor-clock" data-gac-monitor-clock>00:00:00</div>
            <div class="gac-monitor-countdown" data-gac-monitor-status title="">
                <svg viewBox="0 0 36 36" class="gac-countdown-ring">
                    <circle class="gac-countdown-ring-bg" cx="18" cy="18" r="15.9" pathLength="100"></circle>
                    <circle class="gac-countdown-ring-fg" cx="18" cy="18" r="15.9" pathLength="100" data-gac-countdown-circle></circle>
                </svg>
            </div>
        </div>
    </div>
    <div class="gac-monitor-pager" data-gac-monitor-pager hidden></div>
    <div class="gac-monitor-viewport" data-gac-monitor-viewport>
        <table>
            <thead><tr data-gac-monitor-head></tr></thead>
            <tbody data-gac-monitor-body></tbody>
        </table>
        <div class="gac-monitor-empty" data-gac-monitor-empty hidden>{{ __('Nenhuma página configurada para esta tela.', 'gac') }}</div>
    </div>
    <div class="gac-monitor-overflow" data-gac-monitor-overflow hidden></div>
    <audio data-gac-monitor-audio src="{{ alert_sound_url }}" preload="auto"></audio>
</div>
```

Em `templates/monitor/display.html.twig`, troque o conteúdo por `{% include '@gac/monitor/_board.html.twig' with {embedded: true} %}` (confira primeiro o conteúdo atual com `cat templates/monitor/display.html.twig`; se houver algo além do `include`, preserve).

- [ ] **Step 2: Reescrever `public/js/monitor.js`**

Mantenha **sem mudança** as funções `pad`, `startClock`, `readableTextColor`, `setConnectionState`, `setWaitingState`, `restartCountdown`, `playAlert` e `applyAppearance` (linhas 1–38 e 88–140 do arquivo atual). Substitua `renderHeader`, `renderRows` e `boot` (e acrescente as funções novas) por:

```js
    function hexToRgba(hex, alpha) {
        const match = /^#?([0-9a-f]{6})$/i.exec(hex || '');
        if (!match) {
            return null;
        }
        const n = parseInt(match[1], 16);
        return 'rgba(' + ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + alpha + ')';
    }

    function renderHeader(root, columns) {
        const headRow = root.querySelector('[data-gac-monitor-head]');
        headRow.innerHTML = '';
        columns.forEach(function (col) {
            const th = document.createElement('th');
            th.textContent = col.label;
            headRow.appendChild(th);
        });
    }

    // Row colour (spec M15/M16): the server sends a tone (a meaning, never a colour). "priority-N"
    // takes the GLPI-configured priority colour; every other tone is a CSS class that defines its
    // own --gac-row-accent/--gac-row-tint.
    function applyTone(tr, tone, priorityColors) {
        if (!tone) {
            return;
        }
        if (tone.indexOf('priority-') === 0) {
            const color = priorityColors && priorityColors[tone.slice('priority-'.length)];
            const tint = hexToRgba(color, 0.22);
            if (color && tint) {
                tr.classList.add('gac-row-toned');
                tr.style.setProperty('--gac-row-accent', color);
                tr.style.setProperty('--gac-row-tint', tint);
            }
            return;
        }
        tr.classList.add('gac-row-toned', 'gac-tone-' + tone);
    }

    function renderRows(root, page, newIds, priorityColors) {
        const body = root.querySelector('[data-gac-monitor-body]');
        body.innerHTML = '';
        page.rows.forEach(function (row) {
            const tr = document.createElement('tr');
            tr.dataset.ticketId = row.id;
            applyTone(tr, row.row_tone, priorityColors);
            if (newIds && newIds.has(String(row.id))) {
                tr.classList.add('gac-monitor-row-new');
            }
            const badgeColor = priorityColors && priorityColors[row.priority_raw];
            page.columns.forEach(function (col) {
                const td = document.createElement('td');
                if (col.key === 'priority' && badgeColor) {
                    const badge = document.createElement('span');
                    badge.className = 'gac-priority-badge';
                    badge.style.backgroundColor = badgeColor;
                    badge.style.color = readableTextColor(badgeColor);
                    badge.textContent = row[col.key] || '';
                    td.appendChild(badge);
                } else {
                    td.textContent = row[col.key] || '';
                }
                tr.appendChild(td);
            });
            body.appendChild(tr);
        });
    }

    // Overflow bar (spec M14): counts the rows whose bottom edge passes the visible area (a row cut
    // in half counts as hidden). The bar sits outside the viewport, so showing it shrinks the
    // viewport: measure once without it, and again with it when there is overflow.
    function countHiddenRows(root) {
        const viewport = root.querySelector('[data-gac-monitor-viewport]');
        const limit = viewport.getBoundingClientRect().bottom;
        let hidden = 0;
        root.querySelectorAll('[data-gac-monitor-body] tr').forEach(function (tr) {
            if (tr.getBoundingClientRect().bottom > limit + 1) {
                hidden += 1;
            }
        });
        return hidden;
    }

    function updateOverflow(root) {
        const bar = root.querySelector('[data-gac-monitor-overflow]');
        if (!bar) {
            return;
        }
        bar.hidden = true;
        if (countHiddenRows(root) === 0) {
            return;
        }
        bar.hidden = false;
        const hidden = countHiddenRows(root);
        if (hidden === 0) {
            bar.hidden = true;
            return;
        }
        bar.textContent = '\u25BC ' + hidden + (hidden === 1 ? ' ticket abaixo' : ' tickets abaixo');
    }

    function renderPager(root, pages, activeId, flashing) {
        const pager = root.querySelector('[data-gac-monitor-pager]');
        pager.innerHTML = '';
        if (pages.length <= 1) {
            pager.hidden = true;
            return;
        }
        pager.hidden = false;
        pages.forEach(function (page) {
            const pill = document.createElement('span');
            pill.className = 'gac-pager-pill'
                + (String(page.id) === activeId ? ' gac-pager-active' : '')
                + (flashing[String(page.id)] ? ' gac-pager-flash' : '');
            const title = document.createElement('span');
            title.textContent = page.title;
            const count = document.createElement('span');
            count.className = 'gac-pager-count';
            count.textContent = String(page.rows.length);
            pill.appendChild(title);
            pill.appendChild(count);
            pager.appendChild(pill);
        });
    }

    function boot(root) {
        const url = root.dataset.ajaxUrl;
        // Mutable: a poll_interval_seconds/rotation_seconds change on the Tela takes effect from
        // the next cycle on, same as theme/font size.
        let interval = Math.max(5, parseInt(root.dataset.pollInterval, 10) || 15) * 1000;
        let rotationMs = 20000;
        const alertEnabled = root.dataset.alertEnabled === '1';

        let clockOffsetMs = 0;
        startClock(root, function () { return clockOffsetMs; });

        let lastSuccess = null;
        let fetching = false;

        // Per-page state (spec M13): the diff of "new ticket" is per page, kept in memory.
        let pages = [];
        let priorityColors = {};
        let activeId = null;
        let rotationTimer = null;
        let rotationKey = '';
        const previousIds = {}; // page id => Set of ticket ids seen at the last poll
        const newIds = {};      // page id => Set of ids that appeared at the last poll
        const flashing = {};    // page id => true while a hidden page's pill blinks

        // Returns true when any page got a ticket it did not have at the previous poll. The first
        // load of each page never counts (no previous set yet).
        function diffPages(payloadPages) {
            let anyNew = false;
            const live = {};
            payloadPages.forEach(function (page) {
                const pid = String(page.id);
                live[pid] = true;
                const ids = page.rows.map(function (row) { return String(row.id); });
                const fresh = new Set();
                if (previousIds[pid]) {
                    ids.forEach(function (id) {
                        if (!previousIds[pid].has(id)) {
                            fresh.add(id);
                        }
                    });
                }
                previousIds[pid] = new Set(ids);
                newIds[pid] = fresh;
                if (fresh.size > 0) {
                    anyNew = true;
                    if (pid !== activeId) {
                        flashing[pid] = true;
                    }
                }
            });
            Object.keys(previousIds).forEach(function (pid) {
                if (!live[pid]) {
                    delete previousIds[pid];
                    delete newIds[pid];
                    delete flashing[pid];
                }
            });
            return anyNew;
        }

        function activePage() {
            for (let i = 0; i < pages.length; i += 1) {
                if (String(pages[i].id) === activeId) {
                    return pages[i];
                }
            }
            return null;
        }

        function showActive() {
            const table = root.querySelector('[data-gac-monitor-viewport] table');
            const empty = root.querySelector('[data-gac-monitor-empty]');
            if (pages.length === 0) {
                activeId = null;
                table.hidden = true;
                empty.hidden = false;
                renderPager(root, pages, activeId, flashing);
                updateOverflow(root);
                return;
            }
            if (activePage() === null) {
                activeId = String(pages[0].id);
            }
            const page = activePage();
            delete flashing[activeId];
            table.hidden = false;
            empty.hidden = true;
            renderHeader(root, page.columns);
            renderRows(root, page, newIds[activeId], priorityColors);
            renderPager(root, pages, activeId, flashing);
            updateOverflow(root);
        }

        function rotate() {
            if (pages.length > 1) {
                let index = 0;
                for (let i = 0; i < pages.length; i += 1) {
                    if (String(pages[i].id) === activeId) {
                        index = i;
                    }
                }
                activeId = String(pages[(index + 1) % pages.length].id);
                showActive();
            }
            scheduleRotation();
        }

        function scheduleRotation() {
            clearTimeout(rotationTimer);
            rotationTimer = null;
            if (pages.length > 1) {
                rotationTimer = setTimeout(rotate, rotationMs);
            }
        }

        // The ring represents idle wait time, not "time since the request was sent": it only
        // starts draining once a response actually comes back (see the finally block below),
        // and a setTimeout chain (not setInterval) means the next request only fires once that
        // drain finishes. While a request is in flight the ring just sits still, wherever the
        // previous drain left it — a slow or hung connection is then visible as the ring simply
        // not moving, instead of ticking along as if nothing were wrong.
        async function tick() {
            if (fetching) {
                return;
            }
            fetching = true;
            setWaitingState(root, true);
            try {
                const response = await fetch(url, { credentials: 'same-origin' });
                const payload = await response.json();
                if (!response.ok || payload.error) {
                    throw new Error(payload.error || ('HTTP ' + response.status));
                }
                const serverNow = Date.parse(payload.generated_at);
                if (!Number.isNaN(serverNow)) {
                    clockOffsetMs = serverNow - Date.now();
                }
                const pollSeconds = parseInt(payload.poll_interval_seconds, 10);
                if (!Number.isNaN(pollSeconds) && pollSeconds > 0) {
                    interval = Math.max(5, pollSeconds) * 1000;
                }
                const rotationSeconds = parseInt(payload.rotation_seconds, 10);
                if (!Number.isNaN(rotationSeconds) && rotationSeconds > 0) {
                    rotationMs = Math.max(5, rotationSeconds) * 1000;
                }
                applyAppearance(root, payload.theme, payload.font_size_rem);
                priorityColors = payload.priority_colors || {};
                pages = payload.pages || [];
                // One alert per cycle, not one per new ticket (plan "Decisões de implementação" item 8).
                const hasNew = diffPages(pages);
                showActive();
                // Restart the rotation timer only when its parameters change: restarting it on
                // every poll would postpone the rotation forever whenever polling is faster.
                const key = rotationMs + '|' + pages.length;
                if (key !== rotationKey) {
                    rotationKey = key;
                    scheduleRotation();
                }
                lastSuccess = new Date();
                setConnectionState(root, true, lastSuccess);
                if (hasNew && alertEnabled) {
                    playAlert(root);
                }
            } catch (e) {
                setConnectionState(root, false, lastSuccess || new Date());
            } finally {
                setWaitingState(root, false);
                restartCountdown(root, interval);
                fetching = false;
                setTimeout(tick, interval);
            }
        }

        window.addEventListener('resize', function () { updateOverflow(root); });

        tick();
    }

    document.querySelectorAll('[data-gac-monitor]').forEach(boot);
})();
```

(O arquivo termina em `})();`; o trecho acima já inclui as duas últimas linhas, então apague as antigas `document.querySelectorAll(...)` e `})();` ao substituir.)

- [ ] **Step 3: CSS**

Em `public/css/monitor.css`:

1. Na regra `.gac-monitor-board`, troque `min-height: 100vh;` por:

```css
    height: 100vh;
    display: flex;
    flex-direction: column;
```

2. Acrescente ao final do arquivo:

```css
/* Embedded in the GLPI chrome (authenticated display): leave room for the menu/breadcrumb. */
.gac-monitor-embedded {
    height: calc(100vh - 7rem);
}

.gac-monitor-board [hidden] {
    display: none !important;
}

/* The table area never scrolls (spec M14): a TV has nobody to scroll it. */
.gac-monitor-viewport {
    flex: 1 1 auto;
    min-height: 0;
    overflow: hidden;
    position: relative;
}

.gac-monitor-empty {
    padding: 3rem 1rem;
    text-align: center;
    color: #8fa3bf;
    font-size: 1.3rem;
}

.gac-monitor-pager {
    display: flex;
    flex-wrap: wrap;
    gap: 0.6rem;
    margin-bottom: 0.9rem;
}

.gac-pager-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.6rem;
    padding: 0.35em 0.9em;
    border-radius: 999px;
    background: #141f3d;
    color: #8fa3bf;
    font-size: 1rem;
    font-weight: 600;
}

.gac-pager-active {
    background: #2b5fd9;
    color: #fff;
}

.gac-pager-count {
    padding: 0.05em 0.55em;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.16);
    font-variant-numeric: tabular-nums;
}

.gac-pager-flash {
    animation: gac-pager-blink 1s ease-in-out infinite;
}

@keyframes gac-pager-blink {
    0%, 100% { box-shadow: 0 0 0 0 rgba(46, 204, 113, 0); }
    50%      { box-shadow: 0 0 0 4px rgba(46, 204, 113, 0.9); }
}

.gac-monitor-overflow {
    flex: 0 0 auto;
    margin-top: 0.5rem;
    padding: 0.55em 1em;
    border-radius: 8px;
    background: #141f3d;
    color: #ffd166;
    font-size: 1.15rem;
    font-weight: 700;
    text-align: center;
    letter-spacing: 0.02em;
}

/* Row colour (spec M15/M16): tinted background + strong left border. Priority rows set the two
   variables inline (the colours come from GLPI's own settings); the other tones are fixed here. */
.gac-monitor-board tbody tr.gac-row-toned {
    background: var(--gac-row-tint);
}

.gac-tone-status-new        { --gac-row-accent: #3498db; --gac-row-tint: rgba(52, 152, 219, 0.22); }
.gac-tone-status-processing { --gac-row-accent: #2ecc71; --gac-row-tint: rgba(46, 204, 113, 0.22); }
.gac-tone-status-planned    { --gac-row-accent: #1abc9c; --gac-row-tint: rgba(26, 188, 156, 0.22); }
.gac-tone-status-pending    { --gac-row-accent: #f39c12; --gac-row-tint: rgba(243, 156, 18, 0.22); }
.gac-tone-status-solved     { --gac-row-accent: #9b59b6; --gac-row-tint: rgba(155, 89, 182, 0.22); }
.gac-tone-status-approval   { --gac-row-accent: #e67e22; --gac-row-tint: rgba(230, 126, 34, 0.22); }

.gac-tone-sla-late    { --gac-row-accent: #e74c3c; --gac-row-tint: rgba(231, 76, 60, 0.30); }
.gac-tone-sla-warning { --gac-row-accent: #f39c12; --gac-row-tint: rgba(243, 156, 18, 0.26); }
.gac-tone-sla-ok      { --gac-row-accent: #2ecc71; --gac-row-tint: rgba(46, 204, 113, 0.18); }
.gac-tone-sla-paused  { --gac-row-accent: #5dade2; --gac-row-tint: rgba(93, 173, 226, 0.20); }
.gac-tone-sla-none    { --gac-row-accent: #7f8c8d; --gac-row-tint: rgba(127, 140, 141, 0.18); }

/* Light theme */
.gac-theme-light .gac-pager-pill {
    background: #e3e9f5;
    color: #4a5a78;
}

.gac-theme-light .gac-pager-active {
    background: #2b5fd9;
    color: #fff;
}

.gac-theme-light .gac-monitor-overflow {
    background: #fff3d6;
    color: #8a5a00;
}

.gac-theme-light .gac-monitor-empty {
    color: #4a5a78;
}
```

Antes de dar o passo por concluído, confira que as regras de tema claro já existentes no arquivo não conflitam com as acima: `grep -n "gac-theme-light" public/css/monitor.css`.

- [ ] **Step 4: Remover as chaves provisórias do `ScreenQuery`**

Em `src/Monitor/ScreenQuery.php`, no array devolvido por `run()`, apague as quatro linhas do bloco "Transitional" (o comentário e as chaves `'columns'` e `'rows'`).

- [ ] **Step 5: Cache, lint e verificação visual no navegador**

Run: `/c/xampp/php/php.exe -l src/Monitor/ScreenQuery.php; cd /c/Users/juliano/VSCode/glpi-xampp-dev-plugin && /c/xampp/php/php.exe bin/console cache:clear`
Expected: sem erro de sintaxe; cache limpo.

No navegador (Claude in Chrome, Ctrl+F5 para recarregar JS/CSS), com uma Tela **pública** de 2 páginas (uma de "tickets novos" e uma de "em andamento", cada uma com colunas diferentes) e `rotation_seconds = 10`:

1. **Rodízio:** a tabela alterna a cada ~10s; o pill da página ativa fica azul; as colunas do cabeçalho mudam junto com a página.
2. **Overflow:** na página maior, com a janela reduzida (resize) até a lista não caber, aparece "▼ N tickets abaixo"; ao aumentar a janela até caber, a barra some; uma linha cortada conta como escondida. Confira com Tela de 1 página também (sem pager, com barra).
3. **Alerta por página:** crie um ticket (por SQL) que caia na página que não está na tela; no próximo poll o pill dela pisca e, com `alert_enabled=1`, o som é acionado (confira `playAlert` pelo console, sem URL de som configurada não toca) — a página **não** é trocada à força; ao chegar a vez dela, a linha nova pisca e o pill para de piscar.
4. **Primeira carga não alerta** (recarregue a tela: nenhum pill pisca).
5. **Cores:** troque `row_color_mode` (SQL) entre `none`, `status`, `priority` e `sla`; no próximo poll as linhas mudam de cor sem recarregar a página. `sla`: A em laranja, B em vermelho, C em cinza; um ticket Pendente em azul (`sla-paused`).
6. **Tema claro** (`theme=light`): pills, barra de overflow e tintas legíveis.
7. **Exibição autenticada** (`display.php?id=`): o board cabe na janela sob o menu do GLPI, sem barra de rolagem da página; ajuste o `7rem` de `.gac-monitor-embedded` se sobrar/faltar espaço.
8. **Sem páginas:** apague as páginas de uma Tela; a exibição mostra "Nenhuma página configurada para esta tela." sem erro no console (`read_console_messages`).
9. Sem erros no console durante 2 ciclos de rodízio e 2 de polling.

- [ ] **Step 6: Commit** — invocar `/commit` (sugestão: `feat(monitor): rotate between pages, show the overflow bar and colour rows`).

---

### Task 7: Configuração global do rodízio e da janela de SLA

**Files:**
- Modify: `src/Monitor/MonitorConfigSection.php`

**Interfaces:**
- Consumes: `MonitorSettings::defaultRotationSeconds()`, `slaWarningMinutes()` (Task 1).

- [ ] **Step 1: Campos novos na seção**

Em `src/Monitor/MonitorConfigSection.php`, em `render()`, depois do `$this->row(...)` do intervalo padrão de atualização e antes do da URL do som, acrescente (encadeando em `$body`, como os demais):

```php
        $body .= $this->row(
            __('Tempo padrão de cada página no rodízio (segundos)', 'gac'),
            Html::input('monitor_default_rotation_seconds', [
                'type'  => 'number',
                'min'   => 5,
                'value' => MonitorSettings::defaultRotationSeconds($s),
            ])
        );
        $body .= $this->row(
            __('Janela de alerta do SLA (minutos antes de vencer)', 'gac'),
            Html::input('monitor_sla_warning_minutes', [
                'type'  => 'number',
                'min'   => 1,
                'value' => MonitorSettings::slaWarningMinutes($s),
            ])
        );
```

Confirme a forma exata do código ao redor (a primeira atribuição é `$body = $this->row(...)`, as seguintes `$body .= ...`): ao inserir, mantenha a primeira como `=`.

Em `handlePost()`, depois da linha de `monitor_default_poll_interval_seconds`:

```php
        $raw['monitor_default_rotation_seconds'] = (string) (int) ($post['monitor_default_rotation_seconds'] ?? 20);
        $raw['monitor_sla_warning_minutes'] = (string) (int) ($post['monitor_sla_warning_minutes'] ?? 60);
```

- [ ] **Step 2: Lint e verificação**

Run: `/c/xampp/php/php.exe -l src/Monitor/MonitorConfigSection.php; /c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml`
Expected: sem erro de sintaxe; suíte verde.

No navegador, em Configurar > Plugins > Gac (seção Monitor): os dois campos aparecem com 20 e 60; salve `rotation=1` e `sla=0` → recarregue: voltam como 5 e 1 (normalizados); salve 30 e 90 → persistem. Uma Tela sem `rotation_seconds` próprio passa a rodar a 30s (`rotation_seconds` no JSON de `data.php`).

- [ ] **Step 3: Commit** — invocar `/commit` (sugestão: `feat(monitor): configure the default rotation time and the SLA warning window`).

---

### Task 8: Roteiro manual, `CLAUDE.md` e verificação final

**Files:**
- Modify: `docs/monitor-manual-tests.md`, `CLAUDE.md`, `docs/superpowers/specs/2026-10-01-monitor-design.md` (só a linha de status)

- [ ] **Step 1: Roteiro manual**

Em `docs/monitor-manual-tests.md`, depois da última linha numerada da tabela (cenário 27 e quaisquer posteriores — confira com `grep -n "^| [0-9]" docs/monitor-manual-tests.md | tail -3`), acrescente linhas na mesma tabela, numeradas em sequência, com a coluna Resultado `Pendente` até a execução. Cenários (um por linha, com passos e resultado esperado como nas linhas existentes):

1. **Migração:** Tela com pesquisa e colunas antigas passa a ter uma página 1 equivalente (SQL do `hook.php` conferida).
2. **Aba Páginas:** criar, editar, reordenar (campo posição) e excluir páginas; mensagem de Tela sem páginas.
3. **Limite de 8 páginas:** a 9ª é recusada com mensagem.
4. **Pesquisa privada:** salvar página com Pesquisa Salva privada/de outro tipo é recusado.
5. **Rodízio:** 2 páginas, `rotation_seconds` próprio e padrão global; a página ativa troca no tempo certo, pill ativo destacado.
6. **Mudança de página em tempo de execução:** remover a página ativa de uma Tela aberta; o rodízio segue sem travar.
7. **Alerta por página oculta:** ticket novo em página oculta pisca o pill e toca o alerta, sem forçar a troca; a primeira carga não alerta.
8. **Barra de overflow:** lista maior que a tela mostra "▼ N tickets abaixo" (singular/plural), some quando cabe, vale com 1 página, recalcula no resize.
9. **Cor `priority`, `status`, `sla`, `none`:** cada modo, troca em Tela aberta sem recarregar.
10. **SLA:** vencido, perto de vencer (janela da configuração), no prazo, sem SLA (cinza), Pendente (azul), Solucionado (sem cor); `time_to_own` só em ticket Novo.
11. **Tema claro** do pager, da barra e das tintas.
12. **Exibição pública com 2 páginas:** uma única autenticação da conta de serviço por requisição (log), dados corretos nas duas páginas.
13. **Exibição autenticada** cabe sob o chrome do GLPI.
14. **Configuração global:** rotação padrão e janela de SLA normalizadas e persistidas.
15. **Desempenho:** Tela com 8 páginas sobre as tickets `NB-LOAD-*`: tempo de resposta de `public_data.php` (medir com `curl -w '%{time_total}'`) e registrar o número (risco R-8).

Execute cada cenário e preencha a coluna Resultado (`Passou` com a data, ou o achado). Atualize a data/descrição da "Última execução" no topo.

- [ ] **Step 2: `CLAUDE.md`**

No parágrafo do Monitor em "What exists today", acrescente depois da frase da exibição pública: "A Tela alterna entre 1 a 8 **páginas** (`MonitorPage`, cada uma com Pesquisa Salva e colunas próprias; M11 a M14), mostra uma barra de overflow com a contagem de tickets que não cabem e pinta as linhas por status, prioridade ou prazo de SLA (`RowTone`, M15/M16)." Atualize "decisions M1 to M9" para "M1 to M16". Em "Layout of the Monitor module", inclua `PageRotation` e `RowTone` entre as regras puras e `MonitorPage` entre as classes ligadas ao GLPI; mencione a tabela `glpi_plugin_gac_monitorpages` junto de `glpi_plugin_gac_monitorscreens`.

Na spec, ajuste só a linha de status do adendo (`docs/superpowers/specs/2026-10-01-monitor-design.md`): troque "ainda sem revisão do texto escrito nem implementação" por "implementado e testado conforme `docs/monitor-manual-tests.md`".

- [ ] **Step 3: Verificação final**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml`
Expected: suíte inteira verde.

Run: `for f in $(git diff --name-only dev -- '*.php'; git ls-files --others --exclude-standard '*.php'); do /c/xampp/php/php.exe -l $f; done`
Expected: `No syntax errors detected` em todos.

Run: `git status --short` — só os arquivos deste plano (e os dois manuais não rastreados que já existiam, `docs/ltbp-user-manual.md` e `docs/sso-user-manual.md`, que **não** entram nos commits deste plano).

- [ ] **Step 4: Commit** — invocar `/commit` (sugestão: `chore(docs): document the monitor page rotation tests and layout`).

---

## Auto-revisão (spec × plano)

| Spec | Tarefa |
|---|---|
| M11 tabela filha, 1–8 páginas, migração sem drop | 3 (tabela, migração, `MonitorPage`), 5 (CRUD, limite) |
| M12 rodízio, `rotation_seconds`, padrão global, seletor de pills | 1 (limites/config), 3 (`rotationSeconds()`), 5 (campo), 6 (timer, pager), 7 (config global) |
| M13 polling de todas as páginas, alerta por página, sem forçar a troca | 4 (uma busca por página), 6 (`diffPages`, pill piscando) |
| M14 barra de overflow, sem scroll, vale com 1 página | 6 (`updateOverflow`, CSS) |
| M15 `row_color_mode`, padrão `priority`, tom calculado no servidor | 2 (`RowTone`), 3 (coluna), 4 (`row_tone`), 5 (campo), 6 (CSS/JS) |
| M16 SLA (`time_to_resolve`, `time_to_own` só em Novo, tons, `paused`, `none`) | 2 (regras e testes), 4 (search options 18/155, sonda R-7), 7 (janela) |
| Seção 5.1b / 6.4 / 6.6–6.8 | 3, 4, 6 |
| R-6 (aceito), R-7 (sonda), R-8 (carga), R-9 (autoplay) | 4 Step 1 (R-7), 8 cenário 15 (R-8); R-6 e R-9 são aceitos/informativos, sem tarefa |

Consistência de nomes conferida entre tarefas: `MonitorPage::$items_id` (`plugin_gac_monitorscreens_id`), `MonitorScreen::pages()/rotationSeconds()/rowColorMode()` (Task 3) usados em `ScreenQuery` (Task 4); `RowTone::MODES/isValidMode/DEFAULT_MODE/compute` (Task 2) usados em Tasks 3, 4, 5; `PageRotation::clampRotation/canAddPage/nextPosition/pageTitle` (Task 1) usados em Tasks 3 e 5; chaves do JSON (`pages`, `row_tone`, `rotation_seconds`, `row_color_mode`) idênticas na Task 4 e no JS da Task 6.
