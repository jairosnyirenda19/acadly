import { api, type paths } from '@acadly/kernel'
import { read, settle } from '../setup/api'

export type SubjectsState = paths['/v1/academics/subjects']['get']['responses'][200]['content']['application/json']
export type TeachingState = paths['/v1/academics/teaching']['get']['responses'][200]['content']['application/json']
export type TimetableState = paths['/v1/academics/timetable']['get']['responses'][200]['content']['application/json']
export type BellPlan = TimetableState['plans'][number]
export type BellPeriod = BellPlan['periods'][number]
export type SubjectLevel =SubjectsState['levels'][number]
export type Subject = SubjectLevel['subjects'][number]

type Done<T> = Promise<{ data?: T; error?: unknown; response: Response }>

export interface SubjectBody {
  division_id: string
  name: string
  short: string
  core: boolean
  levels: string[]
}

export const academicsApi = {
  subjects: () => read(api.GET('/v1/academics/subjects')),
  start: (divisionId: string) => settle(api.POST('/v1/academics/subjects/start', { body: { division_id: divisionId } as never }) as Done<SubjectsState>),
  addSubject: (body: SubjectBody) => settle(api.POST('/v1/academics/subjects', { body: body as never }) as Done<SubjectsState>),
  saveSubject: (id: string, body: SubjectBody) => settle(api.PUT('/v1/academics/subjects/{id}', { params: { path: { id } }, body: body as never }) as Done<SubjectsState>),
  retire: (id: string, reason: string) => settle(api.POST('/v1/academics/subjects/{id}/retire', { params: { path: { id } }, body: { reason } as never }) as Done<SubjectsState>),

  teaching: (tab: { division_id: string; campus_id: string } | null) =>
    read(api.GET('/v1/academics/teaching', { params: { query: tab ?? {} } })),
  give: (body: { class_id: string; subject_id: string | null; user_id: string | null }) =>
    settle(api.PUT('/v1/academics/teaching', { body: body as never }) as Done<TeachingState>),
  copy: (tab: { division_id: string; campus_id: string }) => settle(api.POST('/v1/academics/teaching/copy', { body: tab as never }) as Done<TeachingState>),

  timetable: (at: { division_id: string; campus_id: string; class_id?: string } | null) => read(api.GET('/v1/academics/timetable', { params: { query: at ?? {} } })),
  saveBells: (body: { division_id: string; campus_id: string; plans: BellPlan[] }) =>
    settle(api.PUT('/v1/academics/timetable/bells', { body: body as never }) as Done<TimetableState>),
  setPeriod: (body: { class_id: string; weekday: number; period: number; subject_id: string | null; user_id: string | null }) =>
    settle(api.PUT('/v1/academics/timetable/period', { body: body as never }) as Done<TimetableState>),
}
