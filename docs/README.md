# WeMart Sales

CRM de vendas imobiliárias multiempresa — construído em PHP + MySQL +
JavaScript puro (sem Node.js, sem framework), compatível com hospedagem
compartilhada HostGator.

## O que está pronto nesta etapa

- **Login/sessão** própria (sem dependências externas) com senha com hash.
- **Multiempresa + RBAC**: toda consulta de leads passa por
  `includes/rbac.php`, que decide o escopo de cada cargo (ver comentário
  no topo da tabela `usuarios` em `sql/schema.sql`).
- **Início** (visão do corretor): KPIs reais (carteira, pendências, visitas,
  vendas do mês), lista de prioridades do dia e card de plantão/roleta —
  tudo calculado a partir do banco, não fixo.
- **Meu Kanban**: colunas reais por etapa, drag-and-drop e botões
  "Voltar"/"Avançar" (persistem no banco e gravam no histórico), filtro por
  empreendimento, criação de lead.
- **Ficha do lead**: dados reais, abas de etapa, formulário "Atualizar
  atendimento" (grava em `leads` e em `lead_eventos`), tarefas
  (`lead_tarefas`, com checkbox via AJAX) e histórico cronológico real.
- **Relatórios**: KPIs (leads recebidos, atendimentos, visitas agendadas,
  vendas, perdas por inatividade), gráfico de barras "Leads por dia",
  funil de conversão, resultado por empreendimento e oportunidades
  perdidas por motivo — todos calculados em tempo real a partir do banco
  (sem biblioteca de gráficos externa, barras desenhadas em CSS/SVG
  simples), com filtros reais de período/empreendimento/superintendência/
  gerente, sempre dentro do escopo RBAC do cargo logado.
- **Roletas**: lista e detalhe reais; cargos de gestão podem criar/editar
  uma roleta (nome, raio, horário, ativa) e vincular/remover
  empreendimentos e corretores; a lista "Corretores habilitados" mostra
  Disponível/Bloqueado a partir da presença mais recente registrada em
  `roleta_presencas` (janela de 6h + dentro do raio). Corretor só
  visualiza, nunca edita (checado também no servidor, não só escondendo
  botão).
- **Usuários e acessos**: lista real de usuários da empresa; cargos de
  gestão podem criar usuário, editar nome/e-mail/senha/cargo/equipe e
  ativar/desativar (nunca exclui — leads e histórico continuam
  referenciando o id). A tabela "Funções da empresa" já contava usuários
  reais por cargo desde a etapa anterior.

### Fora do escopo desta etapa (não fingido — apenas ainda não construído)

- **Geofencing completo da roleta**: a tela Roletas gerencia configuração
  e mostra a última presença registrada, mas não há automação real de
  "corretor abriu o app, enviou geolocalização, roleta decidiu" — isso
  depende de um app/PWA de campo que ainda não existe.
- **Distribuição automática de leads pela roleta** (o "giro" em si —
  escolher o próximo corretor e atribuir o lead): a tela só configura
  quem está habilitado; a atribuição automática fica para quando a
  entrada de leads via Meta Ads também existir.
- Entrada automatizada de leads via Meta Ads.
- Oferta ativa / recuperação automática de leads sem feedback após 4 dias
  (a regra aparece como aviso na ficha, mas não há job automático ainda).
- **Exportação para Excel** (Relatórios) — o botão existe e mostra
  claramente "em desenvolvimento" ao ser clicado, sem gerar um arquivo
  falso.
- **Cargos/funções personalizadas** (Usuários e acessos) — o sistema usa
  as 8 funções fixas do schema (`usuarios.cargo`, um ENUM); "Criar
  função" mostra "em desenvolvimento" em vez de simular suporte a cargos
  arbitrários com escopos próprios, o que exigiria uma tabela de
  permissões configurável nova.
- Auditoria (`auditoria`) — a tabela existe no schema, mas nenhuma tela
  ainda grava nela.

## Rodando localmente

Banco de desenvolvimento já criado neste ambiente:
MySQL/MariaDB, host `localhost`, banco `wemart_sales`, usuário `wemart`,
senha `wemart_dev_pw` (ver `includes/config.php`).

```bash
service mariadb start   # se o serviço não estiver rodando
php -S localhost:8000
```

Para recriar o banco do zero:

```bash
mysql -u wemart -pwemart_dev_pw wemart_sales < sql/schema.sql
mysql -u wemart -pwemart_dev_pw wemart_sales < sql/seed.sql
```

### Login de demonstração

Senha para todas as contas: **demo123**

| Papel | E-mail |
|---|---|
| Corretor (Equipe Zona Oeste) | carlos@hmvendas.demo |
| Corretor (Equipe Zona Oeste) | fernanda.costa@hmvendas.demo |
| Corretor (Equipe Zona Sul) | juliana.prado@hmvendas.demo |
| Gerente (Equipe Zona Oeste) | ana.gerente@hmvendas.demo |
| Gerente (Equipe Zona Sul) | diego.gerente@hmvendas.demo |
| Superintendente (Zona Oeste) | roberto.super@hmvendas.demo |
| Superintendente (Zona Sul) | camila.super@hmvendas.demo |
| GP do Produto (Smart Marquês, Flow Itaim) | patricia.produto@hmvendas.demo |
| GP Líder | eduardo.lider@hmvendas.demo |
| Gerente Geral | beatriz.geral@hmvendas.demo |
| Master da empresa | fernanda.gestora@hmvendas.demo |

Use qualquer uma delas em Relatórios/Roletas/Usuários e acessos para ver
o RBAC em ação — cada cargo enxerga um recorte diferente dos mesmos
dados, e só os cargos de gestão (todos exceto corretor) veem os
controles de criar/editar/remover.

Todos os nomes, leads e números de telefone do seed são **fictícios**,
seguindo a mesma observação do protótipo de referência em
`referencias/LEIA-ME-WEMART-SALES.txt`.

## Estrutura

```
index.php, login.php, logout.php   — entrada e autenticação
includes/                          — config, conexão PDO, auth, RBAC, layout, ícones
pages/                             — telas (inicio, kanban, lead, relatorios, roletas, usuarios)
api/                               — endpoints JSON/POST chamados via fetch() do assets/js/app.js
  lead_mover_etapa, lead_atualizar, tarefa_criar, tarefa_toggle         (Kanban/Ficha do lead)
  roleta_salvar, roleta_corretor, roleta_empreendimento                (Roletas — gestão vedada a corretor)
  usuario_salvar, usuario_toggle_ativo                                 (Usuários e acessos — idem)
assets/css/app.css, assets/js/app.js — sem dependências externas
sql/schema.sql, sql/seed.sql       — schema e dados de demonstração
referencias/                       — protótipo UX original enviado pelo usuário (não é código de produção)
```
