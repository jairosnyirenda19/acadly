import { useLoad } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { firstError } from '../programmes/api'
import { Input, Select } from '../setup/parts'
import { day, resultsApi, type BoardRules } from './api'
import { Panel } from './parts'

const t = copy.results
const r = t.rules

type Bands = { name: string; from: number }[]

/** The board's rules (D-106): standing, carried credits, attempts, the time limit, classes of award and the certificate. */
export function RulesPanel({ division }: { division: string }) {
  const loaded = useLoad(`results-rules:${division}`, () => resultsApi.rules(division))
  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={r.title} labels={copy.loading} rows={4} onRetry={loaded.reload} />

  return <Rules key={loaded.data.rules.id ?? 'standard'} division={division} data={loaded.data} reload={loaded.reload} />
}

function Rules({ division, data, reload }: { division: string; data: BoardRules; reload: () => void }) {
  const rules = data.rules
  const [open, setOpen] = useState(false)
  const [period, setPeriod] = useState(data.periods[data.periods.length - 1]?.id ?? '')
  const [good, setGood] = useState(rules.good_cgpa.toFixed(2))
  const [carry, setCarry] = useState(String(rules.carry_credits))
  const [attempts, setAttempts] = useState(String(rules.max_attempts))
  const [time, setTime] = useState(String(rules.time_factor))
  const [hold, setHold] = useState(rules.hold_certificate)
  const [diploma, setDiploma] = useState<Bands>(rules.classes.diploma.map((c) => ({ ...c })))
  const [degree, setDegree] = useState<Bands>(rules.classes.degree.map((c) => ({ ...c })))
  const [busy, setBusy] = useState(false)

  const list = (label: string, bands: Bands, set: (b: Bands) => void) => (
    <div className="grid content-start gap-2">
      <h3 className="font-extrabold">{label}</h3>
      {bands.map((b, i) => (
        <div key={i} className="flex flex-wrap items-end gap-2">
          <Input label={r.className} value={b.name} disabled={!open} maxLength={24} onChange={(e) => set(bands.map((x, j) => (j === i ? { ...x, name: e.target.value } : x)))} />
          <Input label={r.from} inputMode="decimal" value={String(b.from)} disabled={!open} onChange={(e) => set(bands.map((x, j) => (j === i ? { ...x, from: Number(e.target.value) } : x)))} />
          {open && (
            <button type="button" className="min-h-10 underline" onClick={() => set(bands.filter((_, j) => j !== i))}>
              {r.remove}
            </button>
          )}
        </div>
      ))}
      {open && (
        <button type="button" className="justify-self-start underline" onClick={() => set([...bands, { name: '', from: 2 }])}>
          {r.addClass}
        </button>
      )}
    </div>
  )

  return (
    <Panel title={r.title}>
      <div className="grid gap-4">
        <p className="text-[14px] text-text-muted">{r.lede}</p>
        <Notice tone="done" title={r.inUse(rules.from_on === null ? null : day(rules.from_on))} />
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Input label={r.goodCgpa} hint={r.goodHelp} inputMode="decimal" value={good} disabled={!open} onChange={(e) => setGood(e.target.value)} />
          <Input label={r.carry} inputMode="numeric" value={carry} disabled={!open} onChange={(e) => setCarry(e.target.value)} />
          <Input label={r.attempts} inputMode="numeric" value={attempts} disabled={!open} onChange={(e) => setAttempts(e.target.value)} />
          <Input label={r.time} inputMode="numeric" value={time} disabled={!open} onChange={(e) => setTime(e.target.value)} />
        </div>
        <div className="grid gap-4 md:grid-cols-2">
          {list(r.diploma, diploma, setDiploma)}
          {list(r.degree, degree, setDegree)}
        </div>
        <label className="inline-flex items-center gap-2">
          <input type="checkbox" checked={hold} disabled={!open} onChange={(e) => setHold(e.target.checked)} />
          {r.holdCertificate}
        </label>
        <p className="text-[14px] text-text-muted">{r.holdHelp}</p>
        {!data.can.setup ? (
          <p className="text-[14px] text-text-muted">{r.readOnly}</p>
        ) : !open ? (
          <div>
            <Button variant="secondary" onClick={() => setOpen(true)}>
              {r.newRules}
            </Button>
          </div>
        ) : (
          <div className="flex flex-wrap items-end gap-3">
            <Select label={r.fromPeriod} value={period} onChange={(e) => setPeriod(e.target.value)}>
              {data.periods.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </Select>
            <Button
              busy={busy}
              onClick={async () => {
                setBusy(true)
                const result = await resultsApi.saveRules(division, {
                  period_id: period,
                  good_cgpa: Number(good),
                  carry_credits: Number(carry),
                  max_attempts: Number(attempts),
                  time_factor: Number(time),
                  hold_certificate: hold,
                  classes: { diploma, degree },
                })
                setBusy(false)
                if (result.kind === 'saved') {
                  slips.done({ word: copy.slips.saved, title: r.saved })
                  setOpen(false)
                  reload()
                } else slips.wrong({ word: t.notSaved, title: firstError(result, t.failed) })
              }}
            >
              {r.save}
            </Button>
          </div>
        )}
      </div>
    </Panel>
  )
}
