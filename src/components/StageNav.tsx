import { PROCEDURES } from '../data/procedures'

export interface StageNavProps {
  stageIndex: number
  progress: number
}

/** Vertical index of the 8 stages plus a continuous fill tracking overall scroll progress. */
export function StageNav({ stageIndex, progress }: StageNavProps) {
  return (
    <div className="stage-nav">
      <div className="stage-nav__track">
        <div className="stage-nav__fill" style={{ height: `${progress * 100}%` }} />
      </div>
      <ol className="stage-nav__list">
        {PROCEDURES.map((procedure, index) => (
          <li
            key={procedure.id}
            className={index === stageIndex ? 'stage-nav__item stage-nav__item--active' : 'stage-nav__item'}
          >
            <span className="stage-nav__number">{String(index + 1).padStart(2, '0')}</span>
            <span className="stage-nav__label">{procedure.title}</span>
          </li>
        ))}
      </ol>
    </div>
  )
}
