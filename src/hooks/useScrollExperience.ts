import { useEffect, useRef, useState, type RefObject } from 'react'
import gsap from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'
import { TOTAL_STAGES } from '../data/procedures'

gsap.registerPlugin(ScrollTrigger)

export interface ScrollState {
  /** Smoothed progress, 0 → 1, across the whole pinned experience. */
  progress: number
  stageIndex: number
  /** Progress within the active stage, 0 → 1. */
  stageProgress: number
}

function toStageState(progress: number): ScrollState {
  const clamped = Math.min(Math.max(progress, 0), 1)
  const scaled = clamped * TOTAL_STAGES
  const stageIndex = Math.min(Math.floor(scaled), TOTAL_STAGES - 1)
  const stageProgress = scaled - stageIndex
  return { progress: clamped, stageIndex, stageProgress }
}

const EPSILON = 0.0006

/**
 * Pins `stageRef` for the scroll distance of `trackRef` and turns raw scroll
 * position into a smoothed progress value. ScrollTrigger only reports *where*
 * the scrollbar is; a requestAnimationFrame loop lerps toward that target each
 * frame, so the rendered value — and everything downstream that reads it —
 * stays decoupled from individual wheel/touch events. This keeps motion fluid
 * on fast flicks, slow drags, and direction reversals alike.
 */
export function useScrollExperience(
  trackRef: RefObject<HTMLElement | null>,
  stageRef: RefObject<HTMLElement | null>,
): ScrollState {
  const [state, setState] = useState<ScrollState>(() => toStageState(0))
  const targetProgressRef = useRef(0)
  const renderedProgressRef = useRef(0)

  useEffect(() => {
    const trackEl = trackRef.current
    const stageEl = stageRef.current
    if (!trackEl || !stageEl) return

    const trigger = ScrollTrigger.create({
      trigger: trackEl,
      start: 'top top',
      end: 'bottom bottom',
      pin: stageEl,
      pinSpacing: false,
      scrub: true,
      onUpdate: (self) => {
        targetProgressRef.current = self.progress
      },
    })

    const prefersReducedMotion = window.matchMedia(
      '(prefers-reduced-motion: reduce)',
    ).matches
    const lerpFactor = prefersReducedMotion ? 1 : 0.12

    let frameId: number
    const tick = () => {
      const current = renderedProgressRef.current
      const target = targetProgressRef.current
      const diff = target - current
      const next = Math.abs(diff) < EPSILON ? target : current + diff * lerpFactor

      if (Math.abs(next - current) >= EPSILON || next !== current) {
        renderedProgressRef.current = next
        setState(toStageState(next))
      }

      frameId = requestAnimationFrame(tick)
    }
    frameId = requestAnimationFrame(tick)

    return () => {
      cancelAnimationFrame(frameId)
      trigger.kill()
    }
  }, [trackRef, stageRef])

  return state
}
