# PRE — Protocolo de Reparo de Equipamento: design

Data: 2026-09-24 · Plugin: `gac` (GLPI 11.0.x, PHP >= 8.2) · Namespace: `GlpiPlugin\Gac`

Este documento é a fonte de verdade do subprojeto PRE. Toda decisão tomada na sessão de
brainstorming está registrada aqui, com o motivo. Se o código divergir deste documento, um
dos dois está errado e deve ser corrigido.

## Status das decisões

- **Decidido**: confirmado pelo dono do projeto. Seções 1 a 10, 12 e 13.
- **Rascunho**: seções 11 (estrutura de código) e 14 (erros e testes), ainda sem revisão do dono.
- **Pendente**: ver seção 15.

## 1. Contexto e objetivo

O PRE é o documento que a TI do Grupo Aparício Carvalho (GAC) emite para enviar equipamentos
à assistência técnica de um fornecedor e acompanhar o retorno deles.

Hoje o processo é feito por um app Django separado (`morefunctionsforglpi`, `apps/reports`):
os técnicos abrem tickets no GLPI com o ativo associado, e o app importa esses tickets via API
REST do GLPI, monta o protocolo e gera um PDF com WeasyPrint a partir de
`relatorio_protocolo_reparo.html`.

O PRE do plugin substitui esse fluxo dentro do próprio GLPI e **amplia** o ciclo. O app atual só
cobre a ida (`RASCUNHO` e `FINALIZADO`, sem retorno, sem dados de reparo, sem destino do
equipamento).

Diferenças conhecidas do app atual, que o plugin corrige:

- `get_glpi_item_details_api` usa só `data_link[0]`: ticket com 2 ativos perde o segundo em
  silêncio.
- A numeração `PRE-AAAA-NNN` é "último + 1" sem trava (colisão sob concorrência).
- A coluna Observação vem de scraping do HTML da descrição do ticket, e vai impressa para um
  terceiro sem revisão.
- O PDF depende de WeasyPrint/GTK3 (falha de instalação já tratada no `views.py`).

## 2. Escopo

Dentro: criação do PRE, importação de tickets, envio, retorno por linha, encerramento
automático, reabertura, geração e arquivamento do PDF, configuração, permissões, empacotamento
de release.

Fora da v1 (decidido):

- Documento de retorno em PDF (o retorno é registrado no sistema).
- QR code e assinatura digital no PDF.
- Retorno em lote (mass action). Entra depois se o uso pedir.
- Anexar o PDF a cada ticket (o acompanhamento cita o número do PRE).
- O laudo de baixa patrimonial (outro subprojeto; ver seção 12).
- Migração dos PREs do app Django (D14: não será feita).

## 3. Glossário

- **PRE**: o documento (cabeçalho), um fornecedor por PRE.
- **Linha**: um par ticket + ativo dentro de um PRE. É a unidade do fluxo.
- **Resultado**: o que o fornecedor concluiu sobre o equipamento.
- **Destino**: o que a empresa faz com o equipamento depois do resultado.
- **Linha ativa**: linha em `Aguardando envio` ou `Na assistência`.

## 4. Decisões de escopo e regras de negócio

| # | Decisão | Motivo |
|---|---|---|
| D1 | A linha do PRE é **ticket + ativo**, com snapshot dos dados | O retorno, o resultado e a baixa acontecem por equipamento; um ticket pode ter vários ativos; um ativo pode ser reenviado |
| D2 | Um ticket é elegível se a categoria ITIL está na lista configurada (com subcategorias, opção ligada por padrão), está aberto, tem >= 1 ativo ligado e o par ticket + ativo não tem linha ativa | Recurso nativo do GLPI, filtro simples, sem scraping |
| D3 | As categorias elegíveis ficam em configuração, escolhidas por dropdown de `ITILCategory` | IDs diferem entre dev e produção; digitar ID é frágil |
| D4 | "Enviar" faz, por linha: acompanhamento no ticket, ticket Pendente com motivo de pendência, e mudança do status do ativo guardando o status anterior | O inventário não pode dizer "em estoque" para um equipamento no fornecedor |
| D5 | Status do ativo (`State`) nunca é criado automaticamente. A configuração mapeia papéis para `State` existentes e oferece o botão **+** do próprio campo ("Criar novo"). "Enviar" fica bloqueado com mapeamento incompleto | Não sujar a tabela de `State`; falhar antes de começar um lote |
| D6 | No retorno o técnico registra: data, resultado, destino, descrição do serviço, custo, nº da OS ou nota do fornecedor, prazo de garantia. O custo vira também um `TicketCost` no ticket | O custo acumulado por ativo sustenta o laudo de baixa; a garantia responde "voltou a quebrar" |
| D7 | Quatro resultados mais um caso de exceção (seção 6). Separação entre resultado e destino | Dois resultados com defeito não levam ao mesmo destino |
| D8 | A ação sobre ticket e ativo no retorno é **configurável por resultado**, dentro de um conjunto fixo de ações. Padrões conservadores | Pedido do dono. O padrão evita fechar ticket sem teste do equipamento |
| D9 | O PRE encerra **sozinho** quando todas as linhas estão em estado final. Reabertura só com permissão própria, motivo obrigatório e histórico | Cada retorno já é uma ação explícita; um botão "Encerrar" seria uma tarefa extra |
| D10 | O PDF é gerado no servidor com **mPDF**, empacotado no `vendor/` do plugin | Prioridade do dono: relatório bonito, mesmo com pacote maior |
| D11 | O PDF definitivo é gerado no "Enviar" e anexado ao PRE como `Document`; depois, o download entrega esse arquivo, nunca uma nova renderização | O documento que o fornecedor recebeu não pode mudar |
| D12 | O PDF imprime "Descrição para o fornecedor", campo editável por linha em `Rascunho`, e não o texto cru do ticket | O PDF sai da empresa; o texto do ticket pode ter dado interno |
| D13 | O release é montado por um workflow próprio de CI, sem restaurar o CI do template | Decisão do dono; ver seção 13 |
| D15 | O "Enviar" roda **uma linha por requisição**, dirigido pelo navegador com barra de progresso, com reserva atômica da linha e "Remover linha com falha" como saída para linha que sempre falha | Evita timeout do PHP e sessão presa em PREs grandes; não depende do cron do GLPI; reaproveita a linha como unidade transacional |
| D16 | A tela de configuração do plugin é organizada em **seções por módulo**; o PRE tem a sua ("Protocolo de Reparo de Equipamentos"), com chaves `pre_*` e classe de configuração própria. Funcionalidades futuras entram como novas seções na mesma tela | Pedido do dono; mantém cada funcionalidade isolada (regra do CLAUDE.md) |
| D17 | Ticket com mais de um ativo: a ação de ticket do resultado só é aplicada quando a linha que voltou é a **última linha ativa daquele ticket** (em qualquer PRE). Antes disso o ticket mantém status e motivo e recebe só o acompanhamento-resumo; a ação sobre o ativo vale sempre, por linha | Decisão do dono: um ticket com um equipamento ainda fora não pode ser reaberto nem solucionado por causa do outro |
| D18 | O custo criado no ticket se chama `Fornecedor - Nº OS/NF - Ativo` (partes vazias omitidas) | Decisão do dono: identificação legível na aba Custos |
| D19 | O formulário de retorno aceita vários documentos, opcionais; cada um vira um Documento do GLPI ligado ao ticket da linha e ao PRE (o mesmo arquivo, sem cópia). Os arquivos são anexados depois do commit do retorno: um arquivo recusado gera aviso e não desfaz o retorno. Evento `line_document_attached` | Decisão do dono: o laudo ou a nota do fornecedor ficam na aba Documentos do ticket. A correção pós-reabertura aceita anexos do mesmo jeito |
| D20 | Os formulários de retorno, extravio e correção são enviados por XHR (`data-gac-ajax-form`, `pre.js`): o servidor responde JSON, só a aba Itens é recarregada e o scroll é mantido; o resultado aparece num aviso flutuante. O cabeçalho da aba principal (status do PRE) só atualiza ao recarregar a página | Decisão do dono: PRE com dezenas de itens voltava ao topo a cada retorno |
| D21 | Nos formulários de retorno e de correção, resultado e destino usam o dropdown do GLPI (`dropdownArrayField`, select2) e as datas usam o seletor de datas do GLPI (`dateField`, flatpickr). O que depende do resultado (mostrar o destino) escuta o evento pelo jQuery, e a aba Itens recarregada por XHR é inserida por fragmento, para os scripts dos componentes rodarem de novo | Decisão do dono: usar os componentes nativos, iguais aos do resto do GLPI |
| D22 | Quando há cartões de retorno ou de correção visíveis, a aba Itens mostra o botão "Recolher lista de itens", que esconde a tabela de itens; a escolha fica guardada no navegador (`localStorage`, por PRE) e sobrevive a recarregar a página e ao envio por XHR | Decisão do dono: com dezenas de itens, a tabela empurra os cartões de retorno para baixo |
| D23 | Cada seção de módulo da página de configuração do plugin pode ser recolhida pelo botão do cabeçalho (collapse do Bootstrap); a escolha fica guardada no navegador por seção. O botão Salvar fica dentro da área recolhível | Decisão do dono: a seção do PRE é longa |
| D24 | Os eventos do PRE aparecem só na aba nativa "Histórico" do GLPI: cada evento, além de gravado em `glpi_plugin_gac_repairprotocolevents`, gera uma entrada de texto no histórico nativo (`Log::history`), sobre a opção de busca oculta 90 "Evento", para a coluna "Campo" mostrar "Evento". A aba própria "Histórico" do plugin foi removida. O evento `created` não é espelhado (o GLPI já registra a criação). Os eventos anteriores foram copiados uma vez para o histórico nativo | Decisão do dono: uma aba só. O tipo de entrada "mensagem simples" do GLPI deixa o "Campo" vazio, por isso se usa a entrada de alteração de campo; o texto aparece como "Mudança de (vazio) para <evento>", formato fixo do GLPI |
| D25 | A busca da logomarca sobe a cadeia de entidades com `EntityChain::parentOf()`: o pai da raiz pode ser `-1` ou NULL (bancos migrados), e um pai igual à própria entidade, negativo ou inválido encerra a busca; há também limite de profundidade e controle de entidades já visitadas | Erro de produção: com o pai da raiz NULL, `(int) NULL` apontava a raiz para ela mesma e a busca ficava em laço, prendendo um worker do PHP a cada PDF |
| D26 | O cabeçalho do PDF é compacto: a logomarca é ajustada, mantendo a proporção, a uma caixa de 60 x 14 mm (`LogoFit`, calculada no PHP porque o mPDF ignora `max-width` e `max-height` em imagens), e os dados da empresa usam fonte de 7,2 pt (nome em 9 pt), com menos espaço abaixo do cabeçalho | Decisão do dono: reduzir a área do cabeçalho onde fica a logomarca |
| D14 | **Sem migração** dos PREs do app Django. O app antigo vira arquivo de consulta; o plugin começa do zero, com a sequência de numeração começando em 1, **sem campo de valor inicial** | Decisão do dono: não há itens pendentes no fornecedor, o custo da migração não compensa, e a repetição de números com o app antigo é aceita sem ressalvas. Ver R-4 |

## 5. Modelo de dados

Prefixo `glpi_plugin_gac_`. Tipos exatos ficam para o plano de implementação.

### 5.1 `repairprotocols` (PRE)

- `id`, `entities_id`, `number` (único, `PRE-AAAA-NNN`), `status`
- `suppliers_id` e o nome do fornecedor copiado
- `users_id_tech` (técnico responsável)
- `date_issued`, `date_sent`, `date_closed`
- `documents_id_sent` (PDF definitivo)
- `date_creation`, `date_mod`

### 5.2 `repairprotocolitems` (linha)

- Chave: `protocols_id`, `tickets_id`, `itemtype`, `items_id`
- Snapshot na importação: nome, série, patrimônio, título do ticket, observação do ticket
- `description_supplier`: texto editável em `Rascunho`, inicia com a observação do snapshot; é o
  que o PDF imprime
- Ciclo: `states_id_before` (status do ativo antes do envio), `status`, `outcome`, `destination`
- Retorno: `date_return`, `service_description`, `cost`, `supplier_ref`, `warranty_until`,
  `ticketcosts_id`
- Extravio: `lost_reason`
- `last_error`

### 5.2.1 `repairprotocolevents` (histórico do PRE)

Registro de eventos do PRE (fonte de verdade do estado de reabertura). Cada evento é espelhado como uma linha de texto no histórico nativo do GLPI, que é a única aba "Histórico" (D24). Uma linha por evento: `protocols_id`,
`items_id_line` (opcional, quando o evento é de uma linha), `event` (`created`, `sent`,
`line_returned`, `line_lost`, `line_removed`, `closed`, `reopened`, `line_corrected`, `canceled`), `users_id`,
`date_creation`, `reason` (texto) e `details` (JSON com o antes e o depois, nas correções).

O **motivo da reabertura** é gravado aqui, em `reason`, no evento `reopened` (obrigatório). O
`Log` nativo do GLPI registra a mudança de campos do PRE (por exemplo o `status`), mas não guarda
texto livre de motivo; por isso a tabela própria.

### 5.3 Configuração

**Organização da tela (D16).** A tela de configuração do plugin `gac` é dividida em **seções por
módulo**. Tudo que é do PRE fica numa seção própria, "Protocolo de Reparo de Equipamentos". Cada
funcionalidade futura (por exemplo o laudo de baixa) traz a sua seção na mesma tela. Não há
configuração "solta" fora de uma seção de módulo, exceto o que for realmente do plugin inteiro.

**Armazenamento.** Em `glpi_configs` (contexto `plugin:gac`), com as chaves de cada módulo
prefixadas (`pre_...`), para uma seção nunca colidir com a de outro módulo. Cada módulo declara as
suas próprias chaves, valores padrão, validação e o bloco de formulário da sua seção (seção 11).

**Chaves do PRE:** categorias elegíveis, incluir subcategorias, mapeamento
de `State`, motivos de pendência, tabela de ações por resultado e categoria de documento da logo.
O cabeçalho da empresa vem da entidade (seção 10), sem
tabela própria.

### 5.4 Integridade

- Um par (ticket, itemtype, items_id) só pode estar em **uma linha ativa** por vez. Não dá para
  expressar em índice único; é verificado em código, dentro de transação.
- Numeração: restrição única em `number` com nova tentativa em colisão.
- Índice único em (`protocols_id`, `tickets_id`, `itemtype`, `items_id`), como no app atual.

## 6. Máquinas de estado

### 6.1 PRE

`Rascunho` → `Enviado` → `Retorno parcial` → `Encerrado`; `Cancelado` só a partir de `Rascunho`.

| Transição | Gatilho |
|---|---|
| `Rascunho` → `Enviado` | O "Enviar" começa |
| `Enviado` → `Retorno parcial` | A primeira linha vira `Devolvida` ou `Extraviada` |
| `Retorno parcial` → `Encerrado` | Todas as linhas em estado final (automático) |
| `Rascunho` → `Cancelado` | Ação do técnico |
| `Encerrado` → `Retorno parcial` | Reabertura (permissão própria, motivo, histórico) |

`Retorno parcial` e `Encerrado` são **calculados** a partir das linhas, nunca digitados. Um PRE
`Encerrado` é somente leitura, exceto a reabertura.

### 6.2 Linha

`Aguardando envio` → `Enviando` → `Na assistência` → `Devolvida` | `Extraviada`. `Enviando` é um
estado transitório que reserva a linha durante o envio (ver 7.3); se o processamento falhar, a
linha volta a `Aguardando envio` com `last_error`. `Devolvida` e `Extraviada` são estados finais.

A linha só pode ser removida em `Rascunho`, com uma exceção: **"Remover linha com falha"**, para
uma linha em `Aguardando envio` de um PRE já `Enviado`, com motivo obrigatório gravado no
histórico (evento `line_removed`).

### 6.3 Resultado e destino (linha `Devolvida`)

| Resultado | Descrição |
|---|---|
| Reparado | Voltou consertado |
| Sem defeito encontrado | Voltou sem reparo porque nada foi encontrado |
| Sem conserto | Irrecuperável, com defeito |
| Orçamento não aprovado | Não foi reparado por recusa de orçamento, com defeito |

Extravio não é um resultado: é o estado `Extraviada`, com `lost_reason` obrigatório e
acompanhamento no ticket.

Destino: `Baixa`, `Manter com defeito` ou `nenhum`. Nos dois resultados com defeito o **técnico
escolhe o destino no retorno**, com pré-seleção editável por linha:

- Sem conserto → `Baixa`
- Orçamento não aprovado → `Manter com defeito`

### 6.4 Ações padrão por resultado e destino (D8)

Ações de ticket disponíveis: manter Pendente com motivo X · Reabrir (Em atendimento) · Solucionar.
Ações de ativo: restaurar o status anterior · definir o status Y.

| Resultado / destino | Ticket | Ativo |
|---|---|---|
| Reparado | Reabrir | Restaurar status anterior |
| Sem defeito encontrado | Reabrir | Restaurar status anterior |
| Com defeito → Baixa | Pendente, motivo "Aguardando baixa patrimonial" | Definir "Aguardando baixa" |
| Com defeito → Manter com defeito | Pendente, motivo "Aguardando decisão" | Definir "Com defeito" |

O padrão de fábrica nunca fecha ticket. O dono pediu configurabilidade; o risco (alguém configurar
"Reparado → Solucionar" e o equipamento não ser testado) foi discutido e aceito.

## 7. Fluxos

### 7.1 Criar PRE

Menu do plugin → lista (busca nativa do GLPI) → "Novo": fornecedor (dropdown de `Supplier`),
técnico (padrão: usuário logado), entidade. Salvar cria `Rascunho` com número gerado.

### 7.2 Importar tickets (só `Rascunho`)

Lista de tickets elegíveis (D2). Ticket com N ativos mostra N opções; cada ativo escolhido vira
uma linha, com snapshot. O técnico pode remover linhas e editar `description_supplier` em
`Rascunho`. Prévia do PDF com marca d'água "RASCUNHO".

### 7.3 Enviar

Pré-checagens, **antes de tocar em qualquer coisa**: fornecedor definido, >= 1 linha,
mapeamento de `State` completo e válido para a entidade de cada ativo (seção 10), cada ticket
ainda aberto e cada par ainda sem linha ativa em outro PRE.

**Execução em passos curtos (D15).** O botão "Enviar" move o PRE para `Enviado` e abre uma barra de
progresso. O navegador chama um endpoint que processa **uma linha** por requisição e repete para
a próxima, em sequência. Cada requisição deve durar bem menos que o `max_execution_time` do PHP e
não segura a sessão do GLPI por muito tempo. Não depende do cron do GLPI.

Por linha, dentro de uma transação: acompanhamento no ticket, ticket Pendente com motivo,
`states_id_before` gravado, status do ativo alterado, linha em `Na assistência`. Ou faz tudo, ou
nada.

**Reserva da linha.** A linha é reservada por atualização condicional (`Aguardando envio` →
`Enviando`, só se ainda estiver `Aguardando envio`). Dois cliques ou dois técnicos nunca processam
a mesma linha duas vezes. Se a aba fechar no meio, as linhas já enviadas permanecem enviadas e
"Continuar envio" retoma as pendentes. Uma linha presa em `Enviando` por mais de alguns minutos
(requisição interrompida) volta a `Aguardando envio` na próxima tentativa.

**Falha por linha.** A linha volta a `Aguardando envio` com `last_error`; as demais seguem. O PRE
já está `Enviado`.

**PDF definitivo.** É a última etapa, numa requisição própria, gerada e anexada (D11) só quando
**todas** as linhas estão `Na assistência`. Enquanto houver linha pendente não há PDF definitivo.
Se uma linha falhar sempre, o técnico usa "Remover linha com falha" (seção 6.2) e o PRE segue com
as linhas restantes.

**Medição.** O plano inclui medir o tempo real de uma linha e de um PRE de 50 linhas no GLPI de
desenvolvimento antes de fixar qualquer parâmetro.

### 7.4 Registrar retorno (linha a linha)

Formulário com resultado, destino, data, serviço, custo, OS ou nota, garantia. Ao salvar:
aplicar as ações de D8 (a de ticket só se for a última linha ativa do ticket, D17); gravar `TicketCost` e acompanhamento-resumo; linha `Devolvida`; recalcular
o PRE (podendo encerrar). "Marcar como extraviada" exige justificativa.

### 7.5 Reabrir

Permissão própria e motivo obrigatório, gravado como evento `reopened` no histórico (seção 5.2.1);
cada correção posterior grava `line_corrected` com o antes e o depois. Libera somente a **correção dos dados de retorno** (custo,
OS, garantia, descrição). Corrigir o custo atualiza o `TicketCost` já criado. A reabertura **não
refaz** as ações no ticket nem no ativo: trocar o resultado registrado é uma correção manual.

## 8. Permissões

Um direito do plugin: leitura, criação, edição, exclusão de rascunho e três especiais:
**Enviar**, **Registrar retorno**, **Reabrir**.

## 9. Relatório (PDF)

- Conteúdo herdado do template atual: cabeçalho da empresa, fornecedor, data, técnico, nº do PRE,
  tabela (Ticket, Tipo, Equipamento, Patrimônio, Série, Descrição para o fornecedor), total,
  "Recebido em", assinaturas do fornecedor e do técnico. O título do ticket deixa de ser impresso
  (D12). Visual refeito.
- Motor: template Twig `templates/pre/report.html.twig` → HTML → mPDF. A paginação usa `{PAGENO}` e
  `{nb}` (o mPDF não suporta `counter(pages)` de `@page`). Layout em tabelas, porque flex e grid
  têm suporte limitado.
- Ciclo: prévia em `Rascunho`; definitivo no "Enviar"; depois, download do arquivo guardado.
- O mPDF usa fontes empacotadas (DejaVu e afins) só as necessárias, para conter o tamanho.

## 10. Entidades

O GLPI do GAC usa várias entidades (por exemplo por marca, como Fimca) e o PRE pode ser por
entidade. Decidido:

- **Escopo do PRE.** O PRE pertence a uma entidade (`entities_id`). São importáveis os tickets
  dessa entidade **e das suas subentidades**.
- **Cabeçalho do PDF.** Usa os dados do cadastro da `Entity` do PRE: nome, `registration_number`
  (CNPJ), `address`, `postcode`, `town`, `state`, `phonenumber`. Verificado no `Entity.php` do
  GLPI 11.0.8: existe `registration_number`, e **não existe campo de logo** na entidade.
- **Logomarca.** É um `Document` vinculado à entidade, cuja categoria de documento (`DocumentCategory`)
  é escolhida na configuração global. Havendo mais de um, vale o **último enviado**. Sem logo na
  entidade, sobe para as entidades pais até achar; sem nenhuma, o PDF sai sem logo.
- **Fornecedor.** Dropdown de `Supplier` da entidade, das subentidades e dos fornecedores marcados
  como recursivos em entidades pais (comportamento padrão do dropdown de entidade do GLPI).
- **Numeração.** Uma sequência global por ano (`PRE-AAAA-NNN`), para o fornecedor nunca ver dois
  documentos com o mesmo número.
- **Configuração global.** Categorias, mapeamento de `State`, motivos de pendência e ações por
  resultado valem para todas as entidades.

Consequência de ter subentidades numa configuração global: um `State` é ele próprio de uma entidade
e só serve a ativos de entidades onde é visível (entidade dele ou filhas, se recursivo). Por isso
o "Enviar" e o retorno **validam, antes de tocar em qualquer coisa**, que cada `State` mapeado é
válido para a entidade de cada ativo da linha. Se não for, a pré-checagem bloqueia e diz qual
`State` e qual ativo. A recomendação operacional é criar os `State` usados pelo PRE na entidade
raiz, com recursividade ligada.

## 11. Estrutura de código (rascunho)

Módulo isolado dentro do plugin (regra do CLAUDE.md: cada feature é um módulo):

- `src/Pre/RepairProtocol.php`, `RepairProtocolItem.php`: objetos `CommonDBTM`
- `src/Pre/ProtocolNumberGenerator.php`
- `src/Pre/EligibleTicketFinder.php`
- `src/Pre/SendService.php`, `ReturnService.php`, `ReopenService.php`
- `src/Pre/StateMachine.php`: transições e regras puras, sem acesso a GLPI
- `src/Pre/Report/PdfRenderer.php`
- `src/Config.php`: a tela de configuração do plugin, que só monta a página e percorre os módulos
  registrados, renderizando a seção de cada um. Não conhece as chaves de nenhum módulo.
- `src/Pre/PreConfig.php`: as chaves `pre_*`, padrões, validação e o bloco de formulário da seção
  "Protocolo de Reparo de Equipamentos" (template `templates/pre/config_section.html.twig`).
  Um módulo futuro segue o mesmo padrão: sua própria classe de configuração, registrada na tela.
- `templates/pre/*.html.twig`, `front/*.php` conforme o padrão de plugins do GLPI 11

Requisito prévio: `composer.json` ainda não tem `autoload` para `src/` nem `require` do mPDF.

## 12. Integração com a baixa patrimonial

O PRE só marca a linha com destino `Baixa` e guarda o vínculo (`protocols_id`, linha). O
subprojeto do laudo consome essas linhas (destino `Baixa` e ainda sem laudo). O PRE não cria
laudo. O contrato exato da integração é definido na spec do laudo.

## 13. Release e CI

Workflow próprio, disparado na criação da tag, que monta o pacote pronto para colar em
`plugins/`:

- Pasta raiz `gac/` (a chave do plugin é o nome da pasta).
- `composer install --no-dev --optimize-autoloader` no runner, com PHP 8.2.
- Exclui `tests/`, `docs/`, `.github/`, configs de lint, `var/`.
- Fontes do mPDF reduzidas ao necessário.
- Falha se a versão da tag divergir de `PLUGIN_GAC_VERSION` ou do `gac.xml`.

## 14. Erros, logs e testes (rascunho)

- Erros: cada linha do "Enviar" e do retorno é uma unidade transacional. Falha grava `last_error`,
  desfaz a linha e não afeta as outras. Erros técnicos vão para o log do plugin.
- Desempenho: o "Enviar" é dividido em requisições curtas (D15). O endpoint de linha é idempotente:
  chamado duas vezes para a mesma linha, a segunda não faz nada.
- Testes: o ambiente local não roda PHPUnit (decisão do CLAUDE.md: teste manual por ora). As
  regras puras (`StateMachine`, numeração, elegibilidade) ficam sem dependência de GLPI para
  serem testáveis quando houver um GLPI de desenvolvimento. O plano traz um roteiro de teste
  manual por fluxo.

## 15. Pendências e riscos

- ~~P-1~~ (migração dos PREs do app Django): **resolvida, sem migração** (D14). Fica o risco R-4.
- ~~P-6~~ (nomes reais dos `State` e dos motivos de pendência): **retirada**. Os papéis são
  escolhidos na tela de configuração (D5), então os nomes não importam ao código.
- **R-1**: o pacote fica grande por causa do mPDF (aceito pelo dono).
- **R-2**: a reabertura não corrige resultado errado (seção 7.5); é correção manual.
- **R-3**: o layout do PDF em mPDF não é idêntico ao do WeasyPrint atual; exige validação visual.
- **R-4** (risco **aceito** pelo dono, sem mitigação): o plugin começa a numeração em 1 (D14). Se
  entrar em produção no meio de um ano em que o app antigo já emitiu `PRE-AAAA-001`, os números
  se repetem entre os dois sistemas. Não há campo de valor inicial.

## 16. Ordem de construção (proposta)

1. Fundação: `composer.json` (autoload e mPDF), configuração e permissões.
2. PRE: CRUD, numeração, estados.
3. Elegibilidade e importação de tickets.
4. Enviar.
5. Registrar retorno, extravio, encerramento automático.
6. Reabertura.
7. PDF.
8. Workflow de release.
