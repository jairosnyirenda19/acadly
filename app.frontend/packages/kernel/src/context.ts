import type { paths } from '@acadly/api-client'
import { createContext, createElement, useContext, type ReactNode } from 'react'
import { api } from './api'

/** What the screens are built from: GET /v1/me/context (03 §5.8). */
export type AppContext = paths['/v1/me/context']['get']['responses'][200]['content']['application/json']

export type ScopeType = AppContext['grants'][number]['scope_type']

export interface ScopeTarget {
  type: ScopeType
  id?: string | null
}

/** Areas of the kernel itself. Always on. */
const KERNEL_AREAS = new Set(['identity', 'access', 'audit', 'modules', 'journeys', 'notify'])

/** The context for the signed-in account, or null when there is none or it cannot be read. */
export async function getContext(): Promise<AppContext | null> {
  try {
    const { data } = await api.GET('/v1/me/context')

    return data ?? null
  } catch {
    return null
  }
}

/** Whether a module is on for the school. Kernel areas are always on. */
export function moduleOn(context: AppContext, key: string): boolean {
  return KERNEL_AREAS.has(key) || context.modules.includes(key)
}

/**
 * Whether the account holds a permission at a scope: a grant of that
 * permission at the scope itself or at the institution, and the module that
 * declares it is on. For comfort only. The server checks every request.
 */
export function can(context: AppContext, permission: string, at: ScopeTarget = { type: 'institution' }): boolean {
  const owner = permission.split('.')[0] ?? ''

  if (!moduleOn(context, owner)) {
    return false
  }

  return context.grants.some(
    (grant) =>
      grant.permission === permission &&
      (grant.scope_type === 'institution' || (grant.scope_type === at.type && grant.scope_id === (at.id ?? null))),
  )
}

const Shared = createContext<AppContext | null>(null)

/** Makes the context available to every screen below it. */
export function ContextProvider({ value, children }: { value: AppContext; children: ReactNode }) {
  return createElement(Shared.Provider, { value }, children)
}

function useAppContext(): AppContext {
  const value = useContext(Shared)

  if (value === null) {
    throw new Error('The screen is outside ContextProvider.')
  }

  return value
}

/** `const allowed = useCan(); allowed('attendance.capture', { type: 'class', id })` */
export function useCan(): (permission: string, at?: ScopeTarget) => boolean {
  const context = useAppContext()

  return (permission, at) => can(context, permission, at)
}

/** `useModule('library')` — whether the module is on for the school. */
export function useModule(key: string): boolean {
  return moduleOn(useAppContext(), key)
}

/** The school, the licence and the account, as the context gives them. */
export function useSchool(): Pick<AppContext, 'school' | 'licence' | 'user'> {
  const { school, licence, user } = useAppContext()

  return { school, licence, user }
}
