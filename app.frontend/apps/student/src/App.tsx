import { getAccount, getSchoolLook, getSchoolName, navigate, useLocation, type Account } from '@acadly/kernel'
import { applyLook } from '@acadly/ui'
import { useEffect, useState } from 'react'
import { studentApi, type Me } from './api'
import { CoursePage, TaskPage } from './Classroom'
import { AttendancePage, PhonePage, ScanPage, WeekPage } from './Lectures'
import { CourseResultPage, ResultsPage } from './Results'
import { AccountPage, ArticlePage, CoursesPage, FeesPage, MorePage, NewsPage, RegistrationPage, TodayPage } from './Pages'
import { SignIn } from './SignIn'

/**
 * The student portal (D-101, the approved boards). Phone first: the student
 * signs in with their student number and a password of their own, and sees
 * only their own records: today, courses, results, registration, fees and documents,
 * news, and their account.
 */
export function App() {
  const { path } = useLocation()
  const [account, setAccount] = useState<Account | null | undefined>(undefined)
  const [me, setMe] = useState<Me | null | undefined>(undefined)
  const [school, setSchool] = useState('')

  useEffect(() => {
    void getAccount().then(setAccount)
    void getSchoolName().then((name) => setSchool(name ?? ''))
    void getSchoolLook().then((look) => look !== null && applyLook(look))
  }, [])

  useEffect(() => {
    if (account === null || account === undefined) return
    void studentApi.me().then((answer) => setMe(answer === 'refused' || answer === null ? null : answer))
  }, [account])

  useEffect(() => {
    document.title = school === '' ? 'Student portal' : `${school} · Student portal`
  }, [school])

  const signedOut = () => {
    setAccount(null)
    setMe(undefined)
    navigate('/', true)
  }

  if (account === undefined || (account !== null && me === undefined)) return <div className="min-h-screen bg-paper" />
  if (account === null) {
    return <SignIn school={school} onSignedIn={(signedIn) => (signedIn !== null ? setAccount(signedIn) : void getAccount().then(setAccount), navigate('/', true))} />
  }
  // An account that is not a student's has nothing here.
  if (me === null || me === undefined) return <SignIn school={school} onSignedIn={(signedIn) => setAccount(signedIn)} />

  const article = /^\/news\/([0-9a-f-]{36})$/.exec(path)?.[1]
  const course = /^\/course\/([0-9a-f-]{36})$/.exec(path)?.[1]
  const task = /^\/task\/([0-9a-f-]{36})$/.exec(path)?.[1]
  const result = /^\/results\/([0-9a-f-]{36})$/.exec(path)?.[1]

  if (path === '/courses') return <CoursesPage me={me} />
  if (path === '/registration') return <RegistrationPage me={me} />
  if (path === '/fees') return <FeesPage me={me} />
  if (path === '/news') return <NewsPage me={me} />
  if (article !== undefined) return <ArticlePage key={article} me={me} id={article} />
  if (path === '/more') return <MorePage me={me} onSignedOut={signedOut} />
  if (path === '/account') return <AccountPage me={me} onSignedOut={signedOut} />
  if (path === '/week') return <WeekPage me={me} />
  if (path === '/attendance') return <AttendancePage me={me} />
  if (path === '/phone') return <PhonePage me={me} />
  if (path === '/scan') return <ScanPage />
  if (course !== undefined) return <CoursePage key={course} me={me} id={course} />
  if (task !== undefined) return <TaskPage key={task} me={me} id={task} />
  if (path === '/results') return <ResultsPage me={me} />
  if (result !== undefined) return <CourseResultPage key={result} me={me} id={result} />

  return <TodayPage me={me} />
}
