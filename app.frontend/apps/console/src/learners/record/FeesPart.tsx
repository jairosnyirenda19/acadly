import { navigate, useLoad } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../../copy'
import { feesApi, figure, kwachaText, money, parseKwacha, type AdjustmentKind, type FeeCharge, type Instalment, type LearnerFees } from '../../fees/api'
import { Input, Select, TextArea } from '../../setup/parts'
import { day } from '../../settings/api'
import { Tag } from '../../settings/parts'
import type { LearnerState } from '../api'
import { Caption, Column, Columns, InLink, LedgerFrame, MarginCell, SectionTitle, tabHref, Td, Th } from './ui'

const t = copy.fees

function useFees(id: string) {
  return useLoad(`learner:fees:${id}`, () => feesApi.learner(id))
}

const titleOf = (learner: LearnerState) => learner.parts.find((p) => p.key === 'fees')?.title ?? ''

/** An instalment's state as a word on a colour: never the colour alone. */
function StateTag({ state }: { state: string }) {
  const tone = state === 'paid' ? 'done' : state === 'due' ? 'plain' : 'attention'

  return (
    <Tag tone={tone}>
      {state === 'paid' ? '✓ ' : state === 'due' ? '' : '! '}
      {t.states[state] ?? state}
    </Tag>
  )
}

/** The overview's Fees block: what is due now and the next instalment. */
export function FeesSummary({ learner }: { learner: LearnerState }) {
  const loaded = useFees(learner.id)
  const data = loaded.data

  return (
    <Column edge grow="flex-[1_1_260px]" label={titleOf(learner)}>
      <SectionTitle>{titleOf(learner)}</SectionTitle>
      {data === undefined ? (
        <Waiting phase={loaded.phase} what={titleOf(learner)} labels={copy.loading} rows={2} onRetry={loaded.reload} />
      ) : data.standing.charged === 0 ? (
        <p className="text-sm text-text-muted">{t.summaryNone}</p>
      ) : (
        <>
          <Caption>{t.dueNow}</Caption>
          <div className="type-numeral text-[40px] leading-none">{money(data.standing.due_now)}</div>
          <p className="mt-1 text-[13px] text-text-muted">{t.dueNowNote(data.standing.overdue_since !== null ? day(data.standing.overdue_since) : null)}</p>
          <p className="mt-1 text-[13px]">
            {t.leftLabel}: <b className="ref">{money(data.standing.left)}</b>
          </p>
          <InLink href={tabHref(learner.id, 'fees')}>{t.seeFees}</InLink>
        </>
      )}
    </Column>
  )
}

/** One instalment, as a card with how much of it is paid. */
function InstalmentCard({ i, n }: { i: Instalment; n: number }) {
  const pct = i.amount === 0 ? 0 : Math.round((i.covered / i.amount) * 100)
  const owed = i.amount - i.covered

  return (
    <div className={`bg-sheet px-3.5 py-3 ${i.state === 'overdue' || i.state === 'part' ? 'border-2 border-ink' : 'border border-rule'}`}>
      <div className="flex items-baseline gap-2">
        <span className="ref text-[13px]">{n}</span>
        <span className="min-w-0 flex-1 text-[15px] font-extrabold break-words">{i.label}</span>
        <StateTag state={i.state} />
      </div>
      <div className="mt-1 text-[13px] text-text-muted">{t.dueOn(day(i.due_on))}</div>
      <div className="relative mt-2 h-2.5 bg-field" aria-hidden="true">
        <div className={`absolute inset-y-0 left-0 ${i.state === 'paid' ? 'bg-teal' : 'bg-marigold'}`} style={{ width: `${pct}%` }} />
      </div>
      <div className="mt-1.5 flex justify-between gap-2 text-[13px]">
        <span className="ref">{t.ofAmount(money(i.covered), money(i.amount))}</span>
        {owed > 0 && <span className="ref font-extrabold">{t.leftOf(money(owed))}</span>}
      </div>
    </div>
  )
}

type Panel = 'none' | 'plan' | 'adjust' | 'optional'

/** The Fees tab (the approved design): the band, instalments by bill, and the statement as a ledger. */
export function FeesTab({ learner }: { learner: LearnerState }) {
  const loaded = useFees(learner.id)
  const data = loaded.data
  const [panel, setPanel] = useState<Panel>('none')
  const [chargeId, setChargeId] = useState<string | null>(null)

  if (data === undefined) return <Waiting phase={loaded.phase} what={titleOf(learner)} labels={copy.loading} rows={6} onRetry={loaded.reload} />

  const s = data.standing
  const charges = [...data.charges].reverse()
  const current = charges.find((c) => c.left > 0) ?? charges[0]
  const chosen = data.charges.find((c) => c.id === chargeId) ?? current
  const done = (title: string) => {
    setPanel('none')
    loaded.reload()
    slips.done({ word: copy.slips.saved, title })
  }
  const toggle = (p: Panel) => setPanel(panel === p ? 'none' : p)

  const cells = [
    { label: t.dueNow, value: money(s.due_now), note: t.dueNowNote(s.overdue_since !== null ? day(s.overdue_since) : null), ink: true, attention: false },
    { label: t.charged, value: money(s.charged - s.reduced), note: t.chargedNote(data.charges.length), ink: false, attention: false },
    { label: t.paidLabel, value: money(s.paid), note: t.paidNote, ink: false, attention: false },
    { label: t.leftLabel, value: money(s.left), note: s.credit > 0 ? t.creditNote(money(s.credit)) : t.leftNote, ink: false, attention: s.overdue_since !== null },
  ]

  return (
    <Columns top>
      <Column grow="flex-[1_1_100%]" label={titleOf(learner)}>
        <div className="mb-4 grid grid-cols-2 border-2 border-ink md:grid-cols-4">
          {cells.map((c, i) => (
            <section key={c.label} className={`min-w-0 px-3.5 pt-2.5 pb-3 ${c.ink ? 'bg-ink text-sheet' : c.attention ? 'bg-marigold-wash' : 'bg-sheet'} ${i > 0 ? 'border-ink max-md:[&:nth-child(n+3)]:border-t-2 md:border-l-2 max-md:[&:nth-child(even)]:border-l-2' : ''}`}>
              <h3 className={`label-caps ${c.ink ? 'text-on-ink-muted' : 'text-text-muted'}`}>{c.label}</h3>
              <div className={`type-numeral text-[clamp(26px,3vw,40px)] whitespace-nowrap ${c.ink ? 'text-teal-bright' : ''}`}>{c.value}</div>
              <p className={`text-[13px] ${c.ink ? 'text-on-ink-soft' : 'text-text-muted'}`}>{c.note}</p>
            </section>
          ))}
        </div>

        <div className="mb-5 flex flex-wrap gap-2.5 print:hidden">
          {data.can.collect && <Button onClick={() => navigate(`/fees/pay?learner=${learner.id}`)}>{t.recordPayment}</Button>}
          {data.can.adjust && current !== undefined && (
            <>
              <Button variant="secondary" aria-expanded={panel === 'plan'} onClick={() => toggle('plan')}>
                {t.agreePlan}
              </Button>
              <Button variant="secondary" aria-expanded={panel === 'adjust'} onClick={() => toggle('adjust')}>
                {t.adjust}
              </Button>
            </>
          )}
          {data.can.adjust && data.optional.length > 0 && (
            <Button variant="secondary" aria-expanded={panel === 'optional'} onClick={() => toggle('optional')}>
              {t.optional}
            </Button>
          )}
          <Button variant="secondary" onClick={() => window.open(feesApi.statementPdf(learner.id), '_blank', 'noopener')}>
            {t.print}
          </Button>
        </div>

        {panel !== 'none' && (
          <div className="mb-5 max-w-[720px] border-2 border-ink bg-sheet p-4 print:hidden">
            {panel !== 'optional' && charges.length > 1 && (
              <div className="mb-3">
                <Select label={t.planFor} value={chosen?.id ?? ''} onChange={(e) => setChargeId(e.target.value)}>
                  {charges.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.label} · {money(c.total)}
                    </option>
                  ))}
                </Select>
              </div>
            )}
            {panel === 'plan' && chosen !== undefined && <PlanForm key={chosen.id} charge={chosen} onDone={() => done(t.planSaved)} onCancel={() => setPanel('none')} />}
            {panel === 'adjust' && chosen !== undefined && <AdjustForm key={chosen.id} charge={chosen} onDone={() => done(t.adjustSaved)} onCancel={() => setPanel('none')} />}
            {panel === 'optional' && <OptionalForm learnerId={learner.id} data={data} onDone={() => done(t.optionalSaved)} onCancel={() => setPanel('none')} />}
          </div>
        )}

        {data.charges.length === 0 && <p className="mb-4 text-sm text-text-muted">{t.noFees}</p>}

        {charges
          .filter((c) => s.instalments.some((i) => i.charge_id === c.id))
          .slice(0, 3)
          .map((c) => {
            const list = s.instalments.filter((i) => i.charge_id === c.id)

            return (
              <section key={c.id} className="mb-5" aria-label={t.instalmentsOf(c.label)}>
                <SectionTitle aside={<span className="text-[13px] text-text-muted">{c.agreed ? t.agreedPlan : t.schoolPlan}</span>}>{t.instalmentsOf(c.label)}</SectionTitle>
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                  {list.map((i, n) => (
                    <InstalmentCard key={i.id} i={i} n={n + 1} />
                  ))}
                </div>
              </section>
            )
          })}
        {s.instalments.length > 0 && <p className="mb-5 text-[13px] text-text-muted">{t.instalmentRule}</p>}

        <Statement data={data} />
      </Column>
    </Columns>
  )
}

/** The statement: every line, oldest first, with the running balance. A cancelled receipt stays. */
function Statement({ data }: { data: LearnerFees }) {
  if (data.statement.length === 0) return null

  return (
    <section className="border-2 border-ink bg-sheet px-4 pt-3 pb-4" aria-label={t.statementLabel}>
      <SectionTitle>{t.statement}</SectionTitle>
      <LedgerFrame label={t.statementLabel}>
        <table className="w-full min-w-[720px] border-collapse">
          <thead>
            <tr>
              <Th>{t.cols.date}</Th>
              <Th>{t.cols.detail}</Th>
              <Th>{t.cols.reference}</Th>
              <Th end>{t.cols.charged}</Th>
              <Th end>{t.cols.paid}</Th>
              <Th end>{t.cols.balance}</Th>
            </tr>
          </thead>
          <tbody>
            {data.statement.map((line) => (
              <tr key={line.id}>
                <MarginCell>{day(line.date)}</MarginCell>
                <Td wide>
                  {line.kind === 'payment' ? t.paymentBy(line.detail) : line.kind === 'void' ? t.cancelledLine(line.detail) : line.detail}{' '}
                  {line.kind === 'void' && <Tag tone="wrong">✕ {t.voidTag}</Tag>}
                  {line.note !== null && line.note !== '' && <div className="mt-0.5 text-xs text-text-muted">{line.note}</div>}
                </Td>
                <Td>
                  {line.kind === 'payment' ? (
                    <PaymentLink reference={line.reference} paymentId={line.payment_id} />
                  ) : (
                    <span className="ref text-[13px]">{line.reference}</span>
                  )}
                </Td>
                <Td end>
                  <span className="ref">{line.charged !== null ? figure(line.charged) : ''}</span>
                </Td>
                <Td end>
                  <span className="ref">{line.paid !== null ? figure(line.paid) : ''}</span>
                </Td>
                <Td end>
                  <span className="ref font-extrabold">{money(line.balance)}</span>
                </Td>
              </tr>
            ))}
          </tbody>
        </table>
      </LedgerFrame>
      <p className="mt-2.5 text-[13px] text-text-muted">{t.neverChanged}</p>
    </section>
  )
}

/** A receipt number that opens the receipt. */
function PaymentLink({ reference, paymentId }: { reference: string; paymentId: string | null }) {
  if (paymentId === null) return <span className="ref text-[13px]">{reference}</span>

  return <InLink href={`/fees/receipts/${paymentId}`}>{reference}</InLink>
}

function PlanForm({ charge, onDone, onCancel }: { charge: FeeCharge; onDone: () => void; onCancel: () => void }) {
  const [rows, setRows] = useState([{ label: t.planRow(1), due_on: '', amount: kwachaText(charge.total) }])
  const [reason, setReason] = useState('')
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [busy, setBusy] = useState(false)
  const sum = rows.reduce((a, r) => a + (parseKwacha(r.amount) ?? 0), 0)
  const set = (i: number, key: 'label' | 'due_on' | 'amount', value: string) => setRows(rows.map((r, j) => (j === i ? { ...r, [key]: value } : r)))

  const save = async () => {
    setBusy(true)
    const result = await feesApi.plan(charge.id, reason.trim(), rows.map((r) => ({ label: r.label.trim(), due_on: r.due_on, amount: parseKwacha(r.amount) ?? 0 })))
    setBusy(false)
    if (result.kind === 'saved') onDone()
    else if (result.kind === 'invalid') setErrors(result.errors)
    else slips.wrong({ word: t.notSaved, title: t.failed })
  }

  return (
    <div className="grid gap-3">
      <h3 className="type-display text-[22px] leading-none">{t.planTitle}</h3>
      {Object.values(errors)[0]?.[0] !== undefined && (
        <Notice tone="wrong" title={t.notSaved}>
          {Object.values(errors)[0]?.[0]}
        </Notice>
      )}
      {rows.map((r, i) => (
        <div key={i} className="grid grid-cols-1 items-end gap-2 sm:grid-cols-[minmax(0,1fr)_160px_150px_auto]">
          <Input label={t.planLabel} value={r.label} onChange={(e) => set(i, 'label', e.target.value)} />
          <Input label={t.planDate} type="date" value={r.due_on} onChange={(e) => set(i, 'due_on', e.target.value)} />
          <Input label={t.planAmount} inputMode="decimal" value={r.amount} onChange={(e) => set(i, 'amount', e.target.value)} />
          <button type="button" disabled={rows.length === 1} onClick={() => setRows(rows.filter((_, j) => j !== i))} className="min-h-11 px-1 text-sm font-extrabold underline disabled:opacity-40">
            {t.removeRow}
          </button>
        </div>
      ))}
      <div className="flex flex-wrap items-center gap-3">
        <button type="button" onClick={() => setRows([...rows, { label: t.planRow(rows.length + 1), due_on: '', amount: '' }])} className="min-h-11 text-sm font-extrabold underline">
          {t.addRow}
        </button>
        <span className={`text-[13px] ${sum === charge.total ? 'text-text-muted' : 'font-bold text-red-text'}`}>{t.planTotal(money(sum), money(charge.total))}</span>
      </div>
      <TextArea label={t.planReason} value={reason} onChange={(e) => setReason(e.target.value)} required />
      <div className="flex flex-wrap gap-2">
        <Button busy={busy} disabled={sum !== charge.total || reason.trim() === '' || rows.some((r) => r.due_on === '' || r.label.trim() === '')} onClick={() => void save()}>
          {t.planSave}
        </Button>
        <Button variant="secondary" onClick={onCancel}>
          {t.cancel}
        </Button>
      </div>
    </div>
  )
}

const KINDS: AdjustmentKind[] = ['discount', 'bursary', 'waiver', 'write_off']

function AdjustForm({ charge, onDone, onCancel }: { charge: FeeCharge; onDone: () => void; onCancel: () => void }) {
  const [kind, setKind] = useState<AdjustmentKind>('bursary')
  const [amount, setAmount] = useState('')
  const [reason, setReason] = useState('')
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [busy, setBusy] = useState(false)
  const most = charge.total - charge.reduced
  const value = parseKwacha(amount)

  const save = async () => {
    if (value === null) return
    setBusy(true)
    const result = await feesApi.adjust(charge.id, kind, value, reason.trim())
    setBusy(false)
    if (result.kind === 'saved') onDone()
    else if (result.kind === 'invalid') setErrors(result.errors)
    else slips.wrong({ word: t.notSaved, title: t.failed })
  }

  return (
    <div className="grid gap-3">
      <h3 className="type-display text-[22px] leading-none">{t.adjustTitle}</h3>
      <div className="grid gap-3 sm:grid-cols-2">
        <Select label={t.adjustKind} value={kind} onChange={(e) => setKind(e.target.value as AdjustmentKind)}>
          {KINDS.map((k) => (
            <option key={k} value={k}>
              {t.kinds[k]}
            </option>
          ))}
        </Select>
        <Input label={t.adjustAmount} inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} hint={t.adjustLeft(money(most))} error={errors.amount?.[0]} />
      </div>
      <TextArea label={t.adjustReason} value={reason} onChange={(e) => setReason(e.target.value)} error={errors.reason?.[0]} required />
      <div className="flex flex-wrap gap-2">
        <Button busy={busy} disabled={value === null || value <= 0 || value > most || reason.trim() === ''} onClick={() => void save()}>
          {t.adjustSave}
        </Button>
        <Button variant="secondary" onClick={onCancel}>
          {t.cancel}
        </Button>
      </div>
    </div>
  )
}

function OptionalForm({ learnerId, data, onDone, onCancel }: { learnerId: string; data: LearnerFees; onDone: () => void; onCancel: () => void }) {
  const [chosen, setChosen] = useState(data.optional.filter((o) => o.takes).map((o) => o.id))
  const [busy, setBusy] = useState(false)

  const save = async () => {
    setBusy(true)
    const result = await feesApi.optional(learnerId, chosen)
    setBusy(false)
    if (result.kind === 'saved') onDone()
    else slips.wrong({ word: t.notSaved, title: t.failed })
  }

  return (
    <div className="grid gap-3">
      <h3 className="type-display text-[22px] leading-none">{t.optionalTitle}</h3>
      {data.optional.map((o) => (
        <label key={o.id} className="flex items-center gap-2.5 text-[15px]">
          <input type="checkbox" className="size-5 accent-ink" checked={chosen.includes(o.id)} onChange={(e) => setChosen(e.target.checked ? [...chosen, o.id] : chosen.filter((c) => c !== o.id))} />
          {o.name}
        </label>
      ))}
      <p className="text-[13px] text-text-muted">{t.optionalNote}</p>
      <div className="flex flex-wrap gap-2">
        <Button busy={busy} onClick={() => void save()}>
          {t.optionalSave}
        </Button>
        <Button variant="secondary" onClick={onCancel}>
          {t.cancel}
        </Button>
      </div>
    </div>
  )
}
