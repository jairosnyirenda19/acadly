import { navigate, useLoad } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useEffect, useState, type ReactNode } from 'react'
import { copy } from '../copy'
import { errorFor } from '../setup/api'
import { Input, Select } from '../setup/parts'
import { PageHead, SmallButton, Tag } from '../settings/parts'
import { useWorkIn } from '../shell/WorkIn'
import { ageOn, learnersApi, today, type ContactInput, type LearnersState } from './api'
import { NoticesBy } from './NoticesBy'
import { PhotoPicker } from './PhotoPicker'

const t = copy.learners
const s = copy.settings

const blank = (main: boolean): ContactInput => ({ name: '', relationship: '', phone: '', email: '', notices_by: 'sms', main })

/** Admitting a learner (the approved design): the learner, the class, and at least two contacts. */
export function AdmitScreen() {
  const { place } = useWorkIn()
  const inPlace = place !== null && place.key !== 'institution:' ? place.key : null
  const loaded = useLoad(`learners:${inPlace ?? ''}`, () => learnersApi.list(inPlace))

  if (loaded.data === undefined) {
    return <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} rows={6} onRetry={loaded.reload} />
  }

  return <AdmitForm state={loaded.data} onAdmitted={loaded.reload} />
}

function AdmitForm({ state, onAdmitted }: { state: LearnersState; onAdmitted: () => void }) {
  const word = state.word.toLowerCase()
  const classes = state.classes.filter((c) => c.can_admit)
  const [first, setFirst] = useState('')
  const [other, setOther] = useState('')
  const [surname, setSurname] = useState('')
  const [sex, setSex] = useState('')
  const [born, setBorn] = useState('')
  const [klass, setKlass] = useState(classes.length === 1 ? (classes[0]?.id ?? '') : '')
  const [admitted, setAdmitted] = useState(today())
  const [number, setNumber] = useState('')
  const [contacts, setContacts] = useState<ContactInput[]>([blank(true), blank(false)])
  const [photo, setPhoto] = useState<File | null>(null)
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [failed, setFailed] = useState<string | null>(null)
  const [working, setWorking] = useState(false)
  const [different, setDifferent] = useState(false)

  // A changed name or date of birth asks the duplicate question again.
  useEffect(() => setDifferent(false), [first, surname, born])

  const name = [first, other, surname].map((x) => x.trim()).filter(Boolean).join(' ')
  const age = ageOn(born, admitted)

  const change = (i: number, patch: Partial<ContactInput>) =>
    setContacts((list) => list.map((c, j) => (patch.main === true ? { ...c, main: j === i } : c)).map((c, j) => (j === i ? { ...c, ...patch } : c)))

  const submit = async (confirmed: boolean) => {
    setWorking(true)
    setFailed(null)
    const result = await learnersApi.admit({
      first_name: first,
      other_names: other,
      surname,
      sex,
      date_of_birth: born,
      class_id: klass,
      admitted_on: admitted,
      admission_number: number,
      different: confirmed,
      contacts,
    })

    if (result.kind !== 'saved') {
      setWorking(false)
      if (result.kind === 'invalid') setErrors(result.errors)
      else setFailed(result.kind === 'refused' ? s.refused : s.failed)

      return
    }

    let photoSaved = true
    if (photo !== null) photoSaved = (await learnersApi.photo(result.data.id, photo)).kind === 'saved'

    setWorking(false)
    onAdmitted()
    slips.done({ word: copy.slips.saved, title: t.admitted(name) })
    if (!photoSaved) slips.attention({ word: t.photo, title: t.photoFailed })
    navigate(`/learners/${result.data.id}`)
  }

  const duplicate = errorFor(errors, 'duplicate')
  const duplicateId = errorFor(errors, 'duplicate_id')

  if (classes.length === 0) {
    return (
      <>
        <PageHead kicker={t.admitKicker(state.word_plural)} title={t.admit(word)} intro={t.admitIntro(word)} />
        <div className="px-[clamp(16px,3vw,40px)] pb-8">
          <Notice tone="wrong" title={t.noClassToAdmit} />
        </div>
      </>
    )
  }

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault()
        void submit(different)
      }}
    >
      <PageHead kicker={t.admitKicker(state.word_plural)} title={t.admit(word)} intro={t.admitIntro(word)} />

      {failed !== null && (
        <div className="px-[clamp(16px,3vw,40px)] pb-4">
          <Notice tone="wrong" title={failed} />
        </div>
      )}
      {duplicate !== undefined && !different && (
        <div role="alert" className="mx-[clamp(16px,3vw,40px)] mb-4 flex flex-wrap items-center gap-3 border-2 border-ink bg-marigold-wash px-4 py-3.5">
          <Tag tone="attention">{t.duplicateTag}</Tag>
          <span className="flex-[1_1_420px] text-sm leading-snug font-semibold">{duplicate}</span>
          <span className="flex flex-wrap gap-2">
            {duplicateId !== undefined && <SmallButton onClick={() => navigate(`/learners/${duplicateId}`)}>{t.openThat}</SmallButton>}
            <SmallButton
              onClick={() => {
                setDifferent(true)
                void submit(true)
              }}
            >
              {t.different(word)}
            </SmallButton>
          </span>
        </div>
      )}

      <div className="flex flex-wrap border-t-2 border-ink bg-sheet">
        <Part n="1" title={t.sectionLearner(state.word)}>
          <PhotoPicker word={word} file={photo} onFile={setPhoto} />
          <Input label={t.firstName} required value={first} onChange={(e) => setFirst(e.target.value)} error={errorFor(errors, 'first_name')} maxLength={80} autoComplete="off" />
          <Input label={t.otherNames} value={other} onChange={(e) => setOther(e.target.value)} error={errorFor(errors, 'other_names')} maxLength={120} autoComplete="off" />
          <Input label={t.surname} required value={surname} onChange={(e) => setSurname(e.target.value)} error={errorFor(errors, 'surname')} maxLength={80} autoComplete="off" />
          <div>
            <div className="label-caps mb-1.5 text-text-muted">
              {t.sex}
              <span className="font-semibold tracking-normal normal-case"> · {s.required}</span>
            </div>
            <div role="radiogroup" aria-label={t.sex} className="flex h-11 w-max border-2 border-ink">
              {(['female', 'male'] as const).map((value, i) => (
                <button
                  key={value}
                  type="button"
                  role="radio"
                  aria-checked={sex === value}
                  onClick={() => setSex(value)}
                  className={`px-4.5 text-sm ${i > 0 ? 'border-l-2 border-ink' : ''} ${sex === value ? 'bg-ink font-extrabold text-sheet' : 'font-bold hover:bg-field'}`}
                >
                  {t.sexes[value]}
                </button>
              ))}
            </div>
            {errorFor(errors, 'sex') !== undefined && <div className="mt-1.5 text-[13px] font-bold text-red-text">{errorFor(errors, 'sex')}</div>}
          </div>
          <Input
            label={t.dateOfBirth}
            required
            type="date"
            max={today()}
            value={born}
            onChange={(e) => setBorn(e.target.value)}
            error={errorFor(errors, 'date_of_birth')}
            hint={age !== null ? t.age(age) : undefined}
          />
        </Part>

        <Part n="2" title={t.sectionSchool} edge>
          <Select label={t.klass} required value={klass} onChange={(e) => setKlass(e.target.value)} error={errorFor(errors, 'class_id')}>
            <option value="">{t.chooseClass}</option>
            {classes.map((c) => (
              <option key={c.id} value={c.id}>
                {t.classOption(c.name, c.level, c.campus, c.count)}
              </option>
            ))}
          </Select>
          <Input label={t.admittedOn} required type="date" max={today()} value={admitted} onChange={(e) => setAdmitted(e.target.value)} error={errorFor(errors, 'admitted_on')} />
          <Input label={t.admissionNumber} value={number} onChange={(e) => setNumber(e.target.value)} error={errorFor(errors, 'admission_number')} hint={t.admissionHint} maxLength={40} />
        </Part>
      </div>

      <section className="border-y-2 border-ink bg-sheet px-[clamp(16px,2.4vw,32px)] pt-5.5 pb-6">
        <div className="mb-4 flex flex-wrap items-baseline gap-x-4 gap-y-2">
          <span className="type-display text-[40px] leading-[0.8] text-rule-dashed">3</span>
          <h2 className="type-display text-[26px] leading-none">{t.sectionContacts}</h2>
          <span className="flex-[1_1_320px] text-[13px] text-text-muted">{t.contactsIntro}</span>
          <span className="text-[13px] font-bold">{t.contactCount(contacts.length)}</span>
        </div>
        {errorFor(errors, 'contacts') !== undefined && <p className="mb-3 text-[13px] font-bold text-red-text">{errorFor(errors, 'contacts')}</p>}
        <div className="grid grid-cols-[repeat(auto-fit,minmax(min(300px,100%),1fr))] items-start gap-3">
          {contacts.map((c, i) => (
            <fieldset key={i} className={`grid min-w-0 gap-3 border-2 border-ink p-3.5 ${c.main ? 'bg-paper' : 'bg-sheet'}`}>
              <legend className="sr-only">{t.contact(i + 1)}</legend>
              <div className="flex items-center gap-2">
                <span className="type-display text-[22px] leading-none">{t.contact(i + 1)}</span>
                {c.main && <Tag tone="done">{t.mainContact}</Tag>}
                {contacts.length > 2 && (
                  <span className="ml-auto">
                    <SmallButton onClick={() => setContacts((list) => fixMain(list.filter((_, j) => j !== i)))}>{t.remove}</SmallButton>
                  </span>
                )}
              </div>
              <Input label={t.fullName} required value={c.name} onChange={(e) => change(i, { name: e.target.value })} error={errorFor(errors, `contacts.${i}.name`)} maxLength={120} autoComplete="off" />
              <div className="grid grid-cols-2 gap-3">
                <Select label={t.relationship} required value={c.relationship} onChange={(e) => change(i, { relationship: e.target.value })} error={errorFor(errors, `contacts.${i}.relationship`)}>
                  <option value="">{t.choose}</option>
                  {state.relationships.map((r) => (
                    <option key={r.value} value={r.value}>
                      {r.label}
                    </option>
                  ))}
                </Select>
                <Input label={t.phone} required type="tel" value={c.phone} onChange={(e) => change(i, { phone: e.target.value })} error={errorFor(errors, `contacts.${i}.phone`)} maxLength={30} autoComplete="off" />
              </div>
              <Input
                label={t.email}
                type="email"
                value={c.email}
                onChange={(e) => change(i, { email: e.target.value, ...(e.target.value.trim() === '' ? { notices_by: 'sms' as const } : {}) })}
                error={errorFor(errors, `contacts.${i}.email`)}
                autoComplete="off"
              />
              <NoticesBy value={c.notices_by} hasEmail={c.email.trim() !== ''} onChange={(next) => change(i, { notices_by: next })} error={errorFor(errors, `contacts.${i}.notices_by`)} />
              <label className="flex items-center gap-2.5 text-sm font-bold">
                <input type="radio" name="main-contact" checked={c.main} onChange={() => change(i, { main: true })} className="size-[18px] accent-teal" />
                {t.mainContact}
              </label>
            </fieldset>
          ))}
        </div>
        <div className="mt-3">
          <SmallButton onClick={() => setContacts((list) => [...list, blank(false)])}>{t.addContact}</SmallButton>
        </div>
      </section>

      <div className="flex flex-wrap items-start gap-4 px-[clamp(16px,3vw,40px)] pt-4.5 pb-8">
        <div className="flex flex-wrap gap-2.5">
          <Button type="submit" busy={working}>
            {working ? s.saving : t.admitSubmit(name)}
          </Button>
          <Button variant="secondary" onClick={() => navigate('/learners')}>
            {s.cancel}
          </Button>
        </div>
        <div className="max-w-[640px] flex-[1_1_420px] border-l-2 border-ink pl-4 text-[13px] leading-normal text-text-muted">
          <div className="label-caps mb-1 text-ink">{t.whatHappensTitle}</div>
          {t.whatHappens(word)}
        </div>
      </div>
    </form>
  )
}

/** One contact is always the main one. */
function fixMain(list: ContactInput[]): ContactInput[] {
  return list.some((c) => c.main) ? list : list.map((c, i) => ({ ...c, main: i === 0 }))
}

function Part({ n, title, edge = false, children }: { n: string; title: string; edge?: boolean; children: ReactNode }) {
  return (
    <section className={`min-w-0 flex-[1_1_340px] px-[clamp(16px,2.4vw,32px)] pt-5.5 pb-6.5 ${edge ? 'border-ink max-[719px]:border-t-2 min-[720px]:border-l-2' : ''}`}>
      <div className="mb-4 flex items-baseline gap-2.5">
        <span className="type-display text-[40px] leading-[0.8] text-rule-dashed">{n}</span>
        <h2 className="type-display text-[26px] leading-none">{title}</h2>
      </div>
      <div className="grid gap-4">{children}</div>
    </section>
  )
}
