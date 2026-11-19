import { navigate, useLoad, useLocation } from '@acadly/kernel'
import { Button, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { today } from '../fees/api'
import { PageHead } from '../settings/parts'
import { Input, Select } from '../setup/parts'
import { earlyApi, firstError, type RoomDay, type StepKey } from './api'
import { RoomPicker } from './MyRoomScreen'
import { ALL_STEPS, isStep, StepLegend, StepMark } from './steps'

const t = copy.early

/** A new observation (the approved design): one learning area, one day, a step for each child seen. */
export function ObservationScreen() {
  const { query } = useLocation()
  const rooms = useLoad('early:rooms', () => earlyApi.rooms())
  const list = rooms.data?.rooms ?? []
  const room = query.get('room') ?? list[0]?.id ?? ''
  const [area, setArea] = useState(query.get('area') ?? '')
  const [on, setOn] = useState(query.get('day') ?? today())

  if (rooms.data === undefined) return <Waiting phase={rooms.phase} what={t.obsTitle} labels={copy.loading} rows={6} onRetry={rooms.reload} />
  if (list.length === 0) return <PageHead kicker={t.obsKicker} title={t.obsTitle} intro={t.noRooms} />

  return (
    <>
      <PageHead kicker={t.obsKicker} title={t.obsTitle} intro={t.obsIntro} />
      <RoomPicker rooms={list} value={room} onChange={(r) => navigate(`/observations?room=${r}`)} />
      <Sheet key={`${room}|${area}|${on}`} room={room} area={area} on={on} onArea={setArea} onDay={setOn} />
    </>
  )
}

function Sheet({ room, area, on, onArea, onDay }: { room: string; area: string; on: string; onArea: (a: string) => void; onDay: (d: string) => void }) {
  const loaded = useLoad(`early:day:${room}:${area}:${on}`, () => earlyApi.day(room, area, on))
  const data = loaded.data

  if (data === undefined) return <Waiting phase={loaded.phase} what={t.obsTitle} labels={copy.loading} rows={6} onRetry={loaded.reload} />

  return <Form data={data} onArea={onArea} onDay={onDay} />
}

function Form({ data, onArea, onDay }: { data: RoomDay; onArea: (a: string) => void; onDay: (d: string) => void }) {
  const [focus, setFocus] = useState(data.focus ?? '')
  const [steps, setSteps] = useState<Record<string, StepKey>>(() => Object.fromEntries(data.children.flatMap((c) => (isStep(c.step) ? [[c.id, c.step]] : []))))
  const [notes, setNotes] = useState<Record<string, string>>(() => Object.fromEntries(data.children.map((c) => [c.id, c.note ?? ''])))
  const [noting, setNoting] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const chosen = Object.keys(steps).length
  const seen = Object.values(steps).filter((s) => s !== 'not_seen').length

  const save = async () => {
    if (chosen === 0) {
      slips.wrong({ word: t.notSaved, title: t.noneChosen })
      return
    }

    setBusy(true)
    const result = await earlyApi.record(data.room.id, {
      subject_id: data.subject.id,
      observed_on: data.day,
      focus: focus.trim(),
      marks: Object.entries(steps).map(([id, step]) => ({ learner_id: id, step, note: (notes[id] ?? '').trim() === '' ? null : (notes[id] ?? '').trim() })),
    })
    setBusy(false)
    if (result.kind === 'saved') slips.done({ word: copy.slips.saved, title: t.saved })
    else slips.wrong({ word: t.notSaved, title: firstError(result, t.failed) })
  }

  return (
    <div className="grid gap-6 px-[clamp(16px,3vw,40px)] pb-8 lg:grid-cols-[minmax(0,1fr)_320px]">
      <section aria-label={data.subject.name} className="min-w-0 border-2 border-ink bg-sheet">
        <div className="grid gap-3 border-b-2 border-ink p-4 sm:grid-cols-3">
          <Select label={t.area} value={data.subject.id} onChange={(e) => onArea(e.target.value)}>
            {data.areas.map((a) => (
              <option key={a.id} value={a.id}>
                {a.name}
              </option>
            ))}
          </Select>
          <Input label={t.day} type="date" value={data.day} max={today()} onChange={(e) => e.target.value !== '' && onDay(e.target.value)} />
          <Input label={t.focus} value={focus} maxLength={120} placeholder={t.focusPlaceholder} onChange={(e) => setFocus(e.target.value)} />
        </div>
        <div className="label-caps border-b border-rule px-4 py-2 text-text-muted">{t.seenCount(seen, data.children.length)}</div>
        <ul>
          {data.children.map((c) => (
            <li key={c.id} className="border-b border-rule px-4 py-2.5">
              <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                <b className="min-w-[180px] flex-1 text-[15px]">{c.name}</b>
                <div role="radiogroup" aria-label={c.name} className="flex flex-wrap gap-1.5">
                  {ALL_STEPS.map((s) => {
                    const on = steps[c.id] === s

                    return (
                      <button
                        key={s}
                        type="button"
                        role="radio"
                        aria-checked={on}
                        onClick={() => setSteps((was) => ({ ...was, [c.id]: s }))}
                        className={`flex min-h-11 items-center gap-2 px-2.5 text-[13px] font-bold ${on ? 'border-[3px] border-ink' : 'border border-rule'} bg-sheet text-ink`}
                      >
                        <StepMark step={s} wide="w-9" decorative />
                        <span>{t.steps[s]}</span>
                      </button>
                    )
                  })}
                </div>
                <Button variant="secondary" onClick={() => setNoting(noting === c.id ? null : c.id)}>
                  {t.addNote}
                </Button>
              </div>
              {(noting === c.id || (notes[c.id] ?? '') !== '') && (
                <div className="mt-2 max-w-[560px]">
                  <Input label={t.note} value={notes[c.id] ?? ''} maxLength={255} onChange={(e) => setNotes((was) => ({ ...was, [c.id]: e.target.value }))} />
                </div>
              )}
            </li>
          ))}
        </ul>
        <div className="flex flex-wrap items-center gap-3 p-4">
          <Button busy={busy} disabled={!data.can_observe} onClick={() => void save()}>
            {t.save}
          </Button>
        </div>
      </section>
      <aside className="grid content-start gap-4">
        <section className="border-2 border-ink bg-sheet p-4">
          <h2 className="type-display mb-2 text-[26px] leading-none">{t.stepsTitle}</h2>
          <StepLegend />
          <p className="mt-3 text-[13px] text-text-muted">{t.stepsHelp.not_seen}</p>
        </section>
      </aside>
    </div>
  )
}
