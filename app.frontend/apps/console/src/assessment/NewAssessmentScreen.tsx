import { navigate, useLoad } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { errorFor } from '../setup/api'
import { Input, Select } from '../setup/parts'
import { assessmentApi } from './api'
import { BackLink } from './parts'

const a = copy.assessment
const t = a.set
const s = copy.settings

/** Set an assessment (the approved phone design): class and subject, kind, title, day, out of, whether it counts. */
export function NewAssessmentScreen() {
  const loaded = useLoad('assessment:options', () => assessmentApi.options())
  const options = loaded.data
  const [choice, setChoice] = useState('')
  const [kind, setKind] = useState('')
  const [title, setTitle] = useState('')
  const [held, setHeld] = useState('')
  const [outOf, setOutOf] = useState('')
  const [counts, setCounts] = useState(true)
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [failed, setFailed] = useState<string | null>(null)
  const [working, setWorking] = useState(false)

  if (options === undefined) return <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} rows={5} onRetry={loaded.reload} />

  const chosen = options.choices.find((c) => `${c.class_id}:${c.subject_id}` === choice) ?? (options.choices.length === 1 ? options.choices[0] : undefined)
  const lands = chosen?.lands.find((l) => l.kind === kind)
  const canCount = lands !== undefined && lands.part_label !== null
  const day = held !== '' ? held : options.today

  const submit = async () => {
    if (chosen === undefined) return
    setWorking(true)
    setFailed(null)
    const result = await assessmentApi.set({
      class_id: chosen.class_id,
      subject_id: chosen.subject_id,
      kind,
      title: title.trim(),
      held_on: day,
      out_of: Number(outOf),
      counts: counts && canCount,
    })
    setWorking(false)
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: t.set })
      navigate(`/marks/${result.data.assessment.id}`, true)
    } else if (result.kind === 'invalid') setErrors(result.errors)
    else setFailed(result.kind === 'refused' ? s.refused : s.failed)
  }

  return (
    <>
      <div className="px-4 pt-1 pb-3 md:px-[clamp(16px,3vw,40px)]">
        <BackLink href="/marks">{t.back}</BackLink>
        <h1 className="type-display text-[clamp(40px,7vw,88px)]">{t.title}</h1>
      </div>
      {options.choices.length === 0 ? (
        <div className="px-4 pb-8 md:px-[clamp(16px,3vw,40px)]">
          <Notice tone="wrong" title={t.noChoices} />
        </div>
      ) : (
        <form
          className="flex flex-1 flex-col border-t-2 border-ink bg-sheet"
          onSubmit={(e) => {
            e.preventDefault()
            void submit()
          }}
        >
          <div className="grid max-w-[640px] gap-4 px-4 py-4 md:px-[clamp(16px,3vw,40px)]">
            {failed !== null && <Notice tone="wrong" title={failed} />}
            <Select label={t.classSubject} required value={chosen !== undefined ? `${chosen.class_id}:${chosen.subject_id}` : ''} onChange={(e) => setChoice(e.target.value)} error={errorFor(errors, 'class_id') ?? errorFor(errors, 'subject_id')}>
              <option value="">{t.choose}</option>
              {options.choices.map((c) => (
                <option key={`${c.class_id}:${c.subject_id}`} value={`${c.class_id}:${c.subject_id}`}>
                  {t.choice(c.class, c.subject)}
                </option>
              ))}
            </Select>
            {chosen !== undefined && chosen.period === null && <Notice tone="wrong" title={t.noTerm} />}
            <Select label={t.kind} required value={kind} onChange={(e) => setKind(e.target.value)} error={errorFor(errors, 'kind')}>
              <option value="">{t.chooseKind}</option>
              {options.kinds.map((k) => (
                <option key={k.value} value={k.value}>
                  {k.label}
                </option>
              ))}
            </Select>
            <Input label={t.titleLabel} required hint={t.titleHint} value={title} onChange={(e) => setTitle(e.target.value)} maxLength={120} error={errorFor(errors, 'title')} />
            <div className="grid grid-cols-2 gap-3">
              <Input label={t.heldOn} required type="date" value={day} onChange={(e) => setHeld(e.target.value)} error={errorFor(errors, 'held_on')} />
              <Input label={t.outOf} required inputMode="numeric" value={outOf} onChange={(e) => setOutOf(e.target.value.replace(/[^0-9]/g, ''))} error={errorFor(errors, 'out_of')} />
            </div>
            <p className="-mt-2 text-[13px] text-text-muted">{t.outOfHint}</p>
            <fieldset>
              <legend className="label-caps mb-1.5 text-text-muted">{t.counts}</legend>
              <div className="grid grid-cols-2 border-2 border-ink">
                {[true, false].map((yes, i) => (
                  <label key={String(yes)} className={`flex min-h-11 cursor-pointer items-center justify-center gap-2 px-3 text-[15px] font-extrabold ${i > 0 ? 'border-l-2 border-ink' : ''} ${counts === yes ? 'bg-ink text-sheet' : 'bg-sheet'} has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-ink`}>
                    <input type="radio" name="counts" className="sr-only" checked={counts === yes} onChange={() => setCounts(yes)} />
                    {yes ? t.countsYes : t.countsNo}
                  </label>
                ))}
              </div>
            </fieldset>
            {kind !== '' && chosen !== undefined && (
              <p role="status" className="border-l-4 border-ink bg-paper px-3.5 py-2.5 text-sm">
                <strong>{t.lands}</strong>{' '}
                {!counts ? t.practiceText : canCount && lands.part_label !== null && lands.weight !== null ? t.landsText(lands.part_label, lands.weight, lands.others) : t.notInPlan}
              </p>
            )}
          </div>
          <div className="sticky bottom-0 mt-auto border-t-2 border-ink bg-sheet px-4 py-2.5 md:px-[clamp(16px,3vw,40px)]">
            <Button type="submit" className="h-12 w-full md:w-auto" busy={working} disabled={chosen === undefined || chosen.period === null || kind === '' || title.trim() === '' || outOf === ''}>
              {working ? t.setting : t.submit}
            </Button>
          </div>
        </form>
      )}
    </>
  )
}
