import { api, useLoad, type paths } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { read, settle } from '../setup/api'
import { Input } from '../setup/parts'
import { day } from '../settings/api'
import { PageHead, SmallButton, Tag } from '../settings/parts'
import type { Division } from '../shell/divisions'
import { DivisionChoice, NoDivision, Sheet, td, th, useProgrammeDivision } from './parts'

const t = copy.programmes.accounts

type Accounts = paths['/v1/programmes/{division}/accounts']['get']['responses'][200]['content']['application/json']
type Row = Accounts['rows'][number]

const accountsApi = {
  load: (division: string) => read(api.GET('/v1/programmes/{division}/accounts', { params: { path: { division } } })),
  phone: (division: string, learner: string, phone: string) =>
    settle(api.PUT('/v1/programmes/{division}/accounts/{learner}/phone', { params: { path: { division, learner } }, body: { phone } })),
  codes: (division: string, learners: string[]) => settle(api.POST('/v1/programmes/{division}/accounts/codes', { params: { path: { division } }, body: { learners } })),
  suspend: (division: string, learner: string, reason: string, restore: boolean) =>
    settle(api.POST('/v1/programmes/{division}/accounts/{learner}/suspend', { params: { path: { division, learner } }, body: { reason, restore } })),
}

const tone = { active: 'done', code_sent: 'plain', none: 'attention', suspended: 'wrong' } as const

/** College › Students › Accounts (D-101, the approved board): each student's sign-in, set-up codes, suspend and restore. */
export function AccountsScreen() {
  const { division, choices, choose } = useProgrammeDivision()

  if (division === null) return <NoDivision />

  return <Loaded key={division.id} division={division} choices={choices} choose={choose} />
}

function Loaded({ division, choices, choose }: { division: Division; choices: Division[]; choose: (id: string) => void }) {
  const loaded = useLoad(`programmes:accounts:${division.id}`, () => accountsApi.load(division.id))
  const [busy, setBusy] = useState(false)
  const [phoneFor, setPhoneFor] = useState<Row | null>(null)
  const [stopFor, setStopFor] = useState<Row | null>(null)

  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} rows={8} onRetry={loaded.reload} />

  const data = loaded.data
  const waiting = data.rows.filter((r) => r.state === 'none' && r.phone !== null)
  const send = async (learners: string[]) => {
    setBusy(true)
    const result = await accountsApi.codes(division.id, learners)
    setBusy(false)
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: t.sent(result.data.sent, result.data.no_phone) })
      loaded.reload()
    } else slips.wrong({ word: t.notSent, title: t.failed })
  }
  const cells = [
    { label: t.totals.students, value: data.totals.students, look: 'bg-ink text-sheet' },
    { label: t.totals.active, value: data.totals.active, look: 'bg-sheet' },
    { label: t.totals.codeSent, value: data.totals.code_sent, look: 'bg-sheet' },
    { label: t.totals.none, value: data.totals.none, look: data.totals.none > 0 ? 'bg-marigold' : 'bg-sheet' },
  ]

  return (
    <>
      <PageHead
        kicker={<DivisionChoice choices={choices} division={division} choose={choose} />}
        title={t.title}
        intro={t.intro}
        actions={
          waiting.length > 0 ? (
            <Button busy={busy} onClick={() => void send(waiting.map((r) => r.learner_id))}>
              {t.sendAll(waiting.length)}
            </Button>
          ) : undefined
        }
      />
      <div className="grid gap-4 px-[clamp(16px,3vw,40px)] pb-8">
        <div className="grid grid-cols-2 gap-0.5 border-2 border-ink bg-ink lg:grid-cols-4">
          {cells.map((c) => (
            <section key={c.label} className={`px-4 py-3 ${c.look}`}>
              <h2 className={`label-caps ${c.look.includes('bg-ink') ? 'text-on-ink-muted' : 'text-text-muted'}`}>{c.label}</h2>
              <div className={`type-numeral text-[44px] ${c.look.includes('bg-ink') ? 'text-teal-bright' : ''}`}>{c.label === t.totals.none && c.value > 0 ? `! ${c.value}` : c.value}</div>
            </section>
          ))}
        </div>
        {phoneFor !== null && <PhoneForm division={division.id} row={phoneFor} onDone={() => (setPhoneFor(null), loaded.reload())} />}
        {stopFor !== null && <StopForm division={division.id} row={stopFor} onDone={() => (setStopFor(null), loaded.reload())} />}
        <Sheet label={t.title}>
          <div className="overflow-x-auto">
            <table className="w-full border-collapse text-[15px]">
              <thead>
                <tr>
                  {[t.number, t.student, t.programme, t.phone, t.account, ''].map((h, i) => (
                    <th key={i} scope="col" className={th}>
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {data.rows.map((r) => (
                  <tr key={r.learner_id}>
                    <td className={`${td} font-mono text-[13px]`}>{r.number}</td>
                    <td className={`${td} font-extrabold`}>{r.name}</td>
                    <td className={td}>{`${r.programme} · ${t.year(r.year)}`}</td>
                    <td className={`${td} font-mono text-[13px]`}>{r.phone ?? t.noPhone}</td>
                    <td className={td}>
                      <Tag tone={tone[r.state]}>{t.states[r.state]}</Tag>
                      {r.since !== null && <span className="ml-2 text-[13px] text-text-muted">{day(r.since)}</span>}
                      {r.reason !== null && <span className="block text-[13px] text-text-muted">{r.reason}</span>}
                    </td>
                    <td className={`${td} whitespace-nowrap`}>
                      <span className="flex flex-wrap gap-2">
                        <SmallButton onClick={() => setPhoneFor(r)}>{r.phone === null ? t.addPhone : t.changePhone}</SmallButton>
                        {r.phone !== null && r.state !== 'suspended' && <SmallButton onClick={() => void send([r.learner_id])}>{r.state === 'none' ? t.sendCode : t.resetCode}</SmallButton>}
                        {(r.state === 'active' || r.state === 'code_sent' || r.state === 'suspended') && (
                          <SmallButton tone={r.state === 'suspended' ? 'ink' : 'red'} onClick={() => setStopFor(r)}>
                            {r.state === 'suspended' ? t.restore : t.suspend}
                          </SmallButton>
                        )}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Sheet>
        <p className="max-w-[760px] text-[14px] text-text-muted">{t.note}</p>
      </div>
    </>
  )
}

function PhoneForm({ division, row, onDone }: { division: string; row: Row; onDone: () => void }) {
  const [phone, setPhone] = useState('')
  const [problem, setProblem] = useState<string | null>(null)
  const save = async () => {
    const result = await accountsApi.phone(division, row.learner_id, phone)
    if (result.kind === 'saved') onDone()
    else setProblem(result.kind === 'invalid' ? (Object.values(result.errors)[0]?.[0] ?? t.failed) : t.failed)
  }

  return (
    <Sheet label={t.phoneFor(row.name)} className="max-w-[560px] px-4 py-3">
      <div className="grid gap-3">
        <strong>{t.phoneFor(row.name)}</strong>
        {problem !== null && (
          <Notice tone="wrong" title={t.notSent}>
            {problem}
          </Notice>
        )}
        <Input label={t.phone} value={phone} onChange={(e) => setPhone(e.target.value)} />
        <div className="flex gap-2">
          <Button onClick={() => void save()}>{t.savePhone}</Button>
          <Button variant="secondary" onClick={onDone}>
            {t.cancel}
          </Button>
        </div>
      </div>
    </Sheet>
  )
}

function StopForm({ division, row, onDone }: { division: string; row: Row; onDone: () => void }) {
  const restore = row.state === 'suspended'
  const [reason, setReason] = useState('')
  const [problem, setProblem] = useState<string | null>(null)
  const save = async () => {
    const result = await accountsApi.suspend(division, row.learner_id, reason, restore)
    if (result.kind === 'saved') onDone()
    else setProblem(result.kind === 'invalid' ? (Object.values(result.errors)[0]?.[0] ?? t.failed) : t.failed)
  }

  return (
    <Sheet label={restore ? t.restoreFor(row.name) : t.suspendFor(row.name)} className="max-w-[560px] px-4 py-3">
      <div className="grid gap-3">
        <strong>{restore ? t.restoreFor(row.name) : t.suspendFor(row.name)}</strong>
        <p className="text-[14px] text-text-muted">{restore ? t.restoreNote : t.suspendNote}</p>
        {problem !== null && (
          <Notice tone="wrong" title={t.notSent}>
            {problem}
          </Notice>
        )}
        <Input label={t.reason} value={reason} onChange={(e) => setReason(e.target.value)} />
        <div className="flex gap-2">
          <Button onClick={() => void save()}>{restore ? t.restore : t.suspend}</Button>
          <Button variant="secondary" onClick={onDone}>
            {t.cancel}
          </Button>
        </div>
      </div>
    </Sheet>
  )
}
