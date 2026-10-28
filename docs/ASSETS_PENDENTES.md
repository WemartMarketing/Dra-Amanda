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

**Cor de fundo real:** amostragem de 4 cantos (80×80px) em 20 quadros
(1/segundo de cada vídeo) com Python/Pillow:

| Canto | Cor média |
|---|---|
| Superior-esquerdo | `#E8E4DA` |
| Inferior-esquerdo | `#EEE9E0` |
| Superior-direito | `#DCD5CC` |
| Inferior-direito | `#DFD9CE` |
| **Média geral** | **`#E5DFD5`** |

A iluminação do estúdio não é perfeitamente uniforme (mais clara à
esquerda, mais quente/escura à direita). Por isso `--background-video`
usa `#E5DFD5` como base e o `body` aplica um gradiente horizontal sutil
entre `#E8E4DA` e `#DFD9CE` (ver `assets/css/variables.css`), em vez de
uma cor chapada — exatamente o comportamento pedido na seção 3 do
briefing.

## 2. Sequência de quadros extraída

Os 480 quadros brutos (240+240, 1920×1080) não foram publicados —
pesado demais para hospedagem compartilhada. Foi extraída uma amostra
reduzida, local, com `ffmpeg` (não depende de FFmpeg no servidor):

| Conjunto | Quadros | Resolução | Formato | Tamanho total |
|---|---|---|---|---|
| Desktop (`assets/media/frames/desktop/`) | 120 (1 a cada 4 quadros originais) | 1280px de largura | WebP, q≈80 | ~2,4 MB |
| Mobile (`assets/media/frames/mobile/`) | 60 (1 a cada 8 quadros originais) | 760px de largura | WebP, q≈75 | ~0,5 MB |

Numeração: `frame_001.webp` a `frame_120.webp` (desktop) / `frame_001.webp`
a `frame_060.webp` (mobile), cobrindo a mesma rotação perfil-esquerdo →
frontal → perfil-direito. O quadro 1 é o perfil esquerdo completo; o
quadro central (~60/61 no desktop, ~30/31 no mobile) é o frontal; o
último quadro é o perfil direito completo.

A proporção original é sempre preservada no Canvas (`assets/js/face-player.js`,
método `_drawImageFocused`) — equivalente a `object-fit: contain`. Nenhum
corte de topo da cabeça, queixo ou laterais ocorre: o que "sai" do
enquadramento em um zoom é sempre fundo vazio, nunca um recorte do rosto.

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

### WhatsApp / Agendamento
`includes/config.php` → `WHATSAPP_NUMBER` está vazio. O botão de
agendamento detecta isso e mostra um aviso de "integração pendente" em
vez de um link com número de exemplo. Preencha no formato internacional
sem símbolos (ex.: `5511999999999`).

### "Sobre nós" — dados profissionais/clínica
`includes/config.php` → array `professional` (nome, especialidade,
registro profissional, biografia, foto, clínica, endereço) e `contact`
(WhatsApp de exibição, Instagram, endereço, horário) estão vazios.
Nenhuma formação, registro ou dado de contato foi inventado. O overlay
"Sobre nós" e "Contato" exibem um aviso de pendência enquanto esses
campos não forem preenchidos.

### 4 animações ilustrativas anatômicas
Ainda não há vídeo/sequência de quadros para:
1. Variação de volume labial (`preenchimento-labial`)
2. Alteração do contorno mandibular (`contorno-mandibular`)
3. Projeção/proporção do queixo (`queixo`)
4. Expressão facial / terço superior (`toxina-botulinica`)

Cada uma dessas etapas já tem o "gancho" de interação pronto (hotspot
clicável/tocável, evento `aurea:hotspot-interact`) e exibe um aviso
"Pendente" explícito em vez de simular um resultado. Quando o material
estiver disponível, acrescente o novo player/sequência e ligue-o ao
evento já disparado pelo hotspot correspondente (ver `interactiveDemo`
em `includes/procedures.php` e `assets/js/hotspots.js`).

## 4. Verificação visual real (Chromium headless) e correções aplicadas

Depois da primeira versão, o site foi de fato aberto num navegador
(Chromium headless via Playwright, servido por `php -S`) em 1600×900
(desktop) e 390×844 (mobile), nos 10 estados — não apenas revisado por
leitura de código. Três problemas reais foram encontrados e corrigidos
em `assets/js/face-player.js`:

1. **"Caixa de player" visível** nos planos mais afastados (ex.: tela
   inicial, "Harmonização Global"): a área vazia ao redor do quadro
   ficava transparente/com cor divergente da página, criando uma borda
   retangular nítida — proibido pela seção 3. Corrigido pintando o
   fundo real do estúdio (`--background-video-left/right`) no canvas
   antes de desenhar o quadro, e esmaecendo a borda real da imagem
   (apenas quando alguma borda do quadro cai dentro da tela — nunca
   sobre a pele) num gradiente de ~6% para dentro desse fundo.
2. **Rosto minúsculo no mobile**: o enquadramento "contido" usava a
   largura da tela como referência (como no desktop), mas numa tela
   estreita isso encolhe demais uma imagem paisagem. Corrigido: no
   mobile, o enquadramento usa a altura da tela como referência e deixa
   o fundo (vazio) sangrar para fora dos limites horizontais — nunca o
   rosto, que ocupa só uma fração central do quadro original.
3. **Texto sobre o rosto no mobile** (queixo/mandíbula/lábios, a
   depender do estado): no mobile todo texto cai numa faixa fixa na
   parte inferior da tela, então o enquadramento de cada estado precisa
   deixar essa faixa livre. Corrigido com um sistema que desloca o
   centro vertical da câmera, no mobile, apenas o necessário para que
   `faceExclusion` de cada estado nunca ultrapasse essa faixa — os
   valores de `faceExclusion` em `includes/procedures.php` foram
   recalibrados visualmente (olheiras, lábios, queixo, perfiloplastia e
   terços já tinham valores suficientes; mandíbula e toxina botulínica
   foram ampliados; apresentação, harmonização global e agendamento
   ganharam `faceExclusion` que não existia). Os 10 estados foram
   reconferidos em capturas de tela após o ajuste, em desktop e mobile.

**Limitação conhecida, não bloqueante:** durante uma transição rápida
entre dois estados com rotação muito diferente (ex.: do clímax de
"Harmonização Global", quadro ~120, para "Agendamento", quadro 60), o
crossfade entre quadros pode mostrar um instante de "dupla exposição"
(duas poses sobrepostas) em vez de uma dissolução limpa — visível só
durante a rolagem rápida nesse trecho específico, nunca em repouso. Uma
melhoria futura seria limitar a distância de quadros cruzada por
transição ou usar um efeito de dissolução dedicado nesses saltos
grandes.

As posições de hotspot continuam sendo aproximações razoáveis para um
enquadramento `contain`/`cover` central; um ajuste fino manual, olhando
o site publicado em dispositivos físicos reais, ainda é recomendado
antes do lançamento definitivo — mas a regra "nenhum texto sobre o
rosto" (critério 7 da seção 19) foi verificada visualmente nos 10
estados, não apenas presumida.
