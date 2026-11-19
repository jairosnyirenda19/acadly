import { api, can, type AppContext, type paths } from '@acadly/kernel'
import { read, settle } from '../setup/api'
import type { Division } from '../shell/divisions'

type Get<P extends keyof paths> = paths[P] extends {
  get: { responses: { 200: { content: { 'application/json': infer T } } } }
}
  ? T
  : never
type Done<T> = Promise<{ data?: T; error?: unknown; response: Response }>

export type Structure = Get<'/v1/programmes/{division}/structure'>
export type Faculty = Structure['faculties'][number]
export type Department = Faculty['departments'][number]
export type ProgrammeRow = Structure['programmes'][number]
export type Catalogue = Get<'/v1/programmes/{division}/catalogue'>
export type Course = Catalogue['courses'][number]
export type ProgrammeView = Get<'/v1/programmes/{division}/programmes/{id}'>
export type Version = NonNullable<ProgrammeView['version']>
export type Offerings = Get<'/v1/programmes/{division}/offerings'>
export type Offering = Offerings['offerings'][number]
export type Problem = Version['problems'][number]

export type Award = 'certificate' | 'diploma' | 'bachelor' | 'postgraduate_diploma' | 'master' | 'doctorate'
export const AWARDS: Award[] = ['certificate', 'diploma', 'bachelor', 'postgraduate_diploma', 'master', 'doctorate']

export interface FacultyInput {
  id: string | null
  name: string
  code: string
  retired: boolean
}
export interface DepartmentInput {
  id: string | null
  faculty_id: string
  name: string
  code: string
  prefixes: string[]
  head_user_id: string | null
  retired: boolean
}
export interface CourseInput {
  id: string | null
  department_id: string
  code: string
  title: string
  credits: number
  lecture_hours: number
  tutorial_hours: number
  practical_hours: number
  needs: string[]
  retired: boolean
}
export interface ProgrammeInput {
  id: string | null
  department_id: string
  code: string
  name: string
  award: Award
  years: number
  semesters: number
  credits: number
  min_load: number
  max_load: number
  open: boolean
  retired: boolean
}
export interface DraftInput {
  groups: {
    key: string
    year: number
    semester: number
    label: string
    choose: number
  }[]
  courses: {
    course_id: string
    year: number
    semester: number
    group: string | null
  }[]
}

const at = (division: string) => ({ path: { division } })

export const programmesApi = {
  structure: (division: string) => read(api.GET('/v1/programmes/{division}/structure', { params: at(division) })),
  catalogue: (division: string) => read(api.GET('/v1/programmes/{division}/catalogue', { params: at(division) })),
  programme: (division: string, id: string, version: string | null) =>
    read(
      api.GET('/v1/programmes/{division}/programmes/{id}', {
        params: {
          path: { division, id },
          query: (version === null ? {} : { version }) as never,
        },
      }),
    ),
  offerings: (division: string, period: string | null) =>
    read(
      api.GET('/v1/programmes/{division}/offerings', {
        params: {
          path: { division },
          query: (period === null ? {} : { period }) as never,
        },
      }),
    ),

  saveFaculties: (division: string, faculties: FacultyInput[]) =>
    settle(
      api.PUT('/v1/programmes/{division}/faculties', {
        params: at(division),
        body: { faculties } as never,
      }) as Done<{ saved: boolean }>,
    ),
  saveDepartment: (division: string, body: DepartmentInput) =>
    settle(
      api.POST('/v1/programmes/{division}/departments', {
        params: at(division),
        body: body as never,
      }) as Done<{ id: string }>,
    ),
  saveCourse: (division: string, body: CourseInput) =>
    settle(
      api.POST('/v1/programmes/{division}/courses', {
        params: at(division),
        body: body as never,
      }) as Done<{ id: string }>,
    ),
  saveProgramme: (division: string, body: ProgrammeInput) =>
    settle(
      api.POST('/v1/programmes/{division}/programmes', {
        params: at(division),
        body: body as never,
      }) as Done<{ id: string }>,
    ),
  newVersion: (division: string, id: string) =>
    settle(
      api.POST('/v1/programmes/{division}/programmes/{id}/versions', {
        params: { path: { division, id } },
      }) as Done<{ id: string }>,
    ),
  saveDraft: (division: string, id: string, body: DraftInput) =>
    settle(
      api.PUT('/v1/programmes/{division}/versions/{id}', {
        params: { path: { division, id } },
        body: body as never,
      }) as Done<{ saved: boolean }>,
    ),
  release: (division: string, id: string) =>
    settle(
      api.POST('/v1/programmes/{division}/versions/{id}/release', {
        params: { path: { division, id } },
      }) as Done<{ released: boolean }>,
    ),
  prepare: (division: string, period: string) =>
    settle(
      api.POST('/v1/programmes/{division}/offerings/{period}/prepare', {
        params: { path: { division, period } },
      }) as Done<{ added: number }>,
    ),
  addGroup: (division: string, period: string, courseId: string) =>
    settle(
      api.POST('/v1/programmes/{division}/offerings/{period}/groups', {
        params: { path: { division, period } },
        body: { course_id: courseId } as never,
      }) as Done<{ id: string }>,
    ),
  saveOffering: (division: string, id: string, body: { limit: number | null; lecturers: string[]; assistants: string[] }) =>
    settle(
      api.PUT('/v1/programmes/{division}/offering/{id}', {
        params: { path: { division, id } },
        body: body as never,
      }) as Done<{ saved: boolean }>,
    ),
}

/** Whether a division's profile sets its study by programmes (the binding, D-097). */
export const byProgrammes = (d: Division): boolean => (d.bindings as Record<string, string | undefined>)['programmes.shape'] === 'programmes'

/** The divisions where the account may see programmes. */
export function programmeDivisions(context: AppContext): Division[] {
  return context.divisions.filter((d) => byProgrammes(d) && can(context, 'programmes.view', { type: 'division', id: d.id }))
}

/** The first error of a refused save, or the fallback. */
export function firstError(result: { kind: string; errors?: Record<string, string[]> }, fallback: string): string {
  return result.kind === 'invalid' && result.errors !== undefined ? (Object.values(result.errors)[0]?.[0] ?? fallback) : fallback
}
