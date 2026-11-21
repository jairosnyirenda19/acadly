import { useLoad } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { firstError } from '../programmes/api'
import { Attention, DivisionChoice, Sheet, td, th } from '../programmes/parts'
import { PageHead, SmallButton, Tag } from '../settings/parts'
import { Input, Select } from '../setup/parts'
import { lecturesApi, useLectureDivision, type Rooms } from './api'

const t = copy.lectures
const r = t.rooms

type Room = Rooms['rooms'][number]
type Area = { name: string; latitude: string; longitude: string; reach: string }
const blank = { id: null as string | null, code: '', name: '', seats: '', kind: 'room', retired: false }

/** Rooms and the campus area (D-102, the approved board). */
export function RoomsScreen() {
  const { division, choices, choose } = useLectureDivision(['lectures.view', 'lectures.timetable'])
  if (division === null) {
    return (
      <div className="px-[clamp(16px,3vw,40px)] pt-7">
        <Attention>
          <b>{t.chooseTitle}.</b> {t.choose}
        </Attention>
      </div>
    )
  }

  return <Loaded key={division.id} division={division.id} name={division.name} choice={<DivisionChoice choices={choices} division={division} choose={choose} />} />
}

function Loaded({ division, name, choice }: { division: string; name: string; choice: React.ReactNode }) {
  const loaded = useLoad(`lecture-rooms:${division}`, () => lecturesApi.rooms(division))
  const [room, setRoom] = useState<typeof blank | null>(null)
  const [areas, setAreas] = useState<Area[] | null>(null)
  const [minutes, setMinutes] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)

  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={r.title} labels={copy.loading} rows={6} onRetry={loaded.reload} />
  const data = loaded.data
  const shown: Area[] = areas ?? data.areas.map((a) => ({ name: a.name, latitude: String(a.latitude), longitude: String(a.longitude), reach: String(a.reach) }))
  const mins = minutes ?? String(data.scan_minutes)

  const saveRoom = async () => {
    if (room === null) return
    setBusy(true)
    setProblem(null)
    const result = await lecturesApi.saveRoom(division, { ...room, seats: Number(room.seats) })
    setBusy(false)
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: r.roomSaved })
      setRoom(null)
      loaded.reload()
    } else setProblem(firstError(result, t.failed))
  }
  const saveAreas = async () => {
    setBusy(true)
    setProblem(null)
    const result = await lecturesApi.saveAreas(division, {
      areas: shown.map((a) => ({ name: a.name, latitude: Number(a.latitude), longitude: Number(a.longitude), reach: Number(a.reach) })),
      scan_minutes: Number(mins),
    })
    setBusy(false)
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: r.areaSaved })
      setAreas(null)
      setMinutes(null)
      loaded.reload()
    } else setProblem(firstError(result, t.failed))
  }
  const setArea = (i: number, patch: Partial<Area>) => setAreas(shown.map((a, j) => (j === i ? { ...a, ...patch } : a)))
  const here = (i: number) =>
    navigator.geolocation.getCurrentPosition(
      (pos) => setArea(i, { latitude: pos.coords.latitude.toFixed(6), longitude: pos.coords.longitude.toFixed(6) }),
      () => slips.wrong({ word: t.notSaved, title: r.noHere }),
      { enableHighAccuracy: true, timeout: 10000 },
    )

  return (
    <>
      <PageHead kicker={name} title={r.title} intro={r.intro} actions={choice} />
      <div className="grid items-start gap-4 px-[clamp(16px,3vw,40px)] pb-8 xl:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)]">
        {problem !== null && (
          <div className="xl:col-span-2">
            <Notice tone="wrong" title={t.notSaved}>
              {problem}
            </Notice>
          </div>
        )}
        <Sheet label={r.list}>
          <h2 className="label-caps border-b border-rule px-3.5 py-2.5 text-text-muted">{r.list}</h2>
          {data.rooms.length === 0 ? (
            <p className="px-3.5 py-3 text-text-muted">{r.none}</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[520px] border-collapse">
                <thead>
                  <tr>
                    <th className={th}>{r.code}</th>
                    <th className={th}>{r.name}</th>
                    <th className={`${th} text-right`}>{r.seats}</th>
                    <th className={th}>{r.kind}</th>
                    <th className={th} />
                  </tr>
                </thead>
                <tbody>
                  {data.rooms.map((x: Room) => (
                    <tr key={x.id} className={x.retired ? 'text-text-muted' : ''}>
                      <td className={`${td} font-mono font-extrabold whitespace-nowrap`}>{x.code}</td>
                      <td className={td}>{x.name}</td>
                      <td className={`${td} text-right`}>{x.seats}</td>
                      <td className={td}>{x.retired ? <Tag tone="plain">{r.retired}</Tag> : (r.kinds[x.kind] ?? x.kind)}</td>
                      <td className={`${td} text-right`}>
                        {data.can_change && (
                          <SmallButton onClick={() => setRoom({ id: x.id, code: x.code, name: x.name, seats: String(x.seats), kind: x.kind, retired: x.retired })}>{r.change}</SmallButton>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {data.can_change && (
            <div className="grid gap-3 border-t border-rule px-3.5 py-3">
              {room === null ? (
                <div>
                  <Button onClick={() => setRoom({ ...blank })}>{r.add}</Button>
                </div>
              ) : (
                <>
                  <div className="grid gap-3 sm:grid-cols-[120px_1fr_100px_1fr]">
                    <Input label={r.code} value={room.code} onChange={(e) => setRoom({ ...room, code: e.target.value })} />
                    <Input label={r.name} value={room.name} onChange={(e) => setRoom({ ...room, name: e.target.value })} />
                    <Input label={r.seats} inputMode="numeric" value={room.seats} onChange={(e) => setRoom({ ...room, seats: e.target.value.replace(/\D/g, '') })} />
                    <Select label={r.kind} value={room.kind} onChange={(e) => setRoom({ ...room, kind: e.target.value })}>
                      {Object.entries(r.kinds).map(([k, label]) => (
                        <option key={k} value={k}>
                          {label}
                        </option>
                      ))}
                    </Select>
                  </div>
                  {room.id !== null && (
                    <label className="flex min-h-11 items-center gap-2.5">
                      <input type="checkbox" className="size-5 accent-ink" checked={room.retired} onChange={(e) => setRoom({ ...room, retired: e.target.checked })} />
                      {r.retire}
                    </label>
                  )}
                  <div className="flex gap-2.5">
                    <Button busy={busy} onClick={() => void saveRoom()}>
                      {r.save}
                    </Button>
                    <Button variant="secondary" onClick={() => setRoom(null)}>
                      {r.cancel}
                    </Button>
                  </div>
                </>
              )}
            </div>
          )}
        </Sheet>
        <Sheet label={r.area}>
          <h2 className="label-caps border-b border-rule px-3.5 py-2.5 text-text-muted">{r.area}</h2>
          <div className="grid gap-3 px-3.5 py-3">
            <p className="text-[14px] text-text-muted">{r.areaIntro}</p>
            {shown.length === 0 && <p className="text-[14px]">{r.noArea}</p>}
            {shown.map((a, i) => (
              <fieldset key={i} className="grid gap-2.5 border border-rule px-3 py-2.5" disabled={!data.can_change}>
                <div className="grid gap-2.5 sm:grid-cols-2">
                  <Input label={r.areaName} value={a.name} onChange={(e) => setArea(i, { name: e.target.value })} />
                  <Input label={r.reach} inputMode="numeric" value={a.reach} onChange={(e) => setArea(i, { reach: e.target.value.replace(/\D/g, '') })} />
                  <Input label={r.latitude} inputMode="decimal" value={a.latitude} onChange={(e) => setArea(i, { latitude: e.target.value })} />
                  <Input label={r.longitude} inputMode="decimal" value={a.longitude} onChange={(e) => setArea(i, { longitude: e.target.value })} />
                </div>
                {data.can_change && (
                  <span className="flex flex-wrap gap-2">
                    <SmallButton onClick={() => here(i)}>{r.here}</SmallButton>
                    <SmallButton tone="red" onClick={() => setAreas(shown.filter((_, j) => j !== i))}>
                      {r.removeArea}
                    </SmallButton>
                  </span>
                )}
              </fieldset>
            ))}
            <Input label={r.minutes} hint={r.minutesHint} inputMode="numeric" value={mins} disabled={!data.can_change} onChange={(e) => setMinutes(e.target.value.replace(/\D/g, ''))} />
            {data.can_change && (
              <div className="flex flex-wrap gap-2.5">
                <Button busy={busy} onClick={() => void saveAreas()}>
                  {r.saveArea}
                </Button>
                <Button variant="secondary" onClick={() => setAreas([...shown, { name: '', latitude: '', longitude: '', reach: '250' }])}>
                  {r.addArea}
                </Button>
              </div>
            )}
          </div>
        </Sheet>
      </div>
    </>
  )
}
