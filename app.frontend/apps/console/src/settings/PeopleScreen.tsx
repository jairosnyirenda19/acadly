import { navigate } from '@acadly/kernel'
import { Button, Notice } from '@acadly/ui'
import { useEffect, useMemo, useState } from 'react'
import { copy } from '../copy'
import { errorFor } from '../setup/api'
import { Input, Total, TotalsBand, nn } from '../setup/parts'
import { day, daysSince, missingAt, settingsApi, type PeopleState, type PersonRow } from './api'
import { PageHead, SettingsTabs, SmallButton, Tag, stateTone } from './parts'
import { RolePlace, placeBody } from './RolePlace'

const t = copy.settings.people
const s = copy.settings

type Filter = 'all' | 'active' | 'invited' | 'disabled'

const inFilter = (person: PersonRow, filter: Filter) =>
  filter === 'all' ||
  (filter === 'active' && (person.state === 'active' || person.state === 'no_role')) ||
  (filter === 'invited' && (person.state === 'invited' || person.state === 'ready' || person.state === 'expired')) ||
  (filter === 'disabled' && person.state === 'disabled')

const COLS = 'grid-cols-[48px_minmax(200px,1.4fr)_minmax(240px,2fr)_minmax(110px,0.7fr)_180px]'

export function PeopleScreen() {
  const [state, setState] = useState<PeopleState | 'refused' | null | undefined>(undefined)
  const [search, setSearch] = useState('')
  const [role, setRole] = useState('')
  const [where, setWhere] = useState('')
  const [filter, setFilter] = useState<Filter>('all')
  const [inviting, setInviting] = useState(false)

  const load = () => void settingsApi.people().then(setState)
  useEffect(load, [])

  const people = useMemo(() => {
    if (state === undefined || state === null || state === 'refused') return []
    const q = search.trim().toLowerCase()

    return state.people.filter(
      (p) =>
        inFilter(p, filter) &&
        (q === '' || p.name.toLowerCase().includes(q) || p.email.toLowerCase().includes(q)) &&
        (role === '' || p.roles.some((r) => r.role === role)) &&
        (where === '' || p.roles.some((r) => r.where === where)),
    )
  }, [state, search, role, where, filter])

  if (state === undefined) return <SettingsTabs current="people" />

  if (state === null || state === 'refused') {
    return (
      <>
        <SettingsTabs current="people" />
        <div className="p-[clamp(16px,3vw,40px)]">
          <Notice tone="wrong" title={state === 'refused' ? s.refused : s.loadFailed} />
        </div>
      </>
    )
  }

  const counts = {
    all: state.people.length,
    active: state.people.filter((p) => inFilter(p, 'active')).length,
    invited: state.people.filter((p) => inFilter(p, 'invited')).length,
    disabled: state.people.filter((p) => inFilter(p, 'disabled')).length,
  }
  const attention = state.totals.expired + state.totals.no_role
  const roleNames = [...new Set(state.people.flatMap((p) => p.roles.map((r) => r.role)))].sort()
  const places = [...new Set(state.people.flatMap((p) => p.roles.map((r) => r.where)))].sort()

  return (
    <>
      <SettingsTabs current="people" />
      <PageHead
        kicker={`${s.kicker} · ${t.title}`}
        title={t.title}
        intro={t.intro}
        actions={
          <>
            <Button variant="secondary" disabled title={t.importNote}>
              {t.import}
            </Button>
            <Button onClick={() => setInviting(!inviting)}>{t.invite}</Button>
          </>
        }
      />
      <p className="-mt-2 px-[clamp(16px,3vw,40px)] pb-4 text-right text-[13px] text-text-muted">{t.importNote}</p>

      <TotalsBand>
        <Total tone="ink" label={t.bandAccess} value={String(state.totals.with_access)} note={t.bandAccessNote} />
        <Total label={t.bandInvited} value={String(state.totals.invited)} note={t.bandInvitedNote(state.totals.expired)} />
        <Total tone={attention > 0 ? 'attention' : 'plain'} label={t.bandAttention} value={String(attention)} note={t.bandAttentionNote(state.totals.expired, state.totals.no_role)} />
        <Total label={t.bandGivers} value={String(state.totals.whole_school_givers.length)} note={t.bandGiversNote(state.totals.whole_school_givers)} />
      </TotalsBand>

      <section className="flex-1 bg-sheet px-[clamp(16px,3vw,40px)] pt-5 pb-8">
        {inviting && <InviteForm giving={state.giving} onDone={(id) => navigate(`/settings/people/${id}`)} onCancel={() => setInviting(false)} />}

        <div className="mb-4 flex flex-wrap items-end gap-x-4.5 gap-y-3">
          <div className="min-w-[220px] flex-[2_1_260px]">
            <Input label={t.find} type="search" placeholder={t.findPlaceholder} value={search} onChange={(e) => setSearch(e.target.value)} />
          </div>
          <FilterSelect label={copy.settings.give.role} value={role} onChange={setRole} any={t.anyRole} options={roleNames} />
          <FilterSelect label={copy.settings.give.where} value={where} onChange={setWhere} any={t.anywhere} options={places} />
          <div role="group" aria-label={t.stateLabel} className="flex h-11 border-2 border-ink">
            {(['all', 'active', 'invited', 'disabled'] as const).map((key, i) => (
              <button
                key={key}
                type="button"
                aria-pressed={filter === key}
                onClick={() => setFilter(key)}
                className={`px-3.5 text-sm ${i > 0 ? 'border-l-2 border-ink' : ''} ${filter === key ? 'bg-ink font-extrabold text-sheet' : 'font-bold text-ink hover:bg-field'}`}
              >
                {t.filters[key]} {counts[key]}
              </button>
            ))}
          </div>
        </div>

        <div className="overflow-x-auto">
          <div className="min-w-[820px]">
            <div className={`label-caps grid ${COLS} gap-3 border-b-2 border-ink pr-4 pb-2 text-text-muted`}>
              <span />
              <span>{t.person}</span>
              <span>{t.roles}</span>
              <span>{t.lastSignedIn}</span>
              <span className="text-right">{t.state}</span>
            </div>
            <div className="ledger [--margin:48px]">
              {people.map((person, i) => (
                <PersonLine key={person.id} n={i + 1} person={person} onResent={load} />
              ))}
              {people.length === 0 && <p className="py-4 pl-[60px] text-sm text-text-muted">{t.none}</p>}
            </div>
          </div>
        </div>
        <p className="mt-3.5 text-[13px] text-text-muted">{t.showing(people.length, state.people.length)}</p>
      </section>
    </>
  )
}

function FilterSelect({ label, value, onChange, any, options }: { label: string; value: string; onChange: (v: string) => void; any: string; options: string[] }) {
  return (
    <label className="block min-w-[170px] flex-[1_1_180px]">
      <span className="label-caps mb-1.5 block text-text-muted">{label}</span>
      <select value={value} onChange={(e) => onChange(e.target.value)} className="h-11 w-full border-0 border-b-2 border-ink bg-field px-3 text-[15px] text-ink outline-none focus:border-teal">
        <option value="">{any}</option>
        {options.map((o) => (
          <option key={o} value={o}>
            {o}
          </option>
        ))}
      </select>
    </label>
  )
}

function PersonLine({ n, person, onResent }: { n: number; person: PersonRow; onResent: () => void }) {
  const tone = stateTone[person.state]
  const [sending, setSending] = useState(false)
  const note =
    person.state === 'invited' && person.invitation_sent_at !== null
      ? t.sentAgo(daysSince(person.invitation_sent_at))
      : person.state === 'ready'
        ? t.notSent
        : person.state === 'no_role'
          ? t.noRoleNote
          : person.state === 'disabled' && person.disabled !== null
            ? t.disabledNote(day(person.disabled.at), person.disabled.reason ?? '')
            : null

  return (
    <div
      role="link"
      tabIndex={0}
      onClick={() => navigate(`/settings/people/${person.id}`)}
      onKeyDown={(e) => {
        if (e.key === 'Enter') navigate(`/settings/people/${person.id}`)
      }}
      className={`grid cursor-pointer ${COLS} items-center gap-3 border-b border-rule py-3 pr-4 hover:bg-paper ${tone === 'wrong' ? 'bg-red-wash' : tone === 'attention' ? 'bg-marigold-wash' : ''}`}
    >
      <div className={`type-display text-center text-xl ${tone === 'wrong' ? 'text-red-pen' : 'text-text-muted'}`}>{nn(n)}</div>
      <div className="min-w-0">
        <div className="text-base font-extrabold">{person.name}</div>
        <div className="ref text-xs break-all text-text-muted">{person.email}</div>
      </div>
      <div className="flex flex-wrap gap-1">
        {person.roles.length === 0 && <span className="text-sm text-text-muted">{t.noRoleHeld}</span>}
        {person.roles.map((r, i) => (
          <span key={i} className="inline-flex h-7 items-center border-2 border-ink bg-sheet px-2 text-[13px] font-bold whitespace-nowrap">
            {r.role} · {r.where}
            {r.ends_at !== null && <span className="ml-1 font-medium text-text-muted">{t.until(day(r.ends_at))}</span>}
          </span>
        ))}
      </div>
      <div className="text-sm text-text-muted">{person.last_signed_in_at !== null ? day(person.last_signed_in_at) : t.never}</div>
      <div className="text-right">
        <Tag tone={tone}>{s.states[person.state]}</Tag>
        {note !== null && <div className="mt-1.5 text-[13px]">{note}</div>}
        {person.state === 'expired' && (
          <div className="mt-1.5" onClick={(e) => e.stopPropagation()}>
            <SmallButton
              disabled={sending}
              onClick={() => {
                setSending(true)
                void settingsApi.resend(person.id).then(() => {
                  setSending(false)
                  onResent()
                })
              }}
            >
              {t.sendAgain}
            </SmallButton>
          </div>
        )}
      </div>
    </div>
  )
}

function InviteForm({ giving, onDone, onCancel }: { giving: PeopleState['giving']; onDone: (id: string) => void; onCancel: () => void }) {
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [role, setRole] = useState('')
  const [place, setPlace] = useState('')
  const [send, setSend] = useState(true)
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [failed, setFailed] = useState<string | null>(null)
  const [working, setWorking] = useState(false)

  const submit = async () => {
    setWorking(true)
    setFailed(null)
    const result = await settingsApi.invite({ name, email, role_id: role, ...placeBody(place), send })
    setWorking(false)

    if (result.kind === 'saved') onDone(result.data.person.id)
    else if (result.kind === 'invalid') setErrors(result.errors)
    else setFailed(result.kind === 'refused' ? s.refused : s.failed)
  }

  if (giving.places.length === 0) return <p className="mb-5 text-sm text-text-muted">{copy.settings.give.nowhere}</p>

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault()
        void submit()
      }}
      className="mb-6 border-2 border-ink bg-paper p-4.5"
    >
      <h2 className="type-display text-[22px] leading-none">{t.inviteTitle}</h2>
      <p className="mt-1 mb-3.5 text-[13px] text-text-muted">{t.inviteIntro}</p>
      {failed !== null && (
        <div className="mb-3">
          <Notice tone="wrong" title={failed} />
        </div>
      )}
      <div className="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-x-5 gap-y-4">
        <Input label={t.name} required value={name} onChange={(e) => setName(e.target.value)} error={errorFor(errors, 'name')} maxLength={120} />
        <Input label={t.email} required type="email" value={email} onChange={(e) => setEmail(e.target.value)} error={errorFor(errors, 'email')} />
        <RolePlace
          giving={giving}
          role={role}
          place={place}
          onRole={setRole}
          onPlace={setPlace}
          errors={{ role: errorFor(errors, 'role_id'), place: errorFor(errors, 'scope_id') ?? errorFor(errors, 'scope') }}
        />
      </div>
      <label className="mt-4 flex items-center gap-2 text-sm font-bold">
        <input type="checkbox" checked={send} onChange={(e) => setSend(e.target.checked)} className="size-5 accent-teal" />
        {t.sendNow}
      </label>
      <div className="mt-4 flex flex-wrap gap-2.5">
        <Button type="submit" disabled={working || (role !== '' && place !== '' && missingAt(giving, role, place).length > 0)}>
          {working ? s.saving : t.inviteSubmit}
        </Button>
        <Button variant="secondary" onClick={onCancel}>
          {s.cancel}
        </Button>
      </div>
    </form>
  )
}
