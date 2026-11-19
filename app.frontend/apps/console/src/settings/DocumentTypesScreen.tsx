import { useLoad } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { AccessTag } from '../learners/documents/DocumentsTab'
import { documentsApi, type DocumentAccess, type TypesState } from '../learners/documents/api'
import { Input } from '../setup/parts'
import { PageHead, Tag, SettingsTabs } from './parts'

const t = copy.settings.documentTypes
const d = copy.learners.documents
const LEVELS: DocumentAccess[] = ['staff', 'office', 'confidential']

type TypeRow = TypesState['types'][number]

/** Settings › Learner document types (the approved design): the school's own list, and who may open each. */
export function DocumentTypesScreen() {
  const loaded = useLoad('settings:learner-document-types', () => documentsApi.types())
  const [editing, setEditing] = useState<TypeRow | 'new' | null>(null)
  const data = loaded.data

  if (data === undefined) return <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} rows={8} onRetry={loaded.reload} />

  return (
    <>
      <SettingsTabs />
      <PageHead kicker={t.kicker} title={t.title} intro={t.intro} />
      <div className={`grid items-start gap-x-8 gap-y-6 px-[clamp(16px,3vw,40px)] pb-8 ${editing !== null ? "xl:grid-cols-[minmax(0,1fr)_400px]" : "max-w-[1100px]"}`}>
        <section aria-label={t.title} className="@container border-2 border-ink bg-sheet px-4 pt-2 pb-4 md:px-5">
          <div className="label-caps hidden grid-cols-[minmax(0,1fr)_210px_170px_60px_80px] gap-3 border-b-2 border-ink py-2 text-text-muted @2xl:grid">
            <span>{t.cols.type}</span>
            <span>{t.cols.access}</span>
            <span>{t.cols.admission}</span>
            <span className="text-right">{t.cols.onFile}</span>
            <span />
          </div>
          <ul>
            {data.types.map((x) => (
              <li key={x.id} className={`grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1.5 border-b border-rule py-3 @2xl:grid-cols-[minmax(0,1fr)_210px_170px_60px_80px] ${x.retired ? 'opacity-75' : ''}`}>
                <span className="text-base font-extrabold break-words">
                  {x.name} {x.retired && <Tag tone="plain">{t.retired}</Tag>}
                </span>
                <span className="@max-2xl:col-start-1">
                  <AccessTag access={x.access} />
                </span>
                <span className="text-sm @max-2xl:col-start-1">{x.expected ? `✓ ${t.expected}` : <span className="text-text-muted">{t.notExpected}</span>}</span>
                <span className="ref text-sm @2xl:text-right @max-2xl:col-start-2 @max-2xl:row-start-1">{t.count(x.on_file)}</span>
                <span className="@2xl:text-right @max-2xl:col-start-2 @max-2xl:row-start-2">
                  {!x.retired && (
                    <button type="button" onClick={() => setEditing(x)} aria-pressed={editing !== 'new' && editing?.id === x.id} className="min-h-11 text-sm font-extrabold underline underline-offset-[3px]">
                      {t.change}
                    </button>
                  )}
                </span>
              </li>
            ))}
          </ul>
          <div className="pt-4">
            <Button onClick={() => setEditing('new')}>{t.add}</Button>
          </div>
        </section>

        {editing !== null && (
          <TypeForm
            key={editing === 'new' ? 'new' : editing.id}
            row={editing === 'new' ? null : editing}
            onDone={(title) => {
              setEditing(null)
              loaded.reload()
              slips.done({ word: copy.slips.saved, title })
            }}
            onCancel={() => setEditing(null)}
          />
        )}
      </div>
    </>
  )
}

function TypeForm({ row, onDone, onCancel }: { row: TypeRow | null; onDone: (title: string) => void; onCancel: () => void }) {
  const [name, setName] = useState(row?.name ?? '')
  const [access, setAccess] = useState<DocumentAccess>((row?.access as DocumentAccess | undefined) ?? 'office')
  const [expected, setExpected] = useState(row?.expected ?? false)
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [problem, setProblem] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const save = async () => {
    setBusy(true)
    setErrors({})
    setProblem(null)
    const result = await documentsApi.saveType(row?.id ?? null, { name: name.trim(), access, expected })
    setBusy(false)
    if (result.kind === 'saved') onDone(t.saved)
    else if (result.kind === 'invalid') setErrors(result.errors)
    else setProblem(t.failed)
  }

  const retire = async () => {
    if (row === null) return
    setBusy(true)
    const result = await documentsApi.retireType(row.id)
    setBusy(false)
    if (result.kind === 'saved') onDone(t.retiredDone)
    else setProblem(t.failed)
  }

  return (
    <aside aria-label={row?.name ?? t.newType} className="grid gap-4 border-2 border-ink bg-sheet p-4">
      <h2 className="type-display text-[26px] leading-none">{row?.name ?? t.newType}</h2>
      {problem !== null && (
        <Notice tone="wrong" title={t.notSaved}>
          {problem}
        </Notice>
      )}
      <Input label={t.name} value={name} onChange={(e) => setName(e.target.value)} error={errors.name?.[0] ?? errors.type?.[0]} maxLength={80} required />
      <fieldset className="grid gap-2">
        <legend className="mb-1.5 text-[15px] font-extrabold">{t.accessField}</legend>
        {LEVELS.map((level) => (
          <label key={level} className={`flex items-start gap-2.5 bg-sheet px-3 py-2.5 ${access === level ? 'border-[3px] border-ink' : 'border border-rule'}`}>
            <input type="radio" name="access" checked={access === level} onChange={() => setAccess(level)} className="mt-0.5 size-5 accent-ink" />
            <span>
              <span className="block text-[15px] font-extrabold">{d.access[level]}</span>
              <span className="block text-[13px] text-text-muted">{d.accessNote[level]}</span>
            </span>
          </label>
        ))}
        {row !== null && access !== row.access && <p className="text-[13px] font-bold">{t.accessChange}</p>}
      </fieldset>
      <label className="flex items-center gap-2.5 text-[15px]">
        <input type="checkbox" checked={expected} onChange={(e) => setExpected(e.target.checked)} className="size-5 accent-ink" />
        {t.expectedField}
      </label>
      <p className="-mt-2 text-[13px] text-text-muted">{t.expectedNote}</p>
      <div className="flex flex-wrap gap-2">
        <Button busy={busy} disabled={name.trim() === ''} onClick={() => void save()}>
          {t.save}
        </Button>
        <Button variant="secondary" onClick={onCancel}>
          {t.cancel}
        </Button>
      </div>
      {row !== null && (
        <div className="border-t border-rule pt-3">
          <button type="button" disabled={busy} onClick={() => void retire()} className="min-h-11 text-[15px] font-extrabold text-red-text underline underline-offset-[3px]">
            {t.retire}
          </button>
          <p className="text-[13px] text-text-muted">{t.retireNote}</p>
        </div>
      )}
    </aside>
  )
}
