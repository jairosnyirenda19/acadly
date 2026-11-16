import { navigate, useLoad, useLocation } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { minutesBetween } from '../registers/api'
import { errorFor, type Saved } from '../setup/api'
import { Input, Select } from '../setup/parts'
import { academicsApi, type BellPeriod, type BellPlan, type TimetableState } from './academicsApi'
import { PageHead, SettingsTabs, SmallButton, Tag } from './parts'

const t = copy.settings.timetable
const s = copy.settings

type View = 'bells' | 'classes'
type At = { division_id: string; campus_id: string; class_id?: string }

const viewPaths: Record<View, string> = { bells: '/settings/timetable', classes: '/settings/timetable/classes' }

/** The address of a view, keeping the level and campus (and class) chosen. */
function pathOf(view: View, at: At | null): string {
  if (at === null) return viewPaths[view]
  const q = new URLSearchParams({ division_id: at.division_id, campus_id: at.campus_id, ...(at.class_id !== undefined ? { class_id: at.class_id } : {}) })

  return `${viewPaths[view]}?${q.toString()}`
}

/** 07:30 plus 45 minutes is 08:15. */
function addMinutes(hm: string, minutes: number): string {
  const [h, m] = hm.split(':').map(Number)
  const total = Math.min(23 * 60 + 59, Math.max(0, (h ?? 0) * 60 + (m ?? 0) + minutes))

  return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`
}

/** The plan whose rows the week is drawn with: the one that has Monday, or the one with the most days. */
function mainPlan(plans: BellPlan[]): BellPlan | null {
  return plans.find((p) => p.weekdays.includes(1)) ?? [...plans].sort((a, b) => b.weekdays.length - a.weekdays.length)[0] ?? null
}

/** Settings › Timetable (the approved designs): bell times, and the week of each class. */
export function TimetableScreen({ view }: { view: View }) {
  const { query } = useLocation()
  const division = query.get('division_id')
  const campus = query.get('campus_id')
  const klass = query.get('class_id')
  const asked: At | null = division !== null && campus !== null ? { division_id: division, campus_id: campus, ...(klass !== null && view === 'classes' ? { class_id: klass } : {}) } : null
  const loaded = useLoad(`timetable:${division ?? ''}:${campus ?? ''}:${view === 'classes' ? (klass ?? '') : ''}`, () => academicsApi.timetable(asked))
  const state = loaded.data

  if (state === undefined) {
    return (
      <>
        <SettingsTabs current="timetable" />
        <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} rows={8} onRetry={loaded.reload} />
      </>
    )
  }

  const current = state.current
  const here: At | null = current !== null ? { division_id: current.division_id, campus_id: current.campus_id } : null

  return (
    <>
      <SettingsTabs current="timetable" />
      <PageHead
        kicker={[s.kicker, current?.label ?? null, state.year?.name ?? null].filter((x) => x !== null).join(' · ')}
        title={t.title}
        intro={view === 'bells' ? t.bellsIntro : t.classesIntro}
      />
      <nav aria-label={t.tabsLabel} className="flex gap-1 overflow-x-auto border-b-2 border-ink bg-sheet px-3 [scrollbar-width:none] md:px-[clamp(16px,3vw,40px)]">
        {(Object.keys(viewPaths) as View[]).map((v) => (
          <a
            key={v}
            href={pathOf(v, here)}
            aria-current={v === view ? 'page' : undefined}
            onClick={(e) => {
              e.preventDefault()
              navigate(pathOf(v, here))
            }}
            className={`shrink-0 border-b-4 px-4 pt-2.5 pb-2 text-sm whitespace-nowrap text-ink no-underline ${v === view ? 'border-teal font-extrabold' : 'border-transparent font-medium'}`}
          >
            {t.tabs[v]}
          </a>
        ))}
      </nav>
      {state.tabs.length > 1 && (
        <nav aria-label={t.where} className="flex gap-1 overflow-x-auto border-b border-rule bg-sheet px-3 [scrollbar-width:none] md:px-[clamp(16px,3vw,40px)]">
          {state.tabs.map((x) => {
            const on = current !== null && x.division_id === current.division_id && x.campus_id === current.campus_id

            return (
              <button
                key={`${x.division_id}:${x.campus_id}`}
                type="button"
                aria-current={on ? 'page' : undefined}
                onClick={() => navigate(pathOf(view, { division_id: x.division_id, campus_id: x.campus_id }))}
                className={`shrink-0 border-b-4 px-4 pt-3 pb-2.5 text-[15px] whitespace-nowrap ${on ? 'border-ink font-extrabold' : 'border-transparent font-medium'}`}
              >
                {x.label}
              </button>
            )
          })}
        </nav>
      )}
      {current === null ? (
        <p className="px-[clamp(16px,3vw,40px)] py-6 text-sm text-text-muted">{t.noPlace}</p>
      ) : view === 'bells' ? (
        <Bells key={`${current.division_id}:${current.campus_id}:${JSON.stringify(state.plans)}`} state={state} at={current} onSaved={loaded.set} />
      ) : (
        <Classes state={state} at={current} onSaved={loaded.set} />
      )}
    </>
  )
}

// ── Bell times ───────────────────────────────────────────────────────────────

function Bells({ state, at, onSaved }: { state: TimetableState; at: At; onSaved: (next: TimetableState) => void }) {
  const [plans, setPlans] = useState<BellPlan[]>(() => (state.plans.length > 0 ? state.plans : [{ name: t.defaultPlanName, weekdays: [1, 2, 3, 4, 5], periods: [] }]))
  const [chosen, setChosen] = useState(0)
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [failed, setFailed] = useState<string | null>(null)
  const [working, setWorking] = useState(false)
  const plan = plans[chosen] ?? plans[0]

  if (plan === undefined) return null

  const change = (next: Partial<BellPlan>) => setPlans((ps) => ps.map((p, i) => (i === chosen ? { ...p, ...next } : p)))
  const setRow = (j: number, next: Partial<BellPeriod>) => change({ periods: plan.periods.map((p, k) => (k === j ? { ...p, ...next } : p)) })
  const lessons = plan.periods.filter((p) => p.kind === 'lesson')
  const add = (kind: BellPeriod['kind']) => {
    const last = plan.periods[plan.periods.length - 1]
    const lastLesson = lessons[lessons.length - 1]
    const starts = last?.ends ?? '07:30'
    const length = kind === 'break' ? 30 : lastLesson !== undefined ? minutesBetween(lastLesson.starts, lastLesson.ends) : 40
    change({ periods: [...plan.periods, { name: kind === 'break' ? t.newBreak : t.newPeriod(lessons.length + 1), starts, ends: addMinutes(starts, length), kind }] })
  }
  const taken = new Set(plans.filter((_, i) => i !== chosen).flatMap((p) => p.weekdays))

  const save = async () => {
    setWorking(true)
    setFailed(null)
    setErrors({})
    const result: Saved<TimetableState> = await academicsApi.saveBells({ division_id: at.division_id, campus_id: at.campus_id, plans })
    setWorking(false)

    if (result.kind === 'saved') {
      onSaved(result.data)
      slips.done({ word: copy.slips.saved, title: t.saved })
    } else if (result.kind === 'invalid') {
      setErrors(result.errors)
      setFailed(t.fixTitle)
      const first = Object.keys(result.errors)[0]
      const plan = first !== undefined ? /^plans\.(\d+)/.exec(first)?.[1] : undefined
      if (plan !== undefined) setChosen(Number(plan))
    } else setFailed(result.kind === 'refused' ? s.refused : s.failed)
  }

  const planError = errorFor(errors, `plans.${chosen}.periods`) ?? errorFor(errors, 'plans') ?? errorFor(errors, 'campus_id')
  let number = 0
  const COLS = 'grid-cols-[36px_minmax(140px,1.3fr)_112px_112px_70px_120px_40px]'

  return (
    <div className="flex flex-1 flex-wrap items-stretch">
      <section className="min-w-0 flex-[3_1_640px] bg-sheet px-3 pt-4 pb-6 md:px-[clamp(16px,3vw,40px)]">
        {failed !== null && (
          <div className="mb-3">
            <Notice tone="wrong" title={failed}>
              {planError}
            </Notice>
          </div>
        )}
        {state.plans.length === 0 && <p className="mb-3 text-sm text-text-muted">{t.noBells}</p>}
        <div className="mb-3.5 flex flex-wrap items-center gap-2">
          <span className="label-caps mr-1 text-text-muted">{t.days}</span>
          {plans.map((p, i) => (
            <button
              key={i}
              type="button"
              aria-pressed={i === chosen}
              onClick={() => setChosen(i)}
              className={`h-10 border-2 border-ink px-3.5 text-sm font-extrabold ${i === chosen ? 'bg-ink text-sheet' : 'bg-sheet text-ink hover:bg-field'}`}
            >
              {p.name}
              {Object.keys(errors).some((k) => k.startsWith(`plans.${i}.`)) && <span className="ml-1.5 text-red-pen">!</span>}
            </button>
          ))}
          {plans.length < 7 && (
            <button
              type="button"
              onClick={() => {
                setPlans((ps) => [...ps, { name: t.newPlanName, weekdays: [], periods: plan.periods }])
                setChosen(plans.length)
              }}
              className="h-10 border-2 border-dashed border-rule-dashed bg-sheet px-3.5 text-sm font-bold text-ink hover:bg-field"
            >
              {t.otherDays}
            </button>
          )}
        </div>

        <div className="mb-4 grid gap-3 border-b border-rule pb-4 md:grid-cols-[minmax(200px,280px)_1fr]">
          <Input label={t.planName} hint={t.planNameHint} value={plan.name} maxLength={60} onChange={(e) => change({ name: e.target.value })} error={errorFor(errors, `plans.${chosen}.name`)} />
          <fieldset>
            <legend className="label-caps mb-1.5 text-text-muted">{t.planDays}</legend>
            <div className="flex flex-wrap gap-1.5">
              {[1, 2, 3, 4, 5, 6, 7].map((d) => {
                const on = plan.weekdays.includes(d)

                return (
                  <label
                    key={d}
                    className={`inline-flex h-9 items-center gap-1.5 border-2 px-2.5 text-[13px] font-bold ${taken.has(d) ? 'border-rule text-text-muted' : on ? 'cursor-pointer border-ink bg-ink text-sheet' : 'cursor-pointer border-rule-dashed bg-sheet'}`}
                  >
                    <input
                      type="checkbox"
                      checked={on}
                      disabled={taken.has(d)}
                      onChange={() => change({ weekdays: on ? plan.weekdays.filter((x) => x !== d) : [...plan.weekdays, d].sort() })}
                      className="size-4 accent-teal-bright"
                    />
                    {t.weekdays[d]}
                  </label>
                )
              })}
            </div>
            {errorFor(errors, `plans.${chosen}.weekdays`) !== undefined && <div className="mt-1.5 text-[13px] font-bold text-red-text">{errorFor(errors, `plans.${chosen}.weekdays`)}</div>}
          </fieldset>
          {plans.length > 1 && (
            <div className="md:col-span-2">
              <SmallButton
                tone="red"
                onClick={() => {
                  setPlans((ps) => ps.filter((_, i) => i !== chosen))
                  setChosen(0)
                }}
              >
                {t.removePlan}
              </SmallButton>
            </div>
          )}
        </div>

        <div className="overflow-x-auto">
          <div className="min-w-[680px]">
            <div className={`label-caps grid ${COLS} gap-3 border-b-2 border-ink pr-3 pb-2 text-text-muted`}>
              <span />
              <span>{t.colName}</span>
              <span>{t.colStarts}</span>
              <span>{t.colEnds}</span>
              <span>{t.colLength}</span>
              <span>{t.colKind}</span>
              <span />
            </div>
            {plan.periods.length === 0 && <p className="py-3 text-sm text-text-muted">{t.noRows}</p>}
            {plan.periods.map((p, j) => {
              const lesson = p.kind === 'lesson'
              if (lesson) number += 1
              const error = errorFor(errors, `plans.${chosen}.periods.${j}`)
              const field = 'h-[38px] w-full border-0 border-b-2 bg-field px-2.5 text-ink outline-none focus:border-teal'
              const edge = error !== undefined ? 'border-red-pen' : 'border-ink'
              const label = p.name !== '' ? p.name : t.nameOf(j + 1)

              return (
                <div key={j} className={`border-b border-rule py-2 pr-3 ${error !== undefined ? 'bg-red-wash' : lesson ? '' : 'bg-paper'}`}>
                  <div className={`grid ${COLS} items-center gap-3`}>
                    <span className="type-display text-center text-xl text-text-muted">{lesson ? number : ''}</span>
                    <input type="text" value={p.name} maxLength={40} aria-label={t.nameOf(j + 1)} onChange={(e) => setRow(j, { name: e.target.value })} className={`${field} ${edge} text-[15px] font-bold`} />
                    <input type="time" value={p.starts} aria-label={t.startsOf(label)} onChange={(e) => setRow(j, { starts: e.target.value })} className={`${field} ${edge} ref text-sm`} />
                    <input type="time" value={p.ends} aria-label={t.endsOf(label)} onChange={(e) => setRow(j, { ends: e.target.value })} className={`${field} ${edge} ref text-sm`} />
                    <span className="ref text-sm">{t.minutes(minutesBetween(p.starts, p.ends))}</span>
                    <select value={p.kind} aria-label={t.kindOf(label)} onChange={(e) => setRow(j, { kind: e.target.value as BellPeriod['kind'] })} className={`${field} ${edge} text-sm`}>
                      {(['lesson', 'break'] as const).map((k) => (
                        <option key={k} value={k}>
                          {t.kinds[k]}
                        </option>
                      ))}
                    </select>
                    <button
                      type="button"
                      aria-label={t.remove(label)}
                      title={t.remove(label)}
                      onClick={() => change({ periods: plan.periods.filter((_, k) => k !== j) })}
                      className="size-9 border-2 border-rule bg-sheet text-lg font-extrabold text-text-muted hover:border-ink hover:text-ink"
                    >
                      ×
                    </button>
                  </div>
                  {error !== undefined && <div className="mt-1 pl-12 text-[13px] font-bold text-red-text">{error}</div>}
                </div>
              )
            })}
          </div>
        </div>
        <div className="mt-3 flex flex-wrap gap-2">
          <SmallButton onClick={() => add('lesson')}>{t.addPeriod}</SmallButton>
          <SmallButton onClick={() => add('break')}>{t.addBreak}</SmallButton>
        </div>
        <footer className="mt-4.5 flex flex-wrap items-center gap-3 border-t-2 border-ink pt-4">
          <Button busy={working} onClick={() => void save()}>
            {working ? s.saving : t.save}
          </Button>
          <span className="flex-[1_1_260px] text-[13px] text-text-muted">{t.applies}</span>
        </footer>
      </section>
      <aside className="min-w-0 flex-[1_1_300px] border-ink bg-paper px-3 pt-4 pb-6 max-[959px]:border-t-2 md:px-[clamp(16px,3vw,32px)] min-[960px]:border-l-2">
        <Glance periods={plan.periods} />
      </aside>
    </div>
  )
}

const PX = 1.2

/** The day drawn to scale: each period and break as a box on the hours. */
function Glance({ periods }: { periods: BellPeriod[] }) {
  const valid = periods.filter((p) => /^\d\d:\d\d$/.test(p.starts) && /^\d\d:\d\d$/.test(p.ends) && minutesBetween(p.starts, p.ends) > 0)
  const lessons = valid.filter((p) => p.kind === 'lesson')
  const lengths = new Set(lessons.map((p) => minutesBetween(p.starts, p.ends)))
  const minutes = lessons.reduce((sum, p) => sum + minutesBetween(p.starts, p.ends), 0)
  const first = valid.reduce<string | null>((a, p) => (a === null || p.starts < a ? p.starts : a), null)
  const last = valid.reduce<string | null>((a, p) => (a === null || p.ends > a ? p.ends : a), null)

  return (
    <>
      <h2 className="label-caps mb-1 text-ink">{t.glance}</h2>
      <p className="mb-3.5 text-[13px] text-text-muted">{t.glanceLine(lessons.length, lengths.size === 1 ? ([...lengths][0] ?? null) : null, minutes, last)}</p>
      {first !== null && last !== null && <Timeline periods={valid} first={first} last={last} />}
    </>
  )
}

function Timeline({ periods, first, last }: { periods: BellPeriod[]; first: string; last: string }) {
  const from = Number(first.slice(0, 2))
  const to = Math.min(24, Number(last.slice(0, 2)) + (last.endsWith(':00') ? 0 : 1))
  const origin = `${String(from).padStart(2, '0')}:00`
  const hours = Array.from({ length: to - from + 1 }, (_, i) => from + i)

  return (
    <div className="relative" style={{ height: `${(to - from) * 60 * PX + 8}px` }}>
      {hours.map((h) => (
        <span key={h} className="ref absolute left-0 text-[11px] text-text-muted" style={{ top: `${(h - from) * 60 * PX - 7}px` }}>
          {String(h).padStart(2, '0')}:00
        </span>
      ))}
      {periods.map((p, i) => (
        <div
          key={i}
          className={`absolute right-0 left-[52px] overflow-hidden border-2 border-ink px-2 py-0.5 text-xs ${p.kind === 'lesson' ? 'bg-sheet font-extrabold' : 'bg-paper font-semibold text-text-muted'}`}
          style={{ top: `${minutesBetween(origin, p.starts) * PX}px`, height: `${minutesBetween(p.starts, p.ends) * PX}px` }}
        >
          {p.name}
        </div>
      ))}
    </div>
  )
}

// ── Class timetables ─────────────────────────────────────────────────────────

type Slot = { weekday: number; period: number }

function Classes({ state, at, onSaved }: { state: TimetableState; at: At; onSaved: (next: TimetableState) => void }) {
  const [chosen, setChosen] = useState<Slot | null>(null)
  const [clash, setClash] = useState<Slot | null>(null)

  if (state.year === null) return <p className="px-[clamp(16px,3vw,40px)] py-6 text-sm text-text-muted">{t.noYear}</p>
  if (state.classes.length === 0) return <p className="px-[clamp(16px,3vw,40px)] py-6 text-sm text-text-muted">{t.noClasses}</p>

  const main = mainPlan(state.plans)

  if (main === null || state.class === null) return <p className="px-[clamp(16px,3vw,40px)] py-6 text-sm text-text-muted">{t.bellsFirst}</p>

  const klass = state.class
  const planOf = (weekday: number) => state.plans.find((p) => p.weekdays.includes(weekday)) ?? null
  const lessonOf = (weekday: number, period: number) => planOf(weekday)?.periods.filter((p) => p.kind === 'lesson')[period - 1] ?? null
  const most = Math.max(0, ...state.days.map((d) => d.lessons))
  const subjects = new Map(state.subjects.map((x) => [x.id, x]))
  const teachers = new Map(state.teachers.map((x) => [x.id, x.name]))
  const cols = `120px repeat(${state.days.length}, minmax(120px,1fr))`
  const others = state.plans.filter((p) => p !== main)

  // The rows: the main plan's lessons and breaks, then any lesson rows only other days have.
  const rows: ({ kind: 'lesson'; n: number; times: string } | { kind: 'break'; name: string; starts: string })[] = []
  let n = 0
  for (const p of main.periods) {
    if (p.kind === 'lesson') rows.push({ kind: 'lesson', n: ++n, times: `${p.starts}–${p.ends}` })
    else rows.push({ kind: 'break', name: p.name, starts: p.starts })
  }
  while (n < most) rows.push({ kind: 'lesson', n: ++n, times: '' })

  const same = (a: Slot | null, w: number, p: number) => a !== null && a.weekday === w && a.period === p
  const entry = chosen !== null ? (state.grid.find((g) => g.weekday === chosen.weekday && g.period === chosen.period) ?? null) : null
  const counts = [...state.counts].sort((a, b) => b.periods - a.periods)

  return (
    <>
      <div className="flex flex-wrap items-center gap-2 border-b border-rule bg-sheet px-3 py-3.5 md:px-[clamp(16px,3vw,40px)]">
        <span className="label-caps mr-1 text-text-muted">{t.klass}</span>
        {state.classes.map((c) => (
          <button
            key={c.id}
            type="button"
            aria-pressed={c.id === klass.id}
            onClick={() => {
              setChosen(null)
              setClash(null)
              navigate(pathOf('classes', { ...at, class_id: c.id }))
            }}
            className={`h-10 border-2 border-ink px-3.5 text-sm font-extrabold ${c.id === klass.id ? 'bg-ink text-sheet' : 'bg-sheet text-ink hover:bg-field'}`}
          >
            {c.name}
          </button>
        ))}
      </div>
      <div className="flex flex-1 flex-wrap items-stretch">
        <section className="min-w-0 flex-[3_1_640px] bg-sheet px-3 pt-4 pb-6 md:px-[clamp(16px,3vw,40px)]">
          <div className="overflow-x-auto">
            <div style={{ minWidth: `${120 + state.days.length * 122}px` }}>
              <div className="label-caps grid border-b-2 border-ink text-text-muted" style={{ gridTemplateColumns: cols }}>
                <span />
                {state.days.map((d) => (
                  <span key={d.weekday} className="px-2 pb-2">
                    {t.weekdays[d.weekday]}
                  </span>
                ))}
              </div>
              {rows.map((r) =>
                r.kind === 'break' ? (
                  <div key={`b:${r.starts}`} className="grid h-[26px] grid-cols-[120px_1fr] items-center border-b border-rule bg-paper text-xs text-text-muted">
                    <span className="ref px-2">{r.starts}</span>
                    <span className="label-caps px-2">{r.name}</span>
                  </div>
                ) : (
                  <div key={`p:${r.n}`} className="grid border-b border-rule" style={{ gridTemplateColumns: cols }}>
                    <span className="border-r-2 border-ink p-2">
                      <span className="type-display block text-lg leading-none">{t.period(r.n)}</span>
                      <span className="ref block text-xs text-text-muted">{r.times}</span>
                    </span>
                    {state.days.map((d) => {
                      if (r.n > d.lessons) {
                        return (
                          <span key={d.weekday} className="border-l border-rule bg-[repeating-linear-gradient(135deg,var(--color-paper)_0_6px,var(--color-field)_6px_8px)] p-2 text-xs text-text-muted">
                            {t.noPeriod}
                          </span>
                        )
                      }
                      const g = state.grid.find((x) => x.weekday === d.weekday && x.period === r.n)
                      const subject = g !== undefined ? (subjects.get(g.subject_id)?.name ?? '') : null
                      const teacher = g?.user_id != null ? (teachers.get(g.user_id) ?? null) : null
                      const on = same(chosen, d.weekday, r.n)
                      const clashed = same(clash, d.weekday, r.n)

                      return (
                        <button
                          key={d.weekday}
                          type="button"
                          aria-pressed={on}
                          aria-label={t.cellLabel(t.weekdays[d.weekday] ?? '', r.n, subject ?? t.freePeriod)}
                          onClick={() => {
                            setClash(null)
                            setChosen(on ? null : { weekday: d.weekday, period: r.n })
                          }}
                          className={`block min-h-[58px] border-l border-rule p-2 text-left text-ink ${clashed ? 'bg-red-wash' : 'bg-sheet hover:bg-field'} ${on ? 'outline-[3px] -outline-offset-[3px] outline-teal' : ''}`}
                        >
                          {subject !== null ? (
                            <>
                              <span className="block text-sm font-extrabold">{subject}</span>
                              <span className="block text-xs text-text-muted">{teacher ?? t.noTeacher}</span>
                            </>
                          ) : (
                            <span className="block text-xs text-text-muted">{t.freePeriod}</span>
                          )}
                          {clashed && (
                            <span className="mt-1 block">
                              <Tag tone="wrong">{t.clashTag}</Tag>
                            </span>
                          )}
                        </button>
                      )
                    })}
                  </div>
                ),
              )}
            </div>
          </div>
          <p className="mt-3 text-[13px] text-text-muted">
            {others.map((p) => t.ownTimes(p.name, p.periods.filter((x) => x.kind === 'lesson').length)).join(' ')} {t.appliesClasses}
          </p>
        </section>
        <aside className="min-w-0 flex-[1_1_320px] border-ink bg-paper px-3 pt-4 pb-6 max-[959px]:border-t-2 md:px-[clamp(16px,3vw,32px)] min-[960px]:border-l-2">
          {chosen !== null ? (
            <Editor
              key={`${klass.id}:${chosen.weekday}:${chosen.period}:${entry?.subject_id ?? ''}:${entry?.user_id ?? ''}`}
              state={state}
              klass={klass}
              slot={chosen}
              times={(() => {
                const l = lessonOf(chosen.weekday, chosen.period)
                return l !== null ? `${l.starts}–${l.ends}` : ''
              })()}
              entry={entry}
              onClash={(clashed) => setClash(clashed ? chosen : null)}
              onSaved={(next, free) => {
                setChosen(null)
                setClash(null)
                onSaved(next)
                slips.done({ word: copy.slips.saved, title: free ? t.madeFree : t.periodSaved })
              }}
            />
          ) : (
            <p className="border-2 border-dashed border-rule-dashed bg-sheet px-4 py-3.5 text-sm text-text-muted">{t.pick}</p>
          )}
          <h2 className="label-caps mt-5 mb-1.5 text-ink">{t.perWeek}</h2>
          {counts.map((c) => (
            <div key={c.subject_id} className="flex justify-between border-b border-rule py-1.5 text-sm">
              <span>{subjects.get(c.subject_id)?.name ?? ''}</span>
              <span className="ref font-bold">{c.periods}</span>
            </div>
          ))}
          <div className="flex justify-between border-b border-rule py-1.5 text-sm text-text-muted">
            <span>{t.freePeriods}</span>
            <span className="ref font-bold">{state.free}</span>
          </div>
        </aside>
      </div>
    </>
  )
}

function Editor({
  state,
  klass,
  slot,
  times,
  entry,
  onClash,
  onSaved,
}: {
  state: TimetableState
  klass: { id: string; name: string }
  slot: Slot
  times: string
  entry: TimetableState['grid'][number] | null
  onClash: (clashed: boolean) => void
  onSaved: (next: TimetableState, free: boolean) => void
}) {
  const teaching = state.teaching as Record<string, string>
  const [subject, setSubject] = useState(entry?.subject_id ?? '')
  const [teacher, setTeacher] = useState(entry?.user_id ?? '')
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [failed, setFailed] = useState<string | null>(null)
  const [working, setWorking] = useState(false)
  const fromTeaching = subject !== '' && teaching[subject] === teacher && teacher !== ''
  const clash = errorFor(errors, 'user_id')

  const save = async (free: boolean) => {
    setWorking(true)
    setFailed(null)
    setErrors({})
    const result = await academicsApi.setPeriod({ class_id: klass.id, weekday: slot.weekday, period: slot.period, subject_id: free ? null : subject, user_id: free || teacher === '' ? null : teacher })
    setWorking(false)

    if (result.kind === 'saved') onSaved(result.data, free)
    else if (result.kind === 'invalid') {
      setErrors(result.errors)
      onClash(errorFor(result.errors, 'user_id') !== undefined)
      if (Object.keys(result.errors).every((k) => !['subject_id', 'user_id'].includes(k))) setFailed(Object.values(result.errors)[0]?.[0] ?? s.failed)
    } else setFailed(result.kind === 'refused' ? s.refused : s.failed)
  }

  return (
    <div className="border-2 border-ink bg-sheet px-4 pt-3.5 pb-4">
      <div className="label-caps text-text-muted">{t.editing(t.weekdays[slot.weekday] ?? '', slot.period, times)}</div>
      <div className="type-display mt-1 mb-3 text-[26px] leading-none">{klass.name}</div>
      <div className="grid gap-3">
        <Select
          label={t.subject}
          value={subject}
          onChange={(e) => {
            setSubject(e.target.value)
            const usual = teaching[e.target.value]
            if (usual !== undefined) setTeacher(usual)
          }}
          error={errorFor(errors, 'subject_id')}
        >
          <option value="">{t.chooseSubject}</option>
          {state.subjects.map((x) => (
            <option key={x.id} value={x.id}>
              {x.name}
            </option>
          ))}
        </Select>
        <Select
          label={t.teacher}
          value={teacher}
          onChange={(e) => setTeacher(e.target.value)}
          hint={fromTeaching ? t.fromTeaching(state.teachers.find((x) => x.id === teacher)?.name ?? '', state.subjects.find((x) => x.id === subject)?.name ?? '', klass.name) : undefined}
        >
          <option value="">{t.noTeacher}</option>
          {state.teachers.map((x) => (
            <option key={x.id} value={x.id}>
              {x.name}
            </option>
          ))}
        </Select>
      </div>
      {clash !== undefined && (
        <div role="alert" className="mt-3 border-2 border-red-pen bg-red-wash px-3 py-2.5 text-sm">
          <strong className="text-red-text">{t.clash}</strong> {clash}
        </div>
      )}
      {failed !== null && <p className="mt-3 text-[13px] font-bold text-red-text">{failed}</p>}
      <div className="mt-3 flex flex-wrap gap-2">
        <Button busy={working} disabled={subject === ''} onClick={() => void save(false)}>
          {working ? s.saving : t.savePeriod}
        </Button>
        {entry !== null && (
          <Button variant="secondary" busy={working} onClick={() => void save(true)}>
            {t.makeFree}
          </Button>
        )}
      </div>
    </div>
  )
}
