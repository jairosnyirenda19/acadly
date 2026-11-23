import { navigate, useLoad } from '@acadly/kernel'
import { Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { DivisionChoice, Sheet } from '../programmes/parts'
import { PageHead, Tag } from '../settings/parts'
import { resultsApi, useResultsDivision } from './api'
import { NoResultsDivision, PeriodChoice, ResultsTabs } from './parts'

const t = copy.results

const tone = (state: string) => (state === 'released' || state === 'approved' ? 'done' : 'attention')

/** Results (D-105): the semester's courses and where each result sheet stands. */
export function ResultsCoursesScreen() {
  const { division, choices, choose } = useResultsDivision()
  if (division === null) return <NoResultsDivision />

  return <Loaded key={division.id} division={division.id} choice={<DivisionChoice choices={choices} division={division} choose={choose} />} />
}

function Loaded({ division, choice }: { division: string; choice: React.ReactNode }) {
  const [period, setPeriod] = useState<string | null>(null)
  const loaded = useLoad(`results-courses:${division}:${period ?? ''}`, () => resultsApi.courses(division, period))
  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} rows={5} onRetry={loaded.reload} />
  const data = loaded.data

  return (
    <>
      <PageHead
        kicker={`${t.kicker} · ${data.period.name}`}
        title={t.title}
        intro={t.intro}
        actions={
          <div className="flex flex-wrap items-end gap-3">
            <PeriodChoice periods={data.periods} period={data.period.id} choose={setPeriod} />
            {choice}
          </div>
        }
      />
      <div className="grid gap-4 px-[clamp(16px,3vw,40px)] pb-8">
        <ResultsTabs current="courses" can={data.can} />
        <Sheet label={t.title}>
          {data.courses.length === 0 ? (
            <p className="px-4 py-4 text-text-muted">{t.none}</p>
          ) : (
            <ul>
              {data.courses.map((c) => (
                <li key={c.id} className="border-b border-rule last:border-b-0">
                  <a
                    href={`/results/c/${c.id}`}
                    onClick={(e) => (e.preventDefault(), navigate(`/results/c/${c.id}`))}
                    className="flex min-h-14 flex-wrap items-center justify-between gap-3 px-4 py-3 text-ink no-underline hover:bg-paper"
                  >
                    <span className="flex min-w-0 flex-col">
                      <strong>{`${c.code} · ${c.title}${c.group !== 'A' ? ` · ${c.group}` : ''}`}</strong>
                      <span className="text-[14px] text-text-muted">{[c.department, c.lecturers.join(', '), t.students(c.students)].filter((x) => x !== '').join(' · ')}</span>
                    </span>
                    <span className="flex flex-wrap gap-2">
                      {c.teaches && <Tag tone="plain">{t.yours}</Tag>}
                      <Tag tone={tone(c.main?.state ?? 'entered')}>{t.stateTags[c.main?.state ?? 'entered']}</Tag>
                      {c.supplementary !== null && <Tag tone={tone(c.supplementary.state)}>{t.supplementaryTag(t.states[c.supplementary.state] ?? c.supplementary.state)}</Tag>}
                    </span>
                  </a>
                </li>
              ))}
            </ul>
          )}
        </Sheet>
      </div>
    </>
  )
}
