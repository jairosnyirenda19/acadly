import { signIn, type Account, type SignInOutcome } from '@acadly/kernel'
import { Button, Notice, PasswordField, TextField } from '@acadly/ui'
import { useState, type FormEvent } from 'react'
import { copy } from '../copy'
import { Heading, InLink } from './AuthLayout'

const t = copy.signIn
const portalUrl: string | undefined = import.meta.env.VITE_PORTAL_URL

type Failure = Exclude<SignInOutcome['kind'], 'signed_in'>

const failures: Record<Failure, { title: string; text: string }> = {
  incorrect: { title: t.incorrectTitle, text: t.incorrect },
  paused: { title: t.pausedTitle, text: t.paused },
  unavailable: { title: t.unavailableTitle, text: t.unavailable },
}

export function SignInScreen({ onSignedIn }: { onSignedIn: (account: Account) => void }) {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [failure, setFailure] = useState<Failure | null>(null)
  const [working, setWorking] = useState(false)

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setWorking(true)
    const outcome = await signIn(email, password)
    setWorking(false)

    if (outcome.kind === 'signed_in') {
      onSignedIn(outcome.account)

      return
    }

    setFailure(outcome.kind)
    setPassword('')
  }

  return (
    <form onSubmit={submit} className="flex flex-col gap-5" noValidate>
      <Heading kicker={t.kicker} title={t.title} />

      {failure !== null && (
        <Notice tone="wrong" title={failures[failure].title}>
          {failures[failure].text}
        </Notice>
      )}

      <TextField
        label={copy.email}
        type="email"
        autoComplete="username"
        required
        value={email}
        invalid={failure === 'incorrect'}
        onChange={(e) => setEmail(e.target.value)}
      />
      <PasswordField
        label={copy.password}
        aside={<InLink to="/forgot-password">{t.forgot}</InLink>}
        autoComplete="current-password"
        required
        value={password}
        invalid={failure === 'incorrect'}
        showLabel={copy.show}
        hideLabel={copy.hide}
        onChange={(e) => setPassword(e.target.value)}
      />

      <Button type="submit" className="h-[52px] text-base" disabled={working || email === '' || password === ''}>
        {working ? t.working : t.submit}
      </Button>

      <div className="flex justify-between gap-3 border-t border-rule pt-3 text-[13px] text-text-muted">
        <span>{t.noAccount}</span>
        {portalUrl !== undefined && (
          <a href={portalUrl} className="font-bold whitespace-nowrap text-teal hover:text-ink">
            {t.portal}
          </a>
        )}
      </div>
    </form>
  )
}
