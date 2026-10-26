import { useState } from 'react'

/**
 * TODO: integration pending. Fill this in with the real number in
 * international format (e.g. "5511999999999") once the client provides one —
 * nothing else in this component needs to change.
 */
const WHATSAPP_NUMBER = ''

export function BookingCTA() {
  const [showPendingNote, setShowPendingNote] = useState(false)
  const isConfigured = WHATSAPP_NUMBER.length > 0

  if (isConfigured) {
    return (
      <a
        className="booking-cta"
        href={`https://wa.me/${WHATSAPP_NUMBER}`}
        target="_blank"
        rel="noreferrer"
      >
        Agendar pelo WhatsApp
      </a>
    )
  }

  return (
    <div className="booking-cta booking-cta--pending">
      <button
        type="button"
        className="booking-cta__button"
        aria-disabled="true"
        onClick={() => setShowPendingNote(true)}
      >
        Agendar pelo WhatsApp
      </button>
      {showPendingNote && (
        <p className="booking-cta__note" role="status">
          Integração com o WhatsApp em breve. Deixe o número da clínica
          configurado em <code>BookingCTA.tsx</code> para ativar este botão.
        </p>
      )}
    </div>
  )
}
