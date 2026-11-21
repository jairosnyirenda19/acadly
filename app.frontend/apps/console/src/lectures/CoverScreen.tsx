import { useLoad } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { firstError } from '../programmes/api'
import { Attention, DivisionChoice, Sheet } from '../programmes/parts'
import { PageHead, SmallButton, Tag } from '../settings/parts'
import { Input, Select } from '../setup/parts'
import { addDays, lecturesApi, shortDay, useLectureDivision, type LectureRow } from './api'

const t = copy.lectures
const c = t.cover

/** A lecture's state as a tag, with its mark. */
export function CoverTag({ state }: { state: string }) {
  const tone = state === 'away' ? 'attention' : state === 'covered' ? 'done' : state === 'cancelled' ? 'wrong' : 'plain'

  return <Tag tone={tone}>{c.states[state] ?? state}</Tag>
}

/** What happened to a lecture, in a sentence. */
export function coverLine(l: LectureRow): string {
  if (l.state === 'away') return c.cannotCome(l.lecturer, l.reason)
  if (l.state === 'asked') return c.askedWho(l.asked ?? '')
  if (l.state === 'covered') return [c.coveredBy(l.cover ?? ''), l.taken !== null ? c.taken(l.taken.present, l.students) : ''].join(' ').trim()
  if (l.state === 'cancelled') return c.cancelledWhy(l.reason)
  if (l.moved !== null) return c.movedTo(`${shortDay(l.moved.day)}, ${l.moved.starts}, ${l.moved.room}`, l.reason)

  return ''
}

/** Cover (D-102, the approved board): the week's lectures whose lecturer cannot come, and what to do about each. */
export function CoverScreen() {
  const { division, choices, choose } = useLectureDivision(['lectures.view', 'lectures.cover'])
  const [week, setWeek] = useState<string | null>(null)
  if (division === null) {
    return (
      <div className="px-[clamp(16px,3vw,40px)] pt-7">
        <Attention>
          <b>{t.chooseTitle}.</b> {t.choose}
        </Attention>
      </div>
    )
  }

  return <Loaded key={`${division.id}:${week ?? ''}`} division={division.id} week={week} setWeek={setWeek} choice={<DivisionChoice choices={choices} division={division} choose={choose} />} />
}

function Loaded({ division, week, setWeek, choice }: { division: string; week: string | null; setWeek: (w: string) => void; choice: React.ReactNode }) {
  const loaded = useLoad(`lecture-cover:${division}:${week ?? ''}`, () => lecturesApi.cover(division, week))
  const [picked, setPicked] = useState<string | null>(null)

  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={c.title} labels={copy.loading} rows={6} onRetry={loaded.reload} />
  const data = loaded.data
  const key = (l: LectureRow) => `${l.slot_id}|${l.day}`
  const chosen = data.rows.find((l) => key(l) === picked) ?? null

  return (
    <>
      <PageHead kicker={`${t.kicker} · ${c.weekOf(shortDay(data.week))}`} title={c.title} intro={c.intro} actions={choice} />
      <div className="grid gap-4 px-[clamp(16px,3vw,40px)] pb-8">
        <div className="flex items-center gap-2">
          <SmallButton onClick={() => setWeek(addDays(data.week, -7))}>{`‹ ${c.lastWeek}`}</SmallButton>
          <SmallButton onClick={() => setWeek(addDays(data.week, 7))}>{`${c.nextWeek} ›`}</SmallButton>
        </div>
        <div className="grid grid-cols-2 border-2 border-ink md:grid-cols-4">
          {(
            [
              [c.need, data.counts.need, data.counts.need > 0],
              [c.asked, data.counts.asked, false],
              [c.covered, data.counts.covered, false],
              [c.changed, data.counts.changed, false],
            ] as const
          ).map(([label, n, hot], i) => (
            <div key={label} className={`px-4 py-3 ${i > 0 ? 'md:border-l-2 md:border-ink' : ''} ${hot ? 'bg-marigold' : 'bg-sheet'}`}>
              <span className="label-caps text-text-muted">{label}</span>
              <div className="type-display text-[44px] leading-none">{n}</div>
            </div>
          ))}
        </div>
        <div className="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_420px]">
          <Sheet label={c.week}>
            <h2 className="label-caps border-b border-rule px-3.5 py-2.5 text-text-muted">{c.week}</h2>
            {data.rows.length === 0 ? (
              <p className="px-3.5 py-3 text-text-muted">{c.none}</p>
            ) : (
              <ul>
                {data.rows.map((l) => (
                  <li key={key(l)} className="border-b border-rule last:border-b-0">
                    <button
                      type="button"
                      aria-pressed={picked === key(l)}
                      onClick={() => setPicked(key(l))}
                      className={`flex min-h-12 w-full items-center justify-between gap-3 px-3.5 py-2.5 text-left ${picked === key(l) ? 'bg-paper' : 'hover:bg-paper'}`}
                    >
                      <span className="flex min-w-0 flex-col">
                        <strong>{`${shortDay(l.day)} · ${t.span(l.starts, l.ends)} · ${l.code} ${t.kinds[l.kind] ?? l.kind} · ${l.room}`}</strong>
                        <span className="text-[14px] text-text-muted">{`${coverLine(l)} ${t.students(l.students)}`}</span>
                      </span>
                      <CoverTag state={l.state} />
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </Sheet>
          {chosen === null ? (
            <p className="text-[14px] text-text-muted">{c.choose}</p>
          ) : (
            <Arrange key={key(chosen)} division={division} l={chosen} can={data.can_arrange} done={() => loaded.reload()} />
          )}
        </div>
      </div>
    </>
  )
}

/** Arranging one lecture: ask or name a free colleague, move it, or cancel it. */
export function Arrange({ division, l, can, done }: { division: string; l: LectureRow; can: boolean; done: () => void }) {
  const free = useLoad(`lecture-free:${division}:${l.slot_id}:${l.day}`, () => lecturesApi.free(division, l.slot_id, l.day))
  const rooms = useLoad(`lecture-rooms:${division}`, () => lecturesApi.rooms(division))
  const [moving, setMoving] = useState(false)
  const [to, setTo] = useState({ to_day: l.day, to_starts: l.starts, to_ends: l.ends, to_room: '' })
  const [busy, setBusy] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)
  const changeable = l.state !== 'cancelled' && l.taken === null

  const act = async (action: 'ask' | 'name' | 'cancel' | 'move', extra: Record<string, string> = {}) => {
    let reason = ''
    if (action === 'cancel' || action === 'move') {
      const given = window.prompt(t.reasonPrompt)
      if (given === null) return
      reason = given
    }
    setBusy(true)
    setProblem(null)
    const result = await lecturesApi.act(division, l.slot_id, action, { day: l.day, reason, ...extra })
    setBusy(false)
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: c.done })
      done()
    } else setProblem(firstError(result, t.failed))
  }

  return (
    <Sheet label={`${shortDay(l.day)} · ${l.code}`}>
      <h2 className="label-caps border-b border-rule px-3.5 py-2.5 text-text-muted">{`${shortDay(l.day)} · ${l.code} ${t.kinds[l.kind] ?? l.kind}`}</h2>
      <div className="grid gap-3 px-3.5 py-3">
        {problem !== null && (
          <Notice tone="wrong" title={t.notSaved}>
            {problem}
          </Notice>
        )}
        {(l.state === 'away' || l.state === 'asked' || (can && l.state === 'covered')) && (
          <>
            <span className="label-caps text-text-muted">{c.free(l.starts, l.ends)}</span>
            {free.data === undefined ? (
              <Waiting phase={free.phase} what={c.free(l.starts, l.ends)} labels={copy.loading} rows={3} onRetry={free.reload} />
            ) : (
              <ul className="grid">
                {free.data.colleagues.map((p) => (
                  <li key={p.id} className="flex min-h-11 items-center justify-between gap-2 border-b border-rule py-1.5 last:border-b-0">
                    <span>{p.name}</span>
                    {p.free ? (
                      <span className="flex gap-1.5">
                        {l.state === 'away' && <SmallButton onClick={() => void act('ask', { user_id: p.id })}>{c.ask}</SmallButton>}
                        {can && <SmallButton onClick={() => void act('name', { user_id: p.id })}>{c.name}</SmallButton>}
                      </span>
                    ) : (
                      <Tag tone="wrong">{c.busy}</Tag>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </>
        )}
        {can && changeable && !moving && (
          <div className="flex flex-wrap gap-2">
            <Button variant="secondary" onClick={() => setMoving(true)}>
              {c.move}
            </Button>
            <Button variant="secondary" busy={busy} onClick={() => void act('cancel')}>
              {c.cancel}
            </Button>
          </div>
        )}
        {can && moving && (
          <div className="grid gap-2.5 border border-rule px-3 py-2.5">
            <div className="grid grid-cols-3 gap-2">
              <Input label={c.moveDay} type="date" value={to.to_day} onChange={(e) => setTo({ ...to, to_day: e.target.value })} />
              <Input label={t.timetable.from} type="time" value={to.to_starts} onChange={(e) => setTo({ ...to, to_starts: e.target.value })} />
              <Input label={t.timetable.to} type="time" value={to.to_ends} onChange={(e) => setTo({ ...to, to_ends: e.target.value })} />
            </div>
            <Select label={t.timetable.room} value={to.to_room} onChange={(e) => setTo({ ...to, to_room: e.target.value })}>
              <option value="">{l.room}</option>
              {(rooms.data?.rooms ?? [])
                .filter((x) => !x.retired)
                .map((x) => (
                  <option key={x.id} value={x.id}>{`${x.code} · ${x.seats}`}</option>
                ))}
            </Select>
            <div className="flex gap-2">
              <Button busy={busy} onClick={() => void act('move', to.to_room === '' ? { to_day: to.to_day, to_starts: to.to_starts, to_ends: to.to_ends } : to)}>
                {c.save}
              </Button>
              <Button variant="secondary" onClick={() => setMoving(false)}>
                {c.back}
              </Button>
            </div>
          </div>
        )}
        {!can && <p className="text-[14px] text-text-muted">{c.readOnly}</p>}
        <p className="text-[13px] text-text-muted">{c.note}</p>
      </div>
    </Sheet>
  )
}
