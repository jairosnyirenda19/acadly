import { useLoad } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useEffect, useState, type ReactNode } from 'react'
import { copy } from '../copy'
import { errorFor, type Saved } from '../setup/api'
import { Attention, Input, Select } from '../setup/parts'
import { day } from '../settings/api'
import { SmallButton, Tag } from '../settings/parts'
import { initials, learnersApi, today, type ContactInput, type LearnerState } from './api'
import { NoticesBy } from './NoticesBy'
import { PhotoPicker } from './PhotoPicker'

const t = copy.learners
const s = copy.settings

type Panel = 'none' | 'correct' | 'move' | 'contact' | 'photo'

/** One learner's record (the approved design): details, the class history, contacts and activity. */
export function LearnerScreen({ id }: { id: string }) {
  const loaded = useLoad(`learner:${id}`, () => learnersApi.record(id))
  const record = loaded.data
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

  return (
    <>
      <div className="flex flex-wrap items-end gap-5 px-[clamp(16px,3vw,40px)] pt-6 pb-5">
        <div className="flex shrink-0 flex-col items-center gap-1.5">
          {photo !== null ? (
            <img src={photo} alt={t.photoAlt(record.name)} className="h-[124px] w-24 border-2 border-ink object-cover" />
          ) : (
            <span aria-hidden="true" className="type-display flex h-[124px] w-24 items-center justify-center border-2 border-ink bg-ink text-[44px] text-sheet">
              {initials(record.name)}
            </span>
          )}
          {record.can_manage && (
            <button type="button" onClick={() => toggle('photo')} className="text-[13px] font-bold text-teal underline">
              {record.has_photo ? t.changePhoto : t.choosePhoto}
            </button>
          )}
        </div>
        <div className="min-w-0 flex-[1_1_420px]">
          <div className="mb-1.5 flex flex-wrap gap-1.5">
            <Tag tone={record.status === 'active' ? 'done' : 'plain'}>{t.states[record.status] ?? record.status}</Tag>
            {record.class !== null && (
              <Tag tone="plain">
                {record.word} · {record.class.name}
              </Tag>
            )}
            {record.possible_duplicate && <Tag tone="attention">{t.possibleDuplicate}</Tag>}
          </div>
          <h1 className="type-display mb-2 text-[clamp(48px,6vw,80px)] break-words">{record.name}</h1>
          <div className="text-sm">
            <span className="label-caps text-text-muted">{t.admissionNumberLabel}</span> <span className="ref font-bold">{record.admission_number ?? t.notGiven}</span>{' '}
            <span className="text-text-muted">·</span> <span className="label-caps text-text-muted">{t.acadlyId}</span> <span className="ref font-bold">{record.acadly_id}</span>{' '}
            <span className="text-text-muted">· {t.idsNote}</span>
          </div>
        </div>
        {record.can_manage && (
          <div className="flex flex-wrap gap-2.5">
            <Button variant="secondary" onClick={() => toggle('correct')}>
              {t.correct}
            </Button>
            {record.move_to.length > 0 && <Button onClick={() => toggle('move')}>{t.changeClass}</Button>}
          </div>
        )}
      </div>

      {record.possible_duplicate && (
        <div className="px-[clamp(16px,3vw,40px)] pb-4">
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

      <div className="flex flex-1 flex-wrap border-y-2 border-ink bg-sheet">
        <Column>
          <h2 className="type-display mb-1.5 text-[26px] leading-none">{t.details}</h2>
          <Fact label={t.dateOfBirth} value={`${day(record.date_of_birth)}${ageOn(record.date_of_birth)}`} />
          <Fact label={t.sex} value={t.sexes[record.sex] ?? record.sex} />
          <Fact label={t.admittedLabel} value={day(record.admitted_on)} />
          <Fact label={t.level} value={[record.level, record.division].filter(Boolean).join(' · ')} />
          <Fact label={t.campus} value={record.campus ?? ''} />
          <Fact label={t.classTeacher} value={record.class_teacher ?? t.notGiven} />
          <p className="mt-3 text-[13px] text-text-muted">{t.correctionNote}</p>
        </Column>

        <Column edge wide>
          <h2 className="type-display mb-4 text-[26px] leading-none">{t.atSchool}</h2>
          <ol className="relative">
            <span aria-hidden="true" className="absolute top-2.5 bottom-6 left-[7px] w-0.5 bg-ink" />
            {record.placements.map((p, i) => (
              <li key={i} className="relative pb-4.5 pl-[30px]">
                <span aria-hidden="true" className={`absolute top-0.5 left-0 size-4 border-2 border-ink ${p.now ? 'bg-teal' : 'bg-sheet'}`} />
                <div className="flex flex-wrap items-baseline gap-2">
                  <span className="type-display text-[22px] leading-none">{p.class}</span>
                  <Tag tone={p.now ? 'done' : 'plain'}>{p.now ? t.now : t.ended}</Tag>
                </div>
                <div className="mt-1 text-sm font-bold">
                  {[p.campus, p.year, p.ends_on === null ? t.since(day(p.starts_on)) : t.between(day(p.starts_on), day(p.ends_on))].filter(Boolean).join(' · ')}
                </div>
                <div className="text-[13px] text-text-muted">
                  {t.entry[p.entry_reason] ?? ''} {p.reason !== null ? t.reasonWas(p.reason) : ''}
                </div>
              </li>
            ))}
          </ol>
          <p className="text-[13px] text-text-muted">{t.timelineNote}</p>
        </Column>

        <Column edge tinted>
          {panel === 'move' && record.class !== null && (
            <MoveForm record={record} onDone={(next) => done(next, t.moved)} onCancel={() => setPanel('none')} />
          )}
          <div className="flex items-baseline gap-2.5">
            <h2 className="type-display flex-1 text-[26px] leading-none">{t.contactsTitle}</h2>
            {record.can_manage && <SmallButton onClick={() => toggle('contact')}>{t.addContactShort}</SmallButton>}
          </div>
          {panel === 'contact' && (
            <ContactForm record={record} onDone={(next) => done(next, t.contactSaved)} onCancel={() => setPanel('none')} />
          )}
          {record.contacts.map((c) => (
            <ContactLine key={c.id} record={record} contact={c} onChanged={(next, title) => done(next, title)} />
          ))}
          <p className="mt-2.5 text-[13px] text-text-muted">{t.keepTwo}</p>

          <h2 className="type-display mt-6 mb-1 text-[26px] leading-none">{t.activity}</h2>
          <ul>
            {record.activity.length === 0 && <li className="py-2 text-[13px] text-text-muted">{t.noActivity}</li>}
            {record.activity.map((a, i) => (
              <li key={i} className="border-b border-rule py-2 text-[13px]">
                <span className="ref text-xs text-text-muted">{day(a.at)}</span>
                <br />
                <strong>{a.what}</strong> {t.activityLine(a.who)}
                {a.reason !== null && <span className="block text-text-muted">{t.reasonWas(a.reason)}</span>}
              </li>
            ))}
          </ul>
        </Column>
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

function Box({ children }: { children: ReactNode }) {
  return <div className="mx-[clamp(16px,3vw,40px)] mb-5 border-2 border-ink bg-sheet p-4.5">{children}</div>
}

function Column({ children, edge = false, wide = false, tinted = false }: { children: ReactNode; edge?: boolean; wide?: boolean; tinted?: boolean }) {
  return (
    <section
      className={`min-w-0 px-[clamp(16px,2.4vw,32px)] pt-5 pb-6 ${wide ? 'flex-[1.3_1_340px]' : tinted ? 'flex-[1.2_1_320px] bg-paper' : 'flex-[1_1_260px]'} ${edge ? 'border-ink max-[700px]:border-t-2 min-[701px]:border-l-2' : ''}`}
    >
      {children}
    </section>
  )
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

function ContactLine({ record, contact, onChanged }: { record: LearnerState; contact: LearnerState['contacts'][number]; onChanged: (next: LearnerState, title: string) => void }) {
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
      <div className="mt-1.5 flex flex-wrap items-center gap-1.5">
        <Tag tone="plain">{t.contactOnly}</Tag>
        <Tag tone="plain">{t.noticesTag(contact.notices_by)}</Tag>
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
