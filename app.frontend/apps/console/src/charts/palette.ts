import { useAppContext } from '@acadly/kernel'
import { useEffect, useState } from 'react'

/**
 * The chart colours (D-100), read from the semantic tokens so light and dark
 * each use their own checked steps. A division keeps one colour on every
 * chart: its place in the school's order of divisions, not its rank.
 */
export interface Palette {
  series: string[]
  other: string
  seq: string[]
  ink: string
  muted: string
  rule: string
  field: string
  sheet: string
  attention: string
  done: string
}

function read(): Palette {
  const css = getComputedStyle(document.documentElement)
  const v = (name: string) => css.getPropertyValue(name).trim()

  return {
    series: [1, 2, 3, 4].map((i) => v(`--color-series-${i}`)),
    other: v('--color-series-other'),
    seq: [1, 2, 3, 4, 5, 6].map((i) => v(`--color-seq-${i}`)),
    ink: v('--color-ink'),
    muted: v('--color-text-muted'),
    rule: v('--color-rule'),
    field: v('--color-field'),
    sheet: v('--color-sheet'),
    attention: v('--color-marigold'),
    done: v('--color-teal'),
  }
}

/** The palette, read again when the theme or the school's look changes. */
export function usePalette(): Palette {
  const [palette, setPalette] = useState(read)

  useEffect(() => {
    const observer = new MutationObserver(() => setPalette(read()))
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme', 'style', 'class'] })

    return () => observer.disconnect()
  }, [])

  return palette
}

/** A division's colour: by its place in the school's divisions. A fifth division and beyond, and anything not a division, is the grey of the rest. */
export function useDivisionColour(): (divisionId: string | null | undefined, palette: Palette) => string {
  const context = useAppContext()
  const order = context.divisions.map((d) => d.id)

  return (divisionId, palette) => {
    const i = divisionId === null || divisionId === undefined ? -1 : order.indexOf(divisionId)

    return i >= 0 && i < palette.series.length ? (palette.series[i] ?? palette.other) : palette.ink
  }
}
