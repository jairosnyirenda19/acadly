import { useLoad } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { firstError } from '../programmes/api'
import { Attention, DivisionChoice, Sheet } from '../programmes/parts'
import { PageHead, Tag } from '../settings/parts'
import { Input, Select } from '../setup/parts'
import { lecturesApi, useLectureDivision, type Check, type Slot, type SlotInput } from './api'

const t = copy.lectures
const tt = t.timetable
const HOUR = 56

type View = 'cohort' | 'room' | 'lecturer'

const minutes = (hm: string) => Number(hm.slice(0, 2)) * 60 + Number(hm.slice(3, 5))

/** The lecture timetable (D-102, the approved board): a week for one programme and year, a room or a lecturer, with its checks. */
export function TimetableScreen() {
  const { division, choices, choose } = useLectureDivision(['lectures.view', 'lectures.timetable', 'lectures.teach', 'lectures.cover'])
  const [period, setPeriod] = useState<string | null>(null)
  if (division === null) {
    return (
      <div className="px-[clamp(16px,3vw,40px)] pt-7">
        <Attention>
          <b>{t.chooseTitle}.</b> {t.choose}
        </Attention>
      </div>
    )
  }

  return (
    <Loaded
      key={`${division.id}:${period ?? ''}`}
      division={division.id}
      period={period}
      setPeriod={setPeriod}
      choice={<DivisionChoice choices={choices} division={division} choose={choose} />}
    />
  )
}

function Loaded({ division, period, setPeriod, choice }: { division: string; period: string | null; setPeriod: (p: string) => void; choice: React.ReactNode }) {
  const loaded = useLoad(`lecture-timetable:${division}:${period ?? ''}`, () => lecturesApi.timetable(division, period))
  const [view, setView] = useState<View>('cohort')
  const [focus, setFocus] = useState<string>('')
  const [editing, setEditing] = useState<SlotInput | null>(null)
  const [busy, setBusy] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)

  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={tt.title} labels={copy.loading} rows={8} onRetry={loaded.reload} />
  const data = loaded.data
  if (data.period === null) {
    return (
      <>
        <PageHead kicker={t.kicker} title={tt.title} intro="" actions={choice} />
        <div className="px-[clamp(16px,3vw,40px)]">
          <Attention>{tt.none}</Attention>
        </div>
      </>
    )
  }
  const sem = data.period
  const offering = Object.fromEntries(data.offerings.map((o) => [o.id, o]))
  const room = Object.fromEntries(data.rooms.map((x) => [x.id, x]))
  const label = (s: Slot) => `${offering[s.offering_id]?.code ?? ''} ${t.kinds[s.kind] ?? s.kind}, ${t.short[s.weekday] ?? ''} ${s.starts}`
  const byId = Object.fromEntries(data.slots.map((s) => [s.id, s]))
  const wrong = new Set(data.checks.filter((c) => c.tone === 'wrong').flatMap((c) => c.slots))
  const small = new Set(data.checks.filter((c) => c.kind === 'seats').flatMap((c) => c.slots))
  const problems = data.checks.length

  const choicesFor: { id: string; label: string }[] =
    view === 'cohort' ? data.cohorts.map((c) => ({ id: c.id, label: c.label })) : view === 'room' ? data.rooms.map((x) => ({ id: x.id, label: `${x.code} · ${x.name}` })) : data.staff.map((s) => ({ id: s.id, label: s.name }))
  const chosen = choicesFor.find((c) => c.id === focus)?.id ?? choicesFor[0]?.id ?? ''
  const cohort = data.cohorts.find((c) => c.id === chosen)
  const shown = data.slots.filter((s) => (view === 'cohort' ? (cohort?.offerings.includes(s.offering_id) ?? true) : view === 'room' ? s.room_id === chosen : s.lecturer_id === chosen))
  const days = shown.some((s) => s.weekday === 6) ? [1, 2, 3, 4, 5, 6] : [1, 2, 3, 4, 5]
  const first = Math.min(8, ...shown.map((s) => Math.floor(minutes(s.starts) / 60)))
  const last = Math.max(17, ...shown.map((s) => Math.ceil(minutes(s.ends) / 60)))
  const hours = Array.from({ length: last - first }, (_, i) => first + i)

  const save = async () => {
    if (editing === null) return
    setBusy(true)
    setProblem(null)
    const result = await lecturesApi.saveSlot(division, sem.id, editing)
    setBusy(false)
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: tt.saved })
      setEditing(null)
      loaded.reload()
    } else setProblem(firstError(result, t.failed))
  }
  const remove = async () => {
    if (editing?.id === null || editing === null || !window.confirm(tt.removeConfirm)) return
    const result = await lecturesApi.removeSlot(division, editing.id)
    if (result.kind === 'saved') {
      setEditing(null)
      loaded.reload()
    } else setProblem(firstError(result, t.failed))
  }
  const publish = async () => {
    setBusy(true)
    const answer = await lecturesApi.publish(division, sem.id)
    setBusy(false)
    if (answer === null) setProblem(t.failed)
    else if (answer.published) {
      slips.done({ word: copy.slips.saved, title: tt.publishedNow })
      loaded.reload()
    } else setProblem(tt.stopped)
  }
  const fresh = (): SlotInput => ({
    id: null,
    offering_id: view === 'cohort' ? (cohort?.offerings[0] ?? '') : '',
    kind: 'lecture',
    weekday: 1,
    starts: '08:00',
    ends: '10:00',
    room_id: view === 'room' ? chosen : (data.rooms[0]?.id ?? ''),
    lecturer_id: view === 'lecturer' ? chosen : '',
  })

  return (
    <>
      <PageHead
        kicker={`${t.kicker} · ${sem.name}`}
        title={tt.title}
        intro=""
        actions={
          <div className="flex flex-wrap items-center gap-2.5">
            {choice}
            {data.published ? <Tag tone="done">{tt.published}</Tag> : <Tag tone={problems > 0 ? 'attention' : 'plain'}>{tt.draft(problems)}</Tag>}
            {data.can_change && !data.published && (
              <Button busy={busy} onClick={() => void publish()}>
                {tt.publish}
              </Button>
            )}
          </div>
        }
      />
      <div className="grid gap-4 px-[clamp(16px,3vw,40px)] pb-8">
        <div className="flex flex-wrap items-end gap-2.5">
          {data.periods.length > 1 && (
            <div className="w-48">
              <Select label={tt.semester} value={sem.id} onChange={(e) => setPeriod(e.target.value)}>
                {data.periods.map((x) => (
                  <option key={x.id} value={x.id}>
                    {x.name}
                  </option>
                ))}
              </Select>
            </div>
          )}
          <div role="group" aria-label={tt.viewBy} className="flex flex-wrap items-center gap-2">
            <span className="text-[14px] text-text-muted">{tt.viewBy}</span>
            {(['cohort', 'room', 'lecturer'] as View[]).map((v) => (
              <button
                key={v}
                type="button"
                aria-pressed={view === v}
                onClick={() => (setView(v), setFocus(''))}
                className={`min-h-10 border-2 border-ink px-3.5 font-bold ${view === v ? 'bg-ink text-sheet' : 'bg-sheet text-ink'}`}
              >
                {tt.views[v]}
              </button>
            ))}
          </div>
          {choicesFor.length > 0 && (
            <div className="w-72">
              <Select label={tt.views[view]} value={chosen} onChange={(e) => setFocus(e.target.value)}>
                {choicesFor.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.label}
                  </option>
                ))}
              </Select>
            </div>
          )}
        </div>
        {problem !== null && (
          <Notice tone="wrong" title={t.notSaved}>
            {problem}
          </Notice>
        )}
        <div className="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_340px]">
          <div className="overflow-x-auto">
            <div className="relative grid min-w-[720px] border-2 border-ink bg-sheet" style={{ gridTemplateColumns: `64px repeat(${days.length}, minmax(0, 1fr))` }}>
              <div className="h-10 border-b-2 border-ink" />
              {days.map((d) => (
                <div key={d} className="h-10 border-b-2 border-l border-ink border-l-rule px-2.5 py-2 font-extrabold">
                  {t.short[d]}
                </div>
              ))}
              {hours.map((h) => (
                <Row key={h} hour={h} days={days.length} />
              ))}
              <div className="pointer-events-none absolute inset-0" style={{ top: 40 }}>
                {days.map((d, col) =>
                  lanes(shown.filter((s) => s.weekday === d)).map(({ slot: s, lane, of }) => {
                    const top = ((minutes(s.starts) - first * 60) / 60) * HOUR
                    const height = ((minutes(s.ends) - minutes(s.starts)) / 60) * HOUR
                    const sel = editing?.id === s.id
                    const isWrong = wrong.has(s.id)
                    const isSmall = small.has(s.id)
                    const tone = sel ? 'border-ink bg-ink text-sheet' : isWrong ? 'border-red-pen bg-red-wash' : isSmall ? 'border-marigold bg-marigold-wash' : 'border-ink-rule bg-paper'
                    const o = offering[s.offering_id]

                    return (
                      <button
                        key={s.id}
                        type="button"
                        disabled={!data.can_change}
                        onClick={() => setEditing({ id: s.id, offering_id: s.offering_id, kind: s.kind, weekday: s.weekday, starts: s.starts, ends: s.ends, room_id: s.room_id, lecturer_id: s.lecturer_id })}
                        className={`pointer-events-auto absolute overflow-hidden border-2 px-1.5 py-1 text-left text-[12px] leading-tight ${tone}`}
                        style={{
                          top: top + 3,
                          height: height - 6,
                          left: `calc(64px + (100% - 64px) * ${col / days.length} + (100% - 64px) / ${days.length} * ${lane / of} + 3px)`,
                          width: `calc((100% - 64px) / ${days.length} / ${of} - 6px)`,
                        }}
                      >
                        <b className="text-[13px]">{`${isWrong ? '× ' : isSmall ? '! ' : ''}${o?.code ?? ''}`}</b>
                        {` · ${t.kinds[s.kind] ?? s.kind}`}
                        <br />
                        {`${room[s.room_id]?.code ?? ''} · ${s.lecturer}`}
                      </button>
                    )
                  }),
                )}
              </div>
            </div>
          </div>
          <div className="grid gap-3">
            {data.can_change && data.rooms.length === 0 && <Attention>{tt.noRooms}</Attention>}
            {data.can_change && editing === null && data.rooms.length > 0 && (
              <div>
                <Button onClick={() => setEditing(fresh())}>{tt.add}</Button>
              </div>
            )}
            {!data.can_change && <p className="text-[14px] text-text-muted">{tt.readOnly}</p>}
            {editing !== null && (
              <Sheet label={tt.newTitle}>
                <h2 className="label-caps border-b border-rule px-3.5 py-2.5 text-text-muted">
                  {editing.id === null ? tt.newTitle : `${offering[editing.offering_id]?.code ?? ''} · ${t.days[editing.weekday] ?? ''}`}
                </h2>
                <div className="grid gap-3 px-3.5 py-3">
                  <Select label={tt.offering} value={editing.offering_id} onChange={(e) => setEditing({ ...editing, offering_id: e.target.value })}>
                    <option value="">{tt.choose}</option>
                    {data.offerings.map((o) => (
                      <option key={o.id} value={o.id}>{`${o.code} · ${o.title}${o.group !== 'A' ? ` · ${o.group}` : ''} · ${t.students(o.taken)}`}</option>
                    ))}
                  </Select>
                  <Select label={tt.kind} value={editing.kind} onChange={(e) => setEditing({ ...editing, kind: e.target.value })}>
                    {Object.entries(t.kinds).map(([k, v]) => (
                      <option key={k} value={k}>
                        {v}
                      </option>
                    ))}
                  </Select>
                  <div className="grid grid-cols-3 gap-2">
                    <Select label={tt.day} value={String(editing.weekday)} onChange={(e) => setEditing({ ...editing, weekday: Number(e.target.value) })}>
                      {[1, 2, 3, 4, 5, 6].map((d) => (
                        <option key={d} value={d}>
                          {t.days[d]}
                        </option>
                      ))}
                    </Select>
                    <Input label={tt.from} type="time" value={editing.starts} onChange={(e) => setEditing({ ...editing, starts: e.target.value })} />
                    <Input label={tt.to} type="time" value={editing.ends} onChange={(e) => setEditing({ ...editing, ends: e.target.value })} />
                  </div>
                  <Select label={tt.room} value={editing.room_id} onChange={(e) => setEditing({ ...editing, room_id: e.target.value })}>
                    {data.rooms.map((x) => (
                      <option key={x.id} value={x.id}>{`${x.code} · ${x.seats}`}</option>
                    ))}
                  </Select>
                  <Select label={tt.lecturer} value={editing.lecturer_id} onChange={(e) => setEditing({ ...editing, lecturer_id: e.target.value })}>
                    <option value="">{tt.choose}</option>
                    {data.staff.map((s) => (
                      <option key={s.id} value={s.id}>
                        {s.name}
                      </option>
                    ))}
                  </Select>
                  <div className="flex flex-wrap gap-2">
                    <Button busy={busy} onClick={() => void save()}>
                      {tt.save}
                    </Button>
                    {editing.id !== null && (
                      <Button variant="secondary" onClick={() => void remove()}>
                        {tt.remove}
                      </Button>
                    )}
                    <Button variant="secondary" onClick={() => setEditing(null)}>
                      {t.rooms.cancel}
                    </Button>
                  </div>
                </div>
              </Sheet>
            )}
            {data.can_change && <Checks checks={data.checks} label={(id) => (byId[id] !== undefined ? label(byId[id]) : '')} seats={(id) => room[byId[id]?.room_id ?? '']?.seats ?? 0} />}
            <p className="text-[13px] text-text-muted">{tt.note}</p>
          </div>
        </div>
      </div>
    </>
  )
}

function Row({ hour, days }: { hour: number; days: number }) {
  return (
    <>
      <div className="border-b border-rule px-2 py-1 font-mono text-[12px] text-text-muted" style={{ height: HOUR }}>{`${String(hour).padStart(2, '0')}:00`}</div>
      {Array.from({ length: days }, (_, i) => (
        <div key={i} className="border-b border-l border-rule" style={{ height: HOUR }} />
      ))}
    </>
  )
}

/** Places a day's lectures side by side where they overlap. */
function lanes(slots: Slot[]): { slot: Slot; lane: number; of: number }[] {
  const sorted = [...slots].sort((a, b) => a.starts.localeCompare(b.starts))
  const ends: string[] = []
  const placed = sorted.map((slot) => {
    let lane = ends.findIndex((end) => end <= slot.starts)
    if (lane === -1) lane = ends.length
    ends[lane] = slot.ends

    return { slot, lane }
  })
  const of = Math.max(1, ends.length)

  return placed.map((p) => ({ ...p, of }))
}

function Checks({ checks, label, seats }: { checks: Check[]; label: (id: string) => string; seats: (id: string) => number }) {
  return (
    <Sheet label={tt.checks}>
      <h2 className="label-caps border-b border-rule px-3.5 py-2.5 text-text-muted">{tt.checks}</h2>
      {checks.length === 0 ? (
        <p className="px-3.5 py-3">{tt.noChecks}</p>
      ) : (
        <ul>
          {checks.map((c, i) => (
            <li key={i} className={`border-b border-rule px-3.5 py-2.5 text-[14px] last:border-b-0 ${c.tone === 'wrong' ? 'text-red-text' : ''}`}>
              {c.kind === 'students'
                ? tt.check.students(label(c.slots[0] ?? ''), label(c.slots[1] ?? ''), c.count)
                : c.kind === 'room'
                  ? tt.check.room(label(c.slots[0] ?? ''), label(c.slots[1] ?? ''))
                  : c.kind === 'lecturer'
                    ? tt.check.lecturer(label(c.slots[0] ?? ''), label(c.slots[1] ?? ''))
                    : tt.check.seats(label(c.slots[0] ?? ''), c.count, seats(c.slots[0] ?? ''))}
            </li>
          ))}
        </ul>
      )}
    </Sheet>
  )
}
