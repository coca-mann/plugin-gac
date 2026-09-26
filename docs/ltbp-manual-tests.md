# LTBP: roteiro de teste manual

Roteiro dos fluxos do LTBP que dependem do GLPI (só as classes puras têm teste automatizado, em
`tests/Unit/`). Cada linha diz como reproduzir e o que esperar. A coluna **Resultado** registra a
execução no GLPI 11.0.8 de desenvolvimento: preencha com a data e **OK**, **Parcial** (com a nota)
ou **Não executado** (com o motivo).

Legenda dos resultados já preenchidos (26/09/2026): **OK (smoke, code-path)** foi exercitado por
script que sobe o kernel do GLPI e chama as classes (serviços, guarda, renderização), sem passar
por uma requisição HTTP real nem pelo navegador. **Não executado** significa que nenhuma execução
o cobriu; o navegador só foi usado para conferir que o formulário, a lista e a aba Itens do laudo
abrem e que um laudo pode ser criado. Os cenários com resultado vazio ou "Não executado" precisam
ser rodados no navegador antes de dar o módulo como validado.

## Pré-requisitos

- GLPI local com o plugin instalado e ativo (reinstalado depois de qualquer mudança no `hook.php`),
  configuração do LTBP completa (três status do ativo, quatro campos dos diretores, motivos de
  baixa cadastrados, categoria da logomarca) e a do PRE completa.
- Um usuário com todos os direitos do LTBP **menos** "Editar ativo baixado", um perfil só de leitura
  e um perfil com "Editar ativo baixado". Depois de mudar direitos: novo login.
- Um fornecedor **ativo** (recicladora ou donatário), ativos de teste em "Aguardando baixa" (um
  computador, monitores e, se houver, um ativo personalizado) e um PRE encerrado com linhas
  devolvidas como "Sem conserto" com destino "Encaminhar para baixa".
- Depois de editar um `.twig`: `php bin/console cache:clear`.

## Roteiro

| # | Cenário | Como | Esperado | Resultado |
|---|---|---|---|---|
| 1 | Numeração | Criar 3 laudos | `LTBP-AAAA-001`, `-002`, `-003`; número nunca reaproveitado ao excluir um rascunho | Parcial: a criação de um laudo foi conferida no navegador; a sequência e o não reaproveitamento (o contador avança mesmo com laudos de teste apagados) foram vistos por script |
| 2 | Destinação obrigatória | Criar um laudo sem escolher a destinação | Recusa: "Informe a destinação." | Não executado (só no navegador) |
| 3 | Candidatos | Abrir a aba Itens de um rascunho | Lista os ativos no status "aguardando baixa" da entidade do laudo e de suas subentidades; ativo de outra entidade não aparece | OK (smoke, code-path): candidato de subentidade encontrado; a aba abre no navegador (estado vazio e dica de candidatos) |
| 4 | Origem no PRE | Candidato que veio do PRE | Mostra o número do PRE, o resultado e a descrição do serviço | Parcial: `pre_number`, ticket e `pre_items_id` gravados na linha pelo `LtbpLinker` (smoke); a coluna Origem da lista de candidatos com uma linha real do PRE não foi vista |
| 5 | Busca livre | Adicionar um ativo fora do status pelo seletor | Entra sem origem; nada é escrito em ticket | Parcial: a inclusão livre pelo serviço entra sem origem (smoke); o seletor na tela e a ausência de escrita em ticket não foram conferidos |
| 6 | Um laudo por ativo | Adicionar o mesmo ativo a outro laudo | Recusa: "já está no laudo LTBP-…" | OK (smoke, code-path) |
| 7 | Ativo em PRE ativo | Adicionar um ativo em linha ativa de PRE | Recusa citando o PRE | Não executado (não havia linha ativa de PRE no GLPI de dev quando a Tarefa 8 rodou) |
| 8 | Ativo já baixado | Adicionar um ativo já no status "baixado" | Recusa: "já está baixado" | Não executado |
| 9 | Emissão exige tudo | Emitir sem motivo em alguma linha / sem diretor / sem mapeamento | Botão desabilitado com a lista do que falta | Parcial: `IssueService::problems()` devolve as mensagens certas (destinação, sem linhas, status, diretores, motivos) e o botão sai habilitado quando está tudo completo (smoke); o botão desabilitado na tela não foi visto |
| 10 | Motivo inativo | Inativar um motivo já escolhido e tentar emitir | Recusa: "Escolha um motivo ativo…" | Não executado |
| 11 | Emissão | Emitir um laudo válido | Status "Aguardando assinaturas"; ativos em "em processo de baixa"; PDF anexado; motivos e diretores copiados; evento no histórico | OK (smoke, code-path): status, ativos, `states_id_before`, snapshot dos motivos, diretores, PDF congelado como `Document`, eventos `created` e `issued`, segunda emissão recusada; o clique real no botão (JS `confirm()`, mensagem após o redirect) não foi feito |
| 12 | Emissão atômica | Renomear `vendor/` e emitir | Falha inteira; laudo continua rascunho; status dos ativos intacto | Parcial: falha forçada na geração do PDF (sem renomear `vendor/`) deixou o laudo em rascunho, sem PDF, sem data, ativos e linhas intactos e sem evento (smoke) |
| 13 | Snapshot | Após emitir, editar um motivo e trocar o diretor na configuração | Aba Itens e PDF emitido continuam com o texto antigo | Parcial: trocar o diretor na configuração não alterou o laudo emitido, e a renderização de um laudo emitido lê o snapshot (smoke); a edição do motivo e a comparação do PDF já congelado não foram feitas |
| 14 | PDF retrato | Abrir o PDF de um laudo com 40 ativos | A4 vertical; cabeçalho da tabela repete; nenhuma linha cortada; assinaturas não se dividem; legível em preto e branco | Parcial: gerado A4 vertical (595,28 x 841,89) com 4 páginas e as 40 linhas no texto extraído; a conferência visual (cabeçalho repetido, linhas cortadas, assinaturas, preto e branco, marca d'água) não foi feita |
| 15 | Local e data | Entidade com cidade/UF e sem | "Cidade/UF, dd/mm/aaaa" e só "dd/mm/aaaa"; a emissão não bloqueia | Parcial: o caso com cidade/UF ("Ariquemes/RO, 26/09/2026.") apareceu no PDF de rascunho; o caso sem cidade/UF e a emissão sem endereço não foram testados |
| 16 | Cancelar | Cancelar um laudo emitido, com motivo | Ativos voltam ao status anterior; laudo "Cancelado"; ativos voltam aos candidatos | Parcial: motivo obrigatório, status, `cancel_reason`, `date_canceled` e restauração dos ativos verificados por script; a volta aos candidatos após cancelar não foi conferida |
| 17 | Cancelar com status mexido | Mudar o status de um ativo à mão e cancelar | Aviso para esse ativo; os demais são restaurados | OK (smoke, code-path): aviso "alterado à mão" no resultado e no evento `canceled` |
| 18 | PDF assinado | Anexar e depois substituir o arquivo | Status "Assinado"; os dois arquivos ficam em Documentos; "Baixar PDF assinado" abre o mais novo | Não executado: o caminho de sucesso usa `is_uploaded_file`/`move_uploaded_file` e só funciona numa requisição HTTP real; só as recusas (status errado, arquivo ausente, arquivo não enviado por upload) foram exercitadas |
| 19 | Envio ao patrimônio | Registrar data e "recebido por" | Status "No patrimônio"; já não dá para substituir o PDF assinado | Parcial (smoke, code-path): recusa sem PDF assinado e com datas inválidas, sucesso muda status, datas e evento, e o formulário de substituição some na tela; como o upload real não roda fora do HTTP, o PDF assinado dessa verificação não veio de um upload real (o relatório não diz como foi fornecido) |
| 20 | Baixa sem processo | Confirmar a baixa sem nº do processo | Aceita; status "Baixado"; ativos no status "baixado"; "Cancelar" some | OK (smoke, code-path): processo vazio aceito, ativos movidos pelo `LtbpGuard`, sem formulário de cancelar; anexos de comprovante da baixa (upload) não exercitados |
| 21 | Baixa: falha de status | Mapear um status de outra entidade e confirmar | Recusa por ativo; nada muda (transação) | Parcial: recusa com o status "baixado" sem mapeamento, com ativos intactos (smoke); o caso do status de outra entidade, por ativo, não foi testado |
| 22 | Bloqueio pela tela | Editar nome, status e série de um ativo baixado; editar só os comentários | Nome, status e série recusados com mensagem; comentários gravam | Parcial: o `->update()` recusou nome, status e série e aceitou só o comentário (smoke); a tela real de edição não foi usada. **Verificar no navegador os falsos positivos ao salvar só o comentário** (data vs data e hora, CRLF vs LF em textareas, booleanos, `entities_id`/`is_recursive` em formulário de transferência) |
| 23 | Direito de liberação | Repetir o 22 com o perfil "Editar ativo baixado" | A edição passa | OK (smoke, code-path): perfil com o direito altera o nome; pela tela não foi feito |
| 24 | Sem sessão | `php var/tools/lock_probe.php <Tipo> <id>` num ativo baixado | `LOCK HELD` | OK (sonda de CLI): `LOCK HELD` num ativo baixado e num de laudo marcado como baixado direto no banco; `LOCK DID NOT HOLD` num ativo livre (prova que a sonda enxerga o hook) |
| 25 | Ativo personalizado | Baixar e tentar editar um ativo personalizado | Bloqueado como os demais | OK (smoke, code-path): R-1 resolvido, ver o spec. Na inicialização do plugin `$CFG_GLPI['asset_types']` só tem os 10 tipos nativos e as definições dos ativos personalizados ainda não foram carregadas; `AssetUpdateGuard::itemtypes()` lê a tabela de definições direto. Hook registrado para 11 classes (inclui `Glpi\CustomAsset\nobreakAsset`); a atualização do nome de um ativo personalizado bloqueado é recusada, o comentário passa, e um ativo personalizado livre atualiza |
| 26 | Inventário real | Enviar um inventário de um equipamento baixado | Campos não mudam; sem erro fatal no log | **Não executado**: nenhum agente nem `cron.php` reais. Simulados só no nível do código: sessão de inventário, cron e `Session::callAsSystem()`, todos recusados sem o direito e liberados com ele (por isso a checagem lê `$_SESSION['glpiactiveprofile']`; ver a seção 8 da spec). R-4 continua aberto |
| 27 | Conclusão com comprovante | Concluir sem arquivo (opção ligada) e com arquivo | Sem arquivo o navegador barra; com arquivo conclui; comprovante em Documentos | Parcial: com a exigência ligada, o serviço recusa sem comprovante e a tela mostra "Comprovante (obrigatório)"; a barragem do navegador (`required`) e a conclusão com upload real não foram exercitadas |
| 28 | Conclusão opcional | Desligar a exigência e concluir sem arquivo | Conclui | OK (smoke, code-path): status Concluído, nome do beneficiário copiado, observação aparada, evento `completed`; a tela mostra "Comprovante (opcional)" |
| 29 | Beneficiário inativo | Forçar um fornecedor inativo | Recusa | OK (smoke, code-path): também recusados fornecedor 0 e inexistente |
| 30 | Acompanhamentos no ticket | Emitir, baixar e concluir um laudo com linhas vindas do PRE | Um acompanhamento por marco em cada ticket; o ticket continua Pendente com o mesmo motivo; ticket já fechado é ignorado | OK (smoke, code-path): um acompanhamento por marco e por ticket; um acompanhamento simples num ticket Pendente mantém status, motivo de pendência e `previous_status` (a suposição 4 do plano vale, `TicketOps::keepPendingWithReason` não foi necessário); ticket solucionado ignorado; falha no ticket vira aviso sem desfazer o avanço. Os dados foram montados por classe e por SQL, não pela tela do PRE |
| 31 | Solução do ticket | Ligar a opção e concluir; ticket com ativo em outro laudo aberto | Solucionado ao concluir; **não** solucionado enquanto houver linha aberta | OK (smoke, code-path): opção desligada só acompanha; ligada soluciona (inclusive ticket Pendente); não soluciona com outro laudo em rascunho nem com linha ativa de PRE, e soluciona depois que essas linhas se resolvem |
| 32 | Motivo padrão | Levar linha "Sem conserto" do PRE | Motivo já selecionado e editável | OK (smoke, code-path): motivo do L24 pré-selecionado pelo `LtbpLinker`; a edição na tela não foi feita |
| 33 | Botão em lote (laudo novo) | Marcar linhas no PRE, "Novo laudo…" | Cai no laudo novo com as linhas; PRE mostra o chip do laudo | Parcial: `LtbpLinker::addFromPre` cria o laudo com as linhas e a aba do PRE mostra selo, coluna de seleção, cartão do botão e o chip depois (smoke); o POST real em `ltbpitem.form.php` (redirect, mensagem) e o JS não foram exercitados |
| 34 | Botão em lote (laudo existente) | Escolher um rascunho existente | Linhas entram; sem seletor de destinação | Parcial: caminho do rascunho existente verificado no serviço (`created=false`, `openDrafts()` lista o rascunho, perfil só de leitura recusado); o esconder do seletor de destinação (JS) não foi visto |
| 35 | Selo volta | Excluir o rascunho / cancelar o laudo | Linhas do PRE voltam a "Aguardando laudo" | Não executado |
| 36 | Direitos | Perfil só de leitura; perfil sem ver laudos | Sem formulários de ação; PRE sem selo nem cartão | Parcial: na aba Itens do PRE, direito 0 não mostra selo, coluna nem cartão, e leitura mostra o selo sem cartão nem coluna (smoke); a aba Andamento com perfil de leitura não foi renderizada e nada foi conferido com um login real |
| 37 | Motivo usado | Tentar excluir um motivo em uso | Recusa; inativar funciona | Não executado |
| 38 | Excluir rascunho | Excluir um rascunho com linhas | Apaga o laudo e as linhas; ativos livres | Não executado |
| 39 | Reinstalar | Desinstalar e instalar o plugin | Sem erro; tabelas e direitos recriados | Parcial: desativar, desinstalar, instalar e ativar pelo console rodaram sem erro na Tarefa 5, com as 5 tabelas do LTBP e os direitos recriados; não foi repetido no fim, com o código completo |
| 40 | Regressão do PRE | Enviar um PRE, registrar retornos, baixar o PDF | Tudo como antes da extração para `Shared` | Não executado (a aba Itens do PRE renderiza em rascunho, enviado e vazio segundo o smoke da Tarefa 14, mas o envio, o retorno e o PDF reais não foram refeitos) |

## Não coberto por este roteiro

- Assinatura eletrônica no GLPI (fora da v1, spec L4).
- Reabertura de laudo depois da baixa (fora da v1, spec L10).
- Medição de desempenho da emissão com centenas de ativos (spec R-2): meça uma emissão de 200
  ativos e, se passar do aceitável, adote o modelo de uma linha por requisição do PRE (D15).
- O bloqueio de edição não cobre exclusão/purga do ativo, `updateInDB()`, `$DB->update` cru nas
  cascatas do núcleo (transferência, substituição em dropdown, tabelas de vínculo de Infocom e
  `Item_*`) nem SQL direto (spec, seção 8).
