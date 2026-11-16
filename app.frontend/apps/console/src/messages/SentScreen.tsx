import { navigate, useLoad, useLocation } from '@acadly/kernel'
import { Button, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { longDay } from '../registers/api'
import { Total, TotalsBand } from '../setup/parts'
import { SettingsTabs, Tag } from '../settings/parts'
import { clock, clockSeconds, messagesApi, type MessageState, type SentState } from './api'
import { Facts, Heading, MessagesHead } from './parts'

const t = copy.messages
const s = copy.settings

const tone: Record<string, 'done' | 'attention' | 'wrong' | 'plain'> = {
  delivered: 'done',
  sent: 'plain',
  accepted: 'plain',
  waiting: 'plain',
  not_delivered: 'wrong',
  not_reachable: 'attention',
  over_allowance: 'attention',
  not_sent: 'plain',
}

const COLS = 'md:grid-cols-[64px_minmax(200px,1.4fr)_minmax(150px,1fr)_64px_minmax(190px,1.3fr)_48px]'

/** Every message of one day and what happened to it (the approved design). */
export function SentScreen() {
  const { query } = useLocation()
  const day = query.get('day')
  const channel = query.get('channel') ?? ''
  const state = query.get('state') ?? ''
  const q = query.get('q') ?? ''
  const chosen = query.get('message')
  const loaded = useLoad(`messages:sent:${day ?? ''}:${channel}:${state}:${q}`, () => messagesApi.sent(day, channel, state, q))
  const data = loaded.data

  if (data === undefined) {
    return (
      <>
        <SettingsTabs current="messages" />
        <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} band={[t.bandSentToday, t.bandDeliveredToday, t.bandWaiting, t.bandAttentionToday]} rows={8} onRetry={loaded.reload} />
      </>
    )
  }

  const go = (patch: Record<string, string | null>) => {
    const next = new URLSearchParams(query)

    for (const [k, v] of Object.entries(patch)) {
      if (v === null || v === '') next.delete(k)
      else next.set(k, v)
    }

    if (next.get('day') === data.today) next.delete('day')
    const text = next.toString()
    navigate(`/settings/messages/sent${text !== '' ? `?${text}` : ''}`)
  }

  return (
    <>
      <MessagesHead current="sent" intro={t.sentIntro} />
      <TotalsBand>
        <Total tone="ink" label={t.bandSentToday} value={String(data.totals.sent)} note={t.bandSentNote(longDay(data.day))} />
        <Total label={t.bandDeliveredToday} value={String(data.totals.delivered)} note={t.bandDeliveredNote} />
        <Total label={t.bandWaiting} value={String(data.totals.waiting)} note={t.bandWaitingNote} />
        <Total tone={data.totals.attention > 0 ? 'attention' : 'plain'} label={t.bandAttentionToday} value={String(data.totals.attention)} note={t.bandAttentionTodayNote(data.totals.attention)} />
      </TotalsBand>
      <div className="flex flex-1 flex-wrap items-stretch">
        <section className="min-w-0 flex-[3_1_640px] bg-sheet px-3 pt-3.5 pb-6 md:px-[clamp(16px,3vw,40px)]">
          <Filters data={data} channel={channel} state={state} q={q} onChange={go} />
          <div className={`label-caps hidden gap-3 border-b-2 border-ink pr-4 pb-2 pl-5 text-text-muted md:grid ${COLS}`}>
            <span>{t.colTime}</span>
            <span>{t.colTo}</span>
            <span>{t.colNotice}</span>
            <span>{t.colChannel}</span>
            <span>{t.colState}</span>
            <span className="text-right">{t.colUnits}</span>
          </div>
          {data.messages.length === 0 && <p className="px-4 py-4 text-sm text-text-muted">{t.none}</p>}
          {data.messages.map((m) => (
            <button
              key={m.id}
              type="button"
              onClick={() => go({ message: m.id })}
              aria-pressed={m.id === chosen}
              className={`grid w-full gap-1 border-b border-l-4 border-b-rule py-2.5 pr-4 pl-4 text-left md:items-center md:gap-3 ${COLS} ${m.id === chosen ? 'border-l-teal bg-paper' : 'border-l-transparent hover:bg-paper'}`}
            >
              <span className="ref text-[13px]">{clock(m.at)}</span>
              <span className="min-w-0">
                <span className="block font-extrabold">{m.to}</span>
                <span className="ref block text-[13px] break-all text-text-muted">{m.address ?? '—'}</span>
              </span>
              <span className="text-sm">{m.notice}</span>
              <span className="text-[13px] font-bold">{t.state[m.channel] ?? (m.channel === 'sms' ? t.sms : m.channel === 'email' ? t.email : m.channel)}</span>
              <span className="grid justify-items-start gap-0.5">
                <span className="flex flex-wrap gap-1">
                  <Tag tone={tone[m.state] ?? 'plain'}>{t.state[m.state] ?? m.state}</Tag>
                  {m.again && <Tag tone="plain">{t.again}</Tag>}
                </span>
                {m.reason !== null && <span className="text-xs text-text-muted">{m.reason}</span>}
              </span>
              <span className="ref text-[13px] md:text-right">{m.channel === 'sms' && m.units > 0 ? m.units : '—'}</span>
            </button>
          ))}
        </section>
        <aside className="min-w-0 flex-[1_1_340px] border-ink bg-paper px-3 pt-4.5 pb-6 max-[1023px]:border-t-2 md:px-7 min-[1024px]:border-l-2">
          {chosen === null ? <p className="text-sm text-text-muted">{t.pick}</p> : <Detail key={chosen} id={chosen} onChanged={loaded.reload} />}
        </aside>
      </div>
    </>
  )
}

function Filters({ data, channel, state, q, onChange }: { data: SentState; channel: string; state: string; q: string; onChange: (patch: Record<string, string | null>) => void }) {
  const [text, setText] = useState(q)
  const field = 'h-11 border-0 border-b-2 border-ink bg-field px-3 text-[15px] text-ink outline-none focus:border-teal'

  return (
    <form
      className="mb-3 flex flex-wrap items-end gap-2.5"
      onSubmit={(e) => {
        e.preventDefault()
        onChange({ q: text, message: null })
      }}
    >
      <label className="block">
        <span className="label-caps mb-1.5 block text-text-muted">{t.day}</span>
        <input type="date" value={data.day} max={data.today} onChange={(e) => e.target.value !== '' && onChange({ day: e.target.value, message: null })} className={`${field} w-[170px]`} />
      </label>
      <label className="block">
        <span className="label-caps mb-1.5 block text-text-muted">{t.colChannel}</span>
        <select value={channel} onChange={(e) => onChange({ channel: e.target.value, message: null })} className={`${field} w-[160px]`}>
          <option value="">{t.everyChannel}</option>
          <option value="sms">{t.sms}</option>
          <option value="email">{t.email}</option>
        </select>
      </label>
      <label className="block">
        <span className="label-caps mb-1.5 block text-text-muted">{t.colState}</span>
        <select value={state} onChange={(e) => onChange({ state: e.target.value, message: null })} className={`${field} w-[190px]`}>
          <option value="">{t.everyState}</option>
          {Object.entries(t.state).map(([k, v]) => (
            <option key={k} value={k}>
              {v}
            </option>
          ))}
        </select>
      </label>
      <label className="block min-w-[220px] flex-1">
        <span className="label-caps mb-1.5 block text-text-muted">{t.searchLabel}</span>
        <input type="search" value={text} placeholder={t.search} onChange={(e) => setText(e.target.value)} className={`${field} w-full`} />
      </label>
      {data.day !== data.today && (
        <Button variant="secondary" onClick={() => onChange({ day: data.today, message: null })}>
          {t.today}
        </Button>
      )}
    </form>
  )
}

function Detail({ id, onChanged }: { id: string; onChanged: () => void }) {
  const loaded = useLoad(`messages:message:${id}`, () => messagesApi.message(id))
  const [working, setWorking] = useState(false)
  const [failed, setFailed] = useState<string | null>(null)
  const m = loaded.data

  if (m === undefined) return <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} rows={4} onRetry={loaded.reload} />

  const again = async () => {
    setWorking(true)
    setFailed(null)
    const result = await messagesApi.again(m.id)
    setWorking(false)

    if (result.kind === 'saved') {
      loaded.set(result.data)
      onChanged()
      slips.done({ word: copy.slips.sent, title: t.sentAgain })
    } else if (result.kind === 'invalid') setFailed(Object.values(result.errors)[0]?.[0] ?? s.failed)
    else setFailed(result.kind === 'refused' ? s.refused : s.failed)
  }

  return <DetailView m={m} working={working} failed={failed} onAgain={() => void again()} />
}

function DetailView({ m, working, failed, onAgain }: { m: MessageState; working: boolean; failed: string | null; onAgain: () => void }) {
  return (
    <>
      <Heading aside={<Tag tone={tone[m.state] ?? 'plain'}>{t.state[m.state] ?? m.state}</Tag>}>{m.to}</Heading>
      <Facts
        rows={[
          [t.colTo, <span className="ref font-normal">{m.address ?? '—'}</span>],
          [t.colNotice, m.notice],
          [t.providerLabel, [m.provider ?? '—', m.channel === 'sms' && m.units > 0 ? t.unitsLabel(m.units) : null].filter((x) => x !== null).join(' · ')],
        ]}
      />
      <div className="my-3.5 border-2 border-ink bg-sheet p-3 text-sm leading-normal whitespace-pre-line">
        {m.email_subject !== null && <div className="mb-2 font-extrabold">{m.email_subject}</div>}
        {m.text}
      </div>
      <div className="label-caps mb-1.5 text-text-muted">{t.happened}</div>
      <ol className="text-sm">
        {m.steps.map((step, i) => (
          <li key={i} className="grid grid-cols-[80px_1fr] border-b border-rule py-1.5 last:border-b-0">
            <span className="ref text-xs">{clockSeconds(step.at)}</span>
            <span className={i === m.steps.length - 1 ? 'font-extrabold' : ''}>{t.step[step.what] ?? step.what}</span>
          </li>
        ))}
      </ol>
      {m.reason !== null && <p className="mt-2 text-[13px] text-text-muted">{m.reason}</p>}
      {m.sent_again !== null && (
        <p className="mt-2 text-sm">
          <Tag tone={tone[m.sent_again.state] ?? 'plain'}>{t.state[m.sent_again.state] ?? m.sent_again.state}</Tag> <span className="ml-1">{t.again}</span>
        </p>
      )}
      {m.can_send_again && (
        <div className="mt-3.5">
          <Button busy={working} onClick={onAgain}>
            {working ? s.saving : t.sendAgain}
          </Button>
        </div>
      )}
      {failed !== null && <p className="mt-2 text-[13px] font-bold text-red-text">{failed}</p>}
      <p className="mt-3 text-[13px] text-text-muted">{t.againNote}</p>
    </>
  )
}
