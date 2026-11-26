import { navigate, useLoad, useLocation } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { assessmentApi, type ChoicesState } from './api'

const a = copy.assessment
const t = a.choices

/** Subject choices of a class (D-117, the approved design): core subjects for all, the electives ticked per learner. */
export function ChoicesScreen() {
  const location = useLocation()
  const classId = location.query.get('class_id')
  const loaded = useLoad(`assessment:choices:${classId ?? ''}`, () => assessmentApi.choices(classId))
  const data = loaded.data

  if (data === undefined) return <Waiting phase={loaded.phase} what={a.marks.tabs.choices} labels={copy.loading} rows={8} onRetry={loaded.reload} />

  if (data.class === null) {
    return (
      <p className="px-4 py-6 text-[15px] text-text-muted md:px-[clamp(16px,3vw,40px)]">{t.noClasses}</p>
    )
  }

  return <ChoicesGrid key={data.class.id} data={data} onSaved={loaded.reload} />
}

function ChoicesGrid({ data, onSaved }: { data: ChoicesState; onSaved: () => void }) {
  const klass = data.class
  const [takes, setTakes] = useState<Record<string, string[]>>(() => Object.fromEntries(data.learners.map((l) => [l.id, l.takes])))
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  if (klass === null) return null

  const toggle = (learner: string, subject: string, on: boolean) => {
    const now = takes[learner] ?? []
    setTakes({ ...takes, [learner]: on ? [...now, subject] : now.filter((s) => s !== subject) })
  }

  const save = async () => {
    setBusy(true)
    setError(null)
    const result = await assessmentApi.saveChoices(
      klass.id,
      data.learners.map((l) => ({ learner_id: l.id, subject_ids: takes[l.id] ?? [] })),
    )
    setBusy(false)
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: result.data.changed > 0 ? t.saved(result.data.changed) : t.nothing })
      onSaved()
    } else if (result.kind === 'invalid') setError(Object.values(result.errors)[0]?.[0] ?? t.failed)
    else slips.wrong({ word: t.notSaved, title: t.failed })
  }

  return (
    <div className="px-4 pt-4 pb-8 md:px-[clamp(16px,3vw,40px)]">
      {data.classes.length > 1 && (
        <nav aria-label={t.classes} className="mb-4 flex flex-wrap gap-1 border-b-2 border-ink">
          {data.classes.map((c) => {
            const href = `/marks/choices?class_id=${c.id}`

            return (
              <a
                key={c.id}
                href={href}
                aria-current={c.id === klass.id ? 'page' : undefined}
                onClick={(e) => (e.preventDefault(), navigate(href))}
                className={`inline-flex min-h-11 items-center border-b-4 px-3.5 text-[15px] text-ink no-underline ${c.id === klass.id ? 'border-teal font-extrabold' : 'border-transparent font-medium hover:bg-paper'}`}
              >
                {c.name}
              </a>
            )
          })}
        </nav>
      )}

      <div className="grid grid-cols-[minmax(0,1fr)] items-start gap-x-8 gap-y-6 xl:grid-cols-[minmax(0,1fr)_380px]">
        <section aria-label={klass.name} className="grid gap-3">
          <p className="text-sm">
            <span className="label-caps mr-2 text-text-muted">{t.core}</span>
            {data.core.join(' · ')}
          </p>
          {error !== null && (
            <Notice tone="wrong" title={t.notSaved}>
              {error}
            </Notice>
          )}
          <div role="region" aria-label={klass.name} tabIndex={0} className="overflow-x-auto border-2 border-ink bg-sheet">
            <table className="w-full min-w-[560px] border-collapse">
              <thead>
                <tr>
                  <th scope="col" className="label-caps border-b-2 border-ink px-3 py-2 text-left text-text-muted">
                    {t.learner}
                  </th>
                  {data.electives.map((s) => (
                    <th key={s.id} scope="col" title={s.name} className="label-caps border-b-2 border-ink px-2 py-2 text-center text-text-muted">
                      {s.name}
                    </th>
                  ))}
                  <th scope="col" className="label-caps border-b-2 border-ink px-3 py-2 text-right text-text-muted">
                    {t.subjects}
                  </th>
                </tr>
              </thead>
              <tbody>
                {data.learners.map((l) => {
                  const mine = takes[l.id] ?? []

                  return (
                    <tr key={l.id}>
                      <th scope="row" className="border-b border-rule px-3 py-1.5 text-left text-sm font-extrabold">
                        {l.name}
                      </th>
                      {data.electives.map((s) => (
                        <td key={s.id} className="border-b border-rule px-2 py-1 text-center">
                          <input
                            type="checkbox"
                            aria-label={t.takes(l.name, s.name)}
                            checked={mine.includes(s.id)}
                            onChange={(e) => toggle(l.id, s.id, e.target.checked)}
                            className="size-5 accent-ink"
                          />
                        </td>
                      ))}
                      <td className="ref border-b border-rule px-3 text-right text-[15px] font-extrabold">
                        {data.core.length + mine.length}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
          <div>
            <Button busy={busy} onClick={() => void save()}>
              {t.save}
            </Button>
          </div>
        </section>

        <aside className="grid gap-4">
          {!data.on ? (
            <Attention title={a.marks.tabs.choices}>{t.notOn}</Attention>
          ) : (
            <section aria-label={t.takers(klass.level)} className="border-2 border-ink bg-sheet">
              <h2 className="label-caps border-b-2 border-ink px-4 py-2.5 text-text-muted">{t.takers(klass.level)}</h2>
              <ul>
                {data.electives.map((s, i) => (
                  <li key={s.id} className={`flex items-center justify-between gap-3 px-4 py-2.5 ${i > 0 ? 'border-t border-rule' : ''}`}>
                    <span className="text-[15px]">{s.name}</span>
                    <b className="ref text-[15px]">{s.takers}</b>
                  </li>
                ))}
              </ul>
              <p className="border-t border-rule px-4 py-2 text-xs text-text-muted">{t.takersNote}</p>
            </section>
          )}
          {data.none > 0 && (
            <Attention title={t.none(data.none)}>{t.noneNote}</Attention>
          )}
          <p className="text-[13px] text-text-muted">{t.keepNote}</p>
        </aside>
      </div>
    </div>
  )
}

/** Attention required: marigold, with a mark as well as the colour. */
function Attention({ title, children }: { title: string; children: string }) {
  return (
    <div className="border-2 border-l-[6px] border-ink border-l-marigold bg-sheet px-4 py-3">
      <p className="text-[15px] font-extrabold">
        <span aria-hidden="true">! </span>
        {title}
      </p>
      <p className="mt-1 text-[13px]">{children}</p>
    </div>
  )
}
