import { useLoad } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { firstError } from '../programmes/api'
import { Sheet } from '../programmes/parts'
import { PageHead, Tag } from '../settings/parts'
import { Input, Select } from '../setup/parts'
import { day, resultsApi, useResultsDivision, type Band, type ReleaseView, type ResultsSetup, type Sitting } from './api'
import { NoResultsDivision, Panel, ResultsTabs } from './parts'

const t = copy.results

const th = 'label-caps border-b-2 border-ink px-3 py-2 text-left text-text-muted'
const td = 'border-b border-rule px-3 py-2 align-middle'

async function done(result: { kind: string; errors?: Record<string, string[]> }, title: string): Promise<boolean> {
  if (result.kind === 'saved') {
    slips.done({ word: copy.slips.saved, title })

    return true
  }
  slips.wrong({ word: t.notSaved, title: firstError(result, t.failed) })

  return false
}

/** Release (D-105): approved results go to students all at once; then the supplementary sitting. */
export function ReleaseScreen() {
  const { division } = useResultsDivision()
  if (division === null) return <NoResultsDivision />

  return <Release key={division.id} division={division.id} />
}

function Release({ division }: { division: string }) {
  const r = t.release
  const loaded = useLoad(`results-release:${division}`, () => resultsApi.release(division, null))
  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={r.title} labels={copy.loading} rows={6} onRetry={loaded.reload} />
  const data = loaded.data

  return (
    <>
      <PageHead kicker={`${t.kicker} · ${data.period.name}`} title={r.title} intro={r.lede} />
      <div className="grid gap-4 px-[clamp(16px,3vw,40px)] pb-8">
        <ResultsTabs current="release" can={data.can} />
        {data.sittings
          .filter((x) => x.sitting === 'main' || data.supplementary.sheets > 0)
          .map((x) => (
            <SittingPanel key={x.sitting} division={division} data={data} sitting={x.sitting as Sitting} reload={loaded.reload} />
          ))}
        <Supplementary division={division} data={data} reload={loaded.reload} />
        <Panel title={r.history}>
          {data.releases.length === 0 ? (
            <p className="text-[14px] text-text-muted">{r.none}</p>
          ) : (
            <ul className="grid gap-2">
              {data.releases.map((x, i) => (
                <li key={i} className="text-[14px]">
                  <Tag tone="done">{t.stateTags['released']}</Tag> {r.historyRow(t.sittings[x.sitting] ?? x.sitting, x.sheets, x.by, day(x.at), x.sms)}
                </li>
              ))}
            </ul>
          )}
        </Panel>
        <Panel title={r.changes}>
          <p className="text-[14px] text-text-muted">{r.changesLede}</p>
        </Panel>
        <p className="text-[14px] text-text-muted">{r.needs}</p>
      </div>
    </>
  )
}

function SittingPanel({ division, data, sitting, reload }: { division: string; data: ReleaseView; sitting: Sitting; reload: () => void }) {
  const r = t.release
  const info = data.sittings.find((x) => x.sitting === sitting)
  const [sms, setSms] = useState(true)
  const [busy, setBusy] = useState(false)
  if (info === undefined) return null
  const approved = info.states['approved'] ?? 0

  return (
    <Panel title={t.sittings[sitting] ?? sitting}>
      <div className="grid gap-4">
        <div className="flex flex-wrap gap-2">
          {Object.entries(info.states).map(([state, n]) => (
            <Tag key={state} tone={state === 'approved' || state === 'released' ? 'done' : 'attention'}>{`${t.stateTags[state] ?? state} · ${n}`}</Tag>
          ))}
        </div>
        {info.waiting.length === 0 ? (
          <p className="text-[14px]">{r.allApproved}</p>
        ) : (
          <div>
            <h3 className="font-extrabold">{r.waitingList}</h3>
            <ul className="mt-1 grid gap-1">
              {info.waiting.map((w) => (
                <li key={w.code} className="text-[14px]">{`${w.code} · ${w.title} · ${t.states[w.state] ?? w.state}`}</li>
              ))}
            </ul>
          </div>
        )}
        {data.can.release &&
          (approved === 0 ? (
            <p className="text-[14px] text-text-muted">{r.nothing}</p>
          ) : (
            <div className="flex flex-wrap items-center gap-4">
              <label className="inline-flex items-center gap-2">
                <input type="checkbox" checked={sms} onChange={(e) => setSms(e.target.checked)} />
                {r.sms}
              </label>
              <Button
                busy={busy}
                onClick={async () => {
                  setBusy(true)
                  const result = await resultsApi.releaseNow(division, data.period.id, sitting, sms)
                  setBusy(false)
                  if (await done(result, result.kind === 'saved' ? r.released((result.data as { released: number }).released) : '')) reload()
                }}
              >
                {r.now(approved)}
              </Button>
            </div>
          ))}
      </div>
    </Panel>
  )
}

function Supplementary({ division, data, reload }: { division: string; data: ReleaseView; reload: () => void }) {
  const r = t.release
  const [examOn, setExamOn] = useState('')
  const [busy, setBusy] = useState(false)

  return (
    <Panel title={r.supplementary}>
      <p className="text-[14px] text-text-muted">{r.suppLede}</p>
      {data.supplementary.sheets > 0 && (
        <p className="mt-2">
          <Tag tone="plain">{r.suppState(data.supplementary.sheets, data.supplementary.candidates)}</Tag>
          {data.supplementary.exam_on !== null && ` · ${day(data.supplementary.exam_on)}`}
        </p>
      )}
      {data.can.release && (
        <div className="mt-3 flex flex-wrap items-end gap-3">
          <Input label={r.suppDate} type="date" value={examOn} onChange={(e) => setExamOn(e.target.value)} />
          <Button
            variant="secondary"
            busy={busy}
            onClick={async () => {
              setBusy(true)
              const result = await resultsApi.supplementary(division, data.period.id, examOn === '' ? null : examOn)
              setBusy(false)
              if (await done(result, result.kind === 'saved' ? r.suppOpened((result.data as { opened: number }).opened) : '')) reload()
            }}
          >
            {r.suppOpen}
          </Button>
        </div>
      )}
    </Panel>
  )
}

/** The grading scale and the results settings (D-105). */
export function SetupScreen() {
  const { division } = useResultsDivision()
  if (division === null) return <NoResultsDivision />

  return <Setup key={division.id} division={division.id} />
}

function Setup({ division }: { division: string }) {
  const s = t.setup
  const loaded = useLoad(`results-setup:${division}`, () => resultsApi.setup(division))
  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={s.title} labels={copy.loading} rows={8} onRetry={loaded.reload} />
  const data = loaded.data

  return (
    <>
      <PageHead kicker={t.kicker} title={s.title} intro={s.lede} />
      <div className="grid gap-4 px-[clamp(16px,3vw,40px)] pb-8">
        <ResultsTabs current="setup" can={data.can} />
        <Notice tone="done" title={s.inUse(data.scale.from_on === null ? null : day(data.scale.from_on))}>
          {s.inUseHelp}
        </Notice>
        <div className="grid gap-4 lg:grid-cols-[3fr_2fr]">
          <Sheet label={data.scale.label} className="overflow-x-auto">
            <table className="w-full border-collapse">
              <thead>
                <tr>
                  <th className={th}>{s.grade}</th>
                  <th className={th}>{s.from}</th>
                  <th className={th}>{s.to}</th>
                  <th className={th}>{s.points}</th>
                  <th className={th} />
                </tr>
              </thead>
              <tbody>
                {data.scale.bands.map((b) => (
                  <tr key={b.grade}>
                    <td className={`${td} font-extrabold`}>{b.grade}</td>
                    <td className={`${td} tabular-nums`}>{b.min}</td>
                    <td className={`${td} tabular-nums`}>{b.max}</td>
                    <td className={`${td} tabular-nums`}>{b.points.toFixed(1)}</td>
                    <td className={td}>{b.min >= data.scale.pass_mark ? <Tag tone="done">{s.passTag}</Tag> : <Tag tone="wrong">{s.failTag}</Tag>}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            <p className="px-3 py-2 text-[14px] text-text-muted">{s.whole}</p>
          </Sheet>
          <div className="grid content-start gap-4">
            <Panel title={s.gpa}>
              <p className="text-[14px]">{s.gpaSemester}</p>
              <p className="mt-2 text-[14px]">{s.gpaCumulative}</p>
            </Panel>
            <Panel title={s.shares}>
              <p className="text-[14px]">{s.sharesHelp}</p>
            </Panel>
            <Panel title={s.supplementary}>
              <p className="text-[14px]">{s.suppHelp}</p>
            </Panel>
          </div>
        </div>
        <Settings division={division} data={data} reload={loaded.reload} />
        {data.can.setup ? <NewScale division={division} data={data} reload={loaded.reload} /> : <p className="text-[14px] text-text-muted">{s.readOnly}</p>}
      </div>
    </>
  )
}

function Settings({ division, data, reload }: { division: string; data: ResultsSetup; reload: () => void }) {
  const s = t.setup
  const [percent, setPercent] = useState(String(data.settings.bar_percent))
  const [days, setDays] = useState(String(data.settings.bar_days))
  const [hold, setHold] = useState(data.settings.hold_slip_for_fees)
  const [busy, setBusy] = useState(false)

  return (
    <Panel title={s.bar}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Input label={s.barPercent} inputMode="numeric" value={percent} disabled={!data.can.setup} onChange={(e) => setPercent(e.target.value)} />
        <Input label={s.barDays} inputMode="numeric" value={days} disabled={!data.can.setup} onChange={(e) => setDays(e.target.value)} />
      </div>
      <p className="mt-2 text-[14px] text-text-muted">{s.barHelp}</p>
      <label className="mt-3 inline-flex items-center gap-2">
        <input type="checkbox" checked={hold} disabled={!data.can.setup} onChange={(e) => setHold(e.target.checked)} />
        {s.holdSlip}
      </label>
      <p className="text-[14px] text-text-muted">{s.holdHelp}</p>
      {data.can.setup && (
        <div className="mt-3">
          <Button
            busy={busy}
            onClick={async () => {
              setBusy(true)
              if (await done(await resultsApi.saveSettings(division, { bar_percent: Number(percent), bar_days: Number(days), hold_slip_for_fees: hold }), s.settingsSaved)) reload()
              setBusy(false)
            }}
          >
            {s.saveSettings}
          </Button>
        </div>
      )}
    </Panel>
  )
}

function NewScale({ division, data, reload }: { division: string; data: ResultsSetup; reload: () => void }) {
  const s = t.setup
  const [open, setOpen] = useState(false)
  const [label, setLabel] = useState('')
  const [period, setPeriod] = useState(data.periods[data.periods.length - 1]?.id ?? '')
  const [pass, setPass] = useState(String(data.scale.pass_mark))
  const [bands, setBands] = useState<Band[]>(data.scale.bands.map((b) => ({ ...b })))
  const [busy, setBusy] = useState(false)
  if (!open) {
    return (
      <div>
        <Button variant="secondary" onClick={() => setOpen(true)}>
          {s.newScale}
        </Button>
      </div>
    )
  }
  const set = (i: number, k: keyof Band, v: string) => setBands((x) => x.map((b, j) => (j === i ? { ...b, [k]: k === 'grade' ? v : Number(v) } : b)))

  return (
    <Panel title={s.newScale}>
      <div className="grid gap-3 sm:grid-cols-3">
        <Input label={s.label} value={label} maxLength={80} onChange={(e) => setLabel(e.target.value)} />
        <Select label={s.fromPeriod} value={period} onChange={(e) => setPeriod(e.target.value)}>
          {data.periods.map((p) => (
            <option key={p.id} value={p.id}>
              {p.name}
            </option>
          ))}
        </Select>
        <Input label={s.passMark} inputMode="numeric" value={pass} onChange={(e) => setPass(e.target.value)} />
      </div>
      <table className="mt-4 w-full border-collapse">
        <thead>
          <tr>
            <th className={th}>{s.grade}</th>
            <th className={th}>{s.from}</th>
            <th className={th}>{s.to}</th>
            <th className={th}>{s.points}</th>
            <th className={th} />
          </tr>
        </thead>
        <tbody>
          {bands.map((b, i) => (
            <tr key={i}>
              {(['grade', 'min', 'max', 'points'] as const).map((k) => (
                <td key={k} className={td}>
                  <input
                    aria-label={`${s[k === 'min' ? 'from' : k === 'max' ? 'to' : k === 'grade' ? 'grade' : 'points']} · ${i + 1}`}
                    value={String(b[k])}
                    onChange={(e) => set(i, k, e.target.value)}
                    className="h-10 w-20 border-b-2 border-ink bg-field px-2 tabular-nums"
                  />
                </td>
              ))}
              <td className={td}>
                <button type="button" className="underline" onClick={() => setBands((x) => x.filter((_, j) => j !== i))}>
                  {s.removeBand}
                </button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      <div className="mt-3 flex flex-wrap gap-2">
        <Button variant="secondary" onClick={() => setBands((x) => [...x, { grade: '', min: 0, max: 0, points: 0 }])}>
          {s.addBand}
        </Button>
        <Button
          busy={busy}
          onClick={async () => {
            setBusy(true)
            if (await done(await resultsApi.saveScale(division, { period_id: period, label, pass_mark: Number(pass), bands }), s.scaleSaved)) {
              setOpen(false)
              reload()
            }
            setBusy(false)
          }}
        >
          {s.saveScale}
        </Button>
      </div>
    </Panel>
  )
}
