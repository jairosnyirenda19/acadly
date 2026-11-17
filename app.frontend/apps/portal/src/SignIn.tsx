import { sendSignInCode, signInWithCode, type Account } from '@acadly/kernel'
import { Button, Notice } from '@acadly/ui'
import { useContext, useRef, useState, type FormEvent } from 'react'
import { Bar, LanguageContext, Screen, useWords } from './parts'

type Problem = 'invalid_phone' | 'wrong_code' | 'paused' | 'unavailable' | null

/**
 * Sign-in (the approved design): the phone number the school has, then the
 * six-digit code that comes by text message. No password, no email.
 */
export function SignIn({ school, onSignedIn }: { school: string; onSignedIn: (account: Account) => void }) {
  const t = useWords()
  const s = t.signIn
  const { language } = useContext(LanguageContext)
  const [phone, setPhone] = useState('')
  const [code, setCode] = useState('')
  const [step, setStep] = useState<'phone' | 'code'>('phone')
  const [working, setWorking] = useState(false)
  const [problem, setProblem] = useState<Problem>(null)
  const codeRef = useRef<HTMLInputElement>(null)

  const send = async () => {
    setWorking(true)
    setProblem(null)
    const outcome = await sendSignInCode(phone, language)
    setWorking(false)

    if (outcome.kind === 'sent') {
      setStep('code')
      setCode('')
      window.setTimeout(() => codeRef.current?.focus(), 0)
    } else setProblem(outcome.kind === 'invalid' ? 'invalid_phone' : outcome.kind)
  }

  const open = async () => {
    setWorking(true)
    setProblem(null)
    const outcome = await signInWithCode(phone, code)
    setWorking(false)

    if (outcome.kind === 'signed_in') onSignedIn(outcome.account)
    else setProblem(outcome.kind === 'incorrect' ? 'wrong_code' : outcome.kind)
  }

  const submit = (event: FormEvent) => {
    event.preventDefault()
    void (step === 'phone' ? send() : open())
  }

  const notice =
    problem === 'invalid_phone'
      ? { title: s.invalidPhoneTitle, text: s.invalidPhone }
      : problem === 'wrong_code'
        ? { title: s.wrongCodeTitle, text: s.wrongCode }
        : problem === 'paused'
          ? { title: s.pausedTitle, text: s.paused }
          : problem === 'unavailable'
            ? { title: t.unavailableTitle, text: t.unavailable }
            : null

  const ready = step === 'phone' ? phone.replace(/\D/g, '').length >= 9 : code.replace(/\D/g, '').length === 6

  return (
    <form onSubmit={submit} noValidate>
      <Screen
        bar={<Bar school={school} />}
        foot={
          <>
            <Button type="submit" className="h-14 w-full text-lg" busy={working} disabled={!ready}>
              {step === 'phone' ? (working ? s.sending : s.sendCode) : working ? s.opening : s.open}
            </Button>
            <p className="mt-2.5 text-[13px] text-text-muted">{s.noPassword}</p>
          </>
        }
      >
        <div className="px-[18px] pt-[22px] pb-6">
          <div className="label-caps text-[11px] text-text-muted">{t.kicker}</div>
          <h1 className="type-display mt-1.5 mb-3.5 text-[52px] leading-[0.86]">{s.title}</h1>
          {notice !== null && (
            <div className="mb-4">
              <Notice tone="wrong" title={notice.title}>
                {notice.text}
              </Notice>
            </div>
          )}

          <label htmlFor="phone" className="mb-1.5 block text-base font-extrabold">
            {s.phone}
          </label>
          <input
            id="phone"
            type="tel"
            inputMode="tel"
            autoComplete="tel"
            value={phone}
            readOnly={step === 'code'}
            aria-invalid={problem === 'invalid_phone'}
            onChange={(e) => setPhone(e.target.value)}
            className={`h-[60px] w-full border-b-[3px] bg-field px-3.5 font-mono text-2xl font-extrabold outline-none focus:outline-[3px] focus:outline-marigold ${problem === 'invalid_phone' ? 'border-red-pen' : 'border-ink'}`}
          />
          <p className="mt-2 mb-[18px] text-[15px]">{s.phoneHint}</p>

          {step === 'code' && (
            <>
              <label htmlFor="code" className="mb-2 block text-base font-extrabold">
                {s.code}
              </label>
              <input
                id="code"
                ref={codeRef}
                inputMode="numeric"
                autoComplete="one-time-code"
                maxLength={7}
                value={code}
                aria-invalid={problem === 'wrong_code'}
                onChange={(e) => setCode(e.target.value.replace(/[^\d ]/g, ''))}
                className={`h-[60px] w-full border-b-[3px] bg-field px-3.5 text-center font-mono text-[30px] font-extrabold tracking-[0.4em] outline-none focus:outline-[3px] focus:outline-marigold ${problem === 'wrong_code' ? 'border-red-pen' : 'border-ink'}`}
              />
              <p className="mt-2.5 text-[15px]">{s.codeHint}</p>
              <div className="mt-2 flex flex-wrap gap-x-5">
                <button type="button" disabled={working} onClick={() => void send()} className="min-h-11 text-[15px] font-extrabold underline underline-offset-[3px]">
                  {s.again}
                </button>
                <button
                  type="button"
                  onClick={() => {
                    setStep('phone')
                    setProblem(null)
                  }}
                  className="min-h-11 text-[15px] font-extrabold underline underline-offset-[3px]"
                >
                  {s.otherNumber}
                </button>
              </div>
              <p className="mt-3 text-[13px] text-text-muted">{s.noCode}</p>
            </>
          )}
        </div>
      </Screen>
    </form>
  )
}
