import { can, moduleOn, type AppContext } from '@acadly/kernel'
import { copy } from '../copy'
import { narrowTo, type Division } from './divisions'
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
  /** The school's own word for the page, where its levels name it: "Children" in a nursery. */
  named?: (context: AppContext) => string | undefined
  /**
   * Where the page belongs in a school with several divisions (D-099): the school
   * view (whole-school settings), inside a division (its records), or both.
   */
  where?: 'school' | 'division'
  /** The part (module) the page needs: shown only where the division's profile has it. */
  module?: string
}

export interface NavGroup {
  key: string
  label: string
  items: NavItem[]
  named?: (context: AppContext) => string | undefined
}

const under = (base: string) => (path: string) => path === base || path.startsWith(`${base}/`)

/**
 * The rail, in groups. Only built pages appear, and only to the people who
 * may use them; a group with nothing to show is left out.
 */
export const GROUPS: NavGroup[] = [
  {
    // The person's own pages, first: the dashboard on top, then their day.
    key: 'home',
    label: t.groups.home,
    items: [
      { key: 'today', label: t.nav.today, path: '/', icon: 'today', show: () => true, lights: (p) => p === '/' },
      { key: 'my-day', where: 'division', module: 'attendance', label: t.nav.myDay, path: '/my-day', icon: 'myDay', show: (c) => takesRegisters(c), lights: (p) => p === '/my-day' },
    ],
  },
  {
    key: 'daily',
    label: t.groups.daily,
    items: [
      { key: 'divisions', label: t.nav.divisions, path: '/divisions', icon: 'tiles', show: () => false, lights: (p) => p === '/divisions' },
      { key: 'registers', where: 'division', module: 'attendance', label: t.nav.registers, path: '/registers', icon: 'registers', show: (c) => seesRegisters(c), lights: under('/registers') },
      { key: 'marks', where: 'division', module: 'assessment', label: t.nav.marks, path: '/marks', icon: 'marks', show: (c) => seesMarks(c), lights: under('/marks') },
      { key: 'room', where: 'division', module: 'assessment', label: t.nav.room, path: '/room', icon: 'room', show: (c) => seesObservations(c), lights: under('/room') },
      { key: 'observations', where: 'division', module: 'assessment', label: t.nav.observations, path: '/observations', icon: 'observations', show: (c) => observes(c) && holdsAnywhere(c, 'assessment.observe'), lights: under('/observations') },
      { key: 'reviews', where: 'division', module: 'assessment', label: t.nav.reviews, path: '/reviews', icon: 'reviews', show: (c) => seesObservations(c), lights: under('/reviews') },
      { key: 'pickup', where: 'division', module: 'saferelease', label: t.nav.pickup, path: '/pickup', icon: 'pickup', show: (c) => seesPickup(c), lights: under('/pickup') },
      { key: 'news', where: 'division', module: 'news', label: t.nav.news, path: '/news', icon: 'messages', show: (c) => moduleOn(c, 'news'), lights: under('/news') },
      { key: 'to-approve', where: 'division', label: t.nav.toApprove, path: '/approvals', icon: 'approvals', show: (c) => decides(c), lights: under('/approvals') },
    ],
  },
  {
    key: 'learners',
    label: t.groups.learners,
    named: (c) => schoolWord(c, 'learner'),
    items: [
      { key: 'learners', where: 'division', label: t.nav.learners, path: '/learners', icon: 'learners', show: (c) => holdsAnywhere(c, 'enrolment.learners.view'), lights: under('/learners'), named: (c) => schoolWord(c, 'learner') },
      // D-100: the whole-school overview's read-only lists, in the school view.
      { key: 'all-learners', where: 'school', label: t.nav.allLearners, path: '/school/learners', icon: 'learners', show: (c) => holdsAnywhere(c, 'institution.overview') && holdsAnywhere(c, 'enrolment.learners.view') && c.divisions.length > 1, lights: under('/school/learners') },
      { key: 'notices', label: t.nav.notices, path: '/notices', icon: 'messages', show: (c) => holdsAnywhere(c, 'enrolment.notify'), lights: under('/notices') },
      { key: 'support', where: 'division', module: 'support', label: t.nav.support, path: '/support', icon: 'support', show: (c) => moduleOn(c, 'support') && (holdsAnywhere(c, 'support.view') || holdsAnywhere(c, 'support.lead')), lights: under('/support') },
      { key: 'conduct', where: 'division', module: 'conduct', label: t.nav.conduct, path: '/conduct', icon: 'conduct', show: (c) => moduleOn(c, 'conduct') && (can(c, 'conduct.view') || can(c, 'conduct.follow_up')), lights: under('/conduct') },
    ],
  },
  {
    key: 'fees',
    label: t.groups.fees,
    items: [
      { key: 'fees-ledger', where: 'school', module: 'fees', label: t.nav.feesLedger, path: '/school/ledger', icon: 'fees', show: (c) => moduleOn(c, 'fees') && holdsAnywhere(c, 'institution.overview') && holdsAnywhere(c, 'fees.view') && c.divisions.length > 1, lights: under('/school/ledger') },
      { key: 'fees-desk', where: 'division', module: 'fees', label: t.nav.feesDesk, path: '/fees', icon: 'fees', show: (c) => moduleOn(c, 'fees') && can(c, 'fees.collect'), lights: (p) => p === '/fees' || p.startsWith('/fees/receipts') },
      { key: 'fees-pay', where: 'division', module: 'fees', label: t.nav.feesPay, path: '/fees/pay', icon: 'pay', show: (c) => moduleOn(c, 'fees') && holdsAnywhere(c, 'fees.collect'), lights: under('/fees/pay') },
      { key: 'fees-shop', where: 'division', module: 'fees', label: t.nav.feesShop, path: '/fees/shop', icon: 'shop', show: (c) => moduleOn(c, 'fees') && holdsAnywhere(c, 'fees.collect'), lights: under('/fees/shop') },
    ],
  },
  {
    key: 'programmes',
    label: t.groups.programmes,
    items: [
      { key: 'programmes-structure', where: 'division', module: 'programmes', label: t.nav.programmesStructure, path: '/programmes', icon: 'subjects', show: (c) => seesProgrammes(c), lights: (p) => p === '/programmes' },
      { key: 'programmes-list', where: 'division', module: 'programmes', label: t.nav.programmesList, path: '/programmes/list', icon: 'assessment', show: (c) => seesProgrammes(c), lights: (p) => p === '/programmes/list' || p.startsWith('/programmes/p/') },
      { key: 'programmes-catalogue', where: 'division', module: 'programmes', label: t.nav.programmesCatalogue, path: '/programmes/courses', icon: 'subjects', show: (c) => seesProgrammes(c), lights: under('/programmes/courses') },
      { key: 'programmes-offerings', where: 'division', module: 'programmes', label: t.nav.programmesOfferings, path: '/programmes/offerings', icon: 'teaching', show: (c) => seesProgrammes(c), lights: under('/programmes/offerings') },
      { key: 'programmes-students', where: 'division', module: 'programmes', label: t.nav.programmesStudents, path: '/programmes/students', icon: 'learners', show: (c) => seesProgrammes(c), lights: under('/programmes/students') },
      { key: 'programmes-accounts', where: 'division', module: 'programmes', label: t.nav.studentAccounts, path: '/programmes/accounts', icon: 'learners', show: (c) => holdsAnywhere(c, 'programmes.accounts'), lights: under('/programmes/accounts') },
      { key: 'classroom', where: 'division', module: 'classroom', label: t.nav.classroom, path: '/classroom', icon: 'assessment', show: (c) => seesClassroom(c), lights: (p) => p === '/classroom' || p.startsWith('/classroom/') },
      { key: 'results', where: 'division', module: 'results', label: t.nav.results, path: '/results', icon: 'marks', show: (c) => seesResults(c), lights: (p) => p === '/results' || p.startsWith('/results/c/') || p === '/results/bar' || p === '/results/setup' },
      { key: 'results-board', where: 'division', module: 'results', label: t.nav.examBoard, path: '/results/board', icon: 'approvals', show: (c) => seesResults(c, ['results.view', 'results.moderate', 'results.board', 'results.release']), lights: (p) => p.startsWith('/results/board') || p === '/results/release' || p.startsWith('/results/progression') },
      { key: 'results-graduation', where: 'division', module: 'results', label: t.nav.graduation, path: '/results/graduation', icon: 'reviews', show: (c) => seesResults(c, ['results.view', 'results.moderate', 'results.board', 'results.release', 'results.award']), lights: (p) => p.startsWith('/results/graduation') },
      { key: 'results-transcripts', where: 'division', module: 'results', label: t.nav.transcripts, path: '/results/transcripts', icon: 'subjects', show: (c) => seesResults(c, ['results.view', 'results.moderate', 'results.board', 'results.release', 'results.transcripts']), lights: (p) => p === '/results/transcripts' },
      { key: 'lectures-mine', where: 'division', module: 'lectures', label: t.nav.myLectures, path: '/lectures/my-day', icon: 'today', show: (c) => seesLectures(c, ['lectures.teach']), lights: (p) => p === '/lectures/my-day' || p.startsWith('/lectures/l/') },
      { key: 'lectures-timetable', where: 'division', module: 'lectures', label: t.nav.lectureTimetable, path: '/lectures/timetable', icon: 'timetable', show: (c) => seesLectures(c, ['lectures.view', 'lectures.timetable']), lights: under('/lectures/timetable') },
      { key: 'lectures-rooms', where: 'division', module: 'lectures', label: t.nav.lectureRooms, path: '/lectures/rooms', icon: 'room', show: (c) => seesLectures(c, ['lectures.view', 'lectures.timetable']), lights: under('/lectures/rooms') },
      { key: 'lectures-cover', where: 'division', module: 'lectures', label: t.nav.lectureCover, path: '/lectures/cover', icon: 'people', show: (c) => seesLectures(c, ['lectures.view', 'lectures.cover']), lights: under('/lectures/cover') },
      { key: 'programmes-registration', where: 'division', module: 'programmes', label: t.nav.programmesRegistration, path: '/programmes/registration', icon: 'approvals', show: (c) => seesProgrammes(c), lights: (p) => p === '/programmes/registration' || p.startsWith('/programmes/r/') },
    ],
  },
  {
    key: 'school',
    label: t.groups.school,
    items: [
      { key: 'people', label: t.nav.people, path: '/settings/people', icon: 'people', show: (c) => holdsAnywhere(c, 'access.roles.assign'), lights: under('/settings/people') },
      { key: 'roles', where: 'school', label: t.nav.roles, path: '/settings/roles', icon: 'roles', show: (c) => can(c, 'access.roles.manage'), lights: under('/settings/roles') },
      { key: 'subjects', where: 'division', label: t.nav.subjects, path: '/settings/subjects', icon: 'subjects', show: (c) => holdsAnywhere(c, 'academics.manage'), lights: under('/settings/subjects'), named: (c) => schoolWord(c, 'subject') },
      { key: 'teaching', where: 'division', label: t.nav.teaching, path: '/settings/teaching', icon: 'teaching', show: (c) => holdsAnywhere(c, 'academics.manage'), lights: under('/settings/teaching') },
      { key: 'timetable', where: 'division', label: t.nav.timetable, path: '/settings/timetable', icon: 'timetable', show: (c) => holdsAnywhere(c, 'academics.manage'), lights: under('/settings/timetable') },
      { key: 'next-year', where: 'school', label: t.nav.nextYear, path: '/settings/next-year', icon: 'timetable', show: (c) => can(c, 'academics.manage'), lights: under('/settings/next-year') },
    ],
  },
  {
    key: 'settings',
    label: t.groups.settings,
    items: [
      { key: 'look', where: 'school', label: t.nav.look, path: '/settings/branding', icon: 'look', show: (c) => can(c, 'institution.manage'), lights: under('/settings/branding') },
      { key: 'support-settings', where: 'school', label: t.nav.supportSettings, path: '/settings/support', icon: 'support', show: (c) => moduleOn(c, 'support') && can(c, 'support.manage'), lights: under('/settings/support') },
      { key: 'conduct-settings', where: 'school', label: t.nav.conductSettings, path: '/settings/conduct', icon: 'conduct', show: (c) => moduleOn(c, 'conduct') && can(c, 'conduct.manage'), lights: under('/settings/conduct') },
      { key: 'fee-settings', where: 'school', label: t.nav.feeSettings, path: '/settings/fees', icon: 'fees', show: (c) => moduleOn(c, 'fees') && can(c, 'fees.manage'), lights: under('/settings/fees') },
      { key: 'document-types', where: 'school', label: t.nav.documentTypes, path: '/settings/learner-documents', icon: 'learners', show: (c) => can(c, 'enrolment.manage'), lights: under('/settings/learner-documents') },
      { key: 'messages', where: 'school', label: t.nav.messages, path: '/settings/messages', icon: 'messages', show: (c) => can(c, 'notify.manage'), lights: under('/settings/messages') },
      { key: 'assessment', where: 'school', label: t.nav.assessment, path: '/settings/assessment', icon: 'assessment', show: (c) => keepsGrading(c), lights: under('/settings/assessment') },
      { key: 'review-steps', where: 'school', module: 'assessment', label: t.nav.reviewSteps, path: '/settings/review-steps', icon: 'reviews', show: (c) => namesSteps(c), lights: under('/settings/review-steps') },
      { key: 'approvals', where: 'school', label: t.nav.approvals, path: '/settings/approvals', icon: 'roles', show: (c) => can(c, 'access.approvals.manage'), lights: under('/settings/approvals') },
      { key: 'modules', where: 'school', label: t.nav.modules, path: '/settings/modules', icon: 'modules', show: (c) => can(c, 'modules.manage'), lights: under('/settings/modules') },
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

/** The model a division's profile binds for learning: 'marks' unless it says otherwise. */
function modelOf(division: AppContext['divisions'][number]): string {
  const bindings = division.bindings as Record<string, string> | undefined

  return bindings?.['assessment.model'] ?? 'marks'
}

/** Whether some level of the school takes marks. A school with no levels yet is treated as one that does. */
export function takesMarks(context: AppContext): boolean {
  return context.divisions.length === 0 || context.divisions.some((d) => modelOf(d) === 'marks')
}

/** Whether some level of the school records observations instead of marks, with the module on. */
export function observes(context: AppContext): boolean {
  return moduleOn(context, 'assessment') && context.divisions.some((d) => modelOf(d) === 'observational')
}

/** Whether the account names the review steps (D-109): a level records observations, and the account plans assessment. */
export function namesSteps(context: AppContext): boolean {
  return observes(context) && holdsAnywhere(context, 'assessment.plan')
}

/** Whether the account works with observations or progress reviews anywhere. */
export function seesObservations(context: AppContext): boolean {
  return observes(context) && ['assessment.observe', 'assessment.review', 'assessment.view'].some((p) => holdsAnywhere(context, p))
}

/** Whether the account works at pick-up anywhere, with the module on. */
export function seesPickup(context: AppContext): boolean {
  return moduleOn(context, 'saferelease') && ['saferelease.release', 'saferelease.manage', 'saferelease.view'].some((p) => holdsAnywhere(context, p))
}

/**
 * The school's own word, many form, when the school has one level and its
 * profile names it ("Children", "Learning areas"). With several levels the
 * console keeps its general words.
 */
export function schoolWord(context: AppContext, term: 'learner' | 'subject' | 'group' | 'level'): string | undefined {
  const words = context.vocabulary as Record<string, { one: string; many: string } | undefined>

  return words[term]?.many
}

/** Whether the account works with marks anywhere: sets, enters, locks or sees assessments, with the module on, in a school whose levels take marks. */
export function seesMarks(context: AppContext): boolean {
  return moduleOn(context, 'assessment') && takesMarks(context) && ['assessment.create', 'assessment.enter', 'assessment.lock', 'assessment.view', 'assessment.publish'].some((p) => holdsAnywhere(context, p))
}

/** Whether the account sees every assessment of its classes: the office's list, with lock and return. */
export function seesAllMarks(context: AppContext): boolean {
  return moduleOn(context, 'assessment') && takesMarks(context) && ['assessment.lock', 'assessment.view'].some((p) => holdsAnywhere(context, p))
}

/** Whether the account publishes term results somewhere, with the module on. */
/** Whether the account sets subject choices somewhere (D-117), with the module on. */
export function setsChoices(context: AppContext): boolean {
  return moduleOn(context, 'assessment') && takesMarks(context) && holdsAnywhere(context, 'assessment.plan')
}

export function publishesResults(context: AppContext): boolean {
  return moduleOn(context, 'assessment') && takesMarks(context) && holdsAnywhere(context, 'assessment.publish')
}

/** Whether the account keeps the scales and grading plans somewhere, with the module on. */
export function keepsGrading(context: AppContext): boolean {
  return moduleOn(context, 'assessment') && takesMarks(context) && holdsAnywhere(context, 'assessment.plan')
}

/** The pages that belong to one division's daily work: inside a division, never across all of them (D-096). */
/** Whether the account may open Classroom in a college division of this view (D-104), with the module on. */
export function seesClassroom(context: AppContext): boolean {
  return (
    moduleOn(context, 'classroom') &&
    context.divisions.some(
      (d) =>
        (d.bindings as Record<string, string | undefined>)['programmes.shape'] === 'programmes' &&
        (can(context, 'classroom.view', { type: 'division', id: d.id }) || can(context, 'classroom.teach', { type: 'division', id: d.id })),
    )
  )
}

/** Whether the account holds one of the Lectures permissions in a college division of this view (D-102), with the module on. */
export function seesLectures(context: AppContext, permissions: string[]): boolean {
  return (
    moduleOn(context, 'lectures') &&
    context.divisions.some((d) => (d.bindings as Record<string, string | undefined>)['programmes.shape'] === 'programmes' && permissions.some((p) => can(context, p, { type: 'division', id: d.id })))
  )
}

/** Whether the account sees programmes in a division of this view (D-097): the module on, and the permission held at the division. */
export function seesProgrammes(context: AppContext): boolean {
  return (
    moduleOn(context, 'programmes') &&
    context.divisions.some((d) => (d.bindings as Record<string, string | undefined>)['programmes.shape'] === 'programmes' && can(context, 'programmes.view', { type: 'division', id: d.id }))
  )
}

/** Where the account stands among the divisions: the one it works in (null for all), how many it works in, and whether it works in a finance office. */
export interface Lens {
  division: Division | null
  reach: number
  office?: boolean
}

/** Whether a division's profile has a part (D-099). A profile with no list has every part. */
export function hasPart(division: Division, module: string): boolean {
  const parts = division.modules

  return parts.length === 0 || parts.includes(module)
}

/**
 * The groups and items this account sees. Inside a division, the rail takes
 * that division's words and bindings; across all divisions it keeps the
 * school's shared pages and the Divisions start screen (D-096).
 */
export function visibleGroups(context: AppContext, lens?: Lens): NavGroup[] {
  const many = lens !== undefined && lens.reach > 1
  const office = lens?.office === true
  const all = many && lens.division === null && !office
  const division = lens?.division ?? null
  const ctx = lens === undefined ? context : narrowTo(context, division)

  // D-099: the school view holds whole-school settings; a division its own records and parts; an office only fees.
  const fits = (item: NavItem): boolean => {
    if (office) return (item.module === 'fees' && item.where !== 'school') || item.key === 'learners'
    if (all) return item.where !== 'division'
    if (many && division !== null) return item.where !== 'school' && (item.module === undefined || hasPart(division, item.module))

    return true
  }

  return GROUPS.map((group) => ({
    ...group,
    label: group.named?.(ctx) ?? group.label,
    items: group.items
      .filter((item) => (item.key === 'divisions' ? many : fits(item) && item.show(ctx)))
      .map((item) => ({ ...item, label: item.named?.(ctx) ?? item.label })),
  })).filter((group) => group.items.length > 0)
}

/** The first settings page the account may use, or null. */
export function settingsHome(context: AppContext): string | null {
  return GROUPS.filter((g) => g.key === 'school' || g.key === 'settings').flatMap((g) => g.items).find((item) => item.show(context))?.path ?? null
}

/** The group of the rail that holds a page, with what this account sees of it: the tabs over a School or Settings page. */
export function groupOf(context: AppContext, path: string): NavGroup | undefined {
  return visibleGroups(context).find((g) => (g.key === 'school' || g.key === 'settings' || g.key === 'programmes') && g.items.some((item) => item.lights(path)))
}

/** Whether the account may open Results in a college division of this view (D-105), with the module on. */
export function seesResults(context: AppContext, permissions: string[] = ['results.view', 'results.enter', 'results.moderate', 'results.board', 'results.release', 'results.setup']): boolean {
  return (
    moduleOn(context, 'results') &&
    context.divisions.some(
      (d) => (d.bindings as Record<string, string | undefined>)['programmes.shape'] === 'programmes' && permissions.some((p) => can(context, p, { type: 'division', id: d.id })),
    )
  )
}
