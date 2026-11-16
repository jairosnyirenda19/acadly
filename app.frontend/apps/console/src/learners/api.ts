import { api, type paths } from '@acadly/kernel'
import { read, settle } from '../setup/api'

export type LearnersState = paths['/v1/learners']['get']['responses'][200]['content']['application/json']
export type LearnerRow = LearnersState['learners'][number]
export type LearnerState = paths['/v1/learners/{id}']['get']['responses'][200]['content']['application/json']
export type ContactInput = { name: string; relationship: string; phone: string; email: string; notices_by: 'sms' | 'email'; main: boolean }

type Done<T> = Promise<{ data?: T; error?: unknown; response: Response }>

export const learnersApi = {
  list: (inPlace: string | null) => read(api.GET('/v1/learners', { params: { query: inPlace !== null ? { in: inPlace } : {} } })),
  record: (id: string) => read(api.GET('/v1/learners/{id}', { params: { path: { id } } })),

  admit: (body: {
    first_name: string
    other_names: string
    surname: string
    sex: string
    date_of_birth: string
    class_id: string
    admitted_on: string
    admission_number: string
    different: boolean
    contacts: ContactInput[]
  }) => settle(api.POST('/v1/learners', { body: body as never }) as Done<{ id: string }>),

  correct: (id: string, body: { first_name: string; other_names: string; surname: string; sex: string; date_of_birth: string; admission_number: string; reason: string }) =>
    settle(api.PUT('/v1/learners/{id}', { params: { path: { id } }, body: body as never }) as Done<LearnerState>),

  move: (id: string, body: { class_id: string; from: string; reason: string }) =>
    settle(api.POST('/v1/learners/{id}/class', { params: { path: { id } }, body: body as never }) as Done<LearnerState>),

  addContact: (id: string, body: ContactInput) => settle(api.POST('/v1/learners/{id}/contacts', { params: { path: { id } }, body: body as never }) as Done<LearnerState>),

  noticesBy: (id: string, contact: string, channel: 'sms' | 'email') =>
    settle(api.POST('/v1/learners/{id}/contacts/{contact}/notices', { params: { path: { id, contact } }, body: { notices_by: channel } as never }) as Done<LearnerState>),
  mainContact: (id: string, contact: string) =>
    settle(api.POST('/v1/learners/{id}/contacts/{contact}/main', { params: { path: { id, contact } } }) as Done<LearnerState>),

  removeContact: (id: string, contact: string, reason: string) =>
    settle(api.POST('/v1/learners/{id}/contacts/{contact}/remove', { params: { path: { id, contact } }, body: { reason } as never }) as Done<LearnerState>),

  photo: (id: string, file: File) => {
    const form = new FormData()
    form.append('file', file)

    return settle(api.POST('/v1/learners/{id}/photo', { params: { path: { id } }, body: form as never, bodySerializer: (body: unknown) => body as FormData }) as Done<LearnerState>)
  },

  /** The photo as a local address for an image, or null. The bytes come with the session, never in a link. */
  photoUrl: async (id: string): Promise<string | null> => {
    try {
      const { data, response } = await api.GET('/v1/learners/{id}/photo', { params: { path: { id } }, parseAs: 'blob' })

      return response.ok && data instanceof Blob ? URL.createObjectURL(data) : null
    } catch {
      return null
    }
  },
}

/** Today as YYYY-MM-DD, in local time. */
export function today(): string {
  const d = new Date()

  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

/** Whole years between a date of birth and a day. */
export function ageOn(born: string, on: string): number | null {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(born) || !/^\d{4}-\d{2}-\d{2}$/.test(on)) return null
  const [by, bm, bd] = born.split('-').map(Number) as [number, number, number]
  const [oy, om, od] = on.split('-').map(Number) as [number, number, number]
  const age = oy - by - (om < bm || (om === bm && od < bd) ? 1 : 0)

  return age >= 0 && age < 120 ? age : null
}

/** Initials for a learner with no photo. */
export function initials(name: string): string {
  const parts = name.trim().split(/\s+/)

  return ((parts[0]?.[0] ?? '') + (parts.length > 1 ? (parts[parts.length - 1]?.[0] ?? '') : '')).toUpperCase()
}
