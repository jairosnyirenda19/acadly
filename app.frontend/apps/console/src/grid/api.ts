import { api, type paths } from '@acadly/kernel'
import type { ViewState } from '@acadly/ui'
import { read, settle } from '../setup/api'

type Done<T> = Promise<{ data?: T; error?: unknown; response: Response }>

type Json<P extends keyof paths, M extends 'get' | 'post' | 'put' | 'delete'> = paths[P][M] extends { responses: { 200: { content: { 'application/json': infer T } } } } ? T : never

export type ViewsAnswer = Json<'/v1/grids/{grid}/views', 'post'>
export type ExportState = Json<'/v1/grids/exports/{id}', 'get'>
export type BulkAnswer = Json<'/v1/grids/{grid}/bulk', 'post'>

/**
 * What every data grid shares on the server: a person's columns and views,
 * exports built in the background, and bulk actions. `grid` is the list's
 * key, such as `learners`. Each list's rows come from its own address.
 */
export const gridsApi = {
  columns: (grid: string, body: { shown: string[] } | { reset: true }) =>
    settle(api.PUT('/v1/grids/{grid}/columns', { params: { path: { grid } }, body: body as never }) as Done<{ shown: string[] }>),

  createView: (grid: string, name: string, state: ViewState) =>
    settle(api.POST('/v1/grids/{grid}/views', { params: { path: { grid } }, body: { name, state } as never }) as Done<ViewsAnswer>),

  saveView: (grid: string, id: string, name: string, state: ViewState) =>
    settle(api.PUT('/v1/grids/{grid}/views/{id}', { params: { path: { grid, id } }, body: { name, state } as never }) as Done<ViewsAnswer>),

  deleteView: (grid: string, id: string) => settle(api.DELETE('/v1/grids/{grid}/views/{id}', { params: { path: { grid, id } } }) as Done<{ views: ViewsAnswer['views'] }>),

  /** Asks for an export of every row that matches, or of the rows chosen. */
  export: (grid: string, state: ViewState, ids?: string[]) =>
    settle(api.POST('/v1/grids/{grid}/exports', { params: { path: { grid } }, body: { state, ...(ids !== undefined ? { ids } : {}) } as never }) as Done<{ id: string; status: ExportState['status'] }>),

  bulk: (grid: string, body: { action: string; ids?: string[]; all?: true; state: ViewState; input: Record<string, string> }) =>
    settle(api.POST('/v1/grids/{grid}/bulk', { params: { path: { grid } }, body: body as never }) as Done<BulkAnswer>),

  exportState: (id: string) => read(api.GET('/v1/grids/exports/{id}', { params: { path: { id } } })),

  /** Downloads a finished export through the session; the file never sits behind a public link. */
  download: async (id: string): Promise<'saved' | 'refused' | 'gone' | 'unavailable'> => {
    try {
      const { data, response } = await api.GET('/v1/grids/exports/{id}/file', { params: { path: { id } }, parseAs: 'blob' })

      if (response.status === 401 || response.status === 403) return 'refused'
      if (response.status === 404 || response.status === 410 || response.status === 422) return 'gone'
      if (!response.ok || !(data instanceof Blob)) return 'unavailable'

      const named = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(response.headers.get('Content-Disposition') ?? '')?.[1]
      const link = document.createElement('a')
      link.href = URL.createObjectURL(data)
      link.download = named !== undefined ? decodeURIComponent(named) : `export-${id.slice(-8)}.xlsx`
      document.body.append(link)
      link.click()
      link.remove()
      window.setTimeout(() => URL.revokeObjectURL(link.href), 1000)

      return 'saved'
    } catch {
      return 'unavailable'
    }
  },
}
