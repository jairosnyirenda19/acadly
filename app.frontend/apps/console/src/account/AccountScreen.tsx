import { api, contextChanged, getPortalAddress, useAppContext, useLoad } from '@acadly/kernel'
import { Button, Notice, slips } from '@acadly/ui'
import { useEffect, useState } from 'react'
import { copy } from '../copy'
import { errorFor, read, settle } from '../setup/api'
import { Input, Select } from '../setup/parts'
import { PageHead } from '../settings/parts'

const t = copy.account

/** As people read a number: +265991234567 becomes 0991 234 567. */
function local(phone: string): string {
  const digits = phone.replace(/\D/g, '').slice(-9)

  return digits.length === 9 ? `0${digits.slice(0, 3)} ${digits.slice(3, 6)} ${digits.slice(6)}` : phone
}

/**
 * Your account (the approved design, D-087): the email address the console
 * signs in with, and the phone number that makes the parent portal open
 * this same account.
 */
export function AccountScreen() {
  const { user } = useAppContext()
  const phone = user.phone ?? null

  return (
    <div className="flex flex-1 flex-col bg-paper">
      <PageHead kicker={user.name} title={t.title} intro="" />
      <div className="grid max-w-[720px] gap-4 px-[clamp(16px,3vw,40px)] pb-8">
        <dl className="border-y-2 border-ink bg-sheet px-4">
          <div className="border-b border-rule py-2.5">
            <dt className="label-caps text-text-muted">{t.email}</dt>
            <dd className="text-base font-bold">{user.email}</dd>
          </div>
          <div className="py-2.5">
            <dt className="label-caps text-text-muted">{t.signsInWith}</dt>
            <dd className="text-base font-bold">{t.emailAndPassword}</dd>
          </div>
        </dl>
        {phone === null ? <AddPhone /> : <Children phone={phone} />}
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
