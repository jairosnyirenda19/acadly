import { useLoad } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useState, type ReactNode } from 'react'
import { copy } from '../copy'
import { money } from '../fees/api'
import { day } from '../settings/api'
import { Input } from '../setup/parts'
import { SmallButton, Tag } from '../settings/parts'
import { firstError } from './api'
import { Go, NoDivision, Sheet, td, th, useProgrammeDivision } from './parts'
import { RegTag } from './RegistrationDeskScreen'
import { registrationApi, slipHref, type RegAction, type RegistrationView } from './registrationApi'

const t = copy.programmes
const r = t.registration

/** One student's registration for a semester (D-098, the approved board). */
export function RegistrationScreen({ id }: { id: string }) {
  const { division } = useProgrammeDivision()

  if (division === null) return <NoDivision />

  return <Loaded division={division.id} id={id} />
}

function Loaded({ division, id }: { division: string; id: string }) {
  const loaded = useLoad(`programmes:registration:${division}:${id}`, () => registrationApi.registration(division, id))
  const [editing, setEditing] = useState(false)

  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={r.title} labels={copy.loading} rows={8} onRetry={loaded.reload} />

  const data = loaded.data
  const s = data.student
  const draft = data.state === 'draft'
  const canEdit = draft && data.can.register

  return (
    <div className="grid gap-0">
      <header className="grid gap-2.5 border-b border-rule bg-sheet px-[clamp(16px,3vw,40px)] pt-5 pb-4">
        <Go to="/programmes/registration" className="text-sm">
          {`${r.back} · ${data.period}`}
        </Go>
        <div className="flex flex-wrap items-end justify-between gap-4">
          <div>
            <h1 className="type-display text-[40px] leading-none">{s.name}</h1>
            <p className="mt-1.5 text-text-muted">
              {[
                s.code,
                `${s.programme.code} ${s.programme.name}`,
                `${t.students.curriculum} ${s.curriculum}`,
                `${t.students.year(s.year)}, ${data.period}`,
                t.students.modes[s.mode as 'full_time' | 'part_time'] ?? s.mode,
              ].join(' · ')}
            </p>
          </div>
          <span className="flex items-center gap-2">
            {data.late && <Tag tone="plain">{r.late}</Tag>}
            {data.version > 1 && <Tag tone="plain">{r.version(data.version)}</Tag>}
            <RegTag state={data.state} />
          </span>
        </div>
        <Steps data={data} />
      </header>

      <div className="grid items-start gap-6 px-[clamp(16px,3vw,40px)] py-5 xl:grid-cols-[minmax(0,1fr)_360px]">
        <div className="grid gap-3.5">
          {editing && canEdit ? (
            <CourseEditor
              division={division}
              data={data}
              onDone={(saved) => {
                setEditing(false)
                if (saved) loaded.reload()
              }}
            />
          ) : (
            <>
              <Sheet className="overflow-x-auto">
                <table className="w-full min-w-[640px] border-collapse text-[15px]">
                  <thead>
                    <tr>
                      <th className={th}>{r.columnsCourse.course}</th>
                      <th className={th}>{r.columnsCourse.kind}</th>
                      <th className={th}>{r.columnsCourse.group}</th>
                      <th className={th}>{r.columnsCourse.credits}</th>
                      <th className={th}>{r.columnsCourse.needs}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.courses.length === 0 && (
                      <tr>
                        <td colSpan={5} className={`${td} text-text-muted`}>
                          {r.noCourses}
                        </td>
                      </tr>
                    )}
                    {data.courses.map((c) => (
                      <tr key={c.id}>
                        <td className={td}>
                          <strong>{c.course.code}</strong> {c.course.title}
                        </td>
                        <td className={td}>{r.kinds[c.kind] ?? c.kind}</td>
                        <td className={td}>{c.group}</td>
                        <td className={td}>{c.credits}</td>
                        <td className={td}>{c.needs.length === 0 ? <span className="text-text-muted">{t.none}</span> : c.needs.join(', ')}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </Sheet>
              {canEdit && (
                <div>
                  <Button variant="secondary" onClick={() => setEditing(true)}>
                    {r.editCourses}
                  </Button>
                </div>
              )}
            </>
          )}
          <Load data={data} />
          <p className="text-sm text-text-muted">{r.loadNote}</p>
        </div>

        <aside className="grid gap-4">
          <Checks data={data} />
          <Fees division={division} data={data} onDone={loaded.reload} />
          <Actions division={division} data={data} onDone={loaded.reload} />
          <History data={data} />
        </aside>
      </div>
    </div>
  )
}

function Steps({ data }: { data: RegistrationView }) {
  const done = (n: number) => {
    const order = ['draft', 'submitted', 'approved', 'registered']
    const at = order.indexOf(data.state)
    if (n === 0) return at >= 1
    if (n === 1) return at >= 2
    if (n === 2) return at === 3 || (at === 2 ? data.fees.cleared : false)
    return at === 3
  }
  const labels = [r.steps.chosen, r.steps.approved, r.steps.fees, r.steps.registered]
  const waiting = data.state === 'approved' && !data.fees.cleared

  return (
    <ol aria-label={r.steps.registered} className="mt-2 grid list-none grid-cols-2 border border-rule p-0 lg:grid-cols-4">
      {labels.map((label, i) => {
        const attention = i === 2 && waiting
        return (
          <li key={label} className={`border-r border-b border-rule px-3.5 py-2.5 last:border-r-0 lg:border-b-0 ${done(i) ? 'bg-teal-wash' : attention ? 'bg-marigold-wash' : ''}`}>
            <span
              className={`label-caps ${done(i) ? 'text-teal' : attention ? 'text-ink' : 'text-text-muted'}`}
            >{`${done(i) ? '✓ ' : attention ? '! ' : ''}${i + 1} ${label}`}</span>
          </li>
        )
      })}
    </ol>
  )
}

function Load({ data }: { data: RegistrationView }) {
  const { load, min, max } = data.checks
  const over = load > max
  const under = min > 0 && load < min

  return (
    <Sheet className="flex flex-wrap items-center justify-between gap-4 px-4 py-3">
      <div>
        <span className="label-caps text-text-muted">{r.load}</span>
        <div>
          <span className={`type-display text-[30px] ${over || under ? 'text-red-pen' : ''}`}>{load}</span> <span className="text-text-muted">{r.allowed(min, max)}</span>
        </div>
      </div>
      <div role="img" aria-label={`${load} / ${max}`} className="relative h-3 w-full max-w-[360px] border-2 border-ink bg-sheet">
        <div className={`absolute inset-y-0 left-0 ${over ? 'bg-red-pen' : 'bg-teal'}`} style={{ width: `${Math.min(100, max === 0 ? 0 : (load / max) * 100)}%` }} />
        {min > 0 && <div className="absolute inset-y-0 border-r-2 border-ink" style={{ left: `${(min / max) * 100}%` }} />}
      </div>
    </Sheet>
  )
}

function Box({ title, children }: { title: string; children: ReactNode }) {
  return (
    <Sheet label={title} className="grid gap-2.5 p-4">
      <h2 className="type-display border-b-2 border-ink pb-1.5 text-2xl">{title}</h2>
      {children}
    </Sheet>
  )
}

function Checks({ data }: { data: RegistrationView }) {
  const problems = data.checks.problems

  return (
    <Box title={r.checks}>
      {problems.length === 0 ? (
        <p className="font-extrabold text-teal">{`✓ ${r.checksPass}`}</p>
      ) : (
        <ul className="grid gap-1.5">
          {problems.map((p, i) => (
            <li key={i}>
              <span className="font-extrabold text-red-pen" aria-hidden="true">
                ✕{' '}
              </span>
              {p.text}
            </li>
          ))}
        </ul>
      )}
    </Box>
  )
}

function Fees({ division, data, onDone }: { division: string; data: RegistrationView; onDone: () => void }) {
  const f = data.fees
  const open = ['draft', 'submitted', 'approved'].includes(data.state)

  return (
    <Box title={r.fees}>
      {!f.known ? (
        <p className="text-sm text-text-muted">{r.feesOff}</p>
      ) : f.allowed !== null ? (
        <p>{r.feesAllowed(f.allowed.by, f.allowed.reason)}</p>
      ) : f.owed === 0 ? (
        <p className="text-sm text-text-muted">{r.feesNothing}</p>
      ) : (
        <>
          <p>{r.feesLine(money(f.paid), money(f.needed), f.share)}</p>
          {f.more > 0 ? (
            <p>
              <strong aria-hidden="true">! </strong>
              {r.feesMore(money(f.more))}
            </p>
          ) : (
            <p className="font-extrabold text-teal">{r.feesCleared}</p>
          )}
        </>
      )}
      {f.known && f.allowed === null && f.more > 0 && open && data.can.allow_fees && (
        <ReasonButton label={r.allowFees} note={r.allowNote} onConfirm={(reason) => registrationApi.act(division, data.id, 'allow-fees', reason)} onDone={onDone} />
      )}
    </Box>
  )
}

function Actions({ division, data, onDone }: { division: string; data: RegistrationView; onDone: () => void }) {
  const steps = data.steps.filter((x) => x.permitted)

  return (
    <Box title={r.actions}>
      {data.state === 'registered' && (
        <Button variant="secondary" onClick={() => window.open(slipHref(division, data.id), '_blank', 'noopener')}>
          {r.slip}
        </Button>
      )}
      {steps.length === 0 && data.state !== 'registered' && <p className="text-sm text-text-muted">{t.none}</p>}
      {steps.map((step) => {
        const blocked = step.blocked_reason !== null
        const note = blocked ? (
          <p className="text-sm">
            <strong aria-hidden="true">! </strong>
            {step.blocked_reason} {step.remedy}
          </p>
        ) : null

        if (step.step === 'approve') {
          return (
            <div key={step.step} className="grid gap-2">
              <Simple label={r.act.approve} disabled={blocked} run={() => registrationApi.approve(division, data.period_id, [data.id])} onDone={onDone} />
              <ReasonButton label={r.act.return} variant="secondary" onConfirm={(reason) => registrationApi.act(division, data.id, 'return', reason)} onDone={onDone} />
              {note}
            </div>
          )
        }
        if (step.step === 'register') {
          return (
            <div key={step.step} className="grid gap-2">
              <Simple label={r.act.register} disabled={blocked} run={() => registrationApi.register(division, data.period_id, [data.id])} onDone={onDone} />
              {note}
            </div>
          )
        }
        if (step.step === 'submit') {
          return (
            <div key={step.step} className="grid gap-2">
              <Simple label={r.act.submit} disabled={blocked} run={() => registrationApi.act(division, data.id, 'submit', null)} onDone={onDone} />
              {note}
            </div>
          )
        }
        const action = step.step as RegAction
        return (
          <div key={step.step} className="grid gap-2">
            <ReasonButton
              label={step.step === 'change' ? r.act.change : r.act.withdraw}
              variant="secondary"
              disabled={blocked}
              onConfirm={(reason) => registrationApi.act(division, data.id, action, reason)}
              onDone={onDone}
            />
            {note}
          </div>
        )
      })}
    </Box>
  )
}

type Settled = Promise<{ kind: string; errors?: Record<string, string[]> }>

function Simple({ label, disabled, run, onDone }: { label: string; disabled: boolean; run: () => Settled; onDone: () => void }) {
  const [busy, setBusy] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)

  return (
    <>
      <Button
        busy={busy}
        disabled={disabled}
        onClick={() =>
          void (async () => {
            setBusy(true)
            setProblem(null)
            const result = await run()
            setBusy(false)
            if (result.kind === 'saved') {
              slips.done({ word: copy.slips.saved, title: r.done })
              onDone()
            } else setProblem(firstError(result, t.failed))
          })()
        }
      >
        {label}
      </Button>
      {problem !== null && (
        <Notice tone="wrong" title={t.notSaved}>
          {problem}
        </Notice>
      )}
    </>
  )
}

function ReasonButton({
  label,
  note,
  variant,
  disabled,
  onConfirm,
  onDone,
}: {
  label: string
  note?: string
  variant?: 'secondary'
  disabled?: boolean
  onConfirm: (reason: string) => Settled
  onDone: () => void
}) {
  const [open, setOpen] = useState(false)
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)

  if (!open) {
    return (
      <>
        <Button {...(variant !== undefined ? { variant } : {})} disabled={disabled === true} onClick={() => setOpen(true)}>
          {label}
        </Button>
        {note !== undefined && <p className="text-sm text-text-muted">{note}</p>}
      </>
    )
  }

  return (
    <div className="grid gap-2 border-2 border-ink bg-paper p-3">
      <strong>{label}</strong>
      {problem !== null && (
        <Notice tone="wrong" title={t.notSaved}>
          {problem}
        </Notice>
      )}
      <Input label={r.reason} value={reason} onChange={(e) => setReason(e.target.value)} />
      <div className="flex flex-wrap gap-2">
        <Button
          busy={busy}
          disabled={reason.trim() === ''}
          onClick={() =>
            void (async () => {
              setBusy(true)
              setProblem(null)
              const result = await onConfirm(reason)
              setBusy(false)
              if (result.kind === 'saved') {
                slips.done({ word: copy.slips.saved, title: r.done })
                setOpen(false)
                onDone()
              } else setProblem(firstError(result, t.failed))
            })()
          }
        >
          {r.confirm}
        </Button>
        <Button variant="secondary" onClick={() => setOpen(false)}>
          {r.cancel}
        </Button>
      </div>
    </div>
  )
}

function History({ data }: { data: RegistrationView }) {
  return (
    <Box title={r.history}>
      {data.history.length === 0 ? (
        <p className="text-sm text-text-muted">{r.noHistory}</p>
      ) : (
        <ul className="grid gap-1.5 text-sm">
          {data.history.map((h, i) => (
            <li key={i}>
              {`${day(h.at)} · ${r.outcomes[h.outcome] ?? h.outcome}${r.by(h.by)}`}
              {h.reason !== null && <span className="block text-text-muted">{h.reason}</span>}
            </li>
          ))}
        </ul>
      )}
    </Box>
  )
}

/** The course chooser of a registration being chosen: core courses, elective groups, and other courses. */
function CourseEditor({ division, data, onDone }: { division: string; data: RegistrationView; onDone: (saved: boolean) => void }) {
  const byCourse = new Map<string, RegistrationView['offerings']>()
  for (const o of data.offerings) byCourse.set(o.course.id, [...(byCourse.get(o.course.id) ?? []), o])
  const [chosen, setChosen] = useState<Record<string, string>>(() => Object.fromEntries(data.courses.map((c) => [c.course.id, c.offering_id])))
  const [adding, setAdding] = useState('')
  const [busy, setBusy] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)
  const inGroups = new Set(data.groups.flatMap((g) => g.courses))
  const others = Object.keys(chosen).filter((c) => !data.core.includes(c) && !inGroups.has(c))
  const free = [...byCourse.keys()].filter((c) => chosen[c] === undefined && !data.core.includes(c) && !inGroups.has(c))
  const set = (course: string, offering: string) => {
    const next = Object.fromEntries(Object.entries(chosen).filter(([c]) => c !== course))
    setChosen(offering === '' ? next : { ...next, [course]: offering })
  }
  const first = (course: string) => (byCourse.get(course) ?? []).find((o) => o.seats_left === null || o.seats_left > 0)?.id ?? byCourse.get(course)?.[0]?.id ?? ''
  const title = (course: string) => {
    const o = byCourse.get(course)?.[0]
    return o === undefined ? '' : `${o.course.code} ${o.course.title}`
  }

  const groupPicker = (course: string) => (
    <select
      aria-label={`${r.columnsCourse.group} ${title(course)}`}
      value={chosen[course] ?? ''}
      onChange={(e) => set(course, e.target.value)}
      className="h-10 min-w-44 border-b-2 border-ink bg-field px-2 text-sm"
    >
      {data.core.includes(course) && <option value="">{r.notTaken}</option>}
      {(byCourse.get(course) ?? []).map((o) => (
        <option key={o.id} value={o.id}>
          {`${r.columnsCourse.group} ${o.group} · ${r.seatsLeft(o.seats_left)}`}
        </option>
      ))}
    </select>
  )

  const save = async () => {
    setBusy(true)
    setProblem(null)
    const result = await registrationApi.saveCourses(division, data.id, Object.values(chosen))
    setBusy(false)
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: r.coursesSaved })
      onDone(true)
    } else setProblem(firstError(result, t.failed))
  }

  return (
    <Sheet label={r.courses} className="grid gap-4 p-4">
      {problem !== null && (
        <Notice tone="wrong" title={t.notSaved}>
          {problem}
        </Notice>
      )}
      <section className="grid gap-2">
        <h3 className="label-caps text-text-muted">{r.core}</h3>
        {data.core.map((course) => (
          <div key={course} className="flex flex-wrap items-center justify-between gap-2 border-b border-rule pb-2">
            <span>{title(course) || <span className="text-text-muted">{t.none}</span>}</span>
            {groupPicker(course)}
          </div>
        ))}
      </section>
      {data.groups.map((g) => (
        <fieldset key={g.id} className="grid gap-2">
          <legend className="label-caps mb-1 text-text-muted">{r.electives(g.label, g.choose)}</legend>
          {g.courses.map((course) => (
            <div key={course} className="flex flex-wrap items-center justify-between gap-2 border-b border-rule pb-2">
              <label className="flex items-center gap-2">
                <input
                  type="checkbox"
                  className="h-5 w-5 accent-teal"
                  checked={chosen[course] !== undefined}
                  onChange={(e) => set(course, e.target.checked ? first(course) : '')}
                />
                {title(course)}
              </label>
              {chosen[course] !== undefined && groupPicker(course)}
            </div>
          ))}
        </fieldset>
      ))}
      <section className="grid gap-2">
        <h3 className="label-caps text-text-muted">{r.others}</h3>
        {others.map((course) => (
          <div key={course} className="flex flex-wrap items-center justify-between gap-2 border-b border-rule pb-2">
            <span>{title(course)}</span>
            <span className="flex items-center gap-2">
              {groupPicker(course)}
              <SmallButton onClick={() => set(course, '')}>{r.remove}</SmallButton>
            </span>
          </div>
        ))}
        <div className="flex flex-wrap items-center gap-2">
          <select aria-label={r.addCourse} value={adding} onChange={(e) => setAdding(e.target.value)} className="h-10 min-w-64 border-b-2 border-ink bg-field px-2 text-sm">
            <option value="">{r.addCourse}</option>
            {free.map((course) => (
              <option key={course} value={course}>
                {title(course)}
              </option>
            ))}
          </select>
          <SmallButton disabled={adding === ''} onClick={() => (set(adding, first(adding)), setAdding(''))}>
            {r.addCourse}
          </SmallButton>
        </div>
      </section>
      <div className="flex flex-wrap gap-2.5">
        <Button busy={busy} onClick={() => void save()}>
          {r.saveCourses}
        </Button>
        <Button variant="secondary" onClick={() => onDone(false)}>
          {t.cancel}
        </Button>
      </div>
    </Sheet>
  )
}
