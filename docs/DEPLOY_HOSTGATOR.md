# AURÉA — Publicação na HostGator (cPanel / FTP)

Este projeto é 100% PHP + HTML + CSS + JavaScript estático. **Não é
necessário terminal SSH, Node.js, Composer, FFmpeg nem qualquer
ferramenta de build no servidor.** Basta enviar os arquivos.

## O que enviar

Envie **todo o conteúdo da pasta do projeto** para `public_html/`
(ou para a pasta raiz configurada para o seu domínio/subdomínio),
**exceto**:

- `assets/media/original/` — os vídeos-fonte brutos (≈5 MB cada); são
  material de referência/reextração, não são usados pelo site publicado.
- `docs/` — documentação interna, não precisa estar no servidor.
- Qualquer arquivo `.git`/`.gitignore` do controle de versão.

Tudo dentro de `includes/`, `assets/css/`, `assets/js/`,
`assets/media/frames/`, `assets/media/posters/`, `assets/fonts/`,
`assets/images/`, além de `index.php` e `.htaccess`, deve ser enviado.

## Passo a passo (Gerenciador de Arquivos do cPanel)

1. Acesse o cPanel → **Gerenciador de Arquivos**.
2. Entre em `public_html` (ou na subpasta do domínio/subdomínio
   desejado).
3. Clique em **Carregar** e envie os arquivos/pastas listados acima.
   Se o cPanel só aceitar um arquivo por vez, compacte o projeto em um
   `.zip` antes de enviar e use a opção **Extrair** do próprio
   Gerenciador de Arquivos depois do upload.
4. Confirme que `index.php` ficou diretamente dentro de `public_html`
   (não dentro de uma subpasta extra criada pelo `.zip`).
5. Confirme que `.htaccess` também foi enviado — alguns clientes FTP
   escondem arquivos que começam com ponto; ative "mostrar arquivos
   ocultos" se necessário.

## Passo a passo (FTP/SFTP)

1. Conecte-se com as credenciais de FTP/SFTP fornecidas pela HostGator
   (host, usuário, senha, porta — geralmente 21 para FTP ou 22 para
   SFTP).
2. Navegue até `public_html` (ou a pasta do domínio/subdomínio).
3. Envie todos os arquivos e pastas do projeto mantendo a mesma
   estrutura de diretórios (`includes/`, `assets/`, `index.php`,
   `.htaccess`).
4. Certifique-se de que seu cliente FTP está configurado para exibir e
   enviar arquivos ocultos (`.htaccess`).

## O que conferir depois de publicar

- [ ] Acesse `https://seudominio.com/` — a página deve carregar
      mostrando o rosto da modelo centralizado e o título "Harmonização
      Facial".
- [ ] Role a página — o rosto deve girar suavemente conforme você rola,
      parar quando você para, e voltar suavemente se você rolar para
      cima.
- [ ] Confira em um celular real — o layout deve reorganizar o texto
      para uma faixa abaixo da imagem, sem nenhum texto sobre o rosto.
- [ ] Clique em "Sobre nós", "Agendamento" e "Contato" no menu — os
      painéis devem abrir e fechar (botão "×" e tecla Esc).
- [ ] Se o PHP exibir um erro na tela (em vez da página), confira a
      versão de PHP configurada no cPanel (**Seleção de versão do
      PHP**) — este projeto usa apenas recursos padrão de PHP 7.4+ /
      8.x, sem extensões especiais.
- [ ] Se as imagens não aparecerem, confirme que a pasta
      `assets/media/frames/` foi enviada por completo (120 arquivos
      `.webp` em `desktop/`, 60 em `mobile/`).

## Itens que precisam de configuração antes do lançamento

Edite `includes/config.php` diretamente pelo Gerenciador de Arquivos do
cPanel (ou baixe, edite localmente e reenvie):

1. **`WHATSAPP_NUMBER`** — número da clínica no formato internacional
   sem símbolos (ex.: `5511999999999`). Enquanto vazio, o botão de
   agendamento mostra um aviso em vez de um link falso.
2. **Array `professional`** — nome, especialidade, registro
   profissional, biografia, foto e dados da clínica para a seção
   "Sobre nós".
3. **Array `contact`** — WhatsApp de exibição, Instagram, endereço e
   horário de atendimento para a seção "Contato".

Veja `docs/ASSETS_PENDENTES.md` para a lista completa de pendências,
incluindo as 4 animações ilustrativas ainda não produzidas.

## Cache e compressão (opcional)

O arquivo `.htaccess` já inclui regras de cache (`mod_expires`,
`mod_headers`) e compressão (`mod_deflate`) condicionadas a
`<IfModule>` — ou seja, se o módulo não estiver disponível no seu plano
de hospedagem, a regra é simplesmente ignorada, sem quebrar o site.
Nenhuma configuração manual adicional é necessária no painel da
HostGator.
