import { api, type paths } from '@acadly/kernel'
import { gridApiQuery, type GridQuery } from '@acadly/ui'
import { read, settle } from '../setup/api'

export type PeopleState = paths['/v1/people']['get']['responses'][200]['content']['application/json']
export type PersonState = paths['/v1/people/{id}']['get']['responses'][200]['content']['application/json']
export type RolesState = paths['/v1/roles']['get']['responses'][200]['content']['application/json']
export type Giving = PeopleState['giving']
export type Place = Giving['places'][number]
export type PersonRow = PeopleState['people'][number]
export type PersonStateName = PersonRow['state']
export type PeopleGrid = paths['/v1/people/grid']['get']['responses'][200]['content']['application/json']
export type PersonGridRow = PeopleGrid['rows'][number]

type Done<T> = Promise<{ data?: T; error?: unknown; response: Response }>

export const settingsApi = {
  /** One page of the people, for the data grid. */
  peopleGrid: (query: GridQuery) => read(api.GET('/v1/people/grid', { params: { query: gridApiQuery(query) as never } })),
  people: (inPlace: string | null) => read(api.GET('/v1/people', { params: { query: inPlace !== null ? { in: inPlace } : {} } })),
  person: (id: string) => read(api.GET('/v1/people/{id}', { params: { path: { id } } })),
  roles: () => read(api.GET('/v1/roles')),

  invite: (body: { name: string; email: string; role_id: string; scope_type: string; scope_id: string | null; send: boolean }) =>
    settle(api.POST('/v1/people', { body: body as never }) as Done<PersonState>),

  resend: (id: string) => settle(api.POST('/v1/people/{id}/invitation', { params: { path: { id } } }) as Done<PersonState>),

  give: (id: string, body: { role_id: string; scope_type: string; scope_id: string | null; starts_on: string | null; ends_on: string | null; reason: string }) =>
    settle(api.POST('/v1/people/{id}/roles', { params: { path: { id } }, body: body as never }) as Done<PersonState>),

  end: (id: string, assignment: string, reason: string) =>
    settle(api.POST('/v1/people/{id}/roles/{assignment}/end', { params: { path: { id, assignment } }, body: { reason } as never }) as Done<PersonState>),

  disable: (id: string, reason: string) =>
    settle(api.POST('/v1/people/{id}/disable', { params: { path: { id } }, body: { reason } as never }) as Done<PersonState>),

  passwordReset: (id: string) => settle(api.POST('/v1/people/{id}/password-reset', { params: { path: { id } } }) as Done<{ status: string }>),

  createRole: (body: { name: string; permissions: string[] }) => settle(api.POST('/v1/roles', { body: body as never }) as Done<RolesState>),

  saveRole: (id: string, body: { name: string; description: string | null; permissions: string[] }) =>
    settle(api.PUT('/v1/roles/{id}', { params: { path: { id } }, body: body as never }) as Done<RolesState>),

  removeRole: (id: string) => settle(api.DELETE('/v1/roles/{id}', { params: { path: { id } } }) as Done<RolesState>),
}

export const placeKey = (place: { type: string; id: string | null }) => `${place.type}:${place.id ?? ''}`

/**
 * The permissions of a role the viewer does not hold at a place, by label.
 * Empty when the viewer may give the role there. The server decides again.
 */
export function missingAt(giving: Giving, roleId: string, key: string): string[] {
  const role = giving.roles.find((r) => r.id === roleId)
  const place = giving.places.find((p) => placeKey(p) === key)

  if (role === undefined || place === undefined) return []

  const held = new Set(giving.yours.filter((y) => place.within.includes(y.place)).map((y) => y.permission))

  return role.permissions.filter((p) => !held.has(p)).map((p) => giving.labels[p] ?? p)
}

/** A date as people read it: 19 Dec 2026. */
export function day(iso: string | null | undefined): string {
  if (iso === null || iso === undefined || iso === '') return ''

  return new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
}

/** Whole days from now until a moment; negative in the past. */
export function daysUntil(iso: string): number {
  return Math.ceil((Date.parse(iso) - Date.now()) / 86_400_000)
}

/** Whole days from a moment until now. */
export function daysSince(iso: string): number {
  return Math.floor((Date.now() - Date.parse(iso)) / 86_400_000)
}
