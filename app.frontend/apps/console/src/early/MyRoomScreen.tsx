import { moduleOn, navigate, useAppContext, useLoad, useLocation } from '@acadly/kernel'
import { Button, Waiting } from '@acadly/ui'
import { copy } from '../copy'
import { day } from '../settings/api'
import { PageHead } from '../settings/parts'
import { Select } from '../setup/parts'
import { earlyApi, type RoomToday } from './api'

const t = copy.early

/** The room picker shared by the nursery pages: shown only when there is more than one room. */
export function RoomPicker({ rooms, value, onChange }: { rooms: { id: string; name: string }[]; value: string; onChange: (id: string) => void }) {
  if (rooms.length < 2) return null

  return (
    <div className="max-w-[320px] px-[clamp(16px,3vw,40px)] pb-4">
      <Select label={t.room} value={value} onChange={(e) => onChange(e.target.value)}>
        {rooms.map((r) => (
          <option key={r.id} value={r.id}>
            {r.name}
          </option>
        ))}
      </Select>
    </div>
  )
}

/** A figure in the band at the top of a page. */
export function Tile({ label, value, note, tone = 'plain' }: { label: string; value: string | number; note: string; tone?: 'ink' | 'attention' | 'plain' }) {
  return (
    <section className={`min-w-0 border-2 border-ink px-3.5 pt-2.5 pb-3 ${tone === 'ink' ? 'bg-ink text-sheet' : tone === 'attention' ? 'bg-marigold-wash' : 'bg-sheet'}`}>
      <h3 className={`label-caps ${tone === 'ink' ? 'text-on-ink-muted' : 'text-text-muted'}`}>{label}</h3>
      <div className={`type-numeral text-[clamp(30px,3vw,44px)] ${tone === 'ink' ? 'text-teal-bright' : ''}`}>{value}</div>
      <p className={`text-[13px] ${tone === 'ink' ? 'text-on-ink-soft' : 'text-text-muted'}`}>{note}</p>
    </section>
  )
}

/** My room (the approved design): today in one room, what was observed this week, and the reviews of the term. */
export function MyRoomScreen() {
  const { query } = useLocation()
  const rooms = useLoad('early:rooms', () => earlyApi.rooms())
  const list = rooms.data?.rooms ?? []
  const chosen = query.get('room') ?? list[0]?.id ?? ''

  if (rooms.data === undefined) return <Waiting phase={rooms.phase} what={copy.shell.nav.room} labels={copy.loading} rows={6} onRetry={rooms.reload} />

  if (list.length === 0) {
    return (
      <>
        <PageHead kicker={copy.shell.nav.room} title={copy.shell.nav.room} intro={t.noRooms} />
      </>
    )
  }

  return <Room key={chosen} id={chosen} rooms={list} />
}

function Room({ id, rooms }: { id: string; rooms: { id: string; name: string }[] }) {
  const context = useAppContext()
  const loaded = useLoad(`early:room:${id}`, () => earlyApi.room(id))
  const pickup = moduleOn(context, 'saferelease')
  const data = loaded.data

  if (data === undefined) return <Waiting phase={loaded.phase} what={copy.shell.nav.room} labels={copy.loading} rows={6} onRetry={loaded.reload} />

  const notSeen = data.children.filter((c) => !c.seen_this_week)

  return (
    <>
      <PageHead
        kicker={t.roomKicker(day(data.day), data.period?.name ?? '')}
        title={data.room.name}
        intro={t.roomLine(data.children.length, data.room.level)}
        actions={data.can.observe ? <Button onClick={() => navigate(`/observations?room=${id}`)}>{t.newObservation}</Button> : undefined}
      />
      <RoomPicker rooms={rooms} value={id} onChange={(r) => navigate(`/room?room=${r}`)} />
      <div className="grid gap-5 px-[clamp(16px,3vw,40px)] pb-8">
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          <Tile label={t.children} value={data.children.length} note={t.childrenNote} tone="ink" />
          {pickup ? (
            <DeskTile id={id} />
          ) : (
            <Tile label={t.reviewsTile} value={data.reviews.checked} note={t.totalCheckedNote} />
          )}
          <Tile label={t.observedWeek} value={data.children.length - notSeen.length} note={t.observedWeekNote(notSeen.length)} tone={notSeen.length > 0 ? 'attention' : 'plain'} />
          <Tile label={t.reviewsTile} value={data.reviews.started} note={t.reviewsTileNote(data.reviews.started, data.reviews.children)} tone={data.reviews.started < data.reviews.children ? 'attention' : 'plain'} />
        </div>

        <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_420px]">
          <Areas data={data} />
          <section aria-label={t.notSeenWeek} className="border-2 border-ink bg-sheet px-4.5 pt-3 pb-3.5">
            <h2 className="type-display mb-2 text-[26px] leading-none">{t.notSeenWeek}</h2>
            {notSeen.length === 0 && <p className="py-2 text-sm text-text-muted">{t.everyoneSeen}</p>}
            <ul>
              {notSeen.map((c) => (
                <li key={c.id} className="flex flex-wrap justify-between gap-2 border-b border-rule py-2 text-[15px]">
                  <a href={`/learners/${c.id}?tab=assessment`} onClick={(e) => (e.preventDefault(), navigate(`/learners/${c.id}?tab=assessment`))} className="font-bold text-ink no-underline hover:underline">
                    {c.name}
                  </a>
                  <span className="text-[13px] text-text-muted">{c.last_seen === null ? t.neverSeen : t.lastSeen(day(c.last_seen))}</span>
                </li>
              ))}
            </ul>
            <p className="mt-2 text-[13px] text-text-muted">{t.notSeenNote}</p>
          </section>
        </div>
      </div>
    </>
  )
}

/** How many children were collected today, where the school uses pick-up. */
function DeskTile({ id }: { id: string }) {
  const desk = useLoad(`early:room-desk:${id}`, () => earlyApi.desk(id))
  const totals = desk.data?.totals

  return <Tile label={t.pickedUp} value={totals?.collected ?? '–'} note={totals === undefined ? '' : t.pickedUpNote(totals.here)} />
}

function Areas({ data }: { data: RoomToday }) {
  const of = Math.max(1, data.children.length)

  return (
    <section aria-label={t.byArea} className="border-2 border-ink bg-sheet px-4.5 pt-3 pb-3.5">
      <h2 className="type-display mb-2 text-[26px] leading-none">{t.byArea}</h2>
      <ul>
        {data.areas.map((a) => (
          <li key={a.id} className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 border-b border-rule py-2.5 sm:grid-cols-[minmax(0,1fr)_130px_200px]">
            <b className="text-[15px]">{a.name}</b>
            <span className="text-right font-mono text-sm">{t.areaLine(a.observations)}</span>
            <span className="relative col-span-full h-2.5 bg-field sm:col-span-1" role="img" aria-label={`${a.seen} / ${data.children.length}`}>
              <span className="absolute inset-y-0 left-0 bg-teal" style={{ width: `${Math.round((a.seen / of) * 100)}%` }} />
            </span>
          </li>
        ))}
      </ul>
      <p className="mt-2 text-[13px] text-text-muted">{t.byAreaNote}</p>
    </section>
  )
}
