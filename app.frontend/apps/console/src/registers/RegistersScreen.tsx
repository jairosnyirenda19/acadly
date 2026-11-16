import { navigate, useLoad, useLocation } from '@acadly/kernel'
import { Waiting } from '@acadly/ui'
import { Fragment } from 'react'
import { copy } from '../copy'
import { Total, TotalsBand } from '../setup/parts'
import { Tag } from '../settings/parts'
import { useWorkIn } from '../shell/WorkIn'
import { clock, type DayState, longDay, registersApi } from './api'
import { RegistersHead } from './parts'

const t = copy.registers

const COLS = 'grid-cols-[minmax(140px,1fr)_minmax(150px,1fr)_minmax(200px,1.3fr)_70px_70px_70px_70px]'

/** Every register the viewer may see on one school day (the approved design). */
export function RegistersScreen() {
  const { query } = useLocation()
  const day = query.get('day')
  const { place } = useWorkIn()
  const inPlace = place !== null && place.key !== 'institution:' ? place.key : null
  const loaded = useLoad(`registers:${day ?? ''}:${inPlace ?? ''}`, () => registersApi.day(day, inPlace))
  const state = loaded.data

  if (state === undefined) {
    return <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} band={[t.bandTaken, t.bandPresent, t.bandAbsent, t.bandAttention]} rows={6} onRetry={loaded.reload} />
  }

  const totals = state.totals
  const marked = totals.present + totals.absent + totals.late + totals.excused
  const attention = totals.not_taken + totals.corrections
  // Viewers who see more than one division or campus read the classes under each place.
  const grouped = new Set(state.classes.map((c) => `${c.division} · ${c.campus}`)).size > 1

  return (
    <>
      <RegistersHead
        kicker={t.kicker(inPlace !== null && place !== null ? place.label : null, state.year)}
        intro={t.intro}
        day={state.day}
        today={state.today}
        current="morning"
        tabs={state.lessons}
      />
      <TotalsBand>
        <Total tone="ink" label={t.bandTaken} value={String(totals.taken)} suffix={` / ${totals.due}`} note={t.bandTakenNote(longDay(state.day))} />
        <Total label={t.bandPresent} value={String(totals.present)} note={t.bandPresentNote(marked === 0 ? null : Math.round((totals.present / marked) * 100))} />
        <Total label={t.bandAbsent} value={String(totals.absent)} note={t.bandAbsentNote(totals.late, totals.excused)} />
        <Total tone={attention > 0 ? 'attention' : 'plain'} label={t.bandAttention} value={String(attention)} note={t.bandAttentionNote(totals.not_taken, totals.corrections)} />
      </TotalsBand>

      <section className="flex-1 bg-sheet px-[clamp(16px,3vw,40px)] pt-4.5 pb-8">
        <div className="overflow-x-auto">
          <div className="min-w-[820px]">
            <div className={`label-caps grid ${COLS} gap-3 border-b-2 border-ink px-4 pb-2 text-text-muted`}>
              <span>{t.colClass}</span>
              <span>{t.colTeacher}</span>
              <span>{t.colRegister}</span>
              <span className="text-right">{t.colPresent}</span>
              <span className="text-right">{t.colAbsent}</span>
              <span className="text-right">{t.colLate}</span>
              <span className="text-right">{t.colExcused}</span>
            </div>
            {state.classes.length === 0 && <p className="px-4 py-4 text-sm text-text-muted">{t.none}</p>}
            {state.classes.map((c, i) => {
              const group = `${c.division} · ${c.campus}`
              const before = i > 0 ? state.classes[i - 1] : undefined
              const head = grouped && (before === undefined || `${before.division} · ${before.campus}` !== group)
              const missing = c.due && c.register === null && state.day <= state.today
              const open = () => navigate(`/registers/${c.id}${state.day !== state.today ? `?day=${state.day}` : ''}`)

              return (
                <Fragment key={c.id}>
                {head && <GroupHead name={group} rows={state.classes.filter((x) => x.division === c.division && x.campus === c.campus)} />}
                <div
                  role="link"
                  tabIndex={0}
                  aria-label={t.open(c.name)}
                  onClick={open}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter') open()
                  }}
                  className={`grid cursor-pointer ${COLS} items-center gap-3 border-b border-rule px-4 py-2.5 hover:bg-paper ${missing ? 'bg-marigold-wash' : ''}`}
                >
                  <span className="type-display text-xl leading-none">{c.name}</span>
                  <span className={`text-sm ${c.teacher !== null ? 'font-bold' : 'text-text-muted'}`}>{c.teacher ?? t.notGiven}</span>
                  <span className="flex flex-wrap items-center gap-1.5">
                    {c.register !== null ? (
                      <>
                        <Tag tone="done">{t.taken}</Tag>
                        <span className="ref text-xs text-text-muted">{clock(c.register.submitted_at)}</span>
                      </>
                    ) : missing ? (
                      <Tag tone="attention">{t.notTaken}</Tag>
                    ) : !c.school_day ? (
                      <Tag tone="plain">{t.notSchoolDay}</Tag>
                    ) : (
                      c.learners === 0 && <span className="text-[13px] text-text-muted">{t.noLearners}</span>
                    )}
                    {c.corrections > 0 && <Tag tone="attention">{t.correctionsToApprove(c.corrections)}</Tag>}
                  </span>
                  {(['present', 'absent', 'late', 'excused'] as const).map((k) => (
                    <span key={k} className={`type-numeral text-right text-[26px] ${k === 'absent' && c.counts.absent > 0 ? 'text-red-pen' : ''}`}>
                      {c.register !== null ? c.counts[k] : '–'}
                    </span>
                  ))}
                </div>
                </Fragment>
              )
            })}
          </div>
        </div>
        <p className="mt-3 text-[13px] text-text-muted">{t.rule}</p>
      </section>
    </>
  )
}

/** The place a run of classes belongs to, with how many of its due registers are taken. */
function GroupHead({ name, rows }: { name: string; rows: DayState['classes'] }) {
  const due = rows.filter((r) => r.due).length
  const taken = rows.filter((r) => r.register !== null).length

  return (
    <div className="flex items-baseline justify-between gap-3 border-b border-rule bg-field px-4 pt-4 pb-2">
      <span className="label-caps text-ink">{name}</span>
      <span className="text-[13px] text-text-muted">{t.groupTaken(taken, due)}</span>
    </div>
  )
}
