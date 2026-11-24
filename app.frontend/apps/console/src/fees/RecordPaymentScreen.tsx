import { navigate, useLocation } from '@acadly/kernel'
import { Button, Notice, Waiting } from '@acadly/ui'
import { useEffect, useMemo, useState } from 'react'
import { copy } from '../copy'
import { searchFor, type Hit } from '../search/search'
import { Input } from '../setup/parts'
import { PageHead, Tag } from '../settings/parts'
import { learnersApi } from '../learners/api'
import { feesApi, kwachaText, METHODS, money, parseKwacha, splitPayment, today, type LearnerFees, type Method } from './api'

const t = copy.fees

interface Child {
  id: string
  name: string
  className: string
  due_now: number
  left: number
}

/** Finds a learner by name, from the search everyone already has: only learners the person may see. */
export function LearnerFinder({ onPick }: { onPick: (id: string) => void }) {
  const [text, setText] = useState('')
  const [hits, setHits] = useState<Hit[]>([])
  const [searched, setSearched] = useState(false)

  useEffect(() => {
    if (text.trim().length < 2) {
      setHits([])
      setSearched(false)
      return
    }
    let live = true
    const timer = window.setTimeout(() => {
      void searchFor(text.trim(), { limit: 8 }).then((answer) => {
        if (!live) return
        setHits(answer?.groups.flatMap((g) => g.hits).filter((h) => h.kind === 'learner') ?? [])
        setSearched(true)
      })
    }, 200)

    return () => {
      live = false
      window.clearTimeout(timer)
    }
  }, [text])

  return (
    <div className="grid gap-2">
      <Input label={t.findLearner} type="search" value={text} placeholder={t.findPlaceholder} onChange={(e) => setText(e.target.value)} />
      {hits.length > 0 && (
        <ul className="border border-rule bg-sheet">
          {hits.map((h) => (
            <li key={h.id}>
              <button type="button" onClick={() => onPick(h.id)} className="flex min-h-11 w-full items-baseline gap-2 border-b border-rule px-3 py-2 text-left hover:bg-field">
                <b className="text-[15px]">{h.title}</b>
                <span className="text-[13px] text-text-muted">{[h.reference, h.subtitle].filter(Boolean).join(' · ')}</span>
              </button>
            </li>
          ))}
        </ul>
      )}
      {searched && hits.length === 0 && <p className="text-sm text-text-muted">{t.noMatch}</p>}
    </div>
  )
}

/** Record a payment (the approved design): one family, several children, one receipt. */
export function RecordPaymentScreen() {
  const { query } = useLocation()
  const [learnerId, setLearnerId] = useState<string | null>(query.get('learner'))
  const [fees, setFees] = useState<LearnerFees | null | 'loading'>(learnerId === null ? null : 'loading')

  useEffect(() => {
    if (learnerId === null) return
    setFees('loading')
    void feesApi.learner(learnerId).then((answer) => setFees(answer === null || answer === 'refused' || answer === 'missing' ? null : answer))
  }, [learnerId])

  return (
    <>
      <PageHead kicker={t.payKicker} title={t.payTitle} intro={t.payIntro} />
      <div className="px-[clamp(16px,3vw,40px)] pb-8">
        {learnerId === null || fees === null ? (
          <div className="max-w-[560px]">
            <LearnerFinder onPick={setLearnerId} />
          </div>
        ) : fees === 'loading' ? (
          <Waiting phase="waiting" what={t.payTitle} labels={copy.loading} rows={4} />
        ) : (
          <PaymentForm key={learnerId} learnerId={learnerId} fees={fees} onOther={() => setLearnerId(null)} />
        )}
      </div>
    </>
  )
}

function PaymentForm({ learnerId, fees, onOther }: { learnerId: string; fees: LearnerFees; onOther: () => void }) {
  const me: Child = { id: learnerId, name: '', className: '', due_now: fees.standing.due_now, left: fees.standing.left }
  const children: Child[] = useMemo(() => [me, ...fees.siblings.map((s) => ({ id: s.id, name: s.name, className: s.class, due_now: s.due_now, left: s.left }))], [fees]) // eslint-disable-line react-hooks/exhaustive-deps
  const [included, setIncluded] = useState<string[]>([learnerId])
  const [amountText, setAmountText] = useState(kwachaText(Math.max(fees.standing.due_now, 0)))
  const [shares, setShares] = useState<Record<string, string>>({})
  const [touched, setTouched] = useState(false)
  const [method, setMethod] = useState<Method>('cash')
  const [reference, setReference] = useState('')
  const [received, setReceived] = useState(today())
  const [payer, setPayer] = useState(fees.contact?.name ?? '')
  const [phone, setPhone] = useState(fees.contact?.phone ?? '')
  const [tell, setTell] = useState(true)
  const [busy, setBusy] = useState(false)
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [learner, setLearner] = useState({ name: '', className: '' })

  // The learner's own name and class come from the record.
  useEffect(() => {
    let live = true
    void learnersApi.record(learnerId).then((r) => {
      if (live && r !== null && r !== 'refused' && r !== 'missing') setLearner({ name: r.name, className: r.class?.name ?? '' })
    })

    return () => {
      live = false
    }
  }, [learnerId])

  const amount = parseKwacha(amountText)
  const chosen = children.filter((c) => included.includes(c.id))

  // Until a share is typed, the amount is split for the family: what is due now first.
  useEffect(() => {
    if (touched || amount === null) return
    const split = splitPayment(amount, chosen)
    setShares(Object.fromEntries(chosen.map((c) => [c.id, kwachaText(split[c.id] ?? 0)])))
  }, [amount, included.join(','), touched]) // eslint-disable-line react-hooks/exhaustive-deps

  const values = chosen.map((c) => ({ child: c, value: parseKwacha(shares[c.id] ?? '') ?? 0 }))
  const sum = values.reduce((a, v) => a + v.value, 0)
  const matches = amount !== null && amount > 0 && sum === amount && values.every((v) => v.value > 0)
  const credits = values.filter((v) => v.value > v.child.left)
  const nameOf = (c: Child) => (c.id === learnerId ? learner.name : c.name)

  const save = async () => {
    if (amount === null) return
    setBusy(true)
    setErrors({})
    const result = await feesApi.pay({
      method,
      reference: method === 'cash' ? '' : reference.trim(),
      received_on: received,
      payer_name: payer.trim(),
      payer_phone: phone.trim(),
      amount,
      shares: values.map((v) => ({ learner_id: v.child.id, amount: v.value })),
      tell,
    })
    setBusy(false)
    if (result.kind === 'saved') navigate(`/fees/receipts/${result.data.id}`)
    else if (result.kind === 'invalid') setErrors(result.errors)
    else setErrors({ form: [t.failed] })
  }

  const firstError = Object.values(errors)[0]?.[0]

  return (
    <div className="grid grid-cols-[minmax(0,1fr)] items-start gap-x-8 gap-y-6 xl:grid-cols-[minmax(0,1fr)_400px]">
      <div className="grid gap-5">
        {firstError !== undefined && (
          <Notice tone="wrong" title={t.notSaved}>
            {firstError}
          </Notice>
        )}
        <div className="grid gap-4 sm:grid-cols-2">
          <Input label={t.payerName} value={payer} onChange={(e) => setPayer(e.target.value)} hint={t.payerHint} error={errors.payer_name?.[0]} required />
          <Input label={t.payerPhone} type="tel" value={phone} onChange={(e) => setPhone(e.target.value)} />
        </div>
        <div className="grid gap-4 sm:grid-cols-[220px_minmax(0,1fr)_200px]">
          <Input label={t.amount} inputMode="decimal" value={amountText} onChange={(e) => setAmountText(e.target.value)} error={errors.amount?.[0]} required />
          {method !== 'cash' ? (
            <Input label={t.referenceLabel[method] ?? t.reference} value={reference} onChange={(e) => setReference(e.target.value)} hint={t.referenceHint} error={errors.reference?.[0]} required />
          ) : (
            <span />
          )}
          <Input label={t.receivedOn} type="date" value={received} max={today()} onChange={(e) => setReceived(e.target.value)} error={errors.received_on?.[0]} />
        </div>
        <fieldset className="grid gap-2">
          <legend className="label-caps mb-1.5 text-text-muted">{t.howPaid}</legend>
          <div className="flex flex-wrap gap-2">
            {METHODS.map((m) => (
              <label key={m} className={`flex min-h-11 items-center gap-2 bg-sheet px-3 text-[15px] ${method === m ? 'border-[3px] border-ink font-extrabold' : 'border border-rule'}`}>
                <input type="radio" name="method" checked={method === m} onChange={() => setMethod(m)} className="accent-ink" />
                {t.methods[m]}
              </label>
            ))}
          </div>
        </fieldset>

        <section aria-label={t.children} className="grid gap-3">
          <div className="label-caps text-text-muted">{t.children}</div>
          {children.map((c) => {
            const on = included.includes(c.id)

            return (
              <div key={c.id} className={`grid gap-2 bg-sheet px-3.5 py-3 ${on ? 'border-2 border-ink' : 'border border-rule'}`}>
                <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                  <label className="flex min-h-11 flex-1 items-center gap-2.5">
                    <input
                      type="checkbox"
                      className="size-5 accent-ink"
                      checked={on}
                      disabled={c.id === learnerId}
                      aria-label={t.include(nameOf(c))}
                      onChange={(e) => setIncluded(e.target.checked ? [...included, c.id] : included.filter((i) => i !== c.id))}
                    />
                    <span className="text-[17px] font-extrabold">{nameOf(c)}</span>
                    <span className="text-[13px] text-text-muted">{c.id === learnerId ? learner.className : c.className}</span>
                  </label>
                  <span className="text-[13px] text-text-muted">
                    {t.owes(money(c.left))}
                    {c.due_now > 0 && (
                      <>
                        {' · '}
                        <Tag tone="attention">! {t.dueNowShort(money(c.due_now))}</Tag>
                      </>
                    )}
                  </span>
                </div>
                {on && (
                  <div className="max-w-[220px]">
                    <Input
                      label={t.share}
                      inputMode="decimal"
                      value={shares[c.id] ?? ''}
                      onChange={(e) => {
                        setTouched(true)
                        setShares({ ...shares, [c.id]: e.target.value })
                      }}
                    />
                  </div>
                )}
              </div>
            )
          })}
          <button type="button" onClick={onOther} className="min-h-11 justify-self-start text-sm font-extrabold underline underline-offset-[3px]">
            {t.otherLearner}
          </button>
        </section>
      </div>

      <aside aria-label={t.thisReceipt} className="grid gap-3 border-2 border-ink bg-sheet p-4 xl:sticky xl:top-20">
        <div className="label-caps text-text-muted">{t.thisReceipt}</div>
        <dl className="grid grid-cols-[minmax(0,1fr)_auto] gap-x-3 gap-y-1.5 text-[15px]">
          {values.map((v) => (
            <div key={v.child.id} className="contents">
              <dt>{nameOf(v.child)}</dt>
              <dd className="ref">{money(v.value)}</dd>
            </div>
          ))}
          <dt className="border-t-2 border-ink pt-1.5 font-extrabold">{t.total}</dt>
          <dd className="ref border-t-2 border-ink pt-1.5 font-extrabold">{money(amount ?? 0)}</dd>
        </dl>
        {matches ? (
          <div className="bg-teal-wash px-3 py-2.5 text-sm">
            <Tag tone="done">✓ {t.sharesMatch}</Tag> {t.sharesMatchNote}
          </div>
        ) : (
          amount !== null && (
            <div className="bg-red-wash px-3 py-2.5 text-sm font-bold text-red-text">{t.sharesOff(money(Math.abs((amount ?? 0) - sum)))}</div>
          )
        )}
        {credits.map((v) => (
          <div key={v.child.id} className="bg-marigold-wash px-3 py-2.5 text-sm">
            <Tag tone="attention">! {t.credit}</Tag> {t.creditPreview(nameOf(v.child), money(v.value - v.child.left))}
          </div>
        ))}
        <label className="flex items-center gap-2.5 text-sm">
          <input type="checkbox" className="size-5 accent-ink" checked={tell} onChange={(e) => setTell(e.target.checked)} />
          {t.tell}
        </label>
        <Button busy={busy} disabled={!matches || payer.trim() === '' || (method !== 'cash' && reference.trim() === '')} onClick={() => void save()}>
          {t.paySave}
        </Button>
        <p className="text-[13px] text-text-muted">{t.payNoEdit}</p>
      </aside>
    </div>
  )
}
