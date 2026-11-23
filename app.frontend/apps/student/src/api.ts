import { api, type paths } from '@acadly/kernel'

/** The student portal's calls (D-101): only the signed-in student's own records. */
export type Me = paths['/v1/student/me']['get']['responses'][200]['content']['application/json']
export type Courses = paths['/v1/student/courses']['get']['responses'][200]['content']['application/json']
export type Registration = NonNullable<paths['/v1/student/registration']['get']['responses'][200]['content']['application/json']['registration']>
export type Fees = NonNullable<paths['/v1/student/fees']['get']['responses'][200]['content']['application/json']['fees']>
export type Post = paths['/v1/student/news']['get']['responses'][200]['content']['application/json']['posts'][number]

export type LecturesToday = paths['/v1/student/lectures/today']['get']['responses'][200]['content']['application/json']
export type Lecture = LecturesToday['lectures'][number]
export type Week = paths['/v1/student/lectures/week']['get']['responses'][200]['content']['application/json']
export type Attendance = paths['/v1/student/lectures/attendance']['get']['responses'][200]['content']['application/json']
export type ScanResult = paths['/v1/student/lectures/scan']['post']['responses'][200]['content']['application/json']

export type ClassCourses = paths['/v1/student/classroom/courses']['get']['responses'][200]['content']['application/json']
export type ClassCourse = paths['/v1/student/classroom/courses/{id}']['get']['responses'][200]['content']['application/json']
export type ClassTask = paths['/v1/student/classroom/tasks/{id}']['get']['responses'][200]['content']['application/json']
export type Due = paths['/v1/student/classroom/due']['get']['responses'][200]['content']['application/json']

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
  lecturesToday: () => read(api.GET('/v1/student/lectures/today')),
  week: (from: string | null) => read(api.GET('/v1/student/lectures/week', { params: { query: (from === null ? {} : { from }) as never } })),
  attendance: () => read(api.GET('/v1/student/lectures/attendance')),
  phoneBegin: () => read(api.POST('/v1/student/lectures/phone/begin')),
  phoneSet: (body: { client_data_json: string; attestation_object: string }) => save(api.POST('/v1/student/lectures/phone', { body })),
  /** A scan: the answer, the first message for a code that is not an attendance code, or null when it could not be sent. */
  scan: async (body: { code: string; credential_id: string; client_data_json: string; authenticator_data: string; signature: string; latitude: number | null; longitude: number | null; accuracy: number | null }) => {
    try {
      const { data, error, response } = await api.POST('/v1/student/lectures/scan', { body: body as never })
      if (data !== undefined) return data
      if (response.status === 422) return { invalid: Object.values((error as { errors?: Record<string, string[]> } | undefined)?.errors ?? {})[0]?.[0] ?? '' }

      return null
    } catch {
      return null
    }
  },
  classCourses: () => read(api.GET('/v1/student/classroom/courses')),
  classCourse: (id: string) => read(api.GET('/v1/student/classroom/courses/{id}', { params: { path: { id } } })),
  classTask: (id: string) => read(api.GET('/v1/student/classroom/tasks/{id}', { params: { path: { id } } })),
  due: () => read(api.GET('/v1/student/classroom/due')),
  readAnnouncement: (id: string) => save(api.POST('/v1/student/classroom/announcements/{id}/read', { params: { path: { id } } })),
  withdraw: (id: string) => save(api.POST('/v1/student/classroom/tasks/{id}/withdraw', { params: { path: { id } } })),
  handIn: (id: string, files: File[]) => {
    const form = new FormData()
    for (const f of files) form.append('files[]', f)

    return save(api.POST('/v1/student/classroom/tasks/{id}/hand-in', { params: { path: { id } }, body: form as never, bodySerializer: (body: unknown) => body as FormData }))
  },
  password: (current: string, next: string) =>
    save(api.POST('/v1/me/account/password', { body: { current_password: current, password: next, password_confirmation: next } })),
}

const kwacha = new Intl.NumberFormat('en-GB', { maximumFractionDigits: 0 })

/** Money from minor units: MK 245,000. */
export const money = (minor: number): string => `MK ${kwacha.format(Math.trunc(minor / 100))}`

/** Where a file of the student's courses opens. */
export const classFile = (id: string): string => `/api/v1/student/classroom/files/${id}`

/** A day as people read it: 30 Nov. */
export const day = (iso: string): string => new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })
