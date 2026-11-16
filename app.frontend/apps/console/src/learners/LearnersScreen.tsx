import { navigate, useLoad } from '@acadly/kernel'
import { Button, DataGrid, NO_SELECTION, Notice, viewState, Waiting, type GridQuery, type GridSelection } from '@acadly/ui'
import { useState, type MouseEvent } from 'react'
import { copy } from '../copy'
import { gridsApi, type BulkAnswer } from '../grid/api'
import { BulkResult } from '../grid/BulkResult'
import { startExport, useGridPage, useGridPrefs, useGridQuery, useGridSelection } from '../grid/useGrid'
import { useRememberList } from './back'
import { Input, Select, Total, TotalsBand } from '../setup/parts'
import { PageHead, Tag } from '../settings/parts'
import { useWorkIn } from '../shell/WorkIn'
import { learnersApi, today, type LearnerGridRow } from './api'

const t = copy.learners
const s = copy.settings
const GRID = 'learners'

/** Follows a link inside the app; a click with a modifier key keeps the browser's own behaviour. */
function follow(event: MouseEvent<HTMLAnchorElement>, path: string) {
  if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return
  event.preventDefault()
  navigate(path)
}

function StateTags({ row }: { row: LearnerGridRow }) {
  if (!row.few_contacts && !row.possible_duplicate) return <Tag tone="done">{t.active}</Tag>

  return (
    <span className="inline-flex flex-wrap gap-1">
      {row.few_contacts && <Tag tone="attention">{t.fewContactsTag}</Tag>}
      {row.possible_duplicate && <Tag tone="attention">{t.possibleDuplicate}</Tag>}
    </span>
  )
}

function NameLink({ row }: { row: LearnerGridRow }) {
  return (
    <a href={`/learners/${row.id}`} onClick={(e) => follow(e, `/learners/${row.id}`)} className="text-ink no-underline underline-offset-[3px] hover:underline">
      {row.name}
    </a>
  )
}

const render = {
  learner: (row: LearnerGridRow) => (
    <>
      <span className="block text-[15px] font-extrabold">
        <NameLink row={row} />
      </span>
      <span className="ref block text-xs text-text-muted">{row.acadly_id}</span>
    </>
  ),
  admission_number: (row: LearnerGridRow) => <span className="ref text-[13px]">{row.admission_number ?? ''}</span>,
  class: (row: LearnerGridRow) => row.class,
  sex: (row: LearnerGridRow) => t.sexes[row.sex] ?? row.sex,
  date_of_birth: (row: LearnerGridRow) => <span className="tabular-nums">{t.day(row.date_of_birth)}</span>,
  admitted_on: (row: LearnerGridRow) => <span className="tabular-nums">{row.admitted_on !== null ? t.day(row.admitted_on) : ''}</span>,
  contacts: (row: LearnerGridRow) => <span className="type-numeral text-xl">{row.contacts}</span>,
  main_contact: (row: LearnerGridRow) => (row.main_contact !== null ? <span className="ref text-[13px]">{row.main_contact}</span> : <span className="text-text-muted">{t.notRecorded}</span>),
  campus: (row: LearnerGridRow) => row.campus,
  state: (row: LearnerGridRow) => <StateTags row={row} />,
}

const card = (row: LearnerGridRow) => ({
  title: <NameLink row={row} />,
  meta: [row.admission_number ?? row.acadly_id, row.class].join(' · '),
  aside: <StateTags row={row} />,
})

/** The list of learners on the data grid (the approved design). */
export function LearnersScreen() {
  const { place } = useWorkIn()
  const inPlace = place !== null && place.key !== 'institution:' ? place.key : null
  const loaded = useLoad(`learners:${inPlace ?? ''}`, () => learnersApi.list(inPlace))
  const state = loaded.data
  const [query, setQuery] = useGridQuery('/learners')
  useRememberList()
  const grid = useGridPage(GRID, query, learnersApi.grid)
  const [selection, setSelection] = useGridSelection(query)
  const prefs = useGridPrefs(GRID, grid.reload)
  const [moving, setMoving] = useState(false)
  const [result, setResult] = useState<{ answer: BulkAnswer; to: string } | null>(null)

  if (state === undefined) {
    return <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} band={[t.title, t.bandAdmitted, t.bandClasses, t.bandAttention]} rows={8} onRetry={loaded.reload} />
  }

  const word = state.word.toLowerCase()
  const plural = state.word_plural
  const lower = plural.toLowerCase()
  const attention = state.totals.few_contacts + state.totals.duplicates
  const page = grid.page
  const sort = { sort: page?.sort ?? query.sort, dir: page?.dir ?? query.dir }
  const classes = page?.filter_options.find((f) => f.key === 'class')?.options ?? []
  const chosen = selection.all ? (page?.total ?? 0) : selection.ids.length

  const labels = copy.grid.labels({
    name: plural,
    word,
    plural: lower,
    searchPlaceholder: t.findPlaceholder,
    columnsNote: t.columnsNote(word),
    emptyText: t.emptyText(lower),
    noMatchText: t.noMatchText(state.totals.active, word, lower),
  })

  const exportList = (scope: 'match' | 'selected') => {
    if (page !== undefined) startExport(GRID, scope, { page, query, selection, word, plural: lower })
  }

  const moved = (answer: BulkAnswer, to: string) => {
    setMoving(false)
    setResult({ answer, to })
    setSelection(NO_SELECTION)
    grid.reload()
    loaded.reload()
  }

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
            headers={{ learner: state.word }}
            alignEnd={['contacts']}
            card={card}
            rowName={(row) => row.name}
            rowTone={(row) => (row.few_contacts || row.possible_duplicate ? 'attention' : null)}
            selection={selection}
            onSelection={(next) => {
              setSelection(next)
              if (next.all === false && next.ids.length === 0) setMoving(false)
            }}
            onBulk={(action) => {
              if (action === 'change_class') setMoving(true)
            }}
            bulkPanel={
              moving && (
                <ChangeClassForm
                  classes={classes}
                  count={chosen}
                  word={word}
                  plural={lower}
                  query={query}
                  sort={sort}
                  selection={selection}
                  onDone={moved}
                  onCancel={() => setMoving(false)}
                />
              )
            }
            notice={
              result !== null && (
                <BulkResult
                  label={t.resultLabel}
                  done={t.movedTo(result.answer.done, result.to, word, lower)}
                  notDoneCount={t.notMovedCount(result.answer.refused.length)}
                  notDone={t.notMoved}
                  line={t.notMovedLine}
                  refused={result.answer.refused}
                  close={t.closeResult}
                  onClose={() => setResult(null)}
                />
              )
            }
            onColumns={prefs.onColumns}
            views={prefs.views}
            onExport={exportList}
            emptyActions={
              state.can_admit ? (
                <>
                  <Button onClick={() => navigate('/learners/admit')}>{t.admit(word)}</Button>
                  <Button variant="secondary" onClick={() => navigate('/learners/import')}>
                    {t.emptyImport}
                  </Button>
                </>
              ) : undefined
            }
          />
        </>
      )}
    </>
  )
}

/** Change class for the rows chosen: inline, under the bar of the selection. */
function ChangeClassForm({
  classes,
  count,
  word,
  plural,
  query,
  sort,
  selection,
  onDone,
  onCancel,
}: {
  classes: { value: string; label: string; count: number }[]
  count: number
  word: string
  plural: string
  query: GridQuery
  sort: { sort: string; dir: 'asc' | 'desc' }
  selection: GridSelection
  onDone: (answer: BulkAnswer, to: string) => void
  onCancel: () => void
}) {
  const [to, setTo] = useState('')
  const [from, setFrom] = useState(today())
  const [reason, setReason] = useState('')
  const [failed, setFailed] = useState<string | null>(null)
  const [working, setWorking] = useState(false)

  return (
    <form
      aria-label={t.bulkTitle}
      onSubmit={(e) => {
        e.preventDefault()
        setWorking(true)
        setFailed(null)
        const target = classes.find((c) => c.value === to)?.label ?? ''

        void gridsApi
          .bulk(GRID, {
            action: 'change_class',
            ...(selection.all ? { all: true as const } : { ids: selection.ids }),
            state: viewState(query, sort),
            input: { class_id: to, from, reason },
          })
          .then((r) => {
            setWorking(false)
            if (r.kind === 'saved') onDone(r.data, target)
            else if (r.kind === 'invalid') setFailed(Object.values(r.errors)[0]?.[0] ?? s.failed)
            else setFailed(r.kind === 'refused' ? s.refused : s.failed)
          })
      }}
      className="border-2 border-t-0 border-ink bg-sheet px-4 pt-3.5 pb-4"
    >
      <div className="label-caps text-text-muted">{t.bulkTitle}</div>
      <p className="mt-1 mb-3 max-w-[65ch] text-sm text-text-muted">{t.bulkNote(count, word, plural)}</p>
      {failed !== null && (
        <div className="mb-3">
          <Notice tone="wrong" title={failed} />
        </div>
      )}
      <div className="grid gap-3.5 md:grid-cols-[minmax(200px,1fr)_180px_minmax(220px,1.4fr)]">
        <Select label={t.moveTo} required value={to} onChange={(e) => setTo(e.target.value)} hint={t.moveToHint}>
          <option value="">{t.chooseClass}</option>
          {classes.map((c) => (
            <option key={c.value} value={c.value}>
              {t.moveOption(c.label, c.count)}
            </option>
          ))}
        </Select>
        <Input label={t.moveDate} required type="date" max={today()} value={from} onChange={(e) => setFrom(e.target.value)} />
        <Input label={t.reason} required value={reason} onChange={(e) => setReason(e.target.value)} maxLength={255} />
      </div>
      <div className="mt-4 flex flex-wrap gap-2">
        <Button type="submit" busy={working}>
          {working ? t.moving : t.bulkSubmit(count, word, plural)}
        </Button>
        <Button variant="secondary" onClick={onCancel}>
          {s.cancel}
        </Button>
      </div>
    </form>
  )
}
