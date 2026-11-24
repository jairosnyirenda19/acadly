import { useLoad } from '@acadly/kernel'
import { Waiting } from '@acadly/ui'
import { classroomApi } from '../../classroom/api'
import { copy } from '../../copy'
import { Tag } from '../../settings/parts'
import type { LearnerState } from '../api'
import { Column, Columns, InLink, LedgerFrame, SectionTitle, tabHref, Td, Th } from './ui'

const t = copy.classroom
const r = t.record

function useCoursework(id: string) {
  return useLoad(`learner:coursework:${id}`, () => classroomApi.learner(id))
}

const titleOf = (learner: LearnerState) => learner.parts.find((x) => x.key === 'classroom')?.title ?? ''

/** The overview's Coursework block: work missing, or up to date (D-104). */
export function ClassroomSummary({ learner }: { learner: LearnerState }) {
  const loaded = useCoursework(learner.id)
  const d = loaded.data

  return (
    <Column edge grow="flex-[1_1_260px]" label={titleOf(learner)}>
      <SectionTitle>{titleOf(learner)}</SectionTitle>
      {(loaded.phase === 'failed' || loaded.phase === 'missing') ? (
        <p className="text-sm text-text-muted">{r.none}</p>
      ) : d === undefined ? (
        <Waiting phase={loaded.phase} what={titleOf(learner)} labels={copy.loading} rows={2} onRetry={loaded.reload} />
      ) : d.courses.length === 0 ? (
        <p className="text-sm text-text-muted">{r.none}</p>
      ) : (
        <>
          <p className="mt-1">{d.missing === 0 ? <Tag tone="done">{r.upToDate}</Tag> : <Tag tone="attention">{`! ${r.missing(d.missing)}`}</Tag>}</p>
          <InLink href={tabHref(learner.id, 'classroom')}>{r.open}</InLink>
        </>
      )}
    </Column>
  )
}

/** The Coursework tab: each course's continuous assessment so far, and each piece of work. */
export function ClassroomTab({ learner }: { learner: LearnerState }) {
  const loaded = useCoursework(learner.id)
  const d = loaded.data

  if ((loaded.phase === 'failed' || loaded.phase === 'missing')) return <p className="text-sm text-text-muted">{r.none}</p>
  if (d === undefined) return <Waiting phase={loaded.phase} what={titleOf(learner)} labels={copy.loading} rows={6} onRetry={loaded.reload} />
  if (d.courses.length === 0) return <p className="text-sm text-text-muted">{r.none}</p>

  return (
    <Columns top>
      <Column grow="flex-[2_1_560px]" label={titleOf(learner)}>
        {d.courses.map((c) => (
          <section key={c.code} className="mb-5" aria-label={c.code}>
            <SectionTitle aside={c.missing > 0 ? <Tag tone="attention">{`! ${r.missing(c.missing)}`}</Tag> : undefined}>{`${c.code} · ${c.title} · ${r.total(c.total, c.share)}`}</SectionTitle>
            <LedgerFrame label={c.code}>
              <table className="w-full min-w-[460px] border-collapse">
                <thead>
                  <tr>
                    <Th>{r.course}</Th>
                    <Th end>{t.form.weight}</Th>
                    <Th end>{t.marking.mark}</Th>
                  </tr>
                </thead>
                <tbody>
                  {c.tasks.map((x) => (
                    <tr key={x.title}>
                      <Td>{`${x.title} · ${t.kinds[x.kind] ?? x.kind}`}</Td>
                      <Td end>{x.weight}</Td>
                      <Td end>
                        {x.mark !== null ? `${x.mark} / ${x.out_of}` : x.state === 'missing' ? <Tag tone="wrong">{r.notIn}</Tag> : <span className="text-text-muted">{r.notReleased}</span>}
                      </Td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </LedgerFrame>
          </section>
        ))}
      </Column>
    </Columns>
  )
}
