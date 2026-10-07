# SSO Google — Login com Google Workspace: design

Data: 2026-10-05 · Plugin: `gac` (GLPI 11.0.x, PHP >= 8.2) · Namespace: `GlpiPlugin\Gac\Sso`

Este documento é a fonte de verdade do subprojeto SSO Google. Toda decisão tomada na sessão de
brainstorming está registrada aqui, com o motivo. Se o código divergir deste documento, um dos
dois está errado e deve ser corrigido. Segue o formato das specs do PRE
(`2026-09-24-pre-design.md`), do LTBP (`2026-09-26-ltbp-design.md`) e do Monitor
(`2026-10-01-monitor-design.md`).

## Status das decisões

- **Decidido**: confirmado pelo dono do projeto. S1 a S3, S7 a S22 (exceto o que está listado abaixo).
- **Decidido após spike (2026-10-05)**: S4 a S6. A primeira versão desta spec tinha uma tabela
  própria de mapeamentos e outra de exceções; o dono propôs reaproveitar o motor de regras do GLPI
  para não recriar o escopo de ações, e um spike no GLPI local confirmou a viabilidade (seção 12).
- **Decidido em 07/10/2026**: S25 a S30 (seletor de OU e workspace nas regras e nas OUs
  bloqueadas, chave estável do workspace, trava de remoção, lista de OUs sem tabela). Spike na seção 12.
- **Revisável**: S12 (login do usuário novo = e-mail completo) e S18 (checagem domínio × OU,
  desligada por padrão).
- **Proposta, aguardando confirmação do dono**: S23 (convivência com as regras do AD).
- **Rascunho**: seções 9 a 11 (estrutura de código, erros e testes, implantação), sem revisão do
  dono.
- **A verificar na implementação**: seção 12 (V1 a V16).

## 1. Contexto e objetivo

A empresa usa hoje o AD (LDAP) para autenticar no GLPI, com regras de autorização
(`RuleRight`) que mapeiam **grupos do AD** (`memberof`) para entidade + perfil: cerca de 62
regras ativas, cada uma com uma lista de grupos (a regra "Coord. Cursos FIMCA" tem 21), mais três
regras "Sem setor" (PVH, VHA, JRU) que dão um padrão por unidade pelo `dn` e param o
processamento. As ações dessas regras são entidade, recursivo, perfil e entidade padrão
(`_entities_id_default`). O Google Workspace é o diretório que a empresa passa a usar; o objetivo
é um **login com Google** na tela de login do GLPI, com regras de domínios permitidos, e com a
**Unidade Organizacional (OU)** do usuário no Workspace mapeando para a entidade e o perfil no
GLPI, reaproveitando o motor de regras de autorização que a equipe já conhece.

Fatos do ambiente que moldam o desenho:

- A árvore de OUs do Workspace é **única para a conta inteira**; os domínios são só o sufixo do
  e-mail. Na estrutura da empresa a OU começa em `FIMCA`, depois o domínio
  (`FIMCA/fimca.com.br`), depois a unidade (`FIMCA/fimca.com.br/IES-PVH`) e assim por diante;
  outros domínios ficam em `FIMCA/grupoaparicio...`. O caminho da OU já contém o domínio.
- A árvore de OUs é **diferente** da árvore de entidades do GLPI. As entidades seguem setor
  (Financeiro, Secretaria, Biblioteca...) repetido por unidade, sob a raiz `DTI`.
- No Google não há perfis de permissão: **todos têm o perfil padrão ("Colaborador")**, exceto a
  TI. Os perfis da TI (DTI - Gerência, Analista N1, Técnico N1 PVH) não são separáveis por OU.
- Por regra da empresa, **professores não podem entrar no GLPI**. Há uma OU de professores por
  unidade.
- Os usuários atuais vieram do AD (`authtype = LDAP`, login `sAMAccountName`); o e-mail é a única
  chave em comum com o Google.

## 2. Escopo

**Dentro:**

- Botão "Entrar com Google" na tela de login, fluxo OAuth 2.0 / OpenID Connect com PKCE.
- Lista de domínios permitidos.
- Leitura da OU do usuário na Directory API (conta de serviço com delegação em todo o domínio).
- Um critério novo, "OU do Google", acrescentado às **regras de autorização nativas** do GLPI
  (`RuleRight`) por hook de plugin; todas as ações do motor passam a valer para o login Google.
- Lista de **OUs sempre bloqueadas** (professores), avaliada antes do motor de regras.
- Criação do usuário no primeiro login, vinculação de usuários existentes, conversão com
  reversão.
- Telas de administração: configuração, OUs pendentes, identidades, eventos, teste a seco.
- Modo piloto.

**Fora (YAGNI):**

- Tabelas próprias de mapeamento de OU ou de exceções por e-mail: são regras de autorização
  comuns (S4, S7).
- Página de árvore de OUs com as regras que referenciam cada nó: candidata a uma fase posterior,
  fora da primeira entrega.
- Grupos do Google (nem os de e-mail setoriais nem grupos de papel).
- Sincronização periódica em lote (cron) de usuários ou OUs; provisionamento em massa; SCIM.
- Logout federado (encerrar a sessão do Google).
- O GLPI como provedor de identidade (o servidor OAuth2 nativo do GLPI tem o sentido oposto e
  não é usado).
- Outros provedores (Microsoft, GitHub...). O módulo é nomeado pelo provedor atual; outro
  provedor seria um módulo à parte.
- Bloqueio do login por senha no servidor (o formulário é só ocultado; ver S16).

## 3. Glossário

- **OU / `orgUnitPath`**: caminho da Unidade Organizacional no Workspace, por exemplo
  `/FIMCA/fimca.com.br/IES-PVH/Professores`.
- **Regra de autorização**: regra do tipo `RuleRight` do GLPI (Configurar > Regras de
  autorização). Critérios decidem quando casa; ações atribuem entidade, perfil etc.
- **Critério `GOOGLE_OU`**: critério acrescentado pelo plugin; vale a lista de caminhos ancestrais
  da OU do usuário (S4).
- **Bloqueio duro**: lista de caminhos de OU que negam o login antes de qualquer regra (S9).
- **Identidade**: vínculo entre um usuário do GLPI e um `sub` do Google.
- **Conversão**: passar um usuário existente (do AD) a ser autenticado pelo Google.
- **Teste a seco**: simulação de um login para um e-mail, sem criar sessão nem alterar dados.
- **OU pendente**: OU com tentativas de login negadas por falta de regra.
- **Conta de serviço**: identidade técnica do Google Cloud usada para consultar a Directory API.

## 4. Decisões de escopo e regras de negócio

- **S1. O GLPI 11 não atende nativamente; o plugin é necessário.** Os métodos de login do núcleo
  são banco, e-mail, LDAP, EXTERNAL, CAS, X509, API e cookie. EXTERNAL com proxy OIDC não
  entrega botão nem OU e vale para o vhost inteiro; Google Secure LDAP não é SSO, exige edição
  paga do Workspace e a senha do Google digitada no GLPI. O servidor OAuth2 do núcleo tem o
  sentido oposto (o GLPI como provedor) e o OAuth cliente do núcleo é só para e-mail.
- **S2. Fluxo próprio no plugin, com `league/oauth2-google`** (já no `vendor/` do GLPI, usado
  pelo coletor de e-mail), e a sessão aberta pelo padrão do núcleo
  (`$auth = new Auth(); $auth->auth_succeded = true; $auth->user = $user; Session::init($auth);`,
  usado na impersonação e no login por token). Simular o login EXTERNAL é inviável: a cada
  requisição `Session.php` confere a variável do servidor contra `glpi_remote_user`.
- **S3. A OU é lida na Directory API a cada login**, por conta de serviço com delegação em todo
  o domínio, com **um único escopo**, `admin.directory.user.readonly` (só leitura), representando
  um admin dedicado de função somente leitura. O escopo `admin.directory.orgunit.readonly` só
  será necessário se a página de árvore de OUs (fora da primeira entrega) for feita; pedir menos
  limita o dano de uma chave vazada. Falha da API nega o login (falha fechada); nunca há OU ou
  entidade padrão como plano B. *(Atualizada em 07/10/2026: o login continua com esse único
  escopo; o escopo de OUs entra só para listar, com a delegação autorizada à parte, ver S28.)*
- **S4. O mapeamento OU → entidade/perfil são regras de autorização nativas.** O plugin declara
  `$PLUGIN_HOOKS['use_rules']['gac'] = ['RuleRight']` (o valor é um **array de tipos**, não
  `true`) e implementa dois hooks: `getRuleCriteria`, que acrescenta o critério virtual
  `GOOGLE_OU` ao `RuleRight`, e `ruleCollectionPrepareInputDataForProcess`, que injeta o valor no
  dado avaliado. O valor é a **lista dos caminhos ancestrais** da OU do usuário, em minúsculas,
  do mais raso ao mais profundo (`/fimca`, `/fimca/fimca.com.br`, ...). Assim a regra
  "OU do Google é `/fimca/fimca.com.br/ies-pvh`" casa a OU e todas as descendentes, e o casamento
  é por segmento por construção. Todas as ações do `RuleRight` (entidade, perfil, recursivo,
  entidade padrão, grupo, `_deny_login`, `_stop_rules_processing`...) valem sem código nosso.
- **S5. A herança não é "ancestral mais próximo"; é soma + ordem.** O motor aplica **todas** as
  regras ativas que casam, em ordem de `ranking`, até uma ação `_stop_rules_processing`. Para
  obter "a mais específica vence", as regras específicas devem ter a ação de parar e ficar
  **acima** da regra-padrão da unidade (que também para), como já é o padrão das regras "Sem
  setor" do AD. Efeito observado no spike: sem o stop, a regra da unidade casa **junto** com a
  do setor e o usuário recebe as duas. Isso é um cuidado do administrador, não do plugin.
- **S6. Perfil.** As regras do SSO devem sempre definir o perfil explicitamente (como as do AD).
  Se uma regra atribui só entidade, o núcleo usa o perfil padrão do GLPI (`Profile::getDefault()`);
  o plugin não tem perfil padrão próprio.
- **S7. Exceções da TI são regras comuns**, com critério `MAIL_EMAIL` (que já é do núcleo) e as
  ações de entidade/perfil/recursivo. Não há tabela de exceções. Ficam com `ranking` acima das
  regras de OU.
- **S8. Ordem de decisão em cada login:** (1) domínio permitido e e-mail verificado;
  (2) bloqueio duro de OU (S9); (3) lê a OU e monta a lista de ancestrais; (4) roda a coleção
  `RuleRightCollection`: `_deny_login` nega (`ou_denied`), `_no_rule_matches` nega
  (`ou_unmapped`); (5) identidade; (6) provisionamento (`applyRightRules`) e sessão. No modo
  piloto (S19) há um filtro por e-mail logo após o passo 1.
- **S9. Bloqueio duro de OUs.** Configuração `sso_blocked_ou_paths`, uma OU por linha, casamento
  por segmento e sem diferenciar caixa (`/A/B` não casa com `/A/BC`, mas casa com `/A/B/C`),
  avaliada **antes** do motor de regras. A proibição de professores não pode depender de a ordem
  das regras estar correta.
- **S10. Identidade por `sub`.** O primeiro casamento é por e-mail verificado; depois da
  vinculação a chave é o `sub` (imutável). O e-mail pode ser trocado ou reaproveitado no
  Workspace; casar sempre por e-mail permitiria a uma pessoa herdar a conta de outra. Mais de um
  usuário do GLPI com o mesmo e-mail nega o login (`email_ambiguous`), nunca escolhe.
- **S11. Conversão no primeiro login de cada pessoa, com reversão.** O usuário existente passa a
  `authtype = Auth::EXTERNAL`, `auths_id = 0` (V6). Antes disso o plugin grava `authtype`,
  `auths_id`, a **entidade padrão** e as autorizações dinâmicas removidas na tabela de identidades
  (a regra redefine a entidade padrão a cada login, então sem guardá-la o desfazer deixaria o
  usuário apontando para uma entidade a que não tem acesso). "Desfazer conversão"
  restaura esse estado. Enquanto o login por LDAP estiver ativo a conversão é **suave**: um login
  bem-sucedido pelo AD regrava `authtype = LDAP`; o próximo login pelo Google reafirma a
  conversão (a identidade, por `sub`, não se perde). **Contas locais** (`authtype` banco) nunca
  são convertidas automaticamente: o login é negado com `local_account`. Evita trancar para fora
  o `glpi` e outras contas de emergência que por acaso tenham um e-mail do Workspace.
- **S12. Usuário novo** é criado no primeiro login, se alguma regra conceder acesso (criação
  automática, configurável). O `name` (login) é o **e-mail completo**, porque o login do AD é o
  `sAMAccountName` e reaproveitar a parte local do e-mail colidiria com contas existentes.
  *(Revisável.)*
- **S13. Autorizações via núcleo.** O plugin chama `RuleRightCollection::processAllRules()` e
  `User::willProcessRuleRight()` + `User::applyRightRules()`, o mesmo caminho do login LDAP. O
  núcleo cria, atualiza e apaga **só** as linhas `Profile_User` com `is_dynamic = 1` e trata a
  entidade padrão; as autorizações manuais não são tocadas. Confirmado no spike.
- **S14. Multi-entidade preservado.** Várias regras que casam somam autorizações, como hoje no
  AD. Um usuário na OU `Financeiro` pode receber duas entidades se houver duas regras.
- **S15. Revogação no bloqueio.** Quando um usuário já vinculado cai no bloqueio duro ou em
  `_deny_login`, o plugin remove as autorizações dinâmicas dele e registra um evento.
  Configurável, ligado por padrão. Cobre quem muda de OU para a de professores.
- **S16. Formulário local oculto por padrão.** O hook `display_login` entrega o botão e um script
  que oculta o formulário, a menos que a URL tenha `?local=1`. É cosmético (o `POST` por senha
  continua aceito) e **falha aberto**: módulo desabilitado, sem credenciais ou sem JS mostra o
  formulário. Configurável.
- **S17. O 2FA do GLPI (TOTP) não é exigido** no login pelo Google; o Google é o responsável pela
  verificação em duas etapas. `Session::init` não passa pelo fluxo de MFA do `Auth::login`.
- **S18. Consistência domínio × OU, opcional e desligada.** Um campo informa a posição do domínio
  no caminho da OU (`2` na estrutura atual). Se o domínio do e-mail diverge do segmento, nega e
  registra. *(Revisável.)*
- **S19. Modo piloto** (`sso_pilot_only` + `sso_pilot_emails`): só os e-mails da lista conseguem
  entrar pelo Google. Reduz o risco de ligar em produção.
- **S20. Mensagem genérica e código de correlação.** Toda falha mostra ao usuário "Não foi
  possível entrar com o Google. Procure o DTI informando o código N", em que N é o id do evento.
  O motivo real fica só no log.
- **S21. Segredos** (`client_secret`, chave privada da conta de serviço) ficam criptografados na
  configuração (V1). Tokens nunca vão para o log.
- **S22. Professores.** O bloqueio é feito pelo bloqueio duro (S9), com uma OU de professores por
  unidade. Opcionalmente o administrador pode criar também uma regra com `_deny_login` como
  segunda barreira. Uma OU sem regra já é negada por padrão; o risco que o bloqueio duro cobre é
  uma OU de professores **abaixo** de uma regra-padrão de unidade. O bloqueio ocorre **no
  login**: quem já está logado segue até a sessão expirar, e o login por AD de quem não foi
  convertido não é afetado por este plugin.
- **S23. Convivência com as regras do AD. (Proposta.)** As regras do SSO e as 62 do AD ficam na
  mesma lista de regras de autorização. As do SSO levam também o critério `TYPE` igual a
  "externo" (`Auth::EXTERNAL`) e o prefixo "SSO Google - " no nome, para não casarem em login
  LDAP e vice-versa. Enquanto o AD estiver ativo, um usuário já convertido que entre pelo AD volta
  a ser avaliado pelas regras LDAP, que regravam as autorizações dinâmicas; o próximo login
  Google as regrava de volta. Risco aceito, com a recomendação operacional de desativar as
  regras LDAP de uma unidade quando todas as pessoas dela tiverem migrado.
- **S24. Vários workspaces do Google.** *(Decidida em 05/10/2026, depois de um teste real em que
  `dti.juliano@metropolitana-ro.com.br` passou na lista de domínios e a Directory API respondeu
  403: um domínio de outro workspace não tem OU legível pela conta de serviço de outro.)* A
  configuração tem uma **lista de workspaces**; cada um tem nome, domínios, o **administrador
  representado** daquele workspace e um indicador de ativo. A **conta de serviço é única e
  compartilhada**: a delegação em todo o domínio é concedida em cada Admin Console pelo ID dela,
  então uma só chave atende a todos. No login, o workspace é achado pelo **domínio do e-mail** e a
  OU é lida com o administrador dele. A lista de domínios aceitos é a **união dos domínios dos
  workspaces ativos**; um domínio só pode pertencer a um workspace (a tela recusa duplicados) e, se
  nenhum workspace ativo o tem, o login é negado com `domain_denied`. O cliente OAuth é único e, com
  workspaces de organizações diferentes, a tela de consentimento precisa ser **Externa e publicada**
  (os escopos `openid`, `email` e `profile` não exigem verificação [Likely]); quem barra contas
  indevidas continua sendo o plugin. Fora do escopo: uma chave de conta de serviço por workspace
  (só faria falta se o Google recusasse a delegação cruzada por política, ou para um workspace de
  terceiros); a estrutura aceita acrescentá-la depois. A configuração antiga de um workspace só
  (`sso_allowed_domains` e `sso_sa_admin_subject`) é migrada para um workspace "Principal" na
  instalação.
- **S25. O workspace tem uma chave estável.** *(Decidida em 07/10/2026.)* O `name` é editável e
  não serve de referência para as regras: renomear "Principal" quebraria em silêncio todas as
  regras que o citam. Cada workspace ganha `key`, um identificador `[a-z0-9-]` (1 a 40
  caracteres), **único** e **imutável** depois de salvo. É gerado na criação a partir do nome
  (`Principal` vira `principal`; acento é removido; colisão ganha o sufixo `-2`, `-3`...). Os
  workspaces já gravados sem chave a recebem na primeira carga, pelo preenchimento de valores
  faltantes do `SsoSettings::normalize()`, derivada do nome atual. A tela mostra a chave como
  somente leitura, e o servidor ignora qualquer alteração dela vinda do formulário. O
  `WorkspaceRegistry` recusa chave duplicada. Se um workspace for removido e outro for criado com o
  mesmo nome, a chave gerada é a mesma e as regras antigas voltam a casar; isso é consequência da
  geração, não uma garantia.
- **S26. Novo critério de regra `GOOGLE_WORKSPACE`.** *(Decidida em 07/10/2026.)* Além de
  `GOOGLE_OU` (S4), o `RuleRight` ganha o critério virtual "Workspace do Google", que só aceita a
  condição "é" e cujo valor é a **chave** (S25). O `RuleRunner` já conhece o workspace achado pelo
  domínio do e-mail (S24) e passa a entregar a chave ao motor; o `RuleHooks::inputData` a coloca na
  entrada com a mesma mecânica do `GOOGLE_OU`. **Regras sem esse critério continuam valendo em
  qualquer workspace**, exatamente como antes: a conversão para regras por workspace é opcional e
  feita regra a regra. Motivo: o motor compara apenas o caminho da OU, então o mesmo caminho em dois
  workspaces (por exemplo `/Sistemas`) casaria a mesma regra nos dois; com o critério novo a regra
  pode distinguir.
- **S27. Trava na remoção de um workspace.** *(Decidida em 07/10/2026.)* Ao salvar a
  configuração, se um workspace gravado deixou de existir na lista enviada, o servidor procura
  as regras de autorização que o usam e **recusa o salvamento inteiro** (nada é gravado pela
  metade) enquanto houver alguma. A varredura é uma consulta em `glpi_rulecriterias` ligada a
  `glpi_rules`, com `criteria = 'GOOGLE_WORKSPACE'`, `pattern` igual à chave (qualquer condição: uma regra "não é" também depende do workspace) e regra de autorização
  (`glpi_rules.sub_type = 'RuleRight'`; a tabela `glpi_rules` não tem `is_deleted`, uma regra apagada
  simplesmente deixa de existir). **Regras desativadas contam**: elas
  podem ser reativadas e é ali que quebrariam. A mensagem lista cada regra pelo nome, com link
  para abri-la. A trava vale na remoção, não na desativação: um workspace desativado já nega o
  login por domínio (S24), então a regra nunca chega a ser avaliada. A trava é no servidor
  (`SsoConfig::save()`), porque "Limpar" só esvazia campos no navegador.
- **S28. Lista de OUs lida direto do Google, com cache, sem tabela.** *(Decidida em 07/10/2026,
  depois do spike da seção 12: 91 OUs em uma chamada de cerca de 1,2 s, sem paginação.)* Uma classe
  nova, `OrgUnitDirectory`, chama `GET /admin/directory/v1/customer/my_customer/orgunits?type=all`
  com o escopo **`admin.directory.orgunit.readonly`**, **só na listagem**: o login continua pedindo
  apenas o escopo de usuário (S3), então um workspace que ainda não autorizou o escopo novo não
  afeta ninguém. O resultado é guardado em `$GLPI_CACHE` por chave de workspace, com TTL de 10
  minutos e um botão "Atualizar" que o ignora. Cada workspace falha de forma isolada: o erro de
  um aparece no seletor e não impede a lista dos outros. Não há tabela do plugin; a lista nunca é
  fonte de verdade de nada, só ajuda a digitar. O administrador representado precisa de um papel
  com **Unidades organizacionais: Ler** além da leitura de usuários; super administrador não é
  necessário (o texto da configuração, que dizia isso, é corrigido). Se a Directory API devolver
  401 ou 403 para o escopo novo, a causa provável é o privilégio do papel (seção 12).
- **S29. Seletor na tela de regras.** *(Decidida em 07/10/2026.)* O campo do valor do critério
  não está no HTML da página de regras: o GLPI o carrega por AJAX (`ajax/rulecriteriavalue.php`
  chama `Rule::displayCriteriaSelectPattern()`, que imprime `Html::input('pattern')`) a cada troca
  de critério ou condição. Um JS do plugin (`add_javascript`, sem efeito fora do formulário de
  critérios) observa o DOM e, quando o critério é:
  - `GOOGLE_WORKSPACE`: troca o campo por um `<select>` (valor = chave, rótulo = nome);
  - `GOOGLE_OU`: troca o campo por um **combobox com texto livre**; o rótulo é
    `<nome do workspace> - <caminho>`, e o **valor gravado é só o caminho** (o `RuleHooks` compara
    o caminho puro). Se a mesma regra já tem um critério `GOOGLE_WORKSPACE`, as opções são
    filtradas por ele.
  Os dados vêm de `ajax/sso/orgunits.php` (JSON; exige o direito de ler regras de autorização).
  Se a lista falhar, o campo de texto original permanece, com um aviso discreto. O texto livre
  continua valendo: cobre a OU recém-criada, o cache velho e o Google fora do ar. A escolha do
  componente (select2 do GLPI ou `<datalist>`) fica para o plano; não muda o contrato acima. O
  campo de OUs bloqueadas (S9) usa o mesmo mecanismo de dados, ver S30.
- **S30. Seletor também no campo de OUs bloqueadas.** *(Decidida em 07/10/2026.)* Na
  configuração, o campo `sso_blocked_ou_paths` continua sendo um **texto com um caminho por
  linha** (formato, `OuBlocklist` e dados já gravados não mudam, sem migração). Acima do texto
  entra um combobox "Adicionar OU" com as mesmas OUs da S29 (todos os workspaces, rótulo
  `<workspace> - <caminho>`); escolher uma OU **acrescenta o caminho como uma nova linha** do
  texto, sem duplicar uma linha que já existe (comparação sem diferença de caixa, como o
  `OuPath::normalize()`). O texto continua editável à mão e é a única fonte de verdade; se a lista
  falhar, resta só o texto. **A lista de bloqueio é global, não por workspace**: ela compara apenas
  o caminho (S9), então bloquear `/Docentes` bloqueia esse caminho em todos os workspaces. Isso
  falha para o lado seguro (bloqueia mais, nunca menos), e o rótulo do seletor deixa claro que o
  efeito é em todos. Bloqueio por workspace fica fora desta entrega: exigiria mudar o formato do
  texto e a migração dos dados gravados.

## 5. Modelo de dados

Tabelas `glpi_plugin_gac_sso*`, criadas de forma idempotente em `plugin_gac_install()`. O
mapeamento de OU e as exceções por e-mail **não** têm tabela: são regras nativas (`glpi_rules`).

### 5.1 `ssoidentities`

`users_id` (único), `google_sub` (único), `email_at_link`, `prev_authtype`, `prev_auths_id`,
`prev_entities_id`, `removed_authorizations` (JSON), `linked_at`, `last_login_at`, `last_ou_path`.

### 5.2 `ssoevents`

`id` (é o código de correlação mostrado ao usuário), `date`, `email`, `users_id` (nulo), `ou_path`,
`outcome`, `detail`. Valores de `outcome`: `ok`, `domain_denied`, `email_unverified`,
`ou_blocked`, `ou_denied`, `ou_unmapped`, `domain_mismatch`, `email_ambiguous`, `pilot_blocked`,
`create_disabled`, `user_inactive`, `local_account`, `state_invalid`, `token_invalid`,
`api_error`, `revoked`, `undone`. A tela de OUs
pendentes é um `GROUP BY ou_path` sobre `ou_unmapped`; não há tabela própria. Retenção
configurável.

### 5.3 Configuração (`glpi_configs`, contexto `plugin:gac`, chaves `sso_*`)

`sso_enabled`, `sso_client_id`, `sso_client_secret` (protegido), `sso_workspaces` (JSON: lista de
`{key, name, domains, admin_subject, is_active}`, ver S24 e S25), `sso_sa_client_email`, `sso_sa_private_key`
(protegido), `sso_blocked_ou_paths`, `sso_auto_create` (padrão sim), `sso_hide_local_form` (padrão sim),
`sso_domain_segment` (vazio = desligado), `sso_pilot_only` (padrão não), `sso_pilot_emails`,
`sso_revoke_on_deny` (padrão sim), `sso_event_retention_days`, `sso_button_label`,
`sso_redirect_uri` (opcional; vazio usa a `url_base` do GLPI, e a tela mostra o URI calculado para
cadastrar no Google; permite testar em `http://localhost/` sem mudar a `url_base`).

## 6. Fluxos

### 6.1 Login

1. O botão leva a `start.php`, que gera `state`, `nonce` e verificador PKCE, guarda-os em
   `$_SESSION` (a sessão pré-login que o GLPI já abre na tela de login) e redireciona ao Google,
   pedindo `openid email profile` e `hd`.
2. O Google devolve ao `callback.php`, que aplica o truque `cookie_refresh` do núcleo (um
   `meta refresh` para si mesmo, para o cookie de sessão voltar mesmo com
   `session.cookie_samesite = strict`), valida o `state` da sessão, troca o código pelo token
   e valida o ID token: `iss`, `aud`, `exp`, `nonce`, `email_verified`, `hd` (V7).
3. `DomainPolicy` confere o domínio da lista. Se `sso_domain_segment` está ligado, confere o
   segmento do caminho. No modo piloto, confere a lista de e-mails.
4. `DirectoryClient` lê o `orgUnitPath` do usuário. O bloqueio duro (S9) é avaliado.
5. O plugin roda a `RuleRightCollection` com a lista de ancestrais (S4) e aplica a S8.
6. `IdentityMatcher` decide: usar o usuário vinculado, vincular um existente por e-mail ou
   criar.
7. `UserProvisioner` converte/cria o usuário e aplica as autorizações pelo núcleo (S13, S15).
8. `SessionStarter` abre a sessão (S2), grava `last_login`, registra o evento de login do GLPI
   e redireciona para um caminho relativo validado.

### 6.2 Teste a seco

Na tela de configuração, informa-se um e-mail; o plugin consulta a Directory API, avalia o
bloqueio duro, roda a coleção de regras **sem** aplicar nada e mostra: a OU lida, a lista de
ancestrais, as autorizações resultantes (entidades, perfis e recursivo, com a entidade padrão) e
se o login seria negado e por quê. O motor de regras do núcleo não expõe quais regras casaram,
então a lista de regras não aparece; o resultado final é o que importa. Não cria sessão, usuário, evento de login nem altera autorizações.

### 6.3 Conversão e reversão

A conversão acontece dentro do passo 7 do login, na primeira vez que o `sub` é vinculado. "Desfazer
conversão" (tela de identidades) restaura `authtype`, `auths_id` e as autorizações do snapshot e
remove a identidade.

## 7. Rotas sem sessão e segurança

- `start.php` e `callback.php` são scripts **sem verificação de autenticação, mas com sessão**,
  registrados em `setup.php` por `Firewall::addPluginStrategyForLegacyScripts('gac',
  '#^/front/sso/(start|callback)\.php$#', Firewall::STRATEGY_NO_CHECK)`, como `front/login.php`.
  Não usam `registerPluginStatelessPath()` (isso é para o Monitor público): o callback precisa de
  uma sessão real, tanto para o `state` quanto para abrir o login. O padrão é o do callback
  OAuth do SMTP do próprio GLPI (`front/smtp_oauth2_callback.php`).
- Hooks de regras em `setup.php`/`hook.php`: `use_rules` com `['RuleRight']`,
  `plugin_gac_getRuleCriteria()` e `plugin_gac_ruleCollectionPrepareInputDataForProcess()`. O
  critério é virtual (sem tabela) e o hook só responde quando `rule_itemtype` é `RuleRight`.
- Redirecionamento pós-login só para caminhos relativos do GLPI.
- A conta de serviço pede apenas os dois escopos de leitura; a chave é usada só para obter tokens
  de acesso.
- Falha fechada em qualquer erro (API, token, estado).
- A sessão é regenerada após o login (V2).
- Nenhum token ou segredo em log ou em mensagem de erro.
- O callback registra toda tentativa em `ssoevents`; um volume anormal é visível na tela de
  eventos.

## 8. Permissões

- **Regras de OU e de e-mail**: editadas na tela nativa de regras de autorização, sob o direito
  nativo do GLPI para essa tela; este plugin não cria direito para elas.
- Uma linha em `Features::all()` (`sso`), mostrada na aba única de direitos do perfil:
  - Ler: ver identidades e eventos.
  - **Configurar** (`Features::RIGHT_CONFIG`): credenciais, conta de serviço, bloqueio duro,
    padrões, modo piloto, teste a seco e "Desfazer conversão". Gate da `SsoConfigSection`.

O menu mostra a entrada "Login com Google" só para quem tem direito.

## 9. Estrutura de código (rascunho)

Módulo isolado `src/Sso/`.

**Puras, sem GLPI, com testes em `tests/Unit/`:**

- `DomainPolicy`
- `OuPath` (normalização, lista de ancestrais, casamento por segmento)
- `OuBlocklist` (bloqueio duro, usa `OuPath`)
- `IdentityMatcher`
- `LoginState` (state, nonce, PKCE)
- `LoginDecision` (a ordem da S8 como função pura, sobre um resultado de regras já calculado)
- `SsoSettings`

**Ligadas ao GLPI:**

- `SsoIdentity`, `SsoEvent` (sobrescrevem `getTable()`, ver convenção da sub-namespace no
  `CLAUDE.md`).
- `RuleHooks` (os dois hooks de regras e a definição do critério `GOOGLE_OU`).
- `RuleRunner` (monta a entrada, roda a `RuleRightCollection` e devolve um resultado simples).
- `GoogleClient`, `DirectoryClient`.
- `LoginService` (orquestra e devolve `ServiceResult`), `UserProvisioner` (conversão, criação,
  `applyRightRules`, revogação), `SessionStarter`.
- `SsoLoginButton` (hook `display_login`), `SsoMenu`, `SsoConfigSection`.

Twig em `templates/sso/`, páginas em `front/sso/`, JS em `public/js/`. O módulo só lê as tabelas
dos outros; não escreve nelas.

## 10. Erros, logs e testes (rascunho)

- Cada falha vira um `outcome` em `ssoevents` e uma mensagem genérica com código (S20).
- **Unitários:** cada classe pura, e a `LoginDecision` com casos de bloqueio, `_deny_login`,
  sem regra, e-mail ambíguo, domínio divergente e modo piloto. `OuPath` com fronteira de segmento,
  caixa e barras.
- **Manuais:** `docs/sso-manual-tests.md`, com tabela de resultado. O Google real e a tela de
  regras só são exercitáveis aí. O GLPI local responde em `http://glpi11local.test/`, que o Google
  não aceita como URI de redirecionamento; serão necessários `http://localhost/` e o cookie
  ligado a esse host (V5). O script do spike (aplicação de regras a um usuário EXTERNAL) serve de
  base para um teste de integração manual.
- Bump de versão **minor** (funcionalidade aditiva) e entrada de CHANGELOG em português, no
  fechamento da fase 4. Release e tag são decisão do dono.

## 11. Implantação e pré-requisitos do Google (rascunho)

**Fases:**

1. Fundação: tabelas, configuração, classes puras com testes, hooks de regras, install/uninstall
   idempotentes.
2. Fluxo de login: OAuth, Directory API, identidade e sessão; funciona ponta a ponta com uma
   regra por e-mail.
3. Administração: configuração, bloqueio duro, OUs pendentes, teste a seco, identidades e eventos.
4. Fechamento: conversão e reversão, retenção, manuais, versão.

**Sequência em produção:** instalar com o módulo desabilitado; configurar o Google; criar as
regras "SSO Google - ..." na tela de regras de autorização (com `TYPE` externo e stop nas
específicas); cadastrar o bloqueio duro com as OUs de professores; rodar o teste a seco com
e-mails reais; ligar em modo piloto com a TI; abrir para todos; só então ocultar o formulário.

**No Google (responsabilidade do super-admin):**

1. Projeto no Google Cloud com a Admin SDK API ativada.
2. Tela de consentimento OAuth do tipo **Interna**.
3. Cliente OAuth do tipo Web com os URIs de redirecionamento de produção (HTTPS) e de
   desenvolvimento (`localhost`).
4. Conta de serviço com delegação em todo o domínio, no Admin Console, com **só** o escopo
   `admin.directory.user.readonly`.
5. Admin dedicado, com função personalizada somente leitura, para ser representado.
6. Chave JSON da conta de serviço (se a política da organização bloquear a criação de chaves, ela
   precisa ser liberada antes).
7. Saída do servidor de produção para `oauth2.googleapis.com` e `admin.googleapis.com`, com ou
   sem proxy; o plugin deve respeitar o proxy configurado no GLPI.

## 12. Spike, pendências e riscos

### Confirmado no spike (2026-10-05, GLPI 11.0.8 local, script descartável)

- O hook `getRuleCriteria` acrescenta `GOOGLE_OU` a `RuleRight::getAllCriteria()`. O `use_rules`
  precisa ser um array de tipos.
- `ruleCollectionPrepareInputDataForProcess` recebe os parâmetros brutos de `processAllRules()` e
  injeta o valor; o `RuleRightCollection` por si só descarta parâmetros desconhecidos.
- Critério com lista de valores e condição "é" casa se qualquer elemento casar: uma regra em
  `/…/ies-pvh` casou para a OU filha.
- `_deny_login` + `_stop_rules_processing` devolvem `_deny_login = 1` e nenhum `_ldap_rules`; OU
  sem regra devolve `_no_rule_matches = true`.
- Duas regras para a mesma OU somam entidades e perfis; a regra-padrão da unidade casa junto das
  específicas se estas não pararem o processamento (S5).
- Critério `MAIL_EMAIL` do núcleo funciona para a exceção da TI.
- `willProcessRuleRight()` + `applyRightRules()` num usuário `Auth::EXTERNAL` criam as dinâmicas,
  apagam as antigas ao mudar de OU (inclusive a dinâmica padrão criada com o usuário) e preservam
  a autorização manual.

### Verificações pendentes

Nada abaixo foi confirmado ainda. Cada item é uma verificação a fazer antes ou durante a
implementação.

- **V1.** *(Resolvida.)* `Hooks::SECURED_CONFIGS` já é usado pelo Monitor (`setup.php`); o SSO
  acrescenta `sso_client_secret` e `sso_sa_private_key` e lê com `GLPIKey::decrypt()` como o
  `MonitorConfig`.
- **V2.** *(Resolvida.)* `Session::init()` faz `session_destroy` + `session_regenerate_id()` +
  `session_start`, então o id é regenerado; por isso o `state` da sessão é lido antes.
- **V3.** *(Reduzida pelo spike.)* O núcleo apaga todas as dinâmicas do usuário ao reaplicar; para
  um usuário vindo do AD isso inclui as geradas pelas regras LDAP. Aceito, com o snapshot da S11.
- **V4.** *(Reescrita.)* Que a estratégia `NO_CHECK` do `Firewall` é aplicada às duas rotas do
  plugin e que o cookie de sessão chega ao callback, com e sem `session.cookie_samesite = strict`
  (truque `cookie_refresh`).
- **V5.** Que o Google rejeita `*.test` como host de redirecionamento e aceita `localhost`; como
  servir o GLPI local nesse host sem quebrar o vhost atual.
- **V6.** Efeito de `authtype = EXTERNAL` no formulário de usuário (e-mails, senha) e que o
  login por senha local fica barrado (`connection_db` exige `authtype = DB_GLPI`); que o login
  LDAP regrava `authtype = LDAP` (`AuthLDAP.php:3297`).
- **V7.** Validação do ID token: verificar a assinatura pelas chaves públicas do Google
  (`lcobucci/jwt` ou `firebase/php-jwt`, ambos no `vendor/`) além de confiar no canal TLS.
- **V8.** Latência e cotas da Directory API em uso real; decidir se é preciso cache curto.
- **V9.** *(Resolvida.)* O plugin usa `Toolbox::getGuzzleClient()`, que já honra o proxy do GLPI.
- **V10.** *(Parcialmente resolvida.)* A condição "é" do motor de regras **ignora caixa**
  (segundo spike: padrão `/FIMCA/Fimca.com.br/IES-PVH` casou a entrada em minúsculas). Resta
  saber se os nomes de OU do Google são insensíveis a caixa; o plugin normaliza para minúsculas
  de qualquer forma.
- **V11.** `Event::log` e `last_login` precisam ser feitos à mão pelo `SessionStarter`, já que o
  padrão do núcleo pula `Auth::login`.
- **V12.** Tokens pessoais da API e cookie "lembrar de mim" de um usuário que cai no bloqueio:
  confirmar que a revogação da S15 os cobre ou documentar a lacuna.
- **V13.** A tela de edição de regras renderiza o critério virtual `GOOGLE_OU` e oferece um campo
  de valor utilizável (verificar no navegador).
- **V14.** Que as regras reais do AD não casam num login Google, e as do SSO não casam num login
  LDAP, com o critério `TYPE` (testar com uma cópia das regras de produção; o banco local não as
  tem).
- **V15.** *(Resolvida: limitação.)* No segundo spike, a condição "começa com" **não casou**
  (nem com entrada em lista nem com valor único) quando o padrão contém barras. Em regras do SSO
  use **somente a condição "é"** no critério "OU do Google"; a herança vem da lista de ancestrais
  (S4). A tela de configuração avisa isso.
- **V16.** Tratamento de `_deny_login` quando o usuário é novo (ainda sem `id`) e o fluxo de
  `Auth::login` não é usado: o plugin lê o resultado das regras diretamente.

**Riscos aceitos conscientemente:**

- A herança depende da ordem das regras e da ação de parar (S5); o bloqueio duro (S9) protege só
  a proibição de professores.
- Conversão suave enquanto o AD estiver ativo e alternância de autorizações (S11, S23).
- O 2FA do GLPI não é exigido (S17).
- O bloqueio de professores vale no login, não em sessões abertas (S22).
- A edição de OU em texto livre sujeita a erro de digitação, sem a tela de árvore (fora da
  primeira entrega). *(Mitigado em 07/10/2026 pelo seletor da S29, que sugere as OUs do Google
  e mantém o texto livre como reserva.)*

### Confirmado no spike de listagem de OUs (2026-10-07, script descartável, workspace Principal)

- `orgunits.list?type=all` com `customer/my_customer` devolveu **91 OUs** em uma chamada
  (HTTP 200, cerca de 1,2 s somando o pedido do token, 36 KB, sem `nextPageToken`), com no máximo
  3 níveis. Vieram também as pastas intermediárias vazias. Campos: `name`, `orgUnitPath`,
  `orgUnitId`, `parentOrgUnitPath`, `parentOrgUnitId`.
- O caminho segue o formato do `orgUnitPath` do usuário (barra inicial, com espaços, acentos e
  colchetes: `/fimca.com.br/IES-PVH/Almoxarifado e Compras`, `/[desativados]`) e **mantém a caixa**
  do Google (`IES-PVH`); o `OuPath::normalize()` compara em minúsculas.
- **O administrador representado precisa de um papel com "Unidades organizacionais: Ler".** Os dois
  administradores testados são delegados (`isAdmin: false`, `isDelegatedAdmin: true`). Enquanto o
  papel não tinha o privilégio, a API respondeu **401 "Login Required"** (não 403) com um token
  válido para o escopo (`tokeninfo` confirmou o escopo); `my_customer`, o `customerId` real e hosts
  diferentes deram o mesmo erro. Depois de acrescentar o privilégio ao papel, 200. Um token só com o
  escopo de usuário recebe 403 "insufficient authentication scopes" nessa rota.

### Verificações pendentes da entrega S25 a S30

- **V17.** Escolher o componente do combobox (select2 do GLPI ou `<datalist>`) lendo o GLPI 11.0.x
  local, e confirmar que o rótulo "workspace - caminho" com valor só do caminho funciona nele.
- **V18.** Confirmar no navegador que o observador do DOM troca o campo quando o critério ou a
  condição mudam e na edição de um critério já gravado (valor preenchido).
- **V19.** Testar a listagem na **Metropolitana** (escopo delegado e papel com leitura de OUs ainda
  não conferidos lá) e o isolamento de falha entre workspaces.
- **V20.** Conferir a trava de remoção (S27) com regra ativa e desativada, e que o salvamento
  recusado não grava nada.
- **V21.** Conferir se algum caminho de OU se repete entre o Principal e a Metropolitana; se sim, o
  aviso de repetição no rótulo vale a pena (S26).
- **V22.** Conferir no navegador o "Adicionar OU" do campo de OUs bloqueadas (S30): acrescenta a
  linha, não duplica, respeita linhas de comentário (`#`) e não apaga o que foi digitado à mão.

### Testes da entrega S25 a S30

O harness unitário (`tests/Unit/`, sem GLPI) não tem `__()` nem `htmlescape()`, e o repositório não
tem teste de JS. Para que o que é regra seja testável sem o GLPI, a lógica fica em **classes puras
novas** (em `src/Sso/`, sem `__()`, sem banco e sem Guzzle) e em **funções puras de JS**, e o código
preso ao GLPI fica fino. Há quatro camadas.

**A. Unitários de PHP (`phpunit.unit.xml`).** Classes puras novas:

- `WorkspaceKey`: gerar a chave a partir do nome (`Principal` vira `principal`; acentos, espaços e
  símbolos viram `-`; sem hífen nas pontas; nome só de símbolos ou vazio vira um valor válido
  fixo; limite de 40 caracteres); validar o formato; sufixo `-2`, `-3` em colisão com as chaves
  existentes, sem passar do limite.
- `WorkspaceRegistry` (estende o teste existente): chave acrescentada às linhas sem chave; chave
  duplicada recusada; **chave gravada vence a do formulário** (imutabilidade); renomear mantém a
  chave; JSON de ida e volta; linha antiga sem chave e sem nome não quebra.
- `SsoSettings::normalize()` (estende o teste existente): preenchimento de `key` ao ler a
  configuração antiga; idempotência (normalizar duas vezes dá o mesmo).
- `WorkspaceRemoval` (diferença entre a lista gravada e a enviada): remoção detectada pela chave;
  renomear não conta; desativar não conta; acrescentar não conta; lista vazia e lista nova vazia.
- `OrgUnitList`: ler a resposta de `orgunits.list` (campos ausentes, caminho sem barra inicial,
  lista vazia, resposta sem `organizationUnits`); ordenação estável; rótulo
  `<workspace> - <caminho>`; filtro por workspace; marcação de caminho repetido entre workspaces;
  envelope do cache (montar, ler, expirar, versão inválida descartada).
- `RuleInput` (monta o array de parâmetros do motor): com chave de workspace entrega
  `google_workspace`; sem workspace não entrega a chave; o `google_ou` não muda. Cobre a regra
  de compatibilidade da S26. `RuleHooks::inputData` passa a delegar a ele.
- `OuBlocklist` (estende o teste existente): texto com linhas acrescentadas à mão e por
  "Adicionar OU" (comentários, linhas em branco, caixa diferente, duplicata) dá o mesmo conjunto.

**B. Unitários de JS (`node --test`, Node 24 local).** `public/js/sso-ou-picker.js` expõe suas
funções puras com `module.exports` quando existir `module` (o navegador as ignora): montar rótulo
e valor de uma opção; filtrar por workspace e por texto; acrescentar linha ao texto sem duplicar
(sem diferença de caixa, ignorando comentários, preservando o que existe, com e sem quebra de
linha final); escapar nome de OU com aspas, `<`, `&` e `'` ao montar HTML. O teste roda com
`node --test "tests/js/*.test.js"`. Fica **fora** desta camada tudo que mexe no DOM do GLPI.

**C. Roteiros contra o GLPI local (script em `tests/` ou `var/`, rodando com o PHP do XAMPP).**
Integração com o banco real do ambiente de desenvolvimento, descartável e com dados próprios:

- Varredura da trava (S27): com regras de autorização criadas pelo script e removidas no fim,
  cobrir regra ativa, desativada, de outro tipo (`sub_type`), com a chave de outro workspace e
  com o critério em outra condição (por exemplo "não é"), e uma regra apagada. Esperado: bloqueiam a
  ativa, a desativada e a de outra condição; não bloqueiam a de outro tipo, a de outra chave nem a
  apagada. A mensagem traz o nome e o link de cada uma.
- `SsoConfig::save()` com remoção bloqueada: **nada é gravado** (comparar a configuração antes e
  depois) e o erro volta ao formulário.
- Compatibilidade de regras (S26): uma regra antiga só com `GOOGLE_OU` e outra com `GOOGLE_OU` mais
  `GOOGLE_WORKSPACE`, ambas pelo `RuleRunner`, com usuários de dois workspaces. Esperado: a antiga
  casa nos dois, a nova só no workspace dela.
- `OrgUnitDirectory`: resposta com a OU lida do Google (o spike de `var/` já fez a chamada real),
  falha de um workspace sem derrubar o outro, escopo não delegado (401 e 403 viram mensagem
  legível, sem expor o corpo do erro além do que o `errorDetail` já filtra), cache dentro e fora
  do TTL e o botão "Atualizar".
- Endpoint `ajax/sso/orgunits.php` por `curl`: sem sessão, sem o direito de regras, com o direito;
  o JSON não pode conter chave de conta de serviço, token nem e-mail de administrador.

**D. Roteiro no navegador (`docs/sso-manual-tests.md`, nova seção).** O que só o navegador mostra:
o campo trocado ao escolher o critério `GOOGLE_OU` e `GOOGLE_WORKSPACE`; ao trocar a condição; ao
editar um critério já gravado (valor preenchido); o filtro das OUs pelo workspace da própria
regra; a rotina sem lista (campo de texto original mais o aviso); salvar a regra e conferir o valor
gravado (**só o caminho**); o "Adicionar OU" das bloqueadas (V22); largura de celular; botão de
copiar que já existe. A trava de remoção pela tela, lendo a mensagem e abrindo o link da regra.
Teste de segurança de tela: OU com `<`, aspas ou `&` no nome aparece como texto, nunca como HTML.

**Regressão.** A suíte unitária inteira (`phpunit.unit.xml`) continua verde, e o roteiro do SSO
existente é refeito nos itens que tocam o login, porque o `RuleRunner` e o `RuleHooks` mudam.

**Fora dos testes automáticos, de propósito:** a chamada real ao Google (depende de delegação, de
papel e de rede; coberta pelo roteiro C e pelo spike) e o componente do GLPI (select2).
