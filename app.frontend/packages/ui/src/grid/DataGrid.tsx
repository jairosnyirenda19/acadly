import { useEffect, useId, useRef, useState, type ReactNode, type Ref } from 'react'
import { Button } from '../Button'
import { Check } from './Check'
import { GridIcon, type GridIconName } from './icons'
import { useWide } from './useWide'
import {
  differsFromView,
  EMPTY_QUERY,
  filterCount,
  isRange,
  PAGE_SIZES,
  pageItems,
  queryFromView,
  readViewState,
  rowRange,
  viewState,
  type GridQuery,
  type SortDir,
  type ViewState,
} from './state'

/*
 * The data grid (the approved "Data grid" designs): one component for every
 * list that can reach hundreds of rows. The server pages, sorts, searches and
 * filters; the address keeps the state; this draws it. It knows no list: the
 * words come in `labels`, the cells in `render`, the phone card in `card`.
 */

// ── What the server answers ──────────────────────────────────────────────────

export interface GridColumnInfo {
  key: string
  label: string
  sortable: boolean
  shown: boolean
  always: boolean
}

export interface GridFilterInfo {
  key: string
  label: string
  kind: 'choice' | 'dates'
  options: { value: string; label: string; count: number }[]
}

export interface GridViewInfo {
  id: string
  name: string
  state: Record<string, unknown>
}

/** One page of a list, as every grid address answers it. */
export interface GridPage<Row> {
  rows: Row[]
  total: number
  page: number
  per_page: number
  pages: number
  sort: string
  dir: SortDir
  q: string
  filters: Record<string, string[]>
  columns: GridColumnInfo[]
  filter_options: GridFilterInfo[]
  views: GridViewInfo[]
  bulk: { key: string; label: string }[]
  can_export: boolean
}

/** loading: the rows wait (after a short delay, so a quick answer does not flash). */
export type GridPhase = 'loading' | 'ready' | 'failed' | 'refused'

/** The rows chosen: some on this page, or every row that matches. */
export type GridSelection = { all: false; ids: string[] } | { all: true }

export const NO_SELECTION: GridSelection = { all: false, ids: [] }

/** A row as a phone shows it. */
export interface GridCard {
  title: ReactNode
  meta?: ReactNode
  aside?: ReactNode
}

// ── Words ────────────────────────────────────────────────────────────────────

export interface DataGridLabels {
  /** The list's name, for assistive technology: "Learners". */
  name: string
  loading: string
  search: string
  searchPlaceholder: string
  view: string
  /** The plain list, no saved view: "All learners". */
  noView: string
  filters: string
  /** The phone's filter key, which shows only an icon and a count. */
  filtersCount: (n: number) => string
  columns: string
  export: string
  addFilter: string
  clearFilters: string
  removeFilter: (filter: string, value: string) => string
  /** A date filter's words: "From 1 Sep 2025", "1 Sep 2025 to 3 Nov 2025". */
  dates: (from: string, to: string) => string
  /** A day as people read it. */
  day: (iso: string) => string
  from: string
  to: string
  closeFilters: string
  closeColumns: string
  filtersNote: string
  showAll: (n: number) => string
  showFewer: string
  sortBy: string
  sortOption: (column: string, dir: SortDir) => string
  /** The phone sheet's main action: "Show 186 learners". */
  showRows: (total: number) => string
  clear: string
  columnsNote: string
  alwaysShown: string
  usualColumns: string
  viewDiffers: string
  saveChanges: string
  saveAsNew: string
  saveAsView: string
  viewName: string
  viewNameHint: string
  saveView: string
  cancel: string
  deleteView: string
  deleteConfirm: (name: string) => string
  deleteYes: string
  keepView: string
  selectRow: (name: string) => string
  selectPage: string
  rowNumber: string
  /** Beside the count in the bulk bar: "selected on this page" / "selected: every row that matches". */
  selected: (all: boolean, n: number) => string
  selectAll: (total: number) => string
  selectedRegion: string
  exportThese: string
  clearSelection: string
  clearSelectionLabel: string
  /** The phone's line above the cards: "Sorted by name · 186 learners". */
  sortedBy: (column: string, dir: SortDir, total: number) => string
  rowsOf: (from: number, to: number, total: number) => string
  perPage: string
  pages: string
  previous: string
  next: string
  pageNumber: (n: number) => string
  pageOf: (page: number, pages: number) => string
  emptyTitle: string
  emptyText: string
  noMatchTitle: string
  noMatchText: string
  failedTitle: string
  failedText: string
  retry: string
  refusedTitle: string
  refusedText: string
}

// ── Props ────────────────────────────────────────────────────────────────────

export interface GridViews {
  /** Saves a new view; answers its id, or the reason it was not saved. */
  create: (name: string, state: ViewState) => Promise<{ id: string } | { error: string }>
  /** Keeps the list as it is now in a view; answers the reason it was not saved, or null. */
  save: (id: string, name: string, state: ViewState) => Promise<string | null>
  remove: (id: string) => Promise<string | null>
}

export interface DataGridProps<Row extends { id: string }> {
  labels: DataGridLabels
  /** The last answer; kept on screen while the next one loads. */
  page: GridPage<Row> | undefined
  phase: GridPhase
  query: GridQuery
  /** A new state for the address. `replace` for typing, so each letter is not a step back. */
  onQuery: (next: GridQuery, replace?: boolean) => void
  onRetry: () => void
  /** A cell for each column key. A key without one shows the row's value as text. */
  render?: Partial<Record<string, (row: Row) => ReactNode>>
  /** Column heads in the school's own words, in place of the server's: { learner: 'Pupil' }. */
  headers?: Partial<Record<string, string>> | undefined
  /** Columns whose cells sit at the right, such as counts. */
  alignEnd?: string[]
  card: (row: Row) => GridCard
  /** The row's name, for its checkbox: "Select Alinafe Banda". */
  rowName: (row: Row) => string
  rowTone?: ((row: Row) => 'attention' | 'wrong' | null) | undefined
  /** The row whose detail is open beside the list: a paper fill and a 3px ink edge ("this one"). */
  current?: string | null | undefined
  /** Opens a row when it is clicked anywhere but on a control. Keyboard users open it through a link in a cell. */
  onOpen?: ((row: Row) => void) | undefined
  selection: GridSelection
  onSelection: (next: GridSelection) => void
  onBulk?: ((action: string) => void) | undefined
  /** Under the bulk bar: the form of the action being taken. */
  bulkPanel?: ReactNode
  /** Above the rows: what an action came to. */
  notice?: ReactNode
  onColumns: (shown: string[] | 'reset') => void
  views?: GridViews | undefined
  onExport?: ((scope: 'match' | 'selected') => void) | undefined
  /** What to do when nothing is recorded yet. */
  emptyActions?: ReactNode
}

// ── Small parts ──────────────────────────────────────────────────────────────

const gutter = 'px-4 md:px-[clamp(16px,3vw,40px)]'
const fieldLook = 'h-11 w-full border-0 border-b-2 border-ink bg-field px-3 text-[15px] text-ink outline-none focus:border-teal'

function ToolButton({ icon, children, onClick, pressed, controls, label, disabled, buttonRef, className = '' }: {
  icon: GridIconName
  children?: ReactNode
  onClick: () => void
  pressed?: boolean | undefined
  controls?: string | undefined
  label?: string | undefined
  disabled?: boolean | undefined
  buttonRef?: Ref<HTMLButtonElement> | undefined
  className?: string
}) {
  return (
    <button
      ref={buttonRef}
      type="button"
      onClick={onClick}
      aria-expanded={pressed}
      aria-controls={controls}
      aria-label={label}
      disabled={disabled}
      className={`inline-flex h-11 items-center gap-2 border-2 px-3 text-sm font-extrabold ${disabled === true ? 'border-disabled bg-disabled text-text-muted' : pressed === true ? 'border-ink bg-ink text-sheet' : 'border-ink bg-sheet text-ink hover:bg-field'} ${className}`}
    >
      <GridIcon name={icon} />
      {children}
    </button>
  )
}

function Count({ n, inverse = false }: { n: number; inverse?: boolean }) {
  return <span className={`px-1.5 py-px text-xs font-extrabold tabular-nums ${inverse ? 'bg-sheet text-ink' : 'bg-ink text-sheet'}`}>{n}</span>
}

function TextLink({ children, onClick, onInk = false }: { children: ReactNode; onClick: () => void; onInk?: boolean }) {
  return (
    <button type="button" onClick={onClick} className={`min-h-11 px-1 text-sm font-extrabold underline underline-offset-[3px] ${onInk ? 'text-sheet focus-visible:outline-teal-bright' : 'text-ink'}`}>
      {children}
    </button>
  )
}

/** A boxed state in place of the rows: empty, nothing matches, failed, refused. */
function StatePanel({ tone = 'plain', title, text, children }: { tone?: 'plain' | 'wrong'; title: string; text: string; children?: ReactNode }) {
  return (
    <div role={tone === 'wrong' ? 'alert' : undefined} className={`mx-4 my-2 border-2 bg-sheet p-4 md:mx-[clamp(16px,3vw,40px)] ${tone === 'wrong' ? 'border-red-pen' : 'border-ink'}`}>
      <p className={`type-display mb-1.5 text-[22px] leading-none ${tone === 'wrong' ? 'text-red-text' : ''}`}>{title}</p>
      <p className="mb-3 max-w-[65ch] text-sm">{text}</p>
      {children !== undefined && <div className="flex flex-wrap gap-2">{children}</div>}
    </div>
  )
}

const bars = ['70%', '55%', '80%', '62%', '48%', '74%', '58%', '66%', '52%', '78%']

/** Rows ruled before they are written in: no spinner. */
function SkeletonRows({ rows, cells }: { rows: number; cells: number }) {
  return (
    <>
      {Array.from({ length: rows }, (_, i) => (
        <tr key={i} aria-hidden="true">
          <td className="h-14 w-11 border-b border-rule">
            <span className="mx-auto block size-[22px] bg-field" />
          </td>
          <td className="w-[52px] min-w-[52px] border-r-2 border-b border-r-red-pen border-b-rule" />
          {Array.from({ length: cells }, (_, j) => (
            <td key={j} className="border-b border-rule pr-3 pl-3">
              <span className="block h-3 bg-field" style={{ width: j === 0 ? (bars[i % bars.length] ?? '60%') : '70%' }} />
            </td>
          ))}
        </tr>
      ))}
    </>
  )
}

function SkeletonCards({ rows }: { rows: number }) {
  return (
    <ul aria-hidden="true">
      {Array.from({ length: rows }, (_, i) => (
        <li key={i} className="flex min-h-14 items-center gap-3 border-b border-rule bg-sheet px-4 py-3">
          <span className="size-[22px] shrink-0 bg-field" />
          <span className="flex flex-1 flex-col gap-1.5">
            <span className="block h-3.5 bg-field" style={{ width: bars[i % bars.length] ?? '60%' }} />
            <span className="block h-2.5 w-[40%] bg-field" />
          </span>
        </li>
      ))}
    </ul>
  )
}

/** The filters with one filter's values changed; no values leaves it out. */
const withFilter = (filters: Record<string, string[]>, key: string, values: string[]) =>
  Object.fromEntries([...Object.entries(filters).filter(([k]) => k !== key), ...(values.length > 0 ? [[key, values] as const] : [])])

const cellText = (row: object, key: string): string => {
  const value = (row as Record<string, unknown>)[key]

  return typeof value === 'string' || typeof value === 'number' ? String(value) : ''
}

// ── The grid ─────────────────────────────────────────────────────────────────

export function DataGrid<Row extends { id: string }>(props: DataGridProps<Row>) {
  const { labels: t, page, phase, query, onQuery, selection, onSelection, views } = props
  const wide = useWide()
  const ids = useId()
  const panelId = `${ids}-panel`
  const [panel, setPanel] = useState<'filters' | 'columns' | null>(null)
  const [naming, setNaming] = useState(false)
  const [deleting, setDeleting] = useState(false)
  const filtersButton = useRef<HTMLButtonElement>(null)
  const columnsButton = useRef<HTMLButtonElement>(null)

  // Search: typed text waits 300 ms, then goes to the address.
  const [text, setText] = useState(query.q)
  const sent = useRef(query.q)
  const latest = useRef({ query, onQuery })
  latest.current = { query, onQuery }

  useEffect(() => {
    if (query.q !== sent.current) {
      sent.current = query.q
      setText(query.q)
    }
  }, [query.q])

  useEffect(() => {
    const value = text.trim()
    if (value === sent.current) return
    const wait = window.setTimeout(() => {
      sent.current = value
      latest.current.onQuery({ ...latest.current.query, q: value, page: 1 }, true)
    }, 300)

    return () => window.clearTimeout(wait)
  }, [text])

  const closePanel = () => {
    const was = panel
    setPanel(null)
    window.requestAnimationFrame(() => (was === 'columns' ? columnsButton : filtersButton).current?.focus())
  }

  const rows = page?.rows ?? []
  const allColumns = (page?.columns ?? []).map((c) => ({ ...c, label: props.headers?.[c.key] ?? c.label }))
  const columns = allColumns.filter((c) => c.shown)
  const shownKeys = columns.map((c) => c.key)
  const sort = { sort: page?.sort ?? query.sort, dir: page?.dir ?? query.dir }
  const chosen = filterCount(query.filters)
  /** A click on a row opens it, unless it landed on a control inside the row. */
  const openRow = (event: { target: EventTarget }, row: Row) => {
    if (event.target instanceof Element && event.target.closest('a, button, input, label, select, textarea') !== null) return
    props.onOpen?.(row)
  }

  const current = query.view !== null ? (page?.views.find((v) => v.id === query.view) ?? null) : null
  const differs = current !== null && page !== undefined && differsFromView(query, sort, shownKeys, current.state)
  const narrowed = query.q !== '' || chosen > 0
  const go = (patch: Partial<GridQuery>, replace = false) => onQuery({ ...query, page: 1, ...patch }, replace)

  // Selection on this page, or every row that matches.
  const pageIds = rows.map((r) => r.id)
  const isSelected = (id: string) => selection.all || selection.ids.includes(id)
  const selectedHere = pageIds.filter(isSelected).length
  const selectedCount = selection.all ? (page?.total ?? 0) : selection.ids.length
  const toggleRow = (id: string, on: boolean) => {
    const base = selection.all ? pageIds : selection.ids
    onSelection({ all: false, ids: on ? [...new Set([...base, id])] : base.filter((x) => x !== id) })
  }
  const togglePage = () => onSelection(selectedHere === pageIds.length && pageIds.length > 0 ? { all: false, ids: [] } : { all: false, ids: pageIds })

  const sortBy = (key: string) => go({ sort: key, dir: sort.sort === key && sort.dir === 'asc' ? 'desc' : 'asc' })

  const openView = (id: string) => {
    if (id === '') {
      onQuery({ ...EMPTY_QUERY, perPage: query.perPage })

      return
    }
    const view = page?.views.find((v) => v.id === id)
    if (view === undefined) return
    const kept = readViewState(view.state).columns
    if (kept !== undefined && [...kept].sort().join() !== [...shownKeys].sort().join()) props.onColumns(kept)
    onQuery(queryFromView(view.id, view.state))
  }

  const toggleValue = (key: string, value: string, on: boolean) => {
    const values = query.filters[key] ?? []
    const next = on ? [...values, value] : values.filter((v) => v !== value)
    go({ filters: withFilter(query.filters, key, next) })
  }

  const setRange = (key: string, side: 0 | 1, value: string) => {
    const range = isRange(query.filters[key] ?? []) ? [...(query.filters[key] ?? [])] : ['', '']
    range[side] = value
    go({ filters: withFilter(query.filters, key, range.every((v) => v === '') ? [] : range) })
  }

  const removeFilter = (key: string) => {
    go({ filters: withFilter(query.filters, key, []) })
  }

  const range = page !== undefined ? rowRange(page.page, page.per_page, page.total, rows.length) : { from: 0, to: 0 }
  const waiting = phase === 'loading'
  const failed = phase === 'failed' || phase === 'refused'
  const filterPanelOpen = panel === 'filters'
  const columnsPanelOpen = panel === 'columns' && wide

  // ── The rows, or the state in their place ──
  let body: ReactNode
  if (failed) {
    body =
      phase === 'refused' ? (
        <StatePanel tone="wrong" title={t.refusedTitle} text={t.refusedText} />
      ) : (
        <StatePanel tone="wrong" title={t.failedTitle} text={t.failedText}>
          <Button variant="secondary" onClick={props.onRetry}>
            {t.retry}
          </Button>
        </StatePanel>
      )
  } else if (!waiting && page !== undefined && rows.length === 0) {
    body = narrowed ? (
      <StatePanel title={t.noMatchTitle} text={t.noMatchText}>
        <Button variant="secondary" onClick={() => go({ filters: {}, q: '' })}>
          {t.clearFilters}
        </Button>
      </StatePanel>
    ) : (
      <StatePanel title={t.emptyTitle} text={t.emptyText}>
        {props.emptyActions}
      </StatePanel>
    )
  } else if (wide) {
    body = (
      <div className="overflow-x-auto md:px-[clamp(16px,3vw,40px)]">
        <table className="w-full min-w-[720px] border-collapse text-left" aria-label={t.name} aria-rowcount={page !== undefined ? page.total + 1 : undefined} aria-busy={waiting || undefined}>
          <thead>
            <tr className="label-caps border-b-2 border-ink text-text-muted">
              <th scope="col" className="w-11 pb-1 align-bottom">
                {page !== undefined && (
                  <Check checked={pageIds.length > 0 && selectedHere === pageIds.length} mixed={selectedHere > 0 && selectedHere < pageIds.length} onChange={togglePage} label={t.selectPage} disabled={pageIds.length === 0} />
                )}
              </th>
              <th scope="col" className="w-[52px] min-w-[52px] pb-2 align-bottom">
                <span className="sr-only">{t.rowNumber}</span>
              </th>
              {columns.map((c) => {
                const active = sort.sort === c.key
                const end = props.alignEnd?.includes(c.key) === true

                return (
                  <th
                    key={c.key}
                    scope="col"
                    aria-sort={c.sortable ? (active ? (sort.dir === 'asc' ? 'ascending' : 'descending') : 'none') : undefined}
                    className={`pr-3 pb-1 pl-3 align-bottom font-extrabold whitespace-nowrap ${end ? 'text-right' : ''}`}
                  >
                    {c.sortable ? (
                      <button type="button" onClick={() => sortBy(c.key)} className={`label-caps inline-flex min-h-11 items-center gap-1 ${active ? 'text-ink' : 'text-text-muted hover:text-ink'}`}>
                        {c.label}
                        <GridIcon name={active ? (sort.dir === 'asc' ? 'sortAsc' : 'sortDesc') : 'sortNone'} size={14} />
                      </button>
                    ) : (
                      <span className="inline-flex min-h-11 items-center">{c.label}</span>
                    )}
                  </th>
                )
              })}
            </tr>
          </thead>
          <tbody>
            {waiting ? (
              <SkeletonRows rows={Math.min(query.perPage, 10)} cells={Math.max(columns.length, 3)} />
            ) : (
              rows.map((row, i) => {
                const on = isSelected(row.id)
                const open = props.current === row.id
                const tone = props.rowTone?.(row) ?? null
                const fill = on ? 'bg-field' : open ? 'bg-paper' : tone === 'attention' ? 'bg-marigold-wash hover:bg-paper' : tone === 'wrong' ? 'bg-red-wash hover:bg-paper' : 'hover:bg-paper'
                const edge = on ? 'before:w-1.5' : open ? 'before:w-[3px]' : ''

                return (
                  <tr
                    key={row.id}
                    aria-selected={on}
                    aria-current={open || undefined}
                    aria-rowindex={range.from + i + 1}
                    className={`${fill} ${props.onOpen !== undefined ? 'cursor-pointer' : ''}`}
                    onClick={props.onOpen !== undefined ? (event) => openRow(event, row) : undefined}
                  >
                    <td className={`relative h-14 w-11 min-w-11 border-b border-rule text-center ${edge !== '' ? `before:absolute before:inset-y-0 before:left-0 before:bg-ink ${edge}` : ''}`}>
                      <Check checked={on} onChange={(next) => toggleRow(row.id, next)} label={t.selectRow(props.rowName(row))} />
                    </td>
                    <td className="type-display w-[52px] min-w-[52px] border-r-2 border-b border-r-red-pen border-b-rule text-center text-lg text-text-muted">{range.from + i}</td>
                    {columns.map((c) => {
                      const end = props.alignEnd?.includes(c.key) === true
                      const cell = props.render?.[c.key]

                      return (
                        <td key={c.key} className={`border-b border-rule px-3 py-2 text-sm ${end ? 'text-right' : ''} ${c.always ? 'min-w-[180px]' : 'whitespace-nowrap'}`}>
                          {cell !== undefined ? cell(row) : cellText(row, c.key)}
                        </td>
                      )
                    })}
                  </tr>
                )
              })
            )}
          </tbody>
        </table>
      </div>
    )
  } else {
    const sortLabel = allColumns.find((c) => c.key === sort.sort)?.label ?? ''
    body = (
      <>
        {page !== undefined && <div className="label-caps border-t-2 border-ink bg-sheet px-4 py-1.5 text-text-muted">{t.sortedBy(sortLabel, sort.dir, page.total)}</div>}
        {waiting ? (
          <SkeletonCards rows={6} />
        ) : (
          <ul aria-label={t.name} aria-busy={waiting || undefined}>
            {rows.map((row) => {
              const on = isSelected(row.id)
              const open = props.current === row.id
              const tone = props.rowTone?.(row) ?? null
              const card = props.card(row)

              return (
                <li
                  key={row.id}
                  onClick={props.onOpen !== undefined ? (event) => openRow(event, row) : undefined}
                  className={`${props.onOpen !== undefined ? 'cursor-pointer ' : ''}relative flex min-h-14 items-start gap-1 border-b border-rule py-1.5 pr-4 pl-1.5 ${on ? 'bg-field before:absolute before:inset-y-0 before:left-0 before:w-1.5 before:bg-ink' : open ? 'bg-paper before:absolute before:inset-y-0 before:left-0 before:w-[3px] before:bg-ink' : tone === 'attention' ? 'bg-marigold-wash' : tone === 'wrong' ? 'bg-red-wash' : 'bg-sheet'}`}
                >
                  <Check checked={on} onChange={(next) => toggleRow(row.id, next)} label={t.selectRow(props.rowName(row))} />
                  <div className="min-w-0 flex-1 py-2">
                    <div className="text-base font-extrabold">{card.title}</div>
                    {card.meta !== undefined && <div className="ref text-xs text-text-muted">{card.meta}</div>}
                  </div>
                  {card.aside !== undefined && <div className="py-2.5">{card.aside}</div>}
                </li>
              )
            })}
          </ul>
        )}
      </>
    )
  }

  // ── The panel beside the list (wide) or the sheet over it (phone) ──
  const filtersPanel = filterPanelOpen && page !== undefined && (
    <FiltersPanel
      id={panelId}
      labels={t}
      page={page}
      columns={allColumns}
      query={query}
      sheet={!wide}
      sort={sort}
      onClose={closePanel}
      onToggle={toggleValue}
      onRange={setRange}
      onSort={(key, dir) => go({ sort: key, dir })}
      onClear={() => go({ filters: {} })}
    />
  )

  const columnsPanel = columnsPanelOpen && page !== undefined && (
    <ColumnsPanel id={panelId} labels={t} columns={allColumns} onClose={closePanel} onColumns={props.onColumns} />
  )

  return (
    <div className="flex min-w-0 flex-1 items-stretch bg-sheet">
      <section aria-label={t.name} className="flex min-w-0 flex-1 flex-col pt-4 pb-6">
        <p className="sr-only" role="status" aria-live="polite">
          {page === undefined || waiting ? t.loading : t.rowsOf(range.from, range.to, page.total)}
        </p>

        {/* Search, view and the keys */}
        <div className={`mb-2.5 flex flex-wrap items-end gap-2.5 ${gutter}`}>
          <label className="min-w-0 flex-[1_1_200px] md:max-w-[420px]">
            <span className="label-caps mb-1.5 hidden text-text-muted md:block">{t.search}</span>
            <input type="search" aria-label={wide ? undefined : t.search} placeholder={wide ? t.searchPlaceholder : t.search} value={text} onChange={(e) => setText(e.target.value)} className={fieldLook} />
          </label>
          {page !== undefined && wide && page.views.length > 0 && (
            <label className="w-[230px]">
              <span className="label-caps mb-1.5 block text-text-muted">{t.view}</span>
              <select value={current?.id ?? ''} onChange={(e) => openView(e.target.value)} className={`${fieldLook} font-extrabold`}>
                <option value="">{t.noView}</option>
                {page.views.map((v) => (
                  <option key={v.id} value={v.id}>
                    {v.name}
                  </option>
                ))}
              </select>
            </label>
          )}
          {page !== undefined && (
            <span className="flex gap-2 md:ml-auto">
              <ToolButton
                icon="filters"
                buttonRef={filtersButton}
                pressed={filterPanelOpen}
                controls={filterPanelOpen ? panelId : undefined}
                label={wide ? undefined : t.filtersCount(chosen)}
                onClick={() => (filterPanelOpen ? closePanel() : setPanel('filters'))}
              >
                {wide && t.filters}
                {chosen > 0 && <Count n={chosen} inverse={filterPanelOpen} />}
              </ToolButton>
              {wide && (
                <ToolButton icon="columns" buttonRef={columnsButton} pressed={columnsPanelOpen} controls={columnsPanelOpen ? panelId : undefined} onClick={() => (columnsPanelOpen ? closePanel() : setPanel('columns'))}>
                  {t.columns}
                </ToolButton>
              )}
              {wide && page.can_export && page.total > 0 && props.onExport !== undefined && (
                <ToolButton icon="export" onClick={() => props.onExport?.('match')}>
                  {t.export}
                </ToolButton>
              )}
            </span>
          )}
        </div>

        {/* The filters in force, and the view */}
        {page !== undefined && (chosen > 0 || (wide && (current !== null || narrowed))) && (
          <div className={`mb-3 flex flex-nowrap items-center gap-2 overflow-x-auto md:flex-wrap md:overflow-visible ${gutter}`}>
            {page.filter_options.map((f) => {
              const values = query.filters[f.key]
              if (values === undefined || values.every((v) => v === '')) return null
              const said =
                f.kind === 'dates' ? t.dates(values[0] ? t.day(values[0]) : '', values[1] ? t.day(values[1]) : '') : values.map((v) => f.options.find((o) => o.value === v)?.label ?? v).join(', ')

              return (
                <span key={f.key} className="inline-flex h-11 shrink-0 items-center border-2 border-ink bg-sheet text-sm">
                  <span className="pr-1 pl-2.5 text-text-muted">{f.label}</span>
                  <span className="pr-2 font-extrabold whitespace-nowrap">{said}</span>
                  <button type="button" aria-label={t.removeFilter(f.label, said)} onClick={() => removeFilter(f.key)} className="inline-flex h-full w-10 items-center justify-center border-l border-rule hover:bg-field">
                    <GridIcon name="close" size={12} />
                  </button>
                </span>
              )
            })}
            {wide && (
              <button type="button" onClick={() => setPanel('filters')} className="inline-flex h-11 shrink-0 items-center gap-1.5 border-2 border-dashed border-rule-dashed bg-sheet px-2.5 text-sm font-extrabold hover:bg-field">
                <GridIcon name="add" size={14} />
                {t.addFilter}
              </button>
            )}
            {chosen > 0 && <TextLink onClick={() => go({ filters: {} })}>{t.clearFilters}</TextLink>}
            {wide && views !== undefined && (
              <span className="ml-auto text-[13px] text-text-muted">
                {deleting && current !== null ? (
                  <>
                    {t.deleteConfirm(current.name)}{' '}
                    <TextLink
                      onClick={() => {
                        void views?.remove(current.id).then((error) => {
                          setDeleting(false)
                          if (error === null) onQuery({ ...query, view: null })
                        })
                      }}
                    >
                      {t.deleteYes}
                    </TextLink>
                    {' · '}
                    <TextLink onClick={() => setDeleting(false)}>{t.keepView}</TextLink>
                  </>
                ) : current !== null && differs ? (
                  <>
                    {t.viewDiffers}{' '}
                    <TextLink onClick={() => void views?.save(current.id, current.name, viewState(query, sort, shownKeys))}>{t.saveChanges}</TextLink>
                    {' · '}
                    <TextLink onClick={() => setNaming(true)}>{t.saveAsNew}</TextLink>
                    {' · '}
                    <TextLink onClick={() => setDeleting(true)}>{t.deleteView}</TextLink>
                  </>
                ) : current !== null ? (
                  <TextLink onClick={() => setDeleting(true)}>{t.deleteView}</TextLink>
                ) : (
                  <TextLink onClick={() => setNaming(true)}>{t.saveAsView}</TextLink>
                )}
              </span>
            )}
          </div>
        )}

        {naming && views !== undefined && page !== undefined && (
          <div className={gutter}>
            <NameView
              labels={t}
              onCancel={() => setNaming(false)}
              onSave={async (name) => {
                const saved = await views.create(name, viewState(query, sort, shownKeys))
                if ('error' in saved) return saved.error
                setNaming(false)
                onQuery({ ...query, view: saved.id })

                return null
              }}
            />
          </div>
        )}

        {/* The rows chosen, and what can be done to them */}
        {selectedCount > 0 && page !== undefined && (
          <div className={`mb-2.5 ${gutter}`}>
            <div role="region" aria-label={t.selectedRegion} className="flex flex-wrap items-center gap-x-2.5 gap-y-1 bg-ink py-1.5 pr-2 pl-4 text-sheet">
              <span className="type-numeral text-[32px] text-teal-bright">{selectedCount}</span>
              <span className="text-[15px] font-extrabold">{t.selected(selection.all, selectedCount)}</span>
              {!selection.all && selectedHere === pageIds.length && page.total > pageIds.length && (
                <TextLink onInk onClick={() => onSelection({ all: true })}>
                  {t.selectAll(page.total)}
                </TextLink>
              )}
              <span className="flex flex-wrap gap-2 md:ml-auto">
                {page.bulk.map((action) => (
                  <button
                    key={action.key}
                    type="button"
                    onClick={() => props.onBulk?.(action.key)}
                    className="h-11 border-2 border-sheet px-3.5 text-sm font-extrabold text-sheet hover:bg-ink-rule focus-visible:outline-teal-bright"
                  >
                    {action.label}
                  </button>
                ))}
                {page.can_export && props.onExport !== undefined && (
                  <button type="button" onClick={() => props.onExport?.('selected')} className="h-11 border-2 border-sheet px-3.5 text-sm font-extrabold text-sheet hover:bg-ink-rule focus-visible:outline-teal-bright">
                    {t.exportThese}
                  </button>
                )}
                <button type="button" aria-label={t.clearSelectionLabel} onClick={() => onSelection(NO_SELECTION)} className="h-11 px-2.5 text-sm font-extrabold text-on-ink-soft hover:text-sheet focus-visible:outline-teal-bright">
                  {t.clearSelection}
                </button>
              </span>
            </div>
            {props.bulkPanel}
          </div>
        )}

        {props.notice !== undefined && props.notice !== null && props.notice !== false && <div className={`mb-3 ${gutter}`}>{props.notice}</div>}

        {page === undefined && !failed ? (
          wide ? (
            <div className="md:px-[clamp(16px,3vw,40px)]">
              <table className="w-full border-collapse" aria-hidden="true">
                <tbody>
                  <SkeletonRows rows={8} cells={4} />
                </tbody>
              </table>
            </div>
          ) : (
            <SkeletonCards rows={6} />
          )
        ) : (
          body
        )}

        {page !== undefined && page.total > 0 && !failed && <Pager labels={t} page={page} wide={wide} range={range} onPage={(n) => onQuery({ ...query, page: n })} onSize={(n) => go({ perPage: n })} />}
      </section>

      {filtersPanel}
      {columnsPanel}
    </div>
  )
}

// ── The pager ────────────────────────────────────────────────────────────────

function PageKey({ children, label, onClick, disabled, current }: { children: ReactNode; label?: string; onClick: () => void; disabled?: boolean; current?: boolean }) {
  return (
    <button
      type="button"
      aria-label={label}
      aria-current={current === true ? 'page' : undefined}
      disabled={disabled}
      onClick={onClick}
      className={`inline-flex size-11 items-center justify-center border-2 text-sm font-extrabold tabular-nums ${disabled === true ? 'border-disabled bg-disabled text-text-muted' : current === true ? 'border-ink bg-ink text-sheet' : 'border-ink bg-sheet text-ink hover:bg-field'}`}
    >
      {children}
    </button>
  )
}

function Pager<Row>({ labels: t, page, wide, range, onPage, onSize }: { labels: DataGridLabels; page: GridPage<Row>; wide: boolean; range: { from: number; to: number }; onPage: (n: number) => void; onSize: (n: number) => void }) {
  const first = page.page <= 1
  const last = page.page >= page.pages

  if (!wide) {
    return (
      <nav aria-label={t.pages} className="flex items-center gap-3 border-t-2 border-ink bg-sheet px-4 pt-3">
        <PageKey label={t.previous} disabled={first} onClick={() => onPage(page.page - 1)}>
          <GridIcon name="previous" size={14} />
        </PageKey>
        <span className="flex-1 text-center text-sm font-extrabold tabular-nums">{t.pageOf(page.page, page.pages)}</span>
        <PageKey label={t.next} disabled={last} onClick={() => onPage(page.page + 1)}>
          <GridIcon name="next" size={14} />
        </PageKey>
      </nav>
    )
  }

  return (
    <nav aria-label={t.pages} className="flex flex-wrap items-center gap-3 px-[clamp(16px,3vw,40px)] pt-3.5">
      <span aria-hidden="true" className="text-sm font-extrabold tabular-nums">
        {t.rowsOf(range.from, range.to, page.total)}
      </span>
      <label className="inline-flex items-center gap-2 text-[13px] text-text-muted">
        {t.perPage}
        <select value={page.per_page} onChange={(e) => onSize(Number(e.target.value))} className="h-11 w-[76px] border-0 border-b-2 border-ink bg-field px-3 text-sm text-ink outline-none focus:border-teal">
          {PAGE_SIZES.map((n) => (
            <option key={n} value={n}>
              {n}
            </option>
          ))}
        </select>
      </label>
      <span className="ml-auto flex flex-wrap items-center gap-1">
        <PageKey label={t.previous} disabled={first} onClick={() => onPage(page.page - 1)}>
          <GridIcon name="previous" size={14} />
        </PageKey>
        {pageItems(page.page, page.pages).map((item, i) =>
          item === 'gap' ? (
            <span key={`gap-${i}`} aria-hidden="true" className="w-6 text-center text-text-muted">
              …
            </span>
          ) : (
            <PageKey key={item} label={t.pageNumber(item)} current={item === page.page} onClick={() => onPage(item)}>
              {item}
            </PageKey>
          ),
        )}
        <PageKey label={t.next} disabled={last} onClick={() => onPage(page.page + 1)}>
          <GridIcon name="next" size={14} />
        </PageKey>
      </span>
    </nav>
  )
}

// ── Panels ───────────────────────────────────────────────────────────────────

const LONG = 6

function PanelHead({ title, close, onClose, headRef, sheet }: { title: string; close: string; onClose: () => void; headRef: Ref<HTMLHeadingElement>; sheet: boolean }) {
  return (
    <div className={`flex items-center gap-2.5 ${sheet ? 'border-b-2 border-ink bg-sheet p-3' : 'mb-3.5'}`}>
      <h2 ref={headRef} tabIndex={-1} className={`type-display flex-1 leading-none outline-none ${sheet ? 'text-4xl' : 'text-[26px]'}`}>
        {title}
      </h2>
      <button type="button" aria-label={close} onClick={onClose} className="inline-flex size-11 items-center justify-center border-2 border-ink text-ink hover:bg-field">
        <GridIcon name="close" size={14} />
      </button>
    </div>
  )
}

function useLanding(onClose: () => void) {
  const head = useRef<HTMLHeadingElement>(null)
  const close = useRef(onClose)
  close.current = onClose

  useEffect(() => {
    head.current?.focus()
    const key = (e: KeyboardEvent) => {
      if (e.key === 'Escape') close.current()
    }
    window.addEventListener('keydown', key)

    return () => window.removeEventListener('keydown', key)
  }, [])

  return head
}

function FiltersPanel<Row>({
  id,
  labels: t,
  page,
  columns,
  query,
  sheet,
  sort,
  onClose,
  onToggle,
  onRange,
  onSort,
  onClear,
}: {
  id: string
  labels: DataGridLabels
  page: GridPage<Row>
  columns: GridColumnInfo[]
  query: GridQuery
  sheet: boolean
  sort: { sort: string; dir: SortDir }
  onClose: () => void
  onToggle: (key: string, value: string, on: boolean) => void
  onRange: (key: string, side: 0 | 1, value: string) => void
  onSort: (key: string, dir: SortDir) => void
  onClear: () => void
}) {
  const head = useLanding(onClose)
  const [open, setOpen] = useState<Record<string, boolean>>({})

  // On a phone the sheet covers the page; the page behind it does not scroll.
  useEffect(() => {
    if (!sheet) return
    const before = document.documentElement.style.overflow
    document.documentElement.style.overflow = 'hidden'

    return () => {
      document.documentElement.style.overflow = before
    }
  }, [sheet])

  const sortable = columns.filter((c) => c.sortable)

  return (
    <Frame id={id} label={t.filters} sheet={sheet} onClose={onClose}>
      <PanelHead title={t.filters} close={t.closeFilters} onClose={onClose} headRef={head} sheet={sheet} />
      <div className={sheet ? 'flex-1 overflow-y-auto px-3 py-3.5' : ''}>
        {sheet && sortable.length > 0 && (
          <fieldset className="mb-4">
            <legend className="label-caps mb-2 text-text-muted">{t.sortBy}</legend>
            <select
              aria-label={t.sortBy}
              value={`${sort.sort}:${sort.dir}`}
              onChange={(e) => {
                const [key, dir] = e.target.value.split(':')
                onSort(key ?? '', dir === 'desc' ? 'desc' : 'asc')
              }}
              className={`${fieldLook} font-extrabold`}
            >
              {sortable.flatMap((c) =>
                (['asc', 'desc'] as const).map((dir) => (
                  <option key={`${c.key}:${dir}`} value={`${c.key}:${dir}`}>
                    {t.sortOption(c.label, dir)}
                  </option>
                )),
              )}
            </select>
          </fieldset>
        )}

        {page.filter_options.map((f) => {
          const values = query.filters[f.key] ?? []

          if (f.kind === 'dates') {
            const [from = '', to = ''] = isRange(values) ? values : ['', '']

            return (
              <fieldset key={f.key} className="mb-4">
                <legend className="label-caps mb-2 text-text-muted">{f.label}</legend>
                <div className="grid grid-cols-2 gap-2">
                  <label>
                    <span className="mb-1 block text-xs text-text-muted">{t.from}</span>
                    <input type="date" value={from} max={to || undefined} onChange={(e) => onRange(f.key, 0, e.target.value)} className={`${fieldLook} text-sm`} />
                  </label>
                  <label>
                    <span className="mb-1 block text-xs text-text-muted">{t.to}</span>
                    <input type="date" value={to} min={from || undefined} onChange={(e) => onRange(f.key, 1, e.target.value)} className={`${fieldLook} text-sm`} />
                  </label>
                </div>
              </fieldset>
            )
          }

          const long = f.options.length > LONG + 1
          const hiddenChosen = f.options.slice(LONG).some((o) => values.includes(o.value))
          const all = !long || open[f.key] === true || hiddenChosen
          const options = all ? f.options : f.options.slice(0, LONG)

          return (
            <fieldset key={f.key} className="mb-4">
              <legend className="label-caps mb-1 text-text-muted">{f.label}</legend>
              {options.map((o) => {
                const on = values.includes(o.value)

                return (
                  <Check key={o.value} checked={on} onChange={(next) => onToggle(f.key, o.value, next)} className={`flex w-full text-sm ${on ? 'font-extrabold' : 'font-medium'}`}>
                    <span className="flex-1">{o.label}</span>
                    <span className="ref text-xs text-text-muted">{o.count}</span>
                  </Check>
                )
              })}
              {long && !hiddenChosen && <TextLink onClick={() => setOpen((s) => ({ ...s, [f.key]: !all }))}>{all ? t.showFewer : t.showAll(f.options.length)}</TextLink>}
            </fieldset>
          )
        })}

        {!sheet && <p className="text-[13px] text-text-muted">{t.filtersNote}</p>}
      </div>
      {sheet && (
        <div className="flex gap-2 border-t-2 border-ink bg-sheet px-3 py-2.5">
          <Button variant="secondary" className="flex-1" onClick={onClear}>
            {t.clear}
          </Button>
          <Button className="h-12 flex-[1.4]" onClick={onClose}>
            {t.showRows(page.total)}
          </Button>
        </div>
      )}
    </Frame>
  )
}

/**
 * The filters' frame: a column beside the list on a wide screen; on a phone a
 * sheet over the whole screen, in the browser's top layer, so nothing on the
 * page can sit above it.
 */
function Frame({ id, label, sheet, onClose, children }: { id: string; label: string; sheet: boolean; onClose: () => void; children: ReactNode }) {
  const dialog = useRef<HTMLDialogElement>(null)

  useEffect(() => {
    const d = dialog.current
    if (d === null) return
    if (!d.open && typeof d.showModal === 'function') d.showModal()

    return () => d.close()
  }, [sheet])

  if (!sheet) {
    return (
      <aside id={id} aria-label={label} className="w-[340px] flex-none border-l-2 border-ink bg-paper px-6 pt-4 pb-6">
        {children}
      </aside>
    )
  }

  return (
    <dialog
      ref={dialog}
      id={id}
      aria-label={label}
      onCancel={(e) => {
        e.preventDefault()
        onClose()
      }}
      className="m-0 h-full max-h-none w-full max-w-none border-0 bg-paper p-0 text-ink open:flex open:flex-col"
    >
      {children}
    </dialog>
  )
}

function ColumnsPanel({ id, labels: t, columns, onClose, onColumns }: { id: string; labels: DataGridLabels; columns: GridColumnInfo[]; onClose: () => void; onColumns: (shown: string[] | 'reset') => void }) {
  const head = useLanding(onClose)
  const shown = columns.filter((c) => c.shown).map((c) => c.key)

  return (
    <aside id={id} aria-label={t.columns} className="w-[300px] flex-none border-l-2 border-ink bg-sheet px-6 pt-4 pb-6">
      <PanelHead title={t.columns} close={t.closeColumns} onClose={onClose} headRef={head} sheet={false} />
      <p className="mb-2.5 text-[13px] text-text-muted">{t.columnsNote}</p>
      {columns.map((c) => (
        <Check
          key={c.key}
          checked={c.shown}
          disabled={c.always}
          onChange={(on) => onColumns(on ? [...shown, c.key] : shown.filter((k) => k !== c.key))}
          className={`flex w-full text-sm ${c.shown ? 'font-extrabold' : 'font-medium'}`}
        >
          <span className="flex-1">{c.label}</span>
          {c.always && <span className="text-xs font-medium text-text-muted">{t.alwaysShown}</span>}
        </Check>
      ))}
      <button type="button" onClick={() => onColumns('reset')} className="mt-2.5 h-11 border-2 border-ink px-3 text-sm font-extrabold hover:bg-field">
        {t.usualColumns}
      </button>
    </aside>
  )
}

/** Naming a new view: a field, a sentence, and the save. */
function NameView({ labels: t, onSave, onCancel }: { labels: DataGridLabels; onSave: (name: string) => Promise<string | null>; onCancel: () => void }) {
  const [name, setName] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [working, setWorking] = useState(false)
  const id = useId()

  return (
    <form
      className="mb-3 max-w-[520px] border-2 border-ink bg-sheet p-4"
      onSubmit={(e) => {
        e.preventDefault()
        setWorking(true)
        void onSave(name.trim()).then((failed) => {
          setWorking(false)
          setError(failed)
        })
      }}
    >
      <label htmlFor={id} className="label-caps mb-1.5 block text-text-muted">
        {t.viewName}
      </label>
      <input
        id={id}
        required
        maxLength={60}
        value={name}
        aria-invalid={error !== null}
        aria-describedby={`${id}-hint`}
        onChange={(e) => setName(e.target.value)}
        className={`${fieldLook} ${error !== null ? 'border-b-[3px] border-red-pen text-red-text' : ''}`}
      />
      {error !== null && <p className="mt-1.5 text-[13px] font-bold text-red-text">{error}</p>}
      <p id={`${id}-hint`} className="mt-2 mb-3 text-[13px] text-text-muted">
        {t.viewNameHint}
      </p>
      <div className="flex flex-wrap gap-2">
        <Button type="submit" busy={working} disabled={name.trim() === ''}>
          {t.saveView}
        </Button>
        <Button variant="secondary" onClick={onCancel}>
          {t.cancel}
        </Button>
      </div>
    </form>
  )
}

