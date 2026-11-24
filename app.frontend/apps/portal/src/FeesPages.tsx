import { useLoad } from '@acadly/kernel'
import { useState, type ReactNode } from 'react'
import { portalApi, type FeeInstalment, type FeePayment, type FeesState } from './api'
import { feeDay, useFamily } from './Family'
import { Bar, Loading, Note, Screen, useWords, go } from './parts'

function useFees(id: string) {
  return useLoad(`portal:fees:${id}`, () => portalApi.fees(id))
}

/** A fees page: the way back, then the page; not found when the school shows no fees for the child. */
function FeesFrame({ id, back, phase, data, onRetry, children, foot }: {
  id: string
  back: 'child' | 'fees'
  phase: string
  data: FeesState | undefined
  onRetry: () => void
  children: (data: FeesState) => ReactNode
  foot?: (data: FeesState) => ReactNode
}) {
  const t = useWords()
  const family = useFamily().data
  const child = family?.children.find((c) => c.id === id)
  const school = family?.school.name ?? ''
  const bar = <Bar school={school} back={back === 'child' ? { to: `/child/${id}`, label: child?.first_name ?? t.back } : { to: `/child/${id}/fees`, label: t.fees.door }} />

  if (phase === 'refused') {
    return (
      <Screen bar={<Bar school={school} back={{ to: '/', label: t.home.title }} />}>
        <div className="p-4">
          <Note tone="attention" title={t.home.none}>
            {t.home.noneNote}
          </Note>
        </div>
      </Screen>
    )
  }

  if (data === undefined) return <Screen bar={bar}>{<Loading failed={phase === 'failed'} onRetry={onRetry} />}</Screen>

  return (
    <Screen bar={bar} foot={foot?.(data)}>
      {children(data)}
    </Screen>
  )
}

/** One child's fees for the term (the approved design): left and paid, each instalment, how to pay. */
export function FeesPage({ id }: { id: string }) {
  const t = useWords()
  const f = t.fees
  const loaded = useFees(id)
  const payments = `/child/${id}/payments`

  return (
    <FeesFrame
      id={id}
      back="child"
      phase={loaded.phase}
      data={loaded.data}
      onRetry={loaded.reload}
      foot={(data) => (
        <div className="grid gap-2.5">
          <a href={payments} onClick={(e) => go(e, payments)} className="flex h-14 items-center justify-center border-2 border-ink bg-sheet text-[17px] font-extrabold text-ink no-underline">
            {f.payments}
          </a>
          {data.instalments.length > 0 && (
            <a href={portalApi.statementPdf(id)} target="_blank" rel="noopener" className="flex h-14 items-center justify-center bg-teal text-[17px] font-extrabold text-sheet no-underline">
              {f.statement}
            </a>
          )}
        </div>
      )}
    >
      {(data) => (
        <>
          <div className="px-4 pt-4 pb-2.5">
            <span className="label-caps text-[10px] text-text-muted">{data.term !== null ? f.kicker(`${data.term.name} · ${data.term.year}`) : f.kickerNoTerm}</span>
            <h1 className="type-display mt-1 text-[34px] leading-[0.9]">{data.child.name}</h1>
          </div>
          {data.instalments.length === 0 ? (
            <div className="px-4 pb-4">
              <Note tone="plain" title={f.none}>
                {f.noneNote}
              </Note>
            </div>
          ) : (
            <>
              <div className="grid grid-cols-2 border-y-2 border-ink bg-sheet">
                <div className="bg-ink px-4 py-2.5 text-sheet">
                  <span className="label-caps text-[10px] text-on-ink-muted">{f.leftToPay}</span>
                  <div className="type-display text-[34px] leading-[0.95] text-teal-bright">{f.money(data.left)}</div>
                </div>
                <div className="border-l-2 border-ink px-4 py-2.5">
                  <span className="label-caps text-[10px] text-text-muted">{f.paid}</span>
                  <div className="type-display text-[34px] leading-[0.95]">{f.money(data.paid)}</div>
                </div>
              </div>
              {data.credit > 0 && <p className="border-b border-rule bg-sheet px-4 py-2.5 text-sm">{f.credit(f.money(data.credit))}</p>}
              <div className="px-4 pt-2.5 pb-1">
                <span className="label-caps text-[10px] text-text-muted">{f.instalments(f.money(data.billed))}</span>
              </div>
              <ul>
                {data.instalments.map((i, n) => (
                  <InstalmentRow key={`${i.charge}:${i.label}:${i.due_on}:${n}`} instalment={i} last={n === data.instalments.length - 1} />
                ))}
              </ul>
            </>
          )}
          {data.how_to_pay.trim() !== '' && (
            <div className="mt-2 border-y-2 border-ink bg-sheet px-4 py-3">
              <span className="label-caps text-[10px] text-text-muted">{f.howToPay}</span>
              <p className="mt-1 text-sm leading-[1.45] whitespace-pre-line">{data.how_to_pay}</p>
            </div>
          )}
        </>
      )}
    </FeesFrame>
  )
}

function InstalmentRow({ instalment: i, last }: { instalment: FeeInstalment; last: boolean }) {
  const f = useWords().fees
  const sub = [i.this_term ? null : i.charge, f.due(feeDay(i.due_on)), i.paid > 0 && i.left > 0 ? f.partPaid(f.money(i.paid)) : null].filter((s): s is string => s !== null).join(' · ')
  const tag =
    i.state === 'paid' ? (
      <span className="bg-teal-wash px-2 py-[3px] text-[13px] font-bold whitespace-nowrap text-teal">{f.states.paid}</span>
    ) : i.state === 'not_due' ? (
      <span className="bg-field px-2 py-[3px] text-[13px] font-bold whitespace-nowrap">{f.states.not_due}</span>
    ) : (
      <span className="bg-marigold px-2 py-[3px] text-[13px] font-bold whitespace-nowrap text-ink">{i.state === 'overdue' ? f.overdue(f.money(i.left)) : f.owed(f.money(i.left))}</span>
    )

  return (
    <li className={`flex min-h-14 items-center gap-2.5 bg-sheet px-4 py-1.5 ${last ? '' : 'border-b border-rule'}`}>
      <span className="min-w-0 flex-1">
        <span className="block text-[17px] font-extrabold">
          {i.label} · {f.money(i.amount)}
        </span>
        <span className="block text-sm text-text-muted">{sub}</span>
      </span>
      {tag}
    </li>
  )
}

/** The child's payments and receipts (the approved design): this term's one by one, earlier terms together. */
export function PaymentsPage({ id }: { id: string }) {
  const t = useWords()
  const f = t.fees
  const loaded = useFees(id)
  const [opened, setOpened] = useState<string[]>([])

  return (
    <FeesFrame id={id} back="fees" phase={loaded.phase} data={loaded.data} onRetry={loaded.reload}>
      {(data) => {
        const now = data.term === null ? data.payments : data.payments.filter((p) => p.this_term)
        const groups = new Map<string, FeePayment[]>()
        if (data.term !== null) {
          for (const p of data.payments.filter((x) => !x.this_term)) {
            const key = p.period ?? f.earlier
            groups.set(key, [...(groups.get(key) ?? []), p])
          }
        }

        return (
          <>
            <div className="px-4 pt-4 pb-2.5">
              <span className="label-caps text-[10px] text-text-muted">{f.paymentsKicker(data.child.name)}</span>
              <h1 className="type-display mt-1 text-[34px] leading-[0.9]">{f.receipts}</h1>
            </div>
            {data.payments.length === 0 ? (
              <div className="px-4 pb-3">
                <Note tone="plain" title={f.noPayments}>
                  {f.noPaymentsNote}
                </Note>
              </div>
            ) : (
              <ul className="border-t border-rule">
                {now.map((p) => (
                  <PaymentRow key={p.id} id={id} payment={p} />
                ))}
                {[...groups.entries()].map(([period, list]) => {
                  const open = opened.includes(period)

                  return (
                    <li key={period}>
                      <div className="flex min-h-14 items-center gap-2.5 border-b border-rule bg-sheet px-4 py-1.5">
                        <span className="min-w-0 flex-1">
                          <span className="block text-[17px] font-extrabold">{f.money(list.filter((p) => !p.cancelled).reduce((a, p) => a + p.amount, 0))}</span>
                          <span className="block text-sm text-text-muted">
                            {period} · {f.count(list.length)}
                          </span>
                        </span>
                        <button
                          type="button"
                          aria-expanded={open}
                          onClick={() => setOpened(open ? opened.filter((x) => x !== period) : [...opened, period])}
                          className="min-h-11 px-1 font-extrabold underline underline-offset-[3px]"
                        >
                          {open ? f.close : f.open}
                        </button>
                      </div>
                      {open && (
                        <ul className="bg-paper">
                          {list.map((p) => (
                            <PaymentRow key={p.id} id={id} payment={p} />
                          ))}
                        </ul>
                      )}
                    </li>
                  )
                })}
              </ul>
            )}
            <p className="px-4 py-3 text-sm leading-[1.45] text-text-muted">{f.receiptNote}</p>
            <div className="px-4 pb-6">
              <div className="flex items-baseline gap-2.5 border border-marigold bg-marigold-wash px-3 py-2.5 text-[15px]">
                <strong aria-hidden="true">!</strong>
                <span>
                  <strong>{f.missingTitle}</strong> {data.school_phone !== null ? f.missing(data.school_phone) : f.missingNoPhone}
                </span>
              </div>
            </div>
          </>
        )
      }}
    </FeesFrame>
  )
}

function PaymentRow({ id, payment: p }: { id: string; payment: FeePayment }) {
  const f = useWords().fees
  const sub = [feeDay(p.received_on), f.methods[p.method] ?? p.method, f.receipt(p.number)].join(' · ')

  return (
    <li className="flex min-h-14 items-center gap-2.5 border-b border-rule bg-sheet px-4 py-1.5">
      <span className="min-w-0 flex-1">
        <span className={`block text-[17px] font-extrabold ${p.cancelled ? 'line-through' : ''}`}>{f.money(p.amount)}</span>
        <span className="block text-sm text-text-muted">
          {sub}
          {p.cancelled && <span className="ml-1.5 font-bold text-red-text">· ✕ {f.cancelled}</span>}
        </span>
      </span>
      {p.receipt ? (
        <a href={portalApi.receiptPdf(id, p.id)} target="_blank" rel="noopener" className="inline-flex min-h-11 items-center px-1 font-extrabold text-ink">
          {f.pdf}
        </a>
      ) : (
        <span className="max-w-[110px] text-right text-xs text-text-muted">{f.pdfAtOffice}</span>
      )}
    </li>
  )
}
