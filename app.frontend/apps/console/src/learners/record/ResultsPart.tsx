import { useLoad } from '@acadly/kernel'
import { Waiting } from '@acadly/ui'
import { useState } from 'react'
import { assessmentApi, markText, summaryText, type LearnerResultsState } from '../../assessment/api'
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

  return (
    <Columns top>
      <Column grow="flex-[1.4_1_380px]" label={t.termResults(period.name)}>
        <SectionTitle>{t.termResults(period.name)}</SectionTitle>
        <Standing data={data} />
        <p className="mb-3 text-[13px] text-text-muted">{t.note}</p>
        {data.grades.length === 0 && <p className="text-sm text-text-muted">{t.noGrades}</p>}
        <ul>
          {data.grades.map((g) => (
            <li key={g.subject_id} className="border-b border-rule">
              <div className="grid min-h-12 grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 py-2 md:grid-cols-[minmax(0,1.4fr)_72px_56px_minmax(0,1fr)_auto]">
                <span className="text-sm font-extrabold">{g.subject}</span>
                <span className="ref text-right text-[13px] md:text-left">{g.ready ? a.pct(g.percent) : copy.dash}</span>
                <span>{g.ready && g.grade !== null ? <GradeTag grade={g.grade} passes={g.passes} /> : null}</span>
                <span className="text-[13px] text-text-muted">{g.ready ? g.word : g.not_ready}</span>
                <button
                  type="button"
                  aria-expanded={open === g.subject_id}
                  aria-label={t.seeFor(g.subject)}
                  onClick={() => setOpen(open === g.subject_id ? null : g.subject_id)}
                  className="min-h-11 text-left text-sm font-extrabold underline underline-offset-[3px]"
                >
                  {t.see}
                </button>
              </div>
              {open === g.subject_id && (
                <div className="mb-3 border-2 border-ink bg-paper p-3.5">
                  <LearnerWorking classId={klass.id} periodId={period.id} learnerId={learner.id} subjectId={g.subject_id} onClose={() => setOpen(null)} />
                </div>
              )}
            </li>
          ))}
        </ul>
      </Column>
      <Column edge tinted label={t.assessments}>
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
                {!x.counts && (
                  <>
                    {' '}
                    <Tag tone="plain">{a.practice}</Tag>
                  </>
                )}
              </span>
            </li>
          ))}
        </ul>
      </Column>
    </Columns>
  )
}

function LearnerWorking({ classId, periodId, learnerId, subjectId, onClose }: { classId: string; periodId: string; learnerId: string; subjectId: string; onClose: () => void }) {
  const loaded = useLoad(`assessment:working:${classId}:${periodId}:${learnerId}:${subjectId}`, () =>
    assessmentApi.working({ class_id: classId, learner_id: learnerId, subject_id: subjectId, period_id: periodId }),
  )

  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={a.working.title} labels={copy.loading} rows={3} onRetry={loaded.reload} />

  return <Working data={loaded.data} onClose={onClose} />
}
