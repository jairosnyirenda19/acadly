import { api, type paths } from '@acadly/kernel'
import { read, settle } from '../setup/api'

export type DayState = paths['/v1/registers']['get']['responses'][200]['content']['application/json']
export type RegisterState = paths['/v1/registers/class/{id}']['get']['responses'][200]['content']['application/json']
export type RegisterLearner = RegisterState['learners'][number]
export type MarkValue = 'present' | 'absent' | 'late' | 'excused'
export type Absence = NonNullable<RegisterLearner['absence']>

/** A family is reached when a message was delivered or accepted, is on its way, or staff called. */
export function unreached(absence: Absence): boolean {
  if (absence.follow_ups.some((f) => f.kind === 'called')) return false

  return !absence.told.some((d) => ['delivered', 'sent', 'accepted', 'waiting'].includes(d.state))
}

export const MARKS: MarkValue[] = ['present', 'absent', 'late', 'excused']

type Done<T> = Promise<{ data?: T; error?: unknown; response: Response }>

export const registersApi = {
  day: (day: string | null, inPlace: string | null) =>
    read(api.GET('/v1/registers', { params: { query: { ...(day !== null ? { day } : {}), ...(inPlace !== null ? { in: inPlace } : {}) } as never } })),
  register: (classId: string, day: string | null) => read(api.GET('/v1/registers/class/{id}', { params: { path: { id: classId }, query: (day !== null ? { day } : {}) as never } })),

  submit: (classId: string, day: string, marks: { learner_id: string; status: MarkValue; note: string | null }[]) =>
    settle(api.POST('/v1/registers/class/{id}', { params: { path: { id: classId } }, body: { day, marks } as never }) as Done<RegisterState>),

  requestCorrection: (registerId: string, body: { learner_id: string; to: MarkValue; reason: string }) =>
    settle(api.POST('/v1/registers/{register}/corrections', { params: { path: { register: registerId } }, body: body as never }) as Done<RegisterState>),

  tellNext: (registerId: string, learnerId: string) =>
    settle(api.POST('/v1/registers/{register}/absences/{learner}/next', { params: { path: { register: registerId, learner: learnerId } } }) as Done<RegisterState>),

  called: (registerId: string, learnerId: string, note: string) =>
    settle(api.POST('/v1/registers/{register}/absences/{learner}/called', { params: { path: { register: registerId, learner: learnerId } }, body: { note } as never }) as Done<RegisterState>),

  decide: (correctionId: string, approve: boolean, note: string) =>
    settle(api.POST('/v1/registers/corrections/{id}/decide', { params: { path: { id: correctionId } }, body: { approve, note } as never }) as Done<RegisterState>),
}

/** A day as people read it: Tuesday 17 November 2026. */
export function longDay(iso: string): string {
  return new Date(`${iso}T12:00:00`).toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
}

/** A short day: Tue 17 Nov. */
export function shortDay(iso: string): string {
  return new Date(`${iso}T12:00:00`).toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' })
}

/** The time of a moment: 07:42. */
export function clock(iso: string): string {
  return new Date(iso).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })
}

/** The look of each mark: a glyph on its colour, never colour alone (DESIGN.md). */
export const markLook: Record<MarkValue | 'none', string> = {
  present: 'border-2 border-teal bg-teal text-sheet',
  absent: 'border-[3px] border-red-pen bg-sheet text-red-pen',
  late: 'border-2 border-ink bg-marigold text-ink',
  excused: 'border-2 border-dotted border-ink bg-sheet text-ink',
  none: 'border-2 border-dashed border-rule-dashed bg-sheet text-text-muted',
}
