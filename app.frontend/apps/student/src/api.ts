import { api, type paths } from '@acadly/kernel'

/** The student portal's calls (D-101): only the signed-in student's own records. */
export type Me = paths['/v1/student/me']['get']['responses'][200]['content']['application/json']
export type Courses = paths['/v1/student/courses']['get']['responses'][200]['content']['application/json']
export type Registration = NonNullable<paths['/v1/student/registration']['get']['responses'][200]['content']['application/json']['registration']>
export type Fees = NonNullable<paths['/v1/student/fees']['get']['responses'][200]['content']['application/json']['fees']>
export type Post = paths['/v1/student/news']['get']['responses'][200]['content']['application/json']['posts'][number]

export type Answer<T> = T | 'refused' | null

async function read<T>(request: Promise<{ data?: T; response: Response }>): Promise<Answer<T>> {
  try {
    const { data, response } = await request
    if (data !== undefined) return data
    if (response.status === 401 || response.status === 403 || response.status === 404) return 'refused'

    return null
  } catch {
    return null
  }
}

/** What a change came to: saved, or the first message the school gave. */
export type Saved = { kind: 'saved' } | { kind: 'invalid'; message: string } | { kind: 'failed' }

async function save(request: Promise<{ data?: unknown; error?: unknown; response: Response }>): Promise<Saved> {
  try {
    const { error, response } = await request
    if (response.ok) return { kind: 'saved' }
    if (response.status === 422) {
      const errors = (error as { errors?: Record<string, string[]> } | undefined)?.errors ?? {}

      return { kind: 'invalid', message: Object.values(errors)[0]?.[0] ?? '' }
    }

    return { kind: 'failed' }
  } catch {
    return { kind: 'failed' }
  }
}

export const studentApi = {
  me: () => read(api.GET('/v1/student/me')),
  courses: () => read(api.GET('/v1/student/courses')),
  registration: () => read(api.GET('/v1/student/registration')),
  saveCourses: (offerings: string[]) => save(api.PUT('/v1/student/registration/courses', { body: { offerings } })),
  submit: () => save(api.POST('/v1/student/registration/submit')),
  fees: () => read(api.GET('/v1/student/fees')),
  news: () => read(api.GET('/v1/student/news')),
  post: (id: string) => read(api.GET('/v1/student/news/{id}', { params: { path: { id } } })),
  setUp: async (number: string, code: string, password: string) => {
    await api.GET('/v1/auth/csrf-cookie')

    return save(api.POST('/v1/student/set-up', { body: { number, code, password } }))
  },
  password: (current: string, next: string) =>
    save(api.POST('/v1/me/account/password', { body: { current_password: current, password: next, password_confirmation: next } })),
}

const kwacha = new Intl.NumberFormat('en-GB', { maximumFractionDigits: 0 })

/** Money from minor units: MK 245,000. */
export const money = (minor: number): string => `MK ${kwacha.format(Math.trunc(minor / 100))}`

/** A day as people read it: 30 Nov. */
export const day = (iso: string): string => new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })
