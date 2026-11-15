import { Notice } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { errorFor, setupApi } from './api'
import { Column, Columns, Input, SectionTitle, StepFooter, Total, TotalsBand, nn } from './parts'
import type { StepProps } from './types'

const t = copy.setup.steps.year
const DAY = 86_400_000

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
const long = (day: string) =>
  Number.isFinite(time(day)) ? new Date(time(day)).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' }) : ''

export function YearStep({ academics, onAcademics, layout, next }: StepProps) {
  const word = academics.period_word
  const count = academics.periods_per_year ?? 3
  const year = academics.year
  const [name, setName] = useState(year?.name ?? '')
  const [startsOn, setStartsOn] = useState(year?.starts_on ?? '')
  const [endsOn, setEndsOn] = useState(year?.ends_on ?? '')
  const [terms, setTerms] = useState<Term[]>(() =>
    Array.from({ length: count }, (_, i) => {
      const saved = year?.periods[i]

      return { name: saved?.name ?? t.periodName(word, i + 1), starts_on: saved?.starts_on ?? '', ends_on: saved?.ends_on ?? '' }
    }),
  )
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [failed, setFailed] = useState(false)
  const [working, setWorking] = useState(false)

  const setTerm = (i: number, patch: Partial<Term>) => setTerms((current) => current.map((term, j) => (j === i ? { ...term, ...patch } : term)))
  const total = terms.reduce((sum, term) => sum + weeks(term.starts_on, term.ends_on), 0)
  const yearStart = time(startsOn)
  const yearSpan = time(endsOn) - yearStart
  const at = (day: string) => (Number.isFinite(yearSpan) && yearSpan > 0 ? Math.min(100, Math.max(0, ((time(day) - yearStart) / yearSpan) * 100)) : 0)
  const today = new Date().toISOString().slice(0, 10)
  const months = Number.isFinite(yearStart)
    ? Array.from({ length: 12 }, (_, i) => new Date(Date.UTC(new Date(yearStart).getUTCFullYear(), new Date(yearStart).getUTCMonth() + i, 1)).toLocaleDateString('en-GB', { month: 'short', timeZone: 'UTC' }))
    : []

  const save = async () => {
    setWorking(true)
    setFailed(false)
    const result = await setupApi.saveYear({ name, starts_on: startsOn, ends_on: endsOn, terms })
    setWorking(false)

    if (result.kind === 'saved') {
      setErrors({})
      onAcademics(result.data)
      next()
    } else if (result.kind === 'invalid') {
      setErrors(result.errors)
    } else {
      setFailed(true)
    }
  }

  const termError = (i: number) => errorFor(errors, `terms.${i}.starts_on`) ?? errorFor(errors, `terms.${i}.ends_on`) ?? errorFor(errors, `terms.${i}.name`)

  return layout({
    title: t.title,
    intro: t.intro,
    footer: <StepFooter step="year" primary={copy.setup.continue} onPrimary={() => void save()} working={working} />,
    children: (
      <>
        <TotalsBand>
          <Total tone="ink" label={t.bandYear} value={name || t.bandYearNone} note={startsOn && endsOn ? t.bandSpan(long(startsOn), long(endsOn)) : ''} />
          <Total label={t.bandPeriods(word)} value={String(count)} note={t.bandPeriodsNote} />
          <Total label={t.bandWeeks} value={String(total)} note={t.bandWeeksNote} />
        </TotalsBand>
        <Columns>
          <Column wide sheet>
            {(failed || Object.keys(errors).length > 0) && (
              <div className="mb-5">
                <Notice tone="wrong" title={copy.setup.fixTitle}>
                  {failed ? copy.setup.loadFailed : (errorFor(errors, 'terms') ?? copy.setup.fix)}
                </Notice>
              </div>
            )}
            <SectionTitle>{t.yearSection}</SectionTitle>
            <div className="mb-7 grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-x-6 gap-y-4.5">
              <Input label={t.nameLabel} required value={name} onChange={(e) => setName(e.target.value)} error={errorFor(errors, 'name')} maxLength={40} />
              <Input label={t.startsLabel} required type="date" value={startsOn} onChange={(e) => setStartsOn(e.target.value)} error={errorFor(errors, 'starts_on')} />
              <Input label={t.endsLabel} required type="date" value={endsOn} onChange={(e) => setEndsOn(e.target.value)} error={errorFor(errors, 'ends_on')} />
            </div>
            <SectionTitle>{t.periodsSection(word)}</SectionTitle>
            <div className="overflow-x-auto">
              <div className="min-w-[620px]">
                <div className="label-caps grid grid-cols-[48px_minmax(140px,1.2fr)_minmax(150px,1fr)_minmax(150px,1fr)_88px] gap-3 border-b-2 border-ink pr-4 pb-2 text-text-muted">
                  <span />
                  <span>{word}</span>
                  <span>{t.startsLabel}</span>
                  <span>{t.endsLabel}</span>
                  <span className="text-right">{t.length}</span>
                </div>
                <div className="ledger [--margin:48px]">
                  {terms.map((term, i) => {
                    const problem = termError(i)

                    return (
                      <div key={i} className={`border-b border-rule py-3 pr-4 ${problem !== undefined ? 'bg-red-wash' : ''}`}>
                        <div className="grid grid-cols-[48px_minmax(140px,1.2fr)_minmax(150px,1fr)_minmax(150px,1fr)_88px] items-center gap-3">
                          <div className={`type-display text-center text-xl ${problem !== undefined ? 'text-red-pen' : 'text-text-muted'}`}>{nn(i + 1)}</div>
                          <input aria-label={`${word} ${i + 1}`} value={term.name} maxLength={40} onChange={(e) => setTerm(i, { name: e.target.value })} className="h-11 border-0 border-b-2 border-ink bg-field px-3 text-base font-extrabold outline-none focus:border-teal" />
                          <input aria-label={`${term.name} ${t.startsLabel}`} type="date" value={term.starts_on} onChange={(e) => setTerm(i, { starts_on: e.target.value })} className="h-11 border-0 border-b-2 border-ink bg-field px-3 outline-none focus:border-teal" />
                          <input aria-label={`${term.name} ${t.endsLabel}`} type="date" value={term.ends_on} onChange={(e) => setTerm(i, { ends_on: e.target.value })} className="h-11 border-0 border-b-2 border-ink bg-field px-3 outline-none focus:border-teal" />
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
            <p className="mt-3.5 text-[13px] text-text-muted">{t.periodsNote}</p>
          </Column>

          <Column edge>
            <SectionTitle>{t.glance}</SectionTitle>
            <div className="border-2 border-ink bg-sheet">
              <div className="grid grid-cols-12 border-b-2 border-ink">
                {months.map((m, i) => (
                  <div key={i} className="label-caps border-l border-rule px-1 py-1.5 text-text-muted first:border-l-0">
                    {m}
                  </div>
                ))}
              </div>
              <div className="relative" style={{ height: `${Math.max(1, terms.length) * 38 + 18}px` }}>
                {terms.map((term, i) =>
                  term.starts_on && term.ends_on ? (
                    <div
                      key={i}
                      className="absolute flex h-[30px] items-center overflow-hidden bg-teal pl-1.5 text-[13px] font-extrabold whitespace-nowrap text-sheet"
                      style={{ top: `${14 + i * 38}px`, left: `${at(term.starts_on)}%`, width: `${Math.max(2, at(term.ends_on) - at(term.starts_on))}%` }}
                    >
                      {term.name}
                    </div>
                  ) : null,
                )}
                {Number.isFinite(yearSpan) && time(today) >= yearStart && time(today) <= time(endsOn) && (
                  <div aria-hidden="true" className="absolute top-0 bottom-0 w-[3px] bg-ink" style={{ left: `${at(today)}%` }} />
                )}
              </div>
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
          </Column>
        </Columns>
      </>
    ),
  })
}
