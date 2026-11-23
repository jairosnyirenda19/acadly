import { api, can, moduleOn, useAppContext, type AppContext, type paths } from '@acadly/kernel'
import { useState } from 'react'
import { byProgrammes } from '../programmes/api'
import { read, settle } from '../setup/api'
import type { Division } from '../shell/divisions'
import { useWorkIn } from '../shell/WorkIn'

type Get<P extends keyof paths> = paths[P] extends {
  get: { responses: { 200: { content: { 'application/json': infer T } } } }
}
  ? T
  : never

export type ResultCourses = Get<'/v1/results/{division}/courses'>
export type ResultSheet = Get<'/v1/results/{division}/courses/{id}'>
export type SheetLine = ResultSheet['lines'][number]
export type BarList = Get<'/v1/results/{division}/bar'>
export type BoardList = Get<'/v1/results/{division}/board'>
export type Cohort = Get<'/v1/results/{division}/board/cohort'>
export type ReleaseView = Get<'/v1/results/{division}/release'>
export type ResultsSetup = Get<'/v1/results/{division}/setup'>
export type ResultsRecord = Get<'/v1/results/learners/{learner}'>
export type Band = ResultsSetup['scale']['bands'][number]
export type Sitting = 'main' | 'supplementary'

const API: string = import.meta.env.VITE_API_URL ?? '/api'
const pd = (division: string) => ({ params: { path: { division } } })
const pi = (division: string, id: string) => ({ params: { path: { division, id } } })
const period = (p: string | null) => (p === null ? {} : { period: p })

/** Results (D-105): result sheets, moderation, the attendance bar, the examiners' board, release and the scale. */
export const resultsApi = {
  courses: (division: string, p: string | null) => read(api.GET('/v1/results/{division}/courses', { params: { path: { division }, query: period(p) } })),
  sheet: (division: string, id: string, sitting: Sitting) => read(api.GET('/v1/results/{division}/courses/{id}', { params: { path: { division, id }, query: { sitting } } })),
  enter: (division: string, id: string, sitting: Sitting, examOn: string | null, marks: { learner_id: string; exam: number | null; absent: boolean }[]) =>
    settle(api.PUT('/v1/results/{division}/courses/{id}/marks', { ...pi(division, id), body: { sitting, exam_on: examOn, marks } as never })),
  submit: (division: string, id: string, sitting: Sitting) => settle(api.POST('/v1/results/{division}/courses/{id}/submit', { ...pi(division, id), body: { sitting } as never })),
  moderate: (division: string, sheet: string, changes: { learner_id: string; exam: number | null; reason: string }[]) =>
    settle(api.POST('/v1/results/{division}/sheets/{id}/moderation', { ...pi(division, sheet), body: { changes } as never })),
  decide: (division: string, sheet: string, pass: boolean, reason: string | null) =>
    settle(api.POST('/v1/results/{division}/sheets/{id}/decide', { ...pi(division, sheet), body: { pass, reason } as never })),
  liftBar: (division: string, offering: string, learner: string, reason: string) =>
    settle(api.POST('/v1/results/{division}/courses/{id}/bar/{learner}/lift', { params: { path: { division, id: offering, learner } }, body: { reason } as never })),
  defer: (division: string, sheet: string, learner: string, reason: string) =>
    settle(api.POST('/v1/results/{division}/sheets/{id}/defer', { ...pi(division, sheet), body: { learner_id: learner, reason } as never })),
  returnSheet: (division: string, sheet: string, reason: string) => settle(api.POST('/v1/results/{division}/sheets/{id}/return', { ...pi(division, sheet), body: { reason } as never })),
  correct: (division: string, sheet: string, learner: string, exam: number | null, reason: string) =>
    settle(api.POST('/v1/results/{division}/sheets/{id}/correct', { ...pi(division, sheet), body: { learner_id: learner, exam, reason } as never })),
  bar: (division: string, p: string | null) => read(api.GET('/v1/results/{division}/bar', { params: { path: { division }, query: period(p) } })),
  board: (division: string, p: string | null) => read(api.GET('/v1/results/{division}/board', { params: { path: { division }, query: period(p) } })),
  cohort: (division: string, p: string | null, cohort: string) => read(api.GET('/v1/results/{division}/board/cohort', { params: { path: { division }, query: { ...period(p), cohort } } })),
  approve: (division: string, p: string, cohort: string) => settle(api.POST('/v1/results/{division}/board/approve', { ...pd(division), body: { period_id: p, cohort } as never })),
  release: (division: string, p: string | null) => read(api.GET('/v1/results/{division}/release', { params: { path: { division }, query: period(p) } })),
  releaseNow: (division: string, p: string, sitting: Sitting, sms: boolean) =>
    settle(api.POST('/v1/results/{division}/release', { ...pd(division), body: { period_id: p, sitting, sms } as never })),
  supplementary: (division: string, p: string, examOn: string | null) =>
    settle(api.POST('/v1/results/{division}/supplementary', { ...pd(division), body: { period_id: p, exam_on: examOn } as never })),
  setup: (division: string) => read(api.GET('/v1/results/{division}/setup', pd(division))),
  saveScale: (division: string, body: { period_id: string; label: string; pass_mark: number; bands: Band[] }) =>
    settle(api.POST('/v1/results/{division}/setup/scales', { ...pd(division), body: body as never })),
  saveSettings: (division: string, body: { bar_percent: number; bar_days: number; hold_slip_for_fees: boolean }) =>
    settle(api.PUT('/v1/results/{division}/setup/settings', { ...pd(division), body: body as never })),
  learner: (learner: string) => read(api.GET('/v1/results/learners/{learner}', { params: { path: { learner } } })),
}

/** The address of a student's results slip for a semester, opened in a new tab. */
export const slipHref = (learner: string, p: string) => `${API}/v1/results/learners/${learner}/slip?period=${p}`

const PERMISSIONS = ['results.view', 'results.enter', 'results.moderate', 'results.board', 'results.release', 'results.setup'] as const

/** The college divisions where the account holds a Results permission, with the module on. */
export function resultsDivisions(context: AppContext, only?: (typeof PERMISSIONS)[number][]): Division[] {
  if (!moduleOn(context, 'results')) return []

  return context.divisions.filter((d) => byProgrammes(d) && (only ?? PERMISSIONS).some((p) => can(context, p, { type: 'division', id: d.id })))
}

/** The division a Results page works in: the one worked in when it qualifies, else the first that does. */
export function useResultsDivision(): { division: Division | null; choices: Division[]; choose: (id: string) => void } {
  const context = useAppContext()
  const { division: lens } = useWorkIn()
  const choices = resultsDivisions(context)
  const [picked, setPicked] = useState<string | null>(null)
  const division = choices.find((d) => d.id === picked) ?? choices.find((d) => d.id === lens?.id) ?? choices[0] ?? null

  return { division, choices, choose: setPicked }
}

/** Each result of a course, as the tags show it. */
export const RESULT_TONE: Record<string, 'done' | 'attention' | 'wrong' | 'plain'> = {
  pass: 'done',
  fail: 'wrong',
  barred: 'wrong',
  repeat: 'wrong',
  deferred: 'attention',
  missing: 'attention',
}

/** A day as staff read it: Mon 7 Dec 2026. */
export const day = (iso: string): string => new Date(iso).toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' })
