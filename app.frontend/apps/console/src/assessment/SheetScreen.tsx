import { useLoad } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useEffect, useRef, useState } from 'react'
import { copy } from '../copy'
import { Input, Select } from '../setup/parts'
import { Tag } from '../settings/parts'
import { assessmentApi, markText, type MarkInput, type SheetLearner, type SheetState } from './api'
import { PaperKey, PaperPanel } from './papers'
import { WaitingFor } from '../approvals/WaitingFor'
import { BackLink, InLink, LockPanel, SmallBand, StateTag } from './parts'

const a = copy.assessment
const t = a.sheet
const s = copy.settings

type Reason = MarkInput['reason']
/** What the teacher typed for one learner and has not saved: the text of the mark, or a reason. */
type Entry = { text: string; reason: Reason }
type Draft = Record<string, Entry>

const draftKey = (id: string) => `acadly.marks.${id}`

function readDraft(id: string): Draft {
  try {
    const raw = window.localStorage.getItem(draftKey(id))
    const parsed: unknown = raw !== null ? JSON.parse(raw) : null

    return parsed !== null && typeof parsed === 'object' ? (parsed as Draft) : {}
  } catch {
    return {}
  }
}

function writeDraft(id: string, draft: Draft) {
  try {
    if (Object.keys(draft).length === 0) window.localStorage.removeItem(draftKey(id))
    else window.localStorage.setItem(draftKey(id), JSON.stringify(draft))
  } catch {
    // Without storage the marks stay on the page until saved.
  }
}

/** A typed mark read as a number: empty is none, a comma is a decimal point; anything else is not a number. */
function parse(text: string): number | null | 'bad' {
  const clean = text.trim().replace(',', '.')
  if (clean === '') return null
  const n = Number(clean)

  return Number.isFinite(n) && n >= 0 ? n : 'bad'
}

const saved = (l: SheetLearner): Entry => ({ text: markText(l.value), reason: l.reason })
const same = (x: Entry, y: Entry) => x.reason === y.reason && (x.reason !== null || parse(x.text) === parse(y.text))

/** One assessment's marks sheet (the approved phone design): the ladder, the numbers and a mark per learner. */
export function SheetScreen({ id }: { id: string }) {
  const loaded = useLoad(`assessment:sheet:${id}`, () => assessmentApi.sheet(id))
  const sheet = loaded.data

  if (sheet === undefined) return <Waiting phase={loaded.phase} what={a.marks.title} labels={copy.loading} rows={8} onRetry={loaded.reload} />

  return <Sheet key={sheet.assessment.id} sheet={sheet} onSheet={loaded.set} />
}

function Sheet({ sheet, onSheet }: { sheet: SheetState; onSheet: (next: SheetState) => void }) {
  const x = sheet.assessment
  const [draft, setDraft] = useState<Draft>(() => (sheet.can_enter ? readDraft(x.id) : {}))
  const [open, setOpen] = useState<string | null>(null)
  const [paperOpen, setPaperOpen] = useState<string | null>(null)
  const [working, setWorking] = useState<'save' | 'submit' | null>(null)
  const [failed, setFailed] = useState<string | null>(null)
  const inputs = useRef<Record<string, HTMLInputElement | null>>({})

  // Only what differs from the marks in force is a change.
  const changes = sheet.learners.filter((l) => draft[l.id] !== undefined && !same(draft[l.id] as Entry, saved(l)))
  useEffect(() => writeDraft(x.id, Object.fromEntries(changes.map((l) => [l.id, draft[l.id] as Entry]))), [x.id, changes, draft])

  useEffect(() => {
    if (changes.length === 0) return
    const warn = (e: BeforeUnloadEvent) => {
      e.preventDefault()
    }
    window.addEventListener('beforeunload', warn)

    return () => window.removeEventListener('beforeunload', warn)
  }, [changes.length])

  const entry = (l: SheetLearner): Entry => draft[l.id] ?? saved(l)
  const set = (l: SheetLearner, next: Entry) => setDraft((d) => ({ ...d, [l.id]: next }))

  const rows = sheet.learners.map((l) => {
    const e = entry(l)
    const value = e.reason === null ? parse(e.text) : null
    const bad = value === 'bad' || (typeof value === 'number' && value > x.out_of)
    const percent = typeof value === 'number' && !bad ? (value / x.out_of) * 100 : null

    return { l, e, value, bad, percent }
  })
  const withPaper = sheet.learners.filter((l) => l.paper.length > 0).length
  const done = rows.filter((r) => r.e.reason !== null || typeof r.value === 'number').length
  const left = rows.length - done
  const anyBad = rows.some((r) => r.bad)
  const percents = rows.map((r) => r.percent).filter((v): v is number => v !== null)
  const average = percents.length > 0 ? percents.reduce((sum, v) => sum + v, 0) / percents.length : null
  const below = percents.filter((v) => v < 50).length
  const editable = sheet.can_enter && (x.state === 'marking' || x.state === 'returned')
  const returned = x.state === 'returned' ? [...sheet.history].reverse().find((h) => h.reason !== null)?.reason ?? null : null

  const body = (): MarkInput[] =>
    changes.map((l) => {
      const e = draft[l.id] as Entry
      const v = e.reason === null ? parse(e.text) : null

      return { learner_id: l.id, value: typeof v === 'number' ? v : null, reason: e.reason }
    })

  const save = async (): Promise<boolean> => {
    if (changes.length === 0) return true
    setFailed(null)
    const result = await assessmentApi.save(x.id, body())
    if (result.kind === 'saved') {
      setDraft({})
      onSheet(result.data.sheet)

      return true
    }
    setFailed(result.kind === 'invalid' ? (Object.values(result.errors)[0]?.[0] ?? s.failed) : result.kind === 'refused' ? s.refused : s.failed)

    return false
  }

  const onSave = async () => {
    setWorking('save')
    if (await save()) slips.done({ word: copy.slips.saved, title: t.saved })
    setWorking(null)
  }

  const onSubmit = async () => {
    setWorking('submit')
    if (await save()) {
      const result = await assessmentApi.submit(x.id)
      if (result.kind === 'saved') {
        onSheet(result.data)
        slips.done({ word: result.data.assessment.state_label, title: t.submitted })
      } else setFailed(result.kind === 'invalid' ? (Object.values(result.errors)[0]?.[0] ?? s.failed) : result.kind === 'refused' ? s.refused : s.failed)
    }
    setWorking(null)
  }

  const next = (from: number) => {
    const after = rows.slice(from + 1).find((r) => r.e.reason === null)
    if (after !== undefined) inputs.current[after.l.id]?.focus()
  }

  const blocked = anyBad ? t.fix : left > 0 ? t.left(left) : null

  return (
    <>
      <div className="px-4 pt-1 pb-3 md:px-[clamp(16px,3vw,40px)]">
        <BackLink href="/marks">{t.back}</BackLink>
        <div className="label-caps text-text-muted">{t.head(x.subject, x.kind_label, a.day(x.held_on), x.out_of)}</div>
        <h1 className="type-display mt-1 mb-2.5 text-[clamp(44px,7vw,88px)] break-words">{x.class}</h1>
        <ol aria-label={t.ladderLabel} className="flex flex-wrap gap-x-4 gap-y-1.5">
          {sheet.ladder.map((step) => (
            <li key={step.key} aria-current={step.current ? 'step' : undefined} className={`inline-flex items-center gap-1.5 text-[13px] ${step.current ? 'font-extrabold' : step.done ? '' : 'text-text-muted'}`}>
              {step.done && !step.current ? (
                <span aria-hidden="true" className="grid size-5 place-items-center bg-teal text-xs font-extrabold text-sheet">
                  /
                </span>
              ) : step.current ? (
                <span aria-hidden="true" className="size-5 border-[3px] border-ink bg-teal-bright" />
              ) : (
                <span aria-hidden="true" className="size-5 border-2 border-dashed border-rule-dashed" />
              )}
              {step.label}
            </li>
          ))}
          <li>
            <StateTag state={x.state} label={x.state_label} />
          </li>
        </ol>
        {returned !== null && <p className="mt-2 border-l-4 border-red-pen bg-red-wash px-3 py-2 text-sm font-bold text-red-text">{t.returnedWhy(returned)}</p>}
        {!x.counts && <p className="mt-2 text-[13px] text-text-muted">{t.practice}</p>}
      </div>

      <SmallBand
        cells={[
          { label: t.bandMarked, value: a.fraction(done, rows.length), tone: 'ink' },
          { label: t.bandAverage, value: a.pct(average) },
          { label: t.bandBelow, value: String(below), tone: below > 0 ? 'attention' : 'plain' },
          ...(sheet.can_add_papers || withPaper > 0 ? [{ label: t.bandPapers, value: String(withPaper) }] : []),
        ]}
      />

      <div className="flex flex-1 flex-wrap items-stretch bg-sheet">
        <section aria-label={x.title} className="min-w-0 flex-[2_1_420px]">
          {editable && <p className="border-b border-rule bg-paper px-4 py-2 text-[13px] md:px-[clamp(16px,3vw,40px)]">{t.hint}</p>}
          {sheet.can_add_papers && <p className="border-b border-rule bg-paper px-4 py-2 text-[13px] text-text-muted md:px-[clamp(16px,3vw,40px)]">{t.papersHint}</p>}
          {!editable && x.state === 'submitted' && !sheet.can_lock && <p className="border-b border-rule bg-paper px-4 py-2 text-[13px] md:px-[clamp(16px,3vw,40px)]">{t.waiting}</p>}
          {x.state === 'locked' && <p className="border-b border-rule bg-paper px-4 py-2 text-[13px] md:px-[clamp(16px,3vw,40px)]">{t.lockedNote}</p>}

          <ol className="ledger [--margin:44px] md:mx-[clamp(16px,3vw,40px)]">
            {rows.map((r, i) => {
              const reasonLabel = r.e.reason !== null ? (sheet.reasons.find((x2) => x2.value === r.e.reason)?.label ?? r.e.reason) : null
              const line = r.bad
                ? r.value === 'bad'
                  ? t.notNumber
                  : t.tooHigh(x.out_of)
                : reasonLabel !== null
                  ? t.reasonLine(reasonLabel)
                  : r.percent !== null
                    ? t.percentGrade(a.pct(r.percent), draft[r.l.id] === undefined ? r.l.grade : null)
                    : t.notMarked
              const changed = changes.some((c) => c.id === r.l.id)

              return (
                <li key={r.l.id} className={`border-b border-rule ${r.bad ? 'bg-red-wash' : changed ? 'bg-marigold-wash' : ''}`}>
                  <div className="grid min-h-14 grid-cols-[44px_minmax(0,1fr)_auto] items-center gap-x-2.5 py-1.5 pr-3">
                    <span className="type-display text-center text-lg text-text-muted">{String(i + 1).padStart(2, '0')}</span>
                    <span className="min-w-0 pl-2">
                      <span className="block text-base font-extrabold break-words">{r.l.name}</span>
                      <span className={`block text-[13px] ${r.bad ? 'font-bold text-red-text' : 'text-text-muted'}`}>
                        {line}
                        {r.l.paper.length > 0 && ` · ${t.paperLine(r.l.paper.length)}`}
                        {r.l.corrected && (
                          <>
                            {' '}
                            <Tag tone="plain">{t.correctedTag}</Tag>
                          </>
                        )}
                      </span>
                    </span>
                    <span className="flex items-center gap-1.5">
                      {editable && r.e.reason === null ? (
                        <input
                          ref={(el) => {
                            inputs.current[r.l.id] = el
                          }}
                          inputMode="decimal"
                          enterKeyHint="next"
                          autoComplete="off"
                          aria-label={t.markFor(r.l.name)}
                          aria-invalid={r.bad}
                          value={r.e.text}
                          onChange={(ev) => set(r.l, { text: ev.target.value, reason: null })}
                          onKeyDown={(ev) => {
                            if (ev.key === 'Enter') {
                              ev.preventDefault()
                              next(i)
                            }
                          }}
                          className={`ref h-11 w-16 border-0 border-b-2 bg-field px-2 text-right text-lg text-ink outline-none focus:border-teal ${r.bad ? 'border-b-[3px] border-red-pen text-red-text' : 'border-ink'}`}
                        />
                      ) : (
                        <span className={`ref min-w-16 text-right text-lg font-bold ${reasonLabel !== null ? 'text-[13px]' : ''}`}>{reasonLabel ?? markText(r.l.value)}</span>
                      )}
                      <span className="ref w-11 text-[13px] text-text-muted">{a.outOf(x.out_of)}</span>
                      {(sheet.can_add_papers || r.l.paper.length > 0) && (
                        <PaperKey
                          name={r.l.name}
                          pages={r.l.paper.length}
                          open={paperOpen === r.l.id}
                          disabled={r.l.paper.length === 0 && (r.e.reason === 'absent' || r.l.reason === 'absent')}
                          onClick={() => setPaperOpen(paperOpen === r.l.id ? null : r.l.id)}
                        />
                      )}
                      {editable && (
                        <button
                          type="button"
                          aria-expanded={open === r.l.id}
                          aria-label={t.moreFor(r.l.name)}
                          onClick={() => setOpen(open === r.l.id ? null : r.l.id)}
                          className="h-11 border-2 border-ink px-2.5 text-[13px] font-extrabold hover:bg-field"
                        >
                          {t.more}
                        </button>
                      )}
                    </span>
                  </div>
                  {paperOpen === r.l.id && (
                    <PaperPanel
                      assessmentId={x.id}
                      learnerId={r.l.id}
                      pages={r.l.paper}
                      canAdd={sheet.can_add_papers}
                      onSheet={onSheet}
                      onClose={() => setPaperOpen(null)}
                      onNext={rows[i + 1] !== undefined ? () => setPaperOpen(rows[i + 1]?.l.id ?? null) : null}
                    />
                  )}
                  {open === r.l.id && editable && (
                    <div className="flex flex-wrap gap-2 pr-3 pb-2.5 pl-[56px]">
                      {sheet.reasons.map((reason) => (
                        <button
                          key={reason.value}
                          type="button"
                          aria-pressed={r.e.reason === reason.value}
                          onClick={() => {
                            set(r.l, { text: '', reason: reason.value as Reason })
                            setOpen(null)
                          }}
                          className={`h-11 border-2 border-ink px-3 text-sm font-extrabold ${r.e.reason === reason.value ? 'bg-ink text-sheet' : 'bg-sheet hover:bg-field'}`}
                        >
                          {reason.label}
                        </button>
                      ))}
                      {r.e.reason !== null && (
                        <button
                          type="button"
                          onClick={() => {
                            set(r.l, { text: '', reason: null })
                            setOpen(null)
                            window.requestAnimationFrame(() => inputs.current[r.l.id]?.focus())
                          }}
                          className="h-11 px-2 text-sm font-extrabold underline underline-offset-[3px]"
                        >
                          {t.enterMark}
                        </button>
                      )}
                    </div>
                  )}
                </li>
              )
            })}
          </ol>

          {sheet.can_correct && x.state === 'locked' && <CorrectForm sheet={sheet} onDone={onSheet} />}
          <div className="px-4 py-3 md:px-[clamp(16px,3vw,40px)]">
            <InLink href={`/marks/results?class_id=${x.class_id}`}>{t.results}</InLink>
          </div>
        </section>

        {(sheet.can_lock || sheet.can_return) && (
          <aside className="min-w-0 flex-[1_1_320px] border-ink bg-paper px-4 pt-4 pb-6 max-[767px]:border-t-2 md:border-l-2 md:px-6">
            <LockPanel sheet={sheet} onDone={onSheet} />
          </aside>
        )}
      </div>

      {editable && (
        <div className="sticky bottom-0 z-10 flex flex-col gap-2 border-t-2 border-ink bg-sheet px-4 py-2.5 md:px-[clamp(16px,3vw,40px)]">
          {failed !== null && <Notice tone="wrong" title={failed} />}
          {changes.length > 0 && <p className="text-[13px] font-bold">{t.unsaved(changes.length)}</p>}
          <div className="flex flex-wrap items-center gap-2">
            <Button variant="secondary" className="flex-1 md:flex-none" busy={working === 'save'} disabled={working !== null || changes.length === 0 || anyBad} onClick={() => void onSave()}>
              {working === 'save' ? t.saving : t.save}
            </Button>
            <Button className="h-12 flex-[1.4] md:flex-none" busy={working === 'submit'} disabled={working !== null || blocked !== null} onClick={() => void onSubmit()}>
              {working === 'submit' ? t.submitting : left > 0 ? t.submitLeft(left) : t.submit}
            </Button>
            {blocked !== null && <span className="basis-full text-[13px] text-text-muted md:basis-auto">{blocked}</span>}
          </div>
        </div>
      )}
    </>
  )
}

/** Correcting a locked mark: the learner, the new mark or a reason, and why. */
function CorrectForm({ sheet, onDone }: { sheet: SheetState; onDone: (next: SheetState) => void }) {
  const [open, setOpen] = useState(false)
  const [learner, setLearner] = useState('')
  const [value, setValue] = useState('')
  const [reason, setReason] = useState('')
  const [note, setNote] = useState('')
  const [failed, setFailed] = useState<string | null>(null)
  const [working, setWorking] = useState(false)

  const waiting = sheet.learners.filter((l) => l.correction !== null)

  if (!open) {
    return (
      <div className="grid gap-2 px-4 pt-3 md:px-[clamp(16px,3vw,40px)]">
        {waiting.map((l) =>
          l.correction === null ? null : (
            <WaitingFor
              key={l.id}
              title={t.correctionWaiting(l.name, l.correction.value !== null ? `${markText(l.correction.value)}/${sheet.assessment.out_of}` : (l.correction.reason ?? ''))}
              askedBy={l.correction.asked_by}
              waitingFor={l.correction.waiting_for}
              requestId={l.correction.request_id}
            >
              <span className="text-[13px]">“{l.correction.note}”</span>
            </WaitingFor>
          ),
        )}
        <span>
          <Button variant="secondary" onClick={() => setOpen(true)}>
            {t.correct}
          </Button>
        </span>
      </div>
    )
  }

  const parsed = parse(value)

  return (
    <form
      className="mx-4 mt-3 grid gap-3 border-2 border-ink bg-paper p-4 md:mx-[clamp(16px,3vw,40px)]"
      onSubmit={(e) => {
        e.preventDefault()
        setWorking(true)
        setFailed(null)
        void assessmentApi
          .correct(sheet.assessment.id, { learner_id: learner, value: reason === '' && typeof parsed === 'number' ? parsed : null, reason: reason === '' ? null : reason, note: note.trim() })
          .then((r) => {
            setWorking(false)
            if (r.kind === 'saved') {
              slips.done({ word: copy.slips.saved, title: sheet.correction_asks ? t.correctAsked : t.corrected })
              setOpen(false)
              onDone(r.data)
            } else setFailed(r.kind === 'invalid' ? (Object.values(r.errors)[0]?.[0] ?? s.failed) : r.kind === 'refused' ? s.refused : s.failed)
          })
      }}
    >
      <h2 className="type-display text-[22px] leading-none">{t.correct}</h2>
      {failed !== null && <Notice tone="wrong" title={failed} />}
      <Select label={t.correctLearner} required value={learner} onChange={(e) => setLearner(e.target.value)}>
        <option value="">{t.chooseLearner}</option>
        {sheet.learners.map((l) => (
          <option key={l.id} value={l.id}>
            {l.name}
          </option>
        ))}
      </Select>
      <div className="grid grid-cols-2 gap-3">
        <Input label={t.newMark} inputMode="decimal" value={value} disabled={reason !== ''} onChange={(e) => setValue(e.target.value)} />
        <Select label={t.orReason} value={reason} onChange={(e) => setReason(e.target.value)}>
          <option value="">{t.noReason}</option>
          {sheet.reasons.map((r) => (
            <option key={r.value} value={r.value}>
              {r.label}
            </option>
          ))}
        </Select>
      </div>
      <Input label={t.correctNote} required value={note} onChange={(e) => setNote(e.target.value)} maxLength={255} />
      {sheet.correction_asks && <p className="text-[13px] text-text-muted">{t.correctAsks}</p>}
      <div className="flex flex-wrap gap-2">
        <Button type="submit" busy={working} disabled={learner === '' || note.trim() === '' || (reason === '' && typeof parsed !== 'number')}>
          {sheet.correction_asks ? t.correctAsk : t.correctSave}
        </Button>
        <Button variant="secondary" onClick={() => setOpen(false)}>
          {s.cancel}
        </Button>
      </div>
    </form>
  )
}
