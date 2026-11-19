import { Notice } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { errorFor, setupApi, type AcademicsState } from './api'
import { Column, Columns, Input, SectionTitle, StepFooter, Total, TotalsBand, nn } from './parts'
import type { StepProps } from './types'

const t = copy.setup.steps.year
const DAY = 86_400_000

type Level = AcademicsState['levels'][number]

interface Term {
  name: string
  starts_on: string
  ends_on: string
}

const time = (day: string) => (day === '' ? Number.NaN : Date.parse(`${day}T00:00:00Z`))
const weeks = (from: string, to: string) => {
  const span = time(to) - time(from)

  return Number.isFinite(span) && span > 0 ? Math.round((span + DAY) / (7 * DAY)) : 0
}
const totalWeeks = (terms: Term[]) => terms.reduce((sum, term) => sum + weeks(term.starts_on, term.ends_on), 0)
const long = (day: string) =>
  Number.isFinite(time(day)) ? new Date(time(day)).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' }) : ''

const countOf = (level: Level) => level.periods_per_year ?? Math.max(1, level.terms.length)
const isSet = (level: Level) => level.terms.length > 0 && level.terms.length === countOf(level)

/** The terms of a level as saved, or blank rows named from the level's word. */
function fromServer(level: Level): Term[] {
  return Array.from({ length: countOf(level) }, (_, i) => {
    const saved = level.terms[i]

    return { name: saved?.name ?? t.periodName(level.period_word, i + 1), starts_on: saved?.starts_on ?? '', ends_on: saved?.ends_on ?? '' }
  })
}

export function YearStep({ academics, onAcademics, layout, next }: StepProps) {
  const levels = academics.levels
  const year = academics.year
  const [name, setName] = useState(year?.name ?? '')
  const [startsOn, setStartsOn] = useState(year?.starts_on ?? '')
  const [endsOn, setEndsOn] = useState(year?.ends_on ?? '')
  const [tab, setTab] = useState(() => (levels.find((level) => !isSet(level)) ?? levels[0])?.division_id ?? '')
  const [drafts, setDrafts] = useState<Record<string, Term[]>>(() => Object.fromEntries(levels.map((level) => [level.division_id, fromServer(level)])))
  const [source, setSource] = useState('')
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [failed, setFailed] = useState(false)
  const [working, setWorking] = useState(false)

  const level = levels.find((l) => l.division_id === tab) ?? levels[0]
  const draftOf = (l: Level) => drafts[l.division_id] ?? fromServer(l)
  const terms = level !== undefined ? draftOf(level) : []
  const others = levels.filter((l) => l.division_id !== level?.division_id)
  const from = others.find((l) => l.division_id === source) ?? others[0]

  const setTerm = (i: number, patch: Partial<Term>) => {
    if (level === undefined) return
    const key = level.division_id
    setDrafts((current) => ({ ...current, [key]: (current[key] ?? fromServer(level)).map((term, j) => (j === i ? { ...term, ...patch } : term)) }))
  }

  const copyTerms = () => {
    if (level === undefined || from === undefined) return
    const copied = draftOf(from)
    const key = level.division_id
    setDrafts((current) => ({
      ...current,
      [key]: fromServer(level).map((blank, i) => {
        const term = copied[i]

        return term !== undefined ? { name: term.name, starts_on: term.starts_on, ends_on: term.ends_on } : blank
      }),
    }))
  }

  const yearChanged = year === null || year.name !== name || year.starts_on !== startsOn || year.ends_on !== endsOn

  const save = async () => {
    if (level === undefined) return

    setWorking(true)
    setFailed(false)

    if (yearChanged) {
      const saved = await setupApi.saveYear({ name, starts_on: startsOn, ends_on: endsOn })

      if (saved.kind !== 'saved') {
        setWorking(false)
        if (saved.kind === 'invalid') setErrors(saved.errors)
        else setFailed(true)

        return
      }

      onAcademics(saved.data)
    }

    const result = await setupApi.saveTerms(level.division_id, terms)
    setWorking(false)

    if (result.kind === 'saved') {
      setErrors({})
      onAcademics(result.data)
      const waiting = result.data.levels.find((l) => !isSet(l))

      if (waiting !== undefined) setTab(waiting.division_id)
      else next()
    } else if (result.kind === 'invalid') {
      setErrors(result.errors)
    } else {
      setFailed(true)
    }
  }

  const termError = (i: number) => errorFor(errors, `terms.${i}.starts_on`) ?? errorFor(errors, `terms.${i}.ends_on`) ?? errorFor(errors, `terms.${i}.name`)

  const done = levels.filter(isSet).length
  const weekTotals = levels.map((l) => ({ name: l.division, weeks: totalWeeks(draftOf(l)) })).filter((w) => w.weeks > 0)
  const low = Math.min(...weekTotals.map((w) => w.weeks))
  const high = Math.max(...weekTotals.map((w) => w.weeks))

  const yearStart = time(startsOn)
  const yearSpan = time(endsOn) - yearStart
  const at = (day: string) => (Number.isFinite(yearSpan) && yearSpan > 0 ? Math.min(100, Math.max(0, ((time(day) - yearStart) / yearSpan) * 100)) : 0)
  const today = new Date().toISOString().slice(0, 10)
  const todayInYear = Number.isFinite(yearSpan) && time(today) >= yearStart && time(today) <= time(endsOn)
  const months = Number.isFinite(yearStart)
    ? Array.from({ length: 12 }, (_, i) =>
        new Date(Date.UTC(new Date(yearStart).getUTCFullYear(), new Date(yearStart).getUTCMonth() + i, 1)).toLocaleDateString('en-GB', { month: 'short', timeZone: 'UTC' }),
      )
    : []

  const active = (l: Level) => {
    const term = draftOf(l).find((x) => x.starts_on !== '' && x.ends_on !== '' && x.starts_on <= today && today <= x.ends_on)

    return term !== undefined
      ? t.activeIn(l.division, term.name, Math.floor((time(today) - time(term.starts_on)) / (7 * DAY)) + 1, weeks(term.starts_on, term.ends_on))
      : t.activeOut(l.division)
  }

  const plural = level?.period_word_plural.toLowerCase() ?? ''

  return layout({
    title: t.title,
    intro: t.intro,
    footer: <StepFooter step="year" primary={copy.setup.continue} onPrimary={() => void save()} working={working} />,
    children: (
      <>
        <TotalsBand>
          <Total tone="ink" label={t.bandYear} value={name || t.bandYearNone} note={startsOn && endsOn ? t.bandSpan(long(startsOn), long(endsOn)) : ''} />
          <Total label={t.bandLevels} value={String(done)} suffix={t.bandLevelsOf(levels.length)} note={t.bandLevelsNote} />
          <Total
            label={t.bandWeeks}
            value={weekTotals.length > 0 ? String(low) : '0'}
            suffix={weekTotals.length > 0 && high !== low ? t.bandWeeksRange(high) : undefined}
            note={weekTotals.length > 0 ? t.bandWeeksNote(weekTotals.map((w) => `${w.name} ${w.weeks}`).join(', ')) : t.bandWeeksNone}
          />
        </TotalsBand>
        <Columns>
          <Column wide sheet>
            {(failed || Object.keys(errors).length > 0) && (
              <div className="mb-5">
                <Notice tone="wrong" title={copy.setup.fixTitle}>
                  {failed ? copy.setup.loadFailed : (errorFor(errors, 'terms') ?? errorFor(errors, 'year') ?? copy.setup.fix)}
                </Notice>
              </div>
            )}
            <SectionTitle>{t.yearSection}</SectionTitle>
            <div className="mb-2 grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-x-6 gap-y-4.5">
              <Input label={t.nameLabel} required value={name} onChange={(e) => setName(e.target.value)} error={errorFor(errors, 'name')} maxLength={40} />
              <Input label={t.startsLabel} required type="date" value={startsOn} onChange={(e) => setStartsOn(e.target.value)} error={errorFor(errors, 'starts_on')} />
              <Input label={t.endsLabel} required type="date" value={endsOn} onChange={(e) => setEndsOn(e.target.value)} error={errorFor(errors, 'ends_on')} />
            </div>
            <p className="mb-7 text-[13px] text-text-muted">{t.yearNote}</p>

            <SectionTitle>{t.termsSection}</SectionTitle>
            <nav aria-label={t.termsSection} className="flex flex-wrap gap-1 border-b-2 border-ink">
              {levels.map((l) => {
                const current = l.division_id === level?.division_id
                const saved = isSet(l)

                return (
                  <button
                    key={l.division_id}
                    type="button"
                    aria-current={current ? 'page' : undefined}
                    onClick={() => {
                      setTab(l.division_id)
                      setErrors({})
                    }}
                    className={`flex items-center gap-2 border-b-4 px-4 pt-3 pb-2.5 text-[15px] ${current ? 'border-teal font-extrabold' : 'border-transparent font-medium'}`}
                  >
                    {saved ? (
                      <span aria-hidden="true" className="grid size-[18px] place-items-center bg-teal text-xs font-extrabold text-sheet">
                        /
                      </span>
                    ) : (
                      <span aria-hidden="true" className="size-[18px] border-2 border-dashed border-rule-dashed" />
                    )}
                    {l.division}
                    <span className="ref text-xs text-text-muted">{t.tabSummary(countOf(l), l.period_word_plural.toLowerCase(), totalWeeks(draftOf(l)))}</span>
                    <span className="sr-only"> · {saved ? t.tabSaved : t.tabNotSet}</span>
                  </button>
                )
              })}
            </nav>

            {level !== undefined && from !== undefined && (
              <div className="my-4 flex flex-wrap items-end gap-3 border-2 border-ink bg-paper px-4 py-3.5">
                <label className="block min-w-0 flex-[1_1_220px]">
                  <span className="label-caps mb-1.5 block text-text-muted">{t.copyFrom(plural)}</span>
                  <select
                    value={from.division_id}
                    onChange={(e) => setSource(e.target.value)}
                    className="h-11 w-full border-0 border-b-2 border-ink bg-field px-3 text-[15px] text-ink outline-none focus:border-teal"
                  >
                    {others.map((o) => (
                      <option key={o.division_id} value={o.division_id}>
                        {o.division}
                      </option>
                    ))}
                  </select>
                </label>
                <button type="button" onClick={copyTerms} className="h-11 border-2 border-ink px-[18px] text-[15px] font-extrabold hover:bg-field">
                  {t.copyTo(level.division)}
                </button>
                <p className="basis-full text-[13px] text-text-muted">{t.copyNote(from.division, level.division)}</p>
              </div>
            )}

            {level !== undefined && (
              <>
                <div className="overflow-x-auto">
                  <div className="min-w-[620px]">
                    <div className="label-caps grid grid-cols-[48px_minmax(140px,1.2fr)_minmax(150px,1fr)_minmax(150px,1fr)_88px] gap-3 border-b-2 border-ink pt-2.5 pr-4 pb-2 text-text-muted">
                      <span />
                      <span>{level.period_word}</span>
                      <span>{t.startsLabel}</span>
                      <span>{t.endsLabel}</span>
                      <span className="text-right">{t.length}</span>
                    </div>
                    <div className="ledger [--margin:48px]">
                      {terms.map((term, i) => {
                        const problem = termError(i)

                        return (
                          <div key={`${level.division_id}:${i}`} className={`border-b border-rule py-3 pr-4 ${problem !== undefined ? 'bg-red-wash' : ''}`}>
                            <div className="grid grid-cols-[48px_minmax(140px,1.2fr)_minmax(150px,1fr)_minmax(150px,1fr)_88px] items-center gap-3">
                              <div className={`type-display text-center text-xl ${problem !== undefined ? 'text-red-pen' : 'text-text-muted'}`}>{nn(i + 1)}</div>
                              <input
                                aria-label={`${level.division} · ${level.period_word} ${i + 1}`}
                                value={term.name}
                                maxLength={40}
                                onChange={(e) => setTerm(i, { name: e.target.value })}
                                className="h-11 border-0 border-b-2 border-ink bg-field px-3 text-base font-extrabold outline-none focus:border-teal"
                              />
                              <input
                                aria-label={`${level.division} · ${term.name} · ${t.startsLabel}`}
                                type="date"
                                value={term.starts_on}
                                onChange={(e) => setTerm(i, { starts_on: e.target.value })}
                                className="h-11 border-0 border-b-2 border-ink bg-field px-3 outline-none focus:border-teal"
                              />
                              <input
                                aria-label={`${level.division} · ${term.name} · ${t.endsLabel}`}
                                type="date"
                                value={term.ends_on}
                                onChange={(e) => setTerm(i, { ends_on: e.target.value })}
                                className="h-11 border-0 border-b-2 border-ink bg-field px-3 outline-none focus:border-teal"
                              />
                              <div className="type-numeral text-right text-4xl">
                                {weeks(term.starts_on, term.ends_on)}
                                <span className="label-caps block text-text-muted">{t.weeks}</span>
                              </div>
                            </div>
                            {problem !== undefined && <div className="mt-1.5 pl-[60px] text-[13px] font-bold text-red-text">{problem}</div>}
                          </div>
                        )
                      })}
                    </div>
                  </div>
                </div>
                <p className="mt-3.5 text-[13px] text-text-muted">{t.levelNote(level.division, countOf(level), plural)}</p>
              </>
            )}
          </Column>

          <Column edge>
            <SectionTitle>{t.glance}</SectionTitle>
            <div className="border-2 border-ink bg-sheet">
              <div className="grid grid-cols-[84px_1fr] border-b-2 border-ink">
                <div className="label-caps border-r-2 border-ink px-2 py-1.5 text-text-muted">{t.levelColumn}</div>
                <div className="grid grid-cols-12">
                  {months.map((m, i) => (
                    <div key={i} className="overflow-hidden border-l border-rule py-1.5 text-center text-[9px] font-extrabold tracking-[0.02em] text-text-muted uppercase first:border-l-0">
                      {m}
                    </div>
                  ))}
                </div>
              </div>
              {levels.map((l) => (
                <div key={l.division_id} className="grid grid-cols-[84px_1fr] border-b border-rule last:border-b-0">
                  <div title={l.division} className="truncate border-r-2 border-ink px-2 py-2.5 text-[13px] font-extrabold">
                    {l.division}
                  </div>
                  <div className="relative h-11">
                    {draftOf(l).map((term, i) =>
                      term.starts_on && term.ends_on ? (
                        <div
                          key={i}
                          className="absolute top-[7px] flex h-[30px] items-center overflow-hidden bg-teal pl-1.5 text-xs font-extrabold whitespace-nowrap text-sheet"
                          style={{ left: `${at(term.starts_on)}%`, width: `${Math.max(2, at(term.ends_on) - at(term.starts_on))}%` }}
                        >
                          {term.name}
                        </div>
                      ) : null,
                    )}
                    {todayInYear && <div aria-hidden="true" className="absolute top-0 bottom-0 w-[3px] bg-ink" style={{ left: `${at(today)}%` }} />}
                  </div>
                </div>
              ))}
            </div>
            <div className="mt-3 flex flex-wrap gap-4 text-[13px] text-text-muted">
              <span className="inline-flex items-center gap-1.5">
                <span aria-hidden="true" className="size-4 bg-teal" />
                {t.teaching}
              </span>
              <span className="inline-flex items-center gap-1.5">
                <span aria-hidden="true" className="h-4 w-[3px] bg-ink" />
                {t.today}
              </span>
            </div>

            <div className="mt-7 mb-4.5 h-0.5 bg-ink" />
            <div className="label-caps mb-2 text-text-muted">{t.activeSection}</div>
            <p className="text-sm leading-normal">{t.activeIntro}</p>
            {todayInYear && (
              <ul className="mt-2 text-sm leading-normal">
                {levels.map((l) => (
                  <li key={l.division_id}>{active(l)}</li>
                ))}
              </ul>
            )}
          </Column>
        </Columns>
      </>
    ),
  })
}
