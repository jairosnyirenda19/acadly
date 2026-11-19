import { useEffect, useRef } from 'react'
import type { EChartsCoreOption, EChartsType } from 'echarts/core'

type Engine = typeof import('echarts/core')

let engine: Promise<Engine> | null = null

/** ECharts, loaded once and only the parts the dashboards draw (D-100): lines, bars, a grid, the tooltip and screen-reader text, on SVG. */
function load(): Promise<Engine> {
  engine ??= Promise.all([import('echarts/core'), import('echarts/charts'), import('echarts/components'), import('echarts/renderers')]).then(([core, charts, components, renderers]) => {
    core.use([charts.LineChart, charts.BarChart, components.GridComponent, components.TooltipComponent, components.AriaComponent, renderers.SVGRenderer])

    return core
  })

  return engine
}

/**
 * One chart drawn by ECharts in a sized frame. The option is built by the
 * caller from the panel and the palette; the frame keeps its height while the
 * engine loads, and the chart follows the frame's width.
 */
export function EChart({ option, height, label }: { option: EChartsCoreOption; height: number; label: string }) {
  const frame = useRef<HTMLDivElement>(null)
  const chart = useRef<EChartsType | null>(null)

  useEffect(() => {
    let gone = false
    let observer: ResizeObserver | null = null

    void load().then((core) => {
      if (gone || frame.current === null) return
      chart.current = core.init(frame.current, undefined, { renderer: 'svg' })
      chart.current.setOption(option, true)
      observer = new ResizeObserver(() => chart.current?.resize())
      observer.observe(frame.current)
    })

    return () => {
      gone = true
      observer?.disconnect()
      chart.current?.dispose()
      chart.current = null
    }
    // The chart is made once; the option is applied below.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  useEffect(() => {
    chart.current?.setOption(option, true)
  }, [option])

  return <div ref={frame} role="img" aria-label={label} style={{ height }} className="w-full" />
}
