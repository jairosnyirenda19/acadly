import { useEffect, useState } from 'react'
import { copy } from '../copy'
import { classroomApi, fileUrl, type Pages } from './api'

const t = copy.classroom.pages

/**
 * A document shown by its pages, the way eOffice shows documents (D-103):
 * the server draws each page as a picture; the browser shows the pictures,
 * never the file. While the pages are being drawn, it asks again; a file
 * that cannot be drawn here is offered for download.
 */
export function DocumentPages({ id, name }: { id: string; name: string }) {
  const [pages, setPages] = useState<Pages | null>(null)
  const [round, setRound] = useState(0)

  useEffect(() => {
    let live = true
    void classroomApi.pages(id).then((answer) => {
      if (live && answer !== null && typeof answer === 'object' && 'status' in answer) setPages(answer)
    })

    return () => {
      live = false
    }
  }, [id, round])

  // Drawn after the upload: ask again every few seconds, for a minute.
  useEffect(() => {
    if (pages?.status !== 'pending' || round > 20) return
    const again = window.setTimeout(() => setRound(round + 1), 3000)

    return () => window.clearTimeout(again)
  }, [pages, round])

  const download = (
    <a href={fileUrl(id)} className="inline-flex h-11 items-center border-2 border-ink px-3 text-sm font-extrabold text-ink no-underline">
      {`${t.download} · ${name}`}
    </a>
  )

  if (pages === null || pages.status === 'pending') return <p className="px-4 py-6 text-text-muted">{t.preparing}</p>
  if (pages.status !== 'ready') {
    return (
      <div className="grid justify-items-start gap-3 px-4 py-6">
        <p>{pages.status === 'unsupported' ? t.cannot : t.failed}</p>
        {download}
      </div>
    )
  }

  return (
    <div className="grid gap-4 bg-field px-4 py-4">
      {pages.pages.map((p) => (
        <figure key={p.page} className="m-0 grid justify-items-center gap-1">
          <img
            src={`/api/v1/documents/${id}/pages/${p.page}`}
            width={p.width}
            height={p.height}
            loading="lazy"
            alt={t.page(p.page, pages.pages.length)}
            className="h-auto w-full max-w-[900px] border border-rule bg-sheet"
          />
          <figcaption className="text-[12px] text-text-muted">{t.page(p.page, pages.pages.length)}</figcaption>
        </figure>
      ))}
      <div>{download}</div>
    </div>
  )
}
