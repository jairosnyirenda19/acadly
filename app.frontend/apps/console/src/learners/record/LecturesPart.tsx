import { useLoad } from '@acadly/kernel'
import { slips, Waiting } from '@acadly/ui'
import { copy } from '../../copy'
import { lecturesApi, shortDay } from '../../lectures/api'
import { SmallButton, Tag } from '../../settings/parts'
import type { LearnerState } from '../api'
import { Column, Columns, InLink, LedgerFrame, SectionTitle, tabHref, Td, Th } from './ui'

const t = copy.lectures
const r = t.record

function useLectures(id: string) {
  return useLoad(`learner:lectures:${id}`, () => lecturesApi.learner(id))
}

const titleOf = (learner: LearnerState) => learner.parts.find((x) => x.key === 'lectures')?.title ?? ''
const word = (mark: string) => t.lecture.marks[mark] ?? mark

/** The overview's Lecture attendance block: the semester rate and the courses below 80% (D-102). */
export function LecturesSummary({ learner }: { learner: LearnerState }) {
  const loaded = useLectures(learner.id)
  const d = loaded.data

  return (
    <Column edge grow="flex-[1_1_260px]" label={titleOf(learner)}>
      <SectionTitle>{titleOf(learner)}</SectionTitle>
      {(loaded.phase === 'failed' || loaded.phase === 'missing') ? (
        <p className="text-sm text-text-muted">{r.none}</p>
      ) : d === undefined ? (
        <Waiting phase={loaded.phase} what={titleOf(learner)} labels={copy.loading} rows={2} onRetry={loaded.reload} />
      ) : d.held === 0 ? (
        <p className="text-sm text-text-muted">{r.none}</p>
      ) : (
        <>
          <p className="type-display text-[40px] leading-none">{`${d.rate ?? 0}%`}</p>
          <p className="mt-1.5 text-[13px]">{`${d.semester ?? ''} · ${d.held} ${r.held.toLowerCase()}`}</p>
          {d.courses.some((c) => d.bar !== null && c.rate !== null && c.rate < d.bar) && (
            <p className="mt-1.5">
              <Tag tone="attention">{r.belowTag(d.bar ?? 0)}</Tag>
            </p>
          )}
          <InLink href={tabHref(learner.id, 'lectures')}>{r.open}</InLink>
        </>
      )}
    </Column>
  )
}

/** The Lecture attendance tab (the approved board): rates, by course, the lectures missed or marked by hand, and the attendance phone. */
export function LecturesTab({ learner }: { learner: LearnerState }) {
  const loaded = useLectures(learner.id)
  const d = loaded.data

  if ((loaded.phase === 'failed' || loaded.phase === 'missing')) return <p className="text-sm text-text-muted">{r.none}</p>
  if (d === undefined) return <Waiting phase={loaded.phase} what={titleOf(learner)} labels={copy.loading} rows={6} onRetry={loaded.reload} />
  const below = d.courses.filter((c) => d.bar !== null && c.rate !== null && c.rate < d.bar).length
  const reset = async () => {
    const reason = window.prompt(t.reasonPrompt)
    if (reason === null) return
    const result = await lecturesApi.resetPhone(learner.id, reason)
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: r.resetDone })
      loaded.reload()
    } else slips.wrong({ word: t.notSaved, title: t.failed })
  }

  return (
    <Columns top>
      <Column grow="flex-[2_1_560px]" label={titleOf(learner)}>
        <div className="mb-4 grid grid-cols-2 border-2 border-ink md:grid-cols-4">
          {(
            [
              [r.rate, d.rate === null ? '' : `${d.rate}%`, 'ink'],
              [r.held, String(d.held), 'sheet'],
              [r.missed, String(d.missed), 'sheet'],
              [r.below(d.bar ?? 0), r.courses(below), below > 0 ? 'attention' : 'sheet'],
            ] as const
          ).map(([label, value, tone], i) => (
            <div key={label} className={`px-4 py-3 ${i > 0 ? 'md:border-l-2 md:border-ink' : ''} ${tone === 'ink' ? 'bg-ink text-sheet' : tone === 'attention' ? 'bg-marigold' : 'bg-sheet'}`}>
              <span className={`label-caps ${tone === 'ink' ? 'text-on-ink-muted' : 'text-text-muted'}`}>{label}</span>
              <div className={`type-display text-[40px] leading-none ${tone === 'ink' ? 'text-teal-bright' : ''}`}>{value}</div>
            </div>
          ))}
        </div>
        <SectionTitle>{r.byCourse(d.semester ?? '')}</SectionTitle>
        {d.courses.length === 0 ? (
          <p className="text-sm text-text-muted">{r.none}</p>
        ) : (
          <LedgerFrame label={r.byCourse(d.semester ?? '')}>
            <table className="w-full min-w-[520px] border-collapse">
              <thead>
                <tr>
                  <Th>{r.course}</Th>
                  <Th end>{r.held}</Th>
                  <Th end>{r.missed}</Th>
                  <Th>{r.rateCol}</Th>
                  <Th />
                </tr>
              </thead>
              <tbody>
                {d.courses.map((c) => (
                  <tr key={c.code}>
                    <Td>
                      <strong>{c.code}</strong> {c.title}
                    </Td>
                    <Td end>{c.held}</Td>
                    <Td end>{c.missed}</Td>
                    <Td>
                      <span className="flex items-center gap-2.5">
                        <span className="h-2.5 flex-1 bg-field">
                          <span className={`block h-2.5 ${d.bar !== null && c.rate !== null && c.rate < d.bar ? 'bg-marigold' : 'bg-ink'}`} style={{ width: `${c.rate ?? 0}%` }} />
                        </span>
                        <b className="w-12 text-right">{`${c.rate ?? 0}%`}</b>
                      </span>
                    </Td>
                    <Td>{d.bar !== null && c.rate !== null && c.rate < d.bar ? <Tag tone="attention">{r.belowTag(d.bar ?? 0)}</Tag> : <Tag tone="done">{r.ok}</Tag>}</Td>
                  </tr>
                ))}
              </tbody>
            </table>
          </LedgerFrame>
        )}
        <div className="mt-5">
          <SectionTitle>{r.missedList}</SectionTitle>
          {d.marks.length === 0 ? (
            <p className="text-sm text-text-muted">{r.none}</p>
          ) : (
            <ul className="border border-rule bg-sheet">
              {d.marks.map((m, i) => (
                <li key={i} className="flex min-h-11 items-center justify-between gap-3 border-b border-rule px-3.5 py-2 last:border-b-0">
                  <span className="flex min-w-0 flex-col">
                    <strong>{`${shortDay(m.day)} · ${m.code} ${t.kinds[m.kind] ?? m.kind}`}</strong>
                    <span className="text-[14px] text-text-muted">
                      {m.was !== null ? r.corrected(word(m.was), word(m.mark), m.by, m.reason) : m.source === 'hand' ? r.byHand(word(m.mark), m.by, m.reason) : r.notScanned}
                    </span>
                  </span>
                  <Tag tone={m.mark === 'present' ? 'done' : m.mark === 'late' ? 'attention' : 'wrong'}>{word(m.mark)}</Tag>
                </li>
              ))}
            </ul>
          )}
        </div>
        <p className="mt-3 text-[13px] text-text-muted">{r.note}</p>
      </Column>
      <Column edge grow="flex-[1_1_300px]" label={r.phone}>
        <SectionTitle>{r.phone}</SectionTitle>
        <p className="text-[15px]">{d.phone.set ? r.phoneSet(shortDay(d.phone.set_on ?? '')) : r.phoneNone}</p>
        {d.phone.set && d.can_reset && (
          <div className="mt-2.5">
            <SmallButton onClick={() => void reset()}>{r.reset}</SmallButton>
          </div>
        )}
      </Column>
    </Columns>
  )
}
