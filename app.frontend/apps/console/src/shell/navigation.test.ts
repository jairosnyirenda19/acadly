import type { AppContext } from '@acadly/kernel'
import { describe, expect, it } from 'vitest'
import { seesMarks, seesObservations, visibleGroups } from './navigation'

const nursery = { id: 'd1', name: 'Nursery', profile: 'ECD', vocabulary: { learner: { one: 'Child', many: 'Children' }, subject: { one: 'Learning area', many: 'Learning areas' } }, bindings: { 'assessment.model': 'observational' }, modules: ['fees', 'conduct', 'support', 'attendance', 'assessment', 'saferelease'] }
const primary = { id: 'd2', name: 'Primary', profile: 'PRIMARY', vocabulary: { learner: { one: 'Pupil', many: 'Pupils' } }, bindings: {}, modules: ['fees', 'conduct', 'support', 'attendance', 'assessment', 'saferelease'] }
const college = { id: 'd3', name: 'College', profile: 'TERTIARY', vocabulary: { learner: { one: 'Student', many: 'Students' } }, bindings: { 'programmes.shape': 'programmes' }, modules: ['fees', 'conduct', 'support', 'programmes'] }

function context(divisions: AppContext['divisions']): AppContext {
  return {
    user: { id: 'u1', name: 'Ruth Mkandawire', email: 'r.m@sunrise.example', phone: null },
    school: { name: 'Little Stars', short_name: 'Stars', has_logo: false, open: true, look: { app_name: null, main: '#0C2340', accent: '#2FD6CF', accent_last: true, custom: false } },
    setup_required: false,
    licence: { status: 'valid', read_only: false },
    modules: ['institution', 'enrolment', 'assessment', 'attendance', 'fees', 'programmes'],
    divisions,
    campuses: [],
    vocabulary: divisions.length === 1 ? (divisions[0]?.vocabulary ?? {}) : {},
    navigation: [],
    grants: ['assessment.observe', 'assessment.view', 'enrolment.learners.view', 'attendance.capture', 'fees.collect', 'fees.manage', 'programmes.view', 'access.roles.manage', 'access.roles.assign'].map((permission) => ({ permission, scope_type: 'institution' as const, scope_id: null })),
    places: [],
  }
}

describe('a school with one level that records observations', () => {
  it('shows observations and never marks', () => {
    const ctx = context([nursery])

    expect(seesObservations(ctx)).toBe(true)
    expect(seesMarks(ctx)).toBe(false)
  })

  it('names the pages in the level\'s own words', () => {
    const items = visibleGroups(context([nursery])).flatMap((g) => g.items)

    expect(items.find((i) => i.key === 'learners')?.label).toBe('Children')
    expect(items.some((i) => i.key === 'marks')).toBe(false)
  })
})

describe('divisions (D-096)', () => {
  it('keeps the daily work of a division out of the all-divisions view, and offers the start screen', () => {
    const keys = visibleGroups(context([nursery, primary]), { division: null, reach: 2 }).flatMap((g) => g.items.map((i) => i.key))

    expect(keys).toContain('divisions')
    expect(keys).not.toContain('marks')
    expect(keys).not.toContain('room')
  })

  it('takes the words and menu of the division worked in', () => {
    const items = visibleGroups(context([nursery, primary]), { division: nursery, reach: 2 }).flatMap((g) => g.items)

    expect(items.find((i) => i.key === 'learners')?.label).toBe('Children')
    expect(items.some((i) => i.key === 'marks')).toBe(false)
    expect(items.some((i) => i.key === 'divisions')).toBe(true)
  })

  it('shows no start screen to a person who works in one division', () => {
    const keys = visibleGroups(context([nursery]), { division: nursery, reach: 1 }).flatMap((g) => g.items.map((i) => i.key))

    expect(keys).not.toContain('divisions')
  })
})

describe('a school with several levels', () => {
  it('shows marks and observations, in the console\'s general words', () => {
    const ctx = context([nursery, primary])
    const items = visibleGroups(ctx).flatMap((g) => g.items)

    expect(seesMarks(ctx)).toBe(true)
    expect(seesObservations(ctx)).toBe(true)
    expect(items.find((i) => i.key === 'learners')?.label).toBe('Learners')
  })
})

describe('divisions as boundaries (D-099)', () => {
  const keysIn = (lens: Parameters<typeof visibleGroups>[1]) => visibleGroups(context([nursery, primary, college]), lens).flatMap((g) => g.items.map((i) => i.key))

  it('keeps the school view to whole-school settings: no record of any division', () => {
    const keys = keysIn({ division: null, reach: 3 })

    expect(keys).toContain('roles')
    expect(keys).toContain('fee-settings')
    expect(keys).not.toContain('learners')
    expect(keys).not.toContain('fees-desk')
    expect(keys).not.toContain('registers')
  })

  it('shows inside a division only its own parts, and no whole-school setting', () => {
    const college_ = keysIn({ division: college, reach: 3 })
    const primary_ = keysIn({ division: primary, reach: 3 })

    expect(college_).toContain('programmes-structure')
    expect(college_).not.toContain('registers')
    expect(college_).not.toContain('marks')
    expect(college_).toContain('fees-desk')
    expect(college_).not.toContain('roles')
    expect(primary_).toContain('registers')
    expect(primary_).not.toContain('programmes-structure')
  })

  it('shows in a finance office only fees, and finding a learner', () => {
    const keys = keysIn({ division: null, reach: 3, office: true })

    expect(keys).toContain('fees-desk')
    expect(keys).toContain('learners')
    expect(keys).not.toContain('registers')
    expect(keys).not.toContain('roles')
  })
})
