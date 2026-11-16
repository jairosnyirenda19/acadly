import { can, navigate, useAppContext } from '@acadly/kernel'
import type { ReactNode } from 'react'
import { copy } from '../copy'
import { holdsAnywhere } from '../shell/navigation'
import type { PersonStateName } from './api'

const t = copy.settings

export type SettingsTab = 'people' | 'roles' | 'subjects' | 'teaching' | 'timetable' | 'messages' | 'modules'

/** The settings tabs that are built and that the account may use. */
export function SettingsTabs({ current }: { current: SettingsTab }) {
  const context = useAppContext()
  const tabs = [
    { key: 'people' as const, path: '/settings/people', show: holdsAnywhere(context, 'access.roles.assign') },
    { key: 'roles' as const, path: '/settings/roles', show: can(context, 'access.roles.manage') },
    { key: 'subjects' as const, path: '/settings/subjects', show: holdsAnywhere(context, 'academics.manage') },
    { key: 'teaching' as const, path: '/settings/teaching', show: holdsAnywhere(context, 'academics.manage') },
    { key: 'timetable' as const, path: '/settings/timetable', show: holdsAnywhere(context, 'academics.manage') },
    { key: 'messages' as const, path: '/settings/messages', show: can(context, 'notify.manage') },
    { key: 'modules' as const, path: '/settings/modules', show: can(context, 'modules.manage') },
  ]

  return (
    <nav aria-label={t.tabsLabel} className="flex gap-1 overflow-x-auto border-b-2 border-ink bg-sheet px-3 [scrollbar-width:none] md:px-[clamp(16px,3vw,40px)]">
      {tabs
        .filter((tab) => tab.show)
        .map((tab) => (
          <a
            key={tab.key}
            href={tab.path}
            aria-current={tab.key === current ? 'page' : undefined}
            onClick={(event) => {
              event.preventDefault()
              navigate(tab.path)
            }}
            className={`shrink-0 border-b-4 px-4 pt-3 pb-2.5 text-[15px] whitespace-nowrap text-ink no-underline ${tab.key === current ? 'border-ink font-extrabold' : 'border-transparent font-medium'}`}
          >
            {t.tabs[tab.key]}
          </a>
        ))}
    </nav>
  )
}

/** The head of a settings page: kicker, display title, sentence and actions. */
export function PageHead({ kicker, title, intro, actions }: { kicker: ReactNode; title: string; intro: ReactNode; actions?: ReactNode }) {
  return (
    <div className="flex flex-wrap items-end gap-4 px-[clamp(16px,3vw,40px)] pt-7 pb-5">
      <div className="min-w-0 flex-[1_1_420px]">
        <div className="label-caps flex flex-wrap items-center gap-2 text-text-muted">{kicker}</div>
        <h1 className="type-display mt-1 mb-2.5 text-[clamp(56px,7vw,88px)] break-words">{title}</h1>
        <p className="max-w-[680px] text-[15px] leading-normal text-text-muted">{intro}</p>
      </div>
      {actions !== undefined && <div className="flex flex-wrap gap-2.5">{actions}</div>}
    </div>
  )
}

type Tone = 'done' | 'attention' | 'wrong' | 'plain' | 'locked'

const tones: Record<Tone, string> = {
  done: 'bg-teal text-sheet',
  attention: 'bg-marigold text-ink',
  wrong: 'bg-red-pen text-sheet',
  plain: 'bg-field text-ink',
  locked: 'bg-rule-dashed text-sheet',
}

/** A state written as a word on a colour: the colour never stands alone. */
export function Tag({ tone, children }: { tone: Tone; children: ReactNode }) {
  return <span className={`label-caps px-[7px] py-[3px] whitespace-nowrap ${tones[tone]}`}>{children}</span>
}

export const stateTone: Record<PersonStateName, Tone> = {
  active: 'done',
  ready: 'plain',
  invited: 'plain',
  expired: 'attention',
  no_role: 'attention',
  disabled: 'wrong',
}

/** Small square button used inside tables. */
export function SmallButton({ children, onClick, disabled, tone = 'ink' }: { children: ReactNode; onClick: () => void; disabled?: boolean; tone?: 'ink' | 'red' }) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      className={`h-9 border-2 px-3 text-sm font-extrabold disabled:border-disabled disabled:bg-disabled disabled:text-text-muted ${tone === 'red' ? 'border-red-pen text-red-text hover:bg-red-wash' : 'border-ink text-ink hover:bg-field'}`}
    >
      {children}
    </button>
  )
}
