import { createApi } from '@acadly/api-client'

/** The API door every app shares (rule G2). */
export const api = createApi(import.meta.env.VITE_API_URL ?? '/api', import.meta.env.VITE_CHANNEL)
