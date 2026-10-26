import { useRef, useState } from 'react'
import { Header, type PanelKey } from './components/Header'
import { OverlayPanel } from './components/OverlayPanel'
import { FacePlayer } from './components/FacePlayer'
import { HotspotLayer } from './components/HotspotLayer'
import { StageContent } from './components/StageContent'
import { StageNav } from './components/StageNav'
import { BookingCTA } from './components/BookingCTA'
import { useScrollExperience } from './hooks/useScrollExperience'
import { PROCEDURES, TOTAL_STAGES } from './data/procedures'

function App() {
  const trackRef = useRef<HTMLDivElement>(null)
  const stageRef = useRef<HTMLDivElement>(null)
  const { progress, stageIndex } = useScrollExperience(trackRef, stageRef)
  const [openPanel, setOpenPanel] = useState<PanelKey | null>(null)

  const procedure = PROCEDURES[stageIndex]
  const contentOnLeft = procedure.textSide === 'left'

  return (
    <>
      <Header onOpenPanel={setOpenPanel} />

      <div ref={trackRef} className="scroll-track" style={{ height: `${TOTAL_STAGES * 100}vh` }}>
        <section ref={stageRef} className="stage">
          <div className="stage__grid">
            <div className="stage__column stage__column--left">
              {contentOnLeft ? <StageContent procedure={procedure} /> : <StageNav stageIndex={stageIndex} progress={progress} />}
            </div>

            <div className="stage__column stage__column--center">
              <div className="face-stage">
                <FacePlayer progress={progress} />
                <HotspotLayer procedure={procedure} />
              </div>
            </div>

            <div className="stage__column stage__column--right">
              {contentOnLeft ? <StageNav stageIndex={stageIndex} progress={progress} /> : <StageContent procedure={procedure} />}
            </div>
          </div>
        </section>
      </div>

      <OverlayPanel title="Sobre nós" isOpen={openPanel === 'sobre'} onClose={() => setOpenPanel(null)}>
        <p>
          Auréa é dedicada à harmonização facial com foco em naturalidade e
          proporção — cada procedimento é pensado para preservar a identidade
          de quem o recebe.
        </p>
      </OverlayPanel>

      <OverlayPanel title="Agendamento" isOpen={openPanel === 'agendamento'} onClose={() => setOpenPanel(null)}>
        <p>Agende uma avaliação personalizada com nossa equipe.</p>
        <BookingCTA />
      </OverlayPanel>

      <OverlayPanel title="Contato" isOpen={openPanel === 'contato'} onClose={() => setOpenPanel(null)}>
        <p>Email: contato@aurea.com.br</p>
        <p>Endereço: a definir</p>
      </OverlayPanel>
    </>
  )
}

export default App
