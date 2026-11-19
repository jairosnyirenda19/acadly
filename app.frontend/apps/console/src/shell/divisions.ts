import type { AppContext, Place } from '@acadly/kernel'

export type Division = AppContext['divisions'][number]

/**
 * The divisions the account works in (D-096): every one when it holds a
 * role for the whole school, otherwise the division of each place it holds
 * a role in. Read from the places the server gives; the server decides.
 */
export function reachDivisions(context: AppContext): Division[] {
  const held = context.places.filter((p) => p.held)

  if (held.some((p) => p.type === 'institution')) return context.divisions

  const keys = new Set(held.flatMap((p) => p.within.filter((k) => k.startsWith('division:'))))

  // A role at a campus reaches the divisions taught there.
  for (const p of held.filter((x) => x.type === 'campus')) {
    for (const inside of context.places.filter((x) => x.type === 'offering' && x.within.includes(p.key))) {
      for (const k of inside.within.filter((w) => w.startsWith('division:'))) keys.add(k)
    }
  }

  return context.divisions.filter((d) => keys.has(`division:${d.id}`))
}

/**
 * The division the account is working in: the chosen place's division, or
 * the only division it works in. Null means all divisions, the start screen.
 */
export function divisionOf(context: AppContext, place: Place | null): Division | null {
  const reach = reachDivisions(context)

  if (reach.length === 1) return reach[0] ?? null
  if (place === null) return null

  const key = place.type === 'division' ? place.key : place.within.find((k) => k.startsWith('division:'))

  return reach.find((d) => `division:${d.id}` === key) ?? null
}

/** The letter on a division's tile: its first, in capitals. */
export const letterOf = (name: string): string => (name.trim()[0] ?? '·').toUpperCase()

/** The picture Acadly ships for a division, by its profile. A school may give its own later. */
export function pictureOf(profile: string): string | null {
  const picture: Record<string, string> = { ECD: 'nursery', PRIMARY: 'primary', SECONDARY: 'secondary', TERTIARY: 'college' }
  const name = picture[profile]

  return name === undefined ? null : `/divisions/division-${name}.jpg`
}

/** The context as one division sees it: only its words and its bindings. */
export function narrowTo(context: AppContext, division: Division | null): AppContext {
  return division === null ? context : { ...context, divisions: [division], vocabulary: division.vocabulary }
}
