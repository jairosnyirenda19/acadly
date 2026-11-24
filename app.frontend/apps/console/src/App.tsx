import {
  can,
  CONTEXT_CHANGED,
  ContextProvider,
  getAccount,
  getContext,
  getSchoolName,
  getSchoolLook,
  moduleOn,
  navigate,
  signOut,
  useAppContext,
  useLocation,
  type Account,
  type AppContext,
} from '@acadly/kernel'
import { applyLook, Button, Notice, SlipPad } from '@acadly/ui'
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
import { ReviewStepsScreen } from './settings/ReviewStepsScreen'
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
import { CatalogueScreen } from './programmes/CatalogueScreen'
import { OfferingsScreen } from './programmes/OfferingsScreen'
import { ProgrammeListScreen } from './programmes/ProgrammeListScreen'
import { RegistrationDeskScreen } from './programmes/RegistrationDeskScreen'
import { RegistrationScreen } from './programmes/RegistrationScreen'
import { ProgrammeScreen } from './programmes/ProgrammeScreen'
import { StructureScreen } from './programmes/StructureScreen'
import { StudentsScreen } from './programmes/StudentsScreen'
import { SupportSettingsScreen } from './settings/SupportSettingsScreen'
import { LookScreen } from './settings/LookScreen'
import { DivisionsScreen } from './shell/DivisionsScreen'
import { HomeScreen } from './dashboard/HomeScreen'
import { NewsScreen } from './news/NewsScreen'
import { CoursesScreen as ClassroomCoursesScreen } from './classroom/CoursesScreen'
import { CourseScreen as ClassroomCourseScreen } from './classroom/CourseScreen'
import { AssessmentScreen as ClassroomAssessmentScreen, HandInsScreen, MarkingScreen, TaskFormScreen } from './classroom/TaskScreens'
import { ReleaseScreen as ResultsReleaseScreen, SetupScreen as ResultsSetupScreen } from './results/AdminScreens'
import { BarScreen as ResultsBarScreen, BoardScreen as ResultsBoardScreen, CohortScreen as ResultsCohortScreen } from './results/BoardScreens'
import { ResultsCoursesScreen } from './results/CoursesScreen'
import { GraduationListScreen, GraduationsScreen, ProgressionListScreen, ProgressionsScreen, TranscriptsScreen } from './results/EndOfYearScreens'
import { SheetScreen as ResultsSheetScreen } from './results/SheetScreen'
import { CoverScreen } from './lectures/CoverScreen'
import { LectureScreen } from './lectures/LectureScreen'
import { MyLecturesScreen } from './lectures/MyLecturesScreen'
import { RoomScreen } from './lectures/RoomScreen'
import { RoomsScreen } from './lectures/RoomsScreen'
import { TimetableScreen as LectureTimetableScreen } from './lectures/TimetableScreen'
import { AccountsScreen as StudentAccountsScreen } from './programmes/AccountsScreen'
import { SchoolLearnersScreen } from './dashboard/SchoolLearnersScreen'
import { SchoolLedgerScreen } from './dashboard/SchoolLedgerScreen'
import { WatchScreen } from './support/WatchScreen'
import { OpenPlanScreen } from './support/OpenPlanScreen'
import { PlanScreen } from './support/PlanScreen'
import { MyRoomScreen } from './early/MyRoomScreen'
import { ObservationScreen } from './early/ObservationScreen'
import { ReviewsScreen } from './early/ReviewsScreen'
import { ReviewScreen } from './early/ReviewScreen'
import { PickupScreen } from './early/PickupScreen'
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
import { keepsGrading, namesSteps, observes, seesClassroom, seesLectures, seesResults, seesMarks, seesObservations, seesPickup, seesProgrammes, seesRegisters, takesRegisters } from './shell/navigation'
import { WorkInProvider } from './shell/WorkIn'
import { SignInScreen } from './sign-in/SignInScreen'
import { StatusScreen } from './StatusScreen'
import { CheckScreen } from './CheckScreen'

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
  const support = moduleOn(context, 'support')
  const plan = /^\/support\/plans\/([0-9a-f-]{36})$/.exec(path)?.[1]
  const review = /^\/reviews\/([0-9a-f-]{36})$/.exec(path)?.[1]
  const incident = /^\/conduct\/incidents\/([0-9a-f-]{36})$/.exec(path)?.[1]
  const registers = seesRegisters(context)
  const register = /^\/registers\/([0-9a-f-]{36})$/.exec(path)?.[1]
  const lesson = /^\/registers\/lesson\/([0-9a-f-]{36})$/.exec(path)?.[1]
  const sheet = /^\/marks\/([0-9a-f-]{36})$/.exec(path)?.[1]
  const marks = seesMarks(context)
  const cards = /^\/marks\/cards\/([0-9a-f-]{36})$/.exec(path)?.[1]
  const exported = /^\/exports\/([0-9a-f-]{36})$/.exec(path)?.[1]
  const approval = /^\/approvals\/([0-9a-f-]{36})$/.exec(path)?.[1]
  const programmes = seesProgrammes(context)
  const programme = /^\/programmes\/p\/([0-9a-f-]{36})$/.exec(path)?.[1]
  const registration = /^\/programmes\/r\/([0-9a-f-]{36})$/.exec(path)?.[1]
  const classCourse = /^\/classroom\/c\/([0-9a-f-]{36})$/.exec(path)?.[1]
  const classTaskNew = /^\/classroom\/c\/([0-9a-f-]{36})\/task(?:\/([0-9a-f-]{36}))?$/.exec(path)
  const classAssessment = /^\/classroom\/c\/([0-9a-f-]{36})\/assessment$/.exec(path)?.[1]
  const classTask = /^\/classroom\/t\/([0-9a-f-]{36})$/.exec(path)?.[1]
  const classWork = /^\/classroom\/t\/([0-9a-f-]{36})\/w\/([0-9a-f-]{36})$/.exec(path)
  const classroom = seesClassroom(context)
  const resultsSheet = /^\/results\/c\/([0-9a-f-]{36})$/.exec(path)?.[1]
  const results = seesResults(context)
  const board = seesResults(context, ['results.view', 'results.moderate', 'results.board', 'results.release'])
  const graduation = seesResults(context, ['results.view', 'results.moderate', 'results.board', 'results.release', 'results.award'])
  const transcripts = seesResults(context, ['results.view', 'results.moderate', 'results.board', 'results.release', 'results.transcripts'])
  const lecture = /^\/lectures\/l\/([0-9a-f-]{36})\/(\d{4}-\d{2}-\d{2})$/.exec(path)

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
  else if (path === '/settings/support' && support && can(context, 'support.manage')) page = <SupportSettingsScreen />
  else if (path === '/settings/branding' && can(context, 'institution.manage')) page = <LookScreen />
  else if (path === '/divisions') page = <DivisionsScreen />
  else if (path === '/school/learners' && holdsAnywhere(context, 'institution.overview')) page = <SchoolLearnersScreen />
  else if (path === '/school/ledger' && fees && holdsAnywhere(context, 'institution.overview')) page = <SchoolLedgerScreen />
  else if (path === '/programmes' && programmes) page = <StructureScreen />
  else if (path === '/programmes/list' && programmes) page = <ProgrammeListScreen />
  else if (programme !== undefined && programmes) page = <ProgrammeScreen key={programme} id={programme} />
  else if (path === '/programmes/courses' && programmes) page = <CatalogueScreen />
  else if (path === '/programmes/offerings' && programmes) page = <OfferingsScreen />
  else if (path === '/programmes/students' && programmes) page = <StudentsScreen />
  else if (path === '/programmes/accounts' && programmes && holdsAnywhere(context, 'programmes.accounts')) page = <StudentAccountsScreen />
  else if (path === '/news' && moduleOn(context, 'news')) page = <NewsScreen />
  else if (path === '/classroom' && classroom) page = <ClassroomCoursesScreen />
  else if (classCourse !== undefined && classroom) {
    const tab = new URLSearchParams(window.location.search).get('tab')
    page = <ClassroomCourseScreen key={path + (tab ?? '')} id={classCourse} tab={tab === 'announcements' || tab === 'tasks' ? tab : 'resources'} />
  } else if (classTaskNew !== null && classroom) page = <TaskFormScreen key={path} course={classTaskNew[1] ?? ''} task={classTaskNew[2] ?? null} />
  else if (classAssessment !== undefined && classroom) page = <ClassroomAssessmentScreen key={path} course={classAssessment} />
  else if (classTask !== undefined && classroom) page = <HandInsScreen key={path} task={classTask} />
  else if (classWork !== null && classroom) page = <MarkingScreen key={path} task={classWork[1] ?? ''} learner={classWork[2] ?? ''} />
  else if (path === '/results' && results) page = <ResultsCoursesScreen />
  else if (resultsSheet !== undefined && results) {
    const sitting = new URLSearchParams(window.location.search).get('sitting')
    page = <ResultsSheetScreen key={path + (sitting ?? '')} course={resultsSheet} sitting={sitting === 'supplementary' ? 'supplementary' : 'main'} />
  } else if (path === '/results/bar' && results) page = <ResultsBarScreen />
  else if (path === '/results/setup' && results) page = <ResultsSetupScreen />
  else if (path === '/results/board' && board) page = <ResultsBoardScreen />
  else if (path === '/results/board/cohort' && board) {
    const cohort = new URLSearchParams(window.location.search).get('c') ?? ''
    page = <ResultsCohortScreen key={cohort} cohort={cohort} />
  } else if (path === '/results/release' && board) page = <ResultsReleaseScreen />
  else if (path === '/results/progression' && board) page = <ProgressionsScreen />
  else if (path === '/results/progression/list' && board) {
    const q = new URLSearchParams(window.location.search)
    page = <ProgressionListScreen key={window.location.search} programme={q.get('p') ?? ''} year={Number(q.get('y') ?? '1')} />
  } else if (path === '/results/graduation' && graduation) page = <GraduationsScreen />
  else if (path === '/results/graduation/list' && graduation) page = <GraduationListScreen key={window.location.search} programme={new URLSearchParams(window.location.search).get('p') ?? ''} />
  else if (path === '/results/transcripts' && transcripts) page = <TranscriptsScreen />
  else if (path === '/lectures/my-day' && seesLectures(context, ['lectures.teach'])) page = <MyLecturesScreen />
  else if (path === '/lectures/timetable' && seesLectures(context, ['lectures.view', 'lectures.timetable', 'lectures.teach', 'lectures.cover'])) page = <LectureTimetableScreen />
  else if (path === '/lectures/rooms' && seesLectures(context, ['lectures.view', 'lectures.timetable'])) page = <RoomsScreen />
  else if (path === '/lectures/cover' && seesLectures(context, ['lectures.view', 'lectures.cover'])) page = <CoverScreen />
  else if (lecture !== null && seesLectures(context, ['lectures.teach', 'lectures.cover', 'lectures.view'])) page = <LectureScreen key={path} slot={lecture[1] ?? ''} day={lecture[2] ?? ''} />
  else if (path === '/programmes/registration' && programmes) page = <RegistrationDeskScreen />
  else if (registration !== undefined && programmes) page = <RegistrationScreen key={registration} id={registration} />
  else if (path === '/support' && support && (holdsAnywhere(context, 'support.view') || holdsAnywhere(context, 'support.lead'))) page = <WatchScreen />
  else if (path === '/support/plans/new' && support && holdsAnywhere(context, 'support.lead')) page = <OpenPlanScreen />
  else if (plan !== undefined && support) page = <PlanScreen key={plan} id={plan} />
  else if (path === '/room' && seesObservations(context)) page = <MyRoomScreen />
  else if (path === '/observations' && observes(context) && holdsAnywhere(context, 'assessment.observe')) page = <ObservationScreen />
  else if (path === '/reviews' && seesObservations(context)) page = <ReviewsScreen />
  else if (review !== undefined && seesObservations(context)) page = <ReviewScreen key={review} learnerId={review} />
  else if (path === '/pickup' && seesPickup(context)) page = <PickupScreen />
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
  else if (path === '/settings/review-steps' && namesSteps(context)) page = <ReviewStepsScreen />
  else if (path === '/settings/assessment/scales' && keepsGrading(context)) page = <AssessmentSettingsScreen view="scales" />
  else if (path === '/settings/approvals' && can(context, 'access.approvals.manage')) page = <ApprovalSettingsScreen />
  else if (path === '/approvals') page = <ApprovalsScreen />
  // A request is shown to the person who asked, those asked, and those who may decide it; the server decides.
  else if (approval !== undefined) page = <ApprovalScreen key={approval} id={approval} />
  // Anyone signed in may open an export; the server shows each person only their own.
  else if (exported !== undefined) page = <ExportScreen key={exported} id={exported} />

  // The published report cards and their PDFs (D-107). The server decides who may read them.
  if (cards !== undefined && marks) page = <ReportCardScreen key={cards} id={cards} />

  if (page !== undefined) {
    return (
      <ConsoleShell onSignedOut={onSignedOut}>
        {page}
      </ConsoleShell>
    )
  }

  return (
    <ConsoleShell onSignedOut={onSignedOut}>
      {context.licence.read_only && (
        <div className="px-[clamp(16px,3vw,40px)] pt-6">
          <Notice tone="wrong" title={t.readOnlyTitle}>
            {t.readOnly}
          </Notice>
        </div>
      )}
      <HomeScreen />
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
    void getSchoolLook().then((look) => look !== null && applyLook(look))
  }, [])

  // The school's own colours, as soon as the context says them (D-096).
  useEffect(() => {
    if (context !== null && context !== undefined) applyLook(context.school.look)
  }, [context])

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

  // The public document check needs no sign-in (D-096).
  if (path === '/check') {
    return <CheckScreen school={school} />
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

  // The room screen: full screen on the room's projector, with nothing else around it (D-102).
  const roomScreen = /^\/lectures\/screen\/([0-9a-f-]{36})\/([0-9a-f-]{36})$/.exec(path)
  if (account !== null && context !== null && context !== undefined && roomScreen !== null) {
    return (
      <ContextProvider value={context}>
        <WorkInProvider>
          <RoomScreen division={roomScreen[1] ?? ''} session={roomScreen[2] ?? ''} />
        </WorkInProvider>
      </ContextProvider>
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
