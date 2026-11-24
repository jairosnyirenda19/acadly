import { navigate, useLoad, useLocation } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useEffect, useState } from 'react'
import { copy } from '../copy'
import { LearnerFinder } from '../fees/RecordPaymentScreen'
import { today } from '../fees/api'
import { learnersApi } from '../learners/api'
import { Input, TextArea } from '../setup/parts'
import { PageHead, Tag } from '../settings/parts'
import { conductApi, LEVELS, type Level } from './api'

const t = copy.conduct

/** Record conduct (the approved design): incident or commendation, several learners, a kind, a level. */
export function RecordConductScreen() {
  const { query } = useLocation()
  const kinds = useLoad('conduct:kinds', () => conductApi.kinds())
  const [commendation, setCommendation] = useState(query.get('commendation') === '1')
  const [learners, setLearners] = useState<{ id: string; name: string }[]>([])
  const [adding, setAdding] = useState(query.get('learner') === null)
  const [kindId, setKindId] = useState('')
  const [level, setLevel] = useState<Level | null>(null)
  const [day, setDay] = useState(today())
  const [place, setPlace] = useState('')
  const [what, setWhat] = useState('')
  const [action, setAction] = useState('')
  const [tell, setTell] = useState(false)
  const [busy, setBusy] = useState(false)
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  const add = (id: string) => {
    void learnersApi.record(id).then((r) => {
      if (r !== null && r !== 'refused' && r !== 'missing' && !learners.some((l) => l.id === id)) setLearners((now) => [...now, { id, name: r.name }])
    })
    setAdding(false)
  }

  useEffect(() => {
    const first = query.get('learner')
    if (first !== null) add(first)
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  if (kinds.data === undefined) return <Waiting phase={kinds.phase} what={t.recordTitle} labels={copy.loading} rows={4} onRetry={kinds.reload} />

  const list = kinds.data.kinds.filter((k) => !k.retired && k.commendation === commendation)
  const kind = list.find((k) => k.id === kindId)
  const chosenLevel: Level | null = commendation ? null : (level ?? ((kind?.level as Level | null | undefined) ?? null))
  const always = kind !== undefined && (kind.always || chosenLevel === 'serious')

  const save = async () => {
    if (kind === undefined) return
    setBusy(true)
    setErrors({})
    const result = await conductApi.record({ kind_id: kind.id, level: chosenLevel, learners: learners.map((l) => l.id), happened_on: day, place: place.trim(), what: what.trim(), action: action.trim(), tell: tell || always })
    setBusy(false)
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: t.recorded })
      const first = learners[0]
      navigate(result.data.case_id !== null ? `/conduct/incidents/${result.data.case_id}` : first !== undefined ? `/learners/${first.id}?tab=conduct` : '/conduct')
    } else if (result.kind === 'invalid') setErrors(result.errors)
    else setErrors({ form: [t.failed] })
  }

  const firstError = Object.values(errors)[0]?.[0]

  return (
    <>
      <PageHead kicker={t.recordKicker} title={t.recordTitle} intro="" />
      <div className="grid max-w-[620px] gap-4 px-[clamp(16px,3vw,40px)] pb-8">
        {firstError !== undefined && (
          <Notice tone="wrong" title={t.notSaved}>
            {firstError}
          </Notice>
        )}
        <div role="radiogroup" aria-label={t.whatKind} className="grid grid-cols-2 border-2 border-ink">
          {[false, true].map((c) => (
            <button
              key={String(c)}
              type="button"
              role="radio"
              aria-checked={commendation === c}
              onClick={() => (setCommendation(c), setKindId(''), setLevel(null))}
              className={`min-h-11 text-[15px] font-extrabold ${c ? 'border-l-2 border-ink' : ''} ${commendation === c ? 'bg-ink text-sheet' : 'bg-sheet text-ink'}`}
            >
              {c ? t.commendation : t.incident}
            </button>
          ))}
        </div>

        <div className="grid gap-2">
          <div className="label-caps text-text-muted">{t.learners}</div>
          <div className="flex flex-wrap gap-1.5">
            {learners.map((l) => (
              <span key={l.id} className="inline-flex min-h-9 items-center gap-1.5 border-2 border-ink bg-sheet pl-2.5 text-sm font-extrabold">
                {l.name}
                <button type="button" aria-label={t.removeLearner(l.name)} onClick={() => setLearners(learners.filter((x) => x.id !== l.id))} className="grid size-9 place-items-center">
                  <svg aria-hidden="true" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="square">
                    <path d="M6 6l12 12" />
                    <path d="M18 6L6 18" />
                  </svg>
                </button>
              </span>
            ))}
            {!adding && (
              <button type="button" onClick={() => setAdding(true)} className="min-h-9 border border-dashed border-ink px-2.5 text-sm font-bold">
                {t.addLearner}
              </button>
            )}
          </div>
          {adding && <LearnerFinder onPick={add} />}
        </div>

        <fieldset className="grid gap-2">
          <legend className="label-caps mb-1.5 text-text-muted">{t.whatKind}</legend>
          <div className="flex flex-wrap gap-1.5">
            {list.map((k) => (
              <button
                key={k.id}
                type="button"
                aria-pressed={kindId === k.id}
                onClick={() => (setKindId(k.id), setLevel(null))}
                className={`min-h-10 bg-sheet px-3 text-sm ${kindId === k.id ? 'border-[3px] border-ink font-extrabold' : 'border border-rule'}`}
              >
                {k.name}
              </button>
            ))}
          </div>
        </fieldset>

        {!commendation && (
          <div role="radiogroup" aria-label={t.level} className="grid grid-cols-3 border-2 border-ink">
            {LEVELS.map((l, i) => (
              <button
                key={l}
                type="button"
                role="radio"
                aria-checked={chosenLevel === l}
                onClick={() => setLevel(l)}
                className={`min-h-10 text-sm font-extrabold ${i > 0 ? 'border-l-2 border-ink' : ''} ${chosenLevel === l ? (l === 'serious' ? 'bg-red-pen text-sheet' : 'bg-ink text-sheet') : 'bg-sheet text-ink'}`}
              >
                {t.levels[l]}
              </button>
            ))}
          </div>
        )}

        <div className="grid gap-3 sm:grid-cols-[180px_minmax(0,1fr)]">
          <Input label={t.when} type="date" value={day} max={today()} onChange={(e) => setDay(e.target.value)} error={errors.happened_on?.[0]} />
          <Input label={t.place} value={place} onChange={(e) => setPlace(e.target.value)} hint={t.placeHint} />
        </div>
        <TextArea label={t.what} value={what} onChange={(e) => setWhat(e.target.value)} error={errors.what?.[0]} required />
        <TextArea label={t.action} value={action} onChange={(e) => setAction(e.target.value)} />

        {chosenLevel === 'serious' && (
          <div className="bg-red-wash px-3 py-2.5 text-[13px]">
            <Tag tone="wrong">✕ {t.levels.serious}</Tag> {t.seriousNote}
          </div>
        )}
        <label className="flex items-center gap-2.5 text-sm">
          <input type="checkbox" className="size-5 accent-ink" checked={tell || always} disabled={always} onChange={(e) => setTell(e.target.checked)} />
          {t.tellFamily} {always && <span className="text-text-muted">({t.tellAlways})</span>}
        </label>
        <Button busy={busy} disabled={kind === undefined || learners.length === 0 || what.trim() === ''} onClick={() => void save()}>
          {t.record}
        </Button>
      </div>
    </>
  )
}
