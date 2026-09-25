# PRE: roteiro de teste manual

Roteiro dos fluxos do PRE que dependem do GLPI (não há PHPUnit do GLPI neste repositório; só as
classes puras têm teste automatizado, em `tests/Unit/`). Cada linha diz como reproduzir e o que
esperar. A coluna **Resultado** registra a execução feita durante a implementação, no GLPI 11.0.8
de desenvolvimento (25/09/2026).

Legenda: **OK** verificado no GLPI · **Parcial** verificado em parte (ver nota) · **Não executado**
com o motivo.

## Pré-requisitos

- GLPI local com o plugin instalado e ativo, e a configuração do Plugin - DTI GAC completa (categorias, status
  do ativo, motivos de pendência; ver a seção do PRE em Configurar > Plugin - DTI GAC).
- Um fornecedor **ativo** (o dropdown de fornecedores do GLPI só lista os ativos; um fornecedor criado sem marcar "ativo" não aparece na lista do PRE nem em nenhum outro formulário), tickets na categoria elegível com um ativo associado, e um usuário com todos os
  direitos do PRE. Um segundo usuário sem nenhum direito é necessário para o cenário 22.
- Depois de editar um template Twig, `php bin/console cache:clear`. Depois de editar `pre.js`,
  forçar a atualização do arquivo em cache no navegador.

## Roteiro

| # | Cenário | Como | Esperado | Resultado |
|---|---|---|---|---|
| 1 | Numeração | Criar 3 PREs | `PRE-AAAA-001`, `-002`, `-003`; número nunca reaproveitado ao apagar | OK |
| 2 | Elegibilidade | Ticket fora da categoria; fechado; sem ativo; de outra entidade | Nenhum aparece em "Importar tickets" | OK |
| 3 | Subcategoria | Ticket em subcategoria de uma categoria marcada | Aparece; com "Incluir subcategorias" = Não, não aparece | OK |
| 4 | Subentidade | PRE na entidade pai, ticket na subentidade | Aparece; um PRE na subentidade só vê tickets dela | OK |
| 5 | Vários ativos | Ticket com 2 ativos | 2 opções; importar só 1 é possível | OK |
| 6 | Exclusividade | Mesmo par ticket+ativo em outro PRE | Não aparece enquanto a linha estiver ativa; cancelar o rascunho libera | OK |
| 7 | Descrição | Ticket com "Informações adicionais" | Descrição inicial igual ao texto; sem o bloco, igual ao título | OK |
| 8 | Envio feliz | Enviar linhas | PRE `Enviado`, linhas `Na assistência`, ticket Pendente com motivo, ativo com status "na assistência", PDF anexado | OK |
| 9 | Pré-checagem | Desmapear o status ou mapear um motivo de outra entidade e enviar | Recusa citando o papel/ticket; **nada** alterado | OK |
| 10 | Falha de linha | Forçar erro em 1 linha | Linha `Aguardando envio` com erro; "Continuar envio" reprocessa só ela | Parcial: testado pelo endpoint, não pela barra de progresso |
| 11 | Linha com falha | "Remover linha com falha" sem e com motivo | Exige motivo; grava evento; última linha removida cancela o PRE | OK |
| 12 | Clique duplo | Dois envios simultâneos da mesma linha | Um acompanhamento só | OK (duas chamadas concorrentes) |
| 13 | Reparado | Retorno "Reparado" com custo | Ticket reaberto; ativo restaurado; custo no ticket | OK |
| 14 | Sem defeito | Retorno "Sem defeito encontrado" | Ticket reaberto; ativo restaurado | OK |
| 15 | Baixa | "Sem conserto" + destino Baixa | Ticket pendente "Aguardando baixa patrimonial"; ativo "aguardando baixa" | OK |
| 16 | Manter | "Orçamento não aprovado" + Manter com defeito | Ticket pendente "Aguardando decisão"; ativo "com defeito" | OK |
| 17 | Extravio | "Marcar como extraviada" | Exige justificativa; conta como final | OK |
| 18 | Encerramento | Última linha em estado final | PRE `Encerrado` sozinho, `date_closed` preenchida | OK |
| 19 | Status anterior | Tirar do Pendente um ticket de "Baixa" ou "Manter" | Volta ao status que tinha antes do envio | Parcial: confirmado o dado (`previous_status` original guardado), não a ação manual na tela |
| 20 | Reabertura | Reabrir com motivo; corrigir custo e nº da OS; concluir | Motivo no histórico; custo do ticket atualizado e renomeado; `Encerrado` só após "Concluir correções" | OK |
| 21 | Sem reabertura | Corrigir linha de PRE não reaberto (POST forjado) | Recusado | OK |
| 22 | Direitos | Usuário sem "Enviar", "Registrar retorno" ou "Reabrir" | Botões ausentes e endpoints recusam | Não executado: falta um segundo usuário de teste |
| 23 | Cancelar | Botão "Cancelar PRE" na aba principal, ao lado de "Salvar" (só em rascunho, com confirmação) | Linhas apagadas; pares voltam a ser candidatos | OK |
| 24 | Configuração | Salvar; criar status e motivos pelo botão + | Persiste; nada é criado sem a ação do administrador | OK |
| 25 | PDF | Prévia, definitivo, logo da entidade, herança, 50 linhas | Ver a nota abaixo | OK |
| 26 | Ticket com dois ativos | Devolver uma linha enquanto a outra está fora | Ticket mantém status e motivo, só acompanhamento; a última linha aplica a ação | OK |
| 27 | Nome do custo | Retorno com custo e nº da OS | Custo do ticket chamado `Fornecedor - OS - Ativo` | OK |
| 28 | Ativo customizado | Ticket ligado a um ativo de tipo definido (Nobreak) | Importa, envia, devolve (reparado e baixa) e fecha como qualquer ativo | OK |
| 29 | Documentos no retorno e na correção | Registrar retorno anexando 2 arquivos permitidos e 1 recusado pelo GLPI (`.exe`); repetir na correção de um PRE reaberto | Os 2 viram Documentos ligados ao ticket e ao PRE; o recusado gera aviso e o retorno continua registrado; evento `Documento anexado ao retorno` no histórico | OK (via POST multipart; a mensagem de aviso do arquivo recusado não foi conferida na tela) |
| 30 | Sem recarregar | Num PRE com 18 linhas na assistência, rolar até a 9ª, registrar o retorno (com arquivo), errar um retorno sem resultado | A página não recarrega, o scroll fica igual, o cartão da linha some, aviso flutuante de sucesso/erro; o botão volta a ficar ativo depois de um erro | OK |
| 31 | Recolher a lista | Com cartões de retorno visíveis, recolher a lista de itens, registrar um retorno e recarregar a página | A lista continua recolhida nos dois casos; "Expandir" a mostra de novo; sem cartões de retorno o botão não aparece | OK (o botão ausente não foi conferido na tela) |
| 32 | Marcar todos na importação | Num rascunho com tickets elegíveis, usar a caixa do cabeçalho da lista "Importar tickets" | Marca e desmarca todos; marcar só alguns deixa a caixa do cabeçalho em estado parcial; marcar todos um a um marca a caixa do cabeçalho | OK (13 candidatos; a importação em si não foi refeita) |
| 33 | Histórico único | Abrir um PRE com eventos; reabrir com motivo e concluir as correções | Só existe uma aba "Histórico"; cada evento aparece com Campo "Evento" e o texto (ex. "PRE reaberto: motivo", "Retorno registrado (#71 · NB-1): Reparado"); eventos antigos também aparecem | OK |
| 34 | PDF sem logomarca na cadeia | Categoria de logomarca configurada, mas nenhuma logomarca na entidade nem nas ancestrais; entidade raiz com `entities_id` NULL (como em bancos migrados de versões antigas) | O PDF sai sem logo em menos de 1 s, sem ficar em laço | OK (reproduzido no banco de produção pelo slowlog do PHP-FPM; verificado depois da correção) |
| 35 | Tamanho da logomarca no PDF | Logomarca grande (imagem quadrada) na entidade raiz; abrir a prévia do PDF | A logo cabe em 60 x 14 mm, sem abrir espaço no cabeçalho, ao lado dos dados da empresa | OK |
| 36 | Título e rodapé do PDF | Abrir a prévia do PDF de um rascunho | Sem o número do protocolo abaixo do título (só na tabela); espaço entre título e tabela; rodapé com a URL da aplicação do GLPI à esquerda e "Página x / y" à direita | OK |

**Nota do cenário 25 (PDF).** Prévia em rascunho com marca d'água "RASCUNHO"; cabeçalho com nome,
CNPJ, endereço e telefone da entidade; logo do documento mais recente da categoria configurada,
herdada da entidade pai quando a subentidade não tem; data no formato `dd/mm/aaaa`; PDF definitivo
gerado no envio e anexado ao PRE; o download entrega o **arquivo guardado** (idêntico byte a byte
depois de alterar a linha e o endereço da entidade); PRE de 50 linhas gera 3 páginas com o
cabeçalho da tabela repetido e as assinaturas na última. O visualizador de PDF do Chrome demora
alguns segundos para desenhar a página; uma tela em branco logo ao abrir não é erro.

## Medição de desempenho do "Enviar"

Medido no navegador com `fetch` cronometrado, chamando os mesmos endpoints que a barra de
progresso chama, no GLPI de desenvolvimento (XAMPP local; PHP 8.2; `max_execution_time` de 30 s).
Uma requisição por linha (D15).

| Etapa | 20 linhas | 50 linhas |
|---|---|---|
| Pré-checagens (`start`) | 534 ms | não capturado (estimativa: ~1 s) |
| Envio de cada linha (`line`) | média 806 ms; mediana 805; mín. 713; máx. 901 | não capturado por linha |
| PDF final (`finalize`) | 977 ms | 986 ms (PDF de 3 páginas, 85 KB) |
| Total pela barra de progresso | 17,6 s, 0 falhas | estimativa: ~43 s |
| Importar as linhas | não medido | 942 ms para 50 |
| Aba Itens renderizada | não medido | 722 ms com 50 linhas |

Leitura:

- **Nenhuma requisição passa de ~1 s**, muito abaixo dos 30 s do PHP. O desenho de uma linha por
  requisição elimina o risco de timeout e de sessão presa que motivou o D15.
- O custo de uma linha (~0,8 s) é do próprio GLPI (escrever no ticket, no ativo e enfileirar
  notificações). Para comparação, criar um ticket pelo formulário do GLPI levou ~0,7 s neste mesmo
  ambiente.
- **Limite conhecido:** as pré-checagens do `start` rodam numa única requisição e crescem com o
  número de linhas. Com dezenas de linhas ficam em ~1 s; um PRE com centenas de linhas pode
  aproximar essa requisição do limite do PHP. Se isso virar necessidade real, a pré-checagem passa
  a ser feita em lotes, no mesmo estilo do envio.
- Os números valem para este ambiente local. Um servidor de produção, com outro disco, outro PHP e
  notificações síncronas ligadas, pode ser mais lento; se uma linha passar de ~3 s, investigar
  (provável causa: notificações do ticket enviadas na hora em vez de enfileiradas).

## Não coberto por este roteiro

- Usuário sem os direitos do PRE (cenário 22).
- Falha no meio da transação de uma linha, para observar o desfazer (foram testadas as validações
  antes da transação e a falha de uma linha do envio, não uma exceção dentro do retorno ou da
  correção).
- PRE com centenas de linhas.
- Falha do mPDF na geração do PDF final (código captura o erro e devolve "Tente Concluir envio
  novamente", mas o cenário não foi provocado).
- Logo em formato não reconhecido ou acima de 2 MB (o código ignora e segue sem logo).
