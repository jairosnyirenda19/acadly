import { navigate, useLoad } from '@acadly/kernel'
import { Button, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { conductApi, type ConductEntry, type LearnerConduct } from '../../conduct/api'
import { copy } from '../../copy'
import { TextArea } from '../../setup/parts'
import { day } from '../../settings/api'
import { Tag } from '../../settings/parts'
import type { LearnerState } from '../api'
import { Caption, Column, Columns, InLink, SectionTitle, tabHref } from './ui'

const t = copy.conduct

function useConduct(id: string) {
  return useLoad(`learner:conduct:${id}`, () => conductApi.learner(id))
}

const titleOf = (learner: LearnerState) => learner.parts.find((p) => p.key === 'conduct')?.title ?? ''

/** An entry's kind as a word on a colour: a commendation is teal, a level its own mark. */
export function EntryTag({ entry }: { entry: { commendation: boolean; level: string | null } }) {
  if (entry.commendation) return <Tag tone="done">✓ {t.commendation}</Tag>
  if (entry.level === 'serious') return <Tag tone="wrong">✕ {t.levels.serious}</Tag>
  if (entry.level === 'moderate') return <Tag tone="attention">! {t.levels.moderate}</Tag>

  return <Tag tone="plain">{t.levels[entry.level ?? 'minor'] ?? entry.level}</Tag>
}

/** The overview's Conduct block: this term's counts and why to watch. */
export function ConductSummary({ learner }: { learner: LearnerState }) {
  const loaded = useConduct(learner.id)
  const data = loaded.data
  const now = data?.terms.find((x) => x.current)

  return (
    <Column edge grow="flex-[1_1_260px]" label={titleOf(learner)}>
      <SectionTitle>{titleOf(learner)}</SectionTitle>
      {data === undefined ? (
        <Waiting phase={loaded.phase} what={titleOf(learner)} labels={copy.loading} rows={2} onRetry={loaded.reload} />
      ) : (
        <>
          <Caption>{t.incidentsTerm}</Caption>
          <div className="type-numeral text-[40px] leading-none">{now?.incidents ?? 0}</div>
          <p className="mt-1 text-[13px] text-text-muted">
            {t.commendationsTerm}: <b className="text-ink">{now?.commendations ?? 0}</b>
          </p>
          {data.watch.length > 0 && <p className="mt-1 text-[13px] font-bold">! {data.watch.join(' ')}</p>}
          <InLink href={tabHref(learner.id, 'conduct')}>{t.seeConduct}</InLink>
        </>
      )}
    </Column>
  )
}

/** The Conduct tab (the approved design): the band, by term, the open serious incident, the record. */
export function ConductTab({ learner }: { learner: LearnerState }) {
  const loaded = useConduct(learner.id)
  const data = loaded.data

  if (data === undefined) return <Waiting phase={loaded.phase} what={titleOf(learner)} labels={copy.loading} rows={6} onRetry={loaded.reload} />

  const terms = data.terms
  const now = terms.find((x) => x.current)
  const before = terms.length > 1 ? terms[terms.length - (now !== undefined ? 2 : 1)] : undefined
  const open = data.entries.find((e) => e.case !== null && !['closed', 'withdrawn'].includes(e.case.state))
  const peak = Math.max(1, ...terms.flatMap((x) => [x.incidents, x.commendations]))
  const cells = [
    { label: t.incidentsTerm, value: String(now?.incidents ?? 0), note: before !== undefined ? t.lastTerm(before.incidents) : t.noLastTerm, ink: true },
    { label: t.commendationsTerm, value: String(now?.commendations ?? 0), note: before !== undefined ? t.lastTerm(before.commendations) : t.noLastTerm },
    { label: t.watch, value: data.watch.length > 0 ? '!' : '—', note: data.watch.length > 0 ? data.watch.join(' ') : t.watchNone, attention: data.watch.length > 0 },
  ]

  return (
    <Columns top>
      <Column grow="flex-[1_1_100%]" label={titleOf(learner)}>
        <div className="mb-4 grid grid-cols-1 border-2 border-ink sm:grid-cols-3">
          {cells.map((c, i) => (
            <section key={c.label} className={`min-w-0 px-3.5 pt-2.5 pb-3 ${c.ink === true ? 'bg-ink text-sheet' : c.attention === true ? 'bg-marigold-wash' : 'bg-sheet'} ${i > 0 ? 'border-ink max-sm:border-t-2 sm:border-l-2' : ''}`}>
              <h3 className={`label-caps ${c.ink === true ? 'text-on-ink-muted' : 'text-text-muted'}`}>{c.label}</h3>
              <div className={`type-numeral text-[clamp(30px,3vw,40px)] ${c.ink === true ? 'text-teal-bright' : ''}`}>{c.value}</div>
              <p className={`text-[13px] ${c.ink === true ? 'text-on-ink-soft' : 'text-text-muted'}`}>{c.note}</p>
            </section>
          ))}
        </div>

        {data.can.record && (
          <div className="mb-5 flex flex-wrap gap-2.5">
            <Button onClick={() => navigate(`/conduct/record?learner=${learner.id}`)}>{t.recordIncident}</Button>
            <Button variant="secondary" onClick={() => navigate(`/conduct/record?learner=${learner.id}&commendation=1`)}>
              {t.recordCommendation}
            </Button>
          </div>
        )}

        {terms.length > 0 && (
          <section className="mb-5" aria-label={t.byTerm}>
            <SectionTitle aside={<span className="text-[13px] text-text-muted">{t.countsOnly}</span>}>{t.byTerm}</SectionTitle>
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
              {terms.map((x) => (
                <div key={x.name} className={`grid gap-1.5 bg-sheet px-3 py-2.5 ${x.current ? 'border-2 border-ink' : 'border border-rule'}`}>
                  <div className="label-caps text-text-muted">{x.name}</div>
                  <div className="flex h-16 items-end gap-2.5" aria-hidden="true">
                    <div className="grid flex-1 justify-items-stretch gap-0.5">
                      <div className="bg-marigold" style={{ height: `${Math.round((x.incidents / peak) * 48)}px` }} />
                    </div>
                    <div className="grid flex-1 justify-items-stretch gap-0.5">
                      <div className="bg-teal" style={{ height: `${Math.round((x.commendations / peak) * 48)}px` }} />
                    </div>
                  </div>
                  <div className="flex justify-between text-[12px]">
                    <span>
                      {t.incidents} <b className="ref">{x.incidents}</b>
                    </span>
                    <span>
                      {t.commendations} <b className="ref">{x.commendations}</b>
                    </span>
                  </div>
                </div>
              ))}
            </div>
          </section>
        )}

        {open?.case != null && (
          <section className="mb-5 border-2 border-ink bg-sheet px-4 py-3" aria-label={t.openIncident}>
            <SectionTitle aside={<Tag tone="attention">! {open.case.state_label}</Tag>}>{t.openIncident}</SectionTitle>
            <div className="flex flex-wrap items-center gap-x-4 gap-y-2 text-[15px]">
              <b>
                {open.kind} · {day(open.happened_on)}
              </b>
              {open.others.length > 0 && <span className="text-text-muted">{t.with(open.others.map((o) => o.name).join(', '))}</span>}
              <span className="ml-auto">
                <Button variant="secondary" onClick={() => navigate(`/conduct/incidents/${open.case?.id ?? ''}`)}>
                  {t.openIt}
                </Button>
              </span>
            </div>
          </section>
        )}

        <Record data={data} onChanged={loaded.reload} />
      </Column>
    </Columns>
  )
}

function Record({ data, onChanged }: { data: LearnerConduct; onChanged: () => void }) {
  return (
    <section className="border-2 border-ink bg-sheet px-4 pt-3 pb-3" aria-label={t.theRecord}>
      <SectionTitle>{t.theRecord}</SectionTitle>
      {data.entries.length === 0 && <p className="text-sm text-text-muted">{t.nothingYet}</p>}
      <ul>
        {data.entries.map((e) => (
          <EntryRow key={e.id} entry={e} canWithdraw={data.can.record && e.case === null && e.withdrawn === null} onChanged={onChanged} />
        ))}
      </ul>
      <p className="mt-2 text-[13px] text-text-muted">{t.neverChanged}</p>
    </section>
  )
}

function EntryRow({ entry: e, canWithdraw, onChanged }: { entry: ConductEntry; canWithdraw: boolean; onChanged: () => void }) {
  const [open, setOpen] = useState(false)
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)

  const withdraw = async () => {
    setBusy(true)
    const result = await conductApi.withdraw(e.id, reason.trim())
    setBusy(false)
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: t.withdrawnDone })
      onChanged()
    } else slips.wrong({ word: t.notSaved, title: result.kind === 'invalid' ? (Object.values(result.errors)[0]?.[0] ?? t.failed) : t.failed })
  }

  return (
    <li className={`grid gap-x-4 gap-y-1 border-b border-rule py-3 md:grid-cols-[110px_minmax(0,1fr)_200px] ${e.withdrawn !== null ? 'opacity-60' : ''}`}>
      <span className="ref border-red-pen text-[13px] md:border-r-2 md:pr-2.5">{day(e.happened_on)}</span>
      <div className="min-w-0">
        <div className="flex flex-wrap items-center gap-2">
          <b className={`text-[15px] ${e.withdrawn !== null ? 'line-through' : ''}`}>{e.kind}</b>
          <EntryTag entry={e} />
          {e.withdrawn !== null && <Tag tone="plain">{t.withdrawnTag}</Tag>}
          {e.case !== null && (
            <InLink href={`/conduct/incidents/${e.case.id}`}>
              {e.case.number} · {e.case.state_label}
            </InLink>
          )}
        </div>
        <p className="mt-1 text-sm break-words">{e.what}</p>
        {e.action !== null && <p className="mt-0.5 text-[13px] text-text-muted">{t.actionLine(e.action)}</p>}
        {e.others.length > 0 && <p className="mt-0.5 text-[13px] text-text-muted">{t.with(e.others.map((o) => o.name).join(', '))}</p>}
        {e.withdrawn !== null && <p className="mt-0.5 text-[13px] font-bold">{t.withdrawn(e.withdrawn.reason)}</p>}
        {open && (
          <div className="mt-2 grid gap-2">
            <TextArea label={t.withdrawReason} value={reason} onChange={(ev) => setReason(ev.target.value)} hint={t.withdrawNote} required />
            <div className="flex gap-2">
              <Button busy={busy} disabled={reason.trim() === ''} onClick={() => void withdraw()}>
                {t.withdraw}
              </Button>
              <Button variant="secondary" onClick={() => setOpen(false)}>
                {t.cancel}
              </Button>
            </div>
          </div>
        )}
      </div>
      <div className="text-[13px] text-text-muted">
        {e.recorded_by !== null && <div>{t.by(e.recorded_by)}</div>}
        <div>{e.told_family ? t.toldFamily : t.notTold}</div>
        {canWithdraw && !open && (
          <button type="button" onClick={() => setOpen(true)} className="min-h-9 font-extrabold text-ink underline underline-offset-[3px]">
            {t.withdraw}
          </button>
        )}
      </div>
    </li>
  )
}
