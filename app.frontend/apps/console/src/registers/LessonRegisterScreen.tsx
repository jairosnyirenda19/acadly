import { navigate, useLoad, useLocation } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useEffect, useState } from 'react'
import { copy } from '../copy'
import { Attention, nn, Total, TotalsBand } from '../setup/parts'
import { PageHead, SmallButton, Tag } from '../settings/parts'
import { clock, lessonsApi, longDay, markLook, MARKS, type LessonLearner, type LessonState, type MarkValue } from './api'
import { AskForm, DecideCard, Glyph } from './parts'
import { readDraft, writeDraft, type Draft } from './RegisterScreen'

const t = copy.registers
const s = copy.settings

const draftKey = (slot: string, day: string) => `acadly.lesson.${slot}.${day}`

/** Marked here this morning: present or late on the morning register. */
const here = (morning: string | null) => morning === 'present' || morning === 'late'

/** Where the page goes back to: My day when opened from it, otherwise the Lessons tab. */
function backTo(from: string | null, day: string, today: string): { path: string; label: string } {
  if (from === 'my-day') return { path: day === today ? '/my-day' : `/my-day?day=${day}`, label: t.backToDay }

  return { path: day === today ? '/registers/lessons' : `/registers/lessons?day=${day}`, label: t.backToLessons }
}

/** One lesson's register (the approved design): taking it, and after it is submitted, its corrections. */
export function LessonRegisterScreen({ slot }: { slot: string }) {
  const { query } = useLocation()
  const day = query.get('day')
  const loaded = useLoad(`lesson:${slot}:${day ?? ''}`, () => lessonsApi.lesson(slot, day))
  const state = loaded.data

  if (state === undefined) {
    return <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} band={[t.bandMarked, t.bandPresent, t.bandAbsent, t.bandMissedNow]} rows={8} onRetry={loaded.reload} />
  }

  const back = backTo(query.get('from'), state.day, state.today)

  return state.can_take ? <TakeLesson state={state} back={back} onDone={loaded.set} /> : <SubmittedLesson state={state} back={back} onChange={loaded.set} />
}

function Band({ learners, marks, missed }: { learners: number; marks: string[]; missed: number }) {
  const count = (m: string) => marks.filter((x) => x === m).length

  return (
    <TotalsBand>
      <Total tone="ink" label={t.bandMarked} value={String(marks.length)} suffix={` / ${learners}`} note={t.bandMarkedNote(learners - marks.length)} />
      <Total label={t.bandPresent} value={String(count('present'))} note={t.bandAbsentNote(count('late'), count('excused'))} />
      <Total label={t.bandAbsent} value={String(count('absent'))} note="" />
      <Total tone={missed > 0 ? 'attention' : 'plain'} label={t.bandMissedNow} value={String(missed)} note={t.bandMissedNowNote} />
    </TotalsBand>
  )
}

/** The morning mark, and the earlier lesson's, small: what the learner was before this lesson. */
function Before({ learner, earlier }: { learner: LessonLearner; earlier: number | null }) {
  const word = (m: string | null) => (m !== null ? (t.glyph[m] ?? m) : '·')

  return (
    <span className="ref text-[12px] text-text-muted">
      {t.morningMark(word(learner.morning))}
      {earlier !== null && ` · ${t.earlierMark(earlier, word(learner.earlier))}`}
    </span>
  )
}

/** Away from the register before this one: absent or excused. */
const awayBefore = (status: string | null) => status === 'absent' || status === 'excused'

/**
 * Where a learner starts when the register is confirmed as before: as they were
 * in the register the class was last seen in. Late then is present now; a
 * learner not in that register starts present.
 */
function startOf(state: LessonState): Draft {
  return Object.fromEntries(
    state.learners.map((l) => [l.id, { status: (awayBefore(l.before) ? l.before : 'present') as MarkValue, note: '' }]),
  )
}

/** Why a learner is listed when confirming: how they were before. */
function reasonOf(l: LessonLearner, previous: string): string {
  if (l.before === 'absent') return l.morning === 'absent' ? t.absentSinceMorning : t.absentAt(previous)
  if (l.before === 'excused') return t.excusedAt(previous)
  if (l.before === 'late') return l.morning === 'late' ? t.lateMorning : t.lateAt(previous)

  return ''
}

function TakeLesson({ state, back, onDone }: { state: LessonState; back: { path: string; label: string }; onDone: (next: LessonState) => void }) {
  const key = draftKey(state.lesson.slot_id, state.day)
  const [draft, setDraft] = useState<Draft>(() => {
    const ids = new Set(state.learners.map((l) => l.id))
    const kept = Object.fromEntries(Object.entries(readDraft(key)).filter(([id, m]) => ids.has(id) && MARKS.includes(m.status)))
    // Confirming as before starts every learner as they were; otherwise absent this morning starts as absent. What this device kept wins.
    const start: Draft =
      state.previous !== null
        ? startOf(state)
        : Object.fromEntries(state.learners.filter((l) => l.morning === 'absent').map((l) => [l.id, { status: 'absent' as MarkValue, note: '' }]))

    return { ...start, ...kept }
  })
  const [showAll, setShowAll] = useState(false)
  const [working, setWorking] = useState(false)
  const [failed, setFailed] = useState<string | null>(null)

  useEffect(() => writeDraft(key, Object.keys(draft).length > 0 ? draft : null), [key, draft])

  const set = (id: string, status: MarkValue) => setDraft((d) => ({ ...d, [id]: { status, note: d[id]?.note ?? '' } }))
  const note = (id: string, text: string) => setDraft((d) => (d[id] !== undefined ? { ...d, [id]: { ...d[id], note: text } } : d))
  const cycle = (id: string) => {
    const now = draft[id]?.status
    set(id, MARKS[(now === undefined ? 0 : MARKS.indexOf(now) + 1) % MARKS.length] ?? 'present')
  }
  const rest = () => setDraft((d) => Object.fromEntries(state.learners.map((l) => [l.id, d[l.id] ?? { status: 'present' as MarkValue, note: '' }])))
  const left = state.learners.filter((l) => draft[l.id] === undefined).length
  const missed = state.learners.filter((l) => here(l.morning) && draft[l.id]?.status === 'absent').length
  const earlier = state.earlier?.period ?? null
  const confirming = state.previous !== null
  const previous = state.previous?.name ?? t.morningRegister
  const initial = confirming ? startOf(state) : {}
  const changes = confirming ? state.learners.filter((l) => draft[l.id]?.status !== initial[l.id]?.status).length : 0
  const statuses = Object.values(draft).map((m) => m.status)
  const hereNow = statuses.filter((m) => m === 'present' || m === 'late').length
  const awayNow = statuses.filter((m) => m === 'absent' || m === 'excused').length
  const listed = confirming && !showAll ? state.learners.filter((l) => l.before === 'absent' || l.before === 'excused' || l.before === 'late' || draft[l.id]?.status !== initial[l.id]?.status) : state.learners
  const undo = () => {
    setDraft(startOf(state))
    setShowAll(false)
  }

  const focusRow = (i: number) => document.querySelector<HTMLElement>(`[data-register-row="${i}"]`)?.focus()
  const keyed = (e: React.KeyboardEvent, i: number, id: string) => {
    const pick = ({ p: 'present', a: 'absent', l: 'late', e: 'excused' } as Record<string, MarkValue>)[e.key.toLowerCase()]

    if (pick !== undefined && !(e.target instanceof HTMLInputElement)) {
      e.preventDefault()
      set(id, pick)
      focusRow(i + 1)
    } else if (e.key === 'ArrowDown') {
      e.preventDefault()
      focusRow(i + 1)
    } else if (e.key === 'ArrowUp') {
      e.preventDefault()
      focusRow(i - 1)
    }
  }

  const submit = async () => {
    setWorking(true)
    setFailed(null)
    const result = await lessonsApi.submit(
      state.lesson.slot_id,
      state.day,
      state.learners.map((l) => ({ learner_id: l.id, status: draft[l.id]?.status ?? 'present', note: draft[l.id]?.note.trim() || null })),
    )
    setWorking(false)

    if (result.kind === 'saved') {
      writeDraft(key, null)
      onDone(result.data)
      slips.done({ word: copy.slips.saved, title: t.lessonSubmitted(state.class.name, state.lesson.subject) })
    } else if (result.kind === 'invalid') setFailed(Object.values(result.errors)[0]?.[0] ?? s.failed)
    else setFailed(result.kind === 'refused' ? s.refused : s.failed)
  }

  const COLS = earlier !== null ? 'md:grid-cols-[48px_minmax(180px,1.4fr)_64px_64px_184px_minmax(180px,1.4fr)]' : 'md:grid-cols-[48px_minmax(180px,1.4fr)_64px_184px_minmax(180px,1.4fr)]'

  return (
    <>
      <PageHead
        kicker={t.lessonKicker(state.lesson.subject, state.lesson.period, state.lesson.starts, state.lesson.ends)}
        title={state.class.name}
        intro={t.lessonTakeIntro(longDay(state.day), state.lesson.teacher)}
        actions={
          confirming ? undefined : (
            <Button variant="secondary" onClick={rest}>
              {t.restPresent}
            </Button>
          )
        }
      />
      {confirming && state.previous !== null ? (
        <section className="mx-3 mb-3 border-[3px] border-teal bg-sheet p-3 md:mx-[clamp(16px,3vw,40px)] md:p-4">
          <div className="label-caps text-teal">{changes === 0 ? t.asAt(previous, state.previous.by, clock(state.previous.at)) : t.changesFrom(changes, previous)}</div>
          <div className="mt-1.5 mb-1 flex flex-wrap items-baseline gap-x-3.5">
            <span className="type-display text-[64px] leading-[0.8]">{hereNow}</span>
            <span className="text-base font-extrabold">{t.here}</span>
            <span className="type-display text-[40px] leading-[0.8] text-red-pen">{awayNow}</span>
            <span className="text-base font-extrabold">{t.away}</span>
          </div>
          {changes === 0 && <p className="mb-2.5 text-[13px] text-text-muted">{t.lookAround}</p>}
          <Button className="mt-1 h-14! w-full text-lg! md:w-auto" busy={working} disabled={state.learners.length === 0} onClick={() => void submit()}>
            {working ? t.confirming : t.confirm(hereNow, changes)}
          </Button>
        </section>
      ) : (
        <Band learners={state.learners.length} marks={statuses} missed={missed} />
      )}

      <section className="flex-1 bg-sheet px-3 pt-3.5 pb-5 md:px-[clamp(16px,3vw,40px)]">
        {failed !== null && (
          <div className="mb-3">
            <Notice tone="wrong" title={failed} />
          </div>
        )}
        {!state.lesson.required && <p className="mb-3 text-[13px] text-text-muted">{t.notRequired}</p>}
        {state.cover && (
          <div className="mb-3">
            <Attention title={t.recordedCover}>{t.coverNote}</Attention>
          </div>
        )}
        <div className="mb-2.5 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-[13px]">
          {MARKS.map((m) => (
            <span key={m} className="inline-flex items-center gap-1.5">
              <Glyph mark={m} size="size-[22px] text-[13px]" />
              {t.marks[m]}
            </span>
          ))}
          {!confirming && <span className="text-text-muted">{state.morning_taken ? t.startsAbsent : t.noMorning}</span>}
          <span className="hidden text-text-muted md:inline">{t.keys}</span>
          <span className="text-text-muted md:hidden">{t.tapNote}</span>
        </div>
        {confirming && (
          <div className="mb-1.5 flex flex-wrap items-center justify-between gap-2">
            <span className="label-caps text-text-muted">{showAll ? t.allLearners(state.learners.length) : t.awayBefore(previous)}</span>
            {changes > 0 && (
              <SmallButton onClick={undo}>{t.undo}</SmallButton>
            )}
          </div>
        )}
        {confirming && listed.length === 0 && <p className="border-b border-rule py-3 text-sm text-text-muted">{t.nobodyAway(previous)}</p>}
        {state.learners.length === 0 && <p className="py-4 text-sm text-text-muted">{t.noLearners}</p>}
        <div className={`label-caps hidden ${COLS} gap-3 border-b-2 border-ink pr-4 pb-2 text-text-muted md:grid`}>
          <span />
          <span>{t.colLearner}</span>
          <span>{t.colMorning}</span>
          {earlier !== null && <span>{t.colEarlier(earlier)}</span>}
          <span>{t.colThisLesson}</span>
          <span>{t.colReason}</span>
        </div>
        <div className="md:ledger md:[--margin:48px]">
          {listed.map((l) => {
            const i = state.learners.indexOf(l)
            const mark = draft[l.id]?.status ?? null
            const wasHere = confirming ? !awayBefore(l.before) && awayBefore(mark) : here(l.morning) && mark === 'absent'
            const reason = confirming ? reasonOf(l, previous) : ''

            return (
              <div
                key={l.id}
                data-register-row={i}
                tabIndex={0}
                onKeyDown={(e) => keyed(e, i, l.id)}
                className={`flex items-center gap-2.5 border-b border-rule py-2 pr-1 outline-none focus-visible:bg-teal-wash md:grid md:gap-3 md:pr-4 ${COLS} ${wasHere ? 'bg-marigold-wash' : mark === 'absent' ? 'bg-red-wash' : ''}`}
              >
                <span className="type-display w-6 text-center text-base text-text-muted md:w-auto md:text-lg">{nn(i + 1)}</span>
                <span className="min-w-0 flex-1">
                  <span className="block text-[15px] font-extrabold md:text-base">{l.name}</span>
                  <span className="ref hidden text-xs text-text-muted md:block">{l.admission_number ?? ''}</span>
                  {wasHere ? (
                    <span className="mt-0.5 inline-block">
                      <Tag tone="attention">{confirming ? t.wasHereAt(previous) : t.wasHere}</Tag>
                    </span>
                  ) : reason !== '' ? (
                    <span className="block text-xs text-text-muted">{reason}</span>
                  ) : (
                    <span className="block md:hidden">
                      <Before learner={l} earlier={earlier} />
                    </span>
                  )}
                </span>
                <span className="hidden md:block">
                  <Glyph mark={l.morning} size="size-7 text-sm" />
                </span>
                {earlier !== null && (
                  <span className="hidden md:block">
                    <Glyph mark={l.earlier} size="size-7 text-sm" />
                  </span>
                )}
                <span role="radiogroup" aria-label={l.name} className="hidden gap-1 md:flex">
                  {MARKS.map((m) => (
                    <button
                      key={m}
                      type="button"
                      role="radio"
                      aria-checked={mark === m}
                      aria-label={t.marks[m]}
                      title={t.marks[m]}
                      onClick={() => set(l.id, m)}
                      className={`ref size-10 text-lg font-extrabold ${mark === m ? markLook[m] : 'border-2 border-rule bg-sheet text-text-muted hover:border-ink'}`}
                    >
                      {t.glyph[m]}
                    </button>
                  ))}
                </span>
                <button type="button" aria-label={t.markLabel(l.name, mark !== null ? (t.marks[mark] ?? mark) : t.notMarked)} onClick={() => cycle(l.id)} className="md:hidden">
                  <Glyph mark={mark} size="size-[52px] text-2xl" />
                </button>
                <span className="hidden md:block">
                  {mark !== null && mark !== 'present' ? (
                    <input
                      type="text"
                      value={draft[l.id]?.note ?? ''}
                      onChange={(e) => note(l.id, e.target.value)}
                      maxLength={255}
                      placeholder={t.reason}
                      aria-label={t.reasonFor(l.name)}
                      className="h-9 w-full border-0 border-b-2 border-ink bg-field px-2.5 text-sm text-ink outline-none focus:border-teal"
                    />
                  ) : (
                    <span className="text-[13px] text-text-muted">{mark === null ? t.notMarked : ''}</span>
                  )}
                </span>
              </div>
            )
          })}
        </div>
        {confirming && !showAll && (
          <button type="button" onClick={() => setShowAll(true)} className="h-12 w-full border-b border-rule bg-sheet px-3 text-left text-[15px] font-extrabold text-ink hover:bg-field">
            {t.showAll(state.learners.length)}
          </button>
        )}
        <p className="mt-2.5 text-[13px] text-text-muted">{t.draftKept}</p>
        {confirming && (
          <div className="mt-4">
            <Button variant="secondary" onClick={() => navigate(back.path)}>
              {back.label}
            </Button>
          </div>
        )}
      </section>

      {!confirming && <footer className="sticky bottom-0 flex flex-wrap items-center gap-2 border-t-2 border-ink bg-sheet px-3 py-2.5 md:gap-3 md:px-[clamp(16px,3vw,40px)] md:py-4">
        <span className="hidden md:inline-flex">
          <Button variant="secondary" onClick={() => navigate(back.path)}>
            {back.label}
          </Button>
        </span>
        <span className="md:hidden">
          <Button variant="secondary" onClick={rest}>
            {t.restPresentShort}
          </Button>
        </span>
        <span className="hidden flex-[1_1_240px] text-sm font-bold md:block">{left === 0 ? t.readyNote : t.leftNote(left)}</span>
        <span className="ml-auto">
          <Button busy={working} disabled={left > 0 || state.learners.length === 0} onClick={() => void submit()}>
            {working ? (
              t.submitting
            ) : (
              <>
                <span className="hidden md:inline">{t.submit}</span>
                <span className="md:hidden">{t.submitShort(left)}</span>
              </>
            )}
          </Button>
        </span>
      </footer>}
    </>
  )
}

function SubmittedLesson({ state, back, onChange }: { state: LessonState; back: { path: string; label: string }; onChange: (next: LessonState) => void }) {
  const [asking, setAsking] = useState<string | null>(null)
  const marks = state.learners.map((l) => l.mark?.status).filter((m): m is string => m !== undefined)
  const toDecide = state.learners.filter((l) => l.correction?.can_decide === true)
  const missed = state.learners.filter((l) => here(l.morning) && l.mark?.status === 'absent').length
  const earlier = state.earlier?.period ?? null
  const count = (m: string) => marks.filter((x) => x === m).length
  const intro =
    state.register !== null
      ? t.lessonSubmittedIntro(longDay(state.day), state.register.submitted_by, clock(state.register.submitted_at))
      : `${t.lessonViewIntro(longDay(state.day))} ${!state.school_day ? t.notSchoolDayIntro : t.lessonNotOpen}`

  return (
    <>
      <PageHead kicker={t.lessonKicker(state.lesson.subject, state.lesson.period, state.lesson.starts, state.lesson.ends)} title={state.class.name} intro={intro} />
      {state.register !== null && <Band learners={state.learners.length} marks={marks} missed={missed} />}
      <div className="flex flex-1 flex-wrap items-stretch border-t-2 border-ink">
        <section className="min-w-0 flex-[3_1_600px] bg-sheet px-3 pt-4 pb-6 md:px-[clamp(16px,3vw,40px)]">
          {state.register !== null && (
            <div className="mb-2.5 flex flex-wrap items-center gap-2">
              <Tag tone="done">{t.submittedTag}</Tag>
              {state.register.cover && <Tag tone="plain">{t.recordedCover}</Tag>}
              <span className="text-[13px] text-text-muted">{t.submittedSummary(marks.length, count('present'), count('absent'), count('late'), count('excused'))}</span>
            </div>
          )}
          {state.learners.length === 0 && <p className="py-4 text-sm text-text-muted">{t.noLearners}</p>}
          <div className="md:ledger md:[--margin:48px]">
            {state.learners.map((l, i) => {
              const c = l.correction
              const pending = c?.state === 'requested'
              const wasHere = here(l.morning) && l.mark?.status === 'absent'

              return (
                <div key={l.id} className={`border-b border-rule py-2 pr-1 md:pr-4 ${pending || wasHere ? 'bg-marigold-wash' : l.mark?.status === 'absent' ? 'bg-red-wash' : ''}`}>
                  <div className="flex items-center gap-2.5 md:grid md:grid-cols-[48px_minmax(160px,1.2fr)_auto_40px_minmax(160px,1.6fr)_auto] md:gap-3">
                    <span className="type-display w-6 text-center text-base text-text-muted md:w-auto md:text-lg">{nn(i + 1)}</span>
                    <span className="min-w-0 flex-1">
                      <span className="block text-[15px] font-extrabold md:text-base">{l.name}</span>
                      {wasHere && <Tag tone="attention">{t.wasHere}</Tag>}
                    </span>
                    <span className="hidden md:block">
                      <Before learner={l} earlier={earlier} />
                    </span>
                    <Glyph mark={l.mark?.status ?? null} />
                    <span className="hidden text-[13px] md:block">
                      {pending && c !== null ? (
                        <strong>{t.correctionLine(t.marks[c.to] ?? c.to, c.reason, c.requested_by)}</strong>
                      ) : (
                        <span className="text-text-muted">{l.mark?.note ?? ''}</span>
                      )}
                      {l.mark?.corrected === true && !pending && (
                        <span className="ml-1.5">
                          <Tag tone="plain">{t.corrected}</Tag>
                        </span>
                      )}
                    </span>
                    <span className="text-right">
                      {pending ? (
                        <Tag tone="attention">{t.awaiting}</Tag>
                      ) : (
                        state.can_request &&
                        l.mark !== null && <SmallButton onClick={() => setAsking(asking === l.id ? null : l.id)}>{t.requestChange}</SmallButton>
                      )}
                    </span>
                  </div>
                  {asking === l.id && state.register !== null && l.mark !== null && (
                    <AskForm
                      learner={l}
                      send={(body) => lessonsApi.requestCorrection(state.register?.id ?? '', body)}
                      onDone={(next) => {
                        setAsking(null)
                        onChange(next)
                        slips.done({ word: copy.slips.sent, title: t.requested })
                      }}
                      onCancel={() => setAsking(null)}
                    />
                  )}
                </div>
              )
            })}
          </div>
          {state.register !== null && missed > 0 && <p className="mt-2.5 text-[13px] text-text-muted">{t.lessonFollowUp}</p>}
          <div className="mt-4">
            <Button variant="secondary" onClick={() => navigate(back.path)}>
              {back.label}
            </Button>
          </div>
        </section>
        {toDecide.length > 0 && (
          <section className="min-w-0 flex-[2_1_360px] border-ink bg-paper px-3 pt-4 pb-6 max-[959px]:border-t-2 md:px-[clamp(16px,3vw,32px)] min-[960px]:border-l-2">
            <div className="grid gap-4">
              {toDecide.map((l) => (
                <DecideCard
                  key={l.id}
                  learner={l}
                  className={state.class.name}
                  day={state.day}
                  decide={lessonsApi.decide}
                  onDone={(next, approve) => {
                    onChange(next)
                    slips.done({ word: copy.slips.saved, title: approve ? t.approved : t.declined })
                  }}
                />
              ))}
            </div>
          </section>
        )}
      </div>
    </>
  )
}
