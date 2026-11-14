import { createApi } from '@acadly/api-client'

/**
 * The web kernel: what every app shares that is not a visual component.
 * Stage 0 holds the API door. Sign-in, scope, permissions, vocabulary and the
 * journey components arrive with the stages that need them
 * (docs/architecture/06-frontend.md §4).
 */
export const api = createApi(import.meta.env.VITE_API_URL ?? '/api')

export type Health = NonNullable<Awaited<ReturnType<typeof getHealth>>>

export async function getHealth() {
  const { data, error } = await api.GET('/v1/health')

  if (error !== undefined || data === undefined) {
    throw new Error('The API did not answer.')
  }

  return data
}
