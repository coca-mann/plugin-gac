# Monitor — Painel de Monitoramento de Tickets: design

Data: 2026-10-01 · Plugin: `gac` (GLPI 11.0.x, PHP >= 8.2) · Namespace: `GlpiPlugin\Gac\Monitor`

Este documento é a fonte de verdade do subprojeto Monitor. Toda decisão tomada na sessão de
brainstorming está registrada aqui, com o motivo. Se o código divergir deste documento, um dos
dois está errado e deve ser corrigido. Segue o formato das specs do PRE
(`2026-09-24-pre-design.md`) e do LTBP (`2026-09-26-ltbp-design.md`).

## Status das decisões

- **Decidido**: confirmado pelo dono do projeto. Seções 1 a 10.
- **Rascunho**: seções 11 (estrutura de código) e 12 (erros e testes), sem revisão do dono.
- **Pendente**: ver seção 13.

## 1. Contexto e objetivo

Hoje existe um painel de monitoramento de tickets no app Django separado
(`morefunctionsforglpi`, `apps/panel` + `apps/dbcom`): uma tela (pensada para TV/kiosk) que lista
os tickets abertos do GLPI, atualizada via WebSocket (Django Channels), com KPIs, acompanhamento
de projetos e controle remoto de qual tela cada display mostra. O objetivo deste subprojeto é
trazer **só a lista de tickets em tempo (quase) real** para dentro do plugin `gac`, como um passo
para descontinuar o app Django.

Diferenças conhecidas do app atual, que o plugin corrige:

- A query (`get_panel_data()` em `apps/dbcom/glpi_queries.py`) é SQL bruto contra uma conexão de
  banco separada (`db_glpi`, credenciais próprias fora do GLPI), com filtros específicos
  hard-coded no código (`gt.name NOT LIKE '%TECOM%'`, `NOT LIKE '%manutenção corretiva%'`) em vez
  de critério configurável.
- Não existe filtro por tela: todo display recebe a mesma query global. O pedido que motivou este
  subprojeto é justamente múltiplas telas, cada uma com seu próprio filtro (ex.: uma tela por
  entidade/unidade física).
- URLs de ativos ficam com o domínio de produção fixo no SQL
  (`https://centraldeservicos.grupoapariciocarvalho.com.br/...`).
- "Tempo real" depende de um servidor WebSocket (Django Channels + Redis) rodando ao lado do
  GLPI — infraestrutura que este plugin não tem e que o dono decidiu não replicar (seção 4, M5).

## 2. Escopo

Dentro: cadastro de telas de monitoramento (uma por unidade/finalidade), cada uma com entidade,
filtro de conteúdo via Pesquisa Salva do GLPI, colunas exibidas e ordem, intervalo de polling,
alerta sonoro/visual de ticket novo, exibição autenticada (técnico logado no GLPI) e exibição
pública por token (TV/kiosk sem login), configuração global (intervalo padrão, som padrão),
permissões.

Fora da v1 (decidido):

- Dashboard de KPIs (tickets abertos, tempo médio de resposta, satisfação) — existe no Django
  (`DashboardView.vue`), não entra aqui.
- Acompanhamento de projetos (`ProjectsView.vue`) — fora de escopo, tratado como subprojeto
  futuro separado se algum dia for necessário.
- Controle remoto de qual tela um display mostra (`RemoteControlView.vue`, modelo `Display` do
  Django) — não entra; cada Tela é uma URL fixa, sem comando remoto de troca de tela.
- Push real (WebSocket/SSE) — decidido polling (M5).
- Colunas livres (qualquer search option do Ticket) — decidido um conjunto curado (M4).

## 3. Glossário

- **Tela** (`MonitorScreen`): uma configuração de monitoramento — entidade, filtro, colunas,
  intervalo, público ou não. Corresponde a um display físico (TV) ou a uma aba que um técnico
  mantém aberta.
- **Pesquisa Salva** (`SavedSearch`): o mecanismo nativo do GLPI para guardar um conjunto de
  critérios de busca; aqui, referenciado pela Tela para definir o que aparece (status, categoria,
  urgência etc.), sempre compartilhada (`is_private = 0`).
- **Token público**: string opaca única por Tela que autentica a exibição sem sessão GLPI.

## 4. Decisões de escopo e regras de negócio

| # | Decisão | Motivo |
|---|---|---|
| M1 | Módulo, namespace e tabelas usam o nome **Monitor** (`Monitor`, `glpi_plugin_gac_monitor*`) | Sem sigla própria em português como PRE/LTBP; "Monitor" descreve a função sem ambiguidade com o Dashboard nativo do GLPI |
| M2 | **Entidade é campo próprio da Tela** (`entities_id` + `is_recursive`), fora do critério da Pesquisa Salva | Decisão do dono: várias telas ficam em unidades físicas diferentes (entidades diferentes). A exibição pública roda sem sessão, então não há "entidade ativa" herdada — precisa ser explícita e independente por Tela |
| M3 | O filtro de conteúdo (status, categoria, urgência etc.) vem de uma **Pesquisa Salva do GLPI** (`SavedSearch`, tipo Ticket) referenciada por ID, restrita a **compartilhadas** (`is_private = 0`) | Reaproveita a search engine nativa (`Search::getDatas`) em vez de reinventar um motor de filtro; restringir a compartilhadas evita que uma Tela quebre em silêncio se o dono de uma busca pessoal a editar ou apagar |
| M4 | As colunas exibidas são um **conjunto curado** (~11 campos voltados a monitoramento: ID, título, entidade, status, urgência/prioridade, categoria, solicitante, técnico atribuído, grupo atribuído, data de abertura, tempo decorrido), escolhidas e **ordenadas pelo admin por Tela** | Decisão do dono. Evita colunas inadequadas para uma tela de TV (textos longos, campos técnicos); a lista completa de search options do Ticket (centenas de campos) é demais para esse uso |
| M5 | Atualização por **polling AJAX**, intervalo configurável (padrão global, com override por Tela), não WebSocket/push real | Decisão do dono. GLPI roda em PHP síncrono (Apache/PHP-FPM); push real exigiria um processo separado (Node/Mercure/Soketi) que este ambiente não tem e que tiraria a simplicidade de "é só um plugin GLPI" |
| M6 | Exibição pública (sem login) usa uma **rota stateless** (`SessionManager::registerPluginStatelessPath`), autenticada por um **token opaco por Tela**, nunca a sessão GLPI | TVs ficam ligadas o dia todo sem ninguém logado; evita o problema de sessão expirando numa aba que nunca fecha |
| M7 | O diff de "ticket novo" (para o alerta) é calculado **no cliente (JS)**, comparando o conjunto de IDs do poll atual com o do poll anterior em memória — **sem estado de "últimos vistos" no servidor** | Não há sessão persistente do lado público para guardar esse estado; a página fica aberta o tempo todo, então memória do JS basta. Evita criar uma classe/tabela só para isso |
| M8 | Som/alerta de ticket novo é **configurável por URL** na configuração global (`monitor_alert_sound_url`, vazio por padrão — sem arquivo embutido no plugin), ligado/desligado por Tela (`alert_enabled`), **sem condição extra**: qualquer linha nova no resultado já filtrado dispara o alerta. Sem URL configurada, o alerta fica só visual (linha piscando), sem som | Decisão do dono (som entra em escopo). O próprio critério da Pesquisa Salva já define "o que conta" para aquela Tela; uma camada extra de condição de alerta seria redundante. Embutir um arquivo de áudio no plugin não é necessário: a mesma URL configurável que o Django já usava resolve, sem exigir um binário versionado no repositório |
| M9 | Dashboard de KPIs, projetos e controle remoto de tela ficam **fora da v1** | Decisão do dono: focar só na lista de tickets, que é o que falta para descontinuar o Django |

## 5. Modelo de dados

Prefixo `glpi_plugin_gac_`. Tipos exatos ficam para o plano de implementação.

### 5.1 `monitorscreens` (a Tela)

- `id`, `entities_id`, `is_recursive`
- `name`
- `savedsearches_id` (FK para `glpi_savedsearches`; validado na gravação: deve ser tipo Ticket e
  `is_private = 0`)
- `display_columns` (lista ordenada das chaves do catálogo curado, ex.: `["id", "title",
  "status", "urgency", "requester", "technician", "elapsed"]`)
- `poll_interval_seconds` (nullable; vazio usa o padrão global)
- `is_public` (bool), `public_token` (string, nullable, único — gerado quando `is_public` é
  ligado pela primeira vez; pode ser regenerado, invalidando URLs antigas)
- `alert_enabled` (bool)
- `is_active` (bool)
- `date_creation`, `date_mod`

### 5.2 Configuração global (`MonitorSettings`, contexto `plugin:gac`)

- `monitor_default_poll_interval_seconds` (padrão sugerido: 15)
- `monitor_alert_sound_url` (padrão: vazio — sem URL configurada, o alerta fica só visual)

### 5.3 Catálogo de colunas curado

Lista fixa no código (não no banco): chave, rótulo em pt-BR, e se é uma search option nativa do
Ticket (mapeamento exato para `Ticket::getSearchOptions()` fica para a implementação — não
adivinhar o índice numérico aqui) ou um valor **computado** (caso do "tempo decorrido", calculado
a partir da data de abertura, sem search option correspondente).

## 6. Fluxos

### 6.1 Administração (CRUD de Tela)

`front/monitor/monitorscreen.php`: formulário padrão `CommonDBTM` com os campos da seção 5.1. O
dropdown de Pesquisa Salva só lista `SavedSearch` do tipo Ticket com `is_private = 0`. A seleção
de colunas é uma lista ordenável (o admin define a sequência de exibição). A listagem usa
`Search::show()` padrão do GLPI — busca e filtro de graça, relevante com 10+ telas cadastradas.

### 6.2 Exibição autenticada

`front/monitor/display.php?id=X` — um técnico abre dentro do GLPI, autenticado normalmente,
atrás do direito de leitura do módulo (seção 9).

### 6.3 Exibição pública

`front/monitor/public.php?token=Y` — **arquivo próprio**, não o mesmo `display.php` da exibição
autenticada. `registerPluginStatelessPath()` (seção 7) casa por **caminho**, não por query
string: não dá para o mesmo arquivo ser stateless numa requisição e autenticado em outra, então a
rota pública precisa do seu próprio caminho de arquivo, registrado sozinho como stateless. Token
inválido ou Tela inativa: resposta genérica (não distingue "token não existe" de "Tela
desativada", para não vazar informação sobre quais tokens já existiram). O HTML renderizado é o
mesmo template Twig de `display.php`, só o arquivo PHP que o invoca é diferente.

### 6.4 Polling

Pelo mesmo motivo da seção 6.3, a exibição autenticada e a pública **não dividem o mesmo arquivo
de AJAX**: `ajax/monitor/data.php` atende só `id=` (sessão válida, dentro do GLPI) e
`ajax/monitor/public_data.php` atende só `token=` (rota stateless, registrada junto com
`public.php`). Os dois chamam o mesmo serviço interno de busca; a diferença é só autenticação e
registro de rota. Ambos rodam `Search::getDatas('Ticket', $params)` com:

- entidade forçada a partir de `entities_id`/`is_recursive` **da Tela** (não da sessão);
- critérios vindos da Pesquisa Salva referenciada;
- `forcedisplay` com as search options correspondentes às `display_columns` da Tela.

Devolve JSON `{ columns: [...], rows: [...] }`. O JS consome no intervalo configurado
(`poll_interval_seconds` da Tela, senão o padrão global).

### 6.5 Alerta

Ver M7/M8: o JS compara o conjunto de IDs de `rows` do poll atual com o do poll anterior; IDs
novos disparam o som (se `alert_enabled`) e um destaque visual breve na linha.

## 7. Autenticação e rotas sem sessão

GLPI 11 oferece mais de um mecanismo para liberar um script legado (`front/`, `ajax/`) sem login
(documentados em `docs/glpi-developer-documentation.md`: "Legacy scripts access policy" ~11679 e
"Stateless endpoints" ~11708):

- **`Firewall::addPluginStrategyForLegacyScripts($key, $regex, Firewall::STRATEGY_NO_CHECK)`**:
  sessão inicia normalmente (cookie lido/escrito), mas sem exigir login. Não é o caso de uso aqui
  — a Tela pública roda o dia todo sem ninguém interagindo, não deveria depender de cookie de
  sessão acumulando no servidor.
- **`SessionManager::registerPluginStatelessPath('gac', $regex)`**: nenhuma sessão é iniciada; a
  rota gerencia a própria autenticação. É o mecanismo escolhido, registrado em
  `plugin_init_gac()`, com um regex que casa **só** os dois arquivos públicos
  (`front/monitor/public.php`, `ajax/monitor/public_data.php` — seção 6.3/6.4), nunca
  `display.php`/`data.php` autenticados.

## 8. Permissões

Uma linha nova em `Features::all()` (`src/Features.php`), na aba única de perfil
(`ProfileRights`). Bits: ler telas (exibição autenticada), criar/editar/excluir telas,
**Configurar** (`Features::RIGHT_CONFIG`, padrão, controla a seção de configuração global).

A exibição **pública** não passa por nenhum direito de perfil — o token é o único controle de
acesso, por desenho (M6).

Menu lateral do plugin ganha a entrada de "Monitor" apenas para quem tem direito de leitura
(`GacMenu::getMenuContent()`), e a seção de configuração entra em `Config::sections()`.

## 9. Estrutura de código (rascunho)

Segue o layout do PRE/LTBP. Regras puras, sem GLPI, em `src/Monitor/` (testes em
`tests/Unit/`):

- `ColumnCatalog`: define o conjunto curado de colunas (chave, rótulo, computada ou não), valida
  uma lista de chaves escolhidas por uma Tela.
- `MonitorSettings`: leitura/gravação das chaves de configuração global (mesmo padrão de
  `PreSettings`/`LtbpSettings`).
- `PublicToken`: geração e formato do token opaco (sem acesso a banco).
- `ElapsedTimeLabel`: formata a coluna computada "tempo decorrido" a partir de uma data de
  abertura e do momento atual.

Classes ligadas ao GLPI ao lado: `MonitorScreen` (`CommonDBTM`), o serviço que monta e roda a
busca (`Search::getDatas` com entidade forçada + `forcedisplay`), e os controladores de
`front/monitor/` e `ajax/monitor/`.

Por causa do sub-namespace, valem as mesmas regras do PRE/LTBP: `MonitorScreen` sobrescreve
`getTable()`, a coluna de busca declara `'itemtype' => self::class`, e os arquivos de front ficam
em `front/monitor/`. Twig em `templates/monitor/`, JS em `public/js/monitor.js`, CSS em
`public/css/monitor.css`. Sem arquivo de som embutido (M8): o alerta sonoro depende de
`monitor_alert_sound_url` configurada.

## 10. Erros, logs e testes (rascunho)

- Token inválido/Tela inativa: resposta genérica tanto em `public.php` quanto em
  `public_data.php` (seção 6.3).
- Falha em `Search::getDatas` (ou na conexão): o endpoint de dados devolve um JSON de erro;
  o JS mostra um indicador de "dados desatualizados" (comparando o timestamp do último poll
  bem-sucedido) sem derrubar a tela — ela continua tentando no próximo intervalo.
- Testes: unitários para `ColumnCatalog`, `PublicToken` e `ElapsedTimeLabel` (pure, mesma suíte
  `phpunit.unit.xml`). O restante (exibição pública e autenticada, polling, alerta, bloqueio de
  Pesquisa Salva privada) é validado por um roteiro manual
  (`docs/monitor-manual-tests.md`, a criar).

## 11. Pendências e riscos

- **R-1 (aberto)**: confirmar, já na implementação contra o GLPI local, se `Search::getDatas`
  funciona de forma limpa **sem sessão nenhuma** (`public_data.php` é stateless: `$_SESSION` não
  é necessariamente inicializado, não só "sem usuário logado"), ou se exige simular um contexto
  mínimo de direitos (ex.: `Session::callAsSystem()` ou um usuário de serviço interno, montado à
  mão dentro do próprio request stateless). Se não for limpo, a alternativa é montar a query
  diretamente com `$DB`/`DBmysqlIterator` em vez de `Search::getDatas`, perdendo parte do
  reaproveitamento da Pesquisa Salva.
- **R-2**: mapear as colunas do catálogo curado (seção 5.3) para os índices reais de search
  option de `Ticket::getSearchOptions()` no GLPI 11.0.x — fazer na implementação, não adivinhar
  aqui.
- **R-3**: verificar se `Search::getDatas` aceita forçar a entidade de busca independente de
  `$_SESSION['glpiactiveentities']` sem efeito colateral em requisições concorrentes (ex.: se a
  implementação interna exige setar essa chave de sessão temporariamente, isso precisa ser seguro
  sob múltiplas Telas sendo consultadas ao mesmo tempo, em processos PHP possivelmente
  compartilhando sessão — improvável no fluxo stateless, mas a confirmar).
- **R-4**: o token fica na URL (query string) de telas públicas, o que o expõe em logs de acesso
  do servidor web — comum a qualquer esquema de token em URL, aceitável para o caso de uso (rede
  interna do GAC), mas registrado aqui como trade-off consciente, não como descuido.
- **R-5**: a migração dos dados do app Django (nenhuma Tela hoje tem equivalente 1:1 no Django,
  já que lá o filtro é global) fica a cargo de quem configurar as Telas na v1 — não há dado do
  Django para migrar, só recriar manualmente as visões que hoje existem informalmente.
