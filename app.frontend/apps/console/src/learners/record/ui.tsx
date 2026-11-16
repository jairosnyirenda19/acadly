import { navigate } from '@acadly/kernel'
import type { MouseEvent, ReactNode } from 'react'

/** The record's tabs: the overview, one per part of the record, the messages and the history. */
export type RecordTab = string

/** The address of a tab of a learner's record; the overview is the record's own address. */
export const tabHref = (id: string, tab: RecordTab) => `/learners/${id}${tab === 'overview' ? '' : `?tab=${encodeURIComponent(tab)}`}`

/** Follows a link inside the app; a click with a modifier key keeps the browser's own behaviour. */
export function follow(event: MouseEvent<HTMLAnchorElement>, path: string) {
  if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return
  event.preventDefault()
  navigate(path)
}

/** A link inside the app, drawn as a heavy underlined word. */
export function InLink({ href, children }: { href: string; children: ReactNode }) {
  return (
    <a href={href} onClick={(e) => follow(e, href)} className="inline-flex min-h-11 items-center text-sm font-extrabold text-ink underline underline-offset-[3px]">
      {children}
    </a>
  )
}

/** One ruled column of the record. A second column is divided from the first by a 2px ink rule. */
export function Column({ children, edge = false, grow = 'flex-[1_1_300px]', tinted = false, label }: { children: ReactNode; edge?: boolean; grow?: string; tinted?: boolean; label?: string | undefined }) {
  return (
    <section
      aria-label={label}
      className={`min-w-0 px-4 pt-4.5 pb-5.5 md:px-7 ${grow} ${tinted ? 'bg-paper' : 'bg-sheet'} ${edge ? 'border-ink max-[700px]:border-t-2 min-[701px]:border-l-2' : ''}`}
    >
      {children}
    </section>
  )
}

/** A row of ruled columns. */
export function Columns({ children, top = false }: { children: ReactNode; top?: boolean }) {
  return <div className={`flex flex-wrap items-stretch ${top ? 'border-t-2 border-ink' : ''}`}>{children}</div>
}

export function SectionTitle({ children, aside }: { children: ReactNode; aside?: ReactNode }) {
  return (
    <div className="mb-2.5 flex items-baseline gap-2.5">
      <h2 className="type-display flex-1 text-[26px] leading-none">{children}</h2>
      {aside}
    </div>
  )
}

/** What a small block is, above it. */
export function Caption({ children }: { children: ReactNode }) {
  return <div className="label-caps mb-1.5 text-text-muted">{children}</div>
}

/** A ledger that scrolls sideways inside its own box when the screen is narrow. */
export function LedgerFrame({ children, label }: { children: ReactNode; label: string }) {
  return (
    <div role="region" aria-label={label} tabIndex={0} className="overflow-x-auto">
      {children}
    </div>
  )
}

/** The head of a ledger column. */
export function Th({ children, end = false }: { children?: ReactNode; end?: boolean }) {
  return <th scope="col" className={`label-caps border-b-2 border-ink px-3 pt-1 pb-2 align-bottom whitespace-nowrap text-text-muted ${end ? 'text-right' : 'text-left'}`}>{children}</th>
}

/** The first cell of a ledger row: the day or time, left of the red margin line. */
export function MarginCell({ children }: { children: ReactNode }) {
  return <td className="ref border-r-2 border-b border-r-red-pen border-b-rule py-2.5 pr-3 align-top text-[13px] whitespace-nowrap">{children}</td>
}

export function Td({ children, wide = false, end = false }: { children?: ReactNode; wide?: boolean; end?: boolean }) {
  return <td className={`border-b border-rule px-3 py-2.5 align-top text-sm ${wide ? 'min-w-[200px]' : 'whitespace-nowrap'} ${end ? 'text-right' : ''}`}>{children}</td>
}
