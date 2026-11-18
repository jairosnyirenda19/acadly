import {
  can,
  CONTEXT_CHANGED,
  ContextProvider,
  getAccount,
  getContext,
  getSchoolName,
  moduleOn,
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
import { ExportScreen } from './exports/ExportScreen'
import { MarksScreen } from './assessment/MarksScreen'
import { NewAssessmentScreen } from './assessment/NewAssessmentScreen'
import { CommentsScreen } from './assessment/CommentsScreen'
import { ReportCardScreen } from './assessment/ReportCardScreen'
import { ResultsScreen } from './assessment/ResultsScreen'
import { SheetScreen } from './assessment/SheetScreen'
import { AssessmentSettingsScreen } from './settings/AssessmentSettingsScreen'
import { LearnerImportScreen } from './learners/LearnerImportScreen'
import { LearnerScreen } from './learners/LearnerScreen'
import { FamiliesScreen } from './learners/FamiliesScreen'
import { LearnersScreen } from './learners/LearnersScreen'
import { RegisterScreen } from './registers/RegisterScreen'
import { RegistersScreen } from './registers/RegistersScreen'
import { LessonRegisterScreen } from './registers/LessonRegisterScreen'
import { LessonsScreen } from './registers/LessonsScreen'
import { MissedScreen } from './registers/MissedScreen'
import { MyDayScreen } from './registers/MyDayScreen'
import { AccountScreen } from './account/AccountScreen'
import { SearchScreen } from './search/SearchScreen'
import { NextYearScreen } from './settings/NextYearScreen'
import { DocumentTypesScreen } from './settings/DocumentTypesScreen'
import { FeesSettingsScreen } from './settings/FeesSettingsScreen'
import { ConductSettingsScreen } from './settings/ConductSettingsScreen'
import { OverviewScreen as ConductOverviewScreen } from './conduct/OverviewScreen'
import { RecordConductScreen } from './conduct/RecordConductScreen'
import { IncidentScreen } from './conduct/IncidentScreen'
import { DeskScreen } from './fees/DeskScreen'
import { RecordPaymentScreen } from './fees/RecordPaymentScreen'
import { ReceiptScreen } from './fees/ReceiptScreen'
import { ShopScreen } from './fees/ShopScreen'
import { YearEndScreen } from './learners/YearEndScreen'
import { PasswordNeeded } from './account/PasswordNeeded'
import { TimetableScreen } from './settings/TimetableScreen'
import { PeopleImportScreen } from './settings/PeopleImportScreen'
import { PeopleScreen } from './settings/PeopleScreen'
import { PersonScreen } from './settings/PersonScreen'
import { RolesScreen } from './settings/RolesScreen'
import { SubjectsScreen } from './settings/SubjectsScreen'
import { ModulesScreen } from './settings/ModulesScreen'
import { ChannelsScreen } from './messages/ChannelsScreen'
import { SentScreen } from './messages/SentScreen'
import { WordingScreen } from './messages/WordingScreen'
import { TeachingScreen } from './settings/TeachingScreen'
import { ApprovalScreen, ApprovalsScreen } from './approvals/ApprovalsScreen'
import { ApprovalSettingsScreen } from './approvals/ApprovalSettingsScreen'
import { SetupWizard } from './setup/SetupWizard'
import { ConsoleShell, holdsAnywhere, settingsHome } from './shell/ConsoleShell'
import { keepsGrading, seesMarks, seesRegisters, takesRegisters } from './shell/navigation'
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
  const receipt = /^\/fees\/receipts\/([0-9a-f-]{36})$/.exec(path)?.[1]
  const fees = moduleOn(context, 'fees')
  const conduct = moduleOn(context, 'conduct')
  const incident = /^\/conduct\/incidents\/([0-9a-f-]{36})$/.exec(path)?.[1]
  const registers = seesRegisters(context)
  const register = /^\/registers\/([0-9a-f-]{36})$/.exec(path)?.[1]
  const lesson = /^\/registers\/lesson\/([0-9a-f-]{36})$/.exec(path)?.[1]
  const sheet = /^\/marks\/([0-9a-f-]{36})$/.exec(path)?.[1]
  const marks = seesMarks(context)
  const cards = /^\/marks\/cards\/([0-9a-f-]{36})$/.exec(path)?.[1]
  const exported = /^\/exports\/([0-9a-f-]{36})$/.exec(path)?.[1]
  const approval = /^\/approvals\/([0-9a-f-]{36})$/.exec(path)?.[1]

  useEffect(() => {
    if (path === '/settings' && home !== null) navigate(home, true)
  }, [path, home])

  let page
  if (path === '/my-day' && takesRegisters(context)) page = <MyDayScreen />
  else if (path === '/account') page = <AccountScreen />
  else if (path === '/search') page = <SearchScreen />
  else if (path === '/settings/next-year' && can(context, 'academics.manage')) page = <NextYearScreen />
  else if (path === '/settings/learner-documents' && can(context, 'enrolment.manage')) page = <DocumentTypesScreen />
  else if (path === '/settings/fees' && fees && can(context, 'fees.manage')) page = <FeesSettingsScreen />
  else if (path === '/settings/conduct' && conduct && can(context, 'conduct.manage')) page = <ConductSettingsScreen />
  else if (path === '/conduct' && conduct && (can(context, 'conduct.view') || can(context, 'conduct.follow_up'))) page = <ConductOverviewScreen />
  else if (path === '/conduct/record' && conduct && holdsAnywhere(context, 'conduct.record')) page = <RecordConductScreen />
  else if (incident !== undefined && conduct) page = <IncidentScreen key={incident} id={incident} />
  else if (path === '/fees' && fees && can(context, 'fees.collect')) page = <DeskScreen />
  else if (path === '/fees/pay' && fees && holdsAnywhere(context, 'fees.collect')) page = <RecordPaymentScreen />
  else if (path === '/fees/shop' && fees && holdsAnywhere(context, 'fees.collect')) page = <ShopScreen />
  else if (receipt !== undefined && fees) page = <ReceiptScreen key={receipt} id={receipt} />
  else if (path === '/learners/year-end' && holdsAnywhere(context, 'enrolment.manage')) page = <YearEndScreen />
  else if (path === '/registers' && registers) page = <RegistersScreen />
  else if (path === '/registers/lessons' && registers) page = <LessonsScreen />
  else if (path === '/registers/missed' && registers) page = <MissedScreen />
  else if (lesson !== undefined && registers) page = <LessonRegisterScreen key={lesson} slot={lesson} />
  else if (register !== undefined && registers) page = <RegisterScreen key={register} classId={register} />
  else if (path === '/learners' && seesLearners) page = <LearnersScreen />
  else if (path === '/learners/families' && holdsAnywhere(context, 'enrolment.families')) page = <FamiliesScreen />
  else if (path === '/learners/admit' && holdsAnywhere(context, 'enrolment.admit')) page = <AdmitScreen />
  else if (path === '/learners/import' && holdsAnywhere(context, 'enrolment.admit')) page = <LearnerImportScreen />
  else if (learner !== undefined && seesLearners) page = <LearnerScreen key={learner} id={learner} />
  else if (path === '/settings/people' && gives) page = <PeopleScreen />
  else if (path === '/settings/people/import' && gives) page = <PeopleImportScreen />
  else if (person !== undefined && gives) page = <PersonScreen id={person} />
  else if (path === '/settings/roles' && edits) page = <RolesScreen />
  else if (path === '/settings/subjects' && teaches) page = <SubjectsScreen />
  else if (path === '/settings/teaching' && teaches) page = <TeachingScreen />
  else if (path === '/settings/timetable' && teaches) page = <TimetableScreen view="bells" />
  else if (path === '/settings/timetable/classes' && teaches) page = <TimetableScreen view="classes" />
  else if (path === '/settings/modules' && can(context, 'modules.manage')) page = <ModulesScreen />
  else if (path === '/settings/messages' && can(context, 'notify.manage')) page = <ChannelsScreen />
  else if (path === '/settings/messages/wording' && can(context, 'notify.manage')) page = <WordingScreen />
  else if (path === '/settings/messages/sent' && can(context, 'notify.manage')) page = <SentScreen />
  else if (path === '/marks' && marks) page = <MarksScreen view="mine" />
  else if (path === '/marks/all' && marks) page = <MarksScreen view="all" />
  else if (path === '/marks/new' && marks) page = <NewAssessmentScreen />
  else if (path === '/marks/results' && marks) page = <ResultsScreen />
  else if (path === '/marks/publish' && marks) page = <MarksScreen view="publish" />
  else if (path === '/marks/comments' && marks) page = <CommentsScreen />
  else if (sheet !== undefined && marks) page = <SheetScreen key={sheet} id={sheet} />
  else if (path === '/settings/assessment' && keepsGrading(context)) page = <AssessmentSettingsScreen view="plans" />
  else if (path === '/settings/assessment/scales' && keepsGrading(context)) page = <AssessmentSettingsScreen view="scales" />
  else if (path === '/settings/approvals' && can(context, 'access.approvals.manage')) page = <ApprovalSettingsScreen />
  else if (path === '/approvals') page = <ApprovalsScreen />
  // A request is shown to the person who asked, those asked, and those who may decide it; the server decides.
  else if (approval !== undefined) page = <ApprovalScreen key={approval} id={approval} />
  // Anyone signed in may open an export; the server shows each person only their own.
  else if (exported !== undefined) page = <ExportScreen key={exported} id={exported} />

  // A report card is a printed page: no console around it. The server decides who may read it.
  if (cards !== undefined && marks) return <ReportCardScreen key={cards} id={cards} />

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
    const load = () =>
      void getContext().then((value) => {
        if (current) setContext(value)
      })
    load()
    window.addEventListener(CONTEXT_CHANGED, load)

    return () => {
      current = false
      window.removeEventListener(CONTEXT_CHANGED, load)
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

  // A sign-in by code opens the parent portal only (D-087).
  if (account !== null && account.signed_in_by === 'code') {
    return (
      <AuthLayout school={school}>
        <PasswordNeeded onSignedOut={signedOut} />
      </AuthLayout>
    )
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
