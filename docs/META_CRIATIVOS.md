# Origem do lead — anúncio e criativo do Meta Ads

Mostra a qualquer pessoa que pode ver o lead **de qual campanha, conjunto de
anúncios e anúncio** ele veio, com a **prévia do criativo** e o botão
**"Ver anúncio original"** (quando a Meta fornece um link oficial).

## Instalação
1. `git pull`
2. Importe `sql/migration_011_meta_criativos.sql` (phpMyAdmin → Importar). Pode
   rodar mais de uma vez. Leads antigos do Meta recuperam o id do anúncio que já
   estava guardado no aviso do webhook.
3. (Recomendado) Em **Integrações → Anúncios e criativos**, cole o **token da
   Marketing API**: token de *usuário do sistema* do Gerenciador de Negócios com
   permissão **ads_read** nas contas de anúncio. Sem ele, o sistema tenta ler o
   anúncio com o token da página — a Meta nem sempre permite.
4. O cron que já roda `cron/rotinas.php` a cada 15 minutos busca os criativos.

## Como funciona
- **No cadastro** (webhook do Lead Ads): o sistema pede ao Lead Ads os ids e
  nomes de campanha, conjunto e anúncio, formulário e plataforma
  (Facebook/Instagram/…) e grava **no lead**. Se a Meta recusar algum campo,
  repete só com o essencial: **a origem nunca impede o cadastro nem a roleta**.
  Cadastro orgânico (sem anúncio) não é atribuído a anúncio.
- **Um registro por anúncio** (`meta_anuncios`), reaproveitado por todos os leads
  dele: a Marketing API é consultada uma vez e o cache vale 7 dias.
- **Sincronização** (rotina e "Buscar criativos agora"): anúncio + criativo —
  título, texto, CTA, tipo de mídia (imagem/vídeo/carrossel), conta de anúncios,
  e o **link oficial**, só de fontes da própria Meta, nesta ordem:
  `preview_shareable_link` → permalink do Instagram → permalink do post no
  Facebook. Toda URL é validada (https, domínios facebook.com, fb.me, fb.com,
  instagram.com). **Nunca montamos URL a partir do id do anúncio.**
- **Imagem**: as URLs da Meta expiram, então guardamos uma cópia em
  `storage/criativos/` (pasta bloqueada ao navegador). Só baixa de CDNs da Meta,
  até 5 MB, e confere que é imagem de verdade. Vídeo: mostramos a miniatura e o
  link oficial para assistir (o vídeo não é copiado).
- **Criativo dinâmico** (variações automáticas): a tela avisa que a prévia é
  uma versão do anúncio, não necessariamente a que o cliente viu.
- **Anúncio excluído/arquivado**: os dados e a prévia já guardados continuam, com
  aviso. **Sem acesso**: aviso para conferir o token. **Limite da API**: nova
  tentativa só depois de 1 h. Outras falhas: 30 min, 2 h, 12 h, 24 h. Toda falha
  fica em `meta_criativo_falhas` e aparece em Integrações. Nenhum token vai para
  os registros.
- **Transferência** de corretor não muda a origem do lead.

## Onde aparece
- **Ficha do lead → Resumo**: card "Anúncio que gerou este lead" (prévia
  clicável para ampliar, campanha, conjunto, anúncio, plataforma, data do
  cadastro, formulário, título, texto, CTA e "Ver anúncio original"). Sem
  prévia: "Criativo indisponível", com os demais dados.
- **Kanban e cards de lead**: botão "Ver criativo" abre uma janela com a prévia e
  os dados, sem sair do Kanban.
- **Dashboard → Leads por anúncio**: leads, 1º contato, visitas agendadas,
  contratos e conversão por campanha e por anúncio, com exportação Excel. Só
  dados do CRM — investimento e CPL **não** estão integrados e não são inventados.

## Segurança e permissões
- Tudo é buscado **pelo lead** e pela empresa de quem está logado
  (`api/lead_criativo.php`, `api/criativo_midia.php`): o mesmo recorte da ficha
  (empresa, empreendimento, gerente, superintendência, carteira, transferências).
  Sem acesso ao lead → 404, sem confirmar nada. Nenhum endpoint aceita id de
  anúncio ou caminho de arquivo vindo do navegador.
- Textos da Meta são exibidos como texto (nunca HTML). A prévia HTML oficial
  (`/previews`, um iframe) **não** é embutida: usamos o link oficial em nova aba.

## Testes
`php tests/meta_criativos_test.php` — 57 verificações contra o simulador local
da Graph API (nada sai para a Meta): links e domínios, captura no cadastro,
busca simplificada quando a Meta recusa campos, reaproveitamento do anúncio,
sincronização completa, cache, criativo dinâmico, imagem fora da Meta ou falsa,
anúncio excluído, sem permissão, limite da API, token da Marketing API,
permissões por carteira e empresa, transferência e relatório. 6 mutações
detectadas.

## O que depende da Meta
- O app precisa das permissões `leads_retrieval` (já usada) e, para os criativos,
  `ads_read` (token da Marketing API) — sujeitas à revisão do app pela Meta.
- **Não validado com dados reais**: tudo foi testado com o simulador local. O
  primeiro lead real vai mostrar quais campos a conta de vocês libera.
