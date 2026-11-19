import { useAppContext, useLoad, type AppContext } from '@acadly/kernel'
import { Waiting } from '@acadly/ui'
import { copy } from '../../copy'
import { earlyApi } from '../../early/api'
import { ReviewState } from '../../early/ReviewsScreen'
import { isStep, StepMark } from '../../early/steps'
import { day } from '../../settings/api'
import { Tag } from '../../settings/parts'
import type { LearnerState } from '../api'
import { Caption, Column, Columns, InLink, SectionTitle, tabHref } from './ui'
import { ResultsSummary, ResultsTab } from './ResultsPart'

const t = copy.early
const p = copy.pickup

/** Whether the learner's level records observations, by the binding of its profile. */
export function observesLearner(context: AppContext, learner: LearnerState): boolean {
  const division = context.divisions.find((d) => d.id === learner.division_id)
  const bindings = division?.bindings as Record<string, string> | undefined

  return bindings?.['assessment.model'] === 'observational'
}

const titleOf = (learner: LearnerState, key: string) => learner.parts.find((x) => x.key === key)?.title ?? ''

/** Assessment's block: results where the level takes marks, observations where it records them. */
export function AssessmentSummary({ learner }: { learner: LearnerState }) {
  const context = useAppContext()

  return observesLearner(context, learner) ? <ObservationsSummary learner={learner} /> : <ResultsSummary learner={learner} />
}

export function AssessmentTab({ learner }: { learner: LearnerState }) {
  const context = useAppContext()

  return observesLearner(context, learner) ? <ObservationsTab learner={learner} /> : <ResultsTab learner={learner} />
}

function useReview(id: string) {
  return useLoad(`learner:review:${id}`, () => earlyApi.review(id))
}

function ObservationsSummary({ learner }: { learner: LearnerState }) {
  const loaded = useReview(learner.id)
  const data = loaded.data
  const title = titleOf(learner, 'assessment')

  return (
    <Column edge grow="flex-[1_1_260px]" label={title}>
      <SectionTitle>{title}</SectionTitle>
      {data === undefined ? (
        <Waiting phase={loaded.phase} what={title} labels={copy.loading} rows={2} onRetry={loaded.reload} />
      ) : (
        <>
          <ul className="grid gap-1">
            {data.areas.map((a) => (
              <li key={a.id} className="flex items-center gap-2 text-[13px]">
                <StepMark step={a.trail.at(-1)?.step ?? null} wide="w-9" />
                <span>{a.name}</span>
              </li>
            ))}
          </ul>
          <InLink href={tabHref(learner.id, 'assessment')}>{t.trail}</InLink>
        </>
      )}
    </Column>
  )
}

/** The Observations tab: the term's observations by learning area, and the progress review. */
function ObservationsTab({ learner }: { learner: LearnerState }) {
  const loaded = useReview(learner.id)
  const data = loaded.data
  const title = titleOf(learner, 'assessment')

  if (data === undefined) return <Waiting phase={loaded.phase} what={title} labels={copy.loading} rows={6} onRetry={loaded.reload} />

  return (
    <Columns>
      <Column grow="flex-[2_1_480px]" label={title}>
        <SectionTitle>{`${title} · ${data.period.name}`}</SectionTitle>
        {data.areas.map((a) => (
          <div key={a.id} className="border-b border-rule py-2.5">
            <h3 className="text-[15px] font-extrabold">{a.name}</h3>
            {a.trail.length === 0 && <p className="text-[13px] text-text-muted">{t.noTrail}</p>}
            <ol className="mt-1 grid gap-1">
              {a.trail.map((o, i) => (
                <li key={i} className="flex flex-wrap items-center gap-2 text-[13px]">
                  <span className="w-16 font-mono text-text-muted">{day(o.observed_on)}</span>
                  <StepMark step={isStep(o.step) ? o.step : null} word wide="w-9" />
                  {o.focus !== null && <span className="text-text-muted">{o.focus}</span>}
                  {o.note !== null && <span>{o.note}</span>}
                </li>
              ))}
            </ol>
          </div>
        ))}
      </Column>
      <Column edge grow="flex-[1_1_280px]" label={t.reviewsTile}>
        <SectionTitle>{t.reviewsTile}</SectionTitle>
        <ReviewState state={data.review.id === null ? null : data.review.state} label={data.review.state_label} />
        {data.review.family_note !== '' && (
          <>
            <Caption>{t.familyNote}</Caption>
            <p className="text-sm">{data.review.family_note}</p>
          </>
        )}
        <InLink href={`/reviews/${learner.id}`}>{t.open}</InLink>
      </Column>
    </Columns>
  )
}

function usePickup(id: string) {
  return useLoad(`learner:pickup:${id}`, () => earlyApi.child(id))
}

/** Safe pick-up's block: today, and who may collect. */
export function PickupSummary({ learner }: { learner: LearnerState }) {
  const loaded = usePickup(learner.id)
  const data = loaded.data
  const title = titleOf(learner, 'saferelease')
  const collected = data?.today.find((x) => x.state === 'released')

  return (
    <Column edge grow="flex-[1_1_240px]" label={title}>
      <SectionTitle>{title}</SectionTitle>
      {data === undefined ? (
        <Waiting phase={loaded.phase} what={title} labels={copy.loading} rows={2} onRetry={loaded.reload} />
      ) : (
        <>
          {collected !== undefined ? <Tag tone="done">✓ {`${p.collectedTag} · ${p.collectedLine(collected.at, collected.name)}`}</Tag> : <Tag tone="plain">{p.here}</Tag>}
          <Caption>{p.whoMay(learner.first_name)}</Caption>
          <ul className="grid gap-0.5 text-[13px]">
            {data.may_collect.map((c) => (
              <li key={c.id}>{`${c.name} · ${c.relationship}`}</li>
            ))}
          </ul>
          <InLink href={tabHref(learner.id, 'saferelease')}>{title}</InLink>
        </>
      )}
    </Column>
  )
}

/** Safe pick-up's tab: who may collect, who must not, and every pick-up. */
export function PickupTab({ learner }: { learner: LearnerState }) {
  const loaded = usePickup(learner.id)
  const data = loaded.data
  const title = titleOf(learner, 'saferelease')

  if (data === undefined) return <Waiting phase={loaded.phase} what={title} labels={copy.loading} rows={6} onRetry={loaded.reload} />

  const all = [...data.today.map((x) => ({ ...x, day: '' })), ...data.recent]

  return (
    <Columns>
      <Column grow="flex-[1_1_320px]" label={p.whoMay(learner.first_name)}>
        <SectionTitle>{p.whoMay(learner.first_name)}</SectionTitle>
        <ul>
          {data.may_collect.map((c) => (
            <li key={c.id} className="flex flex-wrap justify-between gap-2 border-b border-rule py-1.5 text-sm">
              <span>
                <b>{c.name}</b> · {c.relationship}
              </span>
              {c.day_only && <Tag tone="attention">! {p.dayOnly}</Tag>}
            </li>
          ))}
        </ul>
        {data.barred.length > 0 && (
          <>
            <Caption>{p.barTitle}</Caption>
            {data.barred.map((b) => (
              <p key={b.id} className="text-sm">
                <Tag tone="wrong">✕ {b.name}</Tag> {p.barredLine(b.reason, day(b.since))}
              </p>
            ))}
          </>
        )}
      </Column>
      <Column edge grow="flex-[1_1_320px]" label={p.recent}>
        <SectionTitle>{p.recent}</SectionTitle>
        <ul>
          {all.map((x) => (
            <li key={x.id} className="flex flex-wrap justify-between gap-2 border-b border-rule py-1.5 text-[13px]">
              <span>{`${x.day === '' ? '' : `${day(x.day)} `}${x.at} · ${x.name}`}</span>
              {x.state === 'released' ? <Tag tone="done">✓ {x.state_label}</Tag> : <Tag tone={x.state === 'disputed' ? 'wrong' : 'plain'}>{x.state === 'disputed' ? `✕ ${x.state_label}` : x.state_label}</Tag>}
            </li>
          ))}
        </ul>
      </Column>
    </Columns>
  )
}
