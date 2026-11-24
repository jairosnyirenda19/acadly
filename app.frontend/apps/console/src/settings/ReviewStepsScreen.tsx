import { useLoad } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { assessmentApi, type StepWordSet, type StepWordsState } from '../assessment/api'
import { copy } from '../copy'
import { StepMark } from '../early/steps'
import { Input } from '../setup/parts'
import { day } from './api'
import { PageHead, SettingsTabs } from './parts'

const t = copy.settings.reviewSteps
/** The three steps; "Not seen" is no step and keeps its word. */
const STEPS = ['starting', 'growing', 'can_do'] as const
type Step = (typeof STEPS)[number]
const STROKES: Record<Step, string> = { starting: '/', growing: '//', can_do: '///' }
const MAX = 24

type Language = 'en' | 'ny'

/** Settings › Review steps (the approved design, D-109): the school's three words for the steps, in English and in Chichewa. */
export function ReviewStepsScreen() {
  const loaded = useLoad('assessment:step-words', () => assessmentApi.stepWords())

  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} rows={4} onRetry={loaded.reload} />

  return <Editor key={loaded.data.saved_at ?? 'standard'} data={loaded.data} onSaved={loaded.reload} />
}

function Editor({ data, onSaved }: { data: StepWordsState; onSaved: () => void }) {
  const [words, setWords] = useState<Record<Language, StepWordSet>>({ en: { ...data.en }, ny: { ...data.ny } })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [problem, setProblem] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const edit = data.can_change
  const set = (language: Language, step: Step, value: string) => setWords((was) => ({ ...was, [language]: { ...was[language], [step]: value } }))

  const save = async () => {
    setBusy(true)
    setProblem(null)
    setErrors({})
    const trim = (w: StepWordSet): StepWordSet => ({ starting: w.starting.trim(), growing: w.growing.trim(), can_do: w.can_do.trim() })
    const result = await assessmentApi.saveStepWords({ en: trim(words.en), ny: trim(words.ny) })
    setBusy(false)
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: t.saved })
      onSaved()
    } else if (result.kind === 'invalid') {
      setErrors(Object.fromEntries(Object.entries(result.errors).map(([k, v]) => [k, v[0] ?? t.failed])))
      setProblem(Object.values(result.errors)[0]?.[0] ?? t.failed)
    } else setProblem(t.failed)
  }

  const standard = () => {
    setWords({ en: { ...copy.early.steps }, ny: { ...t.standardNy } })
    setErrors({})
  }

  const english = (step: Step) => (words.en[step].trim() !== '' ? words.en[step] : copy.early.steps[step])

  return (
    <>
      <SettingsTabs />
      <PageHead kicker={t.kicker} title={t.title} intro={t.intro} />
      <div className="grid items-start gap-x-8 gap-y-6 px-[clamp(16px,3vw,40px)] pb-8 xl:grid-cols-[minmax(0,1fr)_420px]">
        <div className="grid min-w-0 gap-3">
          {problem !== null && (
            <Notice tone="wrong" title={t.notSaved}>
              {problem}
            </Notice>
          )}
          <section aria-label={t.title} className="border-2 border-ink bg-sheet">
            <div className="hidden grid-cols-[72px_minmax(0,1fr)_minmax(0,1fr)] gap-4 border-b-2 border-ink px-4 py-2 sm:grid">
              <span className="label-caps text-text-muted">{t.step}</span>
              <span className="label-caps text-text-muted">{t.english}</span>
              <span className="label-caps text-text-muted">{t.chichewa}</span>
            </div>
            {STEPS.map((step) => (
              <div key={step} className="grid items-end gap-x-4 gap-y-2 border-b border-rule px-4 py-3 sm:grid-cols-[72px_minmax(0,1fr)_minmax(0,1fr)]">
                <div className="pb-2">
                  <StepMark step={step} wide="w-14" />
                </div>
                <Input
                  label={t.wordFor(t.english, STROKES[step])}
                  value={words.en[step]}
                  maxLength={MAX}
                  disabled={!edit}
                  required
                  error={errors[`en.${step}`]}
                  onChange={(e) => set('en', step, e.target.value)}
                />
                <Input
                  label={t.wordFor(t.chichewa, STROKES[step])}
                  value={words.ny[step]}
                  maxLength={MAX}
                  disabled={!edit}
                  placeholder={t.standardNy[step]}
                  error={errors[`ny.${step}`]}
                  onChange={(e) => set('ny', step, e.target.value)}
                />
              </div>
            ))}
            <div className="flex flex-wrap items-center justify-between gap-3 px-4 py-2.5">
              <span className="text-[13px] text-text-muted">
                {t.limit} {t.chichewaEmpty}
              </span>
              {edit && (
                <button type="button" onClick={standard} className="inline-flex min-h-11 items-center text-sm font-extrabold text-ink underline underline-offset-[3px]">
                  {t.standard}
                </button>
              )}
            </div>
          </section>
          {edit ? (
            <div>
              <Button busy={busy} onClick={() => void save()}>
                {t.save}
              </Button>
            </div>
          ) : (
            <p className="text-[15px]">{t.readOnly}</p>
          )}
          <p className="text-[13px] text-text-muted">{data.saved_at !== null ? t.lastSaved(day(data.saved_at), data.saved_by) : t.neverSaved}</p>
          <p className="text-[13px] text-text-muted">{t.rule}</p>
        </div>

        <aside aria-label={t.preview} className="border-2 border-ink bg-sheet">
          <h2 className="label-caps border-b border-rule px-4 py-2.5 text-text-muted">{t.preview}</h2>
          <ul className="px-4 py-1">
            {STEPS.map((step) => (
              <li key={step} className="flex min-h-11 items-center gap-2.5 border-b border-rule py-1.5">
                <StepMark step={step} wide="w-12" decorative />
                <b className="flex-1 text-[15px]">{english(step)}</b>
                <span className="text-sm text-text-muted">{words.ny[step].trim() !== '' ? words.ny[step] : t.standardNy[step]}</span>
              </li>
            ))}
          </ul>
          <p className="px-4 py-2.5 text-[13px] text-text-muted">{t.previewNote}</p>
        </aside>
      </div>
    </>
  )
}
