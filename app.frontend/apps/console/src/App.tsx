import { getAccount, getSchoolName, navigate, signOut, useLocation, type Account } from '@acadly/kernel'
import { Button } from '@acadly/ui'
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

  return (
    <>
      <Heading kicker={t.kicker} title={account.name} />
      <div className="flex justify-between gap-3 border-t-2 border-b border-t-ink border-b-rule py-2.5 text-sm">
        <span className="text-text-muted">{t.signedInAs}</span>
        <span className="font-extrabold break-all">{account.email}</span>
      </div>
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

  useEffect(() => {
    void getAccount().then(setAccount)
    void getSchoolName().then(setSchool)
  }, [])

  useEffect(() => {
    if (school !== null) document.title = school
  }, [school])

  if (path === '/status') {
    return <StatusScreen />
  }

  // The session check is still running: show the empty page, not a wrong screen.
  if (account === undefined) {
    return <div className="min-h-screen bg-paper" />
  }

  let screen
  if (account !== null) {
    screen = <SignedIn account={account} onSignedOut={() => setAccount(null)} />
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
