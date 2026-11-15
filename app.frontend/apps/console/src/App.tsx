import {
  ContextProvider,
  getAccount,
  getContext,
  getSchoolName,
  navigate,
  signOut,
  useLocation,
  useSchool,
  type Account,
  type AppContext,
} from '@acadly/kernel'
import { Button, Notice } from '@acadly/ui'
import { useEffect, useState } from 'react'
import { copy } from './copy'
import { AuthLayout, Heading } from './sign-in/AuthLayout'
import { ForgotPasswordScreen } from './sign-in/ForgotPasswordScreen'
import { ResetPasswordScreen } from './sign-in/ResetPasswordScreen'
import { SignInScreen } from './sign-in/SignInScreen'
import { StatusScreen } from './StatusScreen'

/** Until the staff home screen is built: who is signed in, and the way out. */
function SignedIn({ account, onSignedOut }: { account: Account; onSignedOut: () => void }) {
  const t = copy.home
  const { licence } = useSchool()

  return (
    <>
      <Heading kicker={t.kicker} title={account.name} />
      <div className="flex justify-between gap-3 border-t-2 border-b border-t-ink border-b-rule py-2.5 text-sm">
        <span className="text-text-muted">{t.signedInAs}</span>
        <span className="font-extrabold break-all">{account.email}</span>
      </div>
      {licence.read_only && (
        <Notice tone="wrong" title={t.readOnlyTitle}>
          {t.readOnly}
        </Notice>
      )}
      <p className="text-text-muted">{t.pending}</p>
      <Button
        variant="secondary"
        className="h-12"
        onClick={() => {
          void signOut().then(onSignedOut)
        }}
      >
        {t.signOut}
      </Button>
    </>
  )
}

export function App() {
  const { path, query } = useLocation()
  const [account, setAccount] = useState<Account | null | undefined>(undefined)
  const [school, setSchool] = useState<string | null>(null)
  // undefined: loading · null: could not be read
  const [context, setContext] = useState<AppContext | null | undefined>(undefined)

  useEffect(() => {
    void getAccount().then(setAccount)
    void getSchoolName().then(setSchool)
  }, [])

  useEffect(() => {
    if (school !== null) document.title = school
  }, [school])

  // Once signed in, load what the screens are built from.
  useEffect(() => {
    if (account === null || account === undefined) return

    let current = true
    void getContext().then((value) => {
      if (current) setContext(value)
    })

    return () => {
      current = false
    }
  }, [account])

  if (path === '/status') {
    return <StatusScreen />
  }

  // The session check is still running: show the empty page, not a wrong screen.
  if (account === undefined) {
    return <div className="min-h-screen bg-paper" />
  }

  let screen
  if (account !== null && context !== null && context !== undefined) {
    screen = (
      <ContextProvider value={context}>
        <SignedIn
          account={account}
          onSignedOut={() => {
            setContext(undefined)
            setAccount(null)
          }}
        />
      </ContextProvider>
    )
  } else if (account !== null && context === null) {
    // Signed in, but the context could not be read: say so, never wait for ever.
    screen = (
      <>
        <Heading kicker={copy.home.kicker} title={account.name} />
        <Notice tone="wrong" title={copy.signIn.unavailableTitle}>
          {copy.signIn.unavailable}
        </Notice>
        <Button variant="secondary" className="h-12" onClick={() => window.location.reload()}>
          {copy.home.retry}
        </Button>
      </>
    )
  } else if (account !== null) {
    // Signed in; the context is still loading.
    return <div className="min-h-screen bg-paper" />
  } else if (path === '/forgot-password') {
    screen = <ForgotPasswordScreen />
  } else if (path === '/reset-password') {
    screen = <ResetPasswordScreen token={query.get('token') ?? ''} email={query.get('email') ?? ''} />
  } else {
    screen = (
      <SignInScreen
        onSignedIn={(signedIn) => {
          setAccount(signedIn)
          navigate('/', true)
        }}
      />
    )
  }

  return <AuthLayout school={school}>{screen}</AuthLayout>
}
