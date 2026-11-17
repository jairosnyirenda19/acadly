import { useLoad, useLocation } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { follow } from '../assessment/parts'
import { copy } from '../copy'
import { Select } from '../setup/parts'
import { PageHead, SettingsTabs, Tag } from '../settings/parts'
import { approvalsApi, when, type Spec, type TopicState } from './api'

const t = copy.approvals
const s = copy.settings

const KINDS: Spec['kind'][] = ['anyone', 'relation', 'role', 'person']

/** Settings › Approvals (the approved design): the catalogue, and the chain of steps for one kind of request. */
export function ApprovalSettingsScreen() {
  const loaded = useLoad('approvals:catalogue', () => approvalsApi.catalogue())
  const data = loaded.data
  const { query } = useLocation()

  const body =
    data === undefined ? (
      <Waiting phase={loaded.phase} what={t.settingsTitle} labels={copy.loading} rows={6} onRetry={loaded.reload} />
    ) : (
      (() => {
        const all = data.areas.flatMap((a) => a.topics)
        const chosen = all.find((x) => x.key === query.get('topic')) ?? all[0]

        return (
          <div className="flex flex-1 flex-wrap items-stretch border-t-2 border-ink">
            <nav aria-label={t.kindsLabel} className="min-w-0 flex-[1_1_300px] border-ink bg-paper max-[767px]:border-b-2 md:max-w-[380px] md:border-r-2">
              {data.areas.map((area) => (
                <div key={area.area}>
                  <h2 className="label-caps border-b border-rule px-4 pt-3 pb-1.5 text-text-muted">{area.area}</h2>
                  {area.topics.map((x) => {
                    const href = `/settings/approvals?topic=${encodeURIComponent(x.key)}`

                    return (
                      <a
                        key={x.key}
                        href={href}
                        aria-current={x.key === chosen?.key ? 'page' : undefined}
                        onClick={(e) => follow(e, href)}
                        className={`block border-b border-l-4 border-rule px-4 py-2.5 text-ink no-underline ${x.key === chosen?.key ? 'border-l-ink bg-sheet' : 'border-l-transparent hover:bg-sheet'}`}
                      >
                        <span className="flex items-baseline gap-2">
                          <span className="flex-1 text-[15px] font-extrabold">{x.title}</span>
                          {x.changed && <Tag tone="plain">{t.changed}</Tag>}
                        </span>
                        <span className="block text-[13px] text-text-muted">{x.description}</span>
                        <span className="block text-xs font-bold">
                          {x.steps.length === 0 ? t.noSteps : `${t.stepsCount(x.steps.length)}: ${x.steps.join(' → ')}`}
                        </span>
                      </a>
                    )
                  })}
                </div>
              ))}
            </nav>
            <section className="min-w-0 flex-[3_1_560px] bg-paper px-4 pt-4.5 pb-7 md:px-8">
              {chosen !== undefined && <TopicEditor key={chosen.key} topicKey={chosen.key} onSaved={() => loaded.reload()} />}
            </section>
          </div>
        )
      })()
    )

  return (
    <>
      <SettingsTabs current="approvals" />
      <PageHead kicker={t.settingsKicker} title={t.settingsTitle} intro={t.settingsIntro} />
      {body}
    </>
  )
}

function TopicEditor({ topicKey, onSaved }: { topicKey: string; onSaved: () => void }) {
  const loaded = useLoad(`approvals:topic:${topicKey}`, () => approvalsApi.topic(topicKey))

  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={t.settingsTitle} labels={copy.loading} rows={4} onRetry={loaded.reload} />

  return (
    <Chain
      key={`${loaded.data.chain.saved_at ?? 'default'}`}
      data={loaded.data}
      onSaved={(next) => {
        loaded.set(next)
        onSaved()
      }}
    />
  )
}

function Chain({ data, onSaved }: { data: TopicState; onSaved: (next: TopicState) => void }) {
  const [steps, setSteps] = useState<Spec[]>(data.chain.steps)
  const [fallback, setFallback] = useState(data.chain.fallback_role_id ?? '')
  const [working, setWorking] = useState(false)
  const [failed, setFailed] = useState<string | null>(null)

  const set = (i: number, patch: Partial<Spec>) => setSteps((all) => all.map((x, j) => (j === i ? { ...x, ...patch } : x)))
  const move = (i: number, by: number) =>
    setSteps((all) => {
      const next = [...all]
      const [one] = next.splice(i, 1)
      if (one !== undefined) next.splice(i + by, 0, one)

      return next
    })
  const complete = steps.every((x) => x.kind === 'anyone' || (x.target !== null && x.target !== ''))

  const save = async (reset: boolean) => {
    setWorking(true)
    setFailed(null)
    const r = await approvalsApi.save(data.key, reset ? [] : steps, reset || fallback === '' ? null : fallback)
    setWorking(false)
    if (r.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: reset ? t.resetDone : t.saved })
      onSaved(r.data)
    } else setFailed(r.kind === 'invalid' ? (Object.values(r.errors)[0]?.[0] ?? s.failed) : r.kind === 'refused' ? s.refused : s.failed)
  }

  const targets = (kind: Spec['kind']) =>
    kind === 'relation' ? data.relations.map((x) => ({ value: x.key, label: x.label })) : kind === 'role' ? data.roles.map((x) => ({ value: x.id, label: x.name })) : data.people.map((x) => ({ value: x.id, label: x.name }))

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault()
        void save(false)
      }}
    >
      <div className="mb-1 flex flex-wrap items-baseline gap-2.5">
        <h2 className="type-display flex-1 text-[30px] leading-none">{data.title}</h2>
        <Tag tone="plain">{data.area}</Tag>
      </div>
      <p className="mb-1 max-w-[72ch] text-sm text-text-muted">
        {data.description} {t.chainIntro}
      </p>
      {data.chain.changed && data.chain.saved_by !== null && data.chain.saved_at !== null && <p className="mb-3 text-xs text-text-muted">{t.lastSaved(data.chain.saved_by, when(data.chain.saved_at))}</p>}
      {failed !== null && (
        <div className="mb-3">
          <Notice tone="wrong" title={failed} />
        </div>
      )}

      {steps.length === 0 && <p className="mt-3 mb-3 border-2 border-dashed border-rule-dashed bg-sheet px-3.5 py-3 text-sm">{t.noStepsNote}</p>}
      <ol className="mt-3">
        {steps.map((step, i) => {
          const last = i === steps.length - 1

          return (
            <li key={i} className="grid grid-cols-[40px_minmax(0,1fr)] gap-3">
              <span className="flex flex-col items-center">
                <span className="type-display grid size-9 place-items-center bg-ink text-xl text-sheet">{i + 1}</span>
                {!last && <span aria-hidden="true" className="mt-1 w-0.5 flex-1 bg-ink" />}
              </span>
              <div className="mb-3 border-2 border-ink bg-sheet px-3.5 py-3">
                <div className="grid gap-3 sm:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_140px]">
                  <Select label={t.whoDecides} value={step.kind} onChange={(e) => set(i, { kind: e.target.value as Spec['kind'], target: null })}>
                    {KINDS.filter((k) => k !== 'relation' || data.relations.length > 0).map((k) => (
                      <option key={k} value={k}>
                        {t.kinds[k]}
                      </option>
                    ))}
                  </Select>
                  {step.kind !== 'anyone' ? (
                    <Select label={t.which} value={step.target ?? ''} onChange={(e) => set(i, { target: e.target.value === '' ? null : e.target.value })}>
                      <option value="">{step.kind === 'person' ? t.choosePerson : step.kind === 'relation' ? t.chooseWho : t.chooseRole}</option>
                      {targets(step.kind).map((o) => (
                        <option key={o.value} value={o.value}>
                          {o.label}
                        </option>
                      ))}
                    </Select>
                  ) : (
                    <span className="hidden sm:block" />
                  )}
                  <Select label={t.howMany} value={String(step.needs)} onChange={(e) => set(i, { needs: Number(e.target.value) })}>
                    {[1, 2, 3].map((n) => (
                      <option key={n} value={n}>
                        {t.people(n)}
                      </option>
                    ))}
                  </Select>
                </div>
                <p className="mt-1.5 text-xs text-text-muted">{t.kindNotes[step.kind]}</p>
                <div className="mt-2.5 flex flex-wrap items-end gap-x-4 gap-y-2">
                  {data.facts.length > 0 && (
                    <div className="min-w-[240px] flex-1">
                      <Select label={t.onlyWhen} value={step.when ?? ''} onChange={(e) => set(i, { when: e.target.value === '' ? null : e.target.value })}>
                        <option value="">{t.always}</option>
                        {data.facts.map((f) => (
                          <option key={f.key} value={f.key}>
                            {f.label}
                          </option>
                        ))}
                      </Select>
                    </div>
                  )}
                  <span className="ml-auto flex flex-wrap gap-3 text-sm font-extrabold">
                    {i > 0 && (
                      <button type="button" aria-label={t.moveUp(i + 1)} onClick={() => move(i, -1)} className="min-h-11 underline underline-offset-[3px]">
                        {t.up}
                      </button>
                    )}
                    {!last && (
                      <button type="button" aria-label={t.moveDown(i + 1)} onClick={() => move(i, 1)} className="min-h-11 underline underline-offset-[3px]">
                        {t.down}
                      </button>
                    )}
                    {(steps.length > 1 || data.optional) && (
                      <button type="button" aria-label={t.remove(i + 1)} onClick={() => setSteps((all) => all.filter((_, j) => j !== i))} className="min-h-11 text-red-text underline underline-offset-[3px]">
                        {t.removeShort}
                      </button>
                    )}
                  </span>
                </div>
              </div>
            </li>
          )
        })}
      </ol>
      {steps.length < 8 && (
        <button
          type="button"
          onClick={() => setSteps((all) => [...all, { kind: 'anyone', target: null, needs: 1, when: null }])}
          className="mb-4 ml-[52px] h-11 border-2 border-dashed border-rule-dashed px-3 text-sm font-extrabold hover:bg-field"
        >
          {t.addStep}
        </button>
      )}

      <div className="grid max-w-[880px] gap-4 sm:ml-[52px] md:grid-cols-2">
        <div>
          <Select label={t.fallback} value={fallback} onChange={(e) => setFallback(e.target.value)}>
            <option value="">{t.fallbackNone}</option>
            {data.roles.map((r) => (
              <option key={r.id} value={r.id}>
                {r.name}
              </option>
            ))}
          </Select>
          <p className="mt-1.5 text-xs text-text-muted">{t.fallbackNote}</p>
        </div>
        <div className="border-2 border-ink bg-sheet px-3 py-2.5 text-[13px]">
          <strong className="block text-sm">{t.rulesTitle}</strong>
          {t.rules(data.permission)}
        </div>
      </div>

      <footer className="mt-5 flex flex-wrap items-center gap-3 border-t-2 border-ink pt-4 sm:ml-[52px]">
        <Button type="submit" busy={working} disabled={!complete || (steps.length === 0 && !data.optional)}>
          {t.save}
        </Button>
        {data.chain.changed && (
          <Button variant="secondary" disabled={working} onClick={() => void save(true)}>
            {t.reset}
          </Button>
        )}
        <span className="flex-[1_1_240px] text-[13px] text-text-muted">{t.saveNote}</span>
      </footer>
    </form>
  )
}
