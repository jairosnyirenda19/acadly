import { navigate, useLoad } from '@acadly/kernel'
import { Button, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { day } from '../settings/api'
import { PageHead } from '../settings/parts'
import { TextArea } from '../setup/parts'
import { earlyApi, firstError, type Review } from './api'
import { ReviewState } from './ReviewsScreen'
import { isStep, REACHED, StepMark } from './steps'

const t = copy.early

const stepWord = (step: string | undefined): string => (isStep(step) ? t.steps[step] : '')

/** One child's progress review (the approved design): the step in each area beside the term's observations, and two notes. */
export function ReviewScreen({ learnerId }: { learnerId: string }) {
  const loaded = useLoad(`early:review:${learnerId}`, () => earlyApi.review(learnerId))
  const data = loaded.data

  if (data === undefined) return <Waiting phase={loaded.phase} what={t.reviewsTitle} labels={copy.loading} rows={6} onRetry={loaded.reload} />

  return <Draft key={`${data.review.id ?? 'new'}:${data.review.state}`} data={data} onChanged={loaded.reload} />
}

function Draft({ data, onChanged }: { data: Review; onChanged: () => void }) {
  const [steps, setSteps] = useState<Record<string, string>>(() => Object.fromEntries(data.areas.flatMap((a) => (a.step !== null ? [[a.id, a.step]] : []))))
  const [family, setFamily] = useState(data.review.family_note)
  const [home, setHome] = useState(data.review.home_note)
  const [reason, setReason] = useState('')
  const [returning, setReturning] = useState(false)
  const [busy, setBusy] = useState('')
  const edit = data.can.edit

  const save = async (): Promise<string | null> => {
    const result = await earlyApi.save(data.learner.id, { steps, family_note: family, home_note: home })
    if (result.kind === 'saved') return result.data.id
    slips.wrong({ word: t.notSaved, title: firstError(result, t.failed) })

    return null
  }

  const run = async (what: 'save' | 'submit' | 'check' | 'return' | 'share') => {
    setBusy(what)
    let id = data.review.id
    if (what === 'save' || what === 'submit') {
      id = await save()
      if (id === null) return setBusy('')
    }

    let ok = true
    if (id !== null && (what === 'submit' || what === 'check' || what === 'return')) {
      const result = await earlyApi.step(id, what, reason.trim())
      ok = result.kind === 'saved'
      if (!ok) slips.wrong({ word: t.notSaved, title: firstError(result, t.failed) })
    }
    if (id !== null && what === 'share') {
      const result = await earlyApi.share([id])
      ok = result.kind === 'saved'
      if (!ok) slips.wrong({ word: t.notSaved, title: firstError(result, t.failed) })
    }

    setBusy('')
    if (ok) {
      const word = { save: t.draftSaved, submit: t.submitted, check: t.checked, return: t.returned, share: t.shared(1) }[what]
      slips.done({ word: copy.slips.saved, title: word })
      onChanged()
    }
  }

  return (
    <>
      <PageHead
        kicker={
          <>
            <a href="/reviews" onClick={(e) => (e.preventDefault(), navigate('/reviews'))} className="text-ink underline">
              {t.back}
            </a>
            <span>· {t.reviewKicker(data.learner.room, data.period.name)}</span>
          </>
        }
        title={data.learner.name}
        intro={<ReviewState state={data.review.id === null ? null : data.review.state} label={data.review.state_label} />}
      />
      <div className="grid gap-6 px-[clamp(16px,3vw,40px)] pb-8 lg:grid-cols-[minmax(0,1fr)_360px]">
        <div className="grid min-w-0 content-start gap-5">
          <section aria-label={t.stepReached} className="border-2 border-ink bg-sheet">
            {data.areas.map((a) => (
              <div key={a.id} className="grid gap-3 border-b border-rule p-4 md:grid-cols-[220px_minmax(0,1fr)]">
                <div>
                  <h3 className="text-[15px] font-extrabold">{a.name}</h3>
                  <div role="radiogroup" aria-label={`${a.name}: ${t.stepReached}`} className="mt-2 flex flex-wrap gap-1.5">
                    {REACHED.map((s) => {
                      const on = steps[a.id] === s

                      return (
                        <button
                          key={s}
                          type="button"
                          role="radio"
                          aria-checked={on}
                          disabled={!edit}
                          onClick={() => setSteps((was) => ({ ...was, [a.id]: s }))}
                          className={`flex min-h-11 items-center gap-1.5 px-2 text-[13px] font-bold ${on ? 'border-[3px] border-ink' : 'border border-rule'} bg-sheet text-ink disabled:cursor-default`}
                        >
                          <StepMark step={s} wide="w-9" decorative />
                          <span>{t.steps[s]}</span>
                        </button>
                      )
                    })}
                  </div>
                </div>
                <div className="min-w-0">
                  <div className="label-caps mb-1 text-text-muted">{t.trail}</div>
                  {a.trail.length === 0 && <p className="text-[13px] text-text-muted">{t.noTrail}</p>}
                  <ol className="grid gap-1">
                    {a.trail.map((o, i) => (
                      <li key={i} className="flex flex-wrap items-center gap-2 text-[13px]">
                        <span className="w-16 font-mono text-text-muted">{day(o.observed_on)}</span>
                        <StepMark step={isStep(o.step) ? o.step : null} word wide="w-9" />
                        {o.focus !== null && <span className="text-text-muted">{o.focus}</span>}
                        {o.note !== null && <span>{o.note}</span>}
                      </li>
                    ))}
                  </ol>
                </div>
              </div>
            ))}
          </section>
          <section className="grid gap-3 border-2 border-ink bg-sheet p-4">
            <TextArea label={t.familyNote} value={family} disabled={!edit} maxLength={2000} onChange={(e) => setFamily(e.target.value)} />
            <TextArea label={t.homeNote} value={home} disabled={!edit} maxLength={2000} onChange={(e) => setHome(e.target.value)} />
            {!edit && <p className="text-[13px] text-text-muted">{t.readOnly}</p>}
            {data.review.shared_at !== null && <p className="text-[13px] font-bold">{t.sharedAt(day(data.review.shared_at))}</p>}
            <div className="flex flex-wrap gap-2">
              {edit && (
                <Button variant="secondary" busy={busy === 'save'} onClick={() => void run('save')}>
                  {t.saveDraft}
                </Button>
              )}
              {data.can.submit && (
                <Button busy={busy === 'submit'} onClick={() => void run('submit')}>
                  {t.submit}
                </Button>
              )}
              {data.can.check && (
                <Button busy={busy === 'check'} onClick={() => void run('check')}>
                  {t.check}
                </Button>
              )}
              {data.can.share && (
                <Button busy={busy === 'share'} onClick={() => void run('share')}>
                  {t.shareOne}
                </Button>
              )}
              {data.can.return && (
                <Button variant="secondary" onClick={() => setReturning(!returning)}>
                  {t.returnIt}
                </Button>
              )}
            </div>
            {returning && (
              <div className="grid max-w-[560px] gap-2">
                <TextArea label={t.returnReason} value={reason} onChange={(e) => setReason(e.target.value)} required />
                <div>
                  <Button busy={busy === 'return'} disabled={reason.trim() === ''} onClick={() => void run('return')}>
                    {t.returnIt}
                  </Button>
                </div>
              </div>
            )}
          </section>
        </div>
        <aside className="grid content-start gap-4">
          <section className="border-2 border-ink bg-sheet">
            <div className="bg-ink px-4 py-2.5 text-sheet">
              <div className="label-caps text-on-ink-muted">{`${data.period.name} · ${t.familyView}`}</div>
              <div className="type-display text-[22px] leading-none">{data.learner.name}</div>
            </div>
            <ul>
              {data.areas.map((a) => (
                <li key={a.id} className="flex items-center gap-2.5 border-b border-rule px-4 py-2">
                  <StepMark step={steps[a.id] ?? null} wide="w-10" />
                  <span className="flex-1 text-[13px] font-bold">{a.name}</span>
                  <span className="text-xs text-text-muted">{stepWord(steps[a.id])}</span>
                </li>
              ))}
            </ul>
            {family.trim() !== '' && <p className="px-4 py-2.5 text-[13px]">{family}</p>}
            <p className="px-4 pb-3 text-xs text-text-muted">{t.familyViewNote}</p>
          </section>
        </aside>
      </div>
    </>
  )
}
