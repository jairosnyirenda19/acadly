import { navigate } from '@acadly/kernel'
import jsQR from 'jsqr'
import { useEffect, useRef, useState } from 'react'
import { studentApi, type Lecture, type Me, type ScanResult } from './api'
import { copy } from './copy'
import { canHoldPasskey, makePasskey, signCode, wherePhoneIs } from './passkey'
import { Line, Loading, Page, Primary, Section, Tag, Title, useAnswer } from './parts'

const t = copy.lectures

const shortDay = (iso: string) => new Date(`${iso}T12:00:00`).toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' })
const dayName = (iso: string) => new Date(`${iso}T12:00:00`).toLocaleDateString('en-GB', { weekday: 'long' })
const clock = (iso: string) => new Date(iso).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })
const kind = (k: string) => t.kinds[k] ?? k

/** A lecture's state as a tag: its own mark first, then whether scanning is open, then what happened to it. */
function LectureTag({ l, on }: { l: Lecture; on: string }) {
  if (l.mark === 'present') return <Tag tone="done">{t.present}</Tag>
  if (l.mark === 'late') return <Tag tone="attention">{t.late}</Tag>
  if (l.mark === 'absent') return <Tag tone="wrong">{t.absent}</Tag>
  if (l.scan?.open === true) return <Tag tone="attention">{t.openTag}</Tag>
  if (l.state === 'cancelled') return <Tag tone="wrong">{t.cancelled}</Tag>
  if (l.moved !== null) return <Tag tone="plain">{l.day !== on ? t.movedHere : t.moved}</Tag>
  if (l.state === 'covered') return <Tag tone="plain">{t.covered}</Tag>

  return null
}

/** Where a lecture is, and what changed. */
function where(l: Lecture, on: string, withKind = true): string {
  if (l.moved !== null && l.day === on) return t.movedTo(`${shortDay(l.moved.day)}, ${l.moved.starts}, ${l.moved.room}`)

  return [withKind ? `${kind(l.kind)} · ${l.room}` : l.room, l.cover !== null ? t.cover(l.cover) : null].filter((x) => x !== null).join(' · ')
}

/** Today's lectures on the Today page (the approved P4 board): the one open for scanning, with its Scan in, then the day. */
export function LecturesNow() {
  const loaded = useAnswer(studentApi.lecturesToday, 'lectures-today')
  const d = loaded.data
  if (loaded.refused) return null
  if (d === undefined) return null
  const open = d.open

  return (
    <>
      {open !== null && open.scan !== null && (
        <Section title={t.now(open.starts, open.ends)} strong>
          <div className="grid gap-2.5 px-3.5 py-3">
            <span className="flex flex-col">
              <strong>{`${open.code} · ${open.title}`}</strong>
              <span className="text-[14px] text-text-muted">{`${kind(open.kind)} · ${open.room} · ${open.cover ?? open.lecturer}`}</span>
            </span>
            <div className="flex items-baseline gap-2.5 border border-marigold bg-marigold-wash px-3 py-2.5">
              <strong aria-hidden="true">!</strong>
              <span>{t.open(clock(open.scan.until))}</span>
            </div>
            <Primary onClick={() => navigate('/scan')}>{t.scanIn}</Primary>
          </div>
        </Section>
      )}
      <Section title={t.today}>
        {d.lectures.length === 0 ? (
          <p className="px-3.5 py-3 text-[14px] text-text-muted">{t.none}</p>
        ) : (
          d.lectures.map((l) => <Line key={`${l.slot_id}${l.day}`} strong={`${l.moved !== null && l.day !== d.day ? l.moved.starts : l.starts} · ${l.code}`} sub={where(l, d.day)} right={<LectureTag l={l} on={d.day} />} />)
        )}
        <Line strong={t.week} href="/week" />
      </Section>
    </>
  )
}

/** This week (the approved board): Monday to Saturday, each lecture with its mark or what changed. */
export function WeekPage({ me }: { me: Me }) {
  const [from, setFrom] = useState<string | null>(null)
  const loaded = useAnswer(() => studentApi.week(from), `week:${from ?? ''}`)
  const w = loaded.data
  const move = (days: number) => {
    if (w === undefined) return
    const d = new Date(`${w.from}T12:00:00`)
    d.setDate(d.getDate() + days)
    setFrom(d.toISOString().slice(0, 10))
  }
  const today = new Date().toISOString().slice(0, 10)

  return (
    <Page me={me} tab="today">
      <Title>{t.week}</Title>
      {w === undefined ? (
        <Loading failed={loaded.failed} onRetry={loaded.reload} />
      ) : (
        <>
          <div className="flex items-center justify-between gap-2">
            <button type="button" aria-label={t.lastWeek} onClick={() => move(-7)} className="min-h-11 min-w-11 border-2 border-ink bg-sheet font-extrabold">
              ‹
            </button>
            <b>{t.weekOf(shortDay(w.from), shortDay(w.days[w.days.length - 1]?.day ?? w.from))}</b>
            <button type="button" aria-label={t.nextWeek} onClick={() => move(7)} className="min-h-11 min-w-11 border-2 border-ink bg-sheet font-extrabold">
              ›
            </button>
          </div>
          <section className="grid border border-rule bg-sheet">
            {w.days
              .filter((d) => d.lectures.length > 0)
              .map((d) => (
                <div key={d.day} className="grid">
                  <div className="bg-field px-3.5 py-2 text-[14px] font-extrabold">{`${dayName(d.day)}${d.day === today ? ` · ${t.todayMark}` : ''}`}</div>
                  {d.lectures.map((l) => (
                    <div key={`${l.slot_id}${l.day}`} className="grid min-h-11 grid-cols-[92px_1fr_auto] items-center gap-2 border-b border-rule px-3.5 py-2 last:border-b-0">
                      <span className="font-mono text-[13px]">{l.moved !== null && l.day !== d.day ? `${l.moved.starts}–${l.moved.ends}` : `${l.starts}–${l.ends}`}</span>
                      <span className="flex min-w-0 flex-col">
                        <strong>{`${l.code} · ${kind(l.kind)}`}</strong>
                        <span className="text-[14px] text-text-muted">{where(l, d.day, false)}</span>
                      </span>
                      <LectureTag l={l} on={d.day} />
                    </div>
                  ))}
                </div>
              ))}
            {w.days.every((d) => d.lectures.length === 0) && <p className="px-3.5 py-3 text-[14px] text-text-muted">{t.noneYet}</p>}
          </section>
        </>
      )}
    </Page>
  )
}

/** My attendance (the approved board): the semester rate, course by course, and what was missed or marked by hand. */
export function AttendancePage({ me }: { me: Me }) {
  const a = copy.attendance
  const loaded = useAnswer(studentApi.attendance, 'attendance')
  const d = loaded.data

  return (
    <Page me={me} tab="courses">
      <Title>{a.title}</Title>
      {d === undefined ? (
        <Loading failed={loaded.failed} onRetry={loaded.reload} />
      ) : d.held === 0 ? (
        <p className="text-text-muted">{a.noneYet}</p>
      ) : (
        <>
          <div className="grid grid-cols-2 border-2 border-ink">
            <div className="bg-ink px-3.5 py-2.5 text-sheet">
              <span className="label-caps text-on-ink-muted">{a.semester}</span>
              <div className="type-display text-[40px] leading-none text-teal-bright">{`${d.rate ?? 0}%`}</div>
            </div>
            <div className="border-l-2 border-ink bg-sheet px-3.5 py-2.5">
              <span className="label-caps text-text-muted">{a.missed}</span>
              <div className="type-display text-[40px] leading-none">{a.missedOf(d.missed, d.held)}</div>
            </div>
          </div>
          <Section title={a.byCourse}>
            {d.courses.map((c) => (
              <Line
                key={c.code}
                strong={`${c.code} · ${c.title}`}
                sub={a.attended(c.rate ?? 0)}
                right={c.rate !== null && c.rate < 80 ? <Tag tone="attention">{a.below}</Tag> : <Tag tone="done">{a.ok}</Tag>}
              />
            ))}
          </Section>
          <Section title={a.marks}>
            {d.marks.length === 0 ? (
              <p className="px-3.5 py-3 text-[14px] text-text-muted">{a.none}</p>
            ) : (
              d.marks.map((m, i) => (
                <Line
                  key={`${m.day}${m.code}${i}`}
                  strong={`${shortDay(m.day)} · ${m.code} ${kind(m.kind)}`}
                  sub={m.was !== null ? a.corrected(a.marksWord[m.was] ?? m.was, m.by, m.reason) : m.source === 'hand' ? a.byHand(m.by, m.reason) : a.notScanned}
                  right={m.mark === 'present' ? <Tag tone="done">{t.present}</Tag> : m.mark === 'late' ? <Tag tone="attention">{t.late}</Tag> : <Tag tone="wrong">{t.absent}</Tag>}
                />
              ))
            )}
          </Section>
          <p className="text-[13px] text-text-muted">{a.wrong}</p>
        </>
      )}
    </Page>
  )
}

/** Your attendance phone (the approved board): made with the phone's own lock; one a student. */
export function PhonePage({ me }: { me: Me }) {
  const p = copy.phone
  const loaded = useAnswer(studentApi.lecturesToday, 'phone')
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState<string | null>(null)
  const d = loaded.data
  const set = async () => {
    setBusy(true)
    setMessage(null)
    if (!(await canHoldPasskey())) {
      setBusy(false)
      return setMessage(p.cannot)
    }
    const begin = await studentApi.phoneBegin()
    if (begin === null || begin === 'refused') {
      setBusy(false)
      return setMessage(copy.failed)
    }
    const made = await makePasskey(begin, me.college)
    const saved = made === null ? null : await studentApi.phoneSet(made)
    setBusy(false)
    if (saved === null) return setMessage(p.stopped)
    if (saved.kind === 'saved') return loaded.reload()
    setMessage(saved.kind === 'invalid' && saved.message !== '' ? saved.message : copy.failed)
  }

  return (
    <Page me={me} tab="more">
      <Title>{p.title}</Title>
      {d === undefined ? (
        <Loading failed={loaded.failed} onRetry={loaded.reload} />
      ) : d.phone.set ? (
        <>
          <div className="flex items-baseline gap-2.5 border border-teal bg-teal-wash px-3 py-2.5">
            <strong aria-hidden="true">✓</strong>
            <span>
              <strong>{p.doneTitle}</strong> {p.done(shortDay(d.phone.set_on ?? d.day))}
            </span>
          </div>
          <p className="text-[14px] text-text-muted">{p.other}</p>
        </>
      ) : (
        <>
          <p>{p.intro}</p>
          <Section title={p.how}>
            <Line strong={p.lock} sub={p.lockNote} />
            <Line strong={p.only} sub={p.onlyNote} />
            <Line strong={p.lost} sub={p.lostNote} />
          </Section>
          {message !== null && (
            <div className="flex items-baseline gap-2.5 border border-red-pen bg-red-wash px-3 py-2.5 text-red-text" role="alert">
              <strong aria-hidden="true">×</strong>
              <span>{message}</span>
            </div>
          )}
          <Primary busy={busy} onClick={() => void set()}>
            {p.make}
          </Primary>
          <p className="text-[13px] text-text-muted">{p.location}</p>
        </>
      )}
    </Page>
  )
}

type Outcome = { kind: 'done'; result: ScanResult } | { kind: 'message'; text: string } | null

/** Scan in (the approved board): the camera reads the room code; the phone's lock signs it; the place is read once. */
export function ScanPage() {
  const s = copy.scan
  const today = useAnswer(studentApi.lecturesToday, 'scan')
  const video = useRef<HTMLVideoElement>(null)
  const [round, setRound] = useState(0)
  const [phase, setPhase] = useState<'camera' | 'checking' | 'over'>('camera')
  const [outcome, setOutcome] = useState<Outcome>(null)
  const d = today.data
  const phone = d?.phone

  useEffect(() => {
    if (phone === undefined || !phone.set || phone.credential_id === null) return
    let stream: MediaStream | null = null
    let frame = 0
    let live = true
    const canvas = document.createElement('canvas')
    const look = async () => {
      const v = video.current
      if (!live || v === null) return
      if (v.readyState >= 2 && v.videoWidth > 0) {
        canvas.width = v.videoWidth
        canvas.height = v.videoHeight
        const g = canvas.getContext('2d', { willReadFrequently: true })
        g?.drawImage(v, 0, 0)
        const image = g?.getImageData(0, 0, canvas.width, canvas.height)
        const found = image === undefined ? null : jsQR(image.data, image.width, image.height, { inversionAttempts: 'dontInvert' })
        if (found !== null && found.data.startsWith('AQXL.')) {
          live = false
          stream?.getTracks().forEach((track) => track.stop())
          setPhase('checking')
          const [signed, place] = await Promise.all([signCode(found.data, phone.credential_id ?? '', phone.rp_id), d?.location === true ? wherePhoneIs() : Promise.resolve(null)])
          if (signed === null) {
            setOutcome({ kind: 'message', text: s.confirm })
            return setPhase('over')
          }
          const answer = await studentApi.scan({ code: found.data, ...signed, latitude: place?.latitude ?? null, longitude: place?.longitude ?? null, accuracy: place?.accuracy ?? null })
          setOutcome(answer === null ? { kind: 'message', text: s.failed } : 'invalid' in answer ? { kind: 'message', text: answer.invalid } : { kind: 'done', result: answer })

          return setPhase('over')
        }
      }
      frame = window.setTimeout(() => void look(), 250)
    }
    void navigator.mediaDevices
      ?.getUserMedia({ video: { facingMode: 'environment' }, audio: false })
      .then((s) => {
        stream = s
        if (video.current !== null) {
          video.current.srcObject = s
          void video.current.play()
        }
        void look()
      })
      .catch(() => (setOutcome({ kind: 'message', text: copy.scan.noCamera }), setPhase('over')))

    return () => {
      live = false
      window.clearTimeout(frame)
      stream?.getTracks().forEach((track) => track.stop())
    }
  }, [phone, d?.location, round, s.confirm, s.failed])

  const again = () => (setOutcome(null), setPhase('camera'), setRound(round + 1))
  const r = outcome?.kind === 'done' ? outcome.result : null

  return (
    <div className="mx-auto flex min-h-screen max-w-[640px] flex-col bg-paper text-ink">
      <header className="flex items-center gap-3 bg-ink px-4 py-3.5 text-sheet">
        <a
          href="/"
          aria-label={s.back}
          onClick={(e) => (e.preventDefault(), navigate('/'))}
          className="grid min-h-11 min-w-11 place-items-center text-[22px] font-extrabold text-sheet no-underline"
        >
          ‹
        </a>
        <span className="flex flex-col">
          <span className="text-[13px] text-on-ink-muted">{s.title}</span>
          {d?.open !== null && d?.open !== undefined && <b className="text-[18px]">{`${d.open.code} · ${kind(d.open.kind)} · ${d.open.room}`}</b>}
        </span>
      </header>
      <main className="flex flex-1 flex-col gap-3 px-4 py-3.5">
        {d === undefined ? (
          <Loading failed={today.failed} onRetry={today.reload} />
        ) : !d.phone.set ? (
          <>
            <p>{s.noPhone}</p>
            <Primary onClick={() => navigate('/phone')}>{s.setUp}</Primary>
          </>
        ) : (
          <>
            {phase === 'camera' && (
              <div className="relative grid h-[330px] place-items-center overflow-hidden bg-ink">
                <video ref={video} muted playsInline className="absolute inset-0 size-full object-cover" aria-label={s.point} />
                <div className="relative grid size-[220px] place-items-center border-[3px] border-teal-bright p-4 text-center text-[14px] text-on-ink-soft">{s.point}</div>
              </div>
            )}
            {phase === 'checking' && <p className="border-2 border-ink bg-sheet px-3.5 py-3">{s.checking}</p>}
            {phase === 'over' && (
              <Section strong>
                <div className="grid gap-3 px-3.5 py-3" role="status">
                  {r !== null && r.result === 'present' && (
                    <span className="flex items-baseline gap-2.5">
                      <Tag tone="done">{s.presentTag}</Tag>
                      <span>{s.present(r.course, r.at ?? '')}</span>
                    </span>
                  )}
                  {r !== null && r.result === 'late' && (
                    <span className="flex items-baseline gap-2.5">
                      <Tag tone="attention">{s.lateTag}</Tag>
                      <span>{s.late(r.course)}</span>
                    </span>
                  )}
                  {r !== null && r.result === 'refused' && (
                    <span className="flex items-baseline gap-2.5">
                      <Tag tone="wrong">{s.refused}</Tag>
                      <span>{s.why[r.why ?? ''] ?? s.failed}</span>
                    </span>
                  )}
                  {outcome?.kind === 'message' && (
                    <span className="flex items-baseline gap-2.5">
                      <Tag tone="wrong">{s.refused}</Tag>
                      <span>{outcome.text}</span>
                    </span>
                  )}
                  {(r === null || r.result === 'refused') && <Primary onClick={again}>{s.again}</Primary>}
                </div>
              </Section>
            )}
          </>
        )}
      </main>
    </div>
  )
}
