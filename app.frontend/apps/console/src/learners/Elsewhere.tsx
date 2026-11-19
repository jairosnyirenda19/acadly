import { api, useLoad } from '@acadly/kernel'
import { Button } from '@acadly/ui'
import type { ReactNode } from 'react'
import { copy } from '../copy'
import { read } from '../setup/api'
import { useWorkIn } from '../shell/WorkIn'

const t = copy.learners.elsewhere

/**
 * A learner who is not in the place worked in (D-099). When the account may
 * work where the learner is, the page says so and offers to switch; otherwise
 * the page is the plain "not found", and says nothing about the learner.
 */
export function Elsewhere({ id, fallback }: { id: string; fallback: ReactNode }) {
  const { place, choose } = useWorkIn()
  const where = useLoad(`learner:place:${id}`, () => read(api.GET('/v1/learners/{id}/place', { params: { path: { id } } })))
  const found = where.data

  if (found === undefined || found.key === place?.key) return <>{fallback}</>

  return (
    <div className="px-[clamp(16px,3vw,40px)] py-8">
      <section aria-label={t.title(place?.label ?? '')} className="grid max-w-[640px] gap-3 border-2 border-ink bg-sheet px-5 py-4">
        <span className="label-caps text-text-muted">{t.kicker(place?.label ?? '')}</span>
        <h1 className="type-display text-[34px] leading-[0.95]">{t.title(place?.label ?? '')}</h1>
        <p className="text-[15px]">{t.text}</p>
        <div>
          <Button onClick={() => choose(found.key)}>{t.switch(found.label)}</Button>
        </div>
      </section>
    </div>
  )
}
