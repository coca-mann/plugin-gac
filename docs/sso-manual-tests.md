# Login com Google — roteiro de testes manuais

Roteiro do módulo SSO (spec `docs/superpowers/specs/2026-10-05-sso-google-design.md`). Rodar no GLPI
local servido em `http://localhost/` (o Google não aceita `glpi11local.test` como URI de
redirecionamento). Coluna **Resultado**: data (`dd/mm/aaaa`) e `OK`/`FALHOU`/`N/A` a cada rodada, com o
meio entre parênteses. `—` significa que ainda não foi executado.

Resultados de 05/10/2026 vêm de uma rodada **sem credenciais do Google**: script contra o GLPI local
(`script`), `curl` na tela de login deslogada (`curl`) e o navegador logado como Super-Admin
(`navegador`). Tudo que depende de uma conta real do Workspace continua `—`.

## Preparação

1. Configurar a seção "Login com Google" (domínios, cliente OAuth, conta de serviço, URI de redirecionamento).
2. Ter ao menos: uma regra "SSO Google - ..." para a OU de teste; uma OU de professores no bloqueio duro; um usuário do GLPI com `authtype` LDAP e o e-mail de uma conta de teste.
3. Quem já estava logado no navegador antes da instalação precisa **trocar de perfil** (menu do usuário) ou entrar de novo para enxergar o novo direito "Login com Google".

## Roteiro

| # | Cenário | Passos | Esperado | Resultado |
|---|---|---|---|---|
| 1 | Módulo desligado | `sso_enabled` = não; abrir o login | Sem botão; formulário normal | 05/10/2026 OK (curl) |
| 2 | Botão e formulário oculto | Ligar; abrir o login | Botão do Google; formulário oculto; link "Entrar com usuário e senha" leva a `?local=1` e mostra o formulário | 05/10/2026 parcial (curl: botão, link e script presentes; `?local=1` sem o script de ocultar). **Falta ver a ocultação visual num navegador deslogado** |
| 3 | Login de usuário novo | Conta cuja OU tem regra e que não existe no GLPI | Usuário criado com login = e-mail completo; entidade/perfil da regra; evento `ok` com `detail=created` | — (criação e regras verificadas por script; falta o login real) |
| 4 | Vincular usuário do AD | Conta com o mesmo e-mail de um usuário LDAP | Usa o usuário existente; `authtype` vira externo; identidade gravada; evento `detail=linked`; autorizações dinâmicas antigas substituídas, manuais preservadas | — (conversão e preservação das manuais verificadas por script; falta o login real) |
| 5 | Segundo login | Repetir o 3 ou o 4 | Reusa a identidade (`detail=login`) | — |
| 6 | Domínio não permitido | Conta de domínio fora da lista | Volta ao login com o código; evento `domain_denied` | — (decisão verificada por teste unitário e teste a seco; falta o login real) |
| 7 | OU sem regra | Conta em OU sem regra | Negado; evento `ou_unmapped`; a OU aparece em "OUs pendentes" | — (listagem em "OUs pendentes" verificada no navegador com eventos semeados) |
| 8 | OU bloqueada (professores) | Conta na OU do bloqueio duro | Negado; evento `ou_blocked`, mesmo havendo regra `allow` herdada acima | — |
| 9 | Regra com negar | Regra com `_deny_login` para a OU | Negado; evento `ou_denied` | — (negação verificada por script contra o motor de regras) |
| 10 | Modo piloto | Ligar o piloto sem listar o e-mail | Negado; `pilot_blocked`; listando o e-mail, entra | — |
| 11 | Conta local | Conta com o e-mail de um usuário de banco (ex.: `glpi`) | Negado; `local_account`; o `glpi` continua entrando por senha | — |
| 12 | E-mail ambíguo | Dois usuários com o mesmo e-mail | Negado; `email_ambiguous` | — |
| 13 | Usuário inativo | Vincular e desativar o usuário no GLPI | Negado; `user_inactive` | — |
| 14 | Revogação no bloqueio | Usuário já vinculado, mover a conta do Google para a OU bloqueada, tentar entrar | Negado; evento `revoked`; autorizações dinâmicas removidas, manuais mantidas | — |
| 15 | Multi-entidade | Duas regras para a mesma OU | O usuário recebe as duas entidades | — (verificado por script no motor de regras e no `applyRightRules`) |
| 16 | Herança por ordem | Regra da unidade abaixo da específica, ambas com "Parar" | Só a específica se aplica | — (verificado por script no motor de regras) |
| 17 | Desfazer conversão | Identidades > Desfazer conversão | `authtype` e autorizações dinâmicas voltam; identidade removida; evento `undone` | 05/10/2026 OK (navegador: POST real com CSRF; `authtype` voltou a LDAP, identidade removida, evento `undone`). O botão usa `confirm()`, então o clique foi simulado enviando o mesmo formulário |
| 18 | Falha de API | Quebrar a chave privada e tentar entrar | Negado com código; `api_error`; nada de erro 500 | 05/10/2026 parcial (script/navegador: o teste a seco com chave inválida devolve `api_error` sem erro 500). Falta o login real |
| 19 | Estado inválido | Abrir `callback.php?state=x&code=y&cookie_refresh` direto | Volta ao login com código; `state_invalid` | 05/10/2026 OK (curl: redireciona para `/front/login.php?sso_error=<n>`; o evento é gravado) |
| 20 | Usuário cancela no Google | Fechar a tela de consentimento | Volta ao login com código; `token_invalid` | 05/10/2026 OK (script: `error=access_denied` com estado válido gera `token_invalid`). Falta a ação real no Google |
| 21 | Teste a seco | Seção de configuração, e-mail de cada caso acima | Reflete o resultado esperado sem criar sessão nem alterar dados | 05/10/2026 parcial (navegador: botão "Testar" mostra o alerta vermelho "módulo não configurado"; script: domínio fora da lista e chave inválida). Falta rodar com o Google configurado |
| 22 | Retenção | `SsoEvent::purgeOlderThan(-1)` via `var/tools/gac-eval.php` | Eventos antigos removidos | 05/10/2026 OK (script). A execução pela ação automática `SsoPurge` não foi testada |
| 23 | Redirecionamento | Abrir um link profundo deslogado, entrar pelo Google | Cai no link original; um `redirect` externo é ignorado | — |
| 24 | Desligar | `sso_enabled` = não depois de usar | Botão some; formulário volta; quem já está logado não é afetado | 05/10/2026 OK (curl: sem botão com o módulo desligado). A parte "quem está logado" não foi testada |
| 25 | Salvar a configuração | Seção "Login com Google" > Salvar | Valores persistem; segredo em branco mantém o atual; segredos ficam cifrados no banco | 05/10/2026 OK (navegador: domínios normalizados e persistidos; script: segredo cifrado e `save` parcial preserva as outras chaves) |
| 26 | Critério nas regras | Regras de autorização > Critérios | "OU do Google Workspace" aparece, com condição "é" e campo de texto | 05/10/2026 OK (navegador). O GLPI lista o critério sob o grupo "Critérios LDAP" (cosmético) |
| 27 | PKCE | `GoogleClient::begin()` | `code_challenge_method=S256` e `code_challenge` = `base64url(sha256(verifier))` | 05/10/2026 OK (script) |
| 28 | Rota sem login | `start.php` com o navegador deslogado | Redireciona ao Google (302), sem exigir login do GLPI | 05/10/2026 OK (curl) |

## Verificações pendentes da spec (seção 12)

Resolvidas: V1 (`SECURED_CONFIGS` já usado pelo Monitor; segredo gravado cifrado), V2 (`Session::init()` faz `session_regenerate_id()`), V9 (`Toolbox::getGuzzleClient()` honra o proxy), V10 (a condição "é" ignora caixa), V13 (critério visível e utilizável na tela de regras), V15 (só a condição "é" funciona com barras).

Parcialmente: V4 (a estratégia `NO_CHECK` e o `cookie_refresh` funcionam por `curl`; falta confirmar o cookie de sessão voltando do Google real, com e sem `session.cookie_samesite = strict`).

Em aberto: V3 (as dinâmicas do AD são substituídas, comportamento aceito), V5 (confirmar que o Google rejeita `*.test` como URI de redirecionamento e aceita `localhost`; como servir o GLPI local nesse host), V6 (efeito de `authtype` externo no formulário do usuário), V7 (a assinatura do ID token é verificada contra os certificados do Google e está coberta por teste unitário; falta vê-la com um token real), V8 (latência e cotas da Directory API), V11 (login e `last_login` feitos à mão pelo `SessionStarter`; falta confirmar com um login real), V12 (token pessoal da API e "lembrar de mim" após a revogação), V14 (as regras reais do AD não casam num login Google, e vice-versa) e V16 (`_deny_login` com usuário novo). Registrar o resultado de cada uma aqui.
