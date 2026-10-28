# AURÉA — Assets pendentes e inspeção técnica do material

## 1. Material-fonte inspecionado (Fase 1)

Dois vídeos MP4 foram analisados com `ffmpeg`/`ffprobe` (ferramenta local,
não usada em produção):

| | V1 (`source_v1_left-to-frontal.mp4`) | V2 (`source_v2_frontal-to-right.mp4`) |
|---|---|---|
| Resolução | 1920×1080 | 1920×1080 |
| Codec | H.264, yuv420p | H.264, yuv420p |
| FPS | 24 | 24 |
| Duração | 10,000 s | 10,000 s |
| Quadros | 240 | 240 |
| Rotação | Perfil esquerdo → quase frontal | Frontal → perfil direito |

**Continuidade:** o último quadro de V1 e o primeiro quadro de V2 foram
comparados lado a lado — pose, enquadramento, iluminação e fundo batem,
confirmando que os dois vídeos formam **uma única rotação contínua de
~180°** (perfil esquerdo → frontal → perfil direito). Nenhum quadro foi
espelhado ou inventado: os dois lados do rosto são material real.

**Fundo removido (atualização):** a direção visual mudou para fundo
branco puro (`#FFFFFF`) com a modelo totalmente integrada, sem nenhum
retângulo/moldura visível. Por isso o fundo de estúdio original (medido
nos 4 cantos, média `#E5DFD5`, com leve variação `#E8E4DA`→`#DFD9CE`
entre as laterais — dado mantido aqui só como referência histórica) foi
**removido de cada quadro** com segmentação por IA (rembg, modelo
u2net), gerando WebP com canal alpha real. `--background-video` em
`assets/css/variables.css` é hoje `#FFFFFF`; o canvas (`face-player.js`)
não pinta mais nenhum fundo — onde o quadro não cobre o stage, o branco
da própria página aparece por trás, por construção, sem costura
possível. Conferido visualmente nos 10 estados, em desktop e mobile.

## 2. Sequência de quadros extraída

Os 480 quadros brutos (240+240, 1920×1080) não foram publicados —
pesado demais para hospedagem compartilhada. Foi extraída uma amostra
reduzida, local, com `ffmpeg` (não depende de FFmpeg no servidor), e
depois processada com remoção de fundo (também local — `rembg`/`onnxruntime`,
não usado em produção):

| Conjunto | Quadros | Resolução | Formato | Tamanho total |
|---|---|---|---|---|
| Desktop (`assets/media/frames/desktop/`) | 120 (1 a cada 4 quadros originais) | 1280px de largura | WebP RGBA (alpha real), q≈90 | ~6 MB |
| Mobile (`assets/media/frames/mobile/`) | 60 (1 a cada 8 quadros originais) | 760px de largura | WebP RGBA (alpha real), q≈90 | ~1,4 MB |

Numeração: `frame_001.webp` a `frame_120.webp` (desktop) / `frame_001.webp`
a `frame_060.webp` (mobile), cobrindo a mesma rotação perfil-esquerdo →
frontal → perfil-direito, real e contínua (nenhum quadro espelhado ou
inventado — ver seção 1). O quadro 1 é o perfil esquerdo completo; o
quadro central (~60/61 no desktop, ~30/31 no mobile) é o frontal; o
último quadro é o perfil direito completo.

A proporção original é sempre preservada no Canvas (`assets/js/face-player.js`,
método `_drawImageFocused`) — equivalente a `object-fit: contain`. Nenhum
corte de topo da cabeça, queixo ou laterais ocorre: o que "sai" do
enquadramento em um zoom é sempre fundo vazio (transparente), nunca um
recorte do rosto. Os pôsteres de carregamento (`assets/media/posters/`)
também foram regenerados a partir da sequência transparente.

### Para trocar a sequência por um vídeo novo
Ao extrair uma nova sessão de vídeo, gere os quadros com fundo já
removido (transparência real) antes de salvar em
`assets/media/frames/{desktop,mobile}/` — o site não pinta mais nenhum
fundo por conta própria, então um quadro com fundo opaco voltaria a
mostrar uma "caixa" contra o branco da página.

Os índices de quadro usados em `includes/procedures.php` são definidos na
numeração do conjunto desktop (1–120) e reescalados automaticamente para
o conjunto ativo no navegador (`assets/js/face-player.js`, propriedade
`referenceCount`), para que a mesma configuração de câmera aponte sempre
para a mesma pose relativa em qualquer resolução.

### Como trocar por uma nova sessão de vídeo
1. Extraia uma nova sequência de quadros numerados sequencialmente
   (`frame_001.webp`, `frame_002.webp`, …) para
   `assets/media/frames/desktop/` e, opcionalmente, uma versão mais leve
   para `assets/media/frames/mobile/`.
2. Atualize `count` em `window.AUREA_CONFIG.frames` (em `index.php`) para
   o novo total de cada conjunto.
3. Ajuste os valores de `frame`/`frameRange` em `includes/procedures.php`
   se a nova rotação cobrir um intervalo diferente.
Nenhuma outra mudança de código é necessária — o restante do sistema
(câmera virtual, hotspots, crossfade) é agnóstico ao conteúdo dos quadros.

## 3. Itens pendentes (não inventados)

**Importante:** nada disto aparece ao visitante — o site não exibe mais
avisos de "pendente", instruções de configuração, nomes de arquivo ou
qualquer texto técnico na página pública. Esta seção é só para quem for
configurar o site (ver `includes/config.php`).

### WhatsApp / Agendamento
`includes/config.php` → `WHATSAPP_NUMBER` está vazio. Enquanto isso:
- O CTA de agendamento (estado "Agendamento") abre o overlay de
  Agendamento (texto neutro "Em breve você poderá agendar...") em vez
  de um link com número de exemplo — o botão `data-booking-cta` com o
  link real de WhatsApp só é renderizado quando `WHATSAPP_NUMBER !== ''`
  (ver `index.php`).
- Preencha no formato internacional sem símbolos (ex.: `5511999999999`)
  para ativar o link real.

### "Sobre nós" — dados profissionais/clínica
`includes/config.php` → array `professional` (nome, especialidade,
registro profissional, biografia, foto, clínica, endereço) e `contact`
(WhatsApp de exibição, Instagram, endereço, horário) estão vazios.
Nenhuma formação, registro ou dado de contato foi inventado. Enquanto
vazios, os overlays "Sobre nós" e "Contato" mostram um texto neutro de
marca (sem mencionar configuração pendente) — ver `includes/footer.php`.

### 4 animações ilustrativas anatômicas
Ainda não há vídeo/sequência de quadros para:
1. Variação de volume labial (`preenchimento-labial`)
2. Alteração do contorno mandibular (`contorno-mandibular`)
3. Projeção/proporção do queixo (`queixo`)
4. Expressão facial / terço superior (`toxina-botulinica`)

Cada uma dessas etapas já tem o hotspot visível normalmente, mas ele só
fica clicável/interativo quando existe de fato uma demonstração pronta
(`interactiveDemo` sem `pendingAsset` — ver `assets/js/hotspots.js`);
enquanto o asset não existe, o hotspot é só um marcador informativo
silencioso, sem anunciar nada de "pendente" ao visitante. Quando o
material estiver disponível: (1) remova `pendingAsset` da etapa em
`includes/procedures.php` para ativar a interatividade do hotspot, e
(2) acrescente o novo player/sequência, ligando-o ao evento
`aurea:hotspot-interact` já disparado pelo hotspot.

## 4. Verificação visual real (Chromium headless) e correções aplicadas

Duas rodadas de verificação visual de verdade (Chromium headless via
Playwright, servido por `php -S`, 1600×900 desktop e 390×844 mobile, os
10 estados) encontraram e corrigiram problemas reais — não apenas
revisão de código.

**Rodada 1 (fundo bege, antes da migração para branco puro):** caixa de
"player" visível nos planos afastados; rosto minúsculo no mobile
(enquadramento usando a largura da tela como referência numa tela
estreita); texto sobre queixo/mandíbula/lábios em alguns estados no
mobile. As duas primeiras deixaram de existir estruturalmente com a
migração para fundo branco puro + quadros transparentes (seção 1): sem
fundo pintado, não há mais "caixa" possível, e o ajuste de enquadramento
por altura no mobile foi mantido. A terceira (texto sobre o rosto) usa
o mesmo sistema de `faceExclusion` descrito abaixo.

**Rodada 2 (fundo branco puro + quadros transparentes — versão atual),
problemas encontrados e corrigidos:**

1. **Pôster borrado desalinhado:** o placeholder exibido enquanto o
   quadro 1 carrega usava `object-fit` simples (equivalente a zoom 1),
   mas o estado inicial usa zoom 0,48 — o pôster aparecia bem maior que
   o quadro real, criando um "fantasma" de duas cabeças sobrepostas por
   instantes no primeiro carregamento. Corrigido aplicando a mesma
   escala (`transform: scale(0.48)`) ao pôster em `assets/css/style.css`.
2. **Subtítulo do estado inicial cruzando o nascimento do cabelo:** o
   bloco de título+subtítulo (`.procedure-text--anchor-top`) ficava
   `top: 14%`, e nessa altura o subtítulo encostava na franja/nascimento
   do cabelo em telas largas, prejudicando a leitura. Ajustado para
   `top: 10%`, dando folga suficiente (afeta só desktop — no mobile todo
   texto já fica numa faixa fixa separada).
3. **Texto sobre o queixo no mobile em "Contorno Mandibular" e "Toxina
   Botulínica":** a `faceExclusion` original dessas duas etapas era
   pequena demais para a faixa de texto fixa do mobile. Ampliada
   (`contorno-mandibular`: `ry` 34→42; `toxina-botulinica`: `ry` 26→33)
   — ver `includes/procedures.php`.
4. **Texto sobre o queixo no mobile em "Tratamento de Olheiras":** mesmo
   problema, de novo após a migração de fundo (ficou mais visível sem a
   camuflagem do bege). `faceExclusion.ry` ampliado de 30 para 37.
5. Um "vão" com cantos arredondados observado numa captura em
   "Perfiloplastia" foi investigado a fundo (sondagem do quadro e
   coordenadas exatas renderizadas) e **não é um defeito** — é o espaço
   negativo real e esperado entre o queixo/pescoço e o ombro numa pose
   de perfil, corretamente deixado transparente pela remoção de fundo.
   Documentado aqui para não ser "corrigido" por engano numa futura
   sessão.

Todos os 10 estados (mais o acesso direto a "Queixo", que as frações de
scroll testadas não cobriam) foram reconferidos em desktop e mobile
depois de cada correção. A reversibilidade do scroll (avançar, recuar,
voltar ao início) foi testada e confirmada — o estado é sempre derivado
do `progress` contínuo, nunca de um flag "só uma vez".

**Limitação conhecida, não bloqueante:** durante uma transição rápida
entre dois estados com rotação muito diferente (ex.: do clímax de
"Harmonização Global", quadro ~120, para "Agendamento", quadro 60), o
crossfade entre quadros pode mostrar um instante de "dupla exposição"
(duas poses sobrepostas) em vez de uma dissolução limpa — visível só
durante a rolagem rápida nesse trecho específico, nunca em repouso. Uma
melhoria futura seria limitar a distância de quadros cruzada por
transição ou usar um efeito de dissolução dedicado nesses saltos
grandes.

As posições de hotspot continuam sendo aproximações razoáveis; um
ajuste fino manual, olhando o site publicado em dispositivos físicos
reais, ainda é recomendado antes do lançamento definitivo — mas a regra
"nenhum texto sobre o rosto" (critério 7 da seção 19) foi verificada
visualmente nos 10 estados, não apenas presumida.
