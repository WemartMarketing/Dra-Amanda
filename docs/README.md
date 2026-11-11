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

### Limitações conhecidas (ficam para as próximas etapas)
- **Etapa 3**: regras de visibilidade na transferência de leads (gerente
  anterior perde acesso etc.). Hoje mover um corretor de equipe altera só a
  estrutura — os leads mantêm o `equipe_id` gravado neles (fica registrado
  na auditoria como `usuario.equipe_alterada`). Também: agenda, ficha com
  abas completas e etapas do Kanban configuráveis.
- **Etapa 4**: presença por geolocalização e distribuição automática da
  roleta. Os plantões já guardam coordenadas e raio para isso.
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
```

Observação: o `php -S` ignora `.htaccess`. Para testar as proteções de
`uploads/`, `includes/` e `sql/`, use Apache com `AllowOverride All`.

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

Todos os nomes, leads e telefones do seed são **fictícios**.

## Estrutura

```
index.php, login.php, logout.php   entrada e autenticação
includes/
  auth.php, csrf.php, rbac.php     sessão, CSRF, escopo e permissões
  funcoes.php                      funções padrão e catálogo de permissões
  api.php                          início comum dos endpoints (sessão → CSRF → permissão)
  estrutura.php                    rótulos e upload seguro de imagem
  auditoria.php, db.php, layout_*  auditoria, PDO e layout
pages/                             telas (inicio, kanban, lead, relatorios, equipes,
                                   empreendimentos, empreendimento, plantoes, roletas,
                                   usuarios, superadmin)
api/                               endpoints POST chamados por fetch()
uploads/                           imagens enviadas (protegido por .htaccess)
assets/css/app.css, assets/js/app.js
sql/                               schema, seeds e migrações (ordem acima)
referencias/                       protótipo UX original (não é código de produção)
```
