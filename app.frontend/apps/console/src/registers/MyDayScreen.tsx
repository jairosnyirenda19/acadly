import { navigate, useAppContext, useLoad, useLocation } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useState, type ReactNode } from 'react'
import { copy } from '../copy'
import { Attention, Input } from '../setup/parts'
import { Tag } from '../settings/parts'
import { clock, lessonsApi, longDay, type MyDayState, type MyLesson } from './api'

const t = copy.registers
const d = copy.registers.myDay
const s = copy.settings

type Tone = 'done' | 'attention' | 'wrong' | 'plain'

/** The tag of a lesson in the viewer's day: a word on a colour, never colour alone. */
function lessonTag(l: MyLesson): { tone: Tone; word: string } | null {
  if (l.register !== null) return { tone: 'done', word: t.lessonState.taken ?? '' }
  if (!l.mine) return { tone: 'attention', word: t.lessonState.cover ?? '' }
  const tones: Record<MyLesson['state'], Tone | null> = { taken: 'done', cover: 'done', now: 'plain', later: null, no_register: 'attention', needs_cover: 'attention' }
  const tone = tones[l.state]

  return tone === null ? null : { tone, word: t.lessonState[l.state] ?? l.state }
}

/** A teacher's day (the approved design, phone first): the class register, the lesson now, the rest of the day, and away. */
export function MyDayScreen() {
  const { query } = useLocation()
  const day = query.get('day')
  const { user } = useAppContext()
  const loaded = useLoad(`my-day:${day ?? ''}`, () => lessonsApi.myDay(day))
  const state = loaded.data

  if (state === undefined) {
    return <Waiting phase={loaded.phase} what={d.title} labels={copy.loading} rows={8} onRetry={loaded.reload} />
  }

  const open = (l: MyLesson) => navigate(`/registers/lesson/${l.slot_id}?day=${state.day}&from=my-day`)
  const now = state.lessons.find((l) => l.state === 'now') ?? state.lessons.find((l) => l.state === 'later') ?? null
  const rest = state.lessons.filter((l) => l.slot_id !== now?.slot_id)

  return (
    <div className="flex flex-1 flex-col bg-paper">
      <div className="px-3 pt-4 pb-2 md:px-[clamp(16px,3vw,40px)] md:pt-7">
        <div className="label-caps text-text-muted">{d.kicker(longDay(state.day), user.name)}</div>
        <h1 className="type-display mt-0.5 text-[clamp(48px,7vw,88px)]">{d.title}</h1>
        <p className="mt-1 hidden max-w-[680px] text-[15px] text-text-muted md:block">{d.intro}</p>
      </div>

      <div className="grid flex-1 gap-0 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)] lg:border-t-2 lg:border-ink">
        <div className="grid content-start gap-2.5 px-3 pt-2 pb-3 md:px-[clamp(16px,3vw,40px)] lg:pt-5">
          {state.away !== null && (
            <Attention title={d.awayTitle}>{d.awayText(state.away.reason)}</Attention>
          )}
          {state.morning.map((m) => (
            <a
              key={m.class_id}
              href={`/registers/${m.class_id}${state.day !== state.today ? `?day=${state.day}` : ''}`}
              onClick={(e) => {
                e.preventDefault()
                navigate(`/registers/${m.class_id}${state.day !== state.today ? `?day=${state.day}` : ''}`)
              }}
              className="flex min-h-14 items-center gap-2.5 border-2 border-ink bg-sheet px-3 py-2 text-ink no-underline hover:bg-field"
            >
              <span className="min-w-0 flex-1">
                <span className="label-caps block text-text-muted">{d.morningLabel}</span>
                <span className="block text-[15px] font-extrabold">{m.class}</span>
              </span>
              {m.submitted_at !== null ? (
                <Tag tone="done">{d.takenAt(clock(m.submitted_at))}</Tag>
              ) : !m.school_day ? (
                <Tag tone="plain">{d.notSchoolDay}</Tag>
              ) : (
                <Tag tone="attention">{d.notTaken}</Tag>
              )}
            </a>
          ))}

          {now !== null && (
            <section aria-label={now.state === 'now' ? d.now : d.next} className={`bg-sheet p-3 ${now.state === 'now' ? 'border-[3px] border-teal' : 'border-2 border-ink'}`}>
              <div className="flex items-center justify-between gap-2">
                <span className={`label-caps ${now.state === 'now' ? 'text-teal' : 'text-text-muted'}`}>{d.nowLabel(now.state === 'now' ? d.now : d.next, now.period, now.starts, now.ends)}</span>
                {now.register !== null ? <Tag tone="done">{t.lessonState.taken}</Tag> : now.state === 'now' ? <Tag tone="attention">{d.notTaken}</Tag> : <Tag tone="plain">{t.lessonState.later}</Tag>}
              </div>
              <div className="type-display mt-1.5 mb-0.5 text-[40px] leading-[0.86]">{now.class}</div>
              <div className="mb-2.5 text-[15px] font-bold">
                {now.subject}
                {now.register !== null && ` · ${d.presentOf(now.register.present, now.register.total)}`}
              </div>
              {!now.mine && <p className="mb-2.5 text-[13px] text-text-muted">{d.coverFor(now.cover_for, now.asked_by)}</p>}
              <Button className="h-[52px]! w-full text-[17px]!" variant={now.register === null && now.state === 'now' ? 'primary' : 'secondary'} onClick={() => open(now)}>
                {now.register === null ? d.take : d.open}
              </Button>
            </section>
          )}

          {state.day === state.today && <AwayControl state={state} onChanged={loaded.reload} />}
        </div>

        <div className="border-ink bg-sheet max-lg:mt-1 max-lg:border-t-2 lg:border-l-2">
          <h2 className="label-caps px-3 pt-3 pb-2 text-text-muted md:px-[clamp(16px,3vw,40px)]">{d.restOfDay}</h2>
          {state.lessons.length === 0 && <p className="px-3 pb-4 text-sm text-text-muted md:px-[clamp(16px,3vw,40px)]">{d.noLessons}</p>}
          <ol className="border-t border-rule">
            {dayRows(state, rest, now).map((row) => (
              <li key={row.key}>{row.node(open)}</li>
            ))}
          </ol>
        </div>
      </div>
    </div>
  )
}

type Row = { key: string; starts: string; node: (open: (l: MyLesson) => void) => ReactNode }

/** The rest of the day in time order: lessons, the bells' breaks, and the periods with no lesson as free. */
function dayRows(state: MyDayState, lessons: MyLesson[], now: MyLesson | null): Row[] {
  const pad = 'px-3 md:px-[clamp(16px,3vw,40px)]'
  const rows: Row[] = lessons.map((l) => ({ key: l.slot_id, starts: l.starts, node: (open) => <LessonRow lesson={l} pad={pad} onOpen={() => open(l)} /> }))
  const taken = new Set(state.lessons.map((l) => l.starts))

  for (const b of state.bells) {
    if (b.kind === 'break') {
      rows.push({
        key: `b:${b.starts}`,
        starts: b.starts,
        node: () => (
          <div className={`flex items-center gap-2.5 border-b border-rule bg-paper py-1 text-xs text-text-muted ${pad}`}>
            <span className="ref w-11">{b.starts}</span>
            <span className="label-caps">{b.name}</span>
          </div>
        ),
      })
    } else if (!taken.has(b.starts) && b.starts !== now?.starts) {
      rows.push({
        key: `f:${b.starts}`,
        starts: b.starts,
        node: () => (
          <div className={`flex min-h-12 items-center gap-2.5 border-b border-rule bg-sheet py-1.5 ${pad}`}>
            <Period number={b.number} starts={b.starts} />
            <span className="text-sm text-text-muted">{d.free}</span>
          </div>
        ),
      })
    }
  }

  return rows.sort((a, b) => a.starts.localeCompare(b.starts))
}

function Period({ number, starts }: { number: number | null; starts: string }) {
  return (
    <span className="w-11 flex-none">
      <span className="type-display block text-[15px] leading-none">{number !== null ? t.periodShort(number) : ''}</span>
      <span className="ref block text-[11px] text-text-muted">{starts}</span>
    </span>
  )
}

function LessonRow({ lesson: l, pad, onOpen }: { lesson: MyLesson; pad: string; onOpen: () => void }) {
  const tag = lessonTag(l)
  const sub = !l.mine ? d.coverFor(l.cover_for, l.asked_by) : l.register !== null ? d.presentOf(l.register.present, l.register.total) : ''

  return (
    <button
      type="button"
      onClick={onOpen}
      aria-label={d.lessonLabel(l.period, l.class, l.subject, tag?.word ?? t.lessonState.later ?? '')}
      className={`flex min-h-14 w-full items-center gap-2.5 border-b border-rule py-1.5 text-left hover:bg-field ${l.mine ? 'bg-sheet' : 'bg-marigold-wash'} ${pad}`}
    >
      <Period number={l.period} starts={l.starts} />
      <span className="min-w-0 flex-1">
        <span className="block text-[15px] font-extrabold">
          {l.class} · {l.subject}
        </span>
        {sub !== '' && <span className="block text-xs text-text-muted">{sub}</span>}
      </span>
      {tag !== null ? <Tag tone={tag.tone}>{tag.word}</Tag> : <span className="text-xs text-text-muted">{t.lessonState.later}</span>}
    </button>
  )
}

/** I am away today, with an optional reason; and I am back. */
function AwayControl({ state, onChanged }: { state: MyDayState; onChanged: () => void }) {
  const [asking, setAsking] = useState(false)
  const [reason, setReason] = useState('')
  const [working, setWorking] = useState(false)
  const [failed, setFailed] = useState<string | null>(null)

  const send = async (away: boolean) => {
    setWorking(true)
    setFailed(null)
    const result = await lessonsApi.away({ day: state.day, away, ...(away && reason.trim() !== '' ? { reason: reason.trim() } : {}) })
    setWorking(false)

    if (result.kind === 'saved') {
      setAsking(false)
      setReason('')
      onChanged()
      slips.done({ word: copy.slips.saved, title: away ? d.awaySlip : d.backSlip })
    } else if (result.kind === 'invalid') setFailed(Object.values(result.errors)[0]?.[0] ?? s.failed)
    else setFailed(result.kind === 'refused' ? s.refused : s.failed)
  }

  return (
    <div className="mt-1 border-t-2 border-ink pt-2.5">
      {failed !== null && (
        <div className="mb-2.5">
          <Notice tone="wrong" title={failed} />
        </div>
      )}
      {state.away !== null ? (
        <Button variant="secondary" className="w-full" busy={working} onClick={() => void send(false)}>
          {d.backButton}
        </Button>
      ) : asking ? (
        <form
          className="grid gap-2.5 border-2 border-ink bg-sheet p-3"
          onSubmit={(e) => {
            e.preventDefault()
            void send(true)
          }}
        >
          <h2 className="type-display text-[22px] leading-none">{d.confirmTitle}</h2>
          <p className="text-[13px] text-text-muted">{d.confirmText}</p>
          <Input label={d.reason} hint={d.reasonHint} value={reason} onChange={(e) => setReason(e.target.value)} maxLength={255} />
          <div className="flex flex-wrap gap-2">
            <Button type="submit" busy={working}>
              {working ? s.saving : d.confirm}
            </Button>
            <Button variant="secondary" onClick={() => setAsking(false)}>
              {s.cancel}
            </Button>
          </div>
        </form>
      ) : (
        <Button variant="secondary" className="w-full" onClick={() => setAsking(true)}>
          {d.awayButton}
        </Button>
      )}
    </div>
  )
}
