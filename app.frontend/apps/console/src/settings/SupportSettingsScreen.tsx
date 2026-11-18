import { useLoad } from '@acadly/kernel'
import { Button, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { Input } from '../setup/parts'
import { supportApi, type Rules } from '../support/api'
import { SourceTag } from '../support/WatchScreen'
import { PageHead } from './parts'

const t = copy.settings.support

/** Settings › Support (the approved design): the signal rules and the plan defaults. */
export function SupportSettingsScreen() {
  const loaded = useLoad('support:rules', () => supportApi.rules())

  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} rows={5} onRetry={loaded.reload} />

  return <Editor data={loaded.data} />
}

function Editor({ data }: { data: Rules }) {
  const [values, setValues] = useState<Record<string, string>>(() => Object.fromEntries(data.rules.map((r) => [r.key, String(r.value)])))
  const [review, setReview] = useState(String(data.review_days))
  const [tell, setTell] = useState(data.tell_family)
  const [busy, setBusy] = useState(false)

  const save = async () => {
    setBusy(true)
    const out: Record<string, number> = { 'plan.review_days': Number(review) || 14, 'plan.tell_family': tell ? 1 : 0 }
    for (const [k, v] of Object.entries(values)) out[k] = Number(v) || 0
    const result = await supportApi.saveRules(out)
    setBusy(false)
    if (result.kind === 'saved') slips.done({ word: copy.slips.saved, title: t.saved })
    else slips.wrong({ word: t.notSaved, title: result.kind === 'invalid' ? (Object.values(result.errors)[0]?.[0] ?? t.failed) : t.failed })
  }

  return (
    <>
      <PageHead kicker={t.kicker} title={t.title} intro={t.intro} />
      <div className="grid items-start gap-x-8 gap-y-6 px-[clamp(16px,3vw,40px)] pb-8 xl:grid-cols-[minmax(0,1fr)_420px]">
        <section aria-label={t.signals} className="grid gap-1 border-2 border-ink bg-sheet px-4 py-3.5">
          <h2 className="type-display mb-1 text-[26px] leading-none">{t.signals}</h2>
          {data.rules.map((r) => {
            const [before, after] = r.text.split('{value}')

            return (
              <div key={r.key} className="grid items-center gap-3 border-b border-rule py-2.5 sm:grid-cols-[130px_minmax(0,1fr)]">
                <SourceTag label={r.label} />
                <label className="flex flex-wrap items-center gap-2 text-[15px]">
                  {before}
                  <input
                    inputMode="numeric"
                    value={values[r.key] ?? ''}
                    onChange={(e) => setValues({ ...values, [r.key]: e.target.value })}
                    className="ref h-10 w-16 border-0 border-b-2 border-ink bg-field text-center outline-none focus:border-teal"
                  />
                  {after}
                </label>
              </div>
            )
          })}
          <p className="mt-1 text-[13px] text-text-muted">{t.signalsNote}</p>
          <p className="text-[13px] text-text-muted">{t.conductNote}</p>
        </section>
        <aside className="grid gap-3 border-2 border-ink bg-sheet px-4 py-3.5">
          <h2 className="type-display text-[26px] leading-none">{t.plans}</h2>
          <div className="w-40">
            <Input label={t.reviewEvery} inputMode="numeric" value={review} onChange={(e) => setReview(e.target.value)} />
          </div>
          <label className="flex items-center gap-2.5 text-[15px]">
            <input type="checkbox" className="size-5 accent-ink" checked={tell} onChange={(e) => setTell(e.target.checked)} />
            {t.tellFamily}
          </label>
          <p className="text-[13px] text-text-muted">{t.tellNote}</p>
          <div>
            <Button busy={busy} onClick={() => void save()}>
              {t.save}
            </Button>
          </div>
        </aside>
      </div>
    </>
  )
}
