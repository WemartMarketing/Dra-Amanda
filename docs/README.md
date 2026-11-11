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
  KANBAN, MINHA AGENDA e ENTRAR NA ROLETA (o check-in com localização chega
  na Etapa 4 — o botão leva à tela da roleta e diz isso). Gestores mantêm a
  visão da equipe.
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

### Limitações conhecidas (ficam para as próximas etapas)
- **Etapa 4**: presença por geolocalização (check-in na roleta) e
  distribuição automática da roleta, incluindo redistribuição automática
  de leads parados (o motivo *inatividade* já existe em
  `lead_transferencias`). Os plantões já guardam coordenadas e raio para isso.
- **Etapa 5**: Meta Lead Ads, oferta ativa e recuperação automática.
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
```

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
  arquivos.php                     upload/validação de arquivos de lead
  auditoria.php, db.php, layout_*  auditoria, PDO e layout
pages/                             telas (inicio, leads, kanban, lead, agenda, funil, perfil,
                                   relatorios, equipes, empreendimentos, empreendimento,
                                   plantoes, roletas, usuarios, superadmin)
api/                               endpoints POST chamados por fetch()
uploads/                           imagens de empreendimentos (protegido por .htaccess)
storage/                           arquivos privados de leads — nunca servidos direto (.htaccess nega tudo)
assets/css/app.css, assets/js/app.js
sql/                               schema, seeds e migrações (ordem acima)
referencias/                       protótipo UX original (não é código de produção)
```
