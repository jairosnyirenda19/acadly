import { navigate } from '@acadly/kernel'
import { Button, Notice } from '@acadly/ui'
import { createContext, useContext, type ReactNode } from 'react'
import { words, type Language, type Words } from './copy'

const KEY = 'acadly.portal.language'

/** The language the parent chose. Kept on this phone only; English until they choose. */
export function savedLanguage(): Language {
  try {
    return window.localStorage.getItem(KEY) === 'ny' ? 'ny' : 'en'
  } catch {
    return 'en'
  }
}

export function keepLanguage(language: Language): void {
  try {
    window.localStorage.setItem(KEY, language)
  } catch {
    // Not kept on this phone: the choice lasts until the page closes.
  }
}

export const LanguageContext = createContext<{ language: Language; choose: (l: Language) => void }>({ language: 'en', choose: () => undefined })

/** The words in the parent's language. */
export function useWords(): Words {
  return words[useContext(LanguageContext).language]
}

/** Follows a link inside the portal without reloading the page. */
export function go(event: { preventDefault: () => void }, to: string): void {
  event.preventDefault()
  navigate(to)
}

/** The top bar: the school's name, or the way back; and the language switch. */
export function Bar({ school, back }: { school: string; back?: { to: string; label: string } }) {
  const t = useWords()
  const { language, choose } = useContext(LanguageContext)

  return (
    <header className="flex min-h-[60px] items-center gap-2.5 border-b-2 border-ink bg-sheet px-4 print:hidden">
      <span className="min-w-0 flex-1">
        {back !== undefined ? (
          <a href={back.to} onClick={(e) => go(e, back.to)} className="inline-flex min-h-11 items-center text-base font-extrabold text-ink">
            ‹ {back.label}
          </a>
        ) : (
          <span className="block truncate text-base font-extrabold">{school}</span>
        )}
      </span>
      <span role="group" aria-label={t.language} className="flex border-2 border-ink text-[13px] font-extrabold">
        {(['en', 'ny'] as const).map((l) => (
          <button
            key={l}
            type="button"
            aria-pressed={language === l}
            onClick={() => choose(l)}
            className={`min-h-9 px-2.5 ${language === l ? 'bg-ink text-sheet' : 'bg-sheet text-ink'}`}
          >
            {l === 'en' ? t.english : t.chichewa}
          </button>
        ))}
      </span>
    </header>
  )
}

/** A child's badge: the initials on ink. A badge is never a state colour. */
export function Badge({ name }: { name: string }) {
  const initials = name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p[0]?.toUpperCase() ?? '')
    .join('')

  return (
    <span aria-hidden="true" className="type-display grid size-[52px] shrink-0 place-items-center bg-ink text-[22px] text-sheet">
      {initials}
    </span>
  )
}

const ICONS = {
  card: (
    <>
      <path d="M5 3h10l4 4v14H5z" />
      <path d="M9 12h6" />
      <path d="M9 16h6" />
    </>
  ),
  cal: (
    <>
      <rect x="4" y="5" width="16" height="15" />
      <path d="M8 3v4" />
      <path d="M16 3v4" />
      <path d="M4 10h16" />
    </>
  ),
  bell: (
    <>
      <path d="M6 16V11a6 6 0 0 1 12 0v5l2 2H4z" />
      <path d="M10 20h4" />
    </>
  ),
  call: <path d="M5 4h4l2 5-3 2a11 11 0 0 0 5 5l2-3 5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z" />,
}

export function Icon({ name }: { name: keyof typeof ICONS }) {
  return (
    <svg aria-hidden="true" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="square">
      {ICONS[name]}
    </svg>
  )
}

export type Tone = 'done' | 'attention' | 'wrong' | 'plain'

/** A state's mark: a sign on a colour, always with words beside it. */
export function Mark({ tone }: { tone: Tone }) {
  const look = { done: 'bg-teal text-sheet', attention: 'bg-marigold text-ink', wrong: 'bg-red-pen text-sheet', plain: 'border-2 border-rule-dashed text-text-muted' }[tone]
  const sign = { done: '✓', attention: '!', wrong: '!', plain: '·' }[tone]

  return (
    <span aria-hidden="true" className={`grid size-[26px] shrink-0 place-items-center font-extrabold ${look}`}>
      {sign}
    </span>
  )
}

/** One big door on a child's page: an icon, a title and a note, the whole row a link. */
export function Tile({ icon, title, note, href, fresh = false }: { icon: keyof typeof ICONS; title: string; note: string; href: string; fresh?: boolean }) {
  const external = href.startsWith('tel:')

  return (
    <a
      href={href}
      onClick={external ? undefined : (e) => go(e, href)}
      className={`mb-2.5 flex min-h-[84px] items-center gap-3.5 border-2 border-ink px-4 text-ink no-underline ${fresh ? 'bg-marigold-wash' : 'bg-sheet'}`}
    >
      <span className="grid size-[52px] shrink-0 place-items-center bg-ink text-sheet">
        <Icon name={icon} />
      </span>
      <span className="min-w-0 flex-1">
        <span className="block text-xl font-extrabold">{title}</span>
        <span className="block text-[15px]">{note}</span>
      </span>
      <span aria-hidden="true" className="text-[26px] font-extrabold">
        ›
      </span>
    </a>
  )
}

/** While a screen loads, and when it cannot. */
export function Loading({ failed, onRetry }: { failed: boolean; onRetry: () => void }) {
  const t = useWords()

  if (!failed) {
    return (
      <div className="grid gap-3 p-4" aria-busy="true">
        <span className="sr-only">{t.loading}</span>
        {[0, 1, 2].map((i) => (
          <span key={i} className="block h-[84px] border-2 border-dashed border-rule-dashed bg-sheet" />
        ))}
      </div>
    )
  }

  return (
    <div className="grid gap-3 p-4">
      <Notice tone="wrong" title={t.unavailableTitle}>
        {t.unavailable}
      </Notice>
      <Button variant="secondary" className="h-14 text-[17px]" onClick={onRetry}>
        {t.retry}
      </Button>
    </div>
  )
}

/** A screen of the portal: phone width, the bar on top. */
export function Screen({ bar, children, foot }: { bar: ReactNode; children: ReactNode; foot?: ReactNode }) {
  return (
    <div className="mx-auto flex min-h-screen max-w-[560px] flex-col bg-paper text-ink print:max-w-none print:bg-sheet">
      {bar}
      <main className="flex-1">{children}</main>
      {foot !== undefined && <div className="sticky bottom-0 border-t-2 border-ink bg-sheet px-[18px] pt-4 pb-[22px] print:hidden">{foot}</div>}
    </div>
  )
}

/** Dates as people say them: Monday 7 December. */
export const longDay = (iso: string) => new Date(`${iso.slice(0, 10)}T12:00:00`).toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long' })
export const shortDay = (iso: string) => new Date(`${iso.slice(0, 10)}T12:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })
export const when = (iso: string) =>
  new Date(iso).toLocaleString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })

/** A note that is not an error: marigold when the parent should act, plain otherwise. */
export function Note({ tone, title, children }: { tone: 'attention' | 'plain'; title: ReactNode; children?: ReactNode }) {
  return (
    <div role="status" className={`border-l-4 px-3.5 py-3 text-[15px] leading-[1.45] ${tone === 'attention' ? 'border-marigold bg-marigold-wash' : 'border-ink bg-sheet'}`}>
      <strong className="block text-base">{title}</strong>
      {children}
    </div>
  )
}
