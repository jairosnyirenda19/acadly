import { useLoad } from '@acadly/kernel'
import { Waiting } from '@acadly/ui'
import { useState } from 'react'
import { assessmentApi, markText, summaryText, type LearnerResultsState } from '../../assessment/api'
import { pageUrl, type Page } from '../../assessment/papers'
import { Working } from '../../assessment/parts'
import { copy } from '../../copy'
import { Tag } from '../../settings/parts'
import type { LearnerState } from '../api'
import { Caption, Column, Columns, InLink, SectionTitle, tabHref } from './ui'

const a = copy.assessment
const t = a.learner

/** The Results part, loaded once and shared by the overview and the tab. */
function useResults(id: string) {
  return useLoad(`learner:results:${id}`, () => assessmentApi.learner(id))
}

const titleOf = (learner: LearnerState) => learner.parts.find((p) => p.key === 'assessment')?.title ?? ''

function GradeTag({ grade, passes }: { grade: string; passes: boolean | null }) {
  return <Tag tone={passes === false ? 'wrong' : 'done'}>{grade}</Tag>
}

function Summary({ data }: { data: LearnerResultsState }) {
  if (data.summary.value === null) return null

  return (
    <p className="mt-2 text-sm font-bold">
      {data.position !== null && `${t.position} ${t.positionOf(data.position.position, data.position.of, data.position.shared)}. `}
      {t.summary(a.results.summary[data.summary.kind] ?? '', summaryText(data.summary.kind, data.summary.value), data.summary.of)}
    </p>
  )
}

/** Position, total marks and the overall result, as three ruled cells. */
function Standing({ data }: { data: LearnerResultsState }) {
  const cells = [
    { label: t.position, value: data.position !== null ? t.positionOf(data.position.position, data.position.of, data.position.shared) : copy.dash, note: data.position !== null ? t.positionNote : t.notRanked },
    { label: t.total, value: data.total !== null && data.total.total > 0 ? a.num(data.total.total) : copy.dash, note: t.totalNote(data.total?.out_of ?? 0, data.grades.length) },
    {
      label: a.results.summary[data.summary.kind] ?? '',
      value: data.summary.value !== null ? summaryText(data.summary.kind, data.summary.value) : copy.dash,
      note: t.overallNote[data.summary.kind] ?? '',
    },
  ]

  return (
    <div className="mb-4 grid grid-cols-1 border-2 border-ink sm:grid-cols-3">
      {cells.map((c, i) => (
        <section key={c.label} className={`min-w-0 px-3.5 pt-2.5 pb-3 ${i === 0 ? 'bg-ink text-sheet' : 'border-ink max-sm:border-t-2 sm:border-l-2'}`}>
          <h3 className={`label-caps ${i === 0 ? 'text-on-ink-muted' : 'text-text-muted'}`}>{c.label}</h3>
          <div className={`type-numeral text-[clamp(36px,4vw,48px)] whitespace-nowrap ${i === 0 ? 'text-teal-bright' : ''}`}>{c.value}</div>
          <p className={`text-[13px] ${i === 0 ? 'text-on-ink-soft' : 'text-text-muted'}`}>{c.note}</p>
        </section>
      ))}
    </div>
  )
}

/** The overview's Results block: each subject's term grade so far. */
export function ResultsSummary({ learner }: { learner: LearnerState }) {
  const loaded = useResults(learner.id)
  const data = loaded.data

  return (
    <Column edge grow="flex-[1_1_300px]" label={titleOf(learner)}>
      <SectionTitle>{titleOf(learner)}</SectionTitle>
      {data === undefined ? (
        <Waiting phase={loaded.phase} what={titleOf(learner)} labels={copy.loading} rows={3} onRetry={loaded.reload} />
      ) : data.period === null ? (
        <p className="text-sm text-text-muted">{t.noPeriod}</p>
      ) : (
        <>
          <Caption>{t.termResults(data.period.name)}</Caption>
          {data.grades.length === 0 && <p className="text-sm text-text-muted">{t.noGrades}</p>}
          <ul>
            {data.grades.map((g) => (
              <li key={g.subject_id} className="flex min-h-10 flex-wrap items-center gap-x-2 gap-y-0.5 border-b border-rule py-1.5 text-sm">
                <span className="min-w-0 flex-1 font-bold break-words">{g.subject}</span>
                <span className="ref text-[13px]">{g.ready ? a.pct(g.percent) : copy.dash}</span>
                {g.ready && g.grade !== null ? <GradeTag grade={g.grade} passes={g.passes} /> : <span className="basis-full text-[13px] text-text-muted">{g.not_ready}</span>}
              </li>
            ))}
          </ul>
          <Summary data={data} />
          <InLink href={tabHref(learner.id, 'assessment')}>{t.seeAll}</InLink>
        </>
      )}
    </Column>
  )
}

/** The Results tab (the approved design): term grades with their working, and this term's assessments. */
export function ResultsTab({ learner }: { learner: LearnerState }) {
  const loaded = useResults(learner.id)
  const data = loaded.data
  const [open, setOpen] = useState<string | null>(null)
  const [working, setWorking] = useState<string | null>(null)
  const [paper, setPaper] = useState<{ id: string; page: number } | null>(null)

  if (data === undefined) return <Waiting phase={loaded.phase} what={titleOf(learner)} labels={copy.loading} rows={6} onRetry={loaded.reload} />

  if (data.period === null || data.class === null) {
    return (
      <Columns top>
        <Column>
          <p className="text-sm text-text-muted">{t.noPeriod}</p>
        </Column>
      </Columns>
    )
  }

  const period = data.period
  const klass = data.class
  const shown = paper !== null ? data.assessments.find((x) => x.id === paper.id) : undefined

  return (
    <Columns top>
      <Column grow="flex-[2_1_520px]" label={t.soFar(period.name)}>
        <SectionTitle>{t.soFar(period.name)}</SectionTitle>
        <Standing data={data} />
        <p className="mb-3 text-[13px] text-text-muted">{t.soFarNote}</p>
        {data.grades.length === 0 && <p className="text-sm text-text-muted">{t.noGrades}</p>}
        <ul>
          {data.grades.map((g) => {
            const items = data.assessments.filter((x) => x.subject_id === g.subject_id)
            const marked = items.filter((x) => x.locked && (x.value !== null || x.reason !== null)).length
            const isOpen = open === g.subject_id

            return (
              <li key={g.subject_id} className="border-b border-rule">
                <button
                  type="button"
                  aria-expanded={isOpen}
                  onClick={() => setOpen(isOpen ? null : g.subject_id)}
                  className="grid min-h-12 w-full grid-cols-[minmax(0,1fr)_auto_24px] items-center gap-x-3 py-2 text-left md:grid-cols-[minmax(0,1.2fr)_150px_minmax(0,1fr)_24px]"
                >
                  <span className="text-sm font-extrabold">{g.subject}</span>
                  <span className="flex items-center gap-2 text-[13px]">
                    {g.ready && g.grade !== null ? (
                      <>
                        <span className="ref">{a.pct(g.percent)}</span>
                        <GradeTag grade={g.grade} passes={g.passes} />
                      </>
                    ) : (
                      t.marksSoFar(marked)
                    )}
                  </span>
                  <span className="hidden text-[13px] text-text-muted md:block">{g.ready ? g.word : items.length === 0 ? t.noAssessment : g.not_ready}</span>
                  <span aria-hidden="true" className="text-lg font-extrabold">
                    {isOpen ? '–' : '+'}
                  </span>
                </button>
                {isOpen && (
                  <div className="mb-2.5">
                    {items.length === 0 && <p className="pb-2 pl-3.5 text-[13px] text-text-muted">{t.noAssessment}</p>}
                    {items.map((x) => (
                      <div key={x.id} className="grid grid-cols-[56px_minmax(0,1fr)] gap-x-3 gap-y-1 border-t border-dashed border-rule py-2 pl-3.5 text-sm md:grid-cols-[64px_minmax(0,1fr)_150px_120px]">
                        <span className="ref text-xs text-text-muted">{a.day(x.held_on)}</span>
                        <span>
                          {a.pair(x.kind_label, x.title)}
                          {!x.counts && (
                            <>
                              {' '}
                              <Tag tone="plain">{a.practice}</Tag>
                            </>
                          )}
                        </span>
                        <strong className="ref text-[13px] max-md:col-start-2">{!x.locked ? t.notLocked : x.value !== null ? t.mark(markText(x.value), x.out_of, a.pct(x.percent)) : (x.reason ?? '')}</strong>
                        <span className="max-md:col-start-2">
                          {x.paper.length > 0 ? (
                            <button type="button" aria-pressed={paper?.id === x.id} onClick={() => setPaper({ id: x.id, page: 0 })} className="min-h-9 text-[13px] font-extrabold underline underline-offset-[3px]">
                              {t.thePaper}
                            </button>
                          ) : (
                            x.locked && <span className="text-xs text-text-muted">{t.noPaper}</span>
                          )}
                        </span>
                      </div>
                    ))}
                    {g.ready && (
                      <button type="button" aria-expanded={working === g.subject_id} onClick={() => setWorking(working === g.subject_id ? null : g.subject_id)} className="ml-3.5 min-h-11 text-sm font-extrabold underline underline-offset-[3px]">
                        {t.seeFor(g.subject)}
                      </button>
                    )}
                    {working === g.subject_id && (
                      <div className="mt-1 border-2 border-ink bg-paper p-3.5">
                        <LearnerWorking classId={klass.id} periodId={period.id} learnerId={learner.id} subjectId={g.subject_id} onClose={() => setWorking(null)} />
                      </div>
                    )}
                  </div>
                )}
              </li>
            )
          })}
        </ul>
      </Column>
      <Column edge tinted label={shown !== undefined ? t.thePaper : t.assessments}>
        {shown !== undefined && paper !== null ? (
          <PaperViewer title={a.pair(shown.subject, shown.title)} mark={shown.value !== null ? t.mark(markText(shown.value), shown.out_of, a.pct(shown.percent)) : ''} pages={shown.paper} page={paper.page} onPage={(n) => setPaper({ id: shown.id, page: n })} onClose={() => setPaper(null)} />
        ) : (
          <>
            <SectionTitle>{t.assessments}</SectionTitle>
            {data.assessments.length === 0 && <p className="text-sm text-text-muted">{t.noAssessments}</p>}
            <ul>
              {data.assessments.map((x) => (
                <li key={x.id} className="grid grid-cols-[56px_1fr] gap-2 border-b border-rule py-2 text-sm">
                  <span className="ref text-xs text-text-muted">{a.day(x.held_on)}</span>
                  <span>
                    <strong>{a.pair(x.subject, x.title)}</strong>
                    {a.joiner}
                    {!x.locked ? t.notLocked : x.value !== null ? t.mark(markText(x.value), x.out_of, a.pct(x.percent)) : (x.reason ?? '')}
                    {x.paper.length > 0 && (
                      <>
                        {' · '}
                        <button type="button" onClick={() => setPaper({ id: x.id, page: 0 })} className="font-extrabold underline underline-offset-[3px]">
                          {t.thePaper}
                        </button>
                      </>
                    )}
                  </span>
                </li>
              ))}
            </ul>
          </>
        )}
      </Column>
    </Columns>
  )
}

/** One marked paper, a page at a time (the approved design). The server decides who may open it. */
function PaperViewer({ title, mark, pages, page, onPage, onClose }: { title: string; mark: string; pages: Page[]; page: number; onPage: (n: number) => void; onClose: () => void }) {
  const current = pages[Math.min(page, pages.length - 1)]

  if (current === undefined) return null

  return (
    <div className="border-2 border-ink bg-sheet">
      <div className="flex flex-wrap items-baseline gap-2 border-b-2 border-ink px-3.5 py-2.5">
        <span className="min-w-0 flex-1 text-[15px] font-extrabold">
          {title} · {mark}
        </span>
        <span className="text-[13px]">{t.pageOf(current.position, pages.length)}</span>
      </div>
      <div className="m-3 border border-rule bg-paper">
        {current.type.startsWith('image/') ? (
          <img src={pageUrl(current.document_id)} alt={t.pageAlt(current.position, title)} className="mx-auto max-h-[70vh] w-full object-contain" />
        ) : (
          <iframe src={pageUrl(current.document_id)} title={t.pageAlt(current.position, title)} className="h-[70vh] w-full" />
        )}
      </div>
      <div className="flex flex-wrap gap-2 px-3 pb-3">
        <button type="button" disabled={page === 0} onClick={() => onPage(page - 1)} className="h-11 border-2 border-ink px-3 text-sm font-extrabold disabled:opacity-40">
          ‹ {t.page}
        </button>
        <button type="button" disabled={page >= pages.length - 1} onClick={() => onPage(page + 1)} className="h-11 border-2 border-ink px-3 text-sm font-extrabold disabled:opacity-40">
          {t.page} ›
        </button>
        <span className="flex-1" />
        <a href={`/api/v1/documents/${current.document_id}/content`} className="inline-flex h-11 items-center border-2 border-ink px-3 text-sm font-extrabold text-ink no-underline">
          {t.download}
        </a>
        <button type="button" onClick={onClose} className="h-11 px-2 text-sm font-extrabold underline underline-offset-[3px]">
          {t.closePaper}
        </button>
      </div>
    </div>
  )
}

function LearnerWorking({ classId, periodId, learnerId, subjectId, onClose }: { classId: string; periodId: string; learnerId: string; subjectId: string; onClose: () => void }) {
  const loaded = useLoad(`assessment:working:${classId}:${periodId}:${learnerId}:${subjectId}`, () =>
    assessmentApi.working({ class_id: classId, learner_id: learnerId, subject_id: subjectId, period_id: periodId }),
  )

  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={a.working.title} labels={copy.loading} rows={3} onRetry={loaded.reload} />

  return <Working data={loaded.data} onClose={onClose} />
}
