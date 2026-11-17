import { getAccount, getSchoolName, navigate, signOut, useLocation, type Account } from '@acadly/kernel'
import { useEffect, useState } from 'react'
import { AttendancePage, CardPage, MessagesPage, ReasonPage } from './ChildPages'
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
  const child = /^\/child\/([0-9a-f-]{36})(?:\/(card|attendance|messages|reason)(?:\/(\d{4}-\d{2}-\d{2}))?)?$/.exec(path)

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
