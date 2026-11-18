import { api, type AppContext, type paths } from '@acadly/kernel'
import { visibleGroups } from '../shell/navigation'

export type SearchState = paths['/v1/search']['get']['responses'][200]['content']['application/json']
export type Group = SearchState['groups'][number]
export type Hit = Group['hits'][number]

/** A hit the screen can open: a found record, or a screen of the console. */
export interface Found {
  key: string
  title: string
  reference: string | null
  subtitle: string | null
  state: string | null
  kind: string
  id: string
  path: string
}

/** Where a hit opens. Opening is an ordinary page open: the page checks access again (12-search rule 15). */
export function pathOf(hit: Hit): string {
  if (hit.kind === 'learner') return `/learners/${hit.id}`
  if (hit.kind === 'person') return `/settings/people/${hit.id}`

  return '/learners'
}

export const toFound = (hit: Hit): Found => ({ ...hit, key: `${hit.kind}:${hit.id}:${hit.title}`, path: pathOf(hit) })

const fold = (text: string) => text.toLowerCase().replace(/['’‘`]/g, '')

/**
 * Screens are results too (12-search rule 13): answered from the navigation
 * the reader already has, so nothing is found that they cannot open.
 */
export function screens(context: AppContext, text: string): Found[] {
  const words = fold(text).split(/\s+/).filter((w) => w !== '')

  return visibleGroups(context)
    .flatMap((g) => g.items.map((item) => ({ item, group: g.label })))
    .filter(({ item }) => words.every((w) => ` ${fold(item.label)}`.includes(` ${w}`)))
    .slice(0, 5)
    .map(({ item, group }) => ({ key: `screen:${item.key}`, title: item.label, reference: null, subtitle: group, state: null, kind: 'screen', id: item.key, path: item.path }))
}

/** One search; null when it could not be read. */
export async function searchFor(text: string, options: { in?: string; limit?: number; offset?: number } = {}): Promise<SearchState | null> {
  try {
    const { data } = await api.GET('/v1/search', {
      params: { query: { q: text, ...(options.in !== undefined ? { in: options.in } : {}), ...(options.limit !== undefined ? { limit: options.limit } : {}), ...(options.offset !== undefined ? { offset: options.offset } : {}) } as never },
    })

    return data ?? null
  } catch {
    return null
  }
}
