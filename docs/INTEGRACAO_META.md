# Ligar o Meta Lead Ads (Facebook e Instagram) ao WeMart Sales

Com a integração ligada, cada pessoa que preenche um formulário de anúncio
de cadastro (Lead Ads) vira um lead no sistema em segundos, com origem
**Meta Ads**, e vai para a roleta do empreendimento ligado ao formulário.

> As telas da Meta mudam com frequência (nomes de menus, ordem dos passos).
> Se algo abaixo não estiver exatamente no mesmo lugar, procure pelo nome do
> recurso na ajuda do *Meta for Developers*. O que não muda: o app precisa
> ter o produto **Webhooks** assinando o campo **leadgen** do objeto **Page**,
> as permissões listadas no passo 3 e um **token de página** de longa duração.

Nada disso é simulado: enquanto os passos abaixo não forem feitos, a tela
**Integrações** mostra “Integração ainda não configurada — nenhum lead do
Meta é recebido até as credenciais serem cadastradas.”

---

## O que você vai precisar

- Acesso de **administrador** à Página do Facebook que roda os anúncios e ao
  **Gerenciador de Negócios** (Business Manager) da empresa.
- O sistema publicado com **HTTPS** (a Meta não chama endereços `http://`).
- Acesso ao `includes/config.php` no servidor (cPanel → Gerenciador de
  Arquivos ou terminal).

## 1. Gerar a chave de cifragem (uma vez por servidor)

No terminal do cPanel:

```
php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
```

Cole o resultado em `includes/config.php`:

```php
define('APP_KEY', 'resultado-do-comando');
```

Guarde esse valor num lugar seguro. Se ele mudar, os tokens já cadastrados
deixam de ser lidos e precisam ser colados de novo.

## 2. Criar o app na Meta

1. Entre em **developers.facebook.com** → *Meus apps* → **Criar app**.
2. Tipo/caso de uso: escolha a opção de **Empresa** (Business) — a que
   permite gerenciar anúncios e páginas. Vincule ao Gerenciador de Negócios
   da empresa.
3. Em **Configurações do app → Básico**, copie a **Chave secreta do app**
   (App Secret) para o `config.php`:

   ```php
   define('META_APP_SECRET', 'chave-secreta-do-app');
   ```

4. Invente um texto aleatório e longo para o token de verificação (ex.: gere
   com o mesmo comando do passo 1) e coloque no `config.php`:

   ```php
   define('META_VERIFY_TOKEN', 'texto-aleatorio-que-voce-criou');
   ```

## 3. Permissões do app

O app precisa destas permissões (em *Casos de uso / Permissões* ou *Revisão
do app*):

| Permissão | Para quê |
|---|---|
| `leads_retrieval` | ler os dados preenchidos no formulário |
| `pages_show_list` | listar as páginas que você administra |
| `pages_read_engagement` | ler informações da página |
| `pages_manage_metadata` | assinar a página no webhook |
| `pages_manage_ads` | acessar os formulários de anúncios da página |

Enquanto o app está em **modo de desenvolvimento**, só funciona com páginas e
pessoas que têm função no app. Para receber leads reais de clientes, o app
precisa estar em **modo ativo (Live)**, o que normalmente exige **verificação
da empresa** no Gerenciador de Negócios e **Revisão do app** pela Meta para
as permissões acima (com descrição do uso e, às vezes, um vídeo mostrando a
tela de Integrações). Esse processo é da Meta e pode levar alguns dias.

## 4. Configurar o Webhook

1. No painel do app, adicione o produto **Webhooks**.
2. Escolha o objeto **Page** → *Assinar este objeto*:
   - **URL de retorno**: o endereço mostrado na tela **Integrações** do
     sistema, por exemplo `https://seudominio.com.br/webhook/meta.php`.
   - **Token de verificação**: o mesmo texto de `META_VERIFY_TOKEN`.
3. Clique em *Verificar e salvar*. Se der erro, confira o HTTPS, o
   `META_VERIFY_TOKEN` e se o `config.php` foi salvo no servidor.
4. Na lista de campos do objeto Page, **assine o campo `leadgen`**.

## 5. Gerar o token da página (longa duração)

1. Abra o **Explorador da Graph API** (Graph API Explorer), selecione o seu
   app e gere um **token de usuário** com as permissões do passo 3.
2. Troque-o por um token de **longa duração** (no *Depurador de token de
   acesso* há o botão “Estender token de acesso”).
3. Com o token de usuário de longa duração, consulte `me/accounts`: a
   resposta traz, para cada página, o `id` e o `access_token` **da página** —
   esse token de página derivado de um token de usuário de longa duração não
   expira enquanto as permissões forem mantidas.
   - Alternativa recomendada para empresas: criar um **Usuário do sistema**
     no Gerenciador de Negócios, dar a ele acesso à Página e ao app e gerar o
     token por lá.
4. Nunca envie esse token por e-mail ou chat. Cole direto no sistema (passo 6).

## 6. Cadastrar no WeMart Sales

Entre como **Master da empresa** → **Integrações**:

1. **Conectar página**: ID da página + token da página. O token é cifrado
   antes de ir para o banco e nunca mais aparece na tela.
2. **Testar conexão**: deve responder “Conexão OK com a página …”.
3. **Assinar página no app**: liga a página ao webhook (campo leadgen).
4. **Buscar formulários**: traz os formulários de cadastro da página. Para
   cada um, escolha o **empreendimento** — é ele que define a roleta que vai
   distribuir o lead. Formulário sem empreendimento: o lead entra e espera na
   fila para um gestor atribuir.
   Também é possível cadastrar um formulário pelo ID, à mão.

## 7. Testar de ponta a ponta

1. Abra a **Ferramenta de teste de anúncios de cadastro** (Lead Ads Testing
   Tool) em developers.facebook.com, escolha a página e o formulário e clique
   em *Criar lead* (apague antes o lead de teste anterior, se houver).
2. Em segundos, a tela **Integrações → Registro de eventos** mostra o aviso:
   - **Lead criado** — o lead já está no sistema e foi para a roleta;
   - **Duplicado** — o telefone já existia na empresa: o aviso foi
     registrado no histórico do lead existente, sem criar outro;
   - **Erro** — a mensagem diz o motivo (token vencido, permissão faltando…).
     O sistema tenta de novo em 5, 15, 60 e 180 minutos; depois de 6
     tentativas para, e o botão **Reprocessar** tenta na hora.
3. Confira que o cron (`cron/rotinas.php`, ver `DEPLOY_HOSTGATOR.md`) está
   rodando: é ele que faz as novas tentativas.

## Segurança

- O webhook só aceita avisos com assinatura `X-Hub-Signature-256` válida
  (HMAC-SHA256 com a chave secreta do app); qualquer outro pedido recebe 403
  e nada é gravado.
- Cada página pertence a uma única empresa do sistema; avisos de páginas não
  cadastradas são ignorados.
- O aviso da Meta só traz números de identificação; os dados pessoais são
  buscados na Graph API com o token da página e gravados apenas no lead.
- Tokens nunca aparecem em telas, logs ou mensagens de erro.

## Problemas comuns

| Sintoma | Causa provável |
|---|---|
| “Verificar e salvar” falha na Meta | sem HTTPS, `META_VERIFY_TOKEN` diferente ou `config.php` não atualizado |
| Nenhum evento aparece | campo `leadgen` não assinado, página não assinada no app (botão *Assinar página*) ou app em modo de desenvolvimento com página/usuário sem função no app |
| Evento com erro “Invalid OAuth access token” [190] | token da página vencido ou revogado — gere outro (passo 5) e use *Trocar token* |
| Erro de permissão [10] ou [200] | falta `leads_retrieval`/`pages_manage_ads` ou a Revisão do app |
| Lead sem empreendimento | o formulário não foi ligado a um empreendimento na tela Integrações |
