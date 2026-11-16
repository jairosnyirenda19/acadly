import { navigate, useLoad } from '@acadly/kernel'
import { Button, DataGrid, NO_SELECTION, Notice, slips, viewState, Waiting } from '@acadly/ui'
import { useState, type MouseEvent } from 'react'
import { copy } from '../copy'
import { gridsApi, type BulkAnswer } from '../grid/api'
import { BulkResult } from '../grid/BulkResult'
import { startExport, useGridPage, useGridPrefs, useGridQuery, useGridSelection } from '../grid/useGrid'
import { errorFor } from '../setup/api'
import { Input, Total, TotalsBand } from '../setup/parts'
import { day, daysSince, missingAt, settingsApi, type PeopleState, type PersonGridRow } from './api'
import { PageHead, SettingsTabs, Tag, stateTone } from './parts'
import { RolePlace, placeBody } from './RolePlace'

const t = copy.settings.people
const s = copy.settings
const GRID = 'people'

/** Follows a link inside the app; a click with a modifier key keeps the browser's own behaviour. */
function follow(event: MouseEvent<HTMLAnchorElement>, path: string) {
  if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return
  event.preventDefault()
  navigate(path)
}

function NameLink({ row }: { row: PersonGridRow }) {
  return (
    <a href={`/settings/people/${row.id}`} onClick={(e) => follow(e, `/settings/people/${row.id}`)} className="text-ink no-underline underline-offset-[3px] hover:underline">
      {row.name}
    </a>
  )
}

/** The state as its tag, and one line on what it means where that helps. */
function State({ row }: { row: PersonGridRow }) {
  const note =
    row.state === 'invited' && row.invitation_sent_at !== null ? t.sentAgo(daysSince(row.invitation_sent_at)) : row.state === 'ready' ? t.notSent : row.state === 'no_role' ? t.noRoleNote : null

  return (
    <>
      <Tag tone={stateTone[row.state]}>{s.states[row.state]}</Tag>
      {note !== null && <span className="mt-1 block text-[13px] whitespace-normal">{note}</span>}
    </>
  )
}

/** A moment as date and time, or a dash that is read as "Never". */
function When({ iso }: { iso: string | null }) {
  if (iso === null) {
    return (
      <span className="text-text-muted">
        <span aria-hidden="true">{copy.dash}</span>
        <span className="sr-only">{t.never}</span>
      </span>
    )
  }

  return <span className="tabular-nums">{t.signedInAt(iso)}</span>
}

const render = {
  name: (row: PersonGridRow) => (
    <>
      <span className="block text-[15px] font-extrabold">
        <NameLink row={row} />
      </span>
      <span className="ref block text-xs whitespace-nowrap text-text-muted">{row.email}</span>
    </>
  ),
  email: (row: PersonGridRow) => <span className="ref text-[13px]">{row.email}</span>,
  roles: (row: PersonGridRow) =>
    row.roles.length === 0 ? (
      <span className="text-text-muted">{t.noRoleHeld}</span>
    ) : (
      <span className="flex max-w-[420px] min-w-[220px] flex-wrap gap-1 whitespace-normal">
        {row.roles.map((r, i) => (
          <span key={i} className="inline-flex min-h-7 items-center border-2 border-ink bg-sheet px-2 text-[13px] font-bold">
            {t.roleWhere(r.role, r.where)}
            {r.ends_at !== null && <span className="ml-1 font-medium text-text-muted">{t.until(day(r.ends_at))}</span>}
          </span>
        ))}
      </span>
    ),
  state: (row: PersonGridRow) => <State row={row} />,
  last_signed_in_at: (row: PersonGridRow) => <When iso={row.last_signed_in_at} />,
  invitation_sent_at: (row: PersonGridRow) => <When iso={row.invitation_sent_at} />,
}

const card = (row: PersonGridRow) => ({
  title: <NameLink row={row} />,
  meta: row.email,
  aside: <Tag tone={stateTone[row.state]}>{s.states[row.state]}</Tag>,
})

const rowTone = (row: PersonGridRow) => {
  const tone = stateTone[row.state]

  return tone === 'wrong' ? 'wrong' : tone === 'attention' ? 'attention' : null
}

/** Everyone with an account, on the data grid (the approved design). */
export function PeopleScreen() {
  const [inviting, setInviting] = useState(false)
  // The totals and the invite form cover every part of the school the account can see, as the list does.
  const loaded = useLoad('people:', () => settingsApi.people(null))
  const state = loaded.data
  const [query, setQuery] = useGridQuery('/settings/people')
  const grid = useGridPage(GRID, query, settingsApi.peopleGrid)
  const [selection, setSelection] = useGridSelection(query)
  const prefs = useGridPrefs(GRID, grid.reload)
  const [result, setResult] = useState<BulkAnswer | null>(null)

  if (state === undefined) {
    return (
      <>
        <SettingsTabs current="people" />
        <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} band={[t.bandAccess, t.bandInvited, t.bandAttention, t.bandGivers]} rows={6} onRetry={loaded.reload} />
      </>
    )
  }

  const page = grid.page
  const sort = { sort: page?.sort ?? query.sort, dir: page?.dir ?? query.dir }
  const attention = state.totals.expired + state.totals.no_role

  const labels = copy.grid.labels({
    name: t.title,
    word: t.word,
    plural: t.plural,
    searchPlaceholder: t.findPlaceholder,
    columnsNote: t.gridColumnsNote,
    emptyText: t.gridEmpty,
    noMatchText: t.gridNoMatch(state.people.length),
  })

  // Resend invitations takes no input: it runs at once and its result shows above the rows.
  const bulk = (action: string) => {
    if (action !== 'resend') return
    void gridsApi.bulk(GRID, { action, ...(selection.all ? { all: true as const } : { ids: selection.ids }), state: viewState(query, sort), input: {} }).then((r) => {
      if (r.kind === 'saved') {
        setResult(r.data)
        setSelection(NO_SELECTION)
        grid.reload()
        loaded.reload()
      } else {
        slips.wrong({ word: t.notResent, title: r.kind === 'refused' ? s.refused : t.resendFailed })
      }
    })
  }

  return (
    <>
      <SettingsTabs current="people" />
      <PageHead
        kicker={`${s.kicker} · ${t.title}`}
        title={t.title}
        intro={t.intro}
        actions={
          <>
            <Button variant="secondary" onClick={() => navigate('/settings/people/import')}>
              {t.import}
            </Button>
            <Button onClick={() => setInviting(!inviting)}>{t.invite}</Button>
          </>
        }
      />

      <TotalsBand>
        <Total tone="ink" label={t.bandAccess} value={String(state.totals.with_access)} note={t.bandAccessNote} />
        <Total label={t.bandInvited} value={String(state.totals.invited)} note={t.bandInvitedNote(state.totals.expired)} />
        <Total tone={attention > 0 ? 'attention' : 'plain'} label={t.bandAttention} value={String(attention)} note={t.bandAttentionNote(state.totals.expired, state.totals.no_role)} />
        <Total label={t.bandGivers} value={String(state.totals.whole_school_givers.length)} note={t.bandGiversNote(state.totals.whole_school_givers)} />
      </TotalsBand>

      {inviting && (
        <div className="bg-sheet px-[clamp(16px,3vw,40px)] pt-5">
          <InviteForm giving={state.giving} onDone={(id) => navigate(`/settings/people/${id}`)} onCancel={() => setInviting(false)} />
        </div>
      )}

      <DataGrid
        labels={labels}
        page={page}
        phase={grid.phase}
        query={query}
        onQuery={(next, replace) => {
          setResult(null)
          setQuery(next, replace)
        }}
        onRetry={grid.reload}
        render={render}
        card={card}
        rowName={(row) => row.name}
        rowTone={rowTone}
        selection={selection}
        onSelection={setSelection}
        onBulk={bulk}
        notice={
          result !== null && (
            <BulkResult
              label={t.resendLabel}
              done={t.resent(result.done)}
              notDoneCount={t.notResentCount(result.refused.length)}
              notDone={t.notResent}
              line={t.resultLine}
              refused={result.refused}
              close={t.closeResult}
              onClose={() => setResult(null)}
            />
          )
        }
        onColumns={prefs.onColumns}
        views={prefs.views}
        onExport={(scope) => {
          if (page !== undefined) startExport(GRID, scope, { page, query, selection, word: t.word, plural: t.plural })
        }}
        emptyActions={
          <>
            <Button onClick={() => setInviting(true)}>{t.invite}</Button>
            <Button variant="secondary" onClick={() => navigate('/settings/people/import')}>
              {t.import}
            </Button>
          </>
        }
      />
    </>
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
        <Button type="submit" busy={working} disabled={(role !== '' && place !== '' && missingAt(giving, role, place).length > 0)}>
          {working ? s.saving : t.inviteSubmit}
        </Button>
        <Button variant="secondary" onClick={onCancel}>
          {s.cancel}
        </Button>
      </div>
    </form>
  )
}
