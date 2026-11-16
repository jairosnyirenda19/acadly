import { useLoad } from '@acadly/kernel'
import { Button, GridIcon, slips, Waiting } from '@acadly/ui'
import { useEffect, useState } from 'react'
import { copy } from '../copy'
import { gridsApi, type ExportState } from '../grid/api'
import { PageHead } from '../settings/parts'

const t = copy.exports

/** While an export is prepared, the page looks again on its own this often. */
const LOOK_AGAIN = 5000

/** The mark beside the state: a drawn cell and a word, never colour alone. */
function Mark({ status }: { status: ExportState['status'] }) {
  if (status === 'ready') {
    return (
      <span aria-hidden="true" className="grid size-7 shrink-0 place-items-center bg-teal text-sheet">
        <GridIcon name="check" size={14} />
      </span>
    )
  }
  if (status === 'failed') return <span aria-hidden="true" className="grid size-7 shrink-0 place-items-center border-[3px] border-red-pen bg-sheet text-base font-extrabold text-red-pen">!</span>
  if (status === 'expired') return <span aria-hidden="true" className="size-7 shrink-0 border-2 border-dashed border-rule-dashed bg-sheet" />

  return <span aria-hidden="true" className="size-7 shrink-0 border-2 border-ink bg-marigold" />
}

const words: Record<ExportState['status'], string> = { queued: t.preparingWord, building: t.preparingWord, ready: t.readyWord, failed: t.failedWord, expired: t.expiredWord }

/** One export a person asked for (the approved "Export ready" design), reached from Notices. */
export function ExportScreen({ id }: { id: string }) {
  const loaded = useLoad(`export:${id}`, () => gridsApi.exportState(id))
  const data = loaded.data
  const [working, setWorking] = useState(false)
  const preparing = data !== undefined && (data.status === 'queued' || data.status === 'building')
  const { reload } = loaded

  useEffect(() => {
    if (!preparing) return
    const again = window.setInterval(reload, LOOK_AGAIN)

    return () => window.clearInterval(again)
  }, [preparing, reload])

  if (data === undefined) {
    return <Waiting phase={loaded.phase} what={t.loading} labels={copy.loading} rows={2} onRetry={loaded.reload} />
  }

  const download = () => {
    setWorking(true)
    void gridsApi.download(id).then((outcome) => {
      setWorking(false)
      if (outcome === 'saved') return
      slips.wrong({ word: t.notDownloaded, title: outcome === 'refused' ? t.downloadRefused : outcome === 'gone' ? t.downloadGone : t.downloadFailed })
      if (outcome === 'gone') reload()
    })
  }

  const title = data.status === 'ready' ? t.readyTitle(data.list) : data.status === 'failed' ? t.failedTitle : data.status === 'expired' ? t.expiredTitle : t.preparingTitle
  const text = data.status === 'ready' ? t.readyText(data.rows, data.expires_at) : data.status === 'failed' ? t.failedText : data.status === 'expired' ? t.expiredText : t.preparingText

  return (
    <>
      <PageHead kicker={t.kicker} title={data.list} intro={t.requested(data.requested_at)} />
      <section className="flex-1 border-t-2 border-ink bg-sheet px-[clamp(16px,3vw,40px)] pt-6 pb-8">
        <div role="status" className={`flex max-w-[640px] items-start gap-3 border-2 bg-sheet p-4 ${data.status === 'failed' ? 'border-red-pen' : 'border-ink'}`}>
          <Mark status={data.status} />
          <div className="min-w-0 flex-1">
            <div className={`label-caps ${data.status === 'failed' ? 'text-red-text' : 'text-text-muted'}`}>{words[data.status]}</div>
            <p className={`text-[15px] font-extrabold ${data.status === 'failed' ? 'text-red-text' : ''}`}>{title}</p>
            <p className="mt-0.5 mb-3 text-sm">{text}</p>
            {data.status === 'ready' && (
              <Button busy={working} onClick={download}>
                {t.download}
              </Button>
            )}
            {preparing && (
              <Button variant="secondary" onClick={reload}>
                {t.checkAgain}
              </Button>
            )}
          </div>
        </div>
        {data.status === 'ready' && <p className="mt-2.5 max-w-[640px] text-[13px] text-text-muted">{t.downloadNote}</p>}
      </section>
    </>
  )
}
