import { navigate } from '@acadly/kernel'
import { Button } from '@acadly/ui'
import { useState, type ReactNode } from 'react'
import { copy } from '../copy'
import { Attention } from '../programmes/parts'
import { Input, Select } from '../setup/parts'

const t = copy.results

export type ResultsTab = 'courses' | 'bar' | 'board' | 'release' | 'setup'
type Can = { setup: boolean; release: boolean; board: boolean; moderate: boolean; enter: boolean; view: boolean }

const TAB_PATHS: Record<ResultsTab, string> = { courses: '/results', bar: '/results/bar', board: '/results/board', release: '/results/release', setup: '/results/setup' }

/** The Results tabs a person may open. */
export function ResultsTabs({ current, can }: { current: ResultsTab; can: Can }) {
  const shown: ResultsTab[] = ['courses', 'bar', ...(can.view ? (['board', 'release'] as const) : []), 'setup']

  return (
    <nav aria-label={t.title} className="flex overflow-x-auto border-b-2 border-ink">
      {shown.map((x) => (
        <button
          key={x}
          type="button"
          aria-current={x === current ? 'page' : undefined}
          onClick={() => navigate(TAB_PATHS[x])}
          className={`min-h-11 border-b-4 px-4 whitespace-nowrap ${x === current ? 'border-teal font-extrabold' : 'border-transparent'}`}
        >
          {t.tabs[x]}
        </button>
      ))}
    </nav>
  )
}

const LADDER = ['entered', 'submitted', 'moderated', 'approved', 'released'] as const

/** Where a result sheet stands on its way to release: done steps ticked, the current one marked. */
export function Ladder({ state }: { state: string }) {
  const at = LADDER.indexOf(state as (typeof LADDER)[number])

  return (
    <ol className="flex flex-wrap border-2 border-ink" aria-label={t.states[state] ?? state}>
      {LADDER.map((s, i) => (
        <li
          key={s}
          aria-current={i === at ? 'step' : undefined}
          className={`flex min-h-10 flex-1 items-center gap-2 px-3 py-2 text-[14px] whitespace-nowrap ${i > 0 ? 'border-l-2 border-ink' : ''} ${i === at ? 'bg-ink font-extrabold text-sheet' : i < at ? 'bg-sheet' : 'bg-paper text-text-muted'}`}
        >
          <span aria-hidden="true">{i < at ? '✓' : i === at ? '●' : ''}</span>
          {t.states[s]}
        </li>
      ))}
    </ol>
  )
}

/** A row of figures, the first one on ink. */
export function Figures({ items }: { items: { label: string; value: string; tone?: 'ink' | 'attention' | 'wrong' | 'sheet' }[] }) {
  return (
    <div className="grid grid-cols-2 border-2 border-ink sm:grid-cols-4">
      {items.map((x, i) => {
        const tone = x.tone ?? (i === 0 ? 'ink' : 'sheet')

        return (
          <div
            key={x.label}
            className={`px-4 py-3 ${i > 0 ? 'border-l-2 border-ink' : ''} ${i === 2 ? 'max-sm:border-l-0 max-sm:border-t-2' : ''} ${i === 3 ? 'max-sm:border-t-2' : ''} ${tone === 'ink' ? 'bg-ink text-sheet' : tone === 'attention' ? 'bg-marigold' : 'bg-sheet'}`}
          >
            <span className={`label-caps ${tone === 'ink' ? 'text-on-ink-muted' : 'text-text-muted'}`}>{x.label}</span>
            <div className={`type-display text-[36px] leading-none ${tone === 'ink' ? 'text-teal-bright' : tone === 'wrong' ? 'text-red-text' : ''}`}>{x.value}</div>
          </div>
        )
      })}
    </div>
  )
}

/** The semester a page shows. */
export function PeriodChoice({ periods, period, choose }: { periods: { id: string; name: string }[]; period: string; choose: (id: string) => void }) {
  if (periods.length < 2) return null

  return (
    <Select label={t.semester} value={period} onChange={(e) => choose(e.target.value)}>
      {periods.map((p) => (
        <option key={p.id} value={p.id}>
          {p.name}
        </option>
      ))}
    </Select>
  )
}

/** An action that needs a reason: the button opens a box for it. */
export function WithReason({
  label,
  prompt,
  onConfirm,
  variant = 'secondary',
}: {
  label: string
  prompt: string
  onConfirm: (reason: string) => Promise<boolean>
  variant?: 'primary' | 'secondary'
}) {
  const [open, setOpen] = useState(false)
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  if (!open) {
    return (
      <Button variant={variant} onClick={() => setOpen(true)}>
        {label}
      </Button>
    )
  }

  return (
    <div className="grid gap-3 border-2 border-ink bg-sheet p-3">
      <Input label={prompt} value={reason} required maxLength={255} onChange={(e) => setReason(e.target.value)} />
      <div className="flex flex-wrap gap-2">
        <Button
          busy={busy}
          disabled={reason.trim() === ''}
          onClick={async () => {
            setBusy(true)
            const ok = await onConfirm(reason.trim())
            setBusy(false)
            if (ok) {
              setOpen(false)
              setReason('')
            }
          }}
        >
          {label}
        </Button>
        <Button variant="secondary" onClick={() => setOpen(false)}>
          {t.bar.cancel}
        </Button>
      </div>
    </div>
  )
}

export function NoResultsDivision() {
  return (
    <div className="px-[clamp(16px,3vw,40px)] pt-7">
      <Attention>
        <b>{t.chooseTitle}.</b> {t.choose}
      </Attention>
    </div>
  )
}

export function Panel({ title, aside, children }: { title: string; aside?: ReactNode; children: ReactNode }) {
  return (
    <section aria-label={title} className="border-2 border-ink bg-sheet">
      <header className="flex flex-wrap items-center justify-between gap-2 border-b-2 border-ink px-4 py-2">
        <h2 className="label-caps">{title}</h2>
        {aside}
      </header>
      <div className="p-4">{children}</div>
    </section>
  )
}
