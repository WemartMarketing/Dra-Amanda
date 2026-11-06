# Publicar o WeMart Sales na HostGator (cPanel)

Não depende de terminal SSH, Node.js ou qualquer ferramenta de build —
só PHP, MySQL e upload de arquivos.

## 1. Criar o banco MySQL

No cPanel, abra **MySQL® Databases**:

1. Crie um banco (ex.: `seuusuario_wemart`).
2. Crie um usuário MySQL com senha forte.
3. Adicione esse usuário ao banco com **todos os privilégios**.
4. Anote o nome do banco, usuário e senha — o cPanel normalmente prefixa
   ambos com `seuusuario_`.

## 2. Importar o schema e os dados iniciais

No cPanel, abra **phpMyAdmin**, selecione o banco criado e, na aba
**Importar**:

1. Importe primeiro `sql/schema.sql` (cria as tabelas).
2. Depois importe `sql/seed.sql` **somente se quiser começar com os dados
   de demonstração fictícios** — para uma empresa real, pule esse passo e
   cadastre a empresa/usuários reais diretamente (uma tela de cadastro
   inicial fica para uma próxima etapa; por ora, pode inserir as linhas
   manualmente pelo phpMyAdmin, usando `password_hash()` do PHP para gerar
   o hash da senha de cada usuário).

## 3. Enviar os arquivos

Envie todo o conteúdo deste projeto para a pasta pública do domínio
(`public_html` ou a subpasta configurada), **exceto**:

- `sql/` pode ir junto (fica protegido pelo `.htaccess`) ou ficar de fora —
  não é necessário em produção depois do passo 2.
- `referencias/` é só material de consulta; pode enviar ou não, não afeta
  o funcionamento do site.
- Nunca envie um `includes/config.php` com as credenciais de
  desenvolvimento deste ambiente.

## 4. Configurar as credenciais reais

Copie `includes/config.example.php` para `includes/config.php` no
servidor (pelo Gerenciador de Arquivos do cPanel) e preencha:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'seuusuario_wemart');
define('DB_USER', 'seuusuario_wemartuser');
define('DB_PASS', 'a-senha-que-voce-criou');
```

## 5. Testar

1. Acesse `https://seudominio.com.br/login.php`.
2. Se você importou `sql/seed.sql`, entre com `carlos@hmvendas.demo` /
   `demo123` e confira o Kanban e a Ficha do lead.
3. Se não importou o seed, cadastre a primeira empresa e usuário
   diretamente no banco (phpMyAdmin) antes do primeiro login.
4. Confira o Kanban em um celular real: role a tela, arraste/avance um
   card, abra a ficha e salve um atendimento.

## Verificações de segurança já incluídas

- `.htaccess` bloqueia acesso direto a `/includes/` e `/sql/`.
- Senhas são armazenadas com `password_hash()` (nunca em texto puro).
- Todo dado de lead é filtrado por empresa e pelo escopo do cargo do
  usuário logado (`includes/rbac.php`) — nenhuma página monta essa
  filtragem por conta própria.

## O que ainda não está pronto (ver docs/README.md)

Relatórios, Roletas e Usuários e acessos já funcionam de verdade contra o
banco (KPIs, gráficos, CRUD). Ficam para a próxima etapa apenas: a
distribuição automática de leads por geolocalização ao vivo (hoje a tela
Roletas gerencia a configuração e mostra a última presença registrada,
mas não decide sozinha quem recebe o próximo lead), a integração
automática com Meta Ads, a exportação Excel de verdade, e a criação de
cargos/permissões personalizados (os 8 cargos continuam fixos). Onde
ainda não há lógica real, a tela mostra claramente "Em desenvolvimento"
em vez de fingir que funciona.
