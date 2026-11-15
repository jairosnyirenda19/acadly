import { useAppContext, type Place } from '@acadly/kernel'
import { createContext, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { copy } from '../copy'

const t = copy.shell.workIn

interface WorkIn {
  /** The place chosen, or null when the account has no place. */
  place: Place | null
  choose: (key: string) => void
  /** Whether there is more than one place to choose from. */
  choice: boolean
}

const Shared = createContext<WorkIn>({ place: null, choose: () => {}, choice: false })

const storageKey = (user: string) => `acadly.work-in.${user}`

function remembered(user: string): string | null {
  try {
    return window.localStorage.getItem(storageKey(user))
  } catch {
    return null
  }
}

/** The place the account works in, remembered on this device. Only a filter: the server decides. */
export function WorkInProvider({ children }: { children: ReactNode }) {
  const context = useAppContext()
  const places = context.places
  const fallback = places.find((p) => p.key === 'institution:') ?? places.find((p) => p.held) ?? places[0] ?? null
  const [key, setKey] = useState<string | null>(() => remembered(context.user.id))
  const place = places.find((p) => p.key === key) ?? fallback

  const value = useMemo<WorkIn>(
    () => ({
      place,
      choice: places.length > 1,
      choose: (next) => {
        setKey(next)
        try {
          window.localStorage.setItem(storageKey(context.user.id), next)
        } catch {
          // Not remembered on this device; the choice still holds for this visit.
        }
      },
    }),
    [place, places.length, context.user.id],
  )

  return <Shared.Provider value={value}>{children}</Shared.Provider>
}

export function useWorkIn(): WorkIn {
  return useContext(Shared)
}

/** The places directly inside a place: nothing listed stands between them. */
function childrenOf(places: Place[], parent: string): Place[] {
  const byKey = new Map(places.map((p) => [p.key, p]))

  return places.filter((p) => {
    const at = p.within.indexOf(parent)

    if (p.key === parent || at < 1) return false

    return p.within.slice(1, at).every((between) => !(byKey.get(between)?.within.includes(parent) ?? false))
  })
}

function day(iso: string): string {
  return new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })
}

/** The "Work in" button at the top of the rail. Without a choice to make, it is a plain label. */
export function WorkInButton({ open, onToggle }: { open: boolean; onToggle: () => void }) {
  const context = useAppContext()
  const { place, choice } = useWorkIn()
  const label = place?.label ?? copy.shell.wholeSchool

  if (!choice) {
    return (
      <div className="border-y border-ink-rule px-5 py-3">
        <div className="label-caps text-on-ink-muted">{t.workingIn}</div>
        <div className="text-[15px] font-extrabold">{label}</div>
        <div className="text-[13px] text-on-ink-soft">{context.school.name}</div>
      </div>
    )
  }

  return (
    <button
      type="button"
      aria-expanded={open}
      aria-label={t.change(label)}
      onClick={onToggle}
      className={`flex w-full items-center gap-2.5 border-y border-ink-rule px-5 py-3 text-left ${open ? 'bg-teal-bright text-ink' : 'text-sheet hover:bg-ink-rule'}`}
    >
      <span className="min-w-0 flex-1">
        <span className={`label-caps block ${open ? '' : 'text-on-ink-muted'}`}>{t.workingIn}</span>
        <span className="block text-[15px] font-extrabold">{label}</span>
        <span className={`block text-[13px] ${open ? '' : 'text-on-ink-soft'}`}>{context.school.name}</span>
      </span>
      <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="square">
        <path d="M8 9l4-4 4 4" />
        <path d="M8 15l4 4 4-4" />
      </svg>
    </button>
  )
}

/** The panel that lists where the account can work. */
export function WorkInPanel({ onClose, className = '' }: { onClose: () => void; className?: string }) {
  const { places } = useAppContext()
  const { place, choose } = useWorkIn()
  const [query, setQuery] = useState('')

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose()
    }
    window.addEventListener('keydown', onKey)

    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])

  const pick = (key: string) => {
    choose(key)
    onClose()
  }

  const held = places.filter((p) => p.held)
  const q = query.trim().toLowerCase()
  const found = q === '' ? [] : places.filter((p) => p.label.toLowerCase().includes(q))

  const line = (p: Place, depth: number, withRoles: boolean) => {
    const current = p.key === place?.key
    const roles = withRoles ? p.roles.map((r) => (r.ends_at !== null ? t.roleUntil(r.role, day(r.ends_at)) : r.role)).join('. ') : ''
    const small = depth >= 2

    return (
      <button
        key={`${p.key}:${depth}`}
        type="button"
        aria-current={current ? 'true' : undefined}
        onClick={() => pick(p.key)}
        style={{ paddingLeft: `${16 + depth * 22}px` }}
        className={`relative flex w-full items-center gap-2.5 border-b border-l-4 border-b-rule py-2 pr-4 text-left ${small ? 'min-h-11' : 'min-h-14'} ${current ? 'border-l-teal-bright bg-ink text-sheet' : 'border-l-transparent bg-sheet text-ink hover:bg-paper'}`}
      >
        {depth > 0 && <span aria-hidden="true" className="absolute top-0 bottom-0 w-0.5 bg-rule" style={{ left: `${4 + depth * 22}px` }} />}
        <span className="min-w-0 flex-1">
          <span className={`block ${small ? 'text-sm font-semibold' : 'text-base font-extrabold'}`}>{p.label}</span>
          {roles !== '' && <span className={`block text-[13px] font-medium ${current ? 'text-on-ink-soft' : 'text-text-muted'}`}>{roles}.</span>}
        </span>
        {current && <span className="label-caps bg-teal-bright px-[7px] py-[3px] text-ink">{t.current}</span>}
      </button>
    )
  }

  const tree = (parent: Place, depth: number): ReactNode[] =>
    childrenOf(places, parent.key).flatMap((child) => [line(child, depth, false), ...tree(child, depth + 1)])

  const group = (text: string) => <div className="label-caps border-b-2 border-ink bg-paper px-4 pt-3.5 pb-1.5 text-text-muted">{text}</div>

  return (
    <div role="dialog" aria-label={t.title} className={`fixed top-0 z-[60] flex h-screen w-[420px] max-w-full flex-col border-2 border-t-0 border-ink bg-sheet ${className}`}>
      <div className="flex items-center gap-3 px-4 pt-4 pb-3">
        <h2 className="type-display flex-1 text-[34px] leading-[0.9]">{t.title}</h2>
        <button type="button" onClick={onClose} className="h-9 border-2 border-ink px-3 text-sm font-extrabold hover:bg-field">
          {t.close}
        </button>
      </div>
      <label className="block px-4 pb-3.5">
        <span className="sr-only">{t.find}</span>
        <input
          type="search"
          autoFocus
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder={t.findPlaceholder}
          className="h-11 w-full border-0 border-b-2 border-ink bg-field px-3 text-[15px] text-ink outline-none focus:border-teal"
        />
      </label>
      <div className="flex-1 overflow-y-auto border-t-2 border-ink">
        {q !== '' ? (
          <>
            {group(t.results)}
            {found.length === 0 && <p className="px-4 py-3 text-sm text-text-muted">{t.noMatch}</p>}
            {found.map((p) => line(p, 0, p.held))}
          </>
        ) : (
          <>
            {group(t.held)}
            {held.map((p) => line(p, 0, true))}
            {held
              .filter((p) => childrenOf(places, p.key).length > 0)
              .map((p) => (
                <div key={`inside:${p.key}`}>
                  {group(t.inside(p.label))}
                  {tree(p, 1)}
                </div>
              ))}
          </>
        )}
      </div>
      <p className="border-t-2 border-ink bg-sheet px-4 py-3.5 text-[13px] leading-[1.45] text-text-muted">{t.note}</p>
    </div>
  )
}
