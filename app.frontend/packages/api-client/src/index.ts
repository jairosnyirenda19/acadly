import createClient from 'openapi-fetch'
import type { components, paths } from './schema'

const SAFE_METHODS = new Set(['GET', 'HEAD', 'OPTIONS'])

/** The value of one cookie, or null. */
export function readCookie(source: string, name: string): string | null {
  for (const part of source.split(';')) {
    const [key, ...value] = part.trim().split('=')

    if (key === name) {
      return decodeURIComponent(value.join('='))
    }
  }

  return null
}

/**
 * The ONLY door from the web apps to the API (rule G2).
 *
 * `schema.d.ts` is generated from /contracts/openapi.json by `pnpm client`.
 * Never edit it and never call `fetch` on the API from an app.
 *
 * The session is a cookie. A request that changes state carries the
 * XSRF-TOKEN cookie back as the X-XSRF-TOKEN header.
 */
export function createApi(baseUrl: string, channel?: string) {
  const headers: Record<string, string> = { Accept: 'application/json' }

  // Which app made the request, for the audit trail.
  if (channel !== undefined) {
    headers['X-Acadly-Channel'] = channel
  }

  const client = createClient<paths>({ baseUrl, credentials: 'include', headers })

  client.use({
    onRequest({ request }) {
      if (SAFE_METHODS.has(request.method) || typeof document === 'undefined') {
        return request
      }

      const token = readCookie(document.cookie, 'XSRF-TOKEN')

      if (token !== null) {
        request.headers.set('X-XSRF-TOKEN', token)
      }

      return request
    },
  })

  return client
}

export type Api = ReturnType<typeof createApi>
export type { components, paths }
