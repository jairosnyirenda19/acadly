import { useLoad } from '@acadly/kernel'
import { Button, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { Input, TextArea } from '../setup/parts'
import { day } from '../settings/api'
import { PageHead, Tag } from '../settings/parts'
import { GOAL_STATES, OUTCOMES, supportApi, type CloseOutcome, type GoalState, type Plan } from './api'

const t = copy.support

export function GoalTag({ state }: { state: string | null }) {
  if (state === 'met') return <Tag tone="done">✓ {t.goalStates.met}</Tag>
  if (state === 'partly') return <Tag tone="attention">! {t.goalStates.partly}</Tag>

  return <Tag tone="plain">{t.goalStates.not_yet}</Tag>
}

/** A support plan (the approved design): the steps, concern, goals, actions, reviews and closing. */
export function PlanScreen({ id }: { id: string }) {
  const loaded = useLoad(`support:plan:${id}`, () => supportApi.plan(id))
  const p = loaded.data

  if (p === undefined) return <Waiting phase={loaded.phase} what={t.currentPlan} labels={copy.loading} rows={6} onRetry={loaded.reload} />

  const run = async (what: 'goals' | 'actions' | 'agree' | 'reviews' | 'close', body: Record<string, unknown> = {}): Promise<boolean> => {
    const result = await supportApi.change(p.id, what, body)
    if (result.kind === 'saved') {
      loaded.reload()
      slips.done({ word: copy.slips.saved, title: t.saved })

      return true
    }
    slips.wrong({ word: t.notSaved, title: result.kind === 'invalid' ? (Object.values(result.errors)[0]?.[0] ?? t.failed) : t.failed })

    return false
  }

  const steps = ['raised', 'agreed', 'reviewing', 'closed']
  const at = p.state === 'raised' ? 0 : p.state === 'reviewing' ? 2 : 4

  return (
    <>
      <PageHead kicker={t.planKicker(p.learner.name, p.learner.class)} title={p.title} intro={`${t.planIntro(p.lead, day(p.opened_on))} ${p.confidential ? t.confidential + '.' : p.tell_family ? t.familyTold : t.familyNotTold}`} />
      <div className="grid gap-6 px-[clamp(16px,3vw,40px)] pb-8">
        <ol className="grid gap-2 sm:grid-cols-4" aria-label={t.currentPlan}>
          {steps.map((s, i) => (
            <li key={s} className={`px-3 py-2.5 ${i < at || (i === 1 && at >= 2) ? 'border border-rule bg-teal-wash' : i === at ? 'border-[3px] border-ink bg-sheet' : 'border border-rule bg-paper'}`}>
              {i < at || at === 4 ? <Tag tone="done">✓ {t.stepDone}</Tag> : i === at ? <Tag tone="attention">! {t.stepNow}</Tag> : <Tag tone="plain">{t.stepNext}</Tag>}
              <div className="mt-1.5 text-[15px] font-extrabold">{t.steps[s]}</div>
            </li>
          ))}
        </ol>

        <div className="grid items-start gap-x-8 gap-y-6 xl:grid-cols-[minmax(0,1fr)_440px]">
          <div className="grid gap-5">
            <section className="border-2 border-ink bg-sheet px-4 py-3">
              <h2 className="type-display mb-2 text-[26px] leading-none">{t.theConcern}</h2>
              <p className="text-[15px]">{p.concern}</p>
              {p.signals.length > 0 && (
                <>
                  <div className="label-caps mt-3 mb-1 text-text-muted">{t.fromSignals}</div>
                  <ul className="text-sm">
                    {p.signals.map((s, i) => (
                      <li key={i}>{s}</li>
                    ))}
                  </ul>
                </>
              )}
            </section>
            <Goals plan={p} onAdd={(g) => run('goals', g)} />
            <Actions plan={p} onAdd={(a) => run('actions', a)} />
            {p.reviews.length > 0 && (
              <section className="border-2 border-ink bg-sheet px-4 py-3">
                <h2 className="type-display mb-2 text-[26px] leading-none">{t.reviews}</h2>
                <ul>
                  {p.reviews.map((r) => (
                    <li key={r.id} className="grid gap-x-3 border-b border-rule py-2 text-sm sm:grid-cols-[150px_minmax(0,1fr)]">
                      <span className="ref text-xs text-text-muted">{day(r.at)}</span>
                      <span>
                        <b>{t.reviewLine(r.met, r.of)}</b>
                        {r.by !== null && ` · ${r.by}`}
                        {r.notes !== null && <span className="block text-text-muted">{r.notes}</span>}
                      </span>
                    </li>
                  ))}
                </ul>
              </section>
            )}
          </div>

          <aside className="grid gap-4">
            {p.state === 'closed' && (
              <section className="border-2 border-ink bg-sheet p-4">
                <Tag tone="done">✓ {t.closed(p.outcome_label ?? '')}</Tag>
                {p.closing_note !== null && <p className="mt-2 text-sm">{p.closing_note}</p>}
              </section>
            )}
            {p.can.agree && (
              <section className="grid gap-2.5 border-2 border-ink bg-sheet p-4">
                <h2 className="type-display text-[26px] leading-none">{t.agree}</h2>
                <p className="text-sm">{t.agreeNote}</p>
                <Button disabled={p.goals.length === 0} onClick={() => void run('agree')}>
                  {t.agree}
                </Button>
              </section>
            )}
            {p.can.review && <Review plan={p} onSave={(body) => run('reviews', body)} />}
            {p.can.close && <Close onSave={(body) => run('close', body)} />}
          </aside>
        </div>
      </div>
    </>
  )
}

function Goals({ plan, onAdd }: { plan: Plan; onAdd: (g: { text: string; measure: string; by_when: string }) => Promise<boolean> }) {
  const [g, setG] = useState({ text: '', measure: '', by_when: '' })

  return (
    <section className="border-2 border-ink bg-sheet px-4 py-3" aria-label={t.goals}>
      <h2 className="type-display mb-2 text-[26px] leading-none">{t.goals}</h2>
      {plan.goals.length === 0 && <p className="text-sm text-text-muted">{t.noGoals}</p>}
      <ul>
        {plan.goals.map((x) => (
          <li key={x.id} className="grid gap-x-3 border-b border-rule py-2.5 sm:grid-cols-[minmax(0,1fr)_150px_100px]">
            <span>
              <b className="text-[15px]">{x.text}</b>
              {x.measure !== null && <span className="block text-[13px] text-text-muted">{x.measure}</span>}
            </span>
            <span className="text-[13px]">{x.by_when}</span>
            <span className="sm:text-right">{x.state !== null && <GoalTag state={x.state} />}</span>
          </li>
        ))}
      </ul>
      {plan.can.lead && (
        <div className="mt-3 grid gap-2 sm:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_140px_auto] sm:items-end">
          <Input label={t.goal} value={g.text} onChange={(e) => setG({ ...g, text: e.target.value })} />
          <Input label={t.measure} value={g.measure} onChange={(e) => setG({ ...g, measure: e.target.value })} />
          <Input label={t.byWhen} value={g.by_when} onChange={(e) => setG({ ...g, by_when: e.target.value })} />
          <Button variant="secondary" disabled={g.text.trim() === ''} onClick={() => void onAdd(g).then((ok) => ok && setG({ text: '', measure: '', by_when: '' }))}>
            {t.addGoal}
          </Button>
        </div>
      )}
    </section>
  )
}

function Actions({ plan, onAdd }: { plan: Plan; onAdd: (a: { who: string; what: string; when: string }) => Promise<boolean> }) {
  const [a, setA] = useState({ who: '', what: '', when: '' })

  return (
    <section className="border-2 border-ink bg-sheet px-4 py-3" aria-label={t.actions}>
      <h2 className="type-display mb-2 text-[26px] leading-none">{t.actions}</h2>
      {plan.actions.length === 0 && <p className="text-sm text-text-muted">{t.noActions}</p>}
      <ul>
        {plan.actions.map((x) => (
          <li key={x.id} className="grid gap-x-3 border-b border-rule py-2 text-sm sm:grid-cols-[170px_minmax(0,1fr)_120px]">
            <b>{x.who}</b>
            <span>{x.what}</span>
            <span className="text-text-muted sm:text-right">{x.when}</span>
          </li>
        ))}
      </ul>
      {plan.can.lead && (
        <div className="mt-3 grid gap-2 sm:grid-cols-[170px_minmax(0,1fr)_120px_auto] sm:items-end">
          <Input label={t.who} value={a.who} onChange={(e) => setA({ ...a, who: e.target.value })} />
          <Input label={t.what} value={a.what} onChange={(e) => setA({ ...a, what: e.target.value })} />
          <Input label={t.when} value={a.when} onChange={(e) => setA({ ...a, when: e.target.value })} />
          <Button variant="secondary" disabled={a.who.trim() === '' || a.what.trim() === ''} onClick={() => void onAdd(a).then((ok) => ok && setA({ who: '', what: '', when: '' }))}>
            {t.addAction}
          </Button>
        </div>
      )}
    </section>
  )
}

function Review({ plan, onSave }: { plan: Plan; onSave: (body: Record<string, unknown>) => Promise<boolean> }) {
  const [states, setStates] = useState<Record<string, GoalState>>(() => Object.fromEntries(plan.goals.map((g) => [g.id, (g.state as GoalState | null) ?? 'not_yet'])))
  const [notes, setNotes] = useState('')
  const [next, setNext] = useState('')
  const [busy, setBusy] = useState(false)

  return (
    <section className="grid gap-2.5 border-2 border-ink bg-sheet p-4">
      <h2 className="type-display text-[26px] leading-none">{t.review(day(plan.next_review_on))}</h2>
      {plan.goals.map((g) => (
        <div key={g.id} className="grid gap-1.5 border-b border-rule pb-2">
          <span className="text-sm">{g.text}</span>
          <div className="flex gap-1">
            {GOAL_STATES.map((s) => (
              <button key={s} type="button" aria-pressed={states[g.id] === s} onClick={() => setStates({ ...states, [g.id]: s })} className={`min-h-9 bg-sheet px-2.5 text-[13px] ${states[g.id] === s ? 'border-[3px] border-ink font-extrabold' : 'border border-rule'}`}>
                {t.goalStates[s]}
              </button>
            ))}
          </div>
        </div>
      ))}
      <TextArea label={t.notes} value={notes} onChange={(e) => setNotes(e.target.value)} />
      <Input label={t.nextReviewLabel} type="date" value={next} onChange={(e) => setNext(e.target.value)} />
      <Button busy={busy} disabled={next === ''} onClick={() => (setBusy(true), void onSave({ goals: states, notes, next_review_on: next }).then(() => setBusy(false)))}>
        {t.recordReview}
      </Button>
    </section>
  )
}

function Close({ onSave }: { onSave: (body: Record<string, unknown>) => Promise<boolean> }) {
  const [outcome, setOutcome] = useState<CloseOutcome>('goals_met')
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)

  return (
    <section className="grid gap-2.5 border-2 border-ink bg-sheet p-4">
      <h2 className="type-display text-[22px] leading-none">{t.closePlan}</h2>
      <div className="flex flex-wrap gap-1.5">
        {OUTCOMES.map((o) => (
          <button key={o} type="button" aria-pressed={outcome === o} onClick={() => setOutcome(o)} className={`min-h-10 bg-sheet px-3 text-sm ${outcome === o ? 'border-[3px] border-ink font-extrabold' : 'border border-rule'}`}>
            {t.outcomes[o]}
          </button>
        ))}
      </div>
      <TextArea label={t.closeNote} value={note} onChange={(e) => setNote(e.target.value)} required />
      <p className="text-[13px] text-text-muted">{t.closeHelp}</p>
      <Button variant="secondary" busy={busy} disabled={note.trim() === ''} onClick={() => (setBusy(true), void onSave({ outcome, note }).then(() => setBusy(false)))}>
        {t.closePlan}
      </Button>
    </section>
  )
}
