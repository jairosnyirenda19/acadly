import { slips } from '@acadly/ui'
import { useRef, useState } from 'react'
import { copy } from '../copy'
import { assessmentApi, type SheetState } from './api'

const t = copy.assessment.sheet

export type Page = { id: string; document_id: string; position: number; type: string }

/** Where a page's file is shown in place. The server checks who may open it. */
export const pageUrl = (documentId: string) => `/api/v1/documents/${documentId}/content?disposition=inline`

const CAMERA = (
  <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="square">
    <path d="M3 7h4l2-3h6l2 3h4v13H3z" />
    <circle cx="12" cy="13" r="4" />
  </svg>
)
const PAPER = (
  <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="square">
    <path d="M6 3h9l4 4v14H6z" />
    <path d="M14 3v5h5" />
    <path d="M9 13h7" />
    <path d="M9 17h5" />
  </svg>
)

/** The camera key of a row: teal with a paper once one is kept. */
export function PaperKey({ name, pages, open, disabled, onClick }: { name: string; pages: number; open: boolean; disabled: boolean; onClick: () => void }) {
  return (
    <button
      type="button"
      aria-label={t.paperFor(name, pages)}
      aria-expanded={open}
      disabled={disabled}
      onClick={onClick}
      className={`grid size-11 shrink-0 place-items-center border-2 border-ink disabled:opacity-35 ${pages > 0 ? 'bg-teal text-sheet' : open ? 'bg-ink text-sheet' : 'bg-sheet text-ink hover:bg-field'}`}
    >
      {pages > 0 ? PAPER : CAMERA}
    </button>
  )
}

/** A learner's paper on the marks sheet: its pages, and adding one by camera or file (the approved design). */
export function PaperPanel({
  assessmentId,
  learnerId,
  pages,
  canAdd,
  onSheet,
  onNext,
  onClose,
}: {
  assessmentId: string
  learnerId: string
  pages: Page[]
  canAdd: boolean
  onSheet: (next: SheetState) => void
  onNext: (() => void) | null
  onClose: () => void
}) {
  const camera = useRef<HTMLInputElement>(null)
  const chooser = useRef<HTMLInputElement>(null)
  const [busy, setBusy] = useState(false)

  const add = async (files: FileList | null) => {
    if (files === null || files.length === 0) return
    setBusy(true)
    for (const file of Array.from(files)) {
      const r = await assessmentApi.addPaper(assessmentId, learnerId, file)
      if (r.kind === 'saved') onSheet(r.data)
      else {
        slips.wrong({ word: copy.assessment.notSaved, title: t.paperFailed })
        break
      }
    }
    setBusy(false)
    if (camera.current !== null) camera.current.value = ''
    if (chooser.current !== null) chooser.current.value = ''
  }

  const change = async (run: () => ReturnType<typeof assessmentApi.removePaper>, done?: string) => {
    setBusy(true)
    const r = await run()
    setBusy(false)
    if (r.kind === 'saved') {
      onSheet(r.data)
      if (done !== undefined) slips.done({ word: copy.slips.saved, title: done })
    } else slips.wrong({ word: copy.assessment.notSaved, title: copy.settings.failed })
  }

  return (
    <div className="relative z-10 border-t border-dashed border-rule bg-paper px-3 pt-2.5 pb-3 md:pl-[56px]">
      <div className="mb-2 flex items-baseline gap-2">
        <span className="label-caps flex-1 text-text-muted">{pages.length > 0 ? t.paperTitle(pages.length) : t.paperNone}</span>
        <button type="button" onClick={onClose} className="min-h-11 text-sm font-extrabold underline underline-offset-[3px]">
          {t.closePaper}
        </button>
      </div>
      {pages.length > 0 && (
        <ol className="flex gap-2.5 overflow-x-auto pb-1">
          {pages.map((p) => (
            <li key={p.id} className="w-[104px] shrink-0">
              <a href={pageUrl(p.document_id)} target="_blank" rel="noreferrer" aria-label={t.openPage(p.position)} className="relative block h-[140px] border-2 border-ink bg-sheet">
                {p.type.startsWith('image/') ? (
                  <img src={pageUrl(p.document_id)} alt="" className="size-full object-cover" />
                ) : (
                  <span className="type-display grid size-full place-items-center text-2xl text-text-muted">{t.pdfPage}</span>
                )}
                <span className="absolute bottom-1.5 left-1.5 bg-ink px-1.5 text-xs font-extrabold text-sheet">{p.position}</span>
              </a>
              {canAdd && (
                <span className="mt-1 flex justify-between text-xs font-extrabold">
                  {p.position > 1 ? (
                    <button type="button" aria-label={t.movePageEarlier(p.position)} disabled={busy} onClick={() => void change(() => assessmentApi.movePaper(p.id, true))} className="min-h-8 underline">
                      {t.earlier}
                    </button>
                  ) : (
                    <span />
                  )}
                  <button type="button" aria-label={t.removePage(p.position)} disabled={busy} onClick={() => void change(() => assessmentApi.removePaper(p.id), t.paperRemoved)} className="min-h-8 text-red-text underline">
                    {t.remove}
                  </button>
                </span>
              )}
            </li>
          ))}
        </ol>
      )}
      {canAdd && (
        <>
          <p className="mt-1.5 text-[13px]">{t.paperHow}</p>
          <div className="mt-2 flex flex-wrap gap-2">
            <input ref={camera} type="file" accept="image/*" capture="environment" className="sr-only" tabIndex={-1} onChange={(e) => void add(e.target.files)} />
            <input ref={chooser} type="file" accept="image/jpeg,image/png,application/pdf" multiple className="sr-only" tabIndex={-1} onChange={(e) => void add(e.target.files)} />
            <button type="button" disabled={busy} aria-busy={busy || undefined} onClick={() => camera.current?.click()} className="inline-flex h-11 items-center gap-2 border-2 border-ink bg-sheet px-3 text-sm font-extrabold hover:bg-field disabled:opacity-60">
              {CAMERA}
              {t.takePhoto}
            </button>
            <button type="button" disabled={busy} onClick={() => chooser.current?.click()} className="h-11 border-2 border-ink bg-sheet px-3 text-sm font-extrabold hover:bg-field disabled:opacity-60">
              {t.chooseFile}
            </button>
            {onNext !== null && (
              <button type="button" onClick={onNext} className="ml-auto h-11 px-2 text-sm font-extrabold underline underline-offset-[3px]">
                {t.nextLearner} ›
              </button>
            )}
          </div>
          <p className="mt-2 text-xs text-text-muted">{t.paperPrivacy}</p>
        </>
      )}
    </div>
  )
}
