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
- **Relatórios, Roletas, Usuários e acessos**: navegação completa; Roletas
  e Usuários já leem dados reais (plantão e contagem por cargo); a edição
  completa dessas telas e os gráficos de Relatórios ficam para a próxima
  etapa (ver "Fora do escopo" abaixo).

### Fora do escopo desta etapa (não fingido — apenas ainda não construído)

- Geofencing completo da roleta (raio/horário decidindo quem recebe o
  próximo lead) — hoje a tela Roletas só exibe os dados cadastrados.
- Entrada automatizada de leads via Meta Ads.
- Oferta ativa / recuperação automática de leads sem feedback após 4 dias
  (a regra aparece como aviso na ficha, mas não há job automático ainda).
- Exportação para Excel e os gráficos de conversão/funil da tela Relatórios.
- Editor de cargos/permissões granulares na tela Usuários e acessos.
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
| Corretor | carlos@hmvendas.demo |
| Corretor | fernanda.costa@hmvendas.demo |
| Gerente (Equipe Zona Oeste) | ana.gerente@hmvendas.demo |
| Master da empresa | fernanda.gestora@hmvendas.demo |

Todos os nomes, leads e números de telefone do seed são **fictícios**,
seguindo a mesma observação do protótipo de referência em
`referencias/LEIA-ME-WEMART-SALES.txt`.

## Estrutura

```
index.php, login.php, logout.php   — entrada e autenticação
includes/                          — config, conexão PDO, auth, RBAC, layout, ícones
pages/                             — telas (inicio, kanban, lead, relatorios, roletas, usuarios)
api/                               — endpoints JSON/POST chamados via fetch() do assets/js/app.js
assets/css/app.css, assets/js/app.js — sem dependências externas
sql/schema.sql, sql/seed.sql       — schema e dados de demonstração
referencias/                       — protótipo UX original enviado pelo usuário (não é código de produção)
```
