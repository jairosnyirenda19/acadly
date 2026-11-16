import { can, moduleOn, type AppContext } from '@acadly/kernel'
import { copy } from '../copy'
import type { IconName } from './icons'

const t = copy.shell

/** Whether the account holds a permission anywhere in the school. For what the screens offer; the server decides. */
export function holdsAnywhere(context: AppContext, permission: string): boolean {
  return context.grants.some((grant) => grant.permission === permission)
}

export interface NavItem {
  key: string
  label: string
  path: string
  icon: IconName
  /** Shown only to an account whose permissions allow the page. Never a role name. */
  show: (context: AppContext) => boolean
  /** Paths that light this item: the item's own, and pages under it. */
  lights: (path: string) => boolean
}

export interface NavGroup {
  key: string
  label: string
  items: NavItem[]
}

const under = (base: string) => (path: string) => path === base || path.startsWith(`${base}/`)

/**
 * The rail, in groups. Only built pages appear, and only to the people who
 * may use them; a group with nothing to show is left out.
 */
export const GROUPS: NavGroup[] = [
  {
    key: 'daily',
    label: t.groups.daily,
    items: [
      { key: 'today', label: t.nav.today, path: '/', icon: 'today', show: () => true, lights: (p) => p === '/' },
      { key: 'registers', label: t.nav.registers, path: '/registers', icon: 'registers', show: (c) => seesRegisters(c), lights: under('/registers') },
    ],
  },
  {
    key: 'learners',
    label: t.groups.learners,
    items: [{ key: 'learners', label: t.nav.learners, path: '/learners', icon: 'learners', show: (c) => holdsAnywhere(c, 'enrolment.learners.view'), lights: under('/learners') }],
  },
  {
    key: 'school',
    label: t.groups.school,
    items: [
      { key: 'people', label: t.nav.people, path: '/settings/people', icon: 'people', show: (c) => holdsAnywhere(c, 'access.roles.assign'), lights: under('/settings/people') },
      { key: 'roles', label: t.nav.roles, path: '/settings/roles', icon: 'roles', show: (c) => can(c, 'access.roles.manage'), lights: under('/settings/roles') },
      { key: 'subjects', label: t.nav.subjects, path: '/settings/subjects', icon: 'subjects', show: (c) => holdsAnywhere(c, 'academics.manage'), lights: under('/settings/subjects') },
      { key: 'teaching', label: t.nav.teaching, path: '/settings/teaching', icon: 'teaching', show: (c) => holdsAnywhere(c, 'academics.manage'), lights: under('/settings/teaching') },
    ],
  },
]

/** Whether the account may see a register anywhere: it takes, sees or corrects registers, and the module is on. */
export function seesRegisters(context: AppContext): boolean {
  return moduleOn(context, 'attendance') && ['attendance.capture', 'attendance.all_classes', 'attendance.correct'].some((p) => holdsAnywhere(context, p))
}

/** The groups and items this account sees. */
export function visibleGroups(context: AppContext): NavGroup[] {
  return GROUPS.map((group) => ({ ...group, items: group.items.filter((item) => item.show(context)) })).filter((group) => group.items.length > 0)
}

/** The first settings page the account may use, or null. */
export function settingsHome(context: AppContext): string | null {
  return GROUPS.find((g) => g.key === 'school')?.items.find((item) => item.show(context))?.path ?? null
}
