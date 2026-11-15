import { Button, Notice } from '@acadly/ui'
import { useEffect, useState } from 'react'
import { copy } from '../copy'
import { errorFor } from '../setup/api'
import { Input, SectionTitle, Select, Total, TotalsBand, nn } from '../setup/parts'
import { academicsApi, type Subject, type SubjectLevel, type SubjectsState } from './academicsApi'
import { PageHead, SettingsTabs, Tag } from './parts'

const t = copy.settings.subjects
const s = copy.settings

type Panel = { mode: 'add' } | { mode: 'edit'; subject: Subject } | { mode: 'retire' }

const COLS = 'grid-cols-[48px_minmax(200px,1.4fr)_90px_minmax(230px,1.6fr)_130px_90px]'

export function SubjectsScreen() {
  const [state, setState] = useState<SubjectsState | 'refused' | null | undefined>(undefined)
  const [tab, setTab] = useState('')
  const [panel, setPanel] = useState<Panel>({ mode: 'add' })
  const [message, setMessage] = useState<{ tone: 'done' | 'wrong'; text: string } | null>(null)
  const [working, setWorking] = useState(false)

  useEffect(() => {
    void academicsApi.subjects().then(setState)
  }, [])

  if (state === undefined) return <SettingsTabs current="subjects" />
  if (state === null || state === 'refused') {
    return (
      <>
        <SettingsTabs current="subjects" />
        <div className="p-[clamp(16px,3vw,40px)]">
          <Notice tone="wrong" title={state === 'refused' ? s.refused : s.loadFailed} />
        </div>
      </>
    )
  }

  const level = state.levels.find((l) => l.division_id === tab) ?? state.levels[0]
  const outcome = (result: { kind: string; data?: SubjectsState }, done?: string) => {
    if (result.kind === 'saved' && result.data !== undefined) {
      setState(result.data)
      setMessage(done !== undefined ? { tone: 'done', text: done } : null)
      return true
    }
    setMessage({ tone: 'wrong', text: result.kind === 'refused' ? s.refused : s.failed })
    return false
  }

  const quick = async (subject: Subject, change: Partial<Pick<Subject, 'core' | 'levels'>>) => {
    if (level === undefined) return
    setWorking(true)
    const result = await academicsApi.saveSubject(subject.id, {
      division_id: level.division_id,
      name: subject.name,
      short: subject.short,
      core: change.core ?? subject.core,
      levels: change.levels ?? subject.levels,
    })
    setWorking(false)
    if (result.kind === 'invalid') setMessage({ tone: 'wrong', text: Object.values(result.errors)[0]?.[0] ?? s.failed })
    else outcome(result)
  }

  return (
    <>
      <SettingsTabs current="subjects" />
      <PageHead
        kicker={`${s.kicker} · ${t.title}`}
        title={t.title}
        intro={t.intro}
        actions={
          level !== undefined && level.subjects.length > 0 ? (
            <Button variant="secondary" onClick={() => setPanel({ mode: 'retire' })}>
              {t.retireOpen}
            </Button>
          ) : undefined
        }
      />

      {level !== undefined && <Band level={level} />}

      <div className="flex flex-1 flex-wrap items-stretch">
        <section className="min-w-0 flex-[3_1_640px] bg-sheet px-[clamp(16px,3vw,40px)] pb-8">
          <nav aria-label={copy.settings.give.where} className="mb-1 flex flex-wrap gap-1 border-b-2 border-ink">
            {state.levels.map((l) => {
              const current = l.division_id === level?.division_id

              return (
                <button
                  key={l.division_id}
                  type="button"
                  aria-current={current ? 'page' : undefined}
                  onClick={() => {
                    setTab(l.division_id)
                    setPanel({ mode: 'add' })
                    setMessage(null)
                  }}
                  className={`border-b-4 px-4 pt-3 pb-2.5 text-[15px] ${current ? 'border-ink font-extrabold' : 'border-transparent font-medium'}`}
                >
                  {l.division} <span className="ref text-xs text-text-muted">{t.tabNote(l.subjects.length, l.subject_word_plural)}</span>
                </button>
              )
            })}
          </nav>

          {message !== null && (
            <div className="my-3">
              <Notice tone={message.tone} title={message.text} />
            </div>
          )}

          {level !== undefined && level.subjects.length === 0 && level.starter.length > 0 && (
            <div className="my-5 border-2 border-ink bg-paper p-4.5">
              <h2 className="type-display text-[26px] leading-none">{t.emptyTitle(level.division)}</h2>
              <p className="mt-1.5 mb-3 max-w-[60ch] text-sm text-text-muted">{t.emptyText(level.starter.length, level.subject_word_plural)}</p>
              <p className="mb-4 text-sm font-bold">{level.starter.map((x) => x.name).join(' · ')}</p>
              <Button
                disabled={working}
                onClick={() => {
                  setWorking(true)
                  void academicsApi.start(level.division_id).then((r) => {
                    setWorking(false)
                    outcome(r)
                  })
                }}
              >
                {t.useStarter(level.starter.length)}
              </Button>
            </div>
          )}

          {level !== undefined && level.subjects.length > 0 && (
            <>
              <div className="overflow-x-auto">
                <div className="min-w-[760px]">
                  <div className={`label-caps grid ${COLS} gap-3 border-b-2 border-ink pt-2.5 pr-4 pb-2 text-text-muted`}>
                    <span />
                    <span>{level.subject_word}</span>
                    <span>{t.short}</span>
                    <span>{t.takenIn}</span>
                    <span>{t.kind}</span>
                    <span className="text-right">{t.classes}</span>
                  </div>
                  <div className="ledger [--margin:48px]">
                    {level.subjects.map((subject, i) => (
                      <div key={subject.id} className={`grid ${COLS} items-center gap-3 border-b border-rule py-3 pr-4 ${subject.levels.length === 0 ? 'bg-marigold-wash' : ''}`}>
                        <div className="type-display text-center text-xl text-text-muted">{nn(i + 1)}</div>
                        <div>
                          <button type="button" aria-label={t.edit(subject.name)} onClick={() => setPanel({ mode: 'edit', subject })} className="text-left text-[17px] font-extrabold hover:underline">
                            {subject.name}
                          </button>
                          {subject.levels.length === 0 && (
                            <div className="mt-1">
                              <Tag tone="attention">{t.noLevel}</Tag>
                            </div>
                          )}
                        </div>
                        <div className="ref text-[13px] font-bold">{subject.short}</div>
                        <div className="flex flex-wrap gap-1">
                          {level.levels.map((l) => {
                            const on = subject.levels.includes(l.code)

                            return (
                              <button
                                key={l.code}
                                type="button"
                                aria-pressed={on}
                                aria-label={t.toggle(l.label, on)}
                                title={l.label}
                                disabled={working}
                                onClick={() => void quick(subject, { levels: on ? subject.levels.filter((c) => c !== l.code) : [...subject.levels, l.code] })}
                                className={`h-8 min-w-11 border-2 px-2 text-[13px] font-extrabold ${on ? 'border-teal bg-teal text-sheet' : 'border-rule-dashed bg-sheet text-ink'}`}
                              >
                                {short(l.label)}
                              </button>
                            )
                          })}
                        </div>
                        <div>
                          <button type="button" aria-label={t.kindToggle(subject.core ? t.core : t.optional)} disabled={working} onClick={() => void quick(subject, { core: !subject.core })}>
                            <Tag tone={subject.core ? 'done' : 'plain'}>{subject.core ? t.core : t.optional}</Tag>
                          </button>
                        </div>
                        <div className="type-numeral text-right text-[32px]">
                          {subject.classes}
                          <span className="label-caps block text-text-muted">{t.classesUnit}</span>
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              </div>
              <p className="mt-3.5 text-[13px] text-text-muted">{t.footNote(level.division)}</p>
            </>
          )}
        </section>

        <section className="min-w-0 flex-[2_1_360px] border-l-2 border-ink px-[clamp(16px,3vw,40px)] pt-6 pb-8">
          {level !== undefined && (
            <SidePanel
              key={`${level.division_id}:${panel.mode}:${panel.mode === 'edit' ? panel.subject.id : ''}`}
              level={level}
              panel={panel}
              onDone={(data, done) => {
                setState(data)
                setPanel({ mode: 'add' })
                setMessage({ tone: 'done', text: done })
              }}
              onCancel={() => setPanel({ mode: 'add' })}
            />
          )}
          <div className="my-6.5 h-0.5 bg-ink" />
          <div className="label-caps mb-2 text-text-muted">{t.whoTitle}</div>
          <p className="text-sm leading-normal">{t.whoText}</p>
        </section>
      </div>
    </>
  )
}

/** A level's short label for a toggle: Form 1 → F1, Standard 4 → S4. */
function short(label: string): string {
  const parts = label.split(/\s+/)

  return parts.length > 1 ? `${parts[0]?.[0] ?? ''}${parts.slice(1).join(' ')}` : label
}

function Band({ level }: { level: SubjectLevel }) {
  const core = level.subjects.filter((x) => x.core).length
  const none = level.subjects.filter((x) => x.levels.length === 0).map((x) => x.name)

  return (
    <TotalsBand>
      <Total tone="ink" label={t.bandAll(level.division, level.subject_word_plural)} value={String(level.subjects.length)} note={t.bandAllNote} />
      <Total label={t.bandCore} value={String(core)} note={t.bandCoreNote} />
      <Total label={t.bandOptional} value={String(level.subjects.length - core)} note={t.bandOptionalNote} />
      <Total tone={none.length > 0 ? 'attention' : 'plain'} label={t.bandAttention} value={String(none.length)} note={t.bandAttentionNote(none)} />
    </TotalsBand>
  )
}

function SidePanel({ level, panel, onDone, onCancel }: { level: SubjectLevel; panel: Panel; onDone: (data: SubjectsState, done: string) => void; onCancel: () => void }) {
  const editing = panel.mode === 'edit' ? panel.subject : null
  const [name, setName] = useState(editing?.name ?? '')
  const [shortName, setShortName] = useState(editing?.short ?? '')
  const [levels, setLevels] = useState<string[]>(editing?.levels ?? level.levels.map((l) => l.code))
  const [core, setCore] = useState(editing?.core ?? true)
  const [subject, setSubject] = useState('')
  const [reason, setReason] = useState('')
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [failed, setFailed] = useState<string | null>(null)
  const [working, setWorking] = useState(false)

  const finish = (result: { kind: string; data?: SubjectsState; errors?: Record<string, string[]> }, done: string) => {
    setWorking(false)
    if (result.kind === 'saved' && result.data !== undefined) onDone(result.data, done)
    else if (result.kind === 'invalid') setErrors(result.errors ?? {})
    else setFailed(result.kind === 'refused' ? s.refused : s.failed)
  }

  if (panel.mode === 'retire') {
    return (
      <form
        onSubmit={(e) => {
          e.preventDefault()
          setWorking(true)
          void academicsApi.retire(subject, reason).then((r) => finish(r, t.retired))
        }}
        className="flex flex-col gap-4"
      >
        <SectionTitle>{t.retireTitle}</SectionTitle>
        {failed !== null && <Notice tone="wrong" title={failed} />}
        <Select label={level.subject_word} required value={subject} onChange={(e) => setSubject(e.target.value)} error={errorFor(errors, 'subject')}>
          <option value="">{t.choose}</option>
          {level.subjects.map((x) => (
            <option key={x.id} value={x.id}>
              {x.name}
            </option>
          ))}
        </Select>
        <Input label={t.reason} required value={reason} onChange={(e) => setReason(e.target.value)} error={errorFor(errors, 'reason')} maxLength={500} />
        <p className="text-[13px] text-text-muted">{t.retireNote}</p>
        <div className="flex flex-wrap gap-2.5">
          <Button type="submit" disabled={working || subject === ''}>
            {working ? s.saving : t.retire}
          </Button>
          <Button variant="secondary" onClick={onCancel}>
            {s.cancel}
          </Button>
        </div>
      </form>
    )
  }

  const body = { division_id: level.division_id, name, short: shortName, core, levels }

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault()
        setWorking(true)
        void (editing !== null ? academicsApi.saveSubject(editing.id, body) : academicsApi.addSubject(body)).then((r) => finish(r, t.saved))
      }}
      className="flex flex-col gap-4"
    >
      <SectionTitle>{editing !== null ? t.changeTitle : t.addTitle}</SectionTitle>
      {failed !== null && <Notice tone="wrong" title={failed} />}
      <Input label={t.name} required value={name} onChange={(e) => setName(e.target.value)} error={errorFor(errors, 'name')} maxLength={60} />
      <Input
        label={t.shortName}
        required
        hint={t.shortHint}
        value={shortName}
        onChange={(e) => setShortName(e.target.value.toUpperCase())}
        error={errorFor(errors, 'short')}
        maxLength={4}
        className="ref"
      />
      <div>
        <div className="label-caps mb-1.5 text-text-muted">{t.takenIn}</div>
        <div className="flex flex-wrap gap-1">
          {level.levels.map((l) => {
            const on = levels.includes(l.code)

            return (
              <button
                key={l.code}
                type="button"
                aria-pressed={on}
                onClick={() => setLevels(on ? levels.filter((c) => c !== l.code) : [...levels, l.code])}
                className={`h-8 border-2 px-2 text-[13px] font-extrabold ${on ? 'border-teal bg-teal text-sheet' : 'border-rule-dashed bg-sheet text-ink'}`}
              >
                {l.label}
              </button>
            )
          })}
        </div>
        {errorFor(errors, 'levels') !== undefined && <div className="mt-1.5 text-[13px] font-bold text-red-text">{errorFor(errors, 'levels')}</div>}
      </div>
      <div role="radiogroup" aria-label={t.kind} className="flex h-11 w-max border-2 border-ink">
        {[true, false].map((value, i) => (
          <button
            key={String(value)}
            type="button"
            role="radio"
            aria-checked={core === value}
            onClick={() => setCore(value)}
            className={`px-4 text-sm ${i > 0 ? 'border-l-2 border-ink' : ''} ${core === value ? 'bg-ink font-extrabold text-sheet' : 'font-bold'}`}
          >
            {value ? t.core : t.optional}
          </button>
        ))}
      </div>
      <div className="flex flex-wrap gap-2.5">
        <Button type="submit" disabled={working}>
          {working ? s.saving : editing !== null ? t.save : t.add}
        </Button>
        {editing !== null && (
          <Button variant="secondary" onClick={onCancel}>
            {s.cancel}
          </Button>
        )}
      </div>
    </form>
  )
}
