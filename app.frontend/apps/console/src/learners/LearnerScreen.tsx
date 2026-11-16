import { useLoad, useLocation } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useEffect, useState, type ReactNode } from 'react'
import { copy } from '../copy'
import { errorFor, type Saved } from '../setup/api'
import { Attention, Input, Select, Total, TotalsBand } from '../setup/parts'
import { day } from '../settings/api'
import { SmallButton, Tag } from '../settings/parts'
import { initials, learnersApi, today, type ContactInput, type LearnerState } from './api'
import { BackToList } from './back'
import { NoticesBy } from './NoticesBy'
import { PhotoPicker } from './PhotoPicker'
import { PART_VIEWS } from './record/registry'
import { Activity, HistoryTab, MessagesTab, Placements, useFamilyMessages } from './record/tabs'
import { Column, Columns, InLink, SectionTitle, follow, tabHref } from './record/ui'

const t = copy.learners
const r = copy.learners.record
const s = copy.settings

type Panel = 'none' | 'correct' | 'move' | 'contact' | 'photo'

/** A cell of the record's band: from a part of the record, or what the family was told. */
interface BandCell {
  label: string
  value: string
  note: string
  tone: 'plain' | 'attention'
}

/** One learner's record (the approved learner dashboard): a head, the band, and the record's tabs. */
export function LearnerScreen({ id }: { id: string }) {
  const loaded = useLoad(`learner:${id}`, () => learnersApi.record(id))
  const record = loaded.data
  const { query } = useLocation()
  const [panel, setPanel] = useState<Panel>('none')
  const [photo, setPhoto] = useState<string | null>(null)

  useEffect(() => {
    if (record === undefined || !record.has_photo) {
      setPhoto(null)

      return
    }

    let url: string | null = null
    let current = true
    void learnersApi.photoUrl(id).then((value) => {
      url = value
      if (current) setPhoto(value)
      else if (value !== null) URL.revokeObjectURL(value)
    })

    return () => {
      current = false
      if (url !== null) URL.revokeObjectURL(url)
    }
  }, [id, record?.has_photo, record])

  if (record === undefined) {
    return <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} rows={6} onRetry={loaded.reload} />
  }

  const word = record.word.toLowerCase()
  const done = (next: LearnerState, title: string) => {
    loaded.set(next)
    setPanel('none')
    slips.done({ word: copy.slips.saved, title })
  }
  const toggle = (p: Panel) => setPanel(panel === p ? 'none' : p)

  // Only the parts this console can draw get a tab; the rest wait for their view.
  const parts = record.parts.filter((p) => PART_VIEWS[p.key] !== undefined)
  const tabs = [
    { key: 'overview', label: r.tabs.overview, short: r.tabs.overview, attention: 0 },
    ...parts.map((p) => ({ key: p.key, label: p.title, short: p.title, attention: p.attention })),
    { key: 'messages', label: r.tabs.messages, short: r.shortTabs.messages, attention: 0 },
    { key: 'history', label: r.tabs.history, short: r.tabs.history, attention: 0 },
  ]
  const asked = query.get('tab') ?? 'overview'
  const tab = tabs.some((x) => x.key === asked) ? asked : 'overview'

  const cells: BandCell[] = [
    ...record.parts.flatMap((p) => p.band),
    ...(record.told.all > 0
      ? [{ label: r.toldLabel, value: String(record.told.all), note: r.toldNote(record.told.all, record.told.delivered, record.told.not_delivered), tone: record.told.not_delivered > 0 ? ('attention' as const) : ('plain' as const) }]
      : []),
  ]
  const attentionTags = record.parts.filter((p) => p.attention > 0).map((p) => ({ key: p.key, word: r.partAttention[p.key] ?? r.partAttentionOther }))
  const current = parts.find((p) => p.key === tab)
  const PartTab = current !== undefined ? PART_VIEWS[current.key]?.Tab : undefined

  return (
    <>
      <nav aria-label={t.backLabel} className="px-4 pt-2 md:px-[clamp(16px,3vw,40px)]">
        <BackToList label={t.importing.backTo(record.word_plural)} />
      </nav>

      {/* The head: photo, state, name, identifiers and what can be done */}
      <div className="flex flex-wrap items-end gap-x-5 gap-y-3 px-4 pt-1 pb-4.5 md:px-[clamp(16px,3vw,40px)]">
        <div className="flex min-w-0 flex-[1_1_420px] items-end gap-3.5 md:gap-5">
          <div className="flex shrink-0 flex-col items-center gap-1">
            {photo !== null ? (
              <img src={photo} alt={t.photoAlt(record.name)} className="h-[84px] w-16 border-2 border-ink object-cover md:h-[124px] md:w-24" />
            ) : (
              <span aria-hidden="true" className="type-display flex h-[84px] w-16 items-center justify-center border-2 border-ink bg-ink text-[30px] text-sheet md:h-[124px] md:w-24 md:text-[44px]">
                {initials(record.name)}
              </span>
            )}
            {record.can_manage && (
              <button type="button" onClick={() => toggle('photo')} className="min-h-11 text-[13px] font-bold text-ink underline underline-offset-[3px]">
                {record.has_photo ? t.changePhoto : t.choosePhoto}
              </button>
            )}
          </div>
          <div className="min-w-0 flex-1">
            <div className="mb-1.5 flex flex-wrap gap-1.5">
              <Tag tone={record.status === 'active' ? 'done' : 'plain'}>{t.states[record.status] ?? record.status}</Tag>
              {record.class !== null && (
                <Tag tone="plain">
                  {record.word} · {record.class.name}
                </Tag>
              )}
              {attentionTags.map((a) => (
                <Tag key={a.key} tone="attention">
                  {a.word}
                </Tag>
              ))}
              {record.possible_duplicate && <Tag tone="attention">{t.possibleDuplicate}</Tag>}
            </div>
            <h1 className="type-display mb-1.5 text-[clamp(40px,6vw,88px)] break-words">{record.name}</h1>
            <div className="text-[13px] text-text-muted">
              <span className="label-caps hidden md:inline">{t.admissionNumberLabel}</span> <span className="ref font-bold text-ink">{record.admission_number ?? t.notGiven}</span>{' '}
              <span aria-hidden="true">·</span> <span className="label-caps hidden md:inline">{t.acadlyId}</span> <span className="ref font-bold text-ink">{record.acadly_id}</span>
              <span className="hidden md:inline"> · {t.idsNote}</span>
            </div>
          </div>
        </div>
        {record.can_manage && (
          <div className="flex flex-wrap gap-2.5">
            {record.move_to.length > 0 && record.class !== null && <Button onClick={() => toggle('move')}>{t.changeClass}</Button>}
            <Button variant="secondary" onClick={() => toggle('correct')}>
              {t.correct}
            </Button>
          </div>
        )}
      </div>

      {record.possible_duplicate && (
        <div className="px-4 pb-4 md:px-[clamp(16px,3vw,40px)]">
          <Attention title={t.possibleDuplicate}>{t.duplicateNote}</Attention>
        </div>
      )}
      {panel === 'photo' && (
        <Box>
          <PhotoUpload id={record.id} word={word} current={photo} onDone={(next) => done(next, t.photoSaved)} onCancel={() => setPanel('none')} />
        </Box>
      )}
      {panel === 'correct' && (
        <Box>
          <CorrectForm record={record} onDone={(next) => done(next, t.corrected)} onCancel={() => setPanel('none')} />
        </Box>
      )}
      {panel === 'move' && record.class !== null && (
        <Box>
          <MoveForm record={record} onDone={(next) => done(next, t.moved)} onCancel={() => setPanel('none')} />
        </Box>
      )}

      {/* The band: each part's cells, then what the family was told. On a phone, the first three. */}
      {cells.length > 0 && (
        <>
          <div className="hidden md:block">
            <TotalsBand compact>
              {cells.map((c, i) => (
                <Total key={`${c.label}-${i}`} compact tone={i === 0 ? 'ink' : c.tone === 'attention' ? 'attention' : 'plain'} label={c.label} value={c.value} note={c.note} />
              ))}
            </TotalsBand>
          </div>
          <div className="grid grid-cols-3 border-y-2 border-ink md:hidden">
            {cells.slice(0, 3).map((c, i) => (
              <section key={`${c.label}-${i}`} className={`min-w-0 px-3 pt-2.5 pb-3 ${i === 0 ? 'bg-ink text-sheet' : 'border-l-2 border-ink'} ${i > 0 && c.tone === 'attention' ? 'bg-marigold' : ''}`}>
                <h2 className={`label-caps break-words hyphens-auto ${i === 0 ? 'text-on-ink-muted' : c.tone === 'attention' ? '' : 'text-text-muted'}`}>{c.label}</h2>
                <div className={`type-numeral text-[40px] ${i === 0 ? 'text-teal-bright' : ''}`}>{c.value}</div>
              </section>
            ))}
          </div>
        </>
      )}

      {/* The record's tabs, kept in the address */}
      <nav aria-label={r.tabsLabel} className="flex gap-1 overflow-x-auto border-b-2 border-ink bg-sheet px-2 [scrollbar-width:none] md:px-[clamp(16px,3vw,40px)]">
        {tabs.map((x) => {
          const href = tabHref(record.id, x.key)

          return (
            <a
              key={x.key}
              href={href}
              aria-current={x.key === tab ? 'page' : undefined}
              onClick={(e) => follow(e, href)}
              className={`inline-flex min-h-11 shrink-0 items-center gap-1.5 border-b-4 px-3.5 pt-2.5 pb-2 text-[15px] whitespace-nowrap text-ink no-underline md:px-4 ${x.key === tab ? 'border-ink font-extrabold' : 'border-transparent font-medium hover:bg-paper'}`}
            >
              <span className="md:hidden">{x.short}</span>
              <span className="hidden md:inline">{x.label}</span>
              {x.attention > 0 && (
                <span className="bg-marigold px-1.5 py-px text-xs font-extrabold text-ink">
                  <span aria-hidden="true">{x.attention}</span>
                  <span className="sr-only">{r.needAttention(x.attention)}</span>
                </span>
              )}
            </a>
          )
        })}
      </nav>

      <div className="flex-1 bg-sheet">
        {tab === 'overview' && <Overview record={record} panel={panel} toggle={toggle} done={done} close={() => setPanel('none')} ageOn={ageOn} />}
        {PartTab !== undefined && <PartTab learner={record} />}
        {tab === 'messages' && <MessagesTab learner={record} />}
        {tab === 'history' && <HistoryTab learner={record} />}
      </div>
    </>
  )

  function ageOn(born: string): string {
    const [by, bm, bd] = born.split('-').map(Number) as [number, number, number]
    const [ty, tm, td] = today().split('-').map(Number) as [number, number, number]
    const age = ty - by - (tm < bm || (tm === bm && td < bd) ? 1 : 0)

    return Number.isFinite(age) ? ` · ${t.age(age).replace(/\.$/, '').toLowerCase()}` : ''
  }
}

/** The overview: details, each part's block, contacts; then the class history and recent activity. */
function Overview({
  record,
  panel,
  toggle,
  done,
  close,
  ageOn,
}: {
  record: LearnerState
  panel: Panel
  toggle: (p: Panel) => void
  done: (next: LearnerState, title: string) => void
  close: () => void
  ageOn: (born: string) => string
}) {
  const family = useFamilyMessages(record.id)
  const lastTold = (name: string): string | undefined => {
    if (family.data === undefined) return undefined
    const last = family.data.messages.find((msg) => msg.to === name)

    return last === undefined ? r.neverTold : r.lastTold(day(last.at), (copy.messages.state[last.state] ?? last.state).toLowerCase())
  }

  return (
    <>
      <Columns top>
        <Column label={t.details}>
          <SectionTitle>{t.details}</SectionTitle>
          <Fact label={t.dateOfBirth} value={`${day(record.date_of_birth)}${ageOn(record.date_of_birth)}`} />
          <Fact label={t.sex} value={t.sexes[record.sex] ?? record.sex} />
          <Fact label={t.admittedLabel} value={day(record.admitted_on)} />
          <Fact label={t.level} value={[record.level, record.division].filter(Boolean).join(' · ')} />
          <Fact label={t.campus} value={record.campus ?? ''} />
          <Fact label={t.classTeacher} value={record.class_teacher ?? t.notGiven} />
          <p className="mt-3 text-[13px] text-text-muted">{t.correctionNote}</p>
        </Column>

        {record.parts.map((p) => {
          const Summary = PART_VIEWS[p.key]?.Summary

          return Summary !== undefined ? <Summary key={p.key} learner={record} /> : null
        })}

        <Column edge tinted grow="flex-[1.2_1_320px]" label={t.contactsTitle}>
          <SectionTitle aside={record.can_manage && <SmallButton onClick={() => toggle('contact')}>{t.addContactShort}</SmallButton>}>{t.contactsTitle}</SectionTitle>
          {panel === 'contact' && <ContactForm record={record} onDone={(next) => done(next, t.contactSaved)} onCancel={close} />}
          {record.contacts.map((c) => (
            <ContactLine key={c.id} record={record} contact={c} told={lastTold(c.name)} onChanged={(next, title) => done(next, title)} />
          ))}
          <p className="mt-2.5 text-[13px] text-text-muted">{t.keepTwo}</p>
          <InLink href={tabHref(record.id, 'messages')}>{r.seeMessages}</InLink>
        </Column>
      </Columns>

      <Columns top>
        <Column grow="flex-[1.3_1_340px]" label={t.atSchool}>
          <SectionTitle>{t.atSchool}</SectionTitle>
          <Placements learner={record} />
        </Column>
        <Column edge label={r.recentActivity}>
          <SectionTitle>{r.recentActivity}</SectionTitle>
          <Activity learner={record} limit={3} />
          {record.activity.length > 3 && <InLink href={tabHref(record.id, 'history')}>{r.seeHistory}</InLink>}
        </Column>
      </Columns>
    </>
  )
}

function Box({ children }: { children: ReactNode }) {
  return <div className="mx-4 mb-5 border-2 border-ink bg-sheet p-4.5 md:mx-[clamp(16px,3vw,40px)]">{children}</div>
}

function Fact({ label, value }: { label: string; value: string }) {
  return (
    <div className="border-b border-rule py-2.5">
      <div className="label-caps text-text-muted">{label}</div>
      <div className="text-[15px] font-bold">{value !== '' ? value : t.notGiven}</div>
    </div>
  )
}

/** Runs a save and shows what it came to; the result arrives in the record. */
function useSave() {
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [failed, setFailed] = useState<string | null>(null)
  const [working, setWorking] = useState(false)

  const run = async (request: Promise<Saved<LearnerState>>, onDone: (next: LearnerState) => void) => {
    setWorking(true)
    setFailed(null)
    const result = await request
    setWorking(false)

    if (result.kind === 'saved') onDone(result.data)
    else if (result.kind === 'invalid') {
      setErrors(result.errors)
      const general = errorFor(result.errors, 'contact') ?? errorFor(result.errors, 'file')
      if (general !== undefined) setFailed(general)
    } else setFailed(result.kind === 'refused' ? s.refused : s.failed)
  }

  return { errors, failed, working, run }
}

function Failed({ text }: { text: string | null }) {
  return text === null ? null : (
    <div className="mb-3">
      <Notice tone="wrong" title={text} />
    </div>
  )
}

function PhotoUpload({ id, word, current, onDone, onCancel }: { id: string; word: string; current: string | null; onDone: (next: LearnerState) => void; onCancel: () => void }) {
  const [file, setFile] = useState<File | null>(null)
  const save = useSave()

  return (
    <>
      <Failed text={save.failed} />
      <PhotoPicker word={word} file={file} onFile={setFile} current={current} />
      <div className="mt-4 flex flex-wrap gap-2.5">
        <Button busy={save.working} disabled={file === null} onClick={() => file !== null && void save.run(learnersApi.photo(id, file), onDone)}>
          {save.working ? s.saving : t.changePhoto}
        </Button>
        <Button variant="secondary" onClick={onCancel}>
          {s.cancel}
        </Button>
      </div>
    </>
  )
}

function CorrectForm({ record, onDone, onCancel }: { record: LearnerState; onDone: (next: LearnerState) => void; onCancel: () => void }) {
  const [first, setFirst] = useState(record.first_name)
  const [other, setOther] = useState(record.other_names ?? '')
  const [surname, setSurname] = useState(record.surname)
  const [sex, setSex] = useState(record.sex)
  const [born, setBorn] = useState(record.date_of_birth)
  const [number, setNumber] = useState(record.admission_number ?? '')
  const [reason, setReason] = useState('')
  const save = useSave()
  const e = save.errors

  return (
    <form
      onSubmit={(ev) => {
        ev.preventDefault()
        void save.run(learnersApi.correct(record.id, { first_name: first, other_names: other, surname, sex, date_of_birth: born, admission_number: number, reason }), onDone)
      }}
    >
      <h2 className="type-display mb-3.5 text-[22px] leading-none">{t.correctTitle}</h2>
      <Failed text={save.failed} />
      <div className="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-x-5 gap-y-4">
        <Input label={t.firstName} required value={first} onChange={(ev) => setFirst(ev.target.value)} error={errorFor(e, 'first_name')} maxLength={80} />
        <Input label={t.otherNames} value={other} onChange={(ev) => setOther(ev.target.value)} error={errorFor(e, 'other_names')} maxLength={120} />
        <Input label={t.surname} required value={surname} onChange={(ev) => setSurname(ev.target.value)} error={errorFor(e, 'surname')} maxLength={80} />
        <Select label={t.sex} required value={sex} onChange={(ev) => setSex(ev.target.value)} error={errorFor(e, 'sex')}>
          <option value="female">{t.sexes.female}</option>
          <option value="male">{t.sexes.male}</option>
        </Select>
        <Input label={t.dateOfBirth} required type="date" max={today()} value={born} onChange={(ev) => setBorn(ev.target.value)} error={errorFor(e, 'date_of_birth')} />
        <Input label={t.admissionNumber} value={number} onChange={(ev) => setNumber(ev.target.value)} error={errorFor(e, 'admission_number')} maxLength={40} />
        <Input label={t.correctReason} required wide value={reason} onChange={(ev) => setReason(ev.target.value)} error={errorFor(e, 'reason')} maxLength={255} />
      </div>
      <div className="mt-4 flex flex-wrap gap-2.5">
        <Button type="submit" busy={save.working}>
          {save.working ? s.saving : t.correctSubmit}
        </Button>
        <Button variant="secondary" onClick={onCancel}>
          {s.cancel}
        </Button>
      </div>
    </form>
  )
}

function MoveForm({ record, onDone, onCancel }: { record: LearnerState; onDone: (next: LearnerState) => void; onCancel: () => void }) {
  const [to, setTo] = useState(record.move_to.length === 1 ? (record.move_to[0]?.id ?? '') : '')
  const [from, setFrom] = useState(today())
  const [reason, setReason] = useState('')
  const save = useSave()
  const target = record.move_to.find((c) => c.id === to)?.name ?? ''

  return (
    <form
      onSubmit={(ev) => {
        ev.preventDefault()
        void save.run(learnersApi.move(record.id, { class_id: to, from, reason }), onDone)
      }}
      className="mb-5.5 border-2 border-ink bg-sheet px-4 pt-3.5 pb-4"
    >
      <div className="label-caps text-text-muted">{t.moveTitle}</div>
      <div className="type-display mt-1 mb-3 text-[22px] leading-none">{t.moveFrom(record.class?.name ?? '')}</div>
      <Failed text={save.failed} />
      <div className="grid gap-3.5">
        <Select label={t.moveTo} required value={to} onChange={(ev) => setTo(ev.target.value)} error={errorFor(save.errors, 'class_id')} hint={t.moveToHint}>
          <option value="">{t.choose}</option>
          {record.move_to.map((c) => (
            <option key={c.id} value={c.id}>
              {t.moveOption(c.name, c.count)}
            </option>
          ))}
        </Select>
        <Input label={t.moveDate} required type="date" max={today()} value={from} onChange={(ev) => setFrom(ev.target.value)} error={errorFor(save.errors, 'from')} />
        <Input label={t.reason} required value={reason} onChange={(ev) => setReason(ev.target.value)} error={errorFor(save.errors, 'reason')} maxLength={255} />
        <div className="flex flex-wrap gap-2">
          <Button type="submit" busy={save.working}>
            {save.working ? s.saving : t.moveSubmit(target)}
          </Button>
          <Button variant="secondary" onClick={onCancel}>
            {s.cancel}
          </Button>
        </div>
      </div>
    </form>
  )
}

function ContactForm({ record, onDone, onCancel }: { record: LearnerState; onDone: (next: LearnerState) => void; onCancel: () => void }) {
  const [contact, setContact] = useState<ContactInput>({ name: '', relationship: '', phone: '', email: '', notices_by: 'sms', main: false })
  const save = useSave()
  const e = save.errors
  const set = (patch: Partial<ContactInput>) => setContact((c) => ({ ...c, ...patch }))

  return (
    <form
      onSubmit={(ev) => {
        ev.preventDefault()
        void save.run(learnersApi.addContact(record.id, contact), onDone)
      }}
      className="my-3 grid gap-3 border-2 border-ink bg-sheet p-3.5"
    >
      <Failed text={save.failed} />
      <Input label={t.fullName} required value={contact.name} onChange={(ev) => set({ name: ev.target.value })} error={errorFor(e, 'contacts.0.name')} maxLength={120} />
      <div className="grid grid-cols-2 gap-3">
        <Select label={t.relationship} required value={contact.relationship} onChange={(ev) => set({ relationship: ev.target.value })} error={errorFor(e, 'contacts.0.relationship')}>
          <option value="">{t.choose}</option>
          {record.relationships.map((r) => (
            <option key={r.value} value={r.value}>
              {r.label}
            </option>
          ))}
        </Select>
        <Input label={t.phone} required type="tel" value={contact.phone} onChange={(ev) => set({ phone: ev.target.value })} error={errorFor(e, 'contacts.0.phone')} maxLength={30} />
      </div>
      <Input label={t.email} type="email" value={contact.email} onChange={(ev) => set({ email: ev.target.value, ...(ev.target.value.trim() === '' ? { notices_by: 'sms' as const } : {}) })} error={errorFor(e, 'contacts.0.email')} />
      <NoticesBy value={contact.notices_by} hasEmail={contact.email.trim() !== ''} onChange={(next) => set({ notices_by: next })} error={errorFor(e, 'contacts.0.notices_by')} />
      <label className="flex items-center gap-2.5 text-sm font-bold">
        <input type="checkbox" checked={contact.main} onChange={(ev) => set({ main: ev.target.checked })} className="size-[18px] accent-teal" />
        {t.mainContact}
      </label>
      <div className="flex flex-wrap gap-2">
        <Button type="submit" busy={save.working}>
          {save.working ? s.saving : t.saveContact}
        </Button>
        <Button variant="secondary" onClick={onCancel}>
          {s.cancel}
        </Button>
      </div>
    </form>
  )
}

function ContactLine({
  record,
  contact,
  told,
  onChanged,
}: {
  record: LearnerState
  contact: LearnerState['contacts'][number]
  /** When the contact was last told, from the messages to the family. */
  told?: string | undefined
  onChanged: (next: LearnerState, title: string) => void
}) {
  const [removing, setRemoving] = useState(false)
  const [reason, setReason] = useState('')
  const save = useSave()
  const canRemove = record.contacts.length > 2

  return (
    <div className="border-b border-rule py-3">
      <div className="flex flex-wrap items-baseline gap-2">
        <span className="text-base font-extrabold">{contact.name}</span>
        <span className="text-[13px] text-text-muted">
          {contact.relationship_label}
          {contact.main ? ` · ${t.mainContact}` : ''}
        </span>
      </div>
      <div className="ref mt-0.5 text-[13px] break-all">{[contact.phone, contact.email].filter(Boolean).join(' · ')}</div>
      {contact.main && (
        <a href={`tel:${contact.phone.replace(/\s+/g, '')}`} className="mt-2 inline-flex h-11 items-center bg-teal px-4 text-[15px] font-extrabold text-sheet no-underline hover:bg-ink md:hidden">
          {t.record.call(contact.phone)}
        </a>
      )}
      <div className="mt-1.5 flex flex-wrap items-center gap-1.5">
        <Tag tone="plain">{t.contactOnly}</Tag>
        <Tag tone="plain">{t.noticesTag(contact.notices_by)}</Tag>
        {told !== undefined && <span className="text-[13px] text-text-muted">{told}</span>}
        {record.can_manage && (contact.notices_by === 'email' || contact.email !== null) && (
          <SmallButton
            disabled={save.working}
            onClick={() => void save.run(learnersApi.noticesBy(record.id, contact.id, contact.notices_by === 'email' ? 'sms' : 'email'), (next) => onChanged(next, t.noticesSaved))}
          >
            {contact.notices_by === 'email' ? t.useSms : t.useEmail}
          </SmallButton>
        )}
        {record.can_manage && !contact.main && (
          <SmallButton disabled={save.working} onClick={() => void save.run(learnersApi.mainContact(record.id, contact.id), (next) => onChanged(next, t.contactSaved))}>
            {t.makeMain}
          </SmallButton>
        )}
        {record.can_manage && canRemove && !removing && (
          <SmallButton tone="red" onClick={() => setRemoving(true)}>
            {t.removeContact}
          </SmallButton>
        )}
      </div>
      {save.failed !== null && <p className="mt-1.5 text-[13px] font-bold text-red-text">{save.failed}</p>}
      {removing && (
        <form
          onSubmit={(ev) => {
            ev.preventDefault()
            void save.run(learnersApi.removeContact(record.id, contact.id, reason), (next) => onChanged(next, t.contactRemoved))
          }}
          className="mt-2.5 grid gap-2.5"
        >
          <Input label={t.removeReason} required value={reason} onChange={(ev) => setReason(ev.target.value)} error={errorFor(save.errors, 'reason')} maxLength={255} />
          <div className="flex flex-wrap gap-2">
            <SmallButton tone="red" disabled={save.working} onClick={() => void save.run(learnersApi.removeContact(record.id, contact.id, reason), (next) => onChanged(next, t.contactRemoved))}>
              {t.removeSubmit}
            </SmallButton>
            <SmallButton onClick={() => setRemoving(false)}>{s.cancel}</SmallButton>
          </div>
        </form>
      )}
    </div>
  )
}
