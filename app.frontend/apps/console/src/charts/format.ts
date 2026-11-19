/** How the dashboards write their numbers (D-100). Money arrives in minor units (tambala). */
export type Unit = 'count' | 'percent' | 'money'

const whole = new Intl.NumberFormat('en-GB', { maximumFractionDigits: 0 })
const one = new Intl.NumberFormat('en-GB', { maximumFractionDigits: 1 })

/** A short amount: MK 220.4m, MK 45,000. */
export function money(minor: number): string {
  const kwacha = Math.trunc(minor / 100)
  const sign = kwacha < 0 ? '−' : ''
  const abs = Math.abs(kwacha)

  return abs >= 1_000_000 ? `${sign}MK ${one.format(abs / 1_000_000)}m` : `${sign}MK ${whole.format(abs)}`
}

/** A number in its unit, for a total, an axis or a tooltip. */
export function figure(value: number | null, unit: Unit | string): string {
  if (value === null) return '–'
  if (unit === 'money') return money(value)
  if (unit === 'percent') return `${one.format(value)}%`

  return one.format(value)
}

/** The axis's short form: no decimals on percentages, millions on money. */
export function tick(value: number, unit: Unit | string): string {
  if (unit === 'money') {
    const kwacha = value / 100

    return kwacha >= 1_000_000 ? `${one.format(kwacha / 1_000_000)}m` : kwacha >= 1000 ? `${whole.format(kwacha / 1000)}k` : whole.format(kwacha)
  }

  return unit === 'percent' ? `${whole.format(value)}%` : whole.format(value)
}
