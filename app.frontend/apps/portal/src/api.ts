import { api, getContext, type paths } from '@acadly/kernel'

type Json<P extends keyof paths, M extends 'get' | 'post'> = paths[P][M] extends { responses: { 200: { content: { 'application/json': infer T } } } } ? T : never

export type FamilyState = Json<'/v1/portal/family', 'get'>
export type Child = FamilyState['children'][number]
export type Proposed = FamilyState['to_confirm'][number]
export type AttendanceState = Json<'/v1/portal/children/{id}/attendance', 'get'>
export type CardState = Json<'/v1/portal/children/{id}/card', 'get'>
export type MessagesState = Json<'/v1/portal/children/{id}/messages', 'get'>
export type ReviewState = Json<'/v1/portal/children/{id}/review', 'get'>
export type PickupState = Json<'/v1/portal/children/{id}/pickup', 'get'>
export type FamilyFeesState = Json<'/v1/portal/fees', 'get'>
export type FeesState = Json<'/v1/portal/children/{id}/fees', 'get'>
export type FeeInstalment = FeesState['instalments'][number]
export type FeePayment = FeesState['payments'][number]
export type ConductState = Json<'/v1/portal/children/{id}/conduct', 'get'>
export type SupportState = Json<'/v1/portal/children/{id}/support', 'get'>
export type DocumentsState = Json<'/v1/portal/children/{id}/documents', 'get'>

/** Someone named to collect today: the code, and the phone it was sent to when the school sent it. */
export interface Collecting {
  id: string
  code: string
  sent_to: string | null
}

const API: string = import.meta.env.VITE_API_URL ?? '/api'

/** The data, 'refused' when the parent may not see it (or is signed out), or null when it could not be read. */
async function read<T>(request: Promise<{ data?: T; response: Response }>): Promise<T | 'refused' | null> {
  try {
    const { data, response } = await request

    if (data !== undefined) return data
    if ([401, 403, 404].includes(response.status)) return 'refused'

    return null
  } catch {
    return null
  }
}

export const portalApi = {
  family: () => read(api.GET('/v1/portal/family')),
  confirm: async (mine: string[], notMine: string[]): Promise<FamilyState | null> => {
    try {
      await api.GET('/v1/auth/csrf-cookie')
      const { data } = await api.POST('/v1/portal/family/confirm', { body: { mine, not_mine: notMine } as never })

      return data ?? null
    } catch {
      return null
    }
  },
  attendance: (id: string) => read(api.GET('/v1/portal/children/{id}/attendance', { params: { path: { id } } })),
  /** Give the reason for a morning absence; the child's attendance as it now stands, or null when it was not saved. */
  giveReason: async (id: string, day: string, kind: string, note: string): Promise<AttendanceState | null> => {
    try {
      await api.GET('/v1/auth/csrf-cookie')
      const { data } = await api.POST('/v1/portal/children/{id}/absences/{day}/reason', { params: { path: { id, day } }, body: { kind, note } as never })

      return data ?? null
    } catch {
      return null
    }
  },
  card: (id: string) => read(api.GET('/v1/portal/children/{id}/card', { params: { path: { id } } })),
  messages: (id: string) => read(api.GET('/v1/portal/children/{id}/messages', { params: { path: { id } } })),
  review: (id: string) => read(api.GET('/v1/portal/children/{id}/review', { params: { path: { id } } })),
  pickup: (id: string) => read(api.GET('/v1/portal/children/{id}/pickup', { params: { path: { id } } })),
  /** The family's fees at a glance; 'refused' when the school shows no fees to families. */
  familyFees: () => read(api.GET('/v1/portal/fees')),
  fees: (id: string) => read(api.GET('/v1/portal/children/{id}/fees', { params: { path: { id } } })),
  /** The fee statement and a receipt as PDFs: the same documents the fees office gives. */
  statementPdf: (id: string) => `${API}/v1/portal/children/${id}/fees/statement`,
  receiptPdf: (id: string, receipt: string) => `${API}/v1/portal/children/${id}/receipts/${receipt}`,
  /** The child's newest report card and the shared progress review as PDFs, with their check codes (D-107). */
  cardPdf: (id: string) => `${API}/v1/portal/children/${id}/card/pdf`,
  reviewPdf: (id: string) => `${API}/v1/portal/children/${id}/review/pdf`,
  /** Conduct, support plans and documents the school shares with families (D-110); 'refused' when none is shown. */
  conduct: (id: string) => read(api.GET('/v1/portal/children/{id}/conduct', { params: { path: { id } } })),
  support: (id: string) => read(api.GET('/v1/portal/children/{id}/support', { params: { path: { id } } })),
  documents: (id: string) => read(api.GET('/v1/portal/children/{id}/documents', { params: { path: { id } } })),
  documentUrl: (id: string, document: string) => `${API}/v1/portal/children/${id}/documents/${document}`,
  /** The school's short name, as its text messages begin; null when it cannot be read. */
  shortName: async (): Promise<string | null> => (await getContext())?.school.short_name ?? null,
  /** Name someone to collect the child today; the code to give them, or null when it was not saved. */
  collectToday: async (id: string, name: string, relationship: string, phone: string, sendCode: boolean): Promise<Collecting | null> => {
    try {
      await api.GET('/v1/auth/csrf-cookie')
      const { data } = await api.POST('/v1/portal/children/{id}/collectors', { params: { path: { id } }, body: { name, relationship, phone, send_code: sendCode } as never })

      return (data as Collecting | undefined) ?? null
    } catch {
      return null
    }
  },
}

/**
 * A published report card as the parent reads it. The card is kept exactly
 * as it was published; every part is read with care, because an older card
 * may lack a part a newer one has.
 */
export interface PublishedCard {
  period: { name: string } | null
  learner: { name: string } | null
  position: { position: number; of: number; shared: boolean } | null
  lines: { label: string; value: string }[]
  subjects: { subject: string; percent: number | null; grade: string | null; word: string | null; ready: boolean }[]
  comments: { class_teacher: { text: string; by: string } | null; head: { text: string; by: string } | null }
}

const obj = (v: unknown): Record<string, unknown> | null => (typeof v === 'object' && v !== null && !Array.isArray(v) ? (v as Record<string, unknown>) : null)
const str = (v: unknown): string | null => (typeof v === 'string' ? v : null)
const num = (v: unknown): number | null => (typeof v === 'number' ? v : null)
const list = (v: unknown): unknown[] => (Array.isArray(v) ? v : [])
const said = (v: unknown) => {
  const o = obj(v)
  const text = str(o?.text)

  return o !== null && text !== null && text.trim() !== '' ? { text, by: str(o.by) ?? '' } : null
}

export function readCard(raw: Record<string, unknown>): PublishedCard {
  const period = obj(raw.period)
  const learner = obj(raw.learner)
  const position = obj(raw.position)
  const comments = obj(raw.comments)

  return {
    period: period !== null ? { name: str(period.name) ?? '' } : null,
    learner: learner !== null ? { name: str(learner.name) ?? '' } : null,
    position:
      position !== null && num(position.position) !== null ? { position: num(position.position) ?? 0, of: num(position.of) ?? 0, shared: position.shared === true } : null,
    lines: list(raw.lines).flatMap((l) => {
      const o = obj(l)
      const label = str(o?.label)
      const value = str(o?.value)

      return label !== null && value !== null ? [{ label, value }] : []
    }),
    subjects: list(raw.subjects).flatMap((s) => {
      const o = obj(s)
      const subject = str(o?.subject)

      return o !== null && subject !== null ? [{ subject, percent: num(o.percent), grade: str(o.grade), word: str(o.word), ready: o.ready !== false }] : []
    }),
    comments: { class_teacher: said(comments?.class_teacher), head: said(comments?.head) },
  }
}
