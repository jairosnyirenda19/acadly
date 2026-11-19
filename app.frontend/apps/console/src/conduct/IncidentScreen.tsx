import { useLoad } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { today } from '../fees/api'
import { EntryTag } from '../learners/record/ConductPart'
import { Input, TextArea } from '../setup/parts'
import { day } from '../settings/api'
import { PageHead, Tag } from '../settings/parts'
import { conductApi, SANCTIONS, type CaseStep, type Incident, type Sanction } from './api'

const t = copy.conduct
const STEPS = ['reported', 'looked_into', 'decided', 'family_met', 'closed']

/** A serious incident (the approved design): the steps, the learners, what was found, and the decision. */
export function IncidentScreen({ id }: { id: string }) {
  const loaded = useLoad(`conduct:incident:${id}`, () => conductApi.incident(id))
  const c = loaded.data
  const [busy, setBusy] = useState(false)
  const [note, setNote] = useState('')
  const [reason, setReason] = useState('')
  const [asking, setAsking] = useState<'told' | 'withdraw' | null>(null)

  if (c === undefined) return <Waiting phase={loaded.phase} what={t.openIncident} labels={copy.loading} rows={6} onRetry={loaded.reload} />

  const run = async (step: CaseStep, why = '') => {
    setBusy(true)
    const result = await conductApi.step(c.id, step, why)
    setBusy(false)
    if (result.kind === 'saved') {
      setAsking(null)
      setReason('')
      loaded.reload()
      slips.done({ word: copy.slips.saved, title: t.moved })
    } else slips.wrong({ word: t.notSaved, title: result.kind === 'invalid' ? (Object.values(result.errors)[0]?.[0] ?? t.failed) : t.failed })
  }

  const addNote = async () => {
    setBusy(true)
    const result = await conductApi.note(c.id, note.trim())
    setBusy(false)
    if (result.kind === 'saved') {
      setNote('')
      loaded.reload()
    } else slips.wrong({ word: t.notSaved, title: t.failed })
  }

  const at = STEPS.indexOf(c.state)
  const entry = c.entry

  return (
    <>
      <PageHead
        kicker={t.incidentKicker(c.number)}
        title={entry !== null ? `${entry.kind} · ${day(entry.happened_on)}` : c.number}
        intro={[entry?.place, entry?.recorded_by !== null && entry?.recorded_by !== undefined ? t.reportedBy(entry.recorded_by) : null, c.lead !== null ? t.followedBy(c.lead) : null].filter(Boolean).join(' ')}
      />
      <div className="grid gap-6 px-[clamp(16px,3vw,40px)] pb-8">
        {c.state === 'withdrawn' ? (
          <Notice tone="wrong" title={t.withdrawnState}>
            {c.notes[c.notes.length - 1]?.text}
          </Notice>
        ) : (
          <ol className="grid gap-2 sm:grid-cols-5" aria-label={t.openIncident}>
            {STEPS.map((s, i) => (
              <li key={s} className={`px-3 py-2.5 ${i < at || c.state === 'closed' ? 'border border-rule bg-teal-wash' : i === at ? 'border-[3px] border-ink bg-sheet' : 'border border-rule bg-paper'}`}>
                {i < at || c.state === 'closed' ? <Tag tone="done">✓ {t.stepDone}</Tag> : i === at ? <Tag tone="attention">! {t.stepNow}</Tag> : <Tag tone="plain">{t.stepNext}</Tag>}
                <div className="mt-1.5 text-[15px] font-extrabold">{t.steps[s]}</div>
              </li>
            ))}
          </ol>
        )}

        <div className="grid grid-cols-[minmax(0,1fr)] items-start gap-x-8 gap-y-6 xl:grid-cols-[minmax(0,1fr)_440px]">
          <div className="grid gap-5">
            {entry !== null && (
              <section className="border-2 border-ink bg-sheet px-4 py-3">
                <div className="mb-1.5 flex flex-wrap gap-2">
                  <EntryTag entry={entry} />
                </div>
                <p className="text-[15px]">{entry.what}</p>
                {entry.action !== null && <p className="mt-1 text-[13px] text-text-muted">{t.actionLine(entry.action)}</p>}
              </section>
            )}

            <section className="border-2 border-ink bg-sheet px-4 py-3" aria-label={t.learnersInvolved}>
              <h2 className="type-display mb-2 text-[26px] leading-none">{t.learnersInvolved}</h2>
              <ul>
                {c.learners.map((l) => (
                  <li key={l.id} className="grid gap-x-3 gap-y-1 border-b border-rule py-2.5 sm:grid-cols-[minmax(0,1fr)_130px_minmax(0,1.3fr)]">
                    <b className="text-[15px]">{l.name}</b>
                    <span className="text-sm text-text-muted">{l.class}</span>
                    <span className="text-sm">{l.outcome !== null ? <><b>{l.outcome.text}</b> · {l.outcome.reason}</> : <span className="text-text-muted">{t.noOutcome}</span>}</span>
                  </li>
                ))}
              </ul>
            </section>

            <section className="border-2 border-ink bg-sheet px-4 py-3" aria-label={t.found}>
              <h2 className="type-display mb-2 text-[26px] leading-none">{t.found}</h2>
              <ul>
                {c.notes.map((n) => (
                  <li key={n.id} className="grid gap-x-3 border-b border-rule py-2 text-sm sm:grid-cols-[150px_minmax(0,1fr)]">
                    <span className="ref text-xs text-text-muted">{new Date(n.at).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}</span>
                    <span>
                      {n.by !== null && <b>{n.by} </b>}
                      {n.text}
                    </span>
                  </li>
                ))}
              </ul>
              {c.can.follow_up && !['closed', 'withdrawn'].includes(c.state) && (
                <div className="mt-3 grid gap-2">
                  <TextArea label={t.noteLabel} value={note} onChange={(e) => setNote(e.target.value)} hint={t.notesNote} />
                  <div>
                    <Button variant="secondary" busy={busy} disabled={note.trim() === ''} onClick={() => void addNote()}>
                      {t.addNote}
                    </Button>
                  </div>
                </div>
              )}
              {c.can.follow_up && (
                <div className="mt-4">
                  <div className="label-caps mb-1 text-text-muted">{t.statements}</div>
                  {c.documents.length === 0 ? <p className="text-sm text-text-muted">{t.noStatements}</p> : (
                    <ul className="text-sm">
                      {c.documents.map((d) => (
                        <li key={d.id}>{d.title}</li>
                      ))}
                    </ul>
                  )}
                </div>
              )}
            </section>
          </div>

          {c.can.follow_up && (
            <aside className="grid gap-3 border-2 border-ink bg-sheet p-4">
              {c.can.take_up && (
                <>
                  <h2 className="type-display text-[26px] leading-none">{t.takeUp}</h2>
                  <p className="text-sm">{t.takeUpNote}</p>
                  <Button busy={busy} onClick={() => void run('take-up')}>
                    {t.takeUp}
                  </Button>
                </>
              )}
              {c.state === 'looked_into' && (c.can.reporter ? <p className="text-sm font-bold">{t.reporterNote}</p> : <Decide incident={c} onChanged={loaded.reload} onDecide={() => void run('decide')} busy={busy} />)}
              {c.can.meet && (
                <>
                  <h2 className="type-display text-[26px] leading-none">{t.steps.family_met}</h2>
                  <div className="flex flex-wrap gap-2">
                    <Button busy={busy} onClick={() => void run('met')}>
                      {t.met}
                    </Button>
                    <Button variant="secondary" onClick={() => setAsking('told')}>
                      {t.told}
                    </Button>
                  </div>
                </>
              )}
              {c.can.close && (
                <Button busy={busy} onClick={() => void run('close')}>
                  {t.close}
                </Button>
              )}
              {asking !== null && (
                <div className="grid gap-2">
                  <TextArea label={asking === 'told' ? t.toldReason : t.withdrawReason} value={reason} onChange={(e) => setReason(e.target.value)} required />
                  <div className="flex gap-2">
                    <Button busy={busy} disabled={reason.trim() === ''} onClick={() => void run(asking, reason.trim())}>
                      {asking === 'told' ? t.told : t.withdrawIncident}
                    </Button>
                    <Button variant="secondary" onClick={() => setAsking(null)}>
                      {t.cancel}
                    </Button>
                  </div>
                </div>
              )}
              {c.can.withdraw && asking === null && (
                <button type="button" onClick={() => setAsking('withdraw')} className="min-h-11 justify-self-start text-[15px] font-extrabold text-red-text underline underline-offset-[3px]">
                  {t.withdrawIncident}
                </button>
              )}
            </aside>
          )}
        </div>
      </div>
    </>
  )
}

function Decide({ incident, onChanged, onDecide, busy }: { incident: Incident; onChanged: () => void; onDecide: () => void; busy: boolean }) {
  const [learner, setLearner] = useState(incident.learners.find((l) => l.outcome === null)?.id ?? incident.learners[0]?.id ?? '')
  const [sanction, setSanction] = useState<Sanction>('warning')
  const [starts, setStarts] = useState(today())
  const [days, setDays] = useState('1')
  const [reason, setReason] = useState('')
  const [saving, setSaving] = useState(false)
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const name = incident.learners.find((l) => l.id === learner)?.name ?? ''
  const withDays = sanction === 'detention' || sanction === 'suspension'
  const allGiven = incident.learners.every((l) => l.outcome !== null)

  const give = async () => {
    setSaving(true)
    setErrors({})
    const result = await conductApi.outcome(incident.id, { learner_id: learner, outcome: sanction, starts_on: withDays ? starts : null, days: withDays ? Number(days) : null, reason: reason.trim() })
    setSaving(false)
    if (result.kind === 'saved') {
      setReason('')
      slips.done({ word: copy.slips.saved, title: t.outcomeGiven })
      onChanged()
      const next = incident.learners.find((l) => l.outcome === null && l.id !== learner)
      if (next !== undefined) setLearner(next.id)
    } else if (result.kind === 'invalid') setErrors(result.errors)
    else slips.wrong({ word: t.notSaved, title: t.failed })
  }

  return (
    <div className="grid gap-2.5">
      <h2 className="type-display text-[26px] leading-none">{t.decide}</h2>
      {incident.learners.length > 1 && (
        <div className="flex flex-wrap gap-1.5">
          {incident.learners.map((l) => (
            <button key={l.id} type="button" aria-pressed={learner === l.id} onClick={() => setLearner(l.id)} className={`min-h-10 bg-sheet px-3 text-sm ${learner === l.id ? 'border-[3px] border-ink font-extrabold' : 'border border-rule'}`}>
              {l.outcome !== null && '✓ '}
              {l.name}
            </button>
          ))}
        </div>
      )}
      <fieldset className="grid gap-1.5">
        <legend className="label-caps mb-1 text-text-muted">{t.outcomeFor(name)}</legend>
        {SANCTIONS.map((s) => (
          <label key={s} className={`flex items-start gap-2.5 bg-sheet px-3 py-2 ${sanction === s ? 'border-[3px] border-ink' : 'border border-rule'}`}>
            <input type="radio" name="sanction" checked={sanction === s} onChange={() => setSanction(s)} className="mt-1 accent-ink" />
            <span>
              <b className="block text-[15px]">{t.sanctions[s]}</b>
              <span className="text-[13px] text-text-muted">{t.sanctionNotes[s]}</span>
            </span>
          </label>
        ))}
      </fieldset>
      {withDays && (
        <div className="grid grid-cols-2 gap-2.5">
          <Input label={t.from} type="date" value={starts} onChange={(e) => setStarts(e.target.value)} />
          <Input label={t.days} inputMode="numeric" value={days} onChange={(e) => setDays(e.target.value)} error={errors.days?.[0]} />
        </div>
      )}
      <TextArea label={t.reason} value={reason} onChange={(e) => setReason(e.target.value)} error={errors.reason?.[0] ?? errors.outcome?.[0]} required />
      <Button variant="secondary" busy={saving} disabled={reason.trim() === '' || learner === ''} onClick={() => void give()}>
        {t.giveOutcome(name)}
      </Button>
      <div className="border-t border-rule pt-2.5">
        <Button busy={busy} disabled={!allGiven} onClick={onDecide}>
          {t.decideAll}
        </Button>
        <p className="mt-1.5 text-[13px] text-text-muted">{t.decideNote}</p>
      </div>
    </div>
  )
}
