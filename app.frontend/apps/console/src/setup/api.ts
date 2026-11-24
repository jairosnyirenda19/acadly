import { api, type paths } from '@acadly/kernel'

export type InstitutionState = paths['/v1/institution']['get']['responses'][200]['content']['application/json']
export type AcademicsState = paths['/v1/academics']['get']['responses'][200]['content']['application/json']
export type SetupState = paths['/v1/setup']['get']['responses'][200]['content']['application/json']

/** What a save came to. Named outcomes, never a boolean. */
export type Saved<T> =
  | { kind: 'saved'; data: T }
  | { kind: 'invalid'; errors: Record<string, string[]> }
  | { kind: 'refused' }
  | { kind: 'unavailable' }

export async function settle<T>(request: Promise<{ data?: T; error?: unknown; response: Response }>): Promise<Saved<T>> {
  try {
    const { data, error, response } = await request

    if (data !== undefined && response.ok) return { kind: 'saved', data }
    if (response.status === 422) {
      return { kind: 'invalid', errors: (error as { errors?: Record<string, string[]> } | undefined)?.errors ?? {} }
    }
    if (response.status === 403 || response.status === 401) return { kind: 'refused' }

    return { kind: 'unavailable' }
  } catch {
    return { kind: 'unavailable' }
  }
}

export async function read<T>(request: Promise<{ data?: T; response: Response }>): Promise<T | 'refused' | 'missing' | null> {
  try {
    const { data, response } = await request

    if (data !== undefined) return data
    if (response.status === 403 || response.status === 401) return 'refused'
    // Not there, or outside the place the user works in (D-099): not a fault of the service.
    if (response.status === 404) return 'missing'

    return null
  } catch {
    return null
  }
}

export const setupApi = {
  institution: () => read(api.GET('/v1/institution')),
  academics: () => read(api.GET('/v1/academics')),
  setup: () => read(api.GET('/v1/setup')),

  saveSchool: (body: Record<string, string | null>) =>
    settle(api.PUT('/v1/institution', { body: body as never }) as Promise<{ data?: InstitutionState; error?: unknown; response: Response }>),

  uploadLogo: (file: File) => {
    const form = new FormData()
    form.append('file', file)

    return settle(
      api.POST('/v1/institution/logo', { body: form as never, bodySerializer: (body: unknown) => body as FormData }) as Promise<{
        data?: InstitutionState
        error?: unknown
        response: Response
      }>,
    )
  },

  saveLevels: (levels: { profile: string; name?: string | null }[]) =>
    settle(api.PUT('/v1/institution/levels', { body: { levels } as never }) as Promise<{ data?: InstitutionState; error?: unknown; response: Response }>),

  addCampus: (body: { name: string; place: string; address?: string | null }) =>
    settle(api.POST('/v1/institution/campuses', { body: body as never }) as Promise<{ data?: InstitutionState; error?: unknown; response: Response }>),

  updateCampus: (id: string, body: { name: string; place: string; address?: string | null }) =>
    settle(
      api.PUT('/v1/institution/campuses/{id}', { params: { path: { id } }, body: body as never }) as Promise<{
        data?: InstitutionState
        error?: unknown
        response: Response
      }>,
    ),

  setOffering: (divisionId: string, campusId: string, taught: boolean) =>
    settle(
      api.PUT('/v1/institution/offerings', { body: { division_id: divisionId, campus_id: campusId, taught } as never }) as Promise<{
        data?: InstitutionState
        error?: unknown
        response: Response
      }>,
    ),

  saveYear: (body: { name: string; starts_on: string; ends_on: string }) =>
    settle(api.PUT('/v1/academics/year', { body: body as never }) as Promise<{ data?: AcademicsState; error?: unknown; response: Response }>),

  saveTerms: (divisionId: string, terms: { name: string; starts_on: string; ends_on: string }[]) =>
    settle(
      api.PUT('/v1/academics/terms', { body: { division_id: divisionId, terms } as never }) as Promise<{
        data?: AcademicsState
        error?: unknown
        response: Response
      }>,
    ),

  saveClasses: (divisionId: string, campusId: string, streams: Record<string, string[]>) =>
    settle(
      api.PUT('/v1/academics/classes', { body: { division_id: divisionId, campus_id: campusId, streams } as never }) as Promise<{
        data?: AcademicsState
        error?: unknown
        response: Response
      }>,
    ),

  inviteStaff: (body: { name: string; email: string; role: string; place_type: string; place_id: string | null }) =>
    settle(api.POST('/v1/setup/staff', { body: body as never }) as Promise<{ data?: SetupState; error?: unknown; response: Response }>),

  sendInvitations: () => settle(api.POST('/v1/setup/staff/send') as Promise<{ data?: SetupState; error?: unknown; response: Response }>),

  open: () => settle(api.POST('/v1/setup/open') as Promise<{ data?: SetupState; error?: unknown; response: Response }>),
}

/** The first message for a field, or undefined. */
export function errorFor(errors: Record<string, string[]>, field: string): string | undefined {
  return errors[field]?.[0]
}
