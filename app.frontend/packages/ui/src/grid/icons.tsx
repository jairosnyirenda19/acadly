/**
 * The grid's few drawn icons: square caps, one stroke, from the approved
 * design. Each is decoration beside a word or inside a named control.
 */

const paths = {
  sortNone: ['M8 9l4-4 4 4', 'M8 15l4 4 4-4'],
  sortAsc: ['M7 14l5-5 5 5'],
  sortDesc: ['M7 10l5 5 5-5'],
  filters: ['M3 5h18', 'M6 12h12', 'M10 19h4'],
  columns: ['M3 4h18v16H3z', 'M9 4v16', 'M15 4v16'],
  export: ['M12 4v11', 'M7 10l5 5 5-5', 'M4 20h16'],
  close: ['M6 6l12 12', 'M18 6L6 18'],
  add: ['M12 5v14', 'M5 12h14'],
  previous: ['M15 6l-6 6 6 6'],
  next: ['M9 6l6 6-6 6'],
  check: ['M5 12l5 5 9-10'],
} as const

export type GridIconName = keyof typeof paths

export function GridIcon({ name, size = 16 }: { name: GridIconName; size?: number }) {
  return (
    <svg aria-hidden="true" width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="square" className="shrink-0">
      {paths[name].map((d) => (
        <path key={d} d={d} />
      ))}
    </svg>
  )
}
