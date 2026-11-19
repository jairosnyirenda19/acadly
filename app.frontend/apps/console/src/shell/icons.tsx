/**
 * The console's line icons: one stroke, square ends, no rounding (DESIGN.md).
 * Drawn here, not taken from a set, so every icon shares the register book's hand.
 */
const PATHS = {
  today: 'M4 5h16v15H4z M4 10h16 M9 3v4 M15 3v4 M8 14h3v3H8z',
  myDay: 'M4 4h16v16H4z M12 8v4l3 3 M4 9h3',
  timetable: 'M3 4h18v17H3z M3 9h18 M8 2v4 M16 2v4 M9 9v12 M15 9v12 M3 15h18',
  registers: 'M4 4h16v16H4z M4 10h16 M4 15h16 M10 4v16',
  marks: 'M4 20h16 M7 20V11 M12 20V6 M17 20v-6',
  assessment: 'M4 6h9 M4 12h16 M4 18h6 M17 3v6 M14 15v6',
  learners: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z M4 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1',
  people: 'M9 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6z M3 20v-1a5 5 0 0 1 5-5h2a5 5 0 0 1 5 5v1 M16 5a3 3 0 0 1 0 6 M18 14a5 5 0 0 1 3 5v1',
  roles: 'M4 4h16v16H4z M8 12l3 3 5-6',
  subjects: 'M4 5h7v14H4z M13 5h7v14h-7z M6.5 9h2 M15.5 9h2',
  teaching: 'M3 4h18v12H3z M8 20h8 M12 16v4 M7 9h6',
  messages: 'M4 5h16v11H9l-5 4z M8 9h8 M8 12h5',
  modules: 'M4 4h7v7H4z M13 4h7v7h-7z M4 13h7v7H4z M16.5 13v7 M13 16.5h7',
  menu: 'M3 6h18 M3 12h18 M3 18h18',
  close: 'M5 5l14 14 M19 5L5 19',
  fold: 'M11 17l-5-5 5-5 M18 17l-5-5 5-5',
  unfold: 'M6 17l5-5-5-5 M13 17l5-5-5-5',
  chevrons: 'M8 9l4-4 4 4 M8 15l4 4 4-4',
  down: 'M6 9l6 6 6-6',
  right: 'M9 6l6 6-6 6',
  switch: 'M4 8h13 M14 4l4 4-4 4 M20 16H7 M10 12l-4 4 4 4',
  approvals: 'M4 4h16v16H4z M8 12l3 3 5-6 M4 9h3',
  search: 'M10.5 17a6.5 6.5 0 1 0 0-13 6.5 6.5 0 0 0 0 13z M15.5 15.5L21 21',
  fees: 'M3 6h18v13H3z M3 10h18 M7 15h4',
  conduct: 'M12 3l7 3v6c0 4-3 7-7 9-4-2-7-5-7-9V6z M9 12l2 2 4-4',
  support: 'M12 20s-7-4.5-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.5-7 10-7 10z',
  pay: 'M12 4v16 M4 12h16',
  shop: 'M4 8h16l-1 12H5z M9 8V6a3 3 0 0 1 6 0v2',
  room: 'M4 20V9l8-5 8 5v11z M10 20v-6h4v6',
  observations: 'M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
  reviews: 'M5 4h14v16H5z M8 9h8 M8 13h5',
  pickup: 'M4 20V4h9l7 7v9z M13 4v7h7',
  look: 'M4 4h16v16H4z M4 14l5-5 4 4 3-3 4 4 M15 8h.01',
  tiles: 'M3 3h8v8H3z M13 3h8v5h-8z M13 10h8v11h-8z M3 13h8v8H3z',
} as const

export type IconName = keyof typeof PATHS

export function Icon({ name, size = 20, stroke = 2.4 }: { name: IconName; size?: number; stroke?: number }) {
  return (
    <svg aria-hidden="true" width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={stroke} strokeLinecap="square" strokeLinejoin="miter" className="shrink-0">
      <path d={PATHS[name]} />
    </svg>
  )
}
