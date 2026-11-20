import { navigate } from '@acadly/kernel'
import { useEffect, useState, type ReactNode } from 'react'
import { copy } from './copy'
import type { Answer, Me } from './api'

type Tone = 'done' | 'attention' | 'plain' | 'wrong' | 'ink'

const tones: Record<Tone, string> = {
  done: 'bg-teal-wash text-teal',
  attention: 'bg-marigold text-ink',
  plain: 'bg-field text-ink',
  wrong: 'bg-red-wash text-red-text outline outline-1 outline-red-pen',
  ink: 'bg-ink text-sheet',
}

/** A state, always with its word (and a mark where it has one). */
export function Tag({ tone, children }: { tone: Tone; children: ReactNode }) {
  return <span className={`shrink-0 px-2 py-[3px] text-[13px] font-bold whitespace-nowrap ${tones[tone]}`}>{children}</span>
}

/** The student's own band at the top of every page: the college, their number and programme. */
export function Header({ me }: { me: Me | null }) {
  return (
    <header className="flex flex-col gap-1 bg-ink px-4 pt-[18px] pb-4 text-sheet">
      <div className="flex items-center justify-between gap-3">
        <span className="type-display text-[20px] leading-none">{me?.college ?? copy.portal}</span>
        {me !== null && <span className="font-mono text-[13px] text-on-ink-muted">{me.number}</span>}
      </div>
      {me !== null && <span className="text-[13px] text-on-ink-soft">{`${me.programme.name} · Year ${me.year}${me.semester !== null ? ` · ${me.semester}` : ''}`}</span>}
    </header>
  )
}

const TABS = [
  { key: 'today', path: '/' },
  { key: 'courses', path: '/courses' },
  { key: 'fees', path: '/fees' },
  { key: 'news', path: '/news' },
  { key: 'more', path: '/more' },
] as const

/** The tab bar at the bottom, phone first: the current tab carries the teal edge. */
export function Tabs({ current }: { current: (typeof TABS)[number]['key'] }) {
  return (
    <nav aria-label={copy.portal} className="sticky bottom-0 grid grid-cols-5 border-t-2 border-ink bg-sheet">
      {TABS.map((tab) => (
        <a
          key={tab.key}
          href={tab.path}
          aria-current={tab.key === current ? 'page' : undefined}
          onClick={(e) => (e.preventDefault(), navigate(tab.path))}
          className={`flex min-h-12 items-center justify-center border-t-[3px] text-[13px] no-underline ${tab.key === current ? 'border-teal font-bold text-ink' : 'border-transparent text-text-muted'}`}
        >
          {copy.tabs[tab.key]}
        </a>
      ))}
    </nav>
  )
}

/** A page: the header, the content, the tab bar. */
export function Page({ me, tab, children }: { me: Me | null; tab: Parameters<typeof Tabs>[0]['current']; children: ReactNode }) {
  return (
    <div className="mx-auto flex min-h-screen max-w-[640px] flex-col bg-paper text-ink">
      <Header me={me} />
      <main className="flex flex-1 flex-col gap-3 px-4 py-3">{children}</main>
      <Tabs current={tab} />
    </div>
  )
}

export function Title({ children }: { children: ReactNode }) {
  return <h1 className="type-display text-[28px] leading-none">{children}</h1>
}

/** A white sheet with a caption, the portal's one kind of box. */
export function Section({ title, children, strong = false }: { title?: string; children: ReactNode; strong?: boolean }) {
  return (
    <section className={`flex flex-col bg-sheet ${strong ? 'border-2 border-ink' : 'border border-rule'}`}>
      {title !== undefined && <h2 className="border-b border-rule px-3.5 py-2.5 text-[12px] font-bold tracking-[0.06em] text-text-muted uppercase">{title}</h2>}
      {children}
    </section>
  )
}

/** One row: a strong line, a quiet line, and what sits at the right. A link when it has a place to go. */
export function Line({ strong, sub, right, href, onClick }: { strong: ReactNode; sub?: ReactNode; right?: ReactNode; href?: string; onClick?: () => void }) {
  const body = (
    <>
      <span className="flex min-w-0 flex-col">
        <strong>{strong}</strong>
        {sub !== undefined && <span className="text-[14px] text-text-muted">{sub}</span>}
      </span>
      {right}
    </>
  )
  const cls = 'flex min-h-12 items-center justify-between gap-2.5 border-b border-rule px-3.5 py-2.5 text-ink no-underline last:border-b-0'

  if (href !== undefined) {
    return (
      <a href={href} onClick={(e) => (e.preventDefault(), onClick !== undefined ? onClick() : navigate(href))} className={`${cls} hover:bg-field`}>
        {body}
      </a>
    )
  }

  return <div className={cls}>{body}</div>
}

/** Loads a page's data once, with a plain message when it cannot. */
export function useAnswer<T>(load: () => Promise<Answer<T>>, key = ''): { data: T | undefined; failed: boolean; refused: boolean; reload: () => void } {
  const [data, setData] = useState<T | undefined>(undefined)
  const [state, setState] = useState<'wait' | 'failed' | 'refused'>('wait')
  const [round, setRound] = useState(0)

  useEffect(() => {
    let live = true
    void load().then((answer) => {
      if (!live) return
      if (answer === null) setState('failed')
      else if (answer === 'refused') setState('refused')
      else setData(answer)
    })

    return () => {
      live = false
    }
    // Loads again when the key or the round changes.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key, round])

  return { data, failed: state === 'failed', refused: state === 'refused', reload: () => (setState('wait'), setRound(round + 1)) }
}

export function Loading({ failed, onRetry }: { failed: boolean; onRetry: () => void }) {
  if (!failed) return <p className="text-text-muted">{copy.loading}</p>

  return (
    <div className="grid gap-2 border-2 border-ink bg-sheet px-3.5 py-3">
      <p>{copy.failed}</p>
      <button type="button" onClick={onRetry} className="h-11 border-2 border-ink font-extrabold">
        {copy.retry}
      </button>
    </div>
  )
}

/** The main action of a page: teal, 48px, full width; blocked shows its reason beside it. */
export function Primary({ children, onClick, blocked = false, busy = false, submit = false }: { children: ReactNode; onClick?: () => void; blocked?: boolean; busy?: boolean; submit?: boolean }) {
  return (
    <button
      type={submit ? 'submit' : 'button'}
      disabled={blocked || busy}
      onClick={onClick}
      className={`h-12 w-full text-[16px] font-extrabold ${blocked || busy ? 'bg-disabled text-text-muted' : 'bg-teal text-sheet hover:bg-ink'}`}
    >
      {children}
    </button>
  )
}

export function Field({ label, hint, ...rest }: { label: string; hint?: string | undefined } & React.InputHTMLAttributes<HTMLInputElement>) {
  return (
    <label className="grid gap-1">
      <span className="label-caps text-text-muted">{label}</span>
      <input {...rest} className="h-11 border-b-2 border-ink bg-field px-3 text-[16px] outline-none focus:border-teal" />
      {hint !== undefined && <span className="text-[13px] text-text-muted">{hint}</span>}
    </label>
  )
}
