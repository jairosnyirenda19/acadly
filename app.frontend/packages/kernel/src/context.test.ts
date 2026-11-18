import { describe, expect, it } from 'vitest'
import { can, moduleOn, type AppContext } from './context'

function context(overrides: Partial<AppContext> = {}): AppContext {
  return {
    user: { id: 'u1', name: 'Agnes Banda', email: 'a.banda@sunrise.example', phone: null },
    school: { name: 'Sunrise Academy', short_name: 'Sunrise', has_logo: false, open: true },
    setup_required: false,
    licence: { status: 'valid', read_only: false },
    modules: ['institution', 'attendance'],
    divisions: [],
    campuses: [],
    vocabulary: {},
    navigation: [],
    grants: [],
    places: [],
    ...overrides,
  }
}

describe('modules', () => {
  it('reports a module that is on, and kernel areas as always on', () => {
    const ctx = context()

    expect(moduleOn(ctx, 'attendance')).toBe(true)
    expect(moduleOn(ctx, 'library')).toBe(false)
    expect(moduleOn(ctx, 'access')).toBe(true)
  })
})

describe('permissions', () => {
  it('allows a grant held at the institution anywhere', () => {
    const ctx = context({ grants: [{ permission: 'attendance.capture', scope_type: 'institution', scope_id: null }] })

    expect(can(ctx, 'attendance.capture')).toBe(true)
    expect(can(ctx, 'attendance.capture', { type: 'class', id: 'c1' })).toBe(true)
  })

  it('limits a grant held at one scope to that scope', () => {
    const ctx = context({ grants: [{ permission: 'attendance.capture', scope_type: 'class', scope_id: 'c1' }] })

    expect(can(ctx, 'attendance.capture', { type: 'class', id: 'c1' })).toBe(true)
    expect(can(ctx, 'attendance.capture', { type: 'class', id: 'c2' })).toBe(false)
    expect(can(ctx, 'attendance.capture')).toBe(false)
  })

  it('applies a grant at a level to the classes inside it, and not to another level', () => {
    const ctx = context({
      grants: [{ permission: 'attendance.capture', scope_type: 'division', scope_id: 'd1' }],
      places: [
        { key: 'division:d1', type: 'division', id: 'd1', label: 'Primary', within: ['division:d1', 'institution:'], held: true, roles: [] },
        { key: 'class:c1', type: 'class', id: 'c1', label: 'Standard 1', within: ['class:c1', 'offering:o1', 'division:d1', 'campus:k1', 'institution:'], held: false, roles: [] },
      ],
    })

    expect(can(ctx, 'attendance.capture', { type: 'class', id: 'c1' })).toBe(true)
    expect(can(ctx, 'attendance.capture', { type: 'division', id: 'd2' })).toBe(false)
    expect(can(ctx, 'attendance.capture', { type: 'campus', id: 'k1' })).toBe(false)
  })

  it('refuses a permission of a module that is off, even when it is granted', () => {
    const ctx = context({
      modules: ['institution'],
      grants: [{ permission: 'attendance.capture', scope_type: 'institution', scope_id: null }],
    })

    expect(can(ctx, 'attendance.capture')).toBe(false)
  })
})
