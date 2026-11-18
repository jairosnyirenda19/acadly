import { Button, Notice } from '@acadly/ui'
import { useEffect, useRef, useState } from 'react'
import { copy } from '../../copy'
import { Input, Select } from '../../setup/parts'
import type { LearnerState } from '../api'
import { Column, Columns } from '../record/ui'
import { AccessTag } from './DocumentsTab'
import { documentsApi, type DocumentsState } from './api'
import { firstPagePicture } from './preview'

const t = copy.learners.documents
const MAX_PAGES = 20
const PHOTOS = ['image/jpeg', 'image/png']

/**
 * Adding a document (the approved design): photos of a paper document, which
 * become the pages of one PDF, or one file; then its type, title and date.
 */
export function AddDocument({
  learner,
  state,
  typeId,
  onDone,
  onCancel,
}: {
  learner: LearnerState
  state: DocumentsState
  typeId: string
  onDone: (id: string) => void
  onCancel: () => void
}) {
  const types = state.types.filter((x) => !x.retired && state.can_add.includes(x.access))
  const [type, setType] = useState(types.some((x) => x.id === typeId) ? typeId : '')
  const [title, setTitle] = useState('')
  const [date, setDate] = useState('')
  const [files, setFiles] = useState<File[]>([])
  const [problem, setProblem] = useState<string | null>(null)
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [busy, setBusy] = useState(false)
  const camera = useRef<HTMLInputElement>(null)
  const chooser = useRef<HTMLInputElement>(null)
  const [urls, setUrls] = useState<string[]>([])

  // Small pictures of the photos taken, freed when they change.
  useEffect(() => {
    const made = files.map((f) => (PHOTOS.includes(f.type) ? URL.createObjectURL(f) : ''))
    setUrls(made)

    return () => made.forEach((u) => u !== '' && URL.revokeObjectURL(u))
  }, [files])

  const chosen = types.find((x) => x.id === type)
  const photos = files.length > 0 && files.every((f) => PHOTOS.includes(f.type))

  const take = (list: FileList | null, append: boolean) => {
    const added = Array.from(list ?? [])
    if (added.length === 0) return
    const next = append ? [...files, ...added] : added
    setProblem(next.length > 1 && !next.every((f) => PHOTOS.includes(f.type)) ? t.mixed : next.length > MAX_PAGES ? t.tooMany(MAX_PAGES) : null)
    setFiles(next.slice(0, MAX_PAGES))
    if (camera.current !== null) camera.current.value = ''
    if (chooser.current !== null) chooser.current.value = ''
  }

  const save = async () => {
    if (files.length === 0) {
      setProblem(t.noFile)
      return
    }
    setBusy(true)
    setErrors({})
    const first = files[0]
    const preview = files.length === 1 && first !== undefined ? await firstPagePicture(first) : null
    const result = await documentsApi.add(learner.id, { typeId: type, title: title.trim(), date, files, preview })
    setBusy(false)

    if (result.kind === 'saved') onDone(result.data.id)
    else if (result.kind === 'invalid') {
      setErrors(result.errors)
      setProblem(result.errors.files?.[0] ?? null)
    } else setProblem(t.failed)
  }

  const error = (field: string) => errors[field]?.[0]

  return (
    <Columns top>
      <Column grow="flex-[1_1_100%]" label={t.addTitle}>
        <div className="mx-auto grid max-w-[560px] gap-4">
          <div>
            <div className="label-caps text-text-muted">
              {learner.name} · {t.addKicker}
            </div>
            <h2 className="type-display mt-1 text-[40px] leading-[0.86]">{t.addTitle}</h2>
          </div>

          {problem !== null && (
            <Notice tone="wrong" title={t.notSaved}>
              {problem}
            </Notice>
          )}

          <div className="grid grid-cols-2 gap-2.5">
            <button type="button" onClick={() => camera.current?.click()} className="grid min-h-[92px] justify-items-center gap-1.5 border-2 border-ink bg-sheet p-3 text-[15px] font-extrabold text-ink hover:bg-field">
              <svg aria-hidden="true" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="square">
                <path d="M3 7h4l2-3h6l2 3h4v13H3z" />
                <circle cx="12" cy="13" r="4" />
              </svg>
              {t.takePhotos}
            </button>
            <button type="button" onClick={() => chooser.current?.click()} className="grid min-h-[92px] justify-items-center gap-1.5 border-2 border-ink bg-sheet p-3 text-[15px] font-extrabold text-ink hover:bg-field">
              <svg aria-hidden="true" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="square">
                <path d="M5 3h10l4 4v14H5z" />
                <path d="M12 10v7" />
                <path d="M9 13l3-3 3 3" />
              </svg>
              {t.chooseFile}
            </button>
            <input ref={camera} type="file" accept="image/*" capture="environment" className="hidden" onChange={(e) => take(e.target.files, true)} />
            <input ref={chooser} type="file" accept="application/pdf,image/jpeg,image/png" multiple className="hidden" onChange={(e) => take(e.target.files, false)} />
          </div>

          {files.length > 0 && (
            <div className="border border-rule bg-sheet px-3 py-2.5">
              <div className="flex flex-wrap items-center gap-2">
                <span className="text-sm">
                  <b>{photos ? t.pages(files.length) : files[0]?.name}</b>
                  <br />
                  <span className="text-text-muted">{files.length > 1 ? t.onePdf : t.oneFile}</span>
                </span>
                {photos && files.length < MAX_PAGES && (
                  <button type="button" onClick={() => camera.current?.click()} className="ml-auto min-h-11 text-sm font-extrabold underline underline-offset-[3px]">
                    {t.addPage}
                  </button>
                )}
              </div>
              {photos && (
                <ul className="mt-2 flex flex-wrap gap-2">
                  {files.map((f, i) => (
                    <li key={`${f.name}-${i}`} className="grid justify-items-center gap-0.5">
                      {urls[i] !== undefined && urls[i] !== '' && <img src={urls[i]} alt={t.pageAlt(i + 1)} className="h-[64px] w-[48px] border border-rule object-cover" />}
                      <button type="button" aria-label={t.removePage(i + 1)} onClick={() => setFiles(files.filter((_, j) => j !== i))} className="min-h-9 px-1 text-[12px] font-bold underline">
                        <svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="square">
                          <path d="M6 6l12 12" />
                          <path d="M18 6L6 18" />
                        </svg>
                      </button>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          )}

          <div>
            <Select label={t.typeField} value={type} onChange={(e) => setType(e.target.value)} error={error('type_id')} required>
              <option value="">{t.chooseType}</option>
              {types.map((x) => (
                <option key={x.id} value={x.id}>
                  {x.name}
                </option>
              ))}
            </Select>
            {chosen !== undefined && (
              <p className="mt-2 flex flex-wrap items-center gap-2 text-[13px] text-text-muted">
                <AccessTag access={chosen.access} /> {t.accessNote[chosen.access]}
              </p>
            )}
          </div>
          <Input label={t.titleField} value={title} onChange={(e) => setTitle(e.target.value)} hint={t.titleHint} error={error('title')} maxLength={255} />
          <Input label={t.dateField} type="date" value={date} onChange={(e) => setDate(e.target.value)} hint={t.dateHint} error={error('date')} />

          <div className="flex flex-wrap items-center gap-2.5 border-t-2 border-ink pt-3.5">
            <Button busy={busy} disabled={type === '' || files.length === 0 || (files.length > 1 && !photos)} onClick={() => void save()}>
              {t.save}
            </Button>
            <Button variant="secondary" onClick={onCancel}>
              {t.cancel}
            </Button>
            <p className="basis-full text-[13px] text-text-muted">{t.checked}</p>
          </div>
        </div>
      </Column>
    </Columns>
  )
}
