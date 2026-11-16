import type { ComponentType } from 'react'
import type { LearnerState } from '../api'
import { AttendanceSummary, AttendanceTab } from './AttendancePart'

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
}
