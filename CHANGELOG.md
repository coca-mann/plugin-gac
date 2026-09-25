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
