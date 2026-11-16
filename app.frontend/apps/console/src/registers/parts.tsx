import { navigate } from '@acadly/kernel'
import { Button } from '@acadly/ui'
import { useState, type ReactNode } from 'react'
import { copy } from '../copy'
import { errorFor, type Saved } from '../setup/api'
import { Input, Select } from '../setup/parts'
import { PageHead } from '../settings/parts'
import { clock, longDay, markLook, MARKS, type MarkValue, type RegisterLearner } from './api'

const t = copy.registers
const s = copy.settings

/** A mark drawn in its cell: a glyph on its colour, never colour alone. */
export function Glyph({ mark, size = 'size-9 text-[17px]' }: { mark: string | null; size?: string }) {
  const look = mark !== null && mark in markLook ? markLook[mark as MarkValue] : markLook.none

  return (
    <span role="img" aria-label={mark !== null ? (t.marks[mark] ?? mark) : t.notMarked} className={`ref inline-flex shrink-0 items-center justify-center font-extrabold ${size} ${look}`}>
      {mark !== null ? t.glyph[mark] : ''}
    </span>
  )
}

type Correction = NonNullable<RegisterLearner['correction']>

/** A learner on a submitted register, morning or lesson: what a correction needs. */
export interface Correctable {
  id: string
  name: string
  mark: { status: string } | null
  correction: Correction | null
}

/** A save on a submitted register: what failed, the field errors, and whether it runs. */
export function useSave<T>() {
  const [failed, setFailed] = useState<string | null>(null)
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [working, setWorking] = useState(false)

  const run = async (request: Promise<Saved<T>>, onDone: (next: T) => void) => {
    setWorking(true)
    setFailed(null)
    const result = await request
    setWorking(false)

    if (result.kind === 'saved') onDone(result.data)
    else if (result.kind === 'invalid') {
      setErrors(result.errors)
      if (Object.keys(result.errors).length === 0) setFailed(t.changed)
    } else setFailed(result.kind === 'refused' ? t.otherPerson : s.failed)
  }

  return { failed, errors, working, run }
}

/** Asking for a correction of one mark: the new mark and why. */
export function AskForm<T>({
  learner,
  send,
  onDone,
  onCancel,
}: {
  learner: Correctable
  send: (body: { learner_id: string; to: MarkValue; reason: string }) => Promise<Saved<T>>
  onDone: (next: T) => void
  onCancel: () => void
}) {
  const current = learner.mark?.status ?? ''
  const options = MARKS.filter((m) => m !== current)
  const [to, setTo] = useState<MarkValue>(options[0] ?? 'present')
  const [reason, setReason] = useState('')
  const save = useSave<T>()

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault()
        void save.run(send({ learner_id: learner.id, to, reason }), onDone)
      }}
      className="mt-2.5 mb-1 grid gap-3 border-2 border-ink bg-sheet p-3.5 md:ml-[60px] md:grid-cols-[160px_1fr_auto] md:items-end"
    >
      <Select label={t.newMark} value={to} onChange={(e) => setTo(e.target.value as MarkValue)} error={errorFor(save.errors, 'to')}>
        {options.map((m) => (
          <option key={m} value={m}>
            {t.marks[m]}
          </option>
        ))}
      </Select>
      <Input label={t.why} required value={reason} onChange={(e) => setReason(e.target.value)} error={errorFor(save.errors, 'reason') ?? errorFor(save.errors, 'learner_id')} maxLength={255} />
      <div className="flex gap-2">
        <Button type="submit" busy={save.working}>
          {save.working ? s.saving : t.sendRequest}
        </Button>
        <Button variant="secondary" onClick={onCancel}>
          {s.cancel}
        </Button>
      </div>
      {save.failed !== null && <p className="text-[13px] font-bold text-red-text md:col-span-3">{save.failed}</p>}
    </form>
  )
}

/** A correction someone else asked for, to approve or decline. */
export function DecideCard<T>({
  learner,
  className,
  day,
  decide: send,
  onDone,
}: {
  learner: Correctable
  className: string
  day: string
  decide: (correctionId: string, approve: boolean, note: string) => Promise<Saved<T>>
  onDone: (next: T, approve: boolean) => void
}) {
  const c = learner.correction
  const [note, setNote] = useState('')
  const save = useSave<T>()

  if (c === null) return null

  const decide = (approve: boolean) => void save.run(send(c.id, approve, note), (next) => onDone(next, approve))

  return (
    <div className="border-2 border-ink bg-sheet px-4 pt-3.5 pb-4">
      <div className="label-caps text-text-muted">{t.approveTitle}</div>
      <div className="type-display mt-1 mb-2.5 text-[22px] leading-none">
        {learner.name} · {className}
      </div>
      <dl className="mb-3 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-sm">
        <dt className="text-text-muted">{t.colRegister}</dt>
        <dd className="font-bold">{longDay(day)}</dd>
        <dt className="text-text-muted">{t.from}</dt>
        <dd className="flex items-center gap-2">
          <Glyph mark={c.from} size="size-6 text-sm" /> {t.marks[c.from]}
        </dd>
        <dt className="text-text-muted">{t.to}</dt>
        <dd className="flex items-center gap-2">
          <Glyph mark={c.to} size="size-6 text-sm" /> {t.marks[c.to]}
        </dd>
        <dt className="text-text-muted">{t.colReason}</dt>
        <dd>{c.reason}</dd>
        <dt className="text-text-muted">{t.askedBy}</dt>
        <dd>
          {c.requested_by}, {clock(c.requested_at)}
        </dd>
      </dl>
      <Input label={t.note} hint={t.noteHint} value={note} onChange={(e) => setNote(e.target.value)} error={errorFor(save.errors, 'reason')} maxLength={255} />
      {save.failed !== null && <p className="mt-2 text-[13px] font-bold text-red-text">{save.failed}</p>}
      <div className="mt-3 flex flex-wrap gap-2">
        <Button busy={save.working} onClick={() => decide(true)}>
          {t.approve}
        </Button>
        <button
          type="button"
          disabled={save.working}
          onClick={() => decide(false)}
          className="h-11 border-2 border-red-pen bg-sheet px-[18px] text-[15px] font-extrabold text-red-text hover:bg-red-wash disabled:opacity-60"
        >
          {t.decline}
        </button>
      </div>
      <p className="mt-3 text-[13px] text-text-muted">{t.approveNote}</p>
    </div>
  )
}

type RegistersTab = keyof typeof t.tabs

const tabPaths: Record<RegistersTab, string> = { morning: '/registers', lessons: '/registers/lessons', missed: '/registers/missed' }

/** The head of the Registers pages: the day, and Morning | Lessons | Missed lessons where a level takes lesson registers. */
export function RegistersHead({
  kicker,
  intro,
  day,
  today,
  current,
  tabs,
}: {
  kicker: ReactNode
  intro: ReactNode
  day: string
  today: string
  current: RegistersTab
  tabs: boolean
}) {
  const at = (path: string, next: string) => (next === today ? path : `${path}?day=${next}`)

  return (
    <>
      <PageHead
        kicker={kicker}
        title={t.title}
        intro={intro}
        actions={<DayPicker day={day} today={today} onDay={(next) => navigate(at(tabPaths[current], next))} />}
      />
      {tabs && (
        <nav aria-label={t.tabsLabel} className="flex gap-1 overflow-x-auto border-b-2 border-ink bg-sheet px-3 [scrollbar-width:none] md:px-[clamp(16px,3vw,40px)]">
          {(Object.keys(tabPaths) as RegistersTab[]).map((tab) => {
            const href = at(tabPaths[tab], day)

            return (
              <a
                key={tab}
                href={href}
                aria-current={tab === current ? 'page' : undefined}
                onClick={(event) => {
                  event.preventDefault()
                  navigate(href)
                }}
                className={`shrink-0 border-b-4 px-4 pt-2.5 pb-2 text-sm whitespace-nowrap text-ink no-underline ${tab === current ? 'border-teal font-extrabold' : 'border-transparent font-medium'}`}
              >
                {t.tabs[tab]}
              </a>
            )
          })}
        </nav>
      )}
    </>
  )
}

/** The day a page shows, and a way back to today. */
export function DayPicker({ day, today, onDay }: { day: string; today: string; onDay: (next: string) => void }) {
  return (
    <div className="flex flex-wrap items-end gap-2">
      <label className="block">
        <span className="label-caps mb-1.5 block text-text-muted">{t.day}</span>
        <input
          type="date"
          value={day}
          max={today}
          onChange={(e) => e.target.value !== '' && onDay(e.target.value)}
          className="h-11 border-0 border-b-2 border-ink bg-field px-3 text-[15px] text-ink outline-none focus:border-teal"
        />
      </label>
      {day !== today && (
        <button type="button" onClick={() => onDay(today)} className="h-11 border-2 border-ink px-4 text-[15px] font-extrabold hover:bg-field">
          {t.today}
        </button>
      )}
    </div>
  )
}
