import { getHealth } from '@acadly/kernel'
import { Button } from '@acadly/ui'
import { useCallback, useEffect, useState } from 'react'

type Status = 'checking' | 'operational' | 'unavailable'

const copy: Record<Status, { word: string; detail: string }> = {
  checking: { word: 'Checking', detail: 'The status check is in progress.' },
  operational: { word: 'Operational', detail: 'The service is available.' },
  unavailable: { word: 'Unavailable', detail: 'The service cannot be reached. Try again shortly.' },
}

const mark: Record<Status, { glyph: string; look: string }> = {
  checking: { glyph: '', look: 'border-2 border-dashed border-rule-dashed bg-sheet' },
  operational: { glyph: '/', look: 'bg-teal text-sheet' },
  unavailable: { glyph: 'O', look: 'border-[3px] border-red-pen bg-sheet text-red-pen' },
}

/**
 * Service status. A single page with no navigation: it states one fact,
 * whether the service is available. It shows no release, module or
 * technology detail.
 */
export function App() {
  const [status, setStatus] = useState<Status>('checking')
  const [checkedAt, setCheckedAt] = useState<Date | null>(null)

  const check = useCallback(() => {
    setStatus('checking')
    getHealth()
      .then(() => setStatus('operational'))
      .catch(() => setStatus('unavailable'))
      .finally(() => setCheckedAt(new Date()))
  }, [])

  useEffect(check, [check])

  const { word, detail } = copy[status]
  const { glyph, look } = mark[status]

  return (
    <div className="flex min-h-screen flex-col">
      <header className="border-b-2 border-ink px-[clamp(16px,4vw,40px)] py-4">
        <div className="type-display text-[30px]">
          Acadly <span className="text-teal">IQ-X</span>
        </div>
      </header>

      <main className="mx-auto flex w-full max-w-[720px] flex-1 flex-col justify-center px-[clamp(16px,4vw,40px)] py-10">
        <h1 className="label-caps text-text-muted">Service status</h1>

        <section aria-live="polite" className="mt-3 flex items-center gap-5 border-y-2 border-ink py-6">
          <div role="img" aria-label={word} className={`grid size-24 shrink-0 place-items-center text-[64px] leading-none font-extrabold ${look}`}>
            {glyph}
          </div>
          <div className="min-w-0">
            <div className="type-display text-[clamp(44px,9vw,84px)]">{word}</div>
            <p className="mt-2">{detail}</p>
          </div>
        </section>

        <div className="mt-5 flex flex-wrap items-center justify-between gap-4">
          <p className="text-[13px] text-text-muted">
            {checkedAt === null ? 'Not checked yet.' : (
              <>
                Last checked at <span className="ref text-ink">{checkedAt.toLocaleTimeString()}</span>
              </>
            )}
          </p>
          <Button variant="secondary" onClick={check} disabled={status === 'checking'}>
            Check again
          </Button>
        </div>
      </main>
    </div>
  )
}
