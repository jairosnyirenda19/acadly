import { describe, expect, it } from 'vitest'
import { readCard } from './api'

describe('readCard', () => {
  it('reads a card as it is published', () => {
    const card = readCard({
      period: { id: 'p', name: 'Term 1' },
      learner: { id: 'l', name: 'Madalitso Kalua' },
      position: { of: 12, shared: false, position: 8 },
      lines: [{ label: 'Attendance', value: '58 of 62 days present' }],
      subjects: [
        { subject: 'English', percent: 84, grade: 'A', word: 'Distinction', ready: true },
        { subject: 'Biology', percent: null, grade: null, word: null, ready: false },
      ],
      comments: { head: null, class_teacher: { at: '2026-11-17', by: 'Grace Phiri', text: 'Works steadily.' } },
    })

    expect(card.period?.name).toBe('Term 1')
    expect(card.position).toEqual({ position: 8, of: 12, shared: false })
    expect(card.lines).toEqual([{ label: 'Attendance', value: '58 of 62 days present' }])
    expect(card.subjects.map((s) => [s.subject, s.ready])).toEqual([
      ['English', true],
      ['Biology', false],
    ])
    expect(card.comments).toEqual({ class_teacher: { text: 'Works steadily.', by: 'Grace Phiri' }, head: null })
  })

  it('reads an older card that lacks parts without failing', () => {
    const card = readCard({ subjects: [{ subject: 'Mathematics' }, 'not a subject'], position: { position: null }, comments: { class_teacher: { text: '  ' } } })

    expect(card.period).toBeNull()
    expect(card.position).toBeNull()
    expect(card.lines).toEqual([])
    expect(card.subjects).toEqual([{ subject: 'Mathematics', percent: null, grade: null, word: null, ready: true }])
    expect(card.comments.class_teacher).toBeNull()
  })
})
