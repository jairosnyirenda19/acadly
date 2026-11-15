import { api, navigate, type paths } from '@acadly/kernel'
import { Button, Notice, slips } from '@acadly/ui'
import { useRef, useState } from 'react'
import { copy } from '../copy'
import { errorFor, settle } from '../setup/api'
import { Total, TotalsBand } from '../setup/parts'
import { PageHead, SettingsTabs, Tag } from './parts'

const t = copy.settings.importing
const s = copy.settings

type Checked = paths['/v1/people/import/check']['post']['responses'][200]['content']['application/json']
type Imported = paths['/v1/people/import']['post']['responses'][200]['content']['application/json']
type Show = 'all' | 'problems' | 'wrong'

const ROWS = 'grid-cols-[64px_minmax(170px,1.2fr)_minmax(200px,1.3fr)_minmax(220px,1.5fr)_minmax(220px,1.6fr)]'

function upload<T>(path: '/v1/people/import/check' | '/v1/people/import', file: File, send?: boolean) {
  const form = new FormData()
  form.append('file', file)
  if (send !== undefined) form.append('send', send ? '1' : '0')

  return settle(
    api.POST(path, { body: form as never, bodySerializer: (body: unknown) => body as FormData }) as Promise<{ data?: T; error?: unknown; response: Response }>,
  )
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

export function PeopleImportScreen() {
  const [file, setFile] = useState<File | null>(null)
  const [checked, setChecked] = useState<Checked | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [working, setWorking] = useState(false)
  const [send, setSend] = useState(true)
  const [show, setShow] = useState<Show>('problems')
  const [over, setOver] = useState(false)
  const input = useRef<HTMLInputElement>(null)

  const fail = (r: { kind: string; errors?: Record<string, string[]> }) =>
    setError(r.kind === 'invalid' ? (errorFor(r.errors ?? {}, 'file') ?? s.failed) : r.kind === 'refused' ? s.refused : s.failed)

  const check = async () => {
    if (file === null) return
    setWorking(true)
    setError(null)
    const r = await upload<Checked>('/v1/people/import/check', file)
    setWorking(false)
    if (r.kind === 'saved') setChecked(r.data)
    else fail(r)
  }

  const run = async () => {
    if (file === null) return
    setWorking(true)
    setError(null)
    const r = await upload<Imported>('/v1/people/import', file, send)
    setWorking(false)
    if (r.kind === 'saved') {
      slips.done({ word: copy.slips.imported, title: t.done(r.data.invited, r.data.roles) })
      navigate('/settings/people')
    }
    else fail(r)
  }

  const template = async () => {
    try {
      const { data, response } = await api.GET('/v1/people/import/template', { parseAs: 'blob' })

      if (!response.ok || !(data instanceof Blob)) throw new Error('template')
      const url = URL.createObjectURL(data)
      const link = document.createElement('a')
      link.href = url
      link.download = 'people-to-invite.xlsx'
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
      <SettingsTabs current="people" />
      <PageHead kicker={t.kicker} title={checked === null ? t.title : t.checkTitle} intro={checked === null ? t.intro : t.checkIntro} />
      <Steps current={checked === null ? 0 : 1} />
    </>
  )

  if (checked === null) {
    return (
      <>
        {head}
        <div className="flex flex-1 flex-wrap items-stretch">
          <section className="min-w-0 flex-[3_1_600px] bg-sheet px-[clamp(16px,3vw,40px)] pt-6 pb-8">
            <h2 className="type-display mb-3.5 text-[26px] leading-none">{t.fileTitle}</h2>
            {error !== null && (
              <div className="mb-3">
                <Notice tone="wrong" title={error} />
              </div>
            )}
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
              className={`flex min-h-[220px] cursor-pointer flex-col items-center justify-center gap-2.5 border-2 border-dashed border-ink p-6 text-center ${over ? 'bg-teal-wash' : 'bg-paper'}`}
            >
              <span className="type-display text-[34px] leading-[0.9]">{t.drop}</span>
              <span className="text-sm text-text-muted">{t.dropNote}</span>
              <span className="inline-flex h-11 items-center border-2 border-ink px-[18px] text-[15px] font-extrabold">{t.choose}</span>
              {file !== null && <span className="text-sm font-bold">{t.chosen(file.name)}</span>}
              <input ref={input} type="file" accept=".xlsx,.csv" className="sr-only" onChange={(e) => choose(e.target.files?.[0])} />
            </label>
            <p className="mt-3.5 text-[13px] text-text-muted">{t.nothingSaved}</p>

            <h2 className="type-display mt-7 mb-2.5 text-[26px] leading-none">{t.columnsTitle}</h2>
            <div className="overflow-x-auto">
              <div className="min-w-[600px] border-2 border-ink">
                <div className="grid grid-cols-4 border-b-2 border-ink bg-field">
                  {t.columns.map((c, i) => (
                    <div key={c} className={`label-caps px-2.5 py-2 text-text-muted ${i > 0 ? 'border-l border-rule' : ''}`}>
                      {c}
                    </div>
                  ))}
                </div>
                <div className="grid grid-cols-4">
                  {t.example.map((c, i) => (
                    <div key={c} className={`px-2.5 py-2 text-sm ${i > 0 ? 'border-l border-rule' : ''}`}>
                      {c}
                    </div>
                  ))}
                </div>
              </div>
            </div>
            <ul className="mt-3 list-disc pl-4.5 text-sm leading-relaxed">
              <li>{t.ruleRole}</li>
              <li>{t.ruleWhere}</li>
              <li>{t.ruleTwice}</li>
            </ul>
          </section>

          <section className="min-w-0 flex-[2_1_360px] border-l-2 border-ink px-[clamp(16px,3vw,40px)] pt-6 pb-8">
            <h2 className="type-display mb-2.5 text-[26px] leading-none">{t.templateTitle}</h2>
            <p className="mb-3.5 text-sm leading-normal">{t.templateText}</p>
            <Button onClick={() => void template()}>{t.template}</Button>
            <div className="my-6.5 h-0.5 bg-ink" />
            <div className="label-caps mb-2 text-text-muted">{t.canTitle}</div>
            <p className="text-sm leading-normal">{t.canText}</p>
          </section>
        </div>
        <footer className="flex flex-wrap items-center gap-3 border-t-2 border-ink bg-sheet px-[clamp(16px,3vw,40px)] py-4">
          <Button variant="secondary" onClick={() => navigate('/settings/people')}>
            {t.backToPeople}
          </Button>
          <span className="flex-[1_1_240px] text-sm text-text-muted">{file === null ? t.chooseFirst : t.chosen(file.name)}</span>
          <Button disabled={file === null || working} onClick={() => void check()}>
            {working ? t.checking : t.check}
          </Button>
        </footer>
      </>
    )
  }

  const order = { wrong: 0, existing: 1, second: 2, ready: 3 }
  const rows = (show === 'wrong' ? checked.rows.filter((r) => r.state === 'wrong') : [...checked.rows]).sort((a, b) =>
    show === 'problems' ? order[a.state] - order[b.state] || a.row - b.row : a.row - b.row,
  )
  const second = checked.rows.filter((r) => r.state === 'second').length
  const importable = checked.totals.ready + checked.totals.existing

  return (
    <>
      {head}
      <TotalsBand>
        <Total tone="ink" label={t.bandRows} value={String(checked.totals.rows)} note={t.bandRowsNote(file?.name ?? '')} />
        <Total label={t.bandReady} value={String(checked.totals.ready)} note={t.bandReadyNote(checked.totals.new_people, second)} />
        <Total label={t.bandExisting} value={String(checked.totals.existing)} note={t.bandExistingNote} />
        <Total tone={checked.totals.wrong > 0 ? 'attention' : 'plain'} label={t.bandWrong} value={String(checked.totals.wrong)} note={t.bandWrongNote(checked.totals.wrong)} />
      </TotalsBand>

      <section className="flex-1 bg-sheet px-[clamp(16px,3vw,40px)] pt-5 pb-8">
        {error !== null && (
          <div className="mb-3">
            <Notice tone="wrong" title={error} />
          </div>
        )}
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
          <div className="min-w-[900px]">
            <div className={`label-caps grid ${ROWS} gap-3 border-b-2 border-ink pr-4 pb-2 text-text-muted`}>
              <span />
              <span>{t.person}</span>
              <span>{t.email}</span>
              <span>{t.roleWhere}</span>
              <span>{t.result}</span>
            </div>
            <div className="ledger [--margin:64px]">
              {rows.map((r) => (
                <div key={r.row} className={`grid ${ROWS} items-center gap-3 border-b border-rule py-2.5 pr-4 ${r.state === 'wrong' ? 'bg-red-wash' : ''}`}>
                  <div className={`ref text-center text-[13px] ${r.state === 'wrong' ? 'text-red-pen' : 'text-text-muted'}`}>{t.row(r.row)}</div>
                  <div className="text-[15px] font-extrabold">{r.name}</div>
                  <div className="ref text-xs break-all text-text-muted">{r.email}</div>
                  <div className="text-sm font-bold">
                    {r.role} · {r.where}
                  </div>
                  <div>
                    <Tag tone={r.state === 'wrong' ? 'wrong' : r.state === 'existing' ? 'plain' : 'done'}>{t.states[r.state]}</Tag>
                    <div className={`mt-1 text-[13px] ${r.state === 'wrong' ? 'font-bold text-red-text' : 'text-text-muted'}`}>{r.note}</div>
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
        {checked.totals.new_people > 0 && (
          <label className="inline-flex items-center gap-2 text-sm font-bold">
            <input type="checkbox" checked={send} onChange={(e) => setSend(e.target.checked)} className="size-[18px] accent-teal" />
            {t.sendNow}
          </label>
        )}
        <span className="flex-[1_1_240px] text-sm text-text-muted">{importable === 0 ? t.nothing : t.leftOut(checked.totals.wrong)}</span>
        <Button busy={working} disabled={importable === 0} onClick={() => void run()}>
          {working ? t.inviting : checked.totals.new_people > 0 ? t.invite(checked.totals.new_people) : t.giveOnly(importable)}
        </Button>
      </footer>
    </>
  )
}
