
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
- **URL do som de alerta**: campo de URL, opcional. Quando uma Tela tem o alerta de ticket novo ligado (ver [Formulário da Tela de Monitoramento](#tela-formulário-da-tela-de-monitoramento)) e esta URL está preenchida, o som é reproduzido a cada ciclo em que algum ticket novo aparecer.

> O plugin **não vem com nenhum arquivo de som embutido**. Em branco, o alerta de ticket novo continua existindo, mas fica só visual (destaque verde na linha, ver [Destaque de ticket novo](#destaque-de-ticket-novo)), sem som.

> `[GIF AQUI: preencher o intervalo padrão e a URL do som de alerta, e salvar]`

## Bloco "Conta de serviço para Telas públicas"

Exibe um aviso explicando o motivo do bloco: as Telas públicas (sem login, para TV) não têm sessão de usuário; para conseguir buscar os tickets mesmo assim, a exibição pública se autentica internamente com esta conta, só pelo tempo de cada consulta, e a sessão é destruída antes da resposta chegar ao navegador da TV.

- **Usuário**: login de um usuário GLPI já cadastrado.
- **Senha**: campo de senha. Fica sempre em branco quando exibido — deixar em branco no Salvar **mantém** a senha já configurada; só é sobrescrita se algo for digitado.

> Cadastre um usuário GLPI **dedicado**, com direito de leitura de tickets nas entidades que as Telas públicas vão efetivamente usar, e sem nenhum outro direito administrativo. A senha fica criptografada em repouso pelo próprio mecanismo do GLPI, nunca é reexibida em texto puro neste formulário.

> `[GIF AQUI: cadastrar o usuário e a senha da conta de serviço e salvar]`

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

- **Nome**: texto livre, obrigatório. Aparece como título no topo do painel, tanto na exibição autenticada quanto na pública.
- **Entidade** (obrigatória): dropdown nativo de entidades. Numa Tela nova, vem preenchida com a entidade ativa de quem está criando, mas pode ser trocada para qualquer outra. Define de qual unidade/entidade a Tela mostra tickets — **de forma independente da sessão de quem está vendo**, já que a exibição pública não tem sessão nenhuma.
- **Incluir sub-entidades**: alternância Sim/Não (padrão Não). Com Sim, a Tela também mostra tickets das entidades abaixo da escolhida na árvore; com Não, só da entidade exata.
- **Pesquisa salva (Ticket, compartilhada)** (obrigatória): dropdown só com Pesquisas Salvas de Chamado que já estejam **Públicas** (ver [Pesquisa Salva Pública](#pesquisa-salva-pública-pré-requisito-do-glpi)). É o critério — status, categoria, urgência etc. — que decide quais tickets entram na Tela.

> Tentar salvar sem escolher uma Pesquisa Salva pública de Chamado é bloqueado, com a mensagem "Escolha uma Pesquisa Salva de Ticket compartilhada."

> `[GIF AQUI: criar uma Tela nova escolhendo nome, entidade e a Pesquisa Salva]`

## Aparência e comportamento

- **Ordenação dos tickets**: dropdown com duas opções — **"Urgência, status e data (recomendado)"** (padrão de uma Tela nova: ordena por urgência decrescente, depois por uma prioridade de status pensada para monitoramento — Novo, Em atendimento, Em atendimento planejado, Pendente, Aprovação, Solucionado, qualquer outro —, depois por data de abertura mais recente primeiro) e **"Padrão do GLPI (ID crescente)"** (ordem simples por ID).
- **Tema da tela**: dropdown **Escuro** (padrão) ou **Claro**. Troca o fundo, as cores da tabela e do cabeçalho da página inteira.
- **Tamanho da fonte (só a tabela)**: dropdown de 5 níveis — Pequena, Normal, **Grande (recomendado)**, Muito grande, Extra grande. Afeta o texto das linhas, o cabeçalho das colunas e a pílula de prioridade (que crescem juntos); o título e o relógio no topo não mudam de tamanho.
- **Níveis de entidade exibidos**: dropdown com 3 opções — **"Somente a entidade do ticket"** (1 nível), **"Entidade + 1 nível acima"** (2 níveis) e **"Entidade + até 2 níveis acima (recomendado)"** (3 níveis, padrão). Controla quantos níveis da hierarquia de entidades aparecem na coluna **Entidade**, contados de baixo para cima a partir da entidade do próprio ticket — útil para não imprimir a árvore inteira (`Grupo > Fimca > Campus > Setor`) numa tela de TV.
- **Intervalo de atualização (segundos)**: campo numérico, mínimo 5, opcional. Em branco, usa o intervalo padrão da configuração global (ver [Configuração do Módulo](#tela-configuração-do-módulo)).
- **Alerta sonoro/visual de ticket novo**: alternância Sim/Não (padrão Sim). Liga ou desliga o alerta desta Tela especificamente (ver [Destaque de ticket novo](#destaque-de-ticket-novo)) — o destaque visual (linha verde) acontece sempre que ligado; o som só se, além disso, a configuração global tiver uma URL de som preenchida.
- **Ativa**: alternância Sim/Não (padrão Sim). Uma Tela **inativa** não pode mais ser aberta — nem autenticada, nem pela URL pública — mesmo que o link continue existindo.

> `[GIF AQUI: trocar tema, tamanho da fonte e níveis de entidade, e ver o resultado na exibição]`

## Exibição pública

- **Exibição pública (sem login, para TV)**: alternância Sim/Não (padrão Não). Ao ligar pela primeira vez, o sistema gera automaticamente um **token** e mostra o campo **URL pública** — o endereço completo (com domínio) para abrir num navegador de TV/kiosk, sem nenhum login.
- Ao lado da URL pública, um botão de **copiar link** (ícone de clipe) copia o endereço completo para a área de transferência, com uma confirmação visual rápida (✓ ou ✗).
- Um botão **Gerar novo link** invalida a URL pública atual e gera uma nova, pedindo confirmação antes ("Gerar um novo link público? O link atual deixa de funcionar.").

> Qualquer pessoa com a URL pública consegue ver a Tela, sem senha — o token é o único controle de acesso desse link, por desenho. Gere um novo link se o endereço for exposto por engano (por exemplo, compartilhado fora da rede interna).

> `[GIF AQUI: ligar "Exibição pública", copiar a URL gerada e, em seguida, gerar um novo link]`

## Colunas exibidas

Uma lista com as 11 colunas do catálogo do Monitor — **ID**, **Título**, **Entidade**, **Status**, **Prioridade**, **Categoria**, **Solicitante**, **Técnico**, **Grupo técnico**, **Abertura** e **Tempo decorrido** — cada uma com uma caixa de marcação e um ícone de arrastar (⠿) à esquerda.

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

### Destaque de ticket novo

Quando um ticket aparece pela primeira vez num ciclo de atualização (ou seja, não estava no ciclo anterior), a linha pisca em **verde**, com texto branco, por cerca de 2 segundos, voltando depois ao fundo normal. Esse destaque é independente de som: ele acontece sempre que o alerta da Tela estiver ligado, mesmo sem nenhuma URL de som configurada na configuração global. A primeira vez que a página carrega nunca pisca nenhuma linha (senão toda a tabela piscaria ao abrir).

> `[GIF AQUI: com a exibição aberta, criar um ticket que bate no filtro da Tela e mostrar a linha piscando em verde no ciclo seguinte]`

---

# Permissões

O Monitor tem uma linha própria na aba de perfil **Plugin - DTI GAC**, com as permissões básicas de um cadastro do GLPI mais a permissão específica do módulo. Um perfil pode combinar qualquer conjunto delas.

**Permissões básicas** (comuns a qualquer item do GLPI):

- **Ver**: abrir a lista de Telas e as Telas existentes (exibição autenticada). Sem essa permissão, a entrada **Telas de Monitoramento** nem aparece no menu do plugin.
- **Criar**: usar o botão **Novo** na lista de Telas.
- **Editar**: alterar qualquer campo do formulário de uma Tela já existente.
- **Excluir**: apagar definitivamente uma Tela. Não existe lixeira para este cadastro — a exclusão é direta e sem volta, igual ao cadastro de Motivos do LTBP.

**Permissão específica do Monitor:**

- **Configurar** (`RIGHT_CONFIG`): libera a seção **"Painel de Monitoramento de Tickets"** na tela de configuração do plugin (intervalo padrão, URL do som, conta de serviço). Não dá acesso a nenhuma ação sobre as Telas em si — só à configuração global.

> A exibição **pública** (via token) não passa por nenhum direito de perfil — o token é o único controle de acesso desse caminho, por desenho (ver [Exibição pública](#exibição-pública-tvkiosk)).

**Permissão nativa do GLPI necessária à parte** (não é do plugin, mas bloqueia a criação de Telas se faltar):

- **"Atualizar"** em **Pesquisas salvas (públicas)** (aba **Ferramentas** do perfil): sem ela, ninguém consegue trocar uma Pesquisa Salva de Privada para Pública, e o dropdown de Pesquisa Salva do formulário da Tela fica sempre vazio (ver [Pesquisa Salva Pública](#pesquisa-salva-pública-pré-requisito-do-glpi)).
