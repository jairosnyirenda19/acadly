import { api, navigate, useLoad, useLocation, type paths } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { read, settle } from '../setup/api'
import { Attention, Total, TotalsBand } from '../setup/parts'
import { PageHead, SmallButton, Tag } from '../settings/parts'

const t = copy.yearEnd

type Index = paths['/v1/year-end']['get']['responses'][200]['content']['application/json']
type Run = paths['/v1/year-end/{division}']['get']['responses'][200]['content']['application/json']
type Decision = Run['decisions'][number]
type Target = Run['targets'][number]

const STATE_TONE: Record<string, 'done' | 'attention' | 'plain'> = { decided: 'done', effective: 'done', awaiting_intake: 'attention', cancelled: 'plain' }
const NEEDS_REASON = ['repeated', 'left']
const NEEDS_CLASS = ['promoted', 'repeated', 'progressed']

/**
 * The year-end run (the approved design, D-088): every learner of a level
 * group gets an outcome for next year; the office changes the exceptions,
 * commits once the checks pass, and the next group confirms its intake.
 */
export function YearEndScreen() {
  const { query } = useLocation()
  const index = useLoad('year-end', () => read(api.GET('/v1/year-end')))

  if (index.data === undefined) return <Waiting phase={index.phase} what={t.title} labels={copy.loading} rows={8} onRetry={index.reload} />

  const groups = index.data.groups
  const chosen = groups.find((g) => g.id === query.get('group')) ?? groups[0]

  return (
    <div className="flex flex-1 flex-col bg-paper">
      <nav aria-label={t.groups} className="flex flex-wrap gap-2 px-[clamp(16px,3vw,40px)] pt-5">
        {groups.map((g) => (
          <a
            key={g.id}
            href={`/learners/year-end?group=${g.id}`}
            aria-current={g.id === chosen?.id ? 'page' : undefined}
            onClick={(e) => {
              e.preventDefault()
              navigate(`/learners/year-end?group=${g.id}`)
            }}
            className={`flex min-h-10 items-center gap-2 border-2 border-ink px-3 text-sm font-extrabold no-underline ${g.id === chosen?.id ? 'bg-ink text-sheet' : 'text-ink'}`}
          >
            {g.name}
            {g.intake > 0 && <span className="bg-marigold px-1.5 text-ink">{t.intakeCount(g.intake)}</span>}
          </a>
        ))}
      </nav>
      {chosen !== undefined && <Group key={chosen.id} index={index.data} groupId={chosen.id} onChanged={index.reload} />}
    </div>
  )
}

function Group({ index, groupId, onChanged }: { index: Index; groupId: string; onChanged: () => void }) {
  const loaded = useLoad(`year-end:${groupId}`, () => read(api.GET('/v1/year-end/{division}', { params: { path: { division: groupId } } })))
  const [problem, setProblem] = useState<string | null>(null)
  const [working, setWorking] = useState(false)
  const [confirming, setConfirming] = useState(false)
  const [classFilter, setClassFilter] = useState<string>('')

  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} rows={8} onRetry={loaded.reload} />

  const run = loaded.data
  const done = (next: Run) => {
    loaded.set(next)
    onChanged()
  }
  const act = async (request: Promise<{ kind: string; data?: unknown; errors?: Record<string, string[]> }>, after?: string) => {
    setWorking(true)
    setProblem(null)
    const result = await request
    setWorking(false)

    if (result.kind === 'saved') {
      done(result.data as Run)
      if (after !== undefined) slips.done({ word: copy.slips.saved, title: after })

      return true
    }

    setProblem(result.kind === 'invalid' ? (Object.values(result.errors ?? {})[0]?.[0] ?? copy.settings.failed) : copy.settings.failed)

    return false
  }

  const head = (
    <PageHead
      kicker={t.kicker(run.division.name)}
      title={t.title}
      intro={index.to_year !== null ? t.intro(run.division.name, index.to_year.name) : t.introNoYear}
      actions={
        run.run !== null && run.totals.to_decide > 0 ? (
          <Button busy={working} onClick={() => setConfirming(true)}>
            {t.commit(run.division.name)}
          </Button>
        ) : undefined
      }
    />
  )

  if (run.to_year === null) {
    return (
      <>
        {head}
        <div className="px-[clamp(16px,3vw,40px)]">
          <Attention title={t.noNextYear}>
            <a href="/settings/next-year" onClick={(e) => (e.preventDefault(), navigate('/settings/next-year'))} className="font-bold text-ink underline">
              {t.setUpNextYear}
            </a>
          </Attention>
        </div>
        <Intake run={run} working={working} act={act} />
      </>
    )
  }

  if (run.run === null) {
    return (
      <>
        {head}
        <div className="grid gap-3 px-[clamp(16px,3vw,40px)]">
          {problem !== null && <Notice tone="wrong" title={problem} />}
          <p className="max-w-[680px] text-[15px] leading-normal">{t.openNote(run.division.name, run.to_year.name)}</p>
          <div>
            <Button busy={working} onClick={() => void act(settle(api.POST('/v1/year-end/{division}/open', { params: { path: { division: groupId } } })))}>
              {t.open(run.division.name)}
            </Button>
          </div>
        </div>
        <Intake run={run} working={working} act={act} />
      </>
    )
  }

  const classes = [...new Map(run.decisions.map((d) => [d.class_id, d.class])).entries()]
  const shown = run.decisions.filter((d) => classFilter === '' || d.class_id === classFilter)

  return (
    <>
      {head}
      {run.run.state === 'committed' && run.run.committed_at !== null && (
        <div className="px-[clamp(16px,3vw,40px)] pb-3">
          <div role="status" className="flex items-start gap-3 border-2 border-teal bg-sheet px-4 py-3">
            <span aria-hidden="true" className="grid size-7 shrink-0 place-items-center bg-teal font-extrabold text-sheet">
              ✓
            </span>
            <span className="text-[15px] leading-snug">
              <strong className="block">{t.committed(run.run.committed_by ?? '', new Date(run.run.committed_at).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }))}</strong>
              {t.takesEffect(run.to_year.starts_on)}
            </span>
          </div>
        </div>
      )}
      {confirming && (
        <div className="px-[clamp(16px,3vw,40px)] pb-3">
          <div className="border-2 border-l-[6px] border-ink border-l-marigold bg-sheet px-4 py-3">
            <p className="mb-2.5 text-[15px] leading-snug font-bold">{t.confirmCommit(run.totals.to_decide, run.to_year.starts_on)}</p>
            <div className="flex flex-wrap gap-2.5">
              <Button variant="secondary" onClick={() => setConfirming(false)}>
                {t.notYet}
              </Button>
              <Button
                busy={working}
                onClick={() =>
                  void act(settle(api.POST('/v1/year-end/{division}/commit', { params: { path: { division: groupId } } })), t.committedSlip).then(() => setConfirming(false))
                }
              >
                {t.commitYes}
              </Button>
            </div>
          </div>
        </div>
      )}
      <TotalsBand compact>
        <Total compact label={t.learners} value={String(run.totals.learners)} note={t.learnersNote} tone="ink" />
        <Total compact label={t.outcome.promoted ?? ''} value={String(run.totals.promoted)} note={t.promotedNote} />
        <Total compact label={t.outcome.repeated ?? ''} value={String(run.totals.repeated)} note={t.repeatedNote} />
        <Total compact label={t.onward} value={String(run.totals.progressed + run.totals.completed)} note={t.onwardNote} />
        <Total compact label={t.outcome.left ?? ''} value={String(run.totals.left)} note={t.leftNote} />
        <Total compact label={t.toCheck} value={String(run.checks.length)} note={t.toCheckNote} tone={run.checks.length > 0 ? 'attention' : 'plain'} />
      </TotalsBand>
      <div className="flex flex-1 flex-wrap items-stretch">
        <section className="min-w-0 flex-[3_1_760px] bg-sheet px-[clamp(16px,3vw,32px)] pt-3.5 pb-6">
          {problem !== null && (
            <div className="mb-3">
              <Notice tone="wrong" title={problem} />
            </div>
          )}
          <div className="mb-3 flex flex-wrap gap-2">
            <FilterChip on={classFilter === ''} onClick={() => setClassFilter('')}>
              {t.allClasses}
            </FilterChip>
            {classes.map(([id, name]) => (
              <FilterChip key={id} on={classFilter === id} onClick={() => setClassFilter(id)}>
                {name}
              </FilterChip>
            ))}
          </div>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[860px] border-collapse">
              <thead>
                <tr className="label-caps border-b-2 border-ink text-left text-text-muted">
                  <th className="py-2 pr-3">{t.learner}</th>
                  {run.columns.map((c) => (
                    <th key={c.label} className="py-2 pr-3">
                      {c.label}
                    </th>
                  ))}
                  <th className="py-2 pr-3">{t.outcomeHead}</th>
                  <th className="py-2 pr-3">{t.classNext(run.to_year.name)}</th>
                  <th className="py-2" />
                </tr>
              </thead>
              <tbody>
                {shown.map((d) => (
                  <Row key={d.id} decision={d} run={run} working={working} act={act} />
                ))}
              </tbody>
            </table>
          </div>
          <p className="mt-3 text-[13px] text-text-muted">{t.neverAlone}</p>
        </section>
        <aside className="min-w-0 flex-[1_1_320px] border-ink bg-paper px-[clamp(16px,2vw,24px)] pt-4 pb-6 max-[1100px]:border-t-2 min-[1101px]:border-l-2">
          {run.checks.length > 0 ? (
            <div className="border-2 border-l-[6px] border-ink border-l-marigold bg-sheet px-4 py-3">
              <div className="label-caps text-text-muted">{t.beforeCommit}</div>
              <ul className="mt-2 list-disc pl-4 text-sm leading-normal">
                {run.checks.map((c) => (
                  <li key={c}>{c}</li>
                ))}
              </ul>
            </div>
          ) : (
            <div className="border-2 border-teal bg-sheet px-4 py-3 text-sm font-bold">✓ {t.noChecks}</div>
          )}
          <p className="mt-3 border border-rule bg-sheet px-3.5 py-3 text-[13px] leading-snug">{t.lastLevelNote}</p>
        </aside>
      </div>
      <Intake run={run} working={working} act={act} />
    </>
  )
}

function FilterChip({ on, onClick, children }: { on: boolean; onClick: () => void; children: string }) {
  return (
    <button type="button" aria-pressed={on} onClick={onClick} className={`min-h-9 border-2 border-ink px-3 text-sm font-extrabold ${on ? 'bg-ink text-sheet' : 'bg-sheet text-ink'}`}>
      {children}
    </button>
  )
}

/** The outcomes that fit a learner: at the last level of the group they go on, complete or leave; otherwise they are promoted. */
function outcomesFor(decision: Decision, run: Run): string[] {
  const codes = run.division.levels.map((l) => l.code)
  const last = codes.indexOf(decision.level) === codes.length - 1

  return last ? ['progressed', 'completed', 'repeated', 'left'] : ['promoted', 'repeated', 'left']
}

/** The classes next year an outcome may go to. */
function targetsFor(outcome: string, decision: Decision, run: Run): Target[] {
  const codes = run.division.levels.map((l) => l.code)
  const next = codes[codes.indexOf(decision.level) + 1]

  return run.targets.filter((c) =>
    outcome === 'promoted' ? c.division_id === run.division.id && c.level_code === next : outcome === 'repeated' ? c.division_id === run.division.id && c.level_code === decision.level : c.division_id !== run.division.id,
  )
}

function Row({
  decision,
  run,
  working,
  act,
}: {
  decision: Decision
  run: Run
  working: boolean
  act: (request: Promise<{ kind: string; data?: unknown; errors?: Record<string, string[]> }>, after?: string) => Promise<boolean>
}) {
  const [outcome, setOutcome] = useState(decision.outcome)
  const [toClass, setToClass] = useState(decision.to_class_id ?? '')
  const [reason, setReason] = useState(decision.reason ?? '')
  const open = decision.state === 'proposed'
  const dirty = outcome !== decision.outcome || toClass !== (decision.to_class_id ?? '') || reason !== (decision.reason ?? '')
  const targets = targetsFor(outcome, decision, run)

  const change = (value: string) => {
    setOutcome(value as Decision['outcome'])
    const fits = targetsFor(value, decision, run)
    setToClass(NEEDS_CLASS.includes(value) ? (fits.find((c) => c.id === toClass)?.id ?? fits[0]?.id ?? '') : '')
  }

  return (
    <tr className={`border-b border-rule align-top ${decision.changed || dirty ? 'bg-marigold-wash' : ''}`}>
      <td className="py-2 pr-3">
        <span className="block text-[15px] font-extrabold">{decision.learner}</span>
        <span className="block text-[13px] text-text-muted">{decision.class}</span>
      </td>
      {run.columns.map((c) => (
        <td key={c.label} className="ref py-2 pr-3 text-sm">
          {c.values[decision.learner_id] ?? '—'}
        </td>
      ))}
      <td className="py-2 pr-3">
        {open ? (
          <select value={outcome} onChange={(e) => change(e.target.value)} aria-label={t.outcomeOf(decision.learner)} className="h-9 border-2 border-ink bg-sheet px-2 text-sm font-extrabold">
            {outcomesFor(decision, run).map((o) => (
              <option key={o} value={o}>
                {t.outcome[o] ?? o}
              </option>
            ))}
          </select>
        ) : (
          <span className="text-sm font-extrabold">{t.outcome[decision.outcome] ?? decision.outcome}</span>
        )}
        {open && NEEDS_REASON.includes(outcome) && (
          <input
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            maxLength={255}
            placeholder={t.reasonHint}
            aria-label={t.reasonOf(decision.learner)}
            className="mt-1.5 block h-9 w-full border-2 border-ink bg-sheet px-2 text-sm"
          />
        )}
        {!open && decision.reason !== null && <span className="mt-0.5 block text-[13px] text-text-muted">{decision.reason}</span>}
      </td>
      <td className="py-2 pr-3">
        {open && NEEDS_CLASS.includes(outcome) ? (
          <select value={toClass} onChange={(e) => setToClass(e.target.value)} aria-label={t.classOf(decision.learner)} className="h-9 border-2 border-ink bg-sheet px-2 text-sm">
            {targets.length === 0 && <option value="">{t.noClass}</option>}
            {targets.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </select>
        ) : (
          <span className="text-sm">{decision.to_class ?? '—'}</span>
        )}
      </td>
      <td className="py-2 text-right">
        {open ? (
          dirty ? (
            <SmallButton
              disabled={working}
              onClick={() =>
                void act(settle(api.PUT('/v1/year-end/decisions/{id}', { params: { path: { id: decision.id } }, body: { outcome, to_class_id: toClass || null, reason } as never })))
              }
            >
              {t.save}
            </SmallButton>
          ) : decision.changed ? (
            <Tag tone="attention">! {t.changedTag}</Tag>
          ) : null
        ) : (
          <Tag tone={STATE_TONE[decision.state] ?? 'plain'}>{t.state[decision.state] ?? decision.state}</Tag>
        )}
      </td>
    </tr>
  )
}

/** The learners another level group hands on to this one: confirm in a class, or turn back with a reason. */
function Intake({
  run,
  working,
  act,
}: {
  run: Run
  working: boolean
  act: (request: Promise<{ kind: string; data?: unknown; errors?: Record<string, string[]> }>, after?: string) => Promise<boolean>
}) {
  if (run.intake.length === 0) return null

  const first = run.division.levels[0]?.code
  const classes = run.targets.filter((c) => c.division_id === run.division.id && c.level_code === first)

  return (
    <section className="border-t-2 border-ink bg-sheet px-[clamp(16px,3vw,32px)] pt-4 pb-6">
      <h2 className="type-display text-[28px] leading-[0.9]">{t.intakeTitle(run.division.levels[0]?.label ?? '')}</h2>
      <p className="mt-1 mb-3 max-w-[720px] text-sm leading-normal text-text-muted">{t.intakeNote}</p>
      {run.intake.map((d) => (
        <IntakeRow key={d.id} decision={d} classes={classes} working={working} act={act} />
      ))}
    </section>
  )
}

function IntakeRow({
  decision,
  classes,
  working,
  act,
}: {
  decision: Decision
  classes: Target[]
  working: boolean
  act: (request: Promise<{ kind: string; data?: unknown; errors?: Record<string, string[]> }>, after?: string) => Promise<boolean>
}) {
  const [toClass, setToClass] = useState(decision.to_class_id ?? classes[0]?.id ?? '')
  const [back, setBack] = useState(false)
  const [reason, setReason] = useState('')
  const send = (accept: boolean) =>
    void act(
      settle(api.POST('/v1/year-end/decisions/{id}/intake', { params: { path: { id: decision.id } }, body: { accept, to_class_id: toClass || null, reason } as never })),
      accept ? t.confirmed(decision.learner) : t.turnedBack(decision.learner),
    )

  return (
    <div className="flex flex-wrap items-center gap-3 border-b border-rule py-2.5">
      <span className="min-w-[200px] flex-1">
        <span className="block text-[15px] font-extrabold">{decision.learner}</span>
        <span className="block text-[13px] text-text-muted">{t.from(decision.class)}</span>
      </span>
      {back ? (
        <>
          <input value={reason} onChange={(e) => setReason(e.target.value)} maxLength={255} placeholder={t.turnBackHint} aria-label={t.reasonOf(decision.learner)} className="h-9 min-w-[260px] border-2 border-ink bg-sheet px-2 text-sm" />
          <SmallButton disabled={working || reason.trim() === ''} onClick={() => send(false)}>
            {t.turnBack}
          </SmallButton>
          <SmallButton onClick={() => setBack(false)}>{t.notYet}</SmallButton>
        </>
      ) : (
        <>
          <select value={toClass} onChange={(e) => setToClass(e.target.value)} aria-label={t.classOf(decision.learner)} className="h-9 border-2 border-ink bg-sheet px-2 text-sm">
            {classes.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </select>
          <SmallButton disabled={working} onClick={() => send(true)}>
            {t.confirm}
          </SmallButton>
          <SmallButton onClick={() => setBack(true)}>{t.turnBack}</SmallButton>
        </>
      )}
    </div>
  )
}
