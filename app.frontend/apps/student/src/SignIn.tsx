import { signIn, signInWithTwoStep, type Account } from '@acadly/kernel'
import { useState } from 'react'
import { studentApi } from './api'
import { copy } from './copy'
import { Field, Primary } from './parts'

const t = copy.signIn

/** Sign in (D-101, the approved board): the student number or college email, and a password of the student's own. */
export function SignIn({ school, onSignedIn }: { school: string; onSignedIn: (account: Account | null) => void }) {
  const [mode, setMode] = useState<'sign-in' | 'two-step' | 'set-up'>('sign-in')
  const [number, setNumber] = useState('')
  const [password, setPassword] = useState('')
  const [code, setCode] = useState('')
  const [problem, setProblem] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const go = async () => {
    setBusy(true)
    setProblem(null)
    const result = mode === 'two-step' ? await signInWithTwoStep(code) : await signIn(number.trim(), password)
    setBusy(false)
    if (result.kind === 'signed_in') onSignedIn(result.account)
    else if (result.kind === 'two_step') setMode('two-step')
    else setProblem(result.kind === 'paused' ? t.paused : result.kind === 'unavailable' ? t.unavailable : t.wrong)
  }
  const setUp = async () => {
    setBusy(true)
    setProblem(null)
    const result = await studentApi.setUp(number.trim(), code.trim(), password)
    setBusy(false)
    if (result.kind === 'saved') onSignedIn(null)
    else setProblem(result.kind === 'invalid' ? result.message : copy.setUp.failed)
  }

  return (
    <div className="mx-auto flex min-h-screen max-w-[640px] flex-col bg-paper text-ink">
      <div className="grid gap-2.5 bg-ink px-5 pt-10 pb-7 text-sheet">
        <span className="label-caps text-on-ink-muted">{school}</span>
        <span className="type-display text-[52px] leading-[0.86] uppercase">{mode === 'set-up' ? copy.setUp.title : t.title}</span>
      </div>
      <form
        className="flex flex-col gap-3 px-4 py-4"
        onSubmit={(e) => {
          e.preventDefault()
          void (mode === 'set-up' ? setUp() : go())
        }}
      >
        {problem !== null && (
          <p role="alert" className="border-2 border-red-pen bg-red-wash px-3 py-2 text-red-text">
            {problem}
          </p>
        )}
        {mode === 'set-up' && <p>{copy.setUp.intro}</p>}
        {mode !== 'two-step' && (
          <Field label={mode === 'set-up' ? copy.setUp.number : t.number} value={number} onChange={(e) => setNumber(e.target.value)} autoComplete="username" autoCapitalize="characters" />
        )}
        {mode !== 'sign-in' && (
          <Field label={mode === 'set-up' ? copy.setUp.code : t.twoStep} value={code} onChange={(e) => setCode(e.target.value)} inputMode="numeric" autoComplete="one-time-code" />
        )}
        {mode !== 'two-step' && (
          <Field
            label={mode === 'set-up' ? copy.setUp.password : t.password}
            hint={mode === 'set-up' ? copy.setUp.passwordHint : undefined}
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            autoComplete={mode === 'set-up' ? 'new-password' : 'current-password'}
          />
        )}
        <Primary busy={busy} submit>
          {mode === 'set-up' ? copy.setUp.submit : mode === 'two-step' ? t.twoStepSubmit : t.submit}
        </Primary>
        {mode === 'sign-in' ? (
          <button type="button" className="min-h-11 text-left font-bold text-teal underline" onClick={() => (setMode('set-up'), setProblem(null))}>
            {t.firstTime}
          </button>
        ) : (
          <button type="button" className="min-h-11 text-left font-bold text-teal underline" onClick={() => (setMode('sign-in'), setProblem(null))}>
            {copy.setUp.back}
          </button>
        )}
        <p className="text-[13px] text-text-muted">{mode === 'set-up' ? copy.setUp.noCode : t.forgot}</p>
        <p className="border-t border-rule pt-2.5 text-[13px] text-text-muted">{t.note}</p>
      </form>
    </div>
  )
}
