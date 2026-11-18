# Inteligência de Vendas — Cruzamento de Bases

Módulo que importa as planilhas de vendas do sistema comercial da HM e as cruza
com os leads, atendimentos, corretores, campanhas e históricos do WeMart Sales.

| Fase | Conteúdo | Situação |
|---|---|---|
| 1 | Análise da planilha, do banco e da arquitetura; leitor e normalizador; regras de correspondência v1; diagnóstico só-leitura | **concluída** |
| 2 | Importação: upload, mapeamento, validação, prévia, lotes, duplicidade, histórico de alterações | **concluída** |
| 3 | Cruzamento persistido: índice de contatos, candidatos, revisão humana, auditoria, reprocessamento | **concluída** |
| 4 | Jornada do Cliente: linha do tempo e tempos (jornada, responsabilidade, interação) | **concluída** |
| 5 | Painel de conciliação, resultados detalhados e relatórios especializados | **concluída** |
| 6 | Exportações (Excel, CSV, PDF), testes de integridade e segurança | — |

---

## 1. A planilha da HM (arquivo de 15/08 a 14/10)

Só números agregados. Nenhum dado pessoal da planilha foi gravado no repositório,
nos testes ou em logs.

| Item | Encontrado | Consequência |
|---|---|---|
| Linhas / colunas | 132 / **65** | O leitor de planilhas de leads lia **só 50 colunas**: `datacadastro`, `Situação Proposta`, `Venda RM`, superintendente, gerente e coordenador ficavam de fora. O leitor ganhou um limite configurável; a importação de leads continua com 50 (sem mudança). |
| `id` e `Venda RM` | 132 distintos cada, sem repetição | Servem de chave da venda (empresa + sistema de origem + `id`; `Venda RM` como segunda chave). |
| `Situação Proposta` | 132 × "Vendido" | Importações futuras podem ter outras situações; só "Vendido" (configurável) entra nos indicadores de venda. |
| `datahoravenda` | número serial do Excel; 15/08 a 14/10; **nenhuma com hora** | A venda tem precisão de dia. Lead que entrou no mesmo dia da venda fica com cronologia "mesmo dia" (alerta, revisão). |
| `datacadastro` | **98** com 31/12/1969 (data zero de outro sistema); **7** depois da venda; **3** no futuro (09/12/2026, provável dia e mês trocados) | Só 24 de 132 datas são plausíveis. O campo é guardado como dado importado e **nunca** substitui a entrada do lead no CRM. |
| `Celular` | 130 válidos (55 + DDD + 9 dígitos); 1 com 8 dígitos (celular antigo, sem o 9); 1 com tamanho inválido | Os 2 fora do padrão não entram no cruzamento automático; o de 8 dígitos pode apontar candidato para revisão humana. |
| `telefone` | preenchido em 21; 16 iguais ao celular | Usado como segundo telefone. |
| `Email` | 132 válidos, já em minúsculas; nenhum compartilhado entre compradores diferentes | Bom identificador. |
| `cpf` | gravado como **número**: 21 perderam o zero à esquerda; recuperados, todos os 132 com dígito verificador válido | Não entra no cruzamento v1 (ver decisões). |
| Compradores | 118 pessoas para 132 vendas; **12 compraram mais de uma unidade** | A chave é a venda, nunca a pessoa. |
| `imobiliaria` | 1 valor (a própria HM Vendas) | Não identifica canal externo. |
| `corretor` | 75 nomes, um por `id_corretor`; prefixos: CIA_ (74 vendas), CORRETOR_ (53), GP_ (2), ONLINE_ (2), COORDENADOR_ (1) | Prefixo **não** define interno × externo: depende do cadastro de equivalências (ver decisões). |
| `midia` | Canal de Parceria 55, Indicação 33, Ativo Corretores 16, Mídias Online 7, Indique amigo EME 7, Roleta 5, Sigav 4, Passagem 3, outros 2 | Guardada como **"Mídia declarada na venda"**, separada da origem do lead no CRM. |
| `valor` | soma R$ 34,43 mi; `valor` = `tabela` − `desconto` em 74 de 132 | O valor é o **valor de venda (VGV da unidade)**, não receita nem lucro. |
| Empreendimentos | 17 nomes ("Hm Smart Freguesia do ó", "Reserva dos Ibirás - Loteamento"…) | Nenhum casa automaticamente com o cadastro do CRM: vão para o cadastro de equivalências com revisão humana. |
| Colunas pessoais sem uso | nascimento, gênero, endereço, CEP, cidade, renda, profissão, 2º comprador, fiador, observações, brinde | **Não importadas** (minimização, LGPD); aparecem na tela como "não importadas", nunca descartadas em silêncio. |
| Outras colunas | parc_promocao, ato, intermediária, parcelas, metragem, tipo de comissão… (14) | Guardadas como metadados da linha, para mapear depois. |

## 2. O que já existe e será reaproveitado

| Necessidade | Já existe | Uso |
|---|---|---|
| Lead / oportunidade | `leads` (uma linha por interesse, com empreendimento, origem, UTMs, ids do Meta) | Cada lead é uma oportunidade; a mesma pessoa pode ter vários. |
| Contatos | `leads.telefone`, `leads.email`, `whatsapp_contatos.telefone` ligado ao lead | Índice de contatos normalizados. **Não há** tabela de contatos secundários. |
| Distribuição e responsáveis | `lead_atribuicoes` (forma: roleta, oferta ativa, redistribuição, transferência…; gerente e superintendência da época) | Histórico de corretores, 1º corretor, responsável na data da venda. |
| Transferências | `lead_transferencias` (de/para corretor, equipe, gerente, superintendência; motivo) | Contagem e sequência. |
| Etapas do Kanban | `lead_etapa_historico` | Tempo em cada etapa. |
| Oferta ativa | `oferta_listas` + `leads.oferta_lista_id` + atribuições "oferta_ativa" | Participação em oferta ativa. |
| Remarketing / recuperação | `whatsapp_campanhas` + `whatsapp_campanha_destinatarios`; `jornada_execucoes` | Campanhas recebidas antes da venda. |
| WhatsApp | `whatsapp_conversas` / `whatsapp_mensagens` | Respostas do cliente. |
| Visitas e contratos | `agenda_compromissos`, `lead_contratos` | Visitas e contratos do CRM. |
| Anúncio | `leads.meta_ad_id` → `meta_anuncios` (campanha, conjunto, criativo, imagem, link) | Anúncio de origem com prévia. |
| Origens | `lead_origens` | Origem de aquisição no CRM. |
| Estrutura | `equipes`, `superintendencias`, `usuarios` | Gestores da época (via atribuições). |
| Permissões | `rbac_leads_scope` e funções | Acesso aos dados individuais segue a carteira **atual**. |
| Auditoria e LGPD | `auditoria`, exclusão lógica de lead | Leads excluídos nunca entram no cruzamento. |
| Planilhas e exportação | leitor XLSX/CSV seguro; Excel/CSV/PDF da Central; fila em segundo plano | Mesmo padrão. |

## 3. Limitações dos históricos

- **Responsáveis anteriores à migração 013:** só existem se a retroalimentação os reconstruiu (marcados como "reconstruídos"). Caso contrário, conhecemos só o corretor atual e informamos isso.
- **Anúncio:** só para leads do Meta capturados depois da integração com ids (migração 011). Leads antigos ou importados aparecem como "anúncio não disponível".
- **Contato secundário:** não existe cadastro de segundo telefone/e-mail; só o telefone do contato de WhatsApp ligado ao lead.
- **Telefones no CRM:** estão como foram digitados. Para comparar sem varrer a tabela, a Fase 3 grava um índice normalizado.
- **Data de entrada:** usamos a menor entre `criado_em` e a data do formulário do Meta. Lead vindo de planilha de oferta ativa tem como entrada a data da importação; o CRM não sabe de antes.
- **Venda sem hora:** quando o lead entrou no mesmo dia da venda, não dá para saber o que veio primeiro.

## 4. Regras de correspondência v1 (implementadas e testadas)

Arquivo `includes/vendas/correspondencia.php`; versão gravada em cada resultado (`VENDAS_REGRAS_VERSAO`).

- Telefone: só dígitos, no formato 55 + DDD + número. Aceita +55, 0 de discagem e formatação. Valida o DDD. **Não** acrescenta DDD nem o 9 e **não** aproxima números parecidos.
- E-mail: minúsculas e sem espaços nas pontas. **Não** remove pontos nem "+alias".
- Nome nunca é prova. CPF fica fora da v1.

| Situação | Resultado |
|---|---|
| Telefone e e-mail no mesmo cadastro | **Correspondência forte** |
| Só telefone, sem conflito | **Por telefone** |
| Só e-mail, sem conflito | **Por e-mail** |
| Telefone num cadastro e e-mail em outro; telefone em cadastros com e-mails diferentes; ou só coincide trocando o 9º dígito | **Ambígua**: revisão humana obrigatória |
| Nada coincide | **Sem correspondência** |
| Coincide, mas o 1º cadastro no CRM é depois da venda | **Posterior à venda**: não conta como conversão do CRM |

Todos os leads da mesma pessoa (mesmo telefone ou e-mail) ficam ligados à venda. A entrada no CRM é a do lead **mais antigo**.

## 5. Modelo de dados proposto (migração 017, Fase 2–3)

Nada é alterado em `leads`. O Kanban não é tocado e nenhuma automação é disparada.

| Tabela | Para quê |
|---|---|
| `vendas_lotes` | Cada envio: arquivo (nome, SHA-256), usuário, mapeamento usado, contagens (lidas, incluídas, atualizadas, ignoradas, com erro), situação, data de descarte do arquivo. |
| `vendas_lote_linhas` | Resultado de cada linha do lote (incluída / atualizada / ignorada / erro e motivo), sem dados pessoais. |
| `vendas_externas` | A venda normalizada. Chave única: empresa + sistema de origem + `id`. Guarda também: `Venda RM`, situação, datas, empreendimento (texto e equivalência), unidade, corretor e gestores da origem, **mídia declarada**, valores, contatos normalizados e metadados extras. |
| `vendas_externas_versoes` | Histórico quando uma venda já importada volta com mudanças (antes/depois, lote, usuário). |
| `contato_identificadores` | Índice normalizado de telefone/e-mail → lead, mantido a cada criação ou edição de lead. |
| `vendas_correspondencias` + `vendas_correspondencia_leads` | Resultado do cruzamento (status, motivo, versão das regras, evidências por lead: telefone / e-mail / variante). |
| `vendas_revisoes` | Decisões humanas (confirmar, rejeitar, sem vínculo), com justificativa e usuário. |
| `vendas_equivalencias` | Nome/código do sistema externo → cadastro do WeMart: empreendimento, corretor (`id_corretor` → usuário, ou "externo"), imobiliária, mídia. Só valem depois de confirmadas por uma pessoa. |
| `imobiliarias_parceiras` | Cadastro de imobiliárias/canais externos, para classificar "venda por canal externo". |

A **linha do tempo** da Jornada é montada na leitura, a partir das tabelas que já existem (atribuições, etapas, transferências, agenda, WhatsApp, campanhas, contratos). Assim, não duplicamos eventos. Cada evento leva a marca **confirmado** (CRM), **importado** (planilha) ou **inferido**.

Permissões novas (propostas): `importar_vendas` (Master), `ver_inteligencia_vendas` (Master e diretoria) e `revisar_correspondencias`.

## 6. Segurança e LGPD (decisões já aplicadas)

- O cruzamento usa **só** leads da mesma empresa (testado) e ignora leads excluídos.
- Minimização: as colunas pessoais sem uso não são importadas.
- O arquivo original fica fora da pasta pública, com prazo de descarte (proposta: 30 dias).
- Telas e exportações: os dados pessoais do comprador só aparecem para quem pode ver o lead hoje. Gestores anteriores veem só números agregados.
- A importação de vendas **não** é consentimento: nenhuma campanha ou automação usa esses contatos.

## 7. Diagnóstico no servidor (Fase 1, só leitura)

```bash
cd /home1/wemart86/myonespot.online && git pull
# envie a planilha para a sua pasta pessoal, FORA do site (ex.: ~/vendas.xlsx)
php -q ferramentas/analisar_vendas.php ~/vendas.xlsx               # valida e lista as empresas
php -q ferramentas/analisar_vendas.php ~/vendas.xlsx --empresa=ID  # + prévia do cruzamento
rm ~/vendas.xlsx
```

A saída tem só contagens e nomes de empreendimentos e mídias. Ela responde, com os leads reais:
- quantas vendas já estavam na base;
- por telefone, e-mail ou ambos;
- quantas são ambíguas ou posteriores à venda;
- quantas mudaram de empreendimento;
- quantas passaram por oferta ativa, transferência ou remarketing;
- a jornada média e mediana.

## 8. Arquivos da Fase 1

- `includes/vendas/planilha.php`: dicionário de colunas, datas do Excel, telefone, e-mail, CPF, valores, validação da linha.
- `includes/vendas/correspondencia.php`: regras v1 e dias entre datas.
- `includes/vendas/indice_crm.php`: índice de contatos do CRM por empresa e candidatos.
- `includes/importacao.php`: leitor XLSX com limite de colunas configurável (padrão 50, como antes).
- `ferramentas/analisar_vendas.php`: diagnóstico e prévia, sem gravar nada.
- `tests/vendas_fase1_test.php`: 81 verificações com dados fictícios; 10 de 10 mutações detectadas.

---

## 9. Fase 2 — Importação (concluída)

**Onde:** Relatórios → Inteligência de Vendas → **Importar vendas** (`/pages/vendas_importar.php`). Só quem tem a permissão `importar_vendas` (Master, por padrão).

### Fluxo
1. **Enviar:** a empresa é sempre a empresa ativa da sessão (campo só leitura); escolhe-se o sistema de origem (HM) e o arquivo (XLSX ou CSV, até 5 MB e 5.000 linhas, 65+ colunas).
2. **Conferir:**
   - **Colunas:** as da HM são reconhecidas sozinhas. "Ajustar" permite trocar qualquer coluna e recalcula a prévia.
   - **Campos obrigatórios:** data da venda, empreendimento e `id` ou `Venda RM`.
   - **Colunas não usadas:** as pessoais aparecem como "não importadas (LGPD)"; as demais, como "informação extra". Nenhuma some em silêncio.
3. **Prévia (nada gravado):**
   - **Contagens:** novas, alteradas na origem, já importadas, repetidas no arquivo, inconsistentes (entram com alerta) e recusadas, com os motivos.
   - **Tabela das linhas:** sem nome, telefone, e-mail nem CPF. Mostra só se o contato serve para o cruzamento.
   - **Arquivo repetido:** se for o mesmo arquivo de um lote anterior (SHA-256), um aviso diz isso.
4. **Confirmar:**
   - **Situações:** a pessoa marca quais situações contam como venda (padrão: "Vendido"). As outras ficam gravadas, mas não contam.
   - **Vendas alteradas:** ela também decide se atualiza as vendas que mudaram na origem.
5. **Lote:**
   - **Página do lote** (`/pages/vendas_lote.php`): contagens, resultado de cada linha (número da linha, id de origem, motivo, alertas) e campos alterados.
   - **Reprocessar:** lê de novo o original guardado, com o mesmo mapeamento.
   - **Histórico:** fica na página de importação.

A prévia e a gravação usam a **mesma** análise (`vendas_analisar()`): o lote sempre bate com a prévia.

### Regras de duplicidade e atualização
- **Chave:** empresa + sistema de origem + `id`. Sem `id`, a venda é encontrada pela `Venda RM`.
- **Mesmo `id` repetido no arquivo:** só a primeira linha vale; as outras ficam "ignoradas".
- **`Venda RM` já usada por outra venda,** no arquivo ou no banco: a linha é **recusada** (conflito). Nunca cria duplicata.
- **Venda já importada e igual:** "sem alteração". Reenviar o arquivo não duplica nada.
- **Venda já importada e diferente:**
  - É atualizada só com autorização na confirmação.
  - O histórico (`vendas_externas_versoes`) guarda antes e depois dos campos comerciais.
  - Para dados pessoais, o histórico registra só "alterado".
- **Linhas recusadas:** sem identificador, data da venda vazia, inválida ou no futuro, ou empreendimento vazio.
- **Linhas que entram com alerta:**
  - `datacadastro` inválida (31/12/1969), no futuro ou depois da venda. Ela nunca substitui a entrada no CRM.
  - Telefone fora do padrão.
  - Valor zero.
- **Gravação:** em transação. Uma falha no meio não deixa vendas parciais, e o lote fica "falhou" com o motivo.
- **Nada é alterado em leads, Kanban, responsáveis, eventos ou automações** (verificado nos testes).

### Decisões adotadas (configuráveis; respondem às perguntas da Fase 1)

| Pergunta | Adotado |
|---|---|
| CPF | Só HMAC-SHA256 com chave do servidor **por empresa**, mais os 2 últimos dígitos. O número em claro nunca é gravado. Serve para confirmar identidade no futuro, sem permitir cruzar clientes do SaaS. |
| Quem importa | Permissão `importar_vendas`, dada ao Master. Pode ser concedida a outras funções em Usuários e acessos. |
| Arquivo original | Guardado em `storage/vendas/` (bloqueado ao navegador e fora do git) por **30 dias**, para reprocessar. Depois, o cron (`cron/rotinas.php`, etapa "vendas: arquivos") apaga o arquivo; os dados tratados ficam. |
| Valor | Coluna `valor` = valor de venda da unidade (VGV), **não** receita. `tabela` e `desconto` ficam guardados à parte. |
| Prefixos do corretor (CIA_, CORRETOR_…) | Guardados como texto, sem interpretação. A classificação interno/externo virá do cadastro de equivalências (Fase 3), com revisão humana. |

### Tabelas (migração 017)
`vendas_lotes`, `vendas_lote_linhas`, `vendas_externas`, `vendas_externas_versoes`; permissões `importar_vendas` e `ver_inteligencia_vendas`.

### Testes
- **Fase 2:** `tests/vendas_fase2_test.php`, com 62 verificações de dados fictícios. Cobre:
  - repetição do arquivo, atualização controlada e histórico;
  - conflito de `Venda RM` e busca pela `Venda RM` quando falta o `id`;
  - mesmo cliente com duas unidades;
  - isolamento entre empresas e permissões;
  - CPF e colunas não gravadas, e auditoria sem dados pessoais;
  - reprocessamento e descarte do original;
  - falha no meio sem gravação parcial;
  - leads intocados.
- **Mutações:** 12 de 12 detectadas.
- **Fluxo completo no navegador:** testado com planilha fictícia no formato da HM (65 colunas), no computador e no celular.

---

## 10. Fase 3 — Cruzamento (concluída)

**Telas:**
- **Conciliação** (`/pages/vendas_conciliacao.php`, permissão `ver_inteligencia_vendas`): todas as vendas, com o resultado do cruzamento. Tem filtro por situação, busca por id, Venda RM ou cliente, filtro por lote e "Dias até a compra".
- **Revisão** (`/pages/vendas_revisao.php`): a venda, os leads candidatos com a evidência de cada um e a decisão humana.
- **Equivalências** (`/pages/vendas_equivalencias.php`, permissão `revisar_correspondencias`): empreendimentos, corretores e imobiliárias parceiras.

O cruzamento roda **sozinho depois de cada importação** (passo 8 do fluxo) e no reprocessamento. Também pode ser refeito em "Recalcular cruzamento" quando entram dados novos no CRM.

### Como funciona (`includes/vendas/cruzamento.php`)
1. **Índice de contatos** (`contato_identificadores`): reconstruído a cada cruzamento a partir dos leads ativos da empresa e do telefone do contato de WhatsApp ligado ao lead.
   - Leads excluídos (LGPD) ficam de fora.
   - O índice é só um atalho; a fonte continua sendo `leads`.
2. **Candidatos:** telefone e e-mail **exatos**, depois de padronizados. A variante do 9º dígito só aponta candidato.
3. **Regras v1** (seção 4) e cronologia contra a 1ª entrada comprovada no CRM: a menor data entre o cadastro e o formulário do anúncio.
4. **Persistência idempotente:**
   - `vendas_correspondencias` guarda uma linha por venda, com status, motivo, cronologia, alertas e versão das regras.
   - `vendas_correspondencia_leads` guarda os leads ligados, cada um com evidência e papel (vinculado, candidato ou descartado).
   - Rodar de novo não duplica nada. O histórico (`vendas_conciliacao_log`) só ganha linha quando o status ou os leads mudam.
5. **A decisão humana prevalece:**
   - Confirmar, marcar "sem vínculo" ou reabrir exige justificativa. Tudo fica em `vendas_revisoes`, no histórico e na auditoria, sem dados pessoais.
   - Um novo cruzamento **não** sobrescreve uma decisão humana. Se o automático passar a discordar, aparece um alerta para revisar.
6. **Escopo na revisão:** só dá para confirmar leads que o revisor pode ver hoje (`rbac_leads_scope`). Lead fora da carteira aparece sem dados.
7. **Contatos mascarados nas telas:** (11) 9••••-4321 e f•••@dominio. O suficiente para comparar.
8. **Nada muda no CRM:** confirmar um vínculo não altera o lead, o responsável, a etapa nem as automações.

### Situações na conciliação

| Situação | Conta como "encontrada no CRM"? |
|---|---|
| Forte, por telefone, por e-mail | Sim (automática, sem conflito) |
| Confirmada na revisão | Sim |
| Ambígua | Não, até alguém revisar |
| Posterior à venda | Não como conversão: a pessoa só entrou no CRM depois de comprar |
| Sem correspondência / Sem vínculo (revisão) | Não |

### Equivalências
- **Empreendimento da planilha → empreendimento do CRM, ou "não existe no CRM":**
  - Há sugestão só quando o nome é idêntico (sem acento e caixa) e único no CRM.
  - A sugestão nunca é aplicada sozinha.
  - Depois de confirmada, `vendas_externas.empreendimento_id` é preenchido.
- **Corretor da planilha (`id_corretor`):** pode ser classificado como usuário do CRM, externo (com ou sem imobiliária parceira) ou não identificado. O prefixo do nome (CIA_, CORRETOR_…) não classifica ninguém. Essa classificação alimenta a análise de "venda por outro canal" (Fase 5).
- **Imobiliárias parceiras:** cadastro simples, que pode ser ativado e desativado.

### Testes
- **Fase 3:** `tests/vendas_fase3_test.php`, com 61 verificações de dados fictícios. Cobre:
  - cenários 1 a 5, 13, 14, 17 e 19 da especificação;
  - variante do 9º dígito, lead excluído e contato secundário de WhatsApp;
  - idempotência e histórico, e recruzamento com lead novo;
  - permissões e escopo do revisor;
  - confirmar, rejeitar e reabrir, com a decisão preservada no recruzamento;
  - equivalências e isolamento entre empresas;
  - leads intocados.
- **Mutações:** 14 de 14 detectadas.
- **Fluxo completo no navegador:** importação, cruzamento automático, conciliação, revisão e equivalências. Um gerente sem permissão é bloqueado nas telas, e a API responde 403.

---

## 11. Fase 4 — Jornada do Cliente (concluída)

**Tela:** `/pages/vendas_jornada.php?id=<venda>`, aberta pelo botão "Ver jornada" na Conciliação e na Revisão. Exige a permissão `ver_inteligencia_vendas`.

### De onde vêm os dados
Não há tabela nova. A Jornada é montada **na leitura** (`includes/vendas/jornada.php`) e não grava nada. Ela junta o que já está no CRM:

| Evento | Fonte |
|---|---|
| Cadastro, origem, UTMs, formulário do anúncio | `leads` (+ `lead_origens`, `meta_anuncios`) |
| Lista de oferta ativa | `leads.oferta_lista_id` → `oferta_listas` |
| Distribuição, 1º atendimento, 1º contato efetivo, responsáveis | `lead_atribuicoes` |
| Transferências (com motivo) | `lead_transferencias` |
| Etapas do Kanban e tempo em cada uma | `lead_etapa_historico` |
| Ligação, WhatsApp aberto pelo corretor, contato registrado | `lead_eventos` |
| Visitas agendadas e realizadas | `agenda_compromissos` |
| Mensagens do cliente e do corretor (agrupadas por dia) | `whatsapp_mensagens` |
| Campanhas de remarketing recebidas | `whatsapp_campanha_destinatarios` |
| Jornadas automáticas | `jornada_execucoes` |
| Contrato registrado no CRM | `lead_contratos` |
| Venda | `vendas_externas` (marcada como **importada**) |

**Natureza de cada evento:**
- **Registrado:** gravado pelo CRM na hora.
- **Importado:** vem do sistema comercial.
- **Reconstruído:** gerado depois pela retroalimentação, não observado na hora.

Eventos depois da venda ficam num bloco separado e não entram nas métricas.

### Quem vê o quê (regra de acesso após transferência)
- **Leads da jornada:** só os **vinculados** à venda (automático sem conflito ou confirmado na revisão). Os descartados na revisão nunca aparecem.
- **Carteira atual:** desses, só entram os que o usuário pode consultar **hoje** (`rbac_leads_scope`). As outras oportunidades ficam ocultas, com um aviso de quantas são.
- **Sem lead visível:** a jornada individual não aparece. Os números agregados continuam disponíveis nos relatórios.

### Indicadores (separados de propósito)
- **Tempo decorrido:** dias da 1ª entrada comprovada no CRM até a venda, só quando a pessoa já estava no CRM antes. Também mostra dias até o 1º atendimento humano, do 1º atendimento à venda, da 1ª visita realizada à venda e da última interação à venda.
- **Tempo de responsabilidade:** dias com cada corretor, cortados na data da venda.
- **Interação efetiva:** quantidade de **dias** com contato registrado, resposta do cliente ou visita. Não confundir com tempo de jornada.
- **Contagens:** transferências, redistribuições por inatividade, tentativas de contato, respostas no WhatsApp, visitas, campanhas e jornadas automáticas **antes** da venda.
- **Oferta ativa:** "Sim" quando há registro. Sem registro, aparece como "sem registro" e não é presumida.
- **Períodos sem interação:** intervalos de mais de 30 dias sem interação humana. Campanha automática não conta como interação.

### Entrada × compra
- **O que mostra:** todos os interesses em ordem (1º, intermediários) e o empreendimento comprado, com o resultado "mesmo", "diferente" ou "indisponível".
- **Quando fica indisponível:** sem equivalência do empreendimento comprado, ou sem interesse registrado no lead.
- **Nenhum interesse é eleito como responsável pela venda.**

### Origem × mídia da venda
- **Lado a lado:** a 1ª origem no CRM (e a última antes da compra), as UTMs e o anúncio com a prévia do criativo (o mesmo card da ficha do lead) aparecem ao lado da **mídia declarada na venda**.
- **Uma não substitui a outra:** a diferença entre as duas não é tratada como erro.
- **Anúncio ausente:** quando falta, aparece "não disponível".

### Classificação comercial (análise gerencial, sem crédito nem culpa)

| Classificação | Quando |
|---|---|
| Venda pelo responsável do CRM | O vendedor (equivalência confirmada) era um dos responsáveis no CRM na data da venda |
| Venda por outro corretor da mesma equipe | O vendedor é usuário do CRM da mesma equipe de um dos responsáveis na data |
| Venda por outra equipe | O vendedor é usuário do CRM de outra equipe |
| Venda por canal externo identificado | A equivalência do vendedor está marcada como "externo" |
| Responsável pela venda não identificado | Vendedor sem equivalência ou marcado como "não identificado" |
| Sem histórico do responsável na data da venda | Não há atribuição registrada que cubra a data |
| Correspondência pendente de revisão | A venda está ambígua |
| Cliente não estava no CRM antes da venda | Cruzamento "posterior" ou sem vínculo |

O prefixo do nome do corretor nunca decide nada: só vale a equivalência confirmada por uma pessoa.

### Histórico incompleto
A tela avisa quando:
- falta histórico de distribuição: o 1º corretor fica "não disponível" e **não** se usa o responsável atual no lugar;
- falta histórico de etapas;
- parte do histórico foi reconstruída;
- o lead veio do Meta Ads sem o anúncio registrado.

### Testes
- **Fase 4:** `tests/vendas_fase4_test.php`, com 50 verificações. Usa um cliente fictício completo, com dois interesses, transferência por inatividade, oferta ativa, visita, WhatsApp, campanhas antes e depois da venda e jornada automática. Cobre:
  - cenários 5 a 12, 14, 15 e 18 da especificação;
  - lead descartado na revisão;
  - isolamento entre empresas;
  - histórico incompleto.
- **Mutações:** 15 de 15 detectadas.
- **Fluxo completo no navegador:** computador e celular, a 390px sem rolagem lateral. Um gerente sem permissão é bloqueado.

---

## 12. Fase 5 — Painel e relatórios (concluída)

**Tela:** `/pages/vendas_painel.php` (Relatórios → Inteligência de Vendas → Painel de vendas × CRM). Exige a permissão `ver_inteligencia_vendas`.

### Uma fonte só
`vendas_fatos()` (`includes/vendas/painel.php`) monta um fato por venda em cerca de 8 consultas em lote, sem consultas por venda. Os dados vêm de:
- vendas e correspondências;
- leads vinculados, atribuições e transferências;
- campanhas de WhatsApp e anúncios;
- equivalências de corretores.

Todos os indicadores e relatórios saem desses fatos. A classificação comercial é a mesma função pura usada na Jornada (`vendas_classificar_comercial`), e o teste confere venda a venda que **painel = jornada**.

**Regras:**
- Entram só as vendas cuja situação foi marcada como venda (`eh_venda`). Distratos ficam fora.
- **Encontrada no CRM** = ligada sem conflito (ou confirmada na revisão) **e** com entrada no CRM até o dia da venda.
- Transferências, oferta ativa, remarketing, responsáveis e interesses contam **só até a data da venda**.
- Atribuições encerradas antes da venda e as que começaram depois não fazem de ninguém o "responsável na data".
- Ambíguas não emprestam os leads candidatos.

### Filtros
| Filtro | O que filtra |
|---|---|
| Período | Data da venda (mesmos períodos da Central) |
| Empreendimento | Empreendimento comprado (pela equivalência) |
| Equipe e corretor | Responsável no CRM **na data da venda** |
| Origem | Origem no CRM do 1º cadastro |
| Situação | Situação da conciliação |

Filtros inválidos são ignorados. No celular, os filtros ficam recolhidos num botão, com o número de filtros ativos.

### Abas
| Aba | Conteúdo |
|---|---|
| Visão geral | Indicadores do §14: importadas, encontradas (e %), por telefone, e-mail ou ambos, confirmadas na revisão, ambíguas, posteriores, sem correspondência; múltiplas oportunidades, empreendimento diferente, oferta ativa, remarketing, redistribuição, outro canal; VGV total e das encontradas; jornada média e mediana |
| Encontradas na base | Por empreendimento comprado (vendas, encontradas, %, VGV) e por mídia declarada na venda |
| Jornada até a compra | Média, mediana, faixas (até 30, 31–90, 91–180, 181–365 dias, mais de 1 ano) e tempo por origem |
| Entre empreendimentos | 1º interesse → comprado, quantas tiveram interesses intermediários e VGV. Avisa quantas ficaram de fora por falta de equivalência |
| Anúncios | Campanhas e anúncios do 1º cadastro dos clientes que compraram |
| Após remarketing | Clientes com campanha antes da compra e o tempo entre a última campanha e a venda |
| Após redistribuição | Transferência, redistribuição ou oferta ativa antes da compra, com 1º corretor, responsável na venda e classificação |
| Outros canais | Classificação comercial e a lista "vendas realizadas por outro canal com histórico anterior no CRM" (outro corretor da equipe, outra equipe ou canal externo) |
| Produtividade | Por corretor: recebidos, atendidos, visitas, transferidos (CRM, no período) ao lado das vendas em que era o responsável na data e das em que foi o 1º corretor. Populações diferentes, **sem taxa** entre elas |
| Resultados | Tabela do §15: cliente, comprado, data, valor, cruzamento, origem, empreendimento e corretor iniciais, corretor da venda, transferências, oferta, remarketing, jornada e "Ver jornada" ou "Revisar correspondência". Tem busca, ordenação e paginação |

Cada aba termina com **fonte, período, data de geração e nota metodológica**: associação no tempo não é causa, VGV não é receita, e ninguém recebe crédito nem culpa.

### Acesso
- Os indicadores são agregados e não mostram a linha do tempo de ninguém.
- "Ver jornada" leva à Jornada, que aplica a carteira atual do usuário.
- Um gerente sem a permissão é bloqueado.
- Exportação (Excel, CSV, PDF): Fase 6.

### Testes
- **Fase 5:** `tests/vendas_fase5_test.php`, com 47 verificações. Cobre:
  - a classificação pura, todos os indicadores, cada filtro e os relatórios especializados;
  - coerência com a Jornada;
  - eventos depois da venda e atribuições encerradas;
  - confirmada na revisão com entrada posterior;
  - isolamento entre empresas.
- **Mutações:** 16 de 16 detectadas.
- **Navegador:** as 10 abas sem erro; no celular, todas cabem em 390px; gerente bloqueado.
