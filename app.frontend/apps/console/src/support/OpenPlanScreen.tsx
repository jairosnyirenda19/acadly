import { navigate, useLoad, useLocation } from '@acadly/kernel'
import { Button, Notice, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { Input, Select, TextArea } from '../setup/parts'
import { PageHead } from '../settings/parts'
import { supportApi, type Opening } from './api'
import { SourceTag } from './WatchScreen'

const t = copy.support

/** Open a support plan (the approved design): from the signals, with a lead, a first review and goals. */
export function OpenPlanScreen() {
  const { query } = useLocation()
  const learner = query.get('learner') ?? ''
  const loaded = useLoad(`support:opening:${learner}`, () => supportApi.opening(learner))

  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={t.openTitle} labels={copy.loading} rows={5} onRetry={loaded.reload} />

  return <Form data={loaded.data} />
}

function Form({ data }: { data: Opening }) {
  const [title, setTitle] = useState('')
  const [concern, setConcern] = useState(data.signals.map((s) => s.text).join(' '))
  const [lead, setLead] = useState(data.lead)
  const [review, setReview] = useState(data.first_review)
  const [confidential, setConfidential] = useState(false)
  const [tell, setTell] = useState(data.tell_family)
  const [goals, setGoals] = useState([{ text: '', measure: '', by_when: '' }])
  const [busy, setBusy] = useState(false)
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const set = (i: number, key: 'text' | 'measure' | 'by_when', value: string) => setGoals(goals.map((g, j) => (j === i ? { ...g, [key]: value } : g)))

  const save = async () => {
    setBusy(true)
    setErrors({})
    const result = await supportApi.open({ learner_id: data.learner.id, title: title.trim(), concern: concern.trim(), lead_id: lead, confidential, tell_family: tell && !confidential,
      first_review: review, goals: goals.filter((g) => g.text.trim() !== '') })
    setBusy(false)
    if (result.kind === 'saved') navigate(`/support/plans/${result.data.id}`)
    else if (result.kind === 'invalid') setErrors(result.errors)
    else setErrors({ form: [t.failed] })
  }

  const firstError = Object.values(errors)[0]?.[0]

  return (
    <>
      <PageHead kicker={t.openKicker(`${data.learner.name} · ${data.learner.class}`)} title={t.openTitle} intro="" />
      <div className="grid max-w-[680px] gap-4 px-[clamp(16px,3vw,40px)] pb-8">
        {firstError !== undefined && (
          <Notice tone="wrong" title={t.notSaved}>
            {firstError}
          </Notice>
        )}
        {data.signals.length > 0 && (
          <ul className="grid gap-1.5 border border-rule bg-sheet px-3 py-2.5">
            {data.signals.map((s, i) => (
              <li key={i} className="flex flex-wrap items-baseline gap-2 text-[13px]">
                <SourceTag label={s.label} />
                <span>{s.text}</span>
              </li>
            ))}
          </ul>
        )}
        <Input label={t.title} value={title} onChange={(e) => setTitle(e.target.value)} maxLength={120} required />
        <TextArea label={t.concern} value={concern} onChange={(e) => setConcern(e.target.value)} required />
        <div className="grid gap-3 sm:grid-cols-2">
          <Select label={t.lead} value={lead} onChange={(e) => setLead(e.target.value)} hint={t.leadHint} error={errors.lead_id?.[0]}>
            {data.leads.map((l) => (
              <option key={l.id} value={l.id}>
                {l.name}
              </option>
            ))}
          </Select>
          <Input label={t.firstReview} type="date" value={review} onChange={(e) => setReview(e.target.value)} />
        </div>

        <fieldset className="grid gap-2.5">
          <legend className="label-caps mb-1 text-text-muted">{t.goals}</legend>
          {goals.map((g, i) => (
            <div key={i} className="grid gap-2 border-b border-rule pb-2.5 sm:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_140px_auto] sm:items-end">
              <Input label={t.goal} value={g.text} onChange={(e) => set(i, 'text', e.target.value)} />
              <Input label={t.measure} value={g.measure} onChange={(e) => set(i, 'measure', e.target.value)} />
              <Input label={t.byWhen} value={g.by_when} onChange={(e) => set(i, 'by_when', e.target.value)} />
              <button type="button" disabled={goals.length === 1} onClick={() => setGoals(goals.filter((_, j) => j !== i))} className="min-h-11 px-1 text-sm font-bold underline disabled:opacity-40">
                {t.removeGoal}
              </button>
            </div>
          ))}
          <button type="button" onClick={() => setGoals([...goals, { text: '', measure: '', by_when: '' }])} className="min-h-11 justify-self-start text-sm font-extrabold underline">
            {t.addGoal}
          </button>
        </fieldset>

        {data.can_confidential && (
          <label className="flex items-start gap-2.5 text-sm">
            <input type="checkbox" className="mt-0.5 size-5 accent-ink" checked={confidential} onChange={(e) => setConfidential(e.target.checked)} />
            <span>
              <b>{t.confidentialLabel}</b>
              <span className="block text-text-muted">{t.confidentialNote}</span>
            </span>
          </label>
        )}
        <label className="flex items-center gap-2.5 text-sm">
          <input type="checkbox" className="size-5 accent-ink" checked={tell && !confidential} disabled={confidential} onChange={(e) => setTell(e.target.checked)} />
          {t.tellFamily}
        </label>
        <Button busy={busy} disabled={title.trim() === '' || concern.trim() === ''} onClick={() => void save()}>
          {t.open}
        </Button>
      </div>
    </>
  )
}
