import { navigate, useLoad, useLocation } from '@acadly/kernel'
import { Button, DataGrid, NO_SELECTION, slips, viewState, Waiting, type GridQuery } from '@acadly/ui'
import { useState, type MouseEvent } from 'react'
import { copy } from '../copy'
import { gridsApi, type BulkAnswer } from '../grid/api'
import { BulkResult } from '../grid/BulkResult'
import { gridAddress, startExport, useGridPage, useGridPrefs, useGridQuery, useGridSelection } from '../grid/useGrid'
import { longDay } from '../registers/api'
import { Total, TotalsBand } from '../setup/parts'
import { Tag } from '../settings/parts'
import { clockSeconds, messageTone, messagesApi, type MessageState, type SentRow } from './api'
import { Facts, Heading, MessagesHead } from './parts'

const t = copy.messages
const s = copy.settings
const GRID = 'messages'
const PATH = '/settings/messages/sent'

const tone = messageTone

const channelWord = (channel: string) => t.state[channel] ?? (channel === 'sms' ? t.sms : channel === 'email' ? t.email : channel)

/** A value that is not there: a dash, read as nothing. */
function Dash() {
  return <span aria-hidden="true">{copy.dash}</span>
}

/** The name opens the message beside the list, keeping the list as it is. */
function ToLink({ row, query, open }: { row: SentRow; query: GridQuery; open: boolean }) {
  const path = gridAddress(PATH, query, { message: row.id })

  return (
    <a
      href={path}
      aria-current={open || undefined}
      onClick={(event: MouseEvent<HTMLAnchorElement>) => {
        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return
        event.preventDefault()
        navigate(path)
      }}
      className="text-ink no-underline underline-offset-[3px] hover:underline"
    >
      {row.to}
    </a>
  )
}

function StateCell({ row }: { row: SentRow }) {
  return (
    <span className="grid justify-items-start gap-0.5 whitespace-normal">
      <span className="flex flex-wrap gap-1">
        <Tag tone={tone[row.state] ?? 'plain'}>{t.state[row.state] ?? row.state}</Tag>
        {row.again && <Tag tone="plain">{t.again}</Tag>}
      </span>
      {row.reason !== null && <span className="max-w-[260px] text-xs text-text-muted">{row.reason}</span>}
    </span>
  )
}

/** Every message sent, on the data grid; the one chosen opens beside it (the approved design). */
export function SentScreen() {
  const { query: params } = useLocation()
  const chosen = params.get('message')
  // The band counts today, as before; the list below covers every day.
  const today = useLoad('messages:sent:', () => messagesApi.sent(null, '', '', ''))
  const data = today.data
  const [query, setQuery] = useGridQuery(PATH, ['message'])
  const grid = useGridPage(GRID, query, messagesApi.sentGrid)
  const [selection, setSelection] = useGridSelection(query)
  const prefs = useGridPrefs(GRID, grid.reload)
  const [result, setResult] = useState<BulkAnswer | null>(null)

  if (data === undefined) {
    return <Waiting phase={today.phase} what={t.title} labels={copy.loading} band={[t.bandSentToday, t.bandDeliveredToday, t.bandWaiting, t.bandAttentionToday]} rows={8} onRetry={today.reload} />
  }

  const page = grid.page
  const sort = { sort: page?.sort ?? query.sort, dir: page?.dir ?? query.dir }

  const labels = copy.grid.labels({
    name: t.tabs.sent,
    word: t.word,
    plural: t.plural,
    searchPlaceholder: t.search,
    columnsNote: t.gridColumnsNote,
    emptyText: t.gridEmpty,
    noMatchText: t.gridNoMatch,
  })

  const render = {
    at: (row: SentRow) => <span className="ref text-[13px]">{t.when(row.at)}</span>,
    to: (row: SentRow) => (
      <>
        <span className="block font-extrabold">
          <ToLink row={row} query={query} open={row.id === chosen} />
        </span>
        <span className="ref block text-[13px] break-all text-text-muted">{row.address ?? <Dash />}</span>
      </>
    ),
    address: (row: SentRow) => <span className="ref text-[13px]">{row.address ?? <Dash />}</span>,
    notice: (row: SentRow) => row.notice,
    channel: (row: SentRow) => <span className="text-[13px] font-bold">{channelWord(row.channel)}</span>,
    state: (row: SentRow) => <StateCell row={row} />,
    units: (row: SentRow) => <span className="ref text-[13px]">{row.channel === 'sms' && row.units > 0 ? row.units : <Dash />}</span>,
  }

  const card = (row: SentRow) => ({
    title: <ToLink row={row} query={query} open={row.id === chosen} />,
    meta: [t.when(row.at), row.notice].join(' · '),
    aside: <Tag tone={tone[row.state] ?? 'plain'}>{t.state[row.state] ?? row.state}</Tag>,
  })

  // Send again takes no input: it runs at once and its result shows above the rows.
  const bulk = (action: string) => {
    if (action !== 'again') return
    void gridsApi.bulk(GRID, { action, ...(selection.all ? { all: true as const } : { ids: selection.ids }), state: viewState(query, sort), input: {} }).then((r) => {
      if (r.kind === 'saved') {
        setResult(r.data)
        setSelection(NO_SELECTION)
        grid.reload()
        today.reload()
      } else {
        slips.wrong({ word: t.notAgain, title: r.kind === 'refused' ? s.refused : t.againFailed })
      }
    })
  }

  return (
    <>
      <MessagesHead current="sent" intro={t.gridIntro} />
      <TotalsBand>
        <Total tone="ink" label={t.bandSentToday} value={String(data.totals.sent)} note={t.bandSentNote(longDay(data.day))} />
        <Total label={t.bandDeliveredToday} value={String(data.totals.delivered)} note={t.bandDeliveredNote} />
        <Total label={t.bandWaiting} value={String(data.totals.waiting)} note={t.bandWaitingNote} />
        <Total tone={data.totals.attention > 0 ? 'attention' : 'plain'} label={t.bandAttentionToday} value={String(data.totals.attention)} note={t.bandAttentionTodayNote(data.totals.attention)} />
      </TotalsBand>
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
            alignEnd={['units']}
            card={card}
            rowName={(row) => row.to}
            rowTone={(row) => (tone[row.state] === 'wrong' ? 'wrong' : null)}
            current={chosen}
            onOpen={(row) => navigate(gridAddress(PATH, query, { message: row.id }))}
            selection={selection}
            onSelection={setSelection}
            onBulk={bulk}
            notice={
              result !== null && (
                <BulkResult
                  label={t.againLabel}
                  done={t.againDone(result.done)}
                  notDoneCount={t.notAgainCount(result.refused.length)}
                  notDone={t.notAgain}
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
          />
        </div>
        <aside className="min-w-0 flex-[1_1_340px] border-ink bg-paper px-3 pt-4.5 pb-6 max-[1023px]:border-t-2 md:px-7 min-[1024px]:border-l-2">
          {chosen === null ? <p className="text-sm text-text-muted">{t.pick}</p> : <Detail key={chosen} id={chosen} onChanged={grid.reload} />}
        </aside>
      </div>
    </>
  )
}

function Detail({ id, onChanged }: { id: string; onChanged: () => void }) {
  const loaded = useLoad(`messages:message:${id}`, () => messagesApi.message(id))
  const [working, setWorking] = useState(false)
  const [failed, setFailed] = useState<string | null>(null)
  const m = loaded.data

  if (m === undefined) return <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} rows={4} onRetry={loaded.reload} />

  const again = async () => {
    setWorking(true)
    setFailed(null)
    const result = await messagesApi.again(m.id)
    setWorking(false)

    if (result.kind === 'saved') {
      loaded.set(result.data)
      onChanged()
      slips.done({ word: copy.slips.sent, title: t.sentAgain })
    } else if (result.kind === 'invalid') setFailed(Object.values(result.errors)[0]?.[0] ?? s.failed)
    else setFailed(result.kind === 'refused' ? s.refused : s.failed)
  }

  return <DetailView m={m} working={working} failed={failed} onAgain={() => void again()} />
}

function DetailView({ m, working, failed, onAgain }: { m: MessageState; working: boolean; failed: string | null; onAgain: () => void }) {
  return (
    <>
      <Heading aside={<Tag tone={tone[m.state] ?? 'plain'}>{t.state[m.state] ?? m.state}</Tag>}>{m.to}</Heading>
      <Facts
        rows={[
          [t.colTo, <span className="ref font-normal">{m.address ?? copy.dash}</span>],
          [t.colNotice, m.notice],
          [t.providerLabel, [m.provider ?? copy.dash, m.channel === 'sms' && m.units > 0 ? t.unitsLabel(m.units) : null].filter((x) => x !== null).join(' · ')],
        ]}
      />
      <div className="my-3.5 border-2 border-ink bg-sheet p-3 text-sm leading-normal whitespace-pre-line">
        {m.email_subject !== null && <div className="mb-2 font-extrabold">{m.email_subject}</div>}
        {m.text}
      </div>
      <div className="label-caps mb-1.5 text-text-muted">{t.happened}</div>
      <ol className="text-sm">
        {m.steps.map((step, i) => (
          <li key={i} className="grid grid-cols-[80px_1fr] border-b border-rule py-1.5 last:border-b-0">
            <span className="ref text-xs">{clockSeconds(step.at)}</span>
            <span className={i === m.steps.length - 1 ? 'font-extrabold' : ''}>{t.step[step.what] ?? step.what}</span>
          </li>
        ))}
      </ol>
      {m.reason !== null && <p className="mt-2 text-[13px] text-text-muted">{m.reason}</p>}
      {m.sent_again !== null && (
        <p className="mt-2 text-sm">
          <Tag tone={tone[m.sent_again.state] ?? 'plain'}>{t.state[m.sent_again.state] ?? m.sent_again.state}</Tag> <span className="ml-1">{t.again}</span>
        </p>
      )}
      {m.can_send_again && (
        <div className="mt-3.5">
          <Button busy={working} onClick={onAgain}>
            {working ? s.saving : t.sendAgain}
          </Button>
        </div>
      )}
      {failed !== null && <p className="mt-2 text-[13px] font-bold text-red-text">{failed}</p>}
      <p className="mt-3 text-[13px] text-text-muted">{t.againNote}</p>
    </>
  )
}
