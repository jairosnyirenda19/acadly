import { navigate, useLoad } from '@acadly/kernel'
import { Button, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { firstError } from '../programmes/api'
import { Attention, Sheet } from '../programmes/parts'
import { PageHead, Tag } from '../settings/parts'
import { Input, Select } from '../setup/parts'
import { day, RESULT_TONE, resultsApi, useResultsDivision, type ResultSheet, type SheetLine, type Sitting } from './api'
import { Figures, Ladder, NoResultsDivision, Panel, WithReason } from './parts'

const t = copy.results
const s = t.sheet
const m = t.moderation

const th = 'label-caps border-b-2 border-ink px-3 py-2 text-left text-text-muted'
const thEnd = `${th} text-right`
const td = 'border-b border-rule px-3 py-2 align-middle'
const tdEnd = `${td} text-right tabular-nums`

/** One course's result sheet (D-105): exam marks, moderation, the board's actions and the history. */
export function SheetScreen({ course, sitting }: { course: string; sitting: Sitting }) {
  const { division } = useResultsDivision()
  if (division === null) return <NoResultsDivision />

  return <Loaded key={`${division.id}${course}${sitting}`} division={division.id} course={course} sitting={sitting} />
}

/** Whether a server answer saved, telling the person either way. */
async function done(result: { kind: string; errors?: Record<string, string[]> }, title: string): Promise<boolean> {
  if (result.kind === 'saved') {
    slips.done({ word: copy.slips.saved, title })

    return true
  }
  slips.wrong({ word: t.notSaved, title: firstError(result, t.failed) })

  return false
}

function Loaded({ division, course, sitting }: { division: string; course: string; sitting: Sitting }) {
  const loaded = useLoad(`results-sheet:${division}:${course}:${sitting}`, () => resultsApi.sheet(division, course, sitting))
  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} rows={8} onRetry={loaded.reload} />

  return <SheetView key={loaded.data.sheet?.state ?? 'new'} division={division} course={course} sitting={sitting} data={loaded.data} reload={loaded.reload} />
}

function SheetView({ division, course, sitting, data, reload }: { division: string; course: string; sitting: Sitting; data: ResultSheet; reload: () => void }) {
  const state = data.sheet?.state ?? 'entered'
  const editable = data.teaches && state === 'entered'
  const [marks, setMarks] = useState<Record<string, { exam: string; absent: boolean }>>(() =>
    Object.fromEntries(data.lines.map((l) => [l.learner_id, { exam: l.exam === null ? '' : String(l.exam), absent: l.absence === 'absent' }])),
  )
  const [examOn, setExamOn] = useState(data.sheet?.exam_on ?? '')
  const [busy, setBusy] = useState(false)
  const missing = data.lines.filter((l) => l.result === 'missing').length
  const barred = data.lines.filter((l) => l.barred).length
  const absent = data.lines.filter((l) => l.absence !== null).length

  const save = async (): Promise<boolean> => {
    const list = data.lines
      .filter((l) => !l.barred && l.absence !== 'deferred')
      .map((l) => {
        const v = marks[l.learner_id] ?? { exam: '', absent: false }

        return { learner_id: l.learner_id, exam: v.absent || v.exam.trim() === '' ? null : Number(v.exam), absent: v.absent }
      })
      .filter((x) => x.absent || x.exam !== null)
    const result = await resultsApi.enter(division, course, sitting, examOn === '' ? null : examOn, list)
    if (result.kind === 'saved') return true
    slips.wrong({ word: t.notSaved, title: firstError(result, t.failed) })

    return false
  }

  return (
    <>
      <PageHead
        kicker={`${t.kicker} · ${data.course.period} · ${t.sittings[sitting]}`}
        title={s.title(data.course.code)}
        intro={`${data.course.title} · ${s.lede(data.share, data.lines.length)}`}
        actions={
          editable ? (
            <div className="flex flex-wrap gap-2">
              <Button
                variant="secondary"
                busy={busy}
                onClick={async () => {
                  setBusy(true)
                  if (await save()) {
                    slips.done({ word: copy.slips.saved, title: s.save })
                    reload()
                  }
                  setBusy(false)
                }}
              >
                {s.save}
              </Button>
              <Button
                busy={busy}
                onClick={async () => {
                  setBusy(true)
                  if ((await save()) && (await done(await resultsApi.submit(division, course, sitting), s.submitted))) reload()
                  setBusy(false)
                }}
              >
                {s.submit}
              </Button>
            </div>
          ) : undefined
        }
      />
      <div className="grid gap-4 px-[clamp(16px,3vw,40px)] pb-8">
        <nav className="flex flex-wrap gap-2" aria-label={t.sittings.main}>
          {(['main', 'supplementary'] as const).map((x) => (
            <button
              key={x}
              type="button"
              aria-current={x === sitting ? 'page' : undefined}
              onClick={() => navigate(x === 'main' ? `/results/c/${course}` : `/results/c/${course}?sitting=supplementary`)}
              className={`min-h-10 border-2 border-ink px-3 ${x === sitting ? 'bg-ink text-sheet' : 'bg-sheet'}`}
            >
              {t.sittings[x]}
            </button>
          ))}
        </nav>
        <Ladder state={state} />
        <Figures
          items={[
            { label: s.studentsKpi, value: String(data.lines.length) },
            { label: s.marksIn, value: s.marksInOf(data.lines.length - missing - barred, data.lines.length - barred), tone: missing > 0 ? 'attention' : 'sheet' },
            { label: s.barredKpi, value: String(barred), tone: barred > 0 ? 'wrong' : 'sheet' },
            { label: s.absentKpi, value: String(absent) },
          ]}
        />
        {editable && <Input label={s.examOn} type="date" value={examOn} onChange={(e) => setExamOn(e.target.value)} />}
        {data.unreleased > 0 && editable && <Attention>{s.unreleased(data.unreleased)}</Attention>}
        {sitting === 'supplementary' && <p className="text-[14px] text-text-muted">{s.supplementaryNote}</p>}
        <MarksTable data={data} editable={editable} marks={marks} setMarks={setMarks} sitting={sitting} />
        {editable && missing > 0 && (
          <Attention>
            <b>{s.toEnter(missing)}</b> {s.toEnterHelp}
          </Attention>
        )}
        {data.teaches && state !== 'entered' && state !== 'released' && <p className="text-[14px] text-text-muted">{s.locked}</p>}
        <p className="text-[14px] text-text-muted">{s.note}</p>
        {data.sheet !== null && data.can.moderate && state === 'submitted' && <Moderation division={division} sheet={data.sheet.id} data={data} reload={reload} />}
        {data.sheet !== null && data.can.board && <BoardActions division={division} sheet={data.sheet.id} data={data} reload={reload} />}
        {data.history.length > 0 && (
          <Panel title={s.history}>
            <ul className="grid gap-2">
              {data.history.map((h, i) => (
                <li key={i} className="text-[14px]">
                  <strong>{h.outcome === 'returned' ? m.sentBack : (t.states[h.outcome] ?? h.outcome)}</strong>
                  {` · ${s.by(h.by, day(h.at))}`}
                  {h.reason !== null && <span className="block text-text-muted">{h.reason}</span>}
                </li>
              ))}
            </ul>
          </Panel>
        )}
      </div>
    </>
  )
}

function MarksTable({
  data,
  editable,
  marks,
  setMarks,
  sitting,
}: {
  data: ResultSheet
  editable: boolean
  marks: Record<string, { exam: string; absent: boolean }>
  setMarks: (f: (m: Record<string, { exam: string; absent: boolean }>) => Record<string, { exam: string; absent: boolean }>) => void
  sitting: Sitting
}) {
  const part = (l: SheetLine) => (l.barred || l.exam === null || l.absence !== null ? '—' : ((l.exam * (100 - l.share)) / 100).toFixed(1))

  return (
    <Sheet label={s.title(data.course.code)} className="overflow-x-auto">
      <table className="w-full min-w-[880px] border-collapse">
        <thead>
          <tr>
            <th className={th}>{s.student}</th>
            <th className={th}>{s.number}</th>
            <th className={thEnd}>{s.coursework(data.share)}</th>
            <th className={thEnd}>{s.exam}</th>
            <th className={thEnd}>{s.examPart(data.share)}</th>
            <th className={thEnd}>{s.total}</th>
            <th className={th}>{s.grade}</th>
            <th className={th}>{s.result}</th>
          </tr>
        </thead>
        <tbody>
          {data.lines.map((l) => {
            const v = marks[l.learner_id] ?? { exam: '', absent: false }
            const canType = editable && !l.barred && l.absence !== 'deferred'

            return (
              <tr key={l.learner_id}>
                <td className={td}>
                  <strong>{l.name}</strong>
                  {sitting === 'supplementary' && <span className="block text-[13px] text-text-muted">{s.kinds[l.kind] ?? l.kind}</span>}
                  {l.lifted !== null && <span className="block text-[13px] text-text-muted">{`${s.lifted} · ${l.lifted}`}</span>}
                </td>
                <td className={`${td} font-mono text-[14px] whitespace-nowrap`}>{l.number}</td>
                <td className={tdEnd}>{l.ca.toFixed(1)}</td>
                <td className={tdEnd}>
                  {canType ? (
                    <span className="inline-flex items-center gap-3">
                      <input
                        aria-label={`${s.exam} · ${l.name}`}
                        inputMode="decimal"
                        disabled={v.absent}
                        value={v.absent ? '' : v.exam}
                        onChange={(e) => setMarks((x) => ({ ...x, [l.learner_id]: { exam: e.target.value, absent: false } }))}
                        className="h-10 w-20 border-b-2 border-ink bg-field px-2 text-right tabular-nums"
                      />
                      <label className="inline-flex items-center gap-1 text-[13px]">
                        <input type="checkbox" checked={v.absent} onChange={(e) => setMarks((x) => ({ ...x, [l.learner_id]: { exam: '', absent: e.target.checked } }))} />
                        {s.absent}
                      </label>
                    </span>
                  ) : l.barred ? (
                    '—'
                  ) : l.absence === 'absent' ? (
                    s.absent
                  ) : l.exam === null ? (
                    '—'
                  ) : (
                    l.exam
                  )}
                  {l.change !== null && <span className="block text-[12px] text-text-muted">{`${s.changed} · ${l.change.reason ?? ''}`}</span>}
                </td>
                <td className={tdEnd}>{part(l)}</td>
                <td className={`${tdEnd} font-extrabold`}>{l.mark ?? '—'}</td>
                <td className={td}>{l.grade ?? '—'}</td>
                <td className={td}>
                  <Tag tone={RESULT_TONE[l.result] ?? 'plain'}>{l.barred ? s.barred(l.rate) : (t.results[l.result] ?? l.result)}</Tag>
                </td>
              </tr>
            )
          })}
        </tbody>
      </table>
    </Sheet>
  )
}

/** Moderation (the approved board): the spread of grades, changes with a reason, and the decision. */
function Moderation({ division, sheet, data, reload }: { division: string; sheet: string; data: ResultSheet; reload: () => void }) {
  const [view, setView] = useState<'chart' | 'table'>('chart')
  const [mode, setMode] = useState<'one' | 'each'>('one')
  const [learner, setLearner] = useState('')
  const [mark, setMark] = useState('')
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const spread = data.spread
  const max = Math.max(1, ...spread.bands.map((b) => b.count))
  const fail = data.scale.bands[data.scale.bands.length - 1]?.grade
  const entered = data.lines.filter((l) => l.exam !== null && l.absence === null && !l.barred)

  const apply = async () => {
    const add = Number(mark)
    const changes =
      mode === 'one'
        ? [{ learner_id: learner, exam: Number(mark), reason }]
        : entered.map((l) => ({ learner_id: l.learner_id, exam: Math.max(0, Math.min(100, Math.round(((l.exam ?? 0) + add) * 10) / 10)), reason }))
    setBusy(true)
    if (await done(await resultsApi.moderate(division, sheet, changes), m.changesSaved)) {
      setMark('')
      setReason('')
      reload()
    }
    setBusy(false)
  }

  return (
    <Panel
      title={m.title}
      aside={
        <div className="flex gap-2">
          <Button
            onClick={async () => {
              if (await done(await resultsApi.decide(division, sheet, true, null), m.passed)) reload()
            }}
          >
            {m.pass}
          </Button>
          <WithReason
            label={m.sendBack}
            prompt={m.sendBackReason}
            onConfirm={async (r) => {
              const ok = await done(await resultsApi.decide(division, sheet, false, r), m.sentBack)
              if (ok) reload()

              return ok
            }}
          />
        </div>
      }
    >
      <div className="grid gap-5 lg:grid-cols-[3fr_2fr]">
        <section aria-label={m.spread}>
          <div className="flex flex-wrap items-baseline justify-between gap-2">
            <div>
              <h3 className="font-extrabold">{m.spreadLede(data.course.code, spread.sat)}</h3>
              <p className="text-[14px] text-text-muted">{m.spreadHelp}</p>
            </div>
            <div className="flex border-2 border-ink" role="group">
              {(['chart', 'table'] as const).map((x) => (
                <button key={x} type="button" aria-pressed={view === x} onClick={() => setView(x)} className={`min-h-9 px-3 ${view === x ? 'bg-ink text-sheet' : ''}`}>
                  {m[x]}
                </button>
              ))}
            </div>
          </div>
          {view === 'chart' ? (
            <div className="mt-4 flex h-48 items-end gap-2 border-b-2 border-ink" role="img" aria-label={spread.bands.map((b) => `${b.grade} ${b.count}`).join(', ')}>
              {spread.bands.map((b) => (
                <div key={b.grade} className="flex h-full flex-1 flex-col items-center justify-end gap-1" title={`${b.grade}: ${b.count}`}>
                  <span className="text-[13px] tabular-nums">{b.count}</span>
                  <div
                    className={`w-full ${b.grade === fail ? 'border-2 border-dashed border-red-pen bg-seq-2' : 'bg-seq-4'}`}
                    style={{ height: `${Math.max(2, (b.count / max) * 100)}%` }}
                  />
                </div>
              ))}
            </div>
          ) : (
            <table className="mt-4 w-full border-collapse">
              <thead>
                <tr>
                  <th className={th}>{s.grade}</th>
                  <th className={thEnd}>{m.count}</th>
                </tr>
              </thead>
              <tbody>
                {spread.bands.map((b) => (
                  <tr key={b.grade}>
                    <td className={td}>{b.grade}</td>
                    <td className={tdEnd}>{b.count}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
          {view === 'chart' && (
            <div className="mt-1 flex gap-2">
              {spread.bands.map((b) => (
                <span key={b.grade} className="flex-1 text-center text-[13px] font-extrabold">
                  {b.grade}
                </span>
              ))}
            </div>
          )}
          <dl className="mt-4 flex gap-6">
            <div>
              <dt className="label-caps text-text-muted">{m.average}</dt>
              <dd className="type-display text-[28px]">{spread.average ?? '—'}</dd>
            </div>
            <div>
              <dt className="label-caps text-text-muted">{m.passRate}</dt>
              <dd className="type-display text-[28px]">{spread.sat === 0 ? '—' : `${Math.round((spread.passed * 100) / spread.sat)}%`}</dd>
            </div>
          </dl>
        </section>
        <section aria-label={m.change} className="grid content-start gap-3">
          <h3 className="font-extrabold">{m.change}</h3>
          <div className="flex border-2 border-ink" role="group">
            {(['one', 'each'] as const).map((x) => (
              <button key={x} type="button" aria-pressed={mode === x} onClick={() => setMode(x)} className={`min-h-9 flex-1 px-3 ${mode === x ? 'bg-ink text-sheet' : ''}`}>
                {x === 'one' ? m.oneStudent : m.addToEach}
              </button>
            ))}
          </div>
          {mode === 'one' ? (
            <>
              <Select label={t.correct.student} value={learner} onChange={(e) => setLearner(e.target.value)}>
                <option value="">{t.correct.choose}</option>
                {entered.map((l) => (
                  <option key={l.learner_id} value={l.learner_id}>{`${l.name} · ${l.exam ?? ''}`}</option>
                ))}
              </Select>
              <Input label={m.newMark} inputMode="decimal" value={mark} onChange={(e) => setMark(e.target.value)} />
            </>
          ) : (
            <Input label={m.addToEach} hint={m.addHelp} inputMode="decimal" value={mark} onChange={(e) => setMark(e.target.value)} />
          )}
          <Input label={m.reason} hint={m.reasonHelp} required value={reason} maxLength={255} onChange={(e) => setReason(e.target.value)} />
          <Button busy={busy} disabled={reason.trim() === '' || mark.trim() === '' || (mode === 'one' && learner === '')} onClick={apply}>
            {m.apply}
          </Button>
        </section>
      </div>
    </Panel>
  )
}

/** The board's actions on one sheet: defer an exam, return for moderation, or change a released result. */
function BoardActions({ division, sheet, data, reload }: { division: string; sheet: string; data: ResultSheet; reload: () => void }) {
  const b = t.board
  const c = t.correct
  const state = data.sheet?.state ?? 'entered'
  const [learner, setLearner] = useState('')
  const [mark, setMark] = useState('')
  const absent = data.lines.filter((l) => l.absence === 'absent')

  if (state === 'released') {
    return (
      <Panel title={c.title}>
        <p className="mb-3 text-[14px] text-text-muted">{c.lede}</p>
        <div className="grid gap-3 sm:grid-cols-2">
          <Select label={c.student} value={learner} onChange={(e) => setLearner(e.target.value)}>
            <option value="">{c.choose}</option>
            {data.lines.map((l) => (
              <option key={l.learner_id} value={l.learner_id}>{`${l.name} · ${l.mark ?? '—'} ${l.grade ?? ''}`}</option>
            ))}
          </Select>
          <Input label={m.newMark} inputMode="decimal" value={mark} onChange={(e) => setMark(e.target.value)} />
        </div>
        {learner !== '' && mark.trim() !== '' && (
          <div className="mt-3">
            <WithReason
              variant="primary"
              label={c.save}
              prompt={m.reason}
              onConfirm={async (r) => {
                const ok = await done(await resultsApi.correct(division, sheet, learner, Number(mark), r), c.saved)
                if (ok) reload()

                return ok
              }}
            />
          </div>
        )}
      </Panel>
    )
  }

  if (state !== 'moderated' && (data.sitting !== 'main' || absent.length === 0)) return null

  return (
    <Panel
      title={b.title}
      aside={
        state === 'moderated' ? (
          <WithReason
            label={b.returnSheet}
            prompt={b.returnReason}
            onConfirm={async (r) => {
              const ok = await done(await resultsApi.returnSheet(division, sheet, r), b.returned)
              if (ok) reload()

              return ok
            }}
          />
        ) : undefined
      }
    >
      <h3 className="font-extrabold">{b.absences}</h3>
      {absent.length === 0 || data.sitting !== 'main' ? (
        <p className="text-[14px] text-text-muted">{b.absentNone}</p>
      ) : (
        <ul className="mt-2 grid gap-3">
          {absent.map((l) => (
            <li key={l.learner_id} className="flex flex-wrap items-center justify-between gap-3 border-b border-rule pb-3">
              <strong>{`${l.name} · ${data.course.code}`}</strong>
              <WithReason
                label={b.defer}
                prompt={b.deferReason}
                onConfirm={async (r) => {
                  const ok = await done(await resultsApi.defer(division, sheet, l.learner_id, r), b.deferred1)
                  if (ok) reload()

                  return ok
                }}
              />
            </li>
          ))}
        </ul>
      )}
    </Panel>
  )
}
