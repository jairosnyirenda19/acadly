import { api, type paths } from '@acadly/kernel'
import { read, settle } from '../setup/api'

type Get<P extends keyof paths> = paths[P] extends { get: { responses: { 200: { content: { 'application/json': infer T } } } } } ? T : never
type Done<T> = Promise<{ data?: T; error?: unknown; response: Response }>

export type Rooms = Get<'/v1/assessment/rooms'>
export type RoomToday = Get<'/v1/assessment/rooms/{id}'>
export type RoomDay = Get<'/v1/assessment/rooms/{id}/observations'>
export type Reviews = Get<'/v1/assessment/reviews'>
export type ReviewRow = Reviews['children'][number]
export type Review = Get<'/v1/assessment/reviews/learner/{id}'>
export type Desk = Get<'/v1/pickup'>
export type PickupChild = Get<'/v1/pickup/learners/{id}'>
export type Collector = PickupChild['may_collect'][number]

/** The steps of an observation, as the server names them. */
export type StepKey = 'starting' | 'growing' | 'can_do' | 'not_seen'

export const earlyApi = {
  rooms: () => read(api.GET('/v1/assessment/rooms')),
  room: (id: string) => read(api.GET('/v1/assessment/rooms/{id}', { params: { path: { id } } })),
  day: (id: string, subjectId: string, day: string) =>
    read(api.GET('/v1/assessment/rooms/{id}/observations', { params: { path: { id }, query: { subject_id: subjectId, day } as never } })),
  record: (id: string, body: { subject_id: string; observed_on: string; focus: string; marks: { learner_id: string; step: StepKey; note: string | null }[] }) =>
    settle(api.POST('/v1/assessment/rooms/{id}/observations', { params: { path: { id } }, body: body as never }) as Done<{ recorded: number }>),
  reviews: () => read(api.GET('/v1/assessment/reviews')),
  review: (learnerId: string) => read(api.GET('/v1/assessment/reviews/learner/{id}', { params: { path: { id: learnerId } } })),
  save: (learnerId: string, body: { steps: Record<string, string>; family_note: string; home_note: string }) =>
    settle(api.PUT('/v1/assessment/reviews/learner/{id}', { params: { path: { id: learnerId } }, body: body as never }) as Done<{ id: string }>),
  step: (id: string, what: 'submit' | 'check' | 'return', reason = '') => {
    const path = { params: { path: { id } }, body: { reason } as never }
    const call =
      what === 'submit' ? api.POST('/v1/assessment/reviews/{id}/submit', path) : what === 'check' ? api.POST('/v1/assessment/reviews/{id}/check', path) : api.POST('/v1/assessment/reviews/{id}/return', path)

    return settle(call as Done<{ ok: boolean }>)
  },
  share: (ids: string[]) => settle(api.POST('/v1/assessment/reviews/share', { body: { ids } as never }) as Done<{ shared: number }>),

  desk: (classId: string) => read(api.GET('/v1/pickup', { params: { query: (classId === '' ? {} : { class_id: classId }) as never } })),
  child: (learnerId: string) => read(api.GET('/v1/pickup/learners/{id}', { params: { path: { id: learnerId } } })),
  release: (learnerId: string, kind: 'contact' | 'collector', collectorId: string, code: string) =>
    settle(api.POST('/v1/pickup/learners/{id}/release', { params: { path: { id: learnerId } }, body: { kind, collector_id: collectorId, code } as never }) as Done<{ id: string }>),
  refuse: (learnerId: string, name: string, note: string) =>
    settle(api.POST('/v1/pickup/learners/{id}/refuse', { params: { path: { id: learnerId } }, body: { name, note } as never }) as Done<{ id: string }>),
  addCollector: (learnerId: string, body: { name: string; relationship: string; phone: string; today_only: boolean }) =>
    settle(api.POST('/v1/pickup/learners/{id}/collectors', { params: { path: { id: learnerId } }, body: body as never }) as Done<{ id: string }>),
  endCollector: (id: string) => settle(api.POST('/v1/pickup/collectors/{id}/end', { params: { path: { id } } }) as Done<{ ok: boolean }>),
  bar: (learnerId: string, contactId: string, reason: string) =>
    settle(api.POST('/v1/pickup/learners/{id}/bars', { params: { path: { id: learnerId } }, body: { contact_id: contactId, reason } as never }) as Done<{ ok: boolean }>),
  endBar: (id: string, reason: string) => settle(api.POST('/v1/pickup/bars/{id}/end', { params: { path: { id } }, body: { reason } as never }) as Done<{ ok: boolean }>),
}

/** The first error of a refused form, or the fallback. */
export function firstError(result: { kind: string; errors?: Record<string, string[]> }, fallback: string): string {
  return result.kind === 'invalid' ? (Object.values(result.errors ?? {})[0]?.[0] ?? fallback) : fallback
}
