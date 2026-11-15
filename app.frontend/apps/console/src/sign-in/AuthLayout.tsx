import { navigate } from '@acadly/kernel'
import type { ReactNode } from 'react'
import { copy } from '../copy'

/**
 * The frame of every sign-in screen: the school on a navy panel, the form on
 * a ruled register page with the red margin line.
 */
export function AuthLayout({ school, children }: { school: string | null; children: ReactNode }) {
  return (
    <div className="flex min-h-screen flex-wrap">
      <section className="flex flex-[1_1_420px] flex-col justify-between gap-10 bg-ink px-[clamp(24px,4vw,64px)] py-10 text-sheet">
        <img src="/brand-mark-dark.svg" alt={copy.product} className="size-24" />
        <div>
          <div className="label-caps text-[11px] tracking-[0.16em] text-on-ink-muted">{copy.panelKicker}</div>
          <div className="type-display mt-2.5 text-[clamp(64px,8vw,120px)] break-words">{school ?? copy.schoolFallback}</div>
          <div className="mt-6 max-w-[420px] border-t-2 border-ink-rule" />
          <p className="mt-3.5 max-w-[420px] leading-normal text-on-ink-soft">{copy.panelText}</p>
        </div>
        <div className="flex flex-wrap justify-between gap-3 text-xs text-on-ink-muted">
          <span className="type-display text-lg text-sheet">
            Acadly <span className="text-teal-bright">IQ-X</span>
          </span>
          <span>{copy.panelNotice}</span>
        </div>
      </section>

      <main className="ledger ruled flex flex-[1_1_520px] items-center justify-center px-[clamp(24px,5vw,96px)] py-12 [--margin:clamp(16px,3vw,56px)]">
        <div className="relative flex w-full max-w-[420px] flex-col gap-5">{children}</div>
      </main>
    </div>
  )
}

export function Heading({ kicker, title }: { kicker: string; title: string }) {
  return (
    <div>
      <div className="label-caps text-[11px] tracking-[0.16em] text-text-muted">{kicker}</div>
      <h1 className="type-display mt-1.5 text-[72px]">{title}</h1>
    </div>
  )
}

/** A link to another screen of the app, without a page reload. */
export function InLink({ to, className = '', children }: { to: string; className?: string; children: ReactNode }) {
  return (
    <a
      href={to}
      onClick={(event) => {
        event.preventDefault()
        navigate(to)
      }}
      className={`text-[13px] font-bold text-teal hover:text-ink ${className}`}
    >
      {children}
    </a>
  )
}
