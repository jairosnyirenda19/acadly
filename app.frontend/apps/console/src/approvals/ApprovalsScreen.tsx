import { forgetLoads, navigate, useLoad } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { follow } from '../assessment/parts'
import { copy } from '../copy'
import { Input, Total, TotalsBand } from '../setup/parts'
import { Tag } from '../settings/parts'
import { approvalsApi, daysSince, when, type RequestState, type Waiting as Item } from './api'

const t = copy.approvals

/** The answer buttons, with the reason a decline needs. Shared by the list and one request. */
function Answer({ id, onDone, wide = false }: { id: string; onDone: (next: RequestState) => void; wide?: boolean }) {
  const [declining, setDeclining] = useState(false)
  const [reason, setReason] = useState('')
  const [working, setWorking] = useState(false)
  const [failed, setFailed] = useState<string | null>(null)

  const send = async (approve: boolean) => {
    setWorking(true)
    setFailed(null)
    const r = await approvalsApi.answer(id, approve, approve ? null : reason.trim())
    setWorking(false)
    if (r.kind === 'saved') {
      forgetLoads()
      slips.done({ word: copy.slips.saved, title: approve ? t.approved : t.declined })
      onDone(r.data)
    } else setFailed(r.kind === 'invalid' ? (Object.values(r.errors)[0]?.[0] ?? t.failed) : r.kind === 'refused' ? t.notYours : t.failed)
  }

  return (
    <div className="mt-2.5">
      {failed !== null && (
        <div className="mb-2">
          <Notice tone="wrong" title={failed} />
        </div>
      )}
      {declining ? (
        <div className="grid gap-2">
          <Input label={t.reason} hint={t.reasonHint} required value={reason} maxLength={255} onChange={(e) => setReason(e.target.value)} />
          <div className="flex flex-wrap gap-2">
            <Button variant="secondary" busy={working} disabled={reason.trim() === ''} onClick={() => void send(false)}>
              {t.confirmDecline}
            </Button>
            <button type="button" onClick={() => setDeclining(false)} className="min-h-11 px-2 text-sm font-extrabold underline underline-offset-[3px]">
              {t.cancel}
            </button>
          </div>
        </div>
      ) : (
        <div className={`flex gap-2 ${wide ? 'flex-wrap' : ''}`}>
          <Button busy={working} onClick={() => void send(true)} className={wide ? '' : 'flex-1'}>
            {t.approve}
          </Button>
          <Button variant="secondary" disabled={working} onClick={() => setDeclining(true)} className={wide ? '' : 'flex-1'}>
            {t.decline}
          </Button>
        </div>
      )}
    </div>
  )
}

/** To approve (the approved design): everything waiting for this person, phone first. */
export function ApprovalsScreen() {
  const loaded = useLoad('approvals:waiting', () => approvalsApi.waiting())
  const data = loaded.data

  if (data === undefined) return <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} band={[t.bandWaiting, t.bandOldest]} rows={4} onRetry={loaded.reload} />

  const items = data.waiting
  const oldest = items.reduce<number>((max, i) => Math.max(max, daysSince(i.asked_at)), 0)

  return (
    <>
      <div className="px-4 pt-5 pb-4 md:px-[clamp(16px,3vw,40px)]">
        <div className="label-caps text-text-muted">{t.kicker}</div>
        <h1 className="type-display mt-1 mb-2 text-[clamp(48px,7vw,88px)]">{t.title}</h1>
        <p className="max-w-[680px] text-[15px] text-text-muted">{t.intro}</p>
      </div>
      <TotalsBand compact>
        <Total compact tone={items.length > 0 ? 'attention' : 'ink'} label={t.bandWaiting} value={String(items.length)} note={t.bandWaitingNote} />
        <Total compact label={t.bandOldest} value={items.length > 0 ? t.days(oldest) : copy.dash} note={t.bandOldestNote} />
      </TotalsBand>
      <section aria-label={t.title} className="flex-1 bg-sheet">
        {items.length === 0 && (
          <div className="px-4 py-6 md:px-[clamp(16px,3vw,40px)]">
            <p className="text-[15px] font-extrabold">{t.none}</p>
            <p className="text-sm text-text-muted">{t.noneNote}</p>
          </div>
        )}
        <ul className="grid md:grid-cols-[repeat(auto-fill,minmax(380px,1fr))]">
          {items.map((i) => (
            <Card key={i.id} item={i} onDone={() => loaded.reload()} />
          ))}
        </ul>
      </section>
    </>
  )
}

function Card({ item, onDone }: { item: Item; onDone: () => void }) {
  const href = `/approvals/${item.id}`

  return (
    <li className="border-b border-rule px-4 py-3.5 md:border-r md:px-6">
      <div className="flex items-baseline gap-2">
        <span className="label-caps flex-1 text-text-muted">{item.kind}</span>
        <span className="text-xs font-extrabold">{t.stepOf(item.step, item.steps, item.step_label)}</span>
      </div>
      <a href={href} onClick={(e) => follow(e, href)} className="mt-0.5 block text-base font-extrabold text-ink no-underline hover:underline">
        {item.title}
      </a>
      <div className="text-sm">{item.where}</div>
      <div className="mt-0.5 text-xs text-text-muted">
        {t.askedBy(item.asked_by)}
        {item.reason !== null && ` · "${item.reason}"`}
      </div>
      <Answer id={item.id} onDone={onDone} />
    </li>
  )
}

/** One request and how its chain stands (the approved design). */
export function ApprovalScreen({ id }: { id: string }) {
  const loaded = useLoad(`approvals:request:${id}`, () => approvalsApi.request(id))
  const data = loaded.data

  if (data === undefined) return <Waiting phase={loaded.phase} what={t.who} labels={copy.loading} rows={4} onRetry={loaded.reload} />

  const mark = (state: string) =>
    state === 'approved' ? 'bg-teal text-sheet' : state === 'declined' ? 'bg-red-pen text-sheet' : state === 'waiting' ? 'bg-marigold text-ink' : 'border-2 border-rule-dashed text-text-muted'
  const sign = (state: string) => (state === 'approved' ? '✓' : state === 'declined' ? '✕' : state === 'waiting' ? '…' : state === 'skipped' ? '–' : '·')

  return (
    <>
      <div className="px-4 pt-3 pb-4 md:px-[clamp(16px,3vw,40px)]">
        <a href="/approvals" onClick={(e) => follow(e, '/approvals')} className="inline-flex min-h-11 items-center text-sm font-extrabold text-ink">
          ‹ {t.back}
        </a>
        <div className="label-caps text-text-muted">
          {data.area} · {data.kind}
        </div>
        <h1 className="type-display mt-1 mb-2 text-[clamp(36px,5vw,64px)] break-words">{data.title}</h1>
        <div className="flex flex-wrap items-center gap-2 text-sm">
          <Tag tone={data.state === 'approved' ? 'done' : data.state === 'declined' ? 'wrong' : 'attention'}>{t.requestStates[data.state] ?? data.state}</Tag>
          <span>{data.where}</span>
          <a href={data.link} onClick={(e) => follow(e, data.link)} className="font-extrabold text-ink underline underline-offset-[3px]">
            {t.openRecord}
          </a>
        </div>
      </div>
      <div className="flex flex-1 flex-wrap items-stretch border-t-2 border-ink bg-sheet">
        <section aria-label={t.who} className="min-w-0 flex-[2_1_520px] px-4 pt-4.5 pb-6 md:px-8">
          <h2 className="type-display mb-1.5 text-[22px] leading-none">{t.who}</h2>
          <ol>
            <li className="grid grid-cols-[30px_1fr] gap-3 border-b border-rule py-2.5">
              <span aria-hidden="true" className="grid size-[26px] place-items-center bg-teal font-extrabold text-sheet">
                ✓
              </span>
              <span>
                <span className="block text-[15px] font-extrabold">{t.askedAt(data.asked_by, when(data.asked_at))}</span>
                {data.reason !== null && <span className="block text-[13px] text-text-muted">"{data.reason}"</span>}
              </span>
            </li>
            {data.steps.map((s) => (
              <li key={s.position} className="grid grid-cols-[30px_1fr] gap-3 border-b border-rule py-2.5">
                <span aria-hidden="true" className={`grid size-[26px] place-items-center font-extrabold ${mark(s.state)}`}>
                  {sign(s.state)}
                </span>
                <span className="min-w-0">
                  <span className="block text-[15px] font-extrabold">
                    {t.stepNo(s.position)} · {s.label} <span className="font-medium text-text-muted">· {t.states[s.state] ?? s.state}</span>
                  </span>
                  {s.state !== 'skipped' && s.state !== 'pending' && <span className="block text-[13px]">{s.asked.length > 0 ? t.asked(s.asked) : t.nobody}</span>}
                  {s.needs > 1 && <span className="block text-[13px]">{t.needs(s.needs)}</span>}
                  {s.answers.map((a, i) => (
                    <span key={i} className="block text-[13px]">
                      {t.answer(a.by, t.states[a.answer] ?? a.answer)} · {when(a.at)}
                      {a.reason !== null && ` · "${a.reason}"`}
                    </span>
                  ))}
                  {s.note !== null && <span className="block text-xs text-text-muted">{s.note}</span>}
                </span>
              </li>
            ))}
          </ol>
          {data.can_answer && (
            <div className="mt-3 max-w-[520px]">
              <Answer id={data.id} wide onDone={(next) => loaded.set(next)} />
            </div>
          )}
        </section>
      </div>
    </>
  )
}

/** Opens a request from anywhere. */
export const openApproval = (id: string) => navigate(`/approvals/${id}`)
