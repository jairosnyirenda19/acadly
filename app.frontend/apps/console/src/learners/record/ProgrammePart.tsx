import { useLoad } from '@acadly/kernel'
import { Waiting } from '@acadly/ui'
import { copy } from '../../copy'
import { RegTag } from '../../programmes/RegistrationDeskScreen'
import { registrationApi } from '../../programmes/registrationApi'
import { day } from '../../settings/api'
import type { LearnerState } from '../api'
import { Caption, Column, Columns, InLink, LedgerFrame, SectionTitle, tabHref, Td, Th } from './ui'

const s = copy.programmes.students
const p = copy.programmes.registration.part
const kinds = copy.programmes.registration.kinds

function useProgramme(id: string) {
  return useLoad(`learner:programme:${id}`, () => registrationApi.learner(id))
}

const titleOf = (learner: LearnerState) => learner.parts.find((x) => x.key === 'programmes')?.title ?? ''
const modeOf = (mode: string) => s.modes[mode as 'full_time' | 'part_time'] ?? mode
const statusOf = (status: string) => s.statuses[status as keyof typeof s.statuses] ?? status

/** The overview's Programme block: the programme, the year of study and the latest registration (D-098). */
export function ProgrammeSummary({ learner }: { learner: LearnerState }) {
  const loaded = useProgramme(learner.id)
  const data = loaded.data
  const latest = data?.registrations[0]

  return (
    <Column edge grow="flex-[1_1_260px]" label={titleOf(learner)}>
      <SectionTitle>{titleOf(learner)}</SectionTitle>
      {(loaded.phase === 'failed' || loaded.phase === 'missing') ? (
        <p className="text-sm text-text-muted">{p.none}</p>
      ) : data === undefined ? (
        <Waiting phase={loaded.phase} what={titleOf(learner)} labels={copy.loading} rows={2} onRetry={loaded.reload} />
      ) : (
        <>
          <p className="text-[15px]">
            <strong>{data.programme.code}</strong> {`${data.programme.name} · ${s.year(data.year)}`}
          </p>
          {latest !== undefined ? (
            <p className="mt-1.5 flex flex-wrap items-center gap-2 text-[13px]">
              {latest.period} <RegTag state={latest.state} />
            </p>
          ) : (
            <p className="mt-1.5 text-[13px] text-text-muted">{p.noRegistrations}</p>
          )}
          <InLink href={tabHref(learner.id, 'programmes')}>{p.open}</InLink>
        </>
      )}
    </Column>
  )
}

/** The Programme tab: the programme, every semester registration with its courses, and the programme history. */
export function ProgrammeTab({ learner }: { learner: LearnerState }) {
  const loaded = useProgramme(learner.id)
  const data = loaded.data

  if ((loaded.phase === 'failed' || loaded.phase === 'missing')) return <p className="text-sm text-text-muted">{p.none}</p>
  if (data === undefined) return <Waiting phase={loaded.phase} what={titleOf(learner)} labels={copy.loading} rows={6} onRetry={loaded.reload} />

  return (
    <Columns top>
      <Column grow="flex-[2_1_560px]" label={p.registrations}>
        <SectionTitle>{p.registrations}</SectionTitle>
        {data.registrations.length === 0 && <p className="text-sm text-text-muted">{p.noRegistrations}</p>}
        {data.registrations.map((r) => (
          <section key={r.id} className="mb-5" aria-label={r.period}>
            <Caption>
              <span className="flex flex-wrap items-center gap-2">
                <InLink href={`/programmes/r/${r.id}`}>{`${r.period} · ${s.year(r.year)}`}</InLink>
                <RegTag state={r.state} />
              </span>
            </Caption>
            <LedgerFrame label={r.period}>
              <table className="w-full min-w-[460px] border-collapse">
                <thead>
                  <tr>
                    <Th>{copy.programmes.registration.columnsCourse.course}</Th>
                    <Th>{copy.programmes.registration.columnsCourse.kind}</Th>
                    <Th end>{copy.programmes.registration.columnsCourse.credits}</Th>
                  </tr>
                </thead>
                <tbody>
                  {r.courses.map((c) => (
                    <tr key={c.code}>
                      <Td>
                        <strong>{c.code}</strong> {c.title}
                      </Td>
                      <Td>{kinds[c.kind] ?? c.kind}</Td>
                      <Td end>{c.credits}</Td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </LedgerFrame>
          </section>
        ))}
      </Column>
      <Column edge grow="flex-[1_1_300px]" label={p.programme}>
        <SectionTitle>{p.programme}</SectionTitle>
        <dl className="grid gap-1.5 text-[15px]">
          <div>
            <dt className="label-caps text-text-muted">{p.programme}</dt>
            <dd>{`${data.programme.code} ${data.programme.name} (${s.curriculum} ${data.curriculum})`}</dd>
          </div>
          <div>
            <dt className="label-caps text-text-muted">{p.year}</dt>
            <dd>{s.year(data.year)}</dd>
          </div>
          <div>
            <dt className="label-caps text-text-muted">{p.mode}</dt>
            <dd>{modeOf(data.mode)}</dd>
          </div>
          <div>
            <dt className="label-caps text-text-muted">{p.status}</dt>
            <dd>{statusOf(data.status)}</dd>
          </div>
        </dl>
        <div className="mt-4">
          <Caption>{p.history}</Caption>
          <ul className="grid gap-1 text-[13px]">
            {data.history.map((h, i) => (
              <li key={i}>
                {`${day(h.at)} · ${s.year(h.year)} · ${statusOf(h.status)}`}
                {h.reason !== null && <span className="block text-text-muted">{h.reason}</span>}
              </li>
            ))}
          </ul>
        </div>
      </Column>
    </Columns>
  )
}
