import type { AppContext } from '@acadly/kernel'
import { describe, expect, it } from 'vitest'
import { seesMarks, seesObservations, visibleGroups } from './navigation'

const nursery = { id: 'd1', name: 'Nursery', profile: 'ECD', vocabulary: { learner: { one: 'Child', many: 'Children' }, subject: { one: 'Learning area', many: 'Learning areas' } }, bindings: { 'assessment.model': 'observational' } }
const primary = { id: 'd2', name: 'Primary', profile: 'PRIMARY', vocabulary: { learner: { one: 'Pupil', many: 'Pupils' } }, bindings: {} }

function context(divisions: AppContext['divisions']): AppContext {
  return {
    user: { id: 'u1', name: 'Ruth Mkandawire', email: 'r.m@sunrise.example', phone: null },
    school: { name: 'Little Stars', short_name: 'Stars', has_logo: false, open: true, look: { app_name: null, main: '#0C2340', accent: '#2FD6CF', accent_last: true, custom: false } },
    setup_required: false,
    licence: { status: 'valid', read_only: false },
    modules: ['institution', 'enrolment', 'assessment'],
    divisions,
    campuses: [],
    vocabulary: divisions.length === 1 ? (divisions[0]?.vocabulary ?? {}) : {},
    navigation: [],
    grants: ['assessment.observe', 'assessment.view', 'enrolment.learners.view'].map((permission) => ({ permission, scope_type: 'institution' as const, scope_id: null })),
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
