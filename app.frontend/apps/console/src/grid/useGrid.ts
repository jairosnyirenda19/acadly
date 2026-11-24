import { loading, navigate, useLocation, WAIT_AFTER } from '@acadly/kernel'
import { NO_SELECTION, readGridQuery, slips, viewState, writeGridQuery, type GridPage, type GridPhase, type GridQuery, type GridSelection, type GridViews } from '@acadly/ui'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { copy } from '../copy'
import { errorFor } from '../setup/api'
import { gridsApi } from './api'

const s = copy.settings

/**
 * The grid's state is the address (`?page=&per_page=&sort=&dir=&q=&filter[…]=`),
 * so a link opens the same view. Reads it, and writes a new one.
 */
export function useGridQuery(path: string, keep: readonly string[] = []): [GridQuery, (next: GridQuery, replace?: boolean) => void] {
  const { query } = useLocation()
  const search = query.toString()
  const state = useMemo(() => readGridQuery(new URLSearchParams(search)), [search])
  const kept = keep.join(',')
  const set = useCallback(
    (next: GridQuery, replace = false) => {
      navigate(gridAddress(path, next, keepParams(new URLSearchParams(window.location.search), kept)), replace)
    },
    [path, kept],
  )

  return [state, set]
}

function keepParams(from: URLSearchParams, kept: string): Record<string, string> {
  const out: Record<string, string> = {}
  for (const key of kept.split(',').filter((k) => k !== '')) {
    const value = from.get(key)
    if (value !== null) out[key] = value
  }

  return out
}

/** The address of a list with its grid state and, after it, any params of the page itself (such as `message`). */
export function gridAddress(path: string, query: GridQuery, extra: Record<string, string> = {}): string {
  const parts = [writeGridQuery(query), new URLSearchParams(extra).toString()].filter((x) => x !== '')

  return `${path}${parts.length > 0 ? `?${parts.join('&')}` : ''}`
}

/**
 * One page of a list, loaded for the address. The last page stays on screen
 * while the next loads; after a short wait only the rows show as waiting.
 */
export function useGridPage<Row>(key: string, query: GridQuery, fetch: (query: GridQuery) => Promise<GridPage<Row> | 'refused' | 'missing' | null>) {
  const address = writeGridQuery(query)
  const [page, setPage] = useState<GridPage<Row> | undefined>(undefined)
  const [phase, setPhase] = useState<GridPhase>('ready')
  const [round, setRound] = useState(0)
  const latest = useRef({ fetch, query })
  latest.current = { fetch, query }

  useEffect(() => {
    let live = true
    const wait = window.setTimeout(() => live && setPhase('loading'), WAIT_AFTER)
    loading.start()

    void latest.current
      .fetch(latest.current.query)
      .then((answer) => {
        if (!live) return
        if (answer === 'refused') setPhase('refused')
        else if (answer === null || answer === 'missing') setPhase('failed')
        else {
          setPage(answer)
          setPhase('ready')
        }
      })
      .catch(() => live && setPhase('failed'))
      .finally(() => {
        window.clearTimeout(wait)
        loading.stop()
      })

    return () => {
      live = false
      window.clearTimeout(wait)
    }
  }, [key, address, round])

  const reload = useCallback(() => setRound((r) => r + 1), [])

  return { page, phase, reload }
}

/** The rows chosen. A new address (another page, sort or filter) starts with nothing chosen. */
export function useGridSelection(query: GridQuery): [GridSelection, (next: GridSelection) => void] {
  const address = writeGridQuery(query)
  const [chosen, setChosen] = useState<{ address: string; selection: GridSelection }>({ address, selection: NO_SELECTION })
  const set = useCallback((selection: GridSelection) => setChosen({ address, selection }), [address])

  return [chosen.address === address ? chosen.selection : NO_SELECTION, set]
}

/**
 * Asks for an export of every row that matches, or of the rows chosen. It is
 * built in the background; the person is told in Notices. Nothing is built in
 * the browser.
 */
export function startExport<Row>(
  grid: string,
  scope: 'match' | 'selected',
  { page, query, selection, word, plural }: { page: GridPage<Row>; query: GridQuery; selection: GridSelection; word: string; plural: string },
) {
  const ids = scope === 'selected' && !selection.all ? selection.ids : undefined
  const rows = ids?.length ?? page.total
  const columns = page.columns.filter((c) => c.shown).length
  const g = copy.grid

  void gridsApi.export(grid, viewState(query, { sort: page.sort, dir: page.dir }), ids).then((r) => {
    if (r.kind === 'saved') {
      slips.attention({ word: g.exportWord, title: g.exportTitle, detail: g.exportDetail(rows, columns, word, plural), action: { label: g.exportOpen, run: () => navigate(`/exports/${r.data.id}`) } })
    } else {
      slips.wrong({ word: g.notStarted, title: r.kind === 'refused' ? s.refused : s.failed })
    }
  })
}

const notSaved = (kind: 'refused' | 'unavailable') => (kind === 'refused' ? s.refused : s.failed)

/** A person's columns and saved views of a list, kept on the server. Each change loads the list again. */
export function useGridPrefs(grid: string, reload: () => void): { onColumns: (shown: string[] | 'reset') => void; views: GridViews } {
  return useMemo(
    () => ({
      onColumns: (shown: string[] | 'reset') => {
        void gridsApi.columns(grid, shown === 'reset' ? { reset: true } : { shown }).then((r) => {
          if (r.kind === 'saved') reload()
          else slips.wrong({ word: copy.grid.notSaved, title: r.kind === 'invalid' ? s.failed : notSaved(r.kind) })
        })
      },
      views: {
        create: async (name, state) => {
          const r = await gridsApi.createView(grid, name, state)
          if (r.kind === 'saved') {
            reload()
            slips.done({ word: copy.slips.saved, title: copy.grid.viewSaved(name) })

            return { id: r.data.id }
          }

          return { error: r.kind === 'invalid' ? (errorFor(r.errors, 'name') ?? s.failed) : notSaved(r.kind) }
        },
        save: async (id, name, state) => {
          const r = await gridsApi.saveView(grid, id, name, state)
          if (r.kind === 'saved') {
            reload()
            slips.done({ word: copy.slips.saved, title: copy.grid.viewSaved(name) })

            return null
          }
          const error = r.kind === 'invalid' ? (errorFor(r.errors, 'name') ?? s.failed) : notSaved(r.kind)
          slips.wrong({ word: copy.grid.notSaved, title: error })

          return error
        },
        remove: async (id) => {
          const r = await gridsApi.deleteView(grid, id)
          if (r.kind === 'saved') {
            reload()
            slips.done({ word: copy.grid.deleted, title: copy.grid.viewDeleted })

            return null
          }
          const error = r.kind === 'invalid' ? s.failed : notSaved(r.kind)
          slips.wrong({ word: copy.grid.notSaved, title: error })

          return error
        },
      },
    }),
    [grid, reload],
  )
}
