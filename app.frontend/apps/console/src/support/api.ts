import { api, type paths } from '@acadly/kernel'
import { read, settle } from '../setup/api'

type Get<P extends keyof paths> = paths[P] extends { get: { responses: { 200: { content: { 'application/json': infer T } } } } } ? T : never

export type Watch = Get<'/v1/support'>
export type WatchRow = Watch['learners'][number]
export type LearnerSupport = Get<'/v1/support/learners/{id}'>
export type Opening = Get<'/v1/support/learners/{id}/opening'>
export type Plan = Get<'/v1/support/plans/{id}'>
export type Rules = Get<'/v1/support/rules'>
export type GoalState = 'met' | 'partly' | 'not_yet'
export type CloseOutcome = 'goals_met' | 'partly_met' | 'referred' | 'left'

type Done<T> = Promise<{ data?: T; error?: unknown; response: Response }>

export const GOAL_STATES: GoalState[] = ['met', 'partly', 'not_yet']
export const OUTCOMES: CloseOutcome[] = ['goals_met', 'partly_met', 'referred', 'left']

export const supportApi = {
  watch: () => read(api.GET('/v1/support')),
  learner: (id: string) => read(api.GET('/v1/support/learners/{id}', { params: { path: { id } } })),
  opening: (id: string) => read(api.GET('/v1/support/learners/{id}/opening', { params: { path: { id } } })),
  plan: (id: string) => read(api.GET('/v1/support/plans/{id}', { params: { path: { id } } })),
  rules: () => read(api.GET('/v1/support/rules')),

  open: (body: { learner_id: string; title: string; concern: string; lead_id: string; confidential: boolean; tell_family: boolean; first_review: string; goals: { text: string; measure: string; by_when: string }[] }) =>
    settle(api.POST('/v1/support/plans', { body: body as never }) as Done<{ id: string }>),
  change: (id: string, what: 'goals' | 'actions' | 'agree' | 'reviews' | 'close', body: Record<string, unknown> = {}) =>
    settle(api.POST('/v1/support/plans/{id}/{what}', { params: { path: { id, what } }, body: body as never }) as Done<{ id: string }>),
  family: (id: string, body: { school: string; home: string; shown: boolean }) =>
    settle(api.PUT('/v1/support/plans/{id}/family', { params: { path: { id } }, body: body as never }) as Done<{ id: string }>),
  setAside: (learnerId: string, until: string, reason: string) =>
    settle(api.POST('/v1/support/learners/{id}/set-aside', { params: { path: { id: learnerId } }, body: { until, reason } as never }) as Done<{ set_aside: boolean }>),
  saveRules: (values: Record<string, number>) => settle(api.PUT('/v1/support/rules', { body: { values } as never }) as Done<{ saved: boolean }>),
}

/** A trend value as people read it: "78%", "3", or a dash. */
export function trendValue(value: number | null | undefined, unit: string): string {
  if (value === null || value === undefined) return '–'

  return `${Math.round(value * 10) / 10}${unit}`
}
