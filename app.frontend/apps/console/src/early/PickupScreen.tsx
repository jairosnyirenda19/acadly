import { navigate, useLoad, useLocation } from '@acadly/kernel'
import { Button, slips, Waiting } from '@acadly/ui'
import { useEffect, useRef, useState, type ReactNode } from 'react'
import { copy } from '../copy'
import { day } from '../settings/api'
import { PageHead, Tag } from '../settings/parts'
import { Input, Select, TextArea } from '../setup/parts'
import { earlyApi, firstError, type Collector, type Desk, type PickupChild } from './api'
import { RoomPicker } from './MyRoomScreen'

const t = copy.pickup

/** The desk reads itself again this often (D-109). */
const EVERY = 10_000
const TONE_KEY = 'acadly.pickup.tone'
const FEW = 8

type Arrival = Desk['at_gate'][number]
type Person = 'contact' | 'collector'

const clock = (d: Date) => d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
const hm = (iso: string) => new Date(iso).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })
const asPerson = (kind: string): Person => (kind === 'collector' ? 'collector' : 'contact')

function savedTone(): boolean {
  try {
    return window.localStorage.getItem(TONE_KEY) === 'on'
  } catch {
    return false
  }
}

function keepTone(on: boolean): void {
  try {
    if (on) window.localStorage.setItem(TONE_KEY, 'on')
    else window.localStorage.removeItem(TONE_KEY)
  } catch {
    // The choice is only for this visit when the browser keeps nothing.
  }
}

/** A short, quiet tone: one beep, a fifth of a second. */
function beep(audio: AudioContext | null): void {
  if (audio === null) return
  try {
    const osc = audio.createOscillator()
    const gain = audio.createGain()
    osc.type = 'sine'
    osc.frequency.value = 880
    gain.gain.setValueAtTime(0.0001, audio.currentTime)
    gain.gain.exponentialRampToValueAtTime(0.2, audio.currentTime + 0.02)
    gain.gain.exponentialRampToValueAtTime(0.0001, audio.currentTime + 0.2)
    osc.connect(gain).connect(audio.destination)
    osc.start()
    osc.stop(audio.currentTime + 0.22)
  } catch {
    // No sound where the browser gives none.
  }
}

function newAudio(): AudioContext | null {
  try {
    return new AudioContext()
  } catch {
    return null
  }
}

/**
 * Pick-up (the approved designs E1Queue and E1GatePhone, D-109): the live
 * queue of the room. The gate marks who has come; the room brings the child
 * and releases them here, only to someone the family named.
 */
export function PickupScreen() {
  const { query } = useLocation()
  const room = query.get('room') ?? ''
  // Each answer keeps the time it was read, so the page says when it last updated.
  const loaded = useLoad(`pickup:queue:${room}`, () => earlyApi.desk(room).then((r) => (r !== null && r !== 'refused' && r !== 'missing' ? { ...r, readAt: new Date() } : r)))
  const [open, setOpen] = useState<{ id: string; refuse?: string } | null>(null)
  const [gate, setGate] = useState(false)
  const [tone, setTone] = useState(savedTone)
  const audio = useRef<AudioContext | null>(null)
  const waiting = useRef<number | null>(null)
  const panel = useRef<HTMLDivElement>(null)
  const data = loaded.data
  const reload = loaded.reload

  // The desk updates itself, and says when it last did.
  useEffect(() => {
    const timer = window.setInterval(() => {
      if (document.visibilityState === 'visible') reload()
    }, EVERY)

    return () => window.clearInterval(timer)
  }, [reload, room])

  useEffect(() => {
    if (data === undefined) return
    const now = data.at_gate.length
    if (tone && waiting.current !== null && now > waiting.current) {
      audio.current ??= newAudio()
      beep(audio.current)
    }
    waiting.current = now
  }, [data, tone])

  useEffect(() => {
    if (open !== null) panel.current?.scrollIntoView({ block: 'start', behavior: 'smooth' })
  }, [open])

  if (data === undefined) return <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} rows={6} onRetry={loaded.reload} />

  const chooseTone = (on: boolean) => {
    setTone(on)
    keepTone(on)
    // A browser lets a page make a sound only after a person acts: the tick is that act.
    if (on) {
      audio.current ??= newAudio()
      void audio.current?.resume()
    }
  }

  const changed = () => {
    setOpen(null)
    reload()
  }

  const atGate = new Set(data.at_gate.map((a) => a.learner_id))
  const inRoom = data.children.filter((c) => c.collected === null && !atGate.has(c.id))
  const released = data.children.filter((c) => c.collected !== null).sort((x, y) => (y.collected?.at ?? '').localeCompare(x.collected?.at ?? ''))
  const refused = data.children.reduce((n, c) => n + c.refused, 0)
  const name = data.room?.name ?? ''

  return (
    <>
      <PageHead
        kicker={data.room !== null ? t.roomKicker(name, day(data.day)) : t.kicker(day(data.day))}
        title={t.title}
        intro={t.updates(clock(data.readAt))}
        actions={
          data.room !== null && data.can_release ? (
            <>
              <label className="flex min-h-11 items-center gap-2 text-[15px]">
                <input type="checkbox" className="size-5 accent-ink" checked={tone} onChange={(e) => chooseTone(e.target.checked)} />
                {t.tone}
              </label>
              <Button variant="secondary" aria-expanded={gate} onClick={() => setGate(!gate)}>
                {gate ? t.close : t.markGate}
              </Button>
            </>
          ) : undefined
        }
      />
      <RoomPicker rooms={data.rooms} value={data.room?.id ?? ''} onChange={(r) => navigate(`/pickup?room=${r}`)} />
      {data.room === null ? (
        <p className="px-[clamp(16px,3vw,40px)] pb-8 text-sm text-text-muted">{t.noRooms}</p>
      ) : (
        <div className="grid gap-4 px-[clamp(16px,3vw,40px)] pb-8">
          {gate && (
            <Gate
              room={name}
              waiting={data.children.filter((c) => c.collected === null && !atGate.has(c.id))}
              onDone={() => {
                setGate(false)
                reload()
              }}
            />
          )}

          <dl className="grid grid-cols-2 border-2 border-ink md:grid-cols-4">
            {[
              { label: t.atGate, value: data.at_gate.length, look: data.at_gate.length > 0 ? 'bg-marigold text-ink' : 'bg-sheet', key: 'text-ink' },
              { label: t.inRoom, value: inRoom.length, look: 'bg-sheet', key: 'text-text-muted' },
              { label: t.releasedCol, value: released.length, look: 'bg-ink text-sheet', key: 'text-on-ink-muted', number: 'text-teal-bright' },
              { label: t.refusedToday, value: refused, look: 'bg-sheet', key: 'text-text-muted', number: refused > 0 ? 'text-red-text' : '' },
            ].map((f, i) => (
              <div key={f.label} className={`px-4 py-3 ${f.look} ${i % 2 === 1 ? 'border-l-2 border-ink' : ''} ${i === 2 ? 'max-md:border-t-2 md:border-l-2' : ''} ${i === 3 ? 'max-md:border-t-2' : ''}`}>
                <dt className={`label-caps ${f.key}`}>{f.label}</dt>
                <dd className={`type-display text-[44px] leading-none ${f.number ?? ''}`}>
                  {f.value}
                  {i === 0 && f.value > 0 && <span className="sr-only"> !</span>}
                </dd>
              </div>
            ))}
          </dl>

          <div className="grid items-start gap-4 lg:grid-cols-3">
            <Column title={t.atGate} count={data.at_gate.length}>
              {data.at_gate.length === 0 && <p className="text-sm text-text-muted">{t.noneAtGate}</p>}
              {data.at_gate.map((a) => (
                <GateCard
                  key={a.pickup_id}
                  arrival={a}
                  room={name}
                  canRelease={data.can_release}
                  onReleased={changed}
                  onRefuse={() => setOpen({ id: a.learner_id, refuse: a.name })}
                />
              ))}
            </Column>
            <Column title={t.inRoom} count={inRoom.length}>
              <Rows
                empty={t.noneInRoom}
                rows={inRoom.map((c) => ({
                  id: c.id,
                  name: c.name,
                  line: null,
                  tag: c.refused > 0 ? <Tag tone="wrong">✕ {t.refusedTag}</Tag> : null,
                }))}
                open={open?.id ?? null}
                onOpen={(id) => setOpen(open?.id === id ? null : { id })}
              />
            </Column>
            <Column title={t.releasedCol} count={released.length}>
              <Rows
                empty={t.noneReleased}
                rows={released.map((c) => ({
                  id: c.id,
                  name: c.name,
                  line: c.collected !== null ? t.releasedTo(c.collected.name, c.collected.relationship, c.collected.at) : null,
                  tag: <Tag tone="done">✓ {t.releasedMark}</Tag>,
                }))}
                open={open?.id ?? null}
                onOpen={(id) => setOpen(open?.id === id ? null : { id })}
              />
            </Column>
          </div>

          <p className="text-[13px] text-text-muted">{t.queueFoot}</p>

          {open !== null && (
            <div ref={panel} className="scroll-mt-4 border-t-2 border-ink pt-4">
              <div className="mb-3 flex justify-end">
                <Button variant="secondary" onClick={() => setOpen(null)}>
                  {t.close}
                </Button>
              </div>
              <div className="max-w-[720px]">
                <ChildPanel key={`${open.id}:${open.refuse ?? ''}`} learnerId={open.id} canRelease={data.can_release} refuse={open.refuse} onDone={changed} />
              </div>
            </div>
          )}
        </div>
      )}
    </>
  )
}

function Column({ title, count, children }: { title: string; count: number; children: ReactNode }) {
  return (
    <section aria-label={title} className="grid min-w-0 content-start gap-2.5">
      <div className="flex items-baseline justify-between border-b-2 border-ink pb-1.5">
        <h2 className="type-display text-[22px] leading-none">{title}</h2>
        <span className="type-display text-[30px] leading-none">{count}</span>
      </div>
      {children}
    </section>
  )
}

function Rows({ rows, empty, open, onOpen }: { rows: { id: string; name: string; line: string | null; tag: ReactNode }[]; empty: string; open: string | null; onOpen: (id: string) => void }) {
  const [all, setAll] = useState(false)
  const shown = all ? rows : rows.slice(0, FEW)

  if (rows.length === 0) return <p className="text-sm text-text-muted">{empty}</p>

  return (
    <>
      <ul className="border border-rule bg-sheet">
        {shown.map((r) => (
          <li key={r.id} className="border-b border-rule last:border-b-0">
            <button
              type="button"
              aria-expanded={open === r.id}
              onClick={() => onOpen(r.id)}
              className={`flex min-h-14 w-full items-center gap-3 px-3.5 py-2 text-left text-ink border-l-[6px] ${open === r.id ? 'border-ink bg-field' : 'border-transparent hover:bg-field'}`}
            >
              <span className="flex min-w-0 flex-1 flex-col">
                <strong className="text-[15px]">{r.name}</strong>
                {r.line !== null && <span className="text-sm text-text-muted">{r.line}</span>}
              </span>
              {r.tag}
            </button>
          </li>
        ))}
      </ul>
      {rows.length > FEW && (
        <button type="button" onClick={() => setAll(!all)} className="inline-flex min-h-11 items-center self-start text-sm font-extrabold text-ink underline underline-offset-[3px]">
          {all ? t.showFewer : t.showAll(rows.length)}
        </button>
      )}
    </>
  )
}

/** Someone the gate marked as arrived: release them here, with the code where one is needed, or refuse. */
function GateCard({ arrival: a, room, canRelease, onReleased, onRefuse }: { arrival: Arrival; room: string; canRelease: boolean; onReleased: () => void; onRefuse: () => void }) {
  const [asking, setAsking] = useState(false)
  const [code, setCode] = useState('')
  const [busy, setBusy] = useState(false)

  const release = async () => {
    if (a.ref === null) return
    if (a.needs_code && !asking) return setAsking(true)
    setBusy(true)
    const result = await earlyApi.release(a.learner_id, asPerson(a.kind), a.ref, code.trim())
    setBusy(false)
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: t.released(a.child, a.name) })
      onReleased()
    } else slips.wrong({ word: t.notReleased, title: firstError(result, t.failed) })
  }

  return (
    <article className="grid gap-0.5 border-2 border-ink bg-marigold-wash px-3 py-2.5">
      <b className="text-lg">{a.child}</b>
      <span className="text-sm text-text-muted">{room}</span>
      <span className="text-[15px]">{t.gateLine(a.name, a.relationship, a.needs_code)}</span>
      <span className="text-[13px]">
        <span aria-hidden="true" className="font-extrabold">
          !{' '}
        </span>
        {t.arrived(hm(a.at))}
      </span>
      {canRelease && (
        <div className="mt-1.5 grid gap-2">
          {asking && <Input label={t.codeFor(a.name)} inputMode="numeric" autoComplete="off" maxLength={6} value={code} autoFocus onChange={(e) => setCode(e.target.value)} />}
          <div className="flex flex-wrap gap-2">
            <Button busy={busy} disabled={a.ref === null || (asking && code.trim() === '')} onClick={() => void release()}>
              {t.releaseOne}
            </Button>
            <button type="button" onClick={onRefuse} className="min-h-11 border-2 border-red-text bg-sheet px-4 text-[15px] font-extrabold text-red-text hover:bg-red-wash">
              {t.refuseOne}
            </button>
          </div>
        </div>
      )}
    </article>
  )
}

/** The gate (E1GatePhone): choose the child, then the person on the list who has come, and tell the room. */
function Gate({ room, waiting, onDone }: { room: string; waiting: Desk['children']; onDone: () => void }) {
  const [child, setChild] = useState('')

  return (
    <section aria-label={t.gateTitle(room)} className="grid max-w-[560px] border-2 border-ink bg-sheet">
      <h2 className="flex min-h-[60px] items-center border-b-2 border-ink px-4 text-lg font-extrabold">{t.gateTitle(room)}</h2>
      <div className="grid gap-3 px-4 py-3">
        <Select label={t.gateChild} value={child} onChange={(e) => setChild(e.target.value)}>
          <option value="">{t.gateChoose}</option>
          {waiting.map((c) => (
            <option key={c.id} value={c.id}>
              {c.name}
            </option>
          ))}
        </Select>
      </div>
      {child !== '' && <GateWho key={child} learnerId={child} onDone={onDone} />}
    </section>
  )
}

function GateWho({ learnerId, onDone }: { learnerId: string; onDone: () => void }) {
  const loaded = useLoad(`pickup:child:${learnerId}`, () => earlyApi.child(learnerId))
  const [chosen, setChosen] = useState<Collector | null>(null)
  const [busy, setBusy] = useState(false)
  const data = loaded.data

  if (data === undefined) return <Waiting phase={loaded.phase} what={t.gateWho} labels={copy.loading} rows={3} onRetry={loaded.reload} />

  const collected = data.today.some((p) => p.state === 'released')

  const tell = async () => {
    if (chosen === null) return
    setBusy(true)
    const result = await earlyApi.arrive(data.learner.id, chosen.kind, chosen.id)
    setBusy(false)
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: t.told(chosen.name, data.learner.name) })
      onDone()
    } else slips.wrong({ word: t.notMarked, title: firstError(result, t.failed) })
  }

  return (
    <>
      <div className="grid gap-2 px-4 pb-3">
        <div className="label-caps text-text-muted">{t.gateWho}</div>
        {collected && <Tag tone="done">✓ {t.gateDone}</Tag>}
        {data.may_collect.length === 0 && <p className="text-sm text-text-muted">{t.gateNobody}</p>}
        <div role="radiogroup" aria-label={t.gateWho} className="grid gap-2">
          {data.may_collect.map((c) => {
            const on = chosen?.id === c.id

            return (
              <button
                key={`${c.kind}:${c.id}`}
                type="button"
                role="radio"
                aria-checked={on}
                disabled={collected}
                onClick={() => setChosen(c)}
                className={`flex min-h-[60px] items-center gap-3 border-2 border-ink px-3 text-left disabled:cursor-default ${on ? 'bg-ink text-sheet' : 'bg-sheet text-ink'}`}
              >
                <span aria-hidden="true" className={`size-[22px] shrink-0 border-2 ${on ? 'border-sheet bg-teal-bright' : 'border-ink'}`} />
                <span>
                  <b className="block">{c.name}</b>
                  <span className="text-sm">{[c.relationship, c.needs_code ? t.oneDayCode : c.day_only ? t.dayOnly : null].filter((x) => x !== null && x !== '').join(' · ')}</span>
                </span>
              </button>
            )
          })}
        </div>
        <div className="flex items-baseline gap-2.5 border border-marigold bg-marigold-wash px-3 py-2.5">
          <strong aria-hidden="true">!</strong>
          <span>{t.gateNotListed}</span>
        </div>
      </div>
      <div className="grid gap-2.5 border-t-2 border-ink px-4 pt-3 pb-4">
        <Button className="h-14 w-full text-[17px]" busy={busy} disabled={chosen === null || collected} onClick={() => void tell()}>
          {t.tellRoom}
        </Button>
        <span className="text-[13px] text-text-muted">{t.tellNote}</span>
      </div>
    </>
  )
}

function ChildPanel({ learnerId, canRelease, refuse, onDone }: { learnerId: string; canRelease: boolean; refuse?: string | undefined; onDone: () => void }) {
  const loaded = useLoad(`pickup:child:${learnerId}`, () => earlyApi.child(learnerId))
  const data = loaded.data

  if (data === undefined) return <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} rows={4} onRetry={loaded.reload} />

  return <Panel data={data} canRelease={canRelease && data.can.release} refuse={refuse} onDone={onDone} onChanged={loaded.reload} />
}

function Panel({ data, canRelease, refuse, onDone, onChanged }: { data: PickupChild; canRelease: boolean; refuse?: string | undefined; onDone: () => void; onChanged: () => void }) {
  const [chosen, setChosen] = useState<Collector | null>(null)
  const [code, setCode] = useState('')
  const [refusing, setRefusing] = useState(refuse !== undefined && canRelease)
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

  if (refusing) return <Refuse data={data} came={refuse ?? ''} onDone={onDone} onCancel={() => setRefusing(false)} />

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

function Refuse({ data, came, onDone, onCancel }: { data: PickupChild; came: string; onDone: () => void; onCancel: () => void }) {
  const [name, setName] = useState(came)
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
