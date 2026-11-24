import { getAccount, getSchoolLook, getSchoolName, navigate, signOut, useLocation, type Account } from '@acadly/kernel'
import { applyLook } from '@acadly/ui'
import { useEffect, useState } from 'react'
import { AttendancePage, CardPage, MessagesPage, ReasonPage } from './ChildPages'
import { PickupPage, ReviewPage } from './EarlyPages'
import { ConductPage, DocumentsPage, PlanPage } from './FamilyPages'
import { FeesPage, PaymentsPage } from './FeesPages'
import type { Language } from './copy'
import { ChildScreen, Home } from './Family'
import { keepLanguage, LanguageContext, savedLanguage } from './parts'
import { SignIn } from './SignIn'

/**
 * The parent portal (the approved design, D-082). Phone first: a phone
 * number and a code, the family's children, and for each child the report
 * card, attendance, messages and the school's number.
 */
export function App() {
  const { path } = useLocation()
  const [account, setAccount] = useState<Account | null | undefined>(undefined)
  const [school, setSchool] = useState<string | null>(null)
  const [language, setLanguage] = useState<Language>(savedLanguage)

  useEffect(() => {
    void getAccount().then(setAccount)
    void getSchoolName().then(setSchool)
    void getSchoolLook().then((look) => look !== null && applyLook(look))
  }, [])

  useEffect(() => {
    if (school !== null) document.title = school
    document.documentElement.lang = language
  }, [school, language])

  const choose = (next: Language) => {
    keepLanguage(next)
    setLanguage(next)
  }

  let screen
  const child = /^\/child\/([0-9a-f-]{36})(?:\/(card|attendance|messages|reason|review|pickup|fees|payments|conduct|plan|documents)(?:\/(\d{4}-\d{2}-\d{2}))?)?$/.exec(path)

  if (account === undefined) screen = <div className="min-h-screen bg-paper" />
  else if (account === null) {
    screen = (
      <SignIn
        school={school ?? ''}
        onSignedIn={(signedIn) => {
          setAccount(signedIn)
          navigate('/', true)
        }}
      />
    )
  } else if (child !== null && child[1] !== undefined) {
    const id = child[1]
    screen =
      child[2] === 'card' ? (
        <CardPage id={id} />
      ) : child[2] === 'attendance' ? (
        <AttendancePage id={id} />
      ) : child[2] === 'messages' ? (
        <MessagesPage id={id} />
      ) : child[2] === 'review' ? (
        <ReviewPage id={id} />
      ) : child[2] === 'fees' ? (
        <FeesPage id={id} />
      ) : child[2] === 'payments' ? (
        <PaymentsPage id={id} />
      ) : child[2] === 'conduct' ? (
        <ConductPage id={id} />
      ) : child[2] === 'plan' ? (
        <PlanPage id={id} />
      ) : child[2] === 'documents' ? (
        <DocumentsPage id={id} />
      ) : child[2] === 'pickup' ? (
        <PickupPage id={id} />
      ) : child[2] === 'reason' ? (
        <ReasonPage id={id} day={child[3] ?? null} />
      ) : (
        <ChildScreen id={id} />
      )
  } else {
    screen = <Home onSignOut={() => void signOut().then(() => setAccount(null))} />
  }

  return <LanguageContext.Provider value={{ language, choose }}>{screen}</LanguageContext.Provider>
}
