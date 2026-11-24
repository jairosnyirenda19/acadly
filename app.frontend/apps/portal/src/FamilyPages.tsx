import { useLoad } from '@acadly/kernel'
import { portalApi } from './api'
import { ChildPage } from './ChildPages'
import { Mark, Note, shortDay, longDay, useWords } from './parts'

/** Conduct as the family was told it (D-110): this term's counts, an open serious incident, then each entry. Never what staff wrote. */
export function ConductPage({ id }: { id: string }) {
  const t = useWords()
  const c = t.conduct
  const loaded = useLoad(`portal:conduct:${id}`, () => portalApi.conduct(id))

  return (
    <ChildPage id={id} phase={loaded.phase} data={loaded.data} onRetry={loaded.reload}>
      {(data) => {
        const now = data.entries.filter((e) => e.this_term)
        const before = data.entries.filter((e) => !e.this_term)

        return (
          <div className="px-4 pt-4 pb-6">
            <div className="label-caps text-[11px] text-text-muted">{c.kicker}</div>
            <h1 className="type-display mt-1 mb-3 text-[34px] leading-[0.9]">{data.child.name}</h1>
            <div className="mb-4 grid grid-cols-2 border-2 border-ink bg-sheet">
              <div className="px-3.5 py-2.5">
                <div className="label-caps text-[10px] text-text-muted">{c.commendations}</div>
                <div className="type-display text-[38px] leading-[0.9]">{data.commendations}</div>
                <div className="text-[13px]">{c.thisTerm}</div>
              </div>
              <div className="border-l-2 border-ink px-3.5 py-2.5">
                <div className="label-caps text-[10px] text-text-muted">{c.incidents}</div>
                <div className="type-display text-[38px] leading-[0.9]">{data.incidents}</div>
                <div className="text-[13px]">{c.thisTerm}</div>
              </div>
            </div>
            {data.serious.map((s) => (
              <section key={s.id} className="mb-4 border-2 border-ink bg-sheet px-3.5 py-3" aria-label={c.serious}>
                <div className="label-caps text-[11px] text-text-muted">{c.serious}</div>
                <div className="mt-1 flex items-center gap-2">
                  <span className="text-xl font-extrabold">{s.kind}</span>
                  <span className="inline-flex items-center gap-1.5 border-2 border-ink px-1.5 text-[13px] font-extrabold">■ {c.seriousTag}</span>
                </div>
                <div className="text-[15px]">{c.recorded(longDay(s.happened_on))}</div>
                <div className="mt-1.5 text-[15px] font-extrabold">{s.decision !== null ? c.decided(s.decision) : c.stage(s.stage)}</div>
                {s.meeting && (
                  <div className="mt-2.5">
                    <Note tone="attention" title={c.meeting}>
                      {c.meetingNote}
                    </Note>
                  </div>
                )}
              </section>
            ))}
            {[
              [c.thisTermList, now],
              [c.earlier, before],
            ].map(([title, list]) =>
              (list as typeof now).length === 0 ? null : (
                <section key={title as string} className="mb-4">
                  <h2 className="label-caps mb-1 text-[11px] text-text-muted">{title as string}</h2>
                  <ul className="border-t-2 border-ink bg-sheet">
                    {(list as typeof now).map((e) => (
                      <li key={e.id} className="flex min-h-[60px] items-center gap-3 border-b border-rule px-3.5 py-2">
                        <span className="min-w-0 flex-1">
                          <span className="block text-[17px] font-extrabold">{e.kind}</span>
                          <span className="block text-[13px] text-text-muted">{shortDay(e.happened_on)}</span>
                        </span>
                        <span className="inline-flex items-center gap-1.5 text-[13px] font-extrabold">
                          <Mark tone={e.commendation ? 'done' : e.serious ? 'wrong' : 'attention'} />
                          {e.commendation ? c.commendation : e.serious ? c.seriousTag : c.incident}
                        </span>
                      </li>
                    ))}
                  </ul>
                </section>
              ),
            )}
            {data.entries.length === 0 && <Note tone="plain" title={c.none} />}
            <p className="text-[13px] text-text-muted">{data.school_phone !== null ? c.footPhone(data.school_phone) : c.foot}</p>
          </div>
        )
      }}
    </ChildPage>
  )
}

/** The support plans the school shares (D-110): only the part written for the family. */
export function PlanPage({ id }: { id: string }) {
  const t = useWords()
  const p = t.plan
  const loaded = useLoad(`portal:plan:${id}`, () => portalApi.support(id))

  return (
    <ChildPage id={id} phase={loaded.phase} data={loaded.data} onRetry={loaded.reload}>
      {(data) => (
        <div className="px-4 pt-4 pb-6">
          <div className="label-caps text-[11px] text-text-muted">{p.kicker}</div>
          {data.plans.map((plan) => (
            <section key={plan.id} className="mb-5">
              <h1 className="type-display mt-1 mb-2 text-[34px] leading-[0.9]">{plan.title}</h1>
              <dl className="mb-3 grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-1 text-[15px]">
                <dt className="font-extrabold">{p.ledBy}</dt>
                <dd>{plan.lead}</dd>
                <dt className="font-extrabold">{p.started}</dt>
                <dd>{longDay(plan.opened_on)}</dd>
                <dt className="font-extrabold">{p.nextReview}</dt>
                <dd>{longDay(plan.next_review_on)}</dd>
              </dl>
              <div className="mb-2.5 border-2 border-ink bg-sheet px-3.5 py-3">
                <h2 className="text-[17px] font-extrabold">{p.school}</h2>
                <p className="mt-1 text-base leading-normal">{plan.school}</p>
              </div>
              <div className="border-2 border-ink bg-sheet px-3.5 py-3">
                <h2 className="text-[17px] font-extrabold">{p.home}</h2>
                <p className="mt-1 text-base leading-normal">{plan.home}</p>
              </div>
            </section>
          ))}
          <p className="text-[13px] text-text-muted">{data.school_phone !== null ? p.footPhone(data.school_phone) : p.foot}</p>
        </div>
      )}
    </ChildPage>
  )
}

/** Copies of the documents the school shares (D-110): each opens to save or print. */
export function DocumentsPage({ id }: { id: string }) {
  const t = useWords()
  const d = t.documents
  const loaded = useLoad(`portal:documents:${id}`, () => portalApi.documents(id))

  return (
    <ChildPage id={id} phase={loaded.phase} data={loaded.data} onRetry={loaded.reload}>
      {(data) => (
        <div className="px-4 pt-4 pb-6">
          <div className="label-caps text-[11px] text-text-muted">{d.kicker}</div>
          <h1 className="type-display mt-1 mb-2 text-[34px] leading-[0.9]">{data.child.name}</h1>
          <p className="mb-3 text-[15px]">{d.intro(data.child.first_name)}</p>
          <ul className="border-t-2 border-ink bg-sheet">
            {data.documents.map((doc) => (
              <li key={doc.id} className="flex min-h-[68px] items-center gap-3 border-b border-rule px-3.5 py-2">
                <span className="min-w-0 flex-1">
                  <span className="block text-[17px] font-extrabold break-words">{doc.title}</span>
                  <span className="block text-[13px] text-text-muted">
                    {d.added(shortDay(doc.added_on))} · {d.fileType[doc.file_type] ?? d.fileType.file}
                  </span>
                </span>
                <a href={portalApi.documentUrl(id, doc.id)} target="_blank" rel="noopener" className="grid h-11 place-items-center border-2 border-ink px-3.5 text-[15px] font-extrabold text-ink no-underline">
                  {d.open}
                </a>
              </li>
            ))}
          </ul>
          <p className="mt-3 text-[13px] text-text-muted">{data.school_phone !== null ? d.footPhone(data.school_phone) : d.foot}</p>
        </div>
      )}
    </ChildPage>
  )
}
