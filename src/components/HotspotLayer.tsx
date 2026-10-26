import type { Procedure } from '../data/procedures'

export interface HotspotLayerProps {
  procedure: Procedure
}

/** Discrete golden marker + thin connector line pointing at the active stage's facial region. */
export function HotspotLayer({ procedure }: HotspotLayerProps) {
  const { x, y } = procedure.hotspot
  const labelSide = x < 50 ? 'left' : 'right'
  const labelX = labelSide === 'left' ? Math.max(x - 18, 6) : Math.min(x + 18, 94)

  return (
    <svg
      className="hotspot-layer"
      viewBox="0 0 100 100"
      preserveAspectRatio="none"
      aria-hidden="true"
    >
      <line
        x1={x}
        y1={y}
        x2={labelX}
        y2={y}
        className="hotspot-layer__line"
      />
      <circle cx={x} cy={y} r="0.9" className="hotspot-layer__dot" />
      <circle cx={x} cy={y} r="2.2" className="hotspot-layer__ring" />
    </svg>
  )
}
