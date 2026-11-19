import { navigate, useLoad } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useState, type ReactNode } from 'react'
import { copy } from '../copy'
import { money } from '../fees/api'
import { day } from '../settings/api'
import { Input } from '../setup/parts'
import { PageHead, SettingsTabs, SmallButton, Tag } from '../settings/parts'
import type { Division } from '../shell/divisions'
import { firstError } from './api'
import { Attention, DivisionChoice, Go, NoDivision, Sheet, td, th, useProgrammeDivision } from './parts'
import { registrationApi, type Desk, type RegState } from './registrationApi'

const t = copy.programmes
const r = t.registration

type Tab = 'not_started' | RegState
const TABS: Tab[] = ['not_started', 'draft', 'submitted', 'approved', 'registered', 'withdrawn']

/** A registration state as a word on its colour, with its mark. */
export function RegTag({ state }: { state: string }) {
  const known = state as RegState
  const tone = known === 'registered' ? 'done' : known === 'submitted' || known === 'approved' ? 'attention' : 'plain'

  return <Tag tone={tone}>{`${r.marks[known] ?? ''}${r.states[known] ?? state}`}</Tag>
}

/** Programmes › Registration (D-098, the approved board): the dates, the states, approval and the start of a semester. */
export function RegistrationDeskScreen() {
  const { division, choices, choose } = useProgrammeDivision()
  const [period, setPeriod] = useState<string | null>(null)

  if (division === null) return <NoDivision />

  return <Loaded key={`${division.id}:${period ?? ''}`} division={division} choices={choices} choose={choose} period={period} setPeriod={setPeriod} />
}

function Loaded({
  division,
  choices,
  choose,
  period,
  setPeriod,
}: {
  division: Division
  choices: Division[]
  choose: (id: string) => void
  period: string | null
  setPeriod: (id: string) => void
}) {
  const loaded = useLoad(`programmes:desk:${division.id}:${period ?? ''}`, () => registrationApi.desk(division.id, period))
  const [tab, setTab] = useState<Tab | null>(null)
  const [selected, setSelected] = useState<string[]>([])
  const [editingDates, setEditingDates] = useState(false)
  const [busy, setBusy] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)

  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={r.title} labels={copy.loading} rows={8} onRetry={loaded.reload} />

  const data = loaded.data
  const current = data.period
  const count = (x: Tab) => (x === 'not_started' ? data.not_started.length : data.rows.filter((row) => row.state === x).length)
  const shownTab: Tab = tab ?? (data.can.approve && count('submitted') > 0 ? 'submitted' : count('draft') > 0 ? 'draft' : 'registered')
  const rows = data.rows.filter((row) => row.state === shownTab)
  const passing = rows.filter((row) => row.problems === 0).map((row) => row.id)
  const canSelect = shownTab === 'submitted' && data.can.approve
  const w = data.window
  const daysLeft = w === null ? 0 : Math.ceil((new Date(w.closes_on).getTime() - Date.now()) / 86400000)

  const run = async (work: () => Promise<{ kind: string; errors?: Record<string, string[]> } & { data?: unknown }>, done: (data: never) => string) => {
    setBusy(true)
    setProblem(null)
    const result = await work()
    setBusy(false)
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: done(result.data as never) })
      setSelected([])
      loaded.reload()
    } else setProblem(firstError(result, t.failed))
  }

  return (
    <>
      <SettingsTabs />
      <PageHead kicker={t.kicker} title={r.title} intro={r.intro} actions={<DivisionChoice choices={choices} division={division} choose={choose} />} />
      <div className="grid gap-4 px-[clamp(16px,3vw,40px)] pb-8">
        {current === null ? (
          <Attention>{t.offerings.noSemester}</Attention>
        ) : (
          <>
            <nav aria-label={r.title} className="flex gap-0 overflow-x-auto border-b border-rule">
              {data.periods.map((x) => (
                <button
                  key={x.id}
                  type="button"
                  aria-current={x.id === current.id ? 'page' : undefined}
                  onClick={() => setPeriod(x.id)}
                  className={`min-h-11 shrink-0 border-b-4 px-4 text-[15px] whitespace-nowrap ${x.id === current.id ? 'border-teal font-extrabold text-ink' : 'border-transparent text-text-muted'}`}
                >
                  {x.name}
                </button>
              ))}
            </nav>

            {problem !== null && (
              <Notice tone="wrong" title={t.notSaved}>
                {problem}
              </Notice>
            )}

            {w !== null && w.started_at === null && data.can.register && (
              <div className="flex flex-wrap items-center justify-between gap-4 border-2 border-marigold bg-marigold-wash px-4 py-3.5">
                <div className="grid max-w-[880px] gap-1">
                  <strong className="text-[17px]">
                    <span aria-hidden="true">! </span>
                    {r.notStartedTitle(current.name)}
                  </strong>
                  <span>{r.notStartedText(data.not_started.length)}</span>
                  <span className="text-sm text-text-muted">{r.notStartedNote(data.inactive)}</span>
                </div>
                <Button
                  busy={busy}
                  onClick={() =>
                    void run(
                      () => registrationApi.start(division.id, current.id),
                      (d: { opened: number; sent: number }) => r.started(d.opened, d.sent),
                    )
                  }
                >
                  {r.start(current.name)}
                </Button>
              </div>
            )}

            {w === null || editingDates ? (
              <DatesForm
                division={division.id}
                period={current.id}
                window={w}
                canEdit={data.can.register}
                onDone={(saved) => {
                  setEditingDates(false)
                  if (saved) loaded.reload()
                }}
              />
            ) : (
              <Sheet className="grid sm:grid-cols-2 lg:grid-cols-4">
                <Cell label={r.opens}>
                  <strong>{day(w.opens_on)}</strong>
                </Cell>
                <Cell label={r.closes}>
                  <strong>{day(w.closes_on)}</strong>
                  {data.phase === 'open' && daysLeft >= 0 ? ` · ${r.daysLeft(daysLeft)}` : ''}
                </Cell>
                <Cell label={r.lateUntil}>
                  <strong>{r.until(day(w.late_until))}</strong> · {r.lateFee(money(w.late_fee))}
                </Cell>
                <div className="flex items-center justify-between gap-2 px-4 py-3">
                  <div className="grid">
                    <span className="label-caps text-text-muted">{r.addDrop}</span>
                    <strong>{r.until(day(w.add_drop_until))}</strong>
                  </div>
                  {data.can.register && <SmallButton onClick={() => setEditingDates(true)}>{r.changeDates}</SmallButton>}
                </div>
              </Sheet>
            )}

            <div role="tablist" aria-label={r.title} className="grid grid-cols-2 border-2 border-ink bg-sheet sm:grid-cols-3 lg:grid-cols-6">
              {TABS.map((x) => (
                <button
                  key={x}
                  type="button"
                  role="tab"
                  aria-selected={x === shownTab}
                  onClick={() => (setTab(x), setSelected([]))}
                  className={`grid border-r border-b border-rule px-4 py-3 text-left last:border-r-0 ${x === shownTab ? 'border-b-4 border-b-ink bg-marigold-wash' : ''}`}
                >
                  <span className={`type-display text-[30px] leading-none ${x === 'registered' ? 'text-teal' : ''}`}>{count(x)}</span>
                  <span className={x === shownTab ? 'text-ink' : 'text-text-muted'}>{`${x === 'not_started' ? '' : r.marks[x]}${r.states[x]}`}</span>
                </button>
              ))}
            </div>

            {(canSelect || (shownTab === 'approved' && data.can.register)) && rows.length > 0 && (
              <div className="flex flex-wrap items-center justify-end gap-2.5">
                {canSelect && (
                  <>
                    <span className="text-text-muted">{r.selected(selected.length)}</span>
                    <SmallButton onClick={() => setSelected(passing)}>{r.selectAll}</SmallButton>
                    <Button
                      busy={busy}
                      disabled={selected.length === 0}
                      onClick={() =>
                        void run(
                          () => registrationApi.approve(division.id, current.id, selected),
                          (d: { approved: number; registered: number }) => r.approvedSlip(d.approved, d.registered),
                        )
                      }
                    >
                      {r.approve(selected.length)}
                    </Button>
                  </>
                )}
                {shownTab === 'approved' && data.can.register && (
                  <Button
                    busy={busy}
                    onClick={() =>
                      void run(
                        () => registrationApi.register(division.id, current.id, null),
                        (d: { registered: number }) => r.registeredSlip(d.registered),
                      )
                    }
                  >
                    {r.registerCleared}
                  </Button>
                )}
              </div>
            )}

            {shownTab === 'not_started' ? (
              <NotStarted data={data} canOpen={data.can.register && w !== null} busy={busy} onOpen={(learner) => void open(division.id, current.id, learner, setProblem)} />
            ) : (
              <Sheet className="overflow-x-auto">
                <table className="w-full min-w-[760px] border-collapse text-[15px]">
                  <thead>
                    <tr>
                      {canSelect && <th className={`${th} w-10`} />}
                      <th className={th}>{r.columns.student}</th>
                      <th className={th}>{r.columns.programme}</th>
                      <th className={th}>{r.columns.year}</th>
                      <th className={th}>{r.columns.courses}</th>
                      <th className={th}>{r.columns.credits}</th>
                      <th className={th}>{shownTab === 'approved' ? r.columns.fees : r.columns.checks}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {rows.length === 0 && (
                      <tr>
                        <td colSpan={7} className={`${td} text-text-muted`}>
                          {r.empty}
                        </td>
                      </tr>
                    )}
                    {rows.map((row) => (
                      <tr key={row.id}>
                        {canSelect && (
                          <td className={td}>
                            <input
                              type="checkbox"
                              aria-label={r.select(row.name)}
                              disabled={row.problems > 0}
                              checked={selected.includes(row.id)}
                              onChange={(e) => setSelected(e.target.checked ? [...selected, row.id] : selected.filter((x) => x !== row.id))}
                              className="h-5 w-5 accent-teal"
                            />
                          </td>
                        )}
                        <td className={td}>
                          <Go to={`/programmes/r/${row.id}`} className="font-extrabold">
                            {row.name}
                          </Go>{' '}
                          <span className="font-mono text-sm text-text-muted">{row.code}</span>
                          {row.late && (
                            <>
                              {' '}
                              <Tag tone="plain">{r.late}</Tag>
                            </>
                          )}
                        </td>
                        <td className={td}>{row.programme}</td>
                        <td className={td}>{row.year}</td>
                        <td className={td}>{row.courses}</td>
                        <td className={td}>{row.credits}</td>
                        <td className={td}>
                          {shownTab === 'approved' ? (
                            row.fees_more > 0 ? (
                              <span>{`! ${money(row.fees_more)}`}</span>
                            ) : (
                              <span className="font-extrabold text-teal">{r.feesCleared}</span>
                            )
                          ) : shownTab === 'draft' || shownTab === 'submitted' ? (
                            row.problems === 0 ? (
                              <span className="font-extrabold text-teal">{r.allPass}</span>
                            ) : (
                              <Tag tone="wrong">{r.problems(row.problems)}</Tag>
                            )
                          ) : (
                            <RegTag state={row.state} />
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </Sheet>
            )}
            <p className="text-sm text-text-muted">{r.note}</p>
          </>
        )}
      </div>
    </>
  )
}

async function open(division: string, period: string, learner: string, fail: (p: string) => void) {
  const result = await registrationApi.open(division, period, learner)
  if (result.kind === 'saved') navigate(`/programmes/r/${result.data.id}`)
  else fail(firstError(result, t.failed))
}

function Cell({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="grid border-r border-b border-rule px-4 py-3 lg:border-b-0">
      <span className="label-caps text-text-muted">{label}</span>
      <span>{children}</span>
    </div>
  )
}

function NotStarted({ data, canOpen, busy, onOpen }: { data: Desk; canOpen: boolean; busy: boolean; onOpen: (learner: string) => void }) {
  return (
    <Sheet className="overflow-x-auto">
      <table className="w-full min-w-[600px] border-collapse text-[15px]">
        <thead>
          <tr>
            <th className={th}>{r.columns.student}</th>
            <th className={th}>{r.columns.programme}</th>
            <th className={th}>{r.columns.year}</th>
            {canOpen && <th className={th} />}
          </tr>
        </thead>
        <tbody>
          {data.not_started.length === 0 && (
            <tr>
              <td colSpan={4} className={`${td} text-text-muted`}>
                {r.empty}
              </td>
            </tr>
          )}
          {data.not_started.map((x) => (
            <tr key={x.learner_id}>
              <td className={td}>
                <strong>{x.name}</strong> <span className="font-mono text-sm text-text-muted">{x.code}</span>
              </td>
              <td className={td}>{x.programme}</td>
              <td className={td}>{x.year}</td>
              {canOpen && (
                <td className={`${td} text-right`}>
                  <SmallButton disabled={busy} onClick={() => onOpen(x.learner_id)}>
                    {r.openOne}
                  </SmallButton>
                </td>
              )}
            </tr>
          ))}
        </tbody>
      </table>
    </Sheet>
  )
}

function DatesForm({
  division,
  period,
  window: w,
  canEdit,
  onDone,
}: {
  division: string
  period: string
  window: Desk['window']
  canEdit: boolean
  onDone: (saved: boolean) => void
}) {
  const [opens, setOpens] = useState(w?.opens_on ?? '')
  const [closes, setCloses] = useState(w?.closes_on ?? '')
  const [late, setLate] = useState(w?.late_until ?? '')
  const [addDrop, setAddDrop] = useState(w?.add_drop_until ?? '')
  const [fee, setFee] = useState(w === null ? '' : String(Math.round(w.late_fee / 100)))
  const [share, setShare] = useState(w === null ? '50' : String(w.fees_share))
  const [busy, setBusy] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)

  if (!canEdit) return <Attention>{r.noDates}</Attention>

  const save = async () => {
    setBusy(true)
    setProblem(null)
    const result = await registrationApi.saveWindow(division, period, {
      opens_on: opens,
      closes_on: closes,
      late_until: late,
      add_drop_until: addDrop,
      late_fee: Number(fee === '' ? '0' : fee) * 100,
      fees_share: Number(share === '' ? '0' : share),
    })
    setBusy(false)
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: r.datesSaved })
      onDone(true)
    } else setProblem(firstError(result, t.failed))
  }

  return (
    <Sheet label={r.setDates} className="grid gap-3 p-4">
      <h2 className="type-display border-b-2 border-ink pb-1.5 text-2xl">{r.setDates}</h2>
      {w === null && <Attention>{r.noDates}</Attention>}
      {problem !== null && (
        <Notice tone="wrong" title={t.notSaved}>
          {problem}
        </Notice>
      )}
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Input label={r.opensOn} type="date" value={opens} onChange={(e) => setOpens(e.target.value)} />
        <Input label={r.closesOn} type="date" value={closes} onChange={(e) => setCloses(e.target.value)} />
        <Input label={r.lateUntilOn} type="date" value={late} onChange={(e) => setLate(e.target.value)} />
        <Input label={r.addDropOn} type="date" value={addDrop} onChange={(e) => setAddDrop(e.target.value)} />
        <Input label={r.lateFeeLabel} hint={r.lateFeeHint} inputMode="numeric" value={fee} onChange={(e) => setFee(e.target.value.replace(/\D/g, ''))} />
        <Input label={r.feesShare} hint={r.feesShareHint} inputMode="numeric" value={share} onChange={(e) => setShare(e.target.value.replace(/\D/g, '').slice(0, 3))} />
      </div>
      <div className="flex flex-wrap gap-2.5">
        <Button busy={busy} disabled={opens === '' || closes === '' || late === '' || addDrop === ''} onClick={() => void save()}>
          {r.saveDates}
        </Button>
        {w !== null && (
          <Button variant="secondary" onClick={() => onDone(false)}>
            {t.cancel}
          </Button>
        )}
      </div>
    </Sheet>
  )
}
