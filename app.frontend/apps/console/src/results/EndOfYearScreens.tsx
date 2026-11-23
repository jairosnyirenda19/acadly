import { navigate, useLoad } from '@acadly/kernel'
import { Button, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { firstError } from '../programmes/api'
import { Attention, Sheet } from '../programmes/parts'
import { PageHead, Tag } from '../settings/parts'
import { Input, Select } from '../setup/parts'
import { day, resultsApi, transcriptHref, useResultsDivision, type GraduationView, type ProgressionRow, type ProgressionView } from './api'
import { Figures, Ladder, NoResultsDivision, Panel, ResultsTabs, WithReason } from './parts'

const t = copy.results
const p = t.progression
const g = t.graduation
const tr = t.transcripts

const th = 'label-caps border-b-2 border-ink px-3 py-2 text-left text-text-muted'
const thEnd = `${th} text-right`
const td = 'border-b border-rule px-3 py-2 align-middle'
const tdEnd = `${td} text-right tabular-nums`
const gpa = (n: number | null) => (n === null ? '—' : n.toFixed(2))

async function done(result: { kind: string; errors?: Record<string, string[]> }, title: string): Promise<boolean> {
  if (result.kind === 'saved') {
    slips.done({ word: copy.slips.saved, title })

    return true
  }
  slips.wrong({ word: t.notSaved, title: firstError(result, t.failed) })

  return false
}

const decisionTone = (d: string | null) => (d === null ? 'attention' : d === 'proceed' || d === 'complete' ? 'done' : d === 'carry' ? 'attention' : 'wrong')

/** End of year (D-106): each programme and year, and where its progression list stands. */
export function ProgressionsScreen() {
  const { division } = useResultsDivision()
  if (division === null) return <NoResultsDivision />

  return <Progressions key={division.id} division={division.id} />
}

function Progressions({ division }: { division: string }) {
  const loaded = useLoad(`results-progressions:${division}`, () => resultsApi.progressions(division))
  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={p.title} labels={copy.loading} rows={5} onRetry={loaded.reload} />
  const data = loaded.data

  return (
    <>
      <PageHead kicker={p.kicker(data.period.name)} title={p.title} intro={p.lede} />
      <div className="grid gap-4 px-[clamp(16px,3vw,40px)] pb-8">
        <ResultsTabs current="progression" can={data.can} />
        <Sheet label={p.title}>
          {data.lists.length === 0 ? (
            <p className="px-4 py-4 text-text-muted">{p.none}</p>
          ) : (
            <ul>
              {data.lists.map((l) => {
                const href = `/results/progression/list?p=${l.programme_id}&y=${l.year}`

                return (
                  <li key={`${l.programme_id}${l.year}`} className="border-b border-rule last:border-b-0">
                    <a href={href} onClick={(e) => (e.preventDefault(), navigate(href))} className="flex min-h-14 flex-wrap items-center justify-between gap-3 px-4 py-3 text-ink no-underline hover:bg-paper">
                      <span className="flex flex-col">
                        <strong>{l.label}</strong>
                        <span className="text-[14px] text-text-muted">{`${l.name} · ${p.students(l.students)}`}</span>
                      </span>
                      <Tag tone={l.state === 'applied' ? 'done' : 'attention'}>{p.stateTags[l.state] ?? l.state}</Tag>
                    </a>
                  </li>
                )
              })}
            </ul>
          )}
        </Sheet>
      </div>
    </>
  )
}

/** One programme and year at the end of the year: every student's decision. */
export function ProgressionListScreen({ programme, year }: { programme: string; year: number }) {
  const { division } = useResultsDivision()
  if (division === null) return <NoResultsDivision />

  return <ProgressionList key={`${division.id}${programme}${year}`} division={division.id} programme={programme} year={year} />
}

function ProgressionList({ division, programme, year }: { division: string; programme: string; year: number }) {
  const loaded = useLoad(`results-progression:${division}:${programme}:${year}`, () => resultsApi.progression(division, programme, year))
  const [busy, setBusy] = useState(false)
  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={p.title} labels={copy.loading} rows={8} onRetry={loaded.reload} />
  const data = loaded.data
  const state = data.list?.state ?? 'worked_out'
  const open = state === 'worked_out' && data.can.board

  return (
    <>
      <PageHead
        kicker={p.kicker(data.period)}
        title={p.cohort(data.programme.code, data.year)}
        intro={`${data.programme.name} · ${p.students(data.rows.length)}`}
        actions={
          <div className="flex flex-wrap gap-2">
            {open && (
              <Button
                busy={busy}
                disabled={data.summary.open > 0}
                onClick={async () => {
                  setBusy(true)
                  if (await done(await resultsApi.decideProgression(division, programme, year), p.decided)) loaded.reload()
                  setBusy(false)
                }}
              >
                {p.decide}
              </Button>
            )}
            {state === 'decided' && data.list !== null && data.can.release && (
              <Button
                busy={busy}
                onClick={async () => {
                  setBusy(true)
                  const r = await resultsApi.applyProgression(division, data.list?.id ?? '')
                  setBusy(false)
                  if (await done(r, r.kind === 'saved' ? p.applied((r.data as { applied: number }).applied) : '')) loaded.reload()
                }}
              >
                {p.apply}
              </Button>
            )}
            {state === 'decided' && data.list !== null && data.can.board && (
              <WithReason
                label={p.reopen}
                prompt={p.reopenReason}
                onConfirm={async (reason) => {
                  const ok = await done(await resultsApi.reopen(division, data.list?.id ?? '', reason), p.reopened)
                  if (ok) loaded.reload()

                  return ok
                }}
              />
            )}
          </div>
        }
      />
      <div className="grid gap-4 px-[clamp(16px,3vw,40px)] pb-8">
        <Ladder state={state} steps={['worked_out', 'decided', 'applied']} labels={p.states} />
        <Figures
          items={[
            { label: p.proceed, value: String(data.summary.proceed) },
            { label: p.carry, value: String(data.summary.carry) },
            { label: p.repeat, value: String(data.summary.repeat + data.summary.discontinue) },
            { label: p.open, value: String(data.summary.open), tone: data.summary.open > 0 ? 'attention' : 'sheet' },
          ]}
        />
        {open && data.summary.open > 0 && <Attention>{p.openNote(data.summary.open)}</Attention>}
        <Sheet label={p.title} className="overflow-x-auto">
          <table className="w-full min-w-[980px] border-collapse">
            <thead>
              <tr>
                <th className={th}>{p.student}</th>
                <th className={th}>{p.number}</th>
                <th className={thEnd}>{p.cgpa}</th>
                <th className={thEnd}>{p.credits}</th>
                <th className={th}>{p.standing}</th>
                <th className={th}>{p.decision}</th>
                <th className={th}>{p.why}</th>
              </tr>
            </thead>
            <tbody>
              {data.rows.map((r) => (
                <ProgressionLine key={r.learner_id} division={division} data={data} row={r} open={open} reload={loaded.reload} />
              ))}
            </tbody>
          </table>
        </Sheet>
        <p className="text-[14px] text-text-muted">{p.rules(data.rules.good_cgpa.toFixed(2), data.rules.carry_credits, data.rules.max_attempts)}</p>
        <Panel title={p.afterTitle}>
          <p className="text-[14px]">{p.after}</p>
        </Panel>
        <p className="text-[14px] text-text-muted">{p.needs}</p>
      </div>
    </>
  )
}

function ProgressionLine({ division, data, row, open, reload }: { division: string; data: ProgressionView; row: ProgressionRow; open: boolean; reload: () => void }) {
  const [editing, setEditing] = useState(false)
  const [decision, setDecision] = useState(row.decision ?? (row.fallback === null ? 'repeat_year' : 'continue'))
  const [reason, setReason] = useState('')
  const options = ['proceed', 'carry', 'repeat_year', 'discontinue', ...(data.year >= data.programme.years ? ['complete'] : []), ...(row.fallback !== null ? ['continue'] : [])]

  return (
    <tr>
      <td className={td}>
        <strong>{row.name}</strong>
      </td>
      <td className={`${td} font-mono text-[14px] whitespace-nowrap`}>{row.number}</td>
      <td className={tdEnd}>{gpa(row.cgpa)}</td>
      <td className={tdEnd}>{p.creditsOf(row.passed, row.credits)}</td>
      <td className={td}>{row.standing === null ? '—' : <Tag tone={row.standing === 'good' ? 'done' : 'attention'}>{t.standings[row.standing] ?? row.standing}</Tag>}</td>
      <td className={td}>
        {editing ? (
          <div className="grid min-w-[240px] gap-2">
            <Select label={p.chooseLabel} value={decision} onChange={(e) => setDecision(e.target.value)}>
              {options.map((o) => (
                <option key={o} value={o}>
                  {t.decisions[o] ?? o}
                </option>
              ))}
            </Select>
            <Input label={p.reason} hint={p.reasonHelp} required value={reason} maxLength={255} onChange={(e) => setReason(e.target.value)} />
            <div className="flex gap-2">
              <Button
                disabled={reason.trim() === ''}
                onClick={async () => {
                  if (await done(await resultsApi.choose(division, { programme_id: data.programme.id, year: data.year, learner_id: row.learner_id, decision, reason: reason.trim() }), p.choiceSaved)) {
                    setEditing(false)
                    reload()
                  }
                }}
              >
                {p.saveChoice}
              </Button>
              <Button variant="secondary" onClick={() => setEditing(false)}>
                {p.cancel}
              </Button>
            </div>
          </div>
        ) : (
          <div className="flex flex-wrap items-center gap-2">
            <Tag tone={decisionTone(row.decision)}>{row.decision === null ? p.toDecide : (t.decisionTags[row.decision] ?? row.decision)}</Tag>
            {open && (
              <button type="button" className="font-extrabold text-ink underline" onClick={() => setEditing(true)}>
                {p.change}
              </button>
            )}
          </div>
        )}
      </td>
      <td className={`${td} text-[14px] text-text-muted`}>
        {row.why}
        {row.choice !== null && <span className="block">{p.chosen(row.choice.by, row.choice.reason)}</span>}
      </td>
    </tr>
  )
}

/** Graduation (D-106): each programme's graduation list. */
export function GraduationsScreen() {
  const { division } = useResultsDivision()
  if (division === null) return <NoResultsDivision />

  return <Graduations key={division.id} division={division.id} />
}

function Graduations({ division }: { division: string }) {
  const loaded = useLoad(`results-graduations:${division}`, () => resultsApi.graduations(division))
  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={g.title} labels={copy.loading} rows={5} onRetry={loaded.reload} />
  const data = loaded.data

  return (
    <>
      <PageHead kicker={g.kicker(data.period)} title={g.title} intro={g.lede} />
      <div className="grid gap-4 px-[clamp(16px,3vw,40px)] pb-8">
        <Sheet label={g.title}>
          {data.lists.length === 0 ? (
            <p className="px-4 py-4 text-text-muted">{g.none}</p>
          ) : (
            <ul>
              {data.lists.map((l) => {
                const href = `/results/graduation/list?p=${l.programme_id}`

                return (
                  <li key={l.programme_id} className="border-b border-rule last:border-b-0">
                    <a href={href} onClick={(e) => (e.preventDefault(), navigate(href))} className="flex min-h-14 flex-wrap items-center justify-between gap-3 px-4 py-3 text-ink no-underline hover:bg-paper">
                      <span className="flex flex-col">
                        <strong>{`${l.code} · ${l.name}`}</strong>
                        <span className="text-[14px] text-text-muted">{[g.students(l.students), l.graduation_on === null ? '' : day(l.graduation_on)].filter((x) => x !== '').join(' · ')}</span>
                      </span>
                      <Tag tone={l.state === 'conferred' ? 'done' : 'attention'}>{g.stateTags[l.state] ?? l.state}</Tag>
                    </a>
                  </li>
                )
              })}
            </ul>
          )}
        </Sheet>
      </div>
    </>
  )
}

/** One programme's graduation list: who completed, the class, the certificate, and the steps to conferral. */
export function GraduationListScreen({ programme }: { programme: string }) {
  const { division } = useResultsDivision()
  if (division === null) return <NoResultsDivision />

  return <GraduationList key={`${division.id}${programme}`} division={division.id} programme={programme} />
}

function GraduationList({ division, programme }: { division: string; programme: string }) {
  const loaded = useLoad(`results-graduation:${division}:${programme}`, () => resultsApi.graduation(division, programme))
  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={g.title} labels={copy.loading} rows={8} onRetry={loaded.reload} />

  return <GraduationView key={loaded.data.list?.state ?? 'new'} division={division} data={loaded.data} reload={loaded.reload} />
}

function GraduationView({ division, data, reload }: { division: string; data: GraduationView; reload: () => void }) {
  const state = data.list?.state ?? 'worked_out'
  const [dayValue, setDay] = useState(data.list?.graduation_on ?? '')
  const [busy, setBusy] = useState(false)
  const id = data.list?.id ?? ''
  const act = async (run: () => Promise<{ kind: string; errors?: Record<string, string[]> }>, title: string) => {
    setBusy(true)
    if (await done(await run(), title)) reload()
    setBusy(false)
  }

  return (
    <>
      <PageHead
        kicker={g.title}
        title={`${data.programme.code} · ${data.programme.name}`}
        intro={g.students(data.rows.length)}
        actions={
          <div className="flex flex-wrap gap-2">
            {state === 'worked_out' && data.can.board && (
              <Button busy={busy} onClick={() => act(() => resultsApi.approveAwards(division, data.programme.id), g.approved)}>
                {g.approve}
              </Button>
            )}
            {state === 'approved' && data.can.award && (
              <>
                <Button busy={busy} onClick={() => act(() => resultsApi.confirmAwards(division, id, true, null), g.confirmed)}>
                  {g.confirm}
                </Button>
                <WithReason
                  label={g.returnList}
                  prompt={g.returnReason}
                  onConfirm={async (reason) => {
                    const ok = await done(await resultsApi.confirmAwards(division, id, false, reason), g.returned)
                    if (ok) reload()

                    return ok
                  }}
                />
              </>
            )}
            {state === 'confirmed' && data.can.release && (
              <Button busy={busy} disabled={data.list?.graduation_on === null} onClick={async () => {
                setBusy(true)
                const r = await resultsApi.confer(division, id)
                setBusy(false)
                if (await done(r, r.kind === 'saved' ? g.conferred((r.data as { conferred: number }).conferred) : '')) reload()
              }}>
                {g.confer}
              </Button>
            )}
          </div>
        }
      />
      <div className="grid gap-4 px-[clamp(16px,3vw,40px)] pb-8">
        <Ladder state={state} steps={['worked_out', 'approved', 'confirmed', 'conferred']} labels={g.states} />
        <Figures
          items={[
            { label: g.completed, value: String(data.summary.complete) },
            ...data.summary.classes.slice(0, 2).map((c) => ({ label: c.name, value: String(c.count) })),
            { label: g.notComplete, value: String(data.summary.not_complete), tone: data.summary.not_complete > 0 ? ('attention' as const) : ('sheet' as const) },
          ]}
        />
        <Sheet label={g.title} className="overflow-x-auto">
          <table className="w-full min-w-[900px] border-collapse">
            <thead>
              <tr>
                <th className={th}>{g.student}</th>
                <th className={th}>{g.number}</th>
                <th className={thEnd}>{g.cgpa}</th>
                <th className={thEnd}>{g.credits}</th>
                <th className={th}>{g.class}</th>
                <th className={th}>{g.certificate}</th>
                <th className={th}>{g.state}</th>
              </tr>
            </thead>
            <tbody>
              {data.rows.map((r) => (
                <tr key={r.learner_id}>
                  <td className={td}>
                    <strong>{r.name}</strong>
                  </td>
                  <td className={`${td} font-mono text-[14px] whitespace-nowrap`}>{r.number}</td>
                  <td className={tdEnd}>{gpa(r.cgpa)}</td>
                  <td className={tdEnd}>{g.creditsOf(r.credits, data.programme.credits)}</td>
                  <td className={td}>{r.class === null ? '—' : <strong>{r.class}</strong>}</td>
                  <td className={td}>{r.complete ? <Tag tone={r.certificate === 'held' ? 'attention' : 'done'}>{r.certificate === 'held' ? g.held : g.cleared}</Tag> : '—'}</td>
                  <td className={td}>
                    {r.complete ? <Tag tone="done">{g.completeTag}</Tag> : <Tag tone="wrong">{r.missing.length > 0 ? g.missing(r.missing.slice(0, 3).join(', '), r.missing.length - 3) : g.underPass}</Tag>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </Sheet>
        <p className="text-[14px] text-text-muted">{g.ranges(data.classes.map((c) => `${c.name} ${c.from.toFixed(2)}`).join(', '))}</p>
        {data.list !== null && state !== 'conferred' && data.can.release && (
          <Panel title={g.day}>
            <div className="flex flex-wrap items-end gap-3">
              <Input label={g.day} type="date" value={dayValue} onChange={(e) => setDay(e.target.value)} />
              <Button variant="secondary" disabled={dayValue === ''} onClick={() => act(() => resultsApi.graduationDay(division, id, dayValue), g.daySaved)}>
                {g.saveDay}
              </Button>
            </div>
          </Panel>
        )}
        <Panel title={g.afterTitle}>
          <p className="text-[14px]">{g.after}</p>
        </Panel>
        <p className="text-[14px] text-text-muted">{g.needs}</p>
      </div>
    </>
  )
}

/** Transcripts (D-106): requests and the official copies issued. */
export function TranscriptsScreen() {
  const { division } = useResultsDivision()
  if (division === null) return <NoResultsDivision />

  return <Transcripts key={division.id} division={division.id} />
}

function Transcripts({ division }: { division: string }) {
  const loaded = useLoad(`results-transcripts:${division}`, () => resultsApi.transcripts(division))
  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={tr.title} labels={copy.loading} rows={6} onRetry={loaded.reload} />
  const data = loaded.data
  const issue = async (learner: string, request: string | null) => {
    const r = await resultsApi.issue(division, learner, request)
    if (await done(r, tr.issued)) {
      if (r.kind === 'saved') window.open(transcriptHref(division, (r.data as { id: string }).id), '_blank', 'noopener')
      loaded.reload()
    }
  }

  return (
    <>
      <PageHead kicker={t.kicker} title={tr.title} intro={tr.lede} />
      <div className="grid gap-4 px-[clamp(16px,3vw,40px)] pb-8">
        <Sheet label={tr.requests} className="overflow-x-auto">
          <h2 className="label-caps px-4 pt-3">{tr.requests}</h2>
          {data.requests.length === 0 ? (
            <p className="px-4 py-4 text-text-muted">{tr.noRequests}</p>
          ) : (
            <table className="w-full min-w-[820px] border-collapse">
              <thead>
                <tr>
                  <th className={th}>{tr.student}</th>
                  <th className={th}>{tr.number}</th>
                  <th className={th}>{tr.for}</th>
                  <th className={th}>{tr.asked}</th>
                  <th className={th}>{tr.state}</th>
                </tr>
              </thead>
              <tbody>
                {data.requests.map((r) => (
                  <tr key={r.id}>
                    <td className={td}>
                      <strong>{r.name}</strong>
                    </td>
                    <td className={`${td} font-mono text-[14px] whitespace-nowrap`}>{r.number}</td>
                    <td className={td}>{r.for_kind === 'organisation' ? [r.organisation, r.email].filter((x) => x !== null).join(' · ') : tr.self}</td>
                    <td className={td}>{day(r.at)}</td>
                    <td className={td}>
                      {r.issued !== null ? (
                        <a href={transcriptHref(division, r.issued.id)} target="_blank" rel="noopener">
                          {tr.issuedTag(r.issued.reference)}
                        </a>
                      ) : (
                        <span className="flex flex-wrap items-center gap-3">
                          <Tag tone="attention">{tr.waiting}</Tag>
                          {data.can_issue && (
                            <Button variant="secondary" onClick={() => issue(r.learner_id, r.id)}>
                              {tr.issue}
                            </Button>
                          )}
                        </span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Sheet>
        <Panel title={tr.issuedTitle}>
          {data.issued.length === 0 ? (
            <p className="text-[14px] text-text-muted">{tr.noIssued}</p>
          ) : (
            <ul className="grid gap-2">
              {data.issued.map((i) => (
                <li key={i.id} className="flex flex-wrap justify-between gap-3 text-[14px]">
                  <span>
                    <strong>{`${i.name} · ${i.reference}`}</strong> <span className="text-text-muted">{tr.byWhen(i.by, day(i.at))}</span>
                  </span>
                  <a href={transcriptHref(division, i.id)} target="_blank" rel="noopener">
                    {tr.open}
                  </a>
                </li>
              ))}
            </ul>
          )}
        </Panel>
        <p className="text-[14px] text-text-muted">{tr.copyNote}</p>
        <p className="text-[14px] text-text-muted">{tr.needs}</p>
      </div>
    </>
  )
}
