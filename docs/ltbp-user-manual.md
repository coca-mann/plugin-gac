
> Conteúdo-base para publicação no WikiJS. Onde estiver marcado `[GIF AQUI: ...]`, grave a tela correspondente e substitua a marcação pela incorporação do GIF.

O **LTBP (Laudo Técnico de Baixa Patrimonial)** é o módulo do plugin Gac usado para preparar equipamentos para a baixa patrimonial e para a destinação final (descarte ecológico ou doação). O fluxo físico é: a TI emite o laudo, os dois diretores assinam em papel, o documento vai ao setor de patrimônio (que faz a baixa no sistema dele) e, por fim, a TI executa o descarte ou a doação. Cada laudo tem **uma destinação só** (descarte ou doação vale para o laudo inteiro) e reúne um ou mais ativos.

O plugin tem seu próprio menu no sidebar do GLPI, **Plugin - DTI GAC**, com uma entrada por módulo. O LTBP é acessado pela entrada **Laudos Técnicos de Baixa Patrimonial**; a configuração de todos os módulos do plugin fica na entrada **Configurações**, dentro desse mesmo menu.

Este manual segue a ordem recomendada de uso: primeiro a configuração do módulo e o cadastro de motivos (feitos uma vez, normalmente pelo administrador), depois a criação de um laudo, a escolha dos ativos, o andamento até a conclusão, e por fim o relatório, o histórico e as permissões.

> Sempre que este manual trouxer um aviso, uma restrição ou um comportamento que exige atenção, ele aparece formatado como esta caixa.

---

# Tela: Configuração do Módulo

Acessível pelo menu lateral **Plugin - DTI GAC > Configurações**. Assim como no PRE, essa tela é compartilhada por todos os módulos do plugin: cada um tem seu próprio bloco (cartão), com título, descrição e **botão Salvar independente** — salvar a seção do LTBP não grava nem interfere na seção de outro módulo. A seção do LTBP é **"Laudo Técnico de Baixa Patrimonial"** e só aparece para quem tem o direito "Configurar" do LTBP.

> É recomendável preencher esta tela **antes** de criar o primeiro laudo. A emissão fica bloqueada enquanto os três status do ativo e os dados dos dois diretores não estiverem completos.

## Aviso de mapeamentos pendentes

Quando falta preencher o bloco **Status do ativo** ou o bloco **Assinaturas do laudo** (abaixo), a seção exibe, no topo, uma ou duas caixas de aviso dizendo exatamente o que falta — por exemplo "Status do ativo: Ativo baixado" ou o aviso de que faltam nome e cargo dos diretores. Esses avisos só somem quando os dados obrigatórios estiverem preenchidos.

> `[GIF AQUI: abrir a configuração do LTBP com mapeamentos pendentes e mostrar as caixas de aviso no topo da seção]`

## Bloco "Status do ativo"

Mapeia três papéis fixos do fluxo do LTBP para um status de ativo (`State`) já cadastrado no GLPI:

| Papel | Quando é aplicado ao ativo |
|---|---|
| Ativo aguardando baixa | Status que o ativo precisa ter para entrar na lista de candidatos de um laudo (o mesmo usado pelo PRE quando o retorno de um item tem destino Baixa) |
| Ativo em processo de baixa | Aplicado na emissão do laudo, enquanto se aguarda a assinatura e a confirmação do patrimônio |
| Ativo baixado | Aplicado quando o patrimônio confirma a baixa; a partir daqui o ativo fica bloqueado para edição |

Cada linha é um campo dropdown de status. Ao lado do campo há um botão **+**, que abre um formulário rápido para **criar um novo status sem sair da tela de configuração**; ao salvar, o novo status já fica selecionado no campo.

> Nenhum status é criado automaticamente pelo sistema — a tabela de status do GLPI só recebe um novo valor se você usar esse botão **+** deliberadamente. Recomenda-se criar os status usados pelo LTBP na **entidade raiz**, com a opção de recursividade **ligada**, e usar o mesmo status de "Ativo aguardando baixa" configurado no PRE, para o ativo devolvido pela assistência já entrar direto na lista de candidatos do laudo.

> `[GIF AQUI: usar o botão "+" ao lado de um campo de status para criar um novo status sem sair da tela de configuração]`

## Bloco "Assinaturas do laudo"

Define o nome e o cargo dos dois signatários impressos no PDF: **Diretor de TI: nome**, **Diretor de TI: cargo**, **Diretor Administrativo: nome** e **Diretor Administrativo: cargo** — quatro campos de texto livre.

> Esses dados são copiados para dentro do laudo no momento da emissão. Trocar o nome ou o cargo de um diretor aqui **não altera** laudos já emitidos — cada um mantém a assinatura de quando foi emitido.

## Bloco "Andamento"

Reúne comportamentos que afetam as etapas depois da emissão:

- **Exigir comprovante para concluir**: alternância Sim/Não (padrão **Sim**). Com Sim, a etapa Concluir exige pelo menos um arquivo anexado (certificado de destinação ou termo de doação); com Não, o anexo fica opcional.
- **Solucionar o ticket de origem ao concluir**: alternância Sim/Não (padrão **Não**). Vale só para ativos que vieram de um PRE. Com Sim, o ticket de origem é solucionado automaticamente na conclusão do laudo (se não houver outra linha ainda ativa daquele ticket, em qualquer PRE ou laudo); com Não (padrão), o ticket recebe apenas os acompanhamentos de cada etapa, e o técnico decide quando solucioná-lo.
- **Motivo padrão para "Sem conserto"** e **Motivo padrão para "Orçamento não aprovado"**: dois dropdowns opcionais do cadastro de motivos (ver [Motivos de Baixa](#tela-motivos-de-baixa)). Quando um ativo devolvido pelo PRE com esse resultado entra em um laudo, o motivo mapeado aqui já vem selecionado na linha — o técnico pode trocar antes de emitir. Sem mapeamento, a linha entra sem motivo.

> `[GIF AQUI: alternar "Exigir comprovante para concluir" e escolher os dois motivos padrão]`

## Bloco "Relatório"

- **Categoria de documento da logomarca**: dropdown de categorias de documento (`DocumentCategory`), com a mesma lógica de busca do PRE (documento mais recente anexado à entidade do laudo, subindo para a entidade pai se não houver).

> Os demais dados do cabeçalho do PDF (nome da empresa, CNPJ, endereço, cidade/UF e telefone) **não são configurados aqui**: eles vêm diretamente do cadastro da entidade do laudo, em **Administração > Entidades**. A cidade e a UF da entidade também formam o texto de local impresso acima das assinaturas.

## Bloco "Motivos de baixa"

Não tem campos próprios — só o botão **Gerenciar motivos de baixa**, que leva à tela de cadastro (ver [Motivos de Baixa](#tela-motivos-de-baixa)).

## O botão Salvar

Cada seção de módulo (LTBP, PRE etc.) tem o seu próprio botão **Salvar**, no rodapé do cartão. Ele só envia e grava os campos **daquela seção** — nenhum outro módulo é afetado. Depois de salvar, a página recarrega e mostra a mensagem de confirmação "Configuração do LTBP salva.".

> `[GIF AQUI: preencher os quatro campos de assinatura, clicar em Salvar e mostrar a mensagem de confirmação]`

---

# Tela: Motivos de Baixa

Cadastro dos motivos que o técnico escolhe para cada ativo incluído em um laudo. Acessível de duas formas: pelo botão **Gerenciar motivos de baixa** na tela de configuração, ou diretamente pelo menu lateral, em **Plugin - DTI GAC > Configurações > Motivos de baixa** (esse atalho também mostra o botão nativo **+** para criar um motivo sem passar pela configuração). Requer o direito "Configurar" do LTBP — não existem direitos separados de leitura e escrita nesta tela.

## Lista de motivos

Lista padrão de busca do GLPI, com as colunas **Código**, **ID**, **Título**, **Descrição** e **Ativo**, com os recursos nativos de busca (filtros, ordenação, exportação). Clicar em uma linha abre o motivo para edição.

## Formulário do motivo

| Campo | Tipo | Obrigatório | Observações |
|---|---|---|---|
| Código | Texto curto | Sim | Convertido automaticamente para maiúsculas e limitado a 20 caracteres. Deve ser único entre todos os motivos. É o texto curto (por exemplo `M1`) impresso na tabela de bens do PDF do laudo. |
| Título | Texto | Sim | Descrição curta do motivo, exibida junto ao código onde o motivo é selecionado (por exemplo `M1: Tela quebrada`). |
| Descrição | Texto longo | Não | Aparece na legenda dos motivos usados, impressa no PDF do laudo. |
| Ativo | Sim/Não | — | Controla se o motivo aparece nos dropdowns de seleção. Um motivo novo já nasce **Ativo = Sim** por padrão. |

> Tentar salvar sem código ou sem título é bloqueado, com a mensagem "Informe o código do motivo." ou "Informe o título do motivo.", conforme o campo vazio. Tentar salvar um código já usado por outro motivo é bloqueado com "Já existe um motivo com este código."

> `[GIF AQUI: criar um motivo novo preenchendo Código, Título e Descrição, e salvar]`

## Excluir ou inativar

Um motivo que já foi usado em algum ativo de laudo não pode mais ser excluído — a exclusão é bloqueada. Para retirá-lo de circulação sem afetar laudos já emitidos, edite o motivo e marque **Ativo = Não**: ele deixa de aparecer nas seleções, mas continua associado aos laudos que já o usaram (o laudo emitido guarda uma cópia do código, título e descrição do motivo, feita no momento da emissão — editar ou inativar o motivo depois não muda o que já foi impresso). Um motivo nunca usado pode ser excluído normalmente.

> `[GIF AQUI: tentar excluir um motivo já usado em um laudo e mostrar a mensagem de bloqueio, depois inativá-lo em vez de excluir]`

---

# Tela: Lista de Laudos

Acessível pelo menu lateral **Plugin - DTI GAC > Laudos Técnicos de Baixa Patrimonial**. Lista todos os laudos da entidade atual (e subentidades, conforme a busca nativa do GLPI), usando o mecanismo de busca padrão do GLPI: colunas configuráveis, filtros salvos, exportação.

## Funcionalidades desta tela

- **Buscar/filtrar laudos** pelos campos padrão da busca do GLPI (número, status, destinação, técnico responsável, data de emissão, data da baixa, data da conclusão).
- **Abrir um laudo** existente clicando na linha.
- **Criar um novo laudo** pelo botão de adicionar (canto superior direito, padrão GLPI). Só aparece para quem tem direito de criação no LTBP.

> `[GIF AQUI: abrir a lista de laudos pelo menu, mostrar a busca/filtro e clicar em "Novo"]`

---

# Tela: Cabeçalho do Laudo (Criação do LTBP)

Primeira aba do laudo, com os dados gerais do documento. Só pode ser editada enquanto o laudo está em **Rascunho**; depois da emissão, o cabeçalho vira somente leitura.

## Campos do cabeçalho

- **Número**: mostra `(gerado ao salvar)` num laudo novo. Depois do primeiro Salvar, exibe o número definitivo, no formato `LTBP-AAAA-NNN` — uma sequência anual, global ao GLPI, que nunca é reaproveitada mesmo se um laudo for excluído. Campo somente leitura.
- **Status**: somente leitura — calculado a partir do andamento do laudo, nunca digitado (ver [Ciclo de vida do laudo](#ciclo-de-vida-do-laudo-status)).
- **Destinação** (obrigatória): dropdown com duas opções, **Descarte ecológico** ou **Doação**. Vale para o laudo inteiro — não é possível misturar destinos no mesmo laudo; ativos com destinos diferentes precisam de laudos separados.
- **Técnico responsável**: dropdown de usuários. Vem preenchido, por padrão, com o usuário logado, mas pode ser trocado por qualquer outro usuário com direito no GLPI.
- **Data de emissão**: só aparece depois que o laudo é emitido (é preenchida automaticamente nesse momento, ver [Emitir para assinatura](#emitir-para-assinatura)); num laudo em Rascunho o campo não existe ainda.
- **Comentários**: campo de texto livre, de uso interno — não é impresso no PDF.

> `[GIF AQUI: criar um laudo escolhendo a destinação e o técnico, salvar e mostrar o número gerado]`

## Ciclo de vida do laudo (status)

O status do laudo nunca é escolhido diretamente — ele avança conforme as ações registradas na aba [Andamento do Laudo](#tela-andamento-do-laudo):

| Status | Quando ocorre |
|---|---|
| Rascunho | Antes da emissão — um rascunho não é cancelado, é excluído |
| Aguardando assinaturas | Logo após **Emitir para assinatura** |
| Assinado | Depois de anexar o PDF assinado pelos diretores |
| No patrimônio | Depois de registrar o envio ao setor de patrimônio |
| Baixado | Depois que o patrimônio confirma a baixa — a partir daqui os ativos ficam bloqueados para edição |
| Concluído | Depois de registrar a execução do descarte ou da doação — estado final, não pode mais ser alterado |
| Cancelado | Só a partir de Aguardando assinaturas, Assinado ou No patrimônio — depois de Baixado não há mais cancelamento nem reabertura |

---

# Tela: Ativos do Laudo

Segunda aba do laudo, chamada **"Ativos"**, onde os equipamentos entram no laudo e recebem um motivo. Só permite alterações enquanto o laudo está em **Rascunho**; depois da emissão, mostra os dados como uma cópia congelada.

## Ativos aguardando baixa (candidatos)

Um cartão **Ativos aguardando baixa** lista os ativos que já estão no status "Ativo aguardando baixa" (bloco [Status do ativo](#bloco-status-do-ativo)), na entidade do laudo e nas suas subentidades, e que não estão em nenhum outro laudo ativo nem em uma linha ativa de PRE. Colunas: (caixa de seleção) **Tipo**, **Equipamento**, **Patrimônio**, **Nº de série** e **Origem no PRE**.

- Marque os ativos desejados (checkbox individual, ou "marcar todos" no cabeçalho da tabela) e confirme em **Adicionar selecionados ao laudo**.
- **Origem no PRE**: quando o ativo chegou aqui devolvido por um PRE com destino Baixa, a coluna mostra o número do PRE, o resultado do retorno e a descrição do serviço executado (consulte o manual do PRE para o fluxo completo do lado de lá); ativos sem essa origem mostram um traço.

> Se o status "Ativo aguardando baixa" ainda não estiver mapeado na configuração do LTBP, este cartão mostra um aviso em vez da lista, e nenhum candidato aparece.

> `[GIF AQUI: abrir a aba Ativos de um rascunho com candidatos, marcar alguns e clicar em "Adicionar selecionados ao laudo"]`

## Buscar outro ativo

Cartão para incluir um equipamento que nunca passou pelo status "aguardando baixa" nem pelo PRE — útil para ativos obsoletos que precisam ser baixados diretamente. O campo é um seletor em dois passos: primeiro escolha o **tipo** do ativo, depois busque o ativo pelo nome ou patrimônio dentro daquele tipo (mesmo componente nativo usado em outras telas de vínculo do GLPI). Confirme em **Adicionar ao laudo**.

> Um ativo incluído por esta busca entra sem vínculo com nenhum PRE e sem motivo padrão pré-selecionado: o motivo precisa ser escolhido manualmente antes de emitir.

## Ativos do laudo (lista principal) e motivo

A tabela **Ativos do laudo** mostra, por linha: **Tipo**, **Equipamento**, **Marca / modelo**, **Patrimônio**, **Nº de série**, **Origem** (número do PRE e/ou ticket, quando houver) e **Motivo**.

- **Motivo**: em Rascunho é um dropdown por linha, com os motivos ativos do cadastro (ver [Motivos de Baixa](#tela-motivos-de-baixa)). Quando o ativo veio de um PRE com resultado "Sem conserto" ou "Orçamento não aprovado" e há um motivo padrão configurado para esse resultado (bloco [Andamento](#bloco-andamento)), o dropdown já vem com esse motivo pré-selecionado, mas pode ser trocado. Depois de ajustar quantas linhas quiser, use o botão **Salvar motivos** no rodapé da tabela.
- **Remover**: disponível só em Rascunho, remove o ativo do laudo (ele volta a ficar disponível como candidato, se ainda estiver no status "aguardando baixa").

> Todo ativo precisa de um motivo **ativo** antes da emissão — um ativo sem motivo, ou com um motivo que foi inativado nesse meio-tempo, bloqueia o botão Emitir (ver [Emitir para assinatura](#emitir-para-assinatura)).

> `[GIF AQUI: escolher o motivo de um ativo no dropdown da linha e clicar em "Salvar motivos"]`

---

# Tela: Andamento do Laudo

Aba própria do laudo, separada da aba de ativos, chamada **"Andamento"**. Mostra uma linha do tempo com os seis marcos do laudo (Rascunho, Aguardando assinaturas, Assinado, No patrimônio, Baixado, Concluído — cada um com a data em que ocorreu) e, abaixo, só o cartão de ação que cabe no status atual e nos direitos do usuário logado. Um laudo **Cancelado** mostra, no topo, um aviso vermelho com a data e o motivo do cancelamento no lugar da linha do tempo.

> `[GIF AQUI: abrir a aba Andamento de um laudo em Rascunho e mostrar a linha do tempo com os seis marcos]`

## Emitir para assinatura

Card disponível em **Rascunho**, para quem tem o direito "Emitir e avançar etapas". Antes de liberar o botão **Emitir laudo**, o sistema confirma:

- a destinação está definida;
- há pelo menos um ativo no laudo;
- todos os ativos têm um motivo ativo escolhido;
- os três status do ativo estão mapeados na configuração;
- os dois diretores estão configurados (nome e cargo);
- nenhum ativo está em outro laudo ativo nem em uma linha ativa de PRE.

> Se alguma dessas condições faltar, o botão **Emitir laudo** aparece desabilitado, com a lista exata do que falta acima dele. Nada é alterado até que tudo esteja completo.

Ao confirmar (com uma caixa de confirmação: "Emitir o laudo? Os ativos passam para 'em processo de baixa' e o PDF fica congelado."), o sistema gera o PDF definitivo, copia o motivo de cada ativo e os dados dos dois diretores (uma cópia que não muda mais, mesmo que o cadastro de motivos ou os diretores da configuração mudem depois), muda o status do laudo para **Aguardando assinaturas** e o status de cada ativo para "Ativo em processo de baixa".

> `[GIF AQUI: emitir um laudo completo e mostrar o PDF gerado e o status "Aguardando assinaturas"]`

## PDF assinado

Card disponível em **Aguardando assinaturas** e **Assinado**. Depois que os dois diretores assinam o papel, anexe o arquivo escaneado (**PDF, JPG ou PNG**) pelo campo de upload e o botão **Anexar PDF assinado**. Enquanto o laudo não for enviado ao patrimônio, um novo upload pelo mesmo botão (que passa a se chamar **Substituir PDF assinado**) troca o arquivo anterior — o arquivo antigo continua disponível na aba Documentos, só o vínculo principal muda. Anexar o assinado move o laudo para o status **Assinado**.

> `[GIF AQUI: anexar o PDF assinado e mostrar o status mudando para "Assinado"]`

## Enviar ao patrimônio

Card disponível em **Assinado**. Registra **Data do envio** (obrigatória, vem preenchida com a data atual) e **Recebido por** (texto livre, opcional — nome de quem recebeu no setor de patrimônio). Confirmar em **Registrar envio** move o laudo para **No patrimônio**.

## Confirmar a baixa

Card disponível em **No patrimônio**. Registra **Data da baixa** (obrigatória), **Nº do processo de baixa** (texto livre, opcional — hoje esse número não existe formalmente, mas o campo já existe para quando passar a existir), **Comprovante** (upload de um ou mais arquivos, opcional) e **Observação** (texto livre, opcional). Pede confirmação antes de executar ("Confirmar a baixa? Os ativos ficam bloqueados para edição e isto não pode ser desfeito.").

> Confirmar a baixa muda o status de cada ativo do laudo para "Ativo baixado" e **não pode ser desfeito**: a partir daqui não há mais cancelamento do laudo, e os ativos ficam bloqueados para edição (ver [Bloqueio do ativo baixado](#bloqueio-do-ativo-baixado)).

> `[GIF AQUI: confirmar a baixa de um laudo e mostrar a caixa de confirmação e o aviso de bloqueio]`

## Concluir a destinação

Card disponível em **Baixado**. Registra a execução do descarte ou da doação: **Data da execução** (obrigatória), **Beneficiário (fornecedor)** (dropdown obrigatório de `Supplier` — a empresa recicladora ou o donatário, cadastrado como fornecedor **ativo** da entidade do laudo ou de uma entidade que o técnico tenha acesso), **Comprovante** (upload; obrigatório ou opcional conforme o bloco [Andamento](#bloco-andamento) da configuração — certificado de destinação ou termo de doação) e **Observação** (texto livre, opcional). Confirmar em **Concluir laudo** encerra o laudo, com o status final **Concluído**.

> Um fornecedor cadastrado sem marcar "ativo" não aparece no campo Beneficiário — o GLPI só lista fornecedores ativos em qualquer dropdown do sistema.

> `[GIF AQUI: concluir um laudo escolhendo o beneficiário e anexando o comprovante]`

## Registro

Depois de **Baixado** ou **Concluído**, um cartão **Registro** resume, em modo leitura, o que foi preenchido nas etapas: recebido no patrimônio por, nº do processo de baixa, observação da baixa e, se concluído, o beneficiário e a observação da conclusão.

## Cancelar o laudo

Card disponível em **Aguardando assinaturas**, **Assinado** ou **No patrimônio**, para quem tem o direito "Cancelar" — não aparece mais depois de **Baixado**. Pede um **motivo do cancelamento** (texto livre, obrigatório) e uma confirmação antes de executar.

> Cancelar um laudo restaura, em cada ativo, o status que ele tinha antes da emissão. Se alguém tiver alterado o status desse ativo manualmente nesse meio-tempo, o cancelamento **não sobrescreve** essa mudança — o laudo é cancelado do mesmo jeito, e um aviso registra que aquele ativo específico não foi restaurado.

> `[GIF AQUI: cancelar um laudo emitido, informando o motivo]`

---

# Bloqueio do Ativo Baixado

A partir do status **Baixado**, cada ativo do laudo fica bloqueado para edição em qualquer tela do GLPI onde ele apareça — formulário do ativo, importação por planilha, agente de inventário — exceto o campo de comentário e os vínculos de documentos, que continuam livres.

> Tentar salvar uma alteração bloqueada mostra a mensagem: "Este ativo foi baixado por um laudo de baixa patrimonial e não pode mais ser editado. Campos recusados: [lista dos campos]."

Só perfis com o direito **"Editar ativo baixado"** (ver [Permissões](#permissões)) conseguem editar esses ativos normalmente. É uma permissão pensada para correções pontuais e deve ficar restrita a poucas pessoas — o bloqueio existe justamente para o inventário não continuar mudando um equipamento que já saiu do patrimônio.

> `[GIF AQUI: tentar editar um campo comum de um ativo baixado (sem o direito "Editar ativo baixado") e mostrar a mensagem de bloqueio]`

---

# Relatório em PDF

O laudo tem botões de PDF, com comportamento diferente conforme o status. Na aba **Ativos**, o botão de PDF no cabeçalho do cartão:

- **Em Rascunho**: **Pré-visualizar PDF** gera uma prévia a cada clique — útil para revisar o conteúdo antes de emitir.
- **Depois da emissão**: **Baixar PDF emitido** sempre entrega o **mesmo arquivo** gerado no momento de "Emitir laudo", nunca uma nova renderização.

Na aba **Andamento**, uma vez emitido, um cartão **Documentos do laudo** reúne os dois arquivos: **Baixar PDF emitido** (o documento gerado pelo sistema) e, depois de anexado, **Baixar PDF assinado** (o arquivo escaneado com as assinaturas em papel) — são dois anexos distintos, para comprovar que o papel assinado corresponde ao que o sistema emitiu.

O PDF é gerado em **retrato** (formato A4 vertical, diferente do PDF do PRE, que é paisagem). Conteúdo: cabeçalho com os dados da entidade e a logomarca configurada; tabela com número, data de emissão, técnico e destinação; objetivo do laudo; tabela de bens (equipamento com nome, tipo, marca, modelo, série, patrimônio e código do motivo); legenda só dos motivos realmente usados no laudo; destinação recomendada; conclusão; e três blocos de assinatura (técnico, Diretor de TI, Diretor Administrativo) com os nomes e cargos copiados na emissão. O texto de local e data usa a cidade e a UF da entidade do laudo (por exemplo "Porto Velho/RO, 26/09/2026."); sem cidade ou UF cadastrada, imprime só a data, sem bloquear a emissão.

> `[GIF AQUI: abrir a pré-visualização do PDF em um rascunho e, depois de emitido, baixar o PDF emitido e o PDF assinado]`

---

# Histórico do Laudo

Os eventos do LTBP (criação, ativo adicionado ou removido, emissão, PDF assinado anexado, envio ao patrimônio, baixa confirmada, conclusão, cancelamento, documentos anexados) aparecem na aba nativa **Histórico** do GLPI, junto com as demais alterações de campos do laudo — não existe uma aba própria separada para isso.

> `[GIF AQUI: abrir a aba Histórico de um laudo com vários eventos registrados]`

---

# Permissões

O LTBP tem uma linha própria na aba de perfil **Plugin - DTI GAC**, com as permissões básicas de um cadastro do GLPI mais três permissões específicas do módulo. Um perfil pode combinar qualquer conjunto delas.

**Permissões básicas** (comuns a qualquer item do GLPI):

- **Ver**: abrir a lista de laudos e as abas de um laudo. Sem essa permissão, a entrada **Laudos Técnicos de Baixa Patrimonial** nem aparece no menu do plugin.
- **Criar**: usar o botão **Novo** na lista de laudos, para começar um laudo em Rascunho.
- **Editar**: alterar o cabeçalho (destinação, técnico responsável, comentários) e a aba de ativos (adicionar candidatos, buscar outro ativo, escolher motivo, remover ativo) — só funciona enquanto o laudo está em **Rascunho**.
- **Excluir**: apagar definitivamente um laudo. Só é possível em **Rascunho** ou **Cancelado** — um laudo já emitido não pode ser excluído, apenas cancelado antes da baixa confirmada.

**Permissões específicas do LTBP:**

- **Emitir e avançar etapas**: libera, na aba Andamento, os cartões **Emitir para assinatura**, **PDF assinado**, **Enviar ao patrimônio**, **Confirmar a baixa** e **Concluir a destinação** — é a permissão de quem conduz o laudo do início ao fim.
- **Cancelar**: libera, na aba Andamento, o cartão **Cancelar o laudo**. Separada da permissão anterior porque cancelar desfaz um laudo em andamento — muitas equipes preferem restringi-la.
- **Editar ativo baixado**: libera a edição normal de um ativo depois que ele foi baixado por um laudo (ver [Bloqueio do Ativo Baixado](#bloqueio-do-ativo-baixado)). Não tem relação com as permissões do laudo em si — é sobre o ativo.
- **Configurar** (`RIGHT_CONFIG`): libera a seção **"Laudo Técnico de Baixa Patrimonial"** na tela de configuração do plugin e o cadastro de [Motivos de Baixa](#tela-motivos-de-baixa). Não dá acesso a nenhuma ação sobre os laudos em si — só à configuração do módulo.

> Um usuário sem uma dessas permissões não vê o botão ou o cartão correspondente na tela, e a ação é recusada mesmo se tentada diretamente (por exemplo, forjando a requisição).
