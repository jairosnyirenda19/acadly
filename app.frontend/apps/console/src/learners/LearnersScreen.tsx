import { navigate, useLoad } from '@acadly/kernel'
import { Button, Notice, Waiting } from '@acadly/ui'
import { useMemo, useState } from 'react'
import { copy } from '../copy'
import { Input, Total, TotalsBand, nn } from '../setup/parts'
import { PageHead, Tag } from '../settings/parts'
import { useWorkIn } from '../shell/WorkIn'
import { initials, learnersApi, type LearnerRow } from './api'

const t = copy.learners

const COLS = 'grid-cols-[48px_44px_minmax(200px,1.3fr)_minmax(110px,0.6fr)_minmax(220px,1.4fr)_minmax(150px,auto)]'

/** The list of learners by class (the approved design). */
export function LearnersScreen() {
  const { place } = useWorkIn()
  const inPlace = place !== null && place.key !== 'institution:' ? place.key : null
  const loaded = useLoad(`learners:${inPlace ?? ''}`, () => learnersApi.list(inPlace))
  const state = loaded.data
  const [klass, setKlass] = useState('')
  const [search, setSearch] = useState('')

  const shown = useMemo(() => {
    if (state === undefined) return []
    const q = search.trim().toLowerCase()

    return state.learners.filter(
      (l) =>
        (klass === '' || l.class_id === klass) &&
        (q === '' || l.name.toLowerCase().includes(q) || (l.admission_number ?? '').toLowerCase().includes(q) || l.acadly_id.toLowerCase().includes(q)),
    )
  }, [state, klass, search])

  if (state === undefined) {
    return <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} band={[t.title, t.bandAdmitted, t.bandClasses, t.bandAttention]} rows={8} onRetry={loaded.reload} />
  }

  const word = state.word.toLowerCase()
  const plural = state.word_plural
  const attention = state.totals.few_contacts + state.totals.duplicates
  const inClass = klass === '' ? state.learners.length : (state.classes.find((c) => c.id === klass)?.count ?? 0)

  return (
    <>
      <PageHead
        kicker={t.kicker(inPlace !== null && place !== null ? place.label : null, state.year?.name ?? null)}
        title={plural}
        intro={t.intro(word)}
        actions={
          state.can_admit ? (
            <>
              <Button variant="secondary" onClick={() => navigate('/learners/import')}>
                {t.import}
              </Button>
              <Button onClick={() => navigate('/learners/admit')}>{t.admit(word)}</Button>
            </>
          ) : undefined
        }
      />

      {state.year === null ? (
        <div className="px-[clamp(16px,3vw,40px)] pb-8">
          <Notice tone="wrong" title={t.noYear} />
        </div>
      ) : (
        <>
          <TotalsBand>
            <Total tone="ink" label={t.bandActive(plural)} value={String(state.totals.active)} note={t.bandActiveNote} />
            <Total label={t.bandAdmitted} value={String(state.totals.admitted_this_year)} note={t.bandAdmittedNote(state.year.name)} />
            <Total label={t.bandClasses} value={String(state.totals.classes)} note={t.bandClassesNote(state.totals.classes)} />
            <Total
              tone={attention > 0 ? 'attention' : 'plain'}
              label={t.bandAttention}
              value={String(attention)}
              note={t.bandAttentionNote(state.totals.few_contacts, state.totals.duplicates)}
            />
          </TotalsBand>

          <section className="flex-1 bg-sheet px-[clamp(16px,3vw,40px)] pt-4 pb-8">
            <div className="mb-3 flex flex-wrap items-end gap-3">
              <div className="min-w-[220px] flex-[1_1_320px] md:max-w-[520px]">
                <Input label={t.find(word)} type="search" placeholder={t.findPlaceholder} value={search} onChange={(e) => setSearch(e.target.value)} />
              </div>
              <span className="ml-auto text-[13px] text-text-muted">{t.showing(shown.length, inClass)}</span>
            </div>

            <nav aria-label={t.classLabel} className="flex gap-0.5 overflow-x-auto border-b-2 border-ink [scrollbar-width:none]">
              {[{ id: '', name: t.allClasses, count: state.learners.length }, ...state.classes].map((c) => (
                <button
                  key={c.id}
                  type="button"
                  aria-pressed={klass === c.id}
                  onClick={() => setKlass(c.id)}
                  className={`flex shrink-0 items-baseline gap-2 border-b-4 px-3.5 pt-3 pb-2.5 text-[15px] whitespace-nowrap ${klass === c.id ? 'border-ink font-extrabold' : 'border-transparent font-medium hover:bg-paper'}`}
                >
                  {c.name}
                  <span className="ref text-xs text-text-muted">{c.count}</span>
                </button>
              ))}
            </nav>

            <div className="overflow-x-auto">
              <div className="min-w-[860px]">
                <div className={`label-caps grid ${COLS} gap-3 border-b-2 border-ink py-2 pr-4 text-text-muted`}>
                  <span />
                  <span />
                  <span>{t.colLearner(state.word)}</span>
                  <span>{t.colClass}</span>
                  <span>{t.colContact}</span>
                  <span className="text-right">{t.colState}</span>
                </div>
                <div className="ledger [--margin:48px]">
                  {shown.map((l, i) => (
                    <LearnerLine key={l.id} n={i + 1} learner={l} />
                  ))}
                  {shown.length === 0 && <p className="py-4 pl-[60px] text-sm text-text-muted">{state.learners.length === 0 ? t.empty(plural.toLowerCase()) : t.none}</p>}
                </div>
              </div>
            </div>
          </section>
        </>
      )}
    </>
  )
}

function LearnerLine({ n, learner }: { n: number; learner: LearnerRow }) {
  const few = learner.contacts < 2
  const attention = few || learner.possible_duplicate
  const open = () => navigate(`/learners/${learner.id}`)

  return (
    <div
      role="link"
      tabIndex={0}
      onClick={open}
      onKeyDown={(e) => {
        if (e.key === 'Enter') open()
      }}
      className={`grid cursor-pointer ${COLS} items-center gap-3 border-b border-rule py-2.5 pr-4 hover:bg-paper ${attention ? 'bg-marigold-wash' : ''}`}
    >
      <div className="type-display text-center text-lg text-text-muted">{nn(n)}</div>
      <span aria-hidden="true" className="type-display flex size-10 items-center justify-center border-2 border-ink bg-sheet text-xl">
        {initials(learner.name)}
      </span>
      <div className="min-w-0">
        <div className="text-base font-extrabold">{learner.name}</div>
        <div className="ref text-xs text-text-muted">{learner.admission_number ?? learner.acadly_id}</div>
      </div>
      <div className="text-sm font-bold">{learner.class}</div>
      <div className="text-sm">
        {learner.main_contact === null ? (
          <span className="font-bold">{t.noMainContact}</span>
        ) : (
          `${learner.main_contact.name} · ${learner.main_contact.relationship} · ${learner.main_contact.phone}`
        )}
      </div>
      <div className="flex flex-wrap justify-end gap-1">
        <Tag tone="done">{t.active}</Tag>
        {few && <Tag tone="attention">{t.fewContacts(learner.contacts)}</Tag>}
        {learner.possible_duplicate && <Tag tone="attention">{t.possibleDuplicate}</Tag>}
      </div>
    </div>
  )
}
