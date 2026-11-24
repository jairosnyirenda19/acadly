import { api, forgetLoads, useLoad } from '@acadly/kernel'
import { useEffect, useRef, useState } from 'react'
import { copy } from '../copy'
import { read, settle } from '../setup/api'
import { Icon } from './icons'

const t = copy.viewAs

type People = { people: { id: string; name: string; email: string; roles: { role: string; where: string }[] }[]; total: number }

/** What /me says about View as: whether this account may use it, and who is really signed in while it does. */
export function useViewAs() {
  const loaded = useLoad('me:view-as', () => read(api.GET('/v1/me')))

  return loaded.data?.view_as ?? null
}

/** Every answer the screens remembered belonged to who you were: start again from the top. */
function restart() {
  forgetLoads()
  window.location.assign('/')
}

const time = (iso: string | null) => (iso === null ? '' : new Date(iso).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' }))
const initials = (name: string) =>
  name
    .split(/\s+/)
    .slice(0, 2)
    .map((p) => p[0] ?? '')
    .join('')
    .toUpperCase()

/** The standing strip while viewing as someone (the approved design): it cannot be missed or forgotten. */
export function ViewAsBar({ name }: { name: string }) {
  const viewAs = useViewAs()
  const [leaving, setLeaving] = useState(false)

  if (viewAs === null || viewAs.by === null) return null

  const leave = async () => {
    setLeaving(true)
    await settle(api.DELETE('/v1/view-as'))
    restart()
  }

  return (
    <div role="status" className="flex flex-wrap items-center gap-x-4 gap-y-1.5 border-b-4 border-marigold bg-ink px-3 py-1.5 text-sheet md:px-[clamp(16px,3vw,40px)]">
      <span aria-hidden="true" className="size-3 shrink-0 bg-marigold md:size-3.5" />
      <span className="flex min-w-0 flex-1 flex-wrap items-baseline gap-x-3 gap-y-0.5 md:flex-none">
        <span className="label-caps text-marigold">{t.label}</span>
        <span className="text-sm font-extrabold md:text-base">{name}</span>
      </span>
      <span className="hidden flex-[1_1_320px] text-[13px] text-on-ink-soft md:block">{t.recorded(viewAs.by.name, name.split(' ')[0] ?? name, time(viewAs.ends_at))}</span>
      <button type="button" disabled={leaving} onClick={() => void leave()} className="h-10 shrink-0 border-2 border-sheet px-4 text-sm font-extrabold text-sheet hover:bg-ink-rule disabled:opacity-60">
        {t.leave}
      </button>
    </div>
  )
}

/** The View as key beside your name, and its search panel (the approved design). */
export function ViewAsKey() {
  const viewAs = useViewAs()
  const [open, setOpen] = useState(false)

  if (viewAs === null || !viewAs.available || viewAs.by !== null) return null

  return (
    <div className="relative">
      <button
        type="button"
        aria-expanded={open}
        aria-haspopup="dialog"
        onClick={() => setOpen((v) => !v)}
        className={`inline-flex h-11 items-center gap-2 border-2 border-ink px-3 text-sm font-extrabold ${open ? 'bg-ink text-sheet' : 'text-ink hover:bg-field'}`}
      >
        <Icon name="switch" size={18} />
        <span className="hidden sm:inline">{t.key}</span>
      </button>
      {open && <ViewAsPanel onClose={() => setOpen(false)} />}
    </div>
  )
}

function ViewAsPanel({ onClose }: { onClose: () => void }) {
  const [search, setSearch] = useState('')
  const [found, setFound] = useState<People | null>(null)
  const [failed, setFailed] = useState(false)
  const [starting, setStarting] = useState<string | null>(null)
  const box = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const timer = window.setTimeout(() => {
      void read(api.GET('/v1/view-as/people', { params: { query: { search } as never } })).then((r) => setFound(r !== null && r !== 'refused' && r !== 'missing' ? r : { people: [], total: 0 }))
    }, 200)

    return () => window.clearTimeout(timer)
  }, [search])

  useEffect(() => {
    box.current?.querySelector('input')?.focus()
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose()
    window.addEventListener('keydown', onKey)

    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])

  const start = async (id: string) => {
    setStarting(id)
    setFailed(false)
    const r = await settle(api.POST('/v1/view-as/{id}', { params: { path: { id } } }))
    if (r.kind === 'saved') restart()
    else {
      setStarting(null)
      setFailed(true)
    }
  }

  // Grouped by each person's first role, in the order the roles first appear.
  const groups: { role: string; people: People['people'] }[] = []
  for (const p of found?.people ?? []) {
    const role = p.roles[0]?.role ?? t.noRole
    const g = groups.find((x) => x.role === role)
    if (g !== undefined) g.people.push(p)
    else groups.push({ role, people: [p] })
  }

  return (
    <div ref={box} role="dialog" aria-label={t.title} className="fixed inset-x-2 top-[68px] z-50 max-h-[calc(100vh-80px)] overflow-y-auto border-2 border-ink bg-sheet sm:absolute sm:inset-x-auto sm:top-[52px] sm:right-0 sm:w-[460px]">
      <div className="px-4 pt-3.5 pb-2.5">
        <div className="flex items-baseline gap-2.5">
          <h2 className="type-display flex-1 text-[26px] leading-none">{t.title}</h2>
          <span className="label-caps bg-marigold px-1.5 py-0.5">{t.devOnly}</span>
          <button type="button" aria-label={t.close} onClick={onClose} className="grid size-9 place-items-center border-2 border-ink hover:bg-field">
            <Icon name="close" size={16} />
          </button>
        </div>
        <p className="mt-1.5 mb-2.5 text-[13px]">{t.intro}</p>
        <label className="flex h-11 items-center gap-2 border-b-2 border-ink bg-field px-2.5">
          <Icon name="search" size={18} />
          <input type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t.search} aria-label={t.search} className="h-10 min-w-0 flex-1 bg-transparent text-[15px] outline-none" />
        </label>
        {failed && <p className="mt-2 text-[13px] font-bold text-red-text">{t.failed}</p>}
      </div>
      {found !== null && found.people.length === 0 && <p className="border-t border-rule px-4 py-3 text-sm text-text-muted">{t.none}</p>}
      {groups.map((g) => (
        <section key={g.role} aria-label={g.role}>
          <h3 className="label-caps border-t border-rule px-4 pt-2.5 pb-1 text-text-muted">{g.role}</h3>
          <ul>
            {g.people.map((p) => (
              <li key={p.id}>
                <button
                  type="button"
                  aria-label={t.choose(p.name)}
                  disabled={starting !== null}
                  onClick={() => void start(p.id)}
                  className="flex min-h-14 w-full items-center gap-3 border-l-4 border-transparent px-4 py-2 text-left hover:border-ink hover:bg-paper disabled:opacity-60"
                >
                  <span aria-hidden="true" className="type-display grid size-9 shrink-0 place-items-center bg-ink text-base text-sheet">
                    {initials(p.name)}
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className="block text-[15px] font-extrabold">{p.name}</span>
                    <span className="block text-[13px] text-text-muted">{p.roles.map((r) => `${r.role} · ${r.where}`).join('; ') || p.email}</span>
                  </span>
                  <span className="shrink-0 text-[13px] font-extrabold underline underline-offset-[3px]">{t.chooseShort}</span>
                </button>
              </li>
            ))}
          </ul>
        </section>
      ))}
      {found !== null && found.total > 0 && <p className="border-t-2 border-ink px-4 py-2.5 text-xs text-text-muted">{t.showing(found.people.length, found.total)}</p>}
    </div>
  )
}
