import createClient from 'openapi-fetch'
import type { components, paths } from './schema'

/**
 * The ONLY door from the web apps to the API (rule G2).
 *
 * `schema.d.ts` is generated from /contracts/openapi.json by `pnpm client`.
 * Never edit it and never call `fetch` on the API from an app.
 */
export function createApi(baseUrl: string) {
  return createClient<paths>({ baseUrl, credentials: 'include' })
}

export type Api = ReturnType<typeof createApi>
export type { components, paths }
