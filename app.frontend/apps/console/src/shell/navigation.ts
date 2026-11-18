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
      { key: 'my-day', label: t.nav.myDay, path: '/my-day', icon: 'myDay', show: (c) => takesRegisters(c), lights: (p) => p === '/my-day' },
      { key: 'today', label: t.nav.today, path: '/', icon: 'today', show: () => true, lights: (p) => p === '/' },
      { key: 'registers', label: t.nav.registers, path: '/registers', icon: 'registers', show: (c) => seesRegisters(c), lights: under('/registers') },
      { key: 'marks', label: t.nav.marks, path: '/marks', icon: 'marks', show: (c) => seesMarks(c), lights: under('/marks') },
      { key: 'to-approve', label: t.nav.toApprove, path: '/approvals', icon: 'approvals', show: (c) => decides(c), lights: under('/approvals') },
    ],
  },
  {
    key: 'learners',
    label: t.groups.learners,
    items: [{ key: 'learners', label: t.nav.learners, path: '/learners', icon: 'learners', show: (c) => holdsAnywhere(c, 'enrolment.learners.view'), lights: under('/learners') }],
  },
  {
    key: 'fees',
    label: t.groups.fees,
    items: [
      { key: 'fees-desk', label: t.nav.feesDesk, path: '/fees', icon: 'fees', show: (c) => moduleOn(c, 'fees') && can(c, 'fees.collect'), lights: (p) => p === '/fees' || p.startsWith('/fees/receipts') },
      { key: 'fees-pay', label: t.nav.feesPay, path: '/fees/pay', icon: 'pay', show: (c) => moduleOn(c, 'fees') && holdsAnywhere(c, 'fees.collect'), lights: under('/fees/pay') },
      { key: 'fees-shop', label: t.nav.feesShop, path: '/fees/shop', icon: 'shop', show: (c) => moduleOn(c, 'fees') && holdsAnywhere(c, 'fees.collect'), lights: under('/fees/shop') },
    ],
  },
  {
    key: 'school',
    label: t.groups.school,
    items: [
      { key: 'people', label: t.nav.people, path: '/settings/people', icon: 'people', show: (c) => holdsAnywhere(c, 'access.roles.assign'), lights: under('/settings/people') },
      { key: 'roles', label: t.nav.roles, path: '/settings/roles', icon: 'roles', show: (c) => can(c, 'access.roles.manage'), lights: under('/settings/roles') },
      { key: 'subjects', label: t.nav.subjects, path: '/settings/subjects', icon: 'subjects', show: (c) => holdsAnywhere(c, 'academics.manage'), lights: under('/settings/subjects') },
      { key: 'teaching', label: t.nav.teaching, path: '/settings/teaching', icon: 'teaching', show: (c) => holdsAnywhere(c, 'academics.manage'), lights: under('/settings/teaching') },
      { key: 'timetable', label: t.nav.timetable, path: '/settings/timetable', icon: 'timetable', show: (c) => holdsAnywhere(c, 'academics.manage'), lights: under('/settings/timetable') },
      { key: 'next-year', label: t.nav.nextYear, path: '/settings/next-year', icon: 'timetable', show: (c) => can(c, 'academics.manage'), lights: under('/settings/next-year') },
      { key: 'fee-settings', label: t.nav.feeSettings, path: '/settings/fees', icon: 'fees', show: (c) => moduleOn(c, 'fees') && can(c, 'fees.manage'), lights: under('/settings/fees') },
      { key: 'document-types', label: t.nav.documentTypes, path: '/settings/learner-documents', icon: 'learners', show: (c) => can(c, 'enrolment.manage'), lights: under('/settings/learner-documents') },
      { key: 'messages', label: t.nav.messages, path: '/settings/messages', icon: 'messages', show: (c) => can(c, 'notify.manage'), lights: under('/settings/messages') },
      { key: 'assessment', label: t.nav.assessment, path: '/settings/assessment', icon: 'assessment', show: (c) => keepsGrading(c), lights: under('/settings/assessment') },
      { key: 'approvals', label: t.nav.approvals, path: '/settings/approvals', icon: 'roles', show: (c) => can(c, 'access.approvals.manage'), lights: under('/settings/approvals') },
      { key: 'modules', label: t.nav.modules, path: '/settings/modules', icon: 'modules', show: (c) => can(c, 'modules.manage'), lights: under('/settings/modules') },
    ],
  },
]

/**
 * Whether the account may be asked to approve something: it holds a permission
 * that decides a kind of request, with its module on. Being asked comes from
 * the school's chains; holding the permission is what makes it possible.
 */
export function decides(context: AppContext): boolean {
  return (moduleOn(context, 'attendance') && holdsAnywhere(context, 'attendance.correct')) || (moduleOn(context, 'assessment') && holdsAnywhere(context, 'assessment.lock'))
}

/** Whether the account may see a register anywhere: it takes, sees or corrects registers, and the module is on. */
export function seesRegisters(context: AppContext): boolean {
  return moduleOn(context, 'attendance') && ['attendance.capture', 'attendance.all_classes', 'attendance.correct'].some((p) => holdsAnywhere(context, p))
}

/** Whether the account takes registers somewhere, with the module on: it has a day of its own. */
export function takesRegisters(context: AppContext): boolean {
  return moduleOn(context, 'attendance') && holdsAnywhere(context, 'attendance.capture')
}

/** Whether the account works with marks anywhere: sets, enters, locks or sees assessments, with the module on. */
export function seesMarks(context: AppContext): boolean {
  return moduleOn(context, 'assessment') && ['assessment.create', 'assessment.enter', 'assessment.lock', 'assessment.view', 'assessment.publish'].some((p) => holdsAnywhere(context, p))
}

/** Whether the account sees every assessment of its classes: the office's list, with lock and return. */
export function seesAllMarks(context: AppContext): boolean {
  return moduleOn(context, 'assessment') && ['assessment.lock', 'assessment.view'].some((p) => holdsAnywhere(context, p))
}

/** Whether the account publishes term results somewhere, with the module on. */
export function publishesResults(context: AppContext): boolean {
  return moduleOn(context, 'assessment') && holdsAnywhere(context, 'assessment.publish')
}

/** Whether the account keeps the scales and grading plans somewhere, with the module on. */
export function keepsGrading(context: AppContext): boolean {
  return moduleOn(context, 'assessment') && holdsAnywhere(context, 'assessment.plan')
}

/** The groups and items this account sees. */
export function visibleGroups(context: AppContext): NavGroup[] {
  return GROUPS.map((group) => ({ ...group, items: group.items.filter((item) => item.show(context)) })).filter((group) => group.items.length > 0)
}

/** The first settings page the account may use, or null. */
export function settingsHome(context: AppContext): string | null {
  return GROUPS.find((g) => g.key === 'school')?.items.find((item) => item.show(context))?.path ?? null
}
