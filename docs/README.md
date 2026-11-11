# WeMart Sales

CRM SaaS de vendas imobiliárias multiempresa — PHP + MySQL + JavaScript
puro (sem Node.js, sem framework), compatível com hospedagem compartilhada
HostGator. O desenvolvimento segue as etapas do prompt mestre.

## Etapas entregues

### Etapa 1 — Fundação
- Login por sessão com senha em hash, **bloqueio após 5 tentativas
  erradas em 15 min** (`login_tentativas`).
- **Multiempresa**: toda consulta filtra por `empresa_id`.
- **Funções e permissões configuráveis** (`funcoes`, `funcao_permissoes`,
  `permissoes`): cada empresa tem as 7 funções padrão e o Master pode criar
  outras, escolhendo o **escopo** (quais leads a pessoa enxerga) e as
  **permissões** (o que ela pode fazer). Escopo → `rbac_leads_scope()`;
  ações → `usuario_tem_permissao()` (`includes/rbac.php`), sempre
  verificados no servidor.
- **Superadmin WeMart** (`pages/superadmin.php`): lista e cria empresas
  (com Master e funções iniciais). Não acessa leads das empresas.
- **CSRF** em todo formulário e endpoint de escrita (`includes/csrf.php`).
- **Auditoria** (`auditoria`): login, usuários, funções, etapas de lead,
  empresas e — a partir da Etapa 2 — toda a estrutura comercial.

### Etapa 2 — Estrutura comercial
- **Equipes** (`pages/equipes.php`, permissão *Gerenciar equipes e
  estrutura*): árvore Superintendência → Equipes → Gerente + Corretores.
  Criar/editar/excluir superintendência (com superintendente) e equipe
  (com gerente e empreendimentos atendidos); adicionar, mover e tirar
  corretores de equipes. Exclusão só é permitida quando não deixa nada
  órfão (superintendência sem equipes; equipe sem corretores e sem leads).
- **Empreendimentos** (`pages/empreendimentos.php` e `empreendimento.php`):
  grade de cards para todos que veem leads (corretor consulta, não edita);
  cadastro completo com *Gerenciar empreendimentos* — nome, construtora,
  endereço, bairro, cidade, UF, status comercial, descrição e **imagem**.
  O detalhe mostra plantões, roletas e equipes vinculados. Nunca é
  excluído: "inativo" tira das listas e preserva os leads.
- **Upload seguro de imagem**: só JPG/PNG/WEBP verificados pelo conteúdo
  (`finfo` + `getimagesize`, nunca pela extensão), até 3 MB (ou o limite do
  servidor, se menor), **re-gravada pelo GD** (descarta conteúdo embutido),
  nome aleatório de 32 caracteres. `uploads/.htaccess` só libera arquivos
  com esse padrão de nome e extensão de imagem e desliga a execução de
  scripts na pasta.
- **Plantões** (`pages/plantoes.php`, *Gerenciar plantões*): endereço,
  latitude/longitude (digitadas ou pelo botão "Usar minha localização
  atual", que usa o GPS do navegador e exige HTTPS), raio de liberação
  (50–5.000 m, padrão 300 m), ativo e empreendimentos atendidos.
- **Roletas** agora pertencem a um plantão: endereço, coordenadas e raio
  vêm do plantão; a roleta mantém nome, horário e status.
- **Vínculo usuário ↔ produto**: ao editar um usuário cuja função tem
  escopo "Produtos vinculados" (GP do Produto), aparece a lista de
  empreendimentos — define quais leads ele enxerga.
- Duas permissões novas: *Gerenciar empreendimentos* e *Gerenciar
  plantões* (concedidas ao Master da empresa).

### Etapa 3 — CRM
- **Etapas do funil configuráveis** (*Etapas do funil*, permissão
  *Configurar funil*): renomear, cor, ordem (↑/↓), mostrar ou não no Kanban,
  ativar/desativar e criar etapas. Cada etapa aponta para uma **categoria**
  fixa (Tentando contato, Em atendimento, Visita agendada…); `leads.etapa`
  sempre recebe a categoria, então Relatórios e Início continuam contando
  certo. Categoria de etapa com leads não muda; desativar etapa com leads
  exige escolher o destino.
- **Kanban** com as etapas ativas e coloridas, card com telefone,
  empreendimento, tempo desde a última atividade, próxima ação, alerta de
  atraso, prioridade, WhatsApp (`wa.me/55…`), ligar e ficha. Arrastar ou
  botão **Mover etapa**. Filtros: empreendimento, período, etapa,
  prioridade e (gestores) corretor. No celular, uma coluna por vez com abas.
- **Leads** (lista): busca por nome/telefone, mesmos filtros, paginação e
  transferência em lote para gestores.
- **Cadastro de lead** com checagem de telefone duplicado que nunca revela
  lead de outra carteira; gestor escolhe o corretor; a equipe vem do
  responsável; entra na primeira etapa ativa com evento de atribuição.
- **Ficha** com abas Resumo | Atendimento | Histórico | Agenda | Arquivos
  (aba fica na URL). Ligar e WhatsApp registram o contato sozinhos.
  Atendimento é um formulário único: o que aconteceu + etapa + próximo
  contato (vira retorno na agenda). Histórico com ícones e filtro por tipo.
  Excluir é exclusão lógica com confirmação e auditoria.
- **Agenda** (Minha agenda): atrasados, hoje e próximos, visão semanal;
  visita, retorno ou tarefa (com ou sem lead); realizado, cliente ausente,
  reagendar (novo compromisso ligado ao anterior) e cancelar. Agendar
  visita move o lead para "Visita agendada"; marcar realizada oferece mover
  para "Visita realizada".
- **Início do corretor**: contagens, atividades pendentes, próximos
  compromissos, alertas de leads parados e os botões MEUS LEADS, MEU
  KANBAN, MINHA AGENDA e ENTRAR NA ROLETA (leva a **Minha roleta**, ver
  Etapa 4). Gestores mantêm a visão da equipe.
- **Transferência de leads** (permissão *Redistribuir leads*): da ficha ou
  em lote, só para corretores do próprio escopo. Grava em
  `lead_transferencias` a estrutura de origem e destino (corretor, equipe,
  gerente, superintendência). A estrutura anterior perde o acesso ao lead e
  fica só com o número agregado em **Relatórios → Transferências e
  perdas** (sem nomes nem telefones). Quando um corretor muda de equipe,
  seus leads abertos vão junto (motivo *mudança de equipe*).
- **Arquivos do lead** (permissão *Acessar arquivos de leads*): PDF, JPG,
  PNG, WEBP, DOCX e XLSX validados pelo conteúdo, até 10 MB (ou o limite do
  servidor). Ficam em `storage/lead_arquivos/` com nome aleatório e sem
  extensão; `storage/` é bloqueado por `.htaccess` e o download só passa por
  `api/lead_arquivo_baixar.php` (login + permissão + escopo do lead).
- **Meu perfil**: nome, e-mail e troca de senha (exige a senha atual).
- Menu do corretor: Início, Meus leads, Meu Kanban, Minha agenda, Minha
  roleta e Meu perfil; no celular, barra inferior Início | Leads | Kanban |
  Agenda | Mais.

### Etapa 4 — Roleta
Todas as regras ficam em `includes/roleta.php` (páginas, APIs, cron e, na
Etapa 5, o webhook do Meta Ads chamam as mesmas funções).
- **Minha roleta** (corretor, permissão *Participar de roletas*): status
  grande — verde "DENTRO DO PLANTÃO — PODE ENTRAR NA ROLETA" ou vermelho
  "FORA DO PLANTÃO — ENTRADA BLOQUEADA" com o motivo (distância, GPS
  impreciso, fora do horário/dia, roleta ou corretor pausado). Botões
  Verificar localização / Entrar / Sair; enquanto está dentro, a página
  reconfirma a posição a cada *intervalo de verificação* e mostra "na
  roleta desde HH:MM · próxima verificação em N min", a posição no rodízio
  e os leads recebidos hoje. Sem renovação, a presença vence sozinha depois
  da *validade* (a página precisa ficar aberta — o aviso aparece na tela).
- **Quem decide é o servidor**: o navegador só manda latitude, longitude e
  precisão; o servidor confere vínculo, pausa, roleta ativa, dia (ISO 1–7),
  horário (America/Sao_Paulo, inclusive virando a meia-noite), precisão ≤
  máximo e distância (haversine) ≤ raio do plantão. Toda tentativa — aceita
  ou recusada — fica em `roleta_presencas`; a presença em `roleta_sessoes`
  (no máximo uma aberta por corretor e roleta). O usuário vem sempre da
  sessão: ninguém faz check-in por outro nem numa roleta à qual não está
  vinculado.
- **Rodízio** (`roleta_distribuir_lead`): recebe, entre os corretores com
  presença válida, não pausados e abaixo do limite diário, quem está há mais
  tempo sem receber. Transação com trava na linha do lead e da roleta: dois
  leads simultâneos nunca vão para o mesmo corretor fora da vez, e o mesmo
  lead nunca é entregue duas vezes. Empreendimento em mais de uma roleta:
  tenta primeiro a de menor *prioridade*.
- **Fila de espera**: sem ninguém disponível, o lead fica sem responsável em
  `roleta_fila`. É entregue quando um corretor entra na roleta, pelo botão
  *Distribuir fila agora* / *Distribuir agora*, pelo cron
  `cron/processar_fila.php` ou à mão (*Atribuir manualmente* abre a
  transferência). Leads na fila aparecem para quem vê toda a empresa,
  todos os produtos ou o produto vinculado; gerentes e superintendentes os
  veem depois de distribuídos para a equipe deles.
- **Roletas** (gestão): regras (dias, horário, exigir presença, precisão,
  validade, intervalo, limite diário), prioridade dos empreendimentos,
  corretores com status ao vivo ("Na roleta desde 10:12 · última verificação
  há 2 min · a 85 m", "Fora — presença expirou às 11:40", "Pausado pelo
  gestor"), ordem do rodízio, Pausar/Retomar/Tirar agora/Remover, fila de
  espera e histórico de distribuição (o nome do lead só aparece para quem
  pode vê-lo; os outros veem "Lead #id").
- **Novo lead → Distribuir pela roleta**: padrão para gestores quando o
  empreendimento tem roleta ativa; a tela diz quem recebeu ou que o lead foi
  para a fila.
- **Relatórios → Resultados por roleta**: leads distribuídos, corretores que
  receberam, vendas e conversão (dentro do escopo de quem vê).

### Etapa 5 — Integrações e oferta ativa
- **Feedback válido** (`lead_registrar_feedback`, coluna `leads.ultimo_feedback_em`):
  só reinicia o prazo de recuperação o que comprova atendimento — mudar a
  etapa, *Registrar atendimento/contato* com texto, marcar visita/retorno
  como realizado ou cliente ausente e receber o lead (cadastro, roleta,
  transferência, divisão da oferta ativa). Anotação, arquivo, edição de
  dados, clicar em Ligar/WhatsApp ou só criar um compromisso **não** contam.
- **Recuperação automática** (`includes/recuperacao.php`, tela *Recuperação
  de leads*, permissão *Configurar recuperação*): por padrão 4 dias sem
  feedback válido nas etapas Tentando contato / Primeiro contato / Em
  atendimento, sem visita ou retorno futuro agendado. O lead perde o
  responsável, ganha a linha `inatividade` em `lead_transferencias` (estrutura
  anterior completa; o destino é preenchido quando alguém recebe), evento no
  histórico, auditoria e vai para a fila da roleta (origem *Recuperação*)
  **sem voltar para quem o perdeu** (`evitar_usuario_id`; se só ele estiver
  disponível, o lead espera). Um dia antes o corretor vê *“Vai para
  recuperação amanhã”* no Início e no card do Kanban. Relatórios → *Perdas
  por inatividade* agrega por corretor, equipe ou superintendência anterior
  (só números — o gerente anterior não vê mais o cadastro).
- **Meta Lead Ads** (`webhook/meta.php`, `includes/meta.php`, tela
  *Integrações*, permissão *Gerenciar integrações*): verificação do webhook,
  assinatura `X-Hub-Signature-256` obrigatória, avisos gravados em
  `meta_eventos` antes de processar (deduplicados por `leadgen_id`), busca
  dos dados na Graph API com o token da página **cifrado** (APP_KEY), lead
  criado com origem Meta Ads e enviado para a roleta do empreendimento
  ligado ao formulário. Telefone já cadastrado → registro no lead existente,
  sem duplicar. Falhas: novas tentativas em 5, 15, 60 e 180 min (desiste após
  6, com botão *Reprocessar*). Sem credenciais reais a tela diz claramente
  que nada é recebido — não há simulação. Passo a passo:
  `docs/INTEGRACAO_META.md`.
- **Oferta ativa** (telas *Oferta ativa* e *Importar planilha*, permissão
  *Importar listas e gerenciar oferta ativa*: Master, Gerente Geral,
  Superintendente): CSV (`;` `,` ou tab; UTF-8 ou Windows-1252) e XLSX até 5 MB
  / 5.000 linhas; prévia de 10 linhas com sugestão das colunas; relatório com
  importados, duplicados (na empresa ou repetidos na planilha) e inválidos,
  com o CSV das linhas recusadas. Os leads entram sem corretor e são
  distribuídos *pela roleta* ou *divididos igualmente* entre corretores da
  estrutura de quem distribui. Cada lista mostra andamento por etapa, por
  corretor e o histórico; *Ver leads da lista* abre Leads/Kanban filtrados
  (`?lista=ID`).
- **Uma linha de cron** (`cron/rotinas.php`, a cada 15 min): presenças
  vencidas, fila da roleta, novas tentativas do Meta, recuperação e limpeza
  dos arquivos temporários de importação. `cron/processar_fila.php` continua
  funcionando (delega para a nova rotina).

### Limitações conhecidas (ficam para as próximas etapas)
- A integração com a Meta só recebe leads depois que as credenciais reais
  forem cadastradas (app da Meta aprovado, `META_APP_SECRET`,
  `META_VERIFY_TOKEN`, `APP_KEY` e o token de cada página).
- A presença depende da página aberta no celular: navegadores não permitem
  GPS em segundo plano numa página web.
- **Etapa 6**: dashboards completos e exportação Excel (o botão existe e
  avisa que está em desenvolvimento).
- Logo oficial da WeMart ainda não foi enviada — o sistema usa o texto
  "WeMart Sales". As 5 referências visuais recebidas são variações entre
  si e servem de guia, não de molde literal.

## Rodando localmente

Banco de desenvolvimento: MySQL/MariaDB em `localhost`, banco
`wemart_sales`, usuário `wemart`, senha `wemart_dev_pw` (em
`includes/config.php`, que não é versionado).

```bash
service mariadb start
php -d upload_max_filesize=4M -S localhost:8000
```

Recriar o banco do zero (a ordem importa):

```bash
mysql -u wemart -pwemart_dev_pw wemart_sales < sql/schema.sql
mysql -u wemart -pwemart_dev_pw wemart_sales < sql/seed.sql
mysql -u wemart -pwemart_dev_pw wemart_sales < sql/migration_002_funcoes_permissoes.sql
php sql/migration_002_backfill.php
mysql -u wemart -pwemart_dev_pw wemart_sales < sql/migration_003_estrutura_comercial.sql
mysql -u wemart -pwemart_dev_pw wemart_sales < sql/seed_003.sql
mysql -u wemart -pwemart_dev_pw wemart_sales < sql/migration_004_crm.sql
mysql -u wemart -pwemart_dev_pw wemart_sales < sql/seed_004.sql
mysql -u wemart -pwemart_dev_pw wemart_sales < sql/migration_005_roleta.sql
mysql -u wemart -pwemart_dev_pw wemart_sales < sql/seed_005.sql
```

Todas as migrações e seeds rodam mais de uma vez sem duplicar nada e
funcionam igual em MySQL 5.7+/8.0 e MariaDB 10.3+ (sem procedures,
funções ou triggers). Para a fila da roleta: `php cron/processar_fila.php`.

Observação: o `php -S` ignora `.htaccess` (inclusive o bloqueio de
`storage/`). Para testar as proteções de `uploads/`, `storage/`,
`includes/` e `sql/`, use Apache com `AllowOverride All`.

### Login de demonstração

Senha de todas as contas: **demo123**

| Papel | E-mail |
|---|---|
| Superadmin WeMart | superadmin@wemart.demo |
| Master da empresa | fernanda.gestora@hmvendas.demo |
| Gerente Geral | beatriz.geral@hmvendas.demo |
| GP Líder | eduardo.lider@hmvendas.demo |
| GP do Produto | patricia.produto@hmvendas.demo |
| Superintendente (Zona Oeste) | roberto.super@hmvendas.demo |
| Superintendente (Zona Sul) | camila.super@hmvendas.demo |
| Gerente (Equipe Zona Oeste) | ana.gerente@hmvendas.demo |
| Gerente (Equipe Zona Sul) | diego.gerente@hmvendas.demo |
| Corretor | carlos@hmvendas.demo |

### Como testar a Etapa 2
1. Entre como Master → **Equipes**: crie uma superintendência, uma equipe
   dentro dela com gerente e empreendimentos, e mova um corretor para ela
   pelo seletor ao lado do nome.
2. **Empreendimentos** → *Novo empreendimento*: preencha e envie uma imagem
   JPG/PNG. Tente também um arquivo de texto renomeado para `.png` — deve
   ser recusado.
3. **Plantões** → *Novo plantão*: informe coordenadas (ex.: `-23,5627` e
   `-46.6696` — vírgula ou ponto) e marque empreendimentos.
4. **Roletas** → *Editar regras*: escolha o plantão; o detalhe passa a
   mostrar o endereço e o raio dele.
5. **Usuários e acessos** → editar a Patrícia (GP do Produto): marque só
   um empreendimento; entrando como ela, Kanban e Relatórios mostram só os
   leads desse empreendimento.
6. Entre como corretor: Equipes e Plantões mostram "Acesso restrito";
   Empreendimentos aparece só para consulta.

### Como testar a Etapa 3
1. Como Master → **Etapas do funil**: renomeie uma etapa, crie "Análise de
   crédito" na categoria *Em atendimento* e suba-a com ↑. O Kanban mostra a
   nova ordem; mova um lead para ela e veja Relatórios contar o lead em
   *Em atendimento*. Tente desativá-la: o sistema pede o destino dos leads.
2. Como corretor (`carlos@hmvendas.demo`): **Início** → MEU KANBAN, use
   *Mover etapa*; abra uma ficha → aba **Atendimento**, registre o contato
   com "Amanhã 10h" e confira o retorno em **Minha agenda**.
3. Na agenda: *Reagendar*, *Cliente ausente* e *Realizado* (numa visita, o
   sistema oferece mover o lead para "Visita realizada").
4. Como Master, na ficha de um lead da Equipe Zona Oeste: *Mais →
   Transferir lead* para a Juliana (Zona Sul). Entrando como
   `ana.gerente@…`, a ficha passa a dar "Lead não encontrado" e Relatórios
   mostra +1 em *Transferências e perdas*; `diego.gerente@…` passa a ver o
   lead.
5. Aba **Arquivos**: envie um PDF; um `.php` ou `.exe` (mesmo renomeado
   para `.pdf`) é recusado.
6. **Meu perfil**: trocar a senha exige a senha atual.

### Como testar a Etapa 4
O seed deixa todos os corretores **fora** da roleta e 2 leads na fila da
Casa HM — Zona Oeste (plantão em -23.52621, -46.67669, raio 300 m, seg a
sáb 09:00–18:00).
1. Como `carlos@hmvendas.demo`, no celular (HTTPS) ou com a localização
   simulada no DevTools: **Início → ENTRAR NA ROLETA**. Dentro do raio a
   tela fica verde; *Entrar na roleta* abre a presença e os 2 leads da fila
   chegam para ele.
2. Simule 2 km de distância: vermelho com a distância. Precisão de 500 m:
   "GPS impreciso". Fora do horário: "Fora do horário do plantão".
3. Como `fernanda.gestora@hmvendas.demo` → **Roletas**: veja o status ao
   vivo, pause o Carlos (ele sai na hora) e retome; *Editar regras* muda
   dias, horário, precisão, validade e limite diário.
4. **Novo lead** com empreendimento Smart Marquês: "Distribuir pela roleta"
   já vem marcado; a tela diz quem recebeu (ou que foi para a fila).

### Como testar a Etapa 5
1. Como `carlos@hmvendas.demo`: o Início mostra **Vai para recuperação**
   para *Otávio Bezerra* (3 dias sem feedback). Uma anotação não tira o
   aviso; mudar a etapa tira.
2. Rode `php cron/rotinas.php`: *Sílvia Moraes* (5 dias, da Fernanda Costa)
   sai da carteira, ganha o evento de recuperação e vai para a fila da roleta
   sem voltar para a Fernanda Costa.
3. Como `fernanda.gestora@hmvendas.demo`: **Oferta ativa** → *Feirão Zona
   Sul — demonstração* (2 leads ainda sem corretor) → *Dividir igualmente*;
   **Importar planilha** com um CSV seu; **Recuperação de leads** para mudar
   a regra; **Relatórios → Perdas por inatividade**.
4. **Integrações** mostra “Integração ainda não configurada” até você
   cadastrar uma página real (ver `docs/INTEGRACAO_META.md`).

Todos os nomes, leads e telefones do seed são **fictícios**.

## Estrutura

```
index.php, login.php, logout.php   entrada e autenticação
includes/
  auth.php, csrf.php, rbac.php     sessão, CSRF, escopo e permissões
  funcoes.php                      funções padrão e catálogo de permissões
  api.php                          início comum dos endpoints (sessão → CSRF → permissão)
  estrutura.php                    rótulos e upload seguro de imagem
  funil.php                        etapas do funil e lead_mudar_etapa() (única forma de mudar etapa)
  leads.php, leads_lista.php       helpers de lead, telefone, filtros e consultas da lista/Kanban
  lead_componentes.php             card do lead e modais (mover, transferir, agendar)
  agenda.php, transferencia.php    agenda comercial e transferência de leads
  roleta.php                       presença, rodízio, fila e distribuição (única fonte das regras)
  recuperacao.php                  recuperação automática por inatividade e avisos
  meta.php, cripto.php             Meta Lead Ads (Graph API, eventos) e cifragem de tokens
  importacao.php                   leitura segura de CSV/XLSX e listas de oferta ativa
  arquivos.php                     upload/validação de arquivos de lead
  auditoria.php, db.php, layout_*  auditoria, PDO e layout
pages/                             telas (inicio, leads, kanban, lead, agenda, funil, perfil,
                                   relatorios, equipes, empreendimentos, empreendimento,
                                   plantoes, roletas, minha_roleta, usuarios, superadmin,
                                   oferta, importar, recuperacao, integracoes)
cron/rotinas.php                   rotina única do cron (só linha de comando; HTTP → 403)
webhook/meta.php                   webhook público do Meta Lead Ads (assinatura obrigatória)
api/                               endpoints POST chamados por fetch()
uploads/                           imagens de empreendimentos (protegido por .htaccess)
storage/                           arquivos privados de leads e temporários de importação — nunca servidos direto (.htaccess nega tudo)
assets/css/app.css, assets/js/app.js
sql/                               schema, seeds e migrações (ordem acima)
referencias/                       protótipo UX original (não é código de produção)
```
