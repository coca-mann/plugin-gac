> Conteúdo-base para publicação no WikiJS. Onde estiver marcado `[GIF AQUI: ...]`, grave a tela correspondente e substitua a marcação pela incorporação do GIF.

O **Login com Google** é o módulo do plugin Gac que coloca o botão **Entrar com Google** na tela de login do GLPI. Quem entra por ele não digita senha no GLPI: o Google confirma quem a pessoa é, o plugin pergunta ao Google em qual **unidade organizacional (OU)** do Google Workspace ela está, e as **regras de autorização do próprio GLPI** transformam essa OU em entidade e perfil. O plugin só acrescenta às regras de autorização dois critérios novos, **OU do Google Workspace** e **Workspace do Google**, reunidos num grupo próprio chamado **Login com Google** na lista de critérios; o restante (entidades, perfis, recursividade, negar login, parar o processamento) é o motor de regras nativo do GLPI.

O módulo aparece no menu lateral **Plugin - DTI GAC**, na entrada **Login com Google** (identidades vinculadas, eventos e OUs pendentes), e a configuração fica em **Plugin - DTI GAC > Configurações**, no cartão **Login com Google**.

Este manual segue a ordem em que as coisas precisam ser feitas: primeiro a preparação **no Google** (Google Cloud e Admin Console), depois a configuração **no GLPI**, as regras de autorização, o teste a seco e a abertura gradual para os usuários, depois as telas de acompanhamento, o que o usuário vê ao entrar e, por fim, permissões, limitações e solução de problemas.

> Sempre que este manual trouxer um aviso, uma restrição ou um comportamento que exige atenção, ele aparece formatado como esta caixa.

> **Atenção, leia antes de começar:** este módulo envolve três ambientes diferentes (Google Cloud, Admin Console do Google Workspace e GLPI) e cada um exige uma permissão alta, normalmente de pessoas diferentes. A tabela abaixo mostra quem precisa de quê. Se você não tem a permissão indicada, peça a quem tem, em vez de tentar contornar.

| Ambiente | Etapa | Permissão necessária |
|---|---|---|
| Google Cloud (GCP) | Criar o projeto | Papel **Criador de projetos** na organização (ou usar um projeto que já exista) |
| Google Cloud (GCP) | Ativar a API, configurar a tela de consentimento, criar o cliente OAuth e a conta de serviço | Papel **Proprietário** do projeto (ou **Editor** mais os papéis específicos de conta de serviço) |
| Google Cloud (GCP) | Criar a chave JSON da conta de serviço | Papel **Administrador de chaves de conta de serviço**, e a política da organização **não** pode bloquear a criação de chaves (ver alerta na etapa 6) |
| Admin Console do Google Workspace | Delegação em todo o domínio e função de administrador personalizada | **Super administrador** do Workspace. Nenhuma outra função consegue |
| GLPI | Configurar o módulo (cartão em Configurações) | Perfil com o direito **Configurar** do módulo Login com Google |
| GLPI | Criar as regras de autorização | Perfil com permissão de **editar Regras de autorização** (Administração > Regras) |
| GLPI | Conferir a **URL da aplicação** (usada no endereço de retorno) | Perfil com permissão de editar a **Configuração geral** |

> Na primeira configuração, o mais simples é usar o perfil **Super-Admin** do GLPI. Depois que tudo estiver funcionando, dê aos administradores do dia a dia somente o direito **Configurar** do módulo (ver [Permissões](#permissões)).

---

# Visão geral: como o login funciona

Este resumo ajuda a entender o resto do manual. Cada vez que alguém clica em **Entrar com Google**:

1. O navegador vai ao Google, a pessoa escolhe a conta e o Google confirma a identidade.
2. O plugin confere se o domínio do e-mail pertence a um dos workspaces cadastrados e se o Google confirmou o e-mail.
3. Se o **modo piloto** estiver ligado, confere se o e-mail está na lista do piloto.
4. O plugin pergunta ao Google, na **Directory API**, em qual OU a pessoa está. Essa consulta é feita a **cada login**, por uma conta de serviço que age em nome de um administrador de leitura do workspace.
5. Se a OU estiver na lista de **OUs bloqueadas**, o login é negado antes de qualquer regra.
6. O GLPI roda as **regras de autorização** com a OU (e todas as OUs acima dela) e com o workspace da pessoa. Uma regra com a ação **Negar login**, ou nenhuma regra concedendo acesso, nega o login.
7. O plugin descobre qual usuário do GLPI é essa pessoa: o já vinculado ao Google, um existente com o mesmo e-mail (que é vinculado e convertido) ou um novo (criado).
8. As entidades e perfis vindos das regras são aplicados, e a sessão é aberta.

Qualquer falha em qualquer passo **nega** o login (nunca há acesso "por padrão") e mostra ao usuário uma mensagem genérica com um **código**, que é o número do evento registrado (ver [Eventos](#tela-eventos)).

> O GLPI não pede o segundo fator dele (TOTP) a quem entra pelo Google. A verificação em duas etapas, se existir, é a do próprio Google.

---

# Parte 1: Preparar o Google (Google Cloud e Admin Console)

Esta parte é feita **fora do GLPI**, e só precisa ser feita uma vez por ambiente (com exceção das etapas 7 e 8, repetidas para cada workspace). No fim, você terá em mãos o que será digitado no GLPI: **ID do cliente**, **segredo do cliente**, **e-mail da conta de serviço**, **chave privada** e, para cada workspace, o **e-mail do administrador** que a conta de serviço vai representar.

> Os nomes de menu do Google Cloud e do Admin Console mudam com frequência e variam com o idioma da conta. Os nomes abaixo são os da interface em português, com o termo em inglês entre parênteses. Se não achar um item, procure pelo termo em inglês no campo de busca do console.

## Como o Google entra na história

O módulo usa **duas credenciais diferentes**, e é comum confundi-las:

- **Cliente OAuth** (ID do cliente + segredo): é o que permite ao GLPI mandar a pessoa para a tela de login do Google e receber de volta a confirmação de quem ela é. Só existe **um** para todos os workspaces.
- **Conta de serviço** (e-mail + chave privada): é um "robô" que o plugin usa para consultar a OU da pessoa. Ela só consegue ler dados do Workspace porque cada workspace **delega** isso a ela no Admin Console. Também existe **uma só** para todos os workspaces, mas cada workspace precisa autorizá-la separadamente.

> **Alerta de segurança:** a chave da conta de serviço é uma credencial sensível. Quem tiver a chave e a delegação em vigor consegue ler os dados dos usuários e a estrutura de OUs do domínio (só leitura, porque os escopos são limitados, mas ainda assim dados pessoais). Não envie o arquivo JSON por e-mail ou chat, não o salve em pasta compartilhada e **apague o arquivo baixado** depois de colar a chave no GLPI. O GLPI guarda a chave **criptografada** e nunca a mostra de volta.

## Etapa 1: Criar (ou escolher) o projeto no Google Cloud

1. Acesse `https://console.cloud.google.com` com uma conta que tenha as permissões indicadas na tabela acima.
2. No seletor de projetos, no topo da tela, clique em **Novo projeto** (*New project*).
3. Dê um nome que deixe claro o uso, por exemplo `GLPI - Login com Google`, e confirme a organização. Clique em **Criar**.
4. Aguarde a notificação de que o projeto foi criado e **selecione-o** no seletor de projetos. Todas as etapas seguintes são feitas com este projeto selecionado.

> Se a sua organização já tem um projeto reservado para integrações internas, pode usá-lo. O importante é que você seja **Proprietário** dele.

> `[GIF AQUI: criar o projeto no Google Cloud e selecioná-lo]`

## Etapa 2: Ativar a Admin SDK API

É por esta API que o plugin lê a OU das pessoas.

1. No menu, vá em **APIs e serviços > Biblioteca** (*APIs & Services > Library*).
2. Procure por **Admin SDK API** e abra o resultado.
3. Clique em **Ativar** (*Enable*).

> Se o botão aparecer como **Gerenciar** em vez de **Ativar**, a API já está ativa neste projeto. Não precisa fazer nada.

> `[GIF AQUI: procurar e ativar a Admin SDK API]`

## Etapa 3: Configurar a tela de consentimento OAuth

É a tela que o Google mostra à pessoa ao entrar ("o aplicativo X quer saber seu nome e e-mail"). No Google Cloud mais novo ela fica em **Google Auth Platform** (menus **Identidade visual**, **Público-alvo** e **Acesso a dados**); na versão antiga, em **APIs e serviços > Tela de permissão OAuth**.

1. Abra **Google Auth Platform** (ou **APIs e serviços > Tela de permissão OAuth**) e, se for a primeira vez, clique em **Começar** (*Get started*).
2. **Identidade visual (*Branding*)**: informe o **nome do app** (é o que as pessoas verão, por exemplo `GLPI Grupo Aparício Carvalho`) e o **e-mail para suporte do usuário**. O logotipo é opcional.
3. **Público-alvo (*Audience*)**: escolha o tipo de usuário, conforme a situação:
   - **Interno** (*Internal*): use quando **todos** os usuários que vão entrar pertencem à **mesma organização do Google** que é dona do projeto. É o mais simples e o mais restrito: o Google barra qualquer conta de fora da organização.
   - **Externo** (*External*): use quando houver **mais de um workspace de organizações diferentes** (por exemplo, `fimca.com.br` e `metropolitana-ro.com.br` como organizações separadas). Neste caso o app **precisa ser publicado** (ver abaixo).
4. **Acesso a dados (*Data access*)**: o módulo usa apenas os escopos básicos **`openid`**, **`email`** e **`profile`**. Em geral não é necessário adicioná-los manualmente, mas se a tela pedir, inclua os três.
5. Informe o e-mail de contato do desenvolvedor, aceite os termos e salve.

> **Importante, apenas para o tipo Externo:** um app Externo nasce em modo **Em teste** (*Testing*), no qual só entram contas cadastradas como "usuários de teste" (no máximo 100). Para o login funcionar para todos, clique em **Publicar app** (*Publish app*) e confirme o status **Em produção** (*In production*). Como o módulo usa só escopos básicos, normalmente **não** é exigida a verificação do Google. Em Externo, quem barra contas indevidas é o plugin (pela lista de domínios dos workspaces), e não o Google.

> Escolher **Interno** com workspaces de organizações diferentes faz o login falhar para quem é de fora da organização dona do projeto, com um erro do próprio Google antes mesmo de voltar ao GLPI.

> `[GIF AQUI: configurar a tela de consentimento com o tipo de usuário e publicar o app]`

## Etapa 4: Criar o cliente OAuth (ID do cliente e segredo)

1. Antes de criar, anote o **endereço de retorno** que o Google precisa conhecer. Ele aparece no GLPI, em **Plugin - DTI GAC > Configurações > Login com Google > aba Google**, na caixa azul **URI de redirecionamento a cadastrar no cliente OAuth do Google**. Tem o formato `https://SEU-GLPI/plugins/gac/front/sso/callback.php`.
2. No Google Cloud, vá em **Google Auth Platform > Clientes** (ou **APIs e serviços > Credenciais**) e clique em **Criar cliente** (ou **Criar credenciais > ID do cliente OAuth**).
3. Em **Tipo de aplicativo**, escolha **Aplicativo da Web** (*Web application*).
4. Dê um nome, por exemplo `GLPI`.
5. Em **URIs de redirecionamento autorizados** (*Authorized redirect URIs*), clique em **Adicionar URI** e cole **exatamente** o endereço do passo 1. Não preencha **Origens JavaScript autorizadas**; o módulo não usa.
6. Clique em **Criar**. O Google mostra o **ID do cliente** e o **Segredo do cliente**. **Copie os dois agora** (o segredo pode ser consultado depois, mas é mais prático já ter à mão).

> O endereço de retorno é comparado **caractere por caractere**. Diferença de `http` para `https`, uma barra a mais no final ou um domínio diferente faz o Google recusar o login com o erro `redirect_uri_mismatch`.

> Em **produção**, o Google exige **HTTPS** e um domínio público no endereço de retorno. Para testar na própria máquina, o Google aceita `http://localhost` (com a porta, se houver). Endereços de rede interna, como `http://servidor-glpi` ou um IP, são recusados.

> O endereço mostrado pelo GLPI é montado a partir da **URL da aplicação** (Configuração > Geral > Configuração geral). Se ele mostrar o endereço errado (por exemplo `http` em vez de `https`, ou o nome interno do servidor), corrija primeiro a URL da aplicação, ou preencha o campo **Endereço de retorno (opcional)** na aba Google do módulo.

> `[GIF AQUI: criar o cliente OAuth do tipo Aplicativo da Web com o URI de redirecionamento e copiar o ID e o segredo]`

## Etapa 5: Criar a conta de serviço

1. Vá em **IAM e administrador > Contas de serviço** (*IAM & Admin > Service Accounts*) e clique em **Criar conta de serviço**.
2. Dê um nome, por exemplo `glpi-leitor-diretorio`. O Google gera o e-mail dela, no formato `glpi-leitor-diretorio@SEU-PROJETO.iam.gserviceaccount.com`.
3. Nas telas seguintes (**Conceder acesso a esta conta de serviço ao projeto** e **Conceder acesso a usuários**), **não atribua nenhum papel**. Ela não precisa de permissão no Google Cloud; o poder dela vem da delegação no Admin Console (etapa 7). Clique em **Concluído**.
4. Abra a conta de serviço recém-criada. Na aba **Detalhes**, anote o **ID exclusivo** (*Unique ID*), um **número longo** (algo como `112233445566778899001`). Ele será usado na etapa 7.

> O **ID exclusivo** é um número. Ele **não** é o e-mail da conta de serviço e **não** é o ID do cliente OAuth da etapa 4 (que termina em `.apps.googleusercontent.com`). Misturar esses valores é o erro mais comum nesta etapa.

> `[GIF AQUI: criar a conta de serviço sem papéis e anotar o e-mail e o ID exclusivo]`

## Etapa 6: Criar a chave JSON da conta de serviço

1. Na conta de serviço, abra a aba **Chaves** (*Keys*).
2. Clique em **Adicionar chave > Criar nova chave**, escolha o formato **JSON** e clique em **Criar**. O navegador baixa um arquivo `.json`.
3. Abra o arquivo em um editor de texto. Você vai precisar de dois campos dele: **`client_email`** (o e-mail da conta de serviço) e **`private_key`** (a chave privada, um texto longo que começa com `-----BEGIN PRIVATE KEY-----` e termina com `-----END PRIVATE KEY-----`).

> **Política da organização pode bloquear esta etapa.** Em organizações do Google Cloud criadas recentemente, a criação de chaves de conta de serviço vem **bloqueada por padrão** (política `iam.disableServiceAccountKeyCreation`), e o botão **Criar nova chave** fica desabilitado ou devolve um erro de política. Para liberar, alguém com o papel **Administrador de políticas da organização** (*Organization Policy Administrator*) precisa desativar essa restrição **para este projeto**. Se você não tem esse papel, peça a quem administra a organização do Google Cloud. Este módulo **não** consegue funcionar sem a chave.

> Cada chave criada fica ativa até ser apagada. Se uma chave vazar, apague-a na aba **Chaves** da conta de serviço e crie outra; depois atualize o campo no GLPI.

> `[GIF AQUI: criar a chave JSON e localizar client_email e private_key no arquivo]`

## Etapa 7: Admin Console: criar o administrador de leitura

A conta de serviço não consulta o Google "como ela mesma": ela **representa um administrador** do workspace. Para limitar o estrago, crie um administrador **dedicado**, com uma função que só **lê** usuários e unidades organizacionais. Faça esta etapa **em cada workspace**, com um **super administrador**.

1. Acesse `https://admin.google.com` com uma conta de **super administrador** do workspace.
2. Crie (ou escolha) a conta que será representada, por exemplo `glpi-leitor@seudominio.com.br`. Em **Diretório > Usuários**, clique em **Adicionar novo usuário**. Pode ficar em qualquer OU, mas **não** deve ser uma conta de uso pessoal e **não pode estar suspensa**.
3. Vá em **Conta > Funções de administrador** (*Account > Admin roles*) e clique em **Criar nova função** (*Create new role*).
4. Dê um nome, por exemplo `Leitor de usuários (GLPI)`, e uma descrição.
5. Em privilégios, marque somente leitura de usuários e de unidades organizacionais: em **Privilégios do Admin Console**, **Usuários > Ler** (*Users > Read*) e **Unidades organizacionais > Ler** (*Organizational units > Read*), e em **Privilégios da API Admin** (*Admin API privileges*), **Usuários > Ler** e **Unidades organizacionais > Ler**. Não marque criar, atualizar, excluir, redefinir senha nem nada além de leitura.
6. Salve a função e use **Atribuir administradores** (*Assign admins*) para atribuí-la à conta criada no passo 2.

> **Esta é a causa mais frequente de falha na primeira configuração.** Se a conta representada não tiver privilégio de administrador de leitura de usuários, o teste a seco e os logins falham com o detalhe `Directory API returned HTTP 403` (*Not Authorized to access this resource/api*). Se isso acontecer, volte a esta etapa e confira a função da conta.

> **O privilégio de unidades organizacionais é o que alimenta as listas de OUs** dos seletores das regras e das OUs bloqueadas (ver [Regras de autorização](#regras-de-autorização)). O **login** só precisa de ler usuários, mas, sem **Unidades organizacionais > Ler**, o Google responde ao seletor com o erro `401` (*Login Required*), que não deixa claro o motivo. Um **administrador delegado** com essa função basta; não precisa ser super administrador.

> Prefira uma função personalizada de **somente leitura** a usar um super administrador como conta representada. Se a delegação ou a chave vazarem, o alcance fica limitado à leitura de usuários e de unidades organizacionais, em vez de controle total do workspace.

> `[GIF AQUI: criar a função de administrador personalizada de leitura e atribuí-la a uma conta]`

## Etapa 8: Admin Console: autorizar a conta de serviço (delegação em todo o domínio)

É aqui que o workspace diz: "esta conta de serviço pode agir em nome dos meus administradores, e **só** para ler usuários e unidades organizacionais". Também precisa de um **super administrador**, e deve ser feita **em cada workspace**.

1. No Admin Console, vá em **Segurança > Acesso e controle de dados > Controles da API** (*Security > Access and data control > API controls*).
2. Em **Delegação em todo o domínio** (*Domain-wide delegation*), clique em **Gerenciar a delegação em todo o domínio** (*Manage domain-wide delegation*).
3. Clique em **Adicionar novo** (*Add new*).
4. No campo **ID do cliente** (*Client ID*), cole o **ID exclusivo** (o número longo) da conta de serviço, anotado na etapa 5.
5. No campo **Escopos do OAuth** (*OAuth scopes*), cole **exatamente** estes dois valores, separados por vírgula, e **nenhum outro**: `https://www.googleapis.com/auth/admin.directory.user.readonly,https://www.googleapis.com/auth/admin.directory.orgunit.readonly`
6. Clique em **Autorizar** (*Authorize*).

> **Cole somente estes dois escopos.** Os dois dão só **leitura**: o primeiro lê o usuário (é o que o **login** usa) e o segundo lê a lista de unidades organizacionais (é o que alimenta os **seletores** de OU). Não acrescente escopos "por garantia": cada escopo a mais amplia o que uma chave vazada poderia fazer.

> **Se este workspace já tinha a delegação com o escopo de usuário**, **edite a linha existente** (ela é identificada pelo mesmo ID do cliente) e acrescente o segundo escopo. **Mantenha o escopo de usuário**: ao editar, o Google troca a lista inteira, e sem o escopo de usuário o login deste workspace para de funcionar. Sem o segundo escopo o login continua funcionando; só a lista de OUs dos seletores fica indisponível para este workspace.

> A delegação costuma valer em poucos minutos, mas o Google informa que pode levar **até 24 horas** para propagar. Se o teste a seco falhar logo depois de autorizar, aguarde um pouco antes de rever as etapas.

> Repita as etapas 7 e 8 em **cada workspace** que vai usar o login (a conta de serviço e a chave são as mesmas; o que muda é o Admin Console onde se autoriza e o administrador representado). Um domínio de um workspace só consegue entrar se **aquele** workspace tiver feito a etapa 8.

> `[GIF AQUI: adicionar a delegação em todo o domínio com o ID da conta de serviço e os dois escopos de leitura]`

## O que levar para o GLPI

Ao final da Parte 1, tenha anotado (em local seguro e temporário):

- **ID do cliente** e **segredo do cliente** (etapa 4);
- **E-mail da conta de serviço** (`client_email`) e **chave privada** (`private_key`) (etapa 6);
- Para cada workspace: o **nome** que quiser dar a ele, a lista de **domínios de e-mail** dele (por exemplo `fimca.com.br`) e o **e-mail do administrador de leitura** da etapa 7.

---

# Parte 2: Configurar no GLPI

# Tela: Configuração do módulo

Acessível pelo menu lateral **Plugin - DTI GAC > Configurações**, no cartão **Login com Google**. Assim como nos outros módulos, o cartão tem o seu próprio botão **Salvar** e pode ser recolhido; a escolha fica lembrada no navegador. Dentro do cartão há **cinco abas**: **Geral**, **Google**, **Workspaces**, **Regras e bloqueios** e **Teste a seco**. As abas são só uma organização visual: existe **um único botão Salvar** que grava os campos de **todas** as abas de uma vez.

> Ao instalar, o módulo vem **desligado** (**Ativar o login com Google = Não**) e sem nenhuma credencial. Nada muda na tela de login enquanto você não ligar. Só ligue depois de seguir a sequência de [Abrindo o login para os usuários](#abrindo-o-login-para-os-usuários).

> `[GIF AQUI: percorrer as cinco abas do cartão Login com Google]`

## Aba "Geral"

**Botão e formulário de login**

- **Ativar o login com Google**: Sim/Não, padrão **Não**. Desligado, o botão some da tela de login e todos entram só com usuário e senha. Quem já está logado não é afetado.
- **Texto do botão**: texto livre, padrão `Entrar com Google`. É o que aparece escrito no botão da tela de login. Em branco, volta ao padrão.
- **Esconder o login por usuário e senha**: Sim/Não, padrão **Sim**. Com **Sim**, a tela de login mostra só o botão do Google, e o formulário de usuário e senha aparece ao clicar em **Entrar com usuário e senha**. Com **Não**, o botão do Google e o formulário aparecem juntos, separados por um "ou".

> Esconder o formulário é só visual: o login por senha continua funcionando para quem clicar no botão (ou abrir o endereço do login com `?local=1`). Mantenha esse acesso disponível, pois contas locais como a do administrador **não** entram pelo Google.

**Piloto e retenção**

- **Liberar só para o piloto**: Sim/Não, padrão **Não**. Com **Sim**, só os e-mails da lista abaixo entram pelo Google; os demais recebem o resultado `pilot_blocked`. Use para testar com a TI antes de abrir para todos.
- **E-mails do piloto**: texto, um e-mail por linha. Só vale com o piloto ligado.
- **Guardar os eventos por (dias)**: número, padrão **180**, mínimo 7. Os eventos de login mais antigos são apagados automaticamente, uma vez por dia, pela ação automática **SsoPurge** do GLPI.

> `[GIF AQUI: aba Geral com o piloto ligado e dois e-mails na lista]`

## Aba "Google"

**Cliente OAuth**

Uma caixa azul mostra o **URI de redirecionamento** que precisa estar cadastrado no cliente OAuth do Google (etapa 4 da Parte 1).

- **ID do cliente**: texto, copiado do Google Cloud (termina em `.apps.googleusercontent.com`).
- **Segredo do cliente**: campo de senha. Fica sempre em branco ao abrir a tela; deixar em branco ao salvar **mantém** o segredo já configurado. O texto abaixo do campo informa se já existe um segredo guardado. Fica criptografado e nunca é exibido de volta.
- **Endereço de retorno (opcional)**: texto. Em branco, o módulo usa o endereço do GLPI (a URL da aplicação). Preencha só se precisar de um endereço diferente, que deve ser **idêntico** ao cadastrado no Google Cloud.

**Conta de serviço**

Uma caixa azul lembra que a conta de serviço é **única** para todos os workspaces, precisa da delegação em todo o domínio com **somente** os escopos de leitura de usuários e de unidades organizacionais, e que o administrador representado de cada workspace é definido na aba **Workspaces**.

- **E-mail da conta de serviço**: texto, o campo `client_email` do JSON (termina em `iam.gserviceaccount.com`).
- **Chave privada**: caixa de texto. Cole o campo `private_key` do JSON, **com** as linhas `BEGIN` e `END`. Fica criptografada. Em branco ao salvar, mantém a chave atual.

> Ao colar a `private_key`, cole o valor **com as quebras de linha reais**. Se o JSON mostrar `\n` literal no meio do texto, converta cada `\n` em uma quebra de linha antes de colar. O jeito mais fácil é copiar o valor já decodificado, a partir de um editor ou visualizador de JSON.

> `[GIF AQUI: preencher o ID do cliente, o segredo, o e-mail da conta de serviço e a chave privada]`

## Aba "Workspaces"

Lista os workspaces do Google aceitos. A linha vazia no final é para acrescentar um novo; o botão **Adicionar workspace** cria mais linhas. Cada linha tem:

- **Nome**: texto livre, só para identificar o workspace. Pode ser renomeado a qualquer momento. Logo abaixo do campo aparece a **Chave** do workspace (ver abaixo).
- **Domínios**: um domínio de e-mail por linha (por exemplo `fimca.com.br`). **Cada domínio só pode estar em um workspace.**
- **Administrador do Google**: o e-mail do administrador de leitura (etapa 7 da Parte 1). A conta de serviço age em nome dele para ler a OU dos usuários e a lista de OUs dos seletores. Pode ser um administrador delegado, desde que a função dele leia usuários e unidades organizacionais.
- **Ativo**: Sim/Não. Workspaces inativos não entram na lista de domínios aceitos.
- **Limpar**: esvazia a linha. Para remover um workspace, use **Limpar** e **Salvar**. Se alguma regra de autorização ainda usa o workspace, o salvamento é recusado (ver abaixo).

A **Chave** é um identificador curto, em minúsculas e sem acentos (por exemplo `principal`), mostrado em texto cinza embaixo do nome. O módulo a cria sozinho quando o workspace é salvo pela primeira vez, a partir do nome, e ela **nunca muda**, nem quando o nome é alterado. É a chave, e não o nome, que as regras de autorização guardam no critério **Workspace do Google**; por isso renomear um workspace não quebra nenhuma regra. Os workspaces que já existiam antes desse recurso recebem a chave automaticamente.

> A chave não é editável. Se alguém tentar enviar outra no formulário, o servidor a ignora e mantém a que já estava gravada.

Regras que o módulo aplica ao salvar:

- Os domínios aceitos no login são a **união dos domínios dos workspaces ativos**. Um e-mail de domínio que nenhum workspace ativo tem é negado (`domain_denied`).
- Se o mesmo domínio estiver em dois workspaces, **nada é salvo** e uma mensagem aponta o domínio repetido.
- Um workspace ativo **sem domínio ou sem administrador** é salvo, mas aparece um aviso e ele **não é usado** até ser completado.
- Se você **remover** um workspace (com **Limpar**) que alguma regra de autorização ainda usa no critério **Workspace do Google**, **nada é salvo**. Uma mensagem de erro lista cada regra que depende dele, com um **link** para abri-la, e o formulário continua como estava. A trava vale para regras ativas **e desativadas** (uma regra desativada pode ser ligada de novo), e para qualquer condição do critério. Tire o critério dessas regras, ou mantenha o workspace, e salve de novo.
- **Desativar** um workspace (**Ativo = Não**) não é remover: não há trava, porque os logins daquele domínio já passam a ser negados.

> Quando o primeiro workspace for um e só, dê um nome simples, como `Principal`. Quando um segundo workspace for adicionado, lembre de refazer as etapas 7 e 8 da Parte 1 **nele**; sem a delegação, os logins daquele domínio falham com `api_error`.

> `[GIF AQUI: adicionar um segundo workspace com domínio e administrador e salvar]`

> `[GIF AQUI: tentar remover um workspace usado por uma regra e ver a mensagem com o link da regra]`

## Aba "Regras e bloqueios"

Uma caixa amarela lembra que o mapeamento de OU para entidade e perfil é feito em **Administração > Regras > Regras de autorização**, com os critérios do grupo **Login com Google** (**OU do Google Workspace** e **Workspace do Google**), e que se usa **somente a condição "é"** (ver [Regras de autorização](#regras-de-autorização)).

- **OUs bloqueadas**: texto, um caminho de OU por linha (por exemplo `/fimca.com.br/ies-pvh/docentes`). Quem estiver nessa OU, **ou em qualquer OU abaixo dela**, é negado (`ou_blocked`) **antes** de qualquer regra de autorização. Não diferencia maiúsculas e minúsculas. Linhas em branco e linhas que começam com `#` (comentários) são ignoradas. Acima do texto há o seletor **Adicionar OU** (ver abaixo).
- **Criar o usuário no primeiro login**: Sim/Não, padrão **Sim**. Com **Sim**, quem entra e ainda não existe no GLPI é criado automaticamente. Com **Não**, só entra quem já tem usuário (senão: `create_disabled`).
- **Retirar o acesso de quem for bloqueado**: Sim/Não, padrão **Sim**. Com **Sim**, se uma pessoa já vinculada passar a cair numa OU bloqueada ou numa regra de negar, os perfis que o login do Google deu a ela são **removidos** (evento `revoked`). Perfis dados à mão no GLPI não são tocados.
- **Conferir o domínio no caminho da OU**: número, padrão **0** (desligado). É uma segurança extra para estruturas em que o domínio é uma das pastas do caminho da OU. Exemplo: em `/FIMCA/fimca.com.br/ies-pvh`, o domínio é a pasta de **posição 2**. Informando `2`, o login só passa se o domínio do e-mail for igual a essa pasta; se não for, o resultado é `domain_mismatch`. Com `0`, não confere.

> **Atenção com o bloqueio de professores:** o bloqueio vale **por caminho de OU**, e cada workspace tem os seus caminhos. Cadastre a OU de docentes **de cada workspace** (por exemplo `/fimca.com.br/ies-pvh/docentes` e `/pvh/docentes`). Uma OU de docentes que ficar de fora não é bloqueada.

**Adicionar OU (seletor):** logo acima do texto das OUs bloqueadas há uma caixa com busca, **Adicionar uma OU à lista de bloqueio**. Ela traz as OUs de **todos os workspaces ativos**, lidas do Google, cada uma com o nome do workspace na frente (por exemplo `Principal - /fimca.com.br/IES-PVH`). Ao escolher uma OU, o **caminho** é acrescentado ao texto como uma **nova linha**, a caixa volta a ficar vazia e nada do que já estava escrito (inclusive os comentários com `#`) é alterado. Escolher uma OU que já está no texto, mesmo com maiúsculas diferentes, **não duplica** a linha. Como antes, **só vale o que for salvo**: depois de escolher, clique em **Salvar**.

- O texto continua sendo a **única fonte de verdade**. Você pode digitar, apagar ou editar as linhas à mão, inclusive caminhos que não estão na lista (uma OU recém-criada, por exemplo).
- O bloqueio compara **só o caminho**, não o workspace. Escolher `Principal - /Sistemas` bloqueia o caminho `/Sistemas` em **todos** os workspaces que tiverem uma OU com esse caminho. Isso erra para o lado seguro (bloqueia mais, nunca menos). Não existe bloqueio por workspace.
- Se um workspace não conseguir listar as OUs, aparece um aviso em amarelo embaixo da caixa ("Sem lista de OUs para: ..."), com o erro ao passar o mouse, e as OUs dos outros workspaces continuam disponíveis. Se **nenhuma** lista puder ser lida, a caixa mostra "Lista de OUs indisponível agora" e resta digitar o caminho no texto (ver [Solução de problemas](#solução-de-problemas)).
- A lista de OUs fica guardada por alguns minutos (cerca de 10) para não consultar o Google a cada vez. Uma OU criada agora no Google pode demorar a aparecer. Este seletor não tem botão de atualizar, mas o do seletor da tela de regras (ver [Regras de autorização](#regras-de-autorização)) renova a mesma lista guardada; depois recarregue a configuração.

> `[GIF AQUI: cadastrar as OUs bloqueadas escolhendo no seletor Adicionar OU e salvar]`

## Aba "Teste a seco"

Simula um login para um e-mail, **sem criar sessão, usuário nem evento de login** e sem alterar autorizações. Veja [Teste a seco](#teste-a-seco).

## O botão Salvar

Grava os campos de **todas** as abas. Depois de salvar, a página é recarregada e uma mensagem confirma. Um aviso à parte aparece se algum workspace ficou incompleto, e uma mensagem de erro aparece (sem salvar nada) se houver domínio repetido ou se um workspace removido ainda for usado por regras de autorização.

> **O teste a seco usa o que está salvo, não o que está digitado na tela.** Salve antes de testar.

---

# Regras de autorização

É aqui que se decide **quem recebe qual entidade e qual perfil**. O módulo não tem tabelas próprias de mapeamento: usa as regras de autorização nativas do GLPI. Elas ficam em **Administração > Regras > Regras de autorização**.

> Para criar ou editar regras de autorização é preciso o direito nativo do GLPI sobre essa tela. O direito **Configurar** do módulo Login com Google **não** libera as regras.

## Como a regra enxerga a OU

Para cada login, o plugin entrega ao GLPI a OU da pessoa **e todas as OUs acima dela**, em minúsculas. Por exemplo, uma pessoa em `/FIMCA/fimca.com.br/ies-pvh/dti` é avaliada com o critério **OU do Google Workspace** valendo `/fimca`, `/fimca/fimca.com.br`, `/fimca/fimca.com.br/ies-pvh` e `/fimca/fimca.com.br/ies-pvh/dti`.

Consequência: uma regra com **OU do Google Workspace é `/fimca/fimca.com.br/ies-pvh`** vale para essa OU **e para todas as OUs abaixo dela**. Quem está na raiz da organização tem a OU `/`.

> **Use somente a condição "é".** As outras condições do GLPI (como "começa com") não funcionam com caminhos de OU, por causa das barras. Use sempre **OU do Google Workspace > é > o caminho**. A comparação não diferencia maiúsculas e minúsculas.

## Os critérios do plugin

O módulo acrescenta dois critérios às regras de autorização. Na lista de critérios (**Administração > Regras > Regras de autorização > a regra > aba Critérios > Adicionar novo critério**) eles aparecem num grupo próprio, **Login com Google**; o grupo **Critérios LDAP** continua só com os critérios do LDAP.

- **OU do Google Workspace**: o caminho da OU da pessoa, com a regra valendo também para as OUs abaixo (ver acima). Aceita **só a condição "é"**.
- **Workspace do Google**: o workspace do e-mail da pessoa, identificado pela **chave** (ver [Aba "Workspaces"](#aba-workspaces)). Aceita **só a condição "é"**. Serve para fazer uma regra valer **apenas** para um workspace (ver [Limitar uma regra a um workspace](#limitar-uma-regra-a-um-workspace)).

> O critério de workspace é **opcional**. Uma regra que usa só o critério de OU, como as que já existiam, continua valendo **em qualquer workspace**, exatamente como antes. Nada precisa ser convertido para o login continuar funcionando.

## O seletor de OU e de workspace

Ao escolher um dos dois critérios, o campo do valor deixa de ser um texto simples e vira um seletor, alimentado pelo Google. Quem tem o direito de ler as regras de autorização vê o seletor ao criar ou editar o critério.

- **Workspace do Google**: uma lista com os workspaces ativos, no formato `Principal (principal)` (nome e chave), de escolha **obrigatória**. O que fica gravado na regra é a **chave**.
- **OU do Google Workspace**: uma caixa com busca. Digite parte do nome para filtrar e escolha uma OU. Cada opção mostra o workspace e o caminho, por exemplo `Principal - /fimca.com.br/IES-PVH`, para evitar confusão entre workspaces, mas o que fica gravado na regra é **somente o caminho** (`/fimca.com.br/IES-PVH`). Um caminho que existe nos dois workspaces aparece com o aviso **(repetida)**.
- **Texto livre**: você também pode **digitar um caminho que não está na lista** (por exemplo, uma OU criada há pouco) e confirmar com **Enter**. O que for digitado é gravado exatamente como foi digitado: o seletor **não valida** se a OU existe.
- **Atualizar a lista de OUs**: o botão com o ícone de setas, ao lado da caixa, lê as OUs de novo no Google na hora. Sem ele, a lista é guardada por cerca de 10 minutos.
- **Editar um critério já gravado**: o seletor abre com o valor que estava salvo, mesmo que ele não esteja mais na lista.
- **Filtro pelo workspace da regra**: se a regra **já tem** um critério **Workspace do Google**, a lista de OUs mostra só as OUs **daquele** workspace.
- **Quando a lista não carrega**: se nenhum workspace conseguir listar as OUs, o campo continua sendo o **texto simples** de antes, com o aviso "Lista de OUs indisponível agora". Se **só um** workspace falhar, a caixa funciona com os outros e mostra, em amarelo, "Sem lista de OUs para: ..." (o erro aparece ao passar o mouse).

> Os critérios de outros tipos de regra, e os demais critérios desta tela (como **E-mail**), não são alterados: continuam com o campo de texto normal.

> `[GIF AQUI: escolher o critério OU do Google Workspace, buscar uma OU no seletor, salvar e ver só o caminho na lista de critérios]`

> `[GIF AQUI: escolher o critério Workspace do Google e salvar com a chave do workspace]`

## Criando uma regra

1. Vá em **Administração > Regras > Regras de autorização** e clique em **Adicionar** (o sinal de **+**).
2. Informe um **nome** que mostre a origem, por exemplo `SSO Google - DTI`. Um prefixo comum, como `SSO Google - `, ajuda a separar essas regras das regras do AD que já existem na mesma lista.
3. Deixe a regra **ativa** e salve.
4. Abra a regra e, na aba **Critérios**, adicione: **OU do Google Workspace** > **é** > `/fimca.com.br/ies-pvh/dti`. Escolha a OU no seletor ou digite o caminho que o teste a seco ou a tela de OUs pendentes mostra (use o botão de copiar ao lado da OU nessas telas).
5. Na aba **Ações**, adicione, nesta ordem: **Entidade** (a que a pessoa vai receber), **Perfil** (o que ela terá) e, se quiser, **Recursivo**. Opcionalmente, **Entidade padrão** (a entidade em que ela cai ao entrar).
6. Se esta regra deve ser a única a valer para a OU, adicione também a ação **Parar o processamento das regras** (veja abaixo).

> Defina **sempre o perfil explicitamente** na regra. Se uma regra atribuir só a entidade, o GLPI usa o **perfil padrão** do GLPI, e o módulo não tem um perfil padrão próprio.

> **Cuidado com o perfil alto:** uma regra com um perfil poderoso (como Super-Admin) para uma OU vale para **todas as pessoas dessa OU e das OUs abaixo dela**. Em produção, use o menor perfil possível na regra da OU e dê perfis maiores à TI por **regra de exceção por e-mail** (ver abaixo).

> `[GIF AQUI: criar a regra SSO Google com o critério OU do Google Workspace, a entidade e o perfil]`

## Ordem das regras e "Parar o processamento"

O GLPI **soma** todas as regras que casam, na ordem da lista, até uma delas ter a ação **Parar o processamento das regras**. Ou seja, **não** vale "a regra mais específica ganha" automaticamente: a pessoa recebe as autorizações de todas as regras que casarem.

Para obter "a mais específica vence":

- Coloque as regras **específicas** (de um setor) **acima** da regra geral da unidade na lista.
- Dê a **todas** a ação **Parar o processamento das regras**, inclusive à geral da unidade.

Sem a ação de parar, a regra do setor e a da unidade valem **juntas**, e a pessoa recebe as duas entidades. Isso é útil para quem precisa de acesso a mais de uma entidade (basta duas regras que casem), mas é uma fonte comum de acesso a mais do que o pretendido.

## Negar o login de uma OU

Para barrar uma OU por regra (como segunda barreira, além das **OUs bloqueadas**), crie uma regra com o critério **OU do Google Workspace > é >** o caminho e a ação **Negar login**. O resultado nos eventos é `ou_denied`.

> Uma OU que **nenhuma regra** concede é negada sozinha, com `ou_unmapped`. A regra de **Negar login** serve para o caso em que uma OU de professores fica **abaixo** de uma OU que já tem regra de acesso. Para esse caso, o mais seguro é a lista **OUs bloqueadas**, que não depende da ordem das regras.

## Exceções da TI por e-mail

Para dar um perfil diferente a pessoas específicas, crie uma regra com o critério **E-mail > é >** o e-mail da pessoa (critério nativo do GLPI) e as ações de entidade, perfil e recursivo. Coloque essa regra **acima** das regras por OU na lista.

## Limitar uma regra a um workspace

Duas OUs de workspaces diferentes podem ter o **mesmo caminho** (por exemplo `/Sistemas`). Uma regra só com o critério de OU vale para as duas. Para que valha **só para um workspace**:

1. Na regra, adicione o critério **OU do Google Workspace** > **é** > o caminho.
2. Adicione também o critério **Workspace do Google** > **é** > o workspace (escolhido na lista).
3. Deixe o **operador lógico** da regra em **E** (o padrão): a regra só casa quando **os dois** critérios forem verdadeiros.

> **Não use o operador OU quando a regra tem o critério de workspace.** Com **OU**, basta **um** dos critérios ser verdadeiro, e a regra passaria a valer para **todas** as pessoas daquele workspace, seja qual for a OU. Para a mesma permissão em dois workspaces, crie **uma regra para cada**, cada uma com o seu critério de workspace.

> A chave do workspace não muda ao renomeá-lo. Mas **remover** um workspace que uma regra usa é bloqueado: o módulo recusa o salvamento e lista as regras (ver [Aba "Workspaces"](#aba-workspaces)).

## Uma regra para OUs de mais de um workspace

Uma mesma regra pode atender a vários workspaces sem o critério de workspace: ajuste o **operador lógico** da regra para **OU** (em vez de **E**) e adicione **um critério por OU** (um com a OU de um workspace e outro com a OU do outro).

> **Cuidado com caminhos iguais entre workspaces.** O bloqueio de OUs enxerga somente o **caminho**, não o workspace, e as regras também, **a menos que tenham o critério Workspace do Google**. Se dois workspaces tiverem uma OU com o mesmo caminho (por exemplo `/Sistemas`), um bloqueio para uma vale para a outra, e uma regra **sem** o critério de workspace também. O seletor marca esses caminhos com **(repetida)**. Antes de abrir o login, confira quais caminhos se repetem entre os workspaces.

---

# Teste a seco

Na aba **Teste a seco** da configuração, digite o e-mail de uma pessoa e clique em **Testar** (ou aperte **Enter**). O módulo faz tudo o que um login faz, **exceto** entrar no Google e abrir sessão: confere o domínio e o piloto, lê a OU na Directory API, confere as OUs bloqueadas e roda as regras de autorização.

O resultado mostra:

- Uma faixa **verde** ("Login permitido") ou **vermelha** com o motivo da negação.
- O **workspace** que cuida daquele domínio.
- A **OU** lida no Google, com um botão de **copiar** ao lado (veja [Copiar o caminho de uma OU](#copiar-o-caminho-de-uma-ou)).
- Uma tabela com as **autorizações** que a pessoa receberia: entidade, perfil e se é recursivo.
- A **entidade padrão**, se alguma regra definir.

O teste **não** cria usuário, sessão, evento de login nem altera autorizações. Serve para validar a configuração do Google e as regras **antes** de deixar alguém entrar.

> O teste usa a configuração **salva**. Salve a configuração antes de testar.

> Se o teste devolver "Falha ao consultar o Google ou erro interno", o detalhe aparece logo em seguida. O mais comum é `Directory API returned HTTP 403`: veja [Solução de problemas](#solução-de-problemas).

> `[GIF AQUI: digitar um e-mail na aba Teste a seco e clicar em Testar, mostrando a OU lida e as autorizações]`

---

# Abrindo o login para os usuários

Esta é a sequência recomendada para ligar o módulo com segurança. Ela existe porque, depois de ligado, o botão aparece para **todos** na tela de login.

1. **Instale e deixe desligado.** Com o módulo desligado, nada muda para ninguém.
2. **Faça a Parte 1** (Google) e preencha as abas **Google** e **Workspaces**.
3. **Crie as regras de autorização** das OUs que vão entrar, incluindo as exceções da TI por e-mail.
4. **Cadastre as OUs de professores** na lista **OUs bloqueadas**, **de cada workspace**.
5. **Rode o teste a seco** com e-mails reais de cada tipo: uma pessoa de cada unidade, um professor (deve ser negado), uma conta fora dos domínios (deve ser negada).
6. **Ligue em modo piloto:** **Ativar o login com Google = Sim**, **Liberar só para o piloto = Sim** e liste só os e-mails da TI. Faça logins reais e confira as telas de [Identidades](#tela-identidades) e [Eventos](#tela-eventos).
7. **Abra para todos:** **Liberar só para o piloto = Não**. Acompanhe os eventos e as [OUs pendentes](#tela-ous-pendentes) nos primeiros dias.
8. **Só por último,** quando todos tiverem entrado pelo Google com sucesso, decida se o formulário de usuário e senha continua **escondido** (padrão) ou se o botão do Google e o formulário aparecem juntos.

> Não pule o modo piloto. Ele é o que evita que um erro de regra libere (ou barre) todo mundo ao mesmo tempo.

---

# Tela: Identidades

Acessível por **Plugin - DTI GAC > Login com Google > Identidades**. Lista os usuários do GLPI que já estão **vinculados a uma conta do Google** (até 300, do login mais recente para o mais antigo). O contador no título mostra quantos são.

Colunas:

- **Usuário**: o login do usuário no GLPI (link para o cadastro dele). O e-mail da vinculação só aparece embaixo, em cinza, quando for diferente do login.
- **Origem**: um selo, com o que o usuário era **antes** do vínculo:
  - **Criado pelo Google**: o usuário foi criado pelo próprio login do Google.
  - **Convertido do AD** ou **Convertido**: o usuário já existia (por exemplo, importado do AD) e foi vinculado pelo e-mail, passando a entrar pelo Google.
  - **Inativo**: selo vermelho extra, quando o usuário está desativado ou excluído no GLPI.
- **Vinculado em** e **Último login**: data, com a hora embaixo.
- **Última OU**: a OU lida no último login, com o botão de **copiar** ao lado.
- **Ação** (só para quem tem o direito **Configurar**): **Desfazer conversão** (para os convertidos) ou **Desfazer vínculo** (para os criados pelo Google), com confirmação.

## Desfazer a conversão ou o vínculo

- **Desfazer conversão**: o usuário volta ao método de login que tinha antes (por exemplo, o AD), à **entidade padrão** que tinha e às **autorizações dinâmicas** que foram substituídas pelo login do Google. A identidade é removida e o evento `undone` é registrado.
- **Usuário excluído permanentemente no GLPI** (purgado, e não só enviado para a lixeira): a identidade **sai desta lista** sozinha, e o evento `undone` com o detalhe "user purged in GLPI" registra o motivo. O snapshot da conversão (usado por **Desfazer conversão**) se perde com o usuário. No próximo login pelo Google, a pessoa é tratada como **nova**: se ainda estiver ativa no Google e numa OU com regra, o usuário é **recriado** (ou vinculado a um usuário do AD de mesmo e-mail). Excluir permanentemente um usuário no GLPI **não impede** o acesso dele; para isso, suspenda a conta no Google, coloque a OU em **OUs bloqueadas** ou use o modo piloto. Um usuário que está **só na lixeira** continua com a identidade e é negado com `user_inactive`; restaurá-lo devolve o acesso.
- **Desfazer vínculo** (usuário criado pelo Google): o usuário **continua existindo** no GLPI, mas perde as autorizações dinâmicas e o vínculo com a conta do Google. No próximo login pelo Google, ele é vinculado de novo.

> `[GIF AQUI: abrir a tela de Identidades e desfazer a conversão de um usuário]`

---

# Tela: Eventos

Acessível por **Plugin - DTI GAC > Login com Google > Eventos**. Registra **cada tentativa** de login pelo Google, com sucesso ou falha. Mostra os **200 mais recentes**, e o contador do título indica quantos há (`200+` quando passa disso).

O **número do evento** é o **código** que o usuário vê na tela de login quando uma tentativa falha (ver [O que o usuário vê](#o-que-o-usuário-vê-quando-algo-dá-errado)). Com ele, a TI acha na hora o que aconteceu.

Colunas: **#** (o número do evento), **Data**, **E-mail**, **OU** (com o botão de **copiar** ao lado), **Resultado** (um selo colorido com o código e a explicação em português embaixo) e **Detalhe** (informação extra, como o motivo de uma falha na API do Google).

Cores do selo: **verde** (login permitido), **laranja** (negado por regra do GLPI ou do módulo), **vermelho** (falha técnica) e **azul** (revogação ou desfazer).

Filtros no topo: **resultado** (lista de códigos), **e-mail contém** e o botão **Filtrar**. **Limpar filtro** aparece quando há filtro ativo.

Os eventos mais antigos que o prazo de retenção (padrão 180 dias) são apagados automaticamente.

## Códigos de resultado

| Código | Significado | O que fazer |
|---|---|---|
| `ok` | Login permitido. O detalhe diz se foi `login` (já vinculado), `linked` (usuário existente vinculado agora) ou `created` (usuário novo criado) | Nada |
| `domain_denied` | O domínio do e-mail não está na lista dos workspaces ativos (ou a conta não é de Workspace) | Conferir a aba **Workspaces**. Se o domínio deveria entrar, cadastre-o no workspace certo |
| `email_unverified` | O Google não confirmou o e-mail da conta | Normalmente é uma conta fora do padrão; peça à pessoa que use a conta institucional |
| `ou_blocked` | A OU está na lista de **OUs bloqueadas** | Esperado para professores. Se não deveria bloquear, revise a lista na aba **Regras e bloqueios** |
| `ou_denied` | Uma regra de autorização com **Negar login** casou | Revisar a regra de negar |
| `ou_unmapped` | **Nenhuma regra** concede acesso a esta OU | Criar a regra de autorização da OU (a OU aparece em [OUs pendentes](#tela-ous-pendentes)) |
| `domain_mismatch` | O domínio do e-mail não é o do caminho da OU (só quando **Conferir o domínio no caminho da OU** está ligado) | Conferir a posição configurada ou a OU da pessoa |
| `email_ambiguous` | Mais de um usuário do GLPI tem este e-mail | Corrigir o e-mail duplicado no cadastro de usuários |
| `pilot_blocked` | Modo piloto ligado e o e-mail não está na lista | Esperado durante o piloto; ao abrir para todos, desligue o piloto |
| `create_disabled` | O usuário não existe e **Criar o usuário no primeiro login** está em Não | Criar o usuário no GLPI ou ligar a criação automática |
| `user_inactive` | O usuário do GLPI está inativo ou excluído | Reativar o usuário, se for o caso |
| `local_account` | O e-mail pertence a uma **conta local** do GLPI (senha no próprio GLPI), que nunca é convertida automaticamente | Esperado para contas como a do administrador. A pessoa continua entrando por usuário e senha |
| `state_invalid` | A tentativa de login expirou ou foi aberta de forma inválida (por exemplo, a aba ficou aberta por muito tempo) | A pessoa deve clicar em **Entrar com Google** de novo |
| `token_invalid` | O Google não devolveu um token válido, ou a pessoa cancelou na tela do Google | Se não foi cancelamento, conferir cliente OAuth, segredo e endereço de retorno |
| `api_error` | Falha ao consultar o Google (Directory API) ou erro interno | Ver o **Detalhe** do evento e a [Solução de problemas](#solução-de-problemas) |
| `revoked` | As autorizações dinâmicas de uma pessoa já vinculada foram removidas, porque ela caiu numa OU bloqueada ou negada | Esperado. Ver **Retirar o acesso de quem for bloqueado** |
| `undone` | Uma conversão ou vínculo foi desfeito pela tela de Identidades | Registro de auditoria |

> `[GIF AQUI: abrir a tela de Eventos, filtrar por um resultado e ler a explicação de um evento]`

---

# Tela: OUs pendentes

Acessível por **Plugin - DTI GAC > Login com Google > OUs pendentes**. Lista as OUs em que alguém **tentou entrar** e o resultado foi `ou_unmapped` (nenhuma regra concedeu acesso), com o número de **tentativas** e a **data da última**. É a lista de trabalho do administrador: cada OU que aparece aqui precisa de uma regra (ou, se for de professores, de entrar no bloqueio).

O botão **Abrir as regras de autorização**, no topo do cartão, leva direto à tela de regras. Para liberar uma OU, crie uma regra com **OU do Google Workspace > é >** o caminho mostrado ali (ver [Regras de autorização](#regras-de-autorização)). Ao lado de cada OU há o botão de **copiar** o caminho, para colar no critério da regra.

## Copiar o caminho de uma OU

Em toda coluna que mostra uma OU (**Última OU** em Identidades, **OU** em Eventos e em OUs pendentes, e a OU do resultado do **Teste a seco**) há, ao lado do caminho, um botão pequeno com o ícone de **copiar**. Ao clicar, o **caminho puro** da OU (por exemplo `/fimca.com.br/IES-PVH`) vai para a área de transferência, sem espaços extras, e o ícone vira um **✓ verde** por cerca de um segundo e meio. Depois é só colar no critério da regra ou no texto das OUs bloqueadas. Se o GLPI estiver aberto por `http` (sem HTTPS), o navegador não oferece a cópia moderna e o módulo usa um método alternativo, que funciona do mesmo jeito.

> `[GIF AQUI: clicar no botão de copiar ao lado de uma OU pendente e colar no critério de uma regra]`

---

# Usuários do AD e contas locais

## Usuários que já existem (por exemplo, importados do AD)

Quando uma pessoa que **já tem usuário no GLPI** entra pelo Google pela primeira vez, o módulo a reconhece pelo **e-mail**. Ele **vincula** o usuário à conta do Google e **converte** o método de autenticação para o externo, guardando uma cópia do estado anterior (método de login, entidade padrão e autorizações) para poder **desfazer** (ver [Identidades](#tela-identidades)).

- A partir do vínculo, o usuário é reconhecido pelo **identificador do Google**, não mais pelo e-mail. Se a pessoa mudar de e-mail no Workspace, continua sendo a mesma.
- As autorizações **dinâmicas** (as vindas de regras) são **substituídas** pelas das regras do Google. As autorizações dadas **manualmente** no GLPI são **preservadas**.
- Enquanto o login pelo AD continuar ativo, a conversão é "suave": se a pessoa entrar pelo AD, o GLPI volta a marcá-la como usuária do AD, e o próximo login pelo Google confirma a conversão de novo.

> Se o AD continuar ativo e uma pessoa convertida entrar por ele, as regras do **AD** voltam a valer para ela até o próximo login pelo Google. Quando todos de uma unidade estiverem migrados, desative as regras do AD dessa unidade.

## Contas locais

Usuários cujo método de login é o **banco do GLPI** (senha guardada no próprio GLPI, como `glpi`) **nunca são convertidos automaticamente**. Se o e-mail de uma conta local for o mesmo de alguém do Workspace, o login pelo Google é negado com `local_account`, e a conta continua entrando por usuário e senha. Isso protege as contas de emergência de serem trancadas para fora.

## Professores

Os professores **não devem entrar** no GLPI. A proteção é a lista **OUs bloqueadas** (uma OU de docentes por unidade, **em cada workspace**), avaliada antes das regras. Se um professor já estava vinculado e passar a cair na OU bloqueada, as autorizações dinâmicas dele são removidas no próximo login (evento `revoked`).

> O bloqueio ocorre **no momento do login**. Quem já estava logado continua até a sessão expirar, e quem entra pelo AD sem ter sido convertido **não** é afetado por este módulo.

---

# O que o usuário vê

## Entrando

Na tela de login do GLPI, a pessoa vê o botão **Entrar com Google** (o texto é o configurado) e, abaixo dele, o botão **Entrar com usuário e senha** (se o formulário estiver escondido) ou o formulário completo (se não estiver). Ela clica no botão do Google, escolhe a conta institucional e, se tudo estiver certo, cai direto no GLPI, já com a entidade e o perfil definidos pelas regras da OU dela.

- Se ela abriu um **link específico** do GLPI antes de entrar (um ticket, por exemplo), é levada para esse mesmo link depois do login.
- A primeira vez de uma pessoa nova cria o usuário dela automaticamente (se a criação automática estiver ligada). O **login** do usuário criado é o **e-mail completo**.

> `[GIF AQUI: tela de login, clique em Entrar com Google, escolha da conta e chegada ao GLPI]`

## Entrando por usuário e senha

Quando o formulário está escondido, o botão **Entrar com usuário e senha** o desliza para baixo do botão do Google. Serve para contas locais, como a do administrador, ou para quem ainda usa o AD. O endereço do login com `?local=1` abre a tela com o formulário já visível.

## O que o usuário vê quando algo dá errado

Qualquer falha mostra a mesma mensagem, sem detalhes técnicos: **"Não foi possível entrar com o Google. Procure o DTI informando o código N."** O número **N** é o número do evento registrado. A TI abre a tela de [Eventos](#tela-eventos), acha esse número e vê o motivo real.

---

# Permissões

O módulo tem uma linha própria, **Login com Google**, na aba de perfil **Plugin - DTI GAC**. Um perfil pode combinar qualquer conjunto delas.

- **Ler**: ver as telas **Identidades**, **Eventos** e **OUs pendentes**. Sem essa permissão, a entrada **Login com Google** nem aparece no menu do plugin.
- **Configurar**: abre o cartão **Login com Google** em **Plugin - DTI GAC > Configurações** (credenciais, workspaces, bloqueios, piloto), libera o **Teste a seco**, o seletor **Adicionar OU** das OUs bloqueadas e o botão **Desfazer conversão** / **Desfazer vínculo** nas Identidades. Também é o direito que permite remover um workspace (sujeito à trava das regras).

**Permissão nativa do GLPI necessária à parte** (não é do plugin):

- **Regras de autorização** (tela **Administração > Regras**): necessária para criar e editar as regras que mapeiam OU para entidade e perfil. O direito **Configurar** do módulo não a substitui. Para **ver os seletores de OU e de workspace** nas regras basta poder **ler** as regras de autorização; quem tem só o direito **Configurar** do módulo vê o seletor da configuração (OUs bloqueadas), mas não o das regras.

> Quem tem **Configurar** pode alterar as credenciais do Google, ligar e desligar o login e desfazer vínculos. Dê esse direito a poucas pessoas de confiança. Sem o direito, o cartão sequer aparece nas Configurações.

> Um perfil que já estava aberto em uma sessão antes do módulo ser instalado não enxerga o novo direito. Troque de perfil no menu do usuário ou entre novamente.

---

# Limitações e cuidados

> O módulo lê a OU **a cada login**, na Directory API. Se o Google estiver fora do ar, ou a delegação for revogada, o login pelo Google **falha** (nunca há plano B com acesso padrão). Quem usa usuário e senha não é afetado.

> Uma pessoa **movida de OU** no Google passa a ser avaliada pelas regras da **nova** OU no próximo login. Quem está logado mantém a sessão até ela expirar.

> O usuário novo é criado **antes** de a sessão abrir. Se a abertura da sessão falhar (raro), o usuário já existe, mas o evento `created` não é gravado; o próximo login aparece como `login`.

> Os caminhos de OU de **workspaces diferentes dividem o mesmo espaço de nomes**. O **bloqueio de OUs** só enxerga o caminho e não tem como separar por workspace. As **regras de autorização** também, **a menos que tenham o critério Workspace do Google** (ver [Limitar uma regra a um workspace](#limitar-uma-regra-a-um-workspace)). No ambiente atual, caminhos como `/Sistemas` e `/[desativados]` existem nos dois workspaces.

> A **lista de OUs** dos seletores vem do Google (escopo `admin.directory.orgunit.readonly`) e fica guardada por cerca de **10 minutos**. Uma OU criada ou renomeada agora pode demorar a aparecer: use o botão de atualizar do seletor da tela de regras. A lista é só uma ajuda para digitar; o login **não** depende dela, e a falha dela não bloqueia ninguém.

> O seletor **não valida** o que é digitado: um caminho com erro de digitação é gravado como está e a regra simplesmente nunca casa. Confira o resultado no **Teste a seco**.

> Uma regra com **perfil alto** para uma OU vale para **toda** a OU e as abaixo dela. Use o menor perfil possível nas regras de OU e dê perfis maiores por regra de e-mail.

> Mexer na delegação em todo o domínio, na conta de serviço ou na função do administrador representado, **no Google**, afeta o login de todos os usuários daquele workspace imediatamente (ou em até algumas horas, dependendo da propagação). Faça essas mudanças com o login já testado e fora do horário de pico.

---

# Solução de problemas

## O Google mostra um erro antes de voltar ao GLPI

- **`redirect_uri_mismatch`**: o endereço de retorno do cliente OAuth no Google Cloud é diferente do usado pelo GLPI. Copie o endereço da caixa azul da aba **Google** e cole **exatamente** em **URIs de redirecionamento autorizados** (etapa 4 da Parte 1). Confira `http`/`https`, o domínio e a barra final.
- **Aviso de "app bloqueado", "app não verificado" ou "acesso negado"**: normalmente é a tela de consentimento. Se o tipo for **Externo**, o app precisa estar **Em produção** (etapa 3 da Parte 1). Se for **Interno**, só entram contas da organização dona do projeto.
- **`invalid_client`** ou "o cliente OAuth não foi encontrado": o **ID do cliente** ou o **segredo** digitado no GLPI está errado, ou o cliente foi apagado no Google.

## O login volta para o GLPI com um código

Abra a tela de [Eventos](#tela-eventos), ache o número e leia o **Resultado** e o **Detalhe**.

- **`api_error` com `Directory API returned HTTP 403`**: a conta de serviço foi aceita, mas o administrador representado **não tem privilégio** para ler usuários, ou o escopo não foi autorizado. Confira, no Admin Console, a **função** do administrador (etapa 7) e a **delegação** com o escopo `admin.directory.user.readonly` (etapa 8, que também leva o escopo de OUs usado só pelos seletores). O detalhe pode trazer `Not Authorized to access this resource/api`.
- **`api_error` com `unauthorized_client`** (ou 401): a delegação em todo o domínio **não existe** ou está com o **ID exclusivo errado** (etapa 8). Confira que foi colado o número da conta de serviço, e não o e-mail nem o ID do cliente OAuth. Se acabou de autorizar, aguarde a propagação.
- **`api_error` com `invalid_grant`**: o **administrador representado** não existe, está suspenso, ou o e-mail dele foi digitado com erro na aba **Workspaces**.
- **`api_error` quando o domínio é de outro workspace**: aquele workspace ainda não autorizou a conta de serviço (etapas 7 e 8 não foram feitas nele), ou o domínio está no workspace errado na aba **Workspaces**.
- **`api_error` genérico** ou erro de chave: a **chave privada** foi colada incompleta (faltam as linhas `BEGIN`/`END`) ou com `\n` literal. Cole a chave de novo (ver aba **Google**).
- **`ou_unmapped`**: falta a regra de autorização. Veja a [OU pendente](#tela-ous-pendentes) e crie a regra.
- **`domain_denied`**: o domínio não está em nenhum workspace **ativo**.

## Os seletores de OU não listam as OUs

Os seletores usam a lista de OUs do Google, que depende de **um escopo e de um privilégio a mais** além do login. O **login não é afetado** por nenhum destes problemas. Quando um workspace não consegue listar, o seletor mostra em amarelo "Sem lista de OUs para: nome-do-workspace", e o erro aparece ao passar o mouse sobre o aviso.

- **`unauthorized_client`** (`Client is unauthorized to retrieve access tokens using this method, or client not authorized for any of the scopes requested`): o escopo `admin.directory.orgunit.readonly` **não foi autorizado** na delegação em todo o domínio **daquele** workspace. Edite a linha existente no Admin Console e acrescente o segundo escopo, mantendo o de usuário (etapa 8). Se acabou de autorizar, aguarde a propagação e use o botão de atualizar do seletor.
- **`Directory API returned HTTP 401`** (*Login Required*): o escopo está autorizado, mas a **função** do administrador representado não tem **Unidades organizacionais > Ler** (etapa 7). Este erro costuma aparecer como 401 e não como 403, o que confunde. Acrescente o privilégio à função e tente de novo.
- **`invalid_grant`**: o administrador representado não existe, está suspenso, ou o e-mail dele está errado na aba **Workspaces**.
- **"Lista de OUs indisponível agora" e campo de texto simples** nas regras: nenhum workspace conseguiu listar, ou o endereço de consulta não respondeu. Confira os avisos acima; o campo continua funcionando digitando o caminho.
- **O seletor nem aparece, só o campo de texto** (sem aviso nenhum): o navegador pode estar com a versão antiga do script em cache. Recarregue a página com **Ctrl+F5**. Também só aparece para o critério **OU do Google Workspace** ou **Workspace do Google**, e para quem pode ler as regras de autorização.
- **Uma OU recém-criada não aparece**: a lista fica guardada por cerca de 10 minutos. Use o botão de atualizar da tela de regras, ou digite o caminho.

## Não consigo remover um workspace

Se **Limpar** + **Salvar** mostra "Nada foi salvo: há regras de autorização que usam um workspace que seria removido", abra cada regra listada (o nome é um link) e **exclua o critério Workspace do Google** que aponta para ele, ou troque-o por outro workspace. Regras **desativadas** também contam. Depois salve a configuração de novo. Se a ideia era só impedir logins daquele domínio, use **Ativo = Não** em vez de remover.

## O botão do Google não aparece na tela de login

Confira, na aba **Geral**, se **Ativar o login com Google** está em **Sim**, e se a aba **Google** e a aba **Workspaces** estão completas (cliente OAuth, conta de serviço e ao menos um workspace ativo e utilizável). Com a configuração incompleta, o módulo **não mostra o botão**, para não deixar um botão que não funciona.

## Não consigo entrar por usuário e senha

Se o formulário está escondido, clique em **Entrar com usuário e senha** ou abra o endereço do login com `?local=1`. Se mesmo assim a conta não entra, o problema não é do módulo: o login por senha não é alterado por ele.

## O teste a seco funciona, mas o login real falha

O teste a seco não passa pelo Google nem pelo cliente OAuth. Se ele funciona e o login real não, o problema está no cliente OAuth, no segredo, no endereço de retorno ou na tela de consentimento (etapas 3 e 4 da Parte 1). O evento do login real mostra o motivo.
