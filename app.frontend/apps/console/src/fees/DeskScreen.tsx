import { can, navigate, useAppContext, useLoad, useLocation } from '@acadly/kernel'
import { Button, DataGrid, NO_SELECTION, slips, viewState, Waiting } from '@acadly/ui'
import { useGridPage, useGridPrefs, useGridQuery, startExport, useGridSelection } from '../grid/useGrid'
import { useState } from 'react'
import { copy } from '../copy'
import { Input } from '../setup/parts'
import { day } from '../settings/api'
import { PageHead, Tag } from '../settings/parts'
import { behindGrid, feesApi, money, parseKwacha, paymentsGrid, today, type BehindRow, type PaymentRow } from './api'
import { gridsApi, type BulkAnswer } from '../grid/api'
import { BulkResult } from '../grid/BulkResult'

const t = copy.fees

/** The fees desk (the approved design): this term's totals, who is behind their plan, today's takings and the day's close. */
export function DeskScreen() {
  const context = useAppContext()
  const loaded = useLoad('fees:desk', () => feesApi.desk())
  const { query } = useLocation()
  const d = loaded.data
  const [counted, setCounted] = useState('')
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)
  const [from, setFrom] = useState(today().slice(0, 8) + '01')
  const [to, setTo] = useState(today())

  if (d === undefined) return <Waiting phase={loaded.phase} what={t.deskTitle} labels={copy.loading} rows={6} onRetry={loaded.reload} />

  const view = query.get('view') === 'behind' ? 'behind' : 'ledger'
  const pct = d.billed > 0 ? Math.round((d.collected / d.billed) * 100) : 0
  const cells = [
    { label: t.collected, value: money(d.collected), note: `${pct}% · ${t.collectedNote(money(d.billed))}`, ink: true },
    { label: t.dueUnpaid, value: money(d.due_unpaid), note: t.dueUnpaidNote(d.behind_count), attention: d.due_unpaid > 0 },
    { label: t.paidInFull, value: String(d.paid_in_full), note: t.paidInFullNote(d.billed_learners) },
    { label: t.creditHeld, value: money(d.credit), note: t.creditHeldNote(d.credit_learners) },
  ]
  const countedValue = parseKwacha(counted)

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

        <div className="grid grid-cols-[minmax(0,1fr)] items-start gap-x-8 gap-y-6 xl:grid-cols-[minmax(0,1fr)_420px]">
          <div className="min-w-0">
            <nav aria-label={t.deskTabs} className="flex gap-1 overflow-x-auto border-b-2 border-ink [scrollbar-width:none]">
              {(['ledger', 'behind'] as const).map((v) => (
                <a
                  key={v}
                  href={v === 'ledger' ? '/fees' : '/fees?view=behind'}
                  aria-current={v === view ? 'page' : undefined}
                  onClick={(e) => (e.preventDefault(), navigate(v === 'ledger' ? '/fees' : '/fees?view=behind'))}
                  className={`inline-flex min-h-11 shrink-0 items-center border-b-4 px-3.5 pt-2.5 pb-2 text-[15px] whitespace-nowrap text-ink no-underline ${v === view ? 'border-teal font-extrabold' : 'border-transparent font-medium hover:bg-paper'}`}
                >
                  {v === 'ledger' ? t.ledgerTab : t.behindTab(d.behind_count)}
                </a>
              ))}
            </nav>
            {view === 'ledger' ? <Ledger /> : <BehindList onReminded={loaded.reload} />}
          </div>

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

/** Who is behind their plan (D-095), on the data grid: choose rows, or every row that matches, and remind the families. */
function BehindList({ onReminded }: { onReminded: () => void }) {
  const [query, setQuery] = useGridQuery('/fees', ['view'])
  const grid = useGridPage('fee-behind', query, behindGrid)
  const [selection, setSelection] = useGridSelection(query)
  const prefs = useGridPrefs('fee-behind', grid.reload)
  const [result, setResult] = useState<BulkAnswer | null>(null)
  const g = t.behindGrid
  const page = grid.page
  const labels = copy.grid.labels({ name: t.behind, word: g.word, plural: g.plural, searchPlaceholder: g.search, columnsNote: g.columnsNote, emptyText: g.empty, noMatchText: g.noMatch })
  const open = (row: BehindRow) => navigate(`/learners/${row.id}?tab=fees`)
  const sort = { sort: page?.sort ?? query.sort, dir: page?.dir ?? query.dir }

  const render = {
    learner: (row: BehindRow) => (
      <>
        <a href={`/learners/${row.id}?tab=fees`} onClick={(e) => (e.preventDefault(), open(row))} className="block font-extrabold text-ink no-underline hover:underline">
          {row.name}
        </a>
      </>
    ),
    class: (row: BehindRow) => <span className="text-sm">{row.class}</span>,
    owed_on: (row: BehindRow) => <span className="text-sm">{row.owed_on}</span>,
    due_now: (row: BehindRow) => <span className="ref font-extrabold whitespace-nowrap">{money(row.due_now)}</span>,
    days: (row: BehindRow) => <Tag tone="attention">! {t.days(row.days)}</Tag>,
  }

  const card = (row: BehindRow) => ({
    title: row.name,
    meta: [row.class, row.owed_on].filter((x) => x !== '').join(' · '),
    aside: <span className="ref font-extrabold">{money(row.due_now)}</span>,
  })

  const bulk = (action: string) => {
    if (action !== 'remind') return
    void gridsApi.bulk('fee-behind', { action, ...(selection.all ? { all: true as const } : { ids: selection.ids }), state: viewState(query, sort), input: {} }).then((r) => {
      if (r.kind === 'saved') {
        setResult(r.data)
        setSelection(NO_SELECTION)
        slips.done({ word: copy.slips.sent, title: g.reminded(r.data.done) })
        onReminded()
      } else slips.wrong({ word: t.notSaved, title: t.failed })
    })
  }

  return (
    <div className="border-2 border-t-0 border-ink bg-sheet">
      <DataGrid
        labels={labels}
        page={page}
        phase={grid.phase}
        query={query}
        onQuery={(next, replace) => {
          setResult(null)
          setQuery(next, replace)
        }}
        onRetry={grid.reload}
        render={render}
        alignEnd={['due_now']}
        card={card}
        rowName={(row) => row.name}
        rowTone={() => null}
        onOpen={open}
        selection={selection}
        onSelection={setSelection}
        onBulk={bulk}
        notice={
          result !== null && (
            <BulkResult
              label={g.remindLabel}
              done={g.reminded(result.done)}
              notDoneCount={g.notReminded(result.refused.length)}
              notDone={g.notRemindedWord}
              line={g.resultLine}
              refused={result.refused}
              close={g.close}
              onClose={() => setResult(null)}
            />
          )
        }
        onColumns={prefs.onColumns}
        views={prefs.views}
        onExport={(scope) => {
          if (page !== undefined) startExport('fee-behind', scope, { page, query, selection, word: g.word, plural: g.plural })
        }}
      />
      <p className="px-4 pt-1 pb-3 text-[13px] text-text-muted">{t.remindNote}</p>
    </div>
  )
}

/** The payments ledger (D-095): every receipt, newest first, on the data grid. A cancelled receipt stays, marked. */
function Ledger() {
  const [query, setQuery] = useGridQuery('/fees', ['view'])
  const grid = useGridPage('fee-payments', query, paymentsGrid)
  const [selection, setSelection] = useGridSelection(query)
  const prefs = useGridPrefs('fee-payments', grid.reload)
  const l = t.ledger
  const page = grid.page
  const labels = copy.grid.labels({ name: t.ledgerTab, word: l.word, plural: l.plural, searchPlaceholder: l.search, columnsNote: l.columnsNote, emptyText: l.empty, noMatchText: l.noMatch })
  const open = (row: PaymentRow) => navigate(`/fees/receipts/${row.id}`)

  const render = {
    receipt: (row: PaymentRow) => (
      <a href={`/fees/receipts/${row.id}`} onClick={(e) => (e.preventDefault(), open(row))} className={`ref font-extrabold text-ink no-underline hover:underline ${row.cancelled ? 'line-through' : ''}`}>
        {row.receipt}
      </a>
    ),
    received_on: (row: PaymentRow) => <span className="ref text-[13px]">{day(row.received_on)}</span>,
    learners: (row: PaymentRow) => <span className="text-sm">{row.learners}</span>,
    payer: (row: PaymentRow) => <span className="text-sm">{row.payer}</span>,
    method: (row: PaymentRow) => (
      <span className="text-sm">
        {row.method_label}
        {row.reference !== null && <span className="ref block text-xs text-text-muted">{row.reference}</span>}
      </span>
    ),
    amount: (row: PaymentRow) => <span className={`ref font-extrabold whitespace-nowrap ${row.cancelled ? 'text-text-muted line-through' : ''}`}>{money(row.amount)}</span>,
    received_by: (row: PaymentRow) => <span className="text-sm">{row.received_by ?? ''}</span>,
    state: (row: PaymentRow) => (row.cancelled ? <Tag tone="wrong">✕ {l.cancelled}</Tag> : <Tag tone="done">✓ {l.counted}</Tag>),
  }

  const card = (row: PaymentRow) => ({
    title: <span className="ref">{row.receipt}</span>,
    meta: [day(row.received_on), row.learners, row.method_label].filter((x) => x !== '').join(' · '),
    aside: <span className={`ref font-extrabold ${row.cancelled ? 'line-through' : ''}`}>{money(row.amount)}</span>,
  })

  return (
    <div className="border-2 border-t-0 border-ink bg-sheet">
      <DataGrid
        labels={labels}
        page={page}
        phase={grid.phase}
        query={query}
        onQuery={setQuery}
        onRetry={grid.reload}
        render={render}
        alignEnd={['amount']}
        card={card}
        rowName={(row) => row.receipt}
        rowTone={(row) => (row.cancelled ? 'wrong' : null)}
        onOpen={open}
        selection={selection}
        onSelection={setSelection}
        onBulk={() => undefined}
        onColumns={prefs.onColumns}
        views={prefs.views}
        onExport={(scope) => {
          if (page !== undefined) startExport('fee-payments', scope, { page, query, selection, word: l.word, plural: l.plural })
        }}
      />
    </div>
  )
}
