import { describe, expect, it } from 'vitest'
import { money, parseKwacha, splitPayment } from './api'

describe('fees money', () => {
  it('shows tambala as kwacha, and reads typed kwacha back', () => {
    expect(money(15000000)).toBe('MK 150,000')
    expect(money(15000050)).toBe('MK 150,000.50')
    expect(money(-2500000)).toBe('−MK 25,000')
    expect(parseKwacha('150,000')).toBe(15000000)
    expect(parseKwacha('150000.5')).toBe(15000050)
    expect(parseKwacha('12a')).toBeNull()
  })

  it('splits one payment across children: due now first, then the rest, then credit to the first', () => {
    const kids = [
      { id: 'a', due_now: 1900000, left: 5500000 },
      { id: 'b', due_now: 0, left: 6000000 },
    ]
    expect(splitPayment(15000000, kids)).toEqual({ a: 9000000, b: 6000000 })
  })
})
