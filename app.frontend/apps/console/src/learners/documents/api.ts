import { api, type paths } from '@acadly/kernel'
import { read, settle } from '../../setup/api'

export type DocumentsState = paths['/v1/learners/{id}/documents']['get']['responses'][200]['content']['application/json']
export type LearnerDocument = DocumentsState['documents'][number]
export type DocumentType = DocumentsState['types'][number]
export type DocumentAccess = 'staff' | 'office' | 'confidential'
export type TypesState = paths['/v1/learner-document-types']['get']['responses'][200]['content']['application/json']
export type DocumentDetail = paths['/v1/documents/{id}']['get']['responses'][200]['content']['application/json']

type Done<T> = Promise<{ data?: T; error?: unknown; response: Response }>

const API: string = import.meta.env.VITE_API_URL ?? '/api'

/** Where a document's picture and file are; the server checks who may open them. */
export const thumbnailUrl = (id: string) => `${API}/v1/documents/${id}/thumbnail`
export const contentUrl = (id: string, inline: boolean) => `${API}/v1/documents/${id}/content${inline ? '?disposition=inline' : ''}`

const form = (fields: Record<string, string | Blob | Blob[] | null>) => {
  const body = new FormData()
  for (const [key, value] of Object.entries(fields)) {
    if (value === null) continue
    if (Array.isArray(value)) value.forEach((v) => body.append(`${key}[]`, v))
    else body.append(key, value)
  }

  return body
}
const asForm = (body: unknown) => body as FormData

export const documentsApi = {
  list: (learnerId: string) => read(api.GET('/v1/learners/{id}/documents', { params: { path: { id: learnerId } } })),
  detail: (id: string) => read(api.GET('/v1/documents/{id}', { params: { path: { id } } })),

  add: (learnerId: string, input: { typeId: string; title: string; date: string; files: File[]; preview: Blob | null }) =>
    settle(
      api.POST('/v1/learners/{id}/documents', {
        params: { path: { id: learnerId } },
        body: form({ type_id: input.typeId, title: input.title, date: input.date !== '' ? input.date : null, files: input.files, preview: input.preview }) as never,
        bodySerializer: asForm,
      }) as Done<{ id: string }>,
    ),

  newVersion: (id: string, file: File, preview: Blob | null) =>
    settle(api.POST('/v1/documents/{id}/versions', { params: { path: { id } }, body: form({ file, preview }) as never, bodySerializer: asForm }) as Done<{ id: string; version: number }>),

  trash: (id: string, reason: string) => settle(api.DELETE('/v1/documents/{id}', { params: { path: { id }, query: { reason } } }) as Done<{ batch: string }>),

  types: () => read(api.GET('/v1/learner-document-types')),
  saveType: (id: string | null, body: { name: string; access: DocumentAccess; expected: boolean }) =>
    id === null
      ? settle(api.POST('/v1/learner-document-types', { body: body as never }) as Done<{ id: string }>)
      : settle(api.PUT('/v1/learner-document-types/{id}', { params: { path: { id } }, body: body as never }) as Done<{ id: string }>),
  retireType: (id: string) => settle(api.POST('/v1/learner-document-types/{id}/retire', { params: { path: { id } } }) as Done<{ id: string }>),
}

/** A file size people can read: 412 KB, 2.3 MB. */
export function fileSize(bytes: number): string {
  if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`

  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}
