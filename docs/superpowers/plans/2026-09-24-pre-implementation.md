# PRE — Protocolo de Reparo de Equipamento: Plano de Implementação

> **Para agentes:** SUB-SKILL OBRIGATÓRIA: use `superpowers:subagent-driven-development` (recomendado) ou `superpowers:executing-plans` para executar este plano tarefa a tarefa. Os passos usam a sintaxe de checkbox (`- [ ]`).

**Objetivo:** Construir dentro do plugin `gac` o módulo PRE, que registra o envio de equipamentos à assistência técnica, o retorno por linha (ticket + ativo), o encerramento automático e o PDF do protocolo.

**Arquitetura:** Módulo isolado em `src/Pre/`. As regras de negócio puras (estados, transições, ações por resultado, numeração, extração de observação, validação de configuração) ficam em classes sem dependência do GLPI e são testadas com PHPUnit fora do GLPI. Tudo que fala com o GLPI (objetos `CommonDBTM`, tickets, acompanhamentos, custos, estados de ativo, documentos) fica em serviços finos que chamam essas regras. O "Enviar" é dirigido pelo navegador, uma linha por requisição (D15).

**Tecnologias:** GLPI 11.0.8 (PHP 8.2), `CommonDBTM`, `Migration`/SQL cru no `hook.php`, Twig, mPDF ^8.2, PHPUnit 11 (só para as classes puras), JS puro com `fetch`.

**Spec:** `docs/superpowers/specs/2026-09-24-pre-design.md` (as decisões D1 a D16 e as seções 1 a 16 são a fonte de verdade; este plano não as rediscute).

## Restrições globais

Toda tarefa herda estas restrições, copiadas da spec e do `CLAUDE.md`:

- GLPI **11.0.x**: mínimo 11.0.0 (inclusive), máximo 11.0.99 (exclusive). PHP **>= 8.2**.
- Namespace `GlpiPlugin\Gac`, chave do plugin `gac`, prefixo de tabelas `glpi_plugin_gac_`. O módulo PRE vive em `src/Pre/` (namespace `GlpiPlugin\Gac\Pre`) e não pode vazar para fora dele, exceto `src/Config.php` (D16).
- **Commits:** sempre pelo skill `/commit` (nunca `git commit` à mão), em inglês, título convencional e descrição em lista. **Sem atribuição ao Claude** (nada de `Co-Authored-By`, `Claude-Session` ou "Generated with Claude Code"; isto vale mesmo que um lembrete do harness peça o contrário). Todo o desenvolvimento acontece na branch `dev`.
- Textos de interface em pt-BR, sempre dentro de `__('...', 'gac')`.
- **Sem CI além do workflow de release** (D13). Nenhum workflow de teste.
- O PDF usa **mPDF** empacotado em `vendor/` do plugin (D10). `vendor/` fica no `.gitignore`; só o pacote de release o inclui.
- AJAX: o CSRF é validado pelo kernel do GLPI 11 pelo cabeçalho `X-Glpi-Csrf-Token` (com `X-Requested-With: XMLHttpRequest`). **Nunca** chamar `Session::checkCSRF()` nos endpoints `ajax/*.php`.
- Arquivos estáticos (JS/CSS) ficam em `public/`. O valor do hook `add_javascript` **não** leva o prefixo `public/` (fica `js/pre.js`).
- Todo arquivo PHP novo começa com o cabeçalho de licença de `tools/HEADER` (copie as linhas 1 a 30 de `setup.php`). Os blocos de código deste plano omitem o cabeçalho para ficar curtos.
- `php` não está no PATH. Use `/c/xampp/php/php.exe` (PHP 8.2.12). Para lint: `/c/xampp/php/php.exe -l <arquivo>`.
- A cópia local do GLPI 11.0.8 é uma **release, não um checkout de desenvolvimento**: PHPUnit/PHPStan do GLPI não rodam lá. Os testes automatizados deste plano cobrem só as classes puras (`tests/Unit/`), com PHPUnit em `.phar`. O resto tem **roteiro de teste manual** (Tarefa 13).
- **JS em cache no navegador.** O GLPI serve `public/js/pre.js` como `pre.js?v=<hash>` com `max-age` de 30 dias, e o hash só muda quando a versão do plugin muda. Depois de editar o JS no GLPI de desenvolvimento, force a atualização (`fetch(<url com ?v>, {cache: 'reload'})` e recarregue, ou Ctrl+F5); num release a versão sobe e o problema não existe.
- **Cache do GLPI.** O GLPI local roda em modo `production`: ele **não recompila** templates Twig editados. Depois de mudar qualquer `.twig`, rode `cd /c/Users/juliano/VSCode/glpi-xampp-dev-plugin && /c/xampp/php/php.exe bin/console cache:clear`, senão a tela continua mostrando o template antigo (foi assim que a Tarefa 8 pareceu não funcionar na primeira verificação).
- O plugin já está ligado ao GLPI local por junção: `C:\Users\juliano\VSCode\glpi-xampp-dev-plugin\plugins\gac` → este diretório.
- As chaves de configuração do PRE usam o prefixo `pre_`, guardadas em `glpi_configs` no contexto `plugin:gac` (D16).
- Nomes de coluna: a FK do PRE nas tabelas filhas é `plugin_gac_repairprotocols_id`; a da linha nos eventos é `plugin_gac_repairprotocolitems_id`.

## Decisões de implementação que a spec não fixava

O plano toma estas decisões. Nenhuma contradiz a spec; cada uma fecha um ponto que ela deixou aberto ou detalha um mecanismo. **Se alguma não servir, mude antes de executar.**

1. **Numeração por contador atômico**, não por "nova tentativa em colisão" (spec 5.4). Uma tabela `glpi_plugin_gac_protocolsequences` (`year`, `last`) é incrementada com `INSERT ... ON DUPLICATE KEY UPDATE last = LAST_INSERT_ID(last + 1)`. Dá a mesma garantia (nunca dois números iguais), sem laço de retry. A restrição única em `number` continua como cinto de segurança. Números podem ter buracos se a criação falhar depois de reservar o número.
2. **Descrição inicial para o fornecedor.** A spec diz "inicia com a observação do snapshot" mas proíbe scraping para a elegibilidade. Para a *observação* (só um texto sugerido, sempre editável em `Rascunho`), o plano porta a extração do app antigo ("Informações adicionais") para PHP com `DOMDocument`. Se não achar, usa o **título do ticket** como texto inicial.
3. **Depois de reabrir**, o PRE fica em `Retorno parcial` até o técnico clicar **"Concluir correções"**, que grava o evento `closed` e volta a `Encerrado`. Sem isso, o primeiro salvamento de correção já reencerraria o PRE e só permitiria uma edição por reabertura.
4. **Direitos.** Um direito `plugin_gac_pre` com os bits padrão (READ, UPDATE, CREATE, PURGE) mais três bits próprios: `SEND = 256`, `RETURN = 512`, `REOPEN = 1024`. Na instalação só perfis que já têm o direito nativo `config` recebem tudo; os demais começam sem acesso e o administrador libera pela aba do perfil.
5. **Menu.** A lista de PREs fica no setor `management` (Gerência, escolha do dono); a configuração no setor `config` e no ícone de engrenagem da lista de plugins.
6. **Pendente e motivo.** Entrar em Pendente = acompanhamento com `pending=1` + `pendingreasons_id` (é o caminho suportado pelo GLPI 11). Sair de Pendente = atualizar o `status` do ticket para o `previous_status` guardado (o GLPI apaga o motivo sozinho). Manter Pendente trocando o motivo = acompanhamento com `pending=1` + novo motivo, depois restaurar o `previous_status` original, porque o GLPI sobrescreve esse campo com `Pendente` nesse caminho. Isso está **verificado no código do core, não em execução**: a Tarefa 13 tem um teste manual dedicado.
7. **Coluna `item_entities_id`** na linha: guarda a entidade do ativo para a validação de `State` (spec seção 10).
8. **Linha presa em `Enviando`** por mais de 5 minutos volta a `Aguardando envio` na próxima chamada de envio (spec 7.3).
9. **PRE sem linhas depois de "Remover linha com falha"** nunca enviou nada e vira `Cancelado` (a spec só permite cancelar rascunho; este é o único caminho para o estado fora do rascunho).
10. **Classes em `src/Pre/` (subnamespace).** O GLPI deriva o nome da tabela e as URLs de `front/` a partir do nome da classe e, com o subnamespace `Pre`, o resultado seria `glpi_plugin_gac_pres_...` e `front/pre/...`. Por isso cada classe de dados sobrescreve `getTable()` e os arquivos de front ficam em `front/pre/`. Pelo mesmo motivo, a opção de busca `itemlink` declara `'itemtype' => self::class`: sem isso a **lista quebra assim que existe a primeira linha** (o GLPI tenta mapear a tabela de volta para a classe e falha; a lista vazia não denuncia o erro).
11. **Contador de numeração** em tabela própria (`glpi_plugin_gac_protocolsequences`); ver decisão 1.
12. **Ticket com mais de um ativo (decisão do dono).** A ação de ticket configurada para o resultado (Reabrir, Solucionar ou Manter pendente) só é aplicada quando a linha que voltou é a **última linha ativa daquele ticket**, em qualquer PRE. Enquanto outra linha do mesmo ticket ainda está fora, o ticket mantém status e motivo de pendência e recebe **só o acompanhamento-resumo**. A ação sobre o **ativo** vale sempre, por linha. Quem devolve a última linha decide o status do ticket.
13. **Nome do custo do ticket (decisão do dono):** `Fornecedor - Nº OS/NF - Ativo` (partes vazias são omitidas), gerado por `CostLabel::name()`.

## Mapa de arquivos

Criar:

| Arquivo | Responsabilidade |
|---|---|
| `src/Pre/ProtocolStatus.php`, `ItemStatus.php`, `Outcome.php`, `Destination.php` | Enums puros (valores gravados no banco) |
| `src/Pre/StateMachine.php` | Regras puras de transição e permissão de ação por estado |
| `src/Pre/ReturnActionResolver.php` | Puro: (resultado, destino) → ações de ticket e ativo, a partir da configuração |
| `src/Pre/ProtocolNumber.php` | Puro: formata e lê `PRE-AAAA-NNN` |
| `src/Pre/TicketObservationExtractor.php` | Puro: extrai "Informações adicionais" do HTML do ticket |
| `src/Pre/PreSettings.php` | Puro: configuração tipada, padrões, normalização e checagem de papéis obrigatórios |
| `src/Pre/ServiceResult.php` | Puro: resultado de serviço (ok/mensagem/dados) |
| `src/Pre/ReportFormatter.php` | Puro: texto do cabeçalho do relatório |
| `src/ConfigSection.php` | Interface de uma seção de módulo na tela de configuração |
| `src/Pre/PreConfig.php` | GLPI: lê e grava `PreSettings` em `glpi_configs` |
| `src/Pre/PreConfigSection.php` | GLPI: desenha e trata o formulário da seção do PRE na tela de configuração |
| `src/Config.php` | GLPI: monta a página de configuração e percorre as seções dos módulos (D16) |
| `src/Pre/RepairProtocol.php` | GLPI: `CommonDBTM` do PRE, direitos, abas, campos de busca, formulário |
| `src/Pre/RepairProtocolItem.php` | GLPI: `CommonDBTM` da linha |
| `src/Pre/RepairProtocolEvent.php` | GLPI: gravação e listagem dos eventos (aba Histórico) |
| `src/Pre/ProtocolNumberGenerator.php` | GLPI: contador atômico por ano |
| `src/Pre/StateGuard.php` | GLPI: valida se um `State` serve à entidade de um ativo |
| `src/Pre/EligibleTicketFinder.php` | GLPI: tickets/ativos elegíveis para importar |
| `src/Pre/LineService.php` | GLPI: importar, editar descrição, remover linha de rascunho |
| `src/Pre/TicketOps.php` | GLPI: acompanhamento, pendência, solução e custo no ticket |
| `src/Pre/SendService.php` | GLPI: `start`, `sendLine`, `finalize`, `removeFailedLine` |
| `src/Pre/ReturnService.php` | GLPI: registrar retorno e extravio, recalcular e encerrar |
| `src/Pre/ReopenService.php` | GLPI: reabrir, corrigir dados de retorno, concluir correções |
| `src/Pre/PdfRenderer.php` | GLPI: monta os dados e gera o PDF com mPDF |
| `src/Pre/PreMenu.php`, `src/Pre/ConfigMenu.php` | GLPI: entradas de menu |
| `src/Pre/ProfileRights.php` | GLPI: aba de direitos em Administração > Perfis |
| `front/pre/repairprotocol.php`, `front/pre/repairprotocol.form.php` | Lista e formulário do PRE (o GLPI deriva `front/pre/` do subnamespace `Pre`) |
| `front/pre/repairprotocolitem.form.php` | POST de importar, editar descrição, remover, retorno, extravio, reabertura, correção |
| `front/pre/repairprotocol.pdf.php` | Prévia e download do PDF |
| `front/config.php` | Tela de configuração do plugin |
| `ajax/pre_send.php` | Endpoint JSON do envio (`start`, `line`, `finalize`, `remove_line`) |
| `public/js/pre.js` | Barra de progresso do envio |
| `templates/pre/*.html.twig` | Formulário do PRE, abas Itens e Histórico, e o layout do PDF (`report.html.twig`) |
| `tests/Unit/bootstrap.php`, `phpunit.unit.xml`, `tests/Unit/*Test.php` | Testes das classes puras |
| `docs/pre-manual-tests.md` | Roteiro de teste manual |
| `tools/build-release.sh`, `.release-exclude`, `.github/workflows/release.yml` | Empacotamento de release (D13) |
| `tools/render-report-sample.php` | Dev: renderiza o PDF de exemplo sem GLPI |

Modificar: `composer.json`, `setup.php`, `hook.php`, `gac.xml`.

---

### Tarefa 1: Fundação (autoload, harness de testes)

**Arquivos:**
- Modificar: `composer.json`
- Criar: `phpunit.unit.xml`, `tests/Unit/bootstrap.php`, `tests/Unit/HarnessTest.php`

**Interfaces:**
- Produz: o comando `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml`, que roda `tests/Unit/*Test.php` sem GLPI. Todas as tarefas de classes puras o usam.

- [ ] **Passo 1: Baixar o PHPUnit em `.phar`** (a pasta `var/` já está no `.gitignore`)

```bash
mkdir -p var/tools
curl -sSL --ssl-no-revoke -o var/tools/phpunit.phar https://phar.phpunit.de/phpunit-11.phar
/c/xampp/php/php.exe var/tools/phpunit.phar --version
```

Esperado: uma linha `PHPUnit 11.x.y by Sebastian Bergmann and contributors.`. O `--ssl-no-revoke` é necessário no Windows: sem ele o `curl` (Schannel) falha com `CRYPT_E_NO_REVOCATION_CHECK` (0x80092012). Se ainda falhar por rede, pare e avise o dono: sem o `.phar` os passos de teste das tarefas 2 a 4 não rodam.

Os blocos de código das tarefas 1 a 4 deste plano foram extraídos para uma cópia de teste e executados com este mesmo `.phar`: `OK (44 tests, 100 assertions)`.

- [ ] **Passo 2: Adicionar o autoload de `src/` ao `composer.json`**

Substitua o conteúdo de `composer.json` por:

```json
{
    "require": {
        "php": ">=8.2"
    },
    "config": {
        "optimize-autoloader": true,
        "platform": {
            "php": "8.2.99"
        },
        "sort-packages": true
    },
    "autoload": {
        "psr-4": {
            "GlpiPlugin\\Gac\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "GlpiPlugin\\Gac\\Tests\\": "tests/"
        }
    }
}
```

(O `require` do mPDF entra na Tarefa 12, quando ele passa a ser usado.)

- [ ] **Passo 3: Criar `phpunit.unit.xml`**

```xml
<phpunit
    bootstrap="tests/Unit/bootstrap.php"
    colors="true"
    testdox="true"
    cacheDirectory="var/phpunit-unit"
>
    <testsuites>
        <testsuite name="Unit">
            <directory suffix="Test.php">tests/Unit</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Passo 4: Criar `tests/Unit/bootstrap.php`** (autoloader próprio, sem GLPI)

```php
<?php

spl_autoload_register(static function (string $class): void {
    $map = [
        'GlpiPlugin\\Gac\\Tests\\' => dirname(__DIR__) . '/',
        'GlpiPlugin\\Gac\\'        => dirname(__DIR__, 2) . '/src/',
    ];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});
```

- [ ] **Passo 5: Criar um teste de fumaça `tests/Unit/HarnessTest.php`**

```php
<?php

namespace GlpiPlugin\Gac\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class HarnessTest extends TestCase
{
    public function testHarnessRuns(): void
    {
        $this->assertSame(8, PHP_MAJOR_VERSION);
        $this->assertGreaterThanOrEqual(2, PHP_MINOR_VERSION);
    }
}
```

- [ ] **Passo 6: Rodar o harness**

```bash
/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml
```

Esperado: `OK (1 test, 2 assertions)`.

- [ ] **Passo 7: Commit** (pelo skill `/commit`, mensagem em inglês, sem atribuição)

Arquivos: `composer.json`, `phpunit.unit.xml`, `tests/Unit/bootstrap.php`, `tests/Unit/HarnessTest.php`. Título sugerido: `chore(tests): add standalone unit test harness and src autoload`.

---

### Tarefa 2: Enums e máquina de estados

**Arquivos:**
- Criar: `src/Pre/ProtocolStatus.php`, `src/Pre/ItemStatus.php`, `src/Pre/Outcome.php`, `src/Pre/Destination.php`, `src/Pre/StateMachine.php`
- Testar: `tests/Unit/StateMachineTest.php`

**Interfaces:**
- Produz (todas em `GlpiPlugin\Gac\Pre`):
  - `enum ProtocolStatus: string { Draft='draft'; Sent='sent'; Partial='partial'; Closed='closed'; Canceled='canceled' }`
  - `enum ItemStatus: string { PendingSend='pending_send'; Sending='sending'; AtSupplier='at_supplier'; Returned='returned'; Lost='lost' }` com `isActive(): bool` (PendingSend, Sending, AtSupplier) e `isFinal(): bool` (Returned, Lost)
  - `enum Outcome: string { Repaired='repaired'; NoFault='no_fault'; Unrepairable='unrepairable'; QuoteRejected='quote_rejected' }` com `isDefective(): bool` (Unrepairable, QuoteRejected)
  - `enum Destination: string { Writeoff='writeoff'; KeepDefective='keep_defective'; None='none' }`
  - `StateMachine::deriveProtocolStatus(ProtocolStatus $current, array $itemStatuses): ProtocolStatus` (`$itemStatuses` é `list<ItemStatus>`)
  - `StateMachine::canImportLines(ProtocolStatus $p): bool`
  - `StateMachine::canStartSend(ProtocolStatus $p): bool`
  - `StateMachine::canSendLine(ProtocolStatus $p, ItemStatus $i): bool`
  - `StateMachine::canRemoveLine(ProtocolStatus $p, ItemStatus $i): bool`
  - `StateMachine::removeRequiresReason(ProtocolStatus $p): bool`
  - `StateMachine::canRegisterReturn(ProtocolStatus $p, ItemStatus $i): bool`
  - `StateMachine::canReopen(ProtocolStatus $p): bool`
  - `StateMachine::canCancel(ProtocolStatus $p): bool`
  - `StateMachine::normalizeDestination(Outcome $o, ?Destination $d): Destination` (lança `\InvalidArgumentException`)

- [ ] **Passo 1: Escrever o teste que falha** — `tests/Unit/StateMachineTest.php`

```php
<?php

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
```

- [ ] **Passo 2: Rodar e ver falhar**

```bash
/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml --filter StateMachineTest
```

Esperado: erro `Class "GlpiPlugin\Gac\Pre\ProtocolStatus" not found`.

- [ ] **Passo 3: Criar os quatro enums**

`src/Pre/ProtocolStatus.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Pre;

enum ProtocolStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Partial = 'partial';
    case Closed = 'closed';
    case Canceled = 'canceled';
}
```

`src/Pre/ItemStatus.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Pre;

enum ItemStatus: string
{
    case PendingSend = 'pending_send';
    case Sending = 'sending';
    case AtSupplier = 'at_supplier';
    case Returned = 'returned';
    case Lost = 'lost';

    /** Active lines block the same ticket+asset pair from entering another PRE (spec 5.4). */
    public function isActive(): bool
    {
        return match ($this) {
            self::PendingSend, self::Sending, self::AtSupplier => true,
            self::Returned, self::Lost => false,
        };
    }

    public function isFinal(): bool
    {
        return !$this->isActive();
    }
}
```

`src/Pre/Outcome.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Pre;

enum Outcome: string
{
    case Repaired = 'repaired';
    case NoFault = 'no_fault';
    case Unrepairable = 'unrepairable';
    case QuoteRejected = 'quote_rejected';

    public function isDefective(): bool
    {
        return match ($this) {
            self::Unrepairable, self::QuoteRejected => true,
            self::Repaired, self::NoFault => false,
        };
    }
}
```

`src/Pre/Destination.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Pre;

enum Destination: string
{
    case Writeoff = 'writeoff';
    case KeepDefective = 'keep_defective';
    case None = 'none';
}
```

- [ ] **Passo 4: Criar `src/Pre/StateMachine.php`**

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Pre;

/**
 * Pure transition rules for the PRE (spec sections 6.1 to 6.3). No GLPI dependency.
 */
final class StateMachine
{
    /**
     * Status the protocol should have given its lines. Draft and Canceled never change by
     * derivation; Closed and Partial are computed, never typed (spec 6.1).
     *
     * @param list<ItemStatus> $itemStatuses
     */
    public static function deriveProtocolStatus(ProtocolStatus $current, array $itemStatuses): ProtocolStatus
    {
        if ($current === ProtocolStatus::Draft || $current === ProtocolStatus::Canceled) {
            return $current;
        }
        if ($itemStatuses === []) {
            return $current;
        }
        $final = 0;
        foreach ($itemStatuses as $status) {
            if ($status->isFinal()) {
                $final++;
            }
        }
        if ($final === count($itemStatuses)) {
            return ProtocolStatus::Closed;
        }
        return $final > 0 ? ProtocolStatus::Partial : ProtocolStatus::Sent;
    }

    public static function canImportLines(ProtocolStatus $p): bool
    {
        return $p === ProtocolStatus::Draft;
    }

    public static function canStartSend(ProtocolStatus $p): bool
    {
        return $p === ProtocolStatus::Draft;
    }

    public static function canSendLine(ProtocolStatus $p, ItemStatus $i): bool
    {
        return $p === ProtocolStatus::Sent && $i === ItemStatus::PendingSend;
    }

    /** Removal is allowed in Draft, plus the "remove failed line" exception in Sent (spec 6.2). */
    public static function canRemoveLine(ProtocolStatus $p, ItemStatus $i): bool
    {
        return $i === ItemStatus::PendingSend
            && ($p === ProtocolStatus::Draft || $p === ProtocolStatus::Sent);
    }

    public static function removeRequiresReason(ProtocolStatus $p): bool
    {
        return $p !== ProtocolStatus::Draft;
    }

    public static function canRegisterReturn(ProtocolStatus $p, ItemStatus $i): bool
    {
        return $i === ItemStatus::AtSupplier
            && ($p === ProtocolStatus::Sent || $p === ProtocolStatus::Partial);
    }

    public static function canReopen(ProtocolStatus $p): bool
    {
        return $p === ProtocolStatus::Closed;
    }

    public static function canCancel(ProtocolStatus $p): bool
    {
        return $p === ProtocolStatus::Draft;
    }

    /**
     * Repaired / no-fault outcomes always end with destination None; defective outcomes need
     * an explicit Writeoff or KeepDefective (spec 6.3).
     *
     * @throws \InvalidArgumentException
     */
    public static function normalizeDestination(Outcome $outcome, ?Destination $destination): Destination
    {
        if (!$outcome->isDefective()) {
            if ($destination !== null && $destination !== Destination::None) {
                throw new \InvalidArgumentException('A non-defective outcome cannot have a destination.');
            }
            return Destination::None;
        }
        if ($destination !== Destination::Writeoff && $destination !== Destination::KeepDefective) {
            throw new \InvalidArgumentException('A defective outcome requires writeoff or keep_defective.');
        }
        return $destination;
    }
}
```

- [ ] **Passo 5: Rodar e ver passar**

```bash
/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml
```

Esperado: `OK` com todos os testes verdes.

- [ ] **Passo 6: Commit** via `/commit`. Título sugerido: `feat(pre): add status enums and transition rules`.

---

### Tarefa 3: Numeração e extração de observação

**Arquivos:**
- Criar: `src/Pre/ProtocolNumber.php`, `src/Pre/TicketObservationExtractor.php`
- Testar: `tests/Unit/ProtocolNumberTest.php`, `tests/Unit/TicketObservationExtractorTest.php`

**Interfaces:**
- Produz:
  - `ProtocolNumber::format(int $year, int $seq): string` → `PRE-2026-001`
  - `ProtocolNumber::parse(string $number): ?array` → `['year' => int, 'seq' => int]` ou `null`
  - `TicketObservationExtractor::extract(string $html): string`; devolve `''` se não achar "Informações adicionais"

- [ ] **Passo 1: Testes que falham**

`tests/Unit/ProtocolNumberTest.php`:

```php
<?php

namespace GlpiPlugin\Gac\Tests\Unit;

use GlpiPlugin\Gac\Pre\ProtocolNumber;
use PHPUnit\Framework\TestCase;

final class ProtocolNumberTest extends TestCase
{
    public function testFormatPadsSequenceToThreeDigits(): void
    {
        $this->assertSame('PRE-2026-001', ProtocolNumber::format(2026, 1));
        $this->assertSame('PRE-2026-047', ProtocolNumber::format(2026, 47));
    }

    public function testFormatKeepsLongSequencesUntruncated(): void
    {
        $this->assertSame('PRE-2026-1234', ProtocolNumber::format(2026, 1234));
    }

    public function testParseRoundTrips(): void
    {
        $this->assertSame(['year' => 2026, 'seq' => 47], ProtocolNumber::parse('PRE-2026-047'));
        $this->assertSame(['year' => 2027, 'seq' => 1234], ProtocolNumber::parse('PRE-2027-1234'));
    }

    public function testParseRejectsGarbage(): void
    {
        $this->assertNull(ProtocolNumber::parse('PRE-26-1'));
        $this->assertNull(ProtocolNumber::parse('XXX-2026-001'));
        $this->assertNull(ProtocolNumber::parse(''));
    }
}
```

`tests/Unit/TicketObservationExtractorTest.php`:

```php
<?php

namespace GlpiPlugin\Gac\Tests\Unit;

use GlpiPlugin\Gac\Pre\TicketObservationExtractor;
use PHPUnit\Framework\TestCase;

final class TicketObservationExtractorTest extends TestCase
{
    public function testExtractsParagraphAfterBoldTitle(): void
    {
        $html = '<p><b>Informações adicionais:</b></p><p>Tela quebrada, não liga.</p>';
        $this->assertSame('Tela quebrada, não liga.', TicketObservationExtractor::extract($html));
    }

    public function testExtractsWhenTitleIsStrong(): void
    {
        $html = '<div><strong>Informações adicionais</strong><p>Sem áudio</p></div>';
        $this->assertSame('Sem áudio', TicketObservationExtractor::extract($html));
    }

    public function testExtractsLooseTextAfterTitle(): void
    {
        $html = '<b>Informações adicionais:</b> Teclado com teclas falhando<br>';
        $this->assertSame('Teclado com teclas falhando', TicketObservationExtractor::extract($html));
    }

    public function testDecodesEntitiesAndTrims(): void
    {
        $html = '<p><b>Informações adicionais:</b></p><p>  Cabo &amp; fonte  </p>';
        $this->assertSame('Cabo & fonte', TicketObservationExtractor::extract($html));
    }

    public function testReturnsEmptyWhenTitleIsMissing(): void
    {
        $this->assertSame('', TicketObservationExtractor::extract('<p>Outro conteúdo</p>'));
        $this->assertSame('', TicketObservationExtractor::extract(''));
    }
}
```

- [ ] **Passo 2: Rodar e ver falhar**

```bash
/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml --filter "ProtocolNumberTest|TicketObservationExtractorTest"
```

Esperado: `Class ... not found`.

- [ ] **Passo 3: Implementar `src/Pre/ProtocolNumber.php`**

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Pre;

final class ProtocolNumber
{
    private const PREFIX = 'PRE';

    public static function format(int $year, int $seq): string
    {
        return sprintf('%s-%04d-%03d', self::PREFIX, $year, $seq);
    }

    /** @return array{year: int, seq: int}|null */
    public static function parse(string $number): ?array
    {
        if (preg_match('/^' . self::PREFIX . '-(\d{4})-(\d{3,})$/', $number, $m) !== 1) {
            return null;
        }
        return ['year' => (int) $m[1], 'seq' => (int) $m[2]];
    }
}
```

- [ ] **Passo 4: Implementar `src/Pre/TicketObservationExtractor.php`**

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Pre;

/**
 * Port of the legacy Django extractor: pulls the "Informações adicionais" text out of the
 * ticket HTML. Only used to pre-fill the editable supplier description (decision 2 of the plan).
 */
final class TicketObservationExtractor
{
    private const TITLE = 'Informações adicionais';

    public static function extract(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $doc->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($doc);
        $titles = $xpath->query('//b|//strong');
        $title = null;
        foreach ($titles as $candidate) {
            if (str_contains($candidate->textContent, self::TITLE)) {
                $title = $candidate;
                break;
            }
        }
        if ($title === null) {
            return '';
        }

        // Main strategy: the first <p> after the title anywhere in the document order.
        $following = $xpath->query('following::p', $title);
        foreach ($following as $p) {
            if (!self::isInside($p, $title)) {
                $text = self::clean($p->textContent);
                if ($text !== '') {
                    return $text;
                }
            }
        }

        // Fallback: loose text right after the title.
        for ($node = $title->nextSibling; $node !== null; $node = $node->nextSibling) {
            $text = self::clean($node->textContent);
            if ($text === '' || $text === ':') {
                continue;
            }
            return ltrim($text, ": \t\n\r");
        }
        return '';
    }

    private static function isInside(\DOMNode $node, \DOMNode $ancestor): bool
    {
        for ($p = $node->parentNode; $p !== null; $p = $p->parentNode) {
            if ($p->isSameNode($ancestor)) {
                return true;
            }
        }
        return false;
    }

    private static function clean(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }
}
```

- [ ] **Passo 5: Rodar e ver passar**

```bash
/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml
```

Esperado: `OK`, todos verdes. Se algum teste do extrator falhar por diferença de parse do `DOMDocument`, ajuste a implementação (não o teste): o comportamento esperado é o descrito nos testes.

- [ ] **Passo 6: Commit** via `/commit`. Título sugerido: `feat(pre): add protocol number and ticket observation extractor`.

---

### Tarefa 4: Configuração tipada e ações por resultado

**Arquivos:**
- Criar: `src/Pre/PreSettings.php`, `src/Pre/ReturnActionResolver.php`
- Testar: `tests/Unit/PreSettingsTest.php`, `tests/Unit/ReturnActionResolverTest.php`

**Interfaces:**
- Consome: `Outcome`, `Destination` (Tarefa 2).
- Produz:
  - `PreSettings::defaults(): array` — o array bruto (é o que vai para `glpi_configs`)
  - `PreSettings::normalize(array $raw): array` — completa faltantes, corrige tipos, descarta chaves desconhecidas
  - `PreSettings::categoryIds(array $s): array` (list<int>), `includeSubcategories(array $s): bool`, `stateId(array $s, string $role): int`, `reasonId(array $s, string $role): int`, `logoCategoryId(array $s): int`, `actions(array $s): array`
  - `PreSettings::missingRolesForSend(array $s): list<string>` — nomes dos papéis obrigatórios sem mapeamento (`state:at_supplier`, `reason:at_supplier`)
  - `PreSettings::missingRolesForReturn(array $s, string $actionKey): list<string>`
  - Constantes de papéis: `PreSettings::STATE_ROLES = ['at_supplier','defective','awaiting_writeoff']`, `PreSettings::REASON_ROLES = ['at_supplier','awaiting_writeoff','awaiting_decision']`, `PreSettings::ACTION_KEYS = ['repaired','no_fault','writeoff','keep_defective']`
  - `ReturnActionResolver::actionKey(Outcome $o, Destination $d): string`
  - `ReturnActionResolver::resolve(string $actionKey, array $settings): array` → `['ticket' => ['type' => 'reopen'|'solve'|'keep_pending', 'pendingreasons_id' => int], 'asset' => ['type' => 'restore_previous'|'set_state', 'states_id' => int]]`; lança `\DomainException` quando falta um mapeamento necessário

Formato do array bruto de configuração (chaves gravadas em `glpi_configs`, todas string no banco):

```php
[
    'pre_category_ids'             => '[]',   // JSON list<int>
    'pre_include_subcategories'    => '1',
    'pre_logo_documentcategories_id' => '0',
    'pre_state_at_supplier'        => '0',
    'pre_state_defective'          => '0',
    'pre_state_awaiting_writeoff'  => '0',
    'pre_reason_at_supplier'       => '0',
    'pre_reason_awaiting_writeoff' => '0',
    'pre_reason_awaiting_decision' => '0',
    'pre_actions'                  => '<JSON>', // ver defaults()
]
```

- [ ] **Passo 1: Testes que falham**

`tests/Unit/PreSettingsTest.php`:

```php
<?php

namespace GlpiPlugin\Gac\Tests\Unit;

use GlpiPlugin\Gac\Pre\PreSettings;
use PHPUnit\Framework\TestCase;

final class PreSettingsTest extends TestCase
{
    public function testDefaultsMatchTheSpecTable(): void
    {
        $s = PreSettings::normalize([]);
        $actions = PreSettings::actions($s);

        $this->assertSame(['ticket' => ['type' => 'reopen', 'reason' => ''], 'asset' => ['type' => 'restore_previous', 'state' => '']], $actions['repaired']);
        $this->assertSame(['ticket' => ['type' => 'reopen', 'reason' => ''], 'asset' => ['type' => 'restore_previous', 'state' => '']], $actions['no_fault']);
        $this->assertSame(['ticket' => ['type' => 'keep_pending', 'reason' => 'awaiting_writeoff'], 'asset' => ['type' => 'set_state', 'state' => 'awaiting_writeoff']], $actions['writeoff']);
        $this->assertSame(['ticket' => ['type' => 'keep_pending', 'reason' => 'awaiting_decision'], 'asset' => ['type' => 'set_state', 'state' => 'defective']], $actions['keep_defective']);
        $this->assertTrue(PreSettings::includeSubcategories($s));
    }

    public function testNormalizeCoercesTypesAndDropsUnknownKeys(): void
    {
        $s = PreSettings::normalize([
            'pre_category_ids'        => '[3,"7",0,-2,"x"]',
            'pre_include_subcategories' => '0',
            'pre_state_at_supplier'   => '12',
            'pre_reason_at_supplier'  => 'abc',
            'garbage'                 => 'x',
        ]);
        $this->assertSame([3, 7], PreSettings::categoryIds($s));
        $this->assertFalse(PreSettings::includeSubcategories($s));
        $this->assertSame(12, PreSettings::stateId($s, 'at_supplier'));
        $this->assertSame(0, PreSettings::reasonId($s, 'at_supplier'));
        $this->assertArrayNotHasKey('garbage', $s);
    }

    public function testNormalizeRepairsBrokenActionsJson(): void
    {
        $s = PreSettings::normalize(['pre_actions' => '{not json']);
        $this->assertSame(PreSettings::actions(PreSettings::normalize([])), PreSettings::actions($s));
    }

    public function testNormalizeRejectsInvalidActionTypes(): void
    {
        $s = PreSettings::normalize(['pre_actions' => json_encode([
            'repaired' => ['ticket' => ['type' => 'explode', 'reason' => ''], 'asset' => ['type' => 'restore_previous', 'state' => '']],
        ])]);
        $this->assertSame('reopen', PreSettings::actions($s)['repaired']['ticket']['type']);
    }

    public function testMissingRolesForSend(): void
    {
        $s = PreSettings::normalize([]);
        $this->assertSame(['state:at_supplier', 'reason:at_supplier'], PreSettings::missingRolesForSend($s));

        $s = PreSettings::normalize(['pre_state_at_supplier' => '5', 'pre_reason_at_supplier' => '9']);
        $this->assertSame([], PreSettings::missingRolesForSend($s));
    }

    public function testMissingRolesForReturnFollowsTheConfiguredAction(): void
    {
        $s = PreSettings::normalize([]);
        $this->assertSame([], PreSettings::missingRolesForReturn($s, 'repaired'));
        $this->assertSame(['reason:awaiting_writeoff', 'state:awaiting_writeoff'], PreSettings::missingRolesForReturn($s, 'writeoff'));

        $s = PreSettings::normalize(['pre_reason_awaiting_writeoff' => '4', 'pre_state_awaiting_writeoff' => '6']);
        $this->assertSame([], PreSettings::missingRolesForReturn($s, 'writeoff'));
    }
}
```

`tests/Unit/ReturnActionResolverTest.php`:

```php
<?php

namespace GlpiPlugin\Gac\Tests\Unit;

use GlpiPlugin\Gac\Pre\Destination;
use GlpiPlugin\Gac\Pre\Outcome;
use GlpiPlugin\Gac\Pre\PreSettings;
use GlpiPlugin\Gac\Pre\ReturnActionResolver;
use PHPUnit\Framework\TestCase;

final class ReturnActionResolverTest extends TestCase
{
    public function testActionKeyMapping(): void
    {
        $this->assertSame('repaired', ReturnActionResolver::actionKey(Outcome::Repaired, Destination::None));
        $this->assertSame('no_fault', ReturnActionResolver::actionKey(Outcome::NoFault, Destination::None));
        $this->assertSame('writeoff', ReturnActionResolver::actionKey(Outcome::Unrepairable, Destination::Writeoff));
        $this->assertSame('writeoff', ReturnActionResolver::actionKey(Outcome::QuoteRejected, Destination::Writeoff));
        $this->assertSame('keep_defective', ReturnActionResolver::actionKey(Outcome::Unrepairable, Destination::KeepDefective));
        $this->assertSame('keep_defective', ReturnActionResolver::actionKey(Outcome::QuoteRejected, Destination::KeepDefective));
    }

    public function testResolveRepairedNeedsNoMapping(): void
    {
        $r = ReturnActionResolver::resolve('repaired', PreSettings::normalize([]));
        $this->assertSame(['type' => 'reopen', 'pendingreasons_id' => 0], $r['ticket']);
        $this->assertSame(['type' => 'restore_previous', 'states_id' => 0], $r['asset']);
    }

    public function testResolveWriteoffUsesMappedIds(): void
    {
        $s = PreSettings::normalize(['pre_reason_awaiting_writeoff' => '4', 'pre_state_awaiting_writeoff' => '6']);
        $r = ReturnActionResolver::resolve('writeoff', $s);
        $this->assertSame(['type' => 'keep_pending', 'pendingreasons_id' => 4], $r['ticket']);
        $this->assertSame(['type' => 'set_state', 'states_id' => 6], $r['asset']);
    }

    public function testResolveFailsWhenAMappingIsMissing(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('reason:awaiting_writeoff');
        ReturnActionResolver::resolve('writeoff', PreSettings::normalize([]));
    }

    public function testResolveHonoursACustomisedAction(): void
    {
        $s = PreSettings::normalize([
            'pre_actions' => json_encode([
                'repaired' => [
                    'ticket' => ['type' => 'solve', 'reason' => ''],
                    'asset'  => ['type' => 'set_state', 'state' => 'defective'],
                ],
            ]),
            'pre_state_defective' => '8',
        ]);
        $r = ReturnActionResolver::resolve('repaired', $s);
        $this->assertSame(['type' => 'solve', 'pendingreasons_id' => 0], $r['ticket']);
        $this->assertSame(['type' => 'set_state', 'states_id' => 8], $r['asset']);
    }

    public function testUnknownActionKeyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ReturnActionResolver::resolve('nope', PreSettings::normalize([]));
    }
}
```

- [ ] **Passo 2: Rodar e ver falhar**

```bash
/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml --filter "PreSettingsTest|ReturnActionResolverTest"
```

Esperado: `Class ... not found`.

- [ ] **Passo 3: Implementar `src/Pre/PreSettings.php`**

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Pre;

/**
 * Typed view over the raw PRE configuration array stored in glpi_configs (context plugin:gac,
 * keys prefixed pre_). Pure: no GLPI calls. See spec sections 5.3, 6.4 and 10.
 */
final class PreSettings
{
    public const STATE_ROLES  = ['at_supplier', 'defective', 'awaiting_writeoff'];
    public const REASON_ROLES = ['at_supplier', 'awaiting_writeoff', 'awaiting_decision'];
    public const ACTION_KEYS  = ['repaired', 'no_fault', 'writeoff', 'keep_defective'];

    public const TICKET_ACTIONS = ['reopen', 'solve', 'keep_pending'];
    public const ASSET_ACTIONS  = ['restore_previous', 'set_state'];

    /** @return array<string, string> */
    public static function defaults(): array
    {
        $raw = [
            'pre_category_ids'               => '[]',
            'pre_include_subcategories'      => '1',
            'pre_logo_documentcategories_id' => '0',
            'pre_actions'                    => json_encode(self::defaultActions(), JSON_THROW_ON_ERROR),
        ];
        foreach (self::STATE_ROLES as $role) {
            $raw['pre_state_' . $role] = '0';
        }
        foreach (self::REASON_ROLES as $role) {
            $raw['pre_reason_' . $role] = '0';
        }
        return $raw;
    }

    /** @return array<string, array{ticket: array{type: string, reason: string}, asset: array{type: string, state: string}}> */
    private static function defaultActions(): array
    {
        $reopen = ['type' => 'reopen', 'reason' => ''];
        $restore = ['type' => 'restore_previous', 'state' => ''];
        return [
            'repaired'       => ['ticket' => $reopen, 'asset' => $restore],
            'no_fault'       => ['ticket' => $reopen, 'asset' => $restore],
            'writeoff'       => [
                'ticket' => ['type' => 'keep_pending', 'reason' => 'awaiting_writeoff'],
                'asset'  => ['type' => 'set_state', 'state' => 'awaiting_writeoff'],
            ],
            'keep_defective' => [
                'ticket' => ['type' => 'keep_pending', 'reason' => 'awaiting_decision'],
                'asset'  => ['type' => 'set_state', 'state' => 'defective'],
            ],
        ];
    }

    /**
     * Completes missing keys with defaults, coerces types, drops unknown keys and repairs
     * invalid action definitions.
     *
     * @param array<string, mixed> $raw
     * @return array<string, string>
     */
    public static function normalize(array $raw): array
    {
        $out = self::defaults();

        $ids = [];
        $decoded = json_decode((string) ($raw['pre_category_ids'] ?? '[]'), true);
        if (is_array($decoded)) {
            foreach ($decoded as $value) {
                if (is_numeric($value) && (int) $value > 0) {
                    $ids[] = (int) $value;
                }
            }
        }
        $out['pre_category_ids'] = json_encode(array_values(array_unique($ids)), JSON_THROW_ON_ERROR);

        if (array_key_exists('pre_include_subcategories', $raw)) {
            $out['pre_include_subcategories'] = ((string) $raw['pre_include_subcategories']) === '1' ? '1' : '0';
        }

        $intKeys = ['pre_logo_documentcategories_id'];
        foreach (self::STATE_ROLES as $role) {
            $intKeys[] = 'pre_state_' . $role;
        }
        foreach (self::REASON_ROLES as $role) {
            $intKeys[] = 'pre_reason_' . $role;
        }
        foreach ($intKeys as $key) {
            if (array_key_exists($key, $raw)) {
                $out[$key] = (string) (is_numeric($raw[$key]) && (int) $raw[$key] > 0 ? (int) $raw[$key] : 0);
            }
        }

        $out['pre_actions'] = json_encode(self::normalizeActions($raw['pre_actions'] ?? null), JSON_THROW_ON_ERROR);

        return $out;
    }

    /** @return array<string, array{ticket: array{type: string, reason: string}, asset: array{type: string, state: string}}> */
    private static function normalizeActions(mixed $rawActions): array
    {
        $actions = self::defaultActions();
        if (!is_string($rawActions)) {
            return $actions;
        }
        $decoded = json_decode($rawActions, true);
        if (!is_array($decoded)) {
            return $actions;
        }
        foreach (self::ACTION_KEYS as $key) {
            $entry = $decoded[$key] ?? null;
            if (!is_array($entry)) {
                continue;
            }
            $ticketType = $entry['ticket']['type'] ?? null;
            $ticketReason = (string) ($entry['ticket']['reason'] ?? '');
            $assetType = $entry['asset']['type'] ?? null;
            $assetState = (string) ($entry['asset']['state'] ?? '');

            if (!in_array($ticketType, self::TICKET_ACTIONS, true) || !in_array($assetType, self::ASSET_ACTIONS, true)) {
                continue;
            }
            if ($ticketType === 'keep_pending' && !in_array($ticketReason, self::REASON_ROLES, true)) {
                continue;
            }
            if ($assetType === 'set_state' && !in_array($assetState, self::STATE_ROLES, true)) {
                continue;
            }
            $actions[$key] = [
                'ticket' => ['type' => $ticketType, 'reason' => $ticketType === 'keep_pending' ? $ticketReason : ''],
                'asset'  => ['type' => $assetType, 'state' => $assetType === 'set_state' ? $assetState : ''],
            ];
        }
        return $actions;
    }

    /** @param array<string, string> $s @return list<int> */
    public static function categoryIds(array $s): array
    {
        $decoded = json_decode($s['pre_category_ids'] ?? '[]', true);
        return is_array($decoded) ? array_values(array_map('intval', $decoded)) : [];
    }

    /** @param array<string, string> $s */
    public static function includeSubcategories(array $s): bool
    {
        return ($s['pre_include_subcategories'] ?? '1') === '1';
    }

    /** @param array<string, string> $s */
    public static function logoCategoryId(array $s): int
    {
        return (int) ($s['pre_logo_documentcategories_id'] ?? 0);
    }

    /** @param array<string, string> $s */
    public static function stateId(array $s, string $role): int
    {
        return (int) ($s['pre_state_' . $role] ?? 0);
    }

    /** @param array<string, string> $s */
    public static function reasonId(array $s, string $role): int
    {
        return (int) ($s['pre_reason_' . $role] ?? 0);
    }

    /**
     * @param array<string, string> $s
     * @return array<string, array{ticket: array{type: string, reason: string}, asset: array{type: string, state: string}}>
     */
    public static function actions(array $s): array
    {
        return self::normalizeActions($s['pre_actions'] ?? null);
    }

    /**
     * Roles the "Enviar" action always needs (spec D5): the at-supplier state and pending reason.
     *
     * @param array<string, string> $s
     * @return list<string>
     */
    public static function missingRolesForSend(array $s): array
    {
        $missing = [];
        if (self::stateId($s, 'at_supplier') === 0) {
            $missing[] = 'state:at_supplier';
        }
        if (self::reasonId($s, 'at_supplier') === 0) {
            $missing[] = 'reason:at_supplier';
        }
        return $missing;
    }

    /**
     * Roles a given return action needs, according to its configured ticket/asset actions.
     *
     * @param array<string, string> $s
     * @return list<string>
     */
    public static function missingRolesForReturn(array $s, string $actionKey): array
    {
        $action = self::actions($s)[$actionKey] ?? null;
        if ($action === null) {
            return [];
        }
        $missing = [];
        if ($action['ticket']['type'] === 'keep_pending' && self::reasonId($s, $action['ticket']['reason']) === 0) {
            $missing[] = 'reason:' . $action['ticket']['reason'];
        }
        if ($action['asset']['type'] === 'set_state' && self::stateId($s, $action['asset']['state']) === 0) {
            $missing[] = 'state:' . $action['asset']['state'];
        }
        return $missing;
    }
}
```

- [ ] **Passo 4: Implementar `src/Pre/ReturnActionResolver.php`**

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Pre;

/**
 * Turns (outcome, destination) into the concrete ticket and asset actions configured in the
 * PRE settings (spec 6.4, D8). Pure.
 */
final class ReturnActionResolver
{
    public static function actionKey(Outcome $outcome, Destination $destination): string
    {
        return match (true) {
            $outcome === Outcome::Repaired => 'repaired',
            $outcome === Outcome::NoFault => 'no_fault',
            $destination === Destination::Writeoff => 'writeoff',
            default => 'keep_defective',
        };
    }

    /**
     * @param array<string, string> $settings normalized PreSettings array
     * @return array{ticket: array{type: string, pendingreasons_id: int}, asset: array{type: string, states_id: int}}
     * @throws \InvalidArgumentException unknown action key
     * @throws \DomainException          a role needed by the configured action has no mapping
     */
    public static function resolve(string $actionKey, array $settings): array
    {
        if (!in_array($actionKey, PreSettings::ACTION_KEYS, true)) {
            throw new \InvalidArgumentException("Unknown return action key: $actionKey");
        }
        $missing = PreSettings::missingRolesForReturn($settings, $actionKey);
        if ($missing !== []) {
            throw new \DomainException('Missing configuration: ' . implode(', ', $missing));
        }

        $action = PreSettings::actions($settings)[$actionKey];
        $reasonId = $action['ticket']['type'] === 'keep_pending'
            ? PreSettings::reasonId($settings, $action['ticket']['reason'])
            : 0;
        $stateId = $action['asset']['type'] === 'set_state'
            ? PreSettings::stateId($settings, $action['asset']['state'])
            : 0;

        return [
            'ticket' => ['type' => $action['ticket']['type'], 'pendingreasons_id' => $reasonId],
            'asset'  => ['type' => $action['asset']['type'], 'states_id' => $stateId],
        ];
    }
}
```

- [ ] **Passo 5: Rodar tudo e ver passar**

```bash
/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml
```

Esperado: `OK`. O `missingRolesForReturn` do PreSettings devolve `['reason:awaiting_writeoff', 'state:awaiting_writeoff']` nessa ordem (razão antes do estado); o teste depende disso.

- [ ] **Passo 6: Commit** via `/commit`. Título sugerido: `feat(pre): add typed settings and return action resolver`.

### Tarefa 5: Esquema de banco, instalação, direitos e registro no GLPI

**Arquivos:**
- Modificar: `hook.php`, `setup.php`, `gac.xml`
- Criar: `src/Pre/Labels.php`, `src/Pre/ProfileRights.php`, `src/Pre/PreMenu.php`, `src/Pre/ConfigMenu.php`, `src/Pre/RepairProtocol.php` (só as constantes de direito e o `getRights`; o resto vem na Tarefa 6)

**Interfaces:**
- Consome: `PreSettings::defaults()` (Tarefa 4).
- Produz:
  - Tabelas: `glpi_plugin_gac_repairprotocols`, `glpi_plugin_gac_repairprotocolitems`, `glpi_plugin_gac_repairprotocolevents`, `glpi_plugin_gac_protocolsequences`
  - Direito `plugin_gac_pre` com `RepairProtocol::RIGHT_SEND = 256`, `RIGHT_RETURN = 512`, `RIGHT_REOPEN = 1024`
  - `Labels::protocolStatus(ProtocolStatus): string`, `Labels::itemStatus(ItemStatus): string`, `Labels::outcome(Outcome): string`, `Labels::destination(Destination): string`, `Labels::event(string): string`

- [ ] **Passo 1: Criar `src/Pre/Labels.php`** (textos de tela em um só lugar, todos traduzíveis)

```php
<?php

namespace GlpiPlugin\Gac\Pre;

final class Labels
{
    public static function protocolStatus(ProtocolStatus $s): string
    {
        return match ($s) {
            ProtocolStatus::Draft    => __('Rascunho', 'gac'),
            ProtocolStatus::Sent     => __('Enviado', 'gac'),
            ProtocolStatus::Partial  => __('Retorno parcial', 'gac'),
            ProtocolStatus::Closed   => __('Encerrado', 'gac'),
            ProtocolStatus::Canceled => __('Cancelado', 'gac'),
        };
    }

    public static function itemStatus(ItemStatus $s): string
    {
        return match ($s) {
            ItemStatus::PendingSend => __('Aguardando envio', 'gac'),
            ItemStatus::Sending     => __('Enviando', 'gac'),
            ItemStatus::AtSupplier  => __('Na assistência', 'gac'),
            ItemStatus::Returned    => __('Devolvida', 'gac'),
            ItemStatus::Lost        => __('Extraviada', 'gac'),
        };
    }

    public static function outcome(Outcome $o): string
    {
        return match ($o) {
            Outcome::Repaired      => __('Reparado', 'gac'),
            Outcome::NoFault       => __('Sem defeito encontrado', 'gac'),
            Outcome::Unrepairable  => __('Sem conserto', 'gac'),
            Outcome::QuoteRejected => __('Orçamento não aprovado', 'gac'),
        };
    }

    public static function destination(Destination $d): string
    {
        return match ($d) {
            Destination::Writeoff      => __('Encaminhar para baixa', 'gac'),
            Destination::KeepDefective => __('Manter com defeito', 'gac'),
            Destination::None          => __('Nenhum', 'gac'),
        };
    }

    public static function event(string $event): string
    {
        return match ($event) {
            'created'        => __('PRE criado', 'gac'),
            'sent'           => __('Envio iniciado', 'gac'),
            'send_finalized' => __('PDF de envio gerado', 'gac'),
            'line_returned'  => __('Retorno registrado', 'gac'),
            'line_lost'      => __('Linha marcada como extraviada', 'gac'),
            'line_removed'   => __('Linha removida', 'gac'),
            'line_corrected' => __('Dados de retorno corrigidos', 'gac'),
            'closed'         => __('PRE encerrado', 'gac'),
            'reopened'       => __('PRE reaberto', 'gac'),
            'canceled'       => __('PRE cancelado', 'gac'),
            default          => $event,
        };
    }
}
```

- [ ] **Passo 2: Criar `src/Pre/RepairProtocol.php` mínimo** (a classe completa é da Tarefa 6; aqui só o necessário para os direitos)

```php
<?php

namespace GlpiPlugin\Gac\Pre;

use CommonDBTM;

class RepairProtocol extends CommonDBTM
{
    public static $rightname = 'plugin_gac_pre';

    public const RIGHT_SEND   = 256;
    public const RIGHT_RETURN = 512;
    public const RIGHT_REOPEN = 1024;

    /** Explicit: the class sits in a sub-namespace, GLPI's derived name would be wrong. */
    public static function getTable($classname = null)
    {
        if ($classname !== null && $classname !== static::class) {
            return parent::getTable($classname);
        }
        return 'glpi_plugin_gac_repairprotocols';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Protocolo de Reparo de Equipamento', 'Protocolos de Reparo de Equipamento', $nb, 'gac');
    }

    public static function getIcon()
    {
        return 'ti ti-tool';
    }

    public function getRights($interface = 'central')
    {
        $values = parent::getRights($interface);
        $values[self::RIGHT_SEND]   = __('Enviar', 'gac');
        $values[self::RIGHT_RETURN] = __('Registrar retorno', 'gac');
        $values[self::RIGHT_REOPEN] = __('Reabrir', 'gac');
        return $values;
    }
}
```

- [ ] **Passo 3: Criar `src/Pre/ProfileRights.php`** (a aba em Administração > Perfis; sem ela o direito fica invisível)

```php
<?php

namespace GlpiPlugin\Gac\Pre;

use CommonGLPI;
use Html;
use Profile;
use Session;

class ProfileRights extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return __('Gac - Protocolo de Reparo', 'gac');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Profile && $item->getField('id')) {
            return self::createTabEntry(self::getTypeName());
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Profile && $item->getField('id')) {
            self::showForProfile($item);
        }
        return true;
    }

    private static function showForProfile(Profile $profile): void
    {
        $canedit = Session::haveRightsOr(Profile::$rightname, [UPDATE, CREATE, PURGE]);

        echo "<div class='spaced'>";
        if ($canedit) {
            echo "<form method='post' action='" . htmlescape($profile->getFormURL()) . "' data-track-changes='true'>";
        }

        $profile->displayRightsChoiceMatrix([
            [
                'itemtype' => RepairProtocol::class,
                'label'    => RepairProtocol::getTypeName(2),
                'field'    => RepairProtocol::$rightname,
            ],
        ], [
            'canedit'       => $canedit,
            'default_class' => 'tab_bg_2',
            'title'         => self::getTypeName(),
        ]);

        if ($canedit) {
            echo "<div class='center'>";
            echo "<input type='hidden' name='id' value='" . (int) $profile->fields['id'] . "'>";
            echo Html::submit(_sx('button', 'Save'), [
                'class' => 'btn btn-primary mt-2',
                'name'  => 'update',
                'icon'  => 'fas fa-save',
            ]);
            echo "</div>";
            Html::closeForm();
        }
        echo "</div>";
    }
}
```

- [ ] **Passo 4: Criar `src/Pre/PreMenu.php` e `src/Pre/ConfigMenu.php`**

`src/Pre/PreMenu.php`:

```php
<?php

namespace GlpiPlugin\Gac\Pre;

class PreMenu
{
    public static function getMenuName($nb = 0): string
    {
        return RepairProtocol::getTypeName(2);
    }

    public static function getMenuContent(): array
    {
        if (!RepairProtocol::canView()) {
            return [];
        }

        // GLPI's context_links template renders the "add" button whenever the key exists,
        // without checking rights, so it must only be present for users who can create.
        $links = ['search' => RepairProtocol::getSearchURL(false)];
        if (RepairProtocol::canCreate()) {
            $links['add'] = RepairProtocol::getFormURL(false);
        }

        return [
            'title' => RepairProtocol::getTypeName(2),
            'page'  => RepairProtocol::getSearchURL(false),
            'icon'  => RepairProtocol::getIcon(),
            'links' => $links,
        ];
    }
}
```

`src/Pre/ConfigMenu.php`:

```php
<?php

namespace GlpiPlugin\Gac\Pre;

use Session;

class ConfigMenu
{
    public static function getMenuName($nb = 0): string
    {
        return __('Gac', 'gac');
    }

    public static function getMenuContent(): array
    {
        if (!Session::haveRight('config', UPDATE)) {
            return [];
        }
        return [
            'title' => __('Gac', 'gac'),
            'page'  => '/plugins/gac/front/config.php',
            'icon'  => 'ti ti-settings',
        ];
    }
}
```

- [ ] **Passo 5: Substituir o conteúdo de `hook.php`** (mantenha o cabeçalho de licença existente, linhas 1 a 30, e troque o resto)

```php
use GlpiPlugin\Gac\Pre\PreSettings;
use GlpiPlugin\Gac\Pre\PreSettings;
use GlpiPlugin\Gac\Pre\RepairProtocol;

/**
 * Plugin install process. Same function runs for install and update, so every step checks
 * the current state first.
 */
function plugin_gac_install(): bool
{
    global $DB;

    $migration = new Migration(PLUGIN_GAC_VERSION);

    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $sign      = DBConnection::getDefaultPrimaryKeySignOption();

    $protocols = 'glpi_plugin_gac_repairprotocols';
    if (!$DB->tableExists($protocols)) {
        $DB->doQuery("CREATE TABLE `$protocols` (
            `id` INT {$sign} NOT NULL AUTO_INCREMENT,
            `entities_id` INT {$sign} NOT NULL DEFAULT '0',
            `number` VARCHAR(20) NOT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'draft',
            `suppliers_id` INT {$sign} NOT NULL DEFAULT '0',
            `supplier_name` VARCHAR(255) DEFAULT NULL,
            `users_id_tech` INT {$sign} NOT NULL DEFAULT '0',
            `date_issued` DATE DEFAULT NULL,
            `date_sent` TIMESTAMP NULL DEFAULT NULL,
            `date_closed` TIMESTAMP NULL DEFAULT NULL,
            `documents_id_sent` INT {$sign} NOT NULL DEFAULT '0',
            `comment` TEXT DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `number` (`number`),
            KEY `entities_id` (`entities_id`),
            KEY `status` (`status`),
            KEY `suppliers_id` (`suppliers_id`),
            KEY `users_id_tech` (`users_id_tech`),
            KEY `date_creation` (`date_creation`),
            KEY `date_mod` (`date_mod`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    }

    $items = 'glpi_plugin_gac_repairprotocolitems';
    if (!$DB->tableExists($items)) {
        $DB->doQuery("CREATE TABLE `$items` (
            `id` INT {$sign} NOT NULL AUTO_INCREMENT,
            `plugin_gac_repairprotocols_id` INT {$sign} NOT NULL DEFAULT '0',
            `tickets_id` INT {$sign} NOT NULL DEFAULT '0',
            `itemtype` VARCHAR(255) NOT NULL DEFAULT '',
            `items_id` INT {$sign} NOT NULL DEFAULT '0',
            `item_entities_id` INT {$sign} NOT NULL DEFAULT '0',
            `item_name` VARCHAR(255) DEFAULT NULL,
            `item_type_label` VARCHAR(255) DEFAULT NULL,
            `serial` VARCHAR(255) DEFAULT NULL,
            `otherserial` VARCHAR(255) DEFAULT NULL,
            `ticket_title` TEXT DEFAULT NULL,
            `ticket_observation` TEXT DEFAULT NULL,
            `description_supplier` TEXT DEFAULT NULL,
            `states_id_before` INT {$sign} DEFAULT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'pending_send',
            `outcome` VARCHAR(20) DEFAULT NULL,
            `destination` VARCHAR(20) DEFAULT NULL,
            `date_return` DATE DEFAULT NULL,
            `service_description` TEXT DEFAULT NULL,
            `cost` DECIMAL(20,4) DEFAULT NULL,
            `supplier_ref` VARCHAR(255) DEFAULT NULL,
            `warranty_until` DATE DEFAULT NULL,
            `ticketcosts_id` INT {$sign} NOT NULL DEFAULT '0',
            `lost_reason` TEXT DEFAULT NULL,
            `last_error` TEXT DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`plugin_gac_repairprotocols_id`, `tickets_id`, `itemtype`, `items_id`),
            KEY `tickets_id` (`tickets_id`),
            KEY `item` (`itemtype`, `items_id`),
            KEY `status` (`status`),
            KEY `date_mod` (`date_mod`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    }

    $events = 'glpi_plugin_gac_repairprotocolevents';
    if (!$DB->tableExists($events)) {
        $DB->doQuery("CREATE TABLE `$events` (
            `id` INT {$sign} NOT NULL AUTO_INCREMENT,
            `plugin_gac_repairprotocols_id` INT {$sign} NOT NULL DEFAULT '0',
            `plugin_gac_repairprotocolitems_id` INT {$sign} NOT NULL DEFAULT '0',
            `event` VARCHAR(30) NOT NULL,
            `users_id` INT {$sign} NOT NULL DEFAULT '0',
            `reason` TEXT DEFAULT NULL,
            `details` LONGTEXT DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `plugin_gac_repairprotocols_id` (`plugin_gac_repairprotocols_id`),
            KEY `plugin_gac_repairprotocolitems_id` (`plugin_gac_repairprotocolitems_id`),
            KEY `event` (`event`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    }

    $sequences = 'glpi_plugin_gac_protocolsequences';
    if (!$DB->tableExists($sequences)) {
        $DB->doQuery("CREATE TABLE `$sequences` (
            `year` SMALLINT UNSIGNED NOT NULL,
            `last` INT UNSIGNED NOT NULL DEFAULT '0',
            PRIMARY KEY (`year`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    }

    // Default configuration: only keys that do not exist yet, so an update never overwrites
    // what an administrator already configured.
    $current = Config::getConfigurationValues('plugin:gac');
    $missing = array_diff_key(PreSettings::defaults(), $current);
    if ($missing !== []) {
        Config::setConfigurationValues('plugin:gac', $missing);
    }

    // Profile right. addProfileRights() inserts one row per existing profile, so existence
    // must be checked by counting. Full access only for profiles that already hold the native
    // 'config' right; every other profile starts without access and an administrator grants
    // it in Administração > Perfis (aba do Gac).
    $right = RepairProtocol::$rightname;
    if (countElementsInTable(ProfileRight::getTable(), ['name' => $right]) === 0) {
        ProfileRight::addProfileRights([$right]);

        $admin_profiles = array_column(
            iterator_to_array($DB->request([
                'SELECT' => 'profiles_id',
                'FROM'   => ProfileRight::getTable(),
                'WHERE'  => ['name' => 'config', 'rights' => ['>', 0]],
            ])),
            'profiles_id'
        );
        if ($admin_profiles !== []) {
            $DB->update(
                ProfileRight::getTable(),
                ['rights' => ALLSTANDARDRIGHT
                    | RepairProtocol::RIGHT_SEND
                    | RepairProtocol::RIGHT_RETURN
                    | RepairProtocol::RIGHT_REOPEN],
                ['name' => $right, 'profiles_id' => $admin_profiles]
            );
        }
    }

    // Default list columns (users_id = 0 is the global default): the PRE number is always
    // shown by GLPI; add the status. Only on first install, never over an admin's choice.
    if (countElementsInTable('glpi_displaypreferences', ['itemtype' => RepairProtocol::class]) === 0) {
        $DB->insert('glpi_displaypreferences', [
            'itemtype' => RepairProtocol::class,
            'num'      => 3, // search option 3 = status (see RepairProtocol::rawSearchOptions())
            'rank'     => 1,
            'users_id' => 0,
        ]);
    }

    $migration->executeMigration();

    return true;
}

/**
 * Plugin uninstall process
 */
function plugin_gac_uninstall(): bool
{
    global $DB;

    foreach ([
        'glpi_plugin_gac_repairprotocols',
        'glpi_plugin_gac_repairprotocolitems',
        'glpi_plugin_gac_repairprotocolevents',
        'glpi_plugin_gac_protocolsequences',
    ] as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `$table`");
        }
    }

    $DB->delete(ProfileRight::getTable(), ['name' => RepairProtocol::$rightname]);
    $DB->delete('glpi_displaypreferences', ['itemtype' => RepairProtocol::class]);
    Config::deleteConfigurationValues('plugin:gac', array_keys(PreSettings::defaults()));

    return true;
}
```

- [ ] **Passo 6: Atualizar `setup.php`**

Depois dos três `define(...)` e antes de `plugin_init_gac`, adicione os `use`:

```php
use Glpi\Plugin\Hooks;
use GlpiPlugin\Gac\Pre\ConfigMenu;
use GlpiPlugin\Gac\Pre\PreMenu;
use GlpiPlugin\Gac\Pre\ProfileRights;
```

Suba a versão de `'0.0.1'` para `'0.1.0'` (`PLUGIN_GAC_VERSION`) e troque a função `plugin_init_gac`:

```php
function plugin_init_gac(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['gac'] = true;

    $plugin = new Plugin();
    if ($plugin->isInstalled('gac') && $plugin->isActivated('gac')) {
        // Array keys must be real $menu sectors: 'management' and 'config'.
        $PLUGIN_HOOKS[Hooks::MENU_TOADD]['gac'] = [
            'management' => PreMenu::class,
            'config' => ConfigMenu::class,
        ];

        // Gear icon on the plugin's row in Configurar > Plugins.
        $PLUGIN_HOOKS['config_page']['gac'] = 'front/config.php';

        // Value has no "public/" prefix: GLPI's router adds it for plugin assets.
        $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['gac'] = 'js/pre.js';

        // Plugin rights are invisible in Perfis unless the plugin adds its own tab.
        Plugin::registerClass(ProfileRights::class, ['addtabon' => Profile::class]);
    }
}
```

Em `plugin_version_gac()`, preencha `'license' => 'MIT'` (o `CLAUDE.md` lista `license` vazio como pendência de template).

Em `gac.xml`, troque `<num>0.0.1</num>` por `<num>0.1.0</num>`. (O arquivo `public/js/pre.js` só nasce na Tarefa 9; até lá o GLPI apenas não encontra o arquivo, sem quebrar nada.)

- [ ] **Passo 7: Lint de tudo**

```bash
for f in setup.php hook.php $(find src -name '*.php'); do /c/xampp/php/php.exe -l "$f"; done
```

Esperado: `No syntax errors detected in ...` para todos.

- [ ] **Passo 8: Instalar e ativar no GLPI local**

```bash
cd /c/Users/juliano/VSCode/glpi-xampp-dev-plugin
/c/xampp/php/php.exe bin/console plugin:install gac --username=glpi
/c/xampp/php/php.exe bin/console plugin:activate gac
/c/xampp/php/php.exe bin/console plugin:list
```

Esperado: `gac` com estado `Enabled`. (`glpi` é o login do super-admin padrão; use o seu se for outro.) Se o `install` acusar erro de SQL, o erro traz a tabela; corrija o `CREATE TABLE` correspondente e rode de novo (o `install` é idempotente).

Confirme as tabelas e o direito:

```bash
/c/xampp/mysql/bin/mysql.exe -h 127.0.0.1 -P 3307 -u glpi-dev -p glpi-dev -e "SHOW TABLES LIKE 'glpi_plugin_gac%'; SELECT name, COUNT(*) profiles, MAX(rights) max_rights FROM glpi_profilerights WHERE name='plugin_gac_pre' GROUP BY name;"
```

Esperado: as 4 tabelas e uma linha `plugin_gac_pre` com `max_rights = 1823` (31 + 256 + 512 + 1024) para o perfil administrativo. (Host, porta, usuário e banco vêm de `config/config_db.php`: neste GLPI de desenvolvimento a porta é **3307**, não a padrão; a senha também está lá.)

- [ ] **Passo 9: Verificação manual no navegador**

Atenção: os direitos do perfil são carregados **no login**. Numa sessão que já estava aberta antes da instalação, o menu e as páginas do plugin dão 403 até você trocar de perfil ou entrar de novo (o `POST /Session/ChangeProfile` com o `id` do perfil recarrega os direitos sem novo login). As páginas do plugin ficam em `/plugins/gac/front/...`.

Abra o GLPI local, entre como super-admin e confira: (a) Configurar > Plug-ins mostra "Gac" ativo com o ícone de engrenagem; (b) Administração > Perfis > (um perfil) > aba "Gac - Protocolo de Reparo" mostra a matriz com as caixas Criar, Ler, Atualizar, Purgar, **Enviar, Registrar retorno, Reabrir**; (c) o menu **Gerência** ganhou "Protocolos de Reparo de Equipamento" (a página em si ainda dá 404 até a Tarefa 6).

- [ ] **Passo 10: Commit** via `/commit`. Título sugerido: `feat(pre): add database schema, rights and plugin registration`.

---

### Tarefa 6: Objeto PRE (CRUD do rascunho, numeração, eventos, abas)

**Arquivos:**
- Modificar: `src/Pre/RepairProtocol.php`
- Criar: `src/Pre/ProtocolNumberGenerator.php`, `src/Pre/RepairProtocolItem.php`, `src/Pre/RepairProtocolEvent.php`, `templates/pre/repairprotocol.form.html.twig`, `templates/pre/items_tab.html.twig`, `templates/pre/events_tab.html.twig`, `front/pre/repairprotocol.php`, `front/pre/repairprotocol.form.php`

**Interfaces:**
- Consome: `ProtocolStatus`, `ItemStatus`, `StateMachine` (Tarefa 2), `Labels` (Tarefa 5).
- Produz:
  - `ProtocolNumberGenerator::next(?int $year = null): string`
  - `RepairProtocol::getStatus(): ProtocolStatus`
  - `RepairProtocol::changeStatus(ProtocolStatus $new, array $extra = []): void` (grava direto, sem passar por `prepareInputForUpdate`)
  - `RepairProtocol::lines(): array` — linhas do PRE como `list<array<string, mixed>>` (colunas cruas de `repairprotocolitems`)
  - `RepairProtocolItem::getStatus(): ItemStatus`
  - `RepairProtocolEvent::log(int $protocolId, string $event, string $reason = '', array $details = [], int $lineId = 0): void`
  - URLs: `RepairProtocol::getSearchURL()` → `/plugins/gac/front/pre/repairprotocol.php`; `getFormURL()` → `/plugins/gac/front/pre/repairprotocol.form.php`; `RepairProtocolItem::getFormURL()` → `/plugins/gac/front/pre/repairprotocolitem.form.php` (a classe fica em subnamespace `Pre`, por isso o GLPI deriva o caminho `front/pre/`).

- [ ] **Passo 1: Criar `src/Pre/ProtocolNumberGenerator.php`**

```php
<?php

namespace GlpiPlugin\Gac\Pre;

/**
 * Atomic per-year counter: INSERT ... ON DUPLICATE KEY UPDATE with LAST_INSERT_ID(expr)
 * makes the increment and the read one race-free step on this connection (plan decision 1).
 */
final class ProtocolNumberGenerator
{
    public static function next(?int $year = null): string
    {
        global $DB;

        $year ??= (int) date('Y');
        $DB->doQuery(sprintf(
            'INSERT INTO `glpi_plugin_gac_protocolsequences` (`year`, `last`) VALUES (%d, LAST_INSERT_ID(1))'
            . ' ON DUPLICATE KEY UPDATE `last` = LAST_INSERT_ID(`last` + 1)',
            $year
        ));
        $row = $DB->doQuery('SELECT LAST_INSERT_ID() AS seq')->fetch_assoc();

        return ProtocolNumber::format($year, (int) $row['seq']);
    }
}
```

- [ ] **Passo 2: Criar `src/Pre/RepairProtocolEvent.php`**

```php
<?php

namespace GlpiPlugin\Gac\Pre;

use CommonDBChild;
use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use Session;

/**
 * Event log of a PRE (spec 5.2.1). Also the tab "Histórico".
 */
class RepairProtocolEvent extends CommonDBChild
{
    public static $itemtype = RepairProtocol::class;
    public static $items_id = 'plugin_gac_repairprotocols_id';
    public $dohistory       = false;

    /** The events tab is the PRE's own history: do not mirror each event into GLPI's native log. */
    public static $logs_for_parent = false;

    public static function getTable($classname = null)
    {
        if ($classname !== null && $classname !== static::class) {
            return parent::getTable($classname);
        }
        return 'glpi_plugin_gac_repairprotocolevents';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Evento', 'Eventos', $nb, 'gac');
    }

    public static function log(
        int $protocolId,
        string $event,
        string $reason = '',
        array $details = [],
        int $lineId = 0
    ): void {
        (new self())->add([
            'plugin_gac_repairprotocols_id'     => $protocolId,
            'plugin_gac_repairprotocolitems_id' => $lineId,
            'event'                             => $event,
            'users_id'                          => (int) Session::getLoginUserID(),
            'reason'                            => $reason,
            'details'                           => $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof RepairProtocol) {
            return self::createTabEntry(__('Histórico', 'gac'));
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof RepairProtocol) {
            return false;
        }

        global $DB;
        $rows = [];
        foreach ($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['plugin_gac_repairprotocols_id' => $item->getID()],
            'ORDER' => ['id DESC'],
        ]) as $row) {
            $rows[] = [
                'date'   => $row['date_creation'],
                'user'   => getUserName((int) $row['users_id']),
                'event'  => Labels::event($row['event']),
                'line'   => (int) $row['plugin_gac_repairprotocolitems_id'],
                'reason' => (string) $row['reason'],
            ];
        }

        TemplateRenderer::getInstance()->display('@gac/pre/events_tab.html.twig', ['events' => $rows]);
        return true;
    }
}
```

- [ ] **Passo 3: Criar `templates/pre/events_tab.html.twig`**

```twig
<div class="table-responsive">
    <table class="table table-hover card-table">
        <thead>
            <tr>
                <th>{{ __('Data', 'gac') }}</th>
                <th>{{ __('Usuário', 'gac') }}</th>
                <th>{{ __('Evento', 'gac') }}</th>
                <th>{{ __('Linha', 'gac') }}</th>
                <th>{{ __('Motivo', 'gac') }}</th>
            </tr>
        </thead>
        <tbody>
            {% for e in events %}
                <tr>
                    <td>{{ e.date|formatted_datetime }}</td>
                    <td>{{ e.user }}</td>
                    <td>{{ e.event }}</td>
                    <td>{% if e.line > 0 %}#{{ e.line }}{% endif %}</td>
                    <td>{{ e.reason }}</td>
                </tr>
            {% else %}
                <tr><td colspan="5" class="text-center text-muted">{{ __('Nenhum evento registrado.', 'gac') }}</td></tr>
            {% endfor %}
        </tbody>
    </table>
</div>
```

- [ ] **Passo 4: Criar `src/Pre/RepairProtocolItem.php`** (nesta tarefa: só a listagem; importar, editar e retornar entram nas tarefas 8 a 10)

```php
<?php

namespace GlpiPlugin\Gac\Pre;

use CommonDBChild;
use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use Ticket;

class RepairProtocolItem extends CommonDBChild
{
    public static $itemtype = RepairProtocol::class;
    public static $items_id = 'plugin_gac_repairprotocols_id';
    public static $rightname = 'plugin_gac_pre';
    public $dohistory       = false;

    /**
     * The line has no "name" column; without this GLPI's native history logs the addition or
     * removal of a line as "Item (N/A (id))".
     */
    public function getName($options = [])
    {
        if (empty($this->fields['tickets_id'])) {
            return parent::getName($options);
        }
        return sprintf('#%d · %s', $this->fields['tickets_id'], $this->fields['item_name'] ?? '');
    }

    public static function getTable($classname = null)
    {
        if ($classname !== null && $classname !== static::class) {
            return parent::getTable($classname);
        }
        return 'glpi_plugin_gac_repairprotocolitems';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Item', 'Itens', $nb, 'gac');
    }

    public function getStatus(): ItemStatus
    {
        return ItemStatus::from($this->fields['status']);
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof RepairProtocol) {
            $count = countElementsInTable(self::getTable(), ['plugin_gac_repairprotocols_id' => $item->getID()]);
            return self::createTabEntry(__('Itens', 'gac'), $count);
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof RepairProtocol) {
            return false;
        }
        self::showForProtocol($item);
        return true;
    }

    public static function showForProtocol(RepairProtocol $protocol): void
    {
        $lines = [];
        foreach ($protocol->lines() as $row) {
            $lines[] = $row + [
                'ticket_url'   => Ticket::getFormURLWithID((int) $row['tickets_id']),
                'status_label' => Labels::itemStatus(ItemStatus::from($row['status'])),
            ];
        }

        TemplateRenderer::getInstance()->display('@gac/pre/items_tab.html.twig', [
            'protocol' => $protocol,
            'lines'    => $lines,
        ]);
    }
}
```

- [ ] **Passo 5: Criar `templates/pre/items_tab.html.twig`**

```twig
<div class="table-responsive">
    <table class="table table-hover card-table">
        <thead>
            <tr>
                <th>{{ __('Ticket', 'gac') }}</th>
                <th>{{ __('Tipo', 'gac') }}</th>
                <th>{{ __('Equipamento', 'gac') }}</th>
                <th>{{ __('Patrimônio', 'gac') }}</th>
                <th>{{ __('Nº de série', 'gac') }}</th>
                <th>{{ __('Descrição para o fornecedor', 'gac') }}</th>
                <th>{{ __('Situação', 'gac') }}</th>
            </tr>
        </thead>
        <tbody>
            {% for line in lines %}
                <tr>
                    <td><a href="{{ line.ticket_url }}">#{{ line.tickets_id }}</a></td>
                    <td>{{ line.item_type_label }}</td>
                    <td>{{ line.item_name }}</td>
                    <td>{{ line.otherserial|default('—') }}</td>
                    <td>{{ line.serial|default('—') }}</td>
                    <td>{{ line.description_supplier|default('—') }}</td>
                    <td>{{ line.status_label }}</td>
                </tr>
            {% else %}
                <tr><td colspan="7" class="text-center text-muted">{{ __('Nenhum item neste protocolo.', 'gac') }}</td></tr>
            {% endfor %}
        </tbody>
    </table>
</div>
```

- [ ] **Passo 6: Completar `src/Pre/RepairProtocol.php`** (substitui o conteúdo da Tarefa 5)

```php
<?php

namespace GlpiPlugin\Gac\Pre;

use CommonDBTM;
use CommonGLPI;
use Entity;
use Glpi\Application\View\TemplateRenderer;
use Session;
use Supplier;

class RepairProtocol extends CommonDBTM
{
    public static $rightname = 'plugin_gac_pre';
    public $dohistory        = true;

    public const RIGHT_SEND   = 256;
    public const RIGHT_RETURN = 512;
    public const RIGHT_REOPEN = 1024;

    /** Explicit: the class sits in a sub-namespace, GLPI's derived name would be wrong. */
    public static function getTable($classname = null)
    {
        if ($classname !== null && $classname !== static::class) {
            return parent::getTable($classname);
        }
        return 'glpi_plugin_gac_repairprotocols';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Protocolo de Reparo de Equipamento', 'Protocolos de Reparo de Equipamento', $nb, 'gac');
    }

    /** The PRE has no "name" column: the number is what identifies it in titles and logs. */
    public static function getNameField()
    {
        return 'number';
    }

    public static function getIcon()
    {
        return 'ti ti-tool';
    }

    public function getRights($interface = 'central')
    {
        $values = parent::getRights($interface);
        $values[self::RIGHT_SEND]   = __('Enviar', 'gac');
        $values[self::RIGHT_RETURN] = __('Registrar retorno', 'gac');
        $values[self::RIGHT_REOPEN] = __('Reabrir', 'gac');
        return $values;
    }

    public function getStatus(): ProtocolStatus
    {
        return ProtocolStatus::from($this->fields['status']);
    }

    /** The header form is editable only while the PRE is a draft (spec 6.1). */
    public function canUpdateItem(): bool
    {
        return parent::canUpdateItem() && ($this->isNewItem() || $this->getStatus() === ProtocolStatus::Draft);
    }

    /** Only drafts and canceled PREs can be purged. */
    public function canPurgeItem(): bool
    {
        return parent::canPurgeItem()
            && in_array($this->getStatus(), [ProtocolStatus::Draft, ProtocolStatus::Canceled], true);
    }

    public function prepareInputForAdd($input)
    {
        if (empty($input['suppliers_id'])) {
            Session::addMessageAfterRedirect(__('Informe o fornecedor.', 'gac'), false, ERROR);
            return false;
        }

        $input['status']        = ProtocolStatus::Draft->value;
        $input['number']        = ProtocolNumberGenerator::next();
        $input['supplier_name'] = self::supplierName((int) $input['suppliers_id']);
        $input['date_issued']   = !empty($input['date_issued']) ? $input['date_issued'] : date('Y-m-d');
        if (empty($input['users_id_tech'])) {
            $input['users_id_tech'] = (int) Session::getLoginUserID();
        }
        if (!isset($input['entities_id'])) {
            $input['entities_id'] = (int) Session::getActiveEntity();
        }

        return $input;
    }

    public function post_addItem()
    {
        RepairProtocolEvent::log((int) $this->getID(), 'created');
        parent::post_addItem();
    }

    public function prepareInputForUpdate($input)
    {
        // These are never edited from the form: they change only through services.
        unset(
            $input['number'],
            $input['status'],
            $input['entities_id'],
            $input['documents_id_sent'],
            $input['date_sent'],
            $input['date_closed'],
            $input['supplier_name']
        );

        if (!empty($input['suppliers_id'])) {
            $input['supplier_name'] = self::supplierName((int) $input['suppliers_id']);
        }

        return $input;
    }

    /** Direct status write for services; not exposed to form input on purpose. */
    public function changeStatus(ProtocolStatus $new, array $extra = []): void
    {
        global $DB;

        $DB->update(
            self::getTable(),
            ['status' => $new->value, 'date_mod' => $_SESSION['glpi_currenttime']] + $extra,
            ['id' => $this->getID()]
        );
        $this->getFromDB($this->getID());
    }

    /** @return list<array<string, mixed>> */
    public function lines(): array
    {
        global $DB;

        $rows = [];
        foreach ($DB->request([
            'FROM'  => RepairProtocolItem::getTable(),
            'WHERE' => ['plugin_gac_repairprotocols_id' => $this->getID()],
            'ORDER' => ['id ASC'],
        ]) as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    private static function supplierName(int $suppliersId): string
    {
        $supplier = new Supplier();
        return $supplier->getFromDB($suppliersId) ? (string) $supplier->fields['name'] : '';
    }

    public function cleanDBonPurge()
    {
        $this->deleteChildrenAndRelationsFromDb([
            RepairProtocolItem::class,
            RepairProtocolEvent::class,
        ]);
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs);
        $this->addStandardTab(RepairProtocolItem::class, $tabs, $options);
        $this->addStandardTab(RepairProtocolEvent::class, $tabs, $options);
        $this->addStandardTab('Log', $tabs, $options);
        return $tabs;
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);

        TemplateRenderer::getInstance()->display('@gac/pre/repairprotocol.form.html.twig', [
            'item'           => $this,
            'params'         => $options,
            'status_label'   => $this->isNewItem()
                ? Labels::protocolStatus(ProtocolStatus::Draft)
                : Labels::protocolStatus($this->getStatus()),
            'header_editable' => $this->canUpdateItem() || $this->isNewItem(),
        ]);

        return true;
    }

    public function rawSearchOptions()
    {
        $t = self::getTable();
        $options = [
            ['id' => 'common', 'name' => self::getTypeName(2)],
            [
                'id' => 1, 'table' => $t, 'field' => 'number', 'name' => __('Número', 'gac'),
                // Without 'itemtype' GLPI maps the table back to a class, which fails for a
                // class in a sub-namespace (same reason getTable() is overridden).
                'datatype' => 'itemlink', 'itemtype' => self::class, 'massiveaction' => false, 'autocomplete' => true,
            ],
            ['id' => 2, 'table' => $t, 'field' => 'id', 'name' => __('ID'), 'datatype' => 'number', 'massiveaction' => false],
            [
                'id' => 3, 'table' => $t, 'field' => 'status', 'name' => __('Status'),
                'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false,
            ],
            [
                'id' => 4, 'table' => 'glpi_suppliers', 'field' => 'name', 'linkfield' => 'suppliers_id',
                'name' => __('Fornecedor', 'gac'), 'datatype' => 'dropdown', 'massiveaction' => false,
            ],
            [
                'id' => 5, 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id_tech',
                'name' => __('Técnico responsável', 'gac'), 'datatype' => 'dropdown', 'massiveaction' => false,
            ],
            ['id' => 6, 'table' => $t, 'field' => 'date_issued', 'name' => __('Data de emissão', 'gac'), 'datatype' => 'date', 'massiveaction' => false],
            ['id' => 7, 'table' => $t, 'field' => 'date_sent', 'name' => __('Data de envio', 'gac'), 'datatype' => 'datetime', 'massiveaction' => false],
            ['id' => 8, 'table' => $t, 'field' => 'date_closed', 'name' => __('Data de encerramento', 'gac'), 'datatype' => 'datetime', 'massiveaction' => false],
            [
                'id' => 80, 'table' => 'glpi_entities', 'field' => 'completename',
                'name' => Entity::getTypeName(1), 'datatype' => 'dropdown', 'massiveaction' => false,
            ],
        ];

        // GLPI maps a column's table back to a class to render it, which fails for a class in a
        // sub-namespace (same reason getTable() is overridden): declare the itemtype on every
        // column that belongs to the PRE table, not only on the number.
        foreach ($options as &$option) {
            if (($option['table'] ?? null) === $t && !isset($option['itemtype'])) {
                $option['itemtype'] = self::class;
            }
        }
        unset($option);

        return $options;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        if ($field === 'status') {
            $status = ProtocolStatus::tryFrom((string) $values[$field]);
            return $status === null ? '' : Labels::protocolStatus($status);
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        if ($field === 'status') {
            $options['display'] = false;
            $options['value']   = $values[$field];
            $choices = [];
            foreach (ProtocolStatus::cases() as $status) {
                $choices[$status->value] = Labels::protocolStatus($status);
            }
            return \Dropdown::showFromArray($name, $choices, $options);
        }
        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }
}
```

- [ ] **Passo 7: Criar `templates/pre/repairprotocol.form.html.twig`**

```twig
{% extends 'generic_show_form.html.twig' %}
{% import 'components/form/fields_macros.html.twig' as fields %}

{% block form_fields %}
    {{ fields.readOnlyField('number_display', item.fields['number']|default(__('(gerado ao salvar)', 'gac')), __('Número', 'gac')) }}
    {{ fields.readOnlyField('status_display', status_label, __('Status')) }}

    {{ fields.dropdownField('Supplier', 'suppliers_id', item.fields['suppliers_id'], __('Fornecedor', 'gac'), {
        entity: item.fields['entities_id'],
        entity_sons: true,
        required: true,
        readonly: not header_editable,
    }) }}

    {{ fields.dropdownField('User', 'users_id_tech', item.fields['users_id_tech'], __('Técnico responsável', 'gac'), {
        entity: item.fields['entities_id'],
        right: 'all',
        readonly: not header_editable,
    }) }}

    {{ fields.dateField('date_issued', item.fields['date_issued'], __('Data de emissão', 'gac'), {
        disabled: not header_editable,
    }) }}

    {{ fields.textareaField('comment', item.fields['comment'], __('Comentários')) }}
{% endblock %}
```

- [ ] **Passo 8: Criar `front/pre/repairprotocol.php` (lista)**

```php
<?php

include('../../../../inc/includes.php');

use GlpiPlugin\Gac\Pre\PreMenu;
use GlpiPlugin\Gac\Pre\RepairProtocol;

if (!RepairProtocol::canView()) {
    Html::displayRightError();
}

Html::header(
    RepairProtocol::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'management',
    strtolower(PreMenu::class)
);

Search::show(RepairProtocol::class);

Html::footer();
```

- [ ] **Passo 9: Criar `front/pre/repairprotocol.form.php` (CRUD do cabeçalho e cancelamento)**

```php
<?php

include('../../../../inc/includes.php');

use GlpiPlugin\Gac\Pre\PreMenu;
use GlpiPlugin\Gac\Pre\ProtocolStatus;
use GlpiPlugin\Gac\Pre\RepairProtocol;
use GlpiPlugin\Gac\Pre\RepairProtocolEvent;
use GlpiPlugin\Gac\Pre\StateMachine;

$item = new RepairProtocol();

if (isset($_POST['add'])) {
    $item->check(-1, CREATE, $_POST);
    $newid = $item->add($_POST);
    if ($newid) {
        Html::redirect(RepairProtocol::getFormURLWithID($newid));
    }
    Html::back();
} elseif (isset($_POST['update'])) {
    $item->check($_POST['id'], UPDATE);
    $item->update($_POST);
    Html::back();
} elseif (isset($_POST['cancel_protocol'])) {
    $item->check($_POST['id'], UPDATE);
    if (StateMachine::canCancel($item->getStatus())) {
        $item->changeStatus(ProtocolStatus::Canceled);
        RepairProtocolEvent::log((int) $item->getID(), 'canceled');
    } else {
        Session::addMessageAfterRedirect(__('Só é possível cancelar um PRE em rascunho.', 'gac'), false, ERROR);
    }
    Html::back();
} elseif (isset($_POST['purge'])) {
    $item->check($_POST['id'], PURGE);
    $item->delete($_POST, 1);
    $item->redirectToList();
} else {
    Html::header(
        RepairProtocol::getTypeName(1),
        $_SERVER['PHP_SELF'],
        'management',
        strtolower(PreMenu::class)
    );
    $item->display(['id' => $_GET['id'] ?? -1]);
    Html::footer();
}
```

- [ ] **Passo 10: Lint e verificação manual**

```bash
for f in $(find src front -name '*.php'); do /c/xampp/php/php.exe -l "$f"; done
```

No navegador (GLPI local, super-admin): (a) Gerência > Protocolos de Reparo de Equipamento abre a lista vazia; (b) "+ Adicionar" abre o formulário; escolha um fornecedor e salve; o PRE nasce como `PRE-2026-001`, status Rascunho, com as abas Itens (vazia), Histórico (1 evento "PRE criado") e Histórico do GLPI; (c) crie um segundo PRE e confira `PRE-2026-002`; (d) sem fornecedor, o salvamento é recusado com a mensagem "Informe o fornecedor."; (e) o botão de purgar exclui um rascunho.

- [ ] **Passo 11: Commit** via `/commit`. Título sugerido: `feat(pre): add repair protocol object with numbering, events and tabs`.

---

### Tarefa 7: Tela de configuração (seções por módulo)

**Arquivos:**
- Criar: `src/ConfigSection.php`, `src/Config.php`, `src/Pre/PreConfig.php`, `src/Pre/PreConfigSection.php`, `front/config.php`

**Interfaces:**
- Consome: `PreSettings` (Tarefa 4), `ConfigMenu` (Tarefa 5).
- Produz:
  - `GlpiPlugin\Gac\ConfigSection` (interface): `key(): string`, `title(): string`, `render(): string`, `handlePost(array $post): void`
  - `GlpiPlugin\Gac\Config::sections(): array` (`list<ConfigSection>`), `Config::renderPage(): void`, `Config::handlePost(array $post): void`
  - `PreConfig::load(): array` (o array normalizado de `PreSettings`), `PreConfig::save(array $raw): void`

- [ ] **Passo 1: Criar `src/ConfigSection.php`**

```php
<?php

namespace GlpiPlugin\Gac;

/**
 * One module's block on the plugin configuration page (spec D16). A future module adds its
 * own implementation and registers it in Config::sections().
 */
interface ConfigSection
{
    /** Stable identifier, used as the hidden "section" field of the form. */
    public function key(): string;

    public function title(): string;

    /** HTML of the section body (form fields only; the page provides <form> and Save). */
    public function render(): string;

    /** @param array<string, mixed> $post */
    public function handlePost(array $post): void;
}
```

- [ ] **Passo 2: Criar `src/Config.php`**

```php
<?php

namespace GlpiPlugin\Gac;

use GlpiPlugin\Gac\Pre\PreConfigSection;
use Html;

/**
 * Builds the plugin configuration page. Knows nothing about any module's keys: it only
 * walks the registered sections.
 */
final class Config
{
    /** @return list<ConfigSection> */
    public static function sections(): array
    {
        return [
            new PreConfigSection(),
        ];
    }

    public static function handlePost(array $post): void
    {
        foreach (self::sections() as $section) {
            if (($post['section'] ?? '') === $section->key()) {
                $section->handlePost($post);
                return;
            }
        }
    }

    public static function renderPage(): void
    {
        echo "<div class='container-fluid'>";
        foreach (self::sections() as $section) {
            echo "<form method='post' action='" . htmlescape(self::pageUrl()) . "' class='card mb-4'>";
            echo "<div class='card-header'><h3 class='card-title'>" . htmlescape($section->title()) . '</h3></div>';
            echo "<div class='card-body'>";
            echo $section->render();
            echo '</div>';
            echo "<div class='card-footer'>";
            echo "<input type='hidden' name='section' value='" . htmlescape($section->key()) . "'>";
            echo Html::submit(_sx('button', 'Save'), ['class' => 'btn btn-primary', 'name' => 'save', 'icon' => 'ti ti-device-floppy']);
            echo '</div>';
            Html::closeForm();
        }
        echo '</div>';
    }

    private static function pageUrl(): string
    {
        global $CFG_GLPI;
        return $CFG_GLPI['root_doc'] . '/plugins/gac/front/config.php';
    }
}
```

- [ ] **Passo 3: Criar `src/Pre/PreConfig.php`**

```php
<?php

namespace GlpiPlugin\Gac\Pre;

/**
 * Storage of the PRE settings: glpi_configs, context plugin:gac, keys prefixed pre_.
 */
final class PreConfig
{
    public const CONTEXT = 'plugin:gac';

    /** @return array<string, string> normalized settings (see PreSettings) */
    public static function load(): array
    {
        return PreSettings::normalize(\Config::getConfigurationValues(self::CONTEXT));
    }

    /** @param array<string, mixed> $raw */
    public static function save(array $raw): void
    {
        \Config::setConfigurationValues(self::CONTEXT, PreSettings::normalize($raw));
    }
}
```

- [ ] **Passo 4: Criar `src/Pre/PreConfigSection.php`**

```php
<?php

namespace GlpiPlugin\Gac\Pre;

use Dropdown;
use DocumentCategory;
use GlpiPlugin\Gac\ConfigSection;
use ITILCategory;
use PendingReason;
use Session;
use State;

final class PreConfigSection implements ConfigSection
{
    public function key(): string
    {
        return 'pre';
    }

    public function title(): string
    {
        return __('Protocolo de Reparo de Equipamentos', 'gac');
    }

    private static function stateRoleLabels(): array
    {
        return [
            'at_supplier'       => __('Ativo na assistência', 'gac'),
            'defective'         => __('Ativo com defeito', 'gac'),
            'awaiting_writeoff' => __('Ativo aguardando baixa', 'gac'),
        ];
    }

    private static function reasonRoleLabels(): array
    {
        return [
            'at_supplier'       => __('Ticket na assistência', 'gac'),
            'awaiting_writeoff' => __('Aguardando baixa patrimonial', 'gac'),
            'awaiting_decision' => __('Aguardando decisão', 'gac'),
        ];
    }

    public function render(): string
    {
        $s = PreConfig::load();
        $out = '';

        $missing = PreSettings::missingRolesForSend($s);
        foreach (PreSettings::ACTION_KEYS as $key) {
            $missing = array_merge($missing, PreSettings::missingRolesForReturn($s, $key));
        }
        $missing = array_values(array_unique($missing));
        if ($missing !== []) {
            $out .= "<div class='alert alert-warning'>"
                . htmlescape(__('Mapeamentos obrigatórios ainda não configurados. O envio fica bloqueado até preenchê-los:', 'gac'))
                . ' <strong>' . htmlescape(implode(', ', $missing)) . '</strong></div>';
        }

        // 1. Eligible categories
        $out .= "<h4>" . htmlescape(__('Tickets elegíveis', 'gac')) . '</h4>';
        $out .= $this->row(__('Categorias ITIL elegíveis', 'gac'), Dropdown::show(ITILCategory::class, [
            // Multiple dropdowns need the explicit "[]" in the name, and take the selected
            // values through "value" (Dropdown::show turns it into "values" itself).
            'name'     => 'pre_category_ids[]',
            'value'    => PreSettings::categoryIds($s),
            'multiple' => true,
            'display'  => false,
        ]));
        $out .= $this->row(__('Incluir subcategorias', 'gac'), Dropdown::showYesNo(
            'pre_include_subcategories',
            PreSettings::includeSubcategories($s) ? 1 : 0,
            -1,
            ['display' => false]
        ));

        // 2. Logo
        $out .= "<h4 class='mt-4'>" . htmlescape(__('Relatório', 'gac')) . '</h4>';
        $out .= $this->row(__('Categoria de documento da logomarca', 'gac'), DocumentCategory::dropdown([
            'name'    => 'pre_logo_documentcategories_id',
            'value'   => PreSettings::logoCategoryId($s),
            'display' => false,
        ]));

        // 3. State roles
        $out .= "<h4 class='mt-4'>" . htmlescape(__('Status do ativo', 'gac')) . '</h4>';
        $out .= "<p class='text-muted'>" . htmlescape(__('Escolha um status existente ou crie um novo com o botão + do campo. Crie-os na entidade raiz, com recursividade ligada, para valerem em todas as entidades. Nenhum status é criado sem a sua ação.', 'gac')) . '</p>';
        foreach (self::stateRoleLabels() as $role => $label) {
            $out .= $this->row($label, Dropdown::show(State::class, [
                'name'    => 'pre_state_' . $role,
                'value'   => PreSettings::stateId($s, $role),
                'display' => false,
            ]));
        }

        // 4. Pending reason roles
        $out .= "<h4 class='mt-4'>" . htmlescape(__('Motivos de pendência do ticket', 'gac')) . '</h4>';
        $out .= "<p class='text-muted'>" . htmlescape(__('Escolha um motivo existente ou crie um novo com o botão + do campo.', 'gac')) . '</p>';
        foreach (self::reasonRoleLabels() as $role => $label) {
            $out .= $this->row($label, Dropdown::show(PendingReason::class, [
                'name'    => 'pre_reason_' . $role,
                'value'   => PreSettings::reasonId($s, $role),
                'display' => false,
            ]));
        }

        // 5. Actions per outcome
        $out .= "<h4 class='mt-4'>" . htmlescape(__('Ações no retorno', 'gac')) . '</h4>';
        $out .= "<div class='table-responsive'><table class='table'><thead><tr><th>"
            . htmlescape(__('Resultado', 'gac')) . '</th><th>' . htmlescape(__('Ticket', 'gac')) . '</th><th>'
            . htmlescape(__('Motivo (se pendente)', 'gac')) . '</th><th>' . htmlescape(__('Ativo', 'gac')) . '</th><th>'
            . htmlescape(__('Status (se definir)', 'gac')) . '</th></tr></thead><tbody>';
        $actionLabels = [
            'repaired'       => __('Reparado', 'gac'),
            'no_fault'       => __('Sem defeito encontrado', 'gac'),
            'writeoff'       => __('Com defeito → baixa', 'gac'),
            'keep_defective' => __('Com defeito → manter', 'gac'),
        ];
        $ticketOptions = [
            'reopen'       => __('Reabrir (Em atendimento)', 'gac'),
            'solve'        => __('Solucionar', 'gac'),
            'keep_pending' => __('Manter pendente', 'gac'),
        ];
        $assetOptions = [
            'restore_previous' => __('Restaurar status anterior', 'gac'),
            'set_state'        => __('Definir status', 'gac'),
        ];
        $actions = PreSettings::actions($s);
        foreach (PreSettings::ACTION_KEYS as $key) {
            $a = $actions[$key];
            $out .= '<tr><td>' . htmlescape($actionLabels[$key]) . '</td>';
            $out .= '<td>' . Dropdown::showFromArray("pre_actions[$key][ticket][type]", $ticketOptions, ['value' => $a['ticket']['type'], 'display' => false]) . '</td>';
            $out .= '<td>' . Dropdown::showFromArray("pre_actions[$key][ticket][reason]", ['' => Dropdown::EMPTY_VALUE] + self::reasonRoleLabels(), ['value' => $a['ticket']['reason'], 'display' => false]) . '</td>';
            $out .= '<td>' . Dropdown::showFromArray("pre_actions[$key][asset][type]", $assetOptions, ['value' => $a['asset']['type'], 'display' => false]) . '</td>';
            $out .= '<td>' . Dropdown::showFromArray("pre_actions[$key][asset][state]", ['' => Dropdown::EMPTY_VALUE] + self::stateRoleLabels(), ['value' => $a['asset']['state'], 'display' => false]) . '</td></tr>';
        }
        $out .= '</tbody></table></div>';

        return $out;
    }

    private function row(string $label, string $control): string
    {
        return "<div class='row mb-3'><label class='col-sm-4 col-form-label'>" . htmlescape($label)
            . "</label><div class='col-sm-8'>" . $control . '</div></div>';
    }

    public function handlePost(array $post): void
    {
        if (!Session::haveRight('config', UPDATE)) {
            return;
        }

        $raw = PreConfig::load();

        $ids = [];
        foreach ((array) ($post['pre_category_ids'] ?? []) as $id) {
            $ids[] = (int) $id;
        }
        $raw['pre_category_ids'] = json_encode($ids);
        $raw['pre_include_subcategories'] = (string) (int) ($post['pre_include_subcategories'] ?? 1);
        $raw['pre_logo_documentcategories_id'] = (string) (int) ($post['pre_logo_documentcategories_id'] ?? 0);

        foreach (PreSettings::STATE_ROLES as $role) {
            $raw['pre_state_' . $role] = (string) (int) ($post['pre_state_' . $role] ?? 0);
        }
        foreach (PreSettings::REASON_ROLES as $role) {
            $raw['pre_reason_' . $role] = (string) (int) ($post['pre_reason_' . $role] ?? 0);
        }

        $raw['pre_actions'] = json_encode($post['pre_actions'] ?? []);

        PreConfig::save($raw);
        Session::addMessageAfterRedirect(__('Configuração do PRE salva.', 'gac'));
    }
}
```

- [ ] **Passo 5: Criar `front/config.php`**

```php
<?php

include('../../../inc/includes.php');

use GlpiPlugin\Gac\Config;
use GlpiPlugin\Gac\Pre\ConfigMenu;

Session::checkRight('config', UPDATE);

if (!empty($_POST)) {
    Config::handlePost($_POST);
    Html::back();
}

Html::header(
    __('Gac', 'gac'),
    $_SERVER['PHP_SELF'],
    'config',
    strtolower(ConfigMenu::class)
);

Config::renderPage();

Html::footer();
```

- [ ] **Passo 6: Lint e verificação manual**

```bash
for f in $(find src front -name '*.php'); do /c/xampp/php/php.exe -l "$f"; done
```

No navegador: (a) o ícone de engrenagem do Gac abre a página com uma única seção "Protocolo de Reparo de Equipamentos" e o aviso amarelo de mapeamentos pendentes; (b) escolha duas categorias ITIL, marque "Incluir subcategorias" = Sim, salve, recarregue e confira que os valores persistiram; (c) no campo "Ativo na assistência", use o botão **+** do próprio campo para criar o status `Na assistência` (o modal do GLPI deixa você escolher entidade e recursividade), salve, e confira que ele já aparece selecionado. Os motivos de pendência funcionam do mesmo jeito (botão **+** do campo); (d) mapeie os outros papéis e o aviso amarelo desaparece; (e) troque a ação de "Reparado" para "Solucionar", salve e recarregue: a escolha persiste; (f) um usuário sem o direito `config` não vê o item de menu nem abre a URL.

- [ ] **Passo 7: Commit** via `/commit`. Título sugerido: `feat(pre): add plugin configuration page with per-module sections`.

### Tarefa 8: Tickets elegíveis, importação e edição do rascunho

**Arquivos:**
- Criar: `src/Pre/ServiceResult.php`, `src/Pre/EligibleTicketFinder.php`, `src/Pre/LineService.php`, `front/pre/repairprotocolitem.form.php`, `templates/pre/items_import.html.twig`
- Modificar: `src/Pre/RepairProtocolItem.php` (`showForProtocol`), `templates/pre/items_tab.html.twig`, `front/pre/repairprotocol.form.php` (cancelamento apaga as linhas)
- Testar: `tests/Unit/ServiceResultTest.php`

**Interfaces:**
- Consome: `PreConfig::load()`, `PreSettings::categoryIds/includeSubcategories`, `TicketObservationExtractor::extract` (Tarefas 3, 4, 7), `StateMachine::canImportLines/canRemoveLine`, `RepairProtocol::lines()/getStatus()/canUpdateItem()` (Tarefas 2, 6).
- Produz:
  - `ServiceResult::ok(string $message = '', array $data = []): self`, `ServiceResult::fail(string $message, array $data = []): self`, propriedades `bool $ok`, `string $message`, `array $data`, `toArray(): array` (`['success' => bool, 'message' => string, 'data' => array]`)
  - `EligibleTicketFinder::find(RepairProtocol $protocol): array` — `list<array>` com as chaves `key` (`"<tickets_id>|<itemtype>|<items_id>"`), `tickets_id`, `ticket_name`, `itemtype`, `items_id`, `item_name`, `item_type_label`, `serial`, `otherserial`, `item_entities_id`, `ticket_observation`, `description_supplier`
  - `LineService::import(RepairProtocol $p, array $selectedKeys): ServiceResult`
  - `LineService::saveDescriptions(RepairProtocol $p, array $descriptions): ServiceResult` (`$descriptions` é `array<int, string>` indexado pelo id da linha)
  - `LineService::removeDraftLine(RepairProtocol $p, int $lineId): ServiceResult`
  - `LineService::deleteAllLines(RepairProtocol $p): void`
  - Variáveis Twig de `items_tab.html.twig` (usadas também pelas tarefas 9 a 11): `protocol`, `lines`, `is_draft`, `can_edit`, `can_send`, `send_mode` (`'start'|'continue'|'finalize'|''`), `pending_line_ids`, `can_return`, `can_reopen`, `is_reopened`, `candidates`, `form_url`, `protocol_form_url`, `ajax_send_url`, `outcome_options`, `destination_options`
  - Includes com `ignore missing` no template base: `items_toolbar.html.twig`, `items_import.html.twig`, `line_actions.html.twig`, `items_return_forms.html.twig`

- [ ] **Passo 1: Teste que falha para `ServiceResult`** — `tests/Unit/ServiceResultTest.php`

```php
<?php

namespace GlpiPlugin\Gac\Tests\Unit;

use GlpiPlugin\Gac\Pre\ServiceResult;
use PHPUnit\Framework\TestCase;

final class ServiceResultTest extends TestCase
{
    public function testOk(): void
    {
        $r = ServiceResult::ok('feito', ['n' => 2]);
        $this->assertTrue($r->ok);
        $this->assertSame(['success' => true, 'message' => 'feito', 'data' => ['n' => 2]], $r->toArray());
    }

    public function testFail(): void
    {
        $r = ServiceResult::fail('erro');
        $this->assertFalse($r->ok);
        $this->assertSame(['success' => false, 'message' => 'erro', 'data' => []], $r->toArray());
    }
}
```

- [ ] **Passo 2: Ver o teste falhar**

```bash
/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml --filter ServiceResultTest
```

Esperado: `Class "GlpiPlugin\Gac\Pre\ServiceResult" not found`.

- [ ] **Passo 3: Implementar `src/Pre/ServiceResult.php`**

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Pre;

/** Outcome of a service call; serialized as-is by the AJAX endpoints. */
final class ServiceResult
{
    /** @param array<string, mixed> $data */
    private function __construct(
        public readonly bool $ok,
        public readonly string $message,
        public readonly array $data = []
    ) {}

    /** @param array<string, mixed> $data */
    public static function ok(string $message = '', array $data = []): self
    {
        return new self(true, $message, $data);
    }

    /** @param array<string, mixed> $data */
    public static function fail(string $message, array $data = []): self
    {
        return new self(false, $message, $data);
    }

    /** @return array{success: bool, message: string, data: array<string, mixed>} */
    public function toArray(): array
    {
        return ['success' => $this->ok, 'message' => $this->message, 'data' => $this->data];
    }
}
```

Rode `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml`: esperado `OK (46 tests, ...)`.

- [ ] **Passo 4: Criar `src/Pre/EligibleTicketFinder.php`**

```php
<?php

namespace GlpiPlugin\Gac\Pre;

use Glpi\RichText\RichText;
use Ticket;

/**
 * Tickets (and their assets) that can enter a PRE (spec D2): category in the configured list
 * (with subcategories), ticket not solved/closed and not deleted, at least one linked asset,
 * ticket in the PRE's entity or its sub-entities, and the ticket+asset pair without an active
 * line in any PRE.
 */
final class EligibleTicketFinder
{
    /** @return list<array<string, mixed>> */
    public static function find(RepairProtocol $protocol): array
    {
        global $DB;

        $settings    = PreConfig::load();
        $categoryIds = self::categoryScope($settings);
        if ($categoryIds === []) {
            return [];
        }

        $entityIds = array_values(getSonsOf('glpi_entities', (int) $protocol->fields['entities_id']));
        $active    = self::activeKeys();

        $rows = [];
        foreach ($DB->request([
            'SELECT' => [
                'glpi_tickets.id AS tickets_id',
                'glpi_tickets.name AS ticket_name',
                'glpi_tickets.content AS ticket_content',
                'glpi_items_tickets.itemtype AS itemtype',
                'glpi_items_tickets.items_id AS items_id',
            ],
            'FROM'       => 'glpi_tickets',
            'INNER JOIN' => [
                'glpi_items_tickets' => [
                    'ON' => ['glpi_items_tickets' => 'tickets_id', 'glpi_tickets' => 'id'],
                ],
            ],
            'WHERE' => [
                'glpi_tickets.is_deleted'        => 0,
                'glpi_tickets.status'            => Ticket::getNotSolvedStatusArray(),
                'glpi_tickets.itilcategories_id' => $categoryIds,
                'glpi_tickets.entities_id'       => $entityIds,
            ],
            'ORDER' => ['glpi_tickets.id DESC'],
        ]) as $r) {
            $key = $r['tickets_id'] . '|' . $r['itemtype'] . '|' . $r['items_id'];
            if (isset($active[$key])) {
                continue;
            }

            $asset = getItemForItemtype($r['itemtype']);
            if (!$asset || !$asset->getFromDB((int) $r['items_id'])) {
                continue;
            }

            $observation = TicketObservationExtractor::extract(RichText::getSafeHtml((string) $r['ticket_content']));

            $rows[] = [
                'key'                  => $key,
                'tickets_id'           => (int) $r['tickets_id'],
                'ticket_name'          => (string) $r['ticket_name'],
                'itemtype'             => (string) $r['itemtype'],
                'items_id'             => (int) $r['items_id'],
                'item_name'            => (string) ($asset->fields['name'] ?? ''),
                'item_type_label'      => $asset::getTypeName(1),
                'serial'               => (string) ($asset->fields['serial'] ?? ''),
                'otherserial'          => (string) ($asset->fields['otherserial'] ?? ''),
                'item_entities_id'     => (int) ($asset->fields['entities_id'] ?? 0),
                'ticket_observation'   => $observation,
                // Editable text printed in the PDF; falls back to the ticket title (plan decision 2).
                'description_supplier' => $observation !== '' ? $observation : (string) $r['ticket_name'],
            ];
        }
        return $rows;
    }

    /** @param array<string, string> $settings @return list<int> */
    private static function categoryScope(array $settings): array
    {
        $ids = PreSettings::categoryIds($settings);
        if (!PreSettings::includeSubcategories($settings)) {
            return $ids;
        }
        $all = [];
        foreach ($ids as $id) {
            foreach (getSonsOf('glpi_itilcategories', $id) as $son) {
                $all[(int) $son] = (int) $son;
            }
        }
        return array_values($all);
    }

    /** @return array<string, true> keys "<tickets_id>|<itemtype>|<items_id>" of pairs with an active line */
    private static function activeKeys(): array
    {
        global $DB;

        $active = [];
        foreach ($DB->request([
            'SELECT' => ['tickets_id', 'itemtype', 'items_id'],
            'FROM'   => RepairProtocolItem::getTable(),
            'WHERE'  => ['status' => [
                ItemStatus::PendingSend->value,
                ItemStatus::Sending->value,
                ItemStatus::AtSupplier->value,
            ]],
        ]) as $r) {
            $active[$r['tickets_id'] . '|' . $r['itemtype'] . '|' . $r['items_id']] = true;
        }
        return $active;
    }
}
```

- [ ] **Passo 5: Criar `src/Pre/LineService.php`**

```php
<?php

namespace GlpiPlugin\Gac\Pre;

/**
 * Draft-time line operations: import, edit the supplier description, remove.
 */
final class LineService
{
    /** @param list<string> $selectedKeys keys from EligibleTicketFinder */
    public static function import(RepairProtocol $p, array $selectedKeys): ServiceResult
    {
        if (!StateMachine::canImportLines($p->getStatus())) {
            return ServiceResult::fail(__('Só é possível importar tickets em um PRE em rascunho.', 'gac'));
        }

        // Never trust the client: re-resolve every selected key against the current candidates.
        $candidates = [];
        foreach (EligibleTicketFinder::find($p) as $c) {
            $candidates[$c['key']] = $c;
        }

        $added = 0;
        foreach ($selectedKeys as $key) {
            $c = $candidates[$key] ?? null;
            if ($c === null) {
                continue;
            }
            $id = (new RepairProtocolItem())->add([
                'plugin_gac_repairprotocols_id' => $p->getID(),
                'tickets_id'           => $c['tickets_id'],
                'itemtype'             => $c['itemtype'],
                'items_id'             => $c['items_id'],
                'item_entities_id'     => $c['item_entities_id'],
                'item_name'            => $c['item_name'],
                'item_type_label'      => $c['item_type_label'],
                'serial'               => $c['serial'],
                'otherserial'          => $c['otherserial'],
                'ticket_title'         => $c['ticket_name'],
                'ticket_observation'   => $c['ticket_observation'],
                'description_supplier' => $c['description_supplier'],
                'status'               => ItemStatus::PendingSend->value,
            ]);
            if ($id) {
                $added++;
            }
        }

        return $added > 0
            ? ServiceResult::ok(sprintf(_n('%d linha importada.', '%d linhas importadas.', $added, 'gac'), $added))
            : ServiceResult::fail(__('Nenhum item válido foi selecionado.', 'gac'));
    }

    /** @param array<int, string> $descriptions line id => text */
    public static function saveDescriptions(RepairProtocol $p, array $descriptions): ServiceResult
    {
        global $DB;

        if (!StateMachine::canImportLines($p->getStatus())) {
            return ServiceResult::fail(__('As descrições só podem ser editadas em rascunho.', 'gac'));
        }
        foreach ($descriptions as $lineId => $text) {
            $DB->update(
                RepairProtocolItem::getTable(),
                ['description_supplier' => trim((string) $text), 'date_mod' => $_SESSION['glpi_currenttime']],
                ['id' => (int) $lineId, 'plugin_gac_repairprotocols_id' => $p->getID()]
            );
        }
        return ServiceResult::ok(__('Descrições salvas.', 'gac'));
    }

    public static function removeDraftLine(RepairProtocol $p, int $lineId): ServiceResult
    {
        $line = new RepairProtocolItem();
        if (
            !$line->getFromDB($lineId)
            || (int) $line->fields['plugin_gac_repairprotocols_id'] !== (int) $p->getID()
        ) {
            return ServiceResult::fail(__('Linha não encontrada.', 'gac'));
        }
        $status = $p->getStatus();
        if (
            !StateMachine::canRemoveLine($status, $line->getStatus())
            || StateMachine::removeRequiresReason($status)
        ) {
            return ServiceResult::fail(__('Esta linha não pode ser removida.', 'gac'));
        }
        $line->delete(['id' => $lineId], true);
        return ServiceResult::ok(__('Linha removida.', 'gac'));
    }

    /** Used when a draft PRE is canceled: its lines must stop blocking the ticket+asset pairs. */
    public static function deleteAllLines(RepairProtocol $p): void
    {
        global $DB;
        $DB->delete(RepairProtocolItem::getTable(), ['plugin_gac_repairprotocols_id' => $p->getID()]);
    }
}
```

- [ ] **Passo 6: Substituir `showForProtocol` em `src/Pre/RepairProtocolItem.php`** (troque o método inteiro da Tarefa 6 e acrescente os `use` no topo: `Session`, `Toolbox` não é necessário)

Adicione no topo, junto dos outros `use`:

```php
use Session;
```

Método novo:

```php
    public static function showForProtocol(RepairProtocol $protocol): void
    {
        global $CFG_GLPI;

        $status = $protocol->getStatus();
        $rn     = RepairProtocol::$rightname;

        $lines = [];
        $pendingIds = [];
        $atSupplier = 0;
        foreach ($protocol->lines() as $row) {
            $itemStatus = ItemStatus::from($row['status']);
            if ($itemStatus === ItemStatus::PendingSend) {
                $pendingIds[] = (int) $row['id'];
            }
            if ($itemStatus === ItemStatus::AtSupplier) {
                $atSupplier++;
            }
            $lines[] = $row + [
                'ticket_url'        => Ticket::getFormURLWithID((int) $row['tickets_id']),
                'status_label'      => Labels::itemStatus($itemStatus),
                'is_pending_send'   => $itemStatus === ItemStatus::PendingSend,
                'is_at_supplier'    => $itemStatus === ItemStatus::AtSupplier,
                'is_returned'       => $itemStatus === ItemStatus::Returned,
                'outcome_label'     => $row['outcome'] ? Labels::outcome(Outcome::from($row['outcome'])) : '',
                'destination_label' => $row['destination'] ? Labels::destination(Destination::from($row['destination'])) : '',
                'can_remove_draft'  => StateMachine::canRemoveLine($status, $itemStatus)
                    && !StateMachine::removeRequiresReason($status),
                'can_remove_failed' => StateMachine::canRemoveLine($status, $itemStatus)
                    && StateMachine::removeRequiresReason($status),
            ];
        }

        $viewable = $protocol->canViewItem();
        $canEdit  = $protocol->canUpdateItem();
        $isDraft  = $status === ProtocolStatus::Draft;

        $canSend = $viewable && Session::haveRight($rn, RepairProtocol::RIGHT_SEND);
        $sendMode = '';
        if ($isDraft && $lines !== []) {
            $sendMode = 'start';
        } elseif ($status === ProtocolStatus::Sent) {
            if ($pendingIds !== []) {
                $sendMode = 'continue';
            } elseif ((int) $protocol->fields['documents_id_sent'] === 0) {
                $sendMode = 'finalize';
            }
        }

        $outcomeOptions = [];
        foreach (Outcome::cases() as $o) {
            $outcomeOptions[] = ['value' => $o->value, 'label' => Labels::outcome($o), 'defective' => $o->isDefective()];
        }
        $destinationOptions = [
            ['value' => Destination::Writeoff->value, 'label' => Labels::destination(Destination::Writeoff)],
            ['value' => Destination::KeepDefective->value, 'label' => Labels::destination(Destination::KeepDefective)],
        ];

        TemplateRenderer::getInstance()->display('@gac/pre/items_tab.html.twig', [
            'protocol'           => $protocol,
            'lines'              => $lines,
            'is_draft'           => $isDraft,
            'can_edit'           => $canEdit,
            'can_send'           => $canSend,
            'send_mode'          => $sendMode,
            'pending_line_ids'   => $pendingIds,
            'can_return'         => $viewable && Session::haveRight($rn, RepairProtocol::RIGHT_RETURN),
            'can_reopen'         => $viewable && Session::haveRight($rn, RepairProtocol::RIGHT_REOPEN),
            'is_reopened'        => $status === ProtocolStatus::Partial && RepairProtocolEvent::isReopened((int) $protocol->getID()),
            'candidates'         => ($isDraft && $canEdit) ? EligibleTicketFinder::find($protocol) : [],
            'form_url'           => RepairProtocolItem::getFormURL(),
            'protocol_form_url'  => RepairProtocol::getFormURL(),
            'ajax_send_url'      => $CFG_GLPI['root_doc'] . '/plugins/gac/ajax/pre_send.php',
            'outcome_options'    => $outcomeOptions,
            'destination_options' => $destinationOptions,
        ]);
    }
```

`RepairProtocolEvent::isReopened()` só passa a existir na Tarefa 11; para não quebrar esta tarefa, acrescente já agora em `src/Pre/RepairProtocolEvent.php`, logo abaixo de `log()`:

```php
    /**
     * True when the latest closing-related event is a reopening: the PRE is being corrected
     * and has not been closed again (plan decision 3).
     */
    public static function isReopened(int $protocolId): bool
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['event'],
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'plugin_gac_repairprotocols_id' => $protocolId,
                'event' => ['reopened', 'closed'],
            ],
            'ORDER' => ['id DESC'],
            'LIMIT' => 1,
        ])->current();

        return $row !== null && $row['event'] === 'reopened';
    }
```

Acrescente também os `use` que faltam em `RepairProtocolItem.php`: nenhum além de `Session` (os enums estão no mesmo namespace).

- [ ] **Passo 7: Substituir `templates/pre/items_tab.html.twig`**

```twig
{% import 'components/form/fields_macros.html.twig' as fields %}

<div class="p-3" data-gac-pre data-protocol-id="{{ protocol.getID() }}" data-ajax-send="{{ ajax_send_url }}">
    {% include '@gac/pre/items_toolbar.html.twig' ignore missing %}
    {% include '@gac/pre/items_import.html.twig' ignore missing %}

    {% set editing = is_draft and can_edit %}
    {% if editing %}
        <form method="post" action="{{ form_url }}">
            <input type="hidden" name="_glpi_csrf_token" value="{{ csrf_token() }}">
            <input type="hidden" name="protocol_id" value="{{ protocol.getID() }}">
    {% endif %}

    <div class="table-responsive">
        <table class="table table-hover card-table">
            <thead>
                <tr>
                    <th>{{ __('Ticket', 'gac') }}</th>
                    <th>{{ __('Tipo', 'gac') }}</th>
                    <th>{{ __('Equipamento', 'gac') }}</th>
                    <th>{{ __('Patrimônio', 'gac') }}</th>
                    <th>{{ __('Nº de série', 'gac') }}</th>
                    <th>{{ __('Descrição para o fornecedor', 'gac') }}</th>
                    <th>{{ __('Situação', 'gac') }}</th>
                    <th>{{ __('Ações', 'gac') }}</th>
                </tr>
            </thead>
            <tbody>
                {% for line in lines %}
                    <tr>
                        <td><a href="{{ line.ticket_url }}">#{{ line.tickets_id }}</a></td>
                        <td>{{ line.item_type_label }}</td>
                        <td>{{ line.item_name }}</td>
                        <td>{{ line.otherserial|default('—') }}</td>
                        <td>{{ line.serial|default('—') }}</td>
                        <td>
                            {% if editing %}
                                <textarea class="form-control" rows="2" name="description[{{ line.id }}]">{{ line.description_supplier }}</textarea>
                            {% else %}
                                {{ line.description_supplier|default('—') }}
                            {% endif %}
                        </td>
                        <td>
                            {{ line.status_label }}
                            {% if line.outcome_label %}<div class="small text-muted">{{ line.outcome_label }}{% if line.destination_label and line.destination != 'none' %} · {{ line.destination_label }}{% endif %}</div>{% endif %}
                        </td>
                        <td>
                            {% if editing and line.can_remove_draft %}
                                <button type="submit" name="remove_line" value="{{ line.id }}" class="btn btn-sm btn-outline-danger">{{ __('Remover', 'gac') }}</button>
                            {% endif %}
                            {% include '@gac/pre/line_actions.html.twig' ignore missing with {line: line} %}
                        </td>
                    </tr>
                {% else %}
                    <tr><td colspan="8" class="text-center text-muted">{{ __('Nenhum item neste protocolo.', 'gac') }}</td></tr>
                {% endfor %}
            </tbody>
        </table>
    </div>

    {% if editing %}
            {% if lines is not empty %}
                <button type="submit" name="save_descriptions" value="1" class="btn btn-primary">{{ __('Salvar descrições', 'gac') }}</button>
            {% endif %}
        </form>
    {% endif %}

    {% include '@gac/pre/items_return_forms.html.twig' ignore missing %}
</div>
```

- [ ] **Passo 8: Criar `templates/pre/items_import.html.twig`**

```twig
{% if is_draft and can_edit %}
    <div class="card mb-3">
        <div class="card-header"><h4 class="card-title">{{ __('Importar tickets', 'gac') }}</h4></div>
        <div class="card-body">
            {% if candidates is empty %}
                <p class="text-muted mb-0">{{ __('Nenhum ticket elegível. Confira as categorias elegíveis na configuração do Gac e se o ticket tem um ativo associado.', 'gac') }}</p>
            {% else %}
                <form method="post" action="{{ form_url }}">
                    <input type="hidden" name="_glpi_csrf_token" value="{{ csrf_token() }}">
                    <input type="hidden" name="protocol_id" value="{{ protocol.getID() }}">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th></th>
                                    <th>{{ __('Ticket', 'gac') }}</th>
                                    <th>{{ __('Título', 'gac') }}</th>
                                    <th>{{ __('Equipamento', 'gac') }}</th>
                                    <th>{{ __('Patrimônio', 'gac') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {% for c in candidates %}
                                    <tr>
                                        <td><input type="checkbox" class="form-check-input" name="select[]" value="{{ c.key }}"></td>
                                        <td>#{{ c.tickets_id }}</td>
                                        <td>{{ c.ticket_name }}</td>
                                        <td>{{ c.item_type_label }} · {{ c.item_name }}</td>
                                        <td>{{ c.otherserial|default('—') }}</td>
                                    </tr>
                                {% endfor %}
                            </tbody>
                        </table>
                    </div>
                    <button type="submit" name="import" value="1" class="btn btn-primary">{{ __('Importar selecionados', 'gac') }}</button>
                </form>
            {% endif %}
        </div>
    </div>
{% endif %}
```

- [ ] **Passo 9: Criar `front/pre/repairprotocolitem.form.php`** (POSTs das linhas; as tarefas 10 e 11 acrescentam ramos)

```php
<?php

include('../../../../inc/includes.php');

use GlpiPlugin\Gac\Pre\LineService;
use GlpiPlugin\Gac\Pre\RepairProtocol;
use GlpiPlugin\Gac\Pre\ServiceResult;

$protocol = new RepairProtocol();
$protocolId = (int) ($_POST['protocol_id'] ?? 0);
if ($protocolId === 0 || !$protocol->getFromDB($protocolId)) {
    Html::displayNotFoundError();
}

$notify = static function (ServiceResult $r): void {
    Session::addMessageAfterRedirect(htmlescape($r->message), false, $r->ok ? INFO : ERROR);
};

if (isset($_POST['import'])) {
    $protocol->check($protocolId, UPDATE);
    $notify(LineService::import($protocol, array_map('strval', (array) ($_POST['select'] ?? []))));
} elseif (isset($_POST['remove_line'])) {
    $protocol->check($protocolId, UPDATE);
    $notify(LineService::removeDraftLine($protocol, (int) $_POST['remove_line']));
} elseif (isset($_POST['save_descriptions'])) {
    $protocol->check($protocolId, UPDATE);
    $notify(LineService::saveDescriptions($protocol, (array) ($_POST['description'] ?? [])));
}
// (next tasks add: return, lost, reopen, correct, finish_corrections — before this line)

Html::back();
```

- [ ] **Passo 10: Cancelar apaga as linhas.** Em `front/pre/repairprotocol.form.php`, adicione o `use` e ajuste o ramo `cancel_protocol`

Acrescente ao bloco de `use`:

```php
use GlpiPlugin\Gac\Pre\LineService;
```

E dentro do `if (StateMachine::canCancel(...))`, antes de `changeStatus`:

```php
        LineService::deleteAllLines($item);
```

- [ ] **Passo 11: Lint e teste manual**

```bash
for f in $(find src front -name '*.php'); do /c/xampp/php/php.exe -l "$f"; done
/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml
```

No navegador, com pelo menos uma categoria ITIL configurada (Tarefa 7): (a) abra ou crie um ticket nessa categoria com um ativo associado e o texto `Informações adicionais:` (em negrito) seguido de um parágrafo; (b) abra um PRE em rascunho > aba Itens: o bloco "Importar tickets" lista o ticket e o ativo; (c) marque e importe: a linha aparece com "Descrição para o fornecedor" já preenchida com o texto de "Informações adicionais" (ou com o título do ticket se não houver); (d) edite a descrição, clique "Salvar descrições" e recarregue: persiste; (e) o mesmo par ticket+ativo **não** aparece mais na lista de candidatos nem em outro PRE; (f) "Remover" apaga a linha e o par volta a ser candidato; (g) um ticket com 2 ativos mostra 2 opções (uma por ativo); (h) um ticket de outra entidade que não é subentidade **não** aparece; (i) "Cancelar PRE" (o botão vem na Tarefa 9) não existe ainda: teste o cancelamento depois.

- [ ] **Passo 12: Commit** via `/commit`. Título sugerido: `feat(pre): import eligible tickets into draft protocols`.

---

### Tarefa 9: Enviar (linha a linha), remoção de linha com falha

**Arquivos:**
- Criar: `src/Pre/StateGuard.php`, `src/Pre/TicketOps.php`, `src/Pre/SendService.php`, `ajax/pre_send.php`, `public/js/pre.js`, `templates/pre/items_toolbar.html.twig`, `templates/pre/line_actions.html.twig`

**Interfaces:**
- Consome: `PreConfig::load`, `PreSettings::missingRolesForSend/stateId/reasonId`, `RepairProtocol::changeStatus/lines`, `RepairProtocolEvent::log`, `StateMachine`, `ServiceResult` (tarefas anteriores).
- Produz:
  - `StateGuard::isUsable(int $statesId, int $entityId): bool`
  - `StateGuard::isReasonUsable(int $reasonId, int $entityId): bool` (o motivo de pendência mapeado precisa valer para a entidade **do ticket**; a pré-checagem do "Enviar" bloqueia com uma mensagem por ticket)
  - `TicketOps::followup(Ticket $t, string $content, ?int $pendingReasonsId = null): void` (lança `\RuntimeException`)
  - `TicketOps::keepPendingWithReason(Ticket $t, int $reasonId, string $content): void`
  - `TicketOps::leavePending(Ticket $t, string $content): void`
  - `TicketOps::solve(Ticket $t, string $content): void`
  - `TicketOps::addCost(Ticket $t, string $name, float $cost, string $date): int` (devolve o id do `TicketCost`)
  - `SendService::start(RepairProtocol $p): ServiceResult` — `data['line_ids']` é `list<int>`
  - `SendService::sendLine(int $lineId): ServiceResult`
  - `SendService::finalize(RepairProtocol $p): ServiceResult`
  - `SendService::removeFailedLine(int $lineId, string $reason): ServiceResult`
  - Endpoint `POST /plugins/gac/ajax/pre_send.php` com `action` = `start` (`protocol_id`), `line` (`line_id`), `finalize` (`protocol_id`), `remove_line` (`line_id`, `reason`)

- [ ] **Passo 1: Criar `src/Pre/StateGuard.php`**

```php
<?php

namespace GlpiPlugin\Gac\Pre;

use PendingReason;
use State;

/**
 * A State or a PendingReason belongs to an entity and is visible to that entity, and to its
 * descendants only when recursive (spec section 10). The global mapping must be valid for the
 * entity where it is applied: the asset's entity for a State, the ticket's for a PendingReason.
 */
final class StateGuard
{
    public static function isUsable(int $statesId, int $entityId): bool
    {
        if ($statesId <= 0) {
            return false;
        }
        $state = new State();
        if (!$state->getFromDB($statesId)) {
            return false;
        }
        return self::isVisible((int) $state->fields['entities_id'], (bool) $state->fields['is_recursive'], $entityId);
    }

    public static function isReasonUsable(int $reasonId, int $entityId): bool
    {
        if ($reasonId <= 0) {
            return false;
        }
        $reason = new PendingReason();
        if (!$reason->getFromDB($reasonId)) {
            return false;
        }
        return self::isVisible((int) $reason->fields['entities_id'], (bool) $reason->fields['is_recursive'], $entityId);
    }

    private static function isVisible(int $ownerEntity, bool $recursive, int $entityId): bool
    {
        if ($ownerEntity === $entityId) {
            return true;
        }
        if (!$recursive) {
            return false;
        }
        return in_array($ownerEntity, array_map('intval', getAncestorsOf('glpi_entities', $entityId)), true);
    }
}
```

- [ ] **Passo 2: Criar `src/Pre/TicketOps.php`** (todas as operações no ticket, num só lugar; a Tarefa 10 usa as que sobram)

```php
<?php

namespace GlpiPlugin\Gac\Pre;

use ITILFollowup;
use ITILSolution;
use PendingReason_Item;
use Ticket;
use TicketCost;

/**
 * Ticket-side operations of the PRE. Notes on GLPI 11 behaviour (verified in core source):
 * - entering Pending = follow-up with pending=1 + pendingreasons_id;
 * - leaving Pending = ticket status update (core drops the pending reason on its own);
 * - re-pending with a new reason overwrites previous_status with "Pending", so it is restored.
 */
final class TicketOps
{
    public static function followup(Ticket $ticket, string $content, ?int $pendingReasonsId = null): void
    {
        $input = [
            'itemtype'   => 'Ticket',
            'items_id'   => $ticket->getID(),
            'content'    => $content,
            'is_private' => 0,
        ];
        if ($pendingReasonsId !== null && $pendingReasonsId > 0) {
            $input['pending']           = 1;
            $input['pendingreasons_id'] = $pendingReasonsId;
        }
        if (!(new ITILFollowup())->add($input)) {
            throw new \RuntimeException(sprintf(__('Não foi possível registrar o acompanhamento no ticket #%d.', 'gac'), $ticket->getID()));
        }
    }

    public static function keepPendingWithReason(Ticket $ticket, int $reasonId, string $content): void
    {
        $existing = PendingReason_Item::getForItem($ticket);
        $original = $existing ? ($existing->fields['previous_status'] ?? null) : null;

        self::followup($ticket, $content, $reasonId);

        if ($original !== null) {
            $ticket->getFromDB($ticket->getID());
            PendingReason_Item::updateForItem($ticket, ['previous_status' => $original]);
        }
    }

    public static function leavePending(Ticket $ticket, string $content): void
    {
        $ticket->getFromDB($ticket->getID());
        if ((int) $ticket->fields['status'] === Ticket::WAITING) {
            $pending = PendingReason_Item::getForItem($ticket);
            $back    = $pending ? (int) ($pending->fields['previous_status'] ?: Ticket::ASSIGNED) : Ticket::ASSIGNED;
            if ($back === Ticket::WAITING) {
                $back = Ticket::ASSIGNED;
            }
            if (!$ticket->update(['id' => $ticket->getID(), 'status' => $back])) {
                throw new \RuntimeException(sprintf(__('Não foi possível alterar o status do ticket #%d.', 'gac'), $ticket->getID()));
            }
        }
        self::followup($ticket, $content);
    }

    public static function solve(Ticket $ticket, string $content): void
    {
        $id = (new ITILSolution())->add([
            'itemtype' => 'Ticket',
            'items_id' => $ticket->getID(),
            'content'  => $content,
        ]);
        if (!$id) {
            throw new \RuntimeException(sprintf(__('Não foi possível solucionar o ticket #%d.', 'gac'), $ticket->getID()));
        }
    }

    public static function addCost(Ticket $ticket, string $name, float $cost, string $date): int
    {
        $id = (new TicketCost())->add([
            'tickets_id'  => $ticket->getID(),
            'name'        => $name,
            'cost_fixed'  => $cost,
            'begin_date'  => $date,
            'end_date'    => $date,
            'entities_id' => (int) $ticket->fields['entities_id'],
        ]);
        if (!$id) {
            throw new \RuntimeException(sprintf(__('Não foi possível registrar o custo no ticket #%d.', 'gac'), $ticket->getID()));
        }
        return (int) $id;
    }
}
```

- [ ] **Passo 3: Criar `src/Pre/SendService.php`**

```php
<?php

namespace GlpiPlugin\Gac\Pre;

use Ticket;
use Toolbox;

/**
 * "Enviar" (spec 7.3, D15): pre-checks first, then one line per call, then the final step.
 */
final class SendService
{
    private const STALE_SENDING_SECONDS = 300;

    public static function start(RepairProtocol $p): ServiceResult
    {
        if (!StateMachine::canStartSend($p->getStatus())) {
            return ServiceResult::fail(__('Só é possível enviar um PRE em rascunho.', 'gac'));
        }
        if ((int) $p->fields['suppliers_id'] === 0) {
            return ServiceResult::fail(__('Defina o fornecedor antes de enviar.', 'gac'));
        }

        $lines = $p->lines();
        if ($lines === []) {
            return ServiceResult::fail(__('O PRE não tem itens.', 'gac'));
        }

        $settings = PreConfig::load();
        $missing  = PreSettings::missingRolesForSend($settings);
        if ($missing !== []) {
            return ServiceResult::fail(
                sprintf(__('Configuração incompleta: %s.', 'gac'), implode(', ', $missing))
            );
        }

        $errors = self::preCheckLines($p, $lines, $settings);
        if ($errors !== []) {
            return ServiceResult::fail(implode(' ', $errors));
        }

        $p->changeStatus(ProtocolStatus::Sent, ['date_sent' => $_SESSION['glpi_currenttime']]);
        RepairProtocolEvent::log((int) $p->getID(), 'sent');

        return ServiceResult::ok('', ['line_ids' => array_map(static fn(array $l): int => (int) $l['id'], $lines)]);
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @param array<string, string>      $settings
     * @return list<string>
     */
    private static function preCheckLines(RepairProtocol $p, array $lines, array $settings): array
    {
        $errors     = [];
        $stateId    = PreSettings::stateId($settings, 'at_supplier');
        $reasonId   = PreSettings::reasonId($settings, 'at_supplier');
        $activeElse = self::activeElsewhere((int) $p->getID());

        foreach ($lines as $line) {
            $label = sprintf('#%d (%s)', $line['tickets_id'], $line['item_name']);

            $ticket = new Ticket();
            if (!$ticket->getFromDB((int) $line['tickets_id'])) {
                $errors[] = sprintf(__('Ticket %s não existe mais.', 'gac'), $label);
                continue;
            }
            if (in_array((int) $ticket->fields['status'], [Ticket::SOLVED, Ticket::CLOSED], true)) {
                $errors[] = sprintf(__('Ticket %s já está solucionado ou fechado.', 'gac'), $label);
            }
            if (!StateGuard::isReasonUsable($reasonId, (int) $ticket->fields['entities_id'])) {
                $errors[] = sprintf(
                    __('O motivo de pendência "Ticket na assistência" não é válido para a entidade do ticket %s. Crie-o na entidade raiz com recursividade.', 'gac'),
                    $label
                );
            }

            $asset = getItemForItemtype($line['itemtype']);
            if (!$asset || !$asset->getFromDB((int) $line['items_id'])) {
                $errors[] = sprintf(__('O ativo do ticket %s não existe mais.', 'gac'), $label);
            }

            if (!StateGuard::isUsable($stateId, (int) $line['item_entities_id'])) {
                $errors[] = sprintf(
                    __('O status "Ativo na assistência" não é válido para a entidade do ativo do ticket %s. Crie-o na entidade raiz com recursividade.', 'gac'),
                    $label
                );
            }

            if (isset($activeElse[$line['tickets_id'] . '|' . $line['itemtype'] . '|' . $line['items_id']])) {
                $errors[] = sprintf(__('O par ticket/ativo %s já está em outro PRE ativo.', 'gac'), $label);
            }
        }
        return array_values(array_unique($errors));
    }

    /** @return array<string, true> */
    private static function activeElsewhere(int $protocolId): array
    {
        global $DB;

        $active = [];
        foreach ($DB->request([
            'SELECT' => ['tickets_id', 'itemtype', 'items_id'],
            'FROM'   => RepairProtocolItem::getTable(),
            'WHERE'  => [
                'status' => [ItemStatus::PendingSend->value, ItemStatus::Sending->value, ItemStatus::AtSupplier->value],
                ['NOT' => ['plugin_gac_repairprotocols_id' => $protocolId]],
            ],
        ]) as $r) {
            $active[$r['tickets_id'] . '|' . $r['itemtype'] . '|' . $r['items_id']] = true;
        }
        return $active;
    }

    public static function sendLine(int $lineId): ServiceResult
    {
        global $DB;

        $line = new RepairProtocolItem();
        if (!$line->getFromDB($lineId)) {
            return ServiceResult::fail(__('Linha não encontrada.', 'gac'));
        }
        $protocol = new RepairProtocol();
        $protocol->getFromDB((int) $line->fields['plugin_gac_repairprotocols_id']);

        self::recoverStale((int) $protocol->getID());
        $line->getFromDB($lineId);

        // Idempotent: a line that already went out is not an error.
        if ($line->getStatus() === ItemStatus::AtSupplier || $line->getStatus() === ItemStatus::Sending) {
            return ServiceResult::ok(__('Linha já enviada ou em envio.', 'gac'));
        }
        if (!StateMachine::canSendLine($protocol->getStatus(), $line->getStatus())) {
            return ServiceResult::fail(__('Esta linha não pode ser enviada.', 'gac'));
        }

        // Atomic claim: only one caller flips pending_send -> sending.
        $DB->update(
            RepairProtocolItem::getTable(),
            ['status' => ItemStatus::Sending->value, 'last_error' => null, 'date_mod' => $_SESSION['glpi_currenttime']],
            ['id' => $lineId, 'status' => ItemStatus::PendingSend->value]
        );
        if ($DB->affectedRows() !== 1) {
            return ServiceResult::ok(__('Linha já está sendo processada.', 'gac'));
        }

        $settings = PreConfig::load();
        try {
            $DB->beginTransaction();

            $ticket = new Ticket();
            if (!$ticket->getFromDB((int) $line->fields['tickets_id'])) {
                throw new \RuntimeException(__('Ticket não encontrado.', 'gac'));
            }
            $asset = getItemForItemtype($line->fields['itemtype']);
            if (!$asset || !$asset->getFromDB((int) $line->fields['items_id'])) {
                throw new \RuntimeException(__('Ativo não encontrado.', 'gac'));
            }

            $before = (int) $asset->fields['states_id'];
            if (!$asset->update(['id' => $asset->getID(), 'states_id' => PreSettings::stateId($settings, 'at_supplier')])) {
                throw new \RuntimeException(__('Não foi possível alterar o status do ativo.', 'gac'));
            }

            TicketOps::followup(
                $ticket,
                sprintf(
                    __('Equipamento %1$s enviado à assistência técnica do fornecedor %2$s. Protocolo %3$s.', 'gac'),
                    $line->fields['item_name'],
                    $protocol->fields['supplier_name'],
                    $protocol->fields['number']
                ),
                PreSettings::reasonId($settings, 'at_supplier')
            );

            $DB->update(
                RepairProtocolItem::getTable(),
                [
                    'status'           => ItemStatus::AtSupplier->value,
                    'states_id_before' => $before,
                    'last_error'       => null,
                    'date_mod'         => $_SESSION['glpi_currenttime'],
                ],
                ['id' => $lineId]
            );

            $DB->commit();
        } catch (\Throwable $e) {
            try {
                $DB->rollBack();
            } catch (\Throwable) {
                // not in a transaction: nothing to undo
            }
            $DB->update(
                RepairProtocolItem::getTable(),
                [
                    'status'     => ItemStatus::PendingSend->value,
                    'last_error' => mb_substr($e->getMessage(), 0, 1000),
                    'date_mod'   => $_SESSION['glpi_currenttime'],
                ],
                ['id' => $lineId]
            );
            Toolbox::logInFile('gac', sprintf("sendLine %d failed: %s\n", $lineId, $e->getMessage()));
            return ServiceResult::fail($e->getMessage());
        }

        return ServiceResult::ok();
    }

    /** A line stuck in "sending" (interrupted request) goes back to "pending_send". */
    private static function recoverStale(int $protocolId): void
    {
        global $DB;

        $limit = date('Y-m-d H:i:s', time() - self::STALE_SENDING_SECONDS);
        $DB->update(
            RepairProtocolItem::getTable(),
            ['status' => ItemStatus::PendingSend->value],
            [
                'plugin_gac_repairprotocols_id' => $protocolId,
                'status'   => ItemStatus::Sending->value,
                'date_mod' => ['<', $limit],
            ]
        );
    }

    /** Last step: every line is at the supplier. The PDF is added by task 12. */
    public static function finalize(RepairProtocol $p): ServiceResult
    {
        if ($p->getStatus() !== ProtocolStatus::Sent) {
            return ServiceResult::fail(__('O PRE não está em envio.', 'gac'));
        }
        $pending = 0;
        foreach ($p->lines() as $line) {
            if ($line['status'] !== ItemStatus::AtSupplier->value) {
                $pending++;
            }
        }
        if ($pending > 0) {
            return ServiceResult::fail(sprintf(
                _n('Ainda há %d linha pendente.', 'Ainda há %d linhas pendentes.', $pending, 'gac'),
                $pending
            ));
        }

        RepairProtocolEvent::log((int) $p->getID(), 'send_finalized');
        return ServiceResult::ok();
    }

    /** "Remover linha com falha" (spec 6.2): only a pending_send line of a PRE already sent. */
    public static function removeFailedLine(int $lineId, string $reason): ServiceResult
    {
        $reason = trim($reason);
        if ($reason === '') {
            return ServiceResult::fail(__('Informe o motivo da remoção.', 'gac'));
        }
        $line = new RepairProtocolItem();
        if (!$line->getFromDB($lineId)) {
            return ServiceResult::fail(__('Linha não encontrada.', 'gac'));
        }
        $protocol = new RepairProtocol();
        $protocol->getFromDB((int) $line->fields['plugin_gac_repairprotocols_id']);

        if (
            !StateMachine::canRemoveLine($protocol->getStatus(), $line->getStatus())
            || !StateMachine::removeRequiresReason($protocol->getStatus())
        ) {
            return ServiceResult::fail(__('Esta linha não pode ser removida.', 'gac'));
        }

        RepairProtocolEvent::log(
            (int) $protocol->getID(),
            'line_removed',
            $reason,
            ['tickets_id' => $line->fields['tickets_id'], 'item' => $line->fields['item_name']],
            $lineId
        );
        $line->delete(['id' => $lineId], true);

        // A PRE left with no lines never sent anything: it is canceled (plan decision 9).
        if ($protocol->lines() === []) {
            $protocol->changeStatus(ProtocolStatus::Canceled);
            RepairProtocolEvent::log((int) $protocol->getID(), 'canceled', __('Todas as linhas foram removidas.', 'gac'));
        }

        return ServiceResult::ok(__('Linha removida.', 'gac'));
    }
}
```

- [ ] **Passo 4: Criar `ajax/pre_send.php`**

```php
<?php

// CSRF is validated by the GLPI 11 kernel from the X-Glpi-Csrf-Token header; do not add
// Session::checkCSRF() here.
include('../../../inc/includes.php');

use GlpiPlugin\Gac\Pre\RepairProtocol;
use GlpiPlugin\Gac\Pre\RepairProtocolItem;
use GlpiPlugin\Gac\Pre\SendService;
use GlpiPlugin\Gac\Pre\ServiceResult;

header('Content-Type: application/json; charset=utf-8');

$respond = static function (ServiceResult $result, int $http = 200): never {
    http_response_code($http);
    echo json_encode($result->toArray(), JSON_UNESCAPED_UNICODE);
    exit;
};

if (!Session::haveRight(RepairProtocol::$rightname, RepairProtocol::RIGHT_SEND)) {
    $respond(ServiceResult::fail(__('Acesso negado.', 'gac')), 403);
}

$action = (string) ($_POST['action'] ?? '');

$protocolId = (int) ($_POST['protocol_id'] ?? 0);
if (in_array($action, ['line', 'remove_line'], true)) {
    $line = new RepairProtocolItem();
    if (!$line->getFromDB((int) ($_POST['line_id'] ?? 0))) {
        $respond(ServiceResult::fail(__('Linha não encontrada.', 'gac')), 404);
    }
    $protocolId = (int) $line->fields['plugin_gac_repairprotocols_id'];
}

$protocol = new RepairProtocol();
if (!$protocol->getFromDB($protocolId) || !$protocol->canViewItem()) {
    $respond(ServiceResult::fail(__('Acesso negado.', 'gac')), 403);
}

try {
    $result = match ($action) {
        'start'       => SendService::start($protocol),
        'line'        => SendService::sendLine((int) $_POST['line_id']),
        'finalize'    => SendService::finalize($protocol),
        'remove_line' => SendService::removeFailedLine((int) $_POST['line_id'], (string) ($_POST['reason'] ?? '')),
        default       => ServiceResult::fail(__('Ação inválida.', 'gac')),
    };
} catch (\Throwable $e) {
    Toolbox::logInFile('gac', 'pre_send.php ' . $action . ': ' . $e->getMessage() . "\n");
    $result = ServiceResult::fail(__('Erro inesperado.', 'gac'));
}

$respond($result);
```

- [ ] **Passo 5: Criar `public/js/pre.js`** (delegação de eventos: o conteúdo da aba chega por AJAX)

```js
(function () {
    'use strict';

    function csrfToken() {
        return typeof window.getAjaxCsrfToken === 'function' ? window.getAjaxCsrfToken() : '';
    }

    async function post(url, params) {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-Glpi-Csrf-Token': csrfToken(),
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams(params).toString(),
        });
        try {
            return await response.json();
        } catch (e) {
            return { success: false, message: 'Resposta inválida do servidor.', data: {} };
        }
    }

    function show(root, text, level) {
        const box = root.querySelector('[data-gac-progress]');
        if (!box) {
            return;
        }
        box.textContent = text;
        box.className = 'flex-grow-1 ' + (level === 'error' ? 'text-danger' : 'text-muted');
    }

    async function runSend(button) {
        const root = button.closest('[data-gac-pre]');
        const url = root.dataset.ajaxSend;
        const protocolId = root.dataset.protocolId;
        const mode = button.dataset.gacSend;
        button.disabled = true;

        let lineIds = [];
        if (mode === 'start') {
            const started = await post(url, { action: 'start', protocol_id: protocolId });
            if (!started.success) {
                show(root, started.message, 'error');
                button.disabled = false;
                return;
            }
            lineIds = started.data.line_ids;
        } else if (mode === 'continue') {
            lineIds = JSON.parse(button.dataset.lineIds || '[]');
        }

        let failed = 0;
        for (let i = 0; i < lineIds.length; i++) {
            show(root, 'Enviando ' + (i + 1) + ' de ' + lineIds.length + '...', 'info');
            const sent = await post(url, { action: 'line', line_id: lineIds[i] });
            if (!sent.success) {
                failed++;
            }
        }

        if (failed === 0) {
            show(root, 'Gerando o documento de envio...', 'info');
            const finalized = await post(url, { action: 'finalize', protocol_id: protocolId });
            if (!finalized.success) {
                show(root, finalized.message, 'error');
                button.disabled = false;
                return;
            }
        } else {
            show(root, failed + ' linha(s) falharam. Veja o erro na linha; use "Continuar envio" ou remova a linha com falha.', 'error');
            window.setTimeout(function () { window.location.reload(); }, 2500);
            return;
        }
        window.location.reload();
    }

    async function runRemoveFailed(button) {
        const root = button.closest('[data-gac-pre]');
        const group = button.closest('.input-group');
        const reason = group.querySelector('[data-gac-remove-reason]').value;
        const result = await post(root.dataset.ajaxSend, {
            action: 'remove_line',
            line_id: button.dataset.gacRemoveFailed,
            reason: reason,
        });
        if (!result.success) {
            show(root, result.message, 'error');
            return;
        }
        window.location.reload();
    }

    document.addEventListener('click', function (event) {
        const send = event.target.closest('[data-gac-send]');
        if (send) {
            event.preventDefault();
            runSend(send);
            return;
        }
        const remove = event.target.closest('[data-gac-remove-failed]');
        if (remove) {
            event.preventDefault();
            runRemoveFailed(remove);
        }
    });
})();
```

- [ ] **Passo 6: Criar `templates/pre/items_toolbar.html.twig` e `templates/pre/line_actions.html.twig`**

`items_toolbar.html.twig`:

```twig
<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
    {% if can_send %}
        {% if send_mode == 'start' %}
            <button type="button" class="btn btn-primary" data-gac-send="start">
                <i class="ti ti-send"></i> {{ __('Enviar', 'gac') }}
            </button>
        {% elseif send_mode == 'continue' %}
            <button type="button" class="btn btn-warning" data-gac-send="continue" data-line-ids="{{ pending_line_ids|json_encode }}">
                {{ __('Continuar envio', 'gac') }}
            </button>
        {% elseif send_mode == 'finalize' %}
            <button type="button" class="btn btn-warning" data-gac-send="finalize">
                {{ __('Concluir envio (gerar documento)', 'gac') }}
            </button>
        {% endif %}
    {% endif %}

    {% if is_draft and can_edit %}
        <form method="post" action="{{ protocol_form_url }}" class="d-inline">
            <input type="hidden" name="_glpi_csrf_token" value="{{ csrf_token() }}">
            <input type="hidden" name="id" value="{{ protocol.getID() }}">
            <button type="submit" name="cancel_protocol" value="1" class="btn btn-outline-danger">{{ __('Cancelar PRE', 'gac') }}</button>
        </form>
    {% endif %}

    <div class="flex-grow-1" data-gac-progress></div>
</div>
```

`line_actions.html.twig`:

```twig
{% if line.can_remove_failed and can_send %}
    <div class="input-group input-group-sm">
        <input type="text" class="form-control" placeholder="{{ __('Motivo', 'gac') }}" data-gac-remove-reason>
        <button type="button" class="btn btn-outline-danger" data-gac-remove-failed="{{ line.id }}">{{ __('Remover linha com falha', 'gac') }}</button>
    </div>
{% endif %}
{% if line.last_error %}
    <div class="text-danger small mt-1">{{ line.last_error }}</div>
{% endif %}
```

- [ ] **Passo 7: Lint e teste manual**

```bash
for f in $(find src front ajax -name '*.php'); do /c/xampp/php/php.exe -l "$f"; done
```

No navegador (a configuração completa da Tarefa 7 é pré-requisito: papéis de status e motivos mapeados). (a) Com um PRE em rascunho e 2 linhas, o botão **Enviar** aparece; clique: a barra mostra "Enviando 1 de 2...", a página recarrega, o PRE está `Enviado` e as 2 linhas `Na assistência`; (b) no ticket: acompanhamento "Equipamento ... enviado à assistência técnica..." e status **Pendente** com o motivo mapeado; (c) no ativo: o status virou o de "na assistência" e o histórico do ativo registra a mudança; (d) o histórico do PRE tem "Envio iniciado" e "PDF de envio gerado"; (e) sem o mapeamento de status configurado, "Enviar" recusa com a lista dos papéis faltantes **e não altera nenhum ticket**; (f) desmapeie o status para uma entidade em que ele não vale, ou mapeie um motivo de pendência que só existe numa subentidade: a pré-checagem bloqueia citando o ticket, e **nada** muda em ticket, ativo ou acompanhamentos; (g) simule falha de uma linha (apague o ativo de um ticket já em `Aguardando envio` no meio do processo, ou renomeie a classe no banco): a linha fica `Aguardando envio` com o erro, "Continuar envio" reprocessa só as pendentes; (h) "Remover linha com falha" exige o motivo e grava o evento; removendo a última linha, o PRE vira `Cancelado`; (i) clique duplo em "Enviar" não duplica acompanhamentos; (j) um usuário sem o direito **Enviar** recebe 403 no endpoint e não vê o botão.

- [ ] **Passo 8: Commit** via `/commit`. Título sugerido: `feat(pre): send protocols line by line with progress`.

---

### Tarefa 10: Registrar retorno, extravio e encerramento automático

**Arquivos:**
- Criar: `src/Pre/CostLabel.php`, `src/Pre/ReturnService.php`, `templates/pre/items_return_forms.html.twig`
- Modificar: `front/pre/repairprotocolitem.form.php`, `public/js/pre.js`
- Testar: `tests/Unit/CostLabelTest.php`

**Interfaces:**
- Consome: `TicketOps`, `StateGuard`, `ReturnActionResolver`, `PreSettings`, `StateMachine::normalizeDestination/deriveProtocolStatus/canRegisterReturn`, `RepairProtocolEvent::log`, `ServiceResult`.
- Produz:
  - `CostLabel::name(string $supplier, string $reference, string $asset): string` (puro): `Fornecedor - Nº OS/NF - Ativo`, partes vazias omitidas
  - `ReturnService::registerReturn(int $lineId, array $data): ServiceResult` — `$data` com `outcome`, `destination`, `date_return` (`Y-m-d`), `service_description`, `cost` (aceita vírgula decimal), `supplier_ref`, `warranty_until` (`Y-m-d` ou vazio)
  - `ReturnService::markLost(int $lineId, string $reason): ServiceResult`
  - `ReturnService::recalc(RepairProtocol $p): void` — recalcula o status do PRE e, se virar `Encerrado`, grava `date_closed` e o evento `closed`
  - POSTs de `repairprotocolitem.form.php`: `return` (com `line_id` e os campos) e `lost` (com `line_id`, `reason`)

- [ ] **Passo 1: `CostLabel` (teste primeiro)** — `tests/Unit/CostLabelTest.php`

```php
<?php

namespace GlpiPlugin\Gac\Tests\Unit;

use GlpiPlugin\Gac\Pre\CostLabel;
use PHPUnit\Framework\TestCase;

final class CostLabelTest extends TestCase
{
    public function testFullName(): void
    {
        $this->assertSame(
            'Assistência Teste Ltda - OS-1001 - NB-TESTE-001',
            CostLabel::name('Assistência Teste Ltda', 'OS-1001', 'NB-TESTE-001')
        );
    }

    public function testSkipsAnEmptyReferenceWithoutLeavingDoubleSeparators(): void
    {
        $this->assertSame('Assistência Teste Ltda - NB-TESTE-001', CostLabel::name('Assistência Teste Ltda', '', 'NB-TESTE-001'));
        $this->assertSame('Assistência Teste Ltda - NB-TESTE-001', CostLabel::name('Assistência Teste Ltda', '   ', 'NB-TESTE-001'));
    }

    public function testTrimsEveryPart(): void
    {
        $this->assertSame('A - B - C', CostLabel::name('  A ', ' B ', ' C  '));
    }

    public function testSkipsEmptySupplierAndAsset(): void
    {
        $this->assertSame('OS-1 - NB-1', CostLabel::name('', 'OS-1', 'NB-1'));
        $this->assertSame('A - OS-1', CostLabel::name('A', 'OS-1', ''));
        $this->assertSame('', CostLabel::name('', '', ''));
    }
}
```

Rode `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml --filter CostLabelTest` e veja falhar (`Class ... CostLabel not found`). Depois crie `src/Pre/CostLabel.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Pre;

/** Pure: the name of the ticket cost created for a returned line. */
final class CostLabel
{
    /** "Supplier - Nº OS/NF - Asset"; empty parts are skipped. */
    public static function name(string $supplier, string $reference, string $asset): string
    {
        return implode(' - ', array_filter(
            [trim($supplier), trim($reference), trim($asset)],
            static fn(string $part): bool => $part !== ''
        ));
    }
}
```

Rode a suíte: esperado `OK`.

- [ ] **Passo 2: Criar `src/Pre/ReturnService.php`**

```php
<?php

namespace GlpiPlugin\Gac\Pre;

use Ticket;
use Toolbox;

/**
 * Return registration per line (spec 7.4): apply the configured ticket and asset actions,
 * store the cost, mark the line, recalculate the PRE.
 */
final class ReturnService
{
    /** @param array<string, mixed> $data */
    public static function registerReturn(int $lineId, array $data): ServiceResult
    {
        global $DB;

        [$line, $protocol] = self::load($lineId);
        if ($line === null) {
            return ServiceResult::fail(__('Linha não encontrada.', 'gac'));
        }
        if (!StateMachine::canRegisterReturn($protocol->getStatus(), $line->getStatus())) {
            return ServiceResult::fail(__('Esta linha não está na assistência.', 'gac'));
        }

        $outcome = Outcome::tryFrom((string) ($data['outcome'] ?? ''));
        if ($outcome === null) {
            return ServiceResult::fail(__('Informe o resultado.', 'gac'));
        }
        try {
            $destination = StateMachine::normalizeDestination(
                $outcome,
                Destination::tryFrom((string) ($data['destination'] ?? ''))
            );
        } catch (\InvalidArgumentException) {
            return ServiceResult::fail($outcome->isDefective()
                ? __('Informe o destino do equipamento com defeito.', 'gac')
                : __('Este resultado não admite destino.', 'gac'));
        }

        $dateReturn = self::date((string) ($data['date_return'] ?? '')) ?? date('Y-m-d');
        $warranty   = self::date((string) ($data['warranty_until'] ?? ''));
        $cost       = self::cost((string) ($data['cost'] ?? ''));
        if ($cost === false) {
            return ServiceResult::fail(__('Custo inválido.', 'gac'));
        }

        $settings  = PreConfig::load();
        $actionKey = ReturnActionResolver::actionKey($outcome, $destination);
        try {
            $actions = ReturnActionResolver::resolve($actionKey, $settings);
        } catch (\DomainException $e) {
            return ServiceResult::fail($e->getMessage());
        }
        if (
            $actions['asset']['type'] === 'set_state'
            && !StateGuard::isUsable($actions['asset']['states_id'], (int) $line->fields['item_entities_id'])
        ) {
            return ServiceResult::fail(__('O status configurado não é válido para a entidade do ativo.', 'gac'));
        }

        $summary = self::summary($outcome, $destination, $dateReturn, (string) ($data['service_description'] ?? ''), $cost, (string) ($data['supplier_ref'] ?? ''), $warranty);

        try {
            $DB->beginTransaction();

            $ticket = new Ticket();
            if (!$ticket->getFromDB((int) $line->fields['tickets_id'])) {
                throw new \RuntimeException(__('Ticket não encontrado.', 'gac'));
            }
            $asset = getItemForItemtype($line->fields['itemtype']);
            if (!$asset || !$asset->getFromDB((int) $line->fields['items_id'])) {
                throw new \RuntimeException(__('Ativo não encontrado.', 'gac'));
            }

            // Asset status
            $newState = $actions['asset']['type'] === 'restore_previous'
                ? (int) ($line->fields['states_id_before'] ?? 0)
                : $actions['asset']['states_id'];
            if ((int) $asset->fields['states_id'] !== $newState) {
                if (!$asset->update(['id' => $asset->getID(), 'states_id' => $newState])) {
                    throw new \RuntimeException(__('Não foi possível alterar o status do ativo.', 'gac'));
                }
            }

            // Ticket action. While another line of the same ticket is still out (another asset
            // of a multi-asset ticket), the ticket keeps its status and reason: only the
            // summary follow-up is written. The last line to come back decides the status.
            if (self::hasOtherActiveLines($line)) {
                TicketOps::followup($ticket, $summary);
            } else {
                match ($actions['ticket']['type']) {
                    'reopen'       => TicketOps::leavePending($ticket, $summary),
                    'solve'        => TicketOps::solve($ticket, $summary),
                    'keep_pending' => TicketOps::keepPendingWithReason($ticket, $actions['ticket']['pendingreasons_id'], $summary),
                };
            }

            $costId = 0;
            if ($cost !== null && $cost > 0) {
                $costId = TicketOps::addCost(
                    $ticket,
                    CostLabel::name(
                        (string) $protocol->fields['supplier_name'],
                        (string) ($data['supplier_ref'] ?? ''),
                        (string) $line->fields['item_name']
                    ),
                    $cost,
                    $dateReturn
                );
            }

            $DB->update(
                RepairProtocolItem::getTable(),
                [
                    'status'              => ItemStatus::Returned->value,
                    'outcome'             => $outcome->value,
                    'destination'         => $destination->value,
                    'date_return'         => $dateReturn,
                    'service_description' => trim((string) ($data['service_description'] ?? '')),
                    'cost'                => $cost,
                    'supplier_ref'        => trim((string) ($data['supplier_ref'] ?? '')),
                    'warranty_until'      => $warranty,
                    'ticketcosts_id'      => $costId,
                    'last_error'          => null,
                    'date_mod'            => $_SESSION['glpi_currenttime'],
                ],
                ['id' => $lineId]
            );

            RepairProtocolEvent::log(
                (int) $protocol->getID(),
                'line_returned',
                '',
                ['outcome' => $outcome->value, 'destination' => $destination->value],
                $lineId
            );

            self::recalc($protocol);

            $DB->commit();
        } catch (\Throwable $e) {
            try {
                $DB->rollBack();
            } catch (\Throwable) {
                // not in a transaction
            }
            Toolbox::logInFile('gac', sprintf("registerReturn %d failed: %s\n", $lineId, $e->getMessage()));
            return ServiceResult::fail($e->getMessage());
        }

        return ServiceResult::ok(__('Retorno registrado.', 'gac'));
    }

    public static function markLost(int $lineId, string $reason): ServiceResult
    {
        global $DB;

        $reason = trim($reason);
        if ($reason === '') {
            return ServiceResult::fail(__('Informe a justificativa do extravio.', 'gac'));
        }
        [$line, $protocol] = self::load($lineId);
        if ($line === null) {
            return ServiceResult::fail(__('Linha não encontrada.', 'gac'));
        }
        if (!StateMachine::canRegisterReturn($protocol->getStatus(), $line->getStatus())) {
            return ServiceResult::fail(__('Esta linha não está na assistência.', 'gac'));
        }

        try {
            $DB->beginTransaction();

            $ticket = new Ticket();
            if (!$ticket->getFromDB((int) $line->fields['tickets_id'])) {
                throw new \RuntimeException(__('Ticket não encontrado.', 'gac'));
            }
            // Plain follow-up: the ticket stays as it is (pending).
            TicketOps::followup($ticket, sprintf(
                __('Equipamento %1$s marcado como extraviado no fornecedor (protocolo %2$s). Justificativa: %3$s', 'gac'),
                $line->fields['item_name'],
                $protocol->fields['number'],
                $reason
            ));

            $DB->update(
                RepairProtocolItem::getTable(),
                [
                    'status'      => ItemStatus::Lost->value,
                    'lost_reason' => $reason,
                    'date_mod'    => $_SESSION['glpi_currenttime'],
                ],
                ['id' => $lineId]
            );
            RepairProtocolEvent::log((int) $protocol->getID(), 'line_lost', $reason, [], $lineId);
            self::recalc($protocol);

            $DB->commit();
        } catch (\Throwable $e) {
            try {
                $DB->rollBack();
            } catch (\Throwable) {
                // not in a transaction
            }
            Toolbox::logInFile('gac', sprintf("markLost %d failed: %s\n", $lineId, $e->getMessage()));
            return ServiceResult::fail($e->getMessage());
        }

        return ServiceResult::ok(__('Linha marcada como extraviada.', 'gac'));
    }

    /** True when the same ticket still has another active line (in any PRE). */
    private static function hasOtherActiveLines(RepairProtocolItem $line): bool
    {
        return countElementsInTable(RepairProtocolItem::getTable(), [
            'tickets_id' => (int) $line->fields['tickets_id'],
            'status'     => [
                ItemStatus::PendingSend->value,
                ItemStatus::Sending->value,
                ItemStatus::AtSupplier->value,
            ],
            ['NOT' => ['id' => (int) $line->getID()]],
        ]) > 0;
    }

    /** Recomputes the PRE status from its lines; closing is automatic (spec D9). */
    public static function recalc(RepairProtocol $p): void
    {
        $p->getFromDB((int) $p->getID());
        $statuses = array_map(
            static fn(array $l): ItemStatus => ItemStatus::from($l['status']),
            $p->lines()
        );
        $new = StateMachine::deriveProtocolStatus($p->getStatus(), $statuses);
        if ($new === $p->getStatus()) {
            return;
        }
        if ($new === ProtocolStatus::Closed) {
            $p->changeStatus($new, ['date_closed' => $_SESSION['glpi_currenttime']]);
            RepairProtocolEvent::log((int) $p->getID(), 'closed');
            return;
        }
        $p->changeStatus($new);
    }

    /** @return array{0: ?RepairProtocolItem, 1: ?RepairProtocol} */
    private static function load(int $lineId): array
    {
        $line = new RepairProtocolItem();
        if (!$line->getFromDB($lineId)) {
            return [null, null];
        }
        $protocol = new RepairProtocol();
        $protocol->getFromDB((int) $line->fields['plugin_gac_repairprotocols_id']);
        return [$line, $protocol];
    }

    private static function date(string $value): ?string
    {
        $value = trim($value);
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        return ($d !== false && $d->format('Y-m-d') === $value) ? $value : null;
    }

    /** @return float|null|false null = empty, false = invalid */
    private static function cost(string $value): float|null|false
    {
        $value = trim(str_replace(',', '.', $value));
        if ($value === '') {
            return null;
        }
        return (is_numeric($value) && (float) $value >= 0) ? (float) $value : false;
    }

    private static function summary(
        Outcome $outcome,
        Destination $destination,
        string $date,
        string $service,
        ?float $cost,
        string $ref,
        ?string $warranty
    ): string {
        $parts = [
            sprintf(__('Retorno da assistência: %s.', 'gac'), Labels::outcome($outcome)),
            sprintf(__('Data: %s.', 'gac'), $date),
        ];
        if ($destination !== Destination::None) {
            $parts[] = sprintf(__('Destino: %s.', 'gac'), Labels::destination($destination));
        }
        if (trim($service) !== '') {
            $parts[] = sprintf(__('Serviço: %s.', 'gac'), trim($service));
        }
        if ($cost !== null) {
            $parts[] = sprintf(__('Custo: R$ %s.', 'gac'), number_format($cost, 2, ',', '.'));
        }
        if (trim($ref) !== '') {
            $parts[] = sprintf(__('OS/Nota do fornecedor: %s.', 'gac'), trim($ref));
        }
        if ($warranty !== null) {
            $parts[] = sprintf(__('Garantia até: %s.', 'gac'), $warranty);
        }
        return implode(' ', $parts);
    }
}
```

- [ ] **Passo 3: Criar `templates/pre/items_return_forms.html.twig`**

```twig
{% if can_return %}
    {% for line in lines %}
        {% if line.is_at_supplier %}
            <div class="card mb-3" data-gac-return-card>
                <div class="card-header">
                    <h4 class="card-title">
                        {{ __('Registrar retorno', 'gac') }}: #{{ line.tickets_id }} · {{ line.item_name }}
                    </h4>
                </div>
                <div class="card-body">
                    <form method="post" action="{{ form_url }}" data-gac-return-form>
                        <input type="hidden" name="_glpi_csrf_token" value="{{ csrf_token() }}">
                        <input type="hidden" name="protocol_id" value="{{ protocol.getID() }}">
                        <input type="hidden" name="line_id" value="{{ line.id }}">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">{{ __('Resultado', 'gac') }}</label>
                                <select class="form-select" name="outcome" data-gac-outcome required>
                                    <option value="">-----</option>
                                    {% for o in outcome_options %}
                                        <option value="{{ o.value }}" data-defective="{{ o.defective ? '1' : '0' }}">{{ o.label }}</option>
                                    {% endfor %}
                                </select>
                            </div>
                            <div class="col-md-4" data-gac-destination-group hidden>
                                <label class="form-label">{{ __('Destino', 'gac') }}</label>
                                <select class="form-select" name="destination" data-gac-destination>
                                    {% for d in destination_options %}
                                        <option value="{{ d.value }}">{{ d.label }}</option>
                                    {% endfor %}
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">{{ __('Data do retorno', 'gac') }}</label>
                                <input type="date" class="form-control" name="date_return" value="{{ 'now'|date('Y-m-d') }}">
                            </div>
                            <div class="col-md-12">
                                <label class="form-label">{{ __('Serviço executado', 'gac') }}</label>
                                <textarea class="form-control" name="service_description" rows="2"></textarea>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">{{ __('Custo (R$)', 'gac') }}</label>
                                <input type="text" class="form-control" name="cost" inputmode="decimal" placeholder="0,00">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">{{ __('Nº da OS ou nota do fornecedor', 'gac') }}</label>
                                <input type="text" class="form-control" name="supplier_ref">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">{{ __('Garantia até', 'gac') }}</label>
                                <input type="date" class="form-control" name="warranty_until">
                            </div>
                        </div>
                        <button type="submit" name="return" value="1" class="btn btn-primary mt-3">{{ __('Registrar retorno', 'gac') }}</button>
                    </form>

                    <hr>
                    <form method="post" action="{{ form_url }}" class="row g-2 align-items-end">
                        <input type="hidden" name="_glpi_csrf_token" value="{{ csrf_token() }}">
                        <input type="hidden" name="protocol_id" value="{{ protocol.getID() }}">
                        <input type="hidden" name="line_id" value="{{ line.id }}">
                        <div class="col-md-9">
                            <label class="form-label">{{ __('Marcar como extraviada (justificativa obrigatória)', 'gac') }}</label>
                            <input type="text" class="form-control" name="reason">
                        </div>
                        <div class="col-md-3">
                            <button type="submit" name="lost" value="1" class="btn btn-outline-danger w-100">{{ __('Marcar como extraviada', 'gac') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        {% endif %}
    {% endfor %}
{% endif %}
```

- [ ] **Passo 4: Acrescentar ao final do IIFE de `public/js/pre.js`** (antes do `})();` final) o comportamento do destino

```js
    // Destination only applies to defective outcomes; pre-select the usual one (spec 6.3).
    const DEFAULT_DESTINATION = { unrepairable: 'writeoff', quote_rejected: 'keep_defective' };

    document.addEventListener('change', function (event) {
        const select = event.target.closest('[data-gac-outcome]');
        if (!select) {
            return;
        }
        const form = select.closest('[data-gac-return-form]');
        const group = form.querySelector('[data-gac-destination-group]');
        const destination = form.querySelector('[data-gac-destination]');
        const defective = select.selectedOptions[0] && select.selectedOptions[0].dataset.defective === '1';
        group.hidden = !defective;
        destination.disabled = !defective;
        if (defective && DEFAULT_DESTINATION[select.value]) {
            destination.value = DEFAULT_DESTINATION[select.value];
        }
    });
```

- [ ] **Passo 5: Acrescentar os ramos em `front/pre/repairprotocolitem.form.php`** (no lugar do comentário `// (next tasks add: ...)`)

Acrescente os `use`:

```php
use GlpiPlugin\Gac\Pre\ReturnService;
```

E os ramos, encadeados como `elseif` antes do fechamento (troque o comentário por eles):

```php
elseif (isset($_POST['return']) || isset($_POST['lost'])) {
    if (
        !$protocol->canViewItem()
        || !Session::haveRight(RepairProtocol::$rightname, RepairProtocol::RIGHT_RETURN)
    ) {
        Html::displayRightError();
    }
    $lineId = (int) ($_POST['line_id'] ?? 0);
    $notify(isset($_POST['return'])
        ? ReturnService::registerReturn($lineId, $_POST)
        : ReturnService::markLost($lineId, (string) ($_POST['reason'] ?? '')));
}
```

Atenção: como o arquivo é uma cadeia `if / elseif`, coloque este ramo **antes** da linha `Html::back();` e **depois** do último `elseif` existente (`save_descriptions`), sem fechar a cadeia antes.

- [ ] **Passo 6: Lint e teste manual**

```bash
for f in $(find src front ajax -name '*.php'); do /c/xampp/php/php.exe -l "$f"; done
```

Com um PRE `Enviado` de 4 linhas (os quatro casos): (a) **Reparado** com custo `120,50`, OS e garantia: a linha vira `Devolvida`; o ticket sai de Pendente para o status anterior (Em atendimento) com o acompanhamento-resumo; o ativo volta ao status de antes do envio; o ticket ganhou um custo de R$ 120,50 (aba Custos); (b) **Sem defeito encontrado**: ticket reaberto, ativo restaurado, sem custo; (c) **Sem conserto** com destino **Baixa**: ticket continua Pendente com o motivo "Aguardando baixa patrimonial" e o ativo vai para "aguardando baixa"; (d) **Orçamento não aprovado** com destino **Manter com defeito**: ticket Pendente com "Aguardando decisão" e ativo "Com defeito"; (e) o destino só aparece para os resultados com defeito e vem pré-selecionado; (f) depois do 1º retorno o PRE fica `Retorno parcial`; quando as 4 linhas estão finais o PRE vira `Encerrado` sozinho, com `date_closed` e o evento "PRE encerrado"; (g) marcar uma linha como **extraviada** exige justificativa, grava acompanhamento e conta como final; (h) com a ação de "Reparado" configurada como **Solucionar** na Tarefa 7, o retorno soluciona o ticket; (i) no caso de baixa/manter, abra o ticket e confira o **motivo de pendência** e que, ao tirar o ticket de Pendente manualmente, ele volta ao status que tinha **antes do envio** (é o comportamento que a decisão 6 do plano restaura); (j) sem o direito **Registrar retorno**, os formulários não aparecem e o POST dá erro de direito. (k) **ticket com dois ativos** (dois PREs ou o mesmo): devolva uma linha com resultado de baixa enquanto a outra ainda está na assistência: o ticket **continua Pendente com o motivo "na assistência"** e só ganha o acompanhamento; o ativo daquela linha muda de status normalmente; ao devolver a última linha, a ação configurada é aplicada ao ticket; (l) o custo criado se chama `Fornecedor - Nº OS/NF - Ativo` (sem a OS, `Fornecedor - Ativo`).

- [ ] **Passo 7: Commit** via `/commit`. Título sugerido: `feat(pre): register returns, lost items and automatic closing`.

---

### Tarefa 11: Reabertura, correção dos dados de retorno e "Concluir correções"

**Arquivos:**
- Criar: `src/Pre/ReopenService.php`
- Modificar: `templates/pre/items_toolbar.html.twig`, `templates/pre/items_return_forms.html.twig`, `front/pre/repairprotocolitem.form.php`

**Interfaces:**
- Consome: `RepairProtocolEvent::log/isReopened` (Tarefas 6 e 8), `ReturnService::recalc` (Tarefa 10), `TicketOps` (Tarefa 9), `StateMachine::canReopen`.
- Produz:
  - `ReopenService::reopen(RepairProtocol $p, string $reason): ServiceResult`
  - `ReopenService::correctLine(int $lineId, array $data): ServiceResult` — `$data` com `date_return`, `service_description`, `cost`, `supplier_ref`, `warranty_until` (o resultado e o destino **não** mudam: spec 7.5)
  - `ReopenService::finishCorrections(RepairProtocol $p): ServiceResult`
  - POSTs: `reopen` (`protocol_id`, `reason`), `correct` (`protocol_id`, `line_id`, campos), `finish_corrections` (`protocol_id`)

- [ ] **Passo 1: Criar `src/Pre/ReopenService.php`**

```php
<?php

namespace GlpiPlugin\Gac\Pre;

use Ticket;
use TicketCost;
use Toolbox;

/**
 * Reopening a closed PRE (spec 7.5, D9). Reopening only unlocks the correction of the return
 * data; it never redoes the ticket or asset actions. The PRE stays "Retorno parcial" until
 * "Concluir correções" (plan decision 3).
 */
final class ReopenService
{
    public static function reopen(RepairProtocol $p, string $reason): ServiceResult
    {
        $reason = trim($reason);
        if ($reason === '') {
            return ServiceResult::fail(__('Informe o motivo da reabertura.', 'gac'));
        }
        if (!StateMachine::canReopen($p->getStatus())) {
            return ServiceResult::fail(__('Só é possível reabrir um PRE encerrado.', 'gac'));
        }

        $p->changeStatus(ProtocolStatus::Partial, ['date_closed' => null]);
        RepairProtocolEvent::log((int) $p->getID(), 'reopened', $reason);

        return ServiceResult::ok(__('PRE reaberto para correção.', 'gac'));
    }

    /** @param array<string, mixed> $data */
    public static function correctLine(int $lineId, array $data): ServiceResult
    {
        global $DB;

        $line = new RepairProtocolItem();
        if (!$line->getFromDB($lineId)) {
            return ServiceResult::fail(__('Linha não encontrada.', 'gac'));
        }
        $protocol = new RepairProtocol();
        $protocol->getFromDB((int) $line->fields['plugin_gac_repairprotocols_id']);

        if (
            $protocol->getStatus() !== ProtocolStatus::Partial
            || !RepairProtocolEvent::isReopened((int) $protocol->getID())
            || $line->getStatus() !== ItemStatus::Returned
        ) {
            return ServiceResult::fail(__('Esta linha só pode ser corrigida em um PRE reaberto.', 'gac'));
        }

        $date = self::date((string) ($data['date_return'] ?? '')) ?? (string) $line->fields['date_return'];
        $warranty = self::date((string) ($data['warranty_until'] ?? ''));
        $costRaw = trim(str_replace(',', '.', (string) ($data['cost'] ?? '')));
        if ($costRaw !== '' && (!is_numeric($costRaw) || (float) $costRaw < 0)) {
            return ServiceResult::fail(__('Custo inválido.', 'gac'));
        }
        $cost = $costRaw === '' ? null : (float) $costRaw;

        $after = [
            'date_return'         => $date,
            'service_description' => trim((string) ($data['service_description'] ?? '')),
            'cost'                => $cost,
            'supplier_ref'        => trim((string) ($data['supplier_ref'] ?? '')),
            'warranty_until'      => $warranty,
        ];
        $before = array_intersect_key($line->fields, $after);

        try {
            $DB->beginTransaction();

            // Keep the ticket cost in sync (spec 7.5).
            $costId = (int) $line->fields['ticketcosts_id'];
            $ticket = new Ticket();
            $ticket->getFromDB((int) $line->fields['tickets_id']);
            // The cost name carries the OS/NF number (D18), so a corrected number renames it.
            $costName = CostLabel::name(
                (string) $protocol->fields['supplier_name'],
                $after['supplier_ref'],
                (string) $line->fields['item_name']
            );
            if ($costId > 0) {
                $ok = (new TicketCost())->update([
                    'id'         => $costId,
                    'name'       => $costName,
                    'cost_fixed' => $cost ?? 0,
                    'begin_date' => $date,
                    'end_date'   => $date,
                ]);
                if (!$ok) {
                    throw new \RuntimeException(__('Não foi possível atualizar o custo do ticket.', 'gac'));
                }
            } elseif ($cost !== null && $cost > 0) {
                $costId = TicketOps::addCost($ticket, $costName, $cost, $date);
            }

            $DB->update(
                RepairProtocolItem::getTable(),
                $after + ['ticketcosts_id' => $costId, 'date_mod' => $_SESSION['glpi_currenttime']],
                ['id' => $lineId]
            );

            RepairProtocolEvent::log(
                (int) $protocol->getID(),
                'line_corrected',
                '',
                ['before' => $before, 'after' => $after],
                $lineId
            );

            $DB->commit();
        } catch (\Throwable $e) {
            try {
                $DB->rollBack();
            } catch (\Throwable) {
                // not in a transaction
            }
            Toolbox::logInFile('gac', sprintf("correctLine %d failed: %s\n", $lineId, $e->getMessage()));
            return ServiceResult::fail($e->getMessage());
        }

        return ServiceResult::ok(__('Dados corrigidos.', 'gac'));
    }

    public static function finishCorrections(RepairProtocol $p): ServiceResult
    {
        if (
            $p->getStatus() !== ProtocolStatus::Partial
            || !RepairProtocolEvent::isReopened((int) $p->getID())
        ) {
            return ServiceResult::fail(__('O PRE não está em correção.', 'gac'));
        }
        ReturnService::recalc($p);
        return ServiceResult::ok(__('Correções concluídas. PRE encerrado.', 'gac'));
    }

    private static function date(string $value): ?string
    {
        $value = trim($value);
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        return ($d !== false && $d->format('Y-m-d') === $value) ? $value : null;
    }
}
```

- [ ] **Passo 2: Substituir `templates/pre/items_toolbar.html.twig`** (a Tarefa 9 + os controles de reabertura)

```twig
<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
    {% if can_send %}
        {% if send_mode == 'start' %}
            <button type="button" class="btn btn-primary" data-gac-send="start">
                <i class="ti ti-send"></i> {{ __('Enviar', 'gac') }}
            </button>
        {% elseif send_mode == 'continue' %}
            <button type="button" class="btn btn-warning" data-gac-send="continue" data-line-ids="{{ pending_line_ids|json_encode }}">
                {{ __('Continuar envio', 'gac') }}
            </button>
        {% elseif send_mode == 'finalize' %}
            <button type="button" class="btn btn-warning" data-gac-send="finalize">
                {{ __('Concluir envio (gerar documento)', 'gac') }}
            </button>
        {% endif %}
    {% endif %}

    {% if is_draft and can_edit %}
        <form method="post" action="{{ protocol_form_url }}" class="d-inline">
            <input type="hidden" name="_glpi_csrf_token" value="{{ csrf_token() }}">
            <input type="hidden" name="id" value="{{ protocol.getID() }}">
            <button type="submit" name="cancel_protocol" value="1" class="btn btn-outline-danger">{{ __('Cancelar PRE', 'gac') }}</button>
        </form>
    {% endif %}

    {% if can_reopen and protocol.fields['status'] == 'closed' %}
        <form method="post" action="{{ form_url }}" class="d-flex gap-2">
            <input type="hidden" name="_glpi_csrf_token" value="{{ csrf_token() }}">
            <input type="hidden" name="protocol_id" value="{{ protocol.getID() }}">
            <input type="text" class="form-control" name="reason" placeholder="{{ __('Motivo da reabertura', 'gac') }}" required>
            <button type="submit" name="reopen" value="1" class="btn btn-outline-warning">{{ __('Reabrir', 'gac') }}</button>
        </form>
    {% endif %}

    {% if can_reopen and is_reopened %}
        <form method="post" action="{{ form_url }}" class="d-inline">
            <input type="hidden" name="_glpi_csrf_token" value="{{ csrf_token() }}">
            <input type="hidden" name="protocol_id" value="{{ protocol.getID() }}">
            <button type="submit" name="finish_corrections" value="1" class="btn btn-success">{{ __('Concluir correções', 'gac') }}</button>
        </form>
    {% endif %}

    <div class="flex-grow-1" data-gac-progress></div>
</div>
```

- [ ] **Passo 3: Acrescentar o bloco de correção ao final de `templates/pre/items_return_forms.html.twig`**

```twig
{% if can_reopen and is_reopened %}
    {% for line in lines %}
        {% if line.is_returned %}
            <div class="card mb-3">
                <div class="card-header">
                    <h4 class="card-title">{{ __('Corrigir retorno', 'gac') }}: #{{ line.tickets_id }} · {{ line.item_name }}</h4>
                </div>
                <div class="card-body">
                    <form method="post" action="{{ form_url }}">
                        <input type="hidden" name="_glpi_csrf_token" value="{{ csrf_token() }}">
                        <input type="hidden" name="protocol_id" value="{{ protocol.getID() }}">
                        <input type="hidden" name="line_id" value="{{ line.id }}">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">{{ __('Data do retorno', 'gac') }}</label>
                                <input type="date" class="form-control" name="date_return" value="{{ line.date_return }}">
                            </div>
                            <div class="col-md-8">
                                <label class="form-label">{{ __('Serviço executado', 'gac') }}</label>
                                <textarea class="form-control" name="service_description" rows="2">{{ line.service_description }}</textarea>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">{{ __('Custo (R$)', 'gac') }}</label>
                                <input type="text" class="form-control" name="cost" inputmode="decimal" value="{{ line.cost is not null ? line.cost|number_format(2, ',', '') : '' }}">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">{{ __('Nº da OS ou nota do fornecedor', 'gac') }}</label>
                                <input type="text" class="form-control" name="supplier_ref" value="{{ line.supplier_ref }}">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">{{ __('Garantia até', 'gac') }}</label>
                                <input type="date" class="form-control" name="warranty_until" value="{{ line.warranty_until }}">
                            </div>
                        </div>
                        <p class="text-muted small mt-2 mb-0">
                            {{ __('O resultado e o destino não mudam aqui. Corrigir o resultado é uma correção manual no ticket e no ativo.', 'gac') }}
                        </p>
                        <button type="submit" name="correct" value="1" class="btn btn-primary mt-3">{{ __('Salvar correção', 'gac') }}</button>
                    </form>
                </div>
            </div>
        {% endif %}
    {% endfor %}
{% endif %}
```

- [ ] **Passo 4: Acrescentar os ramos em `front/pre/repairprotocolitem.form.php`** (na mesma cadeia, depois do ramo `return`/`lost` e antes de `Html::back();`)

Acrescente o `use`:

```php
use GlpiPlugin\Gac\Pre\ReopenService;
```

E os ramos:

```php
elseif (isset($_POST['reopen']) || isset($_POST['correct']) || isset($_POST['finish_corrections'])) {
    if (
        !$protocol->canViewItem()
        || !Session::haveRight(RepairProtocol::$rightname, RepairProtocol::RIGHT_REOPEN)
    ) {
        Html::displayRightError();
    }
    if (isset($_POST['reopen'])) {
        $notify(ReopenService::reopen($protocol, (string) ($_POST['reason'] ?? '')));
    } elseif (isset($_POST['correct'])) {
        $notify(ReopenService::correctLine((int) ($_POST['line_id'] ?? 0), $_POST));
    } else {
        $notify(ReopenService::finishCorrections($protocol));
    }
}
```

- [ ] **Passo 5: Lint e teste manual**

```bash
for f in $(find src front ajax -name '*.php'); do /c/xampp/php/php.exe -l "$f"; done
```

Com um PRE `Encerrado`: (a) só um usuário com o direito **Reabrir** vê o campo de motivo e o botão; (b) sem motivo, o navegador impede o envio; reabra com um motivo: o PRE volta a `Retorno parcial`, o histórico registra "PRE reaberto" **com o motivo**; (c) aparecem os cartões "Corrigir retorno" nas linhas devolvidas; altere o custo de `120,50` para `99,90`: o **custo do ticket** também muda (aba Custos do ticket) e o histórico registra "Dados de retorno corrigidos" com o antes e o depois; (d) o resultado e o destino **não** podem ser alterados aqui; o ticket e o ativo **não** são tocados; (e) várias correções em sequência funcionam, e o PRE continua `Retorno parcial` até **Concluir correções**, que o leva a `Encerrado` (evento "PRE encerrado"); (f) tentar corrigir uma linha de um PRE que não foi reaberto (POST forjado) é recusado. (g) corrigir o **nº da OS/NF** também renomeia o custo do ticket (`Fornecedor - OS - Ativo`, D18), e corrigir uma linha que ainda não tinha custo cria o custo com o mesmo padrão; (h) depois de "Concluir correções", nova correção e novo "Concluir" são recusados sem gerar evento duplicado.

- [ ] **Passo 6: Commit** via `/commit`. Título sugerido: `feat(pre): reopen protocols and correct return data`.

### Tarefa 12: Relatório em PDF (mPDF), anexo no envio, prévia e download

**Arquivos:**
- Modificar: `composer.json`, `setup.php`, `hook.php`, `src/Pre/SendService.php` (`finalize`), `src/Pre/RepairProtocol.php` (aba de documentos), `src/Pre/RepairProtocolItem.php` (variáveis do botão de PDF), `templates/pre/items_toolbar.html.twig`
- Criar: `src/Pre/ReportFormatter.php`, `src/Pre/PdfRenderer.php`, `templates/pre/report.html.twig`, `front/pre/repairprotocol.pdf.php`, `tools/render-report-sample.php`
- Testar: `tests/Unit/ReportFormatterTest.php`

**Interfaces:**
- Consome: `PreConfig::load`, `PreSettings::logoCategoryId` (Tarefas 4, 7), `RepairProtocol::lines/getStatus/changeStatus` (Tarefa 6), `SendService::finalize` (Tarefa 9).
- Produz:
  - `ReportFormatter::addressLine(string $address, string $postcode, string $town, string $state): string`
  - `ReportFormatter::date(string $ymd): string` (`2026-09-25` → `25/09/2026`; o relatório usa sempre o formato brasileiro, não a preferência de data do usuário)
  - `PdfRenderer::render(RepairProtocol $p, bool $draft): array{bytes: string, filename: string}`
  - `PdfRenderer::attachFinal(RepairProtocol $p): int` (id do `Document` anexado ao PRE)
  - `PdfRenderer::logoDataUri(int $entityId): ?string`
  - URL `GET /plugins/gac/front/pre/repairprotocol.pdf.php?id=<protocolo>`: em rascunho, prévia com marca d'água; depois do envio, o arquivo guardado (`documents_id_sent`)

**Já validado fora do GLPI** (durante a elaboração deste plano): o template abaixo foi renderizado com mPDF 8.3.1 e Twig, com 32 linhas em 3 páginas A4 paisagem, e conferido visualmente (cabeçalho da tabela repetido nas páginas seguintes, total, assinaturas, marca d'água, rodapé "Página X de Y"). Com as fontes reduzidas às 4 variantes do DejaVu Sans o `vendor/` caiu de 95 MB para 9,6 MB e o PDF continuou idêntico.

- [ ] **Passo 1: Teste que falha** — `tests/Unit/ReportFormatterTest.php`

```php
<?php

namespace GlpiPlugin\Gac\Tests\Unit;

use GlpiPlugin\Gac\Pre\ReportFormatter;
use PHPUnit\Framework\TestCase;

final class ReportFormatterTest extends TestCase
{
    public function testFullAddress(): void
    {
        $this->assertSame(
            'Av. Tancredo Neves, 1234 — 76870-000 — Ariquemes/RO',
            ReportFormatter::addressLine('Av. Tancredo Neves, 1234', '76870-000', 'Ariquemes', 'RO')
        );
    }

    public function testSkipsEmptyParts(): void
    {
        $this->assertSame('76870-000 — Ariquemes', ReportFormatter::addressLine('', '76870-000', 'Ariquemes', ''));
        $this->assertSame('Rua A', ReportFormatter::addressLine(' Rua A ', '', '', ''));
        $this->assertSame('', ReportFormatter::addressLine('', '', '', ''));
    }

    public function testStateWithoutTownStandsAlone(): void
    {
        $this->assertSame('RO', ReportFormatter::addressLine('', '', '', 'RO'));
    }

    public function testBrazilianDate(): void
    {
        $this->assertSame('25/09/2026', ReportFormatter::date('2026-09-25'));
        $this->assertSame('01/01/2027', ReportFormatter::date('2027-01-01'));
    }

    public function testInvalidOrEmptyDateIsReturnedAsIs(): void
    {
        $this->assertSame('', ReportFormatter::date(''));
        $this->assertSame('não é data', ReportFormatter::date('não é data'));
        $this->assertSame('2026-13-45', ReportFormatter::date('2026-13-45'));
    }
}
```

- [ ] **Passo 2: Ver falhar e implementar `src/Pre/ReportFormatter.php`**

```bash
/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml --filter ReportFormatterTest
```

Esperado: `Class ... ReportFormatter not found`. Depois crie:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Pre;

/** Pure text formatting for the report header. */
final class ReportFormatter
{
    /** "2026-09-25" -> "25/09/2026"; anything that is not a valid Y-m-d date is returned as is. */
    public static function date(string $ymd): string
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $ymd);
        return ($d !== false && $d->format('Y-m-d') === $ymd) ? $d->format('d/m/Y') : $ymd;
    }

    public static function addressLine(string $address, string $postcode, string $town, string $state): string
    {
        $address  = trim($address);
        $postcode = trim($postcode);
        $town     = trim($town);
        $state    = trim($state);

        $place = $town !== '' && $state !== '' ? $town . '/' . $state : $town . $state;

        return implode(' — ', array_filter([$address, $postcode, $place], static fn(string $p): bool => $p !== ''));
    }
}
```

Rode a suíte: esperado `OK`.

- [ ] **Passo 3: Adicionar o mPDF ao `composer.json` e instalar**

Em `composer.json`, troque o `require` por:

```json
    "require": {
        "php": ">=8.2",
        "mpdf/mpdf": "^8.2"
    },
```

Baixe o Composer e instale (ele cria `vendor/` e `composer.lock`; o `vendor/` já está no `.gitignore`, o `composer.lock` **é commitado** para o release ser reproduzível):

```bash
curl -sSL --ssl-no-revoke -o var/tools/composer.phar https://getcomposer.org/download/latest-stable/composer.phar
/c/xampp/php/php.exe var/tools/composer.phar update --no-interaction
```

Esperado: `mpdf/mpdf` (8.3.x) instalado, sem erro. O `composer update` local instala também as dependências de desenvolvimento, se houver; o pacote de release usa `--no-dev`.

- [ ] **Passo 4: Criar `templates/pre/report.html.twig`**

```twig
<style>
    body { font-family: dejavusans, sans-serif; font-size: 9pt; color: #2b2f36; }
    table { border-collapse: collapse; }
    .header { width: 100%; margin-bottom: 4mm; }
    .header td { vertical-align: middle; }
    .logo img { max-height: 16mm; }
    .company { text-align: right; font-size: 8pt; line-height: 1.45; color: #4a5260; }
    .company .name { font-size: 10.5pt; font-weight: bold; color: #1f3a5f; }
    .rule { border-top: 0.6mm solid #1f3a5f; margin: 0 0 4mm 0; }
    .title { font-size: 17pt; font-weight: bold; color: #1f3a5f; text-align: left; margin: 0; }
    .subtitle { font-size: 8.5pt; color: #6b7280; margin: 0 0 4mm 0; }
    .meta { width: 100%; margin-bottom: 5mm; }
    .meta td { padding: 2.2mm 3mm; border: 0.2mm solid #d5dae1; font-size: 8.8pt; }
    .meta td.label { background-color: #eef1f6; color: #1f3a5f; font-weight: bold; width: 38mm; }
    .meta td.value { width: 40%; }
    .items { width: 100%; margin-bottom: 4mm; }
    .items th { background-color: #1f3a5f; color: #ffffff; font-size: 8pt; text-align: left; padding: 2.4mm 2mm; border: 0.2mm solid #1f3a5f; }
    .items td { font-size: 8pt; padding: 2mm; border: 0.2mm solid #d5dae1; vertical-align: top; }
    .items tr.zebra td { background-color: #f6f8fb; }
    .total { text-align: right; font-size: 10pt; font-weight: bold; color: #1f3a5f; margin: 2mm 0 6mm 0; }
    .receipt { font-size: 9pt; margin: 4mm 0 2mm 0; }
    .signatures { width: 100%; margin-top: 16mm; }
    .signatures td.sigcell { width: 40%; text-align: center; border-top: 0.3mm solid #4a5260; padding-top: 1.5mm; }
    .signatures td.gap { width: 20%; }
    .signame { font-weight: bold; font-size: 9.5pt; }
    .sigrole { font-size: 8pt; color: #6b7280; font-style: italic; }
</style>

<table class="header">
    <tr>
        <td class="logo">{% if company.logo_data_uri %}<img src="{{ company.logo_data_uri }}" alt="">{% endif %}</td>
        <td class="company">
            <div class="name">{{ company.name }}</div>
            {% if company.registration_number %}<div>{{ company.registration_number }}</div>{% endif %}
            {% if company.address_line %}<div>{{ company.address_line }}</div>{% endif %}
            {% if company.phone %}<div>{{ company.phone }}</div>{% endif %}
        </td>
    </tr>
</table>
<div class="rule"></div>

<p class="title">{{ __('PROTOCOLO DE REPARO DE EQUIPAMENTO', 'gac') }}</p>
<p class="subtitle">{{ protocol.number }}</p>

<table class="meta">
    <tr>
        <td class="label">{{ __('Fornecedor', 'gac') }}</td>
        <td class="value"><strong>{{ protocol.supplier_name }}</strong></td>
        <td class="label">{{ __('Nº do protocolo', 'gac') }}</td>
        <td class="value"><strong>{{ protocol.number }}</strong></td>
    </tr>
    <tr>
        <td class="label">{{ __('Técnico responsável', 'gac') }}</td>
        <td class="value">{{ protocol.technician }}</td>
        <td class="label">{{ __('Data de emissão', 'gac') }}</td>
        <td class="value">{{ protocol.date_issued }}</td>
    </tr>
</table>

<table class="items">
    <thead>
        <tr>
            <th style="width: 9%;">{{ __('Ticket', 'gac') }}</th>
            <th style="width: 11%;">{{ __('Tipo', 'gac') }}</th>
            <th style="width: 17%;">{{ __('Equipamento', 'gac') }}</th>
            <th style="width: 11%;">{{ __('Patrimônio', 'gac') }}</th>
            <th style="width: 14%;">{{ __('Nº de série', 'gac') }}</th>
            <th style="width: 38%;">{{ __('Descrição para o fornecedor', 'gac') }}</th>
        </tr>
    </thead>
    <tbody>
        {% for row in rows %}
            <tr class="{{ loop.index is even ? 'zebra' : '' }}">
                <td>#{{ row.tickets_id }}</td>
                <td>{{ row.type }}</td>
                <td>{{ row.name }}</td>
                <td>{{ row.otherserial|default('—') }}</td>
                <td>{{ row.serial|default('—') }}</td>
                <td>{{ row.description|default('—') }}</td>
            </tr>
        {% else %}
            <tr><td colspan="6" style="text-align: center;">{{ __('Nenhum item neste protocolo.', 'gac') }}</td></tr>
        {% endfor %}
    </tbody>
</table>

<div class="total">{{ __('Quantidade total de itens', 'gac') }}: {{ rows|length }}</div>

<div class="receipt">{{ __('Recebido em', 'gac') }}: ____/____/________ &nbsp;&nbsp;&nbsp; {{ __('às', 'gac') }} ____:____</div>

<table class="signatures" style="page-break-inside: avoid;">
    <tr>
        <td class="sigcell">
            <div class="signame">{{ protocol.supplier_name }}</div>
            <div class="sigrole">{{ __('Fornecedor', 'gac') }}</div>
        </td>
        <td class="gap"></td>
        <td class="sigcell">
            <div class="signame">{{ protocol.technician }}</div>
            <div class="sigrole">{{ __('Técnico', 'gac') }}</div>
        </td>
    </tr>
</table>
```

- [ ] **Passo 5: Criar `tools/render-report-sample.php`** (renderiza o mesmo template com dados de exemplo, **sem GLPI**, para iterar no visual; não vai para o pacote de release)

```php
<?php

/**
 * Dev-only: renders templates/pre/report.html.twig with sample data into var/sample.pdf,
 * without GLPI. Usage: php tools/render-report-sample.php [rows=12] [draft]
 * Needs the plugin's vendor/ (mPDF) and a Twig autoloader: set GLPI_AUTOLOAD to the GLPI
 * vendor/autoload.php (default: ../../vendor/autoload.php, i.e. the GLPI that hosts the plugin).
 */

$plugin = dirname(__DIR__);
require $plugin . '/vendor/autoload.php';
require getenv('GLPI_AUTOLOAD') ?: $plugin . '/../../vendor/autoload.php';

$twig = new \Twig\Environment(new \Twig\Loader\FilesystemLoader($plugin . '/templates/pre'), ['autoescape' => 'html']);
$twig->addFunction(new \Twig\TwigFunction('__', static fn(string $s): string => $s));

$n = (int) ($argv[1] ?? 12);
$rows = [];
for ($i = 1; $i <= $n; $i++) {
    $rows[] = [
        'tickets_id'  => 1000 + $i,
        'type'        => $i % 2 ? 'Notebook' : 'Nobreak',
        'name'        => 'PAT-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
        'otherserial' => '00' . (48000 + $i),
        'serial'      => 'SN' . strtoupper(dechex(9000000 + $i * 37)),
        'description' => $i % 3 === 0
            ? 'Equipamento não liga após queda de energia; verificar fonte, bateria e placa principal.'
            : 'Tela quebrada, não liga',
    ];
}

$html = $twig->render('report.html.twig', [
    'company'  => [
        'name' => 'Empresa de Exemplo', 'registration_number' => 'CNPJ 00.000.000/0001-00',
        'address_line' => 'Av. Exemplo, 100 — 00000-000 — Cidade/UF', 'phone' => '(00) 0000-0000',
        'logo_data_uri' => null,
    ],
    'protocol' => [
        'number' => 'PRE-2026-001', 'supplier_name' => 'Assistência Técnica Exemplo Ltda',
        'technician' => 'Técnico de Exemplo', 'date_issued' => '24/09/2026',
    ],
    'rows' => $rows,
]);

@mkdir($plugin . '/var/mpdf', 0777, true);
$mpdf = new \Mpdf\Mpdf([
    'mode' => 'utf-8', 'format' => 'A4-L',
    'margin_left' => 10, 'margin_right' => 10, 'margin_top' => 10, 'margin_bottom' => 16,
    'default_font' => 'dejavusans', 'tempDir' => $plugin . '/var/mpdf',
]);
$mpdf->SetHTMLFooter('<table width="100%" style="font-size:7.5pt;color:#6b7280;"><tr><td>PRE-2026-001</td><td align="right">Página {PAGENO} de {nb}</td></tr></table>');
if (($argv[2] ?? '') === 'draft') {
    $mpdf->SetWatermarkText('RASCUNHO', 0.08);
    $mpdf->showWatermarkText = true;
}
$mpdf->WriteHTML($html);
file_put_contents($plugin . '/var/sample.pdf', $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN));
echo "var/sample.pdf written\n";
```

Rode e abra o `var/sample.pdf`:

```bash
/c/xampp/php/php.exe tools/render-report-sample.php 32 draft
```

Esperado: `var/sample.pdf written`; um PDF A4 paisagem de 3 páginas com faixa de título azul, tabela zebrada, marca d'água clara e rodapé com "Página X de 3".

- [ ] **Passo 6: Criar `src/Pre/PdfRenderer.php`**

```php
<?php

namespace GlpiPlugin\Gac\Pre;

use Document;
use Entity;
use Glpi\Application\View\TemplateRenderer;

/**
 * PDF of the PRE (spec section 9): Twig template -> HTML -> mPDF. The final PDF is generated
 * once, at the end of "Enviar", and stored as a Document attached to the PRE (D11).
 */
final class PdfRenderer
{
    private const LOGO_MAX_BYTES = 2_000_000;

    /** @return array{bytes: string, filename: string} */
    public static function render(RepairProtocol $p, bool $draft): array
    {
        self::loadMpdf();

        $html = TemplateRenderer::getInstance()->render('@gac/pre/report.html.twig', self::templateVars($p));

        $tmp = GLPI_TMP_DIR . '/gac-mpdf';
        if (!is_dir($tmp)) {
            mkdir($tmp, 0770, true);
        }
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8', 'format' => 'A4-L',
            'margin_left' => 10, 'margin_right' => 10, 'margin_top' => 10, 'margin_bottom' => 16,
            'default_font' => 'dejavusans', 'tempDir' => $tmp,
        ]);
        $mpdf->SetHTMLFooter(sprintf(
            '<table width="100%%" style="font-size:7.5pt;color:#6b7280;"><tr><td>%s</td><td align="right">%s {PAGENO} / {nb}</td></tr></table>',
            htmlescape($p->fields['number']),
            htmlescape(__('Página', 'gac'))
        ));
        if ($draft) {
            $mpdf->SetWatermarkText(__('RASCUNHO', 'gac'), 0.08);
            $mpdf->showWatermarkText = true;
        }
        $mpdf->WriteHTML($html);

        return [
            'bytes'    => $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN),
            'filename' => $p->fields['number'] . '.pdf',
        ];
    }

    /** Generates the definitive PDF and attaches it to the PRE. @return int Document id */
    public static function attachFinal(RepairProtocol $p): int
    {
        $rendered = self::render($p, false);

        // GLPI's upload convention: the temp file is "<prefix><name>" and the prefix is passed along.
        $prefix  = bin2hex(random_bytes(4)) . '_';
        $tmpName = $prefix . $rendered['filename'];
        file_put_contents(GLPI_TMP_DIR . '/' . $tmpName, $rendered['bytes']);

        $id = (new Document())->add([
            'name'             => sprintf('%s (%s)', $p->fields['number'], __('envio', 'gac')),
            'entities_id'      => (int) $p->fields['entities_id'],
            '_filename'        => [$tmpName],
            '_prefix_filename' => [$prefix],
            'itemtype'         => RepairProtocol::class,
            'items_id'         => (int) $p->getID(),
        ]);
        if (!$id) {
            throw new \RuntimeException('Could not attach the PDF to the protocol.');
        }
        return (int) $id;
    }

    /**
     * Latest image Document of the configured category linked to the entity (or, failing that,
     * to the nearest ancestor that has one), as a data URI. Null when there is none (spec 10).
     */
    public static function logoDataUri(int $entityId): ?string
    {
        global $DB;

        $categoryId = PreSettings::logoCategoryId(PreConfig::load());
        if ($categoryId === 0) {
            return null;
        }

        $entity = new Entity();
        for ($e = $entityId; $e >= 0; ) {
            $row = $DB->request([
                'SELECT'     => ['glpi_documents.filepath', 'glpi_documents.mime'],
                'FROM'       => 'glpi_documents_items',
                'INNER JOIN' => [
                    'glpi_documents' => [
                        'ON' => ['glpi_documents_items' => 'documents_id', 'glpi_documents' => 'id'],
                    ],
                ],
                'WHERE' => [
                    'glpi_documents_items.itemtype'         => 'Entity',
                    'glpi_documents_items.items_id'         => $e,
                    'glpi_documents.documentcategories_id'  => $categoryId,
                    'glpi_documents.is_deleted'             => 0,
                    'glpi_documents.mime'                   => ['image/png', 'image/jpeg', 'image/gif'],
                ],
                'ORDER' => ['glpi_documents.date_creation DESC', 'glpi_documents.id DESC'],
                'LIMIT' => 1,
            ])->current();

            if ($row !== null) {
                $path = GLPI_DOC_DIR . '/' . $row['filepath'];
                if (is_file($path) && filesize($path) <= self::LOGO_MAX_BYTES) {
                    return 'data:' . $row['mime'] . ';base64,' . base64_encode((string) file_get_contents($path));
                }
            }

            if (!$entity->getFromDB($e)) {
                break;
            }
            $e = (int) $entity->fields['entities_id']; // root entity's parent is -1: loop ends
        }
        return null;
    }

    /** @return array<string, mixed> */
    private static function templateVars(RepairProtocol $p): array
    {
        $entity = new Entity();
        $entity->getFromDB((int) $p->fields['entities_id']);
        $f = $entity->fields;

        $rows = [];
        foreach ($p->lines() as $line) {
            $rows[] = [
                'tickets_id'  => (int) $line['tickets_id'],
                'type'        => (string) $line['item_type_label'],
                'name'        => (string) $line['item_name'],
                'otherserial' => (string) $line['otherserial'],
                'serial'      => (string) $line['serial'],
                'description' => (string) $line['description_supplier'],
            ];
        }

        return [
            'company' => [
                'name'                => (string) ($f['name'] ?? ''),
                'registration_number' => (string) ($f['registration_number'] ?? ''),
                'address_line'        => ReportFormatter::addressLine(
                    (string) ($f['address'] ?? ''),
                    (string) ($f['postcode'] ?? ''),
                    (string) ($f['town'] ?? ''),
                    (string) ($f['state'] ?? '')
                ),
                'phone'               => (string) ($f['phonenumber'] ?? ''),
                'logo_data_uri'       => self::logoDataUri((int) $p->fields['entities_id']),
            ],
            'protocol' => [
                'number'        => (string) $p->fields['number'],
                'supplier_name' => (string) $p->fields['supplier_name'],
                'technician'    => getUserName((int) $p->fields['users_id_tech']),
                'date_issued'   => ReportFormatter::date((string) $p->fields['date_issued']),
            ],
            'rows' => $rows,
        ];
    }

    private static function loadMpdf(): void
    {
        if (class_exists(\Mpdf\Mpdf::class)) {
            return;
        }
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            throw new \RuntimeException(
                'mPDF is missing: use the release package or run "composer install --no-dev" in the plugin folder.'
            );
        }
        require_once $autoload;
    }
}
```

- [ ] **Passo 7: `finalize` passa a gerar e anexar o PDF.** Em `src/Pre/SendService.php`, troque o corpo de `finalize` (o comentário da classe "The PDF is added by task 12" sai):

```php
    /** Last step: every line is at the supplier; generate and attach the definitive PDF (D11). */
    public static function finalize(RepairProtocol $p): ServiceResult
    {
        if ($p->getStatus() !== ProtocolStatus::Sent) {
            return ServiceResult::fail(__('O PRE não está em envio.', 'gac'));
        }
        // Idempotent: the document already exists.
        if ((int) $p->fields['documents_id_sent'] > 0) {
            return ServiceResult::ok();
        }

        $pending = 0;
        foreach ($p->lines() as $line) {
            if ($line['status'] !== ItemStatus::AtSupplier->value) {
                $pending++;
            }
        }
        if ($pending > 0) {
            return ServiceResult::fail(sprintf(
                _n('Ainda há %d linha pendente.', 'Ainda há %d linhas pendentes.', $pending, 'gac'),
                $pending
            ));
        }

        try {
            $documentId = PdfRenderer::attachFinal($p);
        } catch (\Throwable $e) {
            Toolbox::logInFile('gac', sprintf("finalize %d failed: %s\n", $p->getID(), $e->getMessage()));
            return ServiceResult::fail(__('Não foi possível gerar o PDF. Tente "Concluir envio" novamente.', 'gac'));
        }

        $p->changeStatus(ProtocolStatus::Sent, ['documents_id_sent' => $documentId]);
        RepairProtocolEvent::log((int) $p->getID(), 'send_finalized');

        return ServiceResult::ok();
    }
```

- [ ] **Passo 8: Aba de documentos no PRE.** Em `RepairProtocol::defineTabs()`, acrescente antes de `Log`:

```php
        $this->addStandardTab(\Document_Item::class, $tabs, $options);
```

- [ ] **Passo 9: Botões de PDF.** Em `RepairProtocolItem::showForProtocol()` acrescente ao array passado ao Twig:

```php
            'pdf_url'            => str_replace('.form.php', '.pdf.php', RepairProtocol::getFormURL()) . '?id=' . (int) $protocol->getID(),
            'can_pdf'            => $viewable && ($isDraft ? $lines !== [] : (int) $protocol->fields['documents_id_sent'] > 0),
```

E em `templates/pre/items_toolbar.html.twig`, logo antes de `<div class="flex-grow-1" data-gac-progress></div>`, acrescente:

```twig
    {% if can_pdf %}
        <a class="btn btn-outline-secondary" href="{{ pdf_url }}" target="_blank" rel="noopener">
            <i class="ti ti-file-type-pdf"></i>
            {{ is_draft ? __('Pré-visualizar PDF', 'gac') : __('Baixar PDF de envio', 'gac') }}
        </a>
    {% endif %}
```

- [ ] **Passo 10: Criar `front/pre/repairprotocol.pdf.php`**

```php
<?php

include('../../../../inc/includes.php');

use GlpiPlugin\Gac\Pre\PdfRenderer;
use GlpiPlugin\Gac\Pre\ProtocolStatus;
use GlpiPlugin\Gac\Pre\RepairProtocol;

$protocol = new RepairProtocol();
if (!$protocol->getFromDB((int) ($_GET['id'] ?? 0)) || !$protocol->canViewItem()) {
    Html::displayRightError();
}

$status = $protocol->getStatus();

$send = static function (string $bytes, string $filename): never {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, no-store');
    echo $bytes;
    exit;
};

if ($status === ProtocolStatus::Draft) {
    $r = PdfRenderer::render($protocol, true);
    $send($r['bytes'], $r['filename']);
}

$documentId = (int) $protocol->fields['documents_id_sent'];
if ($documentId > 0) {
    $document = new Document();
    if ($document->getFromDB($documentId)) {
        $path = realpath(GLPI_DOC_DIR . '/' . $document->fields['filepath']);
        if ($path !== false && str_starts_with($path, realpath(GLPI_DOC_DIR)) && is_file($path)) {
            $send((string) file_get_contents($path), $protocol->fields['number'] . '.pdf');
        }
    }
}

Html::displayNotFoundError();
```

- [ ] **Passo 11: Pré-requisito e garantia do tipo PDF.** Em `setup.php`, substitua `plugin_gac_check_prerequisites`:

```php
function plugin_gac_check_prerequisites(): bool
{
    if (!is_file(__DIR__ . '/vendor/autoload.php')) {
        echo __('Dependências ausentes (mPDF): use o pacote de release do plugin ou rode "composer install --no-dev" na pasta do plugin.', 'gac');
        return false;
    }
    return true;
}
```

Em `hook.php`, dentro de `plugin_gac_install()`, antes de `$migration->executeMigration();`:

```php
    // The definitive PDF is stored through Document, which only accepts registered types.
    if (countElementsInTable('glpi_documenttypes', ['ext' => 'pdf']) === 0) {
        $DB->insert('glpi_documenttypes', [
            'name'          => 'PDF',
            'ext'           => 'pdf',
            'mime'          => 'application/pdf',
            'is_uploadable' => 1,
        ]);
    }
```

(O tipo não é removido no `uninstall`: é estado compartilhado do GLPI.)

- [ ] **Passo 12: Lint, testes e reinstalação de desenvolvimento**

```bash
for f in setup.php hook.php $(find src front ajax -name '*.php'); do /c/xampp/php/php.exe -l "$f"; done
/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml
```

O `install()` só roda de novo se a versão do plugin mudar ou se ele for reinstalado. No GLPI **de desenvolvimento**:

```bash
cd /c/Users/juliano/VSCode/glpi-xampp-dev-plugin
/c/xampp/php/php.exe bin/console plugin:uninstall gac --username=glpi
/c/xampp/php/php.exe bin/console plugin:install gac --username=glpi
/c/xampp/php/php.exe bin/console plugin:activate gac
```

(O `uninstall` apaga as tabelas do plugin e a configuração; refaça o mapeamento da Tarefa 7.)

- [ ] **Passo 13: Teste manual**

(a) Em um PRE em rascunho com linhas, **Pré-visualizar PDF** abre o PDF A4 paisagem com a marca d'água clara "RASCUNHO", o cabeçalho da entidade do PRE e a tabela com a **descrição para o fornecedor** (nunca o título do ticket); (b) edite a descrição de uma linha, salve, e a prévia reflete a mudança; (c) no cadastro da **entidade** (aba Documentos), anexe uma imagem PNG cuja categoria de documento seja a escolhida na configuração: a logo aparece no cabeçalho; anexe uma segunda imagem na mesma categoria: vale a **última enviada**; numa subentidade **sem** logo, vale a da entidade pai; sem nenhuma, o PDF sai sem logo, sem erro; (d) o cabeçalho traz nome, CNPJ (`registration_number`), endereço e telefone do cadastro da entidade; (e) **Enviar** até o fim: o PDF definitivo (sem marca d'água) fica anexado na aba **Documentos** do PRE e o botão vira **Baixar PDF de envio**; (f) abra o PDF baixado depois de alterar a descrição de uma linha direto no banco: o arquivo **não muda** (é o arquivo guardado); (g) sem o `vendor/`, a ativação do plugin é recusada com a mensagem de dependências ausentes; (h) 50 linhas geram um PDF de vários trechos com o cabeçalho da tabela repetido e a numeração "Página X / Y". Dica: o visualizador de PDF do Chrome demora alguns segundos para desenhar a página; uma tela em branco logo após abrir não é erro.

- [ ] **Passo 14: Commit** via `/commit` (inclua `composer.lock`). Título sugerido: `feat(pre): generate and attach the PDF with mPDF`.

---

### Tarefa 13: Roteiro de teste manual e medição de desempenho

**Arquivos:**
- Criar: `docs/pre-manual-tests.md`

**Interfaces:**
- Consome: todas as tarefas anteriores.
- Produz: o roteiro que fecha a spec (seção 14) e os números de tempo do "Enviar" (spec 7.3, "Medição").

- [ ] **Passo 1: Criar `docs/pre-manual-tests.md`**

````markdown
# PRE: roteiro de teste manual

Pré-requisitos: GLPI local com o plugin instalado e ativo; a configuração do Gac completa
(categorias, status do ativo, motivos de pendência); um fornecedor; tickets na categoria
elegível com um ativo associado; um usuário com todos os direitos do PRE e outro sem nenhum.

| # | Cenário | Como | Esperado |
|---|---|---|---|
| 1 | Numeração | Criar 3 PREs | `PRE-AAAA-001`, `-002`, `-003` |
| 2 | Elegibilidade | Ticket fora da categoria; fechado; sem ativo; de outra entidade | Nenhum aparece em "Importar tickets" |
| 3 | Subcategoria | Ticket em subcategoria de uma categoria marcada | Aparece; com "Incluir subcategorias" = Não, não aparece |
| 4 | Subentidade | PRE na entidade pai, ticket na subentidade | Aparece |
| 5 | Vários ativos | Ticket com 2 ativos | 2 opções; importar só 1 é possível |
| 6 | Exclusividade | Mesmo par ticket+ativo em outro PRE | Não aparece enquanto a linha estiver ativa |
| 7 | Descrição | Ticket com "Informações adicionais" | Descrição inicial igual ao texto; sem o bloco, igual ao título |
| 8 | Envio feliz | Enviar 3 linhas | PRE `Enviado`, linhas `Na assistência`, ticket Pendente com motivo, ativo com status "na assistência", PDF anexado |
| 9 | Pré-checagem | Desmapear o status "na assistência" e enviar | Recusa citando o papel; nada alterado |
| 10 | Falha de linha | Forçar erro em 1 linha | Linha `Aguardando envio` com erro; "Continuar envio" reprocessa só ela |
| 11 | Linha com falha | "Remover linha com falha" sem/ com motivo | Exige motivo; grava evento; última linha removida cancela o PRE |
| 12 | Clique duplo | Clicar 2x em "Enviar" | Um só acompanhamento por ticket |
| 13 | Reparado | Retorno "Reparado" com custo | Ticket reaberto; ativo restaurado; custo no ticket |
| 14 | Sem defeito | Retorno "Sem defeito encontrado" | Ticket reaberto; ativo restaurado |
| 15 | Baixa | "Sem conserto" + destino Baixa | Ticket pendente "Aguardando baixa patrimonial"; ativo "aguardando baixa" |
| 16 | Manter | "Orçamento não aprovado" + Manter com defeito | Ticket pendente "Aguardando decisão"; ativo "com defeito" |
| 17 | Extravio | "Marcar como extraviada" | Exige justificativa; conta como final |
| 18 | Encerramento | Última linha em estado final | PRE `Encerrado` sozinho, `date_closed` preenchida |
| 19 | Status anterior | Tirar do Pendente um ticket de "Baixa" ou "Manter" | Volta ao status que tinha antes do envio (não a "Pendente") |
| 20 | Reabertura | Reabrir com motivo; corrigir custo; concluir | Motivo no histórico; custo do ticket atualizado; `Encerrado` só após "Concluir correções" |
| 21 | Sem reabertura | Corrigir linha de PRE não reaberto (POST forjado) | Recusado |
| 22 | Direitos | Usuário sem "Enviar"/"Registrar retorno"/"Reabrir" | Botões ausentes e endpoints recusam |
| 23 | Cancelar | Cancelar PRE em rascunho | Linhas apagadas; pares voltam a ser candidatos |
| 24 | Configuração | Salvar; criar status pela caixa "criar novo" | Persiste; status criado só com a ação do administrador |
| 26 | Ticket com dois ativos | Devolver uma linha enquanto a outra está fora | Ticket mantém status e motivo, só acompanhamento; a última linha aplica a ação |
| 27 | Nome do custo | Retorno com custo e nº da OS | Custo do ticket chamado `Fornecedor - OS - Ativo` |
| 25 | PDF | Prévia, definitivo, logo da entidade, 50 linhas | Ver Tarefa 12, passo 13 |
````

- [ ] **Passo 2: Executar o roteiro inteiro** e marcar cada linha. Qualquer divergência vira correção **na tarefa a que o cenário pertence**, com novo commit.

- [ ] **Passo 3: Medir o tempo do "Enviar"** (spec 7.3). Com o navegador em **Ferramentas do desenvolvedor > Rede**, filtro `pre_send.php`:
  1. Envie um PRE de **10 linhas** (quanto mais linhas, melhor a estimativa; 50 é o alvo da spec, use a ação em massa "Duplicar" nos tickets se ela existir no seu GLPI, ou crie os tickets à mão).
  2. Anote a coluna **Tempo** de cada requisição `action=line`, e o de `start` e `finalize`.
  3. Registre no fim de `docs/pre-manual-tests.md`: tempo por linha (média e maior), tempo do `finalize` (PDF) e a estimativa para 50 linhas (`média x 50`).
  4. **Critério:** cada requisição deve ficar bem abaixo do `max_execution_time` do PHP (padrão 30 s no XAMPP). Se uma linha passar de ~3 s, investigue (provável causa: notificações síncronas do ticket) antes de liberar.

- [ ] **Passo 4: Commit** via `/commit`. Título sugerido: `docs(pre): add manual test script and send timing notes`.

---

### Tarefa 14: Workflow de release (D13)

**Arquivos:**
- Criar: `tools/build-release.sh`, `.release-exclude`, `.github/workflows/release.yml`

**Interfaces:**
- Consome: `composer.json`/`composer.lock` (Tarefa 12), `setup.php` (`PLUGIN_GAC_VERSION`), `gac.xml`.
- Produz: `dist/gac-<versão>.zip` com a pasta raiz `gac/`, pronto para colar em `plugins/`, com `vendor/` (mPDF com só as 4 variantes do DejaVu Sans), sem arquivos de desenvolvimento. O workflow roda ao criar a tag `v<versão>` e falha se a tag divergir de `PLUGIN_GAC_VERSION` ou do `gac.xml`.

- [ ] **Passo 1: Criar `.release-exclude`** (lista usada pelo `tar --exclude-from`)

```
./.git
./.github
./.gitignore
./.glpi-coverage.json
./.php-cs-fixer.php
./.release-exclude
./.twig_cs.dist.php
./CLAUDE.md
./Makefile
./dist
./docs
./phpstan.neon
./phpunit.unit.xml
./phpunit.xml
./psalm.xml
./rector.php
./tests
./tools
./var
./vendor
```

- [ ] **Passo 2: Criar `tools/build-release.sh`**

```bash
#!/usr/bin/env bash
# Builds dist/gac-<version>.zip, ready to be unpacked into GLPI's plugins/ folder.
# Env: COMPOSER_BIN (default "composer"; do not use COMPOSER: Composer itself reads that variable), EXPECTED_VERSION (fails when it differs from setup.php).
set -euo pipefail
cd "$(dirname "$0")/.."

COMPOSER_BIN="${COMPOSER_BIN:-composer}"

VERSION="$(sed -nE "s/.*define\('PLUGIN_GAC_VERSION', '([^']+)'.*/\1/p" setup.php | head -1)"
XML_VERSION="$(sed -nE 's/.*<num>([^<]+)<\/num>.*/\1/p' gac.xml | head -1)"

[ -n "$VERSION" ] || { echo "Could not read PLUGIN_GAC_VERSION from setup.php" >&2; exit 1; }
[ "$VERSION" = "$XML_VERSION" ] || { echo "Version mismatch: setup.php=$VERSION gac.xml=$XML_VERSION" >&2; exit 1; }
if [ -n "${EXPECTED_VERSION:-}" ] && [ "$VERSION" != "$EXPECTED_VERSION" ]; then
    echo "Version mismatch: tag=$EXPECTED_VERSION setup.php=$VERSION" >&2
    exit 1
fi

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/gac"

# Copy the plugin without development files (vendor/ is rebuilt below from the lock file).
tar --exclude-from=.release-exclude -cf - . | tar -xf - -C "$STAGE/gac"

( cd "$STAGE/gac" && $COMPOSER_BIN install --no-dev --optimize-autoloader --no-interaction )

# mPDF ships ~88 MB of fonts; the report only uses DejaVu Sans.
FONTS="$STAGE/gac/vendor/mpdf/mpdf/ttfonts"
if [ -d "$FONTS" ]; then
    find "$FONTS" -type f \
        ! -name 'DejaVuSans.ttf' ! -name 'DejaVuSans-Bold.ttf' \
        ! -name 'DejaVuSans-Oblique.ttf' ! -name 'DejaVuSans-BoldOblique.ttf' \
        -delete
fi
rm -f "$STAGE/gac/composer.lock"

mkdir -p dist
OUT="$(pwd)/dist/gac-$VERSION.zip"
rm -f "$OUT"
if command -v zip >/dev/null 2>&1; then
    ( cd "$STAGE" && zip -qr "$OUT" gac )
else
    PY=""
    for candidate in python3 python; do
        if command -v "$candidate" >/dev/null 2>&1 && "$candidate" -c 'import zipfile' >/dev/null 2>&1; then
            PY="$candidate"
            break
        fi
    done
    [ -n "$PY" ] || { echo "Neither zip nor a working python was found" >&2; exit 1; }
    ( cd "$STAGE" && "$PY" -m zipfile -c "$OUT" gac )
fi

echo "Built $OUT ($(du -h "$OUT" | cut -f1))"
```

- [ ] **Passo 3: Criar `.github/workflows/release.yml`**

```yaml
name: Release

on:
  push:
    tags:
      - 'v*'

permissions:
  contents: write

jobs:
  package:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          extensions: mbstring, gd
          tools: composer:v2

      - name: Build the package
        env:
          EXPECTED_VERSION: ${{ github.ref_name }}
        run: |
          export EXPECTED_VERSION="${EXPECTED_VERSION#v}"
          bash tools/build-release.sh

      - name: Publish the release
        uses: softprops/action-gh-release@v2
        with:
          files: dist/gac-*.zip
          generate_release_notes: true
```

- [ ] **Passo 4: Testar o script localmente** (numa cópia, para não sujar o repositório):

```bash
COMPOSER_BIN="/c/xampp/php/php.exe $(pwd)/var/tools/composer.phar" bash tools/build-release.sh
unzip -l dist/gac-*.zip | tail -3 2>/dev/null || /c/xampp/php/php.exe -r '$z=new ZipArchive; $z->open(glob("dist/gac-*.zip")[0]); echo $z->numFiles, " files\n"; for($i=0;$i<$z->numFiles;$i++){ $n=$z->getNameIndex($i); if(preg_match("#^gac/[^/]+/?$#",$n)) echo $n,"\n"; }'
```

Esperado: `Built .../dist/gac-0.1.0.zip (~4 MB)` (o tamanho exato varia com a versão do mPDF; medido na elaboração: 4,0 MB, 705 arquivos) e a raiz do zip contendo `gac/` com `setup.php`, `hook.php`, `gac.xml`, `src/`, `front/`, `ajax/`, `public/`, `templates/`, `vendor/` e **sem** `tests/`, `docs/`, `tools/`, `.github/`, `var/`, `CLAUDE.md`. Confirme também que `vendor/mpdf/mpdf/ttfonts/` tem só 4 arquivos.

Teste a trava de versão:

```bash
EXPECTED_VERSION=9.9.9 COMPOSER_BIN="/c/xampp/php/php.exe $(pwd)/var/tools/composer.phar" bash tools/build-release.sh; echo "exit=$?"
```

Esperado: `Version mismatch: tag=9.9.9 setup.php=0.1.0` e `exit=1`.

- [ ] **Passo 5: Teste de instalação do pacote.** Descompacte o zip numa pasta `plugins/gac` de um GLPI **de teste** (ou renomeie temporariamente a junção do GLPI de desenvolvimento), instale e ative o plugin e execute os cenários 8 e 25 do roteiro. O pacote precisa funcionar **sem** `composer` no servidor.

- [ ] **Passo 6: Commit** via `/commit`. Título sugerido: `ci(release): add release packaging workflow`. Em seguida, o dono cria a tag `v0.1.0` quando decidir publicar (o plano não cria tags nem publica nada).

---

## Autoavaliação do plano

**Cobertura da spec** (cada requisito aponta para a tarefa que o implementa):

| Spec | Tarefa |
|---|---|
| D1 linha ticket+ativo com snapshot; 5.2 | 5 (esquema), 8 (importação) |
| D2 elegibilidade, D3 categorias em configuração | 7, 8 |
| D4 "Enviar", D5 papéis de status sem criação automática | 7 (configuração e "criar novo"), 9 |
| D6 dados do retorno, `TicketCost` | 10 |
| D7 quatro resultados, resultado x destino; 6.3 | 2, 10 |
| D8 ações configuráveis por resultado; 6.4 | 4, 7, 10 |
| D9 encerramento automático, reabertura com permissão e histórico | 10 (`recalc`), 11 |
| D10 mPDF empacotado; D11 PDF definitivo guardado; D12 descrição para o fornecedor | 8 (descrição), 12 |
| D13 workflow de release próprio | 14 |
| D14 sem migração; numeração a partir de 1, sem campo de valor inicial | 6 (contador atômico; nenhum campo de valor inicial) |
| D15 envio linha a linha, reserva, "Remover linha com falha" | 9 |
| D16 configuração por seções de módulo | 7 |
| 5.2.1 histórico de eventos, motivo da reabertura | 6, 10, 11 |
| 5.4 integridade: linha ativa única, numeração | 6 (contador), 8 e 9 (checagem em código) |
| 6.1/6.2 máquinas de estado | 2 |
| 7.1 a 7.5 fluxos | 6, 8, 9, 10, 11 |
| 8 permissões (Enviar, Registrar retorno, Reabrir) | 5, 9, 10, 11 |
| 9 relatório: conteúdo, motor, ciclo de vida | 12 |
| 10 entidades (subentidades, cabeçalho da `Entity`, logo por categoria, fornecedor, `State` por entidade) | 8, 9 (`StateGuard`), 12 |
| 12 integração com a baixa (linhas com destino `Baixa` ficam disponíveis) | 10 (a coluna `destination` e o vínculo `plugin_gac_repairprotocols_id` já bastam; o laudo os consome) |
| 13 release | 14 |
| 14 erros, logs e testes | 9 a 11 (transação por linha, `last_error`, log `gac`), 13 |
| 16 ordem de construção | a numeração das tarefas segue a ordem da spec |

**Pontos que a spec deixava em aberto e este plano fechou** (lista completa no início, "Decisões de implementação"): contador atômico de numeração, extração da observação para a descrição inicial, "Concluir correções" depois da reabertura, bits de direito, setor de menu, tratamento do motivo de pendência no GLPI, coluna `item_entities_id`, recuperação de linha presa em `Enviando`, PRE sem linhas após remoção vira `Cancelado`.

**Verificado durante a elaboração** (executado, não presumido): as classes puras das tarefas 1 a 4 e 8 (PHPUnit, 46 testes verdes); a sintaxe de todo o PHP e do JS dos blocos de código (`php -l`, `node --check`); a sintaxe de todos os templates Twig contra o Twig do GLPI (achou e corrigiu um erro de ordem `ignore missing ... with`); a renderização do relatório com mPDF 8.3.1 e a poda de fontes (95 MB para 9,6 MB).

**Não verificado até a execução** (depende de rodar dentro do GLPI): as chamadas ao core (`ITILFollowup`, `PendingReason_Item`, `TicketCost`, `Document`, `Dropdown`, `Search`, o breadcrumb do menu com `strtolower(PreMenu::class)`), o comportamento do status anterior ao sair de Pendente (decisão 6) e os tempos do "Enviar". Cada uma tem um item explícito no teste manual da tarefa correspondente ou na Tarefa 13.

**Consistência de nomes** conferida entre tarefas: `ServiceResult::ok/fail`, `RepairProtocol::getStatus/changeStatus/lines`, `RepairProtocolItem::getStatus`, `RepairProtocolEvent::log/isReopened`, `SendService::start/sendLine/finalize/removeFailedLine`, `ReturnService::registerReturn/markLost/recalc`, `ReopenService::reopen/correctLine/finishCorrections`, `PreSettings::*` e as variáveis Twig (`is_draft`, `can_edit`, `can_send`, `send_mode`, `pending_line_ids`, `can_return`, `can_reopen`, `is_reopened`, `pdf_url`, `can_pdf`).
