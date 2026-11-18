import { api, contextChanged, getPortalAddress, useAppContext, useLoad, type paths } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useEffect, useState } from 'react'
import { copy } from '../copy'
import { errorFor, read, settle } from '../setup/api'
import { Input, Select } from '../setup/parts'
import { PageHead, Tag } from '../settings/parts'

const t = copy.account

/** As people read a number: +265991234567 becomes 0991 234 567. */
function local(phone: string): string {
  const digits = phone.replace(/\D/g, '').slice(-9)

  return digits.length === 9 ? `0${digits.slice(0, 3)} ${digits.slice(3, 6)} ${digits.slice(6)}` : phone
}

type Account = paths['/v1/me/account']['get']['responses'][200]['content']['application/json']
type Teaching = paths['/v1/me/teaching']['get']['responses'][200]['content']['application/json']

const day = (iso: string) => new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
const when = (iso: string) => new Date(iso).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })

/** A section's heading: small capitals over a heavy rule. */
const Head = ({ children }: { children: string }) => <h2 className="label-caps border-b-2 border-ink pb-1.5">{children}</h2>

/**
 * Your account (the approved design, D-087, D-089), as in eOffice: who you
 * are, how the school reaches you, how you sign in, your lessons in your
 * calendar, your children, and the messages sent to you.
 */
export function AccountScreen() {
  const { user } = useAppContext()
  const phone = user.phone ?? null
  const account = useLoad('account', () => read(api.GET('/v1/me/account')))
  const teaching = useLoad('account:teaching', () => read(api.GET('/v1/me/teaching')))

  if (account.data === undefined) return <Waiting phase={account.phase} what={t.title} labels={copy.loading} rows={6} onRetry={account.reload} />

  const data = account.data

  return (
    <div className="flex flex-1 flex-col bg-paper">
      <PageHead kicker={user.name} title={t.title} intro={t.intro} />
      <div className="grid gap-x-10 gap-y-8 px-[clamp(16px,3vw,40px)] pb-6 lg:grid-cols-[minmax(0,1fr)_380px]">
        <div className="@container grid content-start gap-8">
          <Notices data={data} onSaved={account.set} />
          <section>
            <Head>{t.security}</Head>
            <div className="grid gap-6 pt-3 @2xl:grid-cols-2">
              <PasswordPart data={data} onChanged={account.reload} />
              <div className="grid content-start gap-6">
                <TwoStepPart data={data} onChanged={account.reload} />
                <CalendarPart />
              </div>
            </div>
          </section>
        </div>
        <aside className="grid content-start gap-7">
          <section>
            <Head>{t.whoYouAre}</Head>
            <Fact label={t.name} value={data.user.name} />
            <Fact label={t.email} value={data.user.email ?? '—'} />
            <Fact label={t.signsInWith} value={t.emailAndPassword} />
          </section>
          <section>
            <Head>{t.roles}</Head>
            {data.roles.length === 0 && <p className="py-2.5 text-sm text-text-muted">{t.noRoles}</p>}
            {data.roles.map((r, i) => (
              <Fact key={i} label={r.role} value={r.where} />
            ))}
          </section>
          <section>
            <Head>{t.teaching}</Head>
            <TeachingPart teaching={teaching.data} />
            <p className="mt-2 text-xs text-text-muted">{t.givenByOffice}</p>
          </section>
          {phone === null ? <AddPhone /> : <Children phone={phone} />}
        </aside>
      </div>
      <section className="border-t-2 border-ink px-[clamp(16px,3vw,40px)] pt-5 pb-8">
        <Head>{t.messages}</Head>
        <p className="mt-1.5 mb-1 text-xs text-text-muted">{t.messagesNote}</p>
        {data.messages.length === 0 && <p className="py-3 text-sm text-text-muted">{t.noMessages}</p>}
        {data.messages.map((m) => (
          <div key={m.id} className="grid grid-cols-[64px_minmax(0,1fr)] items-baseline gap-x-4 gap-y-0.5 border-b border-rule py-2.5 md:grid-cols-[72px_minmax(0,1fr)_130px_130px]">
            <span className="label-caps text-text-muted">{t.channel[m.channel] ?? m.channel}</span>
            <span className="min-w-0 text-sm">{m.text}</span>
            <span className={`label-caps max-md:col-start-2 ${m.status === 'failed' || m.status === 'not_delivered' ? 'text-red-text' : 'text-text-muted'}`}>{t.status[m.status] ?? m.status}</span>
            <span className="ref text-xs text-text-muted max-md:col-start-2">{when(m.at)}</span>
          </div>
        ))}
      </section>
    </div>
  )
}

function Fact({ label, value }: { label: string; value: string }) {
  return (
    <p className="flex items-baseline justify-between gap-4 border-b border-rule py-2.5 text-sm">
      <span className="shrink-0 text-text-muted">{label}</span>
      <span className="min-w-0 text-right font-extrabold">{value}</span>
    </p>
  )
}

function TeachingPart({ teaching }: { teaching: Teaching | undefined }) {
  if (teaching === undefined) return null
  if (teaching.class_teacher.length === 0 && teaching.subjects.length === 0) return <p className="py-2.5 text-sm text-text-muted">{t.teachingNone}</p>

  return (
    <>
      {teaching.class_teacher.length > 0 && <Fact label={t.classTeacherOf} value={teaching.class_teacher.join(', ')} />}
      {teaching.subjects.map((s) => (
        <Fact key={s.subject} label={s.subject} value={s.classes.join(', ')} />
      ))}
    </>
  )
}

/** Each notice that goes to people of the school, by channel. A notice about the account itself is always sent. */
function Notices({ data, onSaved }: { data: Account; onSaved: (next: Account) => void }) {
  const [failed, setFailed] = useState(false)
  const channels = ['in_app', 'email', 'sms'] as const

  const set = async (topic: string, channel: string, on: boolean) => {
    setFailed(false)
    const result = await settle(api.PUT('/v1/me/account/notices', { body: { topic, channel, on } as never }))
    if (result.kind === 'saved') onSaved(result.data as Account)
    else setFailed(true)
  }

  return (
    <section>
      <Head>{t.reach}</Head>
      {failed && (
        <div className="mt-2">
          <Notice tone="wrong" title={copy.settings.failed} />
        </div>
      )}
      <div className="label-caps grid grid-cols-[minmax(0,1fr)_repeat(3,64px)] gap-2 pt-2 pb-1 text-text-muted">
        <span>{t.notice}</span>
        {channels.map((c) => (
          <span key={c} className="text-center">
            {t.channel[c]}
          </span>
        ))}
      </div>
      {data.notices.map((n) => (
        <div key={n.key} className="grid grid-cols-[minmax(0,1fr)_repeat(3,64px)] items-center gap-2 border-b border-rule py-2">
          <span className="min-w-0">
            <span className="block text-sm font-extrabold">{n.name}</span>
            {n.category !== null && <span className="block text-xs text-text-muted">{n.category}</span>}
          </span>
          {channels.map((c) => {
            const ch = n.channels.find((x) => x.channel === c)

            return (
              <span key={c} className="text-center">
                {ch === undefined ? (
                  <span className="text-text-muted">—</span>
                ) : n.locked ? (
                  <span className="text-xs font-extrabold">{t.always}</span>
                ) : (
                  <input type="checkbox" checked={ch.on} aria-label={`${n.name}: ${t.channel[c] ?? c}`} onChange={(e) => void set(n.key, c, e.target.checked)} className="size-5 accent-ink" />
                )}
              </span>
            )
          })}
        </div>
      ))}
      <p className="mt-2 text-xs text-text-muted">{t.lockedNote}</p>
    </section>
  )
}

function PasswordPart({ data, onChanged }: { data: Account; onChanged: () => void }) {
  const [current, setCurrent] = useState('')
  const [next, setNext] = useState('')
  const [again, setAgain] = useState('')
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [working, setWorking] = useState(false)

  const change = async () => {
    setWorking(true)
    const result = await settle(api.POST('/v1/me/account/password', { body: { current_password: current, password: next, password_confirmation: again } as never }))
    setWorking(false)

    if (result.kind === 'saved') {
      setErrors({})
      setCurrent('')
      setNext('')
      setAgain('')
      slips.done({ word: copy.slips.saved, title: t.passwordChanged })
      onChanged()
    } else setErrors(result.kind === 'invalid' ? result.errors : { password: [copy.settings.failed] })
  }

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault()
        void change()
      }}
      className="grid content-start gap-2.5"
    >
      <div>
        <div className="text-base font-extrabold">{t.password}</div>
        <div className="text-[13px] text-text-muted">{data.security.password_changed_at !== null ? t.changedOn(day(data.security.password_changed_at)) : t.neverChanged}</div>
      </div>
      <Input label={t.currentPassword} type="password" autoComplete="current-password" value={current} onChange={(e) => setCurrent(e.target.value)} error={errorFor(errors, 'current_password')} />
      <Input label={t.newPassword} type="password" autoComplete="new-password" value={next} onChange={(e) => setNext(e.target.value)} error={errorFor(errors, 'password')} />
      <Input label={t.repeatPassword} type="password" autoComplete="new-password" value={again} onChange={(e) => setAgain(e.target.value)} />
      <div>
        <Button type="submit" busy={working} disabled={current === '' || next === ''}>
          {t.changePassword}
        </Button>
      </div>
    </form>
  )
}

function TwoStepPart({ data, onChanged }: { data: Account; onChanged: () => void }) {
  const [setup, setSetup] = useState<{ key: string; address: string } | null>(null)
  const [code, setCode] = useState('')
  const [codes, setCodes] = useState<string[] | null>(null)
  const [password, setPassword] = useState('')
  const [turningOff, setTurningOff] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [working, setWorking] = useState(false)
  const on = data.security.two_step

  const begin = async () => {
    setWorking(true)
    const result = await settle(api.POST('/v1/me/account/two-step'))
    setWorking(false)
    if (result.kind === 'saved') setSetup(result.data as { key: string; address: string })
    else setError(copy.settings.failed)
  }
  const confirm = async () => {
    setWorking(true)
    const result = await settle(api.POST('/v1/me/account/two-step/confirm', { body: { code } as never }))
    setWorking(false)
    if (result.kind === 'saved') {
      setCodes((result.data as { recovery_codes: string[] }).recovery_codes)
      setSetup(null)
      setError(null)
    } else setError(result.kind === 'invalid' ? (errorFor(result.errors, 'code') ?? copy.settings.failed) : copy.settings.failed)
  }
  const off = async () => {
    setWorking(true)
    const result = await settle(api.DELETE('/v1/me/account/two-step', { body: { password } as never }))
    setWorking(false)
    if (result.kind === 'saved') {
      setTurningOff(false)
      setPassword('')
      slips.done({ word: copy.slips.saved, title: t.twoStepTurnedOff })
      onChanged()
    } else setError(result.kind === 'invalid' ? (errorFor(result.errors, 'password') ?? copy.settings.failed) : copy.settings.failed)
  }

  return (
    <div className="grid content-start gap-2.5">
      <div className="flex items-center gap-2.5">
        <span className="text-base font-extrabold">{t.twoStep}</span>
        <Tag tone={on ? 'done' : 'plain'}>{on ? `✓ ${t.twoStepOn}` : t.twoStepOff}</Tag>
      </div>
      {error !== null && <Notice tone="wrong" title={error} />}
      {codes !== null ? (
        <div className="border-2 border-l-[6px] border-ink border-l-marigold bg-sheet px-3.5 py-3">
          <strong className="block text-[15px]">{t.recoveryTitle}</strong>
          <p className="mt-1 mb-2.5 text-sm">{t.recoveryText}</p>
          <ul className="ref grid grid-cols-2 gap-1.5 text-[15px] font-bold">
            {codes.map((c) => (
              <li key={c}>{c}</li>
            ))}
          </ul>
          <div className="mt-3">
            <Button
              onClick={() => {
                setCodes(null)
                slips.done({ word: copy.slips.saved, title: t.twoStepTurnedOn })
                onChanged()
              }}
            >
              {t.recoveryDone}
            </Button>
          </div>
        </div>
      ) : setup !== null ? (
        <>
          <div className="border-2 border-ink bg-sheet px-3.5 py-3">
            <div className="label-caps text-text-muted">{t.stepOne}</div>
            <p className="mt-1 mb-2 text-sm">{t.stepOneText}</p>
            <div className="ref border border-dashed border-rule-dashed bg-paper px-3 py-2.5 text-center text-lg font-bold tracking-[0.08em]">{setup.key}</div>
            <a href={setup.address} className="mt-2 inline-block text-sm font-bold text-ink underline underline-offset-[3px]">
              {t.openInApp}
            </a>
          </div>
          <div className="border-2 border-ink bg-sheet px-3.5 py-3">
            <div className="label-caps text-text-muted">{t.stepTwo}</div>
            <Input label={t.appCode} inputMode="numeric" autoComplete="one-time-code" maxLength={6} value={code} onChange={(e) => setCode(e.target.value)} />
            <div className="mt-2.5">
              <Button busy={working} disabled={code.length < 6} onClick={() => void confirm()}>
                {t.turnOn}
              </Button>
            </div>
          </div>
        </>
      ) : on ? (
        <>
          <p className="text-sm">{t.twoStepSince(day(data.security.two_step_since ?? ''), data.security.recovery_left)}</p>
          {turningOff ? (
            <div className="grid gap-2.5">
              <Input label={t.passwordToTurnOff} type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} />
              <div className="flex flex-wrap gap-2.5">
                <Button variant="secondary" onClick={() => setTurningOff(false)}>
                  {t.keep}
                </Button>
                <Button busy={working} disabled={password === ''} onClick={() => void off()}>
                  {t.turnOffTwoStep}
                </Button>
              </div>
            </div>
          ) : (
            <div>
              <Button variant="secondary" onClick={() => setTurningOff(true)}>
                {t.turnOffTwoStep}
              </Button>
            </div>
          )}
        </>
      ) : (
        <>
          <p className="text-sm leading-normal">{t.twoStepText}</p>
          <div>
            <Button variant="secondary" busy={working} onClick={() => void begin()}>
              {t.turnOnTwoStep}
            </Button>
          </div>
        </>
      )}
    </div>
  )
}

function CalendarPart() {
  const state = useLoad('account:calendar', () => read(api.GET('/v1/me/calendar')))
  const [url, setUrl] = useState<string | null>(null)
  const [working, setWorking] = useState(false)

  const turnOn = async () => {
    setWorking(true)
    const result = await settle(api.POST('/v1/me/calendar'))
    setWorking(false)
    if (result.kind === 'saved') {
      setUrl((result.data as { url: string }).url)
      state.reload()
    }
  }
  const turnOff = async () => {
    setWorking(true)
    const result = await settle(api.DELETE('/v1/me/calendar'))
    setWorking(false)
    if (result.kind === 'saved') {
      setUrl(null)
      state.reload()
    }
  }
  const on = state.data?.on === true

  return (
    <div className="grid content-start gap-2.5">
      <div className="flex items-center gap-2.5">
        <span className="text-base font-extrabold">{t.calendar}</span>
        <Tag tone={on ? 'done' : 'plain'}>{on ? `✓ ${t.twoStepOn}` : t.twoStepOff}</Tag>
      </div>
      <p className="text-sm leading-normal">{on && state.data?.since != null ? t.calendarSince(day(state.data.since)) : t.calendarText}</p>
      {url !== null && (
        <div className="border-2 border-ink bg-sheet px-3.5 py-3">
          <p className="mb-2 text-sm">{t.calendarShown}</p>
          <div className="ref border border-dashed border-rule-dashed bg-paper px-3 py-2 text-xs break-all">{url}</div>
        </div>
      )}
      <div className="flex flex-wrap gap-2.5">
        <Button variant="secondary" busy={working} onClick={() => void turnOn()}>
          {on ? t.calendarNew : t.calendarOn}
        </Button>
        {on && (
          <Button variant="secondary" disabled={working} onClick={() => void turnOff()}>
            {t.calendarOff}
          </Button>
        )}
      </div>
    </div>
  )
}

function AddPhone() {
  const [phone, setPhone] = useState('')
  const [language, setLanguage] = useState<'en' | 'ny'>('en')
  const [code, setCode] = useState('')
  const [sent, setSent] = useState(false)
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [failed, setFailed] = useState(false)
  const [working, setWorking] = useState(false)

  const run = async (request: Promise<{ kind: string; errors?: Record<string, string[]> }>, done: () => void) => {
    setWorking(true)
    setFailed(false)
    const result = await request
    setWorking(false)

    if (result.kind === 'saved') {
      setErrors({})
      done()
    } else if (result.kind === 'invalid') setErrors(result.errors ?? {})
    else setFailed(true)
  }

  const send = () => run(settle(api.POST('/v1/me/phone/code', { body: { phone, language } as never })), () => setSent(true))
  const confirm = () =>
    run(settle(api.POST('/v1/me/phone', { body: { phone, code } as never })), () => {
      slips.done({ word: copy.slips.saved, title: t.confirmedTitle })
      contextChanged()
    })

  return (
    <section className="border-2 border-ink bg-sheet px-4 pt-3.5 pb-4">
      <h2 className="type-display text-[26px] leading-[0.9]">{t.childrenTitle}</h2>
      <p className="mt-1 mb-3 text-sm leading-normal">{t.childrenIntro}</p>
      {failed && (
        <div className="mb-3">
          <Notice tone="wrong" title={copy.settings.failed} />
        </div>
      )}
      <form
        className="grid gap-3"
        onSubmit={(e) => {
          e.preventDefault()
          void (sent ? confirm() : send())
        }}
      >
        <Input label={t.phone} hint={t.phoneHint} type="tel" autoComplete="tel" value={phone} onChange={(e) => setPhone(e.target.value)} error={errorFor(errors, 'phone')} required />
        <Select label={t.language} value={language} onChange={(e) => setLanguage(e.target.value === 'ny' ? 'ny' : 'en')}>
          <option value="en">{t.english}</option>
          <option value="ny">{t.chichewa}</option>
        </Select>
        {sent && (
          <Input
            label={t.code}
            hint={t.codeHint(phone)}
            inputMode="numeric"
            autoComplete="one-time-code"
            maxLength={6}
            value={code}
            onChange={(e) => setCode(e.target.value)}
            error={errorFor(errors, 'code')}
            required
          />
        )}
        <div className="flex flex-wrap gap-2.5">
          <Button type="submit" busy={working} className="h-12">
            {sent ? t.confirm : t.sendCode}
          </Button>
          {sent && (
            <Button variant="secondary" className="h-12" disabled={working} onClick={() => void send()}>
              {t.sendAgain}
            </Button>
          )}
        </div>
      </form>
    </section>
  )
}

function Children({ phone }: { phone: string }) {
  const family = useLoad('account:family', () => read(api.GET('/v1/portal/family')))
  const [portal, setPortal] = useState<string | null>(null)
  const [removing, setRemoving] = useState(false)
  const [working, setWorking] = useState(false)
  const [failed, setFailed] = useState(false)

  useEffect(() => {
    let live = true
    void getPortalAddress().then((found) => {
      if (live) setPortal(found)
    })

    return () => {
      live = false
    }
  }, [])

  const remove = async () => {
    setWorking(true)
    setFailed(false)
    const result = await settle(api.DELETE('/v1/me/phone'))
    setWorking(false)

    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: t.removed })
      contextChanged()
    } else setFailed(true)
  }

  const children = family.data?.children ?? []
  const toConfirm = family.data?.to_confirm.length ?? 0

  return (
    <section className="border-2 border-ink bg-sheet px-4 pt-3.5 pb-4">
      <div className="flex flex-wrap items-baseline gap-2">
        <h2 className="type-display flex-1 text-[26px] leading-[0.9]">{t.yourChildren}</h2>
        <span className="ref text-sm">{local(phone)}</span>
      </div>
      <ul className="mt-2">
        {children.map((c) => (
          <li key={c.id} className="flex items-center gap-2.5 border-b border-rule py-2.5">
            <span aria-hidden="true" className="grid size-[26px] place-items-center bg-teal font-extrabold text-sheet">
              ✓
            </span>
            <span className="text-base font-extrabold">
              {c.name} <span className="text-sm font-medium">· {c.class}</span>
            </span>
          </li>
        ))}
      </ul>
      {family.data !== undefined && children.length === 0 && toConfirm === 0 && <p className="mt-2 text-sm text-text-muted">{t.noChildren}</p>}
      {toConfirm > 0 && <p className="mt-2 text-sm font-bold">{t.toConfirm(toConfirm)}</p>}
      {portal !== null && (
        <a href={portal} className="mt-3 flex h-12 items-center justify-center bg-teal text-base font-extrabold text-sheet no-underline">
          {t.openPortal}
        </a>
      )}
      <p className="mt-2.5 text-[13px] leading-snug text-text-muted">{t.codeOnlyNote}</p>
      {failed && (
        <div className="mt-3">
          <Notice tone="wrong" title={copy.settings.failed} />
        </div>
      )}
      {removing ? (
        <div className="mt-3 border-l-4 border-marigold bg-marigold-wash px-3 py-2.5">
          <p className="mb-2.5 text-sm leading-snug">{t.removeConfirm}</p>
          <div className="flex flex-wrap gap-2.5">
            <Button variant="secondary" onClick={() => setRemoving(false)}>
              {t.keep}
            </Button>
            <Button busy={working} onClick={() => void remove()}>
              {t.removeYes}
            </Button>
          </div>
        </div>
      ) : (
        <button type="button" onClick={() => setRemoving(true)} className="mt-2.5 min-h-11 text-sm font-bold underline underline-offset-[3px]">
          {t.remove}
        </button>
      )}
    </section>
  )
}
