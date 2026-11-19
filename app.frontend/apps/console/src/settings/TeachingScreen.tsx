import { useLoad } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { Total, TotalsBand } from '../setup/parts'
import { academicsApi, type TeachingState } from './academicsApi'
import { PageHead, SettingsTabs } from './parts'

const t = copy.settings.teaching
const s = copy.settings

type Tab = { division_id: string; campus_id: string }
type Cell = { classId: string; className: string; subjectId: string | null; what: string; who: { id: string; name: string } | null }

export function TeachingScreen() {
  const [tab, setTab] = useState<Tab | null>(null)
  const [cell, setCell] = useState<Cell | null>(null)
  const [message, setMessage] = useState<{ tone: 'done' | 'wrong'; text: string } | null>(null)
  const [working, setWorking] = useState(false)

  const loaded = useLoad(`teaching:${tab?.division_id ?? ''}:${tab?.campus_id ?? ''}`, () => academicsApi.teaching(tab))
  const state = loaded.data
  const setState = loaded.set

  if (state === undefined) {
    return (
      <>
        <SettingsTabs current="teaching" />
        <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} band={[t.bandClasses, t.bandGiven, t.bandAttention, t.bandTeachers]} rows={6} onRetry={loaded.reload} />
      </>
    )
  }

  const current = state.current
  const place = current?.label ?? ''
  const subjects = state.subjects.filter((x) => state.classes.some((c) => x.levels.includes(c.level_code)))
  const cols = `minmax(130px,1fr) minmax(110px,0.9fr) repeat(${subjects.length}, minmax(84px,0.8fr))`

  const result = (r: { kind: string; data?: TeachingState; errors?: Record<string, string[]> }, done?: string) => {
    setWorking(false)
    if (r.kind === 'saved' && r.data !== undefined) {
      setState(r.data)
      setCell(null)
      setMessage(null)
      if (done !== undefined) slips.done({ word: copy.slips.saved, title: done })
    } else if (r.kind === 'invalid') {
      setMessage({ tone: 'wrong', text: Object.values(r.errors ?? {})[0]?.[0] ?? s.failed })
    } else {
      setMessage({ tone: 'wrong', text: r.kind === 'refused' ? s.refused : s.failed })
    }
  }

  const box = (c: Cell, key: string) => {
    const chosen = cell !== null && cell.classId === c.classId && cell.subjectId === c.subjectId

    return (
      <button
        key={key}
        type="button"
        aria-pressed={chosen}
        aria-label={t.cellLabel(c.className, c.what, c.who?.name ?? null)}
        title={c.who?.name ?? t.notGiven}
        onClick={() => setCell(chosen ? null : c)}
        className={`h-11 w-full truncate px-1.5 text-xs font-extrabold ${
          chosen ? 'border-[3px] border-ink bg-teal-bright' : c.who !== null ? 'border-2 border-ink bg-sheet hover:bg-field' : 'border-2 border-dashed border-rule-dashed bg-marigold-wash hover:bg-marigold'
        }`}
      >
        {c.who !== null ? initialed(c.who.name) : t.notGiven}
      </button>
    )
  }

  return (
    <>
      <SettingsTabs current="teaching" />
      <PageHead
        kicker={`${s.kicker} · ${t.title}`}
        title={t.title}
        intro={t.intro}
        actions={
          state.earlier_year !== null && current !== null ? (
            <Button
              variant="secondary"
              busy={working}
              onClick={() => {
                setWorking(true)
                const before = state.totals.given
                void academicsApi.copy(current).then((r) => result(r, r.kind === 'saved' && r.data !== undefined ? t.copied(r.data.totals.given - before) : undefined))
              }}
            >
              {t.copy(state.earlier_year)}
            </Button>
          ) : undefined
        }
      />

      <TotalsBand>
        <Total tone="ink" label={t.bandClasses} value={String(state.totals.classes)} note={t.bandClassesNote(place, state.year?.name ?? '')} />
        <Total label={t.bandGiven} value={String(state.totals.given)} suffix={` / ${state.totals.places}`} note={t.bandGivenNote} />
        <Total tone={state.totals.not_given > 0 ? 'attention' : 'plain'} label={t.bandAttention} value={String(state.totals.not_given)} note={t.bandAttentionNote(state.totals.not_given)} />
        <Total label={t.bandTeachers} value={String(state.totals.teachers)} note={t.bandTeachersNote} />
      </TotalsBand>

      <div className="flex flex-1 flex-wrap items-stretch">
        <section className="min-w-0 flex-[3_1_680px] bg-sheet px-[clamp(16px,3vw,40px)] pb-8">
          <nav aria-label={copy.settings.give.where} className="mb-2 flex flex-wrap gap-1 border-b-2 border-ink">
            {state.tabs.map((x) => {
              const on = current !== null && x.division_id === current.division_id && x.campus_id === current.campus_id

              return (
                <button
                  key={`${x.division_id}:${x.campus_id}`}
                  type="button"
                  aria-current={on ? 'page' : undefined}
                  onClick={() => {
                    setCell(null)
                    setMessage(null)
                    setTab({ division_id: x.division_id, campus_id: x.campus_id })
                  }}
                  className={`border-b-4 px-4 pt-3 pb-2.5 text-[15px] ${on ? 'border-teal font-extrabold' : 'border-transparent font-medium'}`}
                >
                  {x.label} <span className="ref text-xs text-text-muted">{t.tabNote(x.not_given)}</span>
                </button>
              )
            })}
          </nav>

          {message !== null && (
            <div className="my-3">
              <Notice tone={message.tone} title={message.text} />
            </div>
          )}

          {state.year === null ? (
            <p className="py-4 text-sm text-text-muted">{t.noYear}</p>
          ) : state.classes.length === 0 ? (
            <p className="py-4 text-sm text-text-muted">{t.noClasses}</p>
          ) : (
            <>
              {subjects.length === 0 && <p className="py-3 text-sm text-text-muted">{t.noSubjects}</p>}
              <div className="overflow-x-auto">
                <div style={{ minWidth: `${260 + subjects.length * 90}px` }}>
                  <div className="label-caps grid gap-1 border-b-2 border-ink pt-2.5 pb-2 text-text-muted" style={{ gridTemplateColumns: cols }}>
                    <span>{t.klass}</span>
                    <span>{t.classTeacher}</span>
                    {subjects.map((x) => (
                      <span key={x.id} title={x.name} className="text-center">
                        {x.short}
                      </span>
                    ))}
                  </div>
                  {state.classes.map((c) => {
                    const teaching = c.teaching as Record<string, { id: string; name: string } | null>

                    return (
                      <div key={c.id} className="grid items-center gap-1 border-b border-rule py-1.5" style={{ gridTemplateColumns: cols }}>
                        <div className="type-display text-xl leading-none">{c.name}</div>
                        {box({ classId: c.id, className: c.name, subjectId: null, what: t.classTeacher, who: c.class_teacher }, 'ct')}
                        {subjects.map((x) =>
                          x.id in teaching ? (
                            box({ classId: c.id, className: c.name, subjectId: x.id, what: x.name, who: teaching[x.id] ?? null }, x.id)
                          ) : (
                            <span key={x.id} className="text-center text-xs text-text-muted">
                              {t.notTaken}
                            </span>
                          ),
                        )}
                      </div>
                    )
                  })}
                </div>
              </div>
              <div className="mt-3 flex flex-wrap gap-4 text-[13px] text-text-muted">
                <span className="inline-flex items-center gap-1.5">
                  <span aria-hidden="true" className="size-4 border-2 border-ink bg-sheet" />
                  {t.given}
                </span>
                <span className="inline-flex items-center gap-1.5">
                  <span aria-hidden="true" className="size-4 border-2 border-dashed border-rule-dashed bg-marigold-wash" />
                  {t.notGiven}
                </span>
                <span>{t.optionalNote}</span>
              </div>
            </>
          )}
        </section>

        <section className="min-w-0 flex-[2_1_360px] border-l-2 border-ink px-[clamp(16px,3vw,40px)] pt-6 pb-8">
          {cell !== null && (
            <Chooser
              key={`${cell.classId}:${cell.subjectId ?? ''}`}
              cell={cell}
              state={state}
              place={place}
              working={working}
              onGive={(userId) => {
                setWorking(true)
                void academicsApi.give({ class_id: cell.classId, subject_id: cell.subjectId, user_id: userId }).then((r) => result(r))
              }}
              onCancel={() => setCell(null)}
            />
          )}
          <div className={cell !== null ? 'my-6.5 h-0.5 bg-ink' : 'hidden'} />
          <div className="label-caps mb-2 text-text-muted">{t.whoTitle}</div>
          <p className="text-sm leading-normal">{t.whoText(place)}</p>
        </section>
      </div>
    </>
  )
}

/** Mercy Banda → M. Banda */
function initialed(name: string): string {
  const parts = name.split(/\s+/).filter(Boolean)

  return parts.length > 1 ? `${parts[0]?.[0] ?? ''}. ${parts.slice(1).join(' ')}` : name
}

function Chooser({
  cell,
  state,
  place,
  working,
  onGive,
  onCancel,
}: {
  cell: Cell
  state: TeachingState
  place: string
  working: boolean
  onGive: (userId: string | null) => void
  onCancel: () => void
}) {
  const [query, setQuery] = useState('')
  const candidates = state.teachers.filter((p) => p.classes.includes(cell.classId) && p.name.toLowerCase().includes(query.trim().toLowerCase()))
  const [chosen, setChosen] = useState<string>(cell.who?.id ?? candidates[0]?.id ?? '')
  const name = state.teachers.find((p) => p.id === chosen)?.name ?? ''
  const blocked = state.blocked.filter((p) => p.classes.includes(cell.classId) && p.name.toLowerCase().includes(query.trim().toLowerCase()))

  return (
    <div>
      <div className="label-caps text-text-muted">{t.choosing}</div>
      <h2 className="type-display mt-1 mb-1 text-[34px] leading-[0.9]">
        {cell.className} · {cell.what}
      </h2>
      <p className="mb-3.5 text-[13px] text-text-muted">{t.choosingIntro(place, state.year?.name ?? '', cell.who?.name ?? null)}</p>
      <label className="mb-2.5 block">
        <span className="sr-only">{t.find}</span>
        <input
          type="search"
          placeholder={t.find}
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          className="h-11 w-full border-0 border-b-2 border-ink bg-field px-3 text-[15px] text-ink outline-none focus:border-teal"
        />
      </label>
      <div role="radiogroup" aria-label={t.find} className="border-t-2 border-ink">
        {candidates.length === 0 && <p className="py-3 text-sm text-text-muted">{t.noTeacher}</p>}
        {candidates.map((p) => (
          <label key={p.id} className="flex cursor-pointer items-center gap-2.5 border-b border-rule py-2.5">
            <input type="radio" name="teacher" checked={chosen === p.id} onChange={() => setChosen(p.id)} className="size-[18px] accent-teal" />
            <span className="flex-1">
              <span className="block text-[15px] font-extrabold">{p.name}</span>
              <span className="block text-[13px] text-text-muted">{p.teaches.length > 0 ? t.teaches(p.teaches.join(', ')) : t.teachesNothing}</span>
            </span>
            <span className="ref text-xs text-text-muted">{t.classCount(p.classes.length)}</span>
          </label>
        ))}
      </div>
      {blocked.length > 0 && (
        <div className="mt-4">
          <div className="label-caps border-b-2 border-ink pb-1.5 text-text-muted">{t.blockedTitle}</div>
          {blocked.map((p) => (
            <div key={p.id} className="flex items-start gap-2.5 border-b border-rule py-2.5">
              <span aria-hidden="true" className="mt-0.5 flex size-[18px] shrink-0 items-center justify-center border-2 border-dashed border-rule-dashed text-xs font-extrabold text-text-muted">
                –
              </span>
              <span className="flex-1">
                <span className="block text-[15px] font-extrabold text-text-muted">{p.name}</span>
                <span className="block text-[13px] text-text-muted">{t.blockedRoles(p.roles)}</span>
                <span className="block text-[13px]">{p.state === 'invited' ? t.blockedInvited : t.blockedNoTeach(p.roles)}</span>
              </span>
            </div>
          ))}
        </div>
      )}
      <div className="mt-4 flex flex-wrap gap-2.5">
        <Button busy={working} disabled={chosen === '' || chosen === cell.who?.id} onClick={() => onGive(chosen)}>
          {cell.subjectId === null ? t.giveClass(name) : t.give(cell.what, name)}
        </Button>
        {cell.who !== null && (
          <Button variant="secondary" busy={working} onClick={() => onGive(null)}>
            {t.takeBack}
          </Button>
        )}
        <Button variant="secondary" onClick={onCancel}>
          {s.cancel}
        </Button>
      </div>
    </div>
  )
}
