# LTBP — Laudo Técnico de Baixa Patrimonial: design

Data: 2026-09-26 · Plugin: `gac` (GLPI 11.0.x, PHP >= 8.2) · Namespace: `GlpiPlugin\Gac\Ltbp`

Este documento é a fonte de verdade do subprojeto LTBP. Toda decisão tomada na sessão de
brainstorming está registrada aqui, com o motivo. Se o código divergir deste documento, um dos
dois está errado e deve ser corrigido. Segue o formato da spec do PRE
(`2026-09-24-pre-design.md`), de quem herda várias decisões.

## Status das decisões

- **Decidido**: confirmado pelo dono do projeto. Seções 1 a 10 e 14.
- **Rascunho**: seções 11 (estrutura de código) e 12 (erros e testes), sem revisão do dono.
- **Pendente**: ver seção 13.

## 1. Contexto e objetivo

O LTBP é o documento que a TI do Grupo Aparício Carvalho (GAC) emite para preparar equipamentos
para a baixa patrimonial e a destinação final (descarte ecológico ou doação). O fluxo físico é:

1. a TI gera o laudo;
2. os diretores de TI e Administrativo assinam;
3. o documento vai ao setor de patrimônio, que faz a baixa no sistema dele;
4. a TI executa o descarte ou a doação.

Hoje isso é feito pelo app Django separado (`morefunctionsforglpi`, `apps/reports`, modelos
`LaudoBaixa` e `ItemLaudo`). O app cobre a geração do papel e a escrita do status de baixa nos
ativos do GLPI. Ele tem só dois estados (`RASCUNHO`, `PROCESSADO`) e não modela assinatura,
envio ao patrimônio, baixa confirmada nem a execução do destino.

Diferenças conhecidas do app atual, que o plugin corrige:

- O status de baixa é aplicado ao final, então durante o processo o inventário do GLPI mostra o
  equipamento como ativo. O plugin usa dois passos (L2).
- A destinação é só uma "recomendação", sem registro do que aconteceu depois.
- `MotivoBaixa` é uma FK com `SET_NULL`: apagar um motivo esvazia, em silêncio, o motivo dos
  laudos antigos.
- A numeração `LT-AAAA-NNN` é "último + 1" sem trava (colisão sob concorrência).
- O PDF imprime só o tipo do equipamento (falta o nome), tem cidade fixa ("Porto Velho/RO") e
  formata a data por locale do servidor; depende de WeasyPrint/GTK3.
- Os ativos entram por uma view SQL (`v_equipamentos_para_baixa`) com tudo que já está em status
  de baixa, sem o técnico escolher.
- Nada impede a edição do ativo depois da baixa.

## 2. Escopo

Dentro: criação do laudo, escolha de ativos, cadastro de motivos, emissão com PDF congelado,
recebimento do PDF assinado, envio ao patrimônio, confirmação da baixa, conclusão da destinação
(beneficiário e comprovante), cancelamento, bloqueio de edição de ativo baixado, configuração,
permissões.

Fora da v1 (decidido):

- Assinatura eletrônica no GLPI. O v1 é só papel; o modelo deixa o ponto de extensão (L4).
- Desfazer ou reabrir um laudo depois de `Baixado`. Os equipamentos já estão no fim da vida
  útil; a necessidade nunca apareceu (L10).
- Destinação por equipamento dentro do mesmo laudo (L6).
- Laudos com destinos mistos: viram laudos separados.
- Migração dos laudos do app Django (mesma linha da D14 do PRE: o app antigo vira consulta; a
  sequência recomeça em 1).
- Impedir que um equipamento com agente de inventário instalado gere um ativo novo (ver R-3).

## 3. Glossário

- **LTBP** (ou "laudo"): o documento (cabeçalho), uma destinação por laudo.
- **Linha**: um ativo dentro de um laudo.
- **Motivo**: item do cadastro que justifica a baixa de uma linha (por exemplo M1, M2).
- **Laudo ativo**: laudo que não está `Cancelado` nem `Concluído`.
- **Baixa**: baixa patrimonial feita pelo setor de patrimônio no sistema dele.

## 4. Decisões de escopo e regras de negócio

| # | Decisão | Motivo |
|---|---|---|
| L1 | Módulo, namespace e tabelas usam a sigla **LTBP** (`Ltbp`, `glpi_plugin_gac_ltbp*`), no mesmo estilo do PRE | Decisão do dono: o módulo é específico do Laudo Técnico de Baixa Patrimonial, não um "writeoff" genérico |
| L2 | O status do ativo muda em **dois passos**: na emissão vai para "Em processo de baixa" (guardando o status anterior); ao confirmar a baixa vai para "Baixado". Cancelar restaura o status anterior | Decisão do dono: o inventário não pode mostrar como ativo um equipamento reprovado; e não pode dizer "baixado" antes de o patrimônio baixar |
| L3 | Três assinaturas em papel: técnico emissor (usuário logado), Diretor de TI e Diretor Administrativo. Nome e cargo dos diretores ficam na configuração e são **copiados para o laudo na emissão** | Trocar de diretor não altera laudos antigos |
| L4 | O v1 é **só papel**: o PDF congelado é impresso, assinado, escaneado e anexado (PDF assinado). A assinatura eletrônica no GLPI é sub-projeto futuro, a decidir depois de confirmar com o patrimônio se aceita aceite eletrônico | Decisão do dono. O GLPI não tem assinatura de documento (só `CommonITILValidation`, de chamados); teria de ser construída, e a validade depende do patrimônio |
| L5 | O PDF emitido e o PDF assinado são **dois anexos distintos** (`documents_id_frozen`, `documents_id_signed`) | Permite provar que o papel assinado corresponde ao que o sistema emitiu |
| L6 | A destinação (descarte ou doação) vale para o **laudo inteiro** | Decisão do dono. Custo aceito: destinos mistos exigem laudos separados, cada um com as suas assinaturas |
| L7 | Cada ativo entra por **candidatos + busca livre**: a tela lista primeiro os ativos no status "Aguardando baixa" (inclui os que vieram do PRE com destino `writeoff`) e oferece busca por qualquer ativo, para os obsoletos que nunca passaram pelo PRE | Decisão do dono |
| L8 | Um ativo só pode estar em **um laudo ativo** por vez, e não pode estar em linha ativa de PRE (`Aguardando envio`, `Enviando`, `Na assistência`) | Um equipamento no fornecedor não pode ser baixado |
| L9 | O cadastro de **motivos** é gerido na configuração do módulo (código único, título, descrição, ativo). Motivo usado só pode ser **inativado**, nunca excluído. A linha guarda um snapshot (código, título, descrição) na emissão | Decisão do dono (versatilidade). O snapshot evita que editar o texto altere a legenda de laudos já emitidos |
| L10 | Depois de `Baixado` não há cancelamento nem reabertura | Decisão do dono: o laudo prepara equipamentos em fim de vida; nunca precisou desfazer baixa |
| L11 | O **nº do processo de baixa** é opcional na confirmação do patrimônio | Decisão do dono: hoje o número não existe; pode passar a existir |
| L12 | A conclusão (`Concluído`) exige data e **beneficiário**, que é um **Fornecedor** (`Supplier`) do GLPI: a empresa recicladora ou o donatário, cadastrado como fornecedor. O anexo de comprovante (certificado de destinação ou termo de doação) é obrigatório, e essa obrigatoriedade é **configurável** (`ltbp_completion_require_document`, padrão ligado) | Decisão do dono |
| L13 | O laudo encerra sozinho ao registrar a conclusão (estado final `Concluído`) | Mesma lógica da D9 do PRE: cada ação já é explícita |
| L14 | Depois de `Baixado`, o ativo fica **bloqueado para edição** (todos os campos, exceto observações e vínculos de documentos) por um hook `pre_item_update`, que cobre a tela de detalhes, a API e o agente de inventário. Só perfis com o direito "Editar ativo baixado" passam | Decisão do dono. Antes de `Baixado` o ativo segue editável, pois o laudo ainda pode ser cancelado e corrigido |
| L15 | Cidade e UF do texto de data e local no PDF vêm da **entidade do laudo** (campos `town` e `state`) | Decisão do dono. O Django tem "Porto Velho/RO" fixo no template |
| L16 | O PDF é gerado em **retrato** (A4 vertical, `format => 'A4'`), pensado para impressão em preto e branco | Decisão do dono, com ênfase. O PDF do PRE é paisagem (`A4-L`); este é um formato próprio |
| L17 | O PDF definitivo é gerado na emissão e anexado como `Document`; depois, o download entrega esse arquivo, nunca uma nova renderização | Mesma regra da D11 do PRE |
| L18 | Numeração `LTBP-AAAA-NNN`, com sequência trabalhada com trava e nova tentativa em colisão | O Django usava `LT-AAAA-NNN` sem trava |

## 5. Modelo de dados

Prefixo `glpi_plugin_gac_`. Tipos exatos ficam para o plano de implementação.

### 5.1 `ltbps` (o laudo)

- `id`, `entities_id`, `number` (único), `status`, `destination` (`disposal` ou `donation`)
- `users_id_tech` (técnico emissor)
- Datas por marco: `date_issued` (emissão), `date_signed`, `date_sent_patrimony`,
  `date_written_off`, `date_completed`, `date_canceled`
- Signatários copiados na emissão: `director_ti_name`, `director_ti_role`, `director_adm_name`,
  `director_adm_role`
- Envio ao patrimônio: `received_by` (texto, quem recebeu)
- Baixa: `writeoff_process_number` (**opcional**), `writeoff_notes`
- Conclusão: `suppliers_id` (beneficiário) e o nome copiado, `completion_notes`
- `cancel_reason`
- `documents_id_frozen` (PDF emitido), `documents_id_signed` (PDF assinado)
- `date_creation`, `date_mod`

Os anexos da baixa e da conclusão (vários) são `Document` ligados ao laudo (`Document_Item`).

### 5.2 `ltbpitems` (a linha)

- Chave: `ltbps_id`, `itemtype`, `items_id`
- Snapshot na importação: nome, tipo (rótulo), marca, modelo, número de série, patrimônio
- `reasons_id` e snapshot do motivo (`reason_code`, `reason_title`, `reason_description`) na emissão
- `states_id_before` (status do ativo antes da emissão)
- Origem no PRE (opcionais, vazios para ativos vindos da busca livre): `pre_items_id` (a linha do
  PRE), `pre_number` (número do PRE copiado) e `tickets_id` (ticket de origem). Ver seção 14.
- `last_error`

### 5.3 `ltbpreasons` (cadastro de motivos)

- `id`, `code` (único), `title`, `description`, `is_active`, `date_creation`, `date_mod`
- Sem exclusão de motivo usado (L9).

### 5.4 `ltbpevents` (histórico)

Mesmo padrão do PRE (D24): uma linha por evento (`ltbps_id`, `items_id_line` opcional, `event`,
`users_id`, `date_creation`, `reason`, `details` JSON), espelhada no histórico nativo do GLPI.
Eventos: `created`, `line_added`, `line_removed`, `issued`, `signed_uploaded`, `sent_to_patrimony`,
`written_off`, `completed`, `canceled`, `line_document_attached`.

### 5.5 Configuração

Seção própria na tela de configuração do plugin, chaves prefixadas `ltbp_` (D16 do PRE):

- Mapeamento de `State`: "Aguardando baixa", "Em processo de baixa", "Baixado". Nunca são criados
  automaticamente; a tela oferece o botão **+** do próprio campo. "Emitir" e "Confirmar baixa"
  ficam bloqueados com o mapeamento incompleto. É recomendável que "Aguardando baixa" seja o mesmo
  `State` que o PRE usa (o PRE tem o seu mapeamento próprio).
- Nome e cargo do Diretor de TI e do Diretor Administrativo.
- `ltbp_completion_require_document` (padrão ligado, L12).
- `ltbp_default_reason_unrepairable` e `ltbp_default_reason_quote_rejected`: motivo padrão por
  resultado do PRE (L24), opcionais.
- `ltbp_solve_ticket_on_completion` (padrão desligado, L25).
- Acesso ao cadastro de motivos (5.3).

### 5.6 Integridade

- Um ativo (`itemtype`, `items_id`) só pode estar em **um laudo ativo** por vez. Não dá para
  expressar em índice único; é verificado em código, com reserva atômica. Há também um índice
  comum em (`itemtype`, `items_id`), que serve ao hook de bloqueio (seção 8).
- Numeração: restrição única em `number` com nova tentativa em colisão.
- Índice único em (`ltbps_id`, `itemtype`, `items_id`).

## 6. Máquina de estados

`Rascunho` → `Aguardando assinaturas` → `Assinado` → `No patrimônio` → `Baixado` → `Concluído`;
`Cancelado` a partir de qualquer estado anterior a `Baixado`.

| Estado | Ação do técnico | Efeito nos ativos |
|---|---|---|
| `Rascunho` | Define destinação, escolhe ativos, escolhe um motivo por linha | nenhum |
| `Aguardando assinaturas` | **Emitir**: valida, congela o PDF, copia signatários e motivos (snapshot) | guarda `states_id_before`; status → "Em processo de baixa" |
| `Assinado` | Anexa o PDF assinado (obrigatório) | nenhum |
| `No patrimônio` | Registra data do envio e quem recebeu | nenhum |
| `Baixado` | Patrimônio confirmou: data, nº do processo (opcional), anexo opcional | status → "Baixado"; o **bloqueio de edição começa** (L14) |
| `Concluído` | Data, beneficiário (Fornecedor) e comprovante (L12). Estado final | nenhum |
| `Cancelado` | Só antes de `Baixado`; motivo obrigatório | restaura `states_id_before` (ver 6.2) |

`Assinado` e `No patrimônio` são estados separados de propósito: são esperas diferentes
(diretoria e setor de patrimônio), cada uma com o seu prazo.

### 6.1 Validações da emissão

- Destinação definida e pelo menos uma linha.
- Todas as linhas com motivo ativo.
- Mapeamento de `State` completo, diretores configurados.
- Nenhuma linha em outro laudo ativo nem em linha ativa de PRE (L8).
- A emissão é **tudo ou nada em uma transação**: não há chamada externa (diferente do envio do
  PRE), então uma falha desfaz o laudo inteiro. Se a medição mostrar lentidão com muitos ativos
  (ver R-2), adota-se o modelo de uma linha por requisição da D15 do PRE.

### 6.2 Cancelamento

Restaura `states_id_before` em cada linha, mas **só se o status atual do ativo ainda for "Em
processo de baixa"**. Se alguém mudou o status à mão nesse intervalo, o cancelamento não
sobrescreve e registra um aviso no evento `canceled`.

## 7. Fluxos

- **Criar**: técnico cria o laudo em `Rascunho` (entidade ativa, número gerado). Escolhe a
  destinação, importa ativos (L7, lista de candidatos + busca), escolhe o motivo de cada linha.
  Só ativos visíveis na entidade do laudo (mesma regra do PRE).
- **Emitir**: 6.1. O PDF (seção 9) é gerado e anexado como `documents_id_frozen`.
- **Anexar assinado**: upload do PDF escaneado, com substituição permitida até "No patrimônio"
  (gera evento a cada troca).
- **Enviar ao patrimônio**: data e quem recebeu.
- **Confirmar baixa**: data, nº do processo (opcional), observação, anexo opcional. Muda os ativos
  para "Baixado" (tudo ou nada em uma transação, como a emissão).
- **Concluir**: data, beneficiário (Fornecedor ativo), observação e comprovante(s) (L12).
  Encerra o laudo.
- **Cancelar**: 6.2.

O beneficiário precisa estar cadastrado como Fornecedor **e ativo** (o GLPI só lista fornecedores
ativos nos dropdowns; ver o aviso de `is_active` no CLAUDE.md).

## 8. Bloqueio de edição do ativo baixado (L14)

- **Mecanismo**: `$PLUGIN_HOOKS[Hooks::PRE_ITEM_UPDATE]['gac']` para os tipos de ativo. O callback
  procura o ativo em `ltbpitems` cujo laudo esteja `Baixado` ou `Concluído`; se achar e o usuário
  não tiver o direito "Editar ativo baixado", zera `$item->input = []` e avisa com
  `Session::addMessageAfterRedirect`. Os campos `comment` e os vínculos de documentos passam.
- **Cobertura verificada no código do GLPI 11.0.8**: o hook dispara em todo `->update()`, que é o
  caminho da tela de detalhes, da API e da ação de "agente inativo → mudar status"
  (`Agent.php`). As classes de `src/Glpi/Inventory/Asset/` também chamam `->update()`.
- **Não usado**: `Lockedfield`. É um recurso do inventário: uma linha por campo e por ativo,
  impede só o agente de sobrescrever, e a edição pela tela continua liberada.
- **Passagem do próprio módulo**: as mudanças de status feitas pelo laudo (emissão, baixa,
  cancelamento) passam por um guarda de escopo curto (`LtbpGuard`), para o plugin não se
  bloquear.
- **Desempenho**: a verificação só roda quando o `input` tem campos alterados, com uma consulta
  indexada e cache por requisição.
- **Tipos de ativo**: usa a lista de classes de ativo do GLPI (`$CFG_GLPI['asset_types']`), que
  inclui os ativos personalizados (mesma base da D29 do PRE). Como registrar o hook para
  ativos personalizados gerados em tempo de execução precisa ser verificado (R-1).

## 9. PDF

- **mPDF**, **retrato**: `'format' => 'A4'` (L16), com margens e larguras de coluna pensadas para
  6 colunas em uma folha vertical. Nomes e séries longos quebram linha.
- Preto e branco: cabeçalhos de tabela em cinza claro único e bordas de um só tom (mesmo critério
  da D28 do PRE).
- Cabeçalho: logomarca e dados da entidade, com o mesmo `LogoFit` e a mesma cadeia de entidades
  do PRE.
- Conteúdo: título; tabela de dados (número, emissão, técnico, destinação); **objetivo**; tabela
  de bens (equipamento com **nome**, tipo, marca, modelo, série, patrimônio, código do motivo);
  **legenda** só dos motivos usados; **destinação recomendada**; **conclusão**; **três blocos de
  assinatura** (técnico, Diretor de TI, Diretor Administrativo) com nomes e cargos do snapshot.
- Local e data: "Cidade/UF, dd/mm/aaaa" (`dd/mm/aaaa`, sem depender do locale), com cidade e UF
  vindas da entidade (L15). Se a entidade não tiver cidade ou UF, imprime só a data (sem
  bloquear a emissão).
- Rodapé: número do laudo e "Página X de Y", para ligar o papel assinado ao documento emitido, e
  a "URL da aplicação" do GLPI (mesma regra da D27 do PRE).
- Gerado na emissão e anexado (L17). Sem nova renderização depois.

## 10. Permissões

Uma linha de direitos em `Features::all()` (`src/Features.php`), mostrada na aba única de perfil
(`ProfileRights`). Bits:

- ler;
- criar e editar rascunho;
- emitir e avançar etapas (anexar assinado, enviar, confirmar baixa, concluir);
- cancelar;
- **Configurar** (`Features::RIGHT_CONFIG`, já padrão, controla a seção de configuração);
- **Editar ativo baixado** (libera o bloqueio da seção 8).

O menu lateral do plugin ganha a entrada do laudo apenas para quem tem direito (novo item em
`GacMenu::getMenuContent()`), e a seção de configuração entra em `Config::sections()`.

## 11. Estrutura de código (rascunho)

Segue o layout do PRE. Regras puras, sem GLPI, em `src/Ltbp/` (por exemplo `StateMachine`,
`LtbpNumber`, `LtbpSettings`, `EmissionValidator`, enums), com testes em `tests/Unit/`.
Classes ligadas ao GLPI ao lado: `Ltbp`, `LtbpItem`, `LtbpReason`, `LtbpEvent`, os serviços
(emissão, confirmação, conclusão, cancelamento), `LtbpGuard` e `PdfRenderer` próprio (retrato).

Por causa do sub-namespace, valem as mesmas regras do PRE: cada classe de dados sobrescreve
`getTable()`, cada coluna de busca declara `'itemtype' => self::class`, e os arquivos de front
ficam em `front/ltbp/`. Twig em `templates/ltbp/`, AJAX em `ajax/`, JS em `public/js/`. Peças
neutras do PRE (`EntityChain`, `LogoFit`, gerador de número) só são extraídas para um namespace
compartilhado se o LTBP realmente precisar delas, e sem alterar o comportamento do PRE.

## 12. Erros, logs e testes (rascunho)

- Erros: emissão e confirmação da baixa são transações únicas; falha desfaz tudo e mostra a causa
  (linha e mensagem). Erros técnicos vão para o log do plugin.
- Testes: as regras puras têm testes unitários independentes do GLPI (mesma suíte
  `phpunit.unit.xml`). O restante é validado por um roteiro manual (`docs/ltbp-manual-tests.md`,
  a criar), com atenção especial ao bloqueio: tela de detalhes, API e inventário real.

## 13. Pendências e riscos

- **R-1**: registrar o hook de bloqueio para ativos personalizados (classes geradas em tempo de
  execução). A lista `$CFG_GLPI['asset_types']` deve resolver, mas precisa ser confirmada.
- **R-2**: medir a emissão e a confirmação com muitos ativos (por exemplo 200). Se passar do
  aceitável, mudar para uma linha por requisição (D15 do PRE).
- **R-3**: um equipamento baixado que ainda tenha agente de inventário pode gerar um **ativo
  novo** (se as regras de identificação não o reconhecerem). O hook só protege o ativo
  existente. Mitigação operacional: desinstalar ou desativar o agente antes do descarte.
- **R-4**: o comportamento do bloqueio com uma importação real de inventário não foi
  exercitado; só a leitura do código. Testar antes de dar como pronto.
- **R-5**: nada impede que um ativo já `Baixado` seja escolhido para um novo PRE. Avaliar como
  ajuste do PRE em um passo posterior.
- **R-6**: a validade do papel escaneado e da futura assinatura eletrônica depende do setor de
  patrimônio. Confirmar com eles antes de iniciar o sub-projeto de assinatura eletrônica.
- **R-7**: o ticket de origem de uma linha do PRE com destino `Baixa` fica Pendente
  ("Aguardando baixa patrimonial") até a conclusão do laudo. Só é solucionado se
  `ltbp_solve_ticket_on_completion` estiver ligado (L25); com o padrão desligado, o técnico
  fecha o ticket à mão, agora com o histórico dos marcos no acompanhamento.
- Contrato com o PRE: ver seção 14.

## 14. Integração com o PRE

Origem: as linhas do PRE devolvidas com destino `writeoff` (tabela
`glpi_plugin_gac_repairprotocolitems`). No retorno, o PRE já define o status do ativo como
"Aguardando baixa" e deixa o ticket Pendente com o motivo "Aguardando baixa patrimonial".
O PRE **não sabe a destinação** (descarte ou doação): quem a define é o laudo.

### 14.1 Decidido

| # | Decisão | Motivo |
|---|---|---|
| L19 | O gatilho fica na **aba Itens do PRE**: linha `Devolvida` com destino `Baixa` e sem laudo mostra o selo "Aguardando laudo" e uma caixa de seleção; o botão em lote **"Adicionar a laudo"** leva as marcadas para um laudo em `Rascunho` existente ou cria um novo (escolhendo a destinação). O botão só aparece se o usuário tiver direito de criar ou editar rascunho de LTBP | Decisão do dono. Atende também os retornos antigos e o lote, e não altera a transação do retorno |
| L20 | O retorno do PRE **não é alterado**: nada é adicionado ao laudo dentro da transação do retorno | Se a adição falhasse, desfaria um retorno que já mexeu no ticket e no ativo; a D19 do PRE já segue o mesmo princípio para os anexos. E o retorno não conhece a destinação |
| L21 | "Pendente de laudo" = linha do PRE `Devolvida` com destino `Baixa`, cujo ativo **não está em nenhum laudo não cancelado**. Laudo cancelado devolve o ativo à lista; ativo em laudo `Concluído` sai dela, mesmo com o PRE ainda mostrando `Baixa` | Evita oferecer de novo um ativo já tratado |
| L22 | **Fronteira entre os módulos.** A regra fica toda no LTBP, em um serviço (`LtbpLinker`); o PRE só mostra o selo e chama esse serviço. Leituras cruzadas ficam restritas: o LTBP lê as linhas do PRE (candidatos, origem e a regra L8), e o PRE lê `Ltbp::activeLaudoFor(itemtype, items_id)` para o selo. O LTBP **nunca escreve no PRE** | Mantém os módulos isolados (CLAUDE.md) com o mínimo de dependência |
| L23 | A linha do LTBP guarda a origem (`pre_items_id`, `pre_number`, `tickets_id`), e a lista de candidatos mostra de onde cada ativo veio (número do PRE, ticket, resultado, descrição do serviço) | Rastreio nos dois sentidos, e o técnico vê por que o ativo está na lista |

### 14.2 Decidido: motivo padrão e ticket de origem

| # | Decisão | Motivo |
|---|---|---|
| L24 | **Motivo padrão por resultado.** A configuração mapeia "Sem conserto" e "Orçamento não aprovado" para um motivo do cadastro (`ltbp_default_reason_unrepairable`, `ltbp_default_reason_quote_rejected`). Ao adicionar uma linha vinda do PRE, o motivo já vem selecionado e editável. Sem mapeamento, a linha entra sem motivo | Decisão do dono: economizar a digitação do motivo, que quase sempre se repete por resultado |
| L25 | **Acompanhamentos no ticket de origem.** Nos marcos emissão, baixa confirmada e conclusão, o LTBP registra um acompanhamento no ticket de origem da linha (só linhas vindas do PRE). Ao concluir, o ticket só é solucionado se `ltbp_solve_ticket_on_completion` estiver ligado (padrão **desligado**, no mesmo espírito conservador da D8), e vale a regra da D17: com outras linhas ainda ativas do mesmo ticket (em qualquer PRE, ou em outro laudo não concluído), o ticket não é solucionado. Ticket já fechado à mão é ignorado sem erro. A falha no ticket vira aviso e não desfaz o avanço do laudo (princípio da D19) | Decisão do dono. Sem isso o ticket ficaria Pendente para sempre (R-7) |

### 14.3 Limites

- A linha do PRE continua `Devolvida` com destino `Baixa` para sempre; o andamento do laudo só
  aparece no selo e no vínculo, não no estado da linha.
- Ativos vindos da busca livre não têm ticket de origem, então não recebem acompanhamentos (L25)
  nem motivo padrão (L24).
- Adicionar a laudo não altera o status do ativo: ele só muda na emissão (L2).
