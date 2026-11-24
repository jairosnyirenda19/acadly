import { useLoad } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { feesApi, type PortalFees } from '../fees/api'
import { TextArea } from '../setup/parts'
import { day } from './api'

const t = copy.settings.fees.portal

/** Settings › Fees › Parent portal (the approved design): whether families see fees, and how to pay. */
export function FeesPortalSettings() {
  const loaded = useLoad('fees:portal', () => feesApi.portal())

  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} rows={3} onRetry={loaded.reload} />

  return <PortalEditor initial={loaded.data} />
}

function PortalEditor({ initial }: { initial: PortalFees }) {
  const [show, setShow] = useState(initial.show_to_families)
  const [howToPay, setHowToPay] = useState(initial.how_to_pay)
  const [saved, setSaved] = useState(initial.updated_at)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const max = initial.max
  const over = howToPay.length > max

  const save = async () => {
    setBusy(true)
    setError(null)
    const result = await feesApi.savePortal({ show_to_families: show, how_to_pay: howToPay })
    setBusy(false)
    if (result.kind === 'saved') {
      setHowToPay(result.data.how_to_pay)
      setSaved(result.data.updated_at)
      slips.done({ word: copy.slips.saved, title: t.saved })
    } else if (result.kind === 'invalid') setError(result.errors.how_to_pay?.[0] ?? Object.values(result.errors)[0]?.[0] ?? copy.settings.fees.failed)
    else slips.wrong({ word: copy.settings.fees.notSaved, title: copy.settings.fees.failed })
  }

  return (
    <div className="grid grid-cols-[minmax(0,1fr)] items-start gap-x-8 gap-y-6 xl:grid-cols-[minmax(0,1fr)_360px]">
      <div className="grid max-w-[760px] gap-5">
        <div>
          <h2 className="type-display text-[26px] leading-none">{t.title}</h2>
          <p className="mt-1 text-sm text-text-muted">{t.intro}</p>
        </div>

        <section aria-label={t.inPortal} className="border-2 border-ink bg-sheet">
          <div className="label-caps border-b border-rule px-4 py-2.5 text-text-muted">{t.inPortal}</div>
          <div className="grid gap-2 px-4 py-3">
            <label className="flex min-h-11 items-center gap-2.5 text-[15px] font-extrabold">
              <input type="checkbox" className="size-5 accent-ink" checked={show} onChange={(e) => setShow(e.target.checked)} />
              {t.show}
            </label>
            <p className="text-[13px] text-text-muted">{show ? t.showNote : t.hidden}</p>
          </div>
        </section>

        <section aria-label={t.howToPay} className="grid gap-3 border-2 border-ink bg-sheet px-4 py-3.5">
          <div className="label-caps text-text-muted">{t.howToPay}</div>
          <TextArea
            label={t.howToPayLabel}
            rows={6}
            value={howToPay}
            onChange={(e) => setHowToPay(e.target.value)}
            hint={t.howToPayHint(max)}
            error={error ?? undefined}
          />
          <p className={`ref text-xs ${over ? 'font-extrabold text-red-text' : 'text-text-muted'}`}>
            {over ? '✕ ' : ''}
            {t.count(howToPay.length, max)}
          </p>
          {error !== null && (
            <Notice tone="wrong" title={copy.settings.fees.notSaved}>
              {error}
            </Notice>
          )}
          <div className="flex flex-wrap items-center gap-3">
            <Button busy={busy} disabled={over} onClick={() => void save()}>
              {t.save}
            </Button>
            {saved !== null && <span className="text-[13px] text-text-muted">{t.lastSaved(day(saved))}</span>}
          </div>
        </section>
      </div>

      <aside aria-label={t.preview} className="grid gap-2">
        <div className="label-caps text-text-muted">{t.preview}</div>
        <div className="max-w-[360px] border-2 border-ink bg-paper">
          <div className="border-b-2 border-ink bg-sheet px-4 py-3">
            <span className="label-caps text-text-muted">{t.previewKicker}</span>
          </div>
          {show ? (
            <div className="bg-sheet px-4 py-3">
              <span className="label-caps text-text-muted">{t.howToPay}</span>
              <p className="mt-1 text-sm leading-[1.45] whitespace-pre-line">{howToPay.trim() === '' ? t.previewEmpty : howToPay}</p>
            </div>
          ) : (
            <p className="px-4 py-3 text-sm">{t.hidden}</p>
          )}
        </div>
      </aside>
    </div>
  )
}
