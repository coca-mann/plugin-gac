
> Conteúdo-base para publicação no WikiJS. Onde estiver marcado `[GIF AQUI: ...]`, grave a tela correspondente e substitua a marcação pela incorporação do GIF.

O **Monitor** é o módulo do plugin Gac para monitoramento de tickets em tempo (quase) real. Ele mostra, numa tabela que se atualiza sozinha, os tickets que batem num critério escolhido — pensado tanto para uma TV/kiosk ligada o dia todo numa sala quanto para uma aba que um técnico deixa aberta no próprio GLPI. Cada **Tela de Monitoramento** é independente: tem sua própria entidade, seu próprio filtro, suas próprias colunas e sua própria aparência.

O plugin tem seu próprio menu no sidebar do GLPI, **Plugin - DTI GAC**, com uma entrada por módulo. O Monitor é acessado pela entrada **Telas de Monitoramento**; a configuração global do módulo fica na entrada **Configurações**, dentro desse mesmo menu.

Este manual segue a ordem recomendada de uso: primeiro a configuração global (feita uma vez, normalmente pelo administrador), depois o pré-requisito nativo do GLPI de criar uma Pesquisa Salva pública, depois a criação de uma Tela e todos os seus campos, depois as duas formas de exibir a Tela (dentro do GLPI e em público, sem login) e como o painel se comporta enquanto fica aberto, e por fim as permissões.

> Sempre que este manual trouxer um aviso, uma restrição ou um comportamento que exige atenção, ele aparece formatado como esta caixa.

---

# Tela: Configuração do Módulo

Acessível pelo menu lateral **Plugin - DTI GAC > Configurações**. Assim como no PRE e no LTBP, essa tela é compartilhada por todos os módulos do plugin: cada um tem seu próprio bloco (cartão), com título e **botão Salvar independente** — salvar a seção do Monitor não grava nem interfere na seção de outro módulo. A seção do Monitor é **"Painel de Monitoramento de Tickets"** e só aparece para quem tem o direito "Configurar" do Monitor.

## Bloco "Painel de Monitoramento de Tickets"

- **Intervalo padrão de atualização (segundos)**: campo numérico, mínimo 5, sugestão de 15. É o intervalo usado por qualquer Tela que não definir o seu próprio (ver campo **Intervalo de atualização** no formulário da Tela).
- **Tempo de exibição do banner de ticket novo (segundos)**: de 3 a 120, padrão 10. Quanto tempo cada banner fica na tela (ver [Banner de ticket novo](#banner-de-ticket-novo)).
- **Descrição no banner**: caixa de seleção, marcada por padrão. Desmarque se a TV fica à vista de quem não deve ler o texto dos chamados: o banner deixa de mostrar a descrição.
- **Arquivo de som do alerta**: envio de um arquivo **mp3, ogg ou wav de até 512 KB**. Quando uma Tela tem o alerta de ticket novo ligado (ver [Formulário da Tela de Monitoramento](#tela-formulário-da-tela-de-monitoramento)), o som é reproduzido a cada ciclo em que algum ticket novo aparecer. Com um arquivo enviado, a tela mostra o nome, o tamanho, um player para ouvir e a opção **Remover o arquivo atual**; escolher um arquivo novo substitui o atual. O arquivo fica guardado no próprio GLPI, então a TV não precisa de internet.
- **URL do som de alerta**: campo de URL, opcional, que só vale quando **não** há arquivo enviado.

> Cada Tela pode ter o **seu próprio som** (campo **Som de alerta desta Tela** no cadastro da Tela). O som desta configuração é o que vale para as Telas que não têm o seu.

> O plugin **não vem com nenhum arquivo de som embutido**. Sem arquivo e sem URL, o alerta de ticket novo continua existindo, mas fica só visual (destaque verde na linha, ver [Destaque de ticket novo](#destaque-de-ticket-novo)), sem som.

> **O navegador da TV precisa estar liberado para tocar som sozinho.** Enviar o arquivo para o GLPI não dispensa isso: os navegadores só tocam áudio depois de um clique na página, e uma TV ninguém clica. Se o som estiver bloqueado, aparece um **ícone de alto-falante cortado** ao lado do relógio, na barra do topo; um clique na tela o esconde e libera o som. Para a TV tocar sem clique, configure o navegador dela uma vez: no Chrome/Edge em modo quiosque, use a opção `--autoplay-policy=no-user-gesture-required` (ou a política `AutoplayAllowlist` com o endereço do GLPI); no Firefox, `media.autoplay.default = 0` (ou a política `Autoplay`). Um navegador de TV sem essa opção não vai tocar o som sozinho, e o alerta fica só visual.

> `[GIF AQUI: preencher o intervalo padrão, enviar o arquivo do som de alerta, e salvar]`

## Bloco "Conta de serviço para Telas públicas"

Exibe um aviso explicando o motivo do bloco: as Telas públicas (sem login, para TV) não têm sessão de usuário; para conseguir buscar os tickets mesmo assim, a exibição pública se autentica internamente com esta conta, só pelo tempo de cada consulta, e a sessão é destruída antes da resposta chegar ao navegador da TV.

- **Usuário**: login de um usuário GLPI já cadastrado.
- **Senha**: campo de senha. Fica sempre em branco quando exibido — deixar em branco no Salvar **mantém** a senha já configurada; só é sobrescrita se algo for digitado.

> Cadastre um usuário GLPI **dedicado**, com direito de leitura de tickets nas entidades que as Telas públicas vão efetivamente usar, e sem nenhum outro direito administrativo. A senha fica criptografada em repouso pelo próprio mecanismo do GLPI, nunca é reexibida em texto puro neste formulário.

> `[GIF AQUI: cadastrar o usuário e a senha da conta de serviço e salvar]`

## Bloco "Proteção das Telas públicas contra excesso de requisições"

As telas públicas não têm login, então qualquer um que conheça o link pode consultá-las. Cada consulta autentica a conta de serviço e faz uma busca no GLPI, por isso há um **limite de requisições por minuto**:

- **Limite por endereço de cliente (requisições por minuto, 0 desliga)**: padrão **120**. Vale para todas as páginas públicas juntas (a tela, os dados e o som). Cada TV consulta a cada poucos segundos (cerca de 6 vezes por minuto), então 120 comporta cerca de 20 TVs na mesma rede. **Suba o valor se a sua rede tiver mais TVs.**
- **Limite por link público (requisições por minuto, 0 desliga)**: padrão **240**. Vale para os dados de um mesmo link, de qualquer endereço; protege contra um ataque que usa muitos endereços contra um link que ele conhece. Várias TVs mostrando a mesma Tela somam neste limite.
- **Proxies confiáveis (endereços ou faixas, separados por vírgula)**: em branco por padrão. Veja a explicação abaixo.

Quando o limite estoura, a resposta é de erro (**429**, com o tempo de espera). Na TV, **a tabela continua na tela**, o anel de atualização fica vermelho e a TV espera o tempo indicado antes de tentar de novo; quando a janela de um minuto passa, volta ao normal sozinha.

### O que é o nginx e o que é um proxy confiável

- **nginx** é um programa de servidor web. Em muitas instalações ele fica **na frente** do GLPI, como uma recepção: recebe os acessos vindos da rede e os repassa ao GLPI (isso se chama **proxy reverso**). Por isso o GLPI só conversa com o nginx, e não diretamente com cada TV ou computador.
- **Proxy** é qualquer servidor que fica no meio do caminho e repassa o acesso de outro. O proxy sabe de onde o acesso realmente veio e pode informar isso ao GLPI num cabeçalho chamado `X-Forwarded-For` ("encaminhado de").
- O problema é que qualquer pessoa pode escrever esse cabeçalho com um endereço falso. Por isso o plugin só acredita nele quando o acesso chega de um **proxy confiável**: um endereço que **você** declarou, nesta tela, como sendo o seu nginx (ou outro proxy da sua rede). Acessos que não vêm desses endereços têm o cabeçalho ignorado.
- Para que serve saber o endereço real: o limite de requisições é contado **por cliente**. Sem saber quem é cada cliente, o GLPI veria todo mundo como "o nginx" e o limite acabaria atingido por todas as TVs juntas.

### Se o GLPI está atrás de um nginx (proxy reverso)

Quando o nginx fica na frente do GLPI e repassa as requisições, o GLPI enxerga **todas** as conexões vindo do endereço do nginx. Sem ajuste, todas as TVs (e quem estiver atacando) são contadas como **um só cliente**, e um ataque esgotaria o limite e derrubaria as TVs legítimas. Para evitar:

1. No nginx, envie o endereço real do cliente: `proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;` (além do `proxy_set_header Host $host;` de sempre).
2. Neste bloco, em **Proxies confiáveis**, informe o endereço do nginx (por exemplo `10.0.0.5`, ou uma faixa como `10.0.0.0/24`). Só as conexões vindas desses endereços têm o cabeçalho `X-Forwarded-For` aceito; para qualquer outro, ele é ignorado, porque quem fala direto com o servidor pode escrevê-lo como quiser.
3. O bloco mostra uma linha com o **endereço que o GLPI enxerga nesta requisição** e o cliente que ele considera. Se aparecer o endereço do nginx como cliente, a lista ainda está faltando ou o nginx não está enviando o cabeçalho.

Se o nginx é apenas o servidor web do GLPI (repassa para o PHP-FPM e não para outro servidor HTTP), o GLPI já vê o endereço real do cliente e a lista deve ficar **em branco**.

> O limite é aproximado: serve para frear um excesso de requisições, não como uma cota exata. Se o cache do GLPI falhar, a requisição passa em vez de bloquear as TVs.

> `[GIF AQUI: abrir o bloco, informar o endereço do nginx em Proxies confiáveis e salvar]`

## O botão Salvar

Cada seção de módulo (Monitor, PRE, LTBP etc.) tem o seu próprio botão **Salvar**, no rodapé do cartão. Ele só envia e grava os campos **daquela seção** — nenhum outro módulo é afetado. Depois de salvar, a página recarrega e mostra a mensagem de confirmação "Configuração do Monitor salva.".

---

# Pesquisa Salva Pública (pré-requisito do GLPI)

Antes de criar a primeira Tela, é preciso ter pelo menos uma **Pesquisa Salva de Chamado (Ticket), pública**, no GLPI. É um recurso nativo do GLPI — não é uma tela do plugin —, mas sem ela o campo **Pesquisa salva** do formulário da Tela fica vazio e nenhuma Tela pode ser criada.

> O Monitor só aceita Pesquisas Salvas **públicas** (não as privadas de cada usuário). O motivo: reaproveitar a busca nativa do GLPI em vez de reinventar um filtro próprio, e evitar que uma Tela pare de funcionar silenciosamente se o dono de uma busca pessoal a editar ou apagar.

## Criar a pesquisa

1. Vá em **Assistência > Chamados** e monte os filtros desejados (status, categoria, urgência, entidade etc.) usando a busca normal do GLPI.
2. Clique no ícone de marcador (🔖) **"Pesquisas salvas"**, ao lado do botão **Pesquisar**, acima da lista.
3. No painel que abre, salve a pesquisa atual com um nome — por exemplo "Fila de tickets abertos".

> `[GIF AQUI: montar um filtro de chamados, abrir o painel de Pesquisas salvas e salvar a pesquisa atual com um nome]`

## Tornar a pesquisa pública

Uma pesquisa nasce **Privada**. Para usá-la numa Tela de Monitoramento, ela precisa ser trocada para **Pública**:

1. Vá em **Ferramentas > Pesquisas salvas** (ou abra o mesmo painel do passo anterior e use o ícone de engrenagem no topo).
2. Clique na pesquisa criada para abrir seu formulário.
3. No campo **Visibilidade**, troque de **Privado** para **Público** e clique em **Salvar**.

> O campo **Visibilidade** só aparece (e só pode ser trocado para "Público") para quem tem o direito **"Atualizar"** na permissão **"Pesquisas salvas (públicas)"**, dentro da aba **Ferramentas** do perfil (ver [Permissões](#permissões)). Sem esse direito, a pesquisa fica travada em Privada e nunca aparece no dropdown do formulário da Tela.

> `[GIF AQUI: abrir a pesquisa salva criada e trocar o campo Visibilidade de Privado para Público]`

---

# Tela: Lista de Telas de Monitoramento

Acessível pelo menu lateral **Plugin - DTI GAC > Telas de Monitoramento**. Lista todas as Telas, usando o mecanismo de busca padrão do GLPI: colunas configuráveis, filtros salvos, exportação.

## Funcionalidades desta tela

- **Buscar/filtrar Telas** pelos campos padrão da busca do GLPI (nome, entidade, pública, ativa, intervalo, ordenação, tema, tamanho da fonte, níveis de entidade).
- **Abrir uma Tela** existente clicando na linha, para editar sua configuração.
- **Criar uma nova Tela** pelo botão de adicionar (canto superior direito, padrão GLPI). Só aparece para quem tem direito de criação no Monitor.

> `[GIF AQUI: abrir a lista de Telas pelo menu e clicar em "Novo"]`

---

# Tela: Formulário da Tela de Monitoramento

## Campos gerais

O formulário da Tela é dividido em cinco cartões: **Identificação**, **Exibição**, **Atualização**, **Alertas de ticket novo** e **Publicação**. Os campos com um **?** ao lado trazem uma explicação ao passar o mouse. A pesquisa salva e as colunas **não** ficam neste formulário: ficam na aba **Páginas** de cada Tela.

### Identificação

- **Nome da Tela**: texto livre, obrigatório. Aparece como título no topo do painel, tanto na exibição autenticada quanto na pública.
- **Entidade dos tickets** (obrigatória): dropdown nativo de entidades. Numa Tela nova, vem preenchida com a entidade ativa de quem está criando, mas pode ser trocada para qualquer outra. Define de qual unidade/entidade a Tela mostra tickets — **de forma independente da sessão de quem está vendo**, já que a exibição pública não tem sessão nenhuma.
- **Incluir sub-entidades**: alternância Sim/Não (padrão Não). Com Sim, a Tela também mostra tickets das entidades abaixo da escolhida na árvore; com Não, só da entidade exata.
- **Tela ativa**: alternância Sim/Não (padrão Sim). Uma Tela **inativa** não pode mais ser aberta — nem autenticada, nem pela URL pública — mesmo que o link continue existindo.

> `[GIF AQUI: criar uma Tela nova escolhendo nome e entidade]`

## Aparência e comportamento

### Exibição

- **Tema (claro ou escuro)**: dropdown **Escuro** (padrão) ou **Claro**. Troca o fundo, as cores da tabela e do cabeçalho da página inteira.
- **Tamanho do texto da tabela**: dropdown de 5 níveis — Pequena, Normal, **Grande (recomendado)**, Muito grande, Extra grande. Afeta o texto das linhas, o cabeçalho das colunas e a pílula de prioridade (que crescem juntos); o título e o relógio no topo não mudam de tamanho.
- **Ordem padrão das linhas**: vale para as páginas que não escolherem a sua própria ordem (veja "Páginas"). Dropdown com três opções — **"Prioridade, status e data (recomendado)"** (padrão de uma Tela nova: a prioridade mais alta fica no topo — Crítica, Muito alta, Alta, Média, Baixa, Muito baixa —; dentro da mesma prioridade, vem uma ordem de status pensada para monitoramento — Novo, Em atendimento, Em atendimento planejado, Pendente, Aprovação, Solucionado, qualquer outro —; e, em seguida, a data de abertura mais recente primeiro), **"Tempo decorrido (mais antigo primeiro)"** (o ticket aberto há mais tempo fica no topo; em caso de empate na abertura, vale a prioridade mais alta) e **"Padrão do GLPI (ID crescente)"** (ordem simples por ID).
- **Pintar as linhas por**: o que define a cor de fundo de cada linha — **Sem cor**, **Status do chamado**, **Prioridade do chamado (recomendado)** ou **Prazo do SLA** (vencido, perto de vencer, no prazo, parado em Pendente, sem SLA).
- **Níveis da entidade na coluna Entidade**: dropdown com 3 opções — **"Somente a entidade do ticket"** (1 nível), **"Entidade + 1 nível acima"** (2 níveis) e **"Entidade + até 2 níveis acima (recomendado)"** (3 níveis, padrão). Controla quantos níveis da hierarquia de entidades aparecem na coluna **Entidade**, contados de baixo para cima a partir da entidade do próprio ticket — útil para não imprimir a árvore inteira (`Grupo > Fimca > Campus > Setor`) numa tela de TV.

> `[GIF AQUI: trocar tema, tamanho do texto e níveis de entidade, e ver o resultado na exibição]`

### Atualização

- **Atualizar os tickets a cada (segundos)**: campo numérico, mínimo 5, opcional. Em branco, usa o intervalo padrão da configuração global (ver [Configuração do Módulo](#tela-configuração-do-módulo)).
- **Tempo de cada página (segundos)**: campo numérico, mínimo 5, opcional. Só vale quando a Tela tem mais de uma página. Em branco, usa o tempo padrão da configuração global. A troca entre páginas tem um efeito suave de desaparecer e reaparecer (cerca de 0,6 s no total); quem configurou o sistema para reduzir animações vê a troca direta.

### Alertas de ticket novo

- **Alerta de ticket novo (som e destaque da linha)**: alternância Sim/Não (padrão Sim). Liga ou desliga o alerta desta Tela especificamente (ver [Destaque de ticket novo](#destaque-de-ticket-novo)) — o destaque visual (linha verde) acontece sempre que ligado; o som só se, além disso, houver um som configurado (abaixo).
- **Banner grande de ticket novo**: alternância Sim/Não (padrão Não). Quando ligada, cada ticket novo aparece num banner grande no centro da tela, com os detalhes dele (ver [Banner de ticket novo](#banner-de-ticket-novo)).
- **Som de alerta desta Tela**: envio de um arquivo **mp3, ogg ou wav de até 512 KB**, que vale para **todas as páginas** da Tela. Com um arquivo enviado, o formulário mostra o nome, o tamanho, um player para ouvir e a opção **Remover o som desta Tela**. **Sem arquivo na Tela, vale o som da configuração do plugin** (o arquivo enviado lá e, na falta dele, a URL). Uma linha logo abaixo do campo diz qual som está valendo hoje. Se o mesmo arquivo for usado na Tela e na configuração do plugin, remover de um lado não apaga o do outro.

## Exibição pública

- **Liberar exibição pública (link sem login, para TV)**: alternância Sim/Não (padrão Não). Ao ligar pela primeira vez, o sistema gera automaticamente um **token** e mostra o campo **URL pública** — o endereço completo (com domínio) para abrir num navegador de TV/kiosk, sem login nenhum.
- Ao lado da URL pública, um botão de **copiar link** (ícone de clipe) copia o endereço completo para a área de transferência, com uma confirmação visual rápida (✓ ou ✗).
- Um botão **Gerar novo link** invalida a URL pública atual e gera uma nova, pedindo confirmação antes ("Gerar um novo link público? O link atual deixa de funcionar.").

> Qualquer pessoa com a URL pública consegue ver a Tela, sem senha — o token é o único controle de acesso desse link, por desenho. Gere um novo link se o endereço for exposto por engano (por exemplo, compartilhado fora da rede interna).

> `[GIF AQUI: ligar "Liberar exibição pública", copiar a URL gerada e, em seguida, gerar um novo link]`

## Colunas exibidas

Uma lista com as 11 colunas do catálogo do Monitor — **ID**, **Título**, **Entidade**, **Status**, **Prioridade**, **Categoria**, **Solicitante**, **Técnico**, **Grupo técnico**, **Abertura** e **Tempo decorrido** — cada uma com uma caixa de marcação e um ícone de arrastar (⠿) à esquerda.

- Cada página também escolhe a sua **Ordem das linhas**. A primeira opção, "Usar o padrão da Tela", segue a **Ordem padrão das linhas** do formulário da Tela; a lista da aba **Páginas** mostra a ordem que cada página usa de fato e marca "(padrão da Tela)" quando ela é herdada.
- Marque as colunas que a Tela deve mostrar; desmarque as que não interessam.
- Arraste pelo ícone à esquerda para reordenar — a ordem da lista é a ordem de exibição na tabela.
- Uma Tela nova já vem com 9 das 11 colunas marcadas (todas, exceto **Categoria** e **Grupo técnico**), numa ordem pensada para leitura rápida numa TV.

> `[GIF AQUI: desmarcar uma coluna, arrastar outra para uma posição diferente e salvar]`

---

# Exibição da Tela

A mesma tabela aparece nos dois caminhos abaixo — a única diferença é a moldura em volta (dentro do GLPI, ou uma página isolada) e como se autentica.

## Exibição autenticada

Um técnico logado no GLPI, com direito de leitura no Monitor, abre a Tela de dentro do próprio GLPI (clicando na linha da lista, ou pelo link direto da Tela). A tabela aparece dentro do chrome normal do GLPI — menu lateral, atalho de busca, etc.

> Uma Tela **inativa** dá "não encontrado" mesmo na exibição autenticada — não é um erro de permissão, é o mesmo bloqueio da exibição pública.

## Exibição pública (TV/kiosk)

A URL pública (gerada no formulário da Tela) abre uma página isolada, sem nenhum elemento do GLPI em volta — só o painel. Não exige login nem cookie de sessão: por isso é a forma recomendada de deixar ligada numa TV o dia inteiro, sem se preocupar com sessão expirando.

> Um token inválido, ou uma Tela que foi desativada ou excluída, mostram sempre a mesma página genérica de "não encontrado" — de propósito, para não revelar se um token específico já existiu um dia.

> `[GIF AQUI: abrir a URL pública de uma Tela num navegador anônimo, sem nenhum login]`

## Como o painel se comporta enquanto fica aberto

- **Relógio**: no canto superior direito, mostra a hora do **servidor GLPI**, não a hora do computador/TV que está exibindo a tela — corrigida automaticamente a cada ciclo de atualização.
- **Anel de contagem regressiva**: ao lado do relógio, esvazia ao longo do intervalo de atualização configurado e dispara a próxima busca quando chega a zero. Fica **verde** quando a última atualização teve sucesso e **vermelho** quando falhou (a última tabela boa continua na tela enquanto isso); pisca suavemente enquanto uma busca está em andamento.
- **Mudança de tema/fonte/níveis de entidade/intervalo sem recarregar**: se alguém editar a Tela enquanto a exibição já está aberta (numa TV, por exemplo), o próximo ciclo de atualização já aplica o novo tema, tamanho de fonte, níveis de entidade e intervalo — sem precisar recarregar a página manualmente.
- **Tela desativada (ou link público desligado) enquanto está aberta**: no ciclo de atualização seguinte a tabela é **esvaziada** (as linhas, os alertas pendentes e os banners em fila saem da memória do navegador) e o painel mostra, em destaque, **"Esta Tela foi desativada ou não está mais disponível."** O anel de atualização fica vermelho. A tela continua consultando no ritmo normal: se a Tela for **reativada**, ela volta sozinha, sem recarregar a página e sem tocar som nem abrir banner para os tickets que já estavam lá. Quem abre uma Tela desativada **pela primeira vez** continua vendo a página de erro do GLPI ("Item não encontrado"), igual a antes.

### Destaque de ticket novo

Quando um ticket aparece pela primeira vez num ciclo de atualização (ou seja, não estava no ciclo anterior), a linha pisca em **verde**, com texto branco, por cerca de 2 segundos, voltando depois ao fundo normal. Esse destaque é independente de som: ele acontece sempre que o alerta da Tela estiver ligado, mesmo sem nenhum arquivo ou URL de som configurado na configuração global. A primeira vez que a página carrega nunca pisca nenhuma linha (senão toda a tabela piscaria ao abrir).

> `[GIF AQUI: com a exibição aberta, criar um ticket que bate no filtro da Tela e mostrar a linha piscando em verde no ciclo seguinte]`

### Banner de ticket novo

Com a opção **Banner de ticket novo** ligada na Tela, cada ticket que aparece pela primeira vez cobre o centro da tela com um **banner grande**: número, título, prioridade, solicitante, entidade, categoria e as primeiras linhas da descrição. O **fundo do banner tem a cor da prioridade do ticket** (as mesmas cores configuradas no GLPI), e o texto fica claro ou escuro conforme a cor, para ler bem. O banner entra e sai com um efeito suave (esmaece e desliza), e fica na tela pelo tempo definido na configuração do plugin.

- **Mais de um ticket novo ao mesmo tempo**: os banners saem **um de cada vez**, o mais urgente primeiro. No máximo 3 por vez: o último avisa "e mais N tickets novos" para os que não couberam.
- **Som**: com o banner ligado, o som de alerta toca **junto com cada banner**, no instante em que ele aparece (se o alerta sonoro da Tela estiver ligado e houver som configurado). Com o banner desligado, o som continua tocando uma vez por atualização.
- **Telas com várias páginas**: se o ticket novo é de uma página que não está na tela, o banner (e o som) esperam o rodízio levar essa página à tela. Enquanto isso só a bolinha da página pisca.
- **A descrição aparece para quem olhar a TV**: ela é o texto livre do solicitante. Se a TV fica à vista de quem não deve ler os chamados, desmarque **Descrição no banner** na configuração do plugin.
- O banner cobre a tabela enquanto está na tela. Com o tempo padrão de 10 segundos e até 3 banners seguidos, a tabela pode ficar coberta por cerca de 30 segundos.

> `[GIF AQUI: criar um ticket que bate no filtro da Tela e mostrar o banner aparecendo com a cor da prioridade, ficando alguns segundos e saindo]`

---

# Permissões

O Monitor tem uma linha própria na aba de perfil **Plugin - DTI GAC**, com as permissões básicas de um cadastro do GLPI mais a permissão específica do módulo. Um perfil pode combinar qualquer conjunto delas.

**Permissões básicas** (comuns a qualquer item do GLPI):

- **Ver**: abrir a lista de Telas e as Telas existentes (exibição autenticada). Sem essa permissão, a entrada **Telas de Monitoramento** nem aparece no menu do plugin.
- **Criar**: usar o botão **Novo** na lista de Telas.
- **Editar**: alterar qualquer campo do formulário de uma Tela já existente.
- **Excluir**: apagar definitivamente uma Tela. Não existe lixeira para este cadastro — a exclusão é direta e sem volta, igual ao cadastro de Motivos do LTBP.

**Permissão específica do Monitor:**

- **Configurar** (`RIGHT_CONFIG`): libera a seção **"Painel de Monitoramento de Tickets"** na tela de configuração do plugin (intervalo padrão, som do alerta, conta de serviço). Não dá acesso a nenhuma ação sobre as Telas em si — só à configuração global.

> A exibição **pública** (via token) não passa por nenhum direito de perfil — o token é o único controle de acesso desse caminho, por desenho (ver [Exibição pública](#exibição-pública-tvkiosk)).

**Permissão nativa do GLPI necessária à parte** (não é do plugin, mas bloqueia a criação de Telas se faltar):

- **"Atualizar"** em **Pesquisas salvas (públicas)** (aba **Ferramentas** do perfil): sem ela, ninguém consegue trocar uma Pesquisa Salva de Privada para Pública, e o dropdown de Pesquisa Salva do formulário da Tela fica sempre vazio (ver [Pesquisa Salva Pública](#pesquisa-salva-pública-pré-requisito-do-glpi)).
