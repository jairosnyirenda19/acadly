import { api, type paths } from '@acadly/kernel'
import { gridApiQuery, type GridQuery } from '@acadly/ui'
import { copy } from '../copy'
import { read, settle } from '../setup/api'

type Json<P extends keyof paths, M extends 'get' | 'post' | 'put'> = paths[P][M] extends { responses: { 200: { content: { 'application/json': infer T } } } } ? T : never
const API: string = import.meta.env.VITE_API_URL ?? '/api'

type Done<T> = Promise<{ data?: T; error?: unknown; response: Response }>

export type MineState = Json<'/v1/assessment/mine', 'get'>
export type MineRow = MineState['assessments'][number]
export type OptionsState = Json<'/v1/assessment/options', 'get'>
export type Choice = OptionsState['choices'][number]
export type SheetState = Json<'/v1/assessment/assessments/{id}', 'get'>
export type SheetLearner = SheetState['learners'][number]
export type MarkState = SheetState['assessment']['state']
export type AssessGrid = Json<'/v1/assessment/grid', 'get'>
export type AssessRow = AssessGrid['rows'][number]
export type ResultsState = Json<'/v1/assessment/results', 'get'>
export type WorkingState = Json<'/v1/assessment/results/working', 'get'>
export type LearnerResultsState = Json<'/v1/assessment/learner/{id}', 'get'>
export type ScalesState = Json<'/v1/assessment/scales', 'get'>
export type Scale = ScalesState['scales'][number]
export type Band = Scale['bands'][number]
export type Overall = Scale['overall']
export type PlansState = Json<'/v1/assessment/plans', 'get'>
export type Plan = PlansState['plan']
export type PlanPart = Plan['parts'][number]
export type PublishingState = Json<'/v1/assessment/publishing', 'get'>
export type PublishRow = PublishingState['classes'][number]
export type CommentsState = Json<'/v1/assessment/comments', 'get'>
export type StepWordsState = Json<'/v1/assessment/step-words', 'get'>
export type StepWordSet = StepWordsState['en']
export type CommentRow = CommentsState['learners'][number]
export type CardsState = Json<'/v1/assessment/cards/{id}', 'get'>
export type Card = CardsState['cards'][number]

/** A mark as the sheet sends it: a value, or no value and a reason. */
export interface MarkInput {
  learner_id: string
  value: number | null
  reason: 'absent' | 'excused' | 'exempt' | null
}

export const assessmentApi = {
  mine: () => read(api.GET('/v1/assessment/mine')),
  options: () => read(api.GET('/v1/assessment/options')),
  grid: (query: GridQuery) => read(api.GET('/v1/assessment/grid', { params: { query: gridApiQuery(query) as never } })),

  set: (body: { class_id: string; subject_id: string; kind: string; title: string; held_on: string; out_of: number; counts: boolean }) =>
    settle(api.POST('/v1/assessment/assessments', { body: body as never }) as Done<SheetState>),

  sheet: (id: string) => read(api.GET('/v1/assessment/assessments/{id}', { params: { path: { id } } })),
  /** Saves the changed marks; answers how many rows were added and the sheet as it is now. */
  save: (id: string, marks: MarkInput[]) => settle(api.PUT('/v1/assessment/assessments/{id}/marks', { params: { path: { id } }, body: { marks } as never }) as Done<{ saved: number; sheet: SheetState }>),
  submit: (id: string) => settle(api.POST('/v1/assessment/assessments/{id}/submit', { params: { path: { id } } }) as Done<SheetState>),
  lock: (id: string) => settle(api.POST('/v1/assessment/assessments/{id}/lock', { params: { path: { id } } }) as Done<SheetState>),
  giveBack: (id: string, reason: string) => settle(api.POST('/v1/assessment/assessments/{id}/return', { params: { path: { id } }, body: { reason } as never }) as Done<SheetState>),
  correct: (id: string, body: { learner_id: string; value: number | null; reason: string | null; note: string }) =>
    settle(api.POST('/v1/assessment/assessments/{id}/correct', { params: { path: { id } }, body: body as never }) as Done<SheetState>),

  /** Adds one page of a learner's marked paper: a photo or a PDF. */
  addPaper: (id: string, learnerId: string, file: File) => {
    const form = new FormData()
    form.append('learner_id', learnerId)
    form.append('file', file)

    return settle(api.POST('/v1/assessment/assessments/{id}/papers', { params: { path: { id } }, body: form as never, bodySerializer: (body: unknown) => body as FormData }) as Done<SheetState>)
  },
  movePaper: (pageId: string, earlier: boolean) => settle(api.POST('/v1/assessment/papers/{id}/move', { params: { path: { id: pageId } }, body: { earlier } as never }) as Done<SheetState>),
  removePaper: (pageId: string) => settle(api.DELETE('/v1/assessment/papers/{id}', { params: { path: { id: pageId } } }) as Done<SheetState>),

  results: (classId: string, periodId: string | null) =>
    read(api.GET('/v1/assessment/results', { params: { query: { class_id: classId, ...(periodId !== null ? { period_id: periodId } : {}) } as never } })),
  working: (q: { class_id: string; learner_id: string; subject_id: string; period_id: string }) => read(api.GET('/v1/assessment/results/working', { params: { query: q as never } })),
  learner: (id: string) => read(api.GET('/v1/assessment/learner/{id}', { params: { path: { id } } })),

  publishing: () => read(api.GET('/v1/assessment/publishing')),
  publish: (classIds: string[]) => settle(api.POST('/v1/assessment/publishing', { body: { class_ids: classIds } as never }) as Done<PublishingState & { published: { class_id: string; publication_id: string | null; version: number | null; asked: boolean }[] }>),
  cardSettings: (body: { show_position: boolean; head_comment: boolean }) => settle(api.PUT('/v1/assessment/publishing/settings', { body: body as never }) as Done<PublishingState>),
  comments: (classId: string) => read(api.GET('/v1/assessment/comments', { params: { query: { class_id: classId } as never } })),
  comment: (body: { class_id: string; learner_id: string; author: 'class_teacher' | 'head'; text: string }) =>
    settle(api.PUT('/v1/assessment/comments', { body: body as never }) as Done<CommentsState>),
  cards: (publicationId: string, learnerId: string | null) =>
    read(api.GET('/v1/assessment/cards/{id}', { params: { path: { id: publicationId }, query: (learnerId !== null ? { learner_id: learnerId } : {}) as never } })),
  /** The published cards as one PDF with their check codes (D-107): the class, or one learner. Opened in a new tab. */
  cardsPdf: (publicationId: string, learnerId: string | null = null) =>
    `${API}/v1/assessment/cards/${publicationId}/pdf${learnerId !== null ? `?learner_id=${learnerId}` : ''}`,
  /** The school's words for the review steps (D-109); refused without assessment.plan. */
  stepWords: () => read(api.GET('/v1/assessment/step-words')),
  saveStepWords: (body: { en: StepWordSet; ny: StepWordSet }) => settle(api.PUT('/v1/assessment/step-words', { body: body as never }) as Done<{ saved: boolean }>),

  scales: () => read(api.GET('/v1/assessment/scales')),
  addScale: (body: { name: string; overall: Overall; pass_from: string | null; track: string; bands: Band[] }) =>
    settle(api.PUT('/v1/assessment/scales', { body: body as never }) as Done<ScalesState>),
  saveScale: (id: string, body: { name: string; overall: Overall; pass_from: string | null; track: string; bands: Band[]; retired: boolean }) =>
    settle(api.PUT('/v1/assessment/scales/{id}', { params: { path: { id } }, body: body as never }) as Done<ScalesState>),
  levelScale: (body: { division_id: string; level_code: string; scale_id: string }) => settle(api.PUT('/v1/assessment/level-scales', { body: body as never }) as Done<ScalesState>),

  plans: (q: { division_id: string; level_code: string; period_id: string; subject_id: string | null }) =>
    read(api.GET('/v1/assessment/plans', { params: { query: { division_id: q.division_id, level_code: q.level_code, period_id: q.period_id, ...(q.subject_id !== null ? { subject_id: q.subject_id } : {}) } as never } })),
  savePlan: (body: { division_id: string; level_code: string; period_id: string; subject_id: string | null; mode: string; parts: PlanPart[]; reason: string }) =>
    settle(api.POST('/v1/assessment/plans', { body: body as never }) as Done<PlansState>),
}

/** The tone of a marks state: a word on a colour, never colour alone. */
export const stateTone: Record<MarkState, 'done' | 'attention' | 'wrong' | 'plain'> = { marking: 'plain', submitted: 'attention', returned: 'wrong', locked: 'done' }

/** A learner's overall result as people read it: 68.4%, 3.5 (average grade), 14 (best six). */
export const summaryText = (kind: Overall, value: number) =>
  kind === 'average_percent' ? copy.assessment.pct(value) : kind === 'average_grade' ? value.toFixed(1) : String(value)

/** A mark as people read it: 17, 12.5. */
export const markText = (value: number | null) => (value === null ? '' : String(Number.isInteger(value) ? value : Math.round(value * 100) / 100))

