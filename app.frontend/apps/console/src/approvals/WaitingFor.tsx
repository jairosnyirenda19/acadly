import { slips } from '@acadly/ui'
import { useState, type ReactNode } from 'react'
import { follow } from '../assessment/parts'
import { copy } from '../copy'
import { Tag } from '../settings/parts'
import { approvalsApi } from './api'

const t = copy.approvals.waitingBox

/**
 * Something asked for and not yet decided (the approved design): what was
 * asked, who asked, and who it waits for. The person who asked may withdraw it.
 */
export function WaitingFor({
  title,
  children,
  askedBy,
  waitingFor,
  requestId,
  mine = false,
  onWithdrawn,
}: {
  title: string
  children?: ReactNode
  askedBy: string
  waitingFor: string[]
  requestId: string
  mine?: boolean
  onWithdrawn?: () => void
}) {
  const [busy, setBusy] = useState(false)
  const href = `/approvals/${requestId}`

  const withdraw = async () => {
    setBusy(true)
    const r = await approvalsApi.withdraw(requestId)
    setBusy(false)
    if (r.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: t.withdrawn })
      onWithdrawn?.()
    } else slips.wrong({ word: copy.settings.failed, title: t.notWithdrawn })
  }

  return (
    <div role="status" className="border-l-[6px] border-marigold bg-marigold-wash px-3.5 py-3 text-sm">
      <div className="flex flex-wrap items-center gap-2">
        <Tag tone="attention">{t.tag}</Tag>
        <strong className="text-[15px]">{title}</strong>
      </div>
      {children !== undefined && <div className="mt-1">{children}</div>}
      <div className="mt-1 text-[13px]">{t.askedBy(askedBy)}</div>
      <div className="text-[13px]">
        <strong>{t.waitingFor}</strong> {waitingFor.length > 0 ? waitingFor.join(', ') : t.nobody}
      </div>
      <div className="mt-1.5 flex flex-wrap gap-x-4">
        <a href={href} onClick={(e) => follow(e, href)} className="inline-flex min-h-9 items-center text-[13px] font-extrabold text-ink underline underline-offset-[3px]">
          {t.see}
        </a>
        {mine && onWithdrawn !== undefined && (
          <button type="button" disabled={busy} onClick={() => void withdraw()} className="min-h-9 text-[13px] font-extrabold text-red-text underline underline-offset-[3px] disabled:opacity-50">
            {t.withdraw}
          </button>
        )}
      </div>
    </div>
  )
}
