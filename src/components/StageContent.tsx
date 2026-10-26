import type { Procedure } from '../data/procedures'
import { BookingCTA } from './BookingCTA'

export interface StageContentProps {
  procedure: Procedure
}

export function StageContent({ procedure }: StageContentProps) {
  return (
    <div className="stage-content" key={procedure.id}>
      <span className="stage-content__kicker">{procedure.kicker}</span>
      <h1 className="stage-content__title">{procedure.title}</h1>
      <p className="stage-content__description">{procedure.description}</p>
      {procedure.pendingAsset && (
        <p className="stage-content__pending">
          Pendente: {procedure.pendingAsset}.
        </p>
      )}
      {procedure.isFinal && <BookingCTA />}
    </div>
  )
}
