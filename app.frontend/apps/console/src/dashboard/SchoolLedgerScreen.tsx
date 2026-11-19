import { api, navigate, useLoad } from '@acadly/kernel'
import { Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { money } from '../charts/format'
import { useDivisionColour, usePalette } from '../charts/palette'
import { day } from '../settings/api'
import { read } from '../setup/api'
import { useWorkIn } from '../shell/WorkIn'

const t = copy.schoolLists

/**
 * The fees ledger of the whole school, read-only (D-100): money received,
 * grouped by finance office and then by division. A receipt opens in its
 * division.
 */
export function SchoolLedgerScreen() {
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const { enter } = useWorkIn()
  const p = usePalette()
  const colour = useDivisionColour()
  const query = { ...(from !== '' ? { from } : {}), ...(to !== '' ? { to } : {}) }
  const loaded = useLoad(`school:ledger:${from}:${to}`, () => read(api.GET('/v1/fees/ledger', { params: { query } })))
  const data = loaded.data

  return (
    <div className="grid gap-3 px-[clamp(16px,3vw,40px)] pt-6 pb-8">
      <div>
        <div className="label-caps text-text-muted">{t.ledgerKicker}</div>
        <h1 className="type-display mt-1 text-[clamp(48px,6vw,88px)] leading-[0.86]">{t.ledgerTitle}</h1>
      </div>
      <p className="flex flex-wrap gap-2 border-2 border-ink bg-sheet px-3.5 py-2.5 text-[14px]">
        <b>{t.readOnly}</b>
        <span>{t.ledgerIntro}</span>
      </p>
      <div className="flex flex-wrap items-end gap-3">
        <label className="grid gap-1">
          <span className="label-caps text-text-muted">{t.from}</span>
          <input type="date" value={from || (data?.from ?? '')} onChange={(e) => setFrom(e.target.value)} className="h-11 border-b-2 border-ink bg-field px-3" />
        </label>
        <label className="grid gap-1">
          <span className="label-caps text-text-muted">{t.to}</span>
          <input type="date" value={to || (data?.to ?? '')} onChange={(e) => setTo(e.target.value)} className="h-11 border-b-2 border-ink bg-field px-3" />
        </label>
        {data !== undefined && (
          <span className="ml-auto text-right">
            <span className="label-caps block text-text-muted">{t.total}</span>
            <span className="type-numeral text-[40px]">{money(data.total)}</span>
          </span>
        )}
      </div>

      {data === undefined ? (
        <Waiting phase={loaded.phase} what={t.ledgerTitle} labels={copy.loading} rows={8} onRetry={loaded.reload} />
      ) : data.offices.length === 0 ? (
        <p className="text-text-muted">{t.noPayments}</p>
      ) : (
        data.offices.map((o) => (
          <section key={o.office_id} aria-labelledby={`office-${o.office_id}`} className="border-2 border-ink bg-sheet">
            <header className="flex items-baseline gap-3 border-b-2 border-ink px-4 py-2.5">
              <h2 id={`office-${o.office_id}`} className="flex-1 text-[20px] font-extrabold">
                {o.office}
              </h2>
              <span className="font-extrabold tabular-nums">{money(o.total)}</span>
            </header>
            <div className="overflow-x-auto">
              <table className="w-full border-collapse text-[14px]">
                <thead>
                  <tr>
                    {[t.receipt, t.day, t.payer, t.for, t.campus, t.method, t.amount].map((h) => (
                      <th key={h} scope="col" className={`label-caps border-b-2 border-ink px-3 py-2 text-text-muted ${h === t.amount ? 'text-right' : 'text-left'}`}>
                        {h}
                      </th>
                    ))}
                  </tr>
                </thead>
                {o.divisions.map((g) => (
                  <tbody key={g.division_id}>
                    <tr>
                      <th colSpan={6} scope="rowgroup" className="border-b border-rule bg-field px-3 py-2 text-left">
                        <span aria-hidden="true" className="mr-2 inline-block size-3 align-[-1px]" style={{ background: colour(g.division_id, p) }} />
                        <b>{g.division}</b>
                      </th>
                      <td className="border-b border-rule bg-field px-3 py-2 text-right font-extrabold tabular-nums">{money(g.total)}</td>
                    </tr>
                    {g.rows.map((r) => (
                      <tr key={r.payment_id} className={r.cancelled ? 'text-text-muted line-through' : ''}>
                        <td className="border-b border-rule px-3 py-2 font-mono text-[13px]">
                          <a href={`/fees/receipts/${r.payment_id}`} onClick={(e) => (e.preventDefault(), enter(g.division_id), navigate(`/fees/receipts/${r.payment_id}`))} className="text-ink">
                            {r.number}
                          </a>
                        </td>
                        <td className="border-b border-rule px-3 py-2 whitespace-nowrap">{day(r.received_on)}</td>
                        <td className="border-b border-rule px-3 py-2">{r.payer}</td>
                        <td className="border-b border-rule px-3 py-2">{r.learners}</td>
                        <td className="border-b border-rule px-3 py-2">{r.campus}</td>
                        <td className="border-b border-rule px-3 py-2">{r.cancelled ? `${r.method} · ${t.cancelled}` : r.method}</td>
                        <td className="border-b border-rule px-3 py-2 text-right tabular-nums">{money(r.amount)}</td>
                      </tr>
                    ))}
                  </tbody>
                ))}
              </table>
            </div>
          </section>
        ))
      )}
    </div>
  )
}
