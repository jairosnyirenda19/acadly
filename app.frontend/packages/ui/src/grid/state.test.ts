import { describe, expect, it } from 'vitest'
import { differsFromView, EMPTY_QUERY, gridApiQuery, pageItems, queryFromView, readGridQuery, rowRange, viewState, writeGridQuery, type GridQuery } from './state'

const read = (search: string) => readGridQuery(new URLSearchParams(search))

describe('grid address', () => {
  it('reads an empty address as the list on its first page', () => {
    expect(read('')).toEqual(EMPTY_QUERY)
  })

  it('reads every part of the address', () => {
    const q = read('?view=v1&q=banda&sort=admitted_on&dir=desc&filter[class]=a,b&filter[admitted]=2025-09-01..&page=3&per_page=50')

    expect(q).toEqual({
      page: 3,
      perPage: 50,
      sort: 'admitted_on',
      dir: 'desc',
      q: 'banda',
      filters: { class: ['a', 'b'], admitted: ['2025-09-01', ''] },
      view: 'v1',
    })
  })

  it('reads encoded brackets and commas the same', () => {
    expect(read('filter%5Bclass%5D=a%2Cb').filters).toEqual({ class: ['a', 'b'] })
  })

  it('falls back on values it does not know', () => {
    const q = read('page=-2&per_page=33&dir=sideways&filter[class]=&filter[Bad]=x')

    expect(q.page).toBe(1)
    expect(q.perPage).toBe(25)
    expect(q.dir).toBe('asc')
    expect(q.filters).toEqual({})
  })

  it('writes defaults out and keeps a stable order', () => {
    expect(writeGridQuery(EMPTY_QUERY)).toBe('')
    const q: GridQuery = { page: 2, perPage: 10, sort: 'learner', dir: 'asc', q: 'a b', filters: { sex: ['female'], class: ['x', 'y'] }, view: null }

    expect(writeGridQuery(q)).toBe('q=a+b&sort=learner&dir=asc&filter[class]=x,y&filter[sex]=female&page=2&per_page=10')
  })

  it('reads back what it writes', () => {
    const q: GridQuery = { page: 4, perPage: 100, sort: 'class', dir: 'desc', q: 'Mw, 2026', filters: { admitted: ['', '2026-01-31'], class: ['a'] }, view: 'v9' }

    expect(read(writeGridQuery(q))).toEqual(q)
  })

  it('gives the server its query, with date ranges as from..to', () => {
    const q: GridQuery = { ...EMPTY_QUERY, sort: 'learner', filters: { class: ['a', 'b'], admitted: ['2025-09-01', ''] } }

    expect(gridApiQuery(q)).toEqual({ page: 1, per_page: 25, sort: 'learner', dir: 'asc', filter: { class: 'a,b', admitted: '2025-09-01..' } })
    expect(gridApiQuery(EMPTY_QUERY)).toEqual({ page: 1, per_page: 25 })
  })
})

describe('saved views', () => {
  const sort = { sort: 'learner', dir: 'asc' as const }

  it('opens a view on its first page', () => {
    const q = queryFromView('v1', { sort: 'class', dir: 'desc', q: '', filters: { class: ['a'] }, per_page: 50, columns: ['learner'] })

    expect(q).toEqual({ page: 1, perPage: 50, sort: 'class', dir: 'desc', q: '', filters: { class: ['a'] }, view: 'v1' })
  })

  it('reads a damaged view without failing', () => {
    expect(queryFromView('v', { sort: 4, filters: 'x', per_page: 'many' })).toEqual({ ...EMPTY_QUERY, view: 'v' })
  })

  it('says when the list differs from the view', () => {
    const state = { sort: 'learner', dir: 'asc', q: '', filters: { class: ['b', 'a'] }, per_page: 25, columns: ['learner', 'class'] }
    const same: GridQuery = { ...EMPTY_QUERY, filters: { class: ['a', 'b'] }, view: 'v' }

    expect(differsFromView(same, sort, ['class', 'learner'], state)).toBe(false)
    expect(differsFromView({ ...same, q: 'x' }, sort, ['class', 'learner'], state)).toBe(true)
    expect(differsFromView(same, sort, ['learner'], state)).toBe(true)
    expect(differsFromView({ ...same, page: 5 }, sort, ['class', 'learner'], state)).toBe(false)
  })

  it('keeps the list order the server used when the address names none', () => {
    expect(viewState(EMPTY_QUERY, { sort: 'learner', dir: 'desc' }, ['learner'])).toEqual({ sort: 'learner', dir: 'desc', q: '', filters: {}, per_page: 25, columns: ['learner'] })
  })
})

describe('pager', () => {
  it('numbers a short list in full', () => {
    expect(pageItems(1, 1)).toEqual([1])
    expect(pageItems(2, 4)).toEqual([1, 2, 3, 4])
  })

  it('stands a gap for the pages between', () => {
    expect(pageItems(6, 124)).toEqual([1, 2, 'gap', 5, 6, 7, 'gap', 124])
    expect(pageItems(1, 19)).toEqual([1, 2, 'gap', 19])
    expect(pageItems(19, 19)).toEqual([1, 2, 'gap', 18, 19])
  })

  it('fills a gap of one page with the page', () => {
    expect(pageItems(5, 19)).toEqual([1, 2, 3, 4, 5, 6, 'gap', 19])
  })

  it('counts the rows shown', () => {
    expect(rowRange(6, 10, 186, 10)).toEqual({ from: 51, to: 60 })
    expect(rowRange(19, 10, 186, 6)).toEqual({ from: 181, to: 186 })
    expect(rowRange(1, 25, 0, 0)).toEqual({ from: 0, to: 0 })
  })
})
