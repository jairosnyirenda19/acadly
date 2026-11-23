import { navigate, useLoad } from '@acadly/kernel'
import { Button, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { firstError } from '../programmes/api'
import { Attention, Sheet } from '../programmes/parts'
import { PageHead, Tag } from '../settings/parts'
import { day, RESULT_TONE, resultsApi, useResultsDivision } from './api'
import { Figures, Ladder, NoResultsDivision, ResultsTabs, WithReason } from './parts'

const t = copy.results

const th = 'label-caps border-b-2 border-ink px-3 py-2 text-left text-text-muted'
const td = 'border-b border-rule px-3 py-2 align-middle'

/** The attendance bar (D-105): every student under it, course by course, and the bars lifted. */
export function BarScreen() {
  const { division } = useResultsDivision()
  if (division === null) return <NoResultsDivision />

  return <Bar key={division.id} division={division.id} />
}

function Bar({ division }: { division: string }) {
  const b = t.bar
  const loaded = useLoad(`results-bar:${division}`, () => resultsApi.bar(division, null))
  const courses = useLoad(`results-courses:${division}:`, () => resultsApi.courses(division, null))
  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={b.title} labels={copy.loading} rows={6} onRetry={loaded.reload} />
  const data = loaded.data
  const lifted = data.rows.filter((r) => r.lifted !== null).length

  return (
    <>
      <PageHead kicker={`${t.kicker} · ${data.period.name}`} title={b.title} intro={b.lede(data.bar_percent, day(data.checked_on))} />
      <div className="grid gap-4 px-[clamp(16px,3vw,40px)] pb-8">
        {courses.data !== undefined && <ResultsTabs current="bar" can={courses.data.can} />}
        <Figures
          items={[
            { label: b.under, value: String(data.rows.length) },
            { label: b.barred, value: String(data.rows.length - lifted), tone: data.rows.length - lifted > 0 ? 'wrong' : 'sheet' },
            { label: b.lifted, value: String(lifted) },
            { label: b.close, value: String(data.close), tone: data.close > 0 ? 'attention' : 'sheet' },
          ]}
        />
        <Sheet label={b.title} className="overflow-x-auto">
          {data.rows.length === 0 ? (
            <p className="px-4 py-4 text-text-muted">{b.none}</p>
          ) : (
            <table className="w-full min-w-[760px] border-collapse">
              <thead>
                <tr>
                  <th className={th}>{t.sheet.student}</th>
                  <th className={th}>{t.sheet.number}</th>
                  <th className={th}>{b.course}</th>
                  <th className={th}>{b.attended}</th>
                  <th className={th}>{b.state}</th>
                </tr>
              </thead>
              <tbody>
                {data.rows.map((r) => (
                  <tr key={`${r.offering_id}${r.learner_id}`}>
                    <td className={td}>
                      <strong>{r.name}</strong>
                    </td>
                    <td className={`${td} font-mono text-[14px]`}>{r.number}</td>
                    <td className={td}>{r.code}</td>
                    <td className={`${td} tabular-nums`}>{b.attendedOf(r.attended, r.held, r.rate)}</td>
                    <td className={td}>
                      {r.lifted !== null ? (
                        <>
                          <Tag tone="done">{b.liftedTag}</Tag>
                          <span className="block text-[13px] text-text-muted">{r.lifted}</span>
                        </>
                      ) : (
                        <div className="flex flex-wrap items-center gap-3">
                          <Tag tone="wrong">{b.barredTag}</Tag>
                          {data.can_lift && (
                            <WithReason
                              label={b.lift}
                              prompt={b.liftReason}
                              onConfirm={async (reason) => {
                                const result = await resultsApi.liftBar(division, r.offering_id, r.learner_id, reason)
                                if (result.kind === 'saved') {
                                  slips.done({ word: copy.slips.saved, title: b.liftedTitle })
                                  loaded.reload()

                                  return true
                                }
                                slips.wrong({ word: t.notSaved, title: firstError(result, t.failed) })

                                return false
                              }}
                            />
                          )}
                        </div>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Sheet>
        <p className="text-[14px] text-text-muted">{b.note}</p>
      </div>
    </>
  )
}

/** The examiners' board (D-105): each programme and year of the semester. */
export function BoardScreen() {
  const { division } = useResultsDivision()
  if (division === null) return <NoResultsDivision />

  return <Board key={division.id} division={division.id} />
}

function Board({ division }: { division: string }) {
  const b = t.board
  const loaded = useLoad(`results-board:${division}`, () => resultsApi.board(division, null))
  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={b.title} labels={copy.loading} rows={5} onRetry={loaded.reload} />
  const data = loaded.data

  return (
    <>
      <PageHead kicker={`${b.title} · ${data.period.name}`} title={b.title} intro={b.lede} />
      <div className="grid gap-4 px-[clamp(16px,3vw,40px)] pb-8">
        <ResultsTabs current="board" can={data.can} />
        <Sheet label={b.title}>
          {data.cohorts.length === 0 ? (
            <p className="px-4 py-4 text-text-muted">{b.none}</p>
          ) : (
            <ul>
              {data.cohorts.map((c) => {
                const href = `/results/board/cohort?c=${encodeURIComponent(c.id)}`
                const waiting = c.states['moderated'] ?? 0
                const done = (c.states['approved'] ?? 0) + (c.states['released'] ?? 0)

                return (
                  <li key={c.id} className="border-b border-rule last:border-b-0">
                    <a href={href} onClick={(e) => (e.preventDefault(), navigate(href))} className="flex min-h-14 flex-wrap items-center justify-between gap-3 px-4 py-3 text-ink no-underline hover:bg-paper">
                      <span className="flex flex-col">
                        <strong>{c.label}</strong>
                        <span className="text-[14px] text-text-muted">{b.courses(c.courses)}</span>
                      </span>
                      <span className="flex flex-wrap gap-2">
                        {waiting > 0 && <Tag tone="attention">{b.waiting(waiting)}</Tag>}
                        {done === c.courses && <Tag tone="done">{t.stateTags['approved']}</Tag>}
                      </span>
                    </a>
                  </li>
                )
              })}
            </ul>
          )}
        </Sheet>
      </div>
    </>
  )
}

/** One programme and year at the board: every student's grades, GPA and decision. */
export function CohortScreen({ cohort }: { cohort: string }) {
  const { division } = useResultsDivision()
  if (division === null) return <NoResultsDivision />

  return <CohortView key={`${division.id}${cohort}`} division={division.id} cohort={cohort} />
}

function CohortView({ division, cohort }: { division: string; cohort: string }) {
  const b = t.board
  const loaded = useLoad(`results-cohort:${division}:${cohort}`, () => resultsApi.cohort(division, null, cohort))
  const [busy, setBusy] = useState(false)
  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={b.title} labels={copy.loading} rows={8} onRetry={loaded.reload} />
  const data = loaded.data
  const notModerated = data.courses.filter((c) => c.state === 'entered' || c.state === 'submitted')
  const furthest = data.courses.every((c) => c.state === 'released') ? 'released' : data.courses.every((c) => c.state === 'approved' || c.state === 'released') ? 'approved' : 'moderated'

  return (
    <>
      <PageHead
        kicker={`${b.title} · ${data.period.name}`}
        title={data.cohort.label}
        intro={b.cohortLede(data.summary.students)}
        actions={
          data.can.board && data.waiting > 0 ? (
            <Button
              busy={busy}
              disabled={notModerated.length > 0}
              onClick={async () => {
                setBusy(true)
                const result = await resultsApi.approve(division, data.period.id, data.cohort.id)
                setBusy(false)
                if (result.kind === 'saved') {
                  slips.done({ word: copy.slips.saved, title: b.approved((result.data as { approved: number }).approved) })
                  loaded.reload()
                } else slips.wrong({ word: t.notSaved, title: firstError(result, t.failed) })
              }}
            >
              {b.approve}
            </Button>
          ) : undefined
        }
      />
      <div className="grid gap-4 px-[clamp(16px,3vw,40px)] pb-8">
        <Ladder state={furthest} />
        <Figures
          items={[
            { label: b.students, value: String(data.summary.students) },
            { label: b.pass, value: String(data.summary.passed) },
            { label: b.supplementary, value: String(data.summary.supplementary), tone: data.summary.supplementary > 0 ? 'attention' : 'sheet' },
            { label: b.deferred, value: String(data.summary.deferred) },
          ]}
        />
        {notModerated.length > 0 && data.waiting > 0 && (
          <Attention>
            <b>{b.notModerated(notModerated.map((c) => c.code).join(', '))}</b> {b.approveNeeds}
          </Attention>
        )}
        <Sheet label={data.cohort.label} className="overflow-x-auto">
          <table className="w-full min-w-[820px] border-collapse">
            <thead>
              <tr>
                <th className={th}>{t.sheet.student}</th>
                <th className={th}>{t.sheet.number}</th>
                {data.courses.map((c) => (
                  <th key={c.offering_id} className={th}>
                    {c.code}
                  </th>
                ))}
                <th className={`${th} text-right`}>{b.gpa}</th>
                <th className={th}>{b.decision}</th>
              </tr>
            </thead>
            <tbody>
              {data.students.map((st) => (
                <tr key={st.learner_id}>
                  <td className={td}>
                    <strong>{st.name}</strong>
                  </td>
                  <td className={`${td} font-mono text-[14px]`}>{st.number}</td>
                  {data.courses.map((c) => {
                    const r = st.courses[c.offering_id]
                    if (r === undefined) return <td key={c.offering_id} className={td} />
                    const word = r.result === 'pass' ? (r.grade ?? '') : r.result === 'barred' ? `× ${r.grade ?? 'F'} · ${t.bar.barred}` : `${t.results[r.result] ?? r.result}${r.mark !== null ? ` ${r.mark}` : ''}`

                    return (
                      <td key={c.offering_id} className={td}>
                        {r.result === 'pass' ? <strong>{word}</strong> : <Tag tone={RESULT_TONE[r.result] ?? 'plain'}>{word}</Tag>}
                      </td>
                    )
                  })}
                  <td className={`${td} text-right font-extrabold tabular-nums`}>{st.gpa === null ? '—' : st.gpa.toFixed(2)}</td>
                  <td className={td}>
                    <Tag tone={st.decision === 'pass' ? 'done' : st.decision === 'repeat' ? 'wrong' : 'attention'}>{b.decisions[st.decision] ?? st.decision}</Tag>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </Sheet>
        <p className="text-[14px] text-text-muted">{b.gpaNote}</p>
        <Sheet label={b.coursesTitle}>
          <ul>
            {data.courses.map((c) => (
              <li key={c.offering_id} className="border-b border-rule last:border-b-0">
                <a
                  href={`/results/c/${c.offering_id}`}
                  onClick={(e) => (e.preventDefault(), navigate(`/results/c/${c.offering_id}`))}
                  className="flex min-h-12 flex-wrap items-center justify-between gap-3 px-4 py-2 text-ink no-underline hover:bg-paper"
                >
                  <span>{`${c.code} · ${c.title}`}</span>
                  <Tag tone={c.state === 'approved' || c.state === 'released' ? 'done' : 'attention'}>{t.stateTags[c.state] ?? c.state}</Tag>
                </a>
              </li>
            ))}
          </ul>
        </Sheet>
        <p className="text-[14px] text-text-muted">{b.needs}</p>
      </div>
    </>
  )
}
