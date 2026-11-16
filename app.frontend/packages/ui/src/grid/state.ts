/**
 * The data grid's state and the address that keeps it. The address is the
 * source of truth: `?view=&q=&sort=&dir=&filter[class]=a,b&filter[admitted]=2025-09-01..&page=&per_page=`,
 * so a link opens the same rows. Pure functions, no React and no API.
 */

export const PAGE_SIZES = [10, 25, 50, 100] as const
export const DEFAULT_SIZE = 25

export type SortDir = 'asc' | 'desc'

/** What the viewer asked of a list. `sort` empty means the list's own order. */
export interface GridQuery {
  page: number
  perPage: number
  sort: string
  dir: SortDir
  q: string
  /** Choice filters hold option values; a date filter holds [from, to], either side may be empty. */
  filters: Record<string, string[]>
  /** The saved view the list was opened from, or null. */
  view: string | null
}

export const EMPTY_QUERY: GridQuery = { page: 1, perPage: DEFAULT_SIZE, sort: '', dir: 'asc', q: '', filters: {}, view: null }

const DAY = /^\d{4}-\d{2}-\d{2}$/
const RANGE = /^(\d{4}-\d{2}-\d{2})?\.\.(\d{4}-\d{2}-\d{2})?$/
const FILTER = /^filter\[([a-z][a-z0-9_]*)\]$/

const positive = (value: string | null, fallback: number) => {
  const n = value === null ? Number.NaN : Number(value)

  return Number.isInteger(n) && n >= 1 ? n : fallback
}

/** Reads one filter's value as the address writes it. */
export function readFilterValue(raw: string): string[] {
  const range = RANGE.exec(raw)
  if (range !== null) return [range[1] ?? '', range[2] ?? '']

  return raw
    .split(',')
    .map((v) => v.trim())
    .filter((v) => v !== '')
}

/** Whether a filter's values are a date range. */
export function isRange(values: string[]): boolean {
  return values.length === 2 && values.every((v) => v === '' || DAY.test(v)) && values.some((v) => v !== '')
}

function writeFilterValue(values: string[]): string {
  return isRange(values) ? `${values[0] ?? ''}..${values[1] ?? ''}` : values.join(',')
}

/** The grid's state from the address. Anything unknown falls back; the server drops what it does not know. */
export function readGridQuery(params: URLSearchParams): GridQuery {
  const size = positive(params.get('per_page'), DEFAULT_SIZE)
  const filters: Record<string, string[]> = {}

  params.forEach((value, key) => {
    const name = FILTER.exec(key)?.[1]
    if (name === undefined) return
    const values = readFilterValue(value)
    if (values.length > 0 && values.some((v) => v !== '')) filters[name] = values
  })

  return {
    page: positive(params.get('page'), 1),
    perPage: (PAGE_SIZES as readonly number[]).includes(size) ? size : DEFAULT_SIZE,
    sort: (params.get('sort') ?? '').trim(),
    dir: params.get('dir') === 'desc' ? 'desc' : 'asc',
    q: (params.get('q') ?? '').trim(),
    filters,
    view: params.get('view') || null,
  }
}

/** The address of a grid state: defaults are left out, the order is stable. */
export function writeGridQuery(query: GridQuery): string {
  const params = new URLSearchParams()

  if (query.view !== null) params.set('view', query.view)
  if (query.q !== '') params.set('q', query.q)
  if (query.sort !== '') {
    params.set('sort', query.sort)
    params.set('dir', query.dir)
  }
  for (const key of Object.keys(query.filters).sort()) {
    const values = query.filters[key] ?? []
    if (values.some((v) => v !== '')) params.set(`filter[${key}]`, writeFilterValue(values))
  }
  if (query.page > 1) params.set('page', String(query.page))
  if (query.perPage !== DEFAULT_SIZE) params.set('per_page', String(query.perPage))

  // Brackets and commas read better as they are; URLSearchParams reads them back either way.
  return params.toString().replace(/%5B/g, '[').replace(/%5D/g, ']').replace(/%2C/g, ',')
}

/** The query the server's list address takes: `page, per_page, sort, dir, q, filter[key]`. */
export function gridApiQuery(query: GridQuery): Record<string, string | number | Record<string, string>> {
  const filter: Record<string, string> = {}

  for (const [key, values] of Object.entries(query.filters)) {
    if (values.some((v) => v !== '')) filter[key] = writeFilterValue(values)
  }

  return {
    page: query.page,
    per_page: query.perPage,
    ...(query.sort !== '' ? { sort: query.sort, dir: query.dir } : {}),
    ...(query.q !== '' ? { q: query.q } : {}),
    ...(Object.keys(filter).length > 0 ? { filter } : {}),
  }
}

/** What a saved view keeps: the sort, the search, the filters, the page size and, when given, the columns. */
export interface ViewState {
  sort: string
  dir: SortDir
  q: string
  filters: Record<string, string[]>
  per_page: number
  columns?: string[]
}

export function viewState(query: GridQuery, sort: { sort: string; dir: SortDir }, columns?: string[]): ViewState {
  return {
    sort: query.sort !== '' ? query.sort : sort.sort,
    dir: query.sort !== '' ? query.dir : sort.dir,
    q: query.q,
    filters: query.filters,
    per_page: query.perPage,
    ...(columns !== undefined ? { columns } : {}),
  }
}

const text = (v: unknown) => (typeof v === 'string' ? v : '')

/** A saved view's state, read defensively: it comes from storage. */
export function readViewState(state: Record<string, unknown>): ViewState {
  const filters: Record<string, string[]> = {}
  const raw = state['filters']

  if (raw !== null && typeof raw === 'object' && !Array.isArray(raw)) {
    for (const [key, value] of Object.entries(raw as Record<string, unknown>)) {
      const values = Array.isArray(value) ? value.filter((v): v is string => typeof v === 'string') : typeof value === 'string' ? readFilterValue(value) : []
      if (values.some((v) => v !== '')) filters[key] = values
    }
  }

  const size = Number(state['per_page'])
  const columns = state['columns']

  return {
    sort: text(state['sort']),
    dir: state['dir'] === 'desc' ? 'desc' : 'asc',
    q: text(state['q']),
    filters,
    per_page: (PAGE_SIZES as readonly number[]).includes(size) ? size : DEFAULT_SIZE,
    ...(Array.isArray(columns) ? { columns: columns.filter((c): c is string => typeof c === 'string') } : {}),
  }
}

/** The grid state that opens a saved view, from its first page. */
export function queryFromView(id: string, state: Record<string, unknown>): GridQuery {
  const view = readViewState(state)

  return { page: 1, perPage: view.per_page, sort: view.sort, dir: view.dir, q: view.q, filters: view.filters, view: id }
}

const sameList = (a: string[], b: string[]) => a.length === b.length && a.every((v, i) => v === b[i])

function sameFilters(a: Record<string, string[]>, b: Record<string, string[]>): boolean {
  const keys = new Set([...Object.keys(a), ...Object.keys(b)])

  for (const key of keys) {
    const x = [...(a[key] ?? [])]
    const y = [...(b[key] ?? [])]
    if (!isRange(x)) x.sort()
    if (!isRange(y)) y.sort()
    if (!sameList(x, y)) return false
  }

  return true
}

/** Whether the list as it is now differs from a saved view. Columns count only when the view kept them. */
export function differsFromView(query: GridQuery, sort: { sort: string; dir: SortDir }, shown: string[], state: Record<string, unknown>): boolean {
  const view = readViewState(state)
  const now = viewState(query, sort)

  return (
    now.sort !== view.sort ||
    now.dir !== view.dir ||
    now.q !== view.q ||
    now.per_page !== view.per_page ||
    !sameFilters(now.filters, view.filters) ||
    (view.columns !== undefined && !sameList([...shown].sort(), [...view.columns].sort()))
  )
}

/** How many filters are chosen. */
export function filterCount(filters: Record<string, string[]>): number {
  return Object.values(filters).filter((values) => values.some((v) => v !== '')).length
}

/** The pager's numbers: the first two, the current page and its neighbours, the last; a gap stands for the rest. */
export function pageItems(page: number, pages: number): (number | 'gap')[] {
  if (pages <= 1) return [1]
  const wanted = new Set([1, 2, page - 1, page, page + 1, pages].filter((n) => n >= 1 && n <= pages))
  const sorted = [...wanted].sort((a, b) => a - b)
  const out: (number | 'gap')[] = []

  sorted.forEach((n, i) => {
    const before = sorted[i - 1]
    if (before !== undefined && n - before === 2) out.push(n - 1)
    else if (before !== undefined && n - before > 2) out.push('gap')
    out.push(n)
  })

  return out
}

/** The first and last row number shown on a page. */
export function rowRange(page: number, perPage: number, total: number, shown: number): { from: number; to: number } {
  if (total === 0 || shown === 0) return { from: 0, to: 0 }
  const from = (page - 1) * perPage + 1

  return { from, to: from + shown - 1 }
}
