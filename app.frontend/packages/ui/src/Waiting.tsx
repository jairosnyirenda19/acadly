import { useEffect, useState, type ReactNode } from 'react'

/**
 * While a page waits for its data (the approved "Loading" design): the
 * register is ruled before it is written in. Dashed cells hold the places
 * where the data will land. No spinner, no shimmer.
 */

export interface WaitingLabels {
  /** Said once to assistive technology: "Loading People". */
  loading: (what: string) => string
  slowTitle: string
  slowText: string
  failedTitle: string
  failedText: string
  retry: string
  refusedTitle: string
  refusedText: string
  /** Not found, or outside the place the user works in. */
  missingTitle?: string
  missingText?: string
}

type Phase = 'quiet' | 'waiting' | 'slow' | 'ready' | 'refused' | 'missing' | 'failed'

const dashed = 'border-2 border-dashed border-rule-dashed'

function Bar({ width, height = 12 }: { width: string; height?: number }) {
  return <span aria-hidden="true" className="block bg-field" style={{ width, height }} />
}

/** The totals band, with dashed boxes where the numbers will land. */
export function WaitingBand({ labels }: { labels: string[] }) {
  return (
    <div aria-hidden="true" className="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] border-y-2 border-ink">
      {labels.map((label, i) => (
        <section key={label} className={`px-[clamp(16px,3vw,24px)] pt-4.5 pb-5 ${i === 0 ? 'bg-ink' : 'border-l-2 border-ink'}`}>
          <div className={`label-caps text-[11px] ${i === 0 ? 'text-on-ink-muted' : 'text-text-muted'}`}>{label}</div>
          <span className={`my-2 block h-[84px] w-24 border-2 border-dashed ${i === 0 ? 'border-ink-rule' : 'border-rule-dashed'}`} />
          <span className={`block h-2.5 w-[70%] ${i === 0 ? 'bg-ink-rule' : 'bg-field'}`} />
        </section>
      ))}
    </div>
  )
}

const widths = ['42%', '55%', '38%', '48%', '35%', '52%', '44%', '58%']

/** Ledger rows: a dashed cell left of the margin line, bars for the text, a dashed tag on the right. */
export function WaitingRows({ rows = 5 }: { rows?: number }) {
  return (
    <div aria-hidden="true" className="ledger [--margin:48px]">
      {Array.from({ length: rows }, (_, i) => (
        <div key={i} className="grid grid-cols-[48px_1fr_auto] items-center gap-3 border-b border-rule py-3.5 pr-4">
          <span className={`mx-auto block h-5 w-6 ${dashed}`} />
          <span className="flex flex-col gap-1.5">
            <Bar width={widths[i % widths.length] ?? '45%'} height={14} />
            <Bar width="30%" height={10} />
          </span>
          <span className={`block h-5 w-16 ${dashed}`} />
        </div>
      ))}
    </div>
  )
}

/** A short boxed line: the connection is slow, or the page failed or was refused. */
function Line({ tone, title, children }: { tone: 'attention' | 'wrong'; title: string; children: ReactNode }) {
  return (
    <div role={tone === 'wrong' ? 'alert' : 'status'} className={`border-2 px-3.5 py-3 text-sm leading-[1.45] ${tone === 'wrong' ? 'border-red-pen bg-red-wash' : 'border-ink bg-marigold-wash'}`}>
      <strong className={tone === 'wrong' ? 'block' : ''}>{title}</strong> {children}
    </div>
  )
}

/**
 * What a page shows until its data is there. `band` names the totals the page
 * will show; `rows` how many ledger rows to draw.
 */
export function Waiting({ phase, what, labels, band, rows = 5, onRetry }: { phase: Phase; what: string; labels: WaitingLabels; band?: string[] | undefined; rows?: number; onRetry?: () => void }) {
  if (phase === 'ready') return null

  if (phase === 'failed' || phase === 'refused' || phase === 'missing') {
    const title = phase === 'failed' ? labels.failedTitle : phase === 'refused' ? labels.refusedTitle : (labels.missingTitle ?? labels.refusedTitle)
    const text = phase === 'failed' ? labels.failedText : phase === 'refused' ? labels.refusedText : (labels.missingText ?? labels.refusedText)

    return (
      <div className="px-[clamp(16px,3vw,40px)] py-6">
        <Line tone="wrong" title={title}>
          {text}
          {phase === 'failed' && onRetry !== undefined && (
            <span className="mt-2.5 block">
              <button type="button" onClick={onRetry} className="h-9 border-2 border-ink bg-sheet px-3 text-sm font-extrabold hover:bg-field">
                {labels.retry}
              </button>
            </span>
          )}
        </Line>
      </div>
    )
  }

  return (
    <div aria-busy="true">
      <span className="sr-only" role="status">
        {labels.loading(what)}
      </span>
      {phase !== 'quiet' && (
        <div className="animate-settle">
          {band !== undefined && <WaitingBand labels={band} />}
          <div className="bg-sheet px-[clamp(16px,3vw,40px)] pt-5 pb-8">
            {phase === 'slow' && (
              <div className="mb-4">
                <Line tone="attention" title={labels.slowTitle}>
                  {labels.slowText}
                </Line>
              </div>
            )}
            <WaitingRows rows={rows} />
          </div>
        </div>
      )}
    </div>
  )
}

/**
 * The teal rule under the top bar: it fills while anything loads and is
 * removed when it is done. The only thing that moves. With reduced motion it
 * stands still at half.
 */
export function LoadingRule({ active }: { active: boolean }) {
  const [shown, setShown] = useState(active)
  const [width, setWidth] = useState(0)

  useEffect(() => {
    if (active) {
      setShown(true)
      setWidth(0)
      const start = window.requestAnimationFrame(() => setWidth(90))

      return () => window.cancelAnimationFrame(start)
    }

    setWidth(100)
    const end = window.setTimeout(() => setShown(false), 380)

    return () => window.clearTimeout(end)
  }, [active])

  if (!shown) return null

  return (
    <div aria-hidden="true" className="pointer-events-none absolute inset-x-0 -bottom-0.5 h-[3px]">
      <div
        className="h-[3px] bg-teal motion-reduce:!w-1/2 motion-reduce:!transition-none"
        style={{
          width: `${width}%`,
          opacity: width === 100 ? 0 : 1,
          transition: width === 100 ? 'width 180ms cubic-bezier(0.25, 1, 0.5, 1), opacity 200ms ease-out 160ms' : width === 0 ? 'none' : 'width 8s cubic-bezier(0.1, 0.6, 0.2, 1)',
        }}
      />
    </div>
  )
}
