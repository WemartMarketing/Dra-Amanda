import { useEffect, useRef } from 'react'

export interface FacePlayerProps {
  /** Smoothed progress, 0 → 1, across the whole scroll experience. */
  progress: number
}

/**
 * PLACEHOLDER — stands in for the real 120-frame photographic sequence
 * (continuous 180° rotation: left profile → frontal → right profile) that
 * the original session extracted from the model's video and could not be
 * carried into this environment. This renders an abstract gold line-art face
 * instead of hallucinating a fake photo or video.
 *
 * Swap-in point: keep the `progress` prop and the outer
 * `.face-stage__frame` sizing exactly as they are, and replace everything
 * inside that wrapper with a <canvas>-based frame-sequence player, e.g.:
 *
 *   const frameIndex = Math.round(progress * (TOTAL_FRAMES - 1))
 *   ctx.drawImage(frames[frameIndex], 0, 0, canvas.width, canvas.height)
 *
 * drawn inside a requestAnimationFrame tick keyed off the same `progress`
 * value this component already receives. Nothing above this component
 * (grid layout, hotspots, text columns) needs to change.
 */
export function FacePlayer({ progress }: FacePlayerProps) {
  const headRef = useRef<SVGGElement>(null)
  const reducedMotion = useRef(false)

  useEffect(() => {
    reducedMotion.current = window.matchMedia(
      '(prefers-reduced-motion: reduce)',
    ).matches
  }, [])

  useEffect(() => {
    const head = headRef.current
    if (!head) return
    // Gentle impression of the turn captured in the real footage: a partial
    // yaw driven by overall progress rather than a literal 180° rotation,
    // since flat line-art reads poorly past a modest angle.
    const angle = (progress - 0.5) * 46
    head.style.transform = `rotateY(${angle}deg)`
  }, [progress])

  return (
    <div className="face-stage__frame">
      <div className="face-stage__perspective">
        <svg
          viewBox="0 0 100 100"
          className="face-placeholder"
          role="img"
          aria-label="Representação ilustrativa do rosto (imagem real a ser inserida)"
        >
          <defs>
            <linearGradient id="faceGradient" x1="0%" y1="0%" x2="100%" y2="100%">
              <stop offset="0%" stopColor="#f4ecdf" />
              <stop offset="55%" stopColor="#e6d9c3" />
              <stop offset="100%" stopColor="#cdb48a" />
            </linearGradient>
            <linearGradient id="rimGold" x1="0%" y1="0%" x2="100%" y2="0%">
              <stop offset="0%" stopColor="#ad9369" />
              <stop offset="100%" stopColor="#806339" />
            </linearGradient>
          </defs>

          <g ref={headRef} className="face-placeholder__head">
            <ellipse cx="50" cy="52" rx="24" ry="30" fill="url(#faceGradient)" />
            <path
              d="M29 46 C27 30, 38 14, 50 14 C62 14, 73 30, 71 46"
              fill="none"
              stroke="url(#rimGold)"
              strokeWidth="0.6"
              opacity="0.7"
            />
            <path
              d="M31 70 C36 82, 44 88, 50 88 C56 88, 64 82, 69 70"
              fill="none"
              stroke="url(#rimGold)"
              strokeWidth="0.6"
              opacity="0.6"
            />
            <circle cx="40" cy="47" r="1.4" fill="#806339" opacity="0.8" />
            <circle cx="60" cy="47" r="1.4" fill="#806339" opacity="0.8" />
            <path
              d="M46 60 C48 62, 52 62, 54 60"
              fill="none"
              stroke="#806339"
              strokeWidth="0.8"
              strokeLinecap="round"
              opacity="0.75"
            />
            <path
              d="M50 50 L50 57"
              fill="none"
              stroke="#806339"
              strokeWidth="0.5"
              strokeLinecap="round"
              opacity="0.4"
            />
          </g>
        </svg>
      </div>
      <p className="face-placeholder__caption">
        Imagem ilustrativa — sequência fotográfica real a ser inserida
      </p>
    </div>
  )
}
