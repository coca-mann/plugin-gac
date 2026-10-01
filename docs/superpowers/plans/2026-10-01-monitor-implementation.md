# Monitor — Painel de Monitoramento de Tickets: Plano de Implementação

> **Para agentes:** SUB-SKILL OBRIGATÓRIA: use `superpowers:subagent-driven-development` (recomendado) ou `superpowers:executing-plans` para executar este plano tarefa a tarefa. Os passos usam a sintaxe de checkbox (`- [ ]`).

**Objetivo:** Construir dentro do plugin `gac` o módulo Monitor: telas de monitoramento de tickets (uma por unidade/finalidade), cada uma com entidade própria, filtro de conteúdo via Pesquisa Salva do GLPI, colunas escolhidas e ordenadas, intervalo de polling, alerta sonoro/visual de ticket novo, exibição autenticada (técnico logado) e exibição pública por token (TV/kiosk sem login).

**Arquitetura:** Módulo isolado em `src/Monitor/` (namespace `GlpiPlugin\Gac\Monitor`), no mesmo estilo do PRE/LTBP. Regras puras (catálogo de colunas, configuração tipada, token público, formatação de tempo decorrido) ficam em classes sem dependência do GLPI, testadas com PHPUnit fora do GLPI. Tudo que fala com o GLPI (`CommonDBTM`, a engine de busca nativa `Search`, Twig, sessão) fica em classes e páginas finas que chamam essas regras. O filtro de conteúdo de cada Tela reaproveita a search engine nativa do GLPI (`Search::getDatas`) sobre uma Pesquisa Salva (`SavedSearch`) compartilhada, em vez de reinventar um motor de filtro; a entidade é um campo próprio da Tela, forçado por código a cada busca, nunca herdado de sessão.

**Tecnologias:** GLPI 11.0.8 (PHP 8.2), `CommonDBTM`, `Glpi\Search\SearchEngine` (via `Search::getDatas`), `SavedSearch`, `Glpi\Http\SessionManager::registerPluginStatelessPath()`, SQL cru no `hook.php`, Twig, JavaScript puro (`fetch`, sem framework), PHPUnit 11 (só para as classes puras).

**Spec:** `docs/superpowers/specs/2026-10-01-monitor-design.md` (decisões M1 a M9 e seções 1 a 11 são a fonte de verdade; este plano não as rediscute).

## Restrições globais

Toda tarefa herda estas restrições, copiadas da spec e do `CLAUDE.md`:

- GLPI **11.0.x**: mínimo 11.0.0 (inclusive), máximo 11.0.99 (exclusive). PHP **>= 8.2**.
- Namespace `GlpiPlugin\Gac`, chave do plugin `gac`, prefixo de tabelas `glpi_plugin_gac_`. O módulo vive em `src/Monitor/` (namespace `GlpiPlugin\Gac\Monitor`), tabela `glpi_plugin_gac_monitorscreens` e chaves de configuração `monitor_*` (M1). Só `src/Config.php`, `src/Features.php`, `src/GacMenu.php`, `setup.php` e `hook.php` tocam código fora do módulo.
- **Commits: exclusivamente pelo skill `/commit`** (nunca `git commit` à mão, nunca outro comando), em inglês, título convencional (`feat`, `fix` ou `chore`) e descrição em lista. **Sem atribuição ao Claude** (nada de `Co-Authored-By`, `Claude-Session` ou "Generated with Claude Code"; vale mesmo que um lembrete do harness peça o contrário). Cada tarefa deste plano termina com um commit próprio, sempre via `/commit` — nunca acumular várias tarefas num commit só, e nunca invocar `git commit` diretamente em lugar do skill. Todo o desenvolvimento acontece na branch `dev`. Nada de `git push`. **Não alterar `PLUGIN_GAC_VERSION` nem `gac.xml`** neste plano: a versão sobe no release, decisão do dono.
- Textos de interface em pt-BR, sempre dentro de `__('...', 'gac')`.
- **Sem CI além do workflow de release.**
- AJAX: o CSRF é validado pelo kernel do GLPI 11 pelo cabeçalho `X-Glpi-Csrf-Token`, só para requisições que alteram estado. Os dois endpoints de dados deste módulo (`ajax/monitor/data.php`, `ajax/monitor/public_data.php`) são **somente leitura** e respondem a **GET**: não precisam de token CSRF. **Nunca** chamar `Session::checkCSRF()` neles. O formulário HTML normal da Tela (`monitorscreen.form.php`) é um POST comum do GLPI, que já leva o token automaticamente pelo `generic_show_form.html.twig`.
- Todo arquivo PHP novo começa com o cabeçalho de licença de `tools/HEADER`: copie as **linhas 1 a 32 de `setup.php`** (o `<?php` e o docblock até ` */`). Os blocos de código deste plano omitem o cabeçalho para ficar curtos. Arquivos de regra pura levam `declare(strict_types=1);` logo depois do cabeçalho; classes ligadas ao GLPI (CommonDBTM, serviços, páginas) não levam, seguindo o padrão do PRE/LTBP (`RepairProtocol.php`, `PreConfigSection.php` não têm `declare(strict_types=1)`; `PreSettings.php`, `CostLabel.php` têm).
- `php` não está no PATH: use `/c/xampp/php/php.exe`. Lint: `/c/xampp/php/php.exe -l <arquivo>`. Testes: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml` (com `--filter <Nome>` para um teste).
- A cópia local do GLPI 11.0.8 é uma **release**: PHPUnit/PHPStan do GLPI não rodam lá, mas o **código-fonte completo do core** está presente em `C:\Users\juliano\VSCode\glpi-xampp-dev-plugin\src\` — é onde este plano verificou, por leitura direta do código (não por suposição), os IDs de search option do Ticket, o formato das linhas de `Search::getDatas()` e as chaves de sessão de restrição por entidade (ver "Decisões de implementação" abaixo). Use esse caminho para qualquer dúvida nova sobre comportamento interno do GLPI em vez de adivinhar. Os testes automatizados deste plano cobrem só as classes puras (`tests/Unit/`); o resto tem **roteiro de teste manual** (Tarefa 10) e verificação manual dentro de cada tarefa.
- **Cache do GLPI.** O GLPI local roda em `production` e **não recompila** Twig editado. Depois de mudar qualquer `.twig`: `cd /c/Users/juliano/VSCode/glpi-xampp-dev-plugin && /c/xampp/php/php.exe bin/console cache:clear`. JS e CSS deste módulo são servidos com `?v=<hash>` (mesma técnica do `pre.js`): force a atualização no navegador (Ctrl+F5) depois de editá-los.
- **Reinstalar o plugin no GLPI de dev** (quando o `hook.php` muda; o GLPI não reexecuta o install se a versão não mudou): `cd /c/Users/juliano/VSCode/glpi-xampp-dev-plugin && /c/xampp/php/php.exe bin/console plugin:deactivate gac && /c/xampp/php/php.exe bin/console plugin:uninstall gac && /c/xampp/php/php.exe bin/console plugin:install gac --username=glpi && /c/xampp/php/php.exe bin/console plugin:activate gac && /c/xampp/php/php.exe bin/console cache:clear`. **Isto apaga as tabelas do plugin em dev** (inclusive as Telas de teste). Depois, uma sessão já aberta precisa de novo login (ou `POST /Session/ChangeProfile`) para enxergar os direitos novos, senão dá 403.
- O plugin já está ligado ao GLPI local por junção: `C:\Users\juliano\VSCode\glpi-xampp-dev-plugin\plugins\gac` → este diretório. GLPI local em `http://glpi11local.test/`, banco em `127.0.0.1:3307`.
- Classe em sub-namespace (`Monitor`): `MonitorScreen` sobrescreve `getTable()`; toda coluna de busca da própria tabela declara `'itemtype' => self::class`; arquivos de front ficam em `front/monitor/`. Sem isso a lista quebra assim que existe o primeiro registro (mesma causa documentada no PRE/LTBP).

## Decisões de implementação que a spec não fixava

O plano toma estas decisões. Nenhuma contradiz a spec; cada uma fecha um ponto que ela deixou aberto, ou registra um fato do GLPI verificado por leitura direta do código-fonte (não suposição). **Se alguma não servir, mude antes de executar.**

1. **Catálogo de colunas com IDs reais de search option do Ticket**, verificados por grep no GLPI local (`CommonITILObject.php`, `CommonDBTM.php`): `1`=Título (`name`), `2`=ID, `3`=Prioridade, `4`=Solicitante, `5`=Técnico, `7`=Categoria, `8`=Grupo técnico, `15`=Abertura (`date`), `80`=Entidade (`completename`, convenção padrão do GLPI em qualquer itemtype com entidade, já usada pelo `RepairProtocol` do PRE). A spec (M4) falava em "urgência/prioridade" como uma coluna só: o catálogo usa **Prioridade** (id 3, o campo que o GLPI já mostra como selo colorido do ticket), não Urgência (id 10) — mais correto para quem está olhando a tela de monitoramento rapidamente.
2. **`Search::getDatas($itemtype, $params, $forcedisplay)` leva `forcedisplay` como terceiro argumento posicional**, não uma chave de `$params` (verificado em `Glpi\Search\SearchEngine::getData()`). Um erro fácil de cometer (e que a spec não precisava saber) seria passar `$params['forcedisplay']`, que a engine ignora silenciosamente.
3. **Uma Pesquisa Salva guarda os critérios como uma query string** no campo `query` de `glpi_savedsearches` (verificado em `SavedSearch::execute()`, que faz `parse_str($this->getField('query'), $query_tab)`). `ScreenQuery` (Tarefa 6) faz o mesmo `parse_str()` para reconstruir o array `criteria` que `Search::getDatas` espera, em vez de reimplementar um parser de critérios.
4. **Restrição de entidade lê `$_SESSION['glpiactiveentities']` / `glpiactiveentities_string` diretamente** — não é um parâmetro de `Search::getDatas` (confirmado: essas chaves aparecem em `CommonDBTM.php`, `DbUtils.php` etc., não em `Search.php`). `Session::changeActiveEntities()` não serve para a exibição pública (stateless): ela exige `$_SESSION['glpiactiveprofile']['entities']` já populado por um login real, que não existe aí. `ScreenQuery` monta essas duas chaves **à mão**, a partir do `entities_id`/`is_recursive` da Tela (usando `getSonsOf('glpi_entities', $id)` quando recursiva — a mesma função livre que `Session::changeActiveEntities()` usa internamente), roda a busca, e **desfaz em `finally`** — tanto no caminho stateless (onde as chaves nem existiam) quanto no autenticado (onde o técnico logado não pode sair da chamada de poll com a entidade ativa da Tela em vez da sua própria).
5. **O formato de linha de `Search::getDatas()` é o legado `ITEM_<itemtype>_<id_da_search_option>`**, confirmado em `Glpi\Search\Provider\SQLProvider::constructData()`: a chave do array de retorno, depois do parse interno do GLPI, é `"{itemtype}_{id}"` (ex.: `Ticket_12` para o status), e o valor fica em `$row['Ticket_12'][0]['name']` (múltiplos valores, caso de campos com `GROUP_CONCAT` como solicitante/técnico, ficam em `[0]`, `[1]`, `[2]`... até `['count']`). `ScreenQuery::cellParts()` lê exatamente essa chave. **Mesmo assim**, a Tarefa 6 inclui um passo de verificação manual contra o GLPI local antes do commit — o próprio comentário do GLPI nesse trecho diz "will be drop in next version", então o formato pode mudar numa versão futura do GLPI 11, e é exatamente o tipo de detalhe que vale conferir rodando, não só lendo.
   **Achado adicional na Tarefa 9** (também só visível rodando, não lendo): para solicitante/técnico (search options 4/5, campos de ator com `forcegroupby`+`use_subquery`), o `['name']` dessa mesma estrutura **não** é o nome do usuário — é o **id** dele. O GLPI só resolve o nome legível no HTML pré-renderizado de `['displayname']` (com avatar, tooltip, `<script>`), inútil para uma tabela em texto puro. `ScreenQuery::columnValue()` resolve cada id com `User::getFriendlyName()` antes de exibir.
6. **Sem biblioteca de drag-and-drop para ordenar colunas** (YAGNI): o formulário da Tela mostra **uma lista de 11 `<select>` em sequência** (uma por posição de exibição), cada um escolhendo uma chave do catálogo ou "nenhuma". Isso cumpre "o admin define a ordem" (decisão do dono na brainstorming) sem JS extra nem dependência nova.
7. **JS e CSS do Monitor não entram nos hooks globais `ADD_JAVASCRIPT`/`ADD_CSS`** (diferente do `pre.js`, que é global e guardado por um seletor DOM): a tela pública (`public.php`) não passa por `Html::header()` — é um documento HTML próprio, sem menu do GLPI —, então um hook global não a alcançaria mesmo se registrado. As duas páginas (autenticada e pública) incluem a mesma `<script>`/`<link>` diretamente no template Twig, de forma simétrica.
8. **Um alerta por ciclo de poll, não um por ticket novo**: se vários tickets aparecem no mesmo poll, o som toca uma vez (evita várias reproduções sobrepostas). O destaque visual (CSS) continua em cada linha nova individualmente.
9. **`display_columns` é uma coluna JSON da própria Tela** (`TEXT`), não uma chave em `glpi_configs`: é por registro, não configuração global.
10. **Direitos padrão, sem bits extras**: `plugin_gac_monitor` usa só os bits padrão do GLPI (`ALLSTANDARDRIGHT` = ler, criar, editar, excluir) mais `RIGHT_CONFIG` (`Features::RIGHT_CONFIG`, para a seção de configuração global). Não há ação intermediária como "Enviar" do PRE — uma Tela é um cadastro simples.
11. **`ajax/monitor/data.php` e `public_data.php` usam `exit` depois do JSON**, como `ajax/pre_send.php` já faz (padrão existente no repositório). O `front/monitor/public.php` usa a forma recomendada pelo guia de upgrade do GLPI 11 para HTML — `throw new \Glpi\Exception\Http\NotFoundHttpException()` — porque é uma página (não um endpoint JSON) e esse é o padrão que o próprio guia de migração do GLPI 11 pede para código novo.

## Mapa de arquivos

Criar:

| Arquivo | Responsabilidade |
|---|---|
| `src/Monitor/ColumnCatalog.php` | Catálogo curado de colunas (puro) |
| `src/Monitor/MonitorSettings.php` | Configuração global tipada (puro) |
| `src/Monitor/PublicToken.php` | Geração/validação de formato do token público (puro) |
| `src/Monitor/ElapsedTimeLabel.php` | Formata "tempo decorrido" (puro) |
| `src/Monitor/MonitorScreen.php` | A Tela (`CommonDBTM`) |
| `src/Monitor/MonitorLabels.php` | Rótulos pt-BR das colunas |
| `src/Monitor/MonitorMenu.php` | Entrada do módulo no menu lateral |
| `src/Monitor/MonitorConfig.php`, `MonitorConfigSection.php` | Armazenamento e tela da configuração global |
| `src/Monitor/ScreenQuery.php` | Roda a busca de uma Tela (`Search::getDatas` + escopo de entidade forçado) |
| `templates/monitor/_board.html.twig` | Painel compartilhado (tabela + status) |
| `templates/monitor/display.html.twig` | Exibição autenticada (dentro do GLPI) |
| `templates/monitor/public_display.html.twig` | Exibição pública (documento HTML próprio) |
| `templates/monitor/monitorscreen.form.html.twig` | Formulário de cadastro da Tela |
| `front/monitor/monitorscreen.php`, `monitorscreen.form.php` | Lista e formulário (CRUD autenticado) |
| `front/monitor/display.php` | Exibição autenticada |
| `front/monitor/public.php` | Exibição pública (stateless) |
| `ajax/monitor/data.php` | Dados para a exibição autenticada |
| `ajax/monitor/public_data.php` | Dados para a exibição pública (stateless) |
| `public/js/monitor.js` | Polling, render da tabela, alerta |
| `public/css/monitor.css` | Tema escuro do painel |
| `tests/Unit/ColumnCatalogTest.php`, `MonitorSettingsTest.php`, `PublicTokenTest.php`, `ElapsedTimeLabelTest.php` | Testes das classes puras |
| `docs/monitor-manual-tests.md` | Roteiro manual |

Modificar:

| Arquivo | Mudança |
|---|---|
| `src/Features.php` | Registrar `MonitorScreen` em `all()` |
| `src/GacMenu.php` | `ITEM_MONITOR` + entrada no menu |
| `src/Config.php` | Registrar `MonitorConfigSection` |
| `setup.php` | Registrar as duas rotas stateless públicas |
| `hook.php` | Tabela, direitos, configuração padrão, display preference, uninstall |
| `CLAUDE.md` | Nova seção "What exists today" (Tarefa 10) |

---

### Task 1: Catálogo de colunas (`ColumnCatalog`, puro)

**Files:**
- Create: `src/Monitor/ColumnCatalog.php`
- Test: `tests/Unit/ColumnCatalogTest.php`

**Interfaces:**
- Consumes: nada.
- Produces (`GlpiPlugin\Gac\Monitor\ColumnCatalog`, todos `static`):
  - `const DEFAULT_COLUMNS` (`list<string>`)
  - `allKeys(): list<string>`
  - `isValidKey(string $key): bool`
  - `isComputed(string $key): bool`
  - `searchOptionId(string $key): ?int`
  - `labelKey(string $key): string`
  - `sanitize(array $keys): list<string>`
  - `searchOptionIdsFor(array $keys): list<int>`

- [ ] **Step 1: Escrever o teste que falha**

Create `tests/Unit/ColumnCatalogTest.php`:

```php
<?php

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
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml --filter ColumnCatalog`
Expected: FAIL/ERROR `Class "GlpiPlugin\Gac\Monitor\ColumnCatalog" not found`.

- [ ] **Step 3: Implementar**

Create `src/Monitor/ColumnCatalog.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Monitor;

/**
 * The curated set of Ticket columns a Tela can show, in the catalog's own order (not the
 * display order a Tela chooses). Pure: no GLPI calls. Search option ids verified against the
 * GLPI 11 core source (CommonITILObject::rawSearchOptions()); see the plan's "Decisões de
 * implementação" item 1. See spec section 5.3 and decision M4.
 */
final class ColumnCatalog
{
    private const CATALOG = [
        'id'           => ['search_option' => 2,  'computed' => false],
        'title'        => ['search_option' => 1,  'computed' => false],
        'entity'       => ['search_option' => 80, 'computed' => false],
        'status'       => ['search_option' => 12, 'computed' => false],
        'priority'     => ['search_option' => 3,  'computed' => false],
        'category'     => ['search_option' => 7,  'computed' => false],
        'requester'    => ['search_option' => 4,  'computed' => false],
        'technician'   => ['search_option' => 5,  'computed' => false],
        'group'        => ['search_option' => 8,  'computed' => false],
        'opening_date' => ['search_option' => 15, 'computed' => false],
        // Computed from the same raw date as 'opening_date', not its own search option value.
        'elapsed'      => ['search_option' => 15, 'computed' => true],
    ];

    /** @var list<string> */
    public const DEFAULT_COLUMNS = [
        'id', 'title', 'entity', 'status', 'priority', 'requester', 'technician', 'opening_date', 'elapsed',
    ];

    /** @return list<string> */
    public static function allKeys(): array
    {
        return array_keys(self::CATALOG);
    }

    public static function isValidKey(string $key): bool
    {
        return array_key_exists($key, self::CATALOG);
    }

    public static function isComputed(string $key): bool
    {
        return self::CATALOG[$key]['computed'] ?? false;
    }

    public static function searchOptionId(string $key): ?int
    {
        return self::CATALOG[$key]['search_option'] ?? null;
    }

    /** Stable key for a GLPI-bound label lookup (MonitorLabels::column()); identity for now. */
    public static function labelKey(string $key): string
    {
        return $key;
    }

    /**
     * Keeps only valid keys, dedupes, preserves the caller's order. Falls back to
     * DEFAULT_COLUMNS when nothing valid survives (a Tela is never left with an empty table).
     *
     * @param array<mixed> $keys
     * @return list<string>
     */
    public static function sanitize(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            if (is_string($key) && self::isValidKey($key) && !in_array($key, $out, true)) {
                $out[] = $key;
            }
        }
        return $out === [] ? self::DEFAULT_COLUMNS : $out;
    }

    /**
     * Search option ids to force-display for a chosen set of columns: always includes "id" (2,
     * needed to key each result row), plus each column's own option, plus the date behind
     * "elapsed" (15) even when "opening_date" itself was not chosen.
     *
     * @param list<string> $keys
     * @return list<int>
     */
    public static function searchOptionIdsFor(array $keys): array
    {
        $ids = [2];
        foreach ($keys as $key) {
            $id = self::searchOptionId($key);
            if ($id !== null) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml --filter ColumnCatalog`
Expected: PASS.

- [ ] **Step 5: Commit**

Invoque o skill `/commit` (tipo `feat`, por exemplo "add Monitor column catalog").

---

### Task 2: Configuração global, token público e tempo decorrido (puros)

**Files:**
- Create: `src/Monitor/MonitorSettings.php`, `src/Monitor/PublicToken.php`, `src/Monitor/ElapsedTimeLabel.php`
- Test: `tests/Unit/MonitorSettingsTest.php`, `tests/Unit/PublicTokenTest.php`, `tests/Unit/ElapsedTimeLabelTest.php`

**Interfaces:**
- Consumes: nada.
- Produces:
  - `GlpiPlugin\Gac\Monitor\MonitorSettings` (todos `static`; `$s` é `array<string, string>`):
    - `defaults(): array<string, string>`
    - `normalize(array $raw): array<string, string>`
    - `defaultPollIntervalSeconds(array $s): int`
    - `alertSoundUrl(array $s): string` (vazio = usa o som padrão embutido)
    - `clampPollInterval(?int $seconds): ?int` (piso de 5s; `null` continua `null`)
  - `GlpiPlugin\Gac\Monitor\PublicToken`:
    - `generate(): string` (48 caracteres hexadecimais)
    - `isWellFormed(string $token): bool`
  - `GlpiPlugin\Gac\Monitor\ElapsedTimeLabel`:
    - `format(string $openedAt, \DateTimeImmutable $now): string` (`$openedAt` no formato `Y-m-d H:i:s`; inválido devolve `''`)

- [ ] **Step 1: Escrever os testes que falham**

Create `tests/Unit/MonitorSettingsTest.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Tests\Unit;

use GlpiPlugin\Gac\Monitor\MonitorSettings;
use PHPUnit\Framework\TestCase;

final class MonitorSettingsTest extends TestCase
{
    public function testDefaults(): void
    {
        $s = MonitorSettings::normalize([]);
        $this->assertSame(15, MonitorSettings::defaultPollIntervalSeconds($s));
        $this->assertSame('', MonitorSettings::alertSoundUrl($s));
    }

    public function testEveryDefaultKeyIsPrefixed(): void
    {
        foreach (array_keys(MonitorSettings::defaults()) as $key) {
            $this->assertStringStartsWith('monitor_', $key);
        }
    }

    public function testNormalizeCoercesTypesAndDropsUnknownKeys(): void
    {
        $s = MonitorSettings::normalize([
            'monitor_default_poll_interval_seconds' => '42',
            'monitor_alert_sound_url'                => ' https://x/y.mp3 ',
            'garbage'                                 => 'x',
        ]);
        $this->assertSame(42, MonitorSettings::defaultPollIntervalSeconds($s));
        $this->assertSame('https://x/y.mp3', MonitorSettings::alertSoundUrl($s));
        $this->assertArrayNotHasKey('garbage', $s);
    }

    public function testDefaultPollIntervalHasAFloorOfFiveSeconds(): void
    {
        $s = MonitorSettings::normalize(['monitor_default_poll_interval_seconds' => '1']);
        $this->assertSame(5, MonitorSettings::defaultPollIntervalSeconds($s));
    }

    public function testClampPollIntervalKeepsNullAsNull(): void
    {
        $this->assertNull(MonitorSettings::clampPollInterval(null));
    }

    public function testClampPollIntervalEnforcesTheFloor(): void
    {
        $this->assertSame(5, MonitorSettings::clampPollInterval(1));
        $this->assertSame(5, MonitorSettings::clampPollInterval(0));
        $this->assertSame(30, MonitorSettings::clampPollInterval(30));
    }
}
```

Create `tests/Unit/PublicTokenTest.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Tests\Unit;

use GlpiPlugin\Gac\Monitor\PublicToken;
use PHPUnit\Framework\TestCase;

final class PublicTokenTest extends TestCase
{
    public function testGenerateProducesAWellFormedToken(): void
    {
        $this->assertTrue(PublicToken::isWellFormed(PublicToken::generate()));
    }

    public function testGenerateIsNotConstant(): void
    {
        $this->assertNotSame(PublicToken::generate(), PublicToken::generate());
    }

    public function testIsWellFormedRejectsGarbage(): void
    {
        $this->assertFalse(PublicToken::isWellFormed(''));
        $this->assertFalse(PublicToken::isWellFormed('abc'));
        $this->assertFalse(PublicToken::isWellFormed(str_repeat('g', 48)));
        $this->assertFalse(PublicToken::isWellFormed(str_repeat('a', 47)));
    }
}
```

Create `tests/Unit/ElapsedTimeLabelTest.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Tests\Unit;

use GlpiPlugin\Gac\Monitor\ElapsedTimeLabel;
use PHPUnit\Framework\TestCase;

final class ElapsedTimeLabelTest extends TestCase
{
    public function testUnderOneMinute(): void
    {
        $now = new \DateTimeImmutable('2026-10-01 10:00:30');
        $this->assertSame('<1min', ElapsedTimeLabel::format('2026-10-01 10:00:00', $now));
    }

    public function testMinutesOnly(): void
    {
        $now = new \DateTimeImmutable('2026-10-01 10:15:00');
        $this->assertSame('15min', ElapsedTimeLabel::format('2026-10-01 10:00:00', $now));
    }

    public function testHoursAndMinutes(): void
    {
        $now = new \DateTimeImmutable('2026-10-01 12:15:00');
        $this->assertSame('2h15min', ElapsedTimeLabel::format('2026-10-01 10:00:00', $now));
    }

    public function testHoursOnly(): void
    {
        $now = new \DateTimeImmutable('2026-10-01 12:00:00');
        $this->assertSame('2h', ElapsedTimeLabel::format('2026-10-01 10:00:00', $now));
    }

    public function testDaysAndHours(): void
    {
        $now = new \DateTimeImmutable('2026-10-03 12:00:00');
        $this->assertSame('2d 2h', ElapsedTimeLabel::format('2026-10-01 10:00:00', $now));
    }

    public function testDaysOnly(): void
    {
        $now = new \DateTimeImmutable('2026-10-03 10:00:00');
        $this->assertSame('2d', ElapsedTimeLabel::format('2026-10-01 10:00:00', $now));
    }

    public function testMalformedDateReturnsEmptyString(): void
    {
        $this->assertSame('', ElapsedTimeLabel::format('not-a-date', new \DateTimeImmutable()));
    }

    public function testFutureDateIsTreatedAsZero(): void
    {
        $now = new \DateTimeImmutable('2026-10-01 10:00:00');
        $this->assertSame('<1min', ElapsedTimeLabel::format('2026-10-01 11:00:00', $now));
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml --filter "MonitorSettings|PublicToken|ElapsedTimeLabel"`
Expected: FAIL/ERROR, as três classes não existem.

- [ ] **Step 3: Implementar**

Create `src/Monitor/MonitorSettings.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Monitor;

/**
 * Typed view over the raw Monitor configuration array stored in glpi_configs (context
 * plugin:gac, keys prefixed monitor_). Pure: no GLPI calls. See spec section 5.2.
 */
final class MonitorSettings
{
    private const MIN_POLL_INTERVAL_SECONDS = 5;

    /** @return array<string, string> */
    public static function defaults(): array
    {
        return [
            'monitor_default_poll_interval_seconds' => '15',
            'monitor_alert_sound_url'                 => '',
        ];
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

        if (array_key_exists('monitor_default_poll_interval_seconds', $raw)) {
            $seconds = is_numeric($raw['monitor_default_poll_interval_seconds'])
                ? (int) $raw['monitor_default_poll_interval_seconds']
                : self::MIN_POLL_INTERVAL_SECONDS;
            $out['monitor_default_poll_interval_seconds'] = (string) max(self::MIN_POLL_INTERVAL_SECONDS, $seconds);
        }

        if (array_key_exists('monitor_alert_sound_url', $raw)) {
            $out['monitor_alert_sound_url'] = trim((string) $raw['monitor_alert_sound_url']);
        }

        return $out;
    }

    /** @param array<string, string> $s */
    public static function defaultPollIntervalSeconds(array $s): int
    {
        return (int) ($s['monitor_default_poll_interval_seconds'] ?? 15);
    }

    /** @param array<string, string> $s */
    public static function alertSoundUrl(array $s): string
    {
        return (string) ($s['monitor_alert_sound_url'] ?? '');
    }

    /** Clamps a Tela's own poll interval override; null (use the global default) stays null. */
    public static function clampPollInterval(?int $seconds): ?int
    {
        if ($seconds === null) {
            return null;
        }
        return max(self::MIN_POLL_INTERVAL_SECONDS, $seconds);
    }
}
```

Create `src/Monitor/PublicToken.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Monitor;

/**
 * The opaque token that authenticates a public (no-login) Tela display. Pure: no GLPI, no
 * database — a Tela's public_token column is just this format. See spec M6 and section 6.3.
 */
final class PublicToken
{
    private const LENGTH_HEX_CHARS = 48;

    public static function generate(): string
    {
        return bin2hex(random_bytes((int) (self::LENGTH_HEX_CHARS / 2)));
    }

    public static function isWellFormed(string $token): bool
    {
        return (bool) preg_match('/^[0-9a-f]{' . self::LENGTH_HEX_CHARS . '}$/', $token);
    }
}
```

Create `src/Monitor/ElapsedTimeLabel.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Monitor;

/** Pure: formats the "elapsed time" column from a raw GLPI datetime and the current instant. */
final class ElapsedTimeLabel
{
    public static function format(string $openedAt, \DateTimeImmutable $now): string
    {
        $opened = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $openedAt);
        if ($opened === false) {
            return '';
        }

        $seconds = $now->getTimestamp() - $opened->getTimestamp();
        if ($seconds < 0) {
            $seconds = 0;
        }

        $days    = intdiv($seconds, 86400);
        $hours   = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return $hours > 0 ? $days . 'd ' . $hours . 'h' : $days . 'd';
        }
        if ($hours > 0) {
            return $minutes > 0 ? $hours . 'h' . $minutes . 'min' : $hours . 'h';
        }
        if ($minutes > 0) {
            return $minutes . 'min';
        }
        return '<1min';
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml --filter "MonitorSettings|PublicToken|ElapsedTimeLabel"`
Expected: PASS.

- [ ] **Step 5: Commit**

Invoque o skill `/commit` (tipo `feat`, por exemplo "add Monitor settings, public token and elapsed time helpers").

---

### Task 3: A Tela (`MonitorScreen`), tabela, direitos e registro do módulo

**Files:**
- Create: `src/Monitor/MonitorScreen.php`
- Modify: `src/Features.php`, `hook.php`

**Interfaces:**
- Consumes: `ColumnCatalog::sanitize()`, `MonitorSettings::clampPollInterval()`, `PublicToken::generate()` (Tasks 1-2).
- Produces (`GlpiPlugin\Gac\Monitor\MonitorScreen extends CommonDBTM`):
  - `public static $rightname = 'plugin_gac_monitor'`
  - `const RIGHT_CONFIG` (= `Features::RIGHT_CONFIG`)
  - `getTable(): string` → `'glpi_plugin_gac_monitorscreens'`
  - `displayColumns(): list<string>`
  - `pollIntervalSeconds(array $settings): int`
  - `regenerateToken(): void`
  - `static sharedTicketSavedSearches(): array<int, string>`
  - Usado pela Tarefa 4 (`showForm()`, Twig) e pelas Tarefas 7-8 (`ScreenQuery` consome `$screen->fields`, `displayColumns()`, `pollIntervalSeconds()`).

- [ ] **Step 1: Criar a tabela no `hook.php`**

Em `hook.php`, dentro de `plugin_gac_install()`, logo depois do bloco da tabela `glpi_plugin_gac_ltbpsequences` (antes de "Default configuration"), adicione:

```php
    $monitorScreens = 'glpi_plugin_gac_monitorscreens';
    if (!$DB->tableExists($monitorScreens)) {
        $DB->doQuery("CREATE TABLE `$monitorScreens` (
            `id` INT {$sign} NOT NULL AUTO_INCREMENT,
            `entities_id` INT {$sign} NOT NULL DEFAULT '0',
            `is_recursive` TINYINT NOT NULL DEFAULT '0',
            `name` VARCHAR(255) NOT NULL DEFAULT '',
            `savedsearches_id` INT {$sign} NOT NULL DEFAULT '0',
            `display_columns` TEXT DEFAULT NULL,
            `poll_interval_seconds` INT UNSIGNED DEFAULT NULL,
            `is_public` TINYINT NOT NULL DEFAULT '0',
            `public_token` VARCHAR(64) DEFAULT NULL,
            `alert_enabled` TINYINT NOT NULL DEFAULT '1',
            `is_active` TINYINT NOT NULL DEFAULT '1',
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `public_token` (`public_token`),
            KEY `entities_id` (`entities_id`),
            KEY `savedsearches_id` (`savedsearches_id`),
            KEY `is_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    }
```

Em seguida, a linha existente:

```php
    $missing = array_diff_key(PreSettings::defaults() + LtbpSettings::defaults(), $current);
```

vira:

```php
    $missing = array_diff_key(
        PreSettings::defaults() + LtbpSettings::defaults() + MonitorSettings::defaults(),
        $current
    );
```

e adicione o `use` correspondente no topo do arquivo:

```php
use GlpiPlugin\Gac\Monitor\MonitorScreen;
use GlpiPlugin\Gac\Monitor\MonitorSettings;
```

Depois do bloco de direitos do LTBP (antes de "Settings used to be gated..."), adicione o bloco de direitos do Monitor:

```php
    // Monitor right: profiles that can UPDATE the native 'config' right get full access
    // (standard CRUD + Configurar); every other profile starts without access.
    $monitorRight = MonitorScreen::$rightname;
    if (countElementsInTable(ProfileRight::getTable(), ['name' => $monitorRight]) === 0) {
        ProfileRight::addProfileRights([$monitorRight]);

        $monitor_admin_profiles = array_column(
            iterator_to_array($DB->request([
                'SELECT' => 'profiles_id',
                'FROM'   => ProfileRight::getTable(),
                'WHERE'  => ['name' => 'config', 'rights' => ['&', UPDATE]],
            ])),
            'profiles_id'
        );
        if ($monitor_admin_profiles !== []) {
            $DB->update(
                ProfileRight::getTable(),
                ['rights' => ALLSTANDARDRIGHT | MonitorScreen::RIGHT_CONFIG],
                ['name' => $monitorRight, 'profiles_id' => $monitor_admin_profiles]
            );
        }
    }
```

Depois do bloco de `glpi_displaypreferences` do LTBP, adicione:

```php
    if (countElementsInTable('glpi_displaypreferences', ['itemtype' => MonitorScreen::class]) === 0) {
        $DB->insert('glpi_displaypreferences', [
            'itemtype' => MonitorScreen::class,
            'num'      => 80, // search option 80 = entidade (ver MonitorScreen::rawSearchOptions())
            'rank'     => 1,
            'users_id' => 0,
        ]);
    }
```

Em `plugin_gac_uninstall()`, adicione `'glpi_plugin_gac_monitorscreens'` à lista de tabelas apagadas, e depois do bloco de `Ltbp`:

```php
    $DB->delete(ProfileRight::getTable(), ['name' => MonitorScreen::$rightname]);
    $DB->delete('glpi_displaypreferences', ['itemtype' => MonitorScreen::class]);
```

e a chamada `Config::deleteConfigurationValues` vira:

```php
    Config::deleteConfigurationValues('plugin:gac', array_merge(
        array_keys(PreSettings::defaults()),
        array_keys(LtbpSettings::defaults()),
        array_keys(MonitorSettings::defaults()),
        ['pre_config_right_migrated']
    ));
```

- [ ] **Step 2: Lint do `hook.php`**

Run: `/c/xampp/php/php.exe -l hook.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Registrar em `Features::all()`**

Em `src/Features.php`, adicione o `use` e a entrada:

```php
use GlpiPlugin\Gac\Monitor\MonitorScreen;
```

```php
            [
                'itemtype' => MonitorScreen::class,
                'label'    => MonitorScreen::getTypeName(2),
                'field'    => MonitorScreen::$rightname,
            ],
```

(dentro do array de `all()`, depois da entrada do `Ltbp`).

- [ ] **Step 4: Implementar `MonitorScreen`**

Create `src/Monitor/MonitorScreen.php`:

```php
<?php

namespace GlpiPlugin\Gac\Monitor;

use CommonDBTM;
use Dropdown;
use Entity;
use GlpiPlugin\Gac\Features;
use Glpi\Application\View\TemplateRenderer;
use SavedSearch;
use Session;

class MonitorScreen extends CommonDBTM
{
    public static $rightname = 'plugin_gac_monitor';

    public const RIGHT_CONFIG = Features::RIGHT_CONFIG;

    public $dohistory = true;

    /** Explicit: the class sits in a sub-namespace, GLPI's derived name would be wrong. */
    public static function getTable($classname = null)
    {
        if ($classname !== null && $classname !== static::class) {
            return parent::getTable($classname);
        }
        return 'glpi_plugin_gac_monitorscreens';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Tela de Monitoramento', 'Telas de Monitoramento', $nb, 'gac');
    }

    public static function getIcon()
    {
        return 'ti ti-device-tv';
    }

    public function getRights($interface = 'central')
    {
        $values = parent::getRights($interface);
        $values[self::RIGHT_CONFIG] = __('Configurar', 'gac');
        return $values;
    }

    public function prepareInputForAdd($input)
    {
        $input = $this->prepareCommonInput($input);
        if ($input === false) {
            return false;
        }
        if (!isset($input['entities_id'])) {
            $input['entities_id'] = (int) Session::getActiveEntity();
        }
        return $input;
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

        foreach (['is_recursive', 'is_public', 'alert_enabled', 'is_active'] as $flag) {
            if (array_key_exists($flag, $input)) {
                $input[$flag] = ((string) $input[$flag]) === '1' ? 1 : 0;
            }
        }

        if (array_key_exists('poll_interval_seconds', $input)) {
            $seconds = ($input['poll_interval_seconds'] !== '' && is_numeric($input['poll_interval_seconds']))
                ? (int) $input['poll_interval_seconds']
                : null;
            $input['poll_interval_seconds'] = MonitorSettings::clampPollInterval($seconds);
        }

        // The public token is never set from the form directly: it is generated the first time
        // "is_public" turns on. update() loads $this->fields from the DB before calling this
        // method, so on an update $this->fields still holds the row's previous state.
        $wantsPublic = (int) ($input['is_public'] ?? $this->fields['is_public'] ?? 0) === 1;
        $hasToken    = !empty($this->fields['public_token'] ?? null) || !empty($input['public_token'] ?? null);
        if ($wantsPublic && !$hasToken) {
            $input['public_token'] = PublicToken::generate();
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

    /** Explicit, separate action: invalidates the current public URL. Never implicit on save. */
    public function regenerateToken(): void
    {
        global $DB;
        $DB->update(self::getTable(), ['public_token' => PublicToken::generate()], ['id' => $this->getID()]);
        $this->getFromDB($this->getID());
    }

    /** @return list<string> */
    public function displayColumns(): array
    {
        $decoded = json_decode((string) ($this->fields['display_columns'] ?? '[]'), true);
        return ColumnCatalog::sanitize(is_array($decoded) ? $decoded : []);
    }

    /** @param array<string, string> $settings MonitorSettings-normalized global settings */
    public function pollIntervalSeconds(array $settings): int
    {
        $own = (int) ($this->fields['poll_interval_seconds'] ?? 0);
        return $own > 0 ? $own : MonitorSettings::defaultPollIntervalSeconds($settings);
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

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs);
        $this->addStandardTab('Log', $tabs, $options);
        return $tabs;
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        global $CFG_GLPI;

        $columnChoices = ['' => Dropdown::EMPTY_VALUE];
        foreach (ColumnCatalog::allKeys() as $key) {
            $columnChoices[$key] = MonitorLabels::column($key);
        }

        TemplateRenderer::getInstance()->display('@gac/monitor/monitorscreen.form.html.twig', [
            'item'           => $this,
            'params'         => $options,
            'columns'        => ColumnCatalog::allKeys(),
            'chosen'         => $this->isNewItem() ? ColumnCatalog::DEFAULT_COLUMNS : $this->displayColumns(),
            'column_choices' => $columnChoices,
            'saved_searches' => ['' => Dropdown::EMPTY_VALUE] + self::sharedTicketSavedSearches(),
            'public_url'     => empty($this->fields['public_token'] ?? null)
                ? ''
                : $CFG_GLPI['root_doc'] . '/plugins/gac/front/monitor/public.php?token=' . $this->fields['public_token'],
        ]);

        return true;
    }

    public function rawSearchOptions()
    {
        $t = self::getTable();
        $options = [
            ['id' => 'common', 'name' => self::getTypeName(2)],
            [
                'id' => 1, 'table' => $t, 'field' => 'name', 'name' => __('Nome', 'gac'),
                'datatype' => 'itemlink', 'itemtype' => self::class, 'massiveaction' => false, 'autocomplete' => true,
            ],
            ['id' => 2, 'table' => $t, 'field' => 'id', 'name' => __('ID'), 'datatype' => 'number', 'massiveaction' => false],
            [
                'id' => 3, 'table' => SavedSearch::getTable(), 'field' => 'name', 'linkfield' => 'savedsearches_id',
                'name' => SavedSearch::getTypeName(1), 'datatype' => 'dropdown', 'massiveaction' => false,
            ],
            ['id' => 4, 'table' => $t, 'field' => 'is_public', 'name' => __('Pública', 'gac'), 'datatype' => 'bool', 'massiveaction' => false],
            ['id' => 5, 'table' => $t, 'field' => 'is_active', 'name' => __('Ativa', 'gac'), 'datatype' => 'bool', 'massiveaction' => false],
            [
                'id' => 6, 'table' => $t, 'field' => 'poll_interval_seconds', 'name' => __('Intervalo (s)', 'gac'),
                'datatype' => 'number', 'massiveaction' => false,
            ],
            [
                'id' => 80, 'table' => 'glpi_entities', 'field' => 'completename',
                'name' => Entity::getTypeName(1), 'datatype' => 'dropdown', 'massiveaction' => false,
            ],
        ];

        foreach ($options as &$option) {
            if (($option['table'] ?? null) === $t && !isset($option['itemtype'])) {
                $option['itemtype'] = self::class;
            }
        }
        unset($option);

        return $options;
    }
}
```

- [ ] **Step 5: Lint**

Run: `/c/xampp/php/php.exe -l src/Monitor/MonitorScreen.php && /c/xampp/php/php.exe -l src/Features.php`
Expected: `No syntax errors detected` nos dois.

- [ ] **Step 6: Reinstalar no GLPI de dev e conferir a tabela**

Run: `cd /c/Users/juliano/VSCode/glpi-xampp-dev-plugin && /c/xampp/php/php.exe bin/console plugin:deactivate gac && /c/xampp/php/php.exe bin/console plugin:uninstall gac && /c/xampp/php/php.exe bin/console plugin:install gac --username=glpi && /c/xampp/php/php.exe bin/console plugin:activate gac && /c/xampp/php/php.exe bin/console cache:clear`

Depois, confira manualmente (ex.: `mysql` client ou phpMyAdmin no `127.0.0.1:3307`) que `glpi_plugin_gac_monitorscreens` existe e que `glpi_plugin_gac_profilerights` tem uma linha `plugin_gac_monitor`. Faça login de novo no GLPI (ou `POST /Session/ChangeProfile`) para o perfil pegar o direito novo.

- [ ] **Step 7: Commit**

Invoque o skill `/commit` (tipo `feat`, por exemplo "add MonitorScreen data object, table and rights").

---

### Task 4: Rótulos, formulário, páginas de CRUD e menu

**Files:**
- Create: `src/Monitor/MonitorLabels.php`, `src/Monitor/MonitorMenu.php`, `templates/monitor/monitorscreen.form.html.twig`, `front/monitor/monitorscreen.php`, `front/monitor/monitorscreen.form.php`
- Modify: `src/GacMenu.php`

**Interfaces:**
- Consumes: `MonitorScreen` (Task 3), `ColumnCatalog::allKeys()` (Task 1).
- Produces:
  - `GlpiPlugin\Gac\Monitor\MonitorLabels::column(string $key): string`
  - `GlpiPlugin\Gac\Monitor\MonitorMenu::getMenuContent(): array`
  - `GacMenu::ITEM_MONITOR` (string constante)

- [ ] **Step 1: `MonitorLabels`**

Create `src/Monitor/MonitorLabels.php`:

```php
<?php

namespace GlpiPlugin\Gac\Monitor;

/** pt-BR labels for ColumnCatalog keys. GLPI-bound (uses __()), kept out of the pure catalog. */
final class MonitorLabels
{
    public static function column(string $key): string
    {
        return match ($key) {
            'id'           => __('ID', 'gac'),
            'title'        => __('Título', 'gac'),
            'entity'       => __('Entidade', 'gac'),
            'status'       => __('Status', 'gac'),
            'priority'     => __('Prioridade', 'gac'),
            'category'     => __('Categoria', 'gac'),
            'requester'    => __('Solicitante', 'gac'),
            'technician'   => __('Técnico', 'gac'),
            'group'        => __('Grupo técnico', 'gac'),
            'opening_date' => __('Abertura', 'gac'),
            'elapsed'      => __('Tempo decorrido', 'gac'),
            default        => $key,
        };
    }
}
```

- [ ] **Step 2: `MonitorMenu`**

Create `src/Monitor/MonitorMenu.php`:

```php
<?php

namespace GlpiPlugin\Gac\Monitor;

class MonitorMenu
{
    public static function getMenuName($nb = 0): string
    {
        return MonitorScreen::getTypeName(2);
    }

    public static function getMenuContent(): array
    {
        if (!MonitorScreen::canView()) {
            return [];
        }

        $links = ['search' => MonitorScreen::getSearchURL(false)];
        if (MonitorScreen::canCreate()) {
            $links['add'] = MonitorScreen::getFormURL(false);
        }

        return [
            'title' => MonitorScreen::getTypeName(2),
            'page'  => MonitorScreen::getSearchURL(false),
            'icon'  => MonitorScreen::getIcon(),
            'links' => $links,
        ];
    }
}
```

- [ ] **Step 3: Ligar no `GacMenu`**

Em `src/GacMenu.php`, adicione o `use`:

```php
use GlpiPlugin\Gac\Monitor\MonitorMenu;
```

A constante `ITEM_CONFIG` vira, logo acima dela, uma nova constante:

```php
    public const ITEM_MONITOR = 'monitor';
```

E em `getMenuContent()`, depois do bloco do `$ltbp` e antes do `$config`:

```php
        $monitor = MonitorMenu::getMenuContent();
        if ($monitor !== []) {
            $entries[self::ITEM_MONITOR] = $monitor;
        }
```

- [ ] **Step 4: Formulário**

Create `templates/monitor/monitorscreen.form.html.twig`:

```twig
{% extends 'generic_show_form.html.twig' %}
{% import 'components/form/fields_macros.html.twig' as fields %}

{% block form_fields %}
    {{ fields.textField('name', item.fields['name'], __('Nome', 'gac'), {required: true}) }}

    {{ fields.dropdownField('Entity', 'entities_id', item.fields['entities_id'], __('Entidade', 'gac'), {
        required: true,
    }) }}
    {{ fields.sliderField('is_recursive', item.fields['is_recursive'], __('Incluir sub-entidades', 'gac')) }}

    {{ fields.dropdownArrayField('savedsearches_id', item.fields['savedsearches_id'], saved_searches, __('Pesquisa salva (Ticket, compartilhada)', 'gac'), {
        required: true,
    }) }}

    {{ fields.numberField('poll_interval_seconds', item.fields['poll_interval_seconds'], __('Intervalo de atualização (segundos)', 'gac'), {
        min: 5,
        placeholder: __('Em branco usa o padrão da configuração global', 'gac'),
    }) }}

    {{ fields.sliderField('alert_enabled', item.fields['alert_enabled'], __('Alerta sonoro/visual de ticket novo', 'gac')) }}
    {{ fields.sliderField('is_active', item.fields['is_active'], __('Ativa', 'gac')) }}
    {{ fields.sliderField('is_public', item.fields['is_public'], __('Exibição pública (sem login, para TV)', 'gac')) }}

    {% if item.fields['is_public'] and item.fields['public_token'] %}
        {{ fields.readOnlyField('public_url_display', public_url, __('URL pública', 'gac')) }}
        <div class="d-none" data-gac-regenerate-holder>
            <button type="submit" name="regenerate_token" value="1" class="btn btn-outline-warning me-2"
                    formnovalidate onclick="return confirm('{{ __('Gerar um novo link público? O link atual deixa de funcionar.', 'gac')|e('js') }}');">
                <i class="ti ti-refresh"></i>
                <span>{{ __('Gerar novo link', 'gac') }}</span>
            </button>
        </div>
        <script>
            (function () {
                const place = function () {
                    const holder = document.querySelector('[data-gac-regenerate-holder]');
                    const save = holder && holder.closest('form').querySelector('button[name="update"]');
                    if (!holder || !save) { return; }
                    save.after(holder.firstElementChild);
                    holder.remove();
                };
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', place);
                } else {
                    place();
                }
            })();
        </script>
    {% endif %}

    <hr>
    <h4>{{ __('Colunas exibidas, na ordem', 'gac') }}</h4>
    {% for i in 0..(columns|length - 1) %}
        {{ fields.dropdownArrayField('display_columns[]', chosen[i]|default(''), column_choices, (__('Posição', 'gac') ~ ' ' ~ (i + 1))) }}
    {% endfor %}
{% endblock %}
```

- [ ] **Step 5: Páginas de front**

Create `front/monitor/monitorscreen.php`:

```php
<?php

use GlpiPlugin\Gac\GacMenu;
use GlpiPlugin\Gac\Monitor\MonitorScreen;

if (!MonitorScreen::canView()) {
    Html::displayRightError();
}

Html::header(
    MonitorScreen::getTypeName(2),
    $_SERVER['PHP_SELF'],
    GacMenu::SECTOR,
    GacMenu::ITEM_MONITOR
);

Search::show(MonitorScreen::class);

Html::footer();
```

Create `front/monitor/monitorscreen.form.php`:

```php
<?php

use GlpiPlugin\Gac\GacMenu;
use GlpiPlugin\Gac\Monitor\MonitorScreen;

$item = new MonitorScreen();

if (isset($_POST['add'])) {
    $item->check(-1, CREATE, $_POST);
    $newid = $item->add($_POST);
    if ($newid) {
        Html::redirect(MonitorScreen::getFormURLWithID($newid));
    }
    Html::back();
} elseif (isset($_POST['regenerate_token'])) {
    $item->check($_POST['id'], UPDATE);
    $item->regenerateToken();
    Session::addMessageAfterRedirect(__('Novo link público gerado.', 'gac'));
    Html::back();
} elseif (isset($_POST['update'])) {
    $item->check($_POST['id'], UPDATE);
    $item->update($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    $item->check($_POST['id'], PURGE);
    $item->delete($_POST, 1);
    $item->redirectToList();
} else {
    Html::header(
        MonitorScreen::getTypeName(1),
        $_SERVER['PHP_SELF'],
        GacMenu::SECTOR,
        GacMenu::ITEM_MONITOR
    );
    $item->display(['id' => $_GET['id'] ?? -1]);
    Html::footer();
}
```

- [ ] **Step 6: Lint**

Run: `/c/xampp/php/php.exe -l src/Monitor/MonitorLabels.php && /c/xampp/php/php.exe -l src/Monitor/MonitorMenu.php && /c/xampp/php/php.exe -l src/GacMenu.php && /c/xampp/php/php.exe -l front/monitor/monitorscreen.php && /c/xampp/php/php.exe -l front/monitor/monitorscreen.form.php`
Expected: `No syntax errors detected` em todos.

- [ ] **Step 7: Limpar cache e verificar manualmente**

Run: `cd /c/Users/juliano/VSCode/glpi-xampp-dev-plugin && /c/xampp/php/php.exe bin/console cache:clear`

No navegador: entre no GLPI, confira que "Telas de Monitoramento" aparece no menu `gac`, abra "Adicionar", confira que o formulário renderiza (mesmo sem nenhuma Pesquisa Salva cadastrada ainda, o dropdown deve aparecer vazio, não quebrar). Crie uma Pesquisa Salva de Ticket compartilhada pela busca padrão do GLPI (Chamados > pesquisar > "salvar pesquisa", desmarcar "privada") antes de tentar salvar uma Tela, senão a validação de `isSharedTicketSavedSearch()` recusa o formulário (comportamento esperado).

- [ ] **Step 8: Commit**

Invoque o skill `/commit` (tipo `feat`, por exemplo "add Monitor screen CRUD form, pages and menu entry").

---

### Task 5: Configuração global do módulo

**Files:**
- Create: `src/Monitor/MonitorConfig.php`, `src/Monitor/MonitorConfigSection.php`
- Modify: `src/Config.php`

**Interfaces:**
- Consumes: `MonitorSettings` (Task 2), `ConfigSection` interface (`src/ConfigSection.php`, já existe).
- Produces: `GlpiPlugin\Gac\Monitor\MonitorConfig::load(): array`, `::save(array $raw): void`.

- [ ] **Step 1: `MonitorConfig`**

Create `src/Monitor/MonitorConfig.php`:

```php
<?php

namespace GlpiPlugin\Gac\Monitor;

/** Storage of the Monitor settings: glpi_configs, context plugin:gac, keys prefixed monitor_. */
final class MonitorConfig
{
    public const CONTEXT = 'plugin:gac';

    /** @return array<string, string> normalized settings (see MonitorSettings) */
    public static function load(): array
    {
        return MonitorSettings::normalize(\Config::getConfigurationValues(self::CONTEXT));
    }

    /** @param array<string, mixed> $raw */
    public static function save(array $raw): void
    {
        \Config::setConfigurationValues(self::CONTEXT, MonitorSettings::normalize($raw));
    }
}
```

- [ ] **Step 2: `MonitorConfigSection`**

Create `src/Monitor/MonitorConfigSection.php`:

```php
<?php

namespace GlpiPlugin\Gac\Monitor;

use GlpiPlugin\Gac\ConfigSection;
use GlpiPlugin\Gac\Features;
use Html;
use Session;

final class MonitorConfigSection implements ConfigSection
{
    public function key(): string
    {
        return 'monitor';
    }

    public function canConfigure(): bool
    {
        return Features::canConfigure(MonitorScreen::$rightname);
    }

    public function title(): string
    {
        return __('Painel de Monitoramento de Tickets', 'gac');
    }

    public function render(): string
    {
        $s = MonitorConfig::load();

        $body = $this->row(
            __('Intervalo padrão de atualização (segundos)', 'gac'),
            Html::input('monitor_default_poll_interval_seconds', [
                'type'  => 'number',
                'min'   => 5,
                'value' => MonitorSettings::defaultPollIntervalSeconds($s),
            ])
        );
        $body .= $this->row(
            __('URL do som de alerta (opcional; em branco usa o som padrão do plugin)', 'gac'),
            Html::input('monitor_alert_sound_url', [
                'type'  => 'url',
                'value' => MonitorSettings::alertSoundUrl($s),
            ])
        );

        return "<div class='card border mb-4'><div class='card-header bg-body-tertiary'>"
            . "<h4 class='card-title mb-1'><i class='ti ti-device-tv me-2'></i>" . htmlescape($this->title()) . '</h4>'
            . "</div><div class='card-body'>" . $body . '</div></div>';
    }

    private function row(string $label, string $control): string
    {
        return "<div class='row mb-3'><label class='col-sm-4 col-form-label'>" . htmlescape($label)
            . "</label><div class='col-sm-8'>" . $control . '</div></div>';
    }

    public function handlePost(array $post): void
    {
        if (!$this->canConfigure()) {
            return;
        }
        $raw = MonitorConfig::load();
        $raw['monitor_default_poll_interval_seconds'] = (string) (int) ($post['monitor_default_poll_interval_seconds'] ?? 15);
        $raw['monitor_alert_sound_url'] = trim((string) ($post['monitor_alert_sound_url'] ?? ''));
        MonitorConfig::save($raw);
        Session::addMessageAfterRedirect(__('Configuração do Monitor salva.', 'gac'));
    }
}
```

- [ ] **Step 3: Registrar em `Config::sections()`**

Em `src/Config.php`, adicione o `use`:

```php
use GlpiPlugin\Gac\Monitor\MonitorConfigSection;
```

E em `sections()`:

```php
        return [
            new PreConfigSection(),
            new LtbpConfigSection(),
            new MonitorConfigSection(),
        ];
```

- [ ] **Step 4: Lint**

Run: `/c/xampp/php/php.exe -l src/Monitor/MonitorConfig.php && /c/xampp/php/php.exe -l src/Monitor/MonitorConfigSection.php && /c/xampp/php/php.exe -l src/Config.php`
Expected: `No syntax errors detected` em todos.

- [ ] **Step 5: Verificar manualmente**

No navegador, abra a página de configuração do plugin (ícone de engrenagem em Configurar > Plugins > gac) e confira que a seção "Painel de Monitoramento de Tickets" aparece, salva e mantém o valor depois de recarregar.

- [ ] **Step 6: Commit**

Invoque o skill `/commit` (tipo `feat`, por exemplo "add Monitor global configuration section").

---

### Task 6: `ScreenQuery` — roda a busca de uma Tela

**Files:**
- Create: `src/Monitor/ScreenQuery.php`, `tools/probe-monitor-search.php` (descartável, só para o Step 1)

**Interfaces:**
- Consumes: `MonitorScreen::displayColumns()` (Task 3), `ColumnCatalog::searchOptionIdsFor()`/`searchOptionId()` (Task 1), `ElapsedTimeLabel::format()`, `MonitorLabels::column()`.
- Produces: `GlpiPlugin\Gac\Monitor\ScreenQuery::run(MonitorScreen $screen): array{columns: list<array{key: string, label: string}>, rows: list<array<string, string>>}`. Usado pelas Tarefas 7 e 8.

Esta tarefa depende do formato real de `Search::getDatas()`, documentado no item 5 de "Decisões de implementação" a partir da leitura do código-fonte do GLPI — mas **confira antes de commitar**: é exatamente o tipo de comportamento interno que vale ver rodando, não só ler.

- [ ] **Step 1: Provar o formato real antes de escrever o parser definitivo**

Crie uma Pesquisa Salva de Ticket qualquer no GLPI (compartilhada), anote o `id` dela (visível na URL ao editá-la, ou na tabela `glpi_savedsearches`). Crie um script descartável:

Create `tools/probe-monitor-search.php`:

```php
<?php

// Script descartável: prova o formato real de Search::getDatas() antes de escrever o parser
// definitivo em ScreenQuery. Rode de dentro do GLPI (precisa do bootstrap completo), ex.:
//   cd /c/Users/juliano/VSCode/glpi-xampp-dev-plugin
//   /c/xampp/php/php.exe -r "chdir('.'); \$_SERVER['PHP_SELF']='/index.php'; require 'public/index.php';"
// é mais simples copiar este arquivo para glpi-xampp-dev-plugin/tools/probe.php e acessá-lo
// autenticado via navegador, ou colar o corpo abaixo num front/ temporário do plugin.
// Troque SAVEDSEARCH_ID pelo id real antes de rodar.

$savedSearchId = SAVEDSEARCH_ID;

$saved = new SavedSearch();
$saved->getFromDB($savedSearchId);
parse_str((string) $saved->fields['query'], $params);
$params['reset']      = 'reset';
$params['is_deleted'] = 0;
$params['start']      = 0;
$params['list_limit'] = 5;
$params['criteria']   = $params['criteria'] ?? [];

$data = Search::getDatas('Ticket', $params, [2, 1, 12]); // id, título, status

echo '<pre>';
var_dump(array_slice($data['data']['rows'] ?? [], 0, 2));
echo '</pre>';
```

Rode-o autenticado (a forma mais simples: cole o corpo acima temporariamente no fim de `front/monitor/monitorscreen.php`, acesse a página logado, veja o `var_dump`, depois remova). Confira:
- a chave de cada linha para o campo "status" é literalmente `Ticket_12` (ou outra, se o GLPI 11.0.8 local divergir do que o código-fonte sugere);
- o valor está em `[0]['name']`;
- campos com múltiplos valores (ex.: um ticket com dois solicitantes) têm `['count'] > 1` e entradas `[0]`, `[1]`, ...

Se o formato observado **divergir** do item 5 de "Decisões de implementação", ajuste `cellValue()` no Step 3 abaixo para o que foi observado, e registre a divergência num comentário no código (não precisa atualizar a spec — é detalhe de implementação, não decisão de negócio). Depois, apague `tools/probe-monitor-search.php` (é descartável, não faz parte do plugin).

- [ ] **Step 2: Lint do probe (antes de rodar)**

Run: `/c/xampp/php/php.exe -l tools/probe-monitor-search.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Implementar `ScreenQuery`**

Create `src/Monitor/ScreenQuery.php`:

```php
<?php

namespace GlpiPlugin\Gac\Monitor;

use SavedSearch;
use Search;

/**
 * Runs a MonitorScreen's query: the SavedSearch's own criteria, entity scope forced from the
 * Tela (never from a session), and the Tela's chosen columns. See spec section 6.4 and the
 * plan's "Decisões de implementação" items 2-5.
 */
final class ScreenQuery
{
    private const LIST_LIMIT = 200;

    /** @return array{columns: list<array{key: string, label: string}>, rows: list<array<string, string>>} */
    public static function run(MonitorScreen $screen): array
    {
        $columns = $screen->displayColumns();

        $saved    = new SavedSearch();
        $hasSaved = (int) $screen->fields['savedsearches_id'] > 0
            && $saved->getFromDB((int) $screen->fields['savedsearches_id']);

        $params = [];
        if ($hasSaved) {
            parse_str((string) $saved->fields['query'], $params);
        }
        $params['reset']      = 'reset';
        $params['is_deleted'] = 0;
        $params['start']      = 0;
        $params['list_limit'] = self::LIST_LIMIT;
        $params['criteria']   = $params['criteria'] ?? [];

        $forcedisplay = ColumnCatalog::searchOptionIdsFor($columns);

        $previousEntities       = $_SESSION['glpiactiveentities'] ?? null;
        $previousEntitiesString = $_SESSION['glpiactiveentities_string'] ?? null;
        self::forceEntityScope((int) $screen->fields['entities_id'], (bool) $screen->fields['is_recursive']);

        try {
            $data = Search::getDatas('Ticket', $params, $forcedisplay);
        } finally {
            // Never leaves a real session (the authenticated display) scoped to the Tela's
            // entity instead of the technician's own — restore exactly what was there before,
            // or clear it if there was nothing (the stateless public path).
            if ($previousEntities === null) {
                unset($_SESSION['glpiactiveentities'], $_SESSION['glpiactiveentities_string']);
            } else {
                $_SESSION['glpiactiveentities']        = $previousEntities;
                $_SESSION['glpiactiveentities_string'] = $previousEntitiesString;
            }
        }

        $rows = [];
        foreach ($data['data']['rows'] ?? [] as $row) {
            $id = self::cellValue($row, 2);
            if ($id === '') {
                continue;
            }
            $out = ['id' => $id];
            foreach ($columns as $key) {
                if ($key === 'elapsed') {
                    $opened    = self::cellValue($row, 15);
                    $out[$key] = $opened === '' ? '' : ElapsedTimeLabel::format($opened, new \DateTimeImmutable());
                    continue;
                }
                $optionId  = ColumnCatalog::searchOptionId($key);
                $out[$key] = $optionId === null ? '' : self::cellValue($row, $optionId);
            }
            $rows[] = $out;
        }

        $labels = [];
        foreach ($columns as $key) {
            $labels[] = ['key' => $key, 'label' => MonitorLabels::column($key)];
        }

        return ['columns' => $labels, 'rows' => $rows];
    }

    /**
     * Reads one search option's value out of a Search::getDatas() row. Legacy row format
     * "ITEM_Ticket_<id>", which Search::getDatas() parses into the key "Ticket_<id>" with each
     * value under [0..count-1]['name'] — verified against the GLPI 11 core source and the probe
     * script (plan Task 6, Step 1).
     *
     * @param array<string, mixed> $row
     */
    private static function cellValue(array $row, int $searchOptionId): string
    {
        $cell = $row['Ticket_' . $searchOptionId] ?? null;
        if (!is_array($cell)) {
            return '';
        }
        $count = (int) ($cell['count'] ?? 1);
        $parts = [];
        for ($i = 0; $i < $count; $i++) {
            $value = $cell[$i]['name'] ?? null;
            if ($value !== null && $value !== '') {
                $parts[] = (string) $value;
            }
        }
        return implode(', ', $parts);
    }

    private static function forceEntityScope(int $entitiesId, bool $recursive): void
    {
        $ids = [$entitiesId => $entitiesId];
        if ($recursive) {
            foreach (array_keys(getSonsOf('glpi_entities', $entitiesId)) as $son) {
                $ids[$son] = $son;
            }
        }
        $_SESSION['glpiactiveentities']        = $ids;
        $_SESSION['glpiactiveentities_string'] = "'" . implode("', '", $ids) . "'";
    }
}
```

- [ ] **Step 4: Lint**

Run: `/c/xampp/php/php.exe -l src/Monitor/ScreenQuery.php`
Expected: `No syntax errors detected`.

- [ ] **Step 5: Verificar manualmente contra uma Tela real**

Crie uma Tela de teste (Task 4 já deixou o CRUD pronto) apontando para a Pesquisa Salva usada no Step 1, com algumas colunas escolhidas. Chame `ScreenQuery::run()` manualmente (pode reaproveitar o mesmo truque do Step 1: cole uma chamada temporária em `front/monitor/monitorscreen.php` com `var_dump(\GlpiPlugin\Gac\Monitor\ScreenQuery::run($telaDeTeste))`) e confira que `rows` tem os tickets esperados, com os valores certos nas colunas certas, e que a entidade forçada realmente restringe o resultado (teste com uma Tela de uma entidade que não tem tickets abertos e confirme que `rows` vem vazio). Remova o código temporário depois.

- [ ] **Step 6: Apagar o probe e commitar**

Run: `rm tools/probe-monitor-search.php` (ou apague manualmente; é descartável, não deve ir para o commit).

Invoque o skill `/commit` (tipo `feat`, por exemplo "add ScreenQuery to run a Tela's ticket search").

---

### Task 7: Exibição autenticada

**Files:**
- Create: `templates/monitor/_board.html.twig`, `templates/monitor/display.html.twig`, `front/monitor/display.php`, `ajax/monitor/data.php`

**Interfaces:**
- Consumes: `MonitorScreen`, `MonitorConfig::load()`, `ScreenQuery::run()`.
- Produces: página `front/monitor/display.php?id=<id>` e endpoint JSON `ajax/monitor/data.php?id=<id>` (GET), consumidos pelo `public/js/monitor.js` da Tarefa 9.

- [ ] **Step 1: Painel compartilhado**

Create `templates/monitor/_board.html.twig`:

```twig
<div class="gac-monitor-board"
     data-gac-monitor
     data-ajax-url="{{ ajax_url }}"
     data-poll-interval="{{ poll_interval }}"
     data-alert-enabled="{{ alert_enabled ? '1' : '0' }}">
    <div class="gac-monitor-topbar">
        <h1>{{ screen.fields.name }}</h1>
        <span data-gac-monitor-status class="gac-monitor-status-stale"></span>
    </div>
    <table>
        <thead><tr data-gac-monitor-head></tr></thead>
        <tbody data-gac-monitor-body></tbody>
    </table>
    <audio data-gac-monitor-audio src="{{ alert_sound_url }}" preload="auto"></audio>
</div>
```

- [ ] **Step 2: Template autenticado**

Create `templates/monitor/display.html.twig`:

```twig
<link rel="stylesheet" href="{{ asset_css }}">
{% include '@gac/monitor/_board.html.twig' %}
<script src="{{ asset_js }}"></script>
```

- [ ] **Step 3: Página autenticada**

Create `front/monitor/display.php`:

```php
<?php

use GlpiPlugin\Gac\GacMenu;
use GlpiPlugin\Gac\Monitor\MonitorConfig;
use GlpiPlugin\Gac\Monitor\MonitorScreen;
use GlpiPlugin\Gac\Monitor\MonitorSettings;
use Glpi\Application\View\TemplateRenderer;

if (!MonitorScreen::canView()) {
    Html::displayRightError();
}

$screen = new MonitorScreen();
if (!$screen->getFromDB((int) ($_GET['id'] ?? 0)) || !$screen->fields['is_active']) {
    Html::displayNotFoundError();
}

Html::header(
    $screen->fields['name'],
    $_SERVER['PHP_SELF'],
    GacMenu::SECTOR,
    GacMenu::ITEM_MONITOR
);

global $CFG_GLPI;
$settings = MonitorConfig::load();
$version  = Plugin::getPluginFilesVersion('gac');

TemplateRenderer::getInstance()->display('@gac/monitor/display.html.twig', [
    'screen'          => $screen,
    'ajax_url'        => $CFG_GLPI['root_doc'] . '/plugins/gac/ajax/monitor/data.php?id=' . $screen->getID(),
    'poll_interval'   => $screen->pollIntervalSeconds($settings),
    'alert_enabled'   => (bool) $screen->fields['alert_enabled'],
    // Empty when not configured: public/sounds/ is not guaranteed to have a bundled file (Task
    // 9, Step 3). The JS's play() call already swallows a missing/empty source silently.
    'alert_sound_url' => MonitorSettings::alertSoundUrl($settings),
    'asset_js'        => $CFG_GLPI['root_doc'] . '/plugins/gac/js/monitor.js?v=' . $version,
    'asset_css'       => $CFG_GLPI['root_doc'] . '/plugins/gac/css/monitor.css?v=' . $version,
]);

Html::footer();
```

- [ ] **Step 4: Endpoint de dados**

Create `ajax/monitor/data.php`:

```php
<?php

// Somente leitura, GET: não precisa de Session::checkCSRF().
use GlpiPlugin\Gac\Monitor\MonitorScreen;
use GlpiPlugin\Gac\Monitor\ScreenQuery;

header('Content-Type: application/json; charset=utf-8');

if (!MonitorScreen::canView()) {
    http_response_code(403);
    echo json_encode(['error' => __('Acesso negado.', 'gac')], JSON_UNESCAPED_UNICODE);
    exit;
}

$screen = new MonitorScreen();
if (!$screen->getFromDB((int) ($_GET['id'] ?? 0)) || !$screen->fields['is_active']) {
    http_response_code(404);
    echo json_encode(['error' => __('Tela não encontrada.', 'gac')], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $result = ScreenQuery::run($screen);
    echo json_encode($result + ['generated_at' => date('c')], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    Toolbox::logInFile('gac', 'monitor data.php: ' . $e->getMessage() . "\n");
    http_response_code(500);
    echo json_encode(['error' => __('Erro ao buscar os tickets.', 'gac')], JSON_UNESCAPED_UNICODE);
}
```

- [ ] **Step 5: Lint**

Run: `/c/xampp/php/php.exe -l front/monitor/display.php && /c/xampp/php/php.exe -l ajax/monitor/data.php`
Expected: `No syntax errors detected` nos dois.

- [ ] **Step 6: Verificar manualmente**

Limpe o cache (`bin/console cache:clear`), abra uma Tela pela listagem autenticada. A página deve carregar sem erro (a tabela fica vazia até a Tarefa 9 trazer o JS); acesse `ajax/monitor/data.php?id=<id>` direto no navegador logado e confira o JSON (`columns`, `rows`, `generated_at`).

- [ ] **Step 7: Commit**

Invoque o skill `/commit` (tipo `feat`, por exemplo "add authenticated Monitor display page and data endpoint").

---

### Task 8: Exibição pública (sem login)

**Files:**
- Create: `templates/monitor/public_display.html.twig`, `front/monitor/public.php`, `ajax/monitor/public_data.php`
- Modify: `setup.php`

**Interfaces:**
- Consumes: `MonitorScreen`, `MonitorConfig::load()`, `ScreenQuery::run()`, `PublicToken::isWellFormed()`.
- Produces: página `front/monitor/public.php?token=<token>` e endpoint JSON `ajax/monitor/public_data.php?token=<token>` (GET), ambos **sem sessão GLPI**.

- [ ] **Step 1: Registrar as rotas stateless**

Em `setup.php`, adicione o `use`:

```php
use Glpi\Http\SessionManager;
```

E dentro de `plugin_init_gac()`, no bloco `if ($plugin->isInstalled('gac') && $plugin->isActivated('gac'))`, adicione:

```php
        // Monitor: a exibição pública (TV/kiosk) roda sem sessão GLPI — M6/spec seção 7. Casa
        // só estes dois arquivos; display.php e data.php autenticados continuam exigindo login.
        SessionManager::registerPluginStatelessPath('gac', '#^/front/monitor/public\.php$#');
        SessionManager::registerPluginStatelessPath('gac', '#^/ajax/monitor/public_data\.php$#');
```

- [ ] **Step 2: Lint**

Run: `/c/xampp/php/php.exe -l setup.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Template público (documento HTML próprio)**

Create `templates/monitor/public_display.html.twig`:

```twig
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ screen.fields.name }}</title>
    <link rel="stylesheet" href="{{ asset_css }}">
    <style>html, body { margin: 0; padding: 0; background: #0b1220; height: 100%; }</style>
</head>
<body>
{% include '@gac/monitor/_board.html.twig' %}
<script src="{{ asset_js }}"></script>
</body>
</html>
```

- [ ] **Step 4: Página pública**

Create `front/monitor/public.php`:

```php
<?php

use GlpiPlugin\Gac\Monitor\MonitorConfig;
use GlpiPlugin\Gac\Monitor\MonitorScreen;
use GlpiPlugin\Gac\Monitor\MonitorSettings;
use GlpiPlugin\Gac\Monitor\PublicToken;
use Glpi\Application\View\TemplateRenderer;
use Glpi\Exception\Http\NotFoundHttpException;

global $CFG_GLPI;

$token  = (string) ($_GET['token'] ?? '');
$screen = new MonitorScreen();
if (
    !PublicToken::isWellFormed($token)
    || !$screen->getFromDBByCrit(['public_token' => $token, 'is_public' => 1, 'is_active' => 1])
) {
    throw new NotFoundHttpException();
}

$settings = MonitorConfig::load();
$version  = Plugin::getPluginFilesVersion('gac');

TemplateRenderer::getInstance()->display('@gac/monitor/public_display.html.twig', [
    'screen'          => $screen,
    'ajax_url'        => $CFG_GLPI['root_doc'] . '/plugins/gac/ajax/monitor/public_data.php?token=' . $token,
    'poll_interval'   => $screen->pollIntervalSeconds($settings),
    'alert_enabled'   => (bool) $screen->fields['alert_enabled'],
    // Empty when not configured: see the note in front/monitor/display.php.
    'alert_sound_url' => MonitorSettings::alertSoundUrl($settings),
    'asset_js'        => $CFG_GLPI['root_doc'] . '/plugins/gac/js/monitor.js?v=' . $version,
    'asset_css'       => $CFG_GLPI['root_doc'] . '/plugins/gac/css/monitor.css?v=' . $version,
]);
```

- [ ] **Step 5: Endpoint de dados público**

Create `ajax/monitor/public_data.php`:

```php
<?php

// Rota stateless (setup.php): sem sessão GLPI, sem Session::checkCSRF() — somente leitura, GET.
use GlpiPlugin\Gac\Monitor\MonitorScreen;
use GlpiPlugin\Gac\Monitor\PublicToken;
use GlpiPlugin\Gac\Monitor\ScreenQuery;

header('Content-Type: application/json; charset=utf-8');

$token = (string) ($_GET['token'] ?? '');
if (!PublicToken::isWellFormed($token)) {
    http_response_code(404);
    echo json_encode(['error' => __('Tela não encontrada.', 'gac')], JSON_UNESCAPED_UNICODE);
    exit;
}

$screen = new MonitorScreen();
if (!$screen->getFromDBByCrit(['public_token' => $token, 'is_public' => 1, 'is_active' => 1])) {
    http_response_code(404);
    echo json_encode(['error' => __('Tela não encontrada.', 'gac')], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $result = ScreenQuery::run($screen);
    echo json_encode($result + ['generated_at' => date('c')], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    Toolbox::logInFile('gac', 'monitor public_data.php: ' . $e->getMessage() . "\n");
    http_response_code(500);
    echo json_encode(['error' => __('Erro ao buscar os tickets.', 'gac')], JSON_UNESCAPED_UNICODE);
}
```

- [ ] **Step 6: Lint**

Run: `/c/xampp/php/php.exe -l front/monitor/public.php && /c/xampp/php/php.exe -l ajax/monitor/public_data.php`
Expected: `No syntax errors detected` nos dois.

- [ ] **Step 7: Reinstalar (setup.php mudou) e verificar manualmente sem login**

Run: `cd /c/Users/juliano/VSCode/glpi-xampp-dev-plugin && /c/xampp/php/php.exe bin/console plugin:deactivate gac && /c/xampp/php/php.exe bin/console plugin:uninstall gac && /c/xampp/php/php.exe bin/console plugin:install gac --username=glpi && /c/xampp/php/php.exe bin/console plugin:activate gac && /c/xampp/php/php.exe bin/console cache:clear`

**Isto apaga as Telas de teste** — recrie a Tela usada na Tarefa 6/7, marque "Exibição pública", salve (o token é gerado automaticamente), copie a "URL pública" mostrada no formulário. Abra essa URL numa aba anônima/privada do navegador (sem login no GLPI). Confira:
- a página carrega sem pedir login e sem o menu do GLPI (documento próprio, tema escuro);
- `ajax/monitor/public_data.php?token=<token>` devolve o JSON esperado, também sem login;
- trocar um caractere do token devolve 404 genérico (`Html::displayNotFoundError`/`NotFoundHttpException`), tanto na página quanto no endpoint de dados;
- desativar a Tela (`is_active = 0`) faz a mesma URL voltar 404.

- [ ] **Step 8: Commit**

Invoque o skill `/commit` (tipo `feat`, por exemplo "add public, session-less Monitor display for kiosks").

---

### Task 9: JS de polling/alerta e CSS do tema escuro

**Files:**
- Create: `public/js/monitor.js`, `public/css/monitor.css`

**Interfaces:**
- Consumes: os atributos `data-gac-monitor`, `data-ajax-url`, `data-poll-interval`, `data-alert-enabled` do `_board.html.twig` (Task 7); o JSON `{columns, rows, generated_at}` de `ajax/monitor/data.php` / `public_data.php` (Tasks 7-8).
- Produces: nada consumido por outra tarefa (ponta final da cadeia).

- [ ] **Step 1: JS**

Create `public/js/monitor.js`:

```js
(function () {
    'use strict';

    function formatTime(date) {
        return date.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
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

    function renderRows(root, columns, rows) {
        const body = root.querySelector('[data-gac-monitor-body]');
        const previousIds = Array.prototype.slice.call(body.children).map(function (tr) {
            return tr.dataset.ticketId;
        });

        body.innerHTML = '';
        let hasNew = false;
        rows.forEach(function (row) {
            const tr = document.createElement('tr');
            tr.dataset.ticketId = row.id;
            if (previousIds.length > 0 && previousIds.indexOf(row.id) === -1) {
                tr.classList.add('gac-monitor-row-new');
                hasNew = true;
            }
            columns.forEach(function (col) {
                const td = document.createElement('td');
                td.textContent = row[col.key] || '';
                tr.appendChild(td);
            });
            body.appendChild(tr);
        });
        return hasNew;
    }

    function setStatus(root, ok, when) {
        const indicator = root.querySelector('[data-gac-monitor-status]');
        if (!indicator) {
            return;
        }
        indicator.classList.toggle('gac-monitor-status-ok', ok);
        indicator.classList.toggle('gac-monitor-status-stale', !ok);
        indicator.title = ok
            ? 'Atualizado às ' + formatTime(when)
            : 'Dados desatualizados; última atualização bem-sucedida às ' + formatTime(when);
    }

    function playAlert(root) {
        const audio = root.querySelector('[data-gac-monitor-audio]');
        if (audio) {
            audio.currentTime = 0;
            audio.play().catch(function () { /* autoplay pode estar bloqueado até um gesto do usuário */ });
        }
    }

    function boot(root) {
        const url = root.dataset.ajaxUrl;
        const interval = Math.max(5, parseInt(root.dataset.pollInterval, 10) || 15) * 1000;
        const alertEnabled = root.dataset.alertEnabled === '1';

        let lastSuccess = null;
        let fetching = false;
        let firstLoad = true;

        async function tick() {
            if (fetching) {
                return;
            }
            fetching = true;
            try {
                const response = await fetch(url, { credentials: 'same-origin' });
                const payload = await response.json();
                if (!response.ok || payload.error) {
                    throw new Error(payload.error || ('HTTP ' + response.status));
                }
                renderHeader(root, payload.columns);
                // Um alerta por ciclo, não um por ticket novo (plan, "Decisões de implementação" item 8).
                const hasNew = renderRows(root, payload.columns, payload.rows);
                lastSuccess = new Date();
                setStatus(root, true, lastSuccess);
                if (hasNew && alertEnabled && !firstLoad) {
                    playAlert(root);
                }
                firstLoad = false;
            } catch (e) {
                setStatus(root, false, lastSuccess || new Date());
            } finally {
                fetching = false;
            }
        }

        tick();
        setInterval(tick, interval);
    }

    document.querySelectorAll('[data-gac-monitor]').forEach(boot);
})();
```

- [ ] **Step 2: CSS**

Create `public/css/monitor.css`:

```css
.gac-monitor-board {
    background: #0b1220;
    color: #e6edf3;
    font-family: 'Segoe UI', system-ui, sans-serif;
    min-height: 100vh;
    padding: 1rem 1.5rem;
    box-sizing: border-box;
}
.gac-monitor-topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1rem;
}
.gac-monitor-topbar h1 {
    font-size: 1.5rem;
    margin: 0;
}
.gac-monitor-board table {
    width: 100%;
    border-collapse: collapse;
    font-size: 1.1rem;
}
.gac-monitor-board thead th {
    text-align: left;
    padding: 0.5rem 0.75rem;
    border-bottom: 2px solid #1f2a3c;
    color: #8fa3bf;
    text-transform: uppercase;
    font-size: 0.8rem;
    letter-spacing: 0.05em;
}
.gac-monitor-board tbody td {
    padding: 0.5rem 0.75rem;
    border-bottom: 1px solid #1a2436;
}
.gac-monitor-row-new {
    animation: gac-monitor-flash 2s ease-out 1;
}
@keyframes gac-monitor-flash {
    0%   { background-color: #ffcc00; color: #111; }
    100% { background-color: transparent; }
}
.gac-monitor-status-ok::before    { content: '●'; color: #2ecc71; margin-right: 0.35rem; }
.gac-monitor-status-stale::before { content: '●'; color: #e74c3c; margin-right: 0.35rem; }
```

- [ ] **Step 3: Som do alerta — sem arquivo embutido**

O plugin **não** embute um arquivo de som padrão: não há como gerar um binário de áudio real dentro deste plano, e um arquivo placeholder quebrado seria pior do que nenhum. Sem `monitor_alert_sound_url` configurado (Tarefa 5), `alert_sound_url` chega vazio ao template, o `<audio src="">` fica sem fonte, e `audio.play()` falha silenciosamente (já capturado pelo `.catch()` do Step 1) — o alerta visual (linha piscando) continua funcionando normalmente, só o som fica mudo. Para ter som, o dono do plugin configura `monitor_alert_sound_url` na tela de configuração (Tarefa 5) apontando para qualquer URL de áudio acessível (um arquivo subido em `public/sounds/` do próprio plugin, ou uma URL externa, como o Django já permitia). Registre essa pendência em `docs/monitor-manual-tests.md` (Tarefa 10).

- [ ] **Step 4: Verificar manualmente (autenticado e público)**

Limpe o cache do GLPI, force F5/Ctrl+F5 no navegador (JS/CSS cacheados por hash de versão). Abra a exibição autenticada de uma Tela e a pública (aba anônima) lado a lado. Confirme:
- a tabela aparece com cabeçalho e linhas corretas;
- o indicador de status fica verde após o primeiro carregamento;
- crie um ticket novo que bate no critério da Tela — no próximo poll a linha nova pisca (destaque amarelo) e, se "Alerta" estiver ligado, o som toca uma vez;
- derrube a rede (DevTools > Network > Offline) um instante: o indicador fica vermelho e volta a verde quando a rede retorna, sem travar a página.

- [ ] **Step 5: Commit**

Invoque o skill `/commit` (tipo `feat`, por exemplo "add Monitor polling JS and dark board CSS").

---

### Task 10: Roteiro manual, `CLAUDE.md` e verificação final

**Files:**
- Create: `docs/monitor-manual-tests.md`
- Modify: `CLAUDE.md`

**Interfaces:**
- Consumes: tudo das Tarefas 1-9.
- Produces: nada (tarefa terminal).

- [ ] **Step 1: Rodar a suíte inteira de testes puros**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml`
Expected: PASS em todos os testes, incluindo os de PRE e LTBP já existentes (nenhuma regressão).

- [ ] **Step 2: Roteiro manual**

Create `docs/monitor-manual-tests.md`:

```markdown
# Monitor — roteiro de teste manual

Ambiente: GLPI local (`http://glpi11local.test/`), plugin `gac` instalado e ativo. Última
execução: [PREENCHER — data e quem rodou].

| # | Cenário | Passos | Resultado esperado | Resultado |
|---|---|---|---|---|
| 1 | Direito ausente | Logar com um perfil sem o direito `plugin_gac_monitor` | Menu "Telas de Monitoramento" não aparece; acesso direto a `front/monitor/monitorscreen.php` mostra erro de direito | Não executado |
| 2 | Criar Tela sem Pesquisa Salva compartilhada | No formulário de nova Tela, deixar "Pesquisa salva" vazio e salvar | Formulário recusa com a mensagem "Escolha uma Pesquisa Salva de Ticket compartilhada." | Não executado |
| 3 | Criar Tela válida | Criar uma Pesquisa Salva de Ticket compartilhada; criar uma Tela apontando para ela, entidade X, algumas colunas | Tela salva; lista mostra a nova Tela | Não executado |
| 4 | Exibição autenticada | Abrir a Tela criada (exibição, não o formulário) | Tabela mostra os tickets do critério da Pesquisa Salva, restritos à entidade X | Não executado |
| 5 | Restrição de entidade | Criar um ticket na entidade Y (fora de X); confirmar que não aparece na Tela da entidade X | Ticket de Y não aparece | Não executado |
| 6 | Sub-entidades | Marcar "Incluir sub-entidades" numa Tela da entidade raiz de X; criar ticket numa sub-entidade de X | Ticket da sub-entidade aparece | Não executado |
| 7 | Exibição pública | Marcar "Exibição pública", salvar, copiar a URL pública, abrir em aba anônima | Página carrega sem login, sem menu do GLPI, tema escuro, tabela com os mesmos dados da exibição autenticada | Não executado |
| 8 | Token inválido | Alterar um caractere do token na URL pública | 404 genérico, tanto na página quanto em `ajax/monitor/public_data.php` | Não executado |
| 9 | Tela inativa | Desativar a Tela (`is_active`); tentar abrir a URL pública de novo | 404 genérico | Não executado |
| 10 | Regenerar token | No formulário, clicar "Gerar novo link" | Nova URL funciona; a URL antiga passa a dar 404 | Não executado |
| 11 | Polling | Deixar a exibição aberta; criar um ticket que bate no critério | Em até um ciclo de polling, a linha aparece, pisca e (se alerta ligado) toca som uma vez | Não executado |
| 12 | Alerta desligado | Desligar "Alerta sonoro/visual" numa Tela; repetir o cenário 11 | Linha aparece e pisca, sem som | Não executado |
| 13 | Rede fora do ar | DevTools > Network > Offline por um ciclo, depois religar | Indicador fica vermelho e volta a verde sem recarregar a página | Não executado |
| 14 | Configuração global | Mudar o intervalo padrão e a URL do som na configuração do plugin; abrir uma Tela sem intervalo próprio | A Tela usa o novo intervalo padrão | Não executado |
| 15 | Intervalo próprio da Tela | Definir um intervalo específico numa Tela, diferente do padrão global | A Tela usa o intervalo próprio, não o global | Não executado |
| 16 | Colunas e ordem | Escolher um subconjunto de colunas numa ordem específica | A tabela mostra exatamente essas colunas, nessa ordem | Não executado |
| 17 | Excluir Tela | Excluir (`purge`) uma Tela pública | A URL pública antiga passa a dar 404 | Não executado |

## Pendências conhecidas após a implementação

- O plugin não embute um arquivo de som padrão (Tarefa 9, Step 3): sem `monitor_alert_sound_url` configurado, o alerta fica só visual (sem som). [PREENCHER: se/quando um som padrão for configurado, registrar a URL usada aqui.]
- [PREENCHER: qualquer divergência encontrada no formato de `Search::getDatas()` durante a Tarefa 6 (probe script), se houve.]
```

- [ ] **Step 3: Atualizar o `CLAUDE.md`**

Em `C:\Users\juliano\VSCode\plugin-gac\CLAUDE.md`, na seção "## What exists today", depois do parágrafo do LTBP, adicione um parágrafo novo:

```markdown
The third module is the **Monitor** (painel de monitoramento de tickets), in `src/Monitor/`. It
replaces the ticket-monitoring panel of the separate Django project
(`morefunctionsforglpi`, `apps/panel` + `apps/dbcom`): one or more "Telas de Monitoramento"
(`MonitorScreen`), each scoped to an entity (with an explicit recursive flag, never inherited
from a session) and filtered by a shared GLPI Saved Search (`SavedSearch`, type Ticket,
`is_private = 0`), with a curated, admin-ordered set of columns, a polling interval (per-screen
override or a global default), and an optional public, token-authenticated, session-less URL for
unattended TV/kiosk display. Its design is in
`docs/superpowers/specs/2026-10-01-monitor-design.md` (decisions M1 to M9, the source of truth)
and its implementation plan in
`docs/superpowers/plans/2026-10-01-monitor-implementation.md`. `docs/monitor-manual-tests.md` is
the manual test script. **If the code diverges from the spec, one of them is wrong: fix it.**
```

Se, ao rodar esta tarefa, outras seções do `CLAUDE.md` já tiverem mudado de formato desde que este plano foi escrito, adapte o texto ao estilo atual do arquivo em vez de inserir o bloco acima literalmente.

- [ ] **Step 4: Lint final de tudo**

Run: `for f in $(find src/Monitor front/monitor ajax/monitor -name '*.php'); do /c/xampp/php/php.exe -l "$f" || exit 1; done`
Expected: `No syntax errors detected` em todos, sem nenhum `exit 1`.

- [ ] **Step 5: Reinstalar uma última vez e rodar o roteiro manual completo**

Run: `cd /c/Users/juliano/VSCode/glpi-xampp-dev-plugin && /c/xampp/php/php.exe bin/console plugin:deactivate gac && /c/xampp/php/php.exe bin/console plugin:uninstall gac && /c/xampp/php/php.exe bin/console plugin:install gac --username=glpi && /c/xampp/php/php.exe bin/console plugin:activate gac && /c/xampp/php/php.exe bin/console cache:clear`

Execute os 17 cenários de `docs/monitor-manual-tests.md`, preencha a coluna "Resultado" e a data/quem rodou no topo do arquivo. Corrija qualquer cenário que falhe antes de prosseguir — se a correção for maior que um ajuste pontual, trate como uma tarefa nova (não force-encaixe num commit já fechado).

- [ ] **Step 6: Commit final**

Invoque o skill `/commit` (tipo `docs`, por exemplo "add Monitor manual test script and document the module in CLAUDE.md" — **nota:** `/commit` só aceita `feat`, `fix` ou `chore`; se "docs" não for aceito pelo skill, use `chore`).
