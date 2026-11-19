import { navigate } from '@acadly/kernel'
import { useMemo, useState, type ReactNode } from 'react'
import type { EChartsCoreOption } from 'echarts/core'
import { copy } from '../copy'
import { useWorkIn } from '../shell/WorkIn'
import { EChart } from './EChart'
import { figure, tick } from './format'
import { useDivisionColour, usePalette, type Palette } from './palette'

const t = copy.dashboard

/** A panel as the server sends it (D-100): what it is, and the data of its kind. */
export interface PanelData {
  id: string
  view: string
  order: number
  width: 'full' | 'half' | 'quarter'
  kind: string
  label: string
  title: string
  finding: string | null
  data: Record<string, unknown>
}

type Tone = 'plain' | 'done' | 'attention' | 'wrong'
interface Series {
  name: string
  division_id: string | null
  values: (number | null)[]
  dashed?: boolean
  hatched?: boolean
}
interface BarRow {
  name: string
  division_id: string | null
  value: number
  whole: number | null
  note: string | null
  tone: Tone
}

const list = <T,>(v: unknown): T[] => (Array.isArray(v) ? (v as T[]) : [])
const text = (v: unknown): string => (typeof v === 'string' ? v : '')
const num = (v: unknown): number | null => (typeof v === 'number' ? v : null)
const FONT = "'Archivo Variable', 'Archivo', system-ui, sans-serif"

/** The base of every chart: recessive axes, the register's tooltip, screen-reader text. */
function base(p: Palette, unit: string): EChartsCoreOption {
  const still = typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches

  return {
    animation: !still,
    aria: { enabled: true },
    textStyle: { fontFamily: FONT, color: p.ink },
    tooltip: {
      trigger: 'axis',
      backgroundColor: p.sheet,
      borderColor: p.ink,
      borderWidth: 2,
      borderRadius: 0,
      padding: [8, 10],
      extraCssText: 'box-shadow: none;',
      textStyle: { color: p.ink, fontFamily: FONT, fontSize: 13 },
      valueFormatter: (v: unknown) => figure(typeof v === 'number' ? v : null, unit),
      axisPointer: { type: 'line', lineStyle: { color: p.ink, type: 'dashed', width: 1 } },
    },
  }
}

function xAxis(p: Palette, labels: string[]) {
  return { type: 'category', data: labels, axisLine: { lineStyle: { color: p.ink, width: 2 } }, axisTick: { show: false }, axisLabel: { color: p.muted, fontSize: 11, hideOverlap: true } }
}

function yAxis(p: Palette, unit: string, min?: number | null, max?: number | null) {
  return { type: 'value', min: min ?? undefined, max: max ?? undefined, splitLine: { lineStyle: { color: p.field } }, axisLabel: { color: p.muted, fontSize: 11, formatter: (v: number) => tick(v, unit) } }
}

function LineChart({ panel }: { panel: PanelData }) {
  const p = usePalette()
  const colour = useDivisionColour()
  const d = panel.data
  const unit = text(d.unit)
  const series = list<Series>(d.series)
  const labels = list<string>(d.labels)
  const option = useMemo<EChartsCoreOption>(
    () => ({
      ...base(p, unit),
      grid: { left: 44, right: 118, top: 14, bottom: 28 },
      xAxis: xAxis(p, labels),
      yAxis: yAxis(p, unit, num(d.min), num(d.max)),
      series: series.map((s) => {
        const c = colour(s.division_id, p)

        return {
          type: 'line',
          name: s.name,
          data: s.values,
          connectNulls: false,
          symbol: 'rect',
          symbolSize: 8,
          showSymbol: false,
          lineStyle: { width: 2, color: c, type: s.dashed === true ? 'dashed' : 'solid' },
          itemStyle: { color: c, borderColor: p.sheet, borderWidth: 2 },
          endLabel: { show: true, color: p.ink, fontWeight: 700, fontSize: 12, formatter: () => `${s.name} ${figure([...s.values].reverse().find((v) => v !== null) ?? null, unit)}` },
          labelLayout: { moveOverlap: 'shiftY' },
          emphasis: { focus: 'series' },
        }
      }),
    }),
    [p, unit, labels, series, d.min, d.max, colour],
  )

  return (
    <>
      <EChart option={option} height={230} label={panel.title} />
      {series.length > 1 && <Legend items={series.map((s) => ({ colour: colour(s.division_id, p), name: s.name, dashed: s.dashed === true }))} />}
    </>
  )
}

function ColumnChart({ panel }: { panel: PanelData }) {
  const p = usePalette()
  const colour = useDivisionColour()
  const d = panel.data
  const unit = text(d.unit)
  const stacked = d.stacked === true
  const highlight = num(d.highlight)
  const series = list<Series>(d.series)
  const labels = list<string>(d.labels)
  const option = useMemo<EChartsCoreOption>(
    () => ({
      ...base(p, unit),
      tooltip: { ...(base(p, unit).tooltip as object), axisPointer: { type: 'shadow', shadowStyle: { color: p.field } } },
      grid: { left: 48, right: 12, top: 22, bottom: 28 },
      xAxis: xAxis(p, labels),
      yAxis: yAxis(p, unit),
      series: series.map((s, si) => {
        const c = s.division_id !== null ? colour(s.division_id, p) : p.ink
        const top = si === series.length - 1

        return {
          type: 'bar',
          name: s.name,
          stack: stacked ? 'all' : undefined,
          barMaxWidth: 46,
          barGap: '12%',
          data: s.values.map((v, i) => ({
            value: v,
            itemStyle:
              highlight !== null && i !== highlight
                ? { color: p.other }
                : s.hatched === true
                  ? { color: p.sheet, borderColor: p.ink, borderWidth: 2, decal: { symbol: 'rect', dashArrayX: [1, 0], dashArrayY: [2, 4], rotation: Math.PI / 4, color: p.ink } }
                  : { color: highlight !== null && unit === 'percent' ? p.attention : c },
          })),
          itemStyle: stacked ? { borderColor: p.sheet, borderWidth: 1 } : undefined,
          label: {
            show: !stacked || top,
            position: 'top',
            color: p.ink,
            fontWeight: 700,
            fontSize: 11,
            formatter: (x: { dataIndex: number; value: number }) => {
              if (highlight !== null && x.dataIndex !== highlight && x.dataIndex !== labels.length - 1) return ''
              const total = stacked ? series.reduce((sum, one) => sum + (one.values[x.dataIndex] ?? 0), 0) : x.value

              return tick(total, unit)
            },
          },
        }
      }),
    }),
    [p, unit, labels, series, stacked, highlight, colour],
  )

  return (
    <>
      <EChart option={option} height={220} label={panel.title} />
      {series.length > 1 && <Legend items={series.map((s) => ({ colour: s.division_id !== null ? colour(s.division_id, p) : p.ink, name: s.name, hatched: s.hatched === true }))} />}
    </>
  )
}

function Legend({ items }: { items: { colour: string; name: string; dashed?: boolean; hatched?: boolean; outline?: string }[] }) {
  return (
    <ul className="flex flex-wrap gap-x-4 gap-y-1 px-4 pb-3 text-[13px]">
      {items.map((i) => (
        <li key={i.name} className="flex items-center gap-1.5">
          <span
            aria-hidden="true"
            className="inline-block size-3"
            style={
              i.hatched === true
                ? { background: `repeating-linear-gradient(45deg, ${i.colour} 0 2px, transparent 2px 5px)`, outline: `2px solid ${i.colour}`, outlineOffset: -2 }
                : i.dashed === true
                  ? { borderTop: `2px dashed ${i.colour}`, height: 0, width: 16 }
                  : i.outline !== undefined
                    ? { outline: `2px dashed ${i.outline}`, outlineOffset: -2 }
                    : { background: i.colour }
            }
          />
          {i.name}
        </li>
      ))}
    </ul>
  )
}

function Bars({ panel }: { panel: PanelData }) {
  const p = usePalette()
  const colour = useDivisionColour()
  const unit = text(panel.data.unit)
  const rows = list<BarRow>(panel.data.rows)
  const max = Math.max(1, ...rows.map((r) => r.whole ?? r.value))

  if (rows.length === 0) return <p className="px-4 pb-4 text-[14px] text-text-muted">{t.nothing}</p>

  return (
    <ul className="grid gap-2 px-4 pt-1 pb-4">
      {rows.map((r) => {
        const whole = r.whole
        const width = whole !== null ? (whole > 0 ? (r.value / whole) * 100 : 0) : (r.value / max) * 100
        const fill = r.tone === 'done' ? p.done : r.division_id !== null ? colour(r.division_id, p) : p.seq[4]

        return (
          <li key={r.name} className="grid grid-cols-[minmax(96px,150px)_1fr_auto] items-center gap-3 max-sm:grid-cols-[1fr_auto] max-sm:gap-y-1">
            <span className="truncate text-[14px] font-extrabold">{r.name}</span>
            <span className={`relative h-4 max-sm:order-3 max-sm:col-span-2 ${whole !== null ? 'bg-field' : ''}`}>
              <span className="absolute inset-y-0 left-0" style={{ width: `${Math.min(100, width)}%`, background: fill }} />
            </span>
            <span className="text-right text-[13px] tabular-nums">{r.note ?? figure(r.value, unit)}</span>
          </li>
        )
      })}
    </ul>
  )
}

function Segments({ panel }: { panel: PanelData }) {
  const p = usePalette()
  const parts = list<{ name: string; value: number }>(panel.data.parts)
  const total = parts.reduce((s, x) => s + x.value, 0)
  const shades = [p.seq[5], p.seq[4], p.seq[3], p.seq[2], p.seq[1], p.seq[0]]

  if (total === 0) return <p className="px-4 pb-4 text-[14px] text-text-muted">{t.nothing}</p>

  return (
    <>
      <div className="flex h-10 gap-0.5 px-4 pt-1">
        {parts.map((x, i) => {
          const share = Math.round((x.value / total) * 100)

          return (
            <span
              key={x.name}
              title={`${x.name}: ${share}%`}
              className={`flex items-center overflow-hidden pl-2 text-[13px] font-extrabold whitespace-nowrap tabular-nums ${i < 2 ? 'text-sheet' : 'text-ink'}`}
              style={{ flex: `${x.value} 1 0`, background: shades[i] ?? p.other }}
            >
              {share >= 6 ? `${share}%` : ''}
            </span>
          )
        })}
      </div>
      <Legend items={parts.map((x, i) => ({ colour: shades[i] ?? p.other, name: x.name }))} />
    </>
  )
}

function Bands({ panel }: { panel: PanelData }) {
  const p = usePalette()
  const names = list<string>(panel.data.names)
  const below = num(panel.data.below)
  const rows = list<{ name: string; values: number[]; note: string | null }>(panel.data.rows)
  const shades = [p.seq[5], p.seq[3], p.seq[1], p.seq[0]]
  const look = (i: number) => (i === below ? { background: p.sheet, outline: '2px dashed var(--color-red-pen)', outlineOffset: -2 } : { background: shades[i] ?? p.other })

  if (rows.length === 0) return <p className="px-4 pb-4 text-[14px] text-text-muted">{t.nothing}</p>

  return (
    <>
      <ul className="grid gap-2 px-4 pt-1 pb-3">
        {rows.map((r) => {
          const total = r.values.reduce((s, v) => s + v, 0) || 1

          return (
            <li key={r.name} className="grid grid-cols-[minmax(80px,120px)_1fr_auto] items-center gap-3">
              <span className="text-[14px] font-extrabold">{r.name}</span>
              <span className="flex h-[22px] gap-0.5">
                {r.values.map((v, i) => (v > 0 ? <span key={i} title={`${names[i] ?? ''}: ${Math.round((v / total) * 100)}%`} style={{ flex: `${v} 1 0`, ...look(i) }} /> : null))}
              </span>
              <span className="text-right text-[13px] font-extrabold tabular-nums">{r.note}</span>
            </li>
          )
        })}
      </ul>
      <Legend items={names.map((n, i) => (i === below ? { colour: p.sheet, name: n, outline: 'var(--color-red-pen)' } : { colour: shades[i] ?? p.other, name: n }))} />
    </>
  )
}

function Heat({ panel }: { panel: PanelData }) {
  const p = usePalette()
  const columns = list<string>(panel.data.columns)
  const rule = num(panel.data.rule)
  const rows = list<{ name: string; values: (number | null)[] }>(panel.data.rows)
  const step = (v: number) => Math.max(0, Math.min(5, Math.floor((v - 80) / 3.4)))

  return (
    <div className="overflow-x-auto px-4 pt-1 pb-2">
      <table className="border-separate border-spacing-[3px] text-[11px]">
        <thead>
          <tr>
            <th />
            {columns.map((c, i) => (
              <th key={i} scope="col" className="px-1 pb-0.5 font-normal text-text-muted">
                {c}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={r.name}>
              <th scope="row" className="pr-2 text-left text-[13px] font-extrabold whitespace-nowrap">
                {r.name}
              </th>
              {r.values.map((v, i) =>
                v === null ? (
                  <td key={i} className="h-7 min-w-[44px] text-center text-text-muted outline-2 -outline-offset-2 outline-rule-dashed outline-dashed">
                    –
                  </td>
                ) : (
                  <td
                    key={i}
                    title={`${r.name} · ${columns[i] ?? ''}: ${figure(v, 'percent')}`}
                    className={`h-7 min-w-[44px] text-center font-extrabold tabular-nums ${step(v) >= 3 ? 'text-sheet' : 'text-ink'}`}
                    style={{ background: p.seq[step(v)], ...(rule !== null && v < rule ? { outline: `3px solid ${p.attention}`, outlineOffset: -3 } : {}) }}
                  >
                    {rule !== null && v < rule ? `!${Math.round(v)}` : Math.round(v)}
                  </td>
                ),
              )}
            </tr>
          ))}
        </tbody>
      </table>
      <div className="mt-2 flex flex-wrap items-center gap-1.5 text-[12px] text-text-muted">
        <span>80%</span>
        {p.seq.map((c) => (
          <span key={c} aria-hidden="true" className="h-2.5 w-5" style={{ background: c }} />
        ))}
        <span>100%</span>
        {rule !== null && <span className="ml-3 px-1.5 font-extrabold text-ink outline-3 -outline-offset-3" style={{ outlineColor: p.attention, outlineStyle: 'solid' }}>!</span>}
        {rule !== null && <span>{t.below(rule)}</span>}
        <span className="ml-3 inline-block size-3 outline-2 -outline-offset-2 outline-rule-dashed outline-dashed" aria-hidden="true" />
        <span>{t.notTaken}</span>
      </div>
    </div>
  )
}

function Mark({ tone, children }: { tone: Tone | null; children: ReactNode }) {
  const look = tone === 'attention' ? 'bg-marigold text-ink' : tone === 'wrong' ? 'bg-red-pen text-sheet' : tone === 'done' ? 'bg-teal text-sheet' : 'bg-field text-ink'

  return <span className={`label-caps px-[7px] py-[3px] whitespace-nowrap ${look}`}>{children}</span>
}

function ListRows({ panel }: { panel: PanelData }) {
  const p = usePalette()
  const colour = useDivisionColour()
  const { divisions, enter } = useWorkIn()
  const rows = list<{ division_id: string | null; text: string; tone: Tone }>(panel.data.rows)

  if (rows.length === 0) return <p className="px-4 pb-4 text-[14px] text-text-muted">{t.nothingWaiting}</p>

  return (
    <ul className="border-t border-rule">
      {rows.map((r, i) => {
        const division = divisions.find((d) => d.id === r.division_id)

        return (
          <li key={i} className="flex flex-wrap items-center gap-2.5 border-b border-rule px-4 py-2">
            <Mark tone={r.tone}>{r.tone === 'wrong' ? '✕' : '!'}</Mark>
            <span aria-hidden="true" className="size-3" style={{ background: colour(r.division_id, p) }} />
            <span className="flex-1 text-[14px]">
              <b>{division?.name ?? ''}</b> · {r.text}
            </span>
            {division !== undefined && (
              <button type="button" className="text-[14px] font-extrabold underline" onClick={() => (enter(division.id), navigate('/'))}>
                {t.openIn(division.name)}
              </button>
            )}
          </li>
        )
      })}
    </ul>
  )
}

function TableRows({ panel }: { panel: PanelData }) {
  const heads = list<string>(panel.data.heads)
  const rows = list<{ text: string; tone: Tone | null }[]>(panel.data.rows)

  if (rows.length === 0) return <p className="px-4 pb-4 text-[14px] text-text-muted">{t.nothing}</p>

  return <Table heads={heads} rows={rows.map((r) => r.map((c) => (c.tone !== null ? <Mark tone={c.tone}>{c.text}</Mark> : c.text)))} />
}

function Table({ heads, rows }: { heads: string[]; rows: ReactNode[][] }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full border-collapse bg-sheet text-[14px]">
        <thead>
          <tr>
            {heads.map((h) => (
              <th key={h} scope="col" className="label-caps border-b-2 border-ink px-3 py-2 text-left text-text-muted">
                {h}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((r, i) => (
            <tr key={i}>
              {r.map((c, j) => (
                <td key={j} className={`border-b border-rule px-3 py-2 tabular-nums ${j === 0 ? 'font-extrabold' : ''}`}>
                  {c}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

/** The same numbers as the chart, as a table: for screen readers, printing, and anyone who prefers one. */
function AsTable({ panel }: { panel: PanelData }) {
  const d = panel.data
  const unit = text(d.unit)

  switch (panel.kind) {
    case 'line':
    case 'columns': {
      const series = list<Series>(d.series)

      return <Table heads={[t.period, ...series.map((s) => s.name)]} rows={list<string>(d.labels).map((l, i) => [l, ...series.map((s) => figure(s.values[i] ?? null, unit))])} />
    }
    case 'bars':
      return <Table heads={[t.name, t.value]} rows={list<BarRow>(d.rows).map((r) => [r.name, r.note ?? figure(r.value, unit)])} />
    case 'segments': {
      const parts = list<{ name: string; value: number }>(d.parts)
      const total = parts.reduce((s, x) => s + x.value, 0) || 1

      return <Table heads={[t.name, t.value, t.share]} rows={parts.map((x) => [x.name, figure(x.value, unit), `${Math.round((x.value / total) * 100)}%`])} />
    }
    case 'bands':
      return <Table heads={[t.name, ...list<string>(d.names)]} rows={list<{ name: string; values: number[] }>(d.rows).map((r) => [r.name, ...r.values.map((v) => figure(v, 'count'))])} />
    case 'heat':
      return <Table heads={[t.name, ...list<string>(d.columns)]} rows={list<{ name: string; values: (number | null)[] }>(d.rows).map((r) => [r.name, ...r.values.map((v) => figure(v, 'percent'))])} />
    default:
      return null
  }
}

const TABLED = ['line', 'columns', 'bars', 'segments', 'bands', 'heat']

/** One panel of a dashboard: a label, a title, the finding in one sentence, and the chart or its table. */
export function Panel({ panel }: { panel: PanelData }) {
  const [asTable, setAsTable] = useState(false)
  const body = (() => {
    if (asTable) return <AsTable panel={panel} />
    switch (panel.kind) {
      case 'line':
        return <LineChart panel={panel} />
      case 'columns':
        return <ColumnChart panel={panel} />
      case 'bars':
        return <Bars panel={panel} />
      case 'segments':
        return <Segments panel={panel} />
      case 'bands':
        return <Bands panel={panel} />
      case 'heat':
        return <Heat panel={panel} />
      case 'list':
        return <ListRows panel={panel} />
      case 'table':
        return <TableRows panel={panel} />
      default:
        return null
    }
  })()

  return (
    <section aria-labelledby={`panel-${panel.id}`} className={`min-w-0 border-2 border-ink bg-sheet ${panel.width === 'full' ? 'lg:col-span-2' : ''}`}>
      <header className="flex items-start gap-3 px-4 pt-3 pb-2">
        <span className="min-w-0 flex-1">
          <span className="label-caps block text-text-muted">{panel.label}</span>
          <h2 id={`panel-${panel.id}`} className="text-[18px] font-extrabold">
            {panel.title}
          </h2>
          {panel.finding !== null && <p className="mt-0.5 text-[14px] text-text-muted">{panel.finding}</p>}
        </span>
        {TABLED.includes(panel.kind) && (
          <span role="group" aria-label={t.showAs} className="inline-flex shrink-0 border-2 border-ink text-[12px] font-extrabold">
            <button type="button" aria-pressed={!asTable} onClick={() => setAsTable(false)} className={`px-2 py-0.5 ${asTable ? '' : 'bg-ink text-sheet'}`}>
              {t.chart}
            </button>
            <button type="button" aria-pressed={asTable} onClick={() => setAsTable(true)} className={`px-2 py-0.5 ${asTable ? 'bg-ink text-sheet' : ''}`}>
              {t.table}
            </button>
          </span>
        )}
      </header>
      {body}
    </section>
  )
}

/** A small line of the last values, for a total. */
function Spark({ values, colour }: { values: number[]; colour: string }) {
  if (values.length < 2) return null
  const w = 110
  const h = 28
  const min = Math.min(...values)
  const span = Math.max(...values) - min || 1
  const x = (i: number) => 2 + ((w - 8) * i) / (values.length - 1)
  const y = (v: number) => h - 4 - ((v - min) / span) * (h - 8)
  const last = values[values.length - 1] ?? 0

  return (
    <svg aria-hidden="true" width={w} height={h} className="shrink-0">
      <polyline fill="none" stroke={colour} strokeWidth={2} points={values.map((v, i) => `${x(i)},${y(v)}`).join(' ')} />
      <rect x={x(values.length - 1) - 3} y={y(last) - 3} width={6} height={6} fill={colour} />
    </svg>
  )
}

/** The band of totals at the head of a view: the first cell solid ink, a cell that needs attention solid marigold. */
export function Totals({ panels }: { panels: PanelData[] }) {
  const p = usePalette()

  if (panels.length === 0) return null

  return (
    <div className="grid grid-cols-2 gap-0.5 border-2 border-ink bg-ink lg:grid-cols-[repeat(auto-fit,minmax(200px,1fr))]">
      {panels.map((k) => {
        const tone = text(k.data.tone)
        const ink = tone === 'ink'
        const attention = tone === 'attention'
        const value = num(k.data.value)
        const trend = list<number>(k.data.trend)
        const shown = figure(value, text(k.data.unit))

        return (
          <div key={k.id} className={`grid content-start gap-1 px-4 py-3 ${ink ? 'bg-ink text-sheet' : attention ? 'bg-marigold text-ink' : 'bg-sheet text-ink'}`}>
            <span className={`label-caps ${ink ? 'text-on-ink-muted' : attention ? 'text-ink' : 'text-text-muted'}`}>{k.label}</span>
            <span className={`type-numeral leading-[0.86] ${shown.length > 7 ? 'text-[30px] lg:text-[38px]' : 'text-[40px] lg:text-[52px]'} ${ink ? 'text-accent' : ''}`}>
              {attention && value !== null && value > 0 ? `! ${shown}` : shown}
            </span>
            <span className="flex flex-wrap items-end justify-between gap-2">
              <span className="text-[13px]">{text(k.data.sentence)}</span>
              <Spark values={trend} colour={ink ? 'var(--color-accent)' : p.ink} />
            </span>
          </div>
        )
      })}
    </div>
  )
}
