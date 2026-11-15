import { useEffect, useId, useRef, useState } from 'react'
import { copy } from '../copy'
import { SmallButton } from '../settings/parts'

const t = copy.learners
const MAX = 2 * 1024 * 1024

/** The optional passport photo: a frame, a choice, and a preview of the file before it is sent. */
export function PhotoPicker({ word, file, onFile, current }: { word: string; file: File | null; onFile: (file: File | null) => void; current?: string | null }) {
  const input = useRef<HTMLInputElement>(null)
  const id = useId()
  const [preview, setPreview] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (file === null) {
      setPreview(null)

      return
    }

    const url = URL.createObjectURL(file)
    setPreview(url)

    return () => URL.revokeObjectURL(url)
  }, [file])

  const shown = preview ?? current ?? null

  return (
    <div className="flex items-start gap-3.5">
      <div className="flex h-[108px] w-[84px] shrink-0 items-center justify-center overflow-hidden border-2 border-dashed border-rule-dashed bg-paper text-rule-dashed">
        {shown !== null ? (
          <img src={shown} alt="" className="size-full object-cover" />
        ) : (
          <svg aria-hidden="true" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="square">
            <path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z M4 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1" />
          </svg>
        )}
      </div>
      <div>
        <label htmlFor={id} className="label-caps text-text-muted">
          {t.photo}
          <span className="font-semibold tracking-normal normal-case"> · {t.optional}</span>
        </label>
        <p className="mt-1 mb-2.5 text-[13px] leading-snug text-text-muted">{t.photoHint(word)}</p>
        <input
          ref={input}
          id={id}
          type="file"
          accept="image/jpeg,image/png"
          className="sr-only"
          onChange={(e) => {
            const chosen = e.target.files?.[0] ?? null
            e.target.value = ''
            if (chosen === null) return
            if (!['image/jpeg', 'image/png'].includes(chosen.type)) setError(t.photoType)
            else if (chosen.size > MAX) setError(t.photoTooLarge)
            else {
              setError(null)
              onFile(chosen)
            }
          }}
        />
        <div className="flex flex-wrap gap-2">
          <SmallButton onClick={() => input.current?.click()}>{shown !== null ? t.changePhoto : t.choosePhoto}</SmallButton>
          {file !== null && <SmallButton onClick={() => onFile(null)}>{t.clearPhoto}</SmallButton>}
        </div>
        {error !== null && <div className="mt-1.5 text-[13px] font-bold text-red-text">{error}</div>}
      </div>
    </div>
  )
}
