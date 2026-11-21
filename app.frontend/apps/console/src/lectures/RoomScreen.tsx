import { useEffect, useState } from 'react'
import { renderSVG } from 'uqr'
import { copy } from '../copy'
import { lecturesApi, type Screen } from './api'

const t = copy.lectures
const s = t.screen

/**
 * The room screen (D-102, the approved board): the lecture, the changing code
 * and how many have signed in, full screen on the room's projector. It asks
 * for the code every two seconds, so the code on the screen is never old.
 */
export function RoomScreen({ division, session }: { division: string; session: string }) {
  const [data, setData] = useState<Screen | null>(null)
  const [now, setNow] = useState(() => Date.now())

  useEffect(() => {
    let live = true
    const load = () =>
      void lecturesApi.code(division, session).then((answer) => {
        if (live && answer !== null && answer !== undefined && typeof answer === 'object' && 'code' in answer) setData(answer)
      })
    load()
    const every = window.setInterval(() => (load(), setNow(Date.now())), 2000)

    return () => {
      live = false
      window.clearInterval(every)
    }
  }, [division, session])

  useEffect(() => {
    document.title = s.kicker
  }, [])

  const left = data === null ? 0 : Math.max(0, Math.floor((new Date(data.scan_until).getTime() - now) / 1000))
  const x = data?.lecture

  return (
    <div className="grid min-h-screen grid-cols-1 items-center gap-12 bg-ink px-16 py-14 text-sheet lg:grid-cols-[1fr_520px]">
      <div className="grid gap-5">
        <span className="label-caps text-teal-bright">{s.kicker}</span>
        {x !== undefined && (
          <>
            <div className="type-display text-[clamp(56px,7vw,96px)] leading-[0.86] uppercase">
              {x.code}
              <br />
              {x.title}
            </div>
            <div className="text-[24px] text-on-ink-soft">{`${t.span(x.starts, x.ends)} · ${x.room} · ${x.cover ?? x.lecturer}`}</div>
          </>
        )}
        {data !== null && (
          <div className="mt-4 flex flex-wrap gap-10">
            <div>
              <span className="label-caps text-on-ink-muted">{s.scanned}</span>
              <div className="type-display text-[72px] leading-none text-teal-bright">{data.scanned}</div>
            </div>
            <div>
              <span className="label-caps text-on-ink-muted">{s.of}</span>
              <div className="type-display text-[72px] leading-none">{data.of}</div>
            </div>
            {data.open && (
              <div>
                <span className="label-caps text-on-ink-muted">{s.closesIn}</span>
                <div className="type-display text-[72px] leading-none">{`${Math.floor(left / 60)}:${String(left % 60).padStart(2, '0')}`}</div>
              </div>
            )}
          </div>
        )}
        <div className="text-[18px] text-on-ink-soft">{s.how}</div>
      </div>
      <div className="grid justify-items-center gap-3.5">
        {data !== null && data.open && data.code !== '' ? (
          <>
            <div role="img" aria-label={s.code} className="w-full max-w-[468px] bg-sheet p-6 [&>svg]:h-auto [&>svg]:w-full" dangerouslySetInnerHTML={{ __html: renderSVG(data.code, { border: 1 }) }} />
            <div className="h-2 w-full max-w-[468px] bg-ink-rule">
              <div className="h-2 bg-teal-bright transition-[width] duration-1000" style={{ width: `${((10 - data.changes_in) / 10) * 100}%` }} />
            </div>
            <div className="text-[16px] text-on-ink-soft">{s.every}</div>
          </>
        ) : (
          data !== null && <div className="type-display text-[40px]">{s.closed}</div>
        )}
      </div>
    </div>
  )
}
