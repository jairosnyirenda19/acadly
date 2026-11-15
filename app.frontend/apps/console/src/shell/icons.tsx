/**
 * The console's line icons: one stroke, square ends, no rounding (DESIGN.md).
 * Drawn here, not taken from a set, so every icon shares the register book's hand.
 */
const PATHS = {
  today: 'M4 5h16v15H4z M4 10h16 M9 3v4 M15 3v4 M8 14h3v3H8z',
  people: 'M9 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6z M3 20v-1a5 5 0 0 1 5-5h2a5 5 0 0 1 5 5v1 M16 5a3 3 0 0 1 0 6 M18 14a5 5 0 0 1 3 5v1',
  roles: 'M4 4h16v16H4z M8 12l3 3 5-6',
  subjects: 'M4 5h7v14H4z M13 5h7v14h-7z M6.5 9h2 M15.5 9h2',
  teaching: 'M3 4h18v12H3z M8 20h8 M12 16v4 M7 9h6',
  menu: 'M3 6h18 M3 12h18 M3 18h18',
  close: 'M5 5l14 14 M19 5L5 19',
  fold: 'M11 17l-5-5 5-5 M18 17l-5-5 5-5',
  unfold: 'M6 17l5-5-5-5 M13 17l5-5-5-5',
  chevrons: 'M8 9l4-4 4 4 M8 15l4 4 4-4',
  down: 'M6 9l6 6 6-6',
  right: 'M9 6l6 6-6 6',
} as const

export type IconName = keyof typeof PATHS

export function Icon({ name, size = 20, stroke = 2.4 }: { name: IconName; size?: number; stroke?: number }) {
  return (
    <svg aria-hidden="true" width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={stroke} strokeLinecap="square" strokeLinejoin="miter" className="shrink-0">
      <path d={PATHS[name]} />
    </svg>
  )
}
