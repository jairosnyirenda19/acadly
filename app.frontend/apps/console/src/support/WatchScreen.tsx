import { navigate, useLoad } from '@acadly/kernel'
import { Button, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { today } from '../fees/api'
import { Input, Select, TextArea } from '../setup/parts'
import { day } from '../settings/api'
import { PageHead, Tag } from '../settings/parts'
import { supportApi, type WatchRow } from './api'

const t = copy.support

/** A signal's source, as an outlined word. */
export function SourceTag({ label }: { label: string }) {
  return <span className="label-caps border border-ink px-1.5 py-px whitespace-nowrap text-ink">{label}</span>
}

/** Learners to watch (the approved design): signals from every module that is on, each with its reason. */
export function WatchScreen() {
  const loaded = useLoad('support:watch', () => supportApi.watch())
  const [source, setSource] = useState('')
  const [onlyWithout, setOnlyWithout] = useState(false)
  const data = loaded.data

  if (data === undefined) return <Waiting phase={loaded.phase} what={t.watchTitle} labels={copy.loading} rows={6} onRetry={loaded.reload} />

  const rows = data.learners.filter((r) => (source === '' || r.reasons.some((x) => x.source === source)) && (!onlyWithout || (r.plan === null && r.set_aside === null)))
  const cells = [
    { label: t.toWatch, value: data.totals.watch, note: t.toWatchNote, ink: true },
    { label: t.withPlan, value: data.totals.with_plan, note: t.withPlanNote },
    { label: t.withoutPlan, value: data.totals.without_plan, note: t.withoutPlanNote, attention: data.totals.without_plan > 0 },
    { label: t.reviewsDue, value: data.totals.reviews_due, note: t.reviewsDueNote, attention: data.totals.reviews_due > 0 },
  ]

  return (
    <>
      <PageHead kicker={t.watchKicker} title={t.watchTitle} intro={t.watchIntro} />
      <div className="grid gap-5 px-[clamp(16px,3vw,40px)] pb-8">
        <div className="grid grid-cols-2 border-2 border-ink lg:grid-cols-4">
          {cells.map((c, i) => (
            <section key={c.label} className={`min-w-0 px-3.5 pt-2.5 pb-3 ${c.ink === true ? 'bg-ink text-sheet' : c.attention === true ? 'bg-marigold-wash' : 'bg-sheet'} ${i > 0 ? 'border-ink lg:border-l-2 max-lg:[&:nth-child(even)]:border-l-2 max-lg:[&:nth-child(n+3)]:border-t-2' : ''}`}>
              <h3 className={`label-caps ${c.ink === true ? 'text-on-ink-muted' : 'text-text-muted'}`}>{c.label}</h3>
              <div className={`type-numeral text-[clamp(30px,3vw,40px)] ${c.ink === true ? 'text-teal-bright' : ''}`}>{c.value}</div>
              <p className={`text-[13px] ${c.ink === true ? 'text-on-ink-soft' : 'text-text-muted'}`}>{c.note}</p>
            </section>
          ))}
        </div>

        <div className="flex flex-wrap items-end gap-3">
          <div className="min-w-[220px]">
            <Select label={t.source} value={source} onChange={(e) => setSource(e.target.value)}>
              <option value="">{t.everySource}</option>
              {data.sources.map((s) => (
                <option key={s.key} value={s.key}>
                  {s.label}
                </option>
              ))}
            </Select>
          </div>
          <label className="flex min-h-11 items-center gap-2 text-[15px]">
            <input type="checkbox" className="size-5 accent-ink" checked={onlyWithout} onChange={(e) => setOnlyWithout(e.target.checked)} />
            {t.onlyWithout}
          </label>
        </div>

        <section aria-label={t.watchTitle} className="border-2 border-ink bg-sheet px-4 pt-2 pb-4">
          <div className="label-caps hidden grid-cols-[200px_minmax(0,1fr)_200px_220px] gap-3.5 border-b-2 border-ink py-2 text-text-muted lg:grid">
            <span>{t.cols.learner}</span>
            <span>{t.cols.why}</span>
            <span>{t.cols.support}</span>
            <span />
          </div>
          {rows.length === 0 && <p className="py-3 text-sm text-text-muted">{t.nobody}</p>}
          <ul>
            {rows.map((r) => (
              <Row key={r.learner_id} row={r} onChanged={loaded.reload} />
            ))}
          </ul>
          <p className="mt-2.5 text-[13px] text-text-muted">{t.setAsideNote}</p>
        </section>
      </div>
    </>
  )
}

function Row({ row: r, onChanged }: { row: WatchRow; onChanged: () => void }) {
  const [aside, setAside] = useState(false)
  const [until, setUntil] = useState(today())
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)

  const save = async () => {
    setBusy(true)
    const result = await supportApi.setAside(r.learner_id, until, reason.trim())
    setBusy(false)
    if (result.kind === 'saved') {
      setAside(false)
      slips.done({ word: copy.slips.saved, title: t.saved })
      onChanged()
    } else slips.wrong({ word: t.notSaved, title: result.kind === 'invalid' ? (Object.values(result.errors)[0]?.[0] ?? t.failed) : t.failed })
  }

  return (
    <li className="grid gap-x-3.5 gap-y-2 border-b border-rule py-3 lg:grid-cols-[200px_minmax(0,1fr)_200px_220px]">
      <a href={`/learners/${r.learner_id}?tab=support`} onClick={(e) => (e.preventDefault(), navigate(`/learners/${r.learner_id}?tab=support`))} className="text-ink no-underline">
        <b className="block text-[15px] hover:underline">{r.name}</b>
        <span className="text-xs text-text-muted">{r.class}</span>
      </a>
      <ul className="grid gap-1.5">
        {r.reasons.map((x, i) => (
          <li key={i} className="flex flex-wrap items-baseline gap-2 text-sm">
            <SourceTag label={x.label} />
            <span>{x.text}</span>
          </li>
        ))}
      </ul>
      <div className="text-[13px]">
        {r.plan !== null ? (
          <>
            <Tag tone="attention">! {r.plan.state_label}</Tag>
            <div className="text-text-muted">{t.planLine(r.plan.lead, day(r.plan.next_review_on))}</div>
          </>
        ) : r.set_aside !== null ? (
          <>
            <Tag tone="plain">{t.setAsideTag}</Tag>
            <div className="text-text-muted">{t.setAsideLine(r.set_aside.reason, day(r.set_aside.until))}</div>
          </>
        ) : (
          <Tag tone="plain">{t.noPlan}</Tag>
        )}
      </div>
      <div className="flex flex-wrap gap-1.5 lg:justify-end">
        {r.plan !== null ? (
          <Button variant="secondary" onClick={() => navigate(`/support/plans/${r.plan?.id ?? ''}`)}>
            {t.openThePlan}
          </Button>
        ) : (
          r.can_lead && (
            <>
              <Button variant="secondary" onClick={() => navigate(`/support/plans/new?learner=${r.learner_id}`)}>
                {t.openPlan}
              </Button>
              {r.set_aside === null && (
                <Button variant="secondary" onClick={() => setAside(!aside)}>
                  {t.setAside}
                </Button>
              )}
            </>
          )
        )}
      </div>
      {aside && (
        <div className="grid gap-2 lg:col-span-full lg:max-w-[560px]">
          <Input label={t.setAsideUntil} type="date" value={until} min={today()} onChange={(e) => setUntil(e.target.value)} />
          <TextArea label={t.setAsideReason} value={reason} onChange={(e) => setReason(e.target.value)} required />
          <div className="flex gap-2">
            <Button busy={busy} disabled={reason.trim() === '' || until <= today()} onClick={() => void save()}>
              {t.setAside}
            </Button>
            <Button variant="secondary" onClick={() => setAside(false)}>
              {t.cancel}
            </Button>
          </div>
        </div>
      )}
    </li>
  )
}
