import { navigate, useLoad, useSchool } from '@acadly/kernel'
import { Button, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { TextArea } from '../setup/parts'
import { day } from '../settings/api'
import { feesApi, money } from './api'

const t = copy.fees

/** A receipt (the approved design), on A5 when printed. A cancelled receipt says VOID and why. */
export function ReceiptScreen({ id }: { id: string }) {
  const { school } = useSchool()
  const loaded = useLoad(`fees:receipt:${id}`, () => feesApi.receipt(id))
  const r = loaded.data
  const [cancelling, setCancelling] = useState(false)
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)

  if (r === undefined) return <Waiting phase={loaded.phase} what={t.receipt} labels={copy.loading} rows={6} onRetry={loaded.reload} />

  const cancel = async () => {
    setBusy(true)
    const result = await feesApi.cancel(r.id, reason.trim())
    setBusy(false)
    if (result.kind === 'saved') {
      setCancelling(false)
      loaded.reload()
      slips.done({ word: copy.slips.saved, title: t.cancelled })
    } else slips.wrong({ word: t.notSaved, title: result.kind === 'invalid' ? (Object.values(result.errors)[0]?.[0] ?? t.failed) : t.failed })
  }

  const first = r.shares[0]

  return (
    <div className="grid justify-items-center gap-5 px-4 py-6 print:p-0">
      <div className="flex w-full max-w-[560px] flex-wrap gap-2.5 print:hidden">
        <Button onClick={() => window.open(feesApi.receiptPdf(r.id), '_blank', 'noopener')}>{t.printReceipt}</Button>
        {first !== undefined && (
          <Button variant="secondary" onClick={() => navigate(`/learners/${first.learner_id}?tab=fees`)}>
            {t.backToLearner}
          </Button>
        )}
      </div>

      <article aria-label={`${t.receipt} ${r.number}`} className="relative flex w-full max-w-[560px] flex-col gap-4 border-2 border-ink bg-sheet px-6 py-7 print:border-0 sm:px-10">
        {r.void !== null && (
          <div aria-hidden="true" className="type-display pointer-events-none absolute top-24 right-6 rotate-[-12deg] border-4 border-red-pen px-4 text-[64px] leading-none text-red-text opacity-80">
            {t.voidStamp}
          </div>
        )}
        <header className="flex items-start gap-3.5 border-b-[3px] border-ink pb-3">
          <div className="min-w-0 flex-1">
            <div className="type-display text-[26px] leading-none">{school.name}</div>
          </div>
          <div className="text-right">
            <div className="label-caps text-text-muted">{t.receipt}</div>
            <div className="ref text-[15px] font-extrabold">{r.number}</div>
            <div className="text-xs text-text-muted">{day(r.received_on)}</div>
          </div>
        </header>
        <dl className="grid grid-cols-[120px_minmax(0,1fr)] gap-x-3 gap-y-1.5 text-sm">
          <dt className="text-text-muted">{t.receivedFrom}</dt>
          <dd className="font-extrabold">{r.payer}</dd>
          <dt className="text-text-muted">{t.paidByLabel}</dt>
          <dd>
            {r.method_label}
            {r.reference !== null && (
              <>
                {' · '}
                <span className="ref">{r.reference}</span>
              </>
            )}
          </dd>
        </dl>
        <table className="w-full border-collapse text-sm">
          <thead>
            <tr>
              <th scope="col" className="label-caps border-b-2 border-ink py-1 text-left text-text-muted">
                {t.forLabel}
              </th>
              <th scope="col" className="label-caps border-b-2 border-ink py-1 text-right text-text-muted">
                {t.amountLabel}
              </th>
            </tr>
          </thead>
          <tbody>
            {r.shares.map((s) => (
              <tr key={s.learner_id} className="border-b border-rule">
                <td className="py-2 align-top">
                  <b>{s.name}</b>
                  {s.class !== '' && <> · {s.class}</>}
                  <div className="mt-0.5 text-xs text-text-muted">
                    {s.applied.map((a) => `${a.label}: ${money(a.amount)}`).join(' · ')}
                    {s.applied.length > 0 && '. '}
                    {t.balanceNow(money(Math.max(0, s.balance_after)))}
                  </div>
                </td>
                <td className="ref py-2 pl-3 text-right align-top whitespace-nowrap">{money(s.amount)}</td>
              </tr>
            ))}
            <tr>
              <td className="py-2.5 text-base font-extrabold">{t.totalReceived}</td>
              <td className="ref py-2.5 text-right text-lg font-extrabold whitespace-nowrap">{money(r.amount)}</td>
            </tr>
          </tbody>
        </table>
        {r.void !== null && <p className="text-sm font-bold text-red-text">{t.voidLine(r.void.number, r.void.reason)}</p>}
        <div className="mt-6 border-t border-ink pt-1.5 text-xs text-text-muted">{r.received_by !== null && t.receivedBy(r.received_by)}</div>
      </article>

      {r.can_cancel && (
        <div className="w-full max-w-[560px] print:hidden">
          {cancelling ? (
            <div className="grid gap-2.5">
              <TextArea label={t.cancelReason} value={reason} onChange={(e) => setReason(e.target.value)} hint={t.cancelNote} required />
              <div className="flex flex-wrap gap-2">
                <Button busy={busy} disabled={reason.trim() === ''} onClick={() => void cancel()}>
                  {t.cancelReceipt}
                </Button>
                <Button variant="secondary" onClick={() => setCancelling(false)}>
                  {t.cancel}
                </Button>
              </div>
            </div>
          ) : (
            <button type="button" onClick={() => setCancelling(true)} className="min-h-11 text-[15px] font-extrabold text-red-text underline underline-offset-[3px]">
              {t.cancelReceipt}
            </button>
          )}
        </div>
      )}
    </div>
  )
}
