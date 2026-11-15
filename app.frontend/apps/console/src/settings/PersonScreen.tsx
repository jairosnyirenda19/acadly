import { navigate } from '@acadly/kernel'
import { Button, Notice } from '@acadly/ui'
import { useEffect, useState } from 'react'
import { copy } from '../copy'
import { errorFor } from '../setup/api'
import { Input, SectionTitle, nn } from '../setup/parts'
import { day, daysUntil, missingAt, settingsApi, type PersonState } from './api'
import { PageHead, SettingsTabs, SmallButton, Tag, stateTone } from './parts'
import { RolePlace, placeBody } from './RolePlace'

const t = copy.settings.person
const s = copy.settings

const COLS = 'grid-cols-[48px_minmax(150px,1.3fr)_minmax(140px,1.2fr)_minmax(130px,1fr)_72px]'

export function PersonScreen({ id }: { id: string }) {
  const [state, setState] = useState<PersonState | 'refused' | null | undefined>(undefined)
  const [message, setMessage] = useState<{ tone: 'done' | 'wrong'; text: string } | null>(null)

  useEffect(() => {
    void settingsApi.person(id).then(setState)
  }, [id])

  const back = (
    <a
      href="/settings/people"
      onClick={(e) => {
        e.preventDefault()
        navigate('/settings/people')
      }}
      className="font-bold"
    >
      {t.back}
    </a>
  )

  if (state === undefined) return <SettingsTabs current="people" />

  if (state === null || state === 'refused') {
    return (
      <>
        <SettingsTabs current="people" />
        <div className="flex flex-col gap-4 p-[clamp(16px,3vw,40px)]">
          <Notice tone="wrong" title={state === null ? t.notFound : s.refused} />
          <div>{back}</div>
        </div>
      </>
    )
  }

  const { person } = state
  const outcome = (result: { kind: string; data?: PersonState }, done?: string) => {
    if (result.kind === 'saved' && result.data !== undefined) {
      setState(result.data)
      setMessage(done !== undefined ? { tone: 'done', text: done } : null)
    } else {
      setMessage({ tone: 'wrong', text: result.kind === 'refused' ? s.refused : s.failed })
    }
  }

  return (
    <>
      <SettingsTabs current="people" />
      <PageHead
        kicker={
          <>
            <span>{t.kicker}</span>
            <Tag tone={stateTone[person.state]}>{s.states[person.state]}</Tag>
          </>
        }
        title={person.name}
        intro={
          <>
            <span className="ref text-[13px]">{person.email}</span> ·{' '}
            {person.last_signed_in_at !== null ? t.lastSignedIn(day(person.last_signed_in_at)) : t.neverSignedIn}
            {person.disabled !== null && <> {t.disabled(day(person.disabled.at), person.disabled.reason ?? '')}</>}
          </>
        }
        actions={back}
      />

      <div className="flex flex-1 flex-wrap items-stretch border-t-2 border-ink">
        <section className="min-w-0 flex-[3_1_600px] bg-sheet px-[clamp(16px,3vw,40px)] pt-6 pb-8">
          {message !== null && (
            <div className="mb-4">
              <Notice tone={message.tone} title={message.text} />
            </div>
          )}
          {!state.can_act && (
            <div className="mb-4 border-l-4 border-marigold bg-marigold-wash px-4 py-3 text-sm" role="status">
              {t.outside}
            </div>
          )}

          <SectionTitle>{t.held}</SectionTitle>
          <div className="overflow-x-auto">
            <div className="min-w-[600px]">
              <div className={`label-caps grid ${COLS} gap-3 border-b-2 border-ink pr-4 pb-2 text-text-muted`}>
                <span />
                <span>{t.role}</span>
                <span>{t.where}</span>
                <span>{t.when}</span>
                <span />
              </div>
              <div className="ledger [--margin:48px]">
                {state.held.length === 0 && <p className="py-4 pl-[60px] text-sm text-text-muted">{t.none}</p>}
                {state.held.map((held, i) => (
                  <HeldLine key={held.id} n={i + 1} held={held} personId={person.id} onDone={(r) => outcome(r)} />
                ))}
              </div>
            </div>
          </div>

          {person.state !== 'disabled' && state.giving.places.length > 0 && <GiveForm state={state} onDone={(r) => outcome(r)} />}

          {state.ended.length > 0 && (
            <>
              <h2 className="type-display mt-7 mb-2.5 text-[26px] leading-none">{t.ended}</h2>
              <div className="border-t-2 border-ink">
                {state.ended.map((e) => (
                  <div key={e.id} className="flex flex-wrap gap-x-3.5 gap-y-1 border-b border-rule py-2.5 text-sm text-text-muted">
                    <span className="flex-[1_1_260px]">
                      <strong className="text-ink">
                        {e.role} · {e.where}
                      </strong>{' '}
                      · {t.span(day(e.starts_at), day(e.ends_at))}
                    </span>
                    {e.ended_by !== null && <span>{t.endedBy(e.ended_by, e.end_reason ?? '')}</span>}
                  </div>
                ))}
              </div>
            </>
          )}
        </section>

        <section className="min-w-0 flex-[2_1_360px] border-l-2 border-ink px-[clamp(16px,3vw,40px)] pt-6 pb-8">
          <SectionTitle>{t.canDo}</SectionTitle>
          <p className="-mt-2 mb-2.5 text-[13px] text-text-muted">{t.canDoIntro}</p>
          <div className="border-t-2 border-ink">
            {state.can_do.length === 0 && <p className="py-3 text-sm text-text-muted">{t.canDoNone}</p>}
            {state.can_do.map((group) => (
              <div key={group.group}>
                <div className="label-caps pt-2 pb-1 text-text-muted">{group.group}</div>
                {group.permissions.map((p) => (
                  <div key={p.key} className={`flex flex-wrap items-baseline gap-x-3 gap-y-1 border-b border-rule py-2 ${p.sight ? 'bg-marigold-wash' : ''}`}>
                    <span className="flex-[1_1_180px] text-[15px] font-bold">
                      {p.label} {p.sight && <Tag tone="attention">{t.sight}</Tag>}
                    </span>
                    <span className="text-[13px] text-text-muted">{p.where.join(', ')}</span>
                  </div>
                ))}
              </div>
            ))}
          </div>
          {state.can_do.some((g) => g.permissions.some((p) => p.sight)) && (
            <p className="mt-2.5 text-[13px] text-text-muted">
              <Tag tone="attention">{t.sight}</Tag> {t.sightNote}
            </p>
          )}

          {state.can_act && person.state !== 'disabled' && <AccountActions state={state} onDone={outcome} />}
        </section>
      </div>
    </>
  )
}

type Done = (result: { kind: string; data?: PersonState }, message?: string) => void

function HeldLine({ n, held, personId, onDone }: { n: number; held: PersonState['held'][number]; personId: string; onDone: Done }) {
  const [ending, setEnding] = useState(false)
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | undefined>(undefined)
  const [working, setWorking] = useState(false)
  const soon = held.ends_at !== null ? daysUntil(held.ends_at) : null

  const end = async () => {
    setWorking(true)
    const result = await settingsApi.end(personId, held.id, reason)
    setWorking(false)

    if (result.kind === 'invalid') setError(errorFor(result.errors, 'reason') ?? errorFor(result.errors, 'assignment'))
    else onDone(result)
  }

  return (
    <div className={`border-b border-rule py-3 pr-4 ${soon !== null && soon <= 45 ? 'bg-marigold-wash' : ''}`}>
      <div className={`grid ${COLS} items-center gap-3`}>
        <div className="type-display text-center text-xl text-text-muted">{nn(n)}</div>
        <div>
          <div className="type-display text-[22px] leading-none">{held.role}</div>
          {held.reason !== null && <div className="text-[13px] text-text-muted">{held.reason}</div>}
          {held.given_by !== null && <div className="text-[13px] text-text-muted">{t.givenBy(held.given_by)}</div>}
        </div>
        <div className="text-[15px] font-bold">{held.where}</div>
        <div className="text-sm">
          {held.ends_at !== null ? t.span(day(held.starts_at), day(held.ends_at)) : t.from(day(held.starts_at))}
          {soon !== null && soon <= 45 && (
            <div className="mt-1">
              <Tag tone="attention">{t.endsIn(soon)}</Tag>
            </div>
          )}
        </div>
        <div className="text-right">{held.can_end && !ending && <SmallButton onClick={() => setEnding(true)}>{t.end}</SmallButton>}</div>
      </div>
      {ending && (
        <form
          onSubmit={(e) => {
            e.preventDefault()
            void end()
          }}
          className="mt-3 flex flex-wrap items-end gap-3 pl-[60px]"
        >
          <div className="min-w-[220px] flex-1">
            <Input label={t.endReason} required value={reason} onChange={(e) => setReason(e.target.value)} error={error} maxLength={500} />
          </div>
          <Button type="submit" disabled={working}>
            {working ? s.saving : t.endConfirm}
          </Button>
          <Button variant="secondary" onClick={() => setEnding(false)}>
            {s.cancel}
          </Button>
        </form>
      )}
    </div>
  )
}

function GiveForm({ state, onDone }: { state: PersonState; onDone: Done }) {
  const g = copy.settings.give
  const [role, setRole] = useState('')
  const [place, setPlace] = useState('')
  const [from, setFrom] = useState('')
  const [until, setUntil] = useState('')
  const [reason, setReason] = useState('')
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [working, setWorking] = useState(false)
  const blocked = role !== '' && place !== '' && missingAt(state.giving, role, place).length > 0

  const submit = async () => {
    setWorking(true)
    const result = await settingsApi.give(state.person.id, { role_id: role, ...placeBody(place), starts_on: from || null, ends_on: until || null, reason })
    setWorking(false)

    if (result.kind === 'invalid') {
      setErrors(result.errors)
    } else {
      if (result.kind === 'saved') {
        setRole('')
        setPlace('')
        setFrom('')
        setUntil('')
        setReason('')
        setErrors({})
      }
      onDone(result)
    }
  }

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault()
        void submit()
      }}
      className="mt-5.5 border-2 border-ink bg-paper p-4.5"
    >
      <h3 className="type-display text-[22px] leading-none">{t.giveTitle}</h3>
      <p className="mt-1 mb-3.5 text-[13px] text-text-muted">{t.giveIntro}</p>
      <div className="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-x-5 gap-y-4">
        <RolePlace
          giving={state.giving}
          role={role}
          place={place}
          onRole={setRole}
          onPlace={setPlace}
          errors={{ role: errorFor(errors, 'role_id'), place: errorFor(errors, 'scope') ?? errorFor(errors, 'scope_id') }}
        />
        <Input label={g.from} type="date" value={from} onChange={(e) => setFrom(e.target.value)} error={errorFor(errors, 'starts_on')} />
        <Input label={g.until} type="date" hint={g.untilHint} value={until} onChange={(e) => setUntil(e.target.value)} error={errorFor(errors, 'ends_on') ?? errorFor(errors, 'ends_at')} />
        <Input label={g.reason} required wide value={reason} onChange={(e) => setReason(e.target.value)} error={errorFor(errors, 'reason')} maxLength={500} />
      </div>
      <div className="mt-4 flex flex-wrap gap-2.5">
        <Button type="submit" disabled={working || blocked}>
          {working ? s.saving : t.giveSubmit}
        </Button>
      </div>
    </form>
  )
}

function AccountActions({ state, onDone }: { state: PersonState; onDone: Done }) {
  const { person } = state
  const [disabling, setDisabling] = useState(false)
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | undefined>(undefined)
  const [working, setWorking] = useState(false)

  const run = async (action: () => Promise<{ kind: string; data?: PersonState; errors?: Record<string, string[]> }>, done: string) => {
    setWorking(true)
    const result = await action()
    setWorking(false)

    if (result.kind === 'invalid') setError(errorFor(result.errors ?? {}, 'reason') ?? errorFor(result.errors ?? {}, 'user'))
    else onDone(result, done)
  }

  return (
    <>
      <div className="my-6.5 h-0.5 bg-ink" />
      <SectionTitle>{t.account}</SectionTitle>
      <div className="flex flex-wrap gap-2.5">
        {person.state === 'active' || person.state === 'no_role' ? (
          <Button
            variant="secondary"
            disabled={working}
            onClick={() =>
              void run(async () => {
                const r = await settingsApi.passwordReset(person.id)

                return r.kind === 'saved' ? { kind: 'saved', data: state } : r
              }, t.resetSent)
            }
          >
            {t.reset}
          </Button>
        ) : (
          <Button variant="secondary" disabled={working} onClick={() => void run(() => settingsApi.resend(person.id), t.resent)}>
            {t.resend}
          </Button>
        )}
        {!disabling && (
          <button
            type="button"
            onClick={() => setDisabling(true)}
            className="h-11 border-2 border-red-pen px-[18px] text-[15px] font-extrabold text-red-text hover:bg-red-wash"
          >
            {t.disable}
          </button>
        )}
      </div>
      {disabling && (
        <form
          onSubmit={(e) => {
            e.preventDefault()
            void run(() => settingsApi.disable(person.id, reason), t.disabledDone)
          }}
          className="mt-3.5 flex flex-col gap-3 border-l-4 border-red-pen bg-red-wash p-3.5"
        >
          <Input label={t.disableReason} required value={reason} onChange={(e) => setReason(e.target.value)} error={error} maxLength={500} />
          <div className="flex flex-wrap gap-2.5">
            <button type="submit" disabled={working} className="h-11 bg-red-pen px-[18px] text-[15px] font-extrabold text-sheet disabled:bg-disabled disabled:text-text-muted">
              {working ? s.saving : t.disableConfirm}
            </button>
            <Button variant="secondary" onClick={() => setDisabling(false)}>
              {s.cancel}
            </Button>
          </div>
        </form>
      )}
      {error !== undefined && !disabling && <p className="mt-2 text-[13px] font-bold text-red-text">{error}</p>}
      <p className="mt-2.5 text-[13px] text-text-muted">{t.disableNote}</p>
    </>
  )
}
