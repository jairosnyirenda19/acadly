import {
  getAccount,
  getSchoolName,
  navigate,
  requestPasswordReset,
  signIn,
  signOut,
  useLocation,
  type Account,
  type SignInOutcome,
} from '@acadly/kernel'
import { Button, Notice, PasswordField, TextField } from '@acadly/ui'
import { useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { copy } from './copy'

type Failure = Exclude<SignInOutcome['kind'], 'signed_in'>

const t = copy.signIn
const failures: Record<Failure, { title: string; text: string }> = {
  incorrect: { title: t.incorrectTitle, text: t.incorrect },
  paused: { title: t.pausedTitle, text: t.paused },
  unavailable: { title: t.unavailableTitle, text: t.unavailable },
}

function InLink({ to, children }: { to: string; children: ReactNode }) {
  return (
    <a
      href={to}
      onClick={(event) => {
        event.preventDefault()
        navigate(to)
      }}
      className="self-center p-1.5 text-[15px] font-bold text-teal hover:text-ink"
    >
      {children}
    </a>
  )
}

function Title({ children }: { children: ReactNode }) {
  return <h1 className="type-display text-5xl">{children}</h1>
}

function SignInForm({ onSignedIn }: { onSignedIn: (account: Account) => void }) {
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
    <form onSubmit={submit} className="flex flex-col gap-[18px]" noValidate>
      <Title>{t.title}</Title>
      {failure !== null && (
        <Notice tone="wrong" title={failures[failure].title}>
          {failures[failure].text}
        </Notice>
      )}
      <TextField
        label={copy.email}
        type="email"
        inputMode="email"
        autoComplete="username"
        required
        value={email}
        invalid={failure === 'incorrect'}
        onChange={(e) => setEmail(e.target.value)}
      />
      <PasswordField
        label={copy.password}
        autoComplete="current-password"
        required
        value={password}
        invalid={failure === 'incorrect'}
        showLabel={copy.show}
        hideLabel={copy.hide}
        onChange={(e) => setPassword(e.target.value)}
      />
      <Button type="submit" className="h-14 text-[17px]" disabled={working || email === '' || password === ''}>
        {working ? t.working : t.submit}
      </Button>
      <InLink to="/forgot-password">{t.forgot}</InLink>
    </form>
  )
}

function ForgotForm() {
  const f = copy.forgot
  const [email, setEmail] = useState('')
  const [state, setState] = useState<'asking' | 'working' | 'sent' | 'unavailable'>('asking')

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setState('working')
    setState((await requestPasswordReset(email)) ? 'sent' : 'unavailable')
  }

  return (
    <div className="flex flex-col gap-[18px]">
      <Title>{f.title}</Title>
      {state === 'sent' ? (
        <>
          <Notice tone="done" title={f.sentTitle}>
            {f.sent}
          </Notice>
          <Button variant="secondary" className="h-12" onClick={() => setState('asking')}>
            {f.again}
          </Button>
        </>
      ) : (
        <form onSubmit={submit} className="flex flex-col gap-[18px]" noValidate>
          {state === 'unavailable' && (
            <Notice tone="wrong" title={t.unavailableTitle}>
              {t.unavailable}
            </Notice>
          )}
          <p className="leading-normal">{f.intro}</p>
          <TextField label={copy.email} type="email" inputMode="email" autoComplete="username" required value={email} onChange={(e) => setEmail(e.target.value)} />
          <Button type="submit" className="h-14 text-[17px]" disabled={state === 'working' || email === ''}>
            {state === 'working' ? f.working : f.submit}
          </Button>
        </form>
      )}
      <InLink to="/">{f.back}</InLink>
    </div>
  )
}

function SignedIn({ account, onSignedOut }: { account: Account; onSignedOut: () => void }) {
  return (
    <div className="flex flex-col gap-[18px]">
      <Title>{account.name}</Title>
      <div className="flex justify-between gap-3 border-t-2 border-b border-t-ink border-b-rule py-2.5 text-sm">
        <span className="text-text-muted">{copy.home.signedInAs}</span>
        <span className="font-extrabold break-all">{account.email}</span>
      </div>
      <p className="text-text-muted">{copy.home.pending}</p>
      <Button variant="secondary" className="h-12" onClick={() => void signOut().then(onSignedOut)}>
        {copy.home.signOut}
      </Button>
    </div>
  )
}

/** The parent portal. Phone first: the school on a navy band, the form on a ruled page. */
export function App() {
  const { path } = useLocation()
  const [account, setAccount] = useState<Account | null | undefined>(undefined)
  const [school, setSchool] = useState<string | null>(null)

  useEffect(() => {
    void getAccount().then(setAccount)
    void getSchoolName().then(setSchool)
  }, [])

  useEffect(() => {
    if (school !== null) document.title = school
  }, [school])

  if (account === undefined) {
    return <div className="min-h-screen bg-sheet" />
  }

  let screen
  if (account !== null) {
    screen = <SignedIn account={account} onSignedOut={() => setAccount(null)} />
  } else if (path === '/forgot-password') {
    screen = <ForgotForm />
  } else {
    screen = (
      <SignInForm
        onSignedIn={(signedIn) => {
          setAccount(signedIn)
          navigate('/', true)
        }}
      />
    )
  }

  return (
    <div className="mx-auto flex min-h-screen max-w-[560px] flex-col bg-sheet">
      <header className="bg-ink px-5 pt-6 pb-[22px] text-sheet">
        <div className="flex items-center justify-between">
          <img src="/brand-mark-dark.svg" alt={copy.product} className="size-14" />
          <span className="label-caps text-[11px] tracking-[0.16em] text-on-ink-muted">{copy.kicker}</span>
        </div>
        <div className="type-display mt-7 text-[64px] break-words">{school ?? copy.schoolFallback}</div>
      </header>

      <main className="ledger ruled flex-1 py-7 pr-5 pl-9 [--margin:20px]">
        <div className="relative">{screen}</div>
      </main>

      <footer className="border-t-2 border-ink bg-paper px-5 pt-3.5 pb-[18px] text-[13px] leading-[1.45] text-text-muted">
        <div>{copy.noAccount}</div>
        <div className="type-display mt-2 text-[15px] text-ink">
          Acadly <span className="text-teal">IQ-X</span>
        </div>
      </footer>
    </div>
  )
}
