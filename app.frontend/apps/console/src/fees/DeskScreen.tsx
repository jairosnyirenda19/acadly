import { can, navigate, useAppContext, useLoad } from '@acadly/kernel'
import { Button, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { Input } from '../setup/parts'
import { day } from '../settings/api'
import { PageHead, Tag } from '../settings/parts'
import { feesApi, money, parseKwacha, today } from './api'

const t = copy.fees

/** The fees desk (the approved design): this term's totals, who is behind their plan, today's takings and the day's close. */
export function DeskScreen() {
  const context = useAppContext()
  const loaded = useLoad('fees:desk', () => feesApi.desk())
  const d = loaded.data
  const [counted, setCounted] = useState('')
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)
  const [from, setFrom] = useState(today().slice(0, 8) + '01')
  const [to, setTo] = useState(today())

  if (d === undefined) return <Waiting phase={loaded.phase} what={t.deskTitle} labels={copy.loading} rows={6} onRetry={loaded.reload} />

  const pct = d.billed > 0 ? Math.round((d.collected / d.billed) * 100) : 0
  const cells = [
    { label: t.collected, value: money(d.collected), note: `${pct}% · ${t.collectedNote(money(d.billed))}`, ink: true },
    { label: t.dueUnpaid, value: money(d.due_unpaid), note: t.dueUnpaidNote(d.behind_count), attention: d.due_unpaid > 0 },
    { label: t.paidInFull, value: String(d.paid_in_full), note: t.paidInFullNote(d.billed_learners) },
    { label: t.creditHeld, value: money(d.credit), note: t.creditHeldNote(d.credit_learners) },
  ]
  const countedValue = parseKwacha(counted)

  const remind = async () => {
    setBusy(true)
    const result = await feesApi.remind(d.behind.map((b) => b.learner_id))
    setBusy(false)
    if (result.kind === 'saved') slips.done({ word: copy.slips.saved, title: t.reminded(result.data.sent) })
    else slips.wrong({ word: t.notSaved, title: t.failed })
  }

  const close = async () => {
    if (countedValue === null) return
    setBusy(true)
    const result = await feesApi.closeDay(d.today, countedValue, note.trim())
    setBusy(false)
    if (result.kind === 'saved') {
      loaded.reload()
      slips.done({ word: copy.slips.saved, title: `${t.dayClosed}. ${t.difference(money(result.data.difference))}` })
    } else slips.wrong({ word: t.notSaved, title: result.kind === 'invalid' ? (Object.values(result.errors)[0]?.[0] ?? t.failed) : t.failed })
  }

  return (
    <>
      <PageHead
        kicker={t.deskKicker(d.terms.map((x) => `${x.division} ${x.name}`).join(' · '))}
        title={t.deskTitle}
        intro=""
        actions={
          <>
            <Button variant="secondary" onClick={() => navigate('/fees/shop')}>
              {t.sellShop}
            </Button>
            <Button onClick={() => navigate('/fees/pay')}>{t.recordPayment}</Button>
          </>
        }
      />
      <div className="grid gap-6 px-[clamp(16px,3vw,40px)] pb-8">
        <div className="grid grid-cols-2 border-2 border-ink lg:grid-cols-4">
          {cells.map((c, i) => (
            <section key={c.label} className={`min-w-0 px-3.5 pt-2.5 pb-3 ${c.ink === true ? 'bg-ink text-sheet' : c.attention === true ? 'bg-marigold-wash' : 'bg-sheet'} ${i > 0 ? 'border-ink lg:border-l-2 max-lg:[&:nth-child(even)]:border-l-2 max-lg:[&:nth-child(n+3)]:border-t-2' : ''}`}>
              <h3 className={`label-caps ${c.ink === true ? 'text-on-ink-muted' : 'text-text-muted'}`}>{c.label}</h3>
              <div className={`type-numeral text-[clamp(26px,3vw,40px)] whitespace-nowrap ${c.ink === true ? 'text-teal-bright' : ''}`}>{c.value}</div>
              <p className={`text-[13px] ${c.ink === true ? 'text-on-ink-soft' : 'text-text-muted'}`}>{c.note}</p>
            </section>
          ))}
        </div>

        <div className="grid items-start gap-x-8 gap-y-6 xl:grid-cols-[minmax(0,1fr)_420px]">
          <section aria-label={t.behind} className="border-2 border-ink bg-sheet px-4 pt-3 pb-4">
            <h2 className="type-display mb-2 text-[26px] leading-none">{t.behind}</h2>
            {d.behind.length === 0 ? (
              <p className="text-sm text-text-muted">{t.behindNone}</p>
            ) : (
              <ul>
                {d.behind.map((b) => (
                  <li key={b.learner_id} className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 border-b border-rule py-2.5 md:grid-cols-[minmax(0,1fr)_220px_140px_90px]">
                    <a href={`/learners/${b.learner_id}?tab=fees`} onClick={(e) => (e.preventDefault(), navigate(`/learners/${b.learner_id}?tab=fees`))} className="min-w-0 text-ink no-underline">
                      <b className="block text-[15px] underline-offset-[3px] hover:underline">{b.name}</b>
                      <span className="text-xs text-text-muted">{b.class}</span>
                    </a>
                    <span className="text-sm max-md:col-start-1">{b.owed_on}</span>
                    <span className="ref text-right text-sm font-extrabold max-md:col-start-2 max-md:row-start-1">{money(b.due_now)}</span>
                    <span className="text-right max-md:col-start-2 max-md:row-start-2">
                      <Tag tone="attention">! {t.days(b.days)}</Tag>
                    </span>
                  </li>
                ))}
              </ul>
            )}
            {d.behind.length > 0 && (
              <div className="mt-3 grid gap-1.5">
                <div>
                  <Button variant="secondary" busy={busy} onClick={() => void remind()}>
                    {t.remind(d.behind.length)}
                  </Button>
                </div>
                <p className="text-[13px] text-text-muted">{t.remindNote}</p>
              </div>
            )}
          </section>

          <aside className="grid gap-5">
            <section className="border-2 border-ink bg-sheet px-4 py-3.5">
              <div className="mb-2 flex items-baseline gap-2">
                <h2 className="type-display flex-1 text-[26px] leading-none">{t.todayTitle(day(d.today))}</h2>
                {d.closed === null ? <Tag tone="attention">! {t.open}</Tag> : <Tag tone="done">✓ {t.closed}</Tag>}
              </div>
              <ul>
                {d.takings.map((m) => (
                  <li key={m.method} className="flex gap-2.5 border-b border-rule py-2 text-[15px]">
                    <span className="flex-1">{m.label}</span>
                    <span className="text-text-muted">{t.receipts(m.receipts)}</span>
                    <span className="ref w-[120px] text-right">{money(m.amount)}</span>
                  </li>
                ))}
              </ul>
              <div className="flex gap-2.5 pt-2.5 pb-1 text-base font-extrabold">
                <span className="flex-1">{t.total}</span>
                <span className="ref">{money(d.takings.reduce((a, m) => a + m.amount, 0))}</span>
              </div>
              <p className="text-[13px] text-text-muted">
                {d.cashiers.length > 0 && t.cashiers(d.cashiers.map((c) => `${c.name} (${c.receipts})`).join(', '))} {d.cancelled_today > 0 && t.cancelledToday(d.cancelled_today)}
              </p>
              {d.closed === null ? (
                <div className="mt-3 grid gap-2.5">
                  <p className="text-sm">{t.expectedCash(money(d.expected_cash))}</p>
                  <Input label={t.counted} inputMode="decimal" value={counted} onChange={(e) => setCounted(e.target.value)} />
                  <Input label={t.closeNote} value={note} onChange={(e) => setNote(e.target.value)} />
                  <div>
                    <Button busy={busy} disabled={countedValue === null} onClick={() => void close()}>
                      {t.closeDay}
                    </Button>
                  </div>
                  <p className="text-[13px] text-text-muted">{t.closeHelp}</p>
                </div>
              ) : (
                <p className="mt-2 text-sm">
                  {t.closedNote(money(d.closed.counted), money(d.closed.expected))} {t.difference(money(d.closed.counted - d.closed.expected))}
                </p>
              )}
            </section>

            {can(context, 'fees.manage') && (
              <section className="grid gap-2.5 border-2 border-ink bg-sheet px-4 py-3.5">
                <h2 className="type-display text-[26px] leading-none">{t.accountant}</h2>
                <p className="text-sm">{t.accountantNote}</p>
                <div className="grid grid-cols-2 gap-2.5">
                  <Input label={t.from} type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
                  <Input label={t.to} type="date" value={to} onChange={(e) => setTo(e.target.value)} />
                </div>
                <a href={feesApi.exportUrl(from, to)} className="inline-flex min-h-11 items-center justify-self-start border-2 border-ink px-4 text-[15px] font-extrabold text-ink no-underline hover:bg-field">
                  {t.download}
                </a>
              </section>
            )}
          </aside>
        </div>
      </div>
    </>
  )
}
