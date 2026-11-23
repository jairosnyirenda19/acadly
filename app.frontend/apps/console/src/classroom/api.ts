import { api, can, moduleOn, useAppContext, type AppContext, type paths } from '@acadly/kernel'
import { useState } from 'react'
import { read, settle } from '../setup/api'
import { byProgrammes } from '../programmes/api'
import type { Division } from '../shell/divisions'
import { useWorkIn } from '../shell/WorkIn'

type Get<P extends keyof paths> = paths[P] extends {
  get: { responses: { 200: { content: { 'application/json': infer T } } } }
}
  ? T
  : never
type Done<T> = Promise<{ data?: T; error?: unknown; response: Response }>

export type Courses = Get<'/v1/classroom/{division}/courses'>
export type CoursePage = Get<'/v1/classroom/{division}/courses/{id}'>
export type TaskRow = CoursePage['tasks'][number]
export type HandIns = Get<'/v1/classroom/{division}/tasks/{id}'>
export type Work = Get<'/v1/classroom/{division}/tasks/{id}/work/{learner}'>
export type Assessment = Get<'/v1/classroom/{division}/courses/{id}/assessment'>
export type Coursework = Get<'/v1/classroom/learners/{learner}'>
export type Pages = Get<'/v1/documents/{id}/pages'>

export interface TaskInput {
  id: string | null
  kind: 'assignment' | 'exam'
  title: string
  instructions: string
  shows_at: string
  due_at: string
  late_until: string | null
  out_of: number
  weight: number
  accepts: string[]
  max_files: number
  max_mb: number
}

const asForm = (body: unknown) => body as FormData
const pi = (division: string, id: string) => ({ params: { path: { division, id } } })

/** Classroom (D-104): course pages, assignments, hand-ins, marking and continuous assessment. */
export const classroomApi = {
  courses: (division: string) => read(api.GET('/v1/classroom/{division}/courses', { params: { path: { division } } })),
  course: (division: string, id: string) => read(api.GET('/v1/classroom/{division}/courses/{id}', pi(division, id))),
  addResource: (division: string, id: string, input: { section: string; title: string; url: string; shows_from: string; pinned: boolean; file: File | null }) => {
    const form = new FormData()
    form.append('section', input.section)
    form.append('title', input.title)
    if (input.file !== null) form.append('file', input.file)
    else form.append('url', input.url)
    if (input.shows_from !== '') form.append('shows_from', input.shows_from)
    form.append('pinned', input.pinned ? '1' : '0')

    return settle(api.POST('/v1/classroom/{division}/courses/{id}/resources', { ...pi(division, id), body: form as never, bodySerializer: asForm }) as Done<{ id: string }>)
  },
  removeResource: (division: string, id: string) => settle(api.POST('/v1/classroom/{division}/resources/{id}/remove', pi(division, id))),
  announce: (division: string, id: string, body: { title: string; body: string; sms: boolean }) =>
    settle(api.POST('/v1/classroom/{division}/courses/{id}/announcements', { ...pi(division, id), body })),
  share: (division: string, id: string, share: number) => settle(api.PUT('/v1/classroom/{division}/courses/{id}/share', { ...pi(division, id), body: { ca_share: share } })),
  saveTask: (division: string, course: string, input: TaskInput) =>
    settle(api.POST('/v1/classroom/{division}/courses/{id}/tasks', { ...pi(division, course), body: input as never }) as Done<{ id: string }>),
  addTaskFile: (division: string, task: string, file: File) => {
    const form = new FormData()
    form.append('file', file)

    return settle(api.POST('/v1/classroom/{division}/tasks/{id}/files', { ...pi(division, task), body: form as never, bodySerializer: asForm }) as Done<{ id: string }>)
  },
  task: (division: string, id: string) => read(api.GET('/v1/classroom/{division}/tasks/{id}', pi(division, id))),
  work: (division: string, id: string, learner: string) => read(api.GET('/v1/classroom/{division}/tasks/{id}/work/{learner}', { params: { path: { division, id, learner } } })),
  mark: (division: string, task: string, input: { learner_id: string; mark: string; feedback: string; reason: string; file: File | null }) => {
    const form = new FormData()
    form.append('learner_id', input.learner_id)
    form.append('mark', input.mark)
    if (input.feedback !== '') form.append('feedback', input.feedback)
    if (input.reason !== '') form.append('reason', input.reason)
    if (input.file !== null) form.append('file', input.file)

    return settle(api.POST('/v1/classroom/{division}/tasks/{id}/marks', { ...pi(division, task), body: form as never, bodySerializer: asForm }) as Done<{ id: string }>)
  },
  release: (division: string, task: string) => settle(api.POST('/v1/classroom/{division}/tasks/{id}/release', pi(division, task))),
  assessment: (division: string, id: string) => read(api.GET('/v1/classroom/{division}/courses/{id}/assessment', pi(division, id))),
  learner: (learner: string) => read(api.GET('/v1/classroom/learners/{learner}', { params: { path: { learner } } })),
  pages: (id: string) => read(api.GET('/v1/documents/{id}/pages', { params: { path: { id } } })),
}

/** The college divisions where the account may open Classroom, with the module on. */
export function classroomDivisions(context: AppContext): Division[] {
  if (!moduleOn(context, 'classroom')) return []

  return context.divisions.filter((d) => byProgrammes(d) && (can(context, 'classroom.view', { type: 'division', id: d.id }) || can(context, 'classroom.teach', { type: 'division', id: d.id })))
}

/** The division a Classroom page works in: the one worked in when it qualifies, else the first that does. */
export function useClassroomDivision(): { division: Division | null; choices: Division[]; choose: (id: string) => void } {
  const context = useAppContext()
  const { division: lens } = useWorkIn()
  const choices = classroomDivisions(context)
  const [picked, setPicked] = useState<string | null>(null)
  const division = choices.find((d) => d.id === picked) ?? choices.find((d) => d.id === lens?.id) ?? choices[0] ?? null

  return { division, choices, choose: setPicked }
}

/** A moment as staff read it: Sat 21 Nov, 23:59. */
export const when = (iso: string): string =>
  new Date(iso).toLocaleString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }).replace(' at ', ', ')

/** A moment as a form field reads it: 2026-11-21T23:59. */
export const local = (iso: string | null): string => {
  if (iso === null) return ''
  const d = new Date(iso)
  const p = (n: number) => String(n).padStart(2, '0')

  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`
}

/** A file size as people read it: 1.2 MB. */
export const size = (bytes: number): string => (bytes >= 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`)

/** Where a file opens: the download address the console reads. */
export const fileUrl = (id: string): string => `/api/v1/documents/${id}/content`
