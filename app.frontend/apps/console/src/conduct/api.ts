import { api, type paths } from '@acadly/kernel'
import { read, settle } from '../setup/api'

type Get<P extends keyof paths> = paths[P] extends { get: { responses: { 200: { content: { 'application/json': infer T } } } } } ? T : never

export type LearnerConduct = Get<'/v1/conduct/learners/{id}'>
export type ConductEntry = LearnerConduct['entries'][number]
export type Incident = Get<'/v1/conduct/incidents/{id}'>
export type Overview = Get<'/v1/conduct'>
export type KindList = Get<'/v1/conduct/kinds'>
export type Kind = KindList['kinds'][number]
export type Level = 'minor' | 'moderate' | 'serious'
export type Sanction = 'no_action' | 'warning' | 'detention' | 'suspension' | 'referred'
export type CaseStep = 'take-up' | 'decide' | 'met' | 'told' | 'close' | 'withdraw'

type Done<T> = Promise<{ data?: T; error?: unknown; response: Response }>

export const LEVELS: Level[] = ['minor', 'moderate', 'serious']
export const SANCTIONS: Sanction[] = ['no_action', 'warning', 'detention', 'suspension', 'referred']

export const conductApi = {
  learner: (id: string) => read(api.GET('/v1/conduct/learners/{id}', { params: { path: { id } } })),
  incident: (id: string) => read(api.GET('/v1/conduct/incidents/{id}', { params: { path: { id } } })),
  overview: () => read(api.GET('/v1/conduct')),
  kinds: () => read(api.GET('/v1/conduct/kinds')),

  record: (body: { kind_id: string; level: Level | null; learners: string[]; happened_on: string; place: string; what: string; action: string; tell: boolean }) =>
    settle(api.POST('/v1/conduct/entries', { body: body as never }) as Done<{ entry_id: string; case_id: string | null }>),
  withdraw: (id: string, reason: string) => settle(api.POST('/v1/conduct/entries/{id}/withdraw', { params: { path: { id } }, body: { reason } as never }) as Done<{ withdrawn: boolean }>),
  step: (id: string, step: CaseStep, reason = '') =>
    settle(api.POST('/v1/conduct/incidents/{id}/{step}', { params: { path: { id, step } }, body: { reason } as never }) as Done<{ id: string }>),
  endSuspension: (id: string, learner: string, reason: string) =>
    settle(api.POST('/v1/conduct/incidents/{id}/learners/{learner}/end-suspension', { params: { path: { id, learner } }, body: { reason } as never }) as Done<{ id: string }>),
  /** A written statement on a serious incident (D-111): one photo or PDF, kept secret. */
  addStatement: (caseId: string, title: string, file: File) => {
    const form = new FormData()
    form.append('kind', 'conduct.statement')
    form.append('owner_id', caseId)
    form.append('title', title)
    form.append('file', file)

    return settle(api.POST('/v1/documents', { body: form as never, bodySerializer: (body: unknown) => body as FormData }) as Done<{ id: string }>)
  },
  statementUrl: (id: string) => `/api/v1/documents/${id}/content?disposition=inline`,
  note: (id: string, text: string) => settle(api.POST('/v1/conduct/incidents/{id}/notes', { params: { path: { id } }, body: { text } as never }) as Done<{ id: string }>),
  outcome: (id: string, body: { learner_id: string; outcome: Sanction; starts_on: string | null; days: number | null; reason: string }) =>
    settle(api.POST('/v1/conduct/incidents/{id}/outcomes', { params: { path: { id } }, body: body as never }) as Done<{ id: string }>),

  saveKinds: (kinds: { id: string | null; name: string; commendation: boolean; level: Level | null; always: boolean; retired: boolean }[]) =>
    settle(api.PUT('/v1/conduct/kinds', { body: { kinds } as never }) as Done<{ saved: boolean }>),
  saveWatch: (body: { incidents: number; days: number; open_serious: boolean; missed_incidents: number; missed_lessons: number; missed_days: number; portal: boolean }) => settle(api.PUT('/v1/conduct/watch', { body: body as never }) as Done<{ saved: boolean }>),
}
