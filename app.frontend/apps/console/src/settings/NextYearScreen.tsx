import { api, useLoad, type paths } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { errorFor, read, settle } from '../setup/api'
import { Input } from '../setup/parts'
import { PageHead, Tag } from './parts'

const t = copy.nextYear

type State = paths['/v1/academics/next-year']['get']['responses'][200]['content']['application/json']
type Next = NonNullable<State['next']>

/** The streams as people type them: "A, B" is two classes; empty is one class with no stream. */
const toText = (streams: string[]) => streams.join(', ')
const toList = (text: string) =>
  text
    .split(',')
    .map((s) => s.trim())
    .filter((s) => s !== '')

/**
 * The next school year (the approved design, D-088): its dates, the terms of
 * each level and the classes of each level at each campus, a copy of this
 * year to change. Learners move in at the year-end run.
 */
export function NextYearScreen() {
  const loaded = useLoad('settings:next-year', () => read(api.GET('/v1/academics/next-year')))

  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} rows={6} onRetry={loaded.reload} />

  const state = loaded.data

  if (state.current === null || state.next === null) {
    return (
      <div className="flex flex-1 flex-col bg-paper">
        <PageHead kicker={t.kicker} title={t.title} intro="" />
        <div className="px-[clamp(16px,3vw,40px)]">
          <Notice tone="wrong" title={t.noYear} />
        </div>
      </div>
    )
  }

  return <Form key={state.next.id ?? 'proposed'} current={state.current} next={state.next} onSaved={loaded.set} />
}

function Form({ current, next, onSaved }: { current: NonNullable<State['current']>; next: Next; onSaved: (s: State) => void }) {
  const [name, setName] = useState(next.name)
  const [starts, setStarts] = useState(next.starts_on)
  const [ends, setEnds] = useState(next.ends_on)
  const [terms, setTerms] = useState(next.terms)
  const [classes, setClasses] = useState(() =>
    Object.fromEntries(next.classes.flatMap((o) => o.levels.map((l) => [`${o.division_id}|${o.campus_id}|${l.code}`, toText(l.next)]))),
  )
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [failed, setFailed] = useState(false)
  const [working, setWorking] = useState(false)

  const save = async () => {
    setWorking(true)
    setFailed(false)
    const body = {
      name,
      starts_on: starts,
      ends_on: ends,
      terms: terms.map((d) => ({ division_id: d.division_id, terms: d.terms })),
      classes: next.classes.map((o) => ({
        division_id: o.division_id,
        campus_id: o.campus_id,
        streams: Object.fromEntries(o.levels.map((l) => [l.code, toList(classes[`${o.division_id}|${o.campus_id}|${l.code}`] ?? '')])),
      })),
    }
    const result = await settle(api.PUT('/v1/academics/next-year', { body: body as never }))
    setWorking(false)

    if (result.kind === 'saved') {
      setErrors({})
      onSaved(result.data as State)
      slips.done({ word: copy.slips.saved, title: t.saved(name) })
    } else if (result.kind === 'invalid') setErrors(result.errors)
    else setFailed(true)
  }

  const setTerm = (division: string, i: number, field: 'name' | 'starts_on' | 'ends_on', value: string) =>
    setTerms(terms.map((d) => (d.division_id !== division ? d : { ...d, terms: d.terms.map((term, j) => (j === i ? { ...term, [field]: value } : term)) })))

  const firstError = Object.values(errors)[0]?.[0]

  return (
    <div className="flex flex-1 flex-col bg-paper">
      <PageHead
        kicker={t.kicker}
        title={t.title}
        intro={t.intro(current.name)}
        actions={
          <>
            {next.saved && <Tag tone="done">✓ {t.savedTag}</Tag>}
            <Button busy={working} onClick={() => void save()}>
              {next.saved ? t.saveAgain : t.create(name || t.title)}
            </Button>
          </>
        }
      />
      {(failed || firstError !== undefined) && (
        <div className="px-[clamp(16px,3vw,40px)] pb-3">
          <Notice tone="wrong" title={firstError ?? copy.settings.failed} />
        </div>
      )}
      <div className="flex flex-1 flex-wrap items-stretch border-t-2 border-ink">
        <section className="min-w-0 flex-[1_1_480px] bg-sheet px-[clamp(16px,3vw,32px)] pt-4 pb-6">
          <h2 className="type-display mb-2 text-[26px] leading-[0.9]">{t.theYear}</h2>
          <div className="grid gap-3">
            <Input label={t.name} hint={t.nameHint(current.name)} value={name} maxLength={40} onChange={(e) => setName(e.target.value)} error={errorFor(errors, 'name')} required />
            <div className="grid grid-cols-2 gap-3">
              <Input label={t.starts} type="date" value={starts} onChange={(e) => setStarts(e.target.value)} error={errorFor(errors, 'starts_on')} required />
              <Input label={t.ends} type="date" value={ends} onChange={(e) => setEnds(e.target.value)} error={errorFor(errors, 'ends_on')} required />
            </div>
          </div>
          {terms.map((d) => (
            <div key={d.division_id} className="mt-5">
              <h2 className="type-display mb-2 text-[22px] leading-[0.9]">{t.termsOf(d.division)}</h2>
              <div className="grid gap-2">
                {d.terms.map((term, i) => (
                  <div key={i} className="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)] gap-2">
                    <Input label={t.termName} value={term.name} maxLength={40} onChange={(e) => setTerm(d.division_id, i, 'name', e.target.value)} />
                    <Input label={t.starts} type="date" value={term.starts_on} onChange={(e) => setTerm(d.division_id, i, 'starts_on', e.target.value)} />
                    <Input label={t.ends} type="date" value={term.ends_on} onChange={(e) => setTerm(d.division_id, i, 'ends_on', e.target.value)} />
                  </div>
                ))}
              </div>
            </div>
          ))}
        </section>
        <section className="min-w-0 flex-[1_1_520px] bg-paper px-[clamp(16px,3vw,32px)] pt-4 pb-6 max-[1000px]:border-t-2 min-[1001px]:border-l-2 border-ink">
          {next.classes.map((o) => (
            <div key={`${o.division_id}|${o.campus_id}`} className="mb-5">
              <h2 className="type-display mb-1 text-[22px] leading-[0.9]">{t.classesAt(o.division, o.campus)}</h2>
              <div className="label-caps grid grid-cols-[140px_minmax(0,1fr)_minmax(0,1.2fr)] gap-3 border-b-2 border-ink pb-1.5 text-text-muted">
                <span>{t.level}</span>
                <span>{current.name}</span>
                <span>{name || t.title}</span>
              </div>
              {o.levels.map((l) => {
                const key = `${o.division_id}|${o.campus_id}|${l.code}`

                return (
                  <label key={key} className="grid grid-cols-[140px_minmax(0,1fr)_minmax(0,1.2fr)] items-center gap-3 border-b border-rule py-1.5">
                    <span className="text-[15px] font-extrabold">{l.label}</span>
                    <span className="text-sm text-text-muted">{l.now.length > 0 ? l.now.join(', ') : t.oneClass}</span>
                    <input
                      value={classes[key] ?? ''}
                      onChange={(e) => setClasses({ ...classes, [key]: e.target.value })}
                      placeholder={t.oneClass}
                      aria-label={t.streamsOf(l.label)}
                      className="h-10 border-2 border-ink bg-sheet px-2.5 text-[15px]"
                    />
                  </label>
                )
              })}
            </div>
          ))}
          <p className="text-[13px] leading-snug text-text-muted">{t.classesNote}</p>
        </section>
      </div>
    </div>
  )
}
