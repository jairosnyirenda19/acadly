import { api } from './api'

export interface Account {
  id: string
  name: string
  email: string
}

/** What a sign-in attempt came to. Named outcomes, never a boolean. */
export type SignInOutcome =
  | { kind: 'signed_in'; account: Account }
  | { kind: 'incorrect' }
  | { kind: 'paused' }
  | { kind: 'unavailable' }

/** What a new-password request came to. */
export type ResetOutcome = { kind: 'changed' } | { kind: 'link_invalid' } | { kind: 'rejected'; fields: string[] } | { kind: 'unavailable' }

/** The HTTP status of a failed sign-in, as an outcome. */
export function signInFailure(status: number): SignInOutcome {
  if (status === 422) return { kind: 'incorrect' }
  if (status === 429) return { kind: 'paused' }

  return { kind: 'unavailable' }
}

/** The CSRF cookie must exist before the first request that changes state. */
async function prepare(): Promise<void> {
  await api.GET('/v1/auth/csrf-cookie')
}

/** The name of the school this deployment serves, or null when unavailable. */
export async function getSchoolName(): Promise<string | null> {
  try {
    const { data } = await api.GET('/v1/school')

    return data?.name ?? null
  } catch {
    return null
  }
}

/** The signed-in account, or null when there is no session. */
export async function getAccount(): Promise<Account | null> {
  try {
    const { data } = await api.GET('/v1/me')

    return data?.user ?? null
  } catch {
    return null
  }
}

export async function signIn(email: string, password: string): Promise<SignInOutcome> {
  try {
    await prepare()
    const { data, response } = await api.POST('/v1/auth/sign-in', { body: { email, password } })

    if (data !== undefined) {
      return { kind: 'signed_in', account: data.user }
    }

    return signInFailure(response.status)
  } catch {
    return { kind: 'unavailable' }
  }
}

export async function signOut(): Promise<void> {
  try {
    await prepare()
    await api.POST('/v1/auth/sign-out')
  } catch {
    // The session ends on the server or has already ended. The screen signs out either way.
  }
}

/** Resolves when the request is accepted. The answer never says whether the account exists. */
export async function requestPasswordReset(email: string): Promise<boolean> {
  try {
    await prepare()
    const { response } = await api.POST('/v1/auth/forgot-password', { body: { email } })

    return response.status === 202 || response.status === 200
  } catch {
    return false
  }
}

export async function resetPassword(input: {
  token: string
  email: string
  password: string
  password_confirmation: string
  /** The link is an invitation: it sets the first password of a new staff account. */
  invitation?: boolean
}): Promise<ResetOutcome> {
  try {
    await prepare()
    const { response, error } = await api.POST('/v1/auth/reset-password', { body: input })

    if (response.status === 204) return { kind: 'changed' }

    if (response.status === 422) {
      const fields = Object.keys((error as { errors?: Record<string, unknown> } | undefined)?.errors ?? {})

      return fields.includes('email') || fields.includes('token') ? { kind: 'link_invalid' } : { kind: 'rejected', fields }
    }

    return { kind: 'unavailable' }
  } catch {
    return { kind: 'unavailable' }
  }
}
