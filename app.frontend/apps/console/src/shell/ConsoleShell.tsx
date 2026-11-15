import { navigate, signOut, useAppContext, useLoading, useLocation, type AppContext } from '@acadly/kernel'
import { LoadingRule } from '@acadly/ui'
import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react'
import { copy } from '../copy'
import { Icon } from './icons'
import { holdsAnywhere, settingsHome, visibleGroups, type NavGroup } from './navigation'
import { WorkInButton, WorkInPanel, useWorkIn } from './WorkIn'

export { holdsAnywhere, settingsHome }

const t = copy.shell

/** Whether the account sees anything under School. */
export function seesSettings(context: AppContext): boolean {
  return settingsHome(context) !== null
}

type Width = 'phone' | 'tablet' | 'desktop'

const PHONE = '(max-width: 767px)'
const TABLET = '(min-width: 768px) and (max-width: 1023px)'

function widthNow(): Width {
  if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return 'desktop'
  if (window.matchMedia(PHONE).matches) return 'phone'
  if (window.matchMedia(TABLET).matches) return 'tablet'

  return 'desktop'
}

/** Which of the three layouts the window is in, kept current as it changes. */
function useWidth(): Width {
  const [width, setWidth] = useState<Width>(widthNow)

  useEffect(() => {
    const lists = [PHONE, TABLET].map((q) => window.matchMedia(q))
    const update = () => setWidth(widthNow())
    lists.forEach((l) => l.addEventListener('change', update))

    return () => lists.forEach((l) => l.removeEventListener('change', update))
  }, [])

  return width
}

/** A remembered choice on this device; the screen still works when storage is refused. */
function useRemembered<T>(key: string, fallback: T): [T, (next: T) => void] {
  const [value, setValue] = useState<T>(() => {
    try {
      const raw = window.localStorage.getItem(key)

      return raw === null ? fallback : (JSON.parse(raw) as T)
    } catch {
      return fallback
    }
  })

  const remember = useCallback(
    (next: T) => {
      setValue(next)
      try {
        window.localStorage.setItem(key, JSON.stringify(next))
      } catch {
        // Not remembered on this device; the choice still holds for this visit.
      }
    },
    [key],
  )

  return [value, remember]
}

function initials(name: string): string {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .join('')
}

const key = 'grid size-11 shrink-0 place-items-center border-2'

/** The rail's content: the name, Work in, the groups, and the way out. Folded, only icons. */
function Rail({
  groups,
  path,
  folded,
  shut,
  onShut,
  onGo,
  top,
  onWorkIn,
  onSignedOut,
  bottom,
}: {
  groups: NavGroup[]
  path: string
  folded: boolean
  shut: Record<string, boolean>
  onShut: (group: string) => void
  onGo: () => void
  top: ReactNode
  onWorkIn: () => void
  onSignedOut: () => void
  bottom?: ReactNode
}) {
  const { place, choice } = useWorkIn()

  return (
    <>
      {top}
      {folded ? (
        choice && (
          <button type="button" title={t.workIn.change(place?.label ?? t.wholeSchool)} aria-label={t.workIn.change(place?.label ?? t.wholeSchool)} onClick={onWorkIn} className="flex justify-center border-b border-ink-rule py-3 text-teal-bright hover:bg-ink-rule">
            <Icon name="chevrons" size={16} stroke={3} />
          </button>
        )
      ) : (
        <WorkInButton open={false} onToggle={onWorkIn} />
      )}
      <nav aria-label={t.navLabel} className="flex flex-col py-2.5 text-[15px]">
        {groups.map((group, i) => {
          const closed = !folded && (shut[group.key] ?? false)

          return (
            <div key={group.key}>
              {folded ? (
                i > 0 && <div aria-hidden="true" className="mx-3.5 my-1.5 h-px bg-ink-rule" />
              ) : (
                <button
                  type="button"
                  aria-expanded={!closed}
                  onClick={() => onShut(group.key)}
                  className={`label-caps flex w-full items-center gap-2 px-5 pb-1.5 text-left text-[11px] text-on-ink-muted hover:text-sheet ${i > 0 ? 'pt-3.5' : 'pt-1.5'}`}
                >
                  <span className="flex-1">{group.label}</span>
                  <Icon name={closed ? 'right' : 'down'} size={12} stroke={3} />
                </button>
              )}
              {!closed &&
                group.items.map((item) => {
                  const current = item.lights(path)

                  return (
                    <a
                      key={item.key}
                      href={item.path}
                      title={folded ? item.label : undefined}
                      aria-label={folded ? item.label : undefined}
                      aria-current={current ? 'page' : undefined}
                      onClick={(event) => {
                        event.preventDefault()
                        navigate(item.path)
                        onGo()
                      }}
                      className={`flex items-center gap-3 border-l-4 no-underline hover:bg-ink-rule ${folded ? 'justify-center py-2.5' : 'py-[9px] pr-5 pl-4'} ${
                        current ? 'border-teal-bright font-extrabold text-sheet' : 'border-transparent font-medium text-on-ink-soft'
                      }`}
                    >
                      <Icon name={item.icon} />
                      {!folded && <span className="flex-1">{item.label}</span>}
                    </a>
                  )
                })}
            </div>
          )
        })}
      </nav>
      {bottom ?? (
        <div className="mt-auto border-t border-ink-rule px-5 py-3.5 text-[13px]">
          <button type="button" onClick={() => void signOut().then(onSignedOut)} className="font-bold text-sheet">
            {t.signOut}
          </button>
        </div>
      )}
    </>
  )
}

/**
 * The frame of the console after the school opens (the approved shell, from
 * eOffice IQ-X): a sticky rail and a sticky top bar.
 *
 *  - desktop (1024px+): the rail is open, or folded to icons (Ctrl [), remembered;
 *  - tablet (768–1023px): icons; the rail opens over the page;
 *  - phone (under 768px): a menu key opens the rail as a drawer.
 */
export function ConsoleShell({ children, onSignedOut }: { children: ReactNode; onSignedOut: () => void }) {
  const context = useAppContext()
  const { path } = useLocation()
  const busy = useLoading()
  const width = useWidth()
  const groups = visibleGroups(context)
  const [folded, setFolded] = useRemembered('acadly.rail.folded', false)
  const [shut, setShut] = useRemembered<Record<string, boolean>>('acadly.rail.groups', {})
  const [open, setOpen] = useState(false)
  const [choosing, setChoosing] = useState(false)
  const opener = useRef<HTMLButtonElement>(null)
  const drawer = useRef<HTMLDivElement>(null)

  const overlay = width !== 'desktop' && open
  const closeOverlay = useCallback(() => {
    setOpen(false)
    opener.current?.focus()
  }, [])
  const closeChoosing = useCallback(() => setChoosing(false), [])

  // Ctrl [ folds the rail on a desktop, and opens it over the page elsewhere; Escape closes what is open.
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && e.key === '[') {
        e.preventDefault()
        if (width === 'desktop') setFolded(!folded)
        else setOpen((v) => !v)
      }
      if (e.key === 'Escape' && open && !choosing) closeOverlay()
    }
    window.addEventListener('keydown', onKey)

    return () => window.removeEventListener('keydown', onKey)
  }, [width, folded, setFolded, open, choosing, closeOverlay])

  // A desktop has no drawer; leaving the narrow layout closes it.
  useEffect(() => {
    if (width === 'desktop') setOpen(false)
  }, [width])

  // The page behind a drawer does not scroll; the drawer takes focus when it opens.
  useEffect(() => {
    if (!overlay) return
    const before = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    drawer.current?.querySelector<HTMLElement>('button, a')?.focus()

    return () => {
      document.body.style.overflow = before
    }
  }, [overlay])

  const toggleGroup = (group: string) => setShut({ ...shut, [group]: !(shut[group] ?? false) })
  const railFolded = width === 'tablet' || (width === 'desktop' && folded)

  const brand = (
    <span className="type-display flex-1 text-[32px] leading-[0.9]">
      {copy.setup.product} <span className="text-teal-bright">{copy.setup.productAccent}</span>
    </span>
  )

  const railTop = railFolded ? (
    <div className="flex justify-center border-b border-ink-rule pt-3.5 pb-2.5">
      <span aria-hidden="true" className="type-display grid size-10 place-items-center bg-teal-bright text-xl text-ink">
        {copy.setup.product[0]}
      </span>
    </div>
  ) : (
    <div className="flex items-center gap-2.5 pt-[18px] pr-4 pb-3.5 pl-5">
      {brand}
      <button type="button" aria-label={t.fold} title={t.fold} onClick={() => setFolded(true)} className="grid size-8 place-items-center border-2 border-ink-rule text-on-ink-soft hover:bg-ink-rule hover:text-sheet">
        <Icon name="fold" size={16} />
      </button>
    </div>
  )

  const railBottom = railFolded ? (
    <div className="mt-auto flex justify-center pb-3.5">
      <button
        ref={width === 'tablet' ? opener : undefined}
        type="button"
        aria-label={width === 'tablet' ? t.openMenu : t.unfold}
        title={width === 'tablet' ? t.openMenu : t.unfold}
        aria-expanded={width === 'tablet' ? open : undefined}
        onClick={() => (width === 'tablet' ? setOpen(true) : setFolded(false))}
        className="grid size-9 place-items-center border-2 border-ink-rule text-on-ink-soft hover:bg-ink-rule hover:text-sheet"
      >
        <Icon name="unfold" size={16} />
      </button>
    </div>
  ) : undefined

  return (
    <div className="flex min-h-screen bg-paper">
      {width !== 'phone' && (
        <aside className={`sticky top-0 flex h-screen shrink-0 flex-col overflow-y-auto bg-ink text-sheet ${railFolded ? 'w-16' : 'w-[248px]'}`}>
          <Rail
            groups={groups}
            path={path}
            folded={railFolded}
            shut={shut}
            onShut={toggleGroup}
            onGo={() => undefined}
            top={railTop}
            onWorkIn={() => setChoosing(true)}
            onSignedOut={onSignedOut}
            bottom={railBottom}
          />
        </aside>
      )}

      {overlay && (
        <>
          <div aria-hidden="true" onClick={closeOverlay} className="fixed inset-0 z-40 bg-ink/45" />
          <div ref={drawer} role="dialog" aria-modal="true" aria-label={t.menu} className="fixed inset-y-0 left-0 z-50 flex w-[312px] max-w-[85vw] flex-col overflow-y-auto bg-ink text-sheet motion-safe:animate-slip-in">
            <Rail
              groups={groups}
              path={path}
              folded={false}
              shut={shut}
              onShut={toggleGroup}
              onGo={() => setOpen(false)}
              top={
                <div className="flex items-center gap-2.5 pt-3.5 pr-3.5 pb-3 pl-5">
                  {brand}
                  <button type="button" aria-label={t.closeMenu} onClick={closeOverlay} className={`${key} border-sheet text-sheet hover:bg-ink-rule`}>
                    <Icon name="close" size={18} />
                  </button>
                </div>
              }
              onWorkIn={() => setChoosing(true)}
              onSignedOut={onSignedOut}
            />
          </div>
        </>
      )}

      {choosing && <WorkInPanel onClose={closeChoosing} className={width === 'phone' ? 'left-0 w-full' : overlay ? 'left-[312px]' : railFolded ? 'left-16' : 'left-[248px]'} />}

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-30 flex h-[60px] items-center gap-2.5 border-b-2 border-ink bg-paper px-3 md:h-16 md:gap-3 md:px-[clamp(16px,3vw,40px)]">
          <LoadingRule active={busy} />
          {width === 'phone' && (
            <>
              <button ref={opener} type="button" aria-label={t.openMenu} aria-expanded={open} onClick={() => setOpen(true)} className={`${key} border-ink text-ink hover:bg-field`}>
                <Icon name="menu" />
              </button>
              <span className="type-display text-2xl leading-[0.9]">
                {copy.setup.product} <span className="text-teal">{copy.setup.productAccent}</span>
              </span>
            </>
          )}
          <div className="ml-auto flex items-center gap-2.5" title={context.user.name}>
            <span className="hidden w-[176px] text-right leading-tight sm:block">
              <span className="block truncate text-sm font-extrabold">{context.user.name}</span>
              <span className="block truncate text-xs text-text-muted">{context.user.email}</span>
            </span>
            <span aria-hidden="true" className="type-display grid size-11 place-items-center bg-ink text-[22px] text-sheet">
              {initials(context.user.name)}
            </span>
          </div>
        </header>
        <main className="flex min-w-0 flex-1 flex-col">{children}</main>
      </div>
    </div>
  )
}
