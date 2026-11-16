import { useLoad } from '@acadly/kernel'
import { Button, slips, Waiting } from '@acadly/ui'
import { useState, type FormEvent } from 'react'
import { copy } from '../copy'
import { errorFor, type Saved } from '../setup/api'
import { Input, Select, Total, TotalsBand } from '../setup/parts'
import { SmallButton, Tag } from '../settings/parts'
import { clock, messagesApi, type ChannelsState } from './api'
import { Facts, Heading, MessagesHead } from './parts'

const t = copy.messages
const s = copy.settings

/** How messages are sent (the approved design): the SMS provider, email, and the limits. */
export function ChannelsScreen() {
  const loaded = useLoad('messages:channels', () => messagesApi.channels())
  const state = loaded.data

  if (state === undefined) {
    return <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} band={[t.bandSms, t.bandDelivered, t.bandEmails, t.bandAttention]} rows={6} onRetry={loaded.reload} />
  }

  const m = state.month

  return (
    <>
      <MessagesHead current="channels" intro={t.channelsIntro} />
      <TotalsBand>
        <Total tone="ink" label={t.bandSms} value={String(m.sms)} note={t.bandSmsNote(m.units)} />
        <Total label={t.bandDelivered} value={m.delivered_share === null ? '–' : `${m.delivered_share}%`} note={t.bandDeliveredNote} />
        <Total label={t.bandEmails} value={String(m.emails)} note={t.bandEmailsNote(m.emails_accepted)} />
        <Total tone={m.not_delivered > 0 ? 'attention' : 'plain'} label={t.bandAttention} value={String(m.not_delivered)} note={t.bandAttentionNote(m.not_delivered)} />
      </TotalsBand>
      <div className="flex flex-1 flex-wrap items-stretch">
        <section className="min-w-0 flex-[1_1_480px] bg-sheet px-3 pt-5 pb-7 md:px-[clamp(16px,3vw,40px)]">
          <SmsForm state={state} onSaved={loaded.set} />
        </section>
        <section className="min-w-0 flex-[1_1_480px] border-ink bg-sheet px-3 pt-5 pb-7 max-[1023px]:border-t-2 md:px-[clamp(16px,3vw,40px)] min-[1024px]:border-l-2">
          <EmailForm state={state} onSaved={loaded.set} />
          <div className="mt-6 border-t-2 border-ink pt-4">
            <LimitsForm state={state} onSaved={loaded.set} />
          </div>
        </section>
      </div>
    </>
  )
}

function useSave<T>() {
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [failed, setFailed] = useState<string | null>(null)
  const [working, setWorking] = useState(false)

  const run = async (request: Promise<Saved<T>>, onDone: (data: T) => void) => {
    setWorking(true)
    setFailed(null)
    setErrors({})
    const result = await request
    setWorking(false)

    if (result.kind === 'saved') onDone(result.data)
    else if (result.kind === 'invalid') setErrors(result.errors)
    else setFailed(result.kind === 'refused' ? s.refused : s.failed)
  }

  return { errors, failed, working, run }
}

function SmsForm({ state, onSaved }: { state: ChannelsState; onSaved: (next: ChannelsState) => void }) {
  const sms = state.sms
  const providers = sms.providers
  const [provider, setProvider] = useState(sms.provider ?? '')
  const [sender, setSender] = useState(sms.sender ?? '')
  const [username, setUsername] = useState(sms.username ?? '')
  const [key, setKey] = useState('')
  const [replacing, setReplacing] = useState(sms.key_hint === null)
  const save = useSave<ChannelsState>()
  const keyKept = sms.key_hint !== null && provider === sms.provider && !replacing
  const chosen = providers.find((p) => p.key === provider)

  const submit = (e: FormEvent) => {
    e.preventDefault()
    void save.run(messagesApi.saveSms({ provider, sender, username, key: keyKept ? '' : key }), (next) => {
      onSaved(next)
      setKey('')
      setReplacing(false)
      slips.done({ word: copy.slips.saved, title: t.saved })
    })
  }

  return (
    <form onSubmit={submit}>
      <Heading
        aside={
          <>
            <Tag tone={sms.connected ? 'done' : 'attention'}>{sms.connected ? t.connected : t.notConnected}</Tag>
            {sms.test_mode && <Tag tone="attention">{t.testMode}</Tag>}
            <span className="text-[13px] text-text-muted">{sms.last_accepted_at !== null ? t.lastAccepted(clock(sms.last_accepted_at)) : t.noSmsYet}</span>
          </>
        }
      >
        {t.sms}
      </Heading>
      <div className="grid gap-3.5">
        {sms.test_mode && <p className="border-2 border-ink bg-marigold-wash px-3 py-2 text-sm">{t.testModeNote}</p>}
        <Select label={t.provider} required hint={t.providerHint} value={provider} onChange={(e) => setProvider(e.target.value)} error={errorFor(save.errors, 'provider')}>
          {provider === '' && <option value="">{t.chooseProvider}</option>}
          {providers.map((p) => (
            <option key={p.key} value={p.key}>
              {p.name}
            </option>
          ))}
        </Select>
        <div className="grid gap-3 sm:grid-cols-2">
          <Input label={t.sender} required hint={t.senderHint} value={sender} onChange={(e) => setSender(e.target.value)} error={errorFor(save.errors, 'sender')} maxLength={11} />
          {chosen?.needs_username === true && (
            <Input label={t.username} required hint={t.usernameHint(chosen.name)} value={username} onChange={(e) => setUsername(e.target.value)} error={errorFor(save.errors, 'username')} maxLength={80} />
          )}
        </div>
        {keyKept ? (
          <div className="flex flex-col gap-1.5">
            <span className="label-caps text-[11px] text-text-muted">{t.key}</span>
            <div className="flex gap-2">
              <span className="ref flex h-11 flex-1 items-center border-b-2 border-ink bg-field px-3 text-[15px]">{t.keyKept(sms.key_hint ?? '')}</span>
              <SmallButton onClick={() => setReplacing(true)}>{t.replaceKey}</SmallButton>
            </div>
            <span className="text-[13px] text-text-muted">{t.keyHint}</span>
          </div>
        ) : (
          <div className="grid gap-1.5">
            <Input label={t.key} required hint={t.keyHint} type="password" autoComplete="off" value={key} onChange={(e) => setKey(e.target.value)} error={errorFor(save.errors, 'key')} maxLength={500} />
            {sms.key_hint !== null && provider === sms.provider && (
              <div>
                <SmallButton onClick={() => setReplacing(false)}>{t.keepKey}</SmallButton>
              </div>
            )}
          </div>
        )}
        {save.failed !== null && <p className="text-[13px] font-bold text-red-text">{save.failed}</p>}
        <div>
          <Button type="submit" busy={save.working}>
            {save.working ? s.saving : t.save}
          </Button>
        </div>
        {sms.provider !== null && (
          <div className="border-t border-rule pt-3.5">
            <Facts
              rows={[
                [t.balance, sms.balance !== null ? t.balanceAt(sms.balance.value, clock(sms.balance.at)) : t.noBalance],
                [
                  t.reports,
                  sms.reports_address !== null ? (
                    <span className="flex flex-wrap items-center gap-2">
                      <span className="ref text-xs font-normal break-all">{sms.reports_address}</span>
                      <SmallButton
                        onClick={() =>
                          void navigator.clipboard.writeText(sms.reports_address ?? '').then(
                            () => slips.done({ word: copy.slips.saved, title: t.copied }),
                            () => undefined,
                          )
                        }
                      >
                        {t.copy}
                      </SmallButton>
                    </span>
                  ) : (
                    '—'
                  ),
                ],
              ]}
            />
            <p className="mt-1.5 text-[13px] text-text-muted">{t.reportsHint}</p>
          </div>
        )}
        <TestSend channel="sms" label={t.testTo} />
      </div>
    </form>
  )
}

function TestSend({ channel, label }: { channel: 'sms' | 'email'; label: string }) {
  const [address, setAddress] = useState('')
  const save = useSave<{ id: string }>()

  return (
    <div className="grid items-end gap-2 border-t border-rule pt-3.5 sm:grid-cols-[1fr_auto]">
      <Input label={label} type={channel === 'sms' ? 'tel' : 'email'} value={address} onChange={(e) => setAddress(e.target.value)} error={errorFor(save.errors, 'address')} maxLength={190} />
      <Button variant="secondary" busy={save.working} onClick={() => void save.run(messagesApi.test(channel, address), () => slips.done({ word: copy.slips.sent, title: t.testSent }))}>
        {t.sendTest}
      </Button>
      {save.failed !== null && <p className="text-[13px] font-bold text-red-text sm:col-span-2">{save.failed}</p>}
    </div>
  )
}

function EmailForm({ state, onSaved }: { state: ChannelsState; onSaved: (next: ChannelsState) => void }) {
  const email = state.email
  const [fromName, setFromName] = useState(email.from_name)
  const [replyTo, setReplyTo] = useState(email.reply_to ?? '')
  const save = useSave<ChannelsState>()

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault()
        void save.run(messagesApi.saveEmail({ from_name: fromName, reply_to: replyTo }), (next) => {
          onSaved(next)
          slips.done({ word: copy.slips.saved, title: t.saved })
        })
      }}
    >
      <Heading
        aside={
          <>
            <Tag tone={email.connected ? 'done' : 'attention'}>{email.connected ? t.connected : t.notConnected}</Tag>
            <span className="text-[13px] text-text-muted">{email.connected ? t.emailSetUp : t.emailNotSetUp}</span>
          </>
        }
      >
        {t.email}
      </Heading>
      <div className="grid gap-3.5">
        <Facts rows={[[t.sendsFrom, <span className="ref font-normal">{email.from_address}</span>]]} />
        <div className="grid gap-3 sm:grid-cols-2">
          <Input label={t.fromName} required value={fromName} onChange={(e) => setFromName(e.target.value)} error={errorFor(save.errors, 'from_name')} maxLength={120} />
          <Input label={t.replyTo} type="email" hint={t.replyToHint} value={replyTo} onChange={(e) => setReplyTo(e.target.value)} error={errorFor(save.errors, 'reply_to')} maxLength={190} />
        </div>
        {save.failed !== null && <p className="text-[13px] font-bold text-red-text">{save.failed}</p>}
        <div>
          <Button type="submit" busy={save.working}>
            {save.working ? s.saving : t.save}
          </Button>
        </div>
        <TestSend channel="email" label={t.testEmailTo} />
      </div>
    </form>
  )
}

function LimitsForm({ state, onSaved }: { state: ChannelsState; onSaved: (next: ChannelsState) => void }) {
  const l = state.limits
  const [from, setFrom] = useState(l.quiet_from ?? '')
  const [to, setTo] = useState(l.quiet_to ?? '')
  const [cap, setCap] = useState(l.sms_per_month !== null ? String(l.sms_per_month) : '')
  const save = useSave<ChannelsState>()

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault()
        const number = Number.parseInt(cap.replace(/\D/g, ''), 10)
        void save.run(
          messagesApi.saveLimits({ quiet_from: from === '' ? null : from, quiet_to: to === '' ? null : to, sms_per_month: Number.isNaN(number) ? null : number }),
          (next) => {
            onSaved(next)
            slips.done({ word: copy.slips.saved, title: t.saved })
          },
        )
      }}
    >
      <Heading>{t.limits}</Heading>
      <div className="grid gap-3 sm:grid-cols-3">
        <Input label={t.quietFrom} type="time" value={from} onChange={(e) => setFrom(e.target.value)} error={errorFor(save.errors, 'quiet_from')} />
        <Input label={t.quietTo} type="time" value={to} onChange={(e) => setTo(e.target.value)} error={errorFor(save.errors, 'quiet_to')} />
        <Input label={t.smsPerMonth} inputMode="numeric" value={cap} onChange={(e) => setCap(e.target.value)} error={errorFor(save.errors, 'sms_per_month')} maxLength={9} />
      </div>
      <p className="mt-2 text-[13px] text-text-muted">
        {t.quietHint} {t.smsPerMonthHint}
      </p>
      {save.failed !== null && <p className="mt-2 text-[13px] font-bold text-red-text">{save.failed}</p>}
      <div className="mt-3.5">
        <Button type="submit" busy={save.working}>
          {save.working ? s.saving : t.save}
        </Button>
      </div>
    </form>
  )
}
