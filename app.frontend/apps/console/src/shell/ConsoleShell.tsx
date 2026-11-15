import { can, navigate, signOut, useAppContext, type AppContext } from '@acadly/kernel'
import { useCallback, useState, type ReactNode } from 'react'
import { copy } from '../copy'
import { WorkInButton, WorkInPanel } from './WorkIn'

const t = copy.shell

/** Whether the account holds a permission anywhere in the school. For what the screens offer; the server decides. */
export function holdsAnywhere(context: AppContext, permission: string): boolean {
  return context.grants.some((grant) => grant.permission === permission)
}

/** Whether the account sees Settings: it can give roles somewhere, or edit roles. */
export function seesSettings(context: AppContext): boolean {
  return settingsHome(context) !== null
}

/** The first settings page the account may use, or null. */
export function settingsHome(context: AppContext): string | null {
  if (holdsAnywhere(context, 'access.roles.assign')) return '/settings/people'
  if (can(context, 'access.roles.manage')) return '/settings/roles'
  if (holdsAnywhere(context, 'academics.manage')) return '/settings/subjects'

  return null
}

function initials(name: string): string {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .join('')
}

/** The frame of the console after the school opens: the ink rail, the header and the page. */
export function ConsoleShell({ section, children, onSignedOut }: { section: 'today' | 'settings'; children: ReactNode; onSignedOut: () => void }) {
  const context = useAppContext()
  const [choosing, setChoosing] = useState(false)
  const close = useCallback(() => setChoosing(false), [])
  const items = [
    { key: 'today' as const, label: t.nav.today, path: '/', show: true },
    { key: 'settings' as const, label: t.nav.settings, path: settingsHome(context) ?? '/', show: seesSettings(context) },
  ]

  return (
    <div className="flex min-h-screen flex-wrap items-stretch bg-paper">
      <aside className="flex max-w-full flex-[1_1_232px] flex-col bg-ink text-sheet">
        <div className="type-display px-5 pt-5 pb-4 text-[34px] leading-[0.9]">
          {copy.setup.product} <span className="text-teal-bright">{copy.setup.productAccent}</span>
        </div>
        <WorkInButton open={choosing} onToggle={() => setChoosing(!choosing)} />
        <nav aria-label={t.navLabel} className="flex flex-col py-3 text-[15px]">
          {items
            .filter((item) => item.show)
            .map((item) => {
              const current = item.key === section

              return (
                <a
                  key={item.key}
                  href={item.path}
                  aria-current={current ? 'page' : undefined}
                  onClick={(event) => {
                    event.preventDefault()
                    navigate(item.path)
                  }}
                  className={`border-l-4 py-2.5 pr-5 pl-4 no-underline ${current ? 'border-teal-bright font-extrabold text-sheet' : 'border-transparent font-medium text-on-ink-soft'}`}
                >
                  {item.label}
                </a>
              )
            })}
        </nav>
        <div className="mt-auto border-t border-ink-rule px-5 py-3.5 text-[13px]">
          <button type="button" onClick={() => void signOut().then(onSignedOut)} className="font-bold text-sheet">
            {t.signOut}
          </button>
        </div>
      </aside>

      {choosing && <WorkInPanel onClose={close} />}

      <main className="flex min-w-0 flex-[999_1_560px] flex-col">
        <header className="flex flex-wrap items-center gap-3 border-b-2 border-ink bg-paper px-[clamp(16px,3vw,40px)] py-2.5">
          <div className="ml-auto flex items-center gap-2">
            <span className="w-[176px] text-right leading-tight">
              <span className="block truncate text-sm font-extrabold">{context.user.name}</span>
              <span className="block truncate text-xs text-text-muted">{context.user.email}</span>
            </span>
            <span aria-hidden="true" className="type-display grid size-11 place-items-center bg-ink text-[22px] text-sheet">
              {initials(context.user.name)}
            </span>
          </div>
        </header>
        {children}
      </main>
    </div>
  )
}
