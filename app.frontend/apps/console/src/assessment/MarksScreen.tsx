import { navigate, useAppContext, useLoad, useLocation } from '@acadly/kernel'
import { Button, DataGrid, NO_SELECTION, slips, viewState, Waiting, type GridQuery } from '@acadly/ui'
import { useState, type MouseEvent } from 'react'
import { copy } from '../copy'
import { gridsApi, type BulkAnswer } from '../grid/api'
import { BulkResult } from '../grid/BulkResult'
import { gridAddress, startExport, useGridPage, useGridPrefs, useGridQuery, useGridSelection } from '../grid/useGrid'
import { publishesResults, seesAllMarks } from '../shell/navigation'
import { PublishScreen } from './PublishScreen'
import { assessmentApi, stateTone, type AssessRow, type MineRow } from './api'
import { InLink, LockPanel, SmallBand, StateTag, follow } from './parts'

const a = copy.assessment
const t = a.marks
const s = copy.settings
const GRID = 'assessments'

type View = 'mine' | 'all' | 'publish'

/** Marks (the approved designs): a teacher's assessments, phone first; for the office, every assessment with lock and return. */
export function MarksScreen({ view }: { view: View }) {
  const context = useAppContext()
  const office = seesAllMarks(context)
  const publisher = publishesResults(context)
  const shown: View = view === 'all' && office ? 'all' : view === 'publish' && publisher ? 'publish' : 'mine'
  const tabs: View[] = ['mine', ...(office ? (['all'] as const) : []), ...(publisher ? (['publish'] as const) : [])]

  return (
    <>
      <div className="flex flex-wrap items-end gap-3 px-4 pt-5 pb-3 md:px-[clamp(16px,3vw,40px)] md:pt-7 md:pb-5">
        <div className="min-w-0 flex-[1_1_420px]">
          <div className="label-caps text-text-muted">{t.kicker(context.user.name)}</div>
          <h1 className="type-display mt-1 mb-2 text-[clamp(48px,7vw,88px)]">{t.title}</h1>
          <p className="hidden max-w-[680px] text-[15px] text-text-muted md:block">{shown === 'all' ? t.officeIntro : shown === 'publish' ? a.publish.intro : t.intro}</p>
        </div>
      </div>
      {tabs.length > 1 && (
        <nav aria-label={t.tabsLabel} className="flex gap-1 overflow-x-auto border-b-2 border-ink bg-sheet px-2 [scrollbar-width:none] md:px-[clamp(16px,3vw,40px)]">
          {tabs.map((key) => {
            const href = key === 'mine' ? '/marks' : `/marks/${key}`

            return (
              <a
                key={key}
                href={href}
                aria-current={key === shown ? 'page' : undefined}
                onClick={(e) => follow(e, href)}
                className={`inline-flex min-h-11 shrink-0 items-center border-b-4 px-3.5 pt-2.5 pb-2 text-[15px] whitespace-nowrap text-ink no-underline ${key === shown ? 'border-ink font-extrabold' : 'border-transparent font-medium hover:bg-paper'}`}
              >
                {t.tabs[key]}
              </a>
            )
          })}
        </nav>
      )}
      {shown === 'all' ? <AllAssessments /> : shown === 'publish' ? <PublishScreen /> : <Mine office={office} />}
    </>
  )
}

function Mine({ office }: { office: boolean }) {
  const loaded = useLoad('assessment:mine', () => assessmentApi.mine())
  const data = loaded.data

  if (data === undefined) return <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} band={[t.bandAll, t.bandToFinish, t.bandLocked]} rows={5} onRetry={loaded.reload} />

  return (
    <>
      <SmallBand
        cells={[
          { label: t.bandAll, value: String(data.totals.all), tone: 'ink' },
          { label: t.bandToFinish, value: String(data.totals.to_finish), tone: data.totals.to_finish > 0 ? 'attention' : 'plain' },
          { label: t.bandLocked, value: String(data.totals.locked) },
        ]}
      />
      <section className="flex flex-1 flex-col bg-sheet">
        {data.looks_after.length > 0 && (
          <div className="flex flex-wrap items-baseline gap-x-3 border-b border-rule px-4 py-2.5 md:px-[clamp(16px,3vw,40px)]">
            <span className="label-caps text-text-muted">{t.looksAfter}</span>
            {data.looks_after.map((c) => (
              <InLink key={c.class_id} href={`/marks/comments?class_id=${c.class_id}`}>
                {c.class} ›
              </InLink>
            ))}
            <span className="basis-full text-[13px] text-text-muted md:basis-auto">{t.looksAfterNote}</span>
          </div>
        )}
        {data.assessments.length === 0 ? (
          <div className="m-4 border-2 border-ink p-4 md:mx-[clamp(16px,3vw,40px)]">
            <p className="type-display mb-1.5 text-[22px] leading-none">{t.emptyTitle}</p>
            <p className="text-sm">{office && !data.can_set ? t.emptyOffice : t.emptyText}</p>
          </div>
        ) : (
          <ul className="md:mx-[clamp(16px,3vw,40px)]">
            {data.assessments.map((row) => (
              <MineLine key={row.id} row={row} />
            ))}
          </ul>
        )}
        {data.can_set && (
          <div className="sticky bottom-0 mt-auto border-t-2 border-ink bg-sheet px-4 py-2.5 md:px-[clamp(16px,3vw,40px)]">
            <Button className="h-12 w-full md:w-auto" onClick={() => navigate('/marks/new')}>
              {t.set}
            </Button>
          </div>
        )}
      </section>
    </>
  )
}

function MineLine({ row }: { row: MineRow }) {
  const href = `/marks/${row.id}`
  const tone = stateTone[row.state]

  return (
    <li className={`border-b border-rule ${tone === 'wrong' ? 'bg-red-wash' : ''}`}>
      <a href={href} onClick={(e: MouseEvent<HTMLAnchorElement>) => follow(e, href)} className="flex min-h-14 items-center gap-3 px-4 py-2.5 text-ink no-underline hover:bg-paper md:px-3">
        <span className="min-w-0 flex-1">
          <span className="label-caps block text-text-muted">{a.set.choice(row.class, row.subject)}</span>
          <span className="block text-base font-extrabold">{row.title}</span>
          <span className="block text-[13px] text-text-muted">
            {[a.day(row.held_on), t.progress(row.state, row.marked, row.learners, row.absent)].join(a.joiner)}
          </span>
        </span>
        <StateTag state={row.state} label={row.state_label} />
      </a>
    </li>
  )
}

function AllAssessments() {
  const PATH = '/marks/all'
  const { query: params } = useLocation()
  const chosen = params.get('assessment')
  const [query, setQuery] = useGridQuery(PATH, ['assessment'])
  const grid = useGridPage(GRID, query, assessmentApi.grid)
  const [selection, setSelection] = useGridSelection(query)
  const prefs = useGridPrefs(GRID, grid.reload)
  const [result, setResult] = useState<BulkAnswer | null>(null)
  const page = grid.page
  const sort = { sort: page?.sort ?? query.sort, dir: page?.dir ?? query.dir }

  const labels = copy.grid.labels(t.grid)
  const open = (row: AssessRow, q: GridQuery) => gridAddress(PATH, q, { assessment: row.id })

  const render = {
    assessment: (row: AssessRow) => {
      const href = open(row, query)

      return (
        <>
          <a href={href} aria-current={row.id === chosen || undefined} onClick={(e) => follow(e, href)} className="block font-extrabold text-ink no-underline underline-offset-[3px] hover:underline">
            {a.pair(row.subject, row.title)}
          </a>
          <span className="block text-[13px] text-text-muted">{[row.class, row.kind_label, row.counts ? null : a.practice].filter((x) => x !== null).join(a.joiner)}</span>
        </>
      )
    },
    class: (row: AssessRow) => row.class,
    teacher: (row: AssessRow) => row.teacher ?? <span aria-hidden="true">{copy.dash}</span>,
    held_on: (row: AssessRow) => <span className="tabular-nums">{a.day(row.held_on)}</span>,
    marked: (row: AssessRow) => <span className="ref text-[13px]">{a.fraction(row.marked, row.learners)}</span>,
    average: (row: AssessRow) => <span className="ref text-[13px]">{a.pct(row.average)}</span>,
    state: (row: AssessRow) => <StateTag state={row.state} label={row.state_label} />,
  }

  const bulk = (action: string) => {
    void gridsApi.bulk(GRID, { action, ...(selection.all ? { all: true as const } : { ids: selection.ids }), state: viewState(query, sort), input: {} }).then((r) => {
      if (r.kind === 'saved') {
        setResult(r.data)
        setSelection(NO_SELECTION)
        grid.reload()
      } else slips.wrong({ word: t.notLocked, title: r.kind === 'refused' ? s.refused : t.lockFailed })
    })
  }

  return (
    <div className="flex flex-1 flex-wrap items-stretch">
      <div className="flex min-w-0 flex-[3_1_640px]">
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
          alignEnd={['marked', 'average']}
          card={(row) => ({
            title: (
              <a href={open(row, query)} onClick={(e) => follow(e, open(row, query))} className="text-ink no-underline">
                {a.pair(row.subject, row.title)}
              </a>
            ),
            meta: [row.class, a.day(row.held_on), a.fraction(row.marked, row.learners)].join(a.joiner),
            aside: <StateTag state={row.state} label={row.state_label} />,
          })}
          rowName={(row) => `${row.class} ${row.subject} ${row.title}`}
          rowTone={(row) => (row.state === 'submitted' ? 'attention' : null)}
          current={chosen}
          selection={selection}
          onSelection={setSelection}
          onBulk={bulk}
          notice={
            result !== null && (
              <BulkResult
                label={t.resultLabel}
                done={t.lockedDone(result.done)}
                notDoneCount={t.notLockedCount(result.refused.length)}
                notDone={t.notLocked}
                line={t.resultLine}
                refused={result.refused}
                close={t.close}
                onClose={() => setResult(null)}
              />
            )
          }
          onColumns={prefs.onColumns}
          views={prefs.views}
          onExport={(scope) => {
            if (page !== undefined) startExport(GRID, scope, { page, query, selection, word: t.grid.word, plural: t.grid.plural })
          }}
        />
      </div>
      <aside className="min-w-0 flex-[1_1_340px] border-ink bg-paper px-4 pt-4.5 pb-6 max-[1023px]:border-t-2 md:px-7 min-[1024px]:border-l-2">
        {chosen === null ? <p className="text-sm text-text-muted">{t.pick}</p> : <Detail key={chosen} id={chosen} onChanged={grid.reload} />}
      </aside>
    </div>
  )
}

function Detail({ id, onChanged }: { id: string; onChanged: () => void }) {
  const loaded = useLoad(`assessment:sheet:${id}`, () => assessmentApi.sheet(id))
  const sheet = loaded.data

  if (sheet === undefined) return <Waiting phase={loaded.phase} what={a.panel.label} labels={copy.loading} rows={3} onRetry={loaded.reload} />

  return (
    <>
      <LockPanel
        sheet={sheet}
        onDone={(next) => {
          loaded.set(next)
          onChanged()
        }}
      />
      <div className="mt-2 flex flex-col">
        <InLink href={`/marks/${sheet.assessment.id}`}>{a.panel.openSheet}</InLink>
        <InLink href={`/marks/results?class_id=${sheet.assessment.class_id}`}>{a.panel.seeResults}</InLink>
      </div>
    </>
  )
}

