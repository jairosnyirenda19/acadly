import { useLoad } from '@acadly/kernel'
import { Button } from '@acadly/ui'
import { useContext, useId, useState } from 'react'
import { portalApi, type PickupState } from './api'
import { ChildPage } from './ChildPages'
import { LanguageContext, Mark, Note, useWords, type Tone } from './parts'

const strokes: Record<string, string> = { starting: '/', growing: '//', can_do: '///' }
const fills: Record<string, string> = { starting: 'bg-teal-wash text-ink', growing: 'bg-teal-mid text-ink', can_do: 'bg-teal text-sheet' }

/** The progress review on a phone (the approved design): a step and its word in each learning area, then the two notes. */
export function ReviewPage({ id }: { id: string }) {
  const t = useWords().review
  const { language } = useContext(LanguageContext)
  const loaded = useLoad(`portal:review:${id}`, () => portalApi.review(id))
  // The school names the steps (D-109). The server gives its English words; in Chichewa the portal keeps its own.
  const word = (a: { step: string; label: string; label_ny?: string }) => (language === 'en' ? a.label : a.label_ny !== undefined && a.label_ny !== '' ? a.label_ny : (t.steps[a.step] ?? a.label))

  return (
    <ChildPage
      id={id}
      phase={loaded.phase}
      data={loaded.data}
      onRetry={loaded.reload}
      foot={
        loaded.data !== undefined && loaded.data.review !== null ? (
          <div className="grid gap-2">
            <a href={portalApi.reviewPdf(id)} target="_blank" rel="noopener" className="flex h-14 items-center justify-center bg-teal text-lg font-extrabold text-sheet no-underline">
              {t.download}
            </a>
            <p className="text-[13px] text-text-muted">{t.downloadNote}</p>
          </div>
        ) : undefined
      }
    >
      {(data) => {
        const review = data.review

        if (review === null) {
          return (
            <div className="px-4 pt-4">
              <h1 className="type-display mb-3 text-[34px] leading-[0.9]">{data.child.name}</h1>
              <Note tone="plain" title={t.none}>
                {t.noneNote}
              </Note>
            </div>
          )
        }

        return (
          <div className="px-4 pt-4 pb-6">
            <section className="border-2 border-ink bg-sheet">
              <div className="bg-ink px-3.5 py-2.5 text-sheet">
                <div className="label-caps text-[10px] text-on-ink-muted">{t.kicker(review.period)}</div>
                <h1 className="type-display text-[26px] leading-none">{t.title(data.child.first_name)}</h1>
              </div>
              <ul>
                {review.areas.map((a) => (
                  <li key={a.name} className="flex min-h-[48px] items-center gap-2.5 border-b border-rule px-3.5">
                    <span aria-hidden="true" className={`inline-flex h-7 w-11 shrink-0 items-center justify-center text-sm font-extrabold ${fills[a.step] ?? 'bg-field'}`}>
                      {strokes[a.step] ?? ''}
                    </span>
                    <span className="min-w-0 flex-1 text-[15px] font-bold">{a.name}</span>
                    <span className="text-sm text-text-muted">{word(a)}</span>
                  </li>
                ))}
              </ul>
              <div className="grid gap-3 px-3.5 py-3">
                <div className="border-l-[6px] border-ink bg-paper px-3 py-2.5">
                  <div className="label-caps text-[10px] text-text-muted">{t.family}</div>
                  <p className="text-base leading-snug">{review.family_note}</p>
                </div>
                {review.home_note !== null && review.home_note !== '' && (
                  <div className="border-l-[6px] border-teal bg-paper px-3 py-2.5">
                    <div className="label-caps text-[10px] text-text-muted">{t.home}</div>
                    <p className="text-base leading-snug">{review.home_note}</p>
                  </div>
                )}
              </div>
            </section>
            <p className="mt-3 text-xs text-text-muted">{t.note}</p>
          </div>
        )
      }}
    </ChildPage>
  )
}

/** Pick-up on a phone (the approved design): today, who may collect, and someone else for today with a code. */
export function PickupPage({ id }: { id: string }) {
  const loaded = useLoad(`portal:pickup:${id}`, () => portalApi.pickup(id))

  return (
    <ChildPage id={id} phase={loaded.phase} data={loaded.data} onRetry={loaded.reload}>
      {(data) => <Pickup id={id} data={data} onAdded={loaded.reload} />}
    </ChildPage>
  )
}

/** Someone the family just named for today: the code, and whether the school sent it to their phone. */
interface Named {
  name: string
  relationship: string
  code: string
  asked: boolean
  sentTo: string | null
  at: string
}

const firstWord = (name: string) => name.trim().split(/\s+/)[0] ?? name

function Pickup({ id, data, onAdded }: { id: string; data: PickupState; onAdded: () => void }) {
  const t = useWords().pickup
  const [adding, setAdding] = useState(false)
  const [name, setName] = useState('')
  const [relationship, setRelationship] = useState('')
  const [phone, setPhone] = useState('')
  const [send, setSend] = useState(true)
  const [working, setWorking] = useState(false)
  const [failed, setFailed] = useState(false)
  const [named, setNamed] = useState<Named | null>(null)
  const [school, setSchool] = useState<string | null>(null)
  const ids = { name: useId(), relationship: useId(), phone: useId(), hint: useId(), send: useId() }
  const today = data.today
  const tone: Tone = today === null ? 'plain' : today.state === 'released' ? 'done' : 'wrong'
  const typed = phone.trim() !== ''
  const who = firstWord(name)

  const add = async () => {
    setWorking(true)
    setFailed(false)
    const asked = send && typed
    const result = await portalApi.collectToday(id, name.trim(), relationship.trim(), phone.trim(), asked)
    setWorking(false)
    if (result === null) return setFailed(true)
    setNamed({
      name: name.trim(),
      relationship: relationship.trim(),
      code: result.code,
      asked,
      sentTo: result.sent_to,
      at: new Date().toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' }),
    })
    if (result.sent_to !== null) void portalApi.shortName().then(setSchool)
    setAdding(false)
    setName('')
    setRelationship('')
    setPhone('')
    setSend(true)
    onAdded()
  }

  if (named !== null) return <NamedToday named={named} child={data.child.first_name} school={school} onDone={() => setNamed(null)} />

  const field = 'h-12 w-full border-b-[3px] border-ink bg-field px-3 text-base outline-none focus:outline-[3px] focus:outline-marigold'

  return (
    <div className="grid gap-3 px-4 pt-4 pb-6">
      <div>
        <div className="label-caps text-[11px] text-text-muted">{t.kicker}</div>
        <h1 className="type-display mt-1 text-[34px] leading-[0.9]">{data.child.first_name}</h1>
      </div>
      <div className={`flex items-start gap-2.5 px-3 py-2.5 text-[15px] ${tone === 'done' ? 'bg-teal-wash' : tone === 'wrong' ? 'border-2 border-red-pen bg-red-wash' : 'bg-sheet'}`}>
        <Mark tone={tone} />
        <span>{today === null ? t.notCollected : today.state === 'released' ? t.collected(`${today.at} · ${today.name}${today.relationship !== null ? ` (${today.relationship})` : ''}`) : t.refused(today.at)}</span>
      </div>

      <section className="grid gap-2 border-2 border-ink bg-sheet px-3.5 py-3">
        <h2 className="label-caps text-[11px] text-text-muted">{t.whoMay(data.child.first_name)}</h2>
        <ul>
          {data.may_collect.map((c, i) => (
            <li key={i} className="flex flex-wrap justify-between gap-2 border-b border-rule py-1.5 text-[15px]">
              <b>{c.name}</b>
              <span className="text-text-muted">{c.day_only ? `${c.relationship} · ${t.todayOnly}` : c.relationship}</span>
            </li>
          ))}
        </ul>
        <p className="text-xs text-text-muted">{t.rule}</p>
        {!adding ? (
          <Button variant="secondary" className="h-12 w-full" onClick={() => setAdding(true)}>
            {t.someoneElse}
          </Button>
        ) : (
          <div className="grid gap-2.5">
            <h2 className="type-display text-[26px] leading-none">{t.title}</h2>
            <p className="text-sm">{t.someoneElseNote}</p>
            <label htmlFor={ids.name} className="text-sm font-extrabold">
              {t.name}
            </label>
            <input id={ids.name} value={name} maxLength={120} autoComplete="off" onChange={(e) => setName(e.target.value)} className={field} />
            <label htmlFor={ids.relationship} className="text-sm font-extrabold">
              {t.relationship}
            </label>
            <input id={ids.relationship} value={relationship} maxLength={60} autoComplete="off" onChange={(e) => setRelationship(e.target.value)} className={field} />
            <label htmlFor={ids.phone} className="text-sm font-extrabold">
              {t.phone}
            </label>
            <input id={ids.phone} type="tel" inputMode="tel" value={phone} maxLength={30} aria-describedby={ids.hint} onChange={(e) => setPhone(e.target.value)} className={field} />
            <span id={ids.hint} className="text-[13px] text-text-muted">
              {t.phoneHint}
            </span>
            {typed && (
              <label htmlFor={ids.send} className="flex min-h-12 items-center gap-3 text-[15px] font-bold">
                <input id={ids.send} type="checkbox" className="size-6 shrink-0 accent-ink" checked={send} onChange={(e) => setSend(e.target.checked)} />
                {t.sendCode(who !== '' ? who : t.name)}
              </label>
            )}
            {failed && <Note tone="attention" title={t.failed} />}
            <Button className="h-13 w-full text-base" busy={working} disabled={name.trim() === '' || relationship.trim() === ''} onClick={() => void add()}>
              {who !== '' ? t.addFor(who) : t.add}
            </Button>
            <p className="text-[13px] text-text-muted">{t.addNote(data.child.first_name)}</p>
            <Button variant="secondary" className="h-12 w-full" onClick={() => setAdding(false)}>
              {t.cancel}
            </Button>
          </div>
        )}
      </section>
    </div>
  )
}

/** The code for today (the approved design): shown big, and the text the person gets when the school sent it. */
function NamedToday({ named, child, school, onDone }: { named: Named; child: string; school: string | null; onDone: () => void }) {
  const t = useWords().pickup
  const who = firstWord(named.name)

  return (
    <div className="grid gap-3 px-4 pt-4 pb-6">
      <div>
        <div className="label-caps text-[11px] text-text-muted">{t.today}</div>
        <h1 className="type-display mt-1 text-[34px] leading-[0.9]">{t.collects(who)}</h1>
      </div>
      {named.sentTo !== null && (
        <div role="status" className="flex items-baseline gap-2.5 border border-teal bg-teal-wash px-3 py-2.5 text-[15px]">
          <strong aria-hidden="true">✓</strong>
          <span>
            <strong>{t.sentTo(who)}</strong>
            {t.sentOn(named.sentTo, named.at)}
          </span>
        </div>
      )}
      {named.asked && named.sentTo === null && (
        <div role="status" className="flex items-baseline gap-2.5 border border-marigold bg-marigold-wash px-3 py-2.5 text-[15px]">
          <strong aria-hidden="true">!</strong>
          <span>{t.notSent(who)}</span>
        </div>
      )}
      <section className="border-2 border-ink bg-ink px-4 py-3.5 text-sheet">
        <div className="label-caps text-[10px] text-on-ink-muted">{t.codeToday}</div>
        <div className="type-display text-[64px] leading-none tracking-[0.08em] text-teal-bright">{named.code}</div>
        <div className="text-on-ink-soft">{t.codeFor(named.name, named.relationship)}</div>
      </section>
      {named.sentTo !== null ? (
        <>
          <section className="border border-rule bg-sheet px-3.5 py-3">
            <div className="label-caps text-[10px] text-text-muted">{t.textTitle(who)}</div>
            <p className="mt-1.5 font-mono text-[15px] leading-[1.45]">{t.smsText(school, child, named.code)}</p>
          </section>
          <div className="flex items-baseline gap-2.5 border border-marigold bg-marigold-wash px-3 py-2.5 text-[15px]">
            <strong aria-hidden="true">!</strong>
            <span>{t.warning(who)}</span>
          </div>
        </>
      ) : (
        <p className="text-[15px]">{t.codeNote(named.name)}</p>
      )}
      <Button variant="secondary" className="h-14 w-full text-[17px]" onClick={onDone}>
        {t.done}
      </Button>
    </div>
  )
}
