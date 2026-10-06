# Monitor — roteiro de teste manual

Ambiente: GLPI local (`http://glpi11local.test/`), plugin `gac` instalado e ativo. Os cenários 33 a 50 (rodízio de páginas, barra de overflow e cor de linha) foram executados em 2026-10-06 por `curl`, scripts PHP e um navegador real (Claude in Chrome). Última
execução: 2026-10-01, durante a implementação (Tarefa 10 do plano), numa rodada posterior de
ajustes (tema claro/escuro, tamanho de fonte, correção do relógio e do formato de data), e numa
terceira rodada (escala de fonte do cabeçalho/pílula, níveis de entidade, destaque verde de
ticket novo), via `curl` autenticado (sessão real, não simulada) e um navegador real (Claude in
Chrome) para os pontos visuais. Conta de serviço do Monitor configurada como `tech`/`tech` (conta
de demonstração do GLPI) para este teste; em produção, usar uma conta dedicada, só leitura de
tickets.

| # | Cenário | Passos | Resultado esperado | Resultado |
|---|---|---|---|---|
| 1 | Direito ausente | Logar com um perfil sem o direito `plugin_gac_monitor` (`normal`/`normal`) | Menu "Telas de Monitoramento" não aparece; acesso direto a `front/monitor/monitorscreen.php` dá 403 | **Passou** |
| 2 | Criar Tela sem Pesquisa Salva compartilhada | POST de criação com `savedsearches_id=0` | Formulário recusa, nenhuma linha criada | **Passou** |
| 3 | Criar Tela válida | Criar uma Pesquisa Salva de Ticket compartilhada; criar uma Tela apontando para ela, entidade 4, as 11 colunas do catálogo | Tela salva com todas as colunas na ordem enviada, token público gerado automaticamente | **Passou** |
| 4 | Exibição autenticada | Abrir a Tela criada (exibição, não o formulário) dentro do GLPI, logado | Tabela mostra os tickets do critério da Pesquisa Salva, restritos à entidade 4, dentro do chrome normal do GLPI (menu, breadcrumb) | **Passou** |
| 5 | Restrição de entidade | Tela com `entities_id=4`, `is_recursive=0`; critério status=Em atendimento (que bate em 3 entidades de teste) | Só os 4 tickets da entidade 4 aparecem, nenhum de outra entidade | **Passou** |
| 6 | Sub-entidades | Mesma Tela com `entities_id=0` (raiz), `is_recursive=1` | Todos os 11 tickets do critério aparecem, das 3 entidades | **Passou** |
| 7 | Exibição pública | Tela com `is_public=1`; abrir a URL pública sem nenhum cookie (`curl` sem sessão, depois navegador anônimo) | Página carrega sem login, sem menu do GLPI, tema escuro, mesmos dados da exibição autenticada | **Passou** |
| 8 | Token inválido | Um caractere trocado no token da URL pública | 404 genérico, tanto em `public.php` quanto em `public_data.php` | **Passou** |
| 9 | Tela inativa | `is_active=0` numa Tela pública; repetir o acesso | 404 genérico, igual ao token inválido | **Passou** |
| 10 | Regenerar token | Ação "Gerar novo link" no formulário | Token muda no banco; a URL antiga passa a dar 404; a nova funciona | **Passou** |
| 11 | Polling | Deixar a exibição pública aberta num navegador; criar um ticket que bate no critério direto no banco | Em até um ciclo de polling (10s nesse teste), a linha aparece com "Tempo decorrido" correto (`<1min`) | **Passou** |
| 12 | Alerta desligado | `alert_enabled=0` numa Tela | O atributo `data-alert-enabled="0"` aparece no HTML; o JS (revisado em código) não chama `playAlert()` nesse caso | **Passou** |
| 13 | Rede/autenticação fora do ar | Corromper a senha da conta de serviço, deixar o board aberto, esperar um ciclo; depois restaurar a senha | O indicador fica vermelho (dados desatualizados), a última tabela boa continua visível; no ciclo seguinte à correção, o indicador volta a verde e os dados atualizam (campo "Tempo decorrido" avança) | **Passou** |
| 14 | Configuração global | Salvar a seção de configuração do Monitor (intervalo padrão, usuário/senha da conta de serviço) | Salva sem erro; a senha fica criptografada em `glpi_configs` (confirmado via SQL), não em texto puro | **Passou** |
| 15 | Intervalo próprio da Tela | Tela com `poll_interval_seconds=10`, diferente do padrão global (15) | `data-poll-interval="10"` no HTML da exibição, não 15 | **Passou** |
| 16 | Colunas e ordem | Tela com as 11 colunas do catálogo, nessa ordem: ID, Título, Entidade, Status, Prioridade, Solicitante, Técnico, Categoria, Grupo técnico, Abertura, Tempo decorrido | A tabela renderizada mostra exatamente essas colunas, nessa ordem (confirmado visualmente) | **Passou** |
| 17 | Excluir Tela | Excluir (`purge`) uma Tela pública | A URL pública antiga passa a dar 404 imediatamente | **Passou** |
| 18 | Ordenação (`sort_mode=priority`) | Tela com tickets de urgências variadas (1, 3 e 5) e uma data de abertura mais nova num dos tickets de urgência 3 | O ticket de urgência 5 vem primeiro, o de urgência 1 vem por último, e dentro da urgência 3 o ticket mais novo aparece antes dos mais antigos do mesmo status | **Passou** |
| 19 | Ordenação (`sort_mode=id`) | Mesma Tela, trocando para `sort_mode=id` | Volta para ordem crescente de ID simples (o padrão do GLPI) | **Passou** |
| 20 | Copiar URL pública | Clicar no botão de copiar ao lado do campo "URL pública" no formulário | A URL completa (com domínio) vai para a área de transferência; o ícone pisca um check de confirmação | **Passou** |
| 21 | Reordenar colunas por arrastar | Arrastar uma coluna pelo ícone de grip para outra posição na lista; marcar/desmarcar colunas; salvar | A ordem salva (`display_columns`) reflete exatamente o arraste e as marcações feitas | **Passou** |
| 22 | Relógio | Abrir a exibição (autenticada e pública) | Relógio no canto superior direito avança a cada segundo, mostrando a hora do servidor GLPI (corrigida a cada ciclo de polling a partir do `generated_at` da resposta, não a hora crua do computador que exibe a tela) | **Passou** |
| 23 | Cores de prioridade | Tickets com as 6 prioridades do GLPI (Muito baixa a Crítica), com as cores de produção configuradas em Configurações > Valores padrão > Cores das Prioridades | Cada prioridade aparece como um badge colorido com a cor configurada; o texto do badge fica legível (preto em fundos claros, branco em fundos escuros) em todas as 6 cores | **Passou** |
| 24 | Anel de contagem regressiva | Deixar a tela aberta um ciclo inteiro; observar o anel | O anel fica parado (sem animar) enquanto a requisição está em andamento — pisca suavemente nesse período —, e só começa a esvaziar de verdade depois que os dados chegam, terminando de esvaziar exatamente quando a próxima requisição é disparada. Verde quando a última requisição teve sucesso, vermelho quando falhou | **Passou** |
| 25 | Tema claro | Tela com `theme=light`; abrir a exibição (autenticada e pública) | Fundo claro, tabela branca, texto escuro, cabeçalho da página (`public_display.html.twig`) também claro antes mesmo do CSS carregar; badges de prioridade continuam legíveis | **Passou** |
| 26 | Tamanho da fonte | Tela de demo (id 1) com `font_size=4`, depois `font_size=1`; abrir a exibição pública (`public.php`) nas duas | Comparando os dois screenshots: com `font_size=1` o cabeçalho das colunas e a pílula de prioridade (caixa e texto) ficam visivelmente menores que com `font_size=4`, crescendo junto com o texto das linhas; título e relógio não mudam de tamanho | **Passou** (2026-10-01, navegador real via Claude in Chrome) |
| 27 | Formato de data da coluna Abertura | Tela com a coluna "Abertura"; comparar exibição autenticada e pública lado a lado, com o formato de data configurado em GLPI como dd-mm-aaaa (`glpidate_format=1`) | Os dois caminhos mostram a mesma data no mesmo formato (`25-09-2026 20:41:36`), não um formato cru/ISO (`2026-09-25...`) | **Passou** (achado um bug nessa checagem, ver abaixo) |
| 28 | Reinstalação com schema novo | Rodar `plugin:install --force gac` numa instalação que já tinha a tabela `glpi_plugin_gac_monitorscreens` de antes das colunas `theme`/`font_size` existirem | As duas colunas novas aparecem via `ALTER TABLE`, sem apagar os dados existentes (telas e tokens antigos continuam lá) | **Passou** |
| 29 | Tema/fonte aplicados sem recarregar | Deixar a exibição aberta (autenticada); mudar `theme` e `font_size` da Tela direto no banco, sem tocar na página | No ciclo de polling seguinte (até `poll_interval_seconds`), o tema e o tamanho da fonte da tabela mudam sozinhos, sem reload manual | **Passou** |
| 30 | Intervalo de atualização aplicado sem recarregar | Deixar a exibição aberta com `poll_interval_seconds=10`; mudar para `30` direto no banco, sem tocar na página; medir os instantes reais das requisições via `performance.getEntriesByType('resource')` | O ciclo em andamento (agendado com o valor antigo) completa normalmente; a partir dele, os ciclos seguintes passam a respeitar ~30s, não mais ~10s — confirmado com os instantes `502ms, 11431ms, 42434ms, 73431ms` | **Passou** |
| 31 | Níveis de entidade exibidos | `plugin:install --force gac` + `cache:clear` para aplicar a coluna nova; Tela de demo (id 1, entidade raiz > Fimca, 2 níveis de profundidade); testar `entity_levels=3` (completename inteiro) e depois `entity_levels=1` | Com `entity_levels=3` a coluna "Entidade" mostra `Entidade raiz > Fimca - Porto Velho`; com `entity_levels=1` mostra só `Fimca - Porto Velho`, sem o prefixo "Entidade raiz >" | **Passou** (2026-10-01, navegador real; árvore de teste só tem 2 níveis, então `entity_levels=2` e `3` ficam idênticos nesse GLPI — a distinção em 3 níveis já está coberta pela suíte unitária, `EntityLevelsTest`) |
| 32 | Destaque de ticket novo | Deixar a exibição aberta; criar um ticket que bate no critério direto no banco depois da carga inicial, esperar o próximo ciclo | A linha do ticket novo pisca em verde (`#27ae60`, texto branco) e depois volta a transparente, tanto no tema escuro quanto no claro; a primeira carga da página não pisca nenhuma linha | **Passou** (2026-10-01, navegador real — capturado pausando a `Animation` da linha via `getAnimations()`/`currentTime` em vez de tentar acertar o timing de um screenshot num flash de 2s; confirma que a cor certa está sendo aplicada de fato, não só lida do CSS-fonte) |
| 33 | Migração para páginas | Tela de demo (id 1) com `savedsearches_id`/`display_columns` antigos; rodar `plugin_gac_install()` real (`var/tools/gac-install.php`); rodar uma segunda vez | A Tela ganha a página 1 com a pesquisa e as colunas antigas; a segunda execução não duplica nem recria nada | **Passou** (2026-10-06) |
| 34 | Aba Páginas: criar, editar, excluir | Na Tela, aba "Páginas" > "Adicionar página" (posição sugerida preenchida); criar com pesquisa e 3 colunas; editar o título; excluir (`purge`) | Cada ação volta para a Tela; o banco reflete título, colunas e a linha removida; a aba mostra a contagem certa. Reordenar pelo campo posição **não** foi exercitado (só a posição automática) | **Passou** (2026-10-06, navegador real) |
| 35 | Limite de 8 páginas | Tentar criar 9 páginas numa Tela | A 9ª é recusada (mensagem), ficam 8 | **Passou** (2026-10-06, por script) |
| 36 | Página sem pesquisa salva | Enviar o formulário da página sem escolher a pesquisa | Mensagem "Escolha uma Pesquisa Salva de Ticket compartilhada." e nenhuma linha criada. Pesquisa privada ou de outro tipo **não** foi exercitada | **Passou** (2026-10-06, navegador real) |
| 37 | Rodízio | Tela pública com 2 páginas e `rotation_seconds = 10`; observar as trocas; depois deixar o campo vazio com padrão global 30 | A página ativa troca a cada 10 s (medido: 0,5 / 11,5 / 21,5 / 31,5 / 42,5 s), colunas e seletor mudam junto; vazio usa o padrão global (`rotation_seconds` = 30 no JSON) | **Passou** (2026-10-06) |
| 38 | Remover a página ativa com a Tela aberta | Excluir a página que está na tela enquanto a exibição está aberta | O rodízio reajusta o índice sem travar | **Não executado** |
| 39 | Alerta em página oculta | Rodízio em 60 s, tela na página 2; tirar um ticket da fila da página 1 e devolvê-lo com a página 2 ativa | O pill da página 1 passa a piscar e a contagem sobe; a tela **não** salta de página; o pisca some quando a página 1 é exibida. O som em si não foi testado (sem URL configurada) | **Passou** (2026-10-06, navegador real) |
| 40 | Diff de ticket novo por página | Recarregar a exibição; depois devolver um ticket à página visível | Na carga inicial nenhum pill pisca; o ticket devolvido numa página visível aparece como linha nova (flash verde), uma só vez | **Passou** (2026-10-06) |
| 41 | Barra de overflow | Página com 14 tickets numa janela que mostra 6; variar a altura do board (simulado por `style.height` + evento `resize`) | "▼ 8 tickets abaixo" (singular/plural tratados); a barra some quando tudo cabe; sobe conforme a área encolhe; uma linha cortada ao meio conta como escondida (exibição autenticada: 12 de 14). Vale com 1 página | **Passou** (2026-10-06; a janela do navegador não redimensionou de verdade, o resize foi simulado) |
| 42 | Cor das linhas, os 4 modos | `row_color_mode` = `none`, `status`, `priority`, `sla` (trocado por SQL com a tela aberta) | O JSON traz `row_tone` coerente com o modo; a tela muda de cor no poll seguinte sem recarregar. Visual conferido para `sla` e `status`; `priority` e `none` só pelo JSON nesta sessão | **Passou** (2026-10-06) |
| 43 | Tons do SLA | Tickets com `time_to_resolve` daqui a 30 min, 2 h atrás, +1 dia, sem prazo, e um ticket Pendente com prazo de 5 dias atrás | `sla-warning` (laranja), `sla-late` (vermelho), `sla-ok` (verde), `sla-none` (cinza), `sla-paused` (azul). Solucionado/Fechado sem cor e `time_to_own` só em ticket Novo estão cobertos só pelos testes unitários (`RowToneTest`), não por dados reais | **Passou** (2026-10-06) |
| 44 | Tema claro | `theme = light`; abrir a exibição autenticada | Pills, barra de overflow e tintas legíveis. A exibição pública em tema claro não foi capturada nesta rodada | **Passou** (2026-10-06, exibição autenticada) |
| 45 | Exibição pública com 2 páginas | `public_data.php` com token, sem cookie, Tela com 2 e com 8 páginas | Resposta 200 com `pages` de 2 e 8 itens, dados certos nas duas páginas, uma única autenticação da conta de serviço | **Passou** (2026-10-06) |
| 46 | Exibição autenticada cabe no GLPI | Abrir `display.php?id=1` logado | O board cabe sob o menu e o breadcrumb, sem barra de rolagem da página (`7rem` descontados em `.gac-monitor-embedded`) | **Passou** (2026-10-06) |
| 47 | Tela sem páginas | Tela criada por SQL sem nenhuma página; abrir a exibição | Mensagem "Nenhuma página configurada para esta tela.", sem tabela, seletor nem barra, sem erro no console | **Passou** (2026-10-06) |
| 48 | Configuração global | Salvar rotação padrão e janela do SLA com valores inválidos (1 e 0) e válidos (30 e 90) | Inválidos viram 5 e 1; válidos persistem; uma Tela sem `rotation_seconds` próprio passa a rodar a 30 s | **Passou** (2026-10-06) |
| 49 | Campo vazio no formulário da Tela | Abrir a Tela e salvar sem preencher "Tempo de cada página" (e "Intervalo") | Continua NULL (usa o padrão global); `0` também vira NULL | **Passou** (2026-10-06; achado, ver abaixo) |
| 50 | Desempenho com 8 páginas (R-8) | `curl -w %{time_total}` em `public_data.php` com 2 e com 8 páginas | ~0,65 s nos dois casos. **Não é uma medida de carga real**: o banco de dev tem só 14 tickets, o tempo é dominado pelo login da conta de serviço e pelo boot do GLPI. Repetir em produção | **Registrado, não conclusivo** |

## Achados registrados durante a implementação (não são bugs abertos — já corrigidos)

- `Search::getDatas('Ticket', ...)` não funciona numa requisição sem nenhuma sessão (lê
  `$_SESSION['glpigroups']` internamente); a exibição pública resolve isso autenticando
  internamente como a conta de serviço, por requisição (ver spec R-1, `ServiceSession`).
- Forçar só a entidade via `$_SESSION['glpiactiveentities']` não bastava: um perfil com direito
  de ver todas as entidades (`glpishowallentities`) ignorava a restrição da Tela. Corrigido em
  `ScreenQuery::forceEntityScope()` (ver spec R-3).
- As colunas "Solicitante"/"Técnico" (search options 4/5 do Ticket) devolvem o **id** do usuário
  no campo `name` da busca, não o nome — resolvido com `User::getFriendlyName()`.
- Status e prioridade são códigos inteiros crus na tabela `glpi_tickets`; precisam passar por
  `Ticket::getStatus()`/`getPriorityName()` para virar texto.
- A ordenação de linhas não estava decidida na v1 original (passou batido na brainstorming) e
  caía, por acidente, no `ORDER BY id` crescente do GLPI — ruim para uma tela de monitoramento.
  Adicionada depois (`sort_mode`), reproduzindo a regra que o app Django já usava.
- A URL pública do formulário usava `$CFG_GLPI['root_doc']` (só o caminho, ex. `/plugins/gac/...`)
  em vez de `$CFG_GLPI['url_base']` (domínio completo) — o botão de copiar levava um link que só
  funcionava se colado em uma aba já aberta no próprio GLPI, inútil pra abrir numa TV.
- A biblioteca de arrastar-e-soltar que o GLPI já carrega em `/lib/sortable.min.js` **não** é a
  SortableJS que o nome sugere — é a HTML5Sortable (`lukasoppermann/html5sortable`), exposta como
  `window.sortable(elemento, opções)` (função minúscula), não `Sortable.create(...)`. Só se
  percebe isso lendo o arquivo-fonte real; o nome do arquivo engana.
- `ScreenQuery::run()` montava as linhas (com a formatação de data) **depois** de
  `ServiceSession::logout()` no caminho público — `logout()` destrói `$_SESSION` inteira, então
  `Html::convDateTime()` sempre caía no formato padrão embutido do GLPI (`Y-m-d`), ignorando o
  formato configurado (`glpidate_format`). A exibição autenticada não tinha esse problema (nunca
  faz logout no meio da função). Corrigido movendo a montagem das linhas para antes do logout.
- (2026-10-02) A aba **Histórico** de uma Tela mostrava o valor cru salvo (`3`, `light`) em vez do
  rótulo (`Grande (recomendado)`, `Claro`) para `sort_mode`/`theme`/`font_size`/`entity_levels`.
  Causa raiz: `Log::getHistoryData()` chama `CommonDBTM::getValueToDisplay($searchopt, $valor)`,
  que resolve a classe de um campo `datatype => 'specific'` via `getItemTypeForTable($tabela)` —
  **não** via `$searchopt['itemtype']` (que `rawSearchOptions()` já preenche certo, mas que esse
  caminho específico ignora). `getItemTypeForTable('glpi_plugin_gac_monitorscreens')` deriva
  `GlpiPlugin\Gac\Monitorscreen`, que não existe (a classe real tem um nível de sub-namespace a
  mais, `...\Monitor\MonitorScreen`); `class_exists()` falha, a função devolve `null`, e
  `getValueToDisplay()` cai no valor cru em vez de chamar `getSpecificValueToDisplay()`.
  Corrigido sobrescrevendo `MonitorScreen::getValueToDisplay()` para interceptar esse caso
  específico e chamar `self::getSpecificValueToDisplay()` diretamente, antes de delegar ao `parent`
  para todo o resto. Verificado ao vivo: as 19 linhas de histórico já existentes da Tela de demo
  passaram a mostrar o rótulo certo **retroativamente** (o valor cru fica salvo em `glpi_logs`; só
  a formatação na tela é recalculada a cada exibição, então o conserto vale também para o
  histórico antigo, sem precisar de migração de dados). **Risco não verificado**: o mesmo padrão
  (`'datatype' => 'specific'` num cadastro de sub-namespace) existe em `RepairProtocol` (PRE) e em
  `Ltbp` (LTBP) para seus próprios campos de status/destinação — não foram checados nesta sessão,
  mas é provável que tenham o mesmo bug na própria aba Histórico.

- Achado de 2026-10-06 (rodízio de páginas): o macro `numberField` do GLPI preenche o campo
  vazio com o valor de `min` (e, sem `min`, com `0`). Salvar a Tela sem mexer nesse campo gravaria
  `5` (ou `0`) em `poll_interval_seconds`/`rotation_seconds`, em vez de "usar o padrão global". Os
  dois campos ficaram sem `min` no formulário e o servidor trata vazio ou `0` como NULL
  (`MonitorScreen::prepareCommonInput()`). O mesmo defeito já existia para o intervalo de
  atualização; corrigido junto.
- Achado de 2026-10-06: `CommonDBChild::canCreateItem()` consulta o pai pelo campo
  `plugin_gac_monitorscreens_id` do próprio item; no formulário de uma página nova esse campo
  precisa ser preenchido em `showForm()`, senão o botão "Adicionar" não aparece.

## Pendências conhecidas

- O plugin não embuti nenhum arquivo de som padrão (decisão M8): sem `monitor_alert_sound_url`
  configurada, o alerta fica só visual (linha piscando), sem som. O teste do som em si
  (reprodução de áudio real) não foi executado — depende de uma URL de áudio configurada e de
  verificação auditiva, fora do escopo de um teste automatizável por aqui.
- Testado com a conta de demonstração `tech`/`tech` como conta de serviço. Antes de usar em
  produção, criar uma conta dedicada, com direito de leitura de ticket apenas (sem direitos
  administrativos), nas entidades que as Telas públicas vão efetivamente usar.
- Quatro melhorias pedidas pelo dono em 2026-10-01 (linhas 26, 31 e 32) foram implementadas e
  validadas num navegador real nesse mesmo dia: escala do cabeçalho/pílula de prioridade junto
  com `font_size`, a nova coluna `entity_levels`, e o destaque de ticket novo trocado de amarelo
  para verde. Falta só o manual do usuário do módulo — ver memória de sessão
  `monitor-ui-followups-2026-10-01`.
- Rodízio, barra de overflow e cor de linha (M11 a M16): o som do alerta de página oculta, a
  remoção da página ativa com a Tela aberta (cenário 38), a reordenação pelo campo posição, uma
  Pesquisa Salva privada/de outro tipo, a exibição pública em tema claro e a carga real com
  muitos tickets (cenário 50) ficaram sem teste.
