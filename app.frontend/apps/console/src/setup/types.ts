import type { ReactNode } from 'react'
import type { AcademicsState, InstitutionState, SetupState } from './api'

/** What every setup step is given by the wizard. */
export interface StepProps {
  institution: InstitutionState
  onInstitution: (state: InstitutionState) => void
  academics: AcademicsState
  onAcademics: (state: AcademicsState) => void
  setup: SetupState
  onSetup: (state: SetupState) => void
  /** Renders the step inside the setup frame. */
  layout: (page: { title: string; intro: string; children: ReactNode; footer: ReactNode }) => ReactNode
  /** Goes to the next step, after the server state has been read again. */
  next: () => void
}
