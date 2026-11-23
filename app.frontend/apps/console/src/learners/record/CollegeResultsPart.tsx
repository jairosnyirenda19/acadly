import { useLoad } from '@acadly/kernel'
import { Button, Waiting } from '@acadly/ui'
import { copy } from '../../copy'
import { RESULT_TONE, resultsApi, slipHref } from '../../results/api'
import { Tag } from '../../settings/parts'
import type { LearnerState } from '../api'
import { Column, Columns, InLink, LedgerFrame, SectionTitle, tabHref, Td, Th } from './ui'

const t = copy.results
const r = t.record

function useResults(id: string) {
  return useLoad(`learner:college-results:${id}`, () => resultsApi.learner(id))
}

const titleOf = (learner: LearnerState) => learner.parts.find((x) => x.key === 'results')?.title ?? ''
const gpa = (g: number | null) => (g === null ? '—' : g.toFixed(2))

/** The overview's Results block: the cumulative GPA and any course to sit (D-105). */
export function CollegeResultsSummary({ learner }: { learner: LearnerState }) {
  const loaded = useResults(learner.id)
  const d = loaded.data

  return (
    <Column edge grow="flex-[1_1_260px]" label={titleOf(learner)}>
      <SectionTitle>{titleOf(learner)}</SectionTitle>
      {loaded.phase === 'failed' ? (
        <p className="text-sm text-text-muted">{r.none}</p>
      ) : d === undefined ? (
        <Waiting phase={loaded.phase} what={titleOf(learner)} labels={copy.loading} rows={2} onRetry={loaded.reload} />
      ) : d.semesters.length === 0 ? (
        <p className="text-sm text-text-muted">{r.none}</p>
      ) : (
        <>
          <p className="type-display text-[32px] leading-none">{gpa(d.cumulative)}</p>
          <p className="text-sm text-text-muted">{r.cumulativeGpa}</p>
          <p className="mt-1">{d.open === 0 ? <Tag tone="done">{r.clear}</Tag> : <Tag tone="attention">{r.toSit(d.open)}</Tag>}</p>
          <InLink href={tabHref(learner.id, 'results')}>{r.open}</InLink>
        </>
      )}
    </Column>
  )
}

/** The Results tab: each semester's courses, GPAs, decision and slip. */
export function CollegeResultsTab({ learner }: { learner: LearnerState }) {
  const loaded = useResults(learner.id)
  const d = loaded.data

  if (loaded.phase === 'failed') return <p className="text-sm text-text-muted">{r.none}</p>
  if (d === undefined) return <Waiting phase={loaded.phase} what={titleOf(learner)} labels={copy.loading} rows={6} onRetry={loaded.reload} />
  if (d.semesters.length === 0) return <p className="text-sm text-text-muted">{r.none}</p>

  return (
    <Columns top>
      <Column grow="flex-[2_1_620px]" label={titleOf(learner)}>
        {d.semesters.map((s) => {
          const released = s.courses.some((c) => c.released)
          const all = s.courses.reduce((n, c) => n + c.credits, 0)

          return (
            <section key={s.period.id} className="mb-6" aria-label={s.period.name}>
              <div className="mb-3 grid grid-cols-3 border-2 border-ink">
                {(
                  [
                    [r.semesterGpa, gpa(s.gpa)],
                    [r.cumulativeGpa, gpa(d.cumulative)],
                    [r.creditsPassed, r.creditsOf(s.earned, all)],
                  ] as const
                ).map(([label, value], i) => (
                  <div key={label} className={`px-3 py-2 ${i > 0 ? 'border-l-2 border-ink' : 'bg-ink text-sheet'}`}>
                    <span className={`label-caps ${i === 0 ? 'text-on-ink-muted' : 'text-text-muted'}`}>{label}</span>
                    <div className={`type-display text-[28px] leading-none ${i === 0 ? 'text-teal-bright' : ''}`}>{value}</div>
                  </div>
                ))}
              </div>
              <SectionTitle aside={<Tag tone={s.decision === 'pass' ? 'done' : 'attention'}>{t.board.decisions[s.decision] ?? s.decision}</Tag>}>{s.period.name}</SectionTitle>
              <LedgerFrame label={s.period.name}>
                <table className="w-full min-w-[640px] border-collapse">
                  <thead>
                    <tr>
                      <Th>{r.course}</Th>
                      <Th end>{r.credits}</Th>
                      <Th end>{r.coursework}</Th>
                      <Th end>{r.exam}</Th>
                      <Th end>{r.total}</Th>
                      <Th>{r.grade}</Th>
                      <Th end>{r.points}</Th>
                    </tr>
                  </thead>
                  <tbody>
                    {s.courses.map((c) => (
                      <tr key={c.offering_id}>
                        <Td>
                          <strong>{c.code}</strong>
                          {` · ${c.title}`}
                          {c.sitting === 'supplementary' && <span className="block text-[13px] text-text-muted">{r.supplementary}</span>}
                          {!c.released && <span className="block text-[13px] text-text-muted">{r.notReleased}</span>}
                          {c.changed && <span className="block text-[13px] text-text-muted">{t.sheet.changed}</span>}
                        </Td>
                        <Td end>{c.credits}</Td>
                        <Td end>{c.ca === null ? '—' : c.ca.toFixed(1)}</Td>
                        <Td end>{c.exam ?? '—'}</Td>
                        <Td end>{c.mark ?? '—'}</Td>
                        <Td>{c.result === 'pass' ? <strong>{c.grade}</strong> : <Tag tone={RESULT_TONE[c.result] ?? 'plain'}>{`${t.results[c.result] ?? c.result}${c.grade !== null ? ` · ${c.grade}` : ''}`}</Tag>}</Td>
                        <Td end>{c.points === null ? '—' : c.points.toFixed(1)}</Td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </LedgerFrame>
              {released && (
                <div className="mt-3">
                  <Button variant="secondary" onClick={() => window.open(slipHref(learner.id, s.period.id), '_blank', 'noopener')}>
                    {r.slip}
                  </Button>
                </div>
              )}
            </section>
          )
        })}
        <p className="text-sm text-text-muted">{r.note}</p>
      </Column>
    </Columns>
  )
}
