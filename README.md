# Projeto Auréa

Site de harmonização facial com experiência cinematográfica guiada pela
rolagem — React + TypeScript + Vite + GSAP (ScrollTrigger).

## Rodando o projeto

```bash
npm install
npm run dev      # servidor de desenvolvimento
npm run build    # build de produção (tsc + vite build)
npm run lint      # oxlint
npm run preview   # serve o build de produção localmente
```

## Como funciona

A rolagem é ancorada por um único trilho (`.scroll-track`, altura =
`TOTAL_STAGES * 100vh`) que mantém o palco (`.stage`) fixo via
`ScrollTrigger` (`src/hooks/useScrollExperience.ts`). O ScrollTrigger só
informa a posição bruta da rolagem; um loop de `requestAnimationFrame`
interpola (lerp) esse valor a cada quadro, então o progresso renderizado —
e tudo que depende dele — fica desacoplado de eventos individuais de
scroll/touch, com movimento fluido em rolagem rápida, lenta e reversa.
`prefers-reduced-motion` reduz o lerp para um salto direto, sem suavização.

## As 8 etapas

Definidas em `src/data/procedures.ts`, cada uma com título, descrição,
posição do hotspot (coordenadas percentuais sobre o palco do rosto) e lado
da coluna de texto:

1. **Olheiras** — preenchimento da região periorbital
2. **Preenchimento Labial**
3. **Contorno Mandibular**
4. **Queixo**
5. **Perfiloplastia**
6. **Toxina Botulínica**
7. **Harmonização Global**
8. **Agendamento** — etapa final, com o CTA de WhatsApp

Layout em grade de 3 colunas (texto | rosto | nav/texto) garante que texto
nunca sobreponha o rosto; em telas estreitas (≤860px) as colunas se
empilham verticalmente em vez de sobrepor.

## Pendências conhecidas

### 1. Sequência fotográfica real (120 frames)

Os dois vídeos originais do modelo (rotação contínua de 180°: perfil
esquerdo → frontal → perfil direito) não estavam disponíveis neste
ambiente. Em vez de inventar uma foto/vídeo falso, `src/components/FacePlayer.tsx`
renderiza um retrato abstrato em linha dourada, dirigido pelo mesmo valor de
progresso (0–1) que a sequência real usaria.

**Ponto de substituição:** mantenha a prop `progress: number` e o wrapper
`.face-stage__frame` exatamente como estão; troque o conteúdo interno por um
`<canvas>` que desenha o frame correspondente:

```ts
const frameIndex = Math.round(progress * (TOTAL_FRAMES - 1))
ctx.drawImage(frames[frameIndex], 0, 0, canvas.width, canvas.height)
```

chamado dentro do mesmo ciclo de `requestAnimationFrame` já orientado por
`progress`. Nada fora desse componente precisa mudar.

### 2. Número de WhatsApp

`src/components/BookingCTA.tsx` define `WHATSAPP_NUMBER = ''`. Enquanto
vazio, o botão aparece em estado pendente (desabilitado visualmente) e exibe
uma nota ao ser clicado, em vez de usar um número falso. Preencher a
constante com o número real (formato internacional, ex. `"5511999999999"`)
ativa o link `wa.me` automaticamente — nenhuma outra mudança é necessária.

### 3. Animações ilustrativas ausentes (4)

As etapas abaixo mostram uma nota "Pendente: …" em vez de uma animação
falsa, pois os ativos ilustrativos reais não foram fornecidos:

- Preenchimento Labial — variação de volume labial
- Contorno Mandibular — mudança de contorno mandibular
- Queixo — projeção e proporção do queixo
- Toxina Botulínica — expressão facial

Essas notas estão marcadas em `src/data/procedures.ts` no campo
`pendingAsset` de cada etapa.
