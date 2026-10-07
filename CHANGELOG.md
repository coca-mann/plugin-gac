# Changelog

Todas as mudanças notáveis deste projeto serão documentadas neste arquivo.

O formato segue [Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/),
e este projeto adere ao [Semantic Versioning](https://semver.org/lang/pt-BR/).

> Nota: este changelog passa a ser mantido a partir da versão
> `0.0.1`.
> Versões anteriores (se houver) não foram reconstruídas retroativamente;
> consulte o histórico de PRs no Git caso precise dessa informação.

## [Unreleased]

### Added

- [9233a8c](https://github.com/coca-mann/plugin-gac/commit/9233a8c), [c94da60](https://github.com/coca-mann/plugin-gac/commit/c94da60), [e76481c](https://github.com/coca-mann/plugin-gac/commit/e76481c) - A Tela de Monitoramento agora pode ter de 1 a 8 páginas, cada uma com a sua pesquisa salva e as suas colunas. A exibição alterna entre elas no tempo configurado (padrão global ou por Tela), uma barra na parte de baixo conta os tickets que não cabem na tela e as linhas podem ser pintadas por status, prioridade ou prazo de SLA. As páginas são editadas na aba "Páginas" da Tela, com a contagem de tickets que a pesquisa retorna hoje.
- [2d6b0a3](https://github.com/coca-mann/plugin-gac/commit/2d6b0a3) - Na exibição com várias páginas, pequenos pontos ao lado do relógio indicam a página atual e o título da página substitui o título da Tela. O som de ticket novo de uma página que não está na tela só toca quando o rodízio a traz de volta, e o ponto dela pisca enquanto isso.
- [76c3ca3](https://github.com/coca-mann/plugin-gac/commit/76c3ca3) - O som do alerta de ticket novo agora pode ser enviado como arquivo (mp3, ogg ou wav, até 512 KB) na configuração do módulo, em vez de depender de uma URL. Quando o navegador impede o som de tocar, um ícone de alto-falante cortado aparece ao lado do relógio.
- [04bb869](https://github.com/coca-mann/plugin-gac/commit/04bb869) - A Tela de Monitoramento pode mostrar um banner grande a cada ticket novo, com número, título, prioridade, solicitante, entidade, categoria e o início da descrição, na cor da prioridade, com transição de entrada e saída e o som tocando junto. Os banners entram numa fila, o mais urgente primeiro, e ficam desligados por padrão em cada Tela.
- [9802d75](https://github.com/coca-mann/plugin-gac/commit/9802d75) - Cada Tela de Monitoramento pode ter o seu próprio som de alerta, usado antes do som do plugin. O formulário da Tela foi reorganizado em cinco blocos (Identificação, Exibição, Atualização, Alertas de ticket novo e Publicação), com nomes de campos mais claros e dicas de ajuda.
- [f70033b](https://github.com/coca-mann/plugin-gac/commit/f70033b), [1eb39bf](https://github.com/coca-mann/plugin-gac/commit/1eb39bf) - Nova ordem de linhas "Tempo decorrido (mais antigo primeiro)" na Tela de Monitoramento, e cada página pode escolher a sua própria ordem, seguindo a ordem padrão da Tela quando não escolher nenhuma.
- [c510478](https://github.com/coca-mann/plugin-gac/commit/c510478) - A troca de uma página para outra na exibição da Tela de Monitoramento agora tem um efeito suave de desaparecer e reaparecer.
- [a021696](https://github.com/coca-mann/plugin-gac/commit/a021696) - Quando uma Tela de Monitoramento é desativada (ou o link público é desligado) enquanto está aberta, a tabela é esvaziada e aparece uma mensagem avisando, em vez de continuar mostrando tickets antigos. A exibição volta sozinha se a Tela for reativada.
- [e76d1af](https://github.com/coca-mann/plugin-gac/commit/e76d1af), [fe06d94](https://github.com/coca-mann/plugin-gac/commit/fe06d94) - Manuais do usuário dos módulos de Laudo Técnico de Baixa Patrimonial e de Login com Google (SSO).

### Changed

- [fa5c3cc](https://github.com/coca-mann/plugin-gac/commit/fa5c3cc) - As margens da exibição da Tela de Monitoramento ficaram menores, dando mais espaço à tabela na TV.

### Fixed

- [1eb39bf](https://github.com/coca-mann/plugin-gac/commit/1eb39bf) - A ordenação "Urgência, status e data" da Tela de Monitoramento deixava os tickets fora de ordem de prioridade, porque comparava a urgência e não a prioridade que a tela mostra. Agora a opção se chama "Prioridade, status e data" e a prioridade mais alta fica sempre no topo.

### Security

- [6e67bf1](https://github.com/coca-mann/plugin-gac/commit/6e67bf1) - As páginas públicas da Tela de Monitoramento passaram a ter limite de requisições por minuto, por endereço de cliente e por link público, respondendo com erro 429 quando excedido; a tela da TV mantém a tabela e espera para tentar de novo. O endereço do cliente atrás de um proxy reverso (como o nginx) só é lido do cabeçalho X-Forwarded-For para os proxies confiáveis configurados.

## [0.7.0] - 2026-10-05

### Added

- [8b5d6e6](https://github.com/coca-mann/plugin-gac/commit/8b5d6e6), [fa6bed7](https://github.com/coca-mann/plugin-gac/commit/fa6bed7), [cf93c47](https://github.com/coca-mann/plugin-gac/commit/cf93c47), [6cbd44e](https://github.com/coca-mann/plugin-gac/commit/6cbd44e), [7f84e92](https://github.com/coca-mann/plugin-gac/commit/7f84e92), [199857a](https://github.com/coca-mann/plugin-gac/commit/199857a), [ade951a](https://github.com/coca-mann/plugin-gac/commit/ade951a), [7ff67df](https://github.com/coca-mann/plugin-gac/commit/7ff67df), [fdf6e69](https://github.com/coca-mann/plugin-gac/commit/fdf6e69), [824c81c](https://github.com/coca-mann/plugin-gac/commit/824c81c), [730c50f](https://github.com/coca-mann/plugin-gac/commit/730c50f), [7f47141](https://github.com/coca-mann/plugin-gac/commit/7f47141), [7530319](https://github.com/coca-mann/plugin-gac/commit/7530319), [be7c76d](https://github.com/coca-mann/plugin-gac/commit/be7c76d), [e15ea25](https://github.com/coca-mann/plugin-gac/commit/e15ea25), [6f1dea4](https://github.com/coca-mann/plugin-gac/commit/6f1dea4), [70013d2](https://github.com/coca-mann/plugin-gac/commit/70013d2), [cfac327](https://github.com/coca-mann/plugin-gac/commit/cfac327), [af9ee82](https://github.com/coca-mann/plugin-gac/commit/af9ee82), [f97ea4f](https://github.com/coca-mann/plugin-gac/commit/f97ea4f), [4cc7a3f](https://github.com/coca-mann/plugin-gac/commit/4cc7a3f), [fb31155](https://github.com/coca-mann/plugin-gac/commit/fb31155), [a6bd51d](https://github.com/coca-mann/plugin-gac/commit/a6bd51d), [2cc6381](https://github.com/coca-mann/plugin-gac/commit/2cc6381), [59d1740](https://github.com/coca-mann/plugin-gac/commit/59d1740), [2301e1e](https://github.com/coca-mann/plugin-gac/commit/2301e1e), [29c6446](https://github.com/coca-mann/plugin-gac/commit/29c6446) - Novo módulo **Login com Google**: botão "Entrar com Google" na tela de login, no padrão visual do Google, com o formulário de usuário e senha podendo ficar escondido atrás do botão "Entrar com usuário e senha". O acesso é liberado aos domínios de um ou mais workspaces do Google cadastrados, e a unidade organizacional (OU) do usuário no Google define a entidade e o perfil dele pelas regras de autorização do próprio GLPI, por meio do novo critério "OU do Google Workspace". Usuários novos são criados no primeiro login; usuários já existentes (por exemplo, vindos do AD) são vinculados pelo e-mail e a conversão pode ser desfeita. Também há lista de OUs sempre bloqueadas (para os docentes), modo piloto por e-mail, retirada automática do acesso de quem passa a ser bloqueado, teste a seco na configuração, telas de identidades, eventos e OUs pendentes, e um código de evento mostrado ao usuário quando o login falha.

### Fixed

- [6327183](https://github.com/coca-mann/plugin-gac/commit/6327183) - Na tela de configurações, os cabeçalhos das seções de Protocolo de Reparo, Laudo Técnico e Painel de Monitoramento ficavam em cinza claro quando o tema escuro estava ativo; agora acompanham o tema.

## [0.6.0] - 2026-10-01

### Added

- [f864b8f](https://github.com/coca-mann/plugin-gac/commit/f864b8f) - Tela de Monitoramento ganhou um controle de quantos níveis da hierarquia de entidades aparecem na coluna "Entidade" (de 1 a 3, contados a partir da mais específica), para não precisar mostrar a árvore inteira numa tela de TV.

### Changed

- [f864b8f](https://github.com/coca-mann/plugin-gac/commit/f864b8f) - Na Tela de Monitoramento, o cabeçalho das colunas e a pílula de prioridade agora acompanham o tamanho de fonte escolhido, em vez de ficarem sempre do mesmo tamanho; e o destaque de ticket novo passou de amarelo para verde.

### Fixed

- [7baa2bc](https://github.com/coca-mann/plugin-gac/commit/7baa2bc) - A aba Histórico de uma Tela de Monitoramento mostrava o valor interno salvo (ex. "3", "light") em vez do nome (ex. "Grande (recomendado)", "Claro") para tema, tamanho de fonte, ordenação e níveis de entidade; agora mostra o nome, inclusive nas mudanças já registradas antes desta correção.

## [0.5.0] - 2026-10-01

### Added

- [e9c3cd7](https://github.com/coca-mann/plugin-gac/commit/e9c3cd7), [410c1c9](https://github.com/coca-mann/plugin-gac/commit/410c1c9), [c0e17fe](https://github.com/coca-mann/plugin-gac/commit/c0e17fe), [8f05880](https://github.com/coca-mann/plugin-gac/commit/8f05880), [be446ad](https://github.com/coca-mann/plugin-gac/commit/be446ad), [f5a95e5](https://github.com/coca-mann/plugin-gac/commit/f5a95e5), [47c33d5](https://github.com/coca-mann/plugin-gac/commit/47c33d5), [29c176e](https://github.com/coca-mann/plugin-gac/commit/29c176e), [d4907bb](https://github.com/coca-mann/plugin-gac/commit/d4907bb), [70e105f](https://github.com/coca-mann/plugin-gac/commit/70e105f), [5091971](https://github.com/coca-mann/plugin-gac/commit/5091971), [a27ae03](https://github.com/coca-mann/plugin-gac/commit/a27ae03), [7c235d0](https://github.com/coca-mann/plugin-gac/commit/7c235d0), [95dd823](https://github.com/coca-mann/plugin-gac/commit/95dd823), [5d33836](https://github.com/coca-mann/plugin-gac/commit/5d33836), [e19f364](https://github.com/coca-mann/plugin-gac/commit/e19f364), [2a5b980](https://github.com/coca-mann/plugin-gac/commit/2a5b980) - Novo módulo **Monitor** (painel de monitoramento de tickets, substituindo o painel equivalente do app Django descontinuado): cadastro de Telas de Monitoramento, cada uma vinculada a uma Pesquisa Salva compartilhada de Ticket, com colunas e ordem de exibição escolhidas pelo administrador (inclusive por arrastar), ordenação das linhas configurável (urgência, status e data, ou o ID padrão do GLPI), intervalo de atualização (padrão global ou por Tela), tema claro/escuro e tamanho de fonte da tabela — todos aplicados na tela sem precisar recarregar a página. Exibição autenticada dentro do GLPI e exibição pública por link com token (sem login, pensada para TV/kiosk), com botão para copiar o link completo. Relógio sincronizado com o horário do servidor, anel de contagem regressiva até a próxima atualização (no lugar do indicador de conexão) e badges de prioridade com as cores configuradas no GLPI.

### Fixed

- [1a1ae78](https://github.com/coca-mann/plugin-gac/commit/1a1ae78) - Ao reabrir um PRE e corrigir os dados de retorno de uma linha, nenhum acompanhamento era registrado no ticket com os dados corrigidos — só o histórico interno do PRE e o custo eram atualizados.

## [0.4.0] - 2026-09-27

### Added

- [d30ff77](https://github.com/coca-mann/plugin-gac/commit/d30ff77), [4dcce7d](https://github.com/coca-mann/plugin-gac/commit/4dcce7d), [3ce49a8](https://github.com/coca-mann/plugin-gac/commit/3ce49a8), [71bddd9](https://github.com/coca-mann/plugin-gac/commit/71bddd9), [dedf3c8](https://github.com/coca-mann/plugin-gac/commit/dedf3c8), [eb446b1](https://github.com/coca-mann/plugin-gac/commit/eb446b1), [e33487f](https://github.com/coca-mann/plugin-gac/commit/e33487f), [906dc34](https://github.com/coca-mann/plugin-gac/commit/906dc34), [cff1ad8](https://github.com/coca-mann/plugin-gac/commit/cff1ad8), [78c7c63](https://github.com/coca-mann/plugin-gac/commit/78c7c63), [93f5c3e](https://github.com/coca-mann/plugin-gac/commit/93f5c3e), [17b9759](https://github.com/coca-mann/plugin-gac/commit/17b9759), [771b009](https://github.com/coca-mann/plugin-gac/commit/771b009), [b2e75c2](https://github.com/coca-mann/plugin-gac/commit/b2e75c2), [1368f2f](https://github.com/coca-mann/plugin-gac/commit/1368f2f), [1cbba32](https://github.com/coca-mann/plugin-gac/commit/1cbba32) - Novo módulo **LTBP (Laudo Técnico de Baixa Patrimonial)**: o técnico monta o laudo escolhendo os ativos (candidatos "aguardando baixa" ou busca livre) e um motivo de um catálogo próprio (gerenciável pela tela de configuração, com o botão nativo "+ Adicionar" e recusa ao tentar excluir um motivo já usado), emite o PDF em retrato para a assinatura em papel dos diretores, confirma a baixa — o que já bloqueia qualquer edição do ativo, inclusive por inventário e cron — e conclui com a destinação (descarte ou doação), o beneficiário e o comprovante.
- [44406bc](https://github.com/coca-mann/plugin-gac/commit/44406bc) - Retornos do PRE marcados para baixa podem ser levados direto para um laudo do LTBP, em lote, pela aba Itens do PRE.

### Fixed

- [ed518f9](https://github.com/coca-mann/plugin-gac/commit/ed518f9) - A mensagem de mapeamentos obrigatórios pendentes, nas configurações do PRE e do LTBP, mostrava as chaves internas dos campos (ex.: "state:at_supplier") em vez do nome amigável; agora mostra o nome e agrupa os campos por seção.

## [0.3.0] - 2026-09-26

### Added

- [8cf05ea](https://github.com/coca-mann/plugin-gac/commit/8cf05ea) - Menu lateral próprio do plugin, com as entradas do PRE e das Configurações, que saíram de Gerência e de Configurar.
- [1ecb6a2](https://github.com/coca-mann/plugin-gac/commit/1ecb6a2), [3579a47](https://github.com/coca-mann/plugin-gac/commit/3579a47) - Aba única "Plugin - DTI GAC" nos perfis, com uma linha de permissões por funcionalidade e o novo direito "Configurar", que libera as configurações de cada módulo. O menu lateral só mostra as entradas a que o usuário tem direito, e os perfis que já podiam alterar a configuração do GLPI recebem "Configurar" automaticamente na instalação ou atualização.
- [c262d24](https://github.com/coca-mann/plugin-gac/commit/c262d24) - A data de emissão de um novo PRE já vem preenchida com a data de hoje, e continua editável.

### Changed

- [3579a47](https://github.com/coca-mann/plugin-gac/commit/3579a47) - A página de configurações do plugin passou a exigir o direito "Configurar" do módulo, no lugar do direito de configuração do GLPI.
- [1be88fc](https://github.com/coca-mann/plugin-gac/commit/1be88fc) - Configurações do PRE organizadas em blocos com ícone, título e descrição, explicando para que serve cada grupo de opções e de onde o PDF tira o cabeçalho e a logomarca.
- [e96ef46](https://github.com/coca-mann/plugin-gac/commit/e96ef46) - Aba Itens do PRE reorganizada em cartões (itens, importação e ações), com a situação de cada linha destacada por cores.
- [10cab73](https://github.com/coca-mann/plugin-gac/commit/10cab73) - Formulários de registrar retorno e de corrigir retorno divididos em blocos (resultado, serviço e custo, documentos), com o botão de envio no rodapé e a opção de marcar como extraviada destacada em vermelho.

## [0.2.0] - 2026-09-25

### Added

- [e6068dc](https://github.com/coca-mann/plugin-gac/commit/e6068dc) - Botão "Recolher itens para importar" no cartão de importação do rascunho, que esconde a lista de candidatos para deixar só os itens já importados e suas descrições; a escolha fica guardada no navegador.

### Fixed

- [75738db](https://github.com/coca-mann/plugin-gac/commit/75738db) - A lista de importação passou a trazer só itens do tipo ativo (incluindo os ativos personalizados): itens de outros tipos ligados ao ticket, como as respostas do Forms, deixam de aparecer, e o servidor também recusa a importação dessas chaves.

## [0.1.2] - 2026-09-25

### Changed

- [93e06d2](https://github.com/coca-mann/plugin-gac/commit/93e06d2) - PDF: o número do protocolo deixou de aparecer abaixo do título (já consta na tabela do cabeçalho) e o rodapé passou a mostrar a URL da aplicação do GLPI no lugar do número do PRE.
- [1898892](https://github.com/coca-mann/plugin-gac/commit/1898892) - PDF preparado para impressão em preto e branco: cabeçalhos das tabelas em cinza claro com texto escuro, e bordas com o mesmo tom e a mesma espessura em todas as células.

## [0.1.1] - 2026-09-25

### Changed

- [f25c8ed](https://github.com/coca-mann/plugin-gac/commit/f25c8ed), [fa605b6](https://github.com/coca-mann/plugin-gac/commit/fa605b6) - Cabeçalho do PDF mais compacto: a logomarca é ajustada a uma caixa de 60 × 14 mm mantendo a proporção (logos quadradas e retangulares), os dados da empresa usam fonte menor e há menos espaço abaixo do cabeçalho.

### Fixed

- [0b90747](https://github.com/coca-mann/plugin-gac/commit/0b90747) - Corrigido o travamento na geração do PDF quando a entidade raiz não tem entidade pai registrada (comum em bancos migrados de versões antigas do GLPI) e nenhuma logomarca é encontrada: a busca ficava em laço e prendia um processo do PHP a cada tentativa.

## [0.1.0] - 2026-09-24

### Added

- [c36ac41](https://github.com/coca-mann/plugin-gac/commit/c36ac41), [2b1a944](https://github.com/coca-mann/plugin-gac/commit/2b1a944), [9d236f5](https://github.com/coca-mann/plugin-gac/commit/9d236f5), [b8f4467](https://github.com/coca-mann/plugin-gac/commit/b8f4467), [4bff878](https://github.com/coca-mann/plugin-gac/commit/4bff878) - Novo módulo PRE (Protocolo de Reparo de Equipamento), no menu Gerência: cadastro de protocolos com numeração automática por ano, permissões próprias (enviar, registrar retorno e reabrir) e lista com coluna de status.
- [e0d1f6e](https://github.com/coca-mann/plugin-gac/commit/e0d1f6e), [0f9168f](https://github.com/coca-mann/plugin-gac/commit/0f9168f), [1dd6eee](https://github.com/coca-mann/plugin-gac/commit/1dd6eee) - Regras internas do PRE isoladas do GLPI: estados e transições do protocolo e das linhas, número do protocolo, leitura das informações adicionais do ticket, configurações tipadas e escolha das ações de cada retorno.
- [14f1b61](https://github.com/coca-mann/plugin-gac/commit/14f1b61) - Testes unitários das regras internas, que rodam sem o GLPI.
- [4aa2c7e](https://github.com/coca-mann/plugin-gac/commit/4aa2c7e), [27d4e22](https://github.com/coca-mann/plugin-gac/commit/27d4e22), [b90d687](https://github.com/coca-mann/plugin-gac/commit/b90d687) - Página de configuração do plugin com uma seção por módulo, recolhível, onde se definem as categorias de ticket elegíveis, os status do ativo, os motivos de pendência e as ações de cada retorno.
- [96398f7](https://github.com/coca-mann/plugin-gac/commit/96398f7), [14f9bd4](https://github.com/coca-mann/plugin-gac/commit/14f9bd4) - Importação de tickets elegíveis (com ativo associado) para um protocolo em rascunho, com opção de marcar todos.
- [0f15782](https://github.com/coca-mann/plugin-gac/commit/0f15782) - Envio dos equipamentos ao fornecedor linha a linha, com barra de progresso, sem risco de estourar o tempo limite do servidor.
- [4a867f4](https://github.com/coca-mann/plugin-gac/commit/4a867f4), [c696aef](https://github.com/coca-mann/plugin-gac/commit/c696aef) - Registro do retorno de cada linha (reparado, sem defeito, sem conserto, orçamento não aprovado ou extraviado), com destino para baixa patrimonial ou manutenção com defeito, custo lançado no ticket e encerramento automático do protocolo.
- [d67ec3d](https://github.com/coca-mann/plugin-gac/commit/d67ec3d) - Reabertura de protocolo encerrado, com motivo e permissão própria, para corrigir os dados de retorno.
- [b8a166d](https://github.com/coca-mann/plugin-gac/commit/b8a166d), [f74ea62](https://github.com/coca-mann/plugin-gac/commit/f74ea62), [3f4e055](https://github.com/coca-mann/plugin-gac/commit/3f4e055) - PDF do protocolo com o cabeçalho e a logomarca da entidade, gerado no envio e anexado ao protocolo, com pré-visualização em rascunho.
- [548324c](https://github.com/coca-mann/plugin-gac/commit/548324c), [0757144](https://github.com/coca-mann/plugin-gac/commit/0757144) - Anexo de documentos no retorno e na correção, que ficam nos documentos do ticket e do protocolo.
- [7dc41e7](https://github.com/coca-mann/plugin-gac/commit/7dc41e7), [fbe827a](https://github.com/coca-mann/plugin-gac/commit/fbe827a), [df74f3c](https://github.com/coca-mann/plugin-gac/commit/df74f3c), [0757144](https://github.com/coca-mann/plugin-gac/commit/0757144), [77fd05e](https://github.com/coca-mann/plugin-gac/commit/77fd05e), [ac03eb5](https://github.com/coca-mann/plugin-gac/commit/ac03eb5), [0e204c4](https://github.com/coca-mann/plugin-gac/commit/0e204c4) - Ajustes de uso: técnico responsável preenchido com o usuário logado, botão Cancelar PRE ao lado de Salvar, retornos registrados sem recarregar a página, componentes de lista e de data do GLPI nos formulários, lista de itens recolhível e ícone na aba Itens.
- [804bf43](https://github.com/coca-mann/plugin-gac/commit/804bf43) - Eventos do protocolo (envio, retorno, reabertura, correção e outros) registrados no histórico nativo do GLPI, em uma única aba Histórico.
- [476ed38](https://github.com/coca-mann/plugin-gac/commit/476ed38) - Fluxo de release que gera o pacote do plugin, com as dependências, pronto para colar na pasta plugins.

### Changed

- [9466f32](https://github.com/coca-mann/plugin-gac/commit/9466f32) - Nome de exibição do plugin alterado para Plugin - DTI GAC.

### Fixed

- [8c60af1](https://github.com/coca-mann/plugin-gac/commit/8c60af1) - Removidas as entradas N/A que apareciam no histórico nativo do protocolo.
- [ead4e77](https://github.com/coca-mann/plugin-gac/commit/ead4e77) - Removido o aviso de PHP gerado a cada requisição pelas páginas do plugin.

<!--
Ao criar uma nova tag:
1. Renomeie a seção acima para incluir a versão:
   ## [Unreleased]

   ## [X.Y.Z] - AAAA-MM-DD
   ### Added
   - ...
2. Copie o conteúdo da seção "Changelog" do(s) PR(s) incluídos na tag.
3. Preencha a data no formato AAAA-MM-DD.
-->
