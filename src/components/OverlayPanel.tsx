import { useEffect, type ReactNode } from 'react'

export interface OverlayPanelProps {
  title: string
  isOpen: boolean
  onClose: () => void
  children: ReactNode
}

/** Slide-in panel for Sobre nós / Agendamento / Contato — opens over the main
 *  scroll experience without resetting or interrupting its scroll position. */
export function OverlayPanel({ title, isOpen, onClose, children }: OverlayPanelProps) {
  useEffect(() => {
    if (!isOpen) return
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose()
    }
    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
  }, [isOpen, onClose])

  return (
    <div className={`overlay-panel ${isOpen ? 'overlay-panel--open' : ''}`} aria-hidden={!isOpen}>
      <button
        type="button"
        className="overlay-panel__backdrop"
        aria-label="Fechar"
        tabIndex={isOpen ? 0 : -1}
        onClick={onClose}
      />
      <aside className="overlay-panel__content">
        <header className="overlay-panel__header">
          <h2>{title}</h2>
          <button type="button" className="overlay-panel__close" onClick={onClose} aria-label="Fechar">
            ×
          </button>
        </header>
        <div className="overlay-panel__body">{children}</div>
      </aside>
    </div>
  )
}
