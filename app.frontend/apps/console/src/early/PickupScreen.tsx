import { navigate, useLoad, useLocation } from '@acadly/kernel'
import { Button, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { day } from '../settings/api'
import { PageHead, Tag } from '../settings/parts'
import { Input, Select, TextArea } from '../setup/parts'
import { earlyApi, firstError, type Collector, type Desk, type PickupChild } from './api'
import { RoomPicker } from './MyRoomScreen'

const t = copy.pickup

/** Pick-up at the gate (the approved design): each child of the room, released only to someone the family named. */
export function PickupScreen() {
  const { query } = useLocation()
  const room = query.get('room') ?? ''
  const loaded = useLoad(`pickup:desk:${room}`, () => earlyApi.desk(room))
  const [open, setOpen] = useState<string | null>(null)
  const data = loaded.data

  if (data === undefined) return <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} rows={6} onRetry={loaded.reload} />

  return (
    <>
      <PageHead
        kicker={t.kicker(day(data.day))}
        title={data.room?.name ?? t.title}
        intro={
          <span className="flex flex-wrap gap-2">
            <Tag tone="attention">! {t.stillHere(data.totals.here)}</Tag>
            <Tag tone="done">✓ {t.collected(data.totals.collected)}</Tag>
          </span>
        }
      />
      <RoomPicker rooms={data.rooms} value={data.room?.id ?? ''} onChange={(r) => navigate(`/pickup?room=${r}`)} />
      <div className="grid gap-4 px-[clamp(16px,3vw,40px)] pb-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
        {data.room === null && <p className="text-sm text-text-muted">{t.noRooms}</p>}
        <section aria-label={data.room?.name ?? t.title} className="grid content-start gap-1.5">
          {data.children.length === 0 && data.room !== null && <p className="text-sm text-text-muted">{t.nobody}</p>}
          {data.children.map((c) => (
            <ChildRow key={c.id} row={c} open={open === c.id} onOpen={() => setOpen(open === c.id ? null : c.id)} />
          ))}
          <p className="mt-2 text-xs text-text-muted">{t.intro}</p>
        </section>
        <div className="min-w-0">
          {open !== null ? (
            <ChildPanel
              key={open}
              learnerId={open}
              canRelease={data.can_release}
              onDone={() => {
                setOpen(null)
                loaded.reload()
              }}
            />
          ) : (
            <p className="border-2 border-dashed border-rule p-4 text-sm text-text-muted">{t.choose}</p>
          )}
        </div>
      </div>
    </>
  )
}

function ChildRow({ row: c, open, onOpen }: { row: Desk['children'][number]; open: boolean; onOpen: () => void }) {
  return (
    <button
      type="button"
      aria-expanded={open}
      onClick={onOpen}
      className={`flex min-h-14 items-center gap-2.5 bg-sheet px-3 py-2 text-left text-ink ${open ? 'border-[3px] border-ink' : 'border border-rule'}`}
    >
      <span className="flex-1">
        <b className="block text-[15px]">{c.name}</b>
        {c.collected !== null && <span className="text-xs text-text-muted">{t.collectedLine(c.collected.at, c.collected.name)}</span>}
      </span>
      {c.refused > 0 && <Tag tone="wrong">✕ {t.refusedTag}</Tag>}
      {c.collected !== null ? <Tag tone="done">✓ {t.collectedTag}</Tag> : <Tag tone="plain">{t.here}</Tag>}
    </button>
  )
}

function ChildPanel({ learnerId, canRelease, onDone }: { learnerId: string; canRelease: boolean; onDone: () => void }) {
  const loaded = useLoad(`pickup:child:${learnerId}`, () => earlyApi.child(learnerId))
  const data = loaded.data

  if (data === undefined) return <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} rows={4} onRetry={loaded.reload} />

  return <Panel data={data} canRelease={canRelease && data.can.release} onDone={onDone} onChanged={loaded.reload} />
}

function Panel({ data, canRelease, onDone, onChanged }: { data: PickupChild; canRelease: boolean; onDone: () => void; onChanged: () => void }) {
  const [chosen, setChosen] = useState<Collector | null>(null)
  const [code, setCode] = useState('')
  const [refusing, setRefusing] = useState(false)
  const [busy, setBusy] = useState(false)
  const collected = data.today.find((p) => p.state === 'released')
  const first = data.learner.name.split(' ')[0] ?? data.learner.name

  const release = async () => {
    if (chosen === null) return
    setBusy(true)
    const result = await earlyApi.release(data.learner.id, chosen.kind, chosen.id, code.trim())
    setBusy(false)
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: t.released(data.learner.name, chosen.name) })
      onDone()
    } else slips.wrong({ word: t.notReleased, title: firstError(result, t.failed) })
  }

  if (refusing) return <Refuse data={data} onDone={onDone} onCancel={() => setRefusing(false)} />

  return (
    <div className="grid gap-4">
      <section className="grid gap-2 border-2 border-ink bg-sheet p-3">
        <h2 className="label-caps text-text-muted">{t.whoMay(first)}</h2>
        {collected !== undefined && <Tag tone="done">✓ {`${t.collectedTag} · ${t.collectedLine(collected.at, collected.name)}`}</Tag>}
        <div role="radiogroup" aria-label={t.whoMay(first)} className="grid gap-1.5">
          {data.may_collect.map((c) => {
            const on = chosen?.id === c.id

            return (
              <button
                key={c.id}
                type="button"
                role="radio"
                aria-checked={on}
                disabled={!canRelease || collected !== undefined}
                onClick={() => (setChosen(c), setCode(''))}
                className={`flex min-h-14 items-center gap-2.5 bg-sheet px-2.5 text-left text-ink disabled:cursor-default ${on ? 'border-[3px] border-ink' : 'border border-rule'}`}
              >
                <span aria-hidden="true" className="flex size-10 items-center justify-center bg-field font-extrabold">
                  {c.name
                    .split(' ')
                    .map((w) => w[0] ?? '')
                    .join('')
                    .slice(0, 2)}
                </span>
                <span className="flex-1">
                  <b className="block">{c.name}</b>
                  <span className="text-xs text-text-muted">{[c.relationship, c.phone].filter((x) => x !== null && x !== '').join(' · ')}</span>
                </span>
                {c.day_only && <Tag tone="attention">! {c.needs_code ? t.needsCode : t.dayOnly}</Tag>}
              </button>
            )
          })}
        </div>
        {chosen?.needs_code === true && <Input label={t.code} inputMode="numeric" maxLength={6} value={code} onChange={(e) => setCode(e.target.value)} />}
        {canRelease && collected === undefined && (
          <>
            <Button className="h-13 text-base" busy={busy} disabled={chosen === null || (chosen.needs_code && code.trim() === '')} onClick={() => void release()}>
              {chosen === null ? t.choose : t.release(chosen.name)}
            </Button>
            <button type="button" onClick={() => setRefusing(true)} className="min-h-11 border-2 border-red-pen bg-sheet text-sm font-extrabold text-red-text">
              {t.notOnList}
            </button>
          </>
        )}
        <p className="text-xs text-text-muted">{t.safeguarding}</p>
      </section>
      {data.can.manage && <Manage data={data} onChanged={onChanged} />}
      {data.recent.length > 0 && (
        <section className="border-2 border-ink bg-sheet p-3">
          <h2 className="label-caps mb-1 text-text-muted">{t.recent}</h2>
          <ul>
            {data.recent.map((p) => (
              <li key={p.id} className="flex flex-wrap justify-between gap-2 border-b border-rule py-1.5 text-[13px]">
                <span>{`${day(p.day)} ${p.at} · ${p.name}`}</span>
                {p.state === 'released' ? <Tag tone="done">✓ {p.state_label}</Tag> : <Tag tone={p.state === 'disputed' ? 'wrong' : 'plain'}>{p.state === 'disputed' ? `✕ ${p.state_label}` : p.state_label}</Tag>}
              </li>
            ))}
          </ul>
        </section>
      )}
    </div>
  )
}

function Refuse({ data, onDone, onCancel }: { data: PickupChild; onDone: () => void; onCancel: () => void }) {
  const [name, setName] = useState('')
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)
  const first = data.learner.name.split(' ')[0] ?? data.learner.name
  const family = data.may_collect.filter((c) => c.kind === 'contact')

  const refuse = async () => {
    setBusy(true)
    const result = await earlyApi.refuse(data.learner.id, name.trim(), note.trim())
    setBusy(false)
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: t.refused })
      onDone()
    } else slips.wrong({ word: t.notSaved, title: firstError(result, t.failed) })
  }

  return (
    <section className="grid gap-3 border-2 border-ink bg-sheet p-3">
      <h2 className="type-display text-[30px] leading-none">{t.refuseTitle}</h2>
      <div className="border-2 border-red-pen bg-red-wash p-3 text-[15px]">
        <Tag tone="wrong">✕ {t.notOnList}</Tag>
        <p className="mt-2">{t.refuseText(first)}</p>
      </div>
      <div className="grid gap-1.5">
        <div className="label-caps text-text-muted">{t.callFamily}</div>
        {family.map((c) => (
          <a key={c.id} href={`tel:${(c.phone ?? '').replace(/\s/g, '')}`} className="flex min-h-13 items-center justify-between border-2 border-ink bg-sheet px-3 text-ink no-underline">
            <span>
              <b>{c.name}</b> · {c.relationship}
            </span>
            <span className="font-mono font-extrabold">{c.phone}</span>
          </a>
        ))}
      </div>
      <Input label={t.whoCame} value={name} maxLength={120} onChange={(e) => setName(e.target.value)} required />
      <TextArea label={t.refuseNote} value={note} onChange={(e) => setNote(e.target.value)} />
      <div className="flex flex-wrap gap-2">
        <Button busy={busy} disabled={name.trim() === ''} onClick={() => void refuse()}>
          {t.refuse}
        </Button>
        <Button variant="secondary" onClick={onCancel}>
          {t.cancel}
        </Button>
      </div>
      <p className="text-xs text-text-muted">{t.refuseFoot}</p>
    </section>
  )
}

function Manage({ data, onChanged }: { data: PickupChild; onChanged: () => void }) {
  const [name, setName] = useState('')
  const [relationship, setRelationship] = useState('')
  const [phone, setPhone] = useState('')
  const [todayOnly, setTodayOnly] = useState(false)
  const [contact, setContact] = useState('')
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState('')

  const done = (result: { kind: string; errors?: Record<string, string[]> }, word: string, then: () => void) => {
    setBusy('')
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: word })
      then()
      onChanged()
    } else slips.wrong({ word: t.notSaved, title: firstError(result, t.failed) })
  }

  const add = async () => {
    setBusy('add')
    done(await earlyApi.addCollector(data.learner.id, { name: name.trim(), relationship: relationship.trim(), phone: phone.trim(), today_only: todayOnly }), t.added, () => (setName(''), setRelationship(''), setPhone('')))
  }
  const bar = async () => {
    setBusy('bar')
    done(await earlyApi.bar(data.learner.id, contact, reason.trim()), t.barred, () => (setContact(''), setReason('')))
  }
  const end = async (id: string) => {
    setBusy(id)
    done(await earlyApi.endCollector(id), t.ended, () => undefined)
  }
  const endBar = async (id: string) => {
    const why = window.prompt(t.endBarReason) ?? ''
    if (why.trim() === '') return
    setBusy(id)
    done(await earlyApi.endBar(id, why.trim()), t.ended, () => undefined)
  }

  return (
    <section className="grid gap-3 border-2 border-ink bg-sheet p-3">
      <h2 className="type-display text-[24px] leading-none">{t.manageTitle}</h2>
      <ul>
        {data.may_collect
          .filter((c) => c.kind === 'collector')
          .map((c) => (
            <li key={c.id} className="flex flex-wrap items-center justify-between gap-2 border-b border-rule py-1.5 text-sm">
              <span>
                <b>{c.name}</b> · {c.relationship}
                {c.day_only && ` · ${t.dayOnly}`}
              </span>
              <Button variant="secondary" busy={busy === c.id} onClick={() => void end(c.id)}>
                {t.end}
              </Button>
            </li>
          ))}
      </ul>
      <div className="grid gap-2 sm:grid-cols-3">
        <Input label={t.name} value={name} maxLength={120} onChange={(e) => setName(e.target.value)} />
        <Input label={t.relationship} value={relationship} maxLength={60} onChange={(e) => setRelationship(e.target.value)} />
        <Input label={t.phone} value={phone} maxLength={30} onChange={(e) => setPhone(e.target.value)} />
      </div>
      <label className="flex min-h-11 items-center gap-2 text-[15px]">
        <input type="checkbox" className="size-5 accent-ink" checked={todayOnly} onChange={(e) => setTodayOnly(e.target.checked)} />
        {t.todayOnly}
      </label>
      <div>
        <Button variant="secondary" busy={busy === 'add'} disabled={name.trim() === '' || relationship.trim() === ''} onClick={() => void add()}>
          {t.addCollector}
        </Button>
      </div>

      <h3 className="label-caps mt-2 text-text-muted">{t.barTitle}</h3>
      <ul>
        {data.barred.map((b) => (
          <li key={b.id} className="flex flex-wrap items-center justify-between gap-2 border-b border-rule py-1.5 text-sm">
            <span>
              <Tag tone="wrong">✕ {b.name}</Tag> <span className="text-text-muted">{t.barredLine(b.reason, day(b.since))}</span>
            </span>
            <Button variant="secondary" busy={busy === b.id} onClick={() => void endBar(b.id)}>
              {t.endBar}
            </Button>
          </li>
        ))}
      </ul>
      <div className="grid gap-2 sm:grid-cols-2">
        <Select label={t.barContact} value={contact} onChange={(e) => setContact(e.target.value)}>
          <option value="" />
          {data.contacts
            .filter((c) => !data.barred.some((b) => b.contact_id === c.id))
            .map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
        </Select>
        <Input label={t.barReason} value={reason} onChange={(e) => setReason(e.target.value)} />
      </div>
      <div>
        <Button variant="secondary" busy={busy === 'bar'} disabled={contact === '' || reason.trim() === ''} onClick={() => void bar()}>
          {t.bar}
        </Button>
      </div>
    </section>
  )
}
