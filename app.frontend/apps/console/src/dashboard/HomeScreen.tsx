import { api, navigate, useLoad, useLocation, type paths } from '@acadly/kernel'
import { Waiting } from '@acadly/ui'
import { copy } from '../copy'
import { Panel, Totals, type PanelData } from '../charts/Panel'
import { figure } from '../charts/format'
import { usePalette } from '../charts/palette'
import { day } from '../settings/api'
import { read } from '../setup/api'
import { useWorkIn } from '../shell/WorkIn'

type Today = paths['/v1/today']['get']['responses'][200]['content']['application/json']
type Item = Today['items'][number]
type Now = Today['now'][number]
type Chart = NonNullable<Today['chart']>

const t = copy.today
const d = copy.dashboard

function Mark({ tone, text }: { tone: Item['tone']; text: string }) {
  const look = tone === 'attention' ? 'bg-marigold text-ink' : tone === 'wrong' ? 'bg-red-pen text-sheet' : tone === 'done' ? 'bg-teal text-sheet' : 'bg-field text-ink'

  return <span className={`label-caps px-[7px] py-[3px] whitespace-nowrap ${look}`}>{text}</span>
}

const go = (href: string | null) => (e: React.MouseEvent) => {
  e.preventDefault()
  if (href !== null) navigate(href)
}

/** What is happening now: the register of the person's class, the lesson now or next. One action each. */
function NowCard({ now }: { now: Now }) {
  return (
    <section aria-label={now.label} className="border-[3px] border-teal bg-sheet p-3">
      <div className="flex items-center justify-between gap-2">
        <span className="label-caps text-teal">{now.label}</span>
        {now.state !== null && <Mark tone={now.state.tone} text={now.state.text} />}
      </div>
      <div className="type-display mt-1.5 text-[36px] leading-[0.86]">{now.title}</div>
      <div className="mb-2.5 text-[15px] font-bold">{now.sub}</div>
      <a href={now.href} onClick={go(now.href)} className="flex h-12 w-full items-center justify-center bg-teal text-[17px] font-extrabold text-sheet no-underline hover:bg-ink">
        {now.action}
      </a>
    </section>
  )
}

/** One small chart of the person's own week. */
function WeekChart({ chart }: { chart: Chart }) {
  const p = usePalette()

  if (chart.kind === 'segments') {
    const total = chart.parts.reduce((s, x) => s + x.value, 0) || 1
    const fill = (tone: string) => (tone === 'done' ? 'bg-teal text-sheet' : tone === 'attention' ? 'bg-marigold text-ink' : 'bg-sheet text-ink outline-2 -outline-offset-2 outline-dashed outline-rule-dashed')

    return (
      <section className="border-2 border-ink bg-sheet px-3 py-2">
        <div className="label-caps text-text-muted">{chart.label}</div>
        <div className="my-1.5 flex h-[26px] gap-0.5">
          {chart.parts.map((x) =>
            x.value > 0 ? (
              <span key={x.name} title={`${x.name}: ${x.value}`} className={`flex items-center pl-1.5 text-[12px] font-extrabold ${fill(x.tone)}`} style={{ flex: `${x.value} 1 0` }}>
                {x.value}
              </span>
            ) : null,
          )}
        </div>
        <ul className="flex flex-wrap gap-x-3 text-[12px]">
          {chart.parts.map((x) => (
            <li key={x.name} className="flex items-center gap-1">
              <span aria-hidden="true" className={`size-2.5 ${fill(x.tone)}`} />
              {x.name} {Math.round((x.value / total) * 100)}%
            </li>
          ))}
        </ul>
      </section>
    )
  }

  const values = chart.values
  const max = Math.max(1, ...values.filter((v): v is number => v !== null))
  const lastKnown = values.reduce<number>((at, v, i) => (v !== null ? i : at), -1)

  return (
    <section className="border-2 border-ink bg-sheet px-3 py-2">
      <div className="label-caps text-text-muted">{chart.label}</div>
      <div role="img" aria-label={`${chart.label}: ${chart.labels.map((l, i) => `${l} ${figure(values[i] ?? null, chart.unit)}`).join(', ')}`} className="mt-1.5 flex h-16 items-end gap-1.5 border-b-2 border-ink">
        {values.map((v, i) =>
          v === null ? (
            <span key={i} aria-hidden="true" className="h-11 flex-1 outline-2 -outline-offset-2 outline-dashed outline-rule-dashed" />
          ) : (
            <span key={i} aria-hidden="true" className="flex h-full flex-1 flex-col items-center justify-end">
              <span className="text-[11px] font-bold tabular-nums">{figure(v, chart.unit)}</span>
              <span className="w-full" style={{ height: Math.max(6, (v / max) * 44), background: i === lastKnown ? p.ink : p.other }} />
            </span>
          ),
        )}
      </div>
      <div aria-hidden="true" className="flex gap-1.5 text-[11px] text-text-muted">
        {chart.labels.map((l, i) => (
          <span key={i} className="flex-1 text-center">
            {l}
          </span>
        ))}
      </div>
    </section>
  )
}

/** The to-do list: attention first, each row opening the page where the work is done. */
function Todo({ items, title }: { items: Item[]; title: string }) {
  return (
    <section aria-labelledby="todo" className="border-2 border-ink bg-sheet">
      <h2 id="todo" className="label-caps border-b-2 border-ink px-3 py-2 text-text-muted">
        {title}
      </h2>
      {items.length === 0 ? (
        <div className="px-3 py-4">
          <p className="font-extrabold">{t.nothing}</p>
          <p className="text-[14px] text-text-muted">{t.nothingNote}</p>
        </div>
      ) : (
        <ol>
          {items.map((i) => (
            <li key={i.key} className="border-b border-rule last:border-b-0">
              <a href={i.href ?? '#'} onClick={go(i.href)} className="flex min-h-14 items-center gap-2.5 px-3 py-2 text-ink no-underline hover:bg-field">
                <span className="min-w-0 flex-1">
                  <b className="block text-[15px]">{i.title}</b>
                  <span className="text-[13px] text-text-muted">{i.sub}</span>
                </span>
                {i.count !== null && <Mark tone={i.tone} text={i.count} />}
                <span aria-hidden="true" className="font-extrabold">
                  ›
                </span>
              </a>
            </li>
          ))}
        </ol>
      )}
    </section>
  )
}

/** The tabs of a dashboard: text with an edge under the current one. The view is kept in the address. */
function Views({ views, current }: { views: { key: string; label: string }[]; current: string | null }) {
  if (views.length < 2) return null

  return (
    <nav aria-label={d.views} className="flex overflow-x-auto overflow-y-hidden border-b-2 border-ink [scrollbar-width:none]">
      {views.map((v) => (
        <a
          key={v.key}
          href={`/?view=${v.key}`}
          aria-current={v.key === current ? 'page' : undefined}
          onClick={(e) => (e.preventDefault(), navigate(v.key === views[0]?.key ? '/' : `/?view=${v.key}`))}
          className={`-mb-0.5 inline-flex min-h-11 shrink-0 items-center border-b-4 px-4 pt-2.5 pb-2 text-[15px] whitespace-nowrap text-ink no-underline ${v.key === current ? 'border-teal font-extrabold' : 'border-transparent font-medium hover:bg-paper'}`}
        >
          {v.label}
        </a>
      ))}
    </nav>
  )
}

function Panels({ panels }: { panels: PanelData[] }) {
  const totals = panels.filter((p) => p.kind === 'kpi')
  const rest = panels.filter((p) => p.kind !== 'kpi')

  return (
    <>
      <Totals panels={totals} />
      {rest.length > 0 && (
        <div className="grid items-start gap-3 lg:grid-cols-2">
          {rest.map((p) => (
            <Panel key={p.id} panel={p} />
          ))}
        </div>
      )}
    </>
  )
}

/**
 * The home page (D-100). In the school view, for those who may see it, the
 * whole-school overview and its views. Inside a division, the person's Today:
 * what is happening now and their to-do list, and for those who lead the
 * division its figures, with views of its own.
 */
export function HomeScreen() {
  const { query } = useLocation()
  const { division, place } = useWorkIn()
  const view = query.get('view')
  const dashboard = useLoad(`dashboard:${view ?? ''}`, () => read(api.GET('/v1/dashboard', { params: { query: view !== null ? { view } : {} } })))
  const today = useLoad('today', () => read(api.GET('/v1/today')))
  const dash = dashboard.data
  const mine = today.data

  if (mine === undefined) return <Waiting phase={today.phase} what={t.title} labels={copy.loading} rows={6} onRetry={today.reload} />

  const panels = (dash?.panels ?? []) as PanelData[]
  const views = dash?.views ?? []
  const current = dash?.view ?? null
  const school = dash?.school === true
  const leads = panels.length > 0
  const onFirst = current === null || current === views[0]?.key
  const where = school ? '' : (division?.name ?? place?.label ?? '')
  const date = day(new Date().toISOString().slice(0, 10))
  const title = school && onFirst ? d.title : !onFirst ? (views.find((v) => v.key === current)?.label ?? t.title) : t.title

  return (
    <div className="grid gap-4 px-[clamp(16px,3vw,40px)] pt-6 pb-8">
      <div>
        <div className="label-caps text-text-muted">{school ? d.kicker(date) : t.kicker(where, date)}</div>
        <h1 className="type-display mt-1 text-[clamp(48px,6vw,88px)] leading-[0.86]">{title}</h1>
      </div>

      <Views views={views} current={current} />

      {dash === undefined && dashboard.phase !== 'refused' && dashboard.phase !== 'failed' && <Waiting phase={dashboard.phase} what={t.title} labels={copy.loading} rows={4} onRetry={dashboard.reload} />}

      {/* The person's own day: now, their week, their to-do list. Only inside a place, and only once the page knows it is not the school view. */}
      {onFirst && dash !== undefined && !school && (mine.now.length > 0 || mine.chart !== null) && (
        <div className="grid items-start gap-3 md:grid-cols-2 xl:grid-cols-3">
          {mine.now.map((n) => (
            <NowCard key={n.key} now={n} />
          ))}
          {mine.chart !== null && <WeekChart chart={mine.chart} />}
        </div>
      )}

      {leads && <Panels panels={panels} />}

      {onFirst && dash !== undefined && <Todo items={mine.items} title={leads ? t.forYou : t.todo} />}
      {school && onFirst && panels.length === 0 && dash !== undefined && <p className="text-text-muted">{d.noViews}</p>}
    </div>
  )
}
