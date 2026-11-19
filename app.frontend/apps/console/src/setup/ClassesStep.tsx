import { Notice } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { errorFor, setupApi, type AcademicsState } from './api'
import { Column, Columns, SectionTitle, StepFooter, Total, TotalsBand, nn } from './parts'
import type { StepProps } from './types'

const t = copy.setup.steps.classes

type Offering = AcademicsState['offerings'][number]
type Streams = Record<string, string[]>

const keyOf = (o: Offering) => `${o.division_id}:${o.campus_id}`
const fromServer = (o: Offering): Streams => Object.fromEntries(o.levels.map((level) => [level.code, [...level.streams]]))
const countOf = (o: Offering, streams: Streams) => o.levels.reduce((sum, level) => sum + Math.max(1, streams[level.code]?.length ?? 0), 0)

/** Levels with no stream where others in the same tab have streams: probably forgotten. */
const unstreamed = (o: Offering, streams: Streams) =>
  o.levels.some((level) => (streams[level.code]?.length ?? 0) > 0) ? o.levels.filter((level) => (streams[level.code]?.length ?? 0) === 0) : []

export function ClassesStep({ academics, onAcademics, layout, next }: StepProps) {
  const [tab, setTab] = useState(() => academics.offerings[0] !== undefined ? keyOf(academics.offerings[0]) : '')
  const [drafts, setDrafts] = useState<Record<string, Streams>>(() => Object.fromEntries(academics.offerings.map((o) => [keyOf(o), fromServer(o)])))
  const [adding, setAdding] = useState<Record<string, string>>({})
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [failed, setFailed] = useState(false)
  const [working, setWorking] = useState(false)

  const offering = academics.offerings.find((o) => keyOf(o) === tab) ?? academics.offerings[0]
  const streams = offering !== undefined ? (drafts[keyOf(offering)] ?? fromServer(offering)) : {}
  const totals = academics.offerings.map((o) => countOf(o, drafts[keyOf(o)] ?? fromServer(o)))
  const attention = academics.offerings.flatMap((o) => unstreamed(o, drafts[keyOf(o)] ?? fromServer(o)).map((level) => ({ level: level.label, campus: o.campus })))

  const setStreams = (code: string, names: string[]) => {
    if (offering === undefined) return
    const key = keyOf(offering)
    setDrafts((current) => ({ ...current, [key]: { ...(current[key] ?? fromServer(offering)), [code]: names } }))
  }

  const addStream = (code: string) => {
    const name = (adding[code] ?? '').trim()

    if (name !== '' && !(streams[code] ?? []).some((s) => s.toLowerCase() === name.toLowerCase())) {
      setStreams(code, [...(streams[code] ?? []), name])
    }

    setAdding((current) => ({ ...current, [code]: '' }))
  }

  const save = async () => {
    if (offering === undefined) return

    setWorking(true)
    setFailed(false)
    const result = await setupApi.saveClasses(offering.division_id, offering.campus_id, streams)
    setWorking(false)

    if (result.kind === 'saved') {
      setErrors({})
      onAcademics(result.data)
      const waiting = result.data.offerings.find((o) => o.classes === 0)

      if (waiting !== undefined) setTab(keyOf(waiting))
      else next()
    } else if (result.kind === 'invalid') {
      setErrors(result.errors)
    } else {
      setFailed(true)
    }
  }

  if (academics.year === null || offering === undefined) {
    return layout({
      title: t.title,
      intro: t.intro,
      footer: <StepFooter step="classes" primary={copy.setup.continue} onPrimary={next} working={false} blocked={t.yearFirst} />,
      children: (
        <div className="px-[clamp(16px,3vw,40px)] pb-8">
          <Notice tone="wrong" title={copy.setup.fixTitle}>
            {t.yearFirst}
          </Notice>
        </div>
      ),
    })
  }

  return layout({
    title: t.title,
    intro: t.intro,
    footer: <StepFooter step="classes" primary={copy.setup.continue} onPrimary={() => void save()} working={working} />,
    children: (
      <>
        <TotalsBand>
          <Total tone="ink" label={t.bandAll} value={String(totals.reduce((a, b) => a + b, 0))} note={t.bandAllNote(academics.offerings.length)} />
          {academics.offerings.slice(0, 3).map((o, i) => (
            <Total key={keyOf(o)} label={`${o.division} · ${o.campus}`} value={String(totals[i] ?? 0)} note={`${o.levels.length} ${o.level_word.toLowerCase()}s`} />
          ))}
          {attention[0] !== undefined && <Total tone="attention" label={t.bandAttention} value={String(attention.length)} note={t.bandAttentionNote(attention[0].level, attention[0].campus)} />}
        </TotalsBand>
        <Columns>
          <Column wide sheet>
            <nav aria-label={t.title} className="-mt-6 mb-1 flex flex-wrap gap-1 border-b-2 border-ink">
              {academics.offerings.map((o, i) => {
                const current = keyOf(o) === keyOf(offering)

                return (
                  <button
                    key={keyOf(o)}
                    type="button"
                    aria-current={current ? 'page' : undefined}
                    onClick={() => setTab(keyOf(o))}
                    className={`border-b-4 px-4 pt-3 pb-2.5 text-[15px] ${current ? 'border-teal font-extrabold' : 'border-transparent font-medium'}`}
                  >
                    {o.division} · {o.campus} <span className="ref text-xs text-text-muted">{totals[i]}</span>
                  </button>
                )
              })}
            </nav>
            {(failed || Object.keys(errors).length > 0) && (
              <div className="my-4">
                <Notice tone="wrong" title={copy.setup.fixTitle}>
                  {failed ? copy.setup.loadFailed : (Object.values(errors)[0]?.[0] ?? copy.setup.fix)}
                </Notice>
              </div>
            )}
            <div className="label-caps grid grid-cols-[52px_minmax(120px,170px)_1fr_96px] gap-3 border-b-2 border-ink pt-2.5 pr-4 pb-2 text-text-muted">
              <span />
              <span>{offering.level_word}</span>
              <span>{t.streams}</span>
              <span className="text-right">{t.total}</span>
            </div>
            <div className="ledger [--margin:52px]">
              {offering.levels.map((level, i) => {
                const names = streams[level.code] ?? []
                const flagged = unstreamed(offering, streams).some((l) => l.code === level.code)
                const problem = errorFor(errors, `streams.${level.code}`)

                return (
                  <div key={level.code} className={`grid grid-cols-[52px_minmax(120px,170px)_1fr_96px] items-center gap-3 border-b border-rule py-3.5 pr-4 ${flagged ? 'bg-marigold-wash' : ''}`}>
                    <div className="type-display text-center text-xl text-text-muted">{nn(i + 1)}</div>
                    <div>
                      <div className="type-display text-[22px] leading-none">{level.label}</div>
                      {flagged && (
                        <div className="label-caps mt-1">
                          <span className="bg-marigold px-1.5 py-0.5">{t.noStreams}</span>
                        </div>
                      )}
                    </div>
                    <div className="flex flex-wrap items-center gap-1.5">
                      {names.map((name) => (
                        <span key={name} className="inline-flex h-8 items-center gap-1.5 border-2 border-ink bg-sheet px-2.5 text-sm font-extrabold">
                          {name}
                          <button type="button" aria-label={t.removeStream(name)} onClick={() => setStreams(level.code, names.filter((n) => n !== name))} className="font-semibold text-text-muted hover:text-ink">
                            ×
                          </button>
                        </span>
                      ))}
                      <input
                        aria-label={`${level.label} · ${t.streamPlaceholder}`}
                        placeholder={t.streamPlaceholder}
                        value={adding[level.code] ?? ''}
                        maxLength={30}
                        onChange={(e) => {
                          const value = e.target.value
                          setAdding((current) => ({ ...current, [level.code]: value }))
                        }}
                        onKeyDown={(e) => {
                          if (e.key === 'Enter') {
                            e.preventDefault()
                            addStream(level.code)
                          }
                        }}
                        className="h-8 w-32 border-0 border-b-2 border-ink bg-field px-2 text-sm outline-none focus:border-teal"
                      />
                      <button type="button" onClick={() => addStream(level.code)} className="h-8 border-2 border-dashed border-rule-dashed px-2.5 text-sm font-bold hover:bg-field">
                        {t.addStream}
                      </button>
                      {problem !== undefined && <span className="w-full text-[13px] font-bold text-red-text">{problem}</span>}
                    </div>
                    <div className="type-numeral text-right text-[40px]">
                      {Math.max(1, names.length)}
                      <span className="label-caps block text-text-muted">{t.classes}</span>
                    </div>
                  </div>
                )
              })}
            </div>
            <p className="mt-3.5 text-[13px] text-text-muted">{t.ladderNote(offering.division)}</p>
          </Column>

          <Column edge>
            <SectionTitle>{t.tabClasses}</SectionTitle>
            <div className="grid grid-cols-4 gap-1">
              {offering.levels.flatMap((level) => {
                const names = streams[level.code] ?? []

                return (names.length > 0 ? names : [null]).map((stream) => (
                  <div key={`${level.code}:${stream ?? ''}`} className="flex min-h-16 min-w-0 flex-col justify-center bg-ink p-2 text-sheet">
                    <span className="type-display text-[17px] leading-[0.95] break-words">{level.label}</span>
                    {stream !== null && <span className="text-xs text-on-ink-soft">{stream}</span>}
                  </div>
                ))
              })}
            </div>
            <div className="my-6 h-0.5 bg-ink" />
            <div className="label-caps mb-2 text-text-muted">{t.teachersSection}</div>
            <p className="text-sm leading-normal">{t.teachersNote}</p>
          </Column>
        </Columns>
      </>
    ),
  })
}
