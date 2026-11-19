import { useLoad } from '@acadly/kernel'
import { Button } from '@acadly/ui'
import { useId, useState } from 'react'
import { portalApi, type PickupState } from './api'
import { ChildPage } from './ChildPages'
import { Mark, Note, useWords, type Tone } from './parts'

const strokes: Record<string, string> = { starting: '/', growing: '//', can_do: '///' }
const fills: Record<string, string> = { starting: 'bg-teal-wash text-ink', growing: 'bg-teal-mid text-ink', can_do: 'bg-teal text-sheet' }

/** The progress review on a phone (the approved design): a step and its word in each learning area, then the two notes. */
export function ReviewPage({ id }: { id: string }) {
  const t = useWords().review
  const loaded = useLoad(`portal:review:${id}`, () => portalApi.review(id))

  return (
    <ChildPage id={id} phase={loaded.phase} data={loaded.data} onRetry={loaded.reload}>
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
                    <span className="text-sm text-text-muted">{t.steps[a.step] ?? a.label}</span>
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

function Pickup({ id, data, onAdded }: { id: string; data: PickupState; onAdded: () => void }) {
  const t = useWords().pickup
  const [adding, setAdding] = useState(false)
  const [name, setName] = useState('')
  const [relationship, setRelationship] = useState('')
  const [phone, setPhone] = useState('')
  const [working, setWorking] = useState(false)
  const [failed, setFailed] = useState(false)
  const [code, setCode] = useState<{ name: string; code: string } | null>(null)
  const ids = { name: useId(), relationship: useId(), phone: useId() }
  const today = data.today
  const tone: Tone = today === null ? 'plain' : today.state === 'released' ? 'done' : 'wrong'

  const add = async () => {
    setWorking(true)
    setFailed(false)
    const result = await portalApi.collectToday(id, name.trim(), relationship.trim(), phone.trim())
    setWorking(false)
    if (result === null) return setFailed(true)
    setCode({ name: name.trim(), code: result.code })
    setAdding(false)
    setName('')
    setRelationship('')
    setPhone('')
    onAdded()
  }

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

      {code !== null && (
        <section role="status" className="border-2 border-ink bg-sheet px-3.5 py-3">
          <div className="label-caps text-[10px] text-text-muted">{t.codeTitle}</div>
          <div className="font-mono text-[44px] leading-none font-extrabold tracking-[0.2em]">{code.code}</div>
          <p className="mt-2 text-[15px]">{t.codeNote(code.name)}</p>
        </section>
      )}

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
          <Button variant="secondary" className="h-12 w-full" onClick={() => (setAdding(true), setCode(null))}>
            {t.someoneElse}
          </Button>
        ) : (
          <div className="grid gap-2.5">
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
            <input id={ids.phone} type="tel" inputMode="tel" value={phone} maxLength={30} onChange={(e) => setPhone(e.target.value)} className={field} />
            {failed && <Note tone="attention" title={t.failed} />}
            <Button className="h-13 w-full text-base" busy={working} disabled={name.trim() === '' || relationship.trim() === ''} onClick={() => void add()}>
              {t.add}
            </Button>
            <Button variant="secondary" className="h-12 w-full" onClick={() => setAdding(false)}>
              {t.cancel}
            </Button>
          </div>
        )}
      </section>
    </div>
  )
}
