/*
 * The school's own look and each person's appearance (D-096). The colours
 * arrive as --brand-* on the page's root; look.css turns them into the
 * tokens. Light or dark is the person's choice, remembered on this device.
 */

export interface Look {
  app_name: string | null
  main: string
  accent: string
  accent_last: boolean
}

export type Appearance = 'light' | 'dark' | 'device'

const KEY = 'acadly.appearance'

/** A colour made darker (amount below 0) or lighter (above 0), by a share of the way to black or white. */
export function shade(hex: string, amount: number): string {
  const out = [1, 3, 5].map((i) => {
    const c = parseInt(hex.slice(i, i + 2), 16)
    const v = amount < 0 ? c * (1 + amount) : c + (255 - c) * amount

    return Math.round(Math.max(0, Math.min(255, v)))
      .toString(16)
      .padStart(2, '0')
  })

  return `#${out.join('')}`
}

/** Puts the school's colours on the page. The register book's own are the fallback. */
export function applyLook(look: Pick<Look, 'main' | 'accent'>): void {
  const root = document.documentElement.style
  root.setProperty('--brand-main', look.main)
  root.setProperty('--brand-accent', look.accent)
  root.setProperty('--brand-rule', shade(look.main, 0.2))
  root.setProperty('--brand-block-dark', shade(look.main, -0.6))
  root.setProperty('--brand-rule-dark', shade(look.main, -0.15))
}

export function savedAppearance(): Appearance {
  try {
    const v = window.localStorage.getItem(KEY)

    return v === 'light' || v === 'dark' ? v : 'device'
  } catch {
    return 'device'
  }
}

let unwatch: (() => void) | null = null

/** Sets light or dark on the page, and follows the device while the person leaves it to the device. */
export function applyAppearance(choice: Appearance = savedAppearance()): void {
  unwatch?.()
  unwatch = null
  const media = typeof window.matchMedia === 'function' ? window.matchMedia('(prefers-color-scheme: dark)') : null
  const set = () => document.documentElement.setAttribute('data-theme', choice === 'device' ? (media?.matches === true ? 'dark' : 'light') : choice)
  set()

  if (choice === 'device' && media !== null) {
    media.addEventListener('change', set)
    unwatch = () => media.removeEventListener('change', set)
  }
}

export function keepAppearance(choice: Appearance): void {
  try {
    window.localStorage.setItem(KEY, choice)
  } catch {
    // A private window keeps nothing: the choice holds for this visit only.
  }
  applyAppearance(choice)
}

/**
 * The size of the school's name in the rail, by its length: one line at
 * full size, then smaller, then two lines, broken between words.
 */
export function nameSize(name: string): { px: number; lines: 1 | 2 } {
  const n = name.length
  if (n <= 14) return { px: 32, lines: 1 }
  if (n <= 20) return { px: 27, lines: 1 }
  if (n <= 26) return { px: 24, lines: 1 }

  return { px: 21, lines: 2 }
}

/** How readable one colour is on another: the WCAG contrast ratio of two #RRGGBB colours. */
export function contrast(a: string, b: string): number {
  const lum = (hex: string) =>
    [1, 3, 5]
      .map((i) => parseInt(hex.slice(i, i + 2), 16) / 255)
      .map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4))
      .reduce((sum, c, i) => sum + c * ([0.2126, 0.7152, 0.0722][i] ?? 0), 0)
  const [x, y] = [lum(a), lum(b)]

  return Math.round(((Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05)) * 100) / 100
}

export const isColour = (hex: string): boolean => /^#[0-9a-fA-F]{6}$/.test(hex)
