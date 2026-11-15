import { readCookie } from '@acadly/api-client'
import { describe, expect, it } from 'vitest'
import { signInFailure } from './session'

describe('sign-in outcomes', () => {
  it('names each failure', () => {
    expect(signInFailure(422)).toEqual({ kind: 'incorrect' })
    expect(signInFailure(429)).toEqual({ kind: 'paused' })
    expect(signInFailure(500)).toEqual({ kind: 'unavailable' })
    expect(signInFailure(419)).toEqual({ kind: 'unavailable' })
  })
})

describe('the CSRF cookie', () => {
  it('reads and decodes one cookie', () => {
    const jar = 'acadly_session=abc; XSRF-TOKEN=eyJpdiI6%3D%3D; other=1'

    expect(readCookie(jar, 'XSRF-TOKEN')).toBe('eyJpdiI6==')
    expect(readCookie(jar, 'missing')).toBeNull()
  })
})
