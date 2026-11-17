import { api, type paths } from '@acadly/kernel'
import { read, settle } from '../setup/api'

type Json<P extends keyof paths, M extends 'get' | 'post' | 'put'> = paths[P][M] extends { responses: { 200: { content: { 'application/json': infer T } } } } ? T : never
type Done<T> = Promise<{ data?: T; error?: unknown; response: Response }>

export type WaitingState = Json<'/v1/approvals/waiting', 'get'>
export type Waiting = WaitingState['waiting'][number]
export type RequestState = Json<'/v1/approvals/{id}', 'get'>
export type CatalogueState = Json<'/v1/approvals/topics', 'get'>
export type TopicState = Json<'/v1/approvals/topics/{key}', 'get'>
export type Spec = TopicState['chain']['steps'][number]

export const approvalsApi = {
  waiting: () => read(api.GET('/v1/approvals/waiting')),
  request: (id: string) => read(api.GET('/v1/approvals/{id}', { params: { path: { id } } })),
  answer: (id: string, approve: boolean, reason: string | null) =>
    settle(api.POST('/v1/approvals/{id}/answer', { params: { path: { id } }, body: { approve, reason } as never }) as Done<RequestState>),
  withdraw: (id: string) => settle(api.POST('/v1/approvals/{id}/withdraw', { params: { path: { id } } }) as Done<RequestState>),
  catalogue: () => read(api.GET('/v1/approvals/topics')),
  topic: (key: string) => read(api.GET('/v1/approvals/topics/{key}', { params: { path: { key } } })),
  save: (key: string, steps: Spec[], fallbackRoleId: string | null) =>
    settle(api.PUT('/v1/approvals/topics/{key}', { params: { path: { key } }, body: { steps, fallback_role_id: fallbackRoleId } as never }) as Done<TopicState>),
}

/** "16 Nov 08:05". */
export const when = (iso: string) => {
  const at = new Date(iso)

  return `${at.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })} ${at.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })}`
}

/** Whole days since a moment, for "Oldest". */
export const daysSince = (iso: string | null) => (iso === null ? 0 : Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 86_400_000)))
