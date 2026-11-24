import { api, navigate, useAppContext, useLocation } from '@acadly/kernel'
import { Button, Notice } from '@acadly/ui'
import { useEffect, useState } from 'react'
import { copy } from '../copy'
import { read } from '../setup/api'
import { screens, searchFor, toFound, type Found } from './search'

const t = copy.search
const PAGE = 10

interface Place {
  key: string
  label: string
}

/** The initials of a name, for the card's badge. */
const initials = (name: string) =>
  name
    .split(/\s+/)
    .map((w) => w[0] ?? '')
    .join('')
    .slice(0, 2)
    .toUpperCase()

/** The part of a title the words matched, marked: bold with a marigold underline (a mark, not colour alone). */
function Marked({ title, text }: { title: string; text: string }) {
  const words = text
    .toLowerCase()
    .split(/\s+/)
    .filter((w) => w !== '')

  return (
    <>
      {title.split(/(\s+)/).map((part, i) => {
        const word = words.find((w) => part.toLowerCase().replace(/['’‘`]/g, '').startsWith(w))

        return word !== undefined ? (
          <span key={i}>
            <span className="underline decoration-marigold decoration-[3px] underline-offset-[3px]">{part.slice(0, word.length)}</span>
            {part.slice(word.length)}
          </span>
        ) : (
          <span key={i}>{part}</span>
        )
      })}
    </>
  )
}

/**
 * One group of search results (the approved design, D-089): the places on
 * the left, the results as cards in the middle with the match marked, and
 * a preview of the chosen result beside them.
 */
export function SearchScreen() {
  const { query } = useLocation()
  const context = useAppContext()
  const text = (query.get('q') ?? '').trim()
  const [places, setPlaces] = useState<Place[] | null>(null)
  const chosen = query.get('in') ?? places?.[0]?.key ?? 'learners'
  const [hits, setHits] = useState<Found[]>([])
  const [more, setMore] = useState(false)
  const [state, setState] = useState<'loading' | 'ready' | 'failed'>('loading')
  const [picked, setPicked] = useState(0)

  useEffect(() => {
    void read(api.GET('/v1/search/places')).then((p) => setPlaces(p !== null && p !== 'refused' && p !== 'missing' ? p.places : []))
  }, [])

  useEffect(() => {
    let live = true
    setState('loading')
    setPicked(0)

    if (text.length < 2) {
      setHits([])
      setState('ready')

      return
    }

    if (chosen === 'screens') {
      setHits(screens(context, text))
      setMore(false)
      setState('ready')

      return
    }

    void searchFor(text, { in: chosen, limit: PAGE }).then((found) => {
      if (!live) return
      if (found === null) return setState('failed')
      const group = found.groups[0]
      setHits(group?.hits.map(toFound) ?? [])
      setMore(group?.more ?? false)
      setState('ready')
    })

    return () => {
      live = false
    }
  }, [text, chosen, context])

  const showMore = () =>
    void searchFor(text, { in: chosen, limit: PAGE, offset: hits.length }).then((found) => {
      const group = found?.groups[0]
      if (group === undefined) return
      setHits([...hits, ...group.hits.map(toFound)])
      setMore(group.more)
    })

  const go = (key: string) => navigate(`/search?q=${encodeURIComponent(text)}&in=${key}`)
  const all: Place[] = [{ key: 'screens', label: t.screens }, ...(places ?? [])]
  const current = hits[picked]

  return (
    <div className="flex flex-1 flex-col bg-paper">
      <div className="border-b-2 border-ink px-[clamp(16px,3vw,32px)] pt-6 pb-4">
        <div className="label-caps text-text-muted">{t.kicker}</div>
        <h1 className="type-display mt-1 text-[clamp(40px,6vw,64px)] leading-[0.86]">“{text}”</h1>
        <p className="mt-1.5 text-[15px] text-text-muted">{t.pageIntro}</p>
      </div>
      <div className="grid flex-1 grid-cols-1 md:grid-cols-[220px_minmax(0,1fr)] xl:grid-cols-[220px_minmax(0,1fr)_360px]">
        <nav aria-label={t.places} className="border-ink bg-paper py-3 max-md:border-b-2 md:border-r-2">
          <div className="label-caps px-4 pb-1.5 text-text-muted">{t.foundIn}</div>
          <div className="flex flex-wrap md:block">
            {all.map((p) => (
              <a
                key={p.key}
                href={`/search?q=${encodeURIComponent(text)}&in=${p.key}`}
                aria-current={p.key === chosen ? 'page' : undefined}
                onClick={(e) => {
                  e.preventDefault()
                  go(p.key)
                }}
                className={`flex border-l-4 px-3.5 py-2.5 text-[15px] text-ink no-underline ${p.key === chosen ? 'border-teal bg-sheet font-extrabold' : 'border-transparent font-semibold'}`}
              >
                {p.label}
              </a>
            ))}
          </div>
          <p className="mt-4 hidden px-4 text-xs leading-snug text-text-muted md:block">{t.never}</p>
        </nav>
        <section className="min-w-0 px-[clamp(12px,2vw,20px)] pt-4 pb-6">
          {state === 'failed' && <Notice tone="wrong" title={t.unavailable} />}
          {state === 'ready' && hits.length === 0 && <p className="py-4 text-[15px]">{t.nothing(text)}</p>}
          {hits.map((h, i) => (
            <a
              key={h.key}
              href={h.path}
              onMouseEnter={() => setPicked(i)}
              onFocus={() => setPicked(i)}
              onClick={(e) => {
                e.preventDefault()
                navigate(h.path)
              }}
              className={`mb-2.5 flex items-center gap-3.5 border-2 bg-sheet px-4 py-3.5 text-ink no-underline ${i === picked ? 'border-ink shadow-[inset_6px_0_0_var(--color-ink)]' : 'border-rule'}`}
            >
              <span aria-hidden="true" className="type-display grid size-[52px] shrink-0 place-items-center bg-ink text-[22px] text-sheet">
                {initials(h.title)}
              </span>
              <span className="min-w-0 flex-1">
                <span className="block text-xl font-extrabold">
                  <Marked title={h.title} text={text} />
                </span>
                <span className="mt-0.5 block text-sm text-text-muted">
                  {h.reference !== null && <span className="ref mr-1.5 font-bold text-ink">{h.reference}</span>}
                  {h.subtitle}
                </span>
              </span>
              {h.state !== null && <span className="label-caps border-2 border-teal px-[7px] py-[3px] text-teal">{h.state}</span>}
              <span aria-hidden="true" className="text-2xl font-extrabold">
                ›
              </span>
            </a>
          ))}
          {more && (
            <button type="button" onClick={showMore} className="mt-1 h-12 w-full border-2 border-dashed border-rule-dashed text-[15px] font-extrabold text-ink">
              {t.showMore}
            </button>
          )}
        </section>
        <aside aria-label={t.preview} className="hidden border-l-2 border-ink bg-sheet px-5 pt-4 pb-6 xl:block">
          {current !== undefined && <Preview hit={current} />}
        </aside>
      </div>
    </div>
  )
}

/** The chosen result: for a learner, what the learner record shows this reader; opening it checks access again. */
function Preview({ hit }: { hit: Found }) {
  const [record, setRecord] = useState<Record<string, unknown> | null>(null)

  useEffect(() => {
    let live = true
    setRecord(null)
    if (hit.kind !== 'learner') return
    void read(api.GET('/v1/learners/{id}', { params: { path: { id: hit.id } } })).then((r) => {
      if (live && r !== null && r !== 'refused') setRecord(r as unknown as Record<string, unknown>)
    })

    return () => {
      live = false
    }
  }, [hit.kind, hit.id])

  const text = (v: unknown) => (typeof v === 'string' && v !== '' ? v : null)
  const cls = record !== null && typeof record.class === 'object' && record.class !== null ? text((record.class as { name?: unknown }).name) : null
  const contacts = record !== null && Array.isArray(record.contacts) ? (record.contacts as { name?: unknown; relationship_label?: unknown; main?: unknown }[]) : []
  const main = contacts.find((c) => c.main === true)
  const facts: [string, string | null][] = [
    [t.fact.class, cls],
    [t.fact.campus, record !== null ? text(record.campus) : null],
    [t.fact.classTeacher, record !== null ? text(record.class_teacher) : null],
    [t.fact.mainContact, main !== undefined ? `${text(main.name) ?? ''} · ${text(main.relationship_label) ?? ''}` : null],
  ]

  return (
    <>
      <div className="flex items-center gap-3.5">
        <span aria-hidden="true" className="type-display grid size-[72px] shrink-0 place-items-center bg-ink text-[32px] text-sheet">
          {initials(hit.title)}
        </span>
        <span className="min-w-0">
          <span className="type-display block text-[30px] leading-[0.92]">{hit.title}</span>
          {hit.reference !== null && <span className="ref mt-1 block text-[13px] font-bold">{hit.reference}</span>}
        </span>
      </div>
      <div className="mt-3.5">
        {(hit.kind === 'learner' ? facts : [[t.fact.where, hit.subtitle] as [string, string | null]])
          .filter(([, v]) => v !== null)
          .map(([label, value]) => (
            <p key={label} className="flex justify-between gap-3 border-b border-rule py-2 text-sm">
              <span className="text-text-muted">{label}</span>
              <span className="text-right font-extrabold">{value}</span>
            </p>
          ))}
      </div>
      <div className="mt-4 grid gap-2">
        <Button className="h-12" onClick={() => navigate(hit.path)}>
          {hit.kind === 'learner' ? t.openRecord : t.open}
        </Button>
        {hit.kind === 'learner' && (
          <Button variant="secondary" onClick={() => navigate(`${hit.path}?tab=attendance`)}>
            {t.attendance}
          </Button>
        )}
      </div>
      <p className="mt-3.5 text-xs leading-snug text-text-muted">{t.previewNote}</p>
      <p className="mt-3.5 border-t border-rule pt-2.5 text-xs text-text-muted">{t.keys}</p>
    </>
  )
}
