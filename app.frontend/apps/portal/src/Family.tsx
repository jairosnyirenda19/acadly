import { useLoad } from '@acadly/kernel'
import { Button, Notice } from '@acadly/ui'
import { useState } from 'react'
import { portalApi, type Child, type FamilyFeesState, type FamilyState, type Proposed } from './api'
import { Badge, Bar, go, Loading, Mark, Screen, shortDay, Tile, useWords, type Tone, Note } from './parts'

/** The family, loaded once and shared by every screen of the portal. */
export function useFamily() {
  return useLoad('portal:family', () => portalApi.family())
}

/** The family's fees at a glance, shared by home and each child's page. Undefined while loading or when the school shows no fees. */
export function useFamilyFees(): FamilyFeesState | undefined {
  const loaded = useLoad('portal:fees', () => portalApi.familyFees())

  return loaded.phase === 'refused' ? undefined : loaded.data
}

/** Dates as the fees pages say them: Mon 9 Feb. */
export const feeDay = (iso: string) => new Date(`${iso.slice(0, 10)}T12:00:00`).toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' })

/** The family's total left to pay and the next date due (the approved design). */
function FamilyFeesBox({ fees }: { fees: FamilyFeesState }) {
  const f = useWords().fees

  if (fees.children.length === 0 || fees.left <= 0) return null
  const next = fees.next

  return (
    <div className="mt-3.5 border-2 border-ink bg-marigold-wash px-3.5 py-3">
      <span className="label-caps text-[10px]">{f.family}</span>
      <div className="type-display text-[32px] leading-none">{f.money(fees.left)}</div>
      {next !== null && <div className="text-sm">{next.overdue ? f.familyOverdue(feeDay(next.on), next.first_name) : f.familyNext(feeDay(next.on), next.first_name)}</div>}
    </div>
  )
}

/** Home: the children to confirm first, then every child at a glance. */
export function Home({ onSignOut }: { onSignOut: () => void }) {
  const t = useWords()
  const loaded = useFamily()
  const fees = useFamilyFees()
  const data = loaded.data

  if (data === undefined) {
    return <Screen bar={<Bar school="" />}>{<Loading failed={loaded.phase === 'failed'} onRetry={loaded.reload} />}</Screen>
  }

  if (data.to_confirm.length > 0) return <Confirm family={data} onDone={loaded.set} />

  return (
    <Screen bar={<Bar school={data.school.name} />}>
      <div className="px-4 pt-[18px] pb-6">
        <div className="label-caps text-[11px] text-text-muted">{data.parent.name}</div>
        <h1 className="type-display mt-1 mb-3.5 text-[40px] leading-[0.9]">{t.home.title}</h1>
        {data.children.length === 0 && (
          <Note tone="attention" title={t.home.none}>
            {t.home.noneNote}
          </Note>
        )}
        {data.children.map((c) => (
          <ChildCard key={c.id} child={c} fees={fees?.children.find((x) => x.id === c.id)} />
        ))}
        {fees !== undefined && <FamilyFeesBox fees={fees} />}
        <button type="button" onClick={onSignOut} className="mt-4 min-h-11 text-[15px] font-extrabold underline underline-offset-[3px]">
          {t.home.signOut}
        </button>
      </div>
    </Screen>
  )
}

function ChildCard({ child, fees }: { child: Child; fees: FamilyFeesState['children'][number] | undefined }) {
  const t = useWords()
  const href = `/child/${child.id}`
  const today = child.lines.find((l) => l.key === 'today')
  const card = child.lines.find((l) => l.key === 'card')
  const review = child.lines.find((l) => l.key === 'review')
  const pickup = child.lines.find((l) => l.key === 'pickup')
  const reason = `/child/${child.id}/reason`
  const ask = today !== undefined && today.word === 'absent_ask' && child.state !== 'suspended'

  return (
    <div className="mb-3 border-2 border-ink bg-sheet">
    <a href={href} onClick={(e) => go(e, href)} className="block text-ink no-underline">
      <span className="flex items-center gap-3 px-3.5 py-3">
        <Badge name={child.name} />
        <span className="min-w-0 flex-1">
          <span className="block text-xl font-extrabold">{child.first_name}</span>
          <span className="block text-sm">{child.class}</span>
        </span>
        <span aria-hidden="true" className="text-[26px] font-extrabold">
          ›
        </span>
      </span>
      {child.state === 'suspended' ? (
        <span className="flex items-center gap-2.5 border-t border-rule px-3.5 py-2.5">
          <Mark tone="wrong" />
          <span className="text-base font-bold">{t.home.suspended}</span>
        </span>
      ) : (
        today !== undefined && (
          <span className="flex items-center gap-2.5 border-t border-rule px-3.5 py-2.5">
            <Mark tone={today.tone as Tone} />
            <span className="text-base font-bold">{t.home.today[today.word] ?? ''}</span>
          </span>
        )
      )}
      {card !== undefined && card.word === 'new_card' && (
        <span className="block border-t border-rule bg-marigold-wash px-3.5 py-2.5 text-base font-extrabold">{t.home.newCard(card.label ?? '')}</span>
      )}
      {review !== undefined && review.word === 'new_review' && (
        <span className="block border-t border-rule bg-marigold-wash px-3.5 py-2.5 text-base font-extrabold">{t.home.newReview(review.label ?? '')}</span>
      )}
      {pickup !== undefined && pickup.word !== 'not_collected' && child.state !== 'suspended' && (
        <span className="flex items-center gap-2.5 border-t border-rule px-3.5 py-2.5">
          <Mark tone={pickup.tone as Tone} />
          <span className="text-base font-bold">{pickup.word === 'collected' ? t.pickup.collected(pickup.label ?? '') : t.pickup.refused(pickup.label ?? '')}</span>
        </span>
      )}
      {fees !== undefined && child.state !== 'suspended' && (
        <span className="flex items-center gap-2.5 border-t border-rule px-3.5 py-2.5">
          <Mark tone={fees.left > 0 ? 'attention' : 'done'} />
          <span className="text-base font-bold">{fees.left > 0 ? t.fees.left(t.fees.money(fees.left)) : t.fees.allPaid}</span>
        </span>
      )}
    </a>
    {ask && (
      <div className="border-t border-rule bg-marigold-wash px-3.5 pt-3 pb-3.5">
        <p className="mb-2.5 text-[15px] leading-snug">{t.home.askNote}</p>
        <a href={reason} onClick={(e) => go(e, reason)} className="flex h-14 items-center justify-center bg-ink text-lg font-extrabold text-sheet no-underline">
          {t.home.tellWhy}
        </a>
      </div>
    )}
    </div>
  )
}

/** The children for this number, confirmed once (the approved design). */
function Confirm({ family, onDone }: { family: FamilyState; onDone: (next: FamilyState) => void }) {
  const t = useWords()
  const c = t.confirm
  const [notMine, setNotMine] = useState<string[]>([])
  const [choosing, setChoosing] = useState(false)
  const [working, setWorking] = useState(false)
  const [failed, setFailed] = useState(false)

  const toggle = (p: Proposed) => setNotMine((list) => (list.includes(p.id) ? list.filter((id) => id !== p.id) : [...list, p.id]))

  const save = async () => {
    setWorking(true)
    setFailed(false)
    const ids = family.to_confirm.map((p) => p.id)
    const next = await portalApi.confirm(
      ids.filter((id) => !notMine.includes(id)),
      ids.filter((id) => notMine.includes(id)),
    )
    setWorking(false)

    if (next !== null) onDone(next)
    else setFailed(true)
  }

  return (
    <Screen
      bar={<Bar school={family.school.name} />}
      foot={
        <Button className="h-14 w-full text-lg" busy={working} onClick={() => void save()}>
          {working ? c.saving : notMine.length > 0 ? c.save : c.yes}
        </Button>
      }
    >
      <div className="px-[18px] pt-5 pb-6">
        <h1 className="type-display mb-2 text-[40px] leading-[0.9]">{c.title}</h1>
        <p className="mb-4 text-base leading-normal">{c.intro}</p>
        {failed && (
          <div className="mb-3">
            <Notice tone="wrong" title={c.failedTitle}>
              {c.failed}
            </Notice>
          </div>
        )}
        <ul>
          {family.to_confirm.map((p) => {
            const no = notMine.includes(p.id)
            const row = (
              <>
                <Badge name={p.name} />
                <span className="min-w-0 flex-1 text-left">
                  <span className={`block text-lg font-extrabold ${no ? 'line-through' : ''}`}>{p.name}</span>
                  <span className="block text-sm">{p.class}</span>
                </span>
                <span className="flex flex-col items-center gap-0.5">
                  <span aria-hidden="true" className={`grid size-8 place-items-center text-xl font-extrabold ${no ? 'bg-red-pen text-sheet' : 'bg-ink text-sheet'}`}>
                    {no ? '✕' : '✓'}
                  </span>
                  <span className="text-[11px] font-extrabold">{no ? c.notMine : c.mine}</span>
                </span>
              </>
            )

            return (
              <li key={p.id} className="mb-2.5">
                {choosing ? (
                  <button
                    type="button"
                    aria-pressed={no}
                    onClick={() => toggle(p)}
                    className={`flex w-full items-center gap-3 border-2 px-3.5 py-3 ${no ? 'border-red-pen bg-red-wash' : 'border-ink bg-sheet'}`}
                  >
                    {row}
                  </button>
                ) : (
                  <div className="flex items-center gap-3 border-2 border-ink bg-sheet px-3.5 py-3">{row}</div>
                )}
              </li>
            )
          })}
        </ul>
        {!choosing && (
          <button type="button" onClick={() => setChoosing(true)} className="mt-1 min-h-11 text-base font-extrabold underline underline-offset-[3px]">
            {c.oneIsNot}
          </button>
        )}
        {choosing && <p className="mt-1.5 text-[13px] text-text-muted">{c.oneIsNotNote}</p>}
      </div>
    </Screen>
  )
}

/** One child: four big doors (the approved design). */
export function ChildScreen({ id }: { id: string }) {
  const t = useWords()
  const loaded = useFamily()
  const familyFees = useFamilyFees()
  const data = loaded.data
  const child = data?.children.find((c) => c.id === id && c.state === 'active')

  if (data === undefined) {
    return <Screen bar={<Bar school="" back={{ to: '/', label: t.home.title }} />}>{<Loading failed={loaded.phase === 'failed'} onRetry={loaded.reload} />}</Screen>
  }

  if (child === undefined) {
    return (
      <Screen bar={<Bar school={data.school.name} back={{ to: '/', label: t.home.title }} />}>
        <div className="p-4">
          <Note tone="attention" title={t.home.none}>
            {t.home.noneNote}
          </Note>
        </div>
      </Screen>
    )
  }

  const card = child.lines.find((l) => l.key === 'card')
  const review = child.lines.find((l) => l.key === 'review')
  const pickup = child.lines.find((l) => l.key === 'pickup')
  const conduct = child.lines.find((l) => l.key === 'conduct')
  const plan = child.lines.find((l) => l.key === 'plan')
  const documents = child.lines.find((l) => l.key === 'documents')
  // The doors' labels (D-110): "3 2" (commendations, incidents), "2027-02-20 Reading support", "3".
  const [commendations = 0, incidents = 0] = (conduct?.label ?? '').split(' ').map(Number)
  const base = `/child/${child.id}`
  const fees = familyFees?.children.find((x) => x.id === child.id)
  const feesNote =
    fees === undefined
      ? ''
      : fees.left <= 0
        ? t.fees.allPaid
        : [t.fees.left(t.fees.money(fees.left)), fees.next_due !== null ? (fees.next_due.overdue ? t.fees.overdueSince(feeDay(fees.next_due.on)) : t.fees.nextDue(feeDay(fees.next_due.on))) : null]
            .filter((s): s is string => s !== null)
            .join(' · ')

  return (
    <Screen bar={<Bar school={data.school.name} back={{ to: '/', label: t.home.title }} />}>
      <div className="flex items-center gap-3 px-4 pt-[18px] pb-3.5">
        <Badge name={child.name} />
        <span>
          <span className="type-display block text-[30px] leading-[0.9]">{child.name}</span>
          <span className="block text-[15px]">{child.class}</span>
        </span>
      </div>
      <div className="px-4 pb-6">
        {review !== undefined ? (
          <Tile
            icon="review"
            title={t.child.review}
            note={review.word === 'no_review' ? t.review.none : review.word === 'new_review' ? t.child.newCard(review.label ?? '') : (review.label ?? '')}
            fresh={review.word === 'new_review'}
            href={`${base}/review`}
          />
        ) : (
          <Tile
            icon="card"
            title={t.child.card}
            note={card === undefined ? t.child.noCard : card.word === 'new_card' ? t.child.newCard(card.label ?? '') : (card.label ?? '')}
            fresh={card?.word === 'new_card'}
            href={`${base}/card`}
          />
        )}
        {pickup !== undefined && (
          <Tile
            icon="gate"
            title={t.child.pickup}
            note={pickup.word === 'collected' ? t.pickup.collected(pickup.label ?? '') : pickup.word === 'refused' ? t.pickup.refused(pickup.label ?? '') : t.pickup.notCollected}
            fresh={pickup.word === 'refused'}
            href={`${base}/pickup`}
          />
        )}
        {fees !== undefined && <Tile icon="fees" title={t.fees.door} note={feesNote} fresh={fees.next_due?.overdue === true} href={`${base}/fees`} />}
        <Tile icon="cal" title={t.child.attendance} note={t.child.seeTerm} href={`${base}/attendance`} />
        {conduct !== undefined && <Tile icon="star" title={t.conduct.door} note={t.conduct.doorNote(commendations, incidents)} href={`${base}/conduct`} />}
        {plan?.label != null && <Tile icon="plan" title={t.plan.door} note={t.plan.doorNote(plan.label.slice(11), shortDay(plan.label.slice(0, 10)))} href={`${base}/plan`} />}
        {documents !== undefined && <Tile icon="doc" title={t.documents.door} note={t.documents.doorNote(Number(documents.label ?? 0))} href={`${base}/documents`} />}
        <Tile icon="bell" title={t.child.messages} note={t.child.seeMessages} href={`${base}/messages`} />
        {data.school.phone !== null && <Tile icon="call" title={t.child.call} note={data.school.phone} href={`tel:${data.school.phone.replace(/\s/g, '')}`} />}
      </div>
    </Screen>
  )
}
