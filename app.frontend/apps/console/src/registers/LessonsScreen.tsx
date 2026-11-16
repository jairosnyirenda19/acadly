import { navigate, useLoad, useLocation } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { Total, TotalsBand } from '../setup/parts'
import { SmallButton, Tag } from '../settings/parts'
import { clock, lessonsApi, type AwayPerson, type LessonCell, type LessonsState, type LessonStateName } from './api'
import { RegistersHead } from './parts'

const t = copy.registers
const s = copy.settings

/** The look of each lesson state: a fill or an edge, always with a word or a mark (DESIGN.md). */
const cellLook: Record<LessonStateName, string> = {
  taken: 'bg-sheet',
  cover: 'bg-sheet',
  now: 'bg-teal-wash outline-[3px] -outline-offset-[3px] outline-teal',
  no_register: 'bg-marigold-wash',
  needs_cover: 'bg-sheet outline-2 -outline-offset-4 outline-dashed outline-marigold',
  later: 'bg-sheet',
}

const legendLook: Record<keyof typeof t.legend, string> = {
  taken: 'bg-sheet',
  cover: 'bg-sheet',
  now: cellLook.now,
  no_register: cellLook.no_register,
  needs_cover: cellLook.needs_cover,
  free: 'bg-paper',
}

/** Mark beside the subject: the present stroke when a register exists, ! when the period ended with none. */
const cellMark: Partial<Record<LessonStateName, { glyph: string; look: string }>> = {
  taken: { glyph: '/', look: 'bg-teal text-sheet' },
  cover: { glyph: '/', look: 'bg-teal text-sheet' },
  no_register: { glyph: '!', look: 'border-2 border-ink bg-marigold text-ink' },
}

/** Words shown under the subject; a later lesson shows none. */
const cellWord: Partial<Record<LessonStateName, { word: string; strong: boolean }>> = {
  taken: { word: t.lessonState.taken ?? '', strong: false },
  cover: { word: t.lessonState.cover ?? '', strong: true },
  now: { word: t.lessonState.now ?? '', strong: true },
  no_register: { word: t.lessonState.no_register ?? '', strong: true },
  needs_cover: { word: t.lessonState.needs_cover ?? '', strong: true },
}

/** Every lesson of every class on one day (the approved design), and who is away. */
export function LessonsScreen() {
  const { query } = useLocation()
  const day = query.get('day')
  const loaded = useLoad(`lessons:${day ?? ''}`, () => lessonsApi.lessons(day))
  const state = loaded.data

  if (state === undefined) {
    return <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} band={[t.bandLessons, t.bandCover, t.bandNoRegister, t.bandNeedsCover]} rows={6} onRetry={loaded.reload} />
  }

  const totals = state.totals
  const open = (slot: string) => navigate(`/registers/lesson/${slot}?day=${state.day}`)

  return (
    <>
      <RegistersHead kicker={state.places.map((p) => p.label).join(' · ')} intro={t.lessonsIntro} day={state.day} today={state.today} current="lessons" tabs />
      <TotalsBand>
        <Total tone="ink" label={t.bandLessons} value={String(totals.so_far)} suffix={` / ${totals.lessons}`} note={t.bandLessonsNote(state.day === state.today ? state.now : null, totals.taken)} />
        <Total label={t.bandCover} value={String(totals.cover)} note={t.bandCoverNote} />
        <Total tone={totals.no_register > 0 ? 'attention' : 'plain'} label={t.bandNoRegister} value={String(totals.no_register)} note={t.bandNoRegisterNote(totals.no_register)} />
        <Total tone={totals.needs_cover > 0 ? 'attention' : 'plain'} label={t.bandNeedsCover} value={String(totals.needs_cover)} note={t.bandNeedsCoverNote(state.away.map((a) => a.name))} />
      </TotalsBand>

      <div className="flex flex-1 flex-wrap items-stretch">
        <section className="min-w-0 flex-[3_1_640px] bg-sheet px-3 pt-4 pb-6 md:px-[clamp(16px,3vw,40px)]">
          <div className="mb-2.5 flex flex-wrap items-center gap-x-3.5 gap-y-1.5 text-[13px]">
            {(Object.keys(t.legend) as (keyof typeof t.legend)[]).map((k) => (
              <span key={k} className="inline-flex items-center gap-1.5">
                <span aria-hidden="true" className={`size-[18px] border border-rule ${legendLook[k]}`} />
                {t.legend[k]}
              </span>
            ))}
          </div>
          {state.places.every((p) => p.classes.every((c) => c.lessons.length === 0)) && <p className="py-4 text-sm text-text-muted">{t.noLessons}</p>}
          {state.places.map((p) => (
            <Place key={p.label} place={p} labelled={state.places.length > 1} onOpen={open} />
          ))}
          <p className="mt-3 text-[13px] text-text-muted">{t.lessonsRule}</p>
        </section>

        {state.away.length > 0 && (
          <aside className="min-w-0 flex-[1_1_340px] border-ink bg-paper px-3 pt-4 pb-6 max-[999px]:border-t-2 md:px-[clamp(16px,3vw,32px)] min-[1000px]:border-l-2">
            <h2 className="label-caps mb-1.5 text-ink">{t.awayToday}</h2>
            <div className="grid gap-3">
              {state.away.map((a) => (
                <AwayCard key={a.user_id} person={a} day={state.day} onAsked={loaded.set} />
              ))}
            </div>
          </aside>
        )}
      </div>
    </>
  )
}

/** One level at one campus: classes down, lesson periods across, breaks as thin columns. */
function Place({ place, labelled, onOpen }: { place: LessonsState['places'][number]; labelled: boolean; onOpen: (slot: string) => void }) {
  const cols = `116px ${place.bells.map((b) => (b.kind === 'break' ? '10px' : 'minmax(78px,1fr)')).join(' ')}`
  const lessonsIn = place.bells.filter((b) => b.kind === 'lesson').length

  if (lessonsIn === 0) {
    return (
      <div className="mb-4">
        {labelled && <div className="label-caps border-b border-rule bg-field px-3 pt-3 pb-1.5 text-ink">{place.label}</div>}
        <p className="px-3 py-3 text-sm text-text-muted">{t.noBells}</p>
      </div>
    )
  }

  return (
    <div className="mb-4">
      {labelled && <div className="label-caps border-b border-rule bg-field px-3 pt-3 pb-1.5 text-ink">{place.label}</div>}
      <div className="overflow-x-auto">
        <div style={{ minWidth: `${116 + lessonsIn * 80 + (place.bells.length - lessonsIn) * 10}px` }}>
          <div className="label-caps grid border-b-2 border-ink text-text-muted" style={{ gridTemplateColumns: cols }}>
            <span />
            {place.bells.map((b) =>
              b.kind === 'break' ? (
                <span key={b.starts} aria-hidden="true" className="bg-field" />
              ) : (
                <span key={b.starts} className="px-1.5 pb-1.5">
                  <span className="block">{b.number !== null ? t.periodShort(b.number) : b.name}</span>
                  <span className="ref font-semibold tracking-normal">{b.starts}</span>
                </span>
              ),
            )}
          </div>
          {place.classes.map((c) => (
            <div key={c.id} className="grid border-b border-rule" style={{ gridTemplateColumns: cols }}>
              <span className="type-display border-r-2 border-ink p-2 text-lg leading-none">{c.name}</span>
              {c.lessons.length === 0 ? (
                <span className="col-span-full border-l border-rule px-2 py-3 text-[13px] text-text-muted" style={{ gridColumn: '2 / -1' }}>
                  {t.noTimetable}
                </span>
              ) : (
                place.bells.map((b) => {
                if (b.kind === 'break') return <span key={b.starts} aria-hidden="true" className="bg-field" />
                const l = c.lessons.find((x) => x.starts === b.starts)

                return l === undefined ? (
                  <span key={b.starts} className="min-h-11 border-l border-rule bg-paper px-2 py-1.5 text-[11px] font-semibold text-text-muted">
                    {t.free}
                  </span>
                ) : (
                  <Cell key={b.starts} lesson={l} onOpen={() => onOpen(l.slot_id)} />
                )
                })
              )}
            </div>
          ))}
        </div>
      </div>
    </div>
  )
}

function Cell({ lesson: l, onOpen }: { lesson: LessonCell; onOpen: () => void }) {
  const mark = cellMark[l.state]
  const word = cellWord[l.state]
  const who = l.taken_by ?? l.teacher

  return (
    <button
      type="button"
      onClick={onOpen}
      title={[l.subject, who].filter((x) => x !== null).join(' · ')}
      aria-label={t.lessonCell(l.class, l.period, l.subject, t.lessonState[l.state] ?? l.state)}
      className={`min-h-11 border-l border-rule px-2 py-1.5 text-left hover:bg-field ${cellLook[l.state]}`}
    >
      <span className="ref flex items-center gap-1.5 text-sm font-extrabold">
        {mark !== undefined && (
          <span aria-hidden="true" className={`inline-flex size-[18px] items-center justify-center text-[11px] ${mark.look}`}>
            {mark.glyph}
          </span>
        )}
        {l.subject_short}
      </span>
      {word !== undefined && <span className={`block text-[11px] ${word.strong ? 'font-extrabold' : 'font-semibold text-text-muted'}`}>{word.word}</span>}
      {l.asked !== null && l.register === null && <span className="block truncate text-[11px] text-text-muted">{t.asked(l.asked)}</span>}
    </button>
  )
}

/** One person away: their lessons, and asking someone free to cover one. */
function AwayCard({ person: a, day, onAsked }: { person: AwayPerson; day: string; onAsked: (next: LessonsState) => void }) {
  const [asking, setAsking] = useState<string | null>(null)
  const lesson = a.lessons.find((l) => l.slot_id === asking) ?? null
  const covered = a.lessons.filter((l) => l.register !== null).length

  return (
    <div className="border-2 border-ink bg-sheet px-3.5 pt-3 pb-3.5">
      <div className="flex flex-wrap items-center gap-2">
        <span className="type-display text-[22px] leading-none">{a.name}</span>
        <Tag tone="attention">{t.awayTag}</Tag>
      </div>
      <p className="mt-1 mb-2 text-[13px] text-text-muted">
        {t.awayLine(a.marked_by, clock(a.marked_at), a.reason)} {t.awayCount(a.lessons.length, covered)}
      </p>
      {a.lessons.map((l) => (
        <div key={l.slot_id} className="flex items-center gap-2.5 border-b border-rule py-2">
          <span className="min-w-0 flex-1">
            <span className="ref block text-xs text-text-muted">
              {t.periodShort(l.period)} · {l.starts}
            </span>
            <span className="block text-sm font-extrabold">
              {l.class} · {l.subject}
            </span>
          </span>
          {l.register !== null ? (
            <Tag tone="done">{t.lessonState[l.state] ?? l.state}</Tag>
          ) : l.asked !== null ? (
            <Tag tone="plain">{t.asked(l.asked)}</Tag>
          ) : l.can_arrange && l.state !== 'no_register' ? (
            <SmallButton onClick={() => setAsking(asking === l.slot_id ? null : l.slot_id)}>{t.askSomeone}</SmallButton>
          ) : (
            <Tag tone={l.state === 'no_register' || l.state === 'needs_cover' ? 'attention' : 'plain'}>{t.lessonState[l.state] ?? l.state}</Tag>
          )}
        </div>
      ))}
      {lesson !== null && (
        <AskPanel
          key={lesson.slot_id}
          lesson={lesson}
          day={day}
          onDone={(next, name) => {
            setAsking(null)
            onAsked(next)
            slips.done({ word: copy.slips.sent, title: t.askedSlip(name) })
          }}
        />
      )}
    </div>
  )
}

function AskPanel({ lesson, day, onDone }: { lesson: AwayPerson['lessons'][number]; day: string; onDone: (next: LessonsState, name: string) => void }) {
  const loaded = useLoad(`cover-free:${lesson.slot_id}:${day}`, () => lessonsApi.free(lesson.slot_id, day))
  const [chosen, setChosen] = useState<string | null>(null)
  const [working, setWorking] = useState(false)
  const [failed, setFailed] = useState<string | null>(null)
  const free = loaded.data?.free ?? []
  const pick = free.find((p) => p.id === chosen) ?? free[0] ?? null

  const ask = async () => {
    if (pick === null) return
    setWorking(true)
    setFailed(null)
    const result = await lessonsApi.ask({ slot_id: lesson.slot_id, day, user_id: pick.id })
    setWorking(false)

    if (result.kind === 'saved') onDone(result.data, pick.name)
    else if (result.kind === 'invalid') setFailed(Object.values(result.errors)[0]?.[0] ?? s.failed)
    else setFailed(result.kind === 'refused' ? s.refused : s.failed)
  }

  return (
    <div className="mt-3 border-t-2 border-ink pt-3">
      <div className="label-caps text-text-muted">{t.askTitle(lesson.period, lesson.class, lesson.subject)}</div>
      <p className="mt-1 mb-2 text-[13px]">{t.freeAt(lesson.starts)}</p>
      {loaded.data === undefined ? (
        <p className="text-[13px] text-text-muted">{loaded.phase === 'failed' || loaded.phase === 'refused' ? s.loadFailed : t.freeLoading}</p>
      ) : free.length === 0 ? (
        <p className="text-[13px] text-text-muted">{t.nobodyFree}</p>
      ) : (
        <div role="radiogroup" aria-label={t.askSomeone} className="grid gap-1.5">
          {free.map((p) => (
            <label key={p.id} className={`flex cursor-pointer items-center gap-2.5 border-2 bg-sheet px-2.5 py-2 text-sm font-bold ${pick?.id === p.id ? 'border-ink' : 'border-rule'}`}>
              <input type="radio" name={`cover-${lesson.slot_id}`} checked={pick?.id === p.id} onChange={() => setChosen(p.id)} className="size-[18px] accent-teal" />
              <span className="flex-1">{p.name}</span>
              <span className="text-xs font-semibold text-text-muted">{t.lessonsCount(p.lessons)}</span>
            </label>
          ))}
        </div>
      )}
      {failed !== null && (
        <div className="mt-2.5">
          <Notice tone="wrong" title={failed} />
        </div>
      )}
      {pick !== null && (
        <div className="mt-2.5 flex flex-wrap gap-2">
          <Button busy={working} onClick={() => void ask()}>
            {t.ask(pick.name)}
          </Button>
        </div>
      )}
      <p className="mt-2 text-xs text-text-muted">{t.askNote}</p>
    </div>
  )
}
