import { api, type paths } from '@acadly/kernel'
import { read, settle } from '../setup/api'

type Get<P extends keyof paths> = paths[P] extends {
  get: { responses: { 200: { content: { 'application/json': infer T } } } }
}
  ? T
  : never
type Done<T> = Promise<{ data?: T; error?: unknown; response: Response }>

export type Students = Get<'/v1/programmes/{division}/students'>
export type StudentRow = Students['students'][number]
export type Desk = Get<'/v1/programmes/{division}/registration'>
export type DeskRow = Desk['rows'][number]
export type RegistrationView = Get<'/v1/programmes/{division}/registrations/{id}'>
export type StudentRecord = Get<'/v1/programmes/learners/{id}'>

export type RegState = 'draft' | 'submitted' | 'approved' | 'registered' | 'withdrawn'
export const REG_STATES: RegState[] = ['draft', 'submitted', 'approved', 'registered', 'withdrawn']
export type RegAction = 'submit' | 'return' | 'allow-fees' | 'change' | 'withdraw'

export interface WindowInput {
  opens_on: string
  closes_on: string
  late_until: string
  add_drop_until: string
  late_fee: number
  fees_share: number
}

const withPeriod = (period: string | null) => (period === null ? {} : { period }) as never

/** Semester registration (D-098). */
export const registrationApi = {
  students: (division: string, period: string | null) => read(api.GET('/v1/programmes/{division}/students', { params: { path: { division }, query: withPeriod(period) } })),
  desk: (division: string, period: string | null) => read(api.GET('/v1/programmes/{division}/registration', { params: { path: { division }, query: withPeriod(period) } })),
  registration: (division: string, id: string) => read(api.GET('/v1/programmes/{division}/registrations/{id}', { params: { path: { division, id } } })),
  learner: (id: string) => read(api.GET('/v1/programmes/learners/{id}', { params: { path: { id } } })),

  place: (division: string, body: { learner_id: string; programme_id: string; entry_year: number; mode: string }) =>
    settle(api.POST('/v1/programmes/{division}/students', { params: { path: { division } }, body: body as never }) as Done<{ id: string }>),
  status: (division: string, id: string, body: { status: string; reason: string }) =>
    settle(api.POST('/v1/programmes/{division}/students/{id}/status', { params: { path: { division, id } }, body: body as never }) as Done<{ id: string }>),
  saveWindow: (division: string, period: string, body: WindowInput) =>
    settle(api.PUT('/v1/programmes/{division}/registration/{period}/window', { params: { path: { division, period } }, body: body as never }) as Done<{ saved: boolean }>),
  start: (division: string, period: string) =>
    settle(
      api.POST('/v1/programmes/{division}/registration/{period}/start', { params: { path: { division, period } } }) as Done<{ opened: number; sent: number; completed: number }>,
    ),
  open: (division: string, period: string, learnerId: string) =>
    settle(
      api.POST('/v1/programmes/{division}/registration/{period}/open', { params: { path: { division, period } }, body: { learner_id: learnerId } as never }) as Done<{
        id: string
      }>,
    ),
  approve: (division: string, period: string, ids: string[]) =>
    settle(
      api.POST('/v1/programmes/{division}/registration/{period}/approve', { params: { path: { division, period } }, body: { ids } as never }) as Done<{
        approved: number
        registered: number
      }>,
    ),
  register: (division: string, period: string, ids: string[] | null) =>
    settle(
      api.POST('/v1/programmes/{division}/registration/{period}/register', {
        params: { path: { division, period } },
        body: (ids === null ? {} : { ids }) as never,
      }) as Done<{ registered: number }>,
    ),
  saveCourses: (division: string, id: string, offerings: string[]) =>
    settle(api.PUT('/v1/programmes/{division}/registrations/{id}/courses', { params: { path: { division, id } }, body: { offerings } as never }) as Done<{ saved: boolean }>),
  act: (division: string, id: string, action: RegAction, reason: string | null) =>
    settle(
      api.POST('/v1/programmes/{division}/registrations/{id}/{action}', {
        params: { path: { division, id, action } as never },
        body: (reason === null ? {} : { reason }) as never,
      }) as Done<{ done: boolean }>,
    ),
}

const API: string = import.meta.env.VITE_API_URL ?? '/api'

/** The address of the slip PDF, opened in a new tab. */
export const slipHref = (division: string, id: string) => `${API}/v1/programmes/${division}/registrations/${id}/slip`
