export type PanelKey = 'sobre' | 'agendamento' | 'contato'

export interface HeaderProps {
  onOpenPanel: (panel: PanelKey) => void
}

export function Header({ onOpenPanel }: HeaderProps) {
  return (
    <header className="site-header">
      <span className="site-header__mark">Auréa</span>
      <nav className="site-header__nav">
        <button type="button" onClick={() => onOpenPanel('sobre')}>
          Sobre nós
        </button>
        <button type="button" onClick={() => onOpenPanel('agendamento')}>
          Agendamento
        </button>
        <button type="button" onClick={() => onOpenPanel('contato')}>
          Contato
        </button>
      </nav>
    </header>
  )
}
