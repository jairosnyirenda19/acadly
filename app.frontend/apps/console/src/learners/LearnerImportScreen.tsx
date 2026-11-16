import { api, navigate, useLoad, type paths } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { errorFor, settle } from '../setup/api'
import { Total, TotalsBand } from '../setup/parts'
import { day } from '../settings/api'
import { PageHead, Tag } from '../settings/parts'
import { useWorkIn } from '../shell/WorkIn'
import { learnersApi } from './api'

const t = copy.learners.importing
const s = copy.settings

type Checked = paths['/v1/learners/import/check']['post']['responses'][200]['content']['application/json']
type Imported = paths['/v1/learners/import']['post']['responses'][200]['content']['application/json']
type Show = 'all' | 'problems' | 'wrong'

const ROWS = 'grid-cols-[64px_minmax(190px,1.3fr)_minmax(150px,0.9fr)_minmax(230px,1.5fr)_minmax(250px,1.7fr)]'

function upload<T>(path: '/v1/learners/import/check' | '/v1/learners/import', file: File, confirmed: number[] = []) {
  const form = new FormData()
  form.append('file', file)
  for (const row of confirmed) form.append('confirmed[]', String(row))

  return settle(api.POST(path, { body: form as never, bodySerializer: (body: unknown) => body as FormData }) as Promise<{ data?: T; error?: unknown; response: Response }>)
}

function Steps({ current }: { current: number }) {
  return (
    <ol aria-label={t.stepsLabel} className="flex flex-wrap border-y-2 border-ink bg-sheet">
      {t.steps.map((name, i) => {
        const state = i < current ? 'done' : i === current ? 'current' : 'todo'

        return (
          <li
            key={name}
            aria-current={state === 'current' ? 'step' : undefined}
            className={`flex items-center gap-2.5 px-4.5 py-3 text-[15px] ${i > 0 ? 'border-l-2 border-ink' : ''} ${state === 'current' ? 'font-extrabold' : 'font-semibold'} ${state === 'todo' ? 'text-text-muted' : ''}`}
          >
            {state === 'done' ? (
              <span aria-hidden="true" className="grid size-[22px] place-items-center bg-teal font-extrabold text-sheet">
                /
              </span>
            ) : state === 'current' ? (
              <span aria-hidden="true" className="size-[22px] border-[3px] border-ink bg-teal-bright" />
            ) : (
              <span aria-hidden="true" className="size-[22px] border-2 border-dashed border-rule-dashed" />
            )}
            <span className="ref text-xs text-text-muted">{String(i + 1).padStart(2, '0')}</span>
            {name}
          </li>
        )
      })}
    </ol>
  )
}

/** Admitting learners from a spreadsheet (the approved design): choose the file, check every row, then admit. */
export function LearnerImportScreen() {
  const { place } = useWorkIn()
  const inPlace = place !== null && place.key !== 'institution:' ? place.key : null
  const loaded = useLoad(`learners:${inPlace ?? ''}`, () => learnersApi.list(inPlace))
  const [file, setFile] = useState<File | null>(null)
  const [checked, setChecked] = useState<Checked | null>(null)
  const [confirmed, setConfirmed] = useState<Set<number>>(new Set())
  const [error, setError] = useState<string | null>(null)
  const [working, setWorking] = useState(false)
  const [show, setShow] = useState<Show>('problems')
  const [over, setOver] = useState(false)

  if (loaded.data === undefined) {
    return <Waiting phase={loaded.phase} what={copy.learners.title} labels={copy.loading} rows={6} onRetry={loaded.reload} />
  }

  const word = loaded.data.word.toLowerCase()
  const plural = loaded.data.word_plural
  const lower = plural.toLowerCase()

  const fail = (r: { kind: string; errors?: Record<string, string[]> }) =>
    setError(r.kind === 'invalid' ? (errorFor(r.errors ?? {}, 'file') ?? s.failed) : r.kind === 'refused' ? s.refused : s.failed)

  const check = async () => {
    if (file === null) return
    setWorking(true)
    setError(null)
    const r = await upload<Checked>('/v1/learners/import/check', file)
    setWorking(false)
    if (r.kind === 'saved') {
      setChecked(r.data)
      setConfirmed(new Set())
    } else fail(r)
  }

  const run = async () => {
    if (file === null) return
    setWorking(true)
    setError(null)
    const r = await upload<Imported>('/v1/learners/import', file, [...confirmed])
    setWorking(false)
    if (r.kind === 'saved') {
      loaded.reload()
      slips.done({ word: copy.slips.imported, title: t.done(r.data.admitted) })
      navigate('/learners')
    } else fail(r)
  }

  const template = async () => {
    try {
      const { data, response } = await api.GET('/v1/learners/import/template', { parseAs: 'blob' })

      if (!response.ok || !(data instanceof Blob)) throw new Error('template')
      const url = URL.createObjectURL(data)
      const link = document.createElement('a')
      link.href = url
      link.download = 'learners-to-admit.xlsx'
      link.click()
      URL.revokeObjectURL(url)
    } catch {
      setError(t.templateFailed)
    }
  }

  const choose = (chosen: File | undefined) => {
    if (chosen === undefined) return
    setFile(chosen)
    setChecked(null)
    setError(null)
  }

  const head = (
    <>
      <PageHead kicker={t.kicker(plural)} title={checked === null ? t.title(lower) : t.checkTitle} intro={checked === null ? t.intro(lower) : t.checkIntro} />
      <Steps current={checked === null ? 0 : 1} />
    </>
  )
  const failed = error !== null && (
    <div className="mb-3">
      <Notice tone="wrong" title={error} />
    </div>
  )

  if (checked === null) {
    return (
      <>
        {head}
        <div className="flex flex-1 flex-wrap items-stretch">
          <section className="min-w-0 flex-[3_1_600px] bg-sheet px-[clamp(16px,3vw,40px)] pt-6 pb-8">
            <h2 className="type-display mb-3.5 text-[26px] leading-none">{t.fileTitle}</h2>
            {failed}
            <label
              onDragOver={(e) => {
                e.preventDefault()
                setOver(true)
              }}
              onDragLeave={() => setOver(false)}
              onDrop={(e) => {
                e.preventDefault()
                setOver(false)
                choose(e.dataTransfer.files[0])
              }}
              className={`flex min-h-[180px] cursor-pointer flex-col items-center justify-center gap-2.5 border-2 border-dashed border-ink p-6 text-center ${over ? 'bg-teal-wash' : 'bg-paper'}`}
            >
              <span className="type-display text-[34px] leading-[0.9]">{t.drop}</span>
              <span className="text-sm text-text-muted">{t.dropNote(lower)}</span>
              <span className="inline-flex h-11 items-center border-2 border-ink px-[18px] text-[15px] font-extrabold">{t.choose}</span>
              {file !== null && <span className="text-sm font-bold">{t.chosen(file.name)}</span>}
              <input type="file" accept=".xlsx,.csv" className="sr-only" onChange={(e) => choose(e.target.files?.[0])} />
            </label>
            <p className="mt-3 text-[13px] text-text-muted">{t.nothingSaved}</p>

            <h2 className="type-display mt-6 mb-2.5 text-[26px] leading-none">{t.columnsTitle}</h2>
            <div className="grid grid-cols-[repeat(auto-fit,minmax(240px,1fr))] items-start gap-2.5">
              {t.groups.map((group) => (
                <div key={group.title} className="min-w-0 border-2 border-ink bg-sheet">
                  <div className="label-caps border-b-2 border-ink bg-field px-2.5 py-2">{group.title}</div>
                  {group.columns.map(([column, example, required]) => (
                    <div key={column} className="grid grid-cols-2 gap-2 border-b border-rule px-2.5 py-1.5 text-[13px] last:border-b-0">
                      <span className="font-extrabold">
                        {column}
                        {!required && <span className="font-medium text-text-muted"> · {t.optional}</span>}
                      </span>
                      <span className="text-text-muted">{example}</span>
                    </div>
                  ))}
                </div>
              ))}
            </div>
            <ul className="mt-3 list-disc pl-4.5 text-sm leading-relaxed">
              <li>{t.ruleClass}</li>
              <li>{t.ruleRelationship}</li>
              <li>{t.ruleDates}</li>
              <li>{t.ruleNotices}</li>
            </ul>
          </section>

          <section className="min-w-0 flex-[2_1_340px] border-ink px-[clamp(16px,3vw,40px)] pt-6 pb-8 max-[979px]:border-t-2 min-[980px]:border-l-2">
            <h2 className="type-display mb-2.5 text-[26px] leading-none">{t.templateTitle}</h2>
            <p className="mb-3.5 text-sm leading-normal">{t.templateText}</p>
            <Button onClick={() => void template()}>{t.template}</Button>
            <div className="my-6 h-0.5 bg-ink" />
            <div className="label-caps mb-2 text-text-muted">{t.canTitle}</div>
            <p className="mb-3 text-sm leading-normal">{t.canText}</p>
            <div className="label-caps mb-2 text-text-muted">{t.photosTitle}</div>
            <p className="text-sm leading-normal">{t.photosText}</p>
          </section>
        </div>
        <footer className="flex flex-wrap items-center gap-3 border-t-2 border-ink bg-sheet px-[clamp(16px,3vw,40px)] py-4">
          <Button variant="secondary" onClick={() => navigate('/learners')}>
            {t.backTo(plural)}
          </Button>
          <span className="flex-[1_1_240px] text-sm text-text-muted">{file === null ? t.chooseFirst : t.chosen(file.name)}</span>
          <Button disabled={file === null || working} onClick={() => void check()}>
            {working ? t.checking : t.check}
          </Button>
        </footer>
      </>
    )
  }

  const order: Record<string, number> = { wrong: 0, double: 1, ready: 2 }
  const rows = (show === 'wrong' ? checked.rows.filter((r) => r.state === 'wrong') : [...checked.rows]).sort((a, b) =>
    show === 'problems' ? (order[a.state] ?? 3) - (order[b.state] ?? 3) || a.row - b.row : a.row - b.row,
  )
  const admitting = checked.totals.ready + confirmed.size
  const unconfirmed = checked.totals.double - confirmed.size

  return (
    <>
      {head}
      <TotalsBand>
        <Total tone="ink" label={t.bandRows} value={String(checked.totals.rows)} note={t.bandRowsNote(file?.name ?? '')} />
        <Total label={t.bandReady} value={String(checked.totals.ready)} note={t.bandReadyNote(checked.totals.classes)} />
        <Total tone={checked.totals.double > 0 ? 'attention' : 'plain'} label={t.bandDouble} value={String(checked.totals.double)} note={t.bandDoubleNote} />
        <Total tone={checked.totals.wrong > 0 ? 'attention' : 'plain'} label={t.bandWrong} value={String(checked.totals.wrong)} note={t.bandWrongNote(checked.totals.wrong)} />
      </TotalsBand>

      <section className="flex-1 bg-sheet px-[clamp(16px,3vw,40px)] pt-5 pb-8">
        {failed}
        <div className="mb-3.5 flex flex-wrap items-center gap-3">
          <div role="group" aria-label={t.showLabel} className="flex h-11 border-2 border-ink">
            {(['all', 'problems', 'wrong'] as const).map((key, i) => (
              <button
                key={key}
                type="button"
                aria-pressed={show === key}
                onClick={() => setShow(key)}
                className={`px-3.5 text-sm ${i > 0 ? 'border-l-2 border-ink' : ''} ${show === key ? 'bg-ink font-extrabold text-sheet' : 'font-bold hover:bg-field'}`}
              >
                {key === 'all' ? t.show.all(checked.totals.rows) : key === 'wrong' ? t.show.wrong(checked.totals.wrong) : t.show.problems}
              </button>
            ))}
          </div>
          <span className="flex-[1_1_260px] text-[13px] text-text-muted">{t.numbered}</span>
          <Button
            variant="secondary"
            onClick={() => {
              setChecked(null)
              setFile(null)
            }}
          >
            {t.another}
          </Button>
        </div>
        <div className="overflow-x-auto">
          <div className="min-w-[940px]">
            <div className={`label-caps grid ${ROWS} gap-3 border-b-2 border-ink pr-4 pb-2 text-text-muted`}>
              <span />
              <span>{loaded.data.word}</span>
              <span>{t.klass}</span>
              <span>{t.contacts}</span>
              <span>{t.result}</span>
            </div>
            <div className="ledger [--margin:64px]">
              {rows.map((r) => (
                <div
                  key={r.row}
                  className={`grid ${ROWS} items-center gap-3 border-b border-rule py-2.5 pr-4 ${r.state === 'wrong' ? 'bg-red-wash' : r.state === 'double' ? 'bg-marigold-wash' : ''}`}
                >
                  <div className={`ref text-center text-[13px] ${r.state === 'wrong' ? 'text-red-pen' : 'text-text-muted'}`}>{t.row(r.row)}</div>
                  <div className="min-w-0">
                    <div className="text-[15px] font-extrabold">{r.name}</div>
                    <div className="text-[13px] text-text-muted">{[/^\d{4}-\d{2}-\d{2}$/.test(r.born) ? day(r.born) : r.born, copy.learners.sexes[r.sex] ?? ''].filter(Boolean).join(' · ')}</div>
                  </div>
                  <div className="text-sm font-bold">{r.class}</div>
                  <div className="text-[13px]">{r.contacts.join(' · ')}</div>
                  <div>
                    <Tag tone={r.state === 'wrong' ? 'wrong' : r.state === 'double' ? 'attention' : 'done'}>{t.states[r.state]}</Tag>
                    <div className={`mt-1 text-[13px] ${r.state === 'wrong' ? 'font-bold text-red-text' : 'text-text-muted'}`}>{r.note}</div>
                    {r.state === 'double' && (
                      <label className="mt-1.5 flex items-center gap-2 text-[13px] font-bold">
                        <input
                          type="checkbox"
                          checked={confirmed.has(r.row)}
                          onChange={(e) =>
                            setConfirmed((current) => {
                              const next = new Set(current)
                              if (e.target.checked) next.add(r.row)
                              else next.delete(r.row)

                              return next
                            })
                          }
                          className="size-[18px] accent-teal"
                        />
                        {t.confirm(word)}
                      </label>
                    )}
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>
        <p className="mt-3.5 text-[13px] text-text-muted">{t.showing(rows.length, checked.totals.rows)}</p>
      </section>
      <footer className="flex flex-wrap items-center gap-3 border-t-2 border-ink bg-sheet px-[clamp(16px,3vw,40px)] py-4">
        <Button variant="secondary" onClick={() => setChecked(null)}>
          {t.back}
        </Button>
        <span className="flex-[1_1_240px] text-sm text-text-muted">{admitting === 0 ? t.nothing : t.leftOut(checked.totals.wrong, unconfirmed)}</span>
        <Button busy={working} disabled={admitting === 0} onClick={() => void run()}>
          {working ? t.admitting : t.admit(admitting, word, lower)}
        </Button>
      </footer>
    </>
  )
}
