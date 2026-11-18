import { navigate, useLoad } from '@acadly/kernel'
import { Button, Waiting } from '@acadly/ui'
import { copy } from '../../copy'
import { day } from '../../settings/api'
import { Tag } from '../../settings/parts'
import { supportApi, trendValue } from '../../support/api'
import { SourceTag } from '../../support/WatchScreen'
import type { LearnerState } from '../api'
import { Caption, Column, Columns, InLink, LedgerFrame, SectionTitle, tabHref, Td, Th } from './ui'

const t = copy.support

function useSupport(id: string) {
  return useLoad(`learner:support:${id}`, () => supportApi.learner(id))
}

const titleOf = (learner: LearnerState) => learner.parts.find((p) => p.key === 'support')?.title ?? ''

/** The overview's Support block: signals now and an open plan. */
export function SupportSummary({ learner }: { learner: LearnerState }) {
  const loaded = useSupport(learner.id)
  const data = loaded.data
  const open = data?.plans.find((p) => p.state !== 'closed')

  return (
    <Column edge grow="flex-[1_1_260px]" label={titleOf(learner)}>
      <SectionTitle>{titleOf(learner)}</SectionTitle>
      {data === undefined ? (
        <Waiting phase={loaded.phase} what={titleOf(learner)} labels={copy.loading} rows={2} onRetry={loaded.reload} />
      ) : (
        <>
          {data.signals.length === 0 && open === undefined && <p className="text-sm text-text-muted">{t.summaryNone}</p>}
          {data.signals.length > 0 && (
            <>
              <Caption>{t.signalsNow}</Caption>
              <ul className="grid gap-1 text-[13px]">
                {data.signals.map((s, i) => (
                  <li key={i}>
                    <b>{s.label}:</b> {s.text}
                  </li>
                ))}
              </ul>
            </>
          )}
          {open !== undefined && <p className="mt-2 text-[13px]">{`${open.title} · ${t.nextReview(day(open.next_review_on))}`}</p>}
          <InLink href={tabHref(learner.id, 'support')}>{t.seeSupport}</InLink>
        </>
      )}
    </Column>
  )
}

/** The Support tab (the approved design): trends by term, signals now, the plan, past plans. */
export function SupportTab({ learner }: { learner: LearnerState }) {
  const loaded = useSupport(learner.id)
  const data = loaded.data

  if (data === undefined) return <Waiting phase={loaded.phase} what={titleOf(learner)} labels={copy.loading} rows={6} onRetry={loaded.reload} />

  const open = data.plans.find((p) => p.state !== 'closed')
  const past = data.plans.filter((p) => p.state === 'closed')
  const terms = data.trends.terms

  return (
    <Columns top>
      <Column grow="flex-[2_1_560px]" label={t.trends}>
        <section className="mb-5" aria-label={t.trends}>
          <SectionTitle aside={<span className="text-[13px] text-text-muted">{t.trendsNote}</span>}>{t.trends}</SectionTitle>
          {terms.length === 0 ? (
            <p className="text-sm text-text-muted">{t.noTrends}</p>
          ) : (
            <LedgerFrame label={t.trends}>
              <table className="w-full min-w-[520px] border-collapse">
                <thead>
                  <tr>
                    <Th />
                    {terms.map((x) => (
                      <Th key={x.key} end>
                        {x.name}
                      </Th>
                    ))}
                    <Th end />
                  </tr>
                </thead>
                <tbody>
                  {data.trends.rows.map((row) => (
                    <tr key={row.key}>
                      <th scope="row" className="border-b border-rule py-2.5 pr-3 text-left text-sm font-extrabold">
                        {row.label}
                      </th>
                      {terms.map((x, i) => (
                        <Td key={x.key} end>
                          <span className={`ref ${i === terms.length - 1 ? 'font-extrabold' : ''}`}>{trendValue(row.values[x.key], row.unit)}</span>
                        </Td>
                      ))}
                      <Td end>{row.change === 'worse' ? <Tag tone="attention">! {t.worse}</Tag> : row.change === 'better' ? <Tag tone="done">✓ {t.better}</Tag> : null}</Td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </LedgerFrame>
          )}
          <p className="mt-2 text-[13px] text-text-muted">{t.trendsHelp}</p>
        </section>

        <section aria-label={t.signalsNow}>
          <SectionTitle>{t.signalsNow}</SectionTitle>
          {data.signals.length === 0 && <p className="text-sm text-text-muted">{t.noSignals}</p>}
          <ul>
            {data.signals.map((s, i) => (
              <li key={i} className="flex flex-wrap items-baseline gap-2.5 border-b border-rule py-2 text-[15px]">
                <SourceTag label={s.label} />
                <span>{s.text}</span>
              </li>
            ))}
          </ul>
        </section>

        {past.length > 0 && (
          <section className="mt-5" aria-label={t.pastPlans}>
            <SectionTitle>{t.pastPlans}</SectionTitle>
            <ul>
              {past.map((p) => (
                <li key={p.id} className="flex flex-wrap items-center gap-3 border-b border-rule py-2 text-sm">
                  <span className="ref text-[13px]">{day(p.opened_on)}</span>
                  <InLink href={`/support/plans/${p.id}`}>{p.title}</InLink>
                  <Tag tone="done">✓ {p.outcome_label ?? ''}</Tag>
                  <span className="text-text-muted">{t.ledBy(p.lead)}</span>
                </li>
              ))}
            </ul>
          </section>
        )}
      </Column>

      <Column edge grow="flex-[1_1_320px]" label={t.currentPlan}>
        {open !== undefined ? (
          <div className="grid gap-2.5">
            <SectionTitle aside={<Tag tone="attention">! {open.state_label}</Tag>}>{t.currentPlan}</SectionTitle>
            <div className="text-[15px]">
              <b>{open.title}</b>
              <span className="block text-[13px] text-text-muted">
                {t.ledBy(open.lead)} · {t.openedOn(day(open.opened_on))} · {t.nextReview(day(open.next_review_on))}
              </span>
              {open.confidential && <Tag tone="plain">{t.confidential}</Tag>}
            </div>
            <div>
              <Button variant="secondary" onClick={() => navigate(`/support/plans/${open.id}`)}>
                {t.openThePlan}
              </Button>
            </div>
          </div>
        ) : (
          <div className="grid gap-2.5">
            <SectionTitle>{t.currentPlan}</SectionTitle>
            <p className="text-sm text-text-muted">{t.noPlans}</p>
            {data.can.lead && (
              <div>
                <Button onClick={() => navigate(`/support/plans/new?learner=${learner.id}`)}>{t.openTitle}</Button>
              </div>
            )}
          </div>
        )}
      </Column>
    </Columns>
  )
}
