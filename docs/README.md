# AURÉA — Harmonização Facial

Experiência interativa de harmonização facial controlada por rolagem,
construída em **PHP + HTML + CSS + JavaScript vanilla** — sem build
step, sem servidor Node.js, compatível com hospedagem compartilhada
(HostGator). Veja `docs/DEPLOY_HOSTGATOR.md` para publicar e
`docs/ASSETS_PENDENTES.md` para a inspeção técnica do material-fonte e
a lista de pendências.

## Rodando localmente

Qualquer servidor PHP embutido funciona, sem instalar dependências:

```bash
php -S localhost:8000
```

Depois abra `http://localhost:8000/index.php`.

## Estrutura

```
index.php                      Página única; monta o HTML a partir de includes/
includes/
  config.php                   Identidade, paleta, WhatsApp, dados profissionais/contato
  procedures.php                Os 10 estados narrativos (textos, câmera, hotspots)
  header.php / footer.php      Cabeçalho fixo e overlays (Sobre nós/Agendamento/Contato)
assets/
  css/                         variables.css, style.css, responsive.css
  js/                          face-player, camera-controller, hotspots,
                                procedure-controller, scroll-controller,
                                navigation, main (bootstrap)
  media/
    original/                  Vídeos-fonte brutos (referência; não publicar)
    frames/desktop/            120 quadros WebP, 1280px
    frames/mobile/             60 quadros WebP, 760px
    posters/                   Pôster inicial + LQIP para carregamento progressivo
docs/                          Esta documentação
.htaccess                      Cache, compressão e proteção de /includes/
```

## Como funciona (resumo técnico)

- **`includes/procedures.php`** define os 10 estados (seção 9 do
  briefing) com título, descrição, peso de rolagem, configuração de
  câmera virtual (quadro-alvo, foco X/Y, zoom) e hotspots — tudo em PHP,
  editável sem tocar em JavaScript.
- **`assets/js/scroll-controller.js`** converte a rolagem nativa da
  página (via `position: sticky`) em um progresso 0–1, e um laço de
  `requestAnimationFrame` interpola esse valor suavemente (lerp) antes
  de cada redesenho — a renderização nunca está presa 1:1 ao evento de
  scroll, o que mantém fluidez em scroll rápido, lento ou reverso, e
  funciona nativamente com mouse, trackpad, teclado e toque (nenhum
  evento `wheel` é bloqueado).
- **`assets/js/camera-controller.js`** traduz o progresso em um estado
  de câmera interpolado, com transição suave entre o estado de câmera
  anterior e o do procedimento ativo.
- **`assets/js/face-player.js`** desenha a sequência de quadros em
  Canvas, preservando sempre a proporção original (equivalente a
  `object-fit: contain`), com pré-carregamento progressivo, cache e
  crossfade entre quadros adjacentes para suavidade extra.
- **`assets/js/hotspots.js`** projeta cada hotspot do espaço da imagem
  original para a tela, usando a mesma transformação de zoom/foco do
  quadro atual — por isso os marcadores acompanham corretamente a
  câmera virtual em vez de ficarem em posições fixas da viewport.

## Checklist de testes realizados nesta sessão

Testado com Chromium headless (Playwright) contra `php -S` local —
**não foi possível testar em um navegador real de um dispositivo físico
nem em um servidor Apache/HostGator real.**

- [x] `php -l` em todos os arquivos `.php` — sem erros de sintaxe.
- [x] `node --check` em todos os arquivos `.js` — sem erros de sintaxe.
- [x] Página carrega via `php -S` sem warnings/erros no log do PHP.
- [x] Sem erros de console/JavaScript em execução (desktop 1440×900 e
      mobile 390×844).
- [x] Canvas renderiza conteúdo real (verificado lendo os pixels).
- [x] Progresso de rolagem ativa o procedimento correto em cada ponto
      testado (10 estados em desktop, 5 em mobile).
- [x] **Nenhum texto sobre regiões importantes do rosto** — verificado
      visualmente em screenshot nos 10 estados; dois problemas reais
      foram encontrados e corrigidos durante o teste (título do estado
      inicial e do clímax "Harmonização Global" inicialmente cobriam o
      rosto — corrigido ajustando zoom/âncora de texto).
- [x] Hotspots acompanham corretamente o zoom/foco da câmera (bug de
      posicionamento fixo encontrado e corrigido).
- [x] **Bug de reescala mobile:** os índices de quadro eram definidos
      para o conjunto desktop (120 quadros) e, sem ajuste, apontavam
      para poses erradas no conjunto mobile (60 quadros) — corrigido
      com reescala proporcional automática em `face-player.js`.
- [x] Layout mobile: texto nunca sobre o rosto (faixa própria abaixo da
      imagem) — verificado em 5 pontos de rolagem.
- [x] `.htaccess`: bloqueio de acesso direto a `/includes/` depende de
      `mod_rewrite`, disponível em Apache (HostGator) mas **não**
      efetivo no servidor embutido do PHP usado para este teste local —
      comportamento esperado, não é um bug.

### Não testado (requer ambiente real)

- [ ] Publicação real em cPanel/FTP na HostGator.
- [ ] `mod_deflate`/`mod_expires`/`mod_rewrite` realmente habilitados no
      plano de hospedagem contratado.
- [ ] Dispositivos móveis físicos (apenas emulação de viewport/touch via
      Playwright foi testada).
- [ ] Conexões lentas reais (o comportamento de pré-carregamento
      progressivo foi revisado no código, mas não medido em rede 3G/4G
      real).
- [ ] Leitores de tela / navegação por teclado completa (overlays têm
      `role="dialog"`, fecham com Esc e devolvem o foco, mas não foram
      testados com um leitor de tela real).

## Reutilização para outra clínica/profissional

Edite `includes/config.php` (identidade, paleta, WhatsApp, dados
profissionais/contato) e `includes/procedures.php` (textos, câmera,
hotspots). Substitua os arquivos em `assets/media/frames/` por uma nova
sequência de quadros — veja o passo a passo em
`docs/ASSETS_PENDENTES.md`.
