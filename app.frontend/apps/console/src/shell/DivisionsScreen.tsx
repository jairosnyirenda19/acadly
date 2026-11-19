import { api, navigate, useLoad, useLocation, type paths } from '@acadly/kernel'
import { Waiting } from '@acadly/ui'
import { useEffect, useState } from 'react'
import { copy } from '../copy'
import { day } from '../settings/api'
import { read } from '../setup/api'
import { pictureOf } from './divisions'
import { DivisionLetter, useWorkIn } from './WorkIn'

type Overview = paths['/v1/divisions/overview']['get']['responses'][200]['content']['application/json']
type DivisionRow = Overview['divisions'][number]

const t = copy.divisions
const LANDED = 'acadly.landed'

/**
 * Where a person who works in more than one division lands after signing in
 * (D-096): the Divisions start screen. Once a visit; afterwards the person
 * goes where they choose.
 */
export function useLanding(): void {
  const { divisions, enter } = useWorkIn()
  const { path } = useLocation()

  useEffect(() => {
    const landed = (() => {
      try {
        const before = window.sessionStorage.getItem(LANDED) !== null
        window.sessionStorage.setItem(LANDED, '1')

        return before
      } catch {
        return true
      }
    })()
    if (!landed && divisions.length > 1 && path === '/') {
      enter(null)
      navigate('/divisions', true)
    }
    // Once, when the console opens.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])
}

/** Whether the person asked the page to keep still. */
function useStill(): boolean {
  const [still, setStill] = useState(() => typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches)

  useEffect(() => {
    if (typeof window.matchMedia !== 'function') return
    const media = window.matchMedia('(prefers-reduced-motion: reduce)')
    const set = () => setStill(media.matches)
    media.addEventListener('change', set)

    return () => media.removeEventListener('change', set)
  }, [])

  return still
}

function Chip({ tone, text }: { tone: 'done' | 'attention' | 'wrong'; text: string }) {
  const look = { done: 'bg-teal text-sheet', attention: 'bg-marigold text-ink', wrong: 'bg-red-pen text-sheet' }[tone]
  const mark = { done: '✓', attention: '!', wrong: '✕' }[tone]

  return <span className={`label-caps px-[7px] py-[3px] whitespace-nowrap ${look}`}>{`${mark} ${text}`}</span>
}

const percent = (present: number | null, marked: number | null): number | null => (present !== null && marked !== null && marked > 0 ? Math.round((present / marked) * 100) : null)

/** One division's live tile: its picture, turning over to today's figures every few seconds. */
function DivisionTile({ d, turn, onEnter }: { d: DivisionRow; turn: boolean; onEnter: () => void }) {
  const picture = pictureOf(d.profile)
  const pct = percent(d.present, d.marked)
  const line = [d.learners !== null ? `${d.learners} ${d.word.toLowerCase()}` : null, pct !== null ? t.present(pct) : null].filter((x) => x !== null).join(' · ')

  return (
    <a
      href="/"
      onClick={(e) => (e.preventDefault(), onEnter())}
      aria-label={t.enterLabel(d.name, line)}
      className="group relative flex min-h-[272px] flex-col overflow-hidden bg-ink text-sheet no-underline outline-offset-2"
    >
      <span aria-hidden="true" className="relative min-h-0 flex-1 overflow-hidden">
        {picture !== null && <img src={picture} alt="" className={`absolute inset-0 size-full object-cover transition-opacity duration-700 ${turn ? 'opacity-0' : 'opacity-100'}`} />}
        <span className={`absolute inset-0 grid place-content-center gap-1 bg-ink px-4 text-center transition-opacity duration-700 ${turn || picture === null ? 'opacity-100' : 'opacity-0'}`}>
          <span className="type-numeral text-[56px] text-accent">{pct !== null ? `${pct}%` : (d.learners ?? '–')}</span>
          <span className="label-caps text-on-ink-muted">{pct !== null ? t.presentToday : d.word}</span>
        </span>
        <span className="absolute top-3 left-3">
          <DivisionLetter name={d.name} size="size-[34px] text-xl" />
        </span>
      </span>
      <span className="grid gap-1 px-3.5 pt-3 pb-3.5">
        <span className="flex items-baseline gap-2.5">
          <span className="type-display flex-1 text-[34px] leading-[0.9]">{d.name}</span>
          <span className="label-caps text-accent group-hover:underline">{t.enter}</span>
        </span>
        {line !== '' && <span className="text-sm text-on-ink-soft">{line}</span>}
        {d.states.length > 0 && (
          <span className="flex flex-wrap gap-1.5">
            {d.states.map((s, i) => (
              <Chip key={i} tone={s.tone} text={s.text} />
            ))}
          </span>
        )}
      </span>
    </a>
  )
}

function SmallTile({ label, value, note, tone = 'plain', href }: { label: string; value: string; note: string; tone?: 'ink' | 'attention' | 'plain'; href?: string }) {
  const look = tone === 'ink' ? 'bg-ink text-sheet' : tone === 'attention' ? 'border-2 border-ink bg-marigold-wash text-ink' : 'border-2 border-ink bg-sheet text-ink'
  const body = (
    <>
      <span className={`label-caps ${tone === 'ink' ? 'text-on-ink-muted' : 'text-text-muted'}`}>{label}</span>
      <span className={`type-numeral mt-auto text-[40px] ${tone === 'ink' ? 'text-accent' : ''}`}>{value}</span>
      <span className={`text-[13px] ${tone === 'ink' ? 'text-on-ink-soft' : 'text-text-muted'}`}>{note}</span>
    </>
  )
  const cls = `flex min-h-[132px] flex-col px-3.5 pt-3 pb-3 no-underline ${look}`

  return href !== undefined ? (
    <a href={href} onClick={(e) => (e.preventDefault(), navigate(href))} className={`${cls} hover:outline-2 hover:outline-ink`}>
      {body}
    </a>
  ) : (
    <section className={cls}>{body}</section>
  )
}

/** The Divisions start screen (the approved design, D-096): one live tile per division, then what the school shares. */
export function DivisionsScreen() {
  const loaded = useLoad('divisions:overview', () => read(api.GET('/v1/divisions/overview')))
  const { enter } = useWorkIn()
  const still = useStill()
  const [turn, setTurn] = useState(-1)
  const data = loaded.data

  // The tiles turn over one at a time, every few seconds; with reduced motion they keep still.
  useEffect(() => {
    if (still || data === undefined || data.divisions.length === 0) return
    const n = data.divisions.length
    let i = 0
    const timer = window.setInterval(() => {
      setTurn(i % (n + 1) === n ? -1 : i % (n + 1))
      i++
    }, 4000)

    return () => window.clearInterval(timer)
  }, [still, data])

  if (data === undefined) return <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} rows={6} onRetry={loaded.reload} />

  const s = data.school
  const pct = s.present !== null ? percent(s.present.present, s.present.marked) : null

  return (
    <div className="grid gap-4 px-[clamp(16px,3vw,40px)] pt-6 pb-8">
      <div>
        <div className="label-caps text-text-muted">{t.kicker(day(data.day), s.term?.name ?? '')}</div>
        <h1 className="type-display mt-1 text-[clamp(48px,6vw,72px)] leading-[0.86]">{t.title}</h1>
        <p className="mt-2 max-w-[760px] text-[15px]">{t.intro}</p>
      </div>

      <h2 className="label-caps text-text-muted">{t.divisions}</h2>
      <div className="grid grid-cols-[repeat(auto-fit,minmax(240px,1fr))] gap-2">
        {data.divisions.map((d, i) => (
          <DivisionTile
            key={d.id}
            d={d}
            turn={turn === i}
            onEnter={() => {
              enter(d.id)
              navigate('/')
            }}
          />
        ))}
      </div>

      <h2 className="label-caps mt-2 text-text-muted">{t.shared}</h2>
      <div className="grid grid-cols-[repeat(auto-fit,minmax(180px,1fr))] gap-2">
        {s.present !== null && <SmallTile tone="ink" label={t.presentToday} value={pct !== null ? `${pct}%` : '–'} note={t.presentNote(s.present.present, s.present.marked)} />}
        {s.approvals !== null && <SmallTile tone={s.approvals > 0 ? 'attention' : 'plain'} label={t.toApprove} value={String(s.approvals)} note={s.approvals > 0 ? t.waiting : t.nothingWaiting} href="/approvals" />}
        {s.messages !== null && <SmallTile label={t.messages} value={String(s.messages.sent)} note={s.messages.attention > 0 ? t.messagesAttention(s.messages.attention) : t.messagesFine} href="/settings/messages/sent" />}
        {s.term !== null && <SmallTile label={t.termEnds(s.term.name)} value={day(s.term.ends_on)} note={t.daysLeft(s.term.days_left)} />}
        {s.staff !== null && <SmallTile label={t.staff} value={String(s.staff)} note={t.staffNote} href="/settings/people" />}
      </div>
      <p className="text-[13px] text-text-muted">{t.note}</p>
    </div>
  )
}
