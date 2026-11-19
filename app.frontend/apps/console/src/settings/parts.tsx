import { navigate, useAppContext, useLocation } from '@acadly/kernel'
import type { ReactNode } from 'react'
import { copy } from '../copy'
import { groupOf } from '../shell/navigation'
import type { PersonStateName } from './api'

const t = copy.settings

export type SettingsTab = 'people' | 'roles' | 'subjects' | 'teaching' | 'timetable' | 'messages' | 'assessment' | 'approvals' | 'modules'

/**
 * The tabs over a School or Settings page: every page of the same group of
 * the rail that the account may use, so a page added to the rail is never
 * missing here. `current` is kept for the pages that name themselves.
 */
// eslint-disable-next-line @typescript-eslint/no-unused-vars
export function SettingsTabs({ current }: { current?: SettingsTab }) {
  const context = useAppContext()
  const { path } = useLocation()
  const group = groupOf(context, path)

  if (group === undefined || group.items.length < 2) return null

  const tabs = group.items.map((item) => ({ key: item.key, path: item.path, label: item.label, on: item.lights(path), show: true }))

  return (
    <nav aria-label={t.tabsLabel} className="flex gap-1 overflow-x-auto border-b-2 border-ink bg-sheet px-3 [scrollbar-width:none] md:px-[clamp(16px,3vw,40px)]">
      {tabs
        .filter((tab) => tab.show)
        .map((tab) => (
          <a
            key={tab.key}
            href={tab.path}
            aria-current={tab.on ? 'page' : undefined}
            onClick={(event) => {
              event.preventDefault()
              navigate(tab.path)
            }}
            className={`shrink-0 border-b-4 px-4 pt-3 pb-2.5 text-[15px] whitespace-nowrap text-ink no-underline ${tab.on ? 'border-teal font-extrabold' : 'border-transparent font-medium'}`}
          >
            {tab.label}
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

/** The fill of an activity: a fine stripe on the sheet, so it reads as not a lesson. */
export const STRIPE = 'bg-[repeating-linear-gradient(135deg,var(--color-sheet)_0_10px,var(--color-paper)_10px_12px)]'

/** The word that marks an activity: ink edge on the sheet. */
export function ActivityTag({ children }: { children: ReactNode }) {
  return <span className="label-caps inline-block border-2 border-ink bg-sheet px-[5px] py-px whitespace-nowrap text-ink">{children}</span>
}
