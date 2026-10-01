# Monitor — roteiro de teste manual

Ambiente: GLPI local (`http://glpi11local.test/`), plugin `gac` instalado e ativo. Última
execução: 2026-10-01, durante a implementação (Tarefa 10 do plano), via `curl` autenticado
(sessão real, não simulada) e um navegador real para os pontos visuais. Conta de serviço do
Monitor configurada como `tech`/`tech` (conta de demonstração do GLPI) para este teste; em
produção, usar uma conta dedicada, só leitura de tickets.

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

## Pendências conhecidas

- O plugin não embuti nenhum arquivo de som padrão (decisão M8): sem `monitor_alert_sound_url`
  configurada, o alerta fica só visual (linha piscando), sem som. O teste do som em si
  (reprodução de áudio real) não foi executado — depende de uma URL de áudio configurada e de
  verificação auditiva, fora do escopo de um teste automatizável por aqui.
- Testado com a conta de demonstração `tech`/`tech` como conta de serviço. Antes de usar em
  produção, criar uma conta dedicada, com direito de leitura de ticket apenas (sem direitos
  administrativos), nas entidades que as Telas públicas vão efetivamente usar.
