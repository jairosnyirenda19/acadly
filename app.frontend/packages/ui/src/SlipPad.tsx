import { useEffect, useRef, useState, useSyncExternalStore, type KeyboardEvent } from 'react'
import { slips as appSlips, type Slip, type Slips } from './slips'

export interface SlipLabels {
  /** The name of the pad for assistive technology, with its key: "Notices, F8". */
  region: string
  close: string
  /** "3 notices · F8" on the collapsed pad. */
  count: (n: number) => string
  /** "x2" when the same message came again. */
  repeat: (n: number) => string
}

const TICK = 100

const cell: Record<Slip['kind'], string> = {
  done: 'bg-teal text-sheet',
  attention: 'border-2 border-ink bg-marigold text-ink',
  wrong: 'bg-red-pen text-sheet',
  working: 'border-2 border-dashed border-rule-dashed bg-sheet',
}

const glyph: Record<Slip['kind'], string> = { done: '/', attention: '!', wrong: '!', working: '' }

const wordColour: Record<Slip['kind'], string> = { done: 'text-teal', attention: 'text-ink', wrong: 'text-red-text', working: 'text-text-muted' }

const timerColour: Record<Slip['kind'], string> = { done: 'bg-teal', attention: 'bg-ink', wrong: '', working: '' }

function SlipView({ slip, labels, pad, onKey }: { slip: Slip; labels: SlipLabels; pad: Slips; onKey: (e: KeyboardEvent<HTMLDivElement>) => void }) {
  const left = slip.life === null ? null : Math.max(0, 100 - (slip.run / slip.life) * 100)

  return (
    <div
      tabIndex={0}
      onKeyDown={onKey}
      className="relative grid w-full grid-cols-[44px_1fr_auto] items-start gap-3 border-2 border-ink bg-sheet px-3 pt-3 pb-[15px] text-ink outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ink motion-safe:animate-slip-in"
    >
      <div aria-hidden="true" className={`flex size-11 items-center justify-center text-[26px] leading-none font-extrabold ${cell[slip.kind]}`}>
        {glyph[slip.kind]}
      </div>
      <div className="min-w-0 pt-px">
        <div className={`label-caps flex items-center gap-2 text-[11px] ${wordColour[slip.kind]}`}>
          {slip.word}
          {slip.count > 1 && <span className="ref bg-ink px-1.5 py-px text-[11px] tracking-normal text-sheet normal-case">{labels.repeat(slip.count)}</span>}
        </div>
        <div className="mt-[3px] text-[15px] leading-snug font-extrabold">{slip.title}</div>
        {slip.detail !== undefined && <div className="text-sm leading-[1.45] text-text-muted">{slip.detail}</div>}
      </div>
      <div className="flex items-center gap-1.5">
        {slip.action !== undefined && (
          <button
            type="button"
            onClick={() => {
              slip.action?.run()
              pad.close(slip.id)
            }}
            className="h-9 border-2 border-ink px-3 text-sm font-extrabold whitespace-nowrap hover:bg-field"
          >
            {slip.action.label}
          </button>
        )}
        <button type="button" aria-label={labels.close} onClick={() => pad.close(slip.id)} className="flex size-9 items-center justify-center text-text-muted hover:bg-field hover:text-ink">
          <svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3.2" strokeLinecap="square">
            <path d="M5 5l14 14" />
            <path d="M19 5L5 19" />
          </svg>
        </button>
      </div>
      {left !== null && (
        <div aria-hidden="true" className="absolute inset-x-0 bottom-0 h-[3px] bg-field">
          <div className={`h-[3px] ${timerColour[slip.kind]}`} style={{ width: `${left}%` }} />
        </div>
      )}
    </div>
  )
}

/**
 * The pad of slips (the approved design): bottom right on a desktop, under
 * the top bar on a phone. Collapsed it shows the newest slip and the edges
 * of two before it; pointer or focus opens it into a list and stops every
 * timer. F8 moves focus to it; Escape closes the slip that has focus.
 * Messages are announced once: politely, or at once when not saved.
 */
export function SlipPad({ labels, pad = appSlips }: { labels: SlipLabels; pad?: Slips }) {
  const list = useSyncExternalStore(pad.subscribe, pad.list, pad.list)
  const [hover, setHover] = useState(false)
  const [focus, setFocus] = useState(false)
  const [hidden, setHidden] = useState(() => typeof document !== 'undefined' && document.hidden)
  const [said, setSaid] = useState<{ polite: string; urgent: string }>({ polite: '', urgent: '' })
  const known = useRef(new Map<number, number>())
  const region = useRef<HTMLDivElement>(null)
  const open = hover || focus
  const paused = open || hidden

  // Time runs only while nobody is reading and the tab is in view.
  useEffect(() => {
    if (paused) return
    const timer = window.setInterval(() => pad.advance(TICK), TICK)

    return () => window.clearInterval(timer)
  }, [paused, pad])

  useEffect(() => {
    const onVisible = () => setHidden(document.hidden)
    const onKey = (e: globalThis.KeyboardEvent) => {
      if (e.key === 'F8' && region.current !== null && pad.list().length > 0) {
        e.preventDefault()
        region.current.querySelector<HTMLElement>('[tabindex="0"]')?.focus()
      }
    }
    document.addEventListener('visibilitychange', onVisible)
    window.addEventListener('keydown', onKey)

    return () => {
      document.removeEventListener('visibilitychange', onVisible)
      window.removeEventListener('keydown', onKey)
    }
  }, [pad])

  // Announce what is new, once: a new slip, a repeat, or a working slip that finished.
  useEffect(() => {
    for (const slip of list) {
      const stamp = slip.count * 10 + ['working', 'done', 'attention', 'wrong'].indexOf(slip.kind)

      if (known.current.get(slip.id) !== stamp) {
        known.current.set(slip.id, stamp)
        const text = `${[slip.word, slip.title, slip.detail]
          .filter((part): part is string => part !== undefined && part !== '')
          .map((part) => part.replace(/[.\s]+$/, ''))
          .join('. ')}.`
        setSaid((current) => (slip.kind === 'wrong' ? { ...current, urgent: text } : { ...current, polite: text }))
      }
    }
  }, [list])

  const shown = open ? list : list.slice(-1)
  const behind = Math.min(2, list.length - 1)

  const keyOn = (slip: Slip) => (e: KeyboardEvent<HTMLDivElement>) => {
    if (e.key === 'Escape') {
      e.preventDefault()
      pad.close(slip.id)
    }
  }

  return (
    <>
      <div className="sr-only" aria-live="polite" aria-atomic="true">
        {said.polite}
      </div>
      <div className="sr-only" role="alert" aria-atomic="true">
        {said.urgent}
      </div>
      {list.length > 0 && (
        <div
          ref={region}
          role="region"
          aria-label={labels.region}
          onMouseEnter={() => setHover(true)}
          onMouseLeave={() => setHover(false)}
          onFocus={() => setFocus(true)}
          onBlur={(e) => {
            if (!e.currentTarget.contains(e.relatedTarget)) setFocus(false)
          }}
          className="fixed right-6 bottom-6 z-50 flex w-[380px] max-w-[calc(100vw-16px)] flex-col gap-1 max-sm:top-16 max-sm:right-2 max-sm:bottom-auto max-sm:left-2 max-sm:w-auto"
        >
          {!open && list.length > 1 && (
            <div className="label-caps absolute -top-7 right-0 bg-ink px-[7px] py-[3px] text-[11px] text-sheet max-sm:hidden">{labels.count(list.length)}</div>
          )}
          <div className={`relative ${!open && behind > 0 ? (behind > 1 ? 'pb-3' : 'pb-1.5') : ''}`}>
            {!open && behind > 1 && <div aria-hidden="true" className="absolute inset-x-3 bottom-0 h-3.5 border-2 border-t-0 border-ink bg-sheet" />}
            {!open && behind > 0 && <div aria-hidden="true" className={`absolute inset-x-1.5 h-3.5 border-2 border-t-0 border-ink bg-sheet ${behind > 1 ? 'bottom-1.5' : 'bottom-0'}`} />}
            <div className="relative flex flex-col gap-1">
              {shown.map((slip) => (
                <SlipView key={slip.id} slip={slip} labels={labels} pad={pad} onKey={keyOn(slip)} />
              ))}
            </div>
          </div>
        </div>
      )}
    </>
  )
}
