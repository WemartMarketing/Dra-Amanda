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

A partir da Etapa 5 o `config.php` também precisa de:

```php
// Chave que cifra os tokens das páginas do Facebook no banco (32 bytes em base64).
// Gere UMA vez no terminal do cPanel e guarde (trocar depois invalida os tokens salvos):
//   php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
define('APP_KEY', 'cole-aqui-o-resultado');
// Meta Lead Ads (deixe vazio até ter o app aprovado — ver docs/INTEGRACAO_META.md)
define('META_APP_SECRET', '');   // Painel do app → Configurações → Básico → Chave secreta
define('META_VERIFY_TOKEN', ''); // um texto aleatório criado por você
define('META_GRAPH_VERSION', 'v21.0');
define('META_GRAPH_BASE_URL', 'https://graph.facebook.com');
```

Opcional (Etapa 6): quantas horas com o corretor, sem contato, fazem um
lead contar como "sem atendimento" no Dashboard (padrão 2):

```php
define('DASH_HORAS_SEM_ATENDIMENTO', 2);
```

Sem `APP_KEY` o sistema se recusa a guardar tokens; sem `META_APP_SECRET` e
`META_VERIFY_TOKEN` o webhook responde 403 e a tela *Integrações* mostra que
a integração não está configurada.

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
| 9 | `sql/migration_005_roleta.sql` | Etapa 4 (roleta: presença, rodízio, fila de espera) |
| 10 | `sql/seed_005.sql` | **só para demonstração** — regras das roletas, histórico e 2 leads na fila |
| 11 | `sql/migration_006_integracoes_oferta.sql` | Etapa 5 (Meta Lead Ads, oferta ativa, recuperação automática) |
| 12 | `sql/seed_006.sql` | **só para demonstração** — regra de recuperação, uma lista de oferta ativa, leads parados (não cria nada do Meta) |
| 13 | `sql/migration_007_dashboards.sql` | Etapa 6 (colunas dos dashboards, índices dos relatórios, permissão de auditoria) |
| 14 | `sql/migration_008_whatsapp_jornadas.sql` | WhatsApp + Jornadas Inteligentes — Fase 1 (estrutura; ver `docs/WHATSAPP_JORNADAS.md`) |
| 15 | `sql/migration_009_whatsapp_fase2.sql` | WhatsApp — Fase 2 (integração com a Cloud API) |
| 16 | `sql/migration_010_jornada_boas_vindas.sql` | Jornadas — Fase 4 (autorização de WhatsApp pelo formulário do Meta) |
| 17 | `sql/migration_011_meta_criativos.sql` | Origem do lead: campanha, anúncio e criativo do Meta Ads (ver `docs/META_CRIATIVOS.md`) |
| 18 | `sql/migration_012_plantoes_navegacao.sql` | Plantões de vendas: CEP, foto, horário, corretores autorizados, rotas (ver `docs/PLANTOES.md`) |
| 19 | `sql/migration_013_relatorios_historico.sql` | Central de Relatórios — histórico estruturado, contratos, origens/UTM. **Depois dela:** `php -q ferramentas/retroalimentar_historico.php` (ver `docs/RELATORIOS_ETAPA2.md`) |
| 20 | `sql/migration_014_relatorios_favoritos.sql` | Central de Relatórios — filtros favoritos por usuário (ver `docs/RELATORIOS_ETAPA3.md`) |
| 21 | `sql/migration_015_relatorios_exportacoes.sql` | Central de Relatórios — exportações grandes em segundo plano (cron) com link temporário (ver `docs/RELATORIOS_ETAPA5.md`) |
| 22 | `sql/migration_016_relatorios_investimentos_modelos.sql` | Central de Relatórios — investimento em anúncios, modelos personalizados, permissão e índices de desempenho (ver `docs/RELATORIOS_ETAPA6.md`) |

- Atualizando um servidor que já funciona: rode, na ordem, só os arquivos
  que ainda não rodou. Ex.: quem já rodou até o 17 roda apenas o **18**.
  Os seeds (2, 6, 8, 10, 12) são só para demonstração; não há seed da
  Etapa 6 em diante. Na dúvida, repita a migração: todas podem rodar mais
  de uma vez sem estragar nada.
- Rode os arquivos **na ordem** numa instalação nova. Se precisar repetir
  algum depois (por exemplo, se uma importação parou no meio), pode rodar de
  novo inclusive uma migração antiga depois de uma mais nova: nenhuma delas
  encolhe uma lista de valores que outra ampliou nem apaga dados.
- **Todos** os arquivos acima podem ser executados mais de uma vez sem
  duplicar nada e funcionam do mesmo jeito em MySQL 5.7+/8.0 e MariaDB
  10.3+ — não é preciso descobrir qual banco a hospedagem usa.
- Nenhum usa procedures, funções ou triggers (a hospedagem compartilhada
  costuma não liberar `CREATE ROUTINE`); todos importam pelo phpMyAdmin.
- Em uma empresa real, pule os seeds e cadastre empresa e Master pelo
  **Superadmin** (`superadmin@wemart.demo` existe só se o seed foi
  importado — em produção crie o seu e troque a senha).
- **Acesso principal pelo Terminal:** `php -q ferramentas/definir_acesso.php`
  cria ou atualiza um Superadmin WeMart ou o Master de uma empresa. A
  senha é digitada na hora, sem aparecer na tela, e só o hash vai para o
  banco. O script também oferece desativar as contas de demonstração
  (e-mails `*.demo`, senha pública `demo123`). A pasta `ferramentas/` é
  bloqueada para acesso pelo navegador.
- A caixa com as contas de demonstração na tela de login só aparece com
  `define('MOSTRAR_CONTAS_DEMO', true);` no `includes/config.php`. Em
  produção, deixe sem essa linha ou com `false`.

## 5. Rotinas automáticas (Cron Jobs do cPanel)

No cPanel → **Cron Jobs**, adicione **uma única linha** (a cada 15 minutos),
trocando `USUARIO` e o caminho pela pasta onde o sistema está:

```
*/15 * * * * /usr/local/bin/php /home/USUARIO/caminho/cron/rotinas.php >/dev/null 2>&1
```

Ela encerra presenças vencidas, entrega os leads da fila da roleta, tenta de
novo os cadastros do Meta que falharam, aplica a recuperação automática por
inatividade e apaga arquivos temporários de importação com mais de 1 hora.
Se você já tinha a linha do `cron/processar_fila.php` (Etapa 4), troque por
esta (a antiga continua funcionando e faz o mesmo, mas use só uma). Só roda
pela linha de comando: aberta no navegador responde 403 (e a pasta `cron/`
também é bloqueada pelo `.htaccess`). Duas execuções ao mesmo tempo não
acontecem (trava `GET_LOCK`). **Sem o cron, a recuperação automática e as
novas tentativas do Meta não acontecem.**

Adicione também o **backup diário** (uma segunda linha, às 3h15):

```
15 3 * * * /usr/local/bin/php /home/USUARIO/caminho/cron/backup.php >/dev/null 2>&1
```

Ele grava `storage/backups/wemart-AAAA-MM-DD-HHMMSS.sql.gz` (SQL
comprimido, gerado só com PHP) e mantém os 7 mais recentes. A pasta é
bloqueada para o navegador: para baixar, use cPanel → *Gerenciador de
Arquivos* → `storage/backups/` → *Download*. Para restaurar num banco
vazio, importe o `.sql.gz` pelo phpMyAdmin ou rode
`gunzip -c arquivo.sql.gz | mysql -u USUARIO -p BANCO`.
Esse backup fica **no mesmo servidor**: mantenha também os backups da
própria HostGator (cPanel → *Backup* / *JetBackup*) e baixe uma cópia para
fora do servidor de tempos em tempos.

**HTTPS é obrigatório para a roleta**: o celular só libera a localização
(GPS) em páginas `https://`. Ative o SSL gratuito em cPanel → *SSL/TLS
Status* (AutoSSL) antes de usar a roleta.

## 6. Ajuste de upload (MultiPHP INI Editor)

As imagens de empreendimentos aceitam até 3 MB e os arquivos de leads até
10 MB. Se o
`upload_max_filesize` do servidor for menor (é comum vir 2 MB), o sistema
avisa o limite real ao usuário. Para liberar os 3 MB, no cPanel abra
**MultiPHP INI Editor** e defina `upload_max_filesize = 10M` e
`post_max_size = 12M` (assim os arquivos de leads também chegam ao limite
de 10 MB).

## 7. Testar

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
7. Abra `https://seudominio.com.br/cron/rotinas.php`: deve dar
   **403**. No celular, como corretor, **Início → ENTRAR NA ROLETA** dentro
   do raio do plantão.

8. Abra `https://seudominio.com.br/webhook/meta.php`: deve dar **403**
   (sem o token de verificação). Siga `docs/INTEGRACAO_META.md` para ligar
   o Meta.
9. **Oferta ativa → Importar planilha** com um CSV de teste; depois
   `https://seudominio.com.br/storage/importacoes/` deve dar **403**.
10. Como Master: **Dashboard** abre sozinho depois do login; *Exportar*
    baixa um .xlsx (se o diálogo disser "CSV", o servidor está sem o
    ZipArchive — dá para ativar em cPanel → *Select PHP Version* →
    extensão `zip`); **Auditoria** mostra a exportação.
11. Rode o backup uma vez pelo Terminal (`php cron/backup.php`) e confira
    que `https://seudominio.com.br/storage/backups/` dá **403**.

## Checklist depois de instalar (produção)

- [ ] `https://` ativo (AutoSSL) e o site abrindo só por HTTPS.
- [ ] `/uploads/`, `/storage/`, `/storage/lead_arquivos/`,
      `/storage/backups/`, `/includes/`, `/sql/` e `/cron/rotinas.php`
      respondem **403**.
- [ ] `includes/config.php` criado no servidor com banco, `APP_KEY` e (se
      usar Meta) as credenciais — nunca enviado ao Git.
- [ ] Senha do superadmin trocada (ou superadmin próprio criado e o de
      demonstração desativado).
- [ ] **Sem dados de demonstração em produção**: numa empresa real não
      importe `seed*.sql`; se importou para testar, recrie o banco antes de
      usar com clientes reais.
- [ ] As duas linhas de cron (rotinas a cada 15 min e backup diário)
      cadastradas, e um backup baixado para fora do servidor.
- [ ] Roteiro de testes em `docs/CHECKLIST_TESTES.md` executado.

## Verificações de segurança incluídas

- Webhook do Meta: assinatura HMAC-SHA256 obrigatória (sem ela, 403 e nada é
  gravado), sem sessão; tokens das páginas cifrados no banco com `APP_KEY` e
  nunca exibidos nem gravados em logs/erros.
- Importação: CSV/XLSX lidos sem bibliotecas externas; XLSX com DOCTYPE/ENTITY
  (XXE) ou grande demais descompactado é recusado; arquivo temporário em
  `storage/` com nome aleatório ligado à sessão de quem enviou.

- Roleta: a distância é calculada no servidor (nunca um "dentro do raio"
  vindo do navegador); o check-in usa sempre o usuário logado e só vale
  para roletas às quais ele está vinculado.
- `.htaccess` bloqueia `/includes/`, `/sql/` e `/cron/`; `uploads/.htaccess` só
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
- Auditoria das ações administrativas na tabela `auditoria`, com tela de
  consulta (Master) que mascara qualquer campo de segredo.
- Exportações: só por POST com CSRF, mesma permissão e escopo da tela,
  proteção contra fórmulas no Excel, nada salvo no servidor, auditadas.
- LGPD: anonimização irreversível e exportação dos dados de um titular,
  ambas auditadas; orientações em `docs/LGPD.md` (não é parecer jurídico).

## O que ainda não está pronto

Ver "Limitações conhecidas" em `docs/README.md` (logo oficial, entre
outras). O Meta Lead Ads está pronto no código, mas só recebe leads depois
das credenciais reais.
