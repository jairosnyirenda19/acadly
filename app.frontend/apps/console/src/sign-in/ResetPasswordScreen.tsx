import { resetPassword, type ResetOutcome } from '@acadly/kernel'
import { Button, Notice, PasswordField } from '@acadly/ui'
import { useState, type FormEvent } from 'react'
import { copy } from '../copy'
import { Heading, InLink } from './AuthLayout'

const t = copy.reset
const MIN_LENGTH = 12

function Rule({ met, text }: { met: boolean; text: string }) {
  return (
    <li className="flex items-center gap-2.5 text-sm">
      <span
        aria-hidden="true"
        className={`grid size-[22px] shrink-0 place-items-center border-2 text-[15px] font-extrabold text-sheet ${met ? 'border-teal bg-teal' : 'border-rule-dashed bg-sheet'}`}
      >
        {met ? '/' : ''}
      </span>
      <span className={met ? 'font-bold' : ''}>{text}</span>
      <span className={`label-caps ml-auto text-[11px] ${met ? 'text-teal' : 'text-text-muted'}`}>{met ? t.met : t.notMet}</span>
    </li>
  )
}

export function ResetPasswordScreen({ token, email }: { token: string; email: string }) {
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [working, setWorking] = useState(false)
  const [outcome, setOutcome] = useState<ResetOutcome['kind'] | null>(token === '' || email === '' ? 'link_invalid' : null)

  const long = password.length >= MIN_LENGTH
  const same = password !== '' && password === confirmation
  const ready = long && same && !working

  const submit = async (event: FormEvent) => {
    event.preventDefault()

    if (!ready) return

    setWorking(true)
    const result = await resetPassword({ token, email, password, password_confirmation: confirmation })
    setWorking(false)
    setOutcome(result.kind)
  }

  if (outcome === 'changed' || outcome === 'link_invalid') {
    const changed = outcome === 'changed'

    return (
      <>
        <Heading kicker={t.kicker} title={t.title} />
        <Notice tone={changed ? 'done' : 'wrong'} title={changed ? t.changedTitle : t.invalidTitle}>
          {changed ? t.changed : t.invalid}
        </Notice>
        <InLink
          to={changed ? '/' : '/forgot-password'}
          className="inline-flex h-[52px] items-center justify-center bg-teal text-base font-extrabold text-sheet no-underline hover:bg-ink hover:text-sheet"
        >
          {changed ? t.toSignIn : t.requestNew}
        </InLink>
      </>
    )
  }

  return (
    <form onSubmit={submit} className="flex flex-col gap-5" noValidate>
      <Heading kicker={t.kicker} title={t.title} />

      <div className="flex justify-between gap-3 border-t-2 border-b border-t-ink border-b-rule py-2.5 text-sm">
        <span className="text-text-muted">{t.account}</span>
        <span className="font-extrabold break-all">{email}</span>
      </div>

      {outcome === 'rejected' && (
        <Notice tone="wrong" title={t.rejectedTitle}>
          {t.rejected}
        </Notice>
      )}
      {outcome === 'unavailable' && (
        <Notice tone="wrong" title={copy.signIn.unavailableTitle}>
          {copy.signIn.unavailable}
        </Notice>
      )}

      <PasswordField
        label={t.password}
        autoComplete="new-password"
        required
        value={password}
        showLabel={copy.show}
        hideLabel={copy.hide}
        onChange={(e) => setPassword(e.target.value)}
      />
      <PasswordField
        label={t.confirm}
        autoComplete="new-password"
        required
        value={confirmation}
        showLabel={copy.show}
        hideLabel={copy.hide}
        onChange={(e) => setConfirmation(e.target.value)}
      />

      <ul aria-label={t.rulesLabel} aria-live="polite" className="flex flex-col gap-2">
        <Rule met={long} text={t.ruleLength(password.length)} />
        <Rule met={same} text={t.ruleMatch} />
      </ul>

      <Button type="submit" className="h-[52px] text-base" disabled={!ready} aria-describedby={ready ? undefined : 'reset-blocked'}>
        {working ? t.working : t.submit}
      </Button>
      {!ready && !working && (
        <p id="reset-blocked" className="-mt-2 text-[13px] text-text-muted">
          {t.blocked}
        </p>
      )}
    </form>
  )
}
