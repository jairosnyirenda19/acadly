import { navigate, useLoad, useLocation } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useEffect, useState } from 'react'
import { copy } from '../copy'
import { errorFor } from '../setup/api'
import { Input, nn, Total, TotalsBand } from '../setup/parts'
import { PageHead, SmallButton, Tag } from '../settings/parts'
import { clock, longDay, markLook, MARKS, registersApi, shortDay, unreached, type Absence, type MarkValue, type RegisterLearner, type RegisterState } from './api'
import { AskForm, DecideCard, Glyph, useSave } from './parts'
import { WaitingFor } from '../approvals/WaitingFor'

const t = copy.registers
const s = copy.settings

export type Draft = Record<string, { status: MarkValue; note: string }>

const draftKey = (classId: string, day: string) => `acadly.register.${classId}.${day}`

export function readDraft(key: string): Draft {
  try {
    const raw = window.localStorage.getItem(key)
    const parsed: unknown = raw === null ? null : JSON.parse(raw)

    return parsed !== null && typeof parsed === 'object' ? (parsed as Draft) : {}
  } catch {
    return {}
  }
}

export function writeDraft(key: string, draft: Draft | null): void {
  try {
    if (draft === null) window.localStorage.removeItem(key)
    else window.localStorage.setItem(key, JSON.stringify(draft))
  } catch {
    // Not kept on this device; the marks still hold on the page.
  }
}

/** One class's morning register (the approved design): taking it, and after it is submitted, its corrections. */
export function RegisterScreen({ classId }: { classId: string }) {
  const { query } = useLocation()
  const day = query.get('day')
  const loaded = useLoad(`register:${classId}:${day ?? ''}`, () => registersApi.register(classId, day))
  const state = loaded.data

  if (state === undefined) {
    return <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} band={[t.bandMarked, t.bandPresent, t.bandAbsent, t.bandLate]} rows={8} onRetry={loaded.reload} />
  }

  if (state.late_request !== null && state.register === null) return <LateWaiting state={state} />

  return state.can_take ? <TakeRegister state={state} onDone={loaded.set} /> : <SubmittedRegister state={state} onChange={loaded.set} />
}

function History({ learner, days }: { learner: RegisterLearner; days: string[] }) {
  return (
    <span aria-label={t.lastDays} className="ref inline-flex text-[13px] font-bold whitespace-nowrap">
      {learner.history.map((m, i) => (
        <span key={i} title={days[i] !== undefined ? `${shortDay(days[i])}: ${m !== null ? (t.marks[m] ?? m) : t.notMarked}` : undefined} className={`w-[18px] text-center ${m === 'absent' ? 'text-red-pen' : m === null ? 'text-rule-dashed' : ''}`}>
          {m !== null ? t.glyph[m] : '·'}
        </span>
      ))}
    </span>
  )
}

function Band({ learners, marks }: { learners: number; marks: string[] }) {
  const count = (m: string) => marks.filter((x) => x === m).length

  return (
    <TotalsBand>
      <Total tone="ink" label={t.bandMarked} value={String(marks.length)} suffix={` / ${learners}`} note={t.bandMarkedNote(learners - marks.length)} />
      <Total label={t.bandPresent} value={String(count('present'))} note="" />
      <Total label={t.bandAbsent} value={String(count('absent'))} note="" />
      <Total label={t.bandLate} value={String(count('late'))} note={t.bandLateNote(count('excused'))} />
    </TotalsBand>
  )
}

function TakeRegister({ state, onDone }: { state: RegisterState; onDone: (next: RegisterState) => void }) {
  const key = draftKey(state.class.id, state.day)
  const [draft, setDraft] = useState<Draft>(() => {
    const kept = readDraft(key)
    const ids = new Set(state.learners.map((l) => l.id))

    return Object.fromEntries(Object.entries(kept).filter(([id, m]) => ids.has(id) && MARKS.includes(m.status)))
  })
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

  const [lateReason, setLateReason] = useState('')

  const submit = async () => {
    setWorking(true)
    setFailed(null)
    const result = await registersApi.submit(
      state.class.id,
      state.day,
      state.learners.map((l) => ({ learner_id: l.id, status: draft[l.id]?.status ?? 'present', note: draft[l.id]?.note.trim() || null })),
      state.late ? lateReason.trim() : null,
    )
    setWorking(false)

    if (result.kind === 'saved') {
      writeDraft(key, null)
      onDone(result.data)
      slips.done({ word: copy.slips.saved, title: state.late ? t.lateAsked : t.submitted(state.class.name) })
    } else if (result.kind === 'invalid') setFailed(Object.values(result.errors)[0]?.[0] ?? s.failed)
    else setFailed(result.kind === 'refused' ? s.refused : s.failed)
  }

  const COLS = 'md:grid-cols-[48px_minmax(180px,1.4fr)_100px_184px_minmax(200px,1.5fr)]'

  return (
    <>
      <PageHead
        kicker={t.registerKicker(longDay(state.day))}
        title={state.class.name}
        intro={`${t.morning}. ${t.takeIntro(state.teacher)}`}
        actions={
          <Button variant="secondary" onClick={rest}>
            {t.restPresent}
          </Button>
        }
      />
      <Band learners={state.learners.length} marks={Object.values(draft).map((m) => m.status)} />

      <section className="flex-1 bg-sheet px-3 pt-3.5 pb-5 md:px-[clamp(16px,3vw,40px)]">
        {failed !== null && (
          <div className="mb-3">
            <Notice tone="wrong" title={failed} />
          </div>
        )}
        <div className="mb-2.5 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-[13px]">
          {MARKS.map((m) => (
            <span key={m} className="inline-flex items-center gap-1.5">
              <Glyph mark={m} size="size-[22px] text-[13px]" />
              {t.marks[m]}
            </span>
          ))}
          <span className="hidden text-text-muted md:inline">{t.keys}</span>
          <span className="text-text-muted md:hidden">{t.tapNote}</span>
        </div>
        {state.learners.length === 0 && <p className="py-4 text-sm text-text-muted">{t.noLearners}</p>}
        <div className={`label-caps hidden ${COLS} gap-3 border-b-2 border-ink pr-4 pb-2 text-text-muted md:grid`}>
          <span />
          <span>{t.colLearner}</span>
          <span>{t.colLast}</span>
          <span>{t.colToday}</span>
          <span>{t.colReason}</span>
        </div>
        <div className="md:ledger md:[--margin:48px]">
          {state.learners.map((l, i) => {
            const mark = draft[l.id]?.status ?? null

            return (
              <div
                key={l.id}
                data-register-row={i}
                tabIndex={0}
                onKeyDown={(e) => keyed(e, i, l.id)}
                className={`flex items-center gap-2.5 border-b border-rule py-2 pr-1 outline-none focus-visible:bg-teal-wash md:grid md:gap-3 md:pr-4 ${COLS} ${mark === 'absent' ? 'bg-red-wash' : ''}`}
              >
                <span className="type-display w-6 text-center text-base text-text-muted md:w-auto md:text-lg">{nn(i + 1)}</span>
                <span className="min-w-0 flex-1">
                  <span className="block text-[15px] font-extrabold md:text-base">{l.name}</span>
                  <span className="ref hidden text-xs text-text-muted md:block">{l.admission_number ?? ''}</span>
                  <span className="md:hidden">
                    <History learner={l} days={state.days_before} />
                  </span>
                </span>
                <span className="hidden md:block">
                  <History learner={l} days={state.days_before} />
                </span>
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
        <p className="mt-2.5 text-[13px] text-text-muted">{t.draftKept}</p>
        {state.late && (
          <div className="mt-3 grid max-w-[640px] gap-2 border-l-[6px] border-marigold bg-marigold-wash px-3.5 py-3 text-sm">
            <strong>{t.lateTitle}</strong>
            <span>{t.late(longDay(state.day))}</span>
            <Input label={t.lateReason} required value={lateReason} onChange={(e) => setLateReason(e.target.value)} maxLength={255} />
          </div>
        )}
      </section>

      <footer className="sticky bottom-0 flex flex-wrap items-center gap-2 border-t-2 border-ink bg-sheet px-3 py-2.5 md:gap-3 md:px-[clamp(16px,3vw,40px)] md:py-4">
        <span className="hidden md:inline-flex">
          <Button variant="secondary" onClick={() => navigate('/registers')}>
            {t.back}
          </Button>
        </span>
        <span className="md:hidden">
          <Button variant="secondary" onClick={rest}>
            {t.restPresentShort}
          </Button>
        </span>
        <span className="hidden flex-[1_1_240px] text-sm font-bold md:block">{left === 0 ? t.readyNote : t.leftNote(left)}</span>
        <span className="ml-auto">
          <Button busy={working} disabled={left > 0 || state.learners.length === 0 || (state.late && lateReason.trim() === '')} onClick={() => void submit()}>
            {working ? t.submitting : state.late ? t.lateSubmit : (
              <>
                <span className="hidden md:inline">{t.submit}</span>
                <span className="md:hidden">{t.submitShort(left)}</span>
              </>
            )}
          </Button>
        </span>
      </footer>
    </>
  )
}

/** A late register sent for approval: kept as taken, counting for nothing until the last yes. */
function LateWaiting({ state }: { state: RegisterState }) {
  const late = state.late_request

  if (late === null) return null

  const absent = Object.values(late.marks).filter((m) => m === 'absent').length

  return (
    <>
      <PageHead kicker={t.registerKicker(longDay(state.day))} title={state.class.name} intro={null} />
      <div className="max-w-[720px] px-4 pb-6 md:px-[clamp(16px,3vw,40px)]">
        <WaitingFor title={t.lateWaiting} askedBy={late.asked_by} waitingFor={late.waiting_for} requestId={late.id}>
          <span className="block text-[13px]">{t.lateMarks(Object.keys(late.marks).length, absent)}</span>
          <span className="block text-[13px]">“{late.reason}”</span>
        </WaitingFor>
        <div className="mt-3">
          <Button variant="secondary" onClick={() => navigate('/registers')}>
            {t.back}
          </Button>
        </div>
      </div>
    </>
  )
}

function SubmittedRegister({ state, onChange }: { state: RegisterState; onChange: (next: RegisterState) => void }) {
  const [asking, setAsking] = useState<string | null>(null)
  const marks = state.learners.map((l) => l.mark?.status).filter((m): m is string => m !== undefined)
  const toDecide = state.learners.filter((l) => l.correction?.can_decide === true)
  const toReach = state.learners.filter((l) => l.absence !== null && l.absence.can_follow_up && unreached(l.absence))
  const count = (m: string) => marks.filter((x) => x === m).length
  const intro =
    state.register !== null
      ? t.submittedIntro(state.register.submitted_by, clock(state.register.submitted_at))
      : !state.school_day
        ? t.notSchoolDayIntro
        : state.day > state.today
          ? t.futureIntro
          : t.viewIntro

  return (
    <>
      <PageHead kicker={t.registerKicker(longDay(state.day))} title={state.class.name} intro={intro} />
      {state.register !== null && <Band learners={state.learners.length} marks={marks} />}
      <div className="flex flex-1 flex-wrap items-stretch border-t-2 border-ink">
        <section className="min-w-0 flex-[3_1_600px] bg-sheet px-3 pt-4 pb-6 md:px-[clamp(16px,3vw,40px)]">
          {state.register !== null && (
            <div className="mb-2.5 flex flex-wrap items-center gap-2">
              <Tag tone="done">{t.submittedTag}</Tag>
              <span className="text-[13px] text-text-muted">{t.submittedSummary(marks.length, count('present'), count('absent'), count('late'), count('excused'))}</span>
            </div>
          )}
          {state.learners.length === 0 && <p className="py-4 text-sm text-text-muted">{t.noLearners}</p>}
          <div className="md:ledger md:[--margin:48px]">
            {state.learners.map((l, i) => {
              const c = l.correction
              const pending = c?.state === 'requested'

              return (
                <div key={l.id} className={`border-b border-rule py-2 pr-1 md:pr-4 ${pending ? 'bg-marigold-wash' : l.mark?.status === 'absent' ? 'bg-red-wash' : ''}`}>
                  <div className="flex items-center gap-2.5 md:grid md:grid-cols-[48px_minmax(160px,1.2fr)_40px_minmax(180px,1.8fr)_auto] md:gap-3">
                    <span className="type-display w-6 text-center text-base text-text-muted md:w-auto md:text-lg">{nn(i + 1)}</span>
                    <span className="min-w-0 flex-1 text-[15px] font-extrabold md:text-base">{l.name}</span>
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
                        <>
                          <Tag tone="attention">{t.awaiting}</Tag>
                          {c?.waiting_for != null && <span className="mt-0.5 block text-xs text-text-muted">{t.waitingFor(c.waiting_for)}</span>}
                        </>
                      ) : (
                        state.can_request &&
                        l.mark !== null && (
                          <SmallButton onClick={() => setAsking(asking === l.id ? null : l.id)}>{t.requestChange}</SmallButton>
                        )
                      )}
                    </span>
                  </div>
                  {l.absence !== null && <Told absence={l.absence} />}
                  {l.absence?.from_family != null && l.mark?.status === 'absent' && !pending && state.can_request && state.register !== null && (
                    <AcceptReason
                      registerId={state.register.id}
                      learner={l}
                      day={state.day}
                      onDone={(next) => {
                        onChange(next)
                        slips.done({ word: copy.slips.sent, title: t.accepted })
                      }}
                    />
                  )}
                  {asking === l.id && state.register !== null && l.mark !== null && (
                    <AskForm
                      send={(body) => registersApi.requestCorrection(state.register?.id ?? '', body)}
                      learner={l}
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
          <div className="mt-4">
            <Button variant="secondary" onClick={() => navigate('/registers')}>
              {t.back}
            </Button>
          </div>
        </section>
        {(toDecide.length > 0 || toReach.length > 0) && (
          <section className="min-w-0 flex-[2_1_360px] border-ink bg-paper px-3 pt-4 pb-6 max-[959px]:border-t-2 md:px-[clamp(16px,3vw,32px)] min-[960px]:border-l-2">
            <div className="grid gap-4">
              {toReach.length > 0 && state.register !== null && <ReachCard registerId={state.register.id} learners={toReach} onDone={onChange} />}
              {toDecide.map((l) => (
                <DecideCard
                  key={l.id}
                  learner={l}
                  className={state.class.name}
                  day={state.day}
                  decide={registersApi.decide}
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

const toldTone: Record<string, 'done' | 'attention' | 'wrong' | 'plain'> = {
  delivered: 'done',
  sent: 'plain',
  accepted: 'plain',
  waiting: 'plain',
  not_delivered: 'wrong',
  not_reachable: 'attention',
}

/** Who was told of an absence, by which channel, and how it stands; then what staff did. */
function Told({ absence }: { absence: Absence }) {
  return (
    <div className="mt-2 mb-1 grid gap-1 text-[13px] md:ml-[60px]">
      <span className="label-caps text-text-muted">{t.told}</span>
      {absence.told.length === 0 && <span className="text-text-muted">{t.nobodyTold}</span>}
      {absence.told.map((d, i) => (
        <span key={i} className="flex flex-wrap items-center gap-x-2.5 gap-y-1">
          <span className="min-w-[180px]">
            {d.to}
            {d.relationship !== '' && <span className="text-text-muted"> · {d.relationship}</span>}
          </span>
          <span className="w-12 font-bold">{t.channel[d.channel] ?? d.channel}</span>
          <Tag tone={toldTone[d.state] ?? 'plain'}>{t.toldState[d.state] ?? d.state}</Tag>
          <span className="ref text-xs text-text-muted">{clock(d.at)}</span>
        </span>
      ))}
      {absence.from_family !== null && (
        <span className="flex flex-wrap items-center gap-x-2.5 gap-y-1">
          <Tag tone="done">✓ {t.fromFamily}</Tag>
          <span>
            <strong>{t.absenceReasons[absence.from_family.kind] ?? absence.from_family.kind}</strong>
            {absence.from_family.note !== null && <> · “{absence.from_family.note}”</>}
          </span>
          <span className="text-text-muted">{t.familyGave(absence.from_family.by, clock(absence.from_family.at))}</span>
        </span>
      )}
      {absence.follow_ups.map((f, i) => (
        <span key={`f${i}`} className="text-text-muted">
          {(t.followUp[f.kind] ?? ((by: string) => by))(f.by)}, {clock(f.at)}
          {f.note !== null && <> · {f.note}</>}
        </span>
      ))}
    </div>
  )
}

/** Accept the family's reason (the approved design, D-085): ask for a correction from absent to excused. */
function AcceptReason({ registerId, learner, day, onDone }: { registerId: string; learner: RegisterLearner; day: string; onDone: (next: RegisterState) => void }) {
  const [open, setOpen] = useState(false)
  const save = useSave<RegisterState>()

  if (!open) {
    return (
      <div className="mt-1.5 mb-1 md:ml-[60px]">
        <SmallButton onClick={() => setOpen(true)}>{t.acceptReason}</SmallButton>
      </div>
    )
  }

  return (
    <div role="group" aria-label={t.acceptTitle} className="mt-2 mb-1 border-t-4 border-ink bg-sheet px-4 pt-3.5 pb-4 md:ml-[60px] md:border-2 md:border-t-4">
      <div className="label-caps text-[10px] text-text-muted">{t.acceptKicker(learner.name, shortDay(day))}</div>
      <h3 className="type-display mt-1 mb-2 text-[26px] leading-[0.9]">{t.acceptTitle}</h3>
      <div className="mb-2.5 flex items-center gap-2 text-[15px] font-extrabold">
        <span className="flex items-center gap-1.5"><Glyph mark="absent" /> {t.marks.absent}</span>
        <span aria-hidden="true">→</span>
        <span className="flex items-center gap-1.5"><Glyph mark="excused" /> {t.marks.excused}</span>
      </div>
      <p className="mb-1.5 text-sm leading-normal">{t.acceptNote}</p>
      <p className="mb-3.5 border-l-4 border-marigold bg-marigold-wash px-2.5 py-2 text-sm leading-snug">{t.acceptApproval}</p>
      {(save.failed !== null || Object.keys(save.errors).length > 0) && (
        <div className="mb-3">
          <Notice tone="wrong" title={save.failed ?? Object.values(save.errors)[0]?.[0] ?? ''} />
        </div>
      )}
      <div className="flex flex-wrap gap-2.5">
        <Button variant="secondary" onClick={() => setOpen(false)}>
          {t.acceptCancel}
        </Button>
        <Button busy={save.working} onClick={() => void save.run(registersApi.acceptReason(registerId, learner.id), onDone)}>
          {t.acceptSend}
        </Button>
      </div>
    </div>
  )
}

/** Families the school has not reached yet: tell the next contact, or record a call. */
function ReachCard({ registerId, learners, onDone }: { registerId: string; learners: RegisterLearner[]; onDone: (next: RegisterState) => void }) {
  const [calling, setCalling] = useState<string | null>(null)
  const [note, setNote] = useState('')
  const save = useSave<RegisterState>()

  return (
    <div className="border-2 border-ink bg-marigold-wash px-4 pt-3.5 pb-4">
      <div className="mb-2.5 flex flex-wrap items-center gap-2">
        <span className="type-display text-[22px] leading-none">{t.notTold(learners.length)}</span>
        <Tag tone="attention">{copy.registers.bandAttention}</Tag>
      </div>
      <div className="grid gap-4">
        {learners.map((l) => {
          const a = l.absence as Absence
          const missed = a.told.find((d) => d.state === 'not_delivered' || d.state === 'not_reachable')

          return (
            <div key={l.id} className="grid gap-2">
              <p className="text-sm leading-normal">{a.next === null ? `${l.name}. ${t.nobodyLeft}` : t.notToldLine(l.name, missed?.to ?? a.told[0]?.to ?? '')}</p>
              {calling === l.id ? (
                <form
                  className="grid gap-2"
                  onSubmit={(e) => {
                    e.preventDefault()
                    void save.run(registersApi.called(registerId, l.id, note), (next) => {
                      setCalling(null)
                      setNote('')
                      onDone(next)
                      slips.done({ word: copy.slips.saved, title: t.callRecorded })
                    })
                  }}
                >
                  <Input label={t.calledNote} hint={t.calledNoteHint} value={note} onChange={(e) => setNote(e.target.value)} error={errorFor(save.errors, 'note')} maxLength={255} />
                  <div className="flex flex-wrap gap-2">
                    <Button type="submit" busy={save.working}>
                      {save.working ? s.saving : t.saveCall}
                    </Button>
                    <Button variant="secondary" onClick={() => setCalling(null)}>
                      {s.cancel}
                    </Button>
                  </div>
                </form>
              ) : (
                <div className="flex flex-wrap gap-2">
                  {a.next !== null && (
                    <Button
                      busy={save.working}
                      onClick={() =>
                        void save.run(registersApi.tellNext(registerId, l.id), (next) => {
                          onDone(next)
                          slips.done({ word: copy.slips.sent, title: t.toldNext })
                        })
                      }
                    >
                      {t.tellNext(a.next)}
                    </Button>
                  )}
                  <Button variant="secondary" className="bg-sheet" onClick={() => setCalling(l.id)}>
                    {t.called}
                  </Button>
                </div>
              )}
            </div>
          )
        })}
      </div>
      {save.failed !== null && <p className="mt-2 text-[13px] font-bold text-red-text">{save.failed}</p>}
      {errorFor(save.errors, 'learner_id') !== undefined && <p className="mt-2 text-[13px] font-bold text-red-text">{errorFor(save.errors, 'learner_id')}</p>}
      <p className="mt-3 text-[13px] text-text-muted">{t.followUpsKept}</p>
    </div>
  )
}
