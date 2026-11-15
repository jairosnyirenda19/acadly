import { requestPasswordReset } from '@acadly/kernel'
import { Button, Notice, TextField } from '@acadly/ui'
import { useState, type FormEvent } from 'react'
import { copy } from '../copy'
import { Heading, InLink } from './AuthLayout'

const t = copy.forgot

export function ForgotPasswordScreen() {
  const [email, setEmail] = useState('')
  const [state, setState] = useState<'asking' | 'working' | 'sent' | 'unavailable'>('asking')

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setState('working')
    setState((await requestPasswordReset(email)) ? 'sent' : 'unavailable')
  }

  return (
    <>
      <InLink to="/" className="self-start no-underline">
        <span aria-hidden="true">&larr; </span>
        {t.back}
      </InLink>
      <Heading kicker={t.kicker} title={t.title} />

      {state === 'sent' ? (
        <>
          <Notice tone="done" title={t.sentTitle}>
            {t.sent}
          </Notice>
          <ul className="list-disc pl-[18px] text-sm leading-relaxed text-text-muted">
            {t.tips.map((tip) => (
              <li key={tip}>{tip}</li>
            ))}
          </ul>
          <Button variant="secondary" className="h-12" onClick={() => setState('asking')}>
            {t.again}
          </Button>
        </>
      ) : (
        <form onSubmit={submit} className="flex flex-col gap-5" noValidate>
          {state === 'unavailable' && (
            <Notice tone="wrong" title={copy.signIn.unavailableTitle}>
              {copy.signIn.unavailable}
            </Notice>
          )}
          <p className="leading-normal">{t.intro}</p>
          <TextField label={copy.email} type="email" autoComplete="username" required value={email} onChange={(e) => setEmail(e.target.value)} />
          <Button type="submit" className="h-[52px] text-base" disabled={state === 'working' || email === ''}>
            {state === 'working' ? t.working : t.submit}
          </Button>
        </form>
      )}
    </>
  )
}
