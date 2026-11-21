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

export type Rooms = Get<'/v1/lectures/{division}/rooms'>
export type Timetable = Get<'/v1/lectures/{division}/timetable'>
export type Slot = Timetable['slots'][number]
export type Check = Timetable['checks'][number]
export type Cover = Get<'/v1/lectures/{division}/cover'>
export type LectureRow = Cover['rows'][number]
export type MyDay = Get<'/v1/lectures/{division}/my-day'>
export type LectureView = Get<'/v1/lectures/{division}/slots/{id}'>
export type Screen = Get<'/v1/lectures/{division}/sessions/{id}/code'>
export type LectureTab = Get<'/v1/lectures/learners/{learner}'>

export type LecturePermission = 'lectures.view' | 'lectures.timetable' | 'lectures.cover' | 'lectures.teach' | 'lectures.phones'

export interface SlotInput {
  id: string | null
  offering_id: string
  kind: string
  weekday: number
  starts: string
  ends: string
  room_id: string
  lecturer_id: string
}

const p = (division: string) => ({ params: { path: { division } } })
const pi = (division: string, id: string) => ({ params: { path: { division, id } } })

/** Lectures (D-102): rooms, the timetable, cover, the lecturer's day, attendance and the room screen. */
export const lecturesApi = {
  rooms: (division: string) => read(api.GET('/v1/lectures/{division}/rooms', p(division))),
  saveRoom: (division: string, body: { id: string | null; code: string; name: string; seats: number; kind: string; retired: boolean }) =>
    settle(api.POST('/v1/lectures/{division}/rooms', { ...p(division), body })),
  saveAreas: (division: string, body: { areas: { name: string; latitude: number; longitude: number; reach: number }[]; scan_minutes: number }) =>
    settle(api.PUT('/v1/lectures/{division}/areas', { ...p(division), body })),
  timetable: (division: string, period: string | null) =>
    read(api.GET('/v1/lectures/{division}/timetable', { params: { path: { division }, query: (period === null ? {} : { period }) as never } })),
  saveSlot: (division: string, period: string, body: SlotInput) => settle(api.POST('/v1/lectures/{division}/timetable/{period}/slots', { params: { path: { division, period } }, body })),
  removeSlot: (division: string, id: string) => settle(api.POST('/v1/lectures/{division}/slots/{id}/remove', pi(division, id))),
  publish: async (division: string, period: string) => {
    const { data } = await api.POST('/v1/lectures/{division}/timetable/{period}/publish', { params: { path: { division, period } } })

    return data ?? null
  },
  cover: (division: string, week: string | null) => read(api.GET('/v1/lectures/{division}/cover', { params: { path: { division }, query: (week === null ? {} : { week }) as never } })),
  free: (division: string, id: string, day: string) => read(api.GET('/v1/lectures/{division}/slots/{id}/free', { params: { path: { division, id }, query: { day } } })),
  act: (
    division: string,
    id: string,
    action: 'away' | 'ask' | 'accept' | 'decline' | 'name' | 'cancel' | 'move',
    body: { day: string; user_id?: string; reason?: string; to_day?: string; to_starts?: string; to_ends?: string; to_room?: string },
  ) => settle(api.POST('/v1/lectures/{division}/slots/{id}/{action}', { params: { path: { division, id, action } }, body: body as never })),
  myDay: (division: string) => read(api.GET('/v1/lectures/{division}/my-day', p(division))),
  lecture: (division: string, id: string, day: string) => read(api.GET('/v1/lectures/{division}/slots/{id}', { params: { path: { division, id }, query: { day } } })),
  open: (division: string, id: string, day: string) => settle(api.POST('/v1/lectures/{division}/slots/{id}/open', { ...pi(division, id), body: { day } })),
  close: (division: string, session: string) => settle(api.POST('/v1/lectures/{division}/sessions/{id}/close', pi(division, session))),
  mark: (division: string, session: string, body: { learner_id: string; mark: string; reason: string }) =>
    settle(api.POST('/v1/lectures/{division}/sessions/{id}/mark', { ...pi(division, session), body })),
  code: (division: string, session: string) => read(api.GET('/v1/lectures/{division}/sessions/{id}/code', pi(division, session))),
  learner: (learner: string) => read(api.GET('/v1/lectures/learners/{learner}', { params: { path: { learner } } })),
  resetPhone: (learner: string, reason: string) => settle(api.POST('/v1/lectures/learners/{learner}/phone/reset', { params: { path: { learner } }, body: { reason } })),
}

/** The college divisions where the account holds one of the permissions, with the module on. */
export function lectureDivisions(context: AppContext, permissions: LecturePermission[]): Division[] {
  if (!moduleOn(context, 'lectures')) return []

  return context.divisions.filter((d) => byProgrammes(d) && permissions.some((x) => can(context, x, { type: 'division', id: d.id })))
}

/** The division a Lectures page works in: the one worked in when it qualifies, else the first that does. */
export function useLectureDivision(permissions: LecturePermission[]): { division: Division | null; choices: Division[]; choose: (id: string) => void } {
  const context = useAppContext()
  const { division: lens } = useWorkIn()
  const choices = lectureDivisions(context, permissions)
  const [picked, setPicked] = useState<string | null>(null)
  const division = choices.find((d) => d.id === picked) ?? choices.find((d) => d.id === lens?.id) ?? choices[0] ?? null

  return { division, choices, choose: setPicked }
}

/** Today in the school's calendar, as YYYY-MM-DD. */
export const today = (): string => {
  const d = new Date()

  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

/** A day as staff read it: Thu 26 Nov. */
export const shortDay = (iso: string): string => new Date(`${iso}T12:00:00`).toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' })

/** A moment as a clock time: 10:20. */
export const clockOf = (iso: string): string => new Date(iso).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })

/** Moves a YYYY-MM-DD day by some days. */
export const addDays = (iso: string, days: number): string => {
  const d = new Date(`${iso}T12:00:00`)
  d.setDate(d.getDate() + days)

  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
