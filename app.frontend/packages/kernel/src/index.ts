import { api } from './api'

/**
 * The web kernel: what every app shares that is not a visual component
 * (docs/architecture/06-frontend.md §4).
 */
export { api }
export type { paths } from '@acadly/api-client'
export { setPlace } from '@acadly/api-client'
export * from './session'

/** Resolves when the service is available. Rejects when it is not. */
export async function getHealth(): Promise<void> {
  const { data, error } = await api.GET('/v1/health')

  if (error !== undefined || data?.status !== 'ok') {
    throw new Error('The service is unavailable.')
  }
}
export { navigate, useLocation } from './router'
export * from './context'
export * from './load'
