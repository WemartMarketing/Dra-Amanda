export interface Hotspot {
  x: number // percentage, 0-100, left -> right across the face stage
  y: number // percentage, 0-100, top -> bottom across the face stage
}

export interface Procedure {
  id: string
  index: number
  kicker: string
  title: string
  description: string
  hotspot: Hotspot
  /** Side of the layout the text column renders on for this stage. */
  textSide: 'left' | 'right'
  /** Set when the previous session did not have a real illustrative asset for this stage. */
  pendingAsset?: string
  /** Marks the final, booking-focused stage. */
  isFinal?: boolean
}

export const PROCEDURES: Procedure[] = [
  {
    id: 'olheiras',
    index: 0,
    kicker: '01 — Olhar',
    title: 'Olheiras',
    description:
      'Preenchimento da região periorbital para suavizar sombras e devolver luminosidade ao olhar, respeitando a anatomia natural do rosto.',
    hotspot: { x: 38, y: 41 },
    textSide: 'left',
  },
  {
    id: 'labial',
    index: 1,
    kicker: '02 — Lábios',
    title: 'Preenchimento Labial',
    description:
      'Volume e contorno equilibrados, buscando proporção com o restante do rosto — sem exagero, com naturalidade.',
    hotspot: { x: 50, y: 63 },
    textSide: 'right',
    pendingAsset: 'Animação ilustrativa da variação de volume labial',
  },
  {
    id: 'mandibula',
    index: 2,
    kicker: '03 — Mandíbula',
    title: 'Contorno Mandibular',
    description:
      'Definição da linha mandibular para estruturar o terço inferior da face e marcar a transição para o pescoço.',
    hotspot: { x: 69, y: 71 },
    textSide: 'left',
    pendingAsset: 'Animação ilustrativa da mudança de contorno mandibular',
  },
  {
    id: 'queixo',
    index: 3,
    kicker: '04 — Queixo',
    title: 'Queixo',
    description:
      'Ajuste de projeção e proporção do queixo em relação ao nariz e à testa, equilibrando o perfil facial.',
    hotspot: { x: 50, y: 80 },
    textSide: 'right',
    pendingAsset: 'Animação ilustrativa da projeção e proporção do queixo',
  },
  {
    id: 'perfiloplastia',
    index: 4,
    kicker: '05 — Perfil',
    title: 'Perfiloplastia',
    description:
      'Harmonização do perfil como um todo — testa, nariz, lábios e queixo lidos em conjunto, não isoladamente.',
    hotspot: { x: 76, y: 52 },
    textSide: 'left',
  },
  {
    id: 'toxina',
    index: 5,
    kicker: '06 — Expressão',
    title: 'Toxina Botulínica',
    description:
      'Suavização de linhas de expressão na testa e ao redor dos olhos, preservando a mobilidade natural do rosto.',
    hotspot: { x: 50, y: 23 },
    textSide: 'right',
    pendingAsset: 'Animação ilustrativa de expressão facial com toxina botulínica',
  },
  {
    id: 'global',
    index: 6,
    kicker: '07 — Síntese',
    title: 'Harmonização Global',
    description:
      'Todos os ajustes lidos como um único resultado: equilíbrio, proporção e identidade preservada.',
    hotspot: { x: 50, y: 50 },
    textSide: 'left',
  },
  {
    id: 'agendamento',
    index: 7,
    kicker: '08 — Próximo passo',
    title: 'Agendamento',
    description:
      'Converse com nossa equipe e agende uma avaliação personalizada para o seu rosto.',
    hotspot: { x: 50, y: 50 },
    textSide: 'right',
    isFinal: true,
  },
]

export const TOTAL_STAGES = PROCEDURES.length
