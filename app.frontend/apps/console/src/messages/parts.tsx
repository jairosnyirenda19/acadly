import { navigate } from '@acadly/kernel'
import type { ReactNode } from 'react'
import { copy } from '../copy'
import { PageHead, SettingsTabs } from '../settings/parts'

const t = copy.messages

type Tab = keyof typeof t.tabs

const paths: Record<Tab, string> = { channels: '/settings/messages', wording: '/settings/messages/wording', sent: '/settings/messages/sent' }

/** The head of the Messages pages, with the settings tabs and the three Messages tabs. */
export function MessagesHead({ current, intro }: { current: Tab; intro: ReactNode }) {
  return (
    <>
      <PageHead kicker={t.kicker} title={t.title} intro={intro} />
      <SettingsTabs current="messages" />
      <nav aria-label={t.tabsLabel} className="flex gap-1 overflow-x-auto border-b-2 border-ink bg-sheet px-3 [scrollbar-width:none] md:px-[clamp(16px,3vw,40px)]">
        {(Object.keys(paths) as Tab[]).map((tab) => (
          <a
            key={tab}
            href={paths[tab]}
            aria-current={tab === current ? 'page' : undefined}
            onClick={(event) => {
              event.preventDefault()
              navigate(paths[tab])
            }}
            className={`shrink-0 border-b-4 px-4 pt-2.5 pb-2 text-sm whitespace-nowrap text-ink no-underline ${tab === current ? 'border-teal font-extrabold' : 'border-transparent font-medium'}`}
          >
            {t.tabs[tab]}
          </a>
        ))}
      </nav>
    </>
  )
}

/** A section title inside a Messages page. */
export function Heading({ children, aside }: { children: ReactNode; aside?: ReactNode }) {
  return (
    <div className="mb-3.5 flex flex-wrap items-center gap-2.5">
      <h2 className="type-display text-[26px] leading-none">{children}</h2>
      {aside}
    </div>
  )
}

/** Label and value pairs. */
export function Facts({ rows }: { rows: [string, ReactNode][] }) {
  return (
    <dl className="grid grid-cols-[auto_1fr] gap-x-3.5 gap-y-1.5 text-sm">
      {rows.map(([label, value]) => (
        <div key={label} className="contents">
          <dt className="text-text-muted">{label}</dt>
          <dd className="font-bold break-words">{value}</dd>
        </div>
      ))}
    </dl>
  )
}

/** A radio choice drawn as square boxes. */
export function Choice<T extends string>({ label, value, options, onChange, name }: { label: string; value: T; options: { value: T; label: string }[]; onChange: (next: T) => void; name: string }) {
  return (
    <div className="flex flex-col gap-1.5">
      <span className="label-caps text-[11px] text-text-muted">{label}</span>
      <div role="radiogroup" aria-label={label} className="flex flex-wrap gap-1.5">
        {options.map((o) => (
          <label
            key={o.value}
            className={`inline-flex h-10 cursor-pointer items-center gap-2 border-2 px-3.5 text-sm font-extrabold ${value === o.value ? 'border-ink bg-ink text-sheet' : 'border-rule-dashed bg-sheet text-ink hover:bg-field'}`}
          >
            <input type="radio" name={name} checked={value === o.value} onChange={() => onChange(o.value)} className="size-4 accent-teal-bright" />
            {o.label}
          </label>
        ))}
      </div>
    </div>
  )
}
