
> Conteúdo-base para publicação no WikiJS. Onde estiver marcado `[GIF AQUI: ...]`, grave a tela correspondente e substitua a marcação pela incorporação do GIF.

O **PRE (Protocolo de Reparo de Equipamento)** é o módulo do plugin Gac usado para enviar equipamentos à assistência técnica de um fornecedor e acompanhar o retorno deles. Cada PRE reúne um ou mais itens (ticket + equipamento) enviados ao **mesmo fornecedor**, controla o status de cada item durante o ciclo (aguardando envio → na assistência → devolvido/extraviado) e gera o documento em PDF entregue ao fornecedor.

O plugin tem seu próprio menu no sidebar do GLPI, **Plugin - DTI GAC**, com uma entrada por módulo. O PRE é acessado pela entrada **PRE**; a configuração de todos os módulos do plugin fica na entrada **Configurações**, dentro desse mesmo menu.

Este manual segue a ordem recomendada de uso: primeiro a configuração do módulo (feita uma vez, normalmente pelo administrador), depois a criação de um protocolo, a importação e o envio dos itens, e por fim o registro e a correção do retorno.

> Sempre que este manual trouxer um aviso, uma restrição ou um comportamento que exige atenção, ele aparece formatado como esta caixa.

---

# Tela: Configuração do Módulo

Acessível pelo menu lateral **Plugin - DTI GAC > Configurações**. Essa tela é compartilhada por todos os módulos do plugin: cada módulo tem seu próprio bloco (cartão), com título, descrição e **botão Salvar independente** — salvar a seção do PRE não grava nem interfere na seção de outro módulo (por exemplo, o LTBP). A seção do PRE é **"Protocolo de Reparo de Equipamentos"** e só aparece para quem tem o direito "Configurar" do PRE.

> É recomendável preencher esta tela **antes** de criar o primeiro protocolo. O botão Enviar da tela do PRE fica bloqueado enquanto os mapeamentos obrigatórios (status do ativo e motivos de pendência) não estiverem completos.

## Recolher a seção

O cabeçalho do bloco tem o botão **Recolher**, que esconde o conteúdo da seção sem descartar alterações ainda não salvas (o botão Salvar fica dentro da área recolhível). A escolha de recolhido/expandido fica lembrada no navegador, por seção, e persiste entre acessos.

## Aviso de mapeamentos pendentes

Quando falta preencher **Status do ativo** ou **Motivos de pendência do ticket** (abaixo), a seção exibe, no topo, uma caixa de aviso listando exatamente o que falta, agrupado por bloco — por exemplo "Status do ativo: Ativo aguardando baixa" ou "Motivos de pendência do ticket: Aguardando decisão". Esse aviso só some quando todos os papéis obrigatórios tiverem um valor selecionado.

> `[GIF AQUI: abrir a configuração do PRE com mapeamentos pendentes e mostrar a caixa de aviso no topo da seção]`

## Bloco "Tickets elegíveis"

Define quais tickets podem ser importados para um PRE.

- **Categorias ITIL elegíveis**: campo de múltipla escolha (seleção de várias categorias de uma vez) com as categorias de ticket (`ITILCategory`) cadastradas no GLPI. Só tickets nessas categorias entram na lista de importação.
- **Incluir subcategorias**: alternância Sim/Não. Com **Sim** (padrão), um ticket em qualquer subcategoria de uma categoria marcada também é elegível — por exemplo, marcando "Hardware", um ticket em "Hardware > Notebook" também aparece. Com **Não**, só a categoria exata marcada conta.

> `[GIF AQUI: marcar duas ou três categorias ITIL como elegíveis e alternar "Incluir subcategorias"]`

## Bloco "Relatório"

Configura de onde vem a logomarca impressa no PDF do protocolo.

- **Categoria de documento da logomarca**: dropdown de categorias de documento (`DocumentCategory`). O sistema procura, na entidade do protocolo, o documento mais recente anexado nessa categoria e usa a imagem dele como logomarca. Não havendo documento na entidade, a busca sobe para a entidade pai (e assim por diante); se nenhuma entidade da cadeia tiver um documento nessa categoria, o PDF sai sem logomarca — sem erro.

> Os demais dados do cabeçalho do PDF (nome da empresa, CNPJ, endereço, CEP, cidade/UF e telefone) **não são configurados aqui**: eles vêm diretamente do cadastro da entidade do protocolo, em **Administração > Entidades**. Para corrigir esses dados no PDF, edite a entidade correspondente.

## Bloco "Status do ativo"

Mapeia três papéis fixos do fluxo do PRE para um status de ativo (`State`) já cadastrado no GLPI:

| Papel | Quando é aplicado ao ativo |
|---|---|
| Ativo na assistência | Ao enviar o item (fica com esse status enquanto estiver com o fornecedor) |
| Ativo com defeito | No retorno, quando o resultado é "Orçamento não aprovado" com destino "Manter com defeito" |
| Ativo aguardando baixa | No retorno, quando o resultado é "Sem conserto" com destino "Baixa" |

Cada linha é um campo dropdown de status. Ao lado do campo há um botão **+**, que abre um formulário rápido para **criar um novo status sem sair da tela de configuração**; ao salvar, o novo status já fica selecionado no campo.

> Nenhum status é criado automaticamente pelo sistema — a tabela de status do GLPI só recebe um novo valor se você usar esse botão **+** deliberadamente. Recomenda-se criar os status usados pelo PRE na **entidade raiz**, com a opção de recursividade **ligada**, para que valham em todas as subentidades onde os equipamentos podem estar.

> `[GIF AQUI: usar o botão "+" ao lado de um campo de status para criar um novo status sem sair da tela de configuração]`

## Bloco "Motivos de pendência do ticket"

Mapeia três papéis para um motivo de pendência (`PendingReason`) já cadastrado no GLPI — o motivo exibido quando o ticket é colocado como Pendente:

| Papel | Quando é aplicado ao ticket |
|---|---|
| Ticket na assistência | Ao enviar o item |
| Aguardando decisão | No retorno, resultado "Orçamento não aprovado" |
| Aguardando baixa patrimonial | No retorno, resultado "Sem conserto" com destino "Baixa" |

Assim como no bloco de status, cada campo tem o botão **+** para **criar um novo motivo de pendência na hora**, sem sair da tela e sem passar pelo cadastro padrão de motivos de pendência do GLPI.

> Da mesma forma que os status, nenhum motivo de pendência é criado sozinho: só existe se for criado deliberadamente pelo botão **+**. Enquanto um papel obrigatório não tiver um motivo definido, ele aparece na caixa de aviso do topo da seção.

> `[GIF AQUI: usar o botão "+" ao lado de um campo de motivo de pendência para cadastrar um novo motivo]`

## Bloco "Ações no retorno"

Tabela que define o que acontece com o **ticket** e com o **ativo** quando um retorno é registrado, uma linha por combinação de resultado/destino (Reparado, Sem defeito encontrado, Com defeito → baixa, Com defeito → manter). Para cada linha:

- **Ticket**: escolha entre **Reabrir** (volta para Em atendimento), **Solucionar** ou **Manter pendente**. Escolhendo "Manter pendente", uma segunda coluna, **Motivo**, fica disponível para escolher qual dos papéis de motivo de pendência (do bloco anterior) será usado.
- **Ativo**: escolha entre **Restaurar status anterior** (o status que o equipamento tinha antes do envio) ou **Definir status**. Escolhendo "Definir status", a coluna **Status** fica disponível para escolher qual dos papéis de status do ativo (do bloco anterior) será aplicado.

Os valores de fábrica são conservadores: nenhuma combinação soluciona o ticket automaticamente.

> Alterar "Reabrir" para "Solucionar" em qualquer linha faz o ticket ser encerrado no mesmo instante em que o retorno é registrado, sem revisão manual do equipamento testado. Essa configurabilidade é proposital, mas o risco de solucionar um ticket sem confirmar o reparo é do responsável pela configuração.

> `[GIF AQUI: percorrer a tabela "Ações no retorno", trocando a ação de ticket de uma linha de "Reabrir" para "Manter pendente" e escolhendo o motivo]`

## O botão Salvar

Cada seção de módulo (PRE, LTBP etc.) tem o seu próprio botão **Salvar**, no rodapé do cartão, dentro da área recolhível. Ele só envia e grava os campos **daquela seção** — nenhum outro módulo é afetado. Depois de salvar, a página recarrega e mostra a mensagem de confirmação "Configuração do PRE salva.".

> `[GIF AQUI: alterar um mapeamento de status, clicar em Salvar e mostrar a mensagem de confirmação]`

---

# Tela: Lista de Protocolos

Acessível pelo menu lateral **Plugin - DTI GAC > PRE**. Lista todos os PREs da entidade atual (e subentidades, conforme a busca nativa do GLPI), usando o mecanismo de busca padrão do GLPI: colunas configuráveis, filtros salvos, exportação.

## Funcionalidades desta tela

- **Buscar/filtrar protocolos** pelos campos padrão da busca do GLPI (número, status, fornecedor, técnico responsável, datas).
- **Abrir um protocolo** existente clicando na linha.
- **Criar um novo protocolo** pelo botão de adicionar (canto superior direito, padrão GLPI). Só aparece para quem tem direito de criação no PRE.

> `[GIF AQUI: abrir a lista de protocolos pelo menu, mostrar a busca/filtro e clicar em "Novo"]`

---

# Tela: Cabeçalho do Protocolo (Criação do PRE)

É a aba principal do PRE, com os dados gerais do documento. Alguns campos só podem ser editados enquanto o protocolo está em **Rascunho**; depois do envio, o cabeçalho vira somente leitura (exceto os comentários, dependendo do direito do usuário).

## Campos do cabeçalho

- **Número**: mostra `(gerado ao salvar)` num protocolo novo. Depois do primeiro Salvar, exibe o número definitivo, no formato `PRE-AAAA-NNN` — uma sequência anual, global ao GLPI (não por entidade), que nunca é reaproveitada mesmo se um protocolo for excluído. Campo somente leitura.
- **Status**: somente leitura — calculado a partir da situação dos itens, nunca digitado (ver [Ciclo de vida do protocolo](#ciclo-de-vida-do-protocolo-status)).
- **Fornecedor**: dropdown obrigatório de `Supplier`. Lista os fornecedores **ativos** da entidade do protocolo, das suas subentidades, e os fornecedores marcados como recursivos em entidades pai.
  > Um fornecedor cadastrado sem marcar "ativo" não aparece neste campo — o GLPI só lista fornecedores ativos em qualquer dropdown do sistema.
- **Técnico responsável**: dropdown de usuários. Vem preenchido, por padrão, com o usuário logado, mas pode ser trocado por qualquer outro usuário com direito no GLPI.
- **Data de emissão**: seletor de data (calendário nativo do GLPI); vem preenchida com a data atual.
- **Comentários**: campo de texto livre, de uso interno — não é impresso no PDF.

## Cancelar o protocolo

Um protocolo em **Rascunho** exibe o botão **Cancelar PRE**, ao lado de Salvar (disponível para quem tem o direito de editar o protocolo — ver [Permissões](#permissões)).

> Cancelar um protocolo **remove todos os itens já importados** nele. Os pares ticket + equipamento voltam a ficar disponíveis para importação em outro PRE. A ação pede confirmação antes de executar, pois não pode ser desfeita.

> `[GIF AQUI: preencher fornecedor e técnico, salvar o protocolo, mostrar o número gerado e o botão "Cancelar PRE"]`

## Ciclo de vida do protocolo (status)

O status do protocolo nunca é escolhido diretamente — ele é calculado a partir da situação dos itens:

| Status | Quando ocorre |
|---|---|
| Rascunho | Antes do envio |
| Enviado | Envio iniciado, nenhum item devolvido ainda |
| Retorno parcial | Pelo menos um item devolvido/extraviado, mas nem todos |
| Encerrado | Todos os itens em situação final (devolvido ou extraviado) — acontece sozinho, sem botão |

---

# Tela: Itens do Protocolo — Importação e Envio

Segunda aba do PRE, onde o equipamento é montado, enviado e acompanhado até o retorno. O conteúdo muda conforme o status do protocolo (rascunho, enviado, com retorno parcial, encerrado).

No topo desta aba fica a barra de ações do protocolo, com o botão **Enviar** (ver [Enviando o protocolo](#enviando-o-protocolo)) e, assim que houver ao menos um item, o botão **Pré-visualizar PDF** — gera a qualquer momento, em Rascunho, uma prévia do documento com marca d'água "RASCUNHO", útil para conferir o conteúdo antes de importar mais itens ou enviar. Detalhes completos em [Relatório em PDF](#relatório-em-pdf).

## Importar tickets (somente em Rascunho)

Um cartão **Importar tickets** lista os tickets elegíveis: abertos, em uma categoria configurada como elegível (bloco [Tickets elegíveis](#bloco-tickets-elegíveis)), com pelo menos um equipamento vinculado, e cujo par ticket + equipamento ainda não esteja em outro protocolo ativo. Um ticket com vários equipamentos aparece uma vez por equipamento, cada linha com uma caixa de seleção própria.

Colunas da lista de candidatos: **Ticket** (número), **Título**, **Equipamento** (tipo e nome) e **Patrimônio**.

- Marque os itens desejados (checkbox individual, ou "marcar todos" na caixa do cabeçalho da tabela — que também reflete um estado parcial quando só alguns itens estão marcados) e confirme em **Importar selecionados**.
- Com muitos candidatos, o botão **Recolher itens para importar** esconde essa lista, deixando à vista só os itens já importados — útil para revisar descrições sem rolar a página. A escolha fica lembrada no navegador, por protocolo, e sobrevive a recarregar a página. O botão só aparece quando existem candidatos.

> Só entram na lista itens que são **equipamentos** de verdade (usando a mesma lista de tipos de ativo do GLPI). Um ticket aberto por um formulário do GLPI, por exemplo, pode trazer junto um item de outro tipo (como uma resposta de formulário) — esse item nunca aparece para importação.

> `[GIF AQUI: abrir um rascunho com tickets elegíveis, marcar alguns, importar, e usar "Recolher itens para importar"]`

## Itens do protocolo (lista principal)

A tabela mostra, por item: **Ticket** (com link), **Tipo**, **Equipamento** (nome), **Patrimônio**, **Nº de série**, **Descrição para o fornecedor**, **Situação** e **Ações**.

- **Descrição para o fornecedor**: em Rascunho é um campo de texto editável por item — é o texto que sai impresso no PDF, não a descrição interna do ticket. Ela começa preenchida com o campo de informações adicionais do ticket (ou o título do ticket, se não houver esse campo preenchido). Depois de editar quantos itens quiser, use o botão **Salvar descrições** no rodapé da tabela para gravar todas de uma vez.
  > Revise sempre esse texto antes do envio: ele sai impresso no documento entregue ao fornecedor, então não deve conter observações internas da equipe.
- **Remover**: disponível só em Rascunho, remove o item do protocolo (o par ticket + equipamento volta a ficar disponível para importação).
- **Situação**: um badge colorido — Aguardando envio (cinza), Enviando (azul), Na assistência (amarelo), Devolvida (verde) ou Extraviada (vermelho) — com o resultado/destino escrito abaixo assim que houver retorno registrado.
- Com muitos itens e cartões de retorno abertos na mesma tela, o botão **Recolher lista de itens** esconde a tabela inteira, para não empurrar os formulários de retorno para baixo. Só aparece quando existe pelo menos um cartão de retorno ou de correção visível na página, e a escolha também fica lembrada no navegador por protocolo.

> `[GIF AQUI: editar a descrição para o fornecedor de um item e salvar]`

## Enviando o protocolo

O botão **Enviar** aparece no topo da aba Itens assim que o protocolo tem ao menos um item (só para quem tem o direito "Enviar" do PRE).

**Pré-checagens.** Antes de alterar qualquer coisa, o sistema confirma, para o protocolo inteiro:

- há um fornecedor definido;
- existe pelo menos um item;
- o mapeamento de status do ativo (bloco [Status do ativo](#bloco-status-do-ativo)) está completo e cada status mapeado é válido para a entidade de cada equipamento do protocolo;
- cada ticket dos itens ainda está aberto;
- cada par ticket + equipamento ainda está livre (não entrou em outro protocolo entre a importação e o envio).

> Se qualquer uma dessas condições falhar, o envio inteiro é recusado, informando qual condição falhou e em qual item/ticket — e **nada é alterado** no protocolo, no ticket ou no ativo.

**Envio com barra de progresso.** O envio processa **um item por vez**, mostrando uma barra de progresso no navegador. Por item, o sistema registra um acompanhamento no ticket, coloca o ticket como Pendente com o motivo configurado, guarda o status atual do equipamento (para poder restaurar depois) e aplica o novo status de "na assistência".

- Se a aba do navegador for fechada no meio do processo, os itens já enviados permanecem enviados; reabrir o protocolo mostra o botão **Continuar envio**, que retoma só os itens ainda pendentes.
- Um item que falhar (por exemplo, por indisponibilidade momentânea) volta ao status "aguardando envio" com o erro registrado ao lado dele na tabela, sem travar o processamento dos demais itens.
- Um item que falha repetidamente pode ser retirado do envio com **Remover linha com falha**, informando um motivo (obrigatório) — o restante do protocolo segue normalmente. Se essa for a última linha ativa do protocolo, ele é cancelado.

**Concluir envio (gerar o documento).** Quando todos os itens estiverem "na assistência", o botão muda para **Concluir envio (gerar documento)**: essa etapa gera o PDF definitivo e o anexa ao protocolo. Esse é o único momento em que o PDF de envio é criado — downloads posteriores sempre entregam o mesmo arquivo, nunca uma nova versão. Veja mais em [Relatório em PDF](#relatório-em-pdf).

> `[GIF AQUI: clicar em "Enviar", acompanhar a barra de progresso item a item até "Concluir envio (gerar documento)"]`

> `[GIF AQUI: simular uma falha em um item, mostrar "Continuar envio" e, se necessário, "Remover linha com falha"]`

---

# Registrando o Retorno dos Itens

Enquanto o protocolo estiver Enviado ou em Retorno parcial, cada item "na assistência" ganha um cartão **Registrar retorno** na aba Itens (só para quem tem o direito "Registrar retorno" do PRE). O cabeçalho do cartão identifica o item (número do ticket e nome do equipamento).

## Formulário de retorno

- **Resultado** (obrigatório): dropdown com quatro opções —
  - **Reparado**: o equipamento voltou consertado.
  - **Sem defeito encontrado**: voltou sem reparo porque a assistência não encontrou defeito.
  - **Sem conserto**: irrecuperável, com defeito.
  - **Orçamento não aprovado**: não foi reparado por recusa do orçamento, com defeito.
- **Destino**: só aparece (o campo é revelado dinamicamente) quando o resultado escolhido é um dos dois "com defeito". Vem pré-selecionado conforme o resultado — **Sem conserto** sugere **Baixa**; **Orçamento não aprovado** sugere **Manter com defeito** — mas pode ser trocado antes de salvar.
  > Um item com destino Baixa pode depois ser levado a um laudo de baixa patrimonial (módulo LTBP, com manual próprio).
- **Data do retorno**: seletor de data; vem preenchida com a data atual.
- **Serviço executado**: campo de texto livre, descrevendo o que a assistência fez.
- **Custo (R$)**: valor gasto com o reparo. Ao salvar, esse valor também gera um lançamento na aba Custos do ticket, nomeado `Fornecedor - Nº OS/NF - Ativo` (partes vazias são omitidas do nome).
- **Nº da OS ou nota do fornecedor**: campo de texto livre, referência do documento do fornecedor.
- **Garantia até**: data opcional; até quando o reparo tem garantia.
- **Documentos**: campo de upload que aceita vários arquivos de uma vez (opcional) — laudo do fornecedor, nota fiscal etc. Cada um vira um Documento do GLPI, vinculado ao mesmo tempo ao ticket da linha e ao próprio protocolo.
  > Um arquivo de um tipo não permitido pelas regras de upload do GLPI (por exemplo, um `.exe`) é recusado com um aviso, mas isso **não impede** o restante do retorno de ser registrado — só aquele arquivo específico fica de fora.

Ao clicar em **Registrar retorno**, o sistema aplica automaticamente a ação configurada sobre o ticket e sobre o equipamento para aquele resultado/destino (ver [Ações no retorno](#bloco-ações-no-retorno)), grava o custo na aba Custos do ticket e muda a situação do item para "Devolvida".

> O formulário é enviado sem recarregar a página inteira: só a lista de itens é atualizada, a posição da rolagem é mantida e o resultado (sucesso ou erro) aparece em um aviso flutuante. Isso evita perder a posição em protocolos com muitos itens.

## Marcar como extraviada

Abaixo do formulário de retorno, cada item também tem a opção **Marcar como extraviada**, com um campo de justificativa **obrigatório**. Um item extraviado conta como situação final, sem passar por resultado ou destino.

> `[GIF AQUI: registrar um retorno "Reparado" com custo e um anexo, mostrando o aviso flutuante de sucesso]`

> `[GIF AQUI: marcar um item como extraviado, preenchendo a justificativa]`

---

# Reabrindo e Corrigindo um Retorno

Quando todos os itens chegam a uma situação final, o protocolo passa para **Encerrado** automaticamente e se torna somente leitura, exceto pela reabertura (exige o direito "Reabrir" do PRE).

## Motivo da reabertura

O botão **Reabrir**, no topo da aba Itens de um protocolo Encerrado, abre um campo de texto para o **motivo da reabertura**, obrigatório — ele fica registrado na aba nativa Histórico do protocolo.

## Corrigindo dados de um retorno

Com o protocolo reaberto, cada item devolvido ganha um cartão **Corrigir retorno**, com os mesmos campos de serviço e custo do formulário original: **Data do retorno**, **Serviço executado**, **Custo (R$)**, **Nº da OS ou nota do fornecedor**, **Garantia até** e **Documentos** (novos anexos, adicionados aos já existentes). Ao salvar, o sistema também registra um acompanhamento no ticket da linha, resumindo os dados corrigidos (data, serviço, custo, OS/nota e garantia).

> O resultado e o destino **não podem ser alterados por este formulário** — a correção existe só para os dados operacionais do retorno. Se o resultado registrado estiver errado, a correção do ticket e do equipamento deve ser feita manualmente, fora do PRE. Corrigir o custo aqui **atualiza** o mesmo lançamento já existente na aba Custos do ticket, em vez de criar um segundo, e o acompanhamento de correção é sempre adicionado, mesmo sem alteração no custo.

## Concluindo as correções

Depois de ajustar o que for necessário em um ou mais itens, o botão **Concluir correções** (no topo da aba Itens) devolve o protocolo ao status Encerrado.

> `[GIF AQUI: reabrir um protocolo encerrado informando o motivo, corrigir o custo de um item e clicar em "Concluir correções"]`

---

# Relatório em PDF

O protocolo tem um botão de PDF no topo da aba Itens, com comportamento diferente conforme o status:

- **Em Rascunho**: **Pré-visualizar PDF** gera uma prévia a cada clique, com marca d'água "RASCUNHO" — útil para revisar antes de enviar. Essa prévia pode ser gerada quantas vezes forem necessárias e nunca é salva.
- **Depois do envio**: **Baixar PDF de envio** sempre entrega o **mesmo arquivo** gerado no momento de "Concluir envio", nunca uma nova renderização — é literalmente o documento que o fornecedor recebeu, mesmo que dados do item ou da entidade mudem depois.

O documento traz o cabeçalho com os dados da entidade (nome, CNPJ, endereço, telefone) e a logomarca configurada, o fornecedor, a data, o técnico, o número do PRE, a tabela de itens (ticket, tipo, equipamento, patrimônio, série, descrição para o fornecedor), o total, e os campos de assinatura do fornecedor e do técnico.

> `[GIF AQUI: abrir a pré-visualização do PDF em um rascunho e, depois do envio, baixar o PDF de envio definitivo]`

---

# Histórico do Protocolo

Os eventos do PRE (criação, envio, retorno de cada item, remoção de item com falha, extravio, encerramento, reabertura, correções) aparecem na aba nativa **Histórico** do GLPI, junto com as demais alterações de campos do protocolo — não existe uma aba própria separada para isso.

> `[GIF AQUI: abrir a aba Histórico de um protocolo com vários eventos registrados]`

---

# Permissões

O PRE tem uma linha própria na aba de perfil **Plugin - DTI GAC**, com as permissões básicas de um cadastro do GLPI mais quatro permissões específicas do módulo. Um perfil pode combinar qualquer conjunto delas — por exemplo, um técnico pode ter só Ver e Registrar retorno, sem poder enviar nem configurar.

**Permissões básicas** (comuns a qualquer item do GLPI):

- **Ver**: abrir a lista de protocolos e o cabeçalho/itens de um PRE. Sem essa permissão, a entrada **PRE** nem aparece no menu do plugin.
- **Criar**: usar o botão **Novo** na lista de protocolos, para começar um PRE em Rascunho.
- **Editar**: alterar o cabeçalho (fornecedor, técnico responsável, data de emissão, comentários) e a lista de itens (importar tickets, editar a descrição para o fornecedor, remover item) — só funciona enquanto o protocolo está em **Rascunho**. Também é essa permissão que libera o botão **Cancelar PRE**.
- **Excluir**: apagar definitivamente um protocolo. Só é possível em **Rascunho** ou **Cancelado** — um protocolo que já foi enviado não pode ser excluído, apenas cancelado antes do envio.

**Permissões específicas do PRE:**

- **Enviar**: libera o botão **Enviar**, e depois **Continuar envio** e **Concluir envio (gerar documento)**, na aba Itens. Sem essa permissão, o técnico pode montar um protocolo em Rascunho, mas não consegue despachá-lo ao fornecedor.
- **Registrar retorno**: libera, para cada item "na assistência", os cartões **Registrar retorno** e a opção **Marcar como extraviada**. É a permissão de quem lança o resultado que volta da assistência técnica.
- **Reabrir**: libera o botão **Reabrir** num protocolo Encerrado, o cartão **Corrigir retorno** de cada item e o botão **Concluir correções**. Por ser uma exceção ao "documento fechado", costuma ficar restrita a poucas pessoas (ex.: supervisão).
- **Configurar** (`RIGHT_CONFIG`): libera a seção **"Protocolo de Reparo de Equipamentos"** na tela de configuração do plugin (categorias elegíveis, mapeamento de status e motivos, ações no retorno, logomarca). Não dá acesso a nenhuma ação sobre os protocolos em si — só à configuração do módulo.

> Um usuário sem uma dessas permissões não vê o botão ou o cartão correspondente na tela, e a ação é recusada mesmo se tentada diretamente (por exemplo, forjando a requisição).
