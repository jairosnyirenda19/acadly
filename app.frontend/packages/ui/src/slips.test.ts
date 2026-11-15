import { describe, expect, it } from 'vitest'
import { createSlips } from './slips'

describe('slips', () => {
  it('lets done and attention slips leave after their time, and keeps a not saved slip', () => {
    const pad = createSlips()
    pad.done({ word: 'Saved', title: 'Role ended.' })
    pad.attention({ word: 'Saved on this phone', title: 'Register kept on this phone.' })
    pad.wrong({ word: 'Not saved', title: 'The marks could not be saved.' })

    pad.advance(5000)
    expect(pad.list().map((s) => s.kind)).toEqual(['attention', 'wrong'])

    pad.advance(3000)
    expect(pad.list().map((s) => s.kind)).toEqual(['wrong'])

    pad.advance(60_000)
    expect(pad.list()).toHaveLength(1)
  })

  it('counts the same message twice in a row and starts its time again', () => {
    const pad = createSlips()
    pad.done({ word: 'Saved', title: 'Subject saved.' })
    pad.advance(4000)
    pad.done({ word: 'Saved', title: 'Subject saved.' })

    expect(pad.list()).toHaveLength(1)
    expect(pad.list()[0]?.count).toBe(2)
    expect(pad.list()[0]?.run).toBe(0)

    pad.done({ word: 'Saved', title: 'Another thing saved.' })
    expect(pad.list()).toHaveLength(2)
  })

  it('keeps a working slip until it is finished, then lets it run as a done slip in place', () => {
    const pad = createSlips()
    const sending = pad.working({ word: 'Sending', title: 'Sending 4 invitations.' })
    pad.advance(60_000)
    expect(pad.list()[0]?.kind).toBe('working')

    sending.update({ detail: '2 of 4 sent.' })
    expect(pad.list()[0]?.detail).toBe('2 of 4 sent.')

    sending.finish('done', { word: 'Sent', title: '4 invitations sent.' })
    expect(pad.list()[0]).toMatchObject({ id: sending.id, kind: 'done', title: '4 invitations sent.' })

    pad.advance(5000)
    expect(pad.list()).toHaveLength(0)
  })

  it('closes a slip on request and tells listeners', () => {
    const pad = createSlips()
    let heard = 0
    pad.subscribe(() => heard++)
    const id = pad.wrong({ word: 'Not saved', title: 'Unavailable.' })
    pad.close(id)

    expect(pad.list()).toHaveLength(0)
    expect(heard).toBe(2)
  })
})
