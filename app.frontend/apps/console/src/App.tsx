import {
  can,
  ContextProvider,
  getAccount,
  getContext,
  getSchoolName,
  navigate,
  signOut,
  useAppContext,
  useLocation,
  type Account,
  type AppContext,
} from '@acadly/kernel'
import { Button, Notice, SlipPad } from '@acadly/ui'
import { useEffect, useState } from 'react'
import { copy } from './copy'
import { AuthLayout, Heading } from './sign-in/AuthLayout'
import { ForgotPasswordScreen } from './sign-in/ForgotPasswordScreen'
import { ResetPasswordScreen } from './sign-in/ResetPasswordScreen'
import { AdmitScreen } from './learners/AdmitScreen'
import { LearnerScreen } from './learners/LearnerScreen'
import { LearnersScreen } from './learners/LearnersScreen'
import { PeopleImportScreen } from './settings/PeopleImportScreen'
import { PeopleScreen } from './settings/PeopleScreen'
import { PersonScreen } from './settings/PersonScreen'
import { RolesScreen } from './settings/RolesScreen'
import { SubjectsScreen } from './settings/SubjectsScreen'
import { TeachingScreen } from './settings/TeachingScreen'
import { SetupWizard } from './setup/SetupWizard'
import { ConsoleShell, holdsAnywhere, settingsHome } from './shell/ConsoleShell'
import { WorkInProvider } from './shell/WorkIn'
import { SignInScreen } from './sign-in/SignInScreen'
import { StatusScreen } from './StatusScreen'

/** The console after the school opens: the shell, and the page for the path. */
function Console({ path, onSignedOut }: { path: string; onSignedOut: () => void }) {
  const context = useAppContext()
  const t = copy.home
  const gives = holdsAnywhere(context, 'access.roles.assign')
  const edits = can(context, 'access.roles.manage')
  const teaches = holdsAnywhere(context, 'academics.manage')
  const home = settingsHome(context)
  const person = /^\/settings\/people\/([0-9a-f-]{36})$/.exec(path)?.[1]
  const seesLearners = holdsAnywhere(context, 'enrolment.learners.view')
  const learner = /^\/learners\/([0-9a-f-]{36})$/.exec(path)?.[1]

  useEffect(() => {
    if (path === '/settings' && home !== null) navigate(home, true)
  }, [path, home])

  let page
  if (path === '/learners' && seesLearners) page = <LearnersScreen />
  else if (path === '/learners/admit' && holdsAnywhere(context, 'enrolment.admit')) page = <AdmitScreen />
  else if (learner !== undefined && seesLearners) page = <LearnerScreen key={learner} id={learner} />
  else if (path === '/settings/people' && gives) page = <PeopleScreen />
  else if (path === '/settings/people/import' && gives) page = <PeopleImportScreen />
  else if (person !== undefined && gives) page = <PersonScreen id={person} />
  else if (path === '/settings/roles' && edits) page = <RolesScreen />
  else if (path === '/settings/subjects' && teaches) page = <SubjectsScreen />
  else if (path === '/settings/teaching' && teaches) page = <TeachingScreen />

  if (page !== undefined) {
    return (
      <ConsoleShell onSignedOut={onSignedOut}>
        {page}
      </ConsoleShell>
    )
  }

  return (
    <ConsoleShell onSignedOut={onSignedOut}>
      <div className="flex flex-col gap-4 px-[clamp(16px,3vw,40px)] pt-7 pb-8">
        <div className="label-caps text-text-muted">{t.kicker}</div>
        <h1 className="type-display text-[clamp(56px,7vw,96px)]">{context.user.name}</h1>
        {context.licence.read_only && (
          <Notice tone="wrong" title={t.readOnlyTitle}>
            {t.readOnly}
          </Notice>
        )}
        <p className="max-w-[70ch] text-text-muted">{t.pending}</p>
      </div>
    </ConsoleShell>
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

  const signedOut = () => {
    setContext(undefined)
    setAccount(null)
    navigate('/', true)
  }

  // Until the school is opened, the administrator works through setup; others wait.
  if (account !== null && context !== null && context !== undefined && context.setup_required) {
    if (can(context, 'institution.manage')) {
      return (
        <ContextProvider value={context}>
          <SetupWizard
            onSignedOut={signedOut}
            onOpened={() => {
              void getContext().then((value) => {
                setContext(value)
                navigate('/', true)
              })
            }}
          />
        </ContextProvider>
      )
    }

    return (
      <AuthLayout school={school}>
        <Heading kicker={copy.home.kicker} title={account.name} />
        <Notice tone="wrong" title={copy.setup.notReadyTitle}>
          {copy.setup.notReady}
        </Notice>
        <Button variant="secondary" className="h-12" onClick={() => void signOut().then(signedOut)}>
          {copy.home.signOut}
        </Button>
      </AuthLayout>
    )
  }

  if (account !== null && context !== null && context !== undefined) {
    return (
      <ContextProvider value={context}>
        <WorkInProvider>
          <Console path={path} onSignedOut={signedOut} />
        </WorkInProvider>
        <SlipPad labels={copy.slips} />
      </ContextProvider>
    )
  }

  let screen
  if (account !== null && context === null) {
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
    screen = <ResetPasswordScreen token={query.get('token') ?? ''} email={query.get('email') ?? ''} invitation={query.get('invite') === '1'} />
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
