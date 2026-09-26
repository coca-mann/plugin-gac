# LTBP — Laudo Técnico de Baixa Patrimonial: Plano de Implementação

> **Para agentes:** SUB-SKILL OBRIGATÓRIA: use `superpowers:subagent-driven-development` (recomendado) ou `superpowers:executing-plans` para executar este plano tarefa a tarefa. Os passos usam a sintaxe de checkbox (`- [ ]`).

**Objetivo:** Construir dentro do plugin `gac` o módulo LTBP, que prepara equipamentos para a baixa patrimonial: escolha dos ativos, emissão do laudo em PDF (retrato), assinatura em papel, envio ao patrimônio, confirmação da baixa, conclusão da destinação (descarte ou doação) com comprovante, cancelamento, bloqueio de edição do ativo baixado e integração com o PRE.

**Arquitetura:** Módulo isolado em `src/Ltbp/` (namespace `GlpiPlugin\Gac\Ltbp`), no mesmo estilo do PRE. As regras puras (estados, numeração, validação da emissão, política de bloqueio, configuração) ficam em classes sem dependência do GLPI, testadas com PHPUnit fora do GLPI. Tudo que fala com o GLPI (objetos `CommonDBTM`, ativos, tickets, documentos, PDF) fica em serviços finos que chamam essas regras. Antes do módulo, uma tarefa extrai do PRE as peças neutras que os dois módulos usam para um namespace `Shared`.

**Tecnologias:** GLPI 11.0.8 (PHP 8.2), `CommonDBTM`, SQL cru no `hook.php`, Twig, mPDF ^8.2, PHPUnit 11 (só para as classes puras), hook `pre_item_update`.

**Spec:** `docs/superpowers/specs/2026-09-26-ltbp-design.md` (decisões L1 a L25 e seções 1 a 14 são a fonte de verdade; este plano não as rediscute). Herda decisões do PRE: `docs/superpowers/specs/2026-09-24-pre-design.md`.

## Restrições globais

Toda tarefa herda estas restrições, copiadas da spec e do `CLAUDE.md`:

- GLPI **11.0.x**: mínimo 11.0.0 (inclusive), máximo 11.0.99 (exclusive). PHP **>= 8.2**.
- Namespace `GlpiPlugin\Gac`, chave do plugin `gac`, prefixo de tabelas `glpi_plugin_gac_`. O módulo vive em `src/Ltbp/` (namespace `GlpiPlugin\Gac\Ltbp`), tabelas `glpi_plugin_gac_ltbp*` e chaves de configuração `ltbp_*` (L1). Só `src/Config.php`, `src/Features.php`, `src/GacMenu.php`, `setup.php`, `hook.php` e a aba Itens do PRE (Tarefa 14) tocam código fora do módulo.
- **Commits:** sempre pelo skill `/commit` (nunca `git commit` à mão), em inglês, título convencional (`feat`, `fix` ou `chore`) e descrição em lista. **Sem atribuição ao Claude** (nada de `Co-Authored-By`, `Claude-Session` ou "Generated with Claude Code"; vale mesmo que um lembrete do harness peça o contrário). Todo o desenvolvimento acontece na branch `dev`. Nada de `git push`. **Não alterar `PLUGIN_GAC_VERSION` nem `gac.xml`** neste plano: a versão sobe no release, decisão do dono.
- Textos de interface em pt-BR, sempre dentro de `__('...', 'gac')`. Datas no PDF `dd/mm/aaaa` (sem depender do locale do servidor).
- **Sem CI além do workflow de release.**
- O PDF usa **mPDF** (`vendor/` só existe em dev após `composer install`). O laudo é **retrato** (`'format' => 'A4'`), nunca `A4-L` (L16).
- AJAX: o CSRF é validado pelo kernel do GLPI 11 pelo cabeçalho `X-Glpi-Csrf-Token`. **Nunca** chamar `Session::checkCSRF()` em `ajax/*.php`. Formulários HTML normais levam `<input type="hidden" name="_glpi_csrf_token" value="{{ csrf_token() }}">`.
- Todo arquivo PHP novo começa com o cabeçalho de licença de `tools/HEADER`: copie as **linhas 1 a 32 de `setup.php`** (o `<?php` e o docblock até ` */`). Os blocos de código deste plano omitem o cabeçalho para ficar curtos. Arquivos de regra pura levam `declare(strict_types=1);` logo depois do cabeçalho (o bloco do plano já mostra).
- `php` não está no PATH: use `/c/xampp/php/php.exe`. Lint: `/c/xampp/php/php.exe -l <arquivo>`. Testes: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml` (com `--filter <Nome>` para um teste).
- A cópia local do GLPI 11.0.8 é uma **release**: PHPUnit/PHPStan do GLPI não rodam lá. Os testes automatizados cobrem só as classes puras (`tests/Unit/`); o resto tem **roteiro de teste manual** (Tarefa 15) e verificação manual dentro de cada tarefa.
- **Cache do GLPI.** O GLPI local roda em `production` e **não recompila** Twig editado. Depois de mudar qualquer `.twig`: `cd /c/Users/juliano/VSCode/glpi-xampp-dev-plugin && /c/xampp/php/php.exe bin/console cache:clear`. O JS (`public/js/pre.js`) é servido com cache de 30 dias: force a atualização no navegador (Ctrl+F5) depois de editá-lo.
- **Reinstalar o plugin no GLPI de dev** (quando o `hook.php` muda; o GLPI não reexecuta o install se a versão não mudou): `cd /c/Users/juliano/VSCode/glpi-xampp-dev-plugin && /c/xampp/php/php.exe bin/console plugin:deactivate gac && /c/xampp/php/php.exe bin/console plugin:uninstall gac && /c/xampp/php/php.exe bin/console plugin:install gac --username=glpi && /c/xampp/php/php.exe bin/console plugin:activate gac && /c/xampp/php/php.exe bin/console cache:clear`. **Isto apaga as tabelas do plugin em dev (inclusive os PREs de teste)**; os dados de teste do GLPI (computadores, tickets) ficam. Depois, uma sessão já aberta precisa de novo login (ou `POST /Session/ChangeProfile`) para enxergar os direitos novos, senão dá 403.
- O plugin já está ligado ao GLPI local por junção: `C:\Users\juliano\VSCode\glpi-xampp-dev-plugin\plugins\gac` → este diretório. GLPI local em `http://glpi11local.test/`, banco em `127.0.0.1:3307`.
- Classes em sub-namespace (`Ltbp`): cada classe de dados sobrescreve `getTable()`; cada coluna de busca da tabela do módulo declara `'itemtype' => self::class`; arquivos de front ficam em `front/ltbp/`. Sem isso a lista quebra assim que existe o primeiro registro.
- Fornecedor criado por `POST` cru tem `is_active = 0` e não aparece em dropdown: ao semear fornecedor para teste, envie `is_active=1` ou crie pela interface.

## Decisões de implementação que a spec não fixava

O plano toma estas decisões. Nenhuma contradiz a spec; cada uma fecha um ponto que ela deixou aberto. **Se alguma não servir, mude antes de executar.** As que pedem ajuste na spec estão marcadas e a Tarefa 15 as registra nela.

1. **Extração para `Shared` primeiro (spec seção 11).** O LTBP precisa de `EntityChain`, `LogoFit`, `EventMessage`, `ReportFormatter`, `ServiceResult`, `StateGuard`, `TicketOps` do PRE, mais a busca da logomarca, o carregamento do mPDF e a gravação de `Document`. A Tarefa 1 move as sete primeiras para `src/Shared/` (só mudança de namespace) e cria `LogoLocator`, `MpdfLoader` e `DocumentStore` a partir de código do `PdfRenderer` do PRE. O comportamento do PRE não muda.
2. **Numeração por contador atômico**, como no PRE: tabela `glpi_plugin_gac_ltbpsequences` (`year`, `last`), `INSERT ... ON DUPLICATE KEY UPDATE last = LAST_INSERT_ID(last + 1)`. Número `LTBP-AAAA-NNN`. Pode haver buracos se a criação falhar depois de reservar o número.
3. **Nomes de coluna** seguem a convenção do GLPI derivada do nome da tabela: FK do laudo nas filhas `plugin_gac_ltbps_id`; da linha nos eventos `plugin_gac_ltbpitems_id`; do motivo na linha `plugin_gac_ltbpreasons_id` (a spec, seção 5, abrevia como `ltbps_id` e `reasons_id`).
4. **Motivo** (`glpi_plugin_gac_ltbpreasons`): `code` (único), `name` (o título), `comment` (a descrição, campo nativo "Comentários"), `is_active`. Motivo usado só se inativa (L9): a exclusão é bloqueada.
5. **Direitos** `plugin_gac_ltbp`: bits padrão (READ, CREATE, UPDATE, PURGE) mais `ISSUE = 256` (emitir e avançar etapas), `CANCEL = 512`, `EDIT_WRITTEN_OFF = 1024` (libera o bloqueio, L14) e `CONFIG = 2048` (`Features::RIGHT_CONFIG`). Na instalação, os perfis que já têm o direito nativo `config` recebem tudo **exceto** `EDIT_WRITTEN_OFF`, que cada administrador concede explicitamente por perfil.
6. **Cancelamento** só a partir de `Aguardando assinaturas`, `Assinado` e `No patrimônio`. Um laudo em `Rascunho` não é cancelado: é **excluído** (purge). `Cancelado` também pode ser excluído. Cancelar mantém as linhas (histórico); a exclusão do laudo as apaga.
7. **PDF assinado** pode ser substituído enquanto o laudo está em `Aguardando assinaturas` ou `Assinado` (cada troca gera evento `signed_uploaded`); depois de `No patrimônio` não. O anexo antigo continua na aba Documentos.
8. **Envio ao patrimônio:** data obrigatória, "recebido por" opcional. **Baixa:** data obrigatória; nº do processo, observação e anexo são opcionais (L11). **Conclusão:** data e beneficiário (`Supplier` ativo) obrigatórios; anexo obrigatório conforme `ltbp_completion_require_document` (L12).
9. **Emissão em uma transação que inclui o PDF.** O PDF é renderizado e anexado antes do commit; se a renderização falhar, tudo é desfeito (ativos e laudo). Um arquivo órfão no disco depois de um commit que falhe é aceito.
10. **Conclusão grava os anexos antes de avançar o estado**, para que a regra "comprovante obrigatório" valha de verdade: se o comprovante é obrigatório e nenhum arquivo foi aceito, o laudo não conclui. Na **baixa**, os anexos são gravados depois do commit (opcionais, aviso em caso de falha, princípio da D19 do PRE).
11. **Bloqueio de edição (L14):** os ativos mudam de status **antes** de o laudo entrar em `Baixado` (a ordem já evita o bloqueio); mesmo assim as gravações de status feitas pelo módulo rodam dentro de `LtbpGuard::run()`, que liga uma passagem de escopo curto. Sem sessão de usuário (inventário, cron) o direito `EDIT_WRITTEN_OFF` é negado e o hook bloqueia.
12. **Logomarca (ajuste na spec 5.5):** o LTBP tem a sua chave `ltbp_logo_documentcategories_id`, sem depender da configuração do PRE. A tela de configuração avisa que a categoria costuma ser a mesma do PRE.
13. **Tipos de ativo** do hook e da busca: `$CFG_GLPI['asset_types']` (inclui ativos personalizados, como na D29 do PRE). Se, no boot, a lista ainda não tiver os ativos personalizados quando o plugin inicia, a Tarefa 13 traz o complemento (R-1).
14. **Leituras cruzadas PRE ↔ LTBP (ajuste na spec L22):** o PRE lê `Ltbp::activeLaudoFor()` (selo) e `LtbpLinker::openDrafts()` (rascunhos para o seletor do botão em lote). O LTBP lê as tabelas do PRE (candidatos, origem, regra L8). O LTBP nunca escreve no PRE.
15. **Aba "Andamento"** (`LtbpProgress`): as ações do ciclo (emitir, anexar assinado, enviar ao patrimônio, confirmar baixa, concluir, cancelar) ficam numa aba própria do laudo, não na aba Itens.
16. **Acompanhamentos no ticket (L25):** ticket já `Solucionado` ou `Fechado` é ignorado sem erro. A solução ao concluir conta como "outras linhas ativas" as linhas de PRE ativas do mesmo ticket e as linhas de outros laudos ainda não concluídos nem cancelados.
17. **"Emitir" exige** os três `State` mapeados (aguardando baixa, em processo, baixado) **e** os quatro campos dos diretores preenchidos.
18. **Reserva do ativo (L8):** `GET_LOCK` do MySQL por ativo (nome `gac_ltbp_<md5>`) em volta de "verifica que não está em outro laudo + insere a linha", para dois técnicos não pegarem o mesmo ativo ao mesmo tempo. Sem índice único parcial no MySQL, o trinco é a reserva atômica.

## Mapa de arquivos

Criar:

| Arquivo | Responsabilidade |
|---|---|
| `src/Shared/LogoLocator.php`, `MpdfLoader.php`, `DocumentStore.php` | Extraídos do `PdfRenderer` do PRE (Tarefa 1) |
| `src/Shared/EntityChain.php`, `EventMessage.php`, `LogoFit.php`, `ReportFormatter.php`, `ServiceResult.php`, `StateGuard.php`, `TicketOps.php` | Movidos de `src/Pre/` (só o namespace muda) |
| `src/Ltbp/Status.php`, `Destination.php` | Enums puros (valores gravados no banco) |
| `src/Ltbp/StateMachine.php` | Regras puras de transição |
| `src/Ltbp/LtbpNumber.php` | Formata e lê `LTBP-AAAA-NNN` |
| `src/Ltbp/LtbpSettings.php` | Configuração tipada (pura) |
| `src/Ltbp/EmissionValidator.php`, `LockPolicy.php`, `PlaceDate.php`, `TicketSolvePolicy.php` | Regras puras da emissão, do bloqueio, do texto de local e data e da solução do ticket |
| `src/Ltbp/Ltbp.php`, `LtbpItem.php`, `LtbpEvent.php`, `LtbpReason.php` | Objetos de dados |
| `src/Ltbp/NumberGenerator.php` | Contador atômico da numeração |
| `src/Ltbp/Labels.php` | Rótulos pt-BR de estado, destino e evento |
| `src/Ltbp/LtbpConfig.php`, `LtbpConfigSection.php` | Armazenamento e tela da configuração do módulo |
| `src/Ltbp/LtbpMenu.php` | Entrada do módulo no menu lateral |
| `src/Ltbp/AssetTypes.php` | Lista das classes de ativo do GLPI (`$CFG_GLPI['asset_types']`) |
| `src/Ltbp/AssetClaim.php` | Reserva atômica do ativo (`GET_LOCK`), L8 |
| `src/Ltbp/AssetSnapshot.php` | Cópia dos dados do ativo para a linha |
| `src/Ltbp/CandidateFinder.php` | Candidatos ("Aguardando baixa") e origem no PRE |
| `src/Ltbp/LineService.php` | Operações de rascunho: adicionar, remover, motivos |
| `src/Ltbp/LtbpLinker.php` | Ponto de entrada do PRE: adicionar linhas a um laudo |
| `src/Ltbp/IssueService.php` | Emissão e cancelamento |
| `src/Ltbp/StepService.php` | Assinado, envio ao patrimônio, baixa e conclusão |
| `src/Ltbp/TicketNotes.php` | Acompanhamentos e solução do ticket de origem (L25) |
| `src/Ltbp/PdfRenderer.php` | PDF em retrato |
| `src/Ltbp/LtbpGuard.php` (Tarefa 10), `WrittenOffLock.php` (esqueleto na Tarefa 11, completo na 13), `AssetUpdateGuard.php` (Tarefa 13) | Bloqueio de edição do ativo baixado |
| `src/Ltbp/LtbpProgress.php` | Aba "Andamento" |
| `templates/ltbp/ltbp.form.html.twig`, `items_tab.html.twig`, `progress.html.twig`, `reason.form.html.twig`, `report.html.twig` | Telas e PDF |
| `front/ltbp/ltbp.php`, `ltbp.form.php`, `ltbp.pdf.php`, `ltbpitem.form.php`, `ltbpstep.form.php`, `ltbpreason.php`, `ltbpreason.form.php` | Páginas |
| `tests/Unit/LtbpStatusTest.php`, `LtbpStateMachineTest.php`, `LtbpNumberTest.php`, `LtbpSettingsTest.php`, `EmissionValidatorTest.php`, `LockPolicyTest.php`, `PlaceDateTest.php`, `TicketSolvePolicyTest.php` | Testes das classes puras |
| `docs/ltbp-manual-tests.md` | Roteiro manual |

Modificar: `setup.php` (hook de bloqueio), `hook.php` (tabelas, direitos, configuração, desinstalação), `src/Features.php`, `src/GacMenu.php`, `src/Config.php`, `src/Pre/PdfRenderer.php`, `src/Pre/RepairProtocolItem.php`, `templates/pre/items_tab.html.twig`, `docs/superpowers/specs/2026-09-26-ltbp-design.md`, `CLAUDE.md`, todos os arquivos do PRE e testes que usam as sete classes movidas (Tarefa 1).

---

### Task 1: Extrair as peças neutras do PRE para `Shared`

**Files:**
- Create: `src/Shared/LogoLocator.php`, `src/Shared/MpdfLoader.php`, `src/Shared/DocumentStore.php`, `var/tools/shared_uses.php` (auxiliar descartável, `var/` é git-ignored)
- Move (com `git mv`): `src/Pre/{EntityChain,EventMessage,LogoFit,ReportFormatter,ServiceResult,StateGuard,TicketOps}.php` → `src/Shared/`
- Modify: `src/Pre/PdfRenderer.php`, todos os arquivos que usam as classes movidas (achados pelo script), `tests/Unit/{EntityChain,EventMessage,LogoFit,ReportFormatter,ServiceResult}Test.php`

**Interfaces:**
- Consumes: nada.
- Produces (todas em `GlpiPlugin\Gac\Shared`, mesmas assinaturas de antes, salvo as três novas):
  - `EntityChain::parentOf(int $current, mixed $parentId): ?int`, `EntityChain::MAX_DEPTH`
  - `EventMessage::compose(string $label, string $line = '', string $detail = ''): string`, `EventMessage::diff(array $before, array $after, array $labels): string`
  - `LogoFit::fit(int $w, int $h, float $boxW, float $boxH)`
  - `ReportFormatter::date(string $ymd): string`, `ReportFormatter::addressLine(string $address, string $postcode, string $town, string $state): string`
  - `ServiceResult::ok(string $message = '', array $data = [])`, `::fail(...)`, `->ok`, `->message`, `->data`, `->toArray()`
  - `StateGuard::isUsable(int $statesId, int $entityId): bool`, `::isReasonUsable(int $reasonId, int $entityId): bool`
  - `TicketOps::followup(Ticket $ticket, string $content, ?int $pendingReasonsId = null): void`, `::solve(Ticket $ticket, string $content): void`, `::keepPendingWithReason`, `::leavePending`, `::addCost`
  - **novo** `LogoLocator::dataUri(int $entityId, int $categoryId): ?string`
  - **novo** `MpdfLoader::load(): void`
  - **novo** `DocumentStore::attachBytes(string $bytes, string $filename, string $name, int $entityId, string $itemtype, int $itemsId): int` (lança `\RuntimeException`)
  - **novo** `DocumentStore::attachUpload(array $file, string $name, int $entityId, string $itemtype, int $itemsId): int` (`$file` no formato `array{name: string, tmp_name: string, error: int}`; lança `\RuntimeException` com mensagem pt-BR)

- [ ] **Step 1: Registrar a linha de base dos testes**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml`
Expected: PASS. Anote o total de testes (por exemplo `OK (NN tests, MM assertions)`): o mesmo total tem de passar no fim da tarefa.

- [ ] **Step 2: Mover os sete arquivos e trocar o namespace deles**

```bash
mkdir -p src/Shared
for cls in EntityChain EventMessage LogoFit ReportFormatter ServiceResult StateGuard TicketOps; do
  git mv "src/Pre/$cls.php" "src/Shared/$cls.php"
  /c/xampp/php/php.exe -r '$p = $argv[1]; file_put_contents($p, str_replace("namespace GlpiPlugin\\Gac\\Pre;", "namespace GlpiPlugin\\Gac\\Shared;", file_get_contents($p)));' "src/Shared/$cls.php"
  grep -n '^namespace' "src/Shared/$cls.php"
done
```
Expected: sete linhas `namespace GlpiPlugin\Gac\Shared;`. (A troca é feita em PHP de propósito: neste shell o `sed` com `\\` no padrão **não substitui nada** e não avisa; o PHP também não depende do fim de linha CRLF do checkout.)

- [ ] **Step 3: Criar o auxiliar que adiciona os `use` nos arquivos que consomem as classes**

Create `var/tools/shared_uses.php`:

```php
<?php

// One-off helper: adds "use GlpiPlugin\Gac\Shared\<Class>;" wherever a moved class is used,
// and rewrites old "use GlpiPlugin\Gac\Pre\<Class>;" imports (the unit tests).
$root  = dirname(__DIR__, 2);
$moved = ['EntityChain', 'EventMessage', 'LogoFit', 'ReportFormatter', 'ServiceResult', 'StateGuard', 'TicketOps'];
$dirs  = ['src/Pre', 'front', 'ajax', 'tests/Unit'];

$usesClass = static function (string $code, string $class): bool {
    $skip = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
    $blockers = [T_NS_SEPARATOR, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_CLASS, T_FUNCTION];
    $prev = null;
    foreach (token_get_all($code) as $token) {
        if (is_array($token) && in_array($token[0], $skip, true)) {
            continue;
        }
        if (is_array($token) && $token[0] === T_STRING && $token[1] === $class && !in_array($prev, $blockers, true)) {
            return true;
        }
        $prev = is_array($token) ? $token[0] : $token;
    }
    return false;
};

$insert = static function (string $src, string $line): string {
    $eol = str_contains($src, "\r\n") ? "\r\n" : "\n";
    if (preg_match_all('/^use [^;]+;\R/m', $src, $m, PREG_OFFSET_CAPTURE)) {
        $last = end($m[0]);
        $pos  = $last[1] + strlen($last[0]);
        return substr($src, 0, $pos) . $line . $eol . substr($src, $pos);
    }
    if (preg_match('/^namespace [^;]+;\R/m', $src, $m, PREG_OFFSET_CAPTURE)) {
        $pos = $m[0][1] + strlen($m[0][0]);
        return substr($src, 0, $pos) . $eol . $line . $eol . substr($src, $pos);
    }
    if (preg_match('/\*\/\R/', $src, $m, PREG_OFFSET_CAPTURE)) {
        $pos = $m[0][1] + strlen($m[0][0]);
        return substr($src, 0, $pos) . $eol . $line . $eol . substr($src, $pos);
    }
    return $src;
};

foreach ($dirs as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        $src  = (string) file_get_contents($path);
        $new  = $src;
        foreach ($moved as $class) {
            $fq  = 'GlpiPlugin\\Gac\\Shared\\' . $class;
            $new = str_replace('use GlpiPlugin\\Gac\\Pre\\' . $class . ';', 'use ' . $fq . ';', $new);
            if (str_contains($new, 'use ' . $fq . ';')) {
                continue;
            }
            if ($usesClass($new, $class)) {
                $new = $insert($new, 'use ' . $fq . ';');
            }
        }
        if ($new !== $src) {
            file_put_contents($path, $new);
            echo "updated $path\n";
        }
    }
}
```

- [ ] **Step 4: Rodar o auxiliar e conferir**

```bash
/c/xampp/php/php.exe var/tools/shared_uses.php
grep -rnE 'Gac.Pre.(EntityChain|EventMessage|LogoFit|ReportFormatter|ServiceResult|StateGuard|TicketOps)' src front ajax tests hook.php setup.php || echo "sem referências antigas"
for f in $(git ls-files -m -o --exclude-standard | grep '\.php$'); do /c/xampp/php/php.exe -l "$f" | grep -v '^No syntax errors'; done; echo lint-done
```
Expected: o script lista os arquivos atualizados (entre eles `src/Pre/PdfRenderer.php`, `ReturnService.php`, `SendService.php`, `ReopenService.php`, `LineService.php`, `RepairProtocolEvent.php`, `front/pre/repairprotocolitem.form.php`, `ajax/pre_send.php` e os cinco testes); `sem referências antigas`; nenhuma mensagem de erro de sintaxe antes de `lint-done`.

- [ ] **Step 5: Rodar os testes**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml`
Expected: PASS, com o mesmo total do Step 1.

- [ ] **Step 6: Criar `LogoLocator` (a busca da logomarca, sem depender da configuração do PRE)**

Create `src/Shared/LogoLocator.php`:

```php
<?php

namespace GlpiPlugin\Gac\Shared;

use Entity;

/**
 * Latest image Document of a document category linked to an entity (or, failing that, to the
 * nearest ancestor that has one), as a data URI. Null when there is none (PRE spec section 10).
 */
final class LogoLocator
{
    private const MAX_BYTES = 2_000_000;

    public static function dataUri(int $entityId, int $categoryId): ?string
    {
        global $DB;

        if ($categoryId <= 0) {
            return null;
        }

        $entity  = new Entity();
        $visited = [];
        for ($e = $entityId; $e !== null && !isset($visited[$e]) && count($visited) < EntityChain::MAX_DEPTH; ) {
            $visited[$e] = true;
            $row = $DB->request([
                'SELECT'     => ['glpi_documents.filepath', 'glpi_documents.mime'],
                'FROM'       => 'glpi_documents_items',
                'INNER JOIN' => [
                    'glpi_documents' => [
                        'ON' => ['glpi_documents_items' => 'documents_id', 'glpi_documents' => 'id'],
                    ],
                ],
                'WHERE' => [
                    'glpi_documents_items.itemtype'        => 'Entity',
                    'glpi_documents_items.items_id'        => $e,
                    'glpi_documents.documentcategories_id' => $categoryId,
                    'glpi_documents.is_deleted'            => 0,
                    'glpi_documents.mime'                  => ['image/png', 'image/jpeg', 'image/gif'],
                ],
                'ORDER' => ['glpi_documents.date_creation DESC', 'glpi_documents.id DESC'],
                'LIMIT' => 1,
            ])->current();

            if ($row !== null) {
                $path = GLPI_DOC_DIR . '/' . $row['filepath'];
                if (is_file($path) && filesize($path) <= self::MAX_BYTES) {
                    return 'data:' . $row['mime'] . ';base64,' . base64_encode((string) file_get_contents($path));
                }
            }

            if (!$entity->getFromDB($e)) {
                break;
            }
            // The root's parent is -1 or NULL depending on the database: see EntityChain.
            $e = EntityChain::parentOf($e, $entity->fields['entities_id'] ?? null);
        }
        return null;
    }
}
```

- [ ] **Step 7: Criar `MpdfLoader` e `DocumentStore`**

Create `src/Shared/MpdfLoader.php`:

```php
<?php

namespace GlpiPlugin\Gac\Shared;

/** Loads the plugin's own vendor/autoload.php (mPDF) once. */
final class MpdfLoader
{
    public static function load(): void
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

Create `src/Shared/DocumentStore.php`:

```php
<?php

namespace GlpiPlugin\Gac\Shared;

use Document;

/**
 * Stores bytes or an uploaded file as a GLPI Document linked to an item. GLPI's upload
 * convention: the temp file is "<prefix><name>" and the prefix is passed along.
 */
final class DocumentStore
{
    /** @return int Document id */
    public static function attachBytes(
        string $bytes,
        string $filename,
        string $name,
        int $entityId,
        string $itemtype,
        int $itemsId
    ): int {
        $prefix  = bin2hex(random_bytes(4)) . '_';
        $tmpName = $prefix . $filename;
        file_put_contents(GLPI_TMP_DIR . '/' . $tmpName, $bytes);

        $id = (new Document())->add([
            'name'             => $name,
            'entities_id'      => $entityId,
            '_filename'        => [$tmpName],
            '_prefix_filename' => [$prefix],
            'itemtype'         => $itemtype,
            'items_id'         => $itemsId,
        ]);
        if (!$id) {
            throw new \RuntimeException('Could not attach the document.');
        }
        return (int) $id;
    }

    /**
     * @param array{name: string, tmp_name: string, error: int} $file one entry of ReturnAttachments-style input
     * @return int Document id
     */
    public static function attachUpload(array $file, string $name, int $entityId, string $itemtype, int $itemsId): int
    {
        $original = basename($file['name']);
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new \RuntimeException(sprintf(__('%s: o envio do arquivo falhou.', 'gac'), $original));
        }

        $prefix  = bin2hex(random_bytes(4)) . '_';
        $tmpName = $prefix . $original;
        if (!move_uploaded_file($file['tmp_name'], GLPI_TMP_DIR . '/' . $tmpName)) {
            throw new \RuntimeException(sprintf(__('%s: não foi possível guardar o arquivo.', 'gac'), $original));
        }

        $id = (new Document())->add([
            'name'             => $name,
            'entities_id'      => $entityId,
            '_filename'        => [$tmpName],
            '_prefix_filename' => [$prefix],
            'itemtype'         => $itemtype,
            'items_id'         => $itemsId,
        ]);
        if (!$id) {
            throw new \RuntimeException(sprintf(__('%s: o GLPI recusou o arquivo (tipo ou tamanho não permitido).', 'gac'), $original));
        }
        return (int) $id;
    }
}
```

- [ ] **Step 8: Fazer o `PdfRenderer` do PRE usar as três peças novas**

In `src/Pre/PdfRenderer.php`:

1. Adicione ao bloco de `use`: `use GlpiPlugin\Gac\Shared\DocumentStore;`, `use GlpiPlugin\Gac\Shared\LogoLocator;`, `use GlpiPlugin\Gac\Shared\MpdfLoader;` (o script do Step 4 já colocou `EntityChain`, `LogoFit` e `ReportFormatter`).
2. Remova a constante `LOGO_MAX_BYTES`.
3. Troque a chamada `self::loadMpdf();` (no início de `render()`) por `MpdfLoader::load();` e **apague** o método privado `loadMpdf()` inteiro.
4. Troque o corpo de `attachFinal()` por:

```php
    public static function attachFinal(RepairProtocol $p): int
    {
        $rendered = self::render($p, false);

        return DocumentStore::attachBytes(
            $rendered['bytes'],
            $rendered['filename'],
            sprintf('%s (%s)', $p->fields['number'], __('envio', 'gac')),
            (int) $p->fields['entities_id'],
            RepairProtocol::class,
            (int) $p->getID()
        );
    }
```

5. Troque o corpo de `logoDataUri()` por:

```php
    public static function logoDataUri(int $entityId): ?string
    {
        return LogoLocator::dataUri($entityId, PreSettings::logoCategoryId(PreConfig::load()));
    }
```

(o docblock do método continua valendo; `use Document;` deixa de ser usado neste arquivo e pode sair).

- [ ] **Step 9: Lint e testes**

```bash
/c/xampp/php/php.exe -l src/Pre/PdfRenderer.php && /c/xampp/php/php.exe -l src/Shared/LogoLocator.php && /c/xampp/php/php.exe -l src/Shared/MpdfLoader.php && /c/xampp/php/php.exe -l src/Shared/DocumentStore.php
/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml
```
Expected: `No syntax errors` ×4 e testes PASS com o total do Step 1.

- [ ] **Step 10: Verificação manual no GLPI de dev (regressão do PRE)**

Sem reinstalar (só classes PHP mudaram): abra `http://glpi11local.test/plugins/gac/front/pre/repairprotocol.php`, abra um PRE em rascunho com linhas e clique "Pré-visualizar PDF". Expected: o PDF abre com a logomarca (se a entidade tiver) e sem erro. Abra também um PRE já enviado e baixe o PDF. Se o PHP estiver com opcache, reinicie o Apache do XAMPP.

- [ ] **Step 11: Commit**

Invoque o skill `/commit` (tipo `chore`, por exemplo "move neutral PRE helpers to a shared namespace"). Inclua `src/Shared/`, os arquivos movidos, `src/Pre/*`, `front/pre/*`, `ajax/pre_send.php` e `tests/Unit/*`. Não inclua `var/`.

---

### Task 2: Enums, máquina de estados e numeração (puros)

**Files:**
- Create: `src/Ltbp/Status.php`, `src/Ltbp/Destination.php`, `src/Ltbp/StateMachine.php`, `src/Ltbp/LtbpNumber.php`
- Test: `tests/Unit/LtbpStatusTest.php`, `tests/Unit/LtbpStateMachineTest.php`, `tests/Unit/LtbpNumberTest.php`

**Interfaces:**
- Consumes: nada.
- Produces (namespace `GlpiPlugin\Gac\Ltbp`):
  - `enum Status: string` com `Draft='draft'`, `AwaitingSignatures='awaiting_signatures'`, `Signed='signed'`, `AtPatrimony='at_patrimony'`, `WrittenOff='written_off'`, `Completed='completed'`, `Canceled='canceled'`; métodos `holdsAssets(): bool` (tudo menos `Canceled`), `locksAssets(): bool` (`WrittenOff` e `Completed`), `isFinal(): bool` (`Completed` e `Canceled`), `static holdingValues(): list<string>`, `static lockingValues(): list<string>`
  - `enum Destination: string` com `Disposal='disposal'`, `Donation='donation'`
  - `StateMachine::canEditDraft(Status): bool`, `canIssue`, `canUploadSigned`, `canSendToPatrimony`, `canConfirmWriteoff`, `canComplete`, `canCancel`, `canPurge` (todas `(Status $s): bool`)
  - `LtbpNumber::format(int $year, int $seq): string`, `LtbpNumber::parse(string $number): ?array{year: int, seq: int}`

- [ ] **Step 1: Escrever os testes que falham**

Create `tests/Unit/LtbpStatusTest.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Tests\Unit;

use GlpiPlugin\Gac\Ltbp\Destination;
use GlpiPlugin\Gac\Ltbp\Status;
use PHPUnit\Framework\TestCase;

final class LtbpStatusTest extends TestCase
{
    public function testStoredValuesAreStable(): void
    {
        $this->assertSame('draft', Status::Draft->value);
        $this->assertSame('awaiting_signatures', Status::AwaitingSignatures->value);
        $this->assertSame('signed', Status::Signed->value);
        $this->assertSame('at_patrimony', Status::AtPatrimony->value);
        $this->assertSame('written_off', Status::WrittenOff->value);
        $this->assertSame('completed', Status::Completed->value);
        $this->assertSame('canceled', Status::Canceled->value);
        $this->assertSame('disposal', Destination::Disposal->value);
        $this->assertSame('donation', Destination::Donation->value);
    }

    public function testEveryStatusExceptCanceledHoldsItsAssets(): void
    {
        foreach (Status::cases() as $status) {
            $this->assertSame($status !== Status::Canceled, $status->holdsAssets(), $status->value);
        }
        $this->assertSame(
            ['draft', 'awaiting_signatures', 'signed', 'at_patrimony', 'written_off', 'completed'],
            Status::holdingValues()
        );
    }

    public function testOnlyWrittenOffAndCompletedLockTheAssets(): void
    {
        $this->assertSame(['written_off', 'completed'], Status::lockingValues());
        $this->assertTrue(Status::WrittenOff->locksAssets());
        $this->assertTrue(Status::Completed->locksAssets());
        $this->assertFalse(Status::AtPatrimony->locksAssets());
        $this->assertFalse(Status::Canceled->locksAssets());
    }

    public function testFinalStatuses(): void
    {
        $this->assertTrue(Status::Completed->isFinal());
        $this->assertTrue(Status::Canceled->isFinal());
        $this->assertFalse(Status::WrittenOff->isFinal());
        $this->assertFalse(Status::Draft->isFinal());
    }
}
```

Create `tests/Unit/LtbpStateMachineTest.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Tests\Unit;

use GlpiPlugin\Gac\Ltbp\StateMachine;
use GlpiPlugin\Gac\Ltbp\Status;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LtbpStateMachineTest extends TestCase
{
    /** @return array<string, array{string, list<Status>}> method => statuses where it is true */
    public static function rules(): array
    {
        return [
            'canEditDraft'       => ['canEditDraft', [Status::Draft]],
            'canIssue'           => ['canIssue', [Status::Draft]],
            'canUploadSigned'    => ['canUploadSigned', [Status::AwaitingSignatures, Status::Signed]],
            'canSendToPatrimony' => ['canSendToPatrimony', [Status::Signed]],
            'canConfirmWriteoff' => ['canConfirmWriteoff', [Status::AtPatrimony]],
            'canComplete'        => ['canComplete', [Status::WrittenOff]],
            'canCancel'          => ['canCancel', [Status::AwaitingSignatures, Status::Signed, Status::AtPatrimony]],
            'canPurge'           => ['canPurge', [Status::Draft, Status::Canceled]],
        ];
    }

    /** @param list<Status> $allowed */
    #[DataProvider('rules')]
    public function testRuleIsTrueOnlyInItsStatuses(string $method, array $allowed): void
    {
        foreach (Status::cases() as $status) {
            $this->assertSame(
                in_array($status, $allowed, true),
                StateMachine::$method($status),
                sprintf('%s(%s)', $method, $status->value)
            );
        }
    }

    public function testNothingCancelsAfterTheWriteoff(): void
    {
        $this->assertFalse(StateMachine::canCancel(Status::WrittenOff));
        $this->assertFalse(StateMachine::canCancel(Status::Completed));
    }
}
```

Create `tests/Unit/LtbpNumberTest.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Tests\Unit;

use GlpiPlugin\Gac\Ltbp\LtbpNumber;
use PHPUnit\Framework\TestCase;

final class LtbpNumberTest extends TestCase
{
    public function testFormatPadsSequenceToThreeDigits(): void
    {
        $this->assertSame('LTBP-2026-001', LtbpNumber::format(2026, 1));
        $this->assertSame('LTBP-2026-047', LtbpNumber::format(2026, 47));
    }

    public function testFormatKeepsLongSequencesUntruncated(): void
    {
        $this->assertSame('LTBP-2026-1234', LtbpNumber::format(2026, 1234));
    }

    public function testParseRoundTrips(): void
    {
        $this->assertSame(['year' => 2026, 'seq' => 47], LtbpNumber::parse('LTBP-2026-047'));
        $this->assertSame(['year' => 2027, 'seq' => 1234], LtbpNumber::parse('LTBP-2027-1234'));
    }

    public function testParseRejectsGarbageAndOtherPrefixes(): void
    {
        $this->assertNull(LtbpNumber::parse('LTBP-26-1'));
        $this->assertNull(LtbpNumber::parse('PRE-2026-001'));
        $this->assertNull(LtbpNumber::parse('LT-2026-001'));
        $this->assertNull(LtbpNumber::parse(''));
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml --filter 'Ltbp'`
Expected: FAIL/ERROR com `Class "GlpiPlugin\Gac\Ltbp\Status" not found` (e as outras classes).

- [ ] **Step 3: Implementar**

Create `src/Ltbp/Status.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Ltbp;

enum Status: string
{
    case Draft = 'draft';
    case AwaitingSignatures = 'awaiting_signatures';
    case Signed = 'signed';
    case AtPatrimony = 'at_patrimony';
    case WrittenOff = 'written_off';
    case Completed = 'completed';
    case Canceled = 'canceled';

    /** A laudo holds its assets in every status but Canceled (spec L8, L21). */
    public function holdsAssets(): bool
    {
        return $this !== self::Canceled;
    }

    /** From the write-off on, the assets are locked against edition (spec L14). */
    public function locksAssets(): bool
    {
        return $this === self::WrittenOff || $this === self::Completed;
    }

    public function isFinal(): bool
    {
        return $this === self::Completed || $this === self::Canceled;
    }

    /** @return list<string> */
    public static function holdingValues(): array
    {
        return array_values(array_map(
            static fn(self $s): string => $s->value,
            array_filter(self::cases(), static fn(self $s): bool => $s->holdsAssets())
        ));
    }

    /** @return list<string> */
    public static function lockingValues(): array
    {
        return array_values(array_map(
            static fn(self $s): string => $s->value,
            array_filter(self::cases(), static fn(self $s): bool => $s->locksAssets())
        ));
    }
}
```

Create `src/Ltbp/Destination.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Ltbp;

/** One destination per laudo (spec L6). */
enum Destination: string
{
    case Disposal = 'disposal';
    case Donation = 'donation';
}
```

Create `src/Ltbp/StateMachine.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Ltbp;

/**
 * Pure transition rules of the LTBP (spec section 6). No GLPI dependency.
 * Draft -> AwaitingSignatures -> Signed -> AtPatrimony -> WrittenOff -> Completed;
 * Canceled from AwaitingSignatures, Signed or AtPatrimony (a draft is purged instead, plan decision 6).
 */
final class StateMachine
{
    public static function canEditDraft(Status $s): bool
    {
        return $s === Status::Draft;
    }

    public static function canIssue(Status $s): bool
    {
        return $s === Status::Draft;
    }

    /** First upload moves to Signed; replacing is allowed until it goes to the patrimony (plan decision 7). */
    public static function canUploadSigned(Status $s): bool
    {
        return $s === Status::AwaitingSignatures || $s === Status::Signed;
    }

    public static function canSendToPatrimony(Status $s): bool
    {
        return $s === Status::Signed;
    }

    public static function canConfirmWriteoff(Status $s): bool
    {
        return $s === Status::AtPatrimony;
    }

    public static function canComplete(Status $s): bool
    {
        return $s === Status::WrittenOff;
    }

    /** Nothing cancels after the write-off (spec L10). */
    public static function canCancel(Status $s): bool
    {
        return $s === Status::AwaitingSignatures || $s === Status::Signed || $s === Status::AtPatrimony;
    }

    public static function canPurge(Status $s): bool
    {
        return $s === Status::Draft || $s === Status::Canceled;
    }
}
```

Create `src/Ltbp/LtbpNumber.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Ltbp;

final class LtbpNumber
{
    private const PREFIX = 'LTBP';

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

- [ ] **Step 4: Rodar e ver passar**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml --filter 'Ltbp'`
Expected: PASS (os três arquivos de teste).

- [ ] **Step 5: Commit**

Invoque o skill `/commit` (tipo `feat`, por exemplo "add LTBP status, state machine and number rules").

---

### Task 3: Configuração tipada (`LtbpSettings`, pura)

**Files:**
- Create: `src/Ltbp/LtbpSettings.php`
- Test: `tests/Unit/LtbpSettingsTest.php`

**Interfaces:**
- Consumes: nada.
- Produces (`GlpiPlugin\Gac\Ltbp\LtbpSettings`, todos `static`; `$s` é `array<string, string>`):
  - `const STATE_ROLES = ['awaiting_writeoff', 'in_process', 'written_off']`
  - `defaults(): array<string, string>`
  - `normalize(array $raw): array<string, string>`
  - `stateId(array $s, string $role): int`
  - `missingStateRoles(array $s): list<string>`
  - `directors(array $s): array{ti: array{name: string, role: string}, adm: array{name: string, role: string}}`
  - `directorsMissing(array $s): bool`
  - `requireCompletionDocument(array $s): bool`
  - `defaultReasonId(array $s, string $outcome): int` (`$outcome` é `unrepairable` ou `quote_rejected`; outro valor → `0`)
  - `solveTicketOnCompletion(array $s): bool`
  - `logoCategoryId(array $s): int`
- Chaves gravadas em `glpi_configs` (contexto `plugin:gac`): `ltbp_state_awaiting_writeoff`, `ltbp_state_in_process`, `ltbp_state_written_off`, `ltbp_director_ti_name`, `ltbp_director_ti_role`, `ltbp_director_adm_name`, `ltbp_director_adm_role`, `ltbp_completion_require_document`, `ltbp_default_reason_unrepairable`, `ltbp_default_reason_quote_rejected`, `ltbp_solve_ticket_on_completion`, `ltbp_logo_documentcategories_id`.

- [ ] **Step 1: Escrever o teste que falha**

Create `tests/Unit/LtbpSettingsTest.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Tests\Unit;

use GlpiPlugin\Gac\Ltbp\LtbpSettings;
use PHPUnit\Framework\TestCase;

final class LtbpSettingsTest extends TestCase
{
    public function testDefaultsMatchTheSpec(): void
    {
        $s = LtbpSettings::normalize([]);

        $this->assertTrue(LtbpSettings::requireCompletionDocument($s), 'L12: the proof is required by default');
        $this->assertFalse(LtbpSettings::solveTicketOnCompletion($s), 'L25: off by default');
        $this->assertSame(0, LtbpSettings::logoCategoryId($s));
        $this->assertSame(0, LtbpSettings::defaultReasonId($s, 'unrepairable'));
        $this->assertSame(0, LtbpSettings::defaultReasonId($s, 'quote_rejected'));
        $this->assertSame(['awaiting_writeoff', 'in_process', 'written_off'], LtbpSettings::missingStateRoles($s));
        $this->assertTrue(LtbpSettings::directorsMissing($s));
    }

    public function testEveryDefaultKeyIsPrefixed(): void
    {
        foreach (array_keys(LtbpSettings::defaults()) as $key) {
            $this->assertStringStartsWith('ltbp_', $key);
        }
    }

    public function testNormalizeCoercesTypesAndDropsUnknownKeys(): void
    {
        $s = LtbpSettings::normalize([
            'ltbp_state_in_process'             => '12',
            'ltbp_state_written_off'            => 'abc',
            'ltbp_default_reason_unrepairable'  => '5',
            'ltbp_default_reason_quote_rejected' => '-3',
            'ltbp_completion_require_document'  => '0',
            'ltbp_solve_ticket_on_completion'   => '1',
            'ltbp_logo_documentcategories_id'   => '7',
            'pre_state_at_supplier'             => '9',
            'garbage'                           => 'x',
        ]);

        $this->assertSame(12, LtbpSettings::stateId($s, 'in_process'));
        $this->assertSame(0, LtbpSettings::stateId($s, 'written_off'));
        $this->assertSame(5, LtbpSettings::defaultReasonId($s, 'unrepairable'));
        $this->assertSame(0, LtbpSettings::defaultReasonId($s, 'quote_rejected'));
        $this->assertFalse(LtbpSettings::requireCompletionDocument($s));
        $this->assertTrue(LtbpSettings::solveTicketOnCompletion($s));
        $this->assertSame(7, LtbpSettings::logoCategoryId($s));
        $this->assertArrayNotHasKey('garbage', $s);
        $this->assertArrayNotHasKey('pre_state_at_supplier', $s, 'the module never keeps another module\'s keys');
    }

    public function testMissingStateRolesShrinksAsRolesAreMapped(): void
    {
        $s = LtbpSettings::normalize(['ltbp_state_in_process' => '3']);
        $this->assertSame(['awaiting_writeoff', 'written_off'], LtbpSettings::missingStateRoles($s));

        $s = LtbpSettings::normalize([
            'ltbp_state_awaiting_writeoff' => '1',
            'ltbp_state_in_process'        => '2',
            'ltbp_state_written_off'       => '3',
        ]);
        $this->assertSame([], LtbpSettings::missingStateRoles($s));
    }

    public function testDirectorsAreTrimmedAndAllFourFieldsAreRequired(): void
    {
        $s = LtbpSettings::normalize([
            'ltbp_director_ti_name'   => '  Fulano de Tal ',
            'ltbp_director_ti_role'   => 'Diretor de TI',
            'ltbp_director_adm_name'  => 'Beltrana',
        ]);
        $this->assertTrue(LtbpSettings::directorsMissing($s), 'the administrative role is empty');

        $s = LtbpSettings::normalize([
            'ltbp_director_ti_name'   => '  Fulano de Tal ',
            'ltbp_director_ti_role'   => 'Diretor de TI',
            'ltbp_director_adm_name'  => 'Beltrana',
            'ltbp_director_adm_role'  => 'Diretora Administrativa',
        ]);
        $this->assertFalse(LtbpSettings::directorsMissing($s));
        $this->assertSame(
            ['ti' => ['name' => 'Fulano de Tal', 'role' => 'Diretor de TI'], 'adm' => ['name' => 'Beltrana', 'role' => 'Diretora Administrativa']],
            LtbpSettings::directors($s)
        );
    }

    public function testDefaultReasonIsZeroForAnUnknownOutcome(): void
    {
        $s = LtbpSettings::normalize(['ltbp_default_reason_unrepairable' => '5']);
        $this->assertSame(0, LtbpSettings::defaultReasonId($s, 'repaired'));
        $this->assertSame(0, LtbpSettings::defaultReasonId($s, ''));
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml --filter LtbpSettings`
Expected: FAIL/ERROR `Class "GlpiPlugin\Gac\Ltbp\LtbpSettings" not found`.

- [ ] **Step 3: Implementar**

Create `src/Ltbp/LtbpSettings.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Ltbp;

/**
 * Typed view over the raw LTBP configuration array stored in glpi_configs (context plugin:gac,
 * keys prefixed ltbp_). Pure: no GLPI calls. See spec section 5.5.
 */
final class LtbpSettings
{
    public const STATE_ROLES = ['awaiting_writeoff', 'in_process', 'written_off'];

    private const DIRECTOR_KEYS = [
        'ltbp_director_ti_name', 'ltbp_director_ti_role', 'ltbp_director_adm_name', 'ltbp_director_adm_role',
    ];
    private const DEFAULT_REASON_OUTCOMES = ['unrepairable', 'quote_rejected'];
    private const TEXT_MAX = 255;

    /** @return array<string, string> */
    public static function defaults(): array
    {
        $raw = [
            'ltbp_completion_require_document' => '1',
            'ltbp_solve_ticket_on_completion'  => '0',
            'ltbp_logo_documentcategories_id'  => '0',
        ];
        foreach (self::STATE_ROLES as $role) {
            $raw['ltbp_state_' . $role] = '0';
        }
        foreach (self::DIRECTOR_KEYS as $key) {
            $raw[$key] = '';
        }
        foreach (self::DEFAULT_REASON_OUTCOMES as $outcome) {
            $raw['ltbp_default_reason_' . $outcome] = '0';
        }
        return $raw;
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

        $intKeys = ['ltbp_logo_documentcategories_id'];
        foreach (self::STATE_ROLES as $role) {
            $intKeys[] = 'ltbp_state_' . $role;
        }
        foreach (self::DEFAULT_REASON_OUTCOMES as $outcome) {
            $intKeys[] = 'ltbp_default_reason_' . $outcome;
        }
        foreach ($intKeys as $key) {
            if (array_key_exists($key, $raw)) {
                $out[$key] = (string) (is_numeric($raw[$key]) && (int) $raw[$key] > 0 ? (int) $raw[$key] : 0);
            }
        }

        foreach (['ltbp_completion_require_document', 'ltbp_solve_ticket_on_completion'] as $key) {
            if (array_key_exists($key, $raw)) {
                $out[$key] = ((string) $raw[$key]) === '1' ? '1' : '0';
            }
        }

        foreach (self::DIRECTOR_KEYS as $key) {
            if (array_key_exists($key, $raw)) {
                $out[$key] = mb_substr(trim((string) $raw[$key]), 0, self::TEXT_MAX);
            }
        }

        return $out;
    }

    /** @param array<string, string> $s */
    public static function stateId(array $s, string $role): int
    {
        return in_array($role, self::STATE_ROLES, true) ? (int) ($s['ltbp_state_' . $role] ?? 0) : 0;
    }

    /**
     * Roles without a mapped State: "Emitir" stays blocked while this is not empty (plan decision 17).
     *
     * @param array<string, string> $s
     * @return list<string>
     */
    public static function missingStateRoles(array $s): array
    {
        return array_values(array_filter(
            self::STATE_ROLES,
            static fn(string $role): bool => self::stateId($s, $role) <= 0
        ));
    }

    /**
     * @param array<string, string> $s
     * @return array{ti: array{name: string, role: string}, adm: array{name: string, role: string}}
     */
    public static function directors(array $s): array
    {
        return [
            'ti'  => ['name' => (string) ($s['ltbp_director_ti_name'] ?? ''), 'role' => (string) ($s['ltbp_director_ti_role'] ?? '')],
            'adm' => ['name' => (string) ($s['ltbp_director_adm_name'] ?? ''), 'role' => (string) ($s['ltbp_director_adm_role'] ?? '')],
        ];
    }

    /** @param array<string, string> $s */
    public static function directorsMissing(array $s): bool
    {
        foreach (self::DIRECTOR_KEYS as $key) {
            if (trim((string) ($s[$key] ?? '')) === '') {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, string> $s */
    public static function requireCompletionDocument(array $s): bool
    {
        return ($s['ltbp_completion_require_document'] ?? '1') === '1';
    }

    /** @param array<string, string> $s */
    public static function defaultReasonId(array $s, string $outcome): int
    {
        return in_array($outcome, self::DEFAULT_REASON_OUTCOMES, true)
            ? (int) ($s['ltbp_default_reason_' . $outcome] ?? 0)
            : 0;
    }

    /** @param array<string, string> $s */
    public static function solveTicketOnCompletion(array $s): bool
    {
        return ($s['ltbp_solve_ticket_on_completion'] ?? '0') === '1';
    }

    /** @param array<string, string> $s */
    public static function logoCategoryId(array $s): int
    {
        return (int) ($s['ltbp_logo_documentcategories_id'] ?? 0);
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml --filter LtbpSettings`
Expected: PASS.

- [ ] **Step 5: Commit**

Invoque o skill `/commit` (tipo `feat`, por exemplo "add typed LTBP settings").

---

### Task 4: Regras puras da emissão, do bloqueio, do texto de local/data e da solução do ticket

**Files:**
- Create: `src/Ltbp/EmissionValidator.php`, `src/Ltbp/LockPolicy.php`, `src/Ltbp/PlaceDate.php`, `src/Ltbp/TicketSolvePolicy.php`
- Test: `tests/Unit/EmissionValidatorTest.php`, `tests/Unit/LockPolicyTest.php`, `tests/Unit/PlaceDateTest.php`, `tests/Unit/TicketSolvePolicyTest.php`

**Interfaces:**
- Consumes: `Destination` (Tarefa 2), `GlpiPlugin\Gac\Shared\ReportFormatter::date()` (Tarefa 1).
- Produces (namespace `GlpiPlugin\Gac\Ltbp`):
  - `EmissionValidator::validate(array $facts): list<string>` com `$facts` = `array{destination: string, line_count: int, lines_without_reason: int, missing_state_roles: list<string>, directors_missing: bool, conflicts: int}`; devolve códigos, na ordem: `destination`, `no_lines`, `reason`, `states`, `directors`, `conflicts`
  - `LockPolicy::blockedFields(array $input, array $fields): list<string>` (`$input` = `$item->input`; `$fields` = `$item->fields`)
  - `PlaceDate::format(string $town, string $state, string $ymd): string`
  - `TicketSolvePolicy::shouldSolve(bool $enabled, int $otherOpenLines): bool`

- [ ] **Step 1: Escrever os testes que falham**

Create `tests/Unit/EmissionValidatorTest.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Tests\Unit;

use GlpiPlugin\Gac\Ltbp\EmissionValidator;
use PHPUnit\Framework\TestCase;

final class EmissionValidatorTest extends TestCase
{
    /** @return array{destination: string, line_count: int, lines_without_reason: int, missing_state_roles: list<string>, directors_missing: bool, conflicts: int} */
    private static function valid(): array
    {
        return [
            'destination'          => 'disposal',
            'line_count'           => 3,
            'lines_without_reason' => 0,
            'missing_state_roles'  => [],
            'directors_missing'    => false,
            'conflicts'            => 0,
        ];
    }

    public function testAValidLaudoHasNoErrors(): void
    {
        $this->assertSame([], EmissionValidator::validate(self::valid()));
        $this->assertSame([], EmissionValidator::validate(['destination' => 'donation'] + self::valid()));
    }

    public function testEachRuleReportsItsOwnCode(): void
    {
        $this->assertSame(['destination'], EmissionValidator::validate(['destination' => ''] + self::valid()));
        $this->assertSame(['destination'], EmissionValidator::validate(['destination' => 'sale'] + self::valid()));
        $this->assertSame(['no_lines'], EmissionValidator::validate(['line_count' => 0] + self::valid()));
        $this->assertSame(['reason'], EmissionValidator::validate(['lines_without_reason' => 2] + self::valid()));
        $this->assertSame(['states'], EmissionValidator::validate(['missing_state_roles' => ['in_process']] + self::valid()));
        $this->assertSame(['directors'], EmissionValidator::validate(['directors_missing' => true] + self::valid()));
        $this->assertSame(['conflicts'], EmissionValidator::validate(['conflicts' => 1] + self::valid()));
    }

    public function testEveryProblemIsReportedInAFixedOrder(): void
    {
        $facts = [
            'destination'          => '',
            'line_count'           => 0,
            'lines_without_reason' => 1,
            'missing_state_roles'  => ['written_off'],
            'directors_missing'    => true,
            'conflicts'            => 2,
        ];
        $this->assertSame(
            ['destination', 'no_lines', 'reason', 'states', 'directors', 'conflicts'],
            EmissionValidator::validate($facts)
        );
    }
}
```

Create `tests/Unit/LockPolicyTest.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Tests\Unit;

use GlpiPlugin\Gac\Ltbp\LockPolicy;
use PHPUnit\Framework\TestCase;

final class LockPolicyTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function fields(): array
    {
        return [
            'id' => '5', 'name' => 'NB-01', 'serial' => 'ABC123', 'states_id' => '7',
            'comment' => 'old note', 'ticket_tco' => '10.0000', 'buy_date' => null, 'date_mod' => '2026-01-01 10:00:00',
        ];
    }

    public function testUnchangedInputBlocksNothing(): void
    {
        $input = ['id' => '5', 'name' => 'NB-01', 'serial' => 'ABC123', 'states_id' => '7', 'ticket_tco' => '10', 'buy_date' => ''];
        $this->assertSame([], LockPolicy::blockedFields($input, self::fields()));
    }

    public function testChangedColumnsAreBlocked(): void
    {
        $input = ['id' => '5', 'name' => 'NB-02', 'states_id' => '9', 'serial' => 'ABC123'];
        $this->assertSame(['name', 'states_id'], LockPolicy::blockedFields($input, self::fields()));
    }

    public function testCommentAndBookkeepingFieldsAreAllowed(): void
    {
        $input = ['id' => '5', 'comment' => 'new note', 'date_mod' => '2026-09-26 10:00:00', 'date_creation' => 'x'];
        $this->assertSame([], LockPolicy::blockedFields($input, self::fields()));
    }

    public function testUnderscoreKeysAndUnknownKeysAreIgnored(): void
    {
        $input = ['_no_history' => true, '_glpi_csrf_token' => 'abc', 'update' => '1', 'not_a_column' => 'x'];
        $this->assertSame([], LockPolicy::blockedFields($input, self::fields()));
    }

    public function testNumericFormattingAndEmptyValuesDoNotCountAsChanges(): void
    {
        $this->assertSame([], LockPolicy::blockedFields(['ticket_tco' => '10.00'], self::fields()));
        $this->assertSame([], LockPolicy::blockedFields(['buy_date' => ''], self::fields()), 'NULL and empty string are the same');
        $this->assertSame(['ticket_tco'], LockPolicy::blockedFields(['ticket_tco' => '11'], self::fields()));
    }

    public function testLeadingZeroSerialChangeIsBlocked(): void
    {
        $fields = ['serial' => '000123'];
        $this->assertSame(['serial'], LockPolicy::blockedFields(['serial' => '123'], $fields));
        $this->assertSame(['serial'], LockPolicy::blockedFields(['serial' => '000123'], ['serial' => '123']));
    }

    public function testLongNumericSerialChangeIsBlocked(): void
    {
        $fields = ['serial' => '89550123456789012345'];
        $this->assertSame(['serial'], LockPolicy::blockedFields(['serial' => '89550123456789012346'], $fields));
    }

    public function testExponentNotationChangeIsBlocked(): void
    {
        $this->assertSame(['serial'], LockPolicy::blockedFields(['serial' => '1e3'], ['serial' => '1000']));
        $this->assertSame(['serial'], LockPolicy::blockedFields(['serial' => '1000'], ['serial' => '1e3']));
    }

    public function testDecimalFormattingStillEqual(): void
    {
        $this->assertSame([], LockPolicy::blockedFields(['ticket_tco' => '10.0'], ['ticket_tco' => '10.0000']));
        $this->assertSame([], LockPolicy::blockedFields(['ticket_tco' => '10'], ['ticket_tco' => '10.0000']));
        $this->assertSame(['ticket_tco'], LockPolicy::blockedFields(['ticket_tco' => '10.5'], ['ticket_tco' => '10.0000']));
    }

    public function testArrayInputsAreIgnored(): void
    {
        $this->assertSame([], LockPolicy::blockedFields(['name' => ['a', 'b']], self::fields()));
    }
}
```

Create `tests/Unit/PlaceDateTest.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Tests\Unit;

use GlpiPlugin\Gac\Ltbp\PlaceDate;
use PHPUnit\Framework\TestCase;

final class PlaceDateTest extends TestCase
{
    public function testTownStateAndDate(): void
    {
        $this->assertSame('Porto Velho/RO, 26/09/2026', PlaceDate::format('Porto Velho', 'RO', '2026-09-26'));
    }

    public function testTownWithoutState(): void
    {
        $this->assertSame('Porto Velho, 26/09/2026', PlaceDate::format(' Porto Velho ', '', '2026-09-26'));
    }

    public function testStateWithoutTown(): void
    {
        $this->assertSame('RO, 26/09/2026', PlaceDate::format('', 'RO', '2026-09-26'));
    }

    public function testNoPlacePrintsOnlyTheDate(): void
    {
        $this->assertSame('26/09/2026', PlaceDate::format('', '', '2026-09-26'));
    }

    public function testAnInvalidDateIsReturnedAsIs(): void
    {
        $this->assertSame('Porto Velho/RO, sem data', PlaceDate::format('Porto Velho', 'RO', 'sem data'));
    }
}
```

Create `tests/Unit/TicketSolvePolicyTest.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Tests\Unit;

use GlpiPlugin\Gac\Ltbp\TicketSolvePolicy;
use PHPUnit\Framework\TestCase;

final class TicketSolvePolicyTest extends TestCase
{
    public function testSolvesOnlyWhenEnabledAndNothingElseIsOpen(): void
    {
        $this->assertTrue(TicketSolvePolicy::shouldSolve(true, 0));
    }

    public function testNeverSolvesWhenDisabled(): void
    {
        $this->assertFalse(TicketSolvePolicy::shouldSolve(false, 0));
    }

    public function testKeepsTheTicketOpenWhileOtherLinesAreActive(): void
    {
        $this->assertFalse(TicketSolvePolicy::shouldSolve(true, 1));
        $this->assertFalse(TicketSolvePolicy::shouldSolve(true, 5));
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml --filter 'EmissionValidator|LockPolicy|PlaceDate|TicketSolvePolicy'`
Expected: FAIL/ERROR (classes não encontradas).

- [ ] **Step 3: Implementar**

Create `src/Ltbp/EmissionValidator.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Ltbp;

/**
 * Pure: the pre-checks of "Emitir" (spec 6.1), as codes. The service turns each code into a
 * translated message, so this stays free of GLPI.
 */
final class EmissionValidator
{
    /**
     * @param array{destination: string, line_count: int, lines_without_reason: int, missing_state_roles: list<string>, directors_missing: bool, conflicts: int} $facts
     * @return list<string> codes: destination, no_lines, reason, states, directors, conflicts
     */
    public static function validate(array $facts): array
    {
        $errors = [];
        if (Destination::tryFrom($facts['destination']) === null) {
            $errors[] = 'destination';
        }
        if ($facts['line_count'] <= 0) {
            $errors[] = 'no_lines';
        }
        if ($facts['lines_without_reason'] > 0) {
            $errors[] = 'reason';
        }
        if ($facts['missing_state_roles'] !== []) {
            $errors[] = 'states';
        }
        if ($facts['directors_missing']) {
            $errors[] = 'directors';
        }
        if ($facts['conflicts'] > 0) {
            $errors[] = 'conflicts';
        }
        return $errors;
    }
}
```

Create `src/Ltbp/LockPolicy.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Ltbp;

/**
 * Pure: which columns of an asset update are refused once the asset is written off (spec L14).
 * Everything is refused except the free comment and GLPI's own bookkeeping columns.
 */
final class LockPolicy
{
    private const ALLOWED = ['id', 'comment', 'date_mod', 'date_creation'];

    /**
     * @param array<string, mixed> $input  the update input ($item->input)
     * @param array<string, mixed> $fields the current values ($item->fields)
     * @return list<string> input keys that would change a locked column
     */
    public static function blockedFields(array $input, array $fields): array
    {
        $blocked = [];
        foreach ($input as $key => $value) {
            $key = (string) $key;
            if ($key === '' || $key[0] === '_' || in_array($key, self::ALLOWED, true) || !array_key_exists($key, $fields)) {
                continue;
            }
            if (is_array($value)) {
                continue;
            }
            if (!self::same($value, $fields[$key])) {
                $blocked[] = $key;
            }
        }
        return $blocked;
    }

    /**
     * NULL and '' are the same. Only decimal-looking strings ('10.0000', '10.50') are canonicalised
     * by stripping trailing zeros, so '10.0000' equals '10.00' and '10'. Everything else compares as
     * a plain string: never as floats, which would hide changes in serials ('000123' vs '123',
     * 20-digit values, '1e3' vs '1000').
     */
    private static function same(mixed $a, mixed $b): bool
    {
        $a = $a === null ? '' : trim((string) $a);
        $b = $b === null ? '' : trim((string) $b);
        $c = static fn(string $v): string => preg_match('/^-?\d+\.\d+$/', $v) === 1 ? rtrim(rtrim($v, '0'), '.') : $v;
        return $c($a) === $c($b);
    }
}
```

Create `src/Ltbp/PlaceDate.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Ltbp;

use GlpiPlugin\Gac\Shared\ReportFormatter;

/** Pure: the "Cidade/UF, dd/mm/aaaa" line above the signatures (spec L15). */
final class PlaceDate
{
    public static function format(string $town, string $state, string $ymd): string
    {
        $town  = trim($town);
        $state = trim($state);
        $date  = ReportFormatter::date($ymd);

        $place = $town !== '' && $state !== '' ? $town . '/' . $state : $town . $state;

        return $place === '' ? $date : $place . ', ' . $date;
    }
}
```

Create `src/Ltbp/TicketSolvePolicy.php`:

```php
<?php

declare(strict_types=1);

namespace GlpiPlugin\Gac\Ltbp;

/** Pure: whether completing a laudo also solves the source ticket (spec L25, same idea as PRE D17). */
final class TicketSolvePolicy
{
    public static function shouldSolve(bool $enabled, int $otherOpenLines): bool
    {
        return $enabled && $otherOpenLines === 0;
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml`
Expected: PASS em toda a suíte.

- [ ] **Step 5: Commit**

Invoque o skill `/commit` (tipo `feat`, por exemplo "add pure LTBP emission, lock and ticket rules").

---

### Task 5: Objetos de dados, instalação, direitos e registro do módulo

**Files:**
- Create: `src/Ltbp/Ltbp.php`, `src/Ltbp/LtbpItem.php`, `src/Ltbp/LtbpEvent.php`, `src/Ltbp/LtbpReason.php`, `src/Ltbp/NumberGenerator.php`, `src/Ltbp/Labels.php`
- Modify: `hook.php`, `src/Features.php`

**Interfaces:**
- Consumes: `Status`, `Destination`, `StateMachine`, `LtbpNumber`, `LtbpSettings` (Tarefas 2 a 4); `Shared\EventMessage` (Tarefa 1).
- Produces (namespace `GlpiPlugin\Gac\Ltbp`):
  - `Ltbp` (`CommonDBTM`): `public static $rightname = 'plugin_gac_ltbp'`; constantes `RIGHT_ISSUE = 256`, `RIGHT_CANCEL = 512`, `RIGHT_EDIT_WRITTEN_OFF = 1024`, `RIGHT_CONFIG`; `HISTORY_OPTION_EVENT = 90`; `getStatus(): Status`; `getDestination(): ?Destination`; `changeStatus(Status $new, array $extra = []): void`; `lines(): list<array<string, mixed>>`; `static activeLaudoFor(string $itemtype, int $itemsId): ?array{id: int, number: string, status: Status}`
  - `LtbpItem` (`CommonDBChild`): tabela `glpi_plugin_gac_ltbpitems`, FK `plugin_gac_ltbps_id`
  - `LtbpEvent::log(int $ltbpId, string $event, string $reason = '', array $details = [], int $lineId = 0): void`
  - `LtbpReason` (`CommonDBTM`): `static isUsed(int $id): bool`; `static choices(bool $activeOnly = true): array<int, string>` (`id => "COD: título"`); `static row(int $id): ?array<string, mixed>`
  - `NumberGenerator::next(?int $year = null): string`
  - `Labels::status(Status): string`, `Labels::destination(Destination): string`, `Labels::event(string): string`
  - Tabelas: `glpi_plugin_gac_ltbps`, `_ltbpitems`, `_ltbpevents`, `_ltbpreasons`, `_ltbpsequences`

Esta tarefa não tem teste automatizado (tudo depende do GLPI): a verificação é o lint e a reinstalação no GLPI de dev.

- [ ] **Step 1: Criar `Labels`**

Create `src/Ltbp/Labels.php`:

```php
<?php

namespace GlpiPlugin\Gac\Ltbp;

final class Labels
{
    public static function status(Status $s): string
    {
        return match ($s) {
            Status::Draft              => __('Rascunho', 'gac'),
            Status::AwaitingSignatures => __('Aguardando assinaturas', 'gac'),
            Status::Signed             => __('Assinado', 'gac'),
            Status::AtPatrimony        => __('No patrimônio', 'gac'),
            Status::WrittenOff         => __('Baixado', 'gac'),
            Status::Completed          => __('Concluído', 'gac'),
            Status::Canceled           => __('Cancelado', 'gac'),
        };
    }

    public static function destination(Destination $d): string
    {
        return match ($d) {
            Destination::Disposal => __('Descarte ecológico', 'gac'),
            Destination::Donation => __('Doação', 'gac'),
        };
    }

    public static function event(string $event): string
    {
        return match ($event) {
            'created'                => __('Laudo criado', 'gac'),
            'line_added'             => __('Ativo adicionado', 'gac'),
            'line_removed'           => __('Ativo removido', 'gac'),
            'issued'                 => __('Laudo emitido para assinatura', 'gac'),
            'signed_uploaded'        => __('PDF assinado anexado', 'gac'),
            'sent_to_patrimony'      => __('Enviado ao patrimônio', 'gac'),
            'written_off'            => __('Baixa confirmada pelo patrimônio', 'gac'),
            'completed'              => __('Destinação concluída', 'gac'),
            'canceled'               => __('Laudo cancelado', 'gac'),
            'document_attached'      => __('Documento anexado', 'gac'),
            'line_document_attached' => __('Documento anexado à linha', 'gac'),
            default                  => $event,
        };
    }
}
```

- [ ] **Step 2: Criar `NumberGenerator`**

Create `src/Ltbp/NumberGenerator.php`:

```php
<?php

namespace GlpiPlugin\Gac\Ltbp;

/**
 * Atomic per-year counter, same technique as the PRE (INSERT ... ON DUPLICATE KEY UPDATE with
 * LAST_INSERT_ID(expr) makes the increment and the read one race-free step on this connection).
 */
final class NumberGenerator
{
    public static function next(?int $year = null): string
    {
        global $DB;

        $year ??= (int) date('Y');
        $DB->doQuery(sprintf(
            'INSERT INTO `glpi_plugin_gac_ltbpsequences` (`year`, `last`) VALUES (%d, LAST_INSERT_ID(1))'
            . ' ON DUPLICATE KEY UPDATE `last` = LAST_INSERT_ID(`last` + 1)',
            $year
        ));
        $row = $DB->doQuery('SELECT LAST_INSERT_ID() AS seq')->fetch_assoc();

        return LtbpNumber::format($year, (int) $row['seq']);
    }
}
```

- [ ] **Step 3: Criar `Ltbp`**

Create `src/Ltbp/Ltbp.php`:

```php
<?php

namespace GlpiPlugin\Gac\Ltbp;

use CommonDBTM;
use GlpiPlugin\Gac\Features;
use Session;

/**
 * The Laudo Técnico de Baixa Patrimonial (spec section 5.1). UI methods (form, tabs, search
 * options) are added in the pages task; the data rules live here.
 */
class Ltbp extends CommonDBTM
{
    public static $rightname = 'plugin_gac_ltbp';
    /** Search option id that labels the events mirrored into the native history. */
    public const HISTORY_OPTION_EVENT = 90;

    public $dohistory = true;

    public const RIGHT_ISSUE            = 256;
    public const RIGHT_CANCEL           = 512;
    public const RIGHT_EDIT_WRITTEN_OFF = 1024;
    public const RIGHT_CONFIG           = Features::RIGHT_CONFIG;

    /** Explicit: the class sits in a sub-namespace, GLPI's derived name would be wrong. */
    public static function getTable($classname = null)
    {
        if ($classname !== null && $classname !== static::class) {
            return parent::getTable($classname);
        }
        return 'glpi_plugin_gac_ltbps';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Laudo Técnico de Baixa Patrimonial', 'Laudos Técnicos de Baixa Patrimonial', $nb, 'gac');
    }

    /** The laudo has no "name" column: the number identifies it in titles and logs. */
    public static function getNameField()
    {
        return 'number';
    }

    public static function getIcon()
    {
        return 'ti ti-file-certificate';
    }

    public function getRights($interface = 'central')
    {
        $values = parent::getRights($interface);
        $values[self::RIGHT_ISSUE]            = __('Emitir e avançar etapas', 'gac');
        $values[self::RIGHT_CANCEL]           = __('Cancelar', 'gac');
        $values[self::RIGHT_EDIT_WRITTEN_OFF] = __('Editar ativo baixado', 'gac');
        $values[self::RIGHT_CONFIG]           = __('Configurar', 'gac');
        return $values;
    }

    public function getStatus(): Status
    {
        return Status::from($this->fields['status']);
    }

    public function getDestination(): ?Destination
    {
        return Destination::tryFrom((string) ($this->fields['destination'] ?? ''));
    }

    /** The header form is editable only while the laudo is a draft (spec section 6). */
    public function canUpdateItem(): bool
    {
        return parent::canUpdateItem() && ($this->isNewItem() || StateMachine::canEditDraft($this->getStatus()));
    }

    /** Only drafts and canceled laudos can be purged. */
    public function canPurgeItem(): bool
    {
        return parent::canPurgeItem() && StateMachine::canPurge($this->getStatus());
    }

    public function prepareInputForAdd($input)
    {
        if (Destination::tryFrom((string) ($input['destination'] ?? '')) === null) {
            Session::addMessageAfterRedirect(__('Informe a destinação.', 'gac'), false, ERROR);
            return false;
        }

        $input['status'] = Status::Draft->value;
        $input['number'] = NumberGenerator::next();
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
        LtbpEvent::log((int) $this->getID(), 'created');
        parent::post_addItem();
    }

    public function prepareInputForUpdate($input)
    {
        // These change only through services, never from the form.
        unset(
            $input['number'],
            $input['status'],
            $input['entities_id'],
            $input['date_issued'],
            $input['date_signed'],
            $input['date_sent_patrimony'],
            $input['date_written_off'],
            $input['date_completed'],
            $input['date_canceled'],
            $input['director_ti_name'],
            $input['director_ti_role'],
            $input['director_adm_name'],
            $input['director_adm_role'],
            $input['received_by'],
            $input['writeoff_process_number'],
            $input['writeoff_notes'],
            $input['suppliers_id'],
            $input['supplier_name'],
            $input['completion_notes'],
            $input['cancel_reason'],
            $input['documents_id_frozen'],
            $input['documents_id_signed']
        );

        if (isset($input['destination']) && Destination::tryFrom((string) $input['destination']) === null) {
            unset($input['destination']);
        }

        return $input;
    }

    /** Direct status write for services; not exposed to form input on purpose. */
    public function changeStatus(Status $new, array $extra = []): void
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
            'FROM'  => LtbpItem::getTable(),
            'WHERE' => ['plugin_gac_ltbps_id' => $this->getID()],
            'ORDER' => ['id ASC'],
        ]) as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function cleanDBonPurge()
    {
        $this->deleteChildrenAndRelationsFromDb([
            LtbpItem::class,
            LtbpEvent::class,
        ]);
    }

    /**
     * The laudo that holds an asset right now (any status but Canceled), or null (spec L8, L21).
     * The PRE items tab reads this to show its "Aguardando laudo" badge (plan decision 14).
     *
     * @return array{id: int, number: string, status: Status}|null
     */
    public static function activeLaudoFor(string $itemtype, int $itemsId): ?array
    {
        global $DB;

        $items  = LtbpItem::getTable();
        $laudos = self::getTable();

        $row = $DB->request([
            'SELECT'     => ["$laudos.id AS laudo_id", "$laudos.number AS laudo_number", "$laudos.status AS laudo_status"],
            'FROM'       => $items,
            'INNER JOIN' => [
                $laudos => ['ON' => [$items => 'plugin_gac_ltbps_id', $laudos => 'id']],
            ],
            'WHERE' => [
                "$items.itemtype"  => $itemtype,
                "$items.items_id"  => $itemsId,
                "$laudos.status"   => Status::holdingValues(),
            ],
            'ORDER' => ["$laudos.id DESC"],
            'LIMIT' => 1,
        ])->current();

        if ($row === null) {
            return null;
        }
        return [
            'id'     => (int) $row['laudo_id'],
            'number' => (string) $row['laudo_number'],
            'status' => Status::from((string) $row['laudo_status']),
        ];
    }
}
```

- [ ] **Step 4: Criar `LtbpItem` e `LtbpEvent`**

Create `src/Ltbp/LtbpItem.php`:

```php
<?php

namespace GlpiPlugin\Gac\Ltbp;

use CommonDBChild;

/** One asset inside a laudo (spec section 5.2). The items tab is added in the pages task. */
class LtbpItem extends CommonDBChild
{
    public static $itemtype  = Ltbp::class;
    public static $items_id  = 'plugin_gac_ltbps_id';
    public static $rightname = 'plugin_gac_ltbp';
    public $dohistory        = false;

    /** The line has no "name" column; this keeps the native history readable when a line is added or removed. */
    public function getName($options = [])
    {
        if (empty($this->fields['item_name'])) {
            return parent::getName($options);
        }
        return sprintf('%s · %s', $this->fields['item_type_label'] ?? '', $this->fields['item_name']);
    }

    public static function getTable($classname = null)
    {
        if ($classname !== null && $classname !== static::class) {
            return parent::getTable($classname);
        }
        return 'glpi_plugin_gac_ltbpitems';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Item', 'Itens', $nb, 'gac');
    }
}
```

Create `src/Ltbp/LtbpEvent.php`:

```php
<?php

namespace GlpiPlugin\Gac\Ltbp;

use CommonDBChild;
use GlpiPlugin\Gac\Shared\EventMessage;
use Session;

/**
 * Event log of a laudo (spec 5.4). Every event is also written as a text line to GLPI's native
 * history, which is the only history tab (same approach as the PRE, D24).
 */
class LtbpEvent extends CommonDBChild
{
    public static $itemtype = Ltbp::class;
    public static $items_id = 'plugin_gac_ltbps_id';
    public $dohistory       = false;

    /** Events are mirrored into the laudo's native history explicitly (see log()), not by GLPI. */
    public static $logs_for_parent = false;

    public static function getTable($classname = null)
    {
        if ($classname !== null && $classname !== static::class) {
            return parent::getTable($classname);
        }
        return 'glpi_plugin_gac_ltbpevents';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Evento', 'Eventos', $nb, 'gac');
    }

    /** @param array<string, mixed> $details */
    public static function log(
        int $ltbpId,
        string $event,
        string $reason = '',
        array $details = [],
        int $lineId = 0
    ): void {
        (new self())->add([
            'plugin_gac_ltbps_id'     => $ltbpId,
            'plugin_gac_ltbpitems_id' => $lineId,
            'event'                   => $event,
            'users_id'                => (int) Session::getLoginUserID(),
            'reason'                  => $reason,
            'details'                 => $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE),
        ]);

        // "created" already has GLPI's own "item created" line in the native history.
        if ($event !== 'created') {
            \Log::history(
                $ltbpId,
                Ltbp::class,
                [Ltbp::HISTORY_OPTION_EVENT, '', self::message($event, $reason, $lineId)]
            );
        }
    }

    /** The text of an event in the native history (plain text, at most 255 characters). */
    public static function message(string $event, string $reason, int $lineId): string
    {
        $detail = match ($event) {
            'canceled', 'line_removed' => $reason === '' ? '' : sprintf(__('Motivo: %s', 'gac'), $reason),
            default                    => $reason,
        };

        return EventMessage::compose(Labels::event($event), self::lineLabel($lineId), $detail);
    }

    /** "type · asset" of a line, empty when there is no line (or it no longer exists). */
    private static function lineLabel(int $lineId): string
    {
        global $DB;

        if ($lineId <= 0) {
            return '';
        }
        $row = $DB->request([
            'SELECT' => ['item_type_label', 'item_name'],
            'FROM'   => LtbpItem::getTable(),
            'WHERE'  => ['id' => $lineId],
        ])->current();

        return $row === null ? '' : sprintf('%s · %s', (string) $row['item_type_label'], (string) $row['item_name']);
    }
}
```

- [ ] **Step 5: Criar `LtbpReason`**

Create `src/Ltbp/LtbpReason.php`:

```php
<?php

namespace GlpiPlugin\Gac\Ltbp;

use CommonDBTM;
use GlpiPlugin\Gac\Features;
use Session;

/**
 * Catalog of write-off reasons (spec L9): code, title (`name`), description (`comment`), active
 * flag. A reason in use can only be deactivated, never deleted. Managed from the module's
 * configuration, so every right check is the "Configurar" bit.
 */
class LtbpReason extends CommonDBTM
{
    public static $rightname = 'plugin_gac_ltbp';
    public $dohistory        = false;

    private const CODE_MAX = 20;

    public static function getTable($classname = null)
    {
        if ($classname !== null && $classname !== static::class) {
            return parent::getTable($classname);
        }
        return 'glpi_plugin_gac_ltbpreasons';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Motivo de baixa', 'Motivos de baixa', $nb, 'gac');
    }

    public static function getIcon()
    {
        return 'ti ti-list-check';
    }

    public static function canView(): bool
    {
        return Features::canConfigure(self::$rightname);
    }

    public static function canCreate(): bool
    {
        return Features::canConfigure(self::$rightname);
    }

    public static function canUpdate(): bool
    {
        return Features::canConfigure(self::$rightname);
    }

    public static function canDelete(): bool
    {
        return Features::canConfigure(self::$rightname);
    }

    public static function canPurge(): bool
    {
        return Features::canConfigure(self::$rightname);
    }

    /** A reason already used by a laudo line can be deactivated, not deleted (spec L9). */
    public function canPurgeItem(): bool
    {
        return parent::canPurgeItem() && !self::isUsed((int) $this->getID());
    }

    public static function isUsed(int $id): bool
    {
        return countElementsInTable(LtbpItem::getTable(), ['plugin_gac_ltbpreasons_id' => $id]) > 0;
    }

    public function prepareInputForAdd($input)
    {
        return $this->checkedInput($input, 0);
    }

    public function prepareInputForUpdate($input)
    {
        return $this->checkedInput($input, (int) ($input['id'] ?? $this->getID()));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    private function checkedInput(array $input, int $selfId): array|false
    {
        if (array_key_exists('code', $input)) {
            $input['code'] = mb_strtoupper(mb_substr(trim((string) $input['code']), 0, self::CODE_MAX));
            if ($input['code'] === '') {
                Session::addMessageAfterRedirect(__('Informe o código do motivo.', 'gac'), false, ERROR);
                return false;
            }
            $duplicates = countElementsInTable(self::getTable(), ['code' => $input['code'], ['NOT' => ['id' => $selfId]]]);
            if ($duplicates > 0) {
                Session::addMessageAfterRedirect(__('Já existe um motivo com este código.', 'gac'), false, ERROR);
                return false;
            }
        }
        if (array_key_exists('name', $input) && trim((string) $input['name']) === '') {
            Session::addMessageAfterRedirect(__('Informe o título do motivo.', 'gac'), false, ERROR);
            return false;
        }
        return $input;
    }

    /** @return array<string, mixed>|null */
    public static function row(int $id): ?array
    {
        global $DB;

        $row = $DB->request(['FROM' => self::getTable(), 'WHERE' => ['id' => $id]])->current();
        return $row === null ? null : (array) $row;
    }

    /**
     * Options of a reason dropdown: id => "COD: título", ordered by code.
     *
     * @return array<int, string>
     */
    public static function choices(bool $activeOnly = true): array
    {
        global $DB;

        $where = $activeOnly ? ['is_active' => 1] : [];
        $out = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => $where, 'ORDER' => ['code ASC']]) as $row) {
            $out[(int) $row['id']] = sprintf('%s: %s', $row['code'], $row['name']);
        }
        return $out;
    }
}
```

- [ ] **Step 6: Registrar o direito na tela de perfis**

Modify `src/Features.php`: adicione `use GlpiPlugin\Gac\Ltbp\Ltbp;` ao lado do `use ...RepairProtocol;` e acrescente uma segunda linha em `all()`:

```php
    public static function all(): array
    {
        return [
            [
                'itemtype' => RepairProtocol::class,
                'label'    => RepairProtocol::getTypeName(2),
                'field'    => RepairProtocol::$rightname,
            ],
            [
                'itemtype' => Ltbp::class,
                'label'    => Ltbp::getTypeName(2),
                'field'    => Ltbp::$rightname,
            ],
        ];
    }
```

- [ ] **Step 7: Criar as tabelas, os direitos e a configuração em `hook.php`**

Modify `hook.php`.

1. Nos `use` do topo, acrescente:

```php
use GlpiPlugin\Gac\Ltbp\Ltbp;
use GlpiPlugin\Gac\Ltbp\LtbpReason;
use GlpiPlugin\Gac\Ltbp\LtbpSettings;
```

2. Em `plugin_gac_install()`, logo **depois** do bloco que cria `$sequences` (`glpi_plugin_gac_protocolsequences`) e **antes** do comentário `// Default configuration:`, insira:

```php
    $ltbps = 'glpi_plugin_gac_ltbps';
    if (!$DB->tableExists($ltbps)) {
        $DB->doQuery("CREATE TABLE `$ltbps` (
            `id` INT {$sign} NOT NULL AUTO_INCREMENT,
            `entities_id` INT {$sign} NOT NULL DEFAULT '0',
            `number` VARCHAR(20) NOT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'draft',
            `destination` VARCHAR(20) NOT NULL DEFAULT '',
            `users_id_tech` INT {$sign} NOT NULL DEFAULT '0',
            `date_issued` DATE DEFAULT NULL,
            `date_signed` DATE DEFAULT NULL,
            `date_sent_patrimony` DATE DEFAULT NULL,
            `date_written_off` DATE DEFAULT NULL,
            `date_completed` DATE DEFAULT NULL,
            `date_canceled` TIMESTAMP NULL DEFAULT NULL,
            `director_ti_name` VARCHAR(255) DEFAULT NULL,
            `director_ti_role` VARCHAR(255) DEFAULT NULL,
            `director_adm_name` VARCHAR(255) DEFAULT NULL,
            `director_adm_role` VARCHAR(255) DEFAULT NULL,
            `received_by` VARCHAR(255) DEFAULT NULL,
            `writeoff_process_number` VARCHAR(255) DEFAULT NULL,
            `writeoff_notes` TEXT DEFAULT NULL,
            `suppliers_id` INT {$sign} NOT NULL DEFAULT '0',
            `supplier_name` VARCHAR(255) DEFAULT NULL,
            `completion_notes` TEXT DEFAULT NULL,
            `cancel_reason` TEXT DEFAULT NULL,
            `documents_id_frozen` INT {$sign} NOT NULL DEFAULT '0',
            `documents_id_signed` INT {$sign} NOT NULL DEFAULT '0',
            `comment` TEXT DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `number` (`number`),
            KEY `entities_id` (`entities_id`),
            KEY `status` (`status`),
            KEY `users_id_tech` (`users_id_tech`),
            KEY `suppliers_id` (`suppliers_id`),
            KEY `date_creation` (`date_creation`),
            KEY `date_mod` (`date_mod`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    }

    $ltbpItems = 'glpi_plugin_gac_ltbpitems';
    if (!$DB->tableExists($ltbpItems)) {
        $DB->doQuery("CREATE TABLE `$ltbpItems` (
            `id` INT {$sign} NOT NULL AUTO_INCREMENT,
            `plugin_gac_ltbps_id` INT {$sign} NOT NULL DEFAULT '0',
            `itemtype` VARCHAR(255) NOT NULL DEFAULT '',
            `items_id` INT {$sign} NOT NULL DEFAULT '0',
            `item_entities_id` INT {$sign} NOT NULL DEFAULT '0',
            `item_name` VARCHAR(255) DEFAULT NULL,
            `item_type_label` VARCHAR(255) DEFAULT NULL,
            `brand` VARCHAR(255) DEFAULT NULL,
            `model` VARCHAR(255) DEFAULT NULL,
            `serial` VARCHAR(255) DEFAULT NULL,
            `otherserial` VARCHAR(255) DEFAULT NULL,
            `plugin_gac_ltbpreasons_id` INT {$sign} NOT NULL DEFAULT '0',
            `reason_code` VARCHAR(20) DEFAULT NULL,
            `reason_title` VARCHAR(255) DEFAULT NULL,
            `reason_description` TEXT DEFAULT NULL,
            `states_id_before` INT {$sign} DEFAULT NULL,
            `pre_items_id` INT {$sign} NOT NULL DEFAULT '0',
            `pre_number` VARCHAR(20) DEFAULT NULL,
            `tickets_id` INT {$sign} NOT NULL DEFAULT '0',
            `last_error` TEXT DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`plugin_gac_ltbps_id`, `itemtype`, `items_id`),
            KEY `item` (`itemtype`, `items_id`),
            KEY `tickets_id` (`tickets_id`),
            KEY `plugin_gac_ltbpreasons_id` (`plugin_gac_ltbpreasons_id`),
            KEY `date_mod` (`date_mod`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    }

    $ltbpEvents = 'glpi_plugin_gac_ltbpevents';
    if (!$DB->tableExists($ltbpEvents)) {
        $DB->doQuery("CREATE TABLE `$ltbpEvents` (
            `id` INT {$sign} NOT NULL AUTO_INCREMENT,
            `plugin_gac_ltbps_id` INT {$sign} NOT NULL DEFAULT '0',
            `plugin_gac_ltbpitems_id` INT {$sign} NOT NULL DEFAULT '0',
            `event` VARCHAR(30) NOT NULL,
            `users_id` INT {$sign} NOT NULL DEFAULT '0',
            `reason` TEXT DEFAULT NULL,
            `details` LONGTEXT DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `plugin_gac_ltbps_id` (`plugin_gac_ltbps_id`),
            KEY `plugin_gac_ltbpitems_id` (`plugin_gac_ltbpitems_id`),
            KEY `event` (`event`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    }

    $ltbpReasons = 'glpi_plugin_gac_ltbpreasons';
    if (!$DB->tableExists($ltbpReasons)) {
        $DB->doQuery("CREATE TABLE `$ltbpReasons` (
            `id` INT {$sign} NOT NULL AUTO_INCREMENT,
            `code` VARCHAR(20) NOT NULL,
            `name` VARCHAR(255) NOT NULL DEFAULT '',
            `comment` TEXT DEFAULT NULL,
            `is_active` TINYINT NOT NULL DEFAULT '1',
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `code` (`code`),
            KEY `is_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    }

    $ltbpSequences = 'glpi_plugin_gac_ltbpsequences';
    if (!$DB->tableExists($ltbpSequences)) {
        $DB->doQuery("CREATE TABLE `$ltbpSequences` (
            `year` SMALLINT UNSIGNED NOT NULL,
            `last` INT UNSIGNED NOT NULL DEFAULT '0',
            PRIMARY KEY (`year`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    }
```

3. No bloco "Default configuration", troque a linha do `array_diff_key` por:

```php
    $missing = array_diff_key(PreSettings::defaults() + LtbpSettings::defaults(), $current);
```

4. Depois do bloco de direitos do PRE (o que termina antes do comentário `// The definitive PDF is stored through Document`), acrescente o direito do LTBP:

```php
    // LTBP right: same rule as the PRE. Profiles that already hold the native 'config' right
    // get everything except "Editar ativo baixado", which each administrator grants per profile.
    $ltbpRight = Ltbp::$rightname;
    if (countElementsInTable(ProfileRight::getTable(), ['name' => $ltbpRight]) === 0) {
        ProfileRight::addProfileRights([$ltbpRight]);

        $ltbp_admin_profiles = array_column(
            iterator_to_array($DB->request([
                'SELECT' => 'profiles_id',
                'FROM'   => ProfileRight::getTable(),
                'WHERE'  => ['name' => 'config', 'rights' => ['>', 0]],
            ])),
            'profiles_id'
        );
        if ($ltbp_admin_profiles !== []) {
            $DB->update(
                ProfileRight::getTable(),
                ['rights' => ALLSTANDARDRIGHT
                    | Ltbp::RIGHT_ISSUE
                    | Ltbp::RIGHT_CANCEL
                    | Ltbp::RIGHT_CONFIG],
                ['name' => $ltbpRight, 'profiles_id' => $ltbp_admin_profiles]
            );
        }
    }
```

5. Depois do bloco `glpi_displaypreferences` do PRE (o `if (countElementsInTable('glpi_displaypreferences', ['itemtype' => RepairProtocol::class]) === 0)`), acrescente as colunas padrão das listas (status e destinação do laudo; título e ativo do motivo). Os números são as opções de busca definidas na Tarefa 7 (laudo: 3 = status, 4 = destinação) e na Tarefa 6 (motivo: 3 = título, 5 = ativo):

```php
    foreach ([
        Ltbp::class       => [3, 4],
        LtbpReason::class => [3, 5],
    ] as $itemtype => $nums) {
        if (countElementsInTable('glpi_displaypreferences', ['itemtype' => $itemtype]) === 0) {
            foreach ($nums as $rank => $num) {
                $DB->insert('glpi_displaypreferences', [
                    'itemtype' => $itemtype,
                    'num'      => $num,
                    'rank'     => $rank + 1,
                    'users_id' => 0,
                ]);
            }
        }
    }
```

6. Em `plugin_gac_uninstall()`: acrescente as cinco tabelas novas à lista `foreach`:

```php
        'glpi_plugin_gac_ltbps',
        'glpi_plugin_gac_ltbpitems',
        'glpi_plugin_gac_ltbpevents',
        'glpi_plugin_gac_ltbpreasons',
        'glpi_plugin_gac_ltbpsequences',
```

e, depois do `$DB->delete('glpi_displaypreferences', ['itemtype' => RepairProtocol::class]);`, acrescente:

```php
    $DB->delete(ProfileRight::getTable(), ['name' => Ltbp::$rightname]);
    $DB->delete('glpi_displaypreferences', ['itemtype' => [Ltbp::class, LtbpReason::class]]);
```

e troque a linha do `Config::deleteConfigurationValues` por:

```php
    Config::deleteConfigurationValues('plugin:gac', array_merge(
        array_keys(PreSettings::defaults()),
        array_keys(LtbpSettings::defaults()),
        ['pre_config_right_migrated']
    ));
```

- [ ] **Step 8: Lint**

```bash
for f in src/Ltbp/Labels.php src/Ltbp/NumberGenerator.php src/Ltbp/Ltbp.php src/Ltbp/LtbpItem.php src/Ltbp/LtbpEvent.php src/Ltbp/LtbpReason.php src/Features.php hook.php; do /c/xampp/php/php.exe -l "$f"; done
```
Expected: `No syntax errors detected` ×8.

- [ ] **Step 9: Reinstalar o plugin no GLPI de dev e conferir**

Rode o comando de reinstalação das "Restrições globais". Depois:

```bash
cat /c/Users/juliano/VSCode/glpi-xampp-dev-plugin/config/config_db.php | grep -E "dbuser|dbpassword|dbdefault"
/c/xampp/mysql/bin/mysql.exe -h127.0.0.1 -P3307 -u<dbuser> -p<dbpassword> <dbdefault> -e "SHOW TABLES LIKE 'glpi_plugin_gac_ltbp%'; SELECT name, COUNT(*) FROM glpi_profilerights WHERE name='plugin_gac_ltbp';"
```
Expected: as cinco tabelas `glpi_plugin_gac_ltbps`, `_ltbpevents`, `_ltbpitems`, `_ltbpreasons`, `_ltbpsequences`, e a linha `plugin_gac_ltbp` com uma contagem igual ao número de perfis. Faça logout/login no GLPI. Em Administração > Perfis > (um perfil administrador) > aba **Plugin - DTI GAC**: deve aparecer a linha "Laudos Técnicos de Baixa Patrimonial" com as caixas (ler, criar, editar, excluir permanentemente e os quatro especiais: Emitir e avançar etapas, Cancelar, Editar ativo baixado, Configurar). Marque todas menos "Editar ativo baixado" e salve.

- [ ] **Step 10: Commit**

Invoque o skill `/commit` (tipo `feat`, por exemplo "add LTBP data objects, install steps and profile right").

---

### Task 6: Cadastro de motivos e seção de configuração do módulo

**Files:**
- Create: `src/Ltbp/LtbpConfig.php`, `src/Ltbp/LtbpConfigSection.php`, `templates/ltbp/reason.form.html.twig`, `front/ltbp/ltbpreason.php`, `front/ltbp/ltbpreason.form.php`
- Modify: `src/Ltbp/LtbpReason.php` (form e busca), `src/Config.php`

**Interfaces:**
- Consumes: `LtbpSettings`, `LtbpReason`, `Ltbp::$rightname`, `Features::canConfigure()`, `GacMenu::SECTOR`, `GacMenu::ITEM_CONFIG`.
- Produces: `LtbpConfig::load(): array<string, string>`, `LtbpConfig::save(array $raw): void`; `LtbpConfigSection` registrada em `Config::sections()`; páginas `front/ltbp/ltbpreason.php` (lista) e `ltbpreason.form.php` (formulário); busca de `LtbpReason` com as opções 1 (código), 2 (id), 3 (título), 4 (descrição), 5 (ativo).

- [ ] **Step 1: Criar a página de lista dos motivos**

Create `front/ltbp/ltbpreason.php`:

```php
<?php

use GlpiPlugin\Gac\GacMenu;
use GlpiPlugin\Gac\Ltbp\LtbpReason;

if (!LtbpReason::canView()) {
    Html::displayRightError();
}

Html::header(
    LtbpReason::getTypeName(2),
    $_SERVER['PHP_SELF'],
    GacMenu::SECTOR,
    GacMenu::ITEM_CONFIG
);

Search::show(LtbpReason::class);

Html::footer();
```

- [ ] **Step 2: Criar o formulário dos motivos**

Create `front/ltbp/ltbpreason.form.php`:

```php
<?php

use GlpiPlugin\Gac\GacMenu;
use GlpiPlugin\Gac\Ltbp\LtbpReason;

$item = new LtbpReason();

if (isset($_POST['add'])) {
    $item->check(-1, CREATE, $_POST);
    $newid = $item->add($_POST);
    if ($newid) {
        Html::redirect(LtbpReason::getFormURLWithID($newid));
    }
    Html::back();
} elseif (isset($_POST['update'])) {
    $item->check($_POST['id'], UPDATE);
    $item->update($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    $item->check($_POST['id'], PURGE);
    if ($item->delete($_POST, 1)) {
        $item->redirectToList();
    }
    Session::addMessageAfterRedirect(
        __('Este motivo já foi usado em algum laudo: inative-o em vez de excluir.', 'gac'),
        false,
        ERROR
    );
    Html::back();
} else {
    Html::header(
        LtbpReason::getTypeName(1),
        $_SERVER['PHP_SELF'],
        GacMenu::SECTOR,
        GacMenu::ITEM_CONFIG
    );
    $item->display(['id' => $_GET['id'] ?? -1]);
    Html::footer();
}
```

- [ ] **Step 3: Criar o template do formulário**

Create `templates/ltbp/reason.form.html.twig`:

```twig
{% extends 'generic_show_form.html.twig' %}
{% import 'components/form/fields_macros.html.twig' as fields %}

{% block form_fields %}
    {{ fields.textField('code', item.fields['code'], __('Código', 'gac'), {
        required: true,
        helper: __('Curto e único, por exemplo M1. É o que aparece na tabela do PDF.', 'gac'),
    }) }}

    {{ fields.textField('name', item.fields['name'], __('Título', 'gac'), {required: true}) }}

    {{ fields.textareaField('comment', item.fields['comment'], __('Descrição', 'gac'), {
        helper: __('Aparece na legenda dos motivos do PDF.', 'gac'),
    }) }}

    {{ fields.dropdownYesNo('is_active', item.fields['is_active'], __('Ativo', 'gac')) }}
{% endblock %}
```

- [ ] **Step 4: Acrescentar `showForm` e a busca em `LtbpReason`**

Modify `src/Ltbp/LtbpReason.php`. Acrescente aos `use`: `use Glpi\Application\View\TemplateRenderer;`. Acrescente estes métodos à classe (antes de `row()`):

```php
    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);

        TemplateRenderer::getInstance()->display('@gac/ltbp/reason.form.html.twig', [
            'item'   => $this,
            'params' => $options,
        ]);

        return true;
    }

    public function rawSearchOptions()
    {
        $t = self::getTable();
        $options = [
            ['id' => 'common', 'name' => self::getTypeName(2)],
            [
                'id' => 1, 'table' => $t, 'field' => 'code', 'name' => __('Código', 'gac'),
                // Without 'itemtype' GLPI maps the table back to a class, which fails for a class
                // in a sub-namespace (same reason getTable() is overridden).
                'datatype' => 'itemlink', 'itemtype' => self::class, 'massiveaction' => false, 'autocomplete' => true,
            ],
            ['id' => 2, 'table' => $t, 'field' => 'id', 'name' => __('ID'), 'datatype' => 'number', 'massiveaction' => false],
            ['id' => 3, 'table' => $t, 'field' => 'name', 'name' => __('Título', 'gac'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 4, 'table' => $t, 'field' => 'comment', 'name' => __('Descrição', 'gac'), 'datatype' => 'text', 'massiveaction' => false],
            ['id' => 5, 'table' => $t, 'field' => 'is_active', 'name' => __('Ativo', 'gac'), 'datatype' => 'bool', 'massiveaction' => false],
        ];

        // Declare the itemtype on every column of this class's table, not only on the link.
        foreach ($options as &$option) {
            if (($option['table'] ?? null) === $t && !isset($option['itemtype'])) {
                $option['itemtype'] = self::class;
            }
        }
        unset($option);

        return $options;
    }
```

- [ ] **Step 5: Criar o armazenamento da configuração**

Create `src/Ltbp/LtbpConfig.php`:

```php
<?php

namespace GlpiPlugin\Gac\Ltbp;

/**
 * Storage of the LTBP settings: glpi_configs, context plugin:gac, keys prefixed ltbp_
 * (the same context as the PRE; the prefix keeps the modules from colliding, spec D16).
 */
final class LtbpConfig
{
    public const CONTEXT = 'plugin:gac';

    /** @return array<string, string> normalized settings (see LtbpSettings) */
    public static function load(): array
    {
        return LtbpSettings::normalize(\Config::getConfigurationValues(self::CONTEXT));
    }

    /** @param array<string, mixed> $raw */
    public static function save(array $raw): void
    {
        \Config::setConfigurationValues(self::CONTEXT, LtbpSettings::normalize($raw));
    }
}
```

- [ ] **Step 6: Criar a seção da tela de configuração**

Create `src/Ltbp/LtbpConfigSection.php`:

```php
<?php

namespace GlpiPlugin\Gac\Ltbp;

use Dropdown;
use DocumentCategory;
use GlpiPlugin\Gac\ConfigSection;
use GlpiPlugin\Gac\Features;
use Session;
use State;

final class LtbpConfigSection implements ConfigSection
{
    public function key(): string
    {
        return 'ltbp';
    }

    public function canConfigure(): bool
    {
        return Features::canConfigure(Ltbp::$rightname);
    }

    public function title(): string
    {
        return __('Laudo Técnico de Baixa Patrimonial', 'gac');
    }

    /** @return array<string, string> */
    private static function stateRoleLabels(): array
    {
        return [
            'awaiting_writeoff' => __('Ativo aguardando baixa', 'gac'),
            'in_process'        => __('Ativo em processo de baixa', 'gac'),
            'written_off'       => __('Ativo baixado', 'gac'),
        ];
    }

    public function render(): string
    {
        $s   = LtbpConfig::load();
        $out = '';

        $missing = LtbpSettings::missingStateRoles($s);
        if ($missing !== []) {
            $labels = self::stateRoleLabels();
            $names  = array_map(static fn(string $role): string => $labels[$role], $missing);
            $out .= "<div class='alert alert-warning'>"
                . htmlescape(__('Mapeamentos obrigatórios ainda não configurados. A emissão do laudo fica bloqueada até preenchê-los:', 'gac'))
                . ' <strong>' . htmlescape(implode(', ', $names)) . '</strong></div>';
        }
        if (LtbpSettings::directorsMissing($s)) {
            $out .= "<div class='alert alert-warning'>"
                . htmlescape(__('Preencha o nome e o cargo dos dois diretores. A emissão do laudo fica bloqueada até lá.', 'gac'))
                . '</div>';
        }

        // 1. State roles
        $body = '';
        foreach (self::stateRoleLabels() as $role => $label) {
            $body .= $this->row($label, Dropdown::show(State::class, [
                'name'    => 'ltbp_state_' . $role,
                'value'   => LtbpSettings::stateId($s, $role),
                'display' => false,
            ]));
        }
        $out .= $this->block(
            'ti-device-laptop',
            __('Status do ativo', 'gac'),
            __('Status aplicados ao ativo em cada etapa do laudo. "Aguardando baixa" costuma ser o mesmo status usado pelo PRE. Escolha um status existente ou crie um novo com o botão + do campo; nenhum é criado sem a sua ação. Crie-os na entidade raiz, com recursividade ligada.', 'gac'),
            $body
        );

        // 2. Directors
        $d    = LtbpSettings::directors($s);
        $body = $this->row(__('Diretor de TI: nome', 'gac'), $this->text('ltbp_director_ti_name', $d['ti']['name']))
            . $this->row(__('Diretor de TI: cargo', 'gac'), $this->text('ltbp_director_ti_role', $d['ti']['role']))
            . $this->row(__('Diretor Administrativo: nome', 'gac'), $this->text('ltbp_director_adm_name', $d['adm']['name']))
            . $this->row(__('Diretor Administrativo: cargo', 'gac'), $this->text('ltbp_director_adm_role', $d['adm']['role']));
        $out .= $this->block(
            'ti-signature',
            __('Assinaturas do laudo', 'gac'),
            __('Nome e cargo impressos nos blocos de assinatura. São copiados para o laudo na emissão: trocar de diretor não altera laudos já emitidos.', 'gac'),
            $body
        );

        // 3. Completion and ticket behaviour
        $reasons = ['' => Dropdown::EMPTY_VALUE] + LtbpReason::choices();
        $body  = $this->row(__('Exigir comprovante para concluir', 'gac'), Dropdown::showYesNo(
            'ltbp_completion_require_document',
            LtbpSettings::requireCompletionDocument($s) ? 1 : 0,
            -1,
            ['display' => false]
        ));
        $body .= $this->row(__('Solucionar o ticket de origem ao concluir', 'gac'), Dropdown::showYesNo(
            'ltbp_solve_ticket_on_completion',
            LtbpSettings::solveTicketOnCompletion($s) ? 1 : 0,
            -1,
            ['display' => false]
        ));
        $body .= $this->row(__('Motivo padrão para "Sem conserto"', 'gac'), Dropdown::showFromArray('ltbp_default_reason_unrepairable', $reasons, [
            'value'   => LtbpSettings::defaultReasonId($s, 'unrepairable') ?: '',
            'display' => false,
        ]));
        $body .= $this->row(__('Motivo padrão para "Orçamento não aprovado"', 'gac'), Dropdown::showFromArray('ltbp_default_reason_quote_rejected', $reasons, [
            'value'   => LtbpSettings::defaultReasonId($s, 'quote_rejected') ?: '',
            'display' => false,
        ]));
        $out .= $this->block(
            'ti-adjustments',
            __('Andamento', 'gac'),
            __('Com o padrão desligado, o ticket de origem só recebe acompanhamentos; solucioná-lo continua sendo uma ação do técnico. Os motivos padrão pré-selecionam o motivo de uma linha que veio do PRE com aquele resultado.', 'gac'),
            $body
        );

        // 4. Report logo
        $out .= $this->block(
            'ti-file-type-pdf',
            __('Relatório', 'gac'),
            __('Os dados da empresa no cabeçalho do PDF (nome, CNPJ, endereço, cidade/UF e telefone) vêm do cadastro da entidade do laudo. A cidade e a UF também formam o local impresso acima das assinaturas.', 'gac'),
            $this->row(__('Categoria de documento da logomarca', 'gac'), DocumentCategory::dropdown([
                'name'    => 'ltbp_logo_documentcategories_id',
                'value'   => LtbpSettings::logoCategoryId($s),
                'display' => false,
            ]))
            . "<div class='text-muted small'>" . htmlescape(__('Costuma ser a mesma categoria configurada no PRE: a logomarca é um documento anexado à entidade.', 'gac')) . '</div>'
        );

        // 5. Reasons catalog
        $out .= $this->block(
            'ti-list-check',
            __('Motivos de baixa', 'gac'),
            __('Cadastro dos motivos que o técnico escolhe em cada ativo do laudo. Um motivo já usado só pode ser inativado.', 'gac'),
            "<a class='btn btn-outline-primary' href='" . htmlescape(LtbpReason::getSearchURL()) . "'>"
            . "<i class='ti ti-list-check me-1'></i>" . htmlescape(__('Gerenciar motivos de baixa', 'gac')) . '</a>'
        );

        return $out;
    }

    /** A titled, bordered block with a short description right below the title. */
    private function block(string $icon, string $title, string $description, string $content): string
    {
        return "<div class='card border mb-4'><div class='card-header bg-body-tertiary'><div>"
            . "<h4 class='card-title mb-1'><i class='ti " . htmlescape($icon) . " me-2'></i>" . htmlescape($title) . '</h4>'
            . "<div class='text-muted small'>" . htmlescape($description) . '</div>'
            . "</div></div><div class='card-body'>" . $content . '</div></div>';
    }

    private function row(string $label, string $control): string
    {
        return "<div class='row mb-3'><label class='col-sm-4 col-form-label'>" . htmlescape($label)
            . "</label><div class='col-sm-8'>" . $control . '</div></div>';
    }

    private function text(string $name, string $value): string
    {
        return "<input type='text' class='form-control' maxlength='255' name='" . htmlescape($name)
            . "' value='" . htmlescape($value) . "'>";
    }

    public function handlePost(array $post): void
    {
        if (!$this->canConfigure()) {
            return;
        }

        $raw = LtbpConfig::load();

        foreach (LtbpSettings::STATE_ROLES as $role) {
            $raw['ltbp_state_' . $role] = (string) (int) ($post['ltbp_state_' . $role] ?? 0);
        }
        foreach (['ltbp_director_ti_name', 'ltbp_director_ti_role', 'ltbp_director_adm_name', 'ltbp_director_adm_role'] as $key) {
            $raw[$key] = (string) ($post[$key] ?? '');
        }
        $raw['ltbp_completion_require_document'] = (string) (int) ($post['ltbp_completion_require_document'] ?? 1);
        $raw['ltbp_solve_ticket_on_completion']  = (string) (int) ($post['ltbp_solve_ticket_on_completion'] ?? 0);
        $raw['ltbp_default_reason_unrepairable']   = (string) (int) ($post['ltbp_default_reason_unrepairable'] ?? 0);
        $raw['ltbp_default_reason_quote_rejected'] = (string) (int) ($post['ltbp_default_reason_quote_rejected'] ?? 0);
        $raw['ltbp_logo_documentcategories_id']  = (string) (int) ($post['ltbp_logo_documentcategories_id'] ?? 0);

        LtbpConfig::save($raw);
        Session::addMessageAfterRedirect(__('Configuração do LTBP salva.', 'gac'));
    }
}
```

- [ ] **Step 7: Registrar a seção**

Modify `src/Config.php`: acrescente `use GlpiPlugin\Gac\Ltbp\LtbpConfigSection;` ao lado do `use ...PreConfigSection;` e troque `sections()` por:

```php
    /** @return list<ConfigSection> */
    public static function sections(): array
    {
        return [
            new PreConfigSection(),
            new LtbpConfigSection(),
        ];
    }
```

- [ ] **Step 8: Lint, limpar o cache e verificar**

```bash
for f in src/Ltbp/LtbpConfig.php src/Ltbp/LtbpConfigSection.php src/Ltbp/LtbpReason.php src/Config.php front/ltbp/ltbpreason.php front/ltbp/ltbpreason.form.php; do /c/xampp/php/php.exe -l "$f"; done
cd /c/Users/juliano/VSCode/glpi-xampp-dev-plugin && /c/xampp/php/php.exe bin/console cache:clear && cd -
```
No navegador (já com o perfil que recebeu o direito): abra **Configurar > Plugin - DTI GAC** (`/plugins/gac/front/config.php`).
Expected: a seção "Laudo Técnico de Baixa Patrimonial" com os cinco blocos e os dois avisos amarelos (status e diretores). Preencha os três status (crie "Aguardando baixa", "Em processo de baixa" e "Baixado" com o **+**, na entidade raiz, recursivos; "Aguardando baixa" pode ser o mesmo do PRE), os nomes e cargos dos diretores, a categoria da logomarca, e salve: os avisos somem e os valores permanecem. Clique em **Gerenciar motivos de baixa**: crie os motivos `m1` (deve virar `M1`), `M2`; tente criar `M1` de novo (deve recusar com "Já existe um motivo com este código"); edite um título; abra a lista e confirme as colunas Título e Ativo. Volte à configuração e escolha um motivo padrão para "Sem conserto".

- [ ] **Step 9: Commit**

Invoque o skill `/commit` (tipo `feat`, por exemplo "add LTBP reasons catalog and configuration section").

---

### Task 7: Lista, formulário e menu do laudo

**Files:**
- Create: `src/Ltbp/LtbpMenu.php`, `templates/ltbp/ltbp.form.html.twig`, `front/ltbp/ltbp.php`, `front/ltbp/ltbp.form.php`
- Modify: `src/Ltbp/Ltbp.php` (abas, formulário e busca), `src/GacMenu.php`

**Interfaces:**
- Consumes: `Ltbp` (Tarefa 5), `Labels`, `Status`, `Destination`, `StateMachine`.
- Produces: `LtbpMenu::getMenuContent(): array`; `GacMenu::ITEM_LTBP = 'ltbp'`; opções de busca do laudo `1` número, `2` id, `3` status, `4` destinação, `5` técnico, `6` data de emissão, `7` data da baixa, `8` data da conclusão, `80` entidade, `90` evento (a `90` é a que rotula o histórico nativo); `Ltbp::defineTabs()` com as abas Formulário, Documentos e Histórico (as abas Itens e Andamento entram nas Tarefas 8 e 10).

- [ ] **Step 1: Criar `LtbpMenu` e ligar ao `GacMenu`**

Create `src/Ltbp/LtbpMenu.php`:

```php
<?php

namespace GlpiPlugin\Gac\Ltbp;

class LtbpMenu
{
    public static function getMenuName($nb = 0): string
    {
        return Ltbp::getTypeName(2);
    }

    public static function getMenuContent(): array
    {
        if (!Ltbp::canView()) {
            return [];
        }

        // GLPI's context_links template renders the "add" button whenever the key exists,
        // without checking rights, so it must only be present for users who can create.
        $links = ['search' => Ltbp::getSearchURL(false)];
        if (Ltbp::canCreate()) {
            $links['add'] = Ltbp::getFormURL(false);
        }

        return [
            'title' => Ltbp::getTypeName(2),
            'page'  => Ltbp::getSearchURL(false),
            'icon'  => Ltbp::getIcon(),
            'links' => $links,
        ];
    }
}
```

Modify `src/GacMenu.php`: acrescente `use GlpiPlugin\Gac\Ltbp\LtbpMenu;` ao lado dos outros `use`, a constante `public const ITEM_LTBP = 'ltbp';` depois de `ITEM_PRE`, e, em `getMenuContent()`, o bloco do LTBP entre o do PRE e o da configuração:

```php
        $ltbp = LtbpMenu::getMenuContent();
        if ($ltbp !== []) {
            $entries[self::ITEM_LTBP] = $ltbp;
        }
```

- [ ] **Step 2: Acrescentar abas, formulário e busca a `Ltbp`**

Modify `src/Ltbp/Ltbp.php`. Acrescente aos `use`: `use Entity;`, `use Glpi\Application\View\TemplateRenderer;`, `use GlpiPlugin\Gac\Shared\ReportFormatter;`. Acrescente à classe (depois de `cleanDBonPurge()`):

```php
    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs);
        $this->addStandardTab(\Document_Item::class, $tabs, $options);
        $this->addStandardTab('Log', $tabs, $options);
        return $tabs;
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);

        if ($this->isNewItem() && empty($this->fields['users_id_tech'])) {
            $this->fields['users_id_tech'] = (int) Session::getLoginUserID();
        }

        $destinationChoices = [];
        foreach (Destination::cases() as $d) {
            $destinationChoices[$d->value] = Labels::destination($d);
        }

        TemplateRenderer::getInstance()->display('@gac/ltbp/ltbp.form.html.twig', [
            'item'                => $this,
            'params'              => $options,
            'status_label'        => Labels::status($this->isNewItem() ? Status::Draft : $this->getStatus()),
            'destination_choices' => $destinationChoices,
            'date_issued_display' => ReportFormatter::date((string) ($this->fields['date_issued'] ?? '')),
            'header_editable'     => $this->canUpdateItem() || $this->isNewItem(),
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
                // Without 'itemtype' GLPI maps the table back to a class, which fails for a class
                // in a sub-namespace (same reason getTable() is overridden).
                'datatype' => 'itemlink', 'itemtype' => self::class, 'massiveaction' => false, 'autocomplete' => true,
            ],
            ['id' => 2, 'table' => $t, 'field' => 'id', 'name' => __('ID'), 'datatype' => 'number', 'massiveaction' => false],
            [
                'id' => 3, 'table' => $t, 'field' => 'status', 'name' => __('Status'),
                'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false,
            ],
            [
                'id' => 4, 'table' => $t, 'field' => 'destination', 'name' => __('Destinação', 'gac'),
                'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false,
            ],
            [
                'id' => 5, 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id_tech',
                'name' => __('Técnico responsável', 'gac'), 'datatype' => 'dropdown', 'massiveaction' => false,
            ],
            ['id' => 6, 'table' => $t, 'field' => 'date_issued', 'name' => __('Data de emissão', 'gac'), 'datatype' => 'date', 'massiveaction' => false],
            ['id' => 7, 'table' => $t, 'field' => 'date_written_off', 'name' => __('Data da baixa', 'gac'), 'datatype' => 'date', 'massiveaction' => false],
            ['id' => 8, 'table' => $t, 'field' => 'date_completed', 'name' => __('Data da conclusão', 'gac'), 'datatype' => 'date', 'massiveaction' => false],
            // Not a real column of the list: it only names the "Campo" of the events written to the
            // native history (see LtbpEvent::log()).
            [
                'id' => self::HISTORY_OPTION_EVENT, 'table' => $t, 'field' => 'comment', 'name' => __('Evento', 'gac'),
                'datatype' => 'string', 'nosearch' => true, 'nodisplay' => true, 'massiveaction' => false,
            ],
            [
                'id' => 80, 'table' => 'glpi_entities', 'field' => 'completename',
                'name' => Entity::getTypeName(1), 'datatype' => 'dropdown', 'massiveaction' => false,
            ],
        ];

        // Declare the itemtype on every column that belongs to this class's table, not only on the number.
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
            $status = Status::tryFrom((string) $values[$field]);
            return $status === null ? '' : Labels::status($status);
        }
        if ($field === 'destination') {
            $destination = Destination::tryFrom((string) $values[$field]);
            return $destination === null ? '' : Labels::destination($destination);
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        if ($field === 'status' || $field === 'destination') {
            $options['display'] = false;
            $options['value']   = $values[$field];
            $choices = [];
            if ($field === 'status') {
                foreach (Status::cases() as $status) {
                    $choices[$status->value] = Labels::status($status);
                }
            } else {
                foreach (Destination::cases() as $destination) {
                    $choices[$destination->value] = Labels::destination($destination);
                }
            }
            return \Dropdown::showFromArray($name, $choices, $options);
        }
        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }
```

- [ ] **Step 3: Criar o template do formulário**

Create `templates/ltbp/ltbp.form.html.twig`:

```twig
{% extends 'generic_show_form.html.twig' %}
{% import 'components/form/fields_macros.html.twig' as fields %}

{% block form_fields %}
    {{ fields.readOnlyField('number_display', item.fields['number']|default(__('(gerado ao salvar)', 'gac')), __('Número', 'gac')) }}
    {{ fields.readOnlyField('status_display', status_label, __('Status')) }}

    {{ fields.dropdownArrayField('destination', item.fields['destination'], destination_choices, __('Destinação', 'gac'), {
        required: true,
        readonly: not header_editable,
        display_emptychoice: item.isNewItem(),
    }) }}

    {{ fields.dropdownField('User', 'users_id_tech', item.fields['users_id_tech'], __('Técnico responsável', 'gac'), {
        entity: item.fields['entities_id'],
        right: 'all',
        readonly: not header_editable,
    }) }}

    {% if item.fields['date_issued'] %}
        {{ fields.readOnlyField('date_issued_display', date_issued_display, __('Data de emissão', 'gac')) }}
    {% endif %}

    {{ fields.textareaField('comment', item.fields['comment'], __('Comentários')) }}
{% endblock %}
```

- [ ] **Step 4: Criar as páginas**

Create `front/ltbp/ltbp.php`:

```php
<?php

use GlpiPlugin\Gac\GacMenu;
use GlpiPlugin\Gac\Ltbp\Ltbp;

if (!Ltbp::canView()) {
    Html::displayRightError();
}

Html::header(
    Ltbp::getTypeName(2),
    $_SERVER['PHP_SELF'],
    GacMenu::SECTOR,
    GacMenu::ITEM_LTBP
);

Search::show(Ltbp::class);

Html::footer();
```

Create `front/ltbp/ltbp.form.php`:

```php
<?php

use GlpiPlugin\Gac\GacMenu;
use GlpiPlugin\Gac\Ltbp\Ltbp;

$item = new Ltbp();

if (isset($_POST['add'])) {
    $item->check(-1, CREATE, $_POST);
    $newid = $item->add($_POST);
    if ($newid) {
        Html::redirect(Ltbp::getFormURLWithID($newid));
    }
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
        Ltbp::getTypeName(1),
        $_SERVER['PHP_SELF'],
        GacMenu::SECTOR,
        GacMenu::ITEM_LTBP
    );
    $item->display(['id' => $_GET['id'] ?? -1]);
    Html::footer();
}
```

- [ ] **Step 5: Lint, limpar o cache e verificar**

```bash
for f in src/Ltbp/LtbpMenu.php src/Ltbp/Ltbp.php src/GacMenu.php front/ltbp/ltbp.php front/ltbp/ltbp.form.php; do /c/xampp/php/php.exe -l "$f"; done
cd /c/Users/juliano/VSCode/glpi-xampp-dev-plugin && /c/xampp/php/php.exe bin/console cache:clear && cd -
```
Expected: `No syntax errors` ×5. No navegador (recarregue a página inteira para o menu lateral atualizar): no menu **Plugin - DTI GAC** aparece "Laudos Técnicos de Baixa Patrimonial". Abra a lista (vazia, sem erro), clique em **Adicionar**, escolha a destinação **Descarte ecológico** e salve.
Expected: o laudo abre em `LTBP-AAAA-001`, status **Rascunho**, destinação e técnico preenchidos, abas Formulário, Documentos e Histórico (o Histórico mostra "criado" nativo). Volte à lista: a linha mostra número, status e destinação (colunas padrão). Tente salvar sem destinação: recusa com "Informe a destinação." Crie um segundo laudo (número `-002`), e exclua-o com **Excluir permanentemente**.

- [ ] **Step 6: Commit**

Invoque o skill `/commit` (tipo `feat`, por exemplo "add LTBP list, form and sidebar entry").

---

### Task 8: Ativos do laudo: candidatos, busca livre, motivos e a aba Itens

**Files:**
- Create: `src/Ltbp/AssetTypes.php`, `src/Ltbp/AssetClaim.php`, `src/Ltbp/AssetSnapshot.php`, `src/Ltbp/CandidateFinder.php`, `src/Ltbp/LineService.php`, `templates/ltbp/items_tab.html.twig`, `front/ltbp/ltbpitem.form.php`
- Modify: `src/Ltbp/LtbpItem.php` (aba), `src/Ltbp/Ltbp.php` (registrar a aba)

**Interfaces:**
- Consumes: `Ltbp`, `LtbpItem`, `LtbpEvent::log()`, `LtbpReason::choices()/row()`, `LtbpConfig::load()`, `LtbpSettings`, `StateMachine`, `Status`, `Shared\ServiceResult`; do PRE, só leitura: `GlpiPlugin\Gac\Pre\RepairProtocolItem::getTable()`, `Pre\RepairProtocol::getTable()`, `Pre\ItemStatus`, `Pre\Destination`, `Pre\Outcome`, `Pre\Labels::outcome()`.
- Produces:
  - `AssetTypes::all(): list<string>`; `AssetTypes::isAsset(string $itemtype): bool`
  - `AssetClaim::run(string $itemtype, int $itemsId, callable $fn): mixed`
  - `AssetSnapshot::take(CommonDBTM $asset): array{item_entities_id: int, item_name: string, item_type_label: string, brand: string, model: string, serial: string, otherserial: string}`
  - `CandidateFinder::find(Ltbp $laudo): list<array{key: string, itemtype: string, items_id: int, name: string, type_label: string, serial: string, otherserial: string, pre_items_id: int, pre_number: string, tickets_id: int, outcome: string, outcome_label: string, service_description: string}>`
  - `LineService::addAssets(Ltbp $laudo, array $assets, array $origins = []): ServiceResult` (`$assets` = `list<array{itemtype: string, items_id: int}>`; `$origins` indexado por `"itemtype|items_id"`, cada um com `pre_items_id`, `pre_number`, `tickets_id`, `outcome`)
  - `LineService::hasActivePreLine(string $itemtype, int $itemsId): bool` (o asset está em linha ativa de PRE; a emissão confere de novo)
  - `LineService::removeLine(Ltbp $laudo, int $lineId): ServiceResult`
  - `LineService::saveReasons(Ltbp $laudo, array $reasons): ServiceResult` (`$reasons` = `array<int, int|string>`, id da linha → id do motivo, `0` limpa)
  - `LtbpItem::showForLaudo(Ltbp $laudo): void` (a aba Itens)

Sem teste automatizado (GLPI): verificação manual no fim.

- [ ] **Step 1: Criar `AssetTypes` e `AssetClaim`**

Create `src/Ltbp/AssetTypes.php`:

```php
<?php

namespace GlpiPlugin\Gac\Ltbp;

/**
 * GLPI's own list of asset classes: the native ones plus every custom asset definition
 * (same source the PRE uses to import tickets, PRE spec D29).
 */
final class AssetTypes
{
    /** @return list<string> */
    public static function all(): array
    {
        global $CFG_GLPI;

        return array_values(array_unique(array_map('strval', (array) ($CFG_GLPI['asset_types'] ?? []))));
    }

    public static function isAsset(string $itemtype): bool
    {
        return in_array($itemtype, self::all(), true);
    }
}
```

Create `src/Ltbp/AssetClaim.php`:

```php
<?php

namespace GlpiPlugin\Gac\Ltbp;

/**
 * Atomic claim of an asset (spec L8, plan decision 18): MySQL has no partial unique index, so
 * "the asset is in no other laudo" plus the insert of the line run under a named lock, and two
 * technicians never take the same asset at the same time.
 */
final class AssetClaim
{
    private const WAIT_SECONDS = 5;

    public static function run(string $itemtype, int $itemsId, callable $fn): mixed
    {
        global $DB;

        $name = 'gac_ltbp_' . md5($itemtype . '|' . $itemsId);
        $row  = $DB->doQuery(sprintf("SELECT GET_LOCK('%s', %d) AS got", $name, self::WAIT_SECONDS))->fetch_assoc();
        if ((int) ($row['got'] ?? 0) !== 1) {
            throw new \RuntimeException(__('Outro técnico está usando este ativo agora. Tente de novo.', 'gac'));
        }
        try {
            return $fn();
        } finally {
            $DB->doQuery(sprintf("SELECT RELEASE_LOCK('%s')", $name));
        }
    }
}
```

- [ ] **Step 2: Criar `AssetSnapshot`**

Create `src/Ltbp/AssetSnapshot.php`:

```php
<?php

namespace GlpiPlugin\Gac\Ltbp;

use CommonDBTM;

/** The asset data copied into a laudo line (spec 5.2), so the document does not change afterwards. */
final class AssetSnapshot
{
    /** @return array{item_entities_id: int, item_name: string, item_type_label: string, brand: string, model: string, serial: string, otherserial: string} */
    public static function take(CommonDBTM $asset): array
    {
        $f = $asset->fields;

        return [
            'item_entities_id' => (int) ($f['entities_id'] ?? 0),
            'item_name'        => (string) ($f['name'] ?? ''),
            'item_type_label'  => $asset::getTypeName(1),
            'brand'            => self::name('glpi_manufacturers', (int) ($f['manufacturers_id'] ?? 0)),
            'model'            => self::modelName($f),
            'serial'           => (string) ($f['serial'] ?? ''),
            'otherserial'      => (string) ($f['otherserial'] ?? ''),
        ];
    }

    /** The model column is "<type>models_id" and its name depends on the asset type. */
    private static function modelName(array $fields): string
    {
        foreach ($fields as $key => $value) {
            if (str_ends_with((string) $key, 'models_id') && (int) $value > 0) {
                $table = getTableNameForForeignKeyField((string) $key);
                return $table === '' ? '' : self::name($table, (int) $value);
            }
        }
        return '';
    }

    private static function name(string $table, int $id): string
    {
        global $DB;

        if ($id <= 0 || !$DB->tableExists($table)) {
            return '';
        }
        $row = $DB->request(['SELECT' => ['name'], 'FROM' => $table, 'WHERE' => ['id' => $id]])->current();
        return $row === null ? '' : (string) $row['name'];
    }
}
```

- [ ] **Step 3: Criar `CandidateFinder`**

Create `src/Ltbp/CandidateFinder.php`:

```php
<?php

namespace GlpiPlugin\Gac\Ltbp;

use GlpiPlugin\Gac\Pre\Destination as PreDestination;
use GlpiPlugin\Gac\Pre\ItemStatus as PreItemStatus;
use GlpiPlugin\Gac\Pre\Labels as PreLabels;
use GlpiPlugin\Gac\Pre\Outcome as PreOutcome;
use GlpiPlugin\Gac\Pre\RepairProtocol;
use GlpiPlugin\Gac\Pre\RepairProtocolItem;

/**
 * Assets that can enter a laudo (spec L7): the ones in the "Aguardando baixa" status, in the
 * laudo's entity or its sub-entities, that are in no laudo (not canceled) and in no active PRE
 * line. Each candidate carries where it came from in the PRE, when it did (spec L23).
 * This module only reads the PRE tables (spec L22).
 */
final class CandidateFinder
{
    /** @return list<array<string, mixed>> */
    public static function find(Ltbp $laudo): array
    {
        global $DB;

        $stateId = LtbpSettings::stateId(LtbpConfig::load(), 'awaiting_writeoff');
        if ($stateId <= 0) {
            return [];
        }

        $entityIds = array_values(getSonsOf('glpi_entities', (int) $laudo->fields['entities_id']));
        $held      = self::heldKeys();

        $rows = [];
        foreach (AssetTypes::all() as $itemtype) {
            $asset = getItemForItemtype($itemtype);
            if (!$asset) {
                continue;
            }
            $table = $asset::getTable();
            if (!$DB->tableExists($table) || !$DB->fieldExists($table, 'states_id')) {
                continue;
            }

            $where = ['states_id' => $stateId, 'entities_id' => $entityIds];
            if ($DB->fieldExists($table, 'is_deleted')) {
                $where['is_deleted'] = 0;
            }
            if ($DB->fieldExists($table, 'is_template')) {
                $where['is_template'] = 0;
            }

            foreach ($DB->request(['FROM' => $table, 'WHERE' => $where, 'ORDER' => ['name ASC']]) as $r) {
                $key = $itemtype . '|' . $r['id'];
                if (isset($held[$key])) {
                    continue;
                }
                $rows[$key] = [
                    'key'                 => $key,
                    'itemtype'            => $itemtype,
                    'items_id'            => (int) $r['id'],
                    'name'                => (string) ($r['name'] ?? ''),
                    'type_label'          => $asset::getTypeName(1),
                    'serial'              => (string) ($r['serial'] ?? ''),
                    'otherserial'         => (string) ($r['otherserial'] ?? ''),
                    'pre_items_id'        => 0,
                    'pre_number'          => '',
                    'tickets_id'          => 0,
                    'outcome'             => '',
                    'outcome_label'       => '',
                    'service_description' => '',
                ];
            }
        }

        self::addOrigin($rows);

        return array_values($rows);
    }

    /** @return array<string, true> keys "<itemtype>|<items_id>" of assets in a laudo (not canceled) or in an active PRE line */
    private static function heldKeys(): array
    {
        global $DB;

        $items  = LtbpItem::getTable();
        $laudos = Ltbp::getTable();
        $held   = [];

        foreach ($DB->request([
            'SELECT'     => ["$items.itemtype", "$items.items_id"],
            'FROM'       => $items,
            'INNER JOIN' => [
                $laudos => ['ON' => [$items => 'plugin_gac_ltbps_id', $laudos => 'id']],
            ],
            'WHERE' => ["$laudos.status" => Status::holdingValues()],
        ]) as $r) {
            $held[$r['itemtype'] . '|' . $r['items_id']] = true;
        }

        foreach ($DB->request([
            'SELECT' => ['itemtype', 'items_id'],
            'FROM'   => RepairProtocolItem::getTable(),
            'WHERE'  => ['status' => [
                PreItemStatus::PendingSend->value,
                PreItemStatus::Sending->value,
                PreItemStatus::AtSupplier->value,
            ]],
        ]) as $r) {
            $held[$r['itemtype'] . '|' . $r['items_id']] = true;
        }

        return $held;
    }

    /**
     * Fills the PRE origin of each candidate: the latest returned line with destination Baixa.
     *
     * @param array<string, array<string, mixed>> $rows
     */
    private static function addOrigin(array &$rows): void
    {
        global $DB;

        if ($rows === []) {
            return;
        }

        $byType = [];
        foreach ($rows as $row) {
            $byType[$row['itemtype']][] = $row['items_id'];
        }

        $preItems     = RepairProtocolItem::getTable();
        $preProtocols = RepairProtocol::getTable();

        foreach ($byType as $itemtype => $ids) {
            foreach ($DB->request([
                'SELECT'     => [
                    "$preItems.id AS pre_item_id",
                    "$preItems.items_id AS asset_id",
                    "$preItems.tickets_id AS ticket_id",
                    "$preItems.outcome AS outcome",
                    "$preItems.service_description AS service_description",
                    "$preProtocols.number AS pre_number",
                ],
                'FROM'       => $preItems,
                'INNER JOIN' => [
                    $preProtocols => ['ON' => [$preItems => 'plugin_gac_repairprotocols_id', $preProtocols => 'id']],
                ],
                'WHERE' => [
                    "$preItems.itemtype"    => $itemtype,
                    "$preItems.items_id"    => $ids,
                    "$preItems.status"      => PreItemStatus::Returned->value,
                    "$preItems.destination" => PreDestination::Writeoff->value,
                ],
                // Ascending: a later line for the same asset overwrites the earlier one.
                'ORDER' => ["$preItems.id ASC"],
            ]) as $r) {
                $key = $itemtype . '|' . $r['asset_id'];
                if (!isset($rows[$key])) {
                    continue;
                }
                $outcome = PreOutcome::tryFrom((string) $r['outcome']);
                $rows[$key]['pre_items_id']        = (int) $r['pre_item_id'];
                $rows[$key]['pre_number']          = (string) $r['pre_number'];
                $rows[$key]['tickets_id']          = (int) $r['ticket_id'];
                $rows[$key]['outcome']             = (string) $r['outcome'];
                $rows[$key]['outcome_label']       = $outcome === null ? '' : PreLabels::outcome($outcome);
                $rows[$key]['service_description'] = (string) ($r['service_description'] ?? '');
            }
        }
    }
}
```

- [ ] **Step 4: Criar `LineService`**

Create `src/Ltbp/LineService.php`:

```php
<?php

namespace GlpiPlugin\Gac\Ltbp;

use GlpiPlugin\Gac\Pre\ItemStatus as PreItemStatus;
use GlpiPlugin\Gac\Pre\RepairProtocolItem;
use GlpiPlugin\Gac\Shared\ServiceResult;

/** Draft-time line operations: add assets, remove, choose the reason of each line. */
final class LineService
{
    /**
     * @param list<array{itemtype: string, items_id: int}> $assets
     * @param array<string, array<string, mixed>> $origins keyed "itemtype|items_id": pre_items_id, pre_number, tickets_id, outcome
     */
    public static function addAssets(Ltbp $laudo, array $assets, array $origins = []): ServiceResult
    {
        if (!StateMachine::canEditDraft($laudo->getStatus())) {
            return ServiceResult::fail(__('Só é possível adicionar ativos a um laudo em rascunho.', 'gac'));
        }

        $settings = LtbpConfig::load();
        $added    = 0;
        $problems = [];
        foreach ($assets as $a) {
            $key = $a['itemtype'] . '|' . $a['items_id'];
            try {
                self::addOne($laudo, $a['itemtype'], (int) $a['items_id'], $origins[$key] ?? [], $settings);
                $added++;
            } catch (\RuntimeException $e) {
                $problems[] = $e->getMessage();
            }
        }

        if ($added === 0) {
            return ServiceResult::fail(implode(' ', $problems) ?: __('Nenhum ativo válido foi selecionado.', 'gac'));
        }

        $message = sprintf(_n('%d ativo adicionado.', '%d ativos adicionados.', $added, 'gac'), $added);
        if ($problems !== []) {
            $message .= ' ' . __('Não adicionados:', 'gac') . ' ' . implode(' ', $problems);
        }
        return ServiceResult::ok($message);
    }

    /**
     * @param array<string, mixed> $origin
     * @param array<string, string> $settings
     * @throws \RuntimeException with a translated message when the asset cannot enter the laudo
     */
    private static function addOne(Ltbp $laudo, string $itemtype, int $itemsId, array $origin, array $settings): void
    {
        if (!AssetTypes::isAsset($itemtype)) {
            throw new \RuntimeException(sprintf(__('#%d: tipo de item inválido.', 'gac'), $itemsId));
        }
        $asset = getItemForItemtype($itemtype);
        if (!$asset || !$asset->getFromDB($itemsId) || !$asset->canViewItem()) {
            throw new \RuntimeException(sprintf(__('#%d: ativo não encontrado.', 'gac'), $itemsId));
        }

        $label    = (string) ($asset->fields['name'] ?? '') ?: '#' . $itemsId;
        $entities = array_map('intval', getSonsOf('glpi_entities', (int) $laudo->fields['entities_id']));
        if (!in_array((int) ($asset->fields['entities_id'] ?? 0), $entities, true)) {
            throw new \RuntimeException(sprintf(__('%s: está fora da entidade do laudo.', 'gac'), $label));
        }
        if (!empty($asset->fields['is_deleted']) || !empty($asset->fields['is_template'])) {
            throw new \RuntimeException(sprintf(__('%s: está na lixeira ou é um modelo.', 'gac'), $label));
        }
        $writtenOff = LtbpSettings::stateId($settings, 'written_off');
        if ($writtenOff > 0 && (int) ($asset->fields['states_id'] ?? 0) === $writtenOff) {
            throw new \RuntimeException(sprintf(__('%s: já está baixado.', 'gac'), $label));
        }

        AssetClaim::run($itemtype, $itemsId, static function () use ($laudo, $itemtype, $itemsId, $asset, $origin, $settings, $label): void {
            $other = Ltbp::activeLaudoFor($itemtype, $itemsId);
            if ($other !== null) {
                throw new \RuntimeException(sprintf(__('%1$s: já está no laudo %2$s.', 'gac'), $label, $other['number']));
            }
            if (self::hasActivePreLine($itemtype, $itemsId)) {
                throw new \RuntimeException(sprintf(__('%s: está em um PRE ativo (aguardando envio, em envio ou na assistência).', 'gac'), $label));
            }

            $id = (new LtbpItem())->add([
                'plugin_gac_ltbps_id'       => (int) $laudo->getID(),
                'itemtype'                  => $itemtype,
                'items_id'                  => $itemsId,
                'plugin_gac_ltbpreasons_id' => self::defaultReasonFor($origin, $settings),
                'pre_items_id'              => (int) ($origin['pre_items_id'] ?? 0),
                'pre_number'                => (string) ($origin['pre_number'] ?? ''),
                'tickets_id'                => (int) ($origin['tickets_id'] ?? 0),
            ] + AssetSnapshot::take($asset));
            if (!$id) {
                throw new \RuntimeException(sprintf(__('%s: não foi possível adicionar.', 'gac'), $label));
            }

            LtbpEvent::log((int) $laudo->getID(), 'line_added', '', [], (int) $id);
        });
    }

    /** True when the asset is in a PRE line that is still active (the emission checks this again). */
    public static function hasActivePreLine(string $itemtype, int $itemsId): bool
    {
        return countElementsInTable(RepairProtocolItem::getTable(), [
            'itemtype' => $itemtype,
            'items_id' => $itemsId,
            'status'   => [
                PreItemStatus::PendingSend->value,
                PreItemStatus::Sending->value,
                PreItemStatus::AtSupplier->value,
            ],
        ]) > 0;
    }

    /**
     * The default reason of a line that came from the PRE (spec L24): the one configured for the
     * PRE outcome, when it exists and is active. Zero means "choose one".
     *
     * @param array<string, mixed> $origin
     * @param array<string, string> $settings
     */
    private static function defaultReasonFor(array $origin, array $settings): int
    {
        $id = LtbpSettings::defaultReasonId($settings, (string) ($origin['outcome'] ?? ''));
        if ($id <= 0) {
            return 0;
        }
        $row = LtbpReason::row($id);
        return ($row !== null && (int) $row['is_active'] === 1) ? $id : 0;
    }

    public static function removeLine(Ltbp $laudo, int $lineId): ServiceResult
    {
        if (!StateMachine::canEditDraft($laudo->getStatus())) {
            return ServiceResult::fail(__('Só é possível remover ativos de um laudo em rascunho.', 'gac'));
        }
        $line = new LtbpItem();
        if (!$line->getFromDB($lineId) || (int) $line->fields['plugin_gac_ltbps_id'] !== (int) $laudo->getID()) {
            return ServiceResult::fail(__('Linha não encontrada.', 'gac'));
        }

        // Logged first: the event text is built from the line, which is gone after the delete.
        LtbpEvent::log((int) $laudo->getID(), 'line_removed', '', [], $lineId);
        $line->delete(['id' => $lineId], true);

        return ServiceResult::ok(__('Ativo removido do laudo.', 'gac'));
    }

    /** @param array<int|string, int|string> $reasons line id => reason id (0 clears it) */
    public static function saveReasons(Ltbp $laudo, array $reasons): ServiceResult
    {
        global $DB;

        if (!StateMachine::canEditDraft($laudo->getStatus())) {
            return ServiceResult::fail(__('Os motivos só podem ser alterados em rascunho.', 'gac'));
        }

        $active = LtbpReason::choices();
        foreach ($reasons as $lineId => $reasonId) {
            $reasonId = (int) $reasonId;
            if ($reasonId !== 0 && !isset($active[$reasonId])) {
                continue;
            }
            $DB->update(
                LtbpItem::getTable(),
                ['plugin_gac_ltbpreasons_id' => $reasonId, 'date_mod' => $_SESSION['glpi_currenttime']],
                ['id' => (int) $lineId, 'plugin_gac_ltbps_id' => (int) $laudo->getID()]
            );
        }
        return ServiceResult::ok(__('Motivos salvos.', 'gac'));
    }
}
```

- [ ] **Step 5: Criar a aba Itens (`LtbpItem`)**

Modify `src/Ltbp/LtbpItem.php`. Acrescente aos `use`: `use CommonGLPI;`, `use Dropdown;`, `use Glpi\Application\View\TemplateRenderer;`. Acrescente à classe:

```php
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Ltbp) {
            $count = countElementsInTable(self::getTable(), ['plugin_gac_ltbps_id' => $item->getID()]);
            return self::createTabEntry(__('Itens', 'gac'), $count, null, 'ti ti-list-details');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof Ltbp) {
            return false;
        }
        self::showForLaudo($item);
        return true;
    }

    public static function showForLaudo(Ltbp $laudo): void
    {
        $status  = $laudo->getStatus();
        $isDraft = StateMachine::canEditDraft($status);
        $canEdit = $laudo->canUpdateItem();
        $editing = $isDraft && $canEdit;

        $lines = [];
        foreach ($laudo->lines() as $row) {
            $lines[] = $row + [
                'ticket_url' => $row['tickets_id'] ? \Ticket::getFormURLWithID((int) $row['tickets_id']) : '',
            ];
        }
        // Draft: the live catalog (a select per line). After the emission: the snapshot stored in
        // the line, never the live catalog, so editing a reason does not change an issued laudo.
        $catalog = LtbpReason::choices(false);
        foreach ($lines as &$line) {
            $line['reason_text'] = $isDraft
                ? ($catalog[(int) $line['plugin_gac_ltbpreasons_id']] ?? '')
                : ($line['reason_code'] ? sprintf('%s: %s', $line['reason_code'], $line['reason_title']) : '');
        }
        unset($line);

        $settings     = LtbpConfig::load();
        $candidates   = $editing ? CandidateFinder::find($laudo) : [];
        $picker       = '';
        if ($editing) {
            $picker = Dropdown::showSelectItemFromItemtypes([
                'itemtypes'       => AssetTypes::all(),
                'itemtype_name'   => 'asset_itemtype',
                'items_id_name'   => 'asset_items_id',
                'entity_restrict' => -1,
                'checkright'      => true,
                'width'           => '100%',
                'display'         => false,
            ]);
        }

        TemplateRenderer::getInstance()->display('@gac/ltbp/items_tab.html.twig', [
            'laudo'            => $laudo,
            'lines'            => $lines,
            'is_draft'         => $isDraft,
            'editing'          => $editing,
            'candidates'       => $candidates,
            'candidates_hint'  => $editing && LtbpSettings::stateId($settings, 'awaiting_writeoff') <= 0,
            'reason_choices'   => [0 => Dropdown::EMPTY_VALUE] + LtbpReason::choices(),
            'asset_picker'     => $picker,
            'form_url'         => self::getFormURL(),
        ]);
    }
```

Modify `src/Ltbp/Ltbp.php`: em `defineTabs()`, acrescente, logo depois de `addDefaultFormTab`:

```php
        $this->addStandardTab(LtbpItem::class, $tabs, $options);
```

- [ ] **Step 6: Criar o template da aba Itens**

Create `templates/ltbp/items_tab.html.twig`:

```twig
{% import 'components/form/fields_macros.html.twig' as fields %}

<div class="p-3" data-gac-ltbp data-ltbp-id="{{ laudo.getID() }}">

    {% if editing %}
        <form method="post" action="{{ form_url }}">
            <input type="hidden" name="_glpi_csrf_token" value="{{ csrf_token() }}">
            <input type="hidden" name="ltbp_id" value="{{ laudo.getID() }}">
    {% endif %}

    <div class="card border mb-4">
        <div class="card-header bg-body-tertiary">
            <div class="flex-grow-1">
                <h4 class="card-title mb-1"><i class="ti ti-list-details me-2"></i>{{ __('Ativos do laudo', 'gac') }}</h4>
                <div class="text-muted small">
                    {% if is_draft %}
                        {{ __('Escolha o motivo da baixa de cada ativo antes de emitir o laudo.', 'gac') }}
                    {% else %}
                        {{ __('Ativos listados no laudo emitido. Os dados são uma cópia feita na emissão.', 'gac') }}
                    {% endif %}
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover card-table mb-0">
                <thead>
                    <tr>
                        <th>{{ __('Tipo', 'gac') }}</th>
                        <th>{{ __('Equipamento', 'gac') }}</th>
                        <th>{{ __('Marca / modelo', 'gac') }}</th>
                        <th>{{ __('Patrimônio', 'gac') }}</th>
                        <th>{{ __('Nº de série', 'gac') }}</th>
                        <th>{{ __('Origem', 'gac') }}</th>
                        <th>{{ __('Motivo', 'gac') }}</th>
                        {% if editing %}<th>{{ __('Ações', 'gac') }}</th>{% endif %}
                    </tr>
                </thead>
                <tbody>
                    {% for line in lines %}
                        <tr>
                            <td>{{ line.item_type_label }}</td>
                            <td>{{ line.item_name }}</td>
                            <td>{{ [line.brand, line.model]|filter(v => v)|join(' / ') ?: '—' }}</td>
                            <td>{{ line.otherserial ?: '—' }}</td>
                            <td>{{ line.serial ?: '—' }}</td>
                            <td>
                                {% if line.pre_number %}
                                    <span class="badge bg-secondary-lt">{{ line.pre_number }}</span>
                                {% endif %}
                                {% if line.tickets_id %}
                                    <a href="{{ line.ticket_url }}">#{{ line.tickets_id }}</a>
                                {% endif %}
                                {% if not line.pre_number and not line.tickets_id %}<span class="text-muted">—</span>{% endif %}
                            </td>
                            <td>
                                {% if editing %}
                                    <select class="form-select form-select-sm" name="reason[{{ line.id }}]" style="min-width: 14rem;">
                                        {% for reason_id, reason_label in reason_choices %}
                                            <option value="{{ reason_id }}"{% if reason_id == line.plugin_gac_ltbpreasons_id %} selected{% endif %}>{{ reason_label }}</option>
                                        {% endfor %}
                                    </select>
                                {% else %}
                                    {{ line.reason_text ?: '—' }}
                                {% endif %}
                            </td>
                            {% if editing %}
                                <td>
                                    <button type="submit" name="remove_line" value="{{ line.id }}" formnovalidate class="btn btn-sm btn-outline-danger">{{ __('Remover', 'gac') }}</button>
                                </td>
                            {% endif %}
                        </tr>
                    {% else %}
                        <tr><td colspan="{{ editing ? 8 : 7 }}" class="text-center text-muted">{{ __('Nenhum ativo neste laudo.', 'gac') }}</td></tr>
                    {% endfor %}
                </tbody>
            </table>
        </div>

        {% if editing and lines is not empty %}
            <div class="card-footer">
                <button type="submit" name="save_reasons" value="1" class="btn btn-primary">{{ __('Salvar motivos', 'gac') }}</button>
            </div>
        {% endif %}
    </div>

    {% if editing %}
        </form>

        {# Candidates: assets in the "Aguardando baixa" status (includes the ones that came back from the PRE). #}
        <div class="card border mb-4">
            <div class="card-header bg-body-tertiary">
                <div>
                    <h4 class="card-title mb-1"><i class="ti ti-package-import me-2"></i>{{ __('Ativos aguardando baixa', 'gac') }}</h4>
                    <div class="text-muted small">{{ __('Ativos no status "aguardando baixa" que ainda não estão em nenhum laudo, com a origem no PRE quando houver.', 'gac') }}</div>
                </div>
            </div>
            {% if candidates_hint %}
                <div class="card-body text-muted">{{ __('Defina o status "Ativo aguardando baixa" na configuração do LTBP para listar os candidatos.', 'gac') }}</div>
            {% elseif candidates is empty %}
                <div class="card-body text-muted">{{ __('Nenhum ativo aguardando baixa fora de um laudo.', 'gac') }}</div>
            {% else %}
                <form method="post" action="{{ form_url }}">
                    <input type="hidden" name="_glpi_csrf_token" value="{{ csrf_token() }}">
                    <input type="hidden" name="ltbp_id" value="{{ laudo.getID() }}">
                    <div class="table-responsive">
                        <table class="table table-hover card-table mb-0">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" class="form-check-input" data-gac-select-all title="{{ __('Marcar todos', 'gac') }}" aria-label="{{ __('Marcar todos', 'gac') }}"></th>
                                    <th>{{ __('Tipo', 'gac') }}</th>
                                    <th>{{ __('Equipamento', 'gac') }}</th>
                                    <th>{{ __('Patrimônio', 'gac') }}</th>
                                    <th>{{ __('Nº de série', 'gac') }}</th>
                                    <th>{{ __('Origem no PRE', 'gac') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {% for c in candidates %}
                                    <tr>
                                        <td><input type="checkbox" class="form-check-input" name="select[]" value="{{ c.key }}"></td>
                                        <td>{{ c.type_label }}</td>
                                        <td>{{ c.name }}</td>
                                        <td>{{ c.otherserial ?: '—' }}</td>
                                        <td>{{ c.serial ?: '—' }}</td>
                                        <td>
                                            {% if c.pre_number %}
                                                <span class="badge bg-secondary-lt">{{ c.pre_number }}</span>
                                                {% if c.outcome_label %}<span class="small text-muted">{{ c.outcome_label }}</span>{% endif %}
                                                {% if c.service_description %}<div class="small text-muted">{{ c.service_description }}</div>{% endif %}
                                            {% else %}
                                                <span class="text-muted">—</span>
                                            {% endif %}
                                        </td>
                                    </tr>
                                {% endfor %}
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer">
                        <button type="submit" name="add_candidates" value="1" class="btn btn-primary">{{ __('Adicionar selecionados ao laudo', 'gac') }}</button>
                    </div>
                </form>
            {% endif %}
        </div>

        {# Free search: any asset, for equipment that never went through the PRE. #}
        <div class="card border mb-4">
            <div class="card-header bg-body-tertiary">
                <div>
                    <h4 class="card-title mb-1"><i class="ti ti-search me-2"></i>{{ __('Buscar outro ativo', 'gac') }}</h4>
                    <div class="text-muted small">{{ __('Para equipamentos obsoletos que nunca passaram pelo PRE: escolha o tipo e o ativo.', 'gac') }}</div>
                </div>
            </div>
            <form method="post" action="{{ form_url }}">
                <input type="hidden" name="_glpi_csrf_token" value="{{ csrf_token() }}">
                <input type="hidden" name="ltbp_id" value="{{ laudo.getID() }}">
                <div class="card-body">{{ asset_picker|raw }}</div>
                <div class="card-footer">
                    <button type="submit" name="add_asset" value="1" class="btn btn-outline-primary">{{ __('Adicionar ao laudo', 'gac') }}</button>
                </div>
            </form>
        </div>
    {% endif %}
</div>
```

- [ ] **Step 7: Criar o tratador dos formulários da aba**

Create `front/ltbp/ltbpitem.form.php`:

```php
<?php

use GlpiPlugin\Gac\Ltbp\CandidateFinder;
use GlpiPlugin\Gac\Ltbp\LineService;
use GlpiPlugin\Gac\Ltbp\Ltbp;
use GlpiPlugin\Gac\Shared\ServiceResult;

$laudo   = new Ltbp();
$laudoId = (int) ($_POST['ltbp_id'] ?? 0);
if ($laudoId === 0 || !$laudo->getFromDB($laudoId)) {
    Html::displayNotFoundError();
}

$notify = static function (ServiceResult $r): void {
    Session::addMessageAfterRedirect(htmlescape($r->message), false, $r->ok ? INFO : ERROR);
};

if (isset($_POST['add_candidates'])) {
    // UPDATE = edit the draft (the standard right; the laudo is only editable while a draft).
    $laudo->check($laudoId, UPDATE);

    // Never trust the client: re-resolve every selected key against the current candidates.
    $wanted  = array_map('strval', (array) ($_POST['select'] ?? []));
    $assets  = [];
    $origins = [];
    foreach (CandidateFinder::find($laudo) as $c) {
        if (in_array($c['key'], $wanted, true)) {
            $assets[]           = ['itemtype' => $c['itemtype'], 'items_id' => $c['items_id']];
            $origins[$c['key']] = $c;
        }
    }
    $notify($assets === []
        ? ServiceResult::fail(__('Nenhum ativo válido foi selecionado.', 'gac'))
        : LineService::addAssets($laudo, $assets, $origins));
} elseif (isset($_POST['add_asset'])) {
    $laudo->check($laudoId, UPDATE);
    $type = (string) ($_POST['asset_itemtype'] ?? '');
    $id   = (int) ($_POST['asset_items_id'] ?? 0);
    $notify($type !== '' && $id > 0
        ? LineService::addAssets($laudo, [['itemtype' => $type, 'items_id' => $id]])
        : ServiceResult::fail(__('Escolha o tipo e o ativo.', 'gac')));
} elseif (isset($_POST['save_reasons'])) {
    $laudo->check($laudoId, UPDATE);
    $notify(LineService::saveReasons($laudo, (array) ($_POST['reason'] ?? [])));
} elseif (isset($_POST['remove_line'])) {
    $laudo->check($laudoId, UPDATE);
    $notify(LineService::removeLine($laudo, (int) $_POST['remove_line']));
}

Html::back();
```

- [ ] **Step 8: Lint, limpar o cache e verificar**

```bash
for f in src/Ltbp/AssetTypes.php src/Ltbp/AssetClaim.php src/Ltbp/AssetSnapshot.php src/Ltbp/CandidateFinder.php src/Ltbp/LineService.php src/Ltbp/LtbpItem.php src/Ltbp/Ltbp.php front/ltbp/ltbpitem.form.php; do /c/xampp/php/php.exe -l "$f"; done
cd /c/Users/juliano/VSCode/glpi-xampp-dev-plugin && /c/xampp/php/php.exe bin/console cache:clear && cd -
```
Prepare o cenário de teste: deixe 3 ativos (um computador e dois monitores, por exemplo) com o status **Ativo aguardando baixa** (edite o status pela tela do ativo); um quarto ativo qualquer em outro status.
Expected no navegador, com um laudo em rascunho aberto:
1. Aba **Itens** aparece, vazia, com o cartão "Ativos aguardando baixa" listando os 3 ativos (com "—" em Origem) e o cartão "Buscar outro ativo" com o seletor tipo/ativo.
2. Marque 2 candidatos e clique **Adicionar selecionados ao laudo**: mensagem "2 ativos adicionados", as linhas aparecem com marca/modelo/série/patrimônio; os 2 saem da lista de candidatos.
3. Pelo seletor, escolha o quarto ativo (fora do status) e adicione: entra. Tente adicionar de novo o mesmo: "já está no laudo LTBP-…".
4. Escolha um motivo em cada linha e **Salvar motivos**: a escolha permanece após recarregar. Remova uma linha: some, e o ativo volta aos candidatos se estava no status.
5. Crie um segundo laudo e tente adicionar um ativo do primeiro: recusa "já está no laudo …". Se algum ativo estiver em um PRE ativo (aguardando envio, em envio ou na assistência), recusa citando o PRE.
6. A aba **Histórico** do laudo mostra os eventos "Ativo adicionado" e "Ativo removido" com a coluna Campo = "Evento".

- [ ] **Step 9: Commit**

Invoque o skill `/commit` (tipo `feat`, por exemplo "add LTBP items tab with candidates, free search and reasons").

---

### Task 9: PDF do laudo (retrato)

**Files:**
- Create: `src/Ltbp/PdfRenderer.php`, `templates/ltbp/report.html.twig`, `front/ltbp/ltbp.pdf.php`
- Modify: `src/Ltbp/LtbpItem.php` (botão do PDF na aba Itens), `templates/ltbp/items_tab.html.twig`

**Interfaces:**
- Consumes: `Ltbp`, `LtbpReason::row()`, `LtbpConfig::load()`, `LtbpSettings::directors()`, `Labels::destination()`, `PlaceDate::format()` (Tarefa 4), `Shared\{DocumentStore, LogoFit, LogoLocator, MpdfLoader, ReportFormatter}` (Tarefa 1).
- Produces:
  - `PdfRenderer::render(Ltbp $l, bool $draft): array{bytes: string, filename: string}` (`$draft = true` usa os motivos e os diretores **atuais** e põe a marca d'água "RASCUNHO"; `false` usa o snapshot gravado no laudo)
  - `PdfRenderer::attachFrozen(Ltbp $l): int` (Document id; anexa o PDF ao laudo)
  - Página `front/ltbp/ltbp.pdf.php?id=<laudo>[&doc=signed]`: rascunho = prévia; emitido = o PDF congelado (`documents_id_frozen`); `doc=signed` = o arquivo assinado (`documents_id_signed`).

- [ ] **Step 1: Criar o renderizador**

Create `src/Ltbp/PdfRenderer.php`:

```php
<?php

namespace GlpiPlugin\Gac\Ltbp;

use Entity;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Gac\Shared\DocumentStore;
use GlpiPlugin\Gac\Shared\LogoFit;
use GlpiPlugin\Gac\Shared\LogoLocator;
use GlpiPlugin\Gac\Shared\MpdfLoader;
use GlpiPlugin\Gac\Shared\ReportFormatter;

/**
 * PDF of the laudo (spec section 9): Twig template -> HTML -> mPDF, in PORTRAIT (spec L16).
 * The definitive PDF is generated once, at the emission, and stored as a Document (spec L17).
 */
final class PdfRenderer
{
    /** Box the logo is fitted into on the report header, in millimetres (same as the PRE). */
    private const LOGO_BOX_WIDTH_MM = 60.0;
    private const LOGO_BOX_HEIGHT_MM = 14.0;

    /** @return array{bytes: string, filename: string} */
    public static function render(Ltbp $l, bool $draft): array
    {
        MpdfLoader::load();

        $html = TemplateRenderer::getInstance()->render('@gac/ltbp/report.html.twig', self::templateVars($l, $draft));

        $tmp = GLPI_TMP_DIR . '/gac-mpdf';
        if (!is_dir($tmp)) {
            mkdir($tmp, 0770, true);
        }
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8', 'format' => 'A4', 'orientation' => 'P',
            'margin_left' => 15, 'margin_right' => 15, 'margin_top' => 12, 'margin_bottom' => 18,
            'default_font' => 'dejavusans', 'tempDir' => $tmp,
        ]);
        $mpdf->SetHTMLFooter(sprintf(
            '<table width="100%%" style="font-size:7.5pt;color:#6b7280;"><tr><td width="34%%">%s</td><td width="33%%" align="center">%s</td><td width="33%%" align="right">%s {PAGENO} / {nb}</td></tr></table>',
            htmlescape((string) $l->fields['number']),
            htmlescape(self::applicationUrl()),
            htmlescape(__('Página', 'gac'))
        ));
        if ($draft) {
            $mpdf->SetWatermarkText(__('RASCUNHO', 'gac'), 0.08);
            $mpdf->showWatermarkText = true;
        }
        $mpdf->WriteHTML($html);

        return [
            'bytes'    => $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN),
            'filename' => $l->fields['number'] . '.pdf',
        ];
    }

    /** Generates the definitive PDF and attaches it to the laudo. @return int Document id */
    public static function attachFrozen(Ltbp $l): int
    {
        $rendered = self::render($l, false);

        return DocumentStore::attachBytes(
            $rendered['bytes'],
            $rendered['filename'],
            sprintf('%s (%s)', $l->fields['number'], __('emitido', 'gac')),
            (int) $l->fields['entities_id'],
            Ltbp::class,
            (int) $l->getID()
        );
    }

    /** @return array<string, mixed> */
    private static function templateVars(Ltbp $l, bool $draft): array
    {
        $settings = LtbpConfig::load();

        $entity = new Entity();
        $entity->getFromDB((int) $l->fields['entities_id']);
        $f = $entity->fields;

        // A draft preview reads the live catalog and the configured directors; an issued laudo
        // reads only the snapshots stored in it (spec L3, L9).
        $directors = $draft ? LtbpSettings::directors($settings) : [
            'ti'  => ['name' => (string) $l->fields['director_ti_name'], 'role' => (string) $l->fields['director_ti_role']],
            'adm' => ['name' => (string) $l->fields['director_adm_name'], 'role' => (string) $l->fields['director_adm_role']],
        ];

        $rows   = [];
        $legend = [];
        foreach ($l->lines() as $line) {
            if ($draft) {
                $reason = LtbpReason::row((int) $line['plugin_gac_ltbpreasons_id']) ?? [];
                $code   = (string) ($reason['code'] ?? '');
                $title  = (string) ($reason['name'] ?? '');
                $desc   = (string) ($reason['comment'] ?? '');
            } else {
                $code  = (string) $line['reason_code'];
                $title = (string) $line['reason_title'];
                $desc  = (string) $line['reason_description'];
            }
            $rows[] = [
                'name'        => (string) $line['item_name'],
                'type'        => (string) $line['item_type_label'],
                'brand'       => (string) $line['brand'],
                'model'       => (string) $line['model'],
                'serial'      => (string) $line['serial'],
                'otherserial' => (string) $line['otherserial'],
                'reason_code' => $code,
            ];
            if ($code !== '') {
                $legend[$code] = ['code' => $code, 'title' => $title, 'description' => $desc];
            }
        }
        ksort($legend);

        // mPDF ignores max-width/max-height on images, so the size is computed here.
        $logo     = LogoLocator::dataUri((int) $l->fields['entities_id'], LtbpSettings::logoCategoryId($settings));
        $logoSize = null;
        if ($logo !== null) {
            $info = getimagesizefromstring((string) base64_decode(substr($logo, (int) strpos($logo, ',') + 1), true));
            if ($info !== false) {
                $logoSize = LogoFit::fit((int) $info[0], (int) $info[1], self::LOGO_BOX_WIDTH_MM, self::LOGO_BOX_HEIGHT_MM);
            }
        }

        $issued      = $draft ? date('Y-m-d') : (string) $l->fields['date_issued'];
        $destination = $l->getDestination();

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
                'phone'         => (string) ($f['phonenumber'] ?? ''),
                'logo_data_uri' => $logo,
                'logo_size'     => $logoSize,
            ],
            'laudo' => [
                'number'      => (string) $l->fields['number'],
                'destination' => $destination === null ? '' : Labels::destination($destination),
                'technician'  => getUserName((int) $l->fields['users_id_tech']),
                'date_issued' => ReportFormatter::date($issued),
                // "Cidade/UF, dd/mm/aaaa": town and state come from the laudo's entity (spec L15).
                'place_date'  => PlaceDate::format((string) ($f['town'] ?? ''), (string) ($f['state'] ?? ''), $issued),
            ],
            'directors' => $directors,
            'rows'      => $rows,
            'legend'    => array_values($legend),
        ];
    }

    /** The GLPI "URL da aplicação" (Configurar > Geral), shown in the report footer; empty if unset. */
    private static function applicationUrl(): string
    {
        global $CFG_GLPI;

        return trim((string) ($CFG_GLPI['url_base'] ?? ''));
    }
}
```

- [ ] **Step 2: Criar o template do PDF**

Create `templates/ltbp/report.html.twig`:

```twig
<style>
    body { font-family: dejavusans, sans-serif; font-size: 9pt; color: #2b2f36; }
    table { border-collapse: collapse; }
    .header { width: 100%; margin-bottom: 2mm; }
    .header td { vertical-align: middle; }
    .company { text-align: right; font-size: 7.2pt; line-height: 1.3; color: #4a5260; }
    .company .name { font-size: 9pt; font-weight: bold; color: #1f3a5f; }
    .rule { border-top: 0.4mm solid #1f3a5f; margin: 0 0 3mm 0; }
    .title { font-size: 14pt; font-weight: bold; color: #1f3a5f; text-align: center; margin: 0 0 4mm 0; }
    .meta { width: 100%; margin-bottom: 3mm; }
    .meta td { padding: 2mm 3mm; border: 0.25mm solid #9ca3af; font-size: 8.8pt; }
    .meta td.label { background-color: #e3e6eb; color: #111827; font-weight: bold; width: 33mm; }
    h2 { font-size: 10.5pt; color: #1f3a5f; border-bottom: 0.25mm solid #9ca3af; padding-bottom: 1mm; margin: 5mm 0 2mm 0; }
    p { margin: 0 0 2mm 0; text-align: justify; line-height: 1.4; }
    .items { width: 100%; margin-bottom: 3mm; }
    .items th { background-color: #e3e6eb; color: #111827; font-size: 7.8pt; text-align: left; padding: 2.2mm 1.8mm; border: 0.25mm solid #9ca3af; }
    .items td { font-size: 7.8pt; padding: 1.8mm; border: 0.25mm solid #9ca3af; vertical-align: top; }
    .items tr.zebra td { background-color: #f3f4f6; }
    .items .type { color: #6b7280; font-size: 7pt; }
    .items .center { text-align: center; }
    .legend p { text-align: left; margin-bottom: 1.5mm; }
    .place { text-align: center; font-size: 9.5pt; margin: 6mm 0 2mm 0; }
    .signatures { width: 100%; }
    .signature-gap { height: 24mm; }
    .signatures td.sigcell { width: 31%; text-align: center; border-top: 0.3mm solid #4a5260; padding-top: 1.5mm; vertical-align: top; }
    .signatures td.gap { width: 3.5%; }
    .signame { font-weight: bold; font-size: 8.8pt; }
    .sigrole { font-size: 7.5pt; color: #6b7280; font-style: italic; }
</style>

<table class="header">
    <tr>
        <td class="logo">{% if company.logo_data_uri %}<img src="{{ company.logo_data_uri }}" alt=""{% if company.logo_size %} style="width: {{ company.logo_size.width }}mm; height: {{ company.logo_size.height }}mm;"{% endif %}>{% endif %}</td>
        <td class="company">
            <div class="name">{{ company.name }}</div>
            {% if company.registration_number %}<div>{{ company.registration_number }}</div>{% endif %}
            {% if company.address_line %}<div>{{ company.address_line }}</div>{% endif %}
            {% if company.phone %}<div>{{ company.phone }}</div>{% endif %}
        </td>
    </tr>
</table>
<div class="rule"></div>

<p class="title">{{ __('LAUDO TÉCNICO DE BAIXA PATRIMONIAL', 'gac') }}</p>

<table class="meta">
    <tr>
        <td class="label">{{ __('Nº do documento', 'gac') }}</td>
        <td><strong>{{ laudo.number }}</strong></td>
        <td class="label">{{ __('Data de emissão', 'gac') }}</td>
        <td>{{ laudo.date_issued }}</td>
    </tr>
    <tr>
        <td class="label">{{ __('Técnico responsável', 'gac') }}</td>
        <td>{{ laudo.technician }}</td>
        <td class="label">{{ __('Destinação', 'gac') }}</td>
        <td><strong>{{ laudo.destination }}</strong></td>
    </tr>
</table>

<h2>1. {{ __('Objetivo', 'gac') }}</h2>
<p>{{ __('Este laudo tem como objetivo atestar a condição técnica dos bens patrimoniais listados abaixo, pertencentes ao ativo imobilizado desta instituição, e recomendar a sua baixa contábil e destinação final, em conformidade com as normas vigentes. A análise foi baseada em critérios de obsolescência, custo de reparo e inviabilidade técnica de utilização.', 'gac') }}</p>

<h2>2. {{ __('Detalhamento dos bens', 'gac') }}</h2>
<table class="items">
    <thead>
        <tr>
            <th style="width: 27%;">{{ __('Equipamento', 'gac') }}</th>
            <th style="width: 13%;">{{ __('Marca', 'gac') }}</th>
            <th style="width: 16%;">{{ __('Modelo', 'gac') }}</th>
            <th style="width: 17%;">{{ __('Nº de série', 'gac') }}</th>
            <th style="width: 15%;">{{ __('Patrimônio', 'gac') }}</th>
            <th style="width: 12%;" class="center">{{ __('Motivo', 'gac') }}</th>
        </tr>
    </thead>
    <tbody>
        {% for row in rows %}
            <tr{% if loop.index is even %} class="zebra"{% endif %} style="page-break-inside: avoid;">
                <td>{{ row.name }}<div class="type">{{ row.type }}</div></td>
                <td>{{ row.brand ?: '-' }}</td>
                <td>{{ row.model ?: '-' }}</td>
                <td>{{ row.serial ?: '-' }}</td>
                <td>{{ row.otherserial ?: '-' }}</td>
                <td class="center">{{ row.reason_code ?: '-' }}</td>
            </tr>
        {% endfor %}
    </tbody>
</table>

<h2>3. {{ __('Legenda dos motivos da baixa', 'gac') }}</h2>
<div class="legend">
    {% for m in legend %}
        <p><strong>{{ m.code }}:</strong> {{ m.title }}{% if m.description %}<br><em>{{ m.description }}</em>{% endif %}</p>
    {% else %}
        <p>{{ __('Nenhum motivo de baixa foi selecionado para os itens.', 'gac') }}</p>
    {% endfor %}
</div>

<h2>4. {{ __('Destinação recomendada', 'gac') }}</h2>
<p>{{ __('Com base na análise técnica, recomenda-se a seguinte destinação para a totalidade dos bens listados neste laudo:', 'gac') }} <strong>{{ laudo.destination }}</strong>.</p>

<h2>5. {{ __('Conclusão', 'gac') }}</h2>
<p>{{ __('Atestamos, para os devidos fins, que os equipamentos supracitados encontram-se em condição de baixa patrimonial pelos motivos detalhados. Solicitamos, portanto, a autorização para os procedimentos de descarte ou doação conforme recomendado.', 'gac') }}</p>

<div style="page-break-inside: avoid;">
    <p class="place">{{ laudo.place_date }}.</p>
    <table class="signatures">
        <tr><td class="signature-gap" colspan="5">&nbsp;</td></tr>
        <tr>
            <td class="sigcell">
                <div class="signame">{{ laudo.technician }}</div>
                <div class="sigrole">{{ __('Técnico responsável', 'gac') }}</div>
            </td>
            <td class="gap">&nbsp;</td>
            <td class="sigcell">
                <div class="signame">{{ directors.ti.name }}</div>
                <div class="sigrole">{{ directors.ti.role }}</div>
            </td>
            <td class="gap">&nbsp;</td>
            <td class="sigcell">
                <div class="signame">{{ directors.adm.name }}</div>
                <div class="sigrole">{{ directors.adm.role }}</div>
            </td>
        </tr>
    </table>
</div>
```

- [ ] **Step 3: Criar a página do PDF**

Create `front/ltbp/ltbp.pdf.php`:

```php
<?php

use GlpiPlugin\Gac\Ltbp\Ltbp;
use GlpiPlugin\Gac\Ltbp\PdfRenderer;
use GlpiPlugin\Gac\Ltbp\Status;

$laudo = new Ltbp();
if (!$laudo->getFromDB((int) ($_GET['id'] ?? 0)) || !$laudo->canViewItem()) {
    Html::displayRightError();
}

$send = static function (string $bytes, string $filename, string $mime): never {
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, no-store');
    echo $bytes;
    exit;
};

/** Streams a stored Document (from the GLPI documents folder), never a new rendering. */
$sendDocument = static function (int $documentId, string $fallbackName) use ($send): never {
    $document = new Document();
    if ($documentId > 0 && $document->getFromDB($documentId)) {
        $path = realpath(GLPI_DOC_DIR . '/' . $document->fields['filepath']);
        if ($path !== false && str_starts_with($path, realpath(GLPI_DOC_DIR)) && is_file($path)) {
            $send(
                (string) file_get_contents($path),
                (string) ($document->fields['filename'] ?: $fallbackName),
                (string) ($document->fields['mime'] ?: 'application/octet-stream')
            );
        }
    }
    Html::displayNotFoundError();
};

if ((string) ($_GET['doc'] ?? '') === 'signed') {
    $sendDocument((int) $laudo->fields['documents_id_signed'], $laudo->fields['number'] . '-assinado');
}

if ($laudo->getStatus() === Status::Draft) {
    $r = PdfRenderer::render($laudo, true);
    $send($r['bytes'], $r['filename'], 'application/pdf');
}

$sendDocument((int) $laudo->fields['documents_id_frozen'], $laudo->fields['number'] . '.pdf');
```

- [ ] **Step 4: Botão do PDF na aba Itens**

Modify `src/Ltbp/LtbpItem.php`, em `showForLaudo()`: acrescente ao array passado ao `display()` (depois de `'form_url'`):

```php
            'pdf_url'          => str_replace('.form.php', '.pdf.php', Ltbp::getFormURL()) . '?id=' . (int) $laudo->getID(),
            'can_pdf'          => $laudo->canViewItem()
                && ($isDraft ? $lines !== [] : (int) $laudo->fields['documents_id_frozen'] > 0),
```

Modify `templates/ltbp/items_tab.html.twig`: dentro do `card-header` do cartão "Ativos do laudo", logo depois do `</div>` que fecha o bloco `flex-grow-1`, acrescente:

```twig
            {% if can_pdf %}
                <a class="btn btn-sm btn-outline-secondary" href="{{ pdf_url }}" target="_blank" rel="noopener">
                    <i class="ti ti-file-type-pdf"></i>
                    {{ is_draft ? __('Pré-visualizar PDF', 'gac') : __('Baixar PDF emitido', 'gac') }}
                </a>
            {% endif %}
```

- [ ] **Step 5: Lint, limpar o cache e verificar**

```bash
for f in src/Ltbp/PdfRenderer.php src/Ltbp/LtbpItem.php front/ltbp/ltbp.pdf.php; do /c/xampp/php/php.exe -l "$f"; done
cd /c/Users/juliano/VSCode/glpi-xampp-dev-plugin && /c/xampp/php/php.exe bin/console cache:clear && cd -
```
No navegador, num laudo em rascunho com 2 ou mais ativos e motivos escolhidos (aba **Itens**), clique em **Pré-visualizar PDF**.
Expected: PDF aberto no navegador, **A4 em retrato**, marca d'água "RASCUNHO", cabeçalho com logomarca e dados da entidade, tabela do laudo (número, técnico, destinação, data), seções 1 a 5, tabela de bens com as seis colunas legíveis (nomes longos quebram linha), legenda só dos motivos usados, local e data "Cidade/UF, dd/mm/aaaa" (com a cidade e a UF do cadastro da entidade; sem elas, só a data), três blocos de assinatura lado a lado (técnico, Diretor de TI, Diretor Administrativo com os nomes da configuração) e rodapé com o número do laudo e "Página 1 / 1". Imprima em preto e branco (ou veja em escala de cinza): cabeçalhos das tabelas em cinza claro único. Teste com 40 ativos (crie por consulta ou repetindo) e confira a quebra de página: o cabeçalho da tabela se repete, nenhuma linha é cortada e o bloco das assinaturas não se divide.

- [ ] **Step 6: Commit**

Invoque o skill `/commit` (tipo `feat`, por exemplo "add LTBP portrait PDF report").

---

### Task 10: Emissão, cancelamento e a aba Andamento

**Files:**
- Create: `src/Ltbp/LtbpGuard.php`, `src/Ltbp/IssueService.php`, `src/Ltbp/LtbpProgress.php`, `templates/ltbp/progress.html.twig`, `front/ltbp/ltbpstep.form.php`
- Modify: `src/Ltbp/Ltbp.php` (registrar a aba)

**Interfaces:**
- Consumes: `Ltbp`, `LtbpItem`, `LtbpEvent`, `LtbpReason::choices()/row()`, `LtbpConfig`, `LtbpSettings`, `EmissionValidator`, `StateMachine`, `PdfRenderer::attachFrozen()`, `LineService::hasActivePreLine()`, `Shared\{ServiceResult, StateGuard}`.
- Produces:
  - `LtbpGuard::run(callable $fn): mixed`; `LtbpGuard::isActive(): bool` (passagem de escopo curto do bloqueio, plan decision 11)
  - `IssueService::problems(Ltbp $laudo): list<string>` (mensagens traduzidas; vazia = pode emitir)
  - `IssueService::issue(Ltbp $laudo): ServiceResult`
  - `IssueService::cancel(Ltbp $laudo, string $reason): ServiceResult`
  - `LtbpProgress` (aba "Andamento", `CommonGLPI`), com `show(Ltbp $laudo): void`
  - `front/ltbp/ltbpstep.form.php` recebendo `POST` com `ltbp_id` e uma das chaves `issue` ou `cancel` (a Tarefa 11 acrescenta as demais)

- [ ] **Step 1: Criar `LtbpGuard`**

Create `src/Ltbp/LtbpGuard.php`:

```php
<?php

namespace GlpiPlugin\Gac\Ltbp;

/**
 * Short-scoped pass for the module's own asset updates (spec section 8): while a callback runs
 * inside run(), the write-off lock lets the update through, so the plugin never blocks itself.
 */
final class LtbpGuard
{
    private static int $depth = 0;

    public static function run(callable $fn): mixed
    {
        self::$depth++;
        try {
            return $fn();
        } finally {
            self::$depth--;
        }
    }

    public static function isActive(): bool
    {
        return self::$depth > 0;
    }
}
```

- [ ] **Step 2: Criar `IssueService`**

Create `src/Ltbp/IssueService.php`:

```php
<?php

namespace GlpiPlugin\Gac\Ltbp;

use GlpiPlugin\Gac\Shared\ServiceResult;
use GlpiPlugin\Gac\Shared\StateGuard;
use Toolbox;

/**
 * Emission (spec 6.1) and cancellation (spec 6.2). The emission is one transaction that also
 * generates the PDF (plan decision 9): a failure undoes the assets and the laudo alike.
 */
final class IssueService
{
    /** @return list<string> translated messages; empty when the laudo can be issued */
    public static function problems(Ltbp $laudo): array
    {
        $settings = LtbpConfig::load();
        $lines    = $laudo->lines();
        $active   = LtbpReason::choices();

        $withoutReason = 0;
        $conflicts     = 0;
        foreach ($lines as $line) {
            if (!isset($active[(int) $line['plugin_gac_ltbpreasons_id']])) {
                $withoutReason++;
            }
            $other = Ltbp::activeLaudoFor((string) $line['itemtype'], (int) $line['items_id']);
            if (
                ($other !== null && $other['id'] !== (int) $laudo->getID())
                || LineService::hasActivePreLine((string) $line['itemtype'], (int) $line['items_id'])
            ) {
                $conflicts++;
            }
        }

        return array_map(self::message(...), EmissionValidator::validate([
            'destination'          => (string) $laudo->fields['destination'],
            'line_count'           => count($lines),
            'lines_without_reason' => $withoutReason,
            'missing_state_roles'  => LtbpSettings::missingStateRoles($settings),
            'directors_missing'    => LtbpSettings::directorsMissing($settings),
            'conflicts'            => $conflicts,
        ]));
    }

    private static function message(string $code): string
    {
        return match ($code) {
            'destination' => __('Defina a destinação do laudo.', 'gac'),
            'no_lines'    => __('Adicione ao menos um ativo ao laudo.', 'gac'),
            'reason'      => __('Escolha um motivo ativo para todos os ativos.', 'gac'),
            'states'      => __('Mapeie os três status do ativo na configuração do LTBP.', 'gac'),
            'directors'   => __('Preencha o nome e o cargo dos dois diretores na configuração do LTBP.', 'gac'),
            'conflicts'   => __('Há ativos que já estão em outro laudo ou em um PRE ativo; remova-os do laudo.', 'gac'),
            default       => $code,
        };
    }

    public static function issue(Ltbp $laudo): ServiceResult
    {
        global $DB;

        if (!StateMachine::canIssue($laudo->getStatus())) {
            return ServiceResult::fail(__('Só é possível emitir um laudo em rascunho.', 'gac'));
        }
        $problems = self::problems($laudo);
        if ($problems !== []) {
            return ServiceResult::fail(implode(' ', $problems));
        }

        $settings  = LtbpConfig::load();
        $stateIn   = LtbpSettings::stateId($settings, 'in_process');
        $directors = LtbpSettings::directors($settings);
        $lines     = $laudo->lines();

        try {
            $DB->beginTransaction();

            LtbpGuard::run(static function () use ($lines, $stateIn, $DB): void {
                foreach ($lines as $line) {
                    $asset = getItemForItemtype((string) $line['itemtype']);
                    if (!$asset || !$asset->getFromDB((int) $line['items_id'])) {
                        throw new \RuntimeException(sprintf(__('%s: ativo não encontrado.', 'gac'), $line['item_name']));
                    }
                    if (!StateGuard::isUsable($stateIn, (int) ($asset->fields['entities_id'] ?? 0))) {
                        throw new \RuntimeException(sprintf(
                            __('%s: o status "em processo de baixa" não vale para a entidade deste ativo.', 'gac'),
                            $line['item_name']
                        ));
                    }

                    $before = (int) ($asset->fields['states_id'] ?? 0);
                    if ($before !== $stateIn && !$asset->update(['id' => $asset->getID(), 'states_id' => $stateIn])) {
                        throw new \RuntimeException(sprintf(__('%s: não foi possível alterar o status do ativo.', 'gac'), $line['item_name']));
                    }

                    // Snapshot of the reason (spec L9): editing the catalog later never changes an issued laudo.
                    $reason = LtbpReason::row((int) $line['plugin_gac_ltbpreasons_id']) ?? [];
                    $DB->update(
                        LtbpItem::getTable(),
                        [
                            'states_id_before'   => $before,
                            'reason_code'        => (string) ($reason['code'] ?? ''),
                            'reason_title'       => (string) ($reason['name'] ?? ''),
                            'reason_description' => (string) ($reason['comment'] ?? ''),
                            'last_error'         => null,
                            'date_mod'           => $_SESSION['glpi_currenttime'],
                        ],
                        ['id' => (int) $line['id']]
                    );
                }
            });

            // The directors are copied into the laudo (spec L3): a later change of director does not touch it.
            $laudo->changeStatus(Status::AwaitingSignatures, [
                'date_issued'       => date('Y-m-d'),
                'director_ti_name'  => $directors['ti']['name'],
                'director_ti_role'  => $directors['ti']['role'],
                'director_adm_name' => $directors['adm']['name'],
                'director_adm_role' => $directors['adm']['role'],
            ]);

            // Rendered from the rows just written (same connection); a failure here rolls everything back.
            $documentId = PdfRenderer::attachFrozen($laudo);
            $DB->update(Ltbp::getTable(), ['documents_id_frozen' => $documentId], ['id' => (int) $laudo->getID()]);
            $laudo->getFromDB((int) $laudo->getID());

            LtbpEvent::log((int) $laudo->getID(), 'issued', '', ['documents_id' => $documentId]);

            $DB->commit();
        } catch (\Throwable $e) {
            try {
                $DB->rollBack();
            } catch (\Throwable) {
                // not in a transaction
            }
            $laudo->getFromDB((int) $laudo->getID());
            Toolbox::logInFile('gac', sprintf("issue %d failed: %s\n", $laudo->getID(), $e->getMessage()));
            return ServiceResult::fail($e->getMessage());
        }

        return ServiceResult::ok(__('Laudo emitido para assinatura.', 'gac'));
    }

    public static function cancel(Ltbp $laudo, string $reason): ServiceResult
    {
        global $DB;

        if (!StateMachine::canCancel($laudo->getStatus())) {
            return ServiceResult::fail(__('Este laudo não pode mais ser cancelado.', 'gac'));
        }
        $reason = trim($reason);
        if ($reason === '') {
            return ServiceResult::fail(__('Informe o motivo do cancelamento.', 'gac'));
        }

        $stateIn  = LtbpSettings::stateId(LtbpConfig::load(), 'in_process');
        $warnings = [];

        try {
            $DB->beginTransaction();

            LtbpGuard::run(function () use ($laudo, $stateIn, &$warnings): void {
                foreach ($laudo->lines() as $line) {
                    $asset = getItemForItemtype((string) $line['itemtype']);
                    if (!$asset || !$asset->getFromDB((int) $line['items_id'])) {
                        $warnings[] = sprintf(__('%s: ativo não encontrado.', 'gac'), $line['item_name']);
                        continue;
                    }
                    // Only restores a status the laudo itself set: a manual change in the meantime stays (spec 6.2).
                    if ((int) ($asset->fields['states_id'] ?? 0) !== $stateIn) {
                        $warnings[] = sprintf(__('%s: o status foi alterado à mão e não foi restaurado.', 'gac'), $line['item_name']);
                        continue;
                    }
                    if (!$asset->update(['id' => $asset->getID(), 'states_id' => (int) ($line['states_id_before'] ?? 0)])) {
                        throw new \RuntimeException(sprintf(__('%s: não foi possível restaurar o status do ativo.', 'gac'), $line['item_name']));
                    }
                }
            });

            $laudo->changeStatus(Status::Canceled, [
                'date_canceled' => $_SESSION['glpi_currenttime'],
                'cancel_reason' => $reason,
            ]);
            LtbpEvent::log((int) $laudo->getID(), 'canceled', $reason, $warnings === [] ? [] : ['warnings' => $warnings]);

            $DB->commit();
        } catch (\Throwable $e) {
            try {
                $DB->rollBack();
            } catch (\Throwable) {
                // not in a transaction
            }
            $laudo->getFromDB((int) $laudo->getID());
            Toolbox::logInFile('gac', sprintf("cancel %d failed: %s\n", $laudo->getID(), $e->getMessage()));
            return ServiceResult::fail($e->getMessage());
        }

        $message = __('Laudo cancelado.', 'gac');
        if ($warnings !== []) {
            $message .= ' ' . implode(' ', $warnings);
        }
        return ServiceResult::ok($message);
    }
}
```

- [ ] **Step 3: Criar a aba Andamento**

Create `src/Ltbp/LtbpProgress.php`:

```php
<?php

namespace GlpiPlugin\Gac\Ltbp;

use CommonGLPI;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Gac\Shared\ReportFormatter;
use Session;
use Supplier;

/**
 * "Andamento" tab (plan decision 15): where the laudo moves through its lifecycle. Shows the
 * stepper and only the action that fits the current status and the user's rights.
 */
class LtbpProgress extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return __('Andamento', 'gac');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Ltbp && !$item->isNewItem()) {
            return self::createTabEntry(__('Andamento', 'gac'), 0, null, 'ti ti-route');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof Ltbp) {
            return false;
        }
        self::show($item);
        return true;
    }

    public static function show(Ltbp $laudo): void
    {
        global $CFG_GLPI;

        $status   = $laudo->getStatus();
        $rn       = Ltbp::$rightname;
        $viewable = $laudo->canViewItem();
        $advance  = $viewable && Session::haveRight($rn, Ltbp::RIGHT_ISSUE);
        $settings = LtbpConfig::load();

        $supplierDropdown = '';
        if ($advance && StateMachine::canComplete($status)) {
            $supplierDropdown = Dropdown::show(Supplier::class, [
                'name'        => 'suppliers_id',
                'entity'      => (int) $laudo->fields['entities_id'],
                'entity_sons' => true,
                'display'     => false,
                'required'    => true,
            ]);
        }

        $pdfBase = str_replace('.form.php', '.pdf.php', Ltbp::getFormURL()) . '?id=' . (int) $laudo->getID();

        TemplateRenderer::getInstance()->display('@gac/ltbp/progress.html.twig', [
            'laudo'              => $laudo,
            'status_value'       => $status->value,
            'status_label'       => Labels::status($status),
            'steps'              => self::steps($laudo, $status),
            'canceled_on'        => self::day((string) ($laudo->fields['date_canceled'] ?? '')),
            'problems'           => StateMachine::canIssue($status) ? IssueService::problems($laudo) : [],
            'can_issue'          => $advance && StateMachine::canIssue($status),
            'can_cancel'         => $viewable && Session::haveRight($rn, Ltbp::RIGHT_CANCEL) && StateMachine::canCancel($status),
            'can_upload_signed'  => $advance && StateMachine::canUploadSigned($status),
            'can_send'           => $advance && StateMachine::canSendToPatrimony($status),
            'can_confirm'        => $advance && StateMachine::canConfirmWriteoff($status),
            'can_complete'       => $advance && StateMachine::canComplete($status),
            'require_document'   => LtbpSettings::requireCompletionDocument($settings),
            'supplier_dropdown'  => $supplierDropdown,
            'has_frozen'         => (int) $laudo->fields['documents_id_frozen'] > 0,
            'has_signed'         => (int) $laudo->fields['documents_id_signed'] > 0,
            'frozen_url'         => $pdfBase,
            'signed_url'         => $pdfBase . '&doc=signed',
            'today'              => date('Y-m-d'),
            'step_url'           => $CFG_GLPI['root_doc'] . '/plugins/gac/front/ltbp/ltbpstep.form.php',
        ]);
    }

    /** @return list<array{label: string, state: string, date: string}> state is done, current or todo */
    private static function steps(Ltbp $laudo, Status $status): array
    {
        $order = [
            [Status::Draft, 'date_creation'],
            [Status::AwaitingSignatures, 'date_issued'],
            [Status::Signed, 'date_signed'],
            [Status::AtPatrimony, 'date_sent_patrimony'],
            [Status::WrittenOff, 'date_written_off'],
            [Status::Completed, 'date_completed'],
        ];

        $current = null;
        foreach ($order as $i => [$s]) {
            if ($s === $status) {
                $current = $i;
            }
        }

        $steps = [];
        foreach ($order as $i => [$s, $column]) {
            $state = 'todo';
            if ($current !== null) {
                $state = $status === Status::Completed || $i < $current ? 'done' : ($i === $current ? 'current' : 'todo');
            }
            $steps[] = [
                'label' => Labels::status($s),
                'state' => $state,
                'date'  => $state === 'todo' ? '' : self::day((string) ($laudo->fields[$column] ?? '')),
            ];
        }
        return $steps;
    }

    /** A date or timestamp column as dd/mm/aaaa; empty when unset. */
    private static function day(string $value): string
    {
        return $value === '' ? '' : ReportFormatter::date(substr($value, 0, 10));
    }
}
```

Modify `src/Ltbp/Ltbp.php`: em `defineTabs()`, depois da linha do `LtbpItem::class`, acrescente:

```php
        $this->addStandardTab(LtbpProgress::class, $tabs, $options);
```

- [ ] **Step 4: Criar o template da aba Andamento**

Create `templates/ltbp/progress.html.twig`:

```twig
<div class="p-3" data-gac-ltbp-progress>

    {% if status_value == 'canceled' %}
        <div class="alert alert-danger">
            <strong>{{ __('Laudo cancelado', 'gac') }}</strong>{% if canceled_on %} {{ __('em', 'gac') }} {{ canceled_on }}{% endif %}.
            {{ __('Motivo:', 'gac') }} {{ laudo.fields['cancel_reason'] }}
        </div>
    {% endif %}

    <div class="card border mb-4">
        <div class="card-header bg-body-tertiary">
            <div>
                <h4 class="card-title mb-1"><i class="ti ti-route me-2"></i>{{ __('Andamento do laudo', 'gac') }}</h4>
                <div class="text-muted small">{{ __('Situação atual:', 'gac') }} <strong>{{ status_label }}</strong></div>
            </div>
        </div>
        <div class="card-body">
            <div class="d-flex flex-wrap gap-3">
                {% for step in steps %}
                    <div class="text-center flex-fill">
                        <span class="badge {{ step.state == 'done' ? 'bg-success-lt' : (step.state == 'current' ? 'bg-primary-lt' : 'bg-secondary-lt') }}">{{ step.label }}</span>
                        <div class="small text-muted mt-1">{{ step.date ?: '—' }}</div>
                    </div>
                {% endfor %}
            </div>
        </div>
    </div>

    {% if has_frozen %}
        <div class="card border mb-4">
            <div class="card-header bg-body-tertiary">
                <div>
                    <h4 class="card-title mb-1"><i class="ti ti-file-type-pdf me-2"></i>{{ __('Documentos do laudo', 'gac') }}</h4>
                    <div class="text-muted small">{{ __('O PDF emitido é o documento que vai para assinatura e nunca é gerado de novo.', 'gac') }}</div>
                </div>
            </div>
            <div class="card-body d-flex flex-wrap gap-2 align-items-center">
                <a class="btn btn-outline-secondary" href="{{ frozen_url }}" target="_blank" rel="noopener">
                    <i class="ti ti-file-type-pdf"></i> {{ __('Baixar PDF emitido', 'gac') }}
                </a>
                {% if has_signed %}
                    <a class="btn btn-outline-success" href="{{ signed_url }}" target="_blank" rel="noopener">
                        <i class="ti ti-signature"></i> {{ __('Baixar PDF assinado', 'gac') }}
                    </a>
                {% endif %}
                <span class="text-muted small ms-2">
                    {{ __('Assinam:', 'gac') }} {{ laudo.fields['director_ti_name'] }} ({{ laudo.fields['director_ti_role'] }}) {{ __('e', 'gac') }} {{ laudo.fields['director_adm_name'] }} ({{ laudo.fields['director_adm_role'] }})
                </span>
            </div>
        </div>
    {% endif %}

    {% if status_value == 'draft' and can_issue %}
        <div class="card border mb-4">
            <div class="card-header bg-body-tertiary">
                <div>
                    <h4 class="card-title mb-1"><i class="ti ti-send me-2"></i>{{ __('Emitir para assinatura', 'gac') }}</h4>
                    <div class="text-muted small">{{ __('Gera o PDF definitivo, guarda os signatários e move os ativos para "em processo de baixa".', 'gac') }}</div>
                </div>
            </div>
            <div class="card-body">
                {% if problems is not empty %}
                    <div class="alert alert-warning">
                        <div class="mb-1"><strong>{{ __('Antes de emitir:', 'gac') }}</strong></div>
                        <ul class="mb-0">{% for problem in problems %}<li>{{ problem }}</li>{% endfor %}</ul>
                    </div>
                {% endif %}
                <form method="post" action="{{ step_url }}">
                    <input type="hidden" name="_glpi_csrf_token" value="{{ csrf_token() }}">
                    <input type="hidden" name="ltbp_id" value="{{ laudo.getID() }}">
                    <button type="submit" name="issue" value="1" class="btn btn-primary"{% if problems is not empty %} disabled{% endif %}
                            onclick="return confirm('{{ __('Emitir o laudo? Os ativos passam para "em processo de baixa" e o PDF fica congelado.', 'gac')|e('js') }}');">
                        <i class="ti ti-send"></i> {{ __('Emitir laudo', 'gac') }}
                    </button>
                </form>
            </div>
        </div>
    {% endif %}

    {# gac:steps — the forms of the later steps (signed PDF, send, write-off, completion) go here #}

    {% if can_cancel %}
        <div class="card border border-danger-subtle mb-4">
            <div class="card-header bg-body-tertiary">
                <div>
                    <h4 class="card-title mb-1"><i class="ti ti-ban me-2"></i>{{ __('Cancelar o laudo', 'gac') }}</h4>
                    <div class="text-muted small">{{ __('Só é possível antes da confirmação da baixa. Os ativos voltam ao status que tinham antes da emissão.', 'gac') }}</div>
                </div>
            </div>
            <div class="card-body">
                <form method="post" action="{{ step_url }}" class="d-flex flex-wrap gap-2">
                    <input type="hidden" name="_glpi_csrf_token" value="{{ csrf_token() }}">
                    <input type="hidden" name="ltbp_id" value="{{ laudo.getID() }}">
                    <input type="text" class="form-control w-auto flex-grow-1" name="reason" placeholder="{{ __('Motivo do cancelamento', 'gac') }}" required>
                    <button type="submit" name="cancel" value="1" class="btn btn-outline-danger"
                            onclick="return confirm('{{ __('Cancelar este laudo?', 'gac')|e('js') }}');">
                        <i class="ti ti-ban"></i> {{ __('Cancelar laudo', 'gac') }}
                    </button>
                </form>
            </div>
        </div>
    {% endif %}
</div>
```

- [ ] **Step 5: Criar o tratador das ações do ciclo**

Create `front/ltbp/ltbpstep.form.php`:

```php
<?php

use GlpiPlugin\Gac\Ltbp\IssueService;
use GlpiPlugin\Gac\Ltbp\Ltbp;
use GlpiPlugin\Gac\Shared\ServiceResult;

$laudo   = new Ltbp();
$laudoId = (int) ($_POST['ltbp_id'] ?? 0);
if ($laudoId === 0 || !$laudo->getFromDB($laudoId)) {
    Html::displayNotFoundError();
}
if (!$laudo->canViewItem()) {
    Html::displayRightError();
}

$need = static function (int $bit): void {
    if (!Session::haveRight(Ltbp::$rightname, $bit)) {
        Html::displayRightError();
    }
};

$notify = static function (ServiceResult $r): void {
    Session::addMessageAfterRedirect(htmlescape($r->message), false, $r->ok ? INFO : ERROR);
};

if (isset($_POST['issue'])) {
    $need(Ltbp::RIGHT_ISSUE);
    $notify(IssueService::issue($laudo));
} elseif (isset($_POST['cancel'])) {
    $need(Ltbp::RIGHT_CANCEL);
    $notify(IssueService::cancel($laudo, (string) ($_POST['reason'] ?? '')));
}

Html::back();
```

- [ ] **Step 6: Lint, limpar o cache e verificar**

```bash
for f in src/Ltbp/LtbpGuard.php src/Ltbp/IssueService.php src/Ltbp/LtbpProgress.php src/Ltbp/Ltbp.php front/ltbp/ltbpstep.form.php; do /c/xampp/php/php.exe -l "$f"; done
cd /c/Users/juliano/VSCode/glpi-xampp-dev-plugin && /c/xampp/php/php.exe bin/console cache:clear && cd -
```
No navegador (perfil com "Emitir e avançar etapas" e "Cancelar", já concedidos ao perfil na Tarefa 5), num laudo em rascunho com 2 ativos:
1. Aba **Andamento**: o stepper mostra "Rascunho" como atual. Se a configuração estiver incompleta (esvazie um dos diretores) o cartão "Emitir" lista o problema e o botão fica desabilitado; restaure. Se um ativo estiver sem motivo, o problema "Escolha um motivo ativo…" aparece.
2. Com tudo certo, **Emitir laudo** (confirme o aviso). Expected: mensagem "Laudo emitido para assinatura"; status **Aguardando assinaturas**; o cartão "Documentos" mostra "Baixar PDF emitido" (o PDF sem marca d'água, com os diretores da configuração, e o mesmo em toda nova abertura); os dois ativos estão no status **Ativo em processo de baixa** (abra um deles: aba Histórico registra a mudança); a aba **Documentos** do laudo lista o PDF; aba **Itens** mostra os motivos como texto (não select); a aba **Histórico** traz "Laudo emitido para assinatura".
3. Troque o nome do Diretor de TI na configuração e reabra o PDF emitido: continua com o nome antigo. Edite o título de um motivo usado: a aba Itens e o PDF emitido continuam com o texto antigo.
4. **Cancelar o laudo** com um motivo: os dois ativos voltam ao status anterior; o laudo fica **Cancelado** com o aviso vermelho e o motivo. Os dois ativos voltam a aparecer nos candidatos de um laudo novo (se estavam em "Aguardando baixa").
5. Emita outro laudo, mude à mão o status de um ativo, e cancele: mensagem de aviso "o status foi alterado à mão e não foi restaurado" para esse ativo; o outro é restaurado.
6. Erro de PDF: renomeie temporariamente `vendor/` para `vendor_off/`, tente emitir um laudo válido. Expected: a emissão **falha inteira** com a mensagem do mPDF, o laudo continua em rascunho e os ativos **não** mudaram de status. Restaure `vendor/`.

- [ ] **Step 7: Commit**

Invoque o skill `/commit` (tipo `feat`, por exemplo "add LTBP issue, cancel and progress tab").

---

### Task 11: Assinado, envio ao patrimônio, baixa e conclusão

**Files:**
- Create: `src/Ltbp/StepService.php`
- Modify: `src/Shared/DocumentStore.php` (método `collect`), `templates/ltbp/progress.html.twig`, `front/ltbp/ltbpstep.form.php`

**Interfaces:**
- Consumes: `Ltbp`, `LtbpEvent`, `LtbpConfig`, `LtbpSettings`, `StateMachine`, `Status`, `LtbpGuard`, `Shared\{DocumentStore, ServiceResult, StateGuard}`, `Supplier`.
- Produces:
  - `DocumentStore::collect(array $files, string $field): list<array{name: string, tmp_name: string, error: int}>` (lê `$_FILES[$field]` no formato de vários arquivos, pulando os vazios)
  - `StepService::attachSigned(Ltbp $laudo, ?array $file): ServiceResult`
  - `StepService::sendToPatrimony(Ltbp $laudo, array $data): ServiceResult` (`$data['date_sent']` obrigatório `Y-m-d`; `$data['received_by']` opcional)
  - `StepService::confirmWriteoff(Ltbp $laudo, array $data, array $files): ServiceResult` (`$data['date_written_off']` obrigatório; `$data['writeoff_process_number']` e `$data['writeoff_notes']` opcionais; `$files` opcionais)
  - `StepService::complete(Ltbp $laudo, array $data, array $files): ServiceResult` (`$data['date_completed']` e `$data['suppliers_id']` obrigatórios; `$data['completion_notes']` opcional; `$files` obrigatórios se `ltbp_completion_require_document` estiver ligado)
  - Chaves de `POST` em `ltbpstep.form.php`: `upload_signed`, `send_patrimony`, `confirm_writeoff`, `complete`

- [ ] **Step 1: Ler os arquivos enviados (auxiliar em `Shared`)**

Modify `src/Shared/DocumentStore.php`: acrescente este método à classe:

```php
    /**
     * Reads the multi-file input $_FILES[$field] (name="field[]") into a flat list, skipping empty slots.
     *
     * @param array<string, mixed> $files usually $_FILES
     * @return list<array{name: string, tmp_name: string, error: int}>
     */
    public static function collect(array $files, string $field): array
    {
        $raw = $files[$field] ?? null;
        if (!is_array($raw) || !is_array($raw['name'] ?? null)) {
            return [];
        }

        $out = [];
        foreach ($raw['name'] as $i => $name) {
            $error = (int) ($raw['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = [
                'name'     => (string) $name,
                'tmp_name' => (string) ($raw['tmp_name'][$i] ?? ''),
                'error'    => $error,
            ];
        }
        return $out;
    }
```

- [ ] **Step 2: Criar `StepService`**

Create `src/Ltbp/StepService.php`:

```php
<?php

namespace GlpiPlugin\Gac\Ltbp;

use GlpiPlugin\Gac\Shared\DocumentStore;
use GlpiPlugin\Gac\Shared\ServiceResult;
use GlpiPlugin\Gac\Shared\StateGuard;
use Session;
use Supplier;
use Toolbox;

/**
 * The steps after the emission (spec section 6): signed PDF, sending to the patrimony,
 * write-off confirmation and completion. Each one checks the status with the StateMachine.
 */
final class StepService
{
    private const TEXT_MAX = 255;

    /** @param array{name: string, tmp_name: string, error: int}|null $file */
    public static function attachSigned(Ltbp $laudo, ?array $file): ServiceResult
    {
        if (!StateMachine::canUploadSigned($laudo->getStatus())) {
            return ServiceResult::fail(__('O PDF assinado só pode ser anexado antes do envio ao patrimônio.', 'gac'));
        }
        if ($file === null) {
            return ServiceResult::fail(__('Escolha o arquivo do laudo assinado.', 'gac'));
        }

        try {
            $documentId = DocumentStore::attachUpload(
                $file,
                sprintf('%s (%s)', $laudo->fields['number'], __('assinado', 'gac')),
                (int) $laudo->fields['entities_id'],
                Ltbp::class,
                (int) $laudo->getID()
            );
        } catch (\RuntimeException $e) {
            return ServiceResult::fail($e->getMessage());
        }

        // The old file stays in the Documents tab; only the pointer moves (plan decision 7).
        $replaced = (int) $laudo->fields['documents_id_signed'] > 0;
        $laudo->changeStatus(Status::Signed, [
            'documents_id_signed' => $documentId,
            'date_signed'         => date('Y-m-d'),
        ]);
        LtbpEvent::log((int) $laudo->getID(), 'signed_uploaded', '', ['documents_id' => $documentId, 'replaced' => $replaced]);

        return ServiceResult::ok($replaced ? __('PDF assinado substituído.', 'gac') : __('PDF assinado anexado.', 'gac'));
    }

    /** @param array<string, mixed> $data */
    public static function sendToPatrimony(Ltbp $laudo, array $data): ServiceResult
    {
        if (!StateMachine::canSendToPatrimony($laudo->getStatus())) {
            return ServiceResult::fail(__('Só é possível enviar ao patrimônio um laudo assinado.', 'gac'));
        }
        if ((int) $laudo->fields['documents_id_signed'] <= 0) {
            return ServiceResult::fail(__('Anexe o PDF assinado antes de enviar ao patrimônio.', 'gac'));
        }
        $date = self::date((string) ($data['date_sent'] ?? ''));
        if ($date === null) {
            return ServiceResult::fail(__('Informe a data do envio.', 'gac'));
        }

        $receivedBy = mb_substr(trim((string) ($data['received_by'] ?? '')), 0, self::TEXT_MAX);
        $laudo->changeStatus(Status::AtPatrimony, ['date_sent_patrimony' => $date, 'received_by' => $receivedBy]);
        LtbpEvent::log((int) $laudo->getID(), 'sent_to_patrimony', $receivedBy === '' ? '' : sprintf(__('Recebido por %s', 'gac'), $receivedBy));

        return ServiceResult::ok(__('Laudo enviado ao patrimônio.', 'gac'));
    }

    /**
     * @param array<string, mixed> $data
     * @param list<array{name: string, tmp_name: string, error: int}> $files optional proof of the write-off
     */
    public static function confirmWriteoff(Ltbp $laudo, array $data, array $files = []): ServiceResult
    {
        global $DB;

        if (!StateMachine::canConfirmWriteoff($laudo->getStatus())) {
            return ServiceResult::fail(__('Só é possível confirmar a baixa de um laudo que está no patrimônio.', 'gac'));
        }
        $date = self::date((string) ($data['date_written_off'] ?? ''));
        if ($date === null) {
            return ServiceResult::fail(__('Informe a data da baixa.', 'gac'));
        }
        $stateOut = LtbpSettings::stateId(LtbpConfig::load(), 'written_off');
        if ($stateOut <= 0) {
            return ServiceResult::fail(__('Mapeie o status "Ativo baixado" na configuração do LTBP.', 'gac'));
        }

        try {
            $DB->beginTransaction();

            // The assets change status first, while the laudo is still not "Baixado", so the lock
            // never sees them; LtbpGuard makes the pass explicit (plan decision 11).
            LtbpGuard::run(static function () use ($laudo, $stateOut): void {
                foreach ($laudo->lines() as $line) {
                    $asset = getItemForItemtype((string) $line['itemtype']);
                    if (!$asset || !$asset->getFromDB((int) $line['items_id'])) {
                        throw new \RuntimeException(sprintf(__('%s: ativo não encontrado.', 'gac'), $line['item_name']));
                    }
                    if (!StateGuard::isUsable($stateOut, (int) ($asset->fields['entities_id'] ?? 0))) {
                        throw new \RuntimeException(sprintf(
                            __('%s: o status "baixado" não vale para a entidade deste ativo.', 'gac'),
                            $line['item_name']
                        ));
                    }
                    if ((int) ($asset->fields['states_id'] ?? 0) !== $stateOut
                        && !$asset->update(['id' => $asset->getID(), 'states_id' => $stateOut])) {
                        throw new \RuntimeException(sprintf(__('%s: não foi possível alterar o status do ativo.', 'gac'), $line['item_name']));
                    }
                }
            });

            $laudo->changeStatus(Status::WrittenOff, [
                'date_written_off'        => $date,
                // Optional (spec L11): today the number does not exist.
                'writeoff_process_number' => mb_substr(trim((string) ($data['writeoff_process_number'] ?? '')), 0, self::TEXT_MAX),
                'writeoff_notes'          => trim((string) ($data['writeoff_notes'] ?? '')),
            ]);
            LtbpEvent::log((int) $laudo->getID(), 'written_off');

            $DB->commit();
        } catch (\Throwable $e) {
            try {
                $DB->rollBack();
            } catch (\Throwable) {
                // not in a transaction
            }
            $laudo->getFromDB((int) $laudo->getID());
            Toolbox::logInFile('gac', sprintf("confirmWriteoff %d failed: %s\n", $laudo->getID(), $e->getMessage()));
            return ServiceResult::fail($e->getMessage());
        }
        WrittenOffLock::flush();

        // After the commit: a rejected file must not undo a write-off that already changed the assets (PRE D19).
        $attached = self::attachAll($laudo, $files, __('baixa', 'gac'));
        $message  = __('Baixa confirmada. Os ativos ficam bloqueados para edição.', 'gac');
        if ($attached['problems'] !== []) {
            $message .= ' ' . __('Nem todos os documentos foram anexados:', 'gac') . ' ' . implode(' ', $attached['problems']);
        }
        return ServiceResult::ok($message);
    }

    /**
     * @param array<string, mixed> $data
     * @param list<array{name: string, tmp_name: string, error: int}> $files proof: certificate of destination or donation term
     */
    public static function complete(Ltbp $laudo, array $data, array $files = []): ServiceResult
    {
        if (!StateMachine::canComplete($laudo->getStatus())) {
            return ServiceResult::fail(__('Só é possível concluir um laudo com a baixa confirmada.', 'gac'));
        }
        $date = self::date((string) ($data['date_completed'] ?? ''));
        if ($date === null) {
            return ServiceResult::fail(__('Informe a data da execução.', 'gac'));
        }

        // The dropdown lists active suppliers of the laudo's entity; the server re-checks that, and the
        // entity access instead of the user's own right on suppliers (a technician may not have it).
        $supplier    = new Supplier();
        $suppliersId = (int) ($data['suppliers_id'] ?? 0);
        if (
            $suppliersId <= 0
            || !$supplier->getFromDB($suppliersId)
            || (int) $supplier->fields['is_active'] !== 1
            || !Session::haveAccessToEntity((int) $supplier->fields['entities_id'], (bool) $supplier->fields['is_recursive'])
        ) {
            return ServiceResult::fail(__('Escolha o beneficiário: um fornecedor ativo (a empresa recicladora ou o donatário).', 'gac'));
        }

        // The proof is stored BEFORE the laudo moves on, so "obrigatório" really is (plan decision 10).
        $attached = self::attachAll($laudo, $files, __('conclusão', 'gac'));
        if (LtbpSettings::requireCompletionDocument(LtbpConfig::load()) && $attached['count'] === 0) {
            return ServiceResult::fail(
                $attached['problems'] !== []
                    ? implode(' ', $attached['problems'])
                    : __('Anexe o comprovante (certificado de destinação ou termo de doação).', 'gac')
            );
        }

        $laudo->changeStatus(Status::Completed, [
            'date_completed'   => $date,
            'suppliers_id'     => $suppliersId,
            'supplier_name'    => (string) $supplier->fields['name'],
            'completion_notes' => trim((string) ($data['completion_notes'] ?? '')),
        ]);
        LtbpEvent::log((int) $laudo->getID(), 'completed', (string) $supplier->fields['name']);
        WrittenOffLock::flush();

        $message = __('Destinação concluída. O laudo está encerrado.', 'gac');
        if ($attached['problems'] !== []) {
            $message .= ' ' . __('Nem todos os documentos foram anexados:', 'gac') . ' ' . implode(' ', $attached['problems']);
        }
        return ServiceResult::ok($message);
    }

    /**
     * Stores each file as a Document linked to the laudo.
     *
     * @param list<array{name: string, tmp_name: string, error: int}> $files
     * @return array{count: int, problems: list<string>}
     */
    private static function attachAll(Ltbp $laudo, array $files, string $kind): array
    {
        $count    = 0;
        $problems = [];
        foreach ($files as $file) {
            try {
                $id = DocumentStore::attachUpload(
                    $file,
                    sprintf('%s - %s - %s', $laudo->fields['number'], $kind, pathinfo(basename($file['name']), PATHINFO_FILENAME)),
                    (int) $laudo->fields['entities_id'],
                    Ltbp::class,
                    (int) $laudo->getID()
                );
                LtbpEvent::log((int) $laudo->getID(), 'document_attached', basename($file['name']), ['documents_id' => $id]);
                $count++;
            } catch (\Throwable $e) {
                Toolbox::logInFile('gac', sprintf("attach %s failed: %s\n", $file['name'], $e->getMessage()));
                $problems[] = $e->getMessage();
            }
        }
        return ['count' => $count, 'problems' => $problems];
    }

    /** A valid Y-m-d date, or null. */
    private static function date(string $value): ?string
    {
        $value = trim($value);
        $d     = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        return ($d !== false && $d->format('Y-m-d') === $value) ? $value : null;
    }
}
```

`WrittenOffLock::flush()` é criada na Tarefa 13; até lá, este arquivo referencia uma classe que ainda não existe. **Para a Tarefa 11 ficar verificável sozinha, crie agora o esqueleto da classe** (a Tarefa 13 o completa):

Create `src/Ltbp/WrittenOffLock.php`:

```php
<?php

namespace GlpiPlugin\Gac\Ltbp;

/**
 * Whether an asset is locked (its laudo is Baixado or Concluído, spec L14). Cached per request;
 * flush() after a service changes a laudo's status.
 */
final class WrittenOffLock
{
    /** @var array<string, bool> */
    private static array $cache = [];

    public static function flush(): void
    {
        self::$cache = [];
    }
}
```

- [ ] **Step 3: Acrescentar os formulários das etapas à aba Andamento**

Modify `templates/ltbp/progress.html.twig`: substitua a linha do comentário `{# gac:steps — ... #}` por estes quatro blocos (na ordem):

```twig
    {% if can_upload_signed %}
        <div class="card border mb-4">
            <div class="card-header bg-body-tertiary">
                <div>
                    <h4 class="card-title mb-1"><i class="ti ti-signature me-2"></i>{{ __('PDF assinado', 'gac') }}</h4>
                    <div class="text-muted small">{{ __('Depois que os diretores assinarem o papel, anexe o arquivo escaneado. Até o envio ao patrimônio é possível substituí-lo.', 'gac') }}</div>
                </div>
            </div>
            <div class="card-body">
                <form method="post" action="{{ step_url }}" enctype="multipart/form-data" class="d-flex flex-wrap gap-2">
                    <input type="hidden" name="_glpi_csrf_token" value="{{ csrf_token() }}">
                    <input type="hidden" name="ltbp_id" value="{{ laudo.getID() }}">
                    <input type="file" class="form-control w-auto flex-grow-1" name="signed_file[]" accept=".pdf,.jpg,.jpeg,.png" required>
                    <button type="submit" name="upload_signed" value="1" class="btn btn-primary">
                        <i class="ti ti-upload"></i> {{ has_signed ? __('Substituir PDF assinado', 'gac') : __('Anexar PDF assinado', 'gac') }}
                    </button>
                </form>
            </div>
        </div>
    {% endif %}

    {% if can_send %}
        <div class="card border mb-4">
            <div class="card-header bg-body-tertiary">
                <div>
                    <h4 class="card-title mb-1"><i class="ti ti-building-warehouse me-2"></i>{{ __('Enviar ao patrimônio', 'gac') }}</h4>
                    <div class="text-muted small">{{ __('Registre quando o documento assinado foi entregue ao setor de patrimônio.', 'gac') }}</div>
                </div>
            </div>
            <div class="card-body">
                <form method="post" action="{{ step_url }}" class="row g-2">
                    <input type="hidden" name="_glpi_csrf_token" value="{{ csrf_token() }}">
                    <input type="hidden" name="ltbp_id" value="{{ laudo.getID() }}">
                    <div class="col-md-3">
                        <label class="form-label">{{ __('Data do envio', 'gac') }}</label>
                        <input type="date" class="form-control" name="date_sent" value="{{ today }}" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ __('Recebido por (opcional)', 'gac') }}</label>
                        <input type="text" class="form-control" name="received_by" maxlength="255">
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" name="send_patrimony" value="1" class="btn btn-primary">{{ __('Registrar envio', 'gac') }}</button>
                    </div>
                </form>
            </div>
        </div>
    {% endif %}

    {% if can_confirm %}
        <div class="card border mb-4">
            <div class="card-header bg-body-tertiary">
                <div>
                    <h4 class="card-title mb-1"><i class="ti ti-circle-check me-2"></i>{{ __('Confirmar a baixa', 'gac') }}</h4>
                    <div class="text-muted small">{{ __('Quando o patrimônio baixar os bens no sistema dele. Os ativos passam para "baixado" e ficam bloqueados para edição. Não há como desfazer.', 'gac') }}</div>
                </div>
            </div>
            <div class="card-body">
                <form method="post" action="{{ step_url }}" enctype="multipart/form-data" class="row g-2">
                    <input type="hidden" name="_glpi_csrf_token" value="{{ csrf_token() }}">
                    <input type="hidden" name="ltbp_id" value="{{ laudo.getID() }}">
                    <div class="col-md-3">
                        <label class="form-label">{{ __('Data da baixa', 'gac') }}</label>
                        <input type="date" class="form-control" name="date_written_off" value="{{ today }}" required>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">{{ __('Nº do processo de baixa (opcional)', 'gac') }}</label>
                        <input type="text" class="form-control" name="writeoff_process_number" maxlength="255">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">{{ __('Comprovante (opcional)', 'gac') }}</label>
                        <input type="file" class="form-control" name="attachments[]" multiple>
                    </div>
                    <div class="col-12">
                        <label class="form-label">{{ __('Observação (opcional)', 'gac') }}</label>
                        <textarea class="form-control" name="writeoff_notes" rows="2"></textarea>
                    </div>
                    <div class="col-12">
                        <button type="submit" name="confirm_writeoff" value="1" class="btn btn-primary"
                                onclick="return confirm('{{ __('Confirmar a baixa? Os ativos ficam bloqueados para edição e isto não pode ser desfeito.', 'gac')|e('js') }}');">
                            <i class="ti ti-circle-check"></i> {{ __('Confirmar baixa', 'gac') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    {% endif %}

    {% if can_complete %}
        <div class="card border mb-4">
            <div class="card-header bg-body-tertiary">
                <div>
                    <h4 class="card-title mb-1"><i class="ti ti-recycle me-2"></i>{{ __('Concluir a destinação', 'gac') }}</h4>
                    <div class="text-muted small">{{ __('Registre a execução do descarte ou da doação. O beneficiário é a empresa recicladora ou o donatário, cadastrado como fornecedor.', 'gac') }}</div>
                </div>
            </div>
            <div class="card-body">
                <form method="post" action="{{ step_url }}" enctype="multipart/form-data" class="row g-2">
                    <input type="hidden" name="_glpi_csrf_token" value="{{ csrf_token() }}">
                    <input type="hidden" name="ltbp_id" value="{{ laudo.getID() }}">
                    <div class="col-md-3">
                        <label class="form-label">{{ __('Data da execução', 'gac') }}</label>
                        <input type="date" class="form-control" name="date_completed" value="{{ today }}" required>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">{{ __('Beneficiário (fornecedor)', 'gac') }}</label>
                        {{ supplier_dropdown|raw }}
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">{{ require_document ? __('Comprovante (obrigatório)', 'gac') : __('Comprovante (opcional)', 'gac') }}</label>
                        <input type="file" class="form-control" name="attachments[]" multiple{% if require_document %} required{% endif %}>
                        <div class="form-text">{{ __('Certificado de destinação ou termo de doação.', 'gac') }}</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">{{ __('Observação (opcional)', 'gac') }}</label>
                        <textarea class="form-control" name="completion_notes" rows="2"></textarea>
                    </div>
                    <div class="col-12">
                        <button type="submit" name="complete" value="1" class="btn btn-success">
                            <i class="ti ti-recycle"></i> {{ __('Concluir laudo', 'gac') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    {% endif %}

    {% if status_value in ['written_off', 'completed'] %}
        <div class="card border mb-4">
            <div class="card-header bg-body-tertiary">
                <h4 class="card-title mb-0"><i class="ti ti-info-circle me-2"></i>{{ __('Registro', 'gac') }}</h4>
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    {% if laudo.fields['received_by'] %}<dt class="col-sm-3">{{ __('Recebido no patrimônio por', 'gac') }}</dt><dd class="col-sm-9">{{ laudo.fields['received_by'] }}</dd>{% endif %}
                    <dt class="col-sm-3">{{ __('Nº do processo de baixa', 'gac') }}</dt><dd class="col-sm-9">{{ laudo.fields['writeoff_process_number'] ?: '—' }}</dd>
                    {% if laudo.fields['writeoff_notes'] %}<dt class="col-sm-3">{{ __('Observação da baixa', 'gac') }}</dt><dd class="col-sm-9">{{ laudo.fields['writeoff_notes'] }}</dd>{% endif %}
                    {% if status_value == 'completed' %}
                        <dt class="col-sm-3">{{ __('Beneficiário', 'gac') }}</dt><dd class="col-sm-9">{{ laudo.fields['supplier_name'] }}</dd>
                        {% if laudo.fields['completion_notes'] %}<dt class="col-sm-3">{{ __('Observação da conclusão', 'gac') }}</dt><dd class="col-sm-9">{{ laudo.fields['completion_notes'] }}</dd>{% endif %}
                    {% endif %}
                </dl>
            </div>
        </div>
    {% endif %}
```

- [ ] **Step 4: Ligar as ações no tratador**

Modify `front/ltbp/ltbpstep.form.php`: acrescente aos `use`: `use GlpiPlugin\Gac\Ltbp\StepService;` e `use GlpiPlugin\Gac\Shared\DocumentStore;`. Troque o bloco `if (isset($_POST['issue'])) { ... } elseif (isset($_POST['cancel'])) { ... }` por:

```php
if (isset($_POST['issue'])) {
    $need(Ltbp::RIGHT_ISSUE);
    $notify(IssueService::issue($laudo));
} elseif (isset($_POST['cancel'])) {
    $need(Ltbp::RIGHT_CANCEL);
    $notify(IssueService::cancel($laudo, (string) ($_POST['reason'] ?? '')));
} elseif (isset($_POST['upload_signed'])) {
    $need(Ltbp::RIGHT_ISSUE);
    $notify(StepService::attachSigned($laudo, DocumentStore::collect($_FILES, 'signed_file')[0] ?? null));
} elseif (isset($_POST['send_patrimony'])) {
    $need(Ltbp::RIGHT_ISSUE);
    $notify(StepService::sendToPatrimony($laudo, $_POST));
} elseif (isset($_POST['confirm_writeoff'])) {
    $need(Ltbp::RIGHT_ISSUE);
    $notify(StepService::confirmWriteoff($laudo, $_POST, DocumentStore::collect($_FILES, 'attachments')));
} elseif (isset($_POST['complete'])) {
    $need(Ltbp::RIGHT_ISSUE);
    $notify(StepService::complete($laudo, $_POST, DocumentStore::collect($_FILES, 'attachments')));
}
```

- [ ] **Step 5: Lint, limpar o cache e verificar**

```bash
for f in src/Shared/DocumentStore.php src/Ltbp/StepService.php src/Ltbp/WrittenOffLock.php front/ltbp/ltbpstep.form.php; do /c/xampp/php/php.exe -l "$f"; done
cd /c/Users/juliano/VSCode/glpi-xampp-dev-plugin && /c/xampp/php/php.exe bin/console cache:clear && cd -
```
Cadastre um fornecedor **ativo** ("Recicladora Teste", com `is_active` marcado pela interface). Com um laudo emitido (status **Aguardando assinaturas**):
1. Aba **Andamento**: formulário "PDF assinado". Anexe um PDF qualquer: status **Assinado**, botão "Baixar PDF assinado" abre o arquivo; a aba **Documentos** lista os dois PDFs; o Histórico registra o evento. Substitua o arquivo: mensagem "PDF assinado substituído".
2. Formulário "Enviar ao patrimônio": envie sem data (o campo `required` do navegador impede); envie com data e "Recebido por": status **No patrimônio**. Não há mais formulário de anexar/substituir.
3. "Confirmar a baixa" com data, sem número de processo (deixe em branco: aceito) e sem anexo: aceita o aviso, status **Baixado**; os ativos ficam no status **Ativo baixado**; o cartão "Registro" mostra "Nº do processo de baixa: —". O botão "Cancelar laudo" **não** aparece mais. (O bloqueio de edição do ativo só entra na Tarefa 13.)
4. "Concluir a destinação": tente sem arquivo (o `required` do navegador impede); com `ltbp_completion_require_document` desligado na configuração o campo deixa de ser obrigatório. Ligue de novo, escolha o fornecedor, a data e anexe um comprovante: status **Concluído**, o cartão "Registro" mostra o beneficiário, o comprovante está na aba Documentos, o stepper marca tudo como concluído.
5. Pule uma etapa forçando o `POST` (por exemplo, com as ferramentas do navegador, envie `send_patrimony` num laudo em rascunho): mensagem "Só é possível enviar ao patrimônio um laudo assinado", nada muda.
6. Perfil **sem** "Emitir e avançar etapas" (crie um perfil de teste só com Ler): a aba Andamento mostra o stepper e os documentos, sem nenhum formulário de ação.

- [ ] **Step 6: Commit**

Invoque o skill `/commit` (tipo `feat`, por exemplo "add LTBP signed, patrimony, write-off and completion steps").

---

### Task 12: Acompanhamentos no ticket de origem (L25)

**Files:**
- Create: `src/Ltbp/TicketNotes.php`
- Modify: `src/Ltbp/IssueService.php`, `src/Ltbp/StepService.php`

**Interfaces:**
- Consumes: `Ltbp`, `LtbpItem`, `LtbpConfig`, `LtbpSettings`, `TicketSolvePolicy` (Tarefa 4), `Labels`, `Status`, `Shared\TicketOps::followup()/solve()`; do PRE, só leitura: `Pre\RepairProtocolItem::getTable()`, `Pre\ItemStatus`.
- Produces: `TicketNotes::milestone(Ltbp $laudo, string $kind): list<string>` (`$kind` = `issued`, `written_off` ou `completed`; devolve avisos traduzidos, vazio quando tudo deu certo).

- [ ] **Step 1: Criar `TicketNotes`**

Create `src/Ltbp/TicketNotes.php`:

```php
<?php

namespace GlpiPlugin\Gac\Ltbp;

use GlpiPlugin\Gac\Pre\ItemStatus as PreItemStatus;
use GlpiPlugin\Gac\Pre\RepairProtocolItem;
use GlpiPlugin\Gac\Shared\TicketOps;
use Ticket;
use Toolbox;

/**
 * Follow-ups on the source ticket at the laudo's milestones, and the optional solution when the
 * laudo completes (spec L25). Only lines that came from a PRE have a ticket. It runs after the
 * commit of each step, so a ticket problem is a warning, never an undo (PRE D19 principle).
 */
final class TicketNotes
{
    /** @return list<string> warnings */
    public static function milestone(Ltbp $laudo, string $kind): array
    {
        $warnings = [];
        $settings = LtbpConfig::load();

        foreach (self::assetsByTicket($laudo) as $ticketId => $assets) {
            try {
                $ticket = new Ticket();
                if (!$ticket->getFromDB($ticketId) || self::isSolvedOrClosed($ticket)) {
                    continue; // a ticket already closed by hand is ignored without error (plan decision 16)
                }

                TicketOps::followup($ticket, self::text($laudo, $kind, $assets));

                if (
                    $kind === 'completed'
                    && TicketSolvePolicy::shouldSolve(
                        LtbpSettings::solveTicketOnCompletion($settings),
                        self::otherOpenLines($laudo, $ticketId)
                    )
                ) {
                    TicketOps::solve($ticket, sprintf(
                        __('Baixa patrimonial concluída no laudo %1$s: %2$s.', 'gac'),
                        $laudo->fields['number'],
                        implode(', ', $assets)
                    ));
                }
            } catch (\Throwable $e) {
                Toolbox::logInFile('gac', sprintf("ticket note #%d (%s) failed: %s\n", $ticketId, $kind, $e->getMessage()));
                $warnings[] = sprintf(__('Ticket #%1$d: %2$s', 'gac'), $ticketId, $e->getMessage());
            }
        }

        return $warnings;
    }

    /** @return array<int, list<string>> ticket id => names of the laudo's assets that came from it */
    private static function assetsByTicket(Ltbp $laudo): array
    {
        $byTicket = [];
        foreach ($laudo->lines() as $line) {
            if ((int) $line['tickets_id'] > 0) {
                $byTicket[(int) $line['tickets_id']][] = (string) $line['item_name'];
            }
        }
        return $byTicket;
    }

    private static function isSolvedOrClosed(Ticket $ticket): bool
    {
        return in_array((int) $ticket->fields['status'], [Ticket::SOLVED, Ticket::CLOSED], true);
    }

    /** @param list<string> $assets */
    private static function text(Ltbp $laudo, string $kind, array $assets): string
    {
        $list   = implode(', ', $assets);
        $number = (string) $laudo->fields['number'];

        return match ($kind) {
            'issued'      => sprintf(__('Equipamento(s) %1$s incluído(s) no laudo de baixa patrimonial %2$s, emitido para assinatura.', 'gac'), $list, $number),
            'written_off' => sprintf(__('Baixa patrimonial confirmada pelo patrimônio para %1$s (laudo %2$s).', 'gac'), $list, $number),
            'completed'   => sprintf(
                __('Destinação concluída para %1$s (laudo %2$s): %3$s. Beneficiário: %4$s.', 'gac'),
                $list,
                $number,
                $laudo->getDestination() === null ? '' : Labels::destination($laudo->getDestination()),
                (string) $laudo->fields['supplier_name']
            ),
            default       => $list,
        };
    }

    /**
     * Lines of the same ticket that are still open elsewhere: active PRE lines (any PRE) and lines
     * of other laudos that are not completed or canceled. While there are any, the ticket is not
     * solved (same idea as PRE D17).
     */
    private static function otherOpenLines(Ltbp $laudo, int $ticketId): int
    {
        global $DB;

        $preLines = countElementsInTable(RepairProtocolItem::getTable(), [
            'tickets_id' => $ticketId,
            'status'     => [
                PreItemStatus::PendingSend->value,
                PreItemStatus::Sending->value,
                PreItemStatus::AtSupplier->value,
            ],
        ]);

        $notFinal = array_values(array_map(
            static fn(Status $s): string => $s->value,
            array_filter(Status::cases(), static fn(Status $s): bool => !$s->isFinal())
        ));
        $items  = LtbpItem::getTable();
        $laudos = Ltbp::getTable();
        $row = $DB->request([
            'COUNT'      => 'cpt',
            'FROM'       => $items,
            'INNER JOIN' => [
                $laudos => ['ON' => [$items => 'plugin_gac_ltbps_id', $laudos => 'id']],
            ],
            'WHERE' => [
                "$items.tickets_id" => $ticketId,
                "$laudos.id"        => ['<>', (int) $laudo->getID()],
                "$laudos.status"    => $notFinal,
            ],
        ])->current();

        return $preLines + (int) ($row['cpt'] ?? 0);
    }
}
```

- [ ] **Step 2: Chamar nos três marcos**

Modify `src/Ltbp/IssueService.php`: no fim de `issue()`, troque a linha final `return ServiceResult::ok(__('Laudo emitido para assinatura.', 'gac'));` por:

```php
        $message = __('Laudo emitido para assinatura.', 'gac');
        $notes   = TicketNotes::milestone($laudo, 'issued');
        if ($notes !== []) {
            $message .= ' ' . __('Avisos nos tickets:', 'gac') . ' ' . implode(' ', $notes);
        }
        return ServiceResult::ok($message);
```

Modify `src/Ltbp/StepService.php`:

1. Em `confirmWriteoff()`, depois de `WrittenOffLock::flush();` e da linha `$attached = self::attachAll(...)`, e antes do `return`, acrescente ao `$message` os avisos do ticket. Troque o trecho

```php
        $message  = __('Baixa confirmada. Os ativos ficam bloqueados para edição.', 'gac');
        if ($attached['problems'] !== []) {
            $message .= ' ' . __('Nem todos os documentos foram anexados:', 'gac') . ' ' . implode(' ', $attached['problems']);
        }
        return ServiceResult::ok($message);
```

por

```php
        $message  = __('Baixa confirmada. Os ativos ficam bloqueados para edição.', 'gac');
        if ($attached['problems'] !== []) {
            $message .= ' ' . __('Nem todos os documentos foram anexados:', 'gac') . ' ' . implode(' ', $attached['problems']);
        }
        $notes = TicketNotes::milestone($laudo, 'written_off');
        if ($notes !== []) {
            $message .= ' ' . __('Avisos nos tickets:', 'gac') . ' ' . implode(' ', $notes);
        }
        return ServiceResult::ok($message);
```

2. Em `complete()`, troque o trecho final

```php
        $message = __('Destinação concluída. O laudo está encerrado.', 'gac');
        if ($attached['problems'] !== []) {
            $message .= ' ' . __('Nem todos os documentos foram anexados:', 'gac') . ' ' . implode(' ', $attached['problems']);
        }
        return ServiceResult::ok($message);
```

por

```php
        $message = __('Destinação concluída. O laudo está encerrado.', 'gac');
        if ($attached['problems'] !== []) {
            $message .= ' ' . __('Nem todos os documentos foram anexados:', 'gac') . ' ' . implode(' ', $attached['problems']);
        }
        $notes = TicketNotes::milestone($laudo, 'completed');
        if ($notes !== []) {
            $message .= ' ' . __('Avisos nos tickets:', 'gac') . ' ' . implode(' ', $notes);
        }
        return ServiceResult::ok($message);
```

- [ ] **Step 3: Lint e verificação manual**

```bash
for f in src/Ltbp/TicketNotes.php src/Ltbp/IssueService.php src/Ltbp/StepService.php; do /c/xampp/php/php.exe -l "$f"; done
```
Prepare o cenário: um PRE com um ticket + ativo (categoria elegível) enviado e devolvido como **Sem conserto**, destino **Encaminhar para baixa** (o ativo vai para "aguardando baixa" e o ticket fica Pendente "Aguardando baixa patrimonial", pelas ações padrão do PRE). Crie outro par igual para um segundo ticket.
1. Num laudo novo, aba Itens, os dois ativos aparecem em "Ativos aguardando baixa" **com o número do PRE e o ticket** na coluna Origem. Adicione ambos. Configure os motivos e emita.
2. Abra os dois tickets: cada um tem um acompanhamento "Equipamento(s) … incluído(s) no laudo … emitido para assinatura", **e o ticket continua Pendente com o mesmo motivo**. Se o status do ticket mudar por causa do acompanhamento, registre no roteiro manual (`docs/ltbp-manual-tests.md`, cenário 30) e ajuste `TicketNotes` para usar `TicketOps::keepPendingWithReason` (o PRE já verificou que o acompanhamento simples mantém o status no caso D17; este passo confirma para o LTBP).
3. Avance até "Baixado": novo acompanhamento nos dois tickets. Conclua (com `ltbp_solve_ticket_on_completion` **desligado**): acompanhamento de conclusão, tickets **não** solucionados.
4. Repita com um laudo novo e a opção **ligada**: ao concluir, os tickets são **solucionados**. Com dois ativos do **mesmo** ticket em laudos diferentes, o primeiro laudo a concluir **não** soluciona o ticket (há linha aberta no outro laudo); o segundo soluciona.
5. Feche um dos tickets à mão antes de emitir: a emissão segue sem erro nem aviso para ele.
6. Um ativo vindo da busca livre (sem ticket): nada é escrito e nada falha.

- [ ] **Step 4: Commit**

Invoque o skill `/commit` (tipo `feat`, por exemplo "add LTBP milestone notes on the source ticket").

---

### Task 13: Bloqueio de edição do ativo baixado (L14)

**Files:**
- Modify: `src/Ltbp/WrittenOffLock.php` (completar), `setup.php`
- Create: `src/Ltbp/AssetUpdateGuard.php`, `var/tools/lock_probe.php` (auxiliar descartável)

**Interfaces:**
- Consumes: `LockPolicy::blockedFields()` (Tarefa 4), `LtbpGuard::isActive()` (Tarefa 10), `AssetTypes::all()`, `Ltbp::RIGHT_EDIT_WRITTEN_OFF`, `Status::lockingValues()`, `LtbpItem`, `Ltbp`.
- Produces: `WrittenOffLock::isLocked(string $itemtype, int $itemsId): bool`; `WrittenOffLock::flush(): void`; `AssetUpdateGuard::itemtypes(): list<string>`; `AssetUpdateGuard::onPreUpdate(CommonDBTM $item): void` (callback do hook `pre_item_update`).

- [ ] **Step 1: Completar `WrittenOffLock`**

Replace o conteúdo de `src/Ltbp/WrittenOffLock.php` por:

```php
<?php

namespace GlpiPlugin\Gac\Ltbp;

/**
 * Whether an asset is locked: it is in a laudo that is Baixado or Concluído (spec L14).
 * Cached per request; flush() after a service changes a laudo's status.
 */
final class WrittenOffLock
{
    /** @var array<string, bool> */
    private static array $cache = [];

    public static function isLocked(string $itemtype, int $itemsId): bool
    {
        global $DB;

        $key = $itemtype . '|' . $itemsId;
        if (!isset(self::$cache[$key])) {
            $items  = LtbpItem::getTable();
            $laudos = Ltbp::getTable();
            $row = $DB->request([
                'COUNT'      => 'cpt',
                'FROM'       => $items,
                'INNER JOIN' => [
                    $laudos => ['ON' => [$items => 'plugin_gac_ltbps_id', $laudos => 'id']],
                ],
                'WHERE' => [
                    "$items.itemtype" => $itemtype,
                    "$items.items_id" => $itemsId,
                    "$laudos.status"  => Status::lockingValues(),
                ],
            ])->current();
            self::$cache[$key] = (int) ($row['cpt'] ?? 0) > 0;
        }
        return self::$cache[$key];
    }

    public static function flush(): void
    {
        self::$cache = [];
    }
}
```

- [ ] **Step 2: Criar o callback do hook**

Create `src/Ltbp/AssetUpdateGuard.php`:

```php
<?php

namespace GlpiPlugin\Gac\Ltbp;

use CommonDBTM;
use Glpi\Asset\AssetDefinition;
use Session;

/**
 * Refuses updates to an asset that was written off (spec L14 and section 8). It is a
 * "pre_item_update" hook: it runs on every ->update() of an asset class, which is the path of the
 * asset form, the REST API, the inventory and GLPI's own "stale agent" action.
 *
 * Custom asset definitions are loaded after the plugins are initialised, so they are not yet in
 * $CFG_GLPI['asset_types'] when the hook is registered: itemtypes() reads them from the definitions
 * table and builds the class name the way AssetDefinition::getCustomObjectClassName() does.
 */
final class AssetUpdateGuard
{
    /** @return list<string> the asset classes the hook is registered for */
    public static function itemtypes(): array
    {
        $types = AssetTypes::all();
        try {
            global $DB;
            $table = AssetDefinition::getTable();
            if ($DB->tableExists($table)) {
                foreach ($DB->request(['SELECT' => ['system_name'], 'FROM' => $table, 'WHERE' => ['is_active' => 1]]) as $row) {
                    $types[] = AssetDefinition::getCustomObjectNamespace() . '\\' . $row['system_name'] . AssetDefinition::getCustomObjectClassSuffix();
                }
            }
        } catch (\Throwable) {
            // Never break the site at init: fall back to the native list.
        }
        return array_values(array_unique($types));
    }

    public static function onPreUpdate(CommonDBTM $item): void
    {
        if (LtbpGuard::isActive() || !is_array($item->input) || $item->input === []) {
            return;
        }
        if (!WrittenOffLock::isLocked($item::class, (int) $item->getID())) {
            return;
        }
        // Only profiles that were granted "Editar ativo baixado" pass. Read the active profile
        // directly: Session::haveRight() must NOT be used, it returns true for inventory, cron,
        // callAsSystem() and disabled rights checks, which are exactly the paths the lock must stop.
        if (((int) ($_SESSION['glpiactiveprofile'][Ltbp::$rightname] ?? 0)) & Ltbp::RIGHT_EDIT_WRITTEN_OFF) {
            return;
        }

        $blocked = LockPolicy::blockedFields($item->input, $item->fields);
        if ($blocked === []) {
            return;
        }

        $item->input = [];
        Session::addMessageAfterRedirect(
            sprintf(
                __('Este ativo foi baixado por um laudo de baixa patrimonial e não pode mais ser editado. Campos recusados: %s.', 'gac'),
                implode(', ', $blocked)
            ),
            false,
            ERROR
        );
    }
}
```

- [ ] **Step 3: Registrar o hook no `setup.php`**

Modify `setup.php`: acrescente `use GlpiPlugin\Gac\Ltbp\AssetUpdateGuard;` aos `use` do topo e, dentro do `if ($plugin->isInstalled('gac') && $plugin->isActivated('gac')) { ... }` de `plugin_init_gac()`, depois da linha do `Plugin::registerClass(ProfileRights::class, ...)`, acrescente:

```php
        // Write-off lock (LTBP spec L14): refuses updates to an asset whose laudo is Baixado or Concluído.
        foreach (AssetUpdateGuard::itemtypes() as $itemtype) {
            $PLUGIN_HOOKS[Hooks::PRE_ITEM_UPDATE]['gac'][$itemtype] = [AssetUpdateGuard::class, 'onPreUpdate'];
        }
```

- [ ] **Step 4: Verificar se a lista de tipos já tem os ativos personalizados na inicialização (R-1)**

O hook é registrado dentro de `plugin_init_gac()`, que pode rodar **antes** de os ativos personalizados entrarem em `$CFG_GLPI['asset_types']`. Confirme com evidência:

1. Acrescente **temporariamente**, logo depois do `foreach` do Step 3:

```php
        Toolbox::logInFile('gac', 'init asset_types: ' . implode(',', AssetUpdateGuard::itemtypes()) . "\n");
```

2. Recarregue qualquer página do GLPI e leia o log:

```bash
tail -n 3 /c/Users/juliano/VSCode/glpi-xampp-dev-plugin/files/_log/gac.log
/c/xampp/php/php.exe var/tools/print_asset_types.php
```
Expected: compare a lista `init asset_types` com a lista `asset_types:` impressa pelo script (que roda **depois** do boot completo) e com as linhas `custom:`.
3. **Se os ativos personalizados (`custom:`) estão na lista do `init`:** nada a fazer. Remova a linha de log temporária.
4. **Se faltam:** complete `AssetUpdateGuard::itemtypes()` com as definições, e mantenha a lista sem duplicatas:

```php
    public static function itemtypes(): array
    {
        $types = AssetTypes::all();
        foreach (\Glpi\Asset\AssetDefinitionManager::getInstance()->getDefinitions(true) as $definition) {
            $types[] = $definition->getAssetClassName();
        }
        return array_values(array_unique($types));
    }
```
   e repita o Step 4.1 e 4.2 até a lista do `init` conter todos os `custom:`. Se ainda faltarem (as definições também não estão prontas nesse ponto), **pare e registre** o resultado em `docs/ltbp-manual-tests.md` (cenário 25) e na spec (R-1): o hook, nesse caso, precisa ser registrado por outro caminho (por exemplo, no evento de boot dos ativos personalizados), e isso vira uma decisão do dono.
5. Remova a linha de log temporária.

- [ ] **Step 5: Criar a sonda que simula uma atualização sem sessão (inventário, cron)**

Create `var/tools/lock_probe.php`:

```php
<?php

// Usage: php var/tools/lock_probe.php <ItemType> <id>
// Updates the name of a written-off asset WITHOUT a user session (like the inventory or a cron)
// and reports whether the lock refused it. Restores the name if the lock did not hold.
chdir('C:/Users/juliano/VSCode/glpi-xampp-dev-plugin');
require 'C:/Users/juliano/VSCode/glpi-xampp-dev-plugin/vendor/autoload.php';
$kernel = new Glpi\Kernel\Kernel('production');
$kernel->boot();

[$type, $id] = [$argv[1] ?? 'Computer', (int) ($argv[2] ?? 0)];
$asset = new $type();
if (!$asset->getFromDB($id)) {
    fwrite(STDERR, "asset not found\n");
    exit(1);
}
$old = $asset->fields['name'];
$ok  = $asset->update(['id' => $id, 'name' => $old . '-LOCKTEST']);
$asset->getFromDB($id);

echo 'update returned: ', var_export($ok, true), "\n", 'name now: ', $asset->fields['name'], "\n";
if ($asset->fields['name'] !== $old) {
    $asset->update(['id' => $id, 'name' => $old]);
    echo "LOCK DID NOT HOLD (name restored)\n";
} else {
    echo "LOCK HELD\n";
}
```

- [ ] **Step 6: Lint, limpar o cache e verificar**

```bash
for f in src/Ltbp/WrittenOffLock.php src/Ltbp/AssetUpdateGuard.php setup.php; do /c/xampp/php/php.exe -l "$f"; done
cd /c/Users/juliano/VSCode/glpi-xampp-dev-plugin && /c/xampp/php/php.exe bin/console cache:clear && cd -
```
Use um ativo de um laudo **Baixado** (Tarefa 11) e outro de um laudo ainda **No patrimônio**:
1. Ativo do laudo **No patrimônio** (não bloqueado): edite o nome pela tela do ativo e salve: **funciona**.
2. Ativo do laudo **Baixado**: edite o nome e salve: mensagem vermelha "Este ativo foi baixado… Campos recusados: name.", o nome **não** muda. Edite só o campo **Comentários** e salve: **funciona** (se recusar por falso positivo em algum campo formatado, anote o campo no roteiro, cenário 22, e ajuste a normalização em `LockPolicy` com um teste novo). Tente mudar o **status** e o **nº de série**: recusados.
3. Perfil com o direito **Editar ativo baixado** (marque-o no perfil de teste e refaça o login): a edição do nome passa.
4. Sem sessão: `/c/xampp/php/php.exe var/tools/lock_probe.php Computer <id-do-ativo-baixado>`. Expected: `LOCK HELD`. Com um ativo **não** baixado a sonda deve dar `LOCK DID NOT HOLD (name restored)` (prova de que a sonda enxerga o hook; se ela nunca bloquear nem o ativo baixado, o kernel do CLI pode não ter carregado o plugin: confirme pela tela e registre).
5. Concluir o laudo (status **Concluído**) mantém o bloqueio; cancelar não é mais possível.
6. Ativo personalizado baixado (se houver um ativo personalizado no GLPI de dev): mesmo teste da tela.
7. **Inventário real (R-4):** se houver um agente de teste, envie um inventário de um equipamento baixado. Expected: os campos não mudam e o log do GLPI não mostra erro fatal. Sem agente disponível, registre "Não executado" no roteiro (cenário 26).

- [ ] **Step 7: Commit**

Invoque o skill `/commit` (tipo `feat`, por exemplo "block edits to written-off assets"). Não inclua `var/`.

---

### Task 14: Integração com o PRE (L19 a L23)

**Files:**
- Create: `src/Ltbp/LtbpLinker.php`
- Modify: `src/Pre/RepairProtocolItem.php`, `templates/pre/items_tab.html.twig`, `front/ltbp/ltbpitem.form.php`

**Interfaces:**
- Consumes: `Ltbp` (`activeLaudoFor`, `canCreate`, `canView`, `canUpdate`), `Status`, `Destination`, `Labels`, `LineService::addAssets()`, `Shared\ServiceResult`; do PRE: `RepairProtocol`, `RepairProtocolItem`, `ItemStatus`, `Destination` (só leitura).
- Produces:
  - `LtbpLinker::openDrafts(): list<array{id: int, number: string, destination_label: string}>`
  - `LtbpLinker::addFromPre(array $preItemIds, int $ltbpId, string $newDestination): ServiceResult` (`$ltbpId = 0` cria um rascunho novo com a destinação dada; em caso de criação, `->data` traz `created = true` e `ltbp_id`)
  - `POST` `link_pre` em `front/ltbp/ltbpitem.form.php` com `pre_items[]`, `ltbp_id` e `destination`
  - Na aba Itens do PRE: selo "Aguardando laudo", chip com o número do laudo e o botão em lote "Adicionar a laudo".

- [ ] **Step 1: Criar `LtbpLinker`**

Create `src/Ltbp/LtbpLinker.php`:

```php
<?php

namespace GlpiPlugin\Gac\Ltbp;

use GlpiPlugin\Gac\Pre\Destination as PreDestination;
use GlpiPlugin\Gac\Pre\ItemStatus as PreItemStatus;
use GlpiPlugin\Gac\Pre\RepairProtocol;
use GlpiPlugin\Gac\Pre\RepairProtocolItem;
use GlpiPlugin\Gac\Shared\ServiceResult;
use Session;

/**
 * Entry point of the PRE integration (spec L19 to L22): takes returned PRE lines with destination
 * Baixa into a draft laudo. All the rules live here and in LineService; the PRE only shows the
 * button. This never writes to the PRE tables.
 */
final class LtbpLinker
{
    /** @return list<array{id: int, number: string, destination_label: string}> drafts the user can add to */
    public static function openDrafts(): array
    {
        global $DB;

        $drafts = [];
        foreach ($DB->request([
            'FROM'  => Ltbp::getTable(),
            'WHERE' => ['status' => Status::Draft->value] + getEntitiesRestrictCriteria(Ltbp::getTable()),
            'ORDER' => ['id DESC'],
        ]) as $r) {
            $destination = Destination::tryFrom((string) $r['destination']);
            $drafts[] = [
                'id'                => (int) $r['id'],
                'number'            => (string) $r['number'],
                'destination_label' => $destination === null ? '' : Labels::destination($destination),
            ];
        }
        return $drafts;
    }

    /**
     * @param list<int> $preItemIds ids of the PRE lines
     * @param int $ltbpId 0 creates a new draft with $newDestination
     */
    public static function addFromPre(array $preItemIds, int $ltbpId, string $newDestination): ServiceResult
    {
        global $DB;

        $ids = array_values(array_filter(array_map('intval', $preItemIds), static fn(int $id): bool => $id > 0));
        if ($ids === []) {
            return ServiceResult::fail(__('Marque ao menos uma linha aguardando laudo.', 'gac'));
        }

        // Never trust the client: only returned lines with destination Baixa count.
        $preItems     = RepairProtocolItem::getTable();
        $preProtocols = RepairProtocol::getTable();
        $assets       = [];
        $origins      = [];
        $entityId     = null;
        foreach ($DB->request([
            'SELECT'     => [
                "$preItems.id AS pre_item_id",
                "$preItems.itemtype AS itemtype",
                "$preItems.items_id AS items_id",
                "$preItems.tickets_id AS ticket_id",
                "$preItems.outcome AS outcome",
                "$preProtocols.number AS pre_number",
                "$preProtocols.entities_id AS pre_entities_id",
            ],
            'FROM'       => $preItems,
            'INNER JOIN' => [
                $preProtocols => ['ON' => [$preItems => 'plugin_gac_repairprotocols_id', $preProtocols => 'id']],
            ],
            'WHERE' => [
                "$preItems.id"          => $ids,
                "$preItems.status"      => PreItemStatus::Returned->value,
                "$preItems.destination" => PreDestination::Writeoff->value,
            ],
        ]) as $r) {
            $key           = $r['itemtype'] . '|' . $r['items_id'];
            $assets[]      = ['itemtype' => (string) $r['itemtype'], 'items_id' => (int) $r['items_id']];
            $origins[$key] = [
                'pre_items_id' => (int) $r['pre_item_id'],
                'pre_number'   => (string) $r['pre_number'],
                'tickets_id'   => (int) $r['ticket_id'],
                'outcome'      => (string) $r['outcome'],
            ];
            $entityId ??= (int) $r['pre_entities_id'];
        }
        if ($assets === []) {
            return ServiceResult::fail(__('Nenhuma das linhas marcadas está devolvida com destino Baixa.', 'gac'));
        }

        $laudo   = new Ltbp();
        $created = false;
        if ($ltbpId > 0) {
            if (!Ltbp::canUpdate() || !$laudo->getFromDB($ltbpId) || !StateMachine::canEditDraft($laudo->getStatus()) || !$laudo->canUpdateItem()) {
                return ServiceResult::fail(__('Escolha um laudo em rascunho que você possa editar.', 'gac'));
            }
        } else {
            if (!Ltbp::canCreate()) {
                return ServiceResult::fail(__('Você não tem permissão para criar laudos.', 'gac'));
            }
            if (Destination::tryFrom($newDestination) === null) {
                return ServiceResult::fail(__('Escolha a destinação do novo laudo.', 'gac'));
            }
            if ($entityId === null || !Session::haveAccessToEntity($entityId)) {
                return ServiceResult::fail(__('Você não tem acesso à entidade destes ativos.', 'gac'));
            }
            $newId = $laudo->add(['destination' => $newDestination, 'entities_id' => $entityId]);
            if (!$newId || !$laudo->getFromDB((int) $newId)) {
                return ServiceResult::fail(__('Não foi possível criar o laudo.', 'gac'));
            }
            $created = true;
        }

        $result = LineService::addAssets($laudo, $assets, $origins);
        if (!$result->ok && $created) {
            // Do not leave an empty draft behind when nothing could be added to it.
            $laudo->delete(['id' => (int) $laudo->getID()], true);
            return $result;
        }
        if (!$result->ok) {
            return $result;
        }

        return ServiceResult::ok(
            $result->message . ' ' . sprintf(__('Laudo %s.', 'gac'), (string) $laudo->fields['number']),
            ['created' => $created, 'ltbp_id' => (int) $laudo->getID()]
        );
    }
}
```

- [ ] **Step 2: Ligar a ação no tratador do LTBP**

Modify `front/ltbp/ltbpitem.form.php`: acrescente aos `use`: `use GlpiPlugin\Gac\Ltbp\LtbpLinker;`. **Antes** da linha `$laudo   = new Ltbp();` (o `link_pre` pode chegar sem laudo, quando é para criar um novo), acrescente:

```php
if (isset($_POST['link_pre'])) {
    if (!Ltbp::canView()) {
        Html::displayRightError();
    }
    $linked = LtbpLinker::addFromPre(
        array_map('intval', (array) ($_POST['pre_items'] ?? [])),
        (int) ($_POST['ltbp_id'] ?? 0),
        (string) ($_POST['destination'] ?? '')
    );
    Session::addMessageAfterRedirect(htmlescape($linked->message), false, $linked->ok ? INFO : ERROR);
    if ($linked->ok && !empty($linked->data['created'])) {
        Html::redirect(Ltbp::getFormURLWithID((int) $linked->data['ltbp_id']));
    }
    Html::back();
}
```

- [ ] **Step 3: Dados do selo e do botão em lote na aba Itens do PRE**

Modify `src/Pre/RepairProtocolItem.php`.

1. Acrescente aos `use`:

```php
use GlpiPlugin\Gac\Ltbp\Destination as LtbpDestination;
use GlpiPlugin\Gac\Ltbp\Labels as LtbpLabels;
use GlpiPlugin\Gac\Ltbp\Ltbp;
use GlpiPlugin\Gac\Ltbp\LtbpItem;
use GlpiPlugin\Gac\Ltbp\LtbpLinker;
```

2. Em `showForProtocol()`, **antes** do `foreach ($protocol->lines() as $row)`, acrescente:

```php
        // LTBP integration (LTBP spec L19 to L22): only when the user can see laudos.
        $ltbpEnabled = Ltbp::canView();
```

3. Dentro do `foreach`, no array `$lines[] = $row + [ ... ]`, acrescente estas chaves (por exemplo depois de `'can_remove_failed' => ...,`):

```php
                'awaiting_ltbp'     => false,
                'ltbp_number'       => '',
                'ltbp_url'          => '',
                'ltbp_status_label' => '',
```

e, logo depois de fechar `];` desse `$lines[] = ...`, acrescente o preenchimento (dentro do mesmo `foreach`, antes de fechar a chave dele):

```php
            if (
                $ltbpEnabled
                && $itemStatus === ItemStatus::Returned
                && ($row['destination'] ?? '') === Destination::Writeoff->value
            ) {
                $laudo = Ltbp::activeLaudoFor((string) $row['itemtype'], (int) $row['items_id']);
                $last  = array_key_last($lines);
                if ($laudo === null) {
                    $lines[$last]['awaiting_ltbp'] = true;
                } else {
                    $lines[$last]['ltbp_number']       = $laudo['number'];
                    $lines[$last]['ltbp_url']          = Ltbp::getFormURLWithID($laudo['id']);
                    $lines[$last]['ltbp_status_label'] = LtbpLabels::status($laudo['status']);
                }
            }
```

4. Depois do bloco que monta `$destinationChoices` e **antes** do `TemplateRenderer::getInstance()->display(...)`, acrescente:

```php
        $canLinkLtbp = $ltbpEnabled && (Ltbp::canCreate() || Ltbp::canUpdate());
        $ltbpDestinations = [];
        foreach (LtbpDestination::cases() as $d) {
            $ltbpDestinations[$d->value] = LtbpLabels::destination($d);
        }
```

e, no array passado ao `display()`, acrescente (depois de `'destination_choices' => $destinationChoices,`):

```php
            'can_link_ltbp'       => $canLinkLtbp,
            'ltbp_drafts'         => $canLinkLtbp ? LtbpLinker::openDrafts() : [],
            'ltbp_destinations'   => $ltbpDestinations,
            'ltbp_link_url'       => LtbpItem::getFormURL(),
```

- [ ] **Step 4: Selo, coluna de seleção e cartão do botão em lote**

Modify `templates/pre/items_tab.html.twig`:

1. Logo depois da linha `{% set editing = is_draft and can_edit %}`, acrescente:

```twig
    {% set link_col = can_link_ltbp and (lines|filter(l => l.awaiting_ltbp)|length) > 0 %}
```

2. No `<thead>`, logo depois do `<tr>` de abertura (antes do `<th>` do "Ticket"), acrescente:

```twig
                    {% if link_col %}
                        <th><input type="checkbox" class="form-check-input" data-gac-ltbp-all title="{{ __('Marcar todos', 'gac') }}" aria-label="{{ __('Marcar todos', 'gac') }}"></th>
                    {% endif %}
```

3. No `{% for line in lines %}`, logo depois do `<tr>` de abertura da linha (antes do `<td>` do ticket), acrescente:

```twig
                        {% if link_col %}
                            <td>
                                {% if line.awaiting_ltbp %}
                                    <input type="checkbox" class="form-check-input" form="gac-ltbp-link" name="pre_items[]" value="{{ line.id }}" aria-label="{{ __('Levar para um laudo de baixa', 'gac') }}">
                                {% endif %}
                            </td>
                        {% endif %}
```

4. Na célula "Situação", logo depois da linha `{% if line.outcome_label %}...{% endif %}`, acrescente:

```twig
                            {% if line.awaiting_ltbp %}
                                <div class="mt-1"><span class="badge bg-warning-lt">{{ __('Aguardando laudo', 'gac') }}</span></div>
                            {% elseif line.ltbp_number %}
                                <div class="mt-1"><a class="badge bg-info-lt" href="{{ line.ltbp_url }}">{{ line.ltbp_number }} · {{ line.ltbp_status_label }}</a></div>
                            {% endif %}
```

5. Na linha vazia da tabela, troque `colspan="8"` por `colspan="{{ link_col ? 9 : 8 }}"`.

6. Logo **antes** da linha `{% include '@gac/pre/items_return_forms.html.twig' ignore missing %}`, acrescente o cartão do botão em lote:

```twig
    {% if link_col %}
        <form method="post" action="{{ ltbp_link_url }}" id="gac-ltbp-link" class="card border mb-4">
            <input type="hidden" name="_glpi_csrf_token" value="{{ csrf_token() }}">
            <div class="card-header bg-body-tertiary">
                <div>
                    <h4 class="card-title mb-1"><i class="ti ti-file-certificate me-2"></i>{{ __('Levar para um laudo de baixa (LTBP)', 'gac') }}</h4>
                    <div class="text-muted small">{{ __('Marque as linhas "Aguardando laudo" e escolha um laudo em rascunho ou crie um novo. O status do ativo só muda quando o laudo é emitido.', 'gac') }}</div>
                </div>
            </div>
            <div class="card-body row g-2">
                <div class="col-md-5">
                    <label class="form-label">{{ __('Laudo', 'gac') }}</label>
                    <select class="form-select" name="ltbp_id" data-gac-ltbp-target>
                        <option value="0">{{ __('Novo laudo…', 'gac') }}</option>
                        {% for draft in ltbp_drafts %}
                            <option value="{{ draft.id }}">{{ draft.number }}{% if draft.destination_label %} — {{ draft.destination_label }}{% endif %}</option>
                        {% endfor %}
                    </select>
                </div>
                <div class="col-md-4" data-gac-ltbp-destination>
                    <label class="form-label">{{ __('Destinação do novo laudo', 'gac') }}</label>
                    <select class="form-select" name="destination">
                        {% for value, label in ltbp_destinations %}
                            <option value="{{ value }}">{{ label }}</option>
                        {% endfor %}
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" name="link_pre" value="1" class="btn btn-primary">{{ __('Adicionar a laudo', 'gac') }}</button>
                </div>
            </div>
        </form>
        <script>
            (function () {
                const all = document.querySelector('[data-gac-ltbp-all]');
                if (all) {
                    all.addEventListener('change', function () {
                        document.querySelectorAll('input[form="gac-ltbp-link"][name="pre_items[]"]').forEach(function (box) { box.checked = all.checked; });
                    });
                }
                const target = document.querySelector('[data-gac-ltbp-target]');
                const destination = document.querySelector('[data-gac-ltbp-destination]');
                if (target && destination) {
                    const sync = function () { destination.hidden = target.value !== '0'; };
                    target.addEventListener('change', sync);
                    sync();
                }
            })();
        </script>
    {% endif %}
```

- [ ] **Step 5: Lint, limpar o cache e verificar**

```bash
for f in src/Ltbp/LtbpLinker.php src/Pre/RepairProtocolItem.php front/ltbp/ltbpitem.form.php; do /c/xampp/php/php.exe -l "$f"; done
/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml
cd /c/Users/juliano/VSCode/glpi-xampp-dev-plugin && /c/xampp/php/php.exe bin/console cache:clear && cd -
```
Prepare **um PRE encerrado com 3 linhas devolvidas** como "Sem conserto" + destino "Encaminhar para baixa" (e configure um motivo padrão para "Sem conserto" na configuração do LTBP).
1. Aba Itens do PRE: as 3 linhas mostram o selo **Aguardando laudo**, uma coluna de caixas de seleção e o cartão "Levar para um laudo de baixa".
2. Marque 2 linhas, deixe "Novo laudo…" com a destinação **Descarte ecológico** e clique **Adicionar a laudo**: você cai no laudo novo (rascunho, destinação descarte) com as 2 linhas, a coluna Origem mostra o **número do PRE** e o **ticket**, e o **motivo padrão já vem selecionado**.
3. Volte ao PRE: as 2 linhas mostram o chip `LTBP-… · Rascunho` (link para o laudo) e não têm mais caixa; a 3ª continua "Aguardando laudo".
4. Crie outro laudo em rascunho à mão; no PRE, marque a 3ª linha, escolha esse laudo no seletor (o seletor de destinação some) e adicione: a linha entra no laudo existente.
5. Exclua (**Excluir permanentemente**) o primeiro laudo (rascunho): as 2 linhas do PRE voltam a **Aguardando laudo**. Emita e depois cancele um laudo: as linhas voltam a aparecer como "Aguardando laudo" (a regra L21).
6. Tente adicionar uma linha cujo ativo já está em outro laudo (forçando o `POST`): recusa e **nenhum rascunho vazio fica criado** (confira a lista de laudos).
7. Perfil **sem** direito de ver laudos: a aba Itens do PRE não mostra selo, coluna nem cartão. Perfil que só lê laudos (sem criar/editar): sem o cartão.
8. **Regressão do PRE:** envie um PRE novo, registre um retorno "Reparado" e um "Sem conserto", baixe o PDF de envio: tudo como antes.

- [ ] **Step 6: Commit**

Invoque o skill `/commit` (tipo `feat`, por exemplo "link returned PRE lines to a write-off report").

---

### Task 15: Roteiro manual, spec, CLAUDE.md e verificação final

**Files:**
- Create: `docs/ltbp-manual-tests.md`
- Modify: `docs/superpowers/specs/2026-09-26-ltbp-design.md`, `CLAUDE.md`

- [ ] **Step 1: Criar o roteiro de teste manual**

Create `docs/ltbp-manual-tests.md`:

```markdown
# LTBP: roteiro de teste manual

Roteiro dos fluxos do LTBP que dependem do GLPI (só as classes puras têm teste automatizado, em
`tests/Unit/`). Cada linha diz como reproduzir e o que esperar. A coluna **Resultado** registra a
execução no GLPI 11.0.8 de desenvolvimento: preencha com a data e **OK**, **Parcial** (com a nota)
ou **Não executado** (com o motivo).

## Pré-requisitos

- GLPI local com o plugin instalado e ativo (reinstalado depois de qualquer mudança no `hook.php`),
  configuração do LTBP completa (três status do ativo, quatro campos dos diretores, motivos de
  baixa cadastrados, categoria da logomarca) e a do PRE completa.
- Um usuário com todos os direitos do LTBP **menos** "Editar ativo baixado", um perfil só de leitura
  e um perfil com "Editar ativo baixado". Depois de mudar direitos: novo login.
- Um fornecedor **ativo** (recicladora ou donatário), ativos de teste em "Aguardando baixa" (um
  computador, monitores e, se houver, um ativo personalizado) e um PRE encerrado com linhas
  devolvidas como "Sem conserto" com destino "Encaminhar para baixa".
- Depois de editar um `.twig`: `php bin/console cache:clear`.

## Roteiro

| # | Cenário | Como | Esperado | Resultado |
|---|---|---|---|---|
| 1 | Numeração | Criar 3 laudos | `LTBP-AAAA-001`, `-002`, `-003`; número nunca reaproveitado ao excluir um rascunho | |
| 2 | Destinação obrigatória | Criar um laudo sem escolher a destinação | Recusa: "Informe a destinação." | |
| 3 | Candidatos | Abrir a aba Itens de um rascunho | Lista os ativos no status "aguardando baixa" da entidade do laudo e de suas subentidades; ativo de outra entidade não aparece | |
| 4 | Origem no PRE | Candidato que veio do PRE | Mostra o número do PRE, o resultado e a descrição do serviço | |
| 5 | Busca livre | Adicionar um ativo fora do status pelo seletor | Entra sem origem; nada é escrito em ticket | |
| 6 | Um laudo por ativo | Adicionar o mesmo ativo a outro laudo | Recusa: "já está no laudo LTBP-…" | |
| 7 | Ativo em PRE ativo | Adicionar um ativo em linha ativa de PRE | Recusa citando o PRE | |
| 8 | Ativo já baixado | Adicionar um ativo já no status "baixado" | Recusa: "já está baixado" | |
| 9 | Emissão exige tudo | Emitir sem motivo em alguma linha / sem diretor / sem mapeamento | Botão desabilitado com a lista do que falta | |
| 10 | Motivo inativo | Inativar um motivo já escolhido e tentar emitir | Recusa: "Escolha um motivo ativo…" | |
| 11 | Emissão | Emitir um laudo válido | Status "Aguardando assinaturas"; ativos em "em processo de baixa"; PDF anexado; motivos e diretores copiados; evento no histórico | |
| 12 | Emissão atômica | Renomear `vendor/` e emitir | Falha inteira; laudo continua rascunho; status dos ativos intacto | |
| 13 | Snapshot | Após emitir, editar um motivo e trocar o diretor na configuração | Aba Itens e PDF emitido continuam com o texto antigo | |
| 14 | PDF retrato | Abrir o PDF de um laudo com 40 ativos | A4 vertical; cabeçalho da tabela repete; nenhuma linha cortada; assinaturas não se dividem; legível em preto e branco | |
| 15 | Local e data | Entidade com cidade/UF e sem | "Cidade/UF, dd/mm/aaaa" e só "dd/mm/aaaa"; a emissão não bloqueia | |
| 16 | Cancelar | Cancelar um laudo emitido, com motivo | Ativos voltam ao status anterior; laudo "Cancelado"; ativos voltam aos candidatos | |
| 17 | Cancelar com status mexido | Mudar o status de um ativo à mão e cancelar | Aviso para esse ativo; os demais são restaurados | |
| 18 | PDF assinado | Anexar e depois substituir o arquivo | Status "Assinado"; os dois arquivos ficam em Documentos; "Baixar PDF assinado" abre o mais novo | |
| 19 | Envio ao patrimônio | Registrar data e "recebido por" | Status "No patrimônio"; já não dá para substituir o PDF assinado | |
| 20 | Baixa sem processo | Confirmar a baixa sem nº do processo | Aceita; status "Baixado"; ativos no status "baixado"; "Cancelar" some | |
| 21 | Baixa: falha de status | Mapear um status de outra entidade e confirmar | Recusa por ativo; nada muda (transação) | |
| 22 | Bloqueio pela tela | Editar nome, status e série de um ativo baixado; editar só os comentários | Nome, status e série recusados com mensagem; comentários gravam | |
| 23 | Direito de liberação | Repetir o 22 com o perfil "Editar ativo baixado" | A edição passa | |
| 24 | Sem sessão | `php var/tools/lock_probe.php <Tipo> <id>` num ativo baixado | `LOCK HELD` | |
| 25 | Ativo personalizado | Baixar e tentar editar um ativo personalizado | Bloqueado como os demais (ver o Step 4 da Tarefa 13 sobre a lista de tipos) | |
| 26 | Inventário real | Enviar um inventário de um equipamento baixado | Campos não mudam; sem erro fatal no log | |
| 27 | Conclusão com comprovante | Concluir sem arquivo (opção ligada) e com arquivo | Sem arquivo o navegador barra; com arquivo conclui; comprovante em Documentos | |
| 28 | Conclusão opcional | Desligar a exigência e concluir sem arquivo | Conclui | |
| 29 | Beneficiário inativo | Forçar um fornecedor inativo | Recusa | |
| 30 | Acompanhamentos no ticket | Emitir, baixar e concluir um laudo com linhas vindas do PRE | Um acompanhamento por marco em cada ticket; o ticket continua Pendente com o mesmo motivo; ticket já fechado é ignorado | |
| 31 | Solução do ticket | Ligar a opção e concluir; ticket com ativo em outro laudo aberto | Solucionado ao concluir; **não** solucionado enquanto houver linha aberta | |
| 32 | Motivo padrão | Levar linha "Sem conserto" do PRE | Motivo já selecionado e editável | |
| 33 | Botão em lote (laudo novo) | Marcar linhas no PRE, "Novo laudo…" | Cai no laudo novo com as linhas; PRE mostra o chip do laudo | |
| 34 | Botão em lote (laudo existente) | Escolher um rascunho existente | Linhas entram; sem seletor de destinação | |
| 35 | Selo volta | Excluir o rascunho / cancelar o laudo | Linhas do PRE voltam a "Aguardando laudo" | |
| 36 | Direitos | Perfil só de leitura; perfil sem ver laudos | Sem formulários de ação; PRE sem selo nem cartão | |
| 37 | Motivo usado | Tentar excluir um motivo em uso | Recusa; inativar funciona | |
| 38 | Excluir rascunho | Excluir um rascunho com linhas | Apaga o laudo e as linhas; ativos livres | |
| 39 | Reinstalar | Desinstalar e instalar o plugin | Sem erro; tabelas e direitos recriados | |
| 40 | Regressão do PRE | Enviar um PRE, registrar retornos, baixar o PDF | Tudo como antes da extração para `Shared` | |

## Não coberto por este roteiro

- Assinatura eletrônica no GLPI (fora da v1, spec L4).
- Reabertura de laudo depois da baixa (fora da v1, spec L10).
- Medição de desempenho da emissão com centenas de ativos (spec R-2): meça uma emissão de 200
  ativos e, se passar do aceitável, adote o modelo de uma linha por requisição do PRE (D15).
```

- [ ] **Step 2: Atualizar a spec com os ajustes que o plano fez**

Modify `docs/superpowers/specs/2026-09-26-ltbp-design.md`:

1. Em **5.5 Configuração**, na lista de chaves, acrescente depois do item `ltbp_solve_ticket_on_completion`:

```markdown
- `ltbp_logo_documentcategories_id`: categoria de documento da logomarca do PDF (própria do LTBP;
  costuma ser a mesma do PRE). O cabeçalho do PDF vem da entidade do laudo, como no PRE.
```

2. Em **5.4 `ltbpevents`**, na lista de eventos, acrescente `document_attached` (anexo do laudo na baixa ou na conclusão) ao lado de `line_document_attached`.

3. Na tabela da seção **14.1**, no fim da linha da decisão **L22**, acrescente ao texto: "e `LtbpLinker::openDrafts()` (os rascunhos que o seletor do botão em lote lista)". E, em **5.1** e **5.2**, acrescente a nota: "Os nomes das colunas de chave estrangeira seguem a convenção do GLPI: `plugin_gac_ltbps_id`, `plugin_gac_ltbpitems_id`, `plugin_gac_ltbpreasons_id`."

4. Na seção **13 Pendências e riscos**, atualize o **R-1** com o resultado do Step 4 da Tarefa 13 (a lista de tipos estava completa na inicialização, ou o complemento com `AssetDefinitionManager` foi necessário, ou o hook precisou de outro caminho) e o **R-4** com o resultado do teste de inventário do cenário 26.

- [ ] **Step 3: Atualizar o `CLAUDE.md`**

Modify `CLAUDE.md`:

1. Na seção **What exists today**, troque o parágrafo que começa com "The second feature idea, the **laudo de baixa patrimonial**" por:

```markdown
The second module is the **LTBP (Laudo Técnico de Baixa Patrimonial)**, in `src/Ltbp/`. It prepares equipment for the write-off: the technician picks assets (candidates in the "Aguardando baixa" status, with their origin in the PRE, plus a free search), each with a reason from an editable catalog; the laudo is issued as a portrait PDF (frozen as a `Document`), signed on paper by the two directors, sent to the patrimony, the write-off is confirmed (assets go to the "baixado" status and are locked against edition), and the destination (disposal or donation) is completed with a beneficiary (a `Supplier`) and a proof. Returned PRE lines with destination Baixa are taken into a laudo by a batch button on the PRE items tab. Its design is in `docs/superpowers/specs/2026-09-26-ltbp-design.md` (decisions L1 to L25, the source of truth) and its plan in `docs/superpowers/plans/2026-09-26-ltbp-implementation.md`; `docs/ltbp-manual-tests.md` is the manual test script. **If the code diverges from the spec, one of them is wrong: fix it.**
```

2. Na seção **Plugin structure conventions**, acrescente ao fim do item "Layout of the PRE module" (ou logo depois dele) um item novo:

```markdown
- **Layout of the LTBP module and `Shared`:** pure rules in `src/Ltbp/` (`Status`, `Destination`, `StateMachine`, `LtbpNumber`, `LtbpSettings`, `EmissionValidator`, `LockPolicy`, `PlaceDate`, `TicketSolvePolicy`) with tests in `tests/Unit/`; GLPI-bound classes next to them (`Ltbp`, `LtbpItem`, `LtbpEvent`, `LtbpReason`, the `*Service` classes, `LtbpLinker`, `PdfRenderer`, `AssetUpdateGuard`). Twig in `templates/ltbp/`, pages in `front/ltbp/`, tables `glpi_plugin_gac_ltbp*`, settings `ltbp_*`. The pieces both modules use (`EntityChain`, `LogoFit`, `LogoLocator`, `EventMessage`, `ReportFormatter`, `ServiceResult`, `StateGuard`, `TicketOps`, `MpdfLoader`, `DocumentStore`) live in `src/Shared/`. The modules only read each other's tables (LTBP reads the PRE lines; the PRE reads `Ltbp::activeLaudoFor()` and `LtbpLinker::openDrafts()`); the LTBP never writes to the PRE.
- **Write-off lock:** `AssetUpdateGuard` is registered on `pre_item_update` for every asset class in `setup.php` and refuses updates to an asset in a laudo that is Baixado or Concluído (except comments), unless the profile has the "Editar ativo baixado" right. The module's own status writes run inside `LtbpGuard::run()`.
```

3. Na seção **Still to fix**, acrescente: `- The LTBP has no manual-test results recorded yet (docs/ltbp-manual-tests.md, column Resultado), and the write-off lock was verified from the GLPI source and the probe script, not with a real inventory agent (spec R-4).`

- [ ] **Step 4: Verificação final**

```bash
/c/xampp/php/php.exe var/tools/phpunit.phar -c phpunit.unit.xml
for f in $(git ls-files -m -o --exclude-standard | grep '\.php$'); do /c/xampp/php/php.exe -l "$f" | grep -v '^No syntax errors'; done; echo lint-done
grep -rnE 'Gac.Pre.(EntityChain|EventMessage|LogoFit|ReportFormatter|ServiceResult|StateGuard|TicketOps)' src front ajax tests hook.php setup.php || echo "sem referências antigas"
git status --short
```
Expected: testes PASS; nenhuma mensagem de erro antes de `lint-done`; `sem referências antigas`; o `git status` mostra só arquivos deste plano (nada em `var/`, `vendor/` ou `files/`). Rode o roteiro manual inteiro (`docs/ltbp-manual-tests.md`) preenchendo a coluna **Resultado**, incluindo o cenário 40 (regressão do PRE). Reinstale o plugin uma última vez e refaça um ciclo completo de ponta a ponta (PRE → retorno "Sem conserto"/Baixa → botão em lote → emitir → assinar → patrimônio → baixar → concluir) num GLPI limpo.

- [ ] **Step 5: Commit**

Invoque o skill `/commit` (tipo `chore`, por exemplo "add LTBP manual tests and update the spec and project notes"), incluindo `docs/ltbp-manual-tests.md`, a spec e o `CLAUDE.md`. O `CHANGELOG.md` e o bump de versão ficam para o fluxo de release do dono (`/changelog-pr`, `/changelog-release`); esta funcionalidade é **MINOR** pelo `docs/versioning.md` (funcionalidade nova, aditiva), e o `hook.php` só roda na atualização quando a versão sobe, então o release precisa subir `PLUGIN_GAC_VERSION` e o `gac.xml`.

---

## Autoavaliação do plano

**Cobertura da spec.** Cada decisão e seção da spec tem uma tarefa:

| Spec | Tarefa |
|---|---|
| L1 (nome LTBP), 5.x (tabelas, chaves, configuração), 10 (direitos) | 5, 6, 7 |
| L2 (status do ativo em dois passos), 6.1 e 6.2 (emissão e cancelamento) | 2, 4, 10 |
| L3, L5 (signatários copiados, PDF emitido e PDF assinado distintos), L4 (só papel), L11, L12, L13 | 10, 11 |
| L6, L7, L8 (destinação por laudo, candidatos + busca livre, um laudo por ativo) | 5, 8 |
| L9 (cadastro de motivos, snapshot, motivo usado só se inativa) | 5, 6, 10 |
| L14 e seção 8 (bloqueio de edição) | 4 (`LockPolicy`), 13 |
| L15, L16, L17 e seção 9 (PDF retrato, cidade/UF da entidade, congelado) | 4 (`PlaceDate`), 9, 10 |
| L18 (numeração) | 2, 5 |
| L19 a L23 (botão em lote no PRE, retorno intocado, regra "pendente de laudo", fronteira, origem na linha) | 8, 14 |
| L24 (motivo padrão), L25 (acompanhamentos e solução do ticket) | 6, 8, 12 |
| Seção 11 (extrair peças neutras) | 1 |
| Seção 12 (testes) e 13 (riscos R-1 a R-7) | 2 a 4 (unitários), 13 e 15 (roteiro manual, R-1 e R-4) |

**Varredura de lacunas de texto.** Sem "TBD", "TODO" ou "implementar depois". Os pontos que só o GLPI pode confirmar têm um passo de verificação com o resultado esperado (não uma promessa).

**Consistência de tipos e nomes** (conferida por busca no próprio plano): `Ltbp::activeLaudoFor()` devolve `array{id, number, status}` e é lido assim em `LineService`, `IssueService` e `RepairProtocolItem`; `LtbpReason::choices()` (ativos) e `choices(false)` (todos) e `row()`; `LineService::addAssets($laudo, $assets, $origins)` recebe as chaves `pre_items_id`, `pre_number`, `tickets_id` e `outcome` que `CandidateFinder` e `LtbpLinker` produzem; `EmissionValidator::validate()` recebe as seis chaves que `IssueService::problems()` monta; `StepService::attachSigned()` recebe o primeiro item de `DocumentStore::collect()`; `WrittenOffLock::flush()` existe desde a Tarefa 11 (esqueleto) e é completada na 13; `LtbpGuard` nasce na Tarefa 10 e é usada pela 11 e pela 13.

**O que o plano assume sem ter executado** (cada item tem o passo de verificação indicado; se falhar, o passo diz o que ajustar):

1. O hook `pre_item_update` cancelar uma atualização com `$item->input = []` (padrão da documentação do GLPI, ~9590) e o `update()` devolver `false` sem erro fatal: Tarefa 13, passos 6.2 e 6.4.
2. `$CFG_GLPI['asset_types']` já conter os ativos personalizados quando `plugin_init_gac()` roda (R-1): Tarefa 13, Step 4, com o complemento pronto.
3. `Dropdown::showSelectItemFromItemtypes()` com `display => false` devolver o HTML (o PRE não o usa; o código do GLPI foi lido): Tarefa 8, Step 8.
4. A adição de um acompanhamento simples num ticket **Pendente** manter o status e o motivo (o PRE já assume isso no caso D17): Tarefa 12, Step 3.2, com o ajuste indicado.
5. O `TemplateRenderer` do plugin resolver `@gac/ltbp/...` como faz com `@gac/pre/...` (mesma raiz `templates/`): Tarefa 7, Step 5.
6. Os campos de data do GLPI não foram usados nos cartões do Andamento: eles usam `<input type="date">` simples, para não depender do layout dos macros do GLPI fora do formulário padrão.
7. Falso positivo do `LockPolicy` em campos formatados ao salvar só o comentário de um ativo baixado: Tarefa 13, Step 6.2, com o teste de regressão a acrescentar.

**Ordem de execução.** As Tarefas 1 a 4 não dependem do GLPI (testes automatizados) e podem ser feitas e revisadas sem o GLPI de dev. A partir da 5, cada tarefa termina com uma verificação manual no GLPI de dev. A 14 (PRE) exige as 8 e 12 prontas; a 13 (bloqueio) exige a 11.
