import { navigate, useLoad } from '@acadly/kernel'
import { Button, Notice, Waiting } from '@acadly/ui'
import { useEffect, useState } from 'react'
import { copy } from '../copy'
import { learnersApi } from '../learners/api'
import { Input } from '../setup/parts'
import { PageHead } from '../settings/parts'
import { feesApi, METHODS, money, type Method } from './api'
import { LearnerFinder } from './RecordPaymentScreen'

const t = copy.fees

/** The school shop (the approved design): a uniform sale for one learner, paid now or added to the statement. */
export function ShopScreen() {
  const loaded = useLoad('fees:shop', () => feesApi.shop())
  const [learner, setLearner] = useState<{ id: string; name: string; className: string } | null>(null)
  const [picked, setPicked] = useState<string | null>(null)
  const [qty, setQty] = useState<Record<string, number>>({})
  const [payNow, setPayNow] = useState<Method | null>('cash')
  const [reference, setReference] = useState('')
  const [payer, setPayer] = useState('')
  const [busy, setBusy] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)

  useEffect(() => {
    if (picked === null) return
    void learnersApi.record(picked).then((r) => {
      if (r !== null && r !== 'refused') {
        setLearner({ id: r.id, name: r.name, className: r.class?.name ?? '' })
        setPayer(r.contacts.find((c) => c.main)?.name ?? r.contacts[0]?.name ?? '')
      }
    })
  }, [picked])

  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={t.shopTitle} labels={copy.loading} rows={4} onRetry={loaded.reload} />

  const items = loaded.data.items.filter((i) => !i.retired)
  const total = items.reduce((a, i) => a + i.price * (qty[i.id] ?? 0), 0)
  const step = (id: string, by: number) => setQty({ ...qty, [id]: Math.max(0, Math.min(99, (qty[id] ?? 0) + by)) })

  const sell = async () => {
    if (learner === null) return
    setBusy(true)
    setProblem(null)
    const result = await feesApi.sale({
      learner_id: learner.id,
      lines: items.filter((i) => (qty[i.id] ?? 0) > 0).map((i) => ({ item_id: i.id, quantity: qty[i.id] ?? 0 })),
      pay_now: payNow,
      reference: payNow !== null && payNow !== 'cash' ? reference.trim() : '',
      payer_name: payer.trim(),
    })
    setBusy(false)
    if (result.kind === 'saved') navigate(result.data.payment_id !== null ? `/fees/receipts/${result.data.payment_id}` : `/learners/${learner.id}?tab=fees`)
    else setProblem(result.kind === 'invalid' ? (Object.values(result.errors)[0]?.[0] ?? t.failed) : t.failed)
  }

  return (
    <>
      <PageHead kicker={t.shopKicker} title={t.shopTitle} intro={t.shopNote} />
      <div className="grid max-w-[560px] gap-4 px-[clamp(16px,3vw,40px)] pb-8">
        {problem !== null && (
          <Notice tone="wrong" title={t.notSaved}>
            {problem}
          </Notice>
        )}
        {learner === null ? (
          <LearnerFinder onPick={setPicked} />
        ) : (
          <div className="flex items-center gap-2.5 border-2 border-ink bg-sheet px-3 py-2.5">
            <span className="flex-1">
              <b>{learner.name}</b>
              <br />
              <span className="text-[13px] text-text-muted">{learner.className}</span>
            </span>
            <button type="button" onClick={() => (setLearner(null), setPicked(null))} className="min-h-11 text-[13px] font-extrabold underline">
              {t.otherLearner}
            </button>
          </div>
        )}

        {items.length === 0 ? (
          <p className="text-sm text-text-muted">{t.shopEmpty}</p>
        ) : (
          <ul className="bg-sheet px-3">
            {items.map((i) => (
              <li key={i.id} className="flex items-center gap-2.5 border-b border-rule py-2.5">
                <span className="flex-1">
                  <b className="text-[15px]">{i.name}</b>
                  <br />
                  <span className="text-[13px] text-text-muted">{[i.size, money(i.price)].filter(Boolean).join(' · ')}</span>
                </span>
                <span className="flex items-center border-2 border-ink">
                  <button type="button" aria-label={`${t.fewer}: ${i.name}`} onClick={() => step(i.id, -1)} className="size-10 bg-sheet text-xl font-extrabold">
                    −
                  </button>
                  <span className="ref w-8 text-center font-extrabold" aria-live="polite">
                    {qty[i.id] ?? 0}
                  </span>
                  <button type="button" aria-label={`${t.one}: ${i.name}`} onClick={() => step(i.id, 1)} className="size-10 bg-sheet text-xl font-extrabold">
                    +
                  </button>
                </span>
              </li>
            ))}
          </ul>
        )}

        <div className="flex justify-between text-[17px] font-extrabold">
          <span>{t.total}</span>
          <span className="ref">{money(total)}</span>
        </div>

        <fieldset className="grid gap-2">
          <legend className="label-caps mb-1.5 text-text-muted">{t.payment}</legend>
          {METHODS.map((m) => (
            <label key={m} className={`flex min-h-11 items-center gap-2.5 bg-sheet px-3 text-[15px] ${payNow === m ? 'border-[3px] border-ink font-extrabold' : 'border border-rule'}`}>
              <input type="radio" name="pay" checked={payNow === m} onChange={() => setPayNow(m)} className="accent-ink" />
              {t.paidNow(t.methods[m] ?? m)}
            </label>
          ))}
          <label className={`flex min-h-11 items-center gap-2.5 bg-sheet px-3 text-[15px] ${payNow === null ? 'border-[3px] border-ink font-extrabold' : 'border border-rule'}`}>
            <input type="radio" name="pay" checked={payNow === null} onChange={() => setPayNow(null)} className="accent-ink" />
            {t.toStatement}
          </label>
        </fieldset>
        {payNow !== null && <Input label={t.payerName} value={payer} onChange={(e) => setPayer(e.target.value)} required />}
        {payNow !== null && payNow !== 'cash' && <Input label={t.referenceLabel[payNow] ?? t.reference} value={reference} onChange={(e) => setReference(e.target.value)} required />}

        <Button busy={busy} disabled={learner === null || total === 0 || (payNow !== null && payer.trim() === '') || (payNow !== null && payNow !== 'cash' && reference.trim() === '')} onClick={() => void sell()}>
          {t.sell}
        </Button>
      </div>
    </>
  )
}
