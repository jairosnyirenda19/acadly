import type { ComponentType } from 'react'
import type { LearnerState } from '../api'
import { AttendanceSummary, AttendanceTab } from './AttendancePart'
import { FeesSummary, FeesTab } from './FeesPart'
import { ConductSummary, ConductTab } from './ConductPart'
import { SupportSummary, SupportTab } from './SupportPart'
import { AssessmentSummary, AssessmentTab, PickupSummary, PickupTab } from './EarlyParts'

/**
 * The parts of a learner's record the console can draw, by the key the server
 * gives in `parts` (one per module that is on). A part this list does not
 * know gets no tab and no block. Adding the next module's part is one
 * component file and one entry here.
 */
export interface PartView {
  /** Its block on the overview. */
  Summary: ComponentType<{ learner: LearnerState }>
  /** Its own tab. */
  Tab: ComponentType<{ learner: LearnerState }>
}

export const PART_VIEWS: Partial<Record<string, PartView>> = {
  attendance: { Summary: AttendanceSummary, Tab: AttendanceTab },
  assessment: { Summary: AssessmentSummary, Tab: AssessmentTab },
  saferelease: { Summary: PickupSummary, Tab: PickupTab },
  fees: { Summary: FeesSummary, Tab: FeesTab },
  conduct: { Summary: ConductSummary, Tab: ConductTab },
  support: { Summary: SupportSummary, Tab: SupportTab },
}
