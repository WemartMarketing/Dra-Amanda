# Publicar o WeMart Sales na HostGator (cPanel)

Só PHP, MySQL e arquivos — sem Node.js e sem etapa de build. Um único
passo (backfill da Etapa 1) é um script PHP de linha de comando: rode pelo
SSH ou pelo **Terminal** do cPanel.

## 1. Criar o banco MySQL

No cPanel, em **MySQL® Databases**:

1. Crie um banco (ex.: `seuusuario_wemart`).
2. Crie um usuário MySQL com senha forte e adicione-o ao banco com
   **todos os privilégios**.
3. Anote banco, usuário e senha (o cPanel prefixa com `seuusuario_`).

## 2. Enviar os arquivos

Envie o projeto para a pasta pública do domínio (`public_html` ou a
subpasta configurada), ou atualize com `git pull origin main` se a pasta já
for um clone do repositório.

- `referencias/` é só material de consulta (opcional).
- `sql/` e `includes/` ficam bloqueados para acesso pelo navegador
  (`.htaccess`).
- **`uploads/` precisa ter permissão de escrita** para o PHP (normalmente
  755 já basta na HostGator). Confirme que `uploads/.htaccess` foi enviado —
  é ele que impede a execução de scripts na pasta de imagens.
- **`storage/lead_arquivos/` também precisa ter permissão de escrita**
  (755). Confirme que `storage/.htaccess` foi enviado: ele nega todo acesso
  pelo navegador — os documentos dos clientes só saem pelo download
  autenticado do sistema.

## 3. Configurar as credenciais

Copie `includes/config.example.php` para `includes/config.php` no servidor
e preencha:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'seuusuario_wemart');
define('DB_USER', 'seuusuario_wemartuser');
define('DB_PASS', 'a-senha-que-voce-criou');
```

`includes/config.php` não é versionado: um `git pull` nunca sobrescreve
este arquivo.

## 4. Criar/atualizar o banco — sempre nesta ordem

Importe pelo **phpMyAdmin** (aba *Importar*) ou pelo terminal com
`mysql -u USUARIO -p BANCO < arquivo.sql`:

| # | Arquivo | Quando |
|---|---|---|
| 1 | `sql/schema.sql` | instalação nova |
| 2 | `sql/seed.sql` | **só para demonstração** (dados fictícios) |
| 3 | `sql/migration_002_funcoes_permissoes.sql` | Etapa 1 |
| 4 | `php sql/migration_002_backfill.php` (terminal) | Etapa 1 — logo após o passo 3 |
| 5 | `sql/migration_003_estrutura_comercial.sql` | Etapa 2 |
| 6 | `sql/seed_003.sql` | **só para demonstração** — complementa o passo 2 |
| 7 | `sql/migration_004_crm.sql` | Etapa 3 (CRM: funil configurável, agenda, transferências, arquivos) |
| 8 | `sql/seed_004.sql` | **só para demonstração** — agenda, uma transferência e prioridades |

- Quem já rodou os passos 1–6 roda apenas o **7** (e o 8, se usa a base de
  demonstração).
- `migration_003`, `seed_003`, `migration_004` e `seed_004` podem ser
  executados mais de uma vez sem duplicar nada. A `migration_002` não: rode
  uma única vez.
- O `seed_004` não usa procedures nem triggers (a hospedagem compartilhada
  costuma não liberar `CREATE ROUTINE`) e importa pelo phpMyAdmin.
- Em uma empresa real, pule os seeds e cadastre empresa e Master pelo
  **Superadmin** (`superadmin@wemart.demo` existe só se o seed foi
  importado — em produção crie o seu e troque a senha).

## 5. Ajuste de upload (MultiPHP INI Editor)

As imagens de empreendimentos aceitam até 3 MB e os arquivos de leads até
10 MB. Se o
`upload_max_filesize` do servidor for menor (é comum vir 2 MB), o sistema
avisa o limite real ao usuário. Para liberar os 3 MB, no cPanel abra
**MultiPHP INI Editor** e defina `upload_max_filesize = 10M` e
`post_max_size = 12M` (assim os arquivos de leads também chegam ao limite
de 10 MB).

## 6. Testar

1. Acesse `https://seudominio.com.br/login.php`.
2. Com o seed: entre como `fernanda.gestora@hmvendas.demo` / `demo123` e
   confira Equipes, Empreendimentos (envie uma imagem), Plantões e Roletas.
3. Abra `https://seudominio.com.br/uploads/` no navegador: deve aparecer
   **acesso negado (403)** — se listar arquivos, o `.htaccess` não foi
   enviado.
4. Abra `https://seudominio.com.br/storage/` e
   `https://seudominio.com.br/storage/lead_arquivos/`: os dois devem dar
   **403**. Se aparecer listagem ou "não encontrado" com conteúdo, o
   `storage/.htaccess` não foi enviado — não use a aba Arquivos até corrigir.
5. Como corretor (`carlos@hmvendas.demo`): Início, Meu Kanban, uma ficha e
   Minha agenda; na aba Arquivos, envie um PDF e baixe de volta.
6. No celular: abra Plantões → *Novo plantão* → "Usar minha localização
   atual" (só funciona com HTTPS ativo).

## Verificações de segurança incluídas

- `.htaccess` bloqueia `/includes/` e `/sql/`; `uploads/.htaccess` só
  entrega imagens com nome gerado pelo sistema e não executa scripts
  (testado em Apache com mod_php: `.php`, `.phtml` e `.php.png` → 403).
- Senhas com `password_hash()`; bloqueio após 5 tentativas de login.
- Token CSRF em todas as gravações; permissões e empresa verificadas no
  servidor em todo endpoint (ID de outra empresa → 404, nada é alterado).
- Imagens validadas pelo conteúdo e re-gravadas antes de salvar.
- Arquivos de leads validados pelo conteúdo (`finfo`; DOCX/XLSX conferidos
  por dentro), salvos com nome aleatório sem extensão em `storage/` e
  entregues só como anexo (`Content-Disposition: attachment`, `nosniff`)
  depois de conferir login, permissão e escopo do lead.
- Telefone duplicado no cadastro nunca revela lead de outra carteira.
- Exclusão de lead é lógica (some de todas as telas e relatórios) e fica
  na auditoria.
- Auditoria das ações administrativas na tabela `auditoria`.

## O que ainda não está pronto

Ver "Limitações conhecidas" em `docs/README.md` (presença por
geolocalização, distribuição automática e redistribuição por inatividade,
Meta Ads, oferta ativa, exportação Excel e logo oficial).
