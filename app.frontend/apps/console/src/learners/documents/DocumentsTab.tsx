import { useLoad } from '@acadly/kernel'
import { Button, slips, Waiting } from '@acadly/ui'
import { useMemo, useRef, useState } from 'react'
import { copy } from '../../copy'
import { TextArea } from '../../setup/parts'
import { day } from '../../settings/api'
import { Tag } from '../../settings/parts'
import type { LearnerState } from '../api'
import { Column, Columns, InLink, SectionTitle, tabHref } from '../record/ui'
import { AddDocument } from './AddDocument'
import { contentUrl, documentsApi, fileSize, thumbnailUrl, type DocumentsState, type LearnerDocument } from './api'
import { firstPagePicture } from './preview'

const t = copy.learners.documents

const LOCK = (
  <svg aria-hidden="true" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.6" strokeLinecap="square">
    <rect x="5" y="11" width="14" height="10" />
    <path d="M8 11V7a4 4 0 0 1 8 0v4" />
  </svg>
)

/** Who may open a document, as a word; confidential is ink with a lock. */
export function AccessTag({ access }: { access: string }) {
  if (access === 'confidential') {
    return (
      <span className="label-caps inline-flex items-center gap-1.5 bg-ink px-[7px] py-[3px] whitespace-nowrap text-sheet">
        {LOCK}
        {t.access.confidential}
      </span>
    )
  }

  return <Tag tone="plain">{t.access[access] ?? access}</Tag>
}

/** The first page of a document, or its file type when there is no picture. */
function Picture({ doc, large = false }: { doc: LearnerDocument; large?: boolean }) {
  const [failed, setFailed] = useState(false)
  const size = large ? 'h-[300px] w-full' : 'h-[66px] w-[50px]'

  if (doc.thumbnail && !failed) {
    return (
      <img
        src={thumbnailUrl(doc.id)}
        alt={large ? t.pictureOf(doc.title) : ''}
        onError={() => setFailed(true)}
        className={`${size} shrink-0 border border-rule bg-sheet object-contain ${large ? 'bg-paper' : 'object-top'}`}
      />
    )
  }

  return (
    <span aria-hidden={!large} className={`${size} ref flex shrink-0 items-end justify-center border-2 border-ink bg-sheet pb-1.5 text-[10px] font-extrabold`}>
      {t.fileType[doc.file_type] ?? doc.file_type}
    </span>
  )
}

/** The Documents tab of a learner's record (the approved design): by type, what is missing, the selected one beside. */
export function DocumentsTab({ learner }: { learner: LearnerState }) {
  const loaded = useLoad(`learner:documents:${learner.id}`, () => documentsApi.list(learner.id))
  const data = loaded.data
  const [find, setFind] = useState('')
  const [type, setType] = useState('')
  const [selected, setSelected] = useState<string | null>(null)
  const [adding, setAdding] = useState<string | null>(null)

  const groups = useMemo(() => {
    if (data === undefined) return []
    const words = find.trim().toLowerCase()

    return data.types
      .filter((x) => type === '' || x.id === type)
      .map((x) => ({
        type: x,
        docs: data.documents.filter((d) => d.type_id === x.id && (words === '' || d.title.toLowerCase().includes(words) || x.name.toLowerCase().includes(words))),
      }))
      .filter((g) => g.docs.length > 0)
  }, [data, find, type])

  if (data === undefined) {
    return <Waiting phase={loaded.phase} what={copy.learners.record.tabs.documents} labels={copy.loading} rows={5} onRetry={loaded.reload} />
  }

  if (adding !== null) {
    return (
      <AddDocument
        learner={learner}
        state={data}
        typeId={adding}
        onDone={(id) => {
          setAdding(null)
          setSelected(id)
          loaded.reload()
          slips.done({ word: copy.slips.saved, title: t.addedDone })
        }}
        onCancel={() => setAdding(null)}
      />
    )
  }

  const canAdd = data.can_add.length > 0
  const shown = data.documents.find((d) => d.id === selected) ?? groups[0]?.docs[0]

  return (
    <Columns top>
      <Column grow="flex-[2_1_520px]" label={copy.learners.record.tabs.documents}>
        <div className="mb-4 flex flex-wrap items-end gap-2.5">
          <label className="flex min-w-0 flex-[1_1_240px] items-center border-2 border-ink bg-sheet px-3">
            <span className="sr-only">{t.find}</span>
            <input type="search" value={find} onChange={(e) => setFind(e.target.value)} placeholder={t.findPlaceholder} className="h-10 min-w-0 flex-1 bg-transparent text-[15px] outline-none" />
          </label>
          <label className="flex items-center gap-2 text-sm font-bold">
            {t.typeFilter}
            <select value={type} onChange={(e) => setType(e.target.value)} className="h-11 border-2 border-ink bg-sheet px-2 text-[15px]">
              <option value="">{t.allTypes}</option>
              {data.types.map((x) => (
                <option key={x.id} value={x.id}>
                  {x.name}
                </option>
              ))}
            </select>
          </label>
          {canAdd && <Button onClick={() => setAdding('')}>{t.add}</Button>}
        </div>

        {data.missing.length > 0 && (
          <ul className="mb-5 grid gap-2">
            {data.missing.map((m) => (
              <li key={m.type_id} className="flex flex-wrap items-center gap-x-3 gap-y-1.5 border-2 border-dashed border-ink bg-marigold-wash px-3.5 py-2.5">
                <Tag tone="attention">! {t.notOnFile}</Tag>
                <span className="min-w-0 flex-1 text-sm">
                  <b>{m.name}.</b> {t.expectedNote}
                </span>
                {data.can_add.length > 0 && (
                  <button type="button" onClick={() => setAdding(m.type_id)} className="min-h-11 px-2 text-sm font-extrabold underline underline-offset-[3px]">
                    {t.addShort}
                  </button>
                )}
              </li>
            ))}
          </ul>
        )}

        {data.documents.length === 0 && <p className="text-sm text-text-muted">{t.none}</p>}
        {data.documents.length > 0 && groups.length === 0 && <p className="text-sm text-text-muted">{t.noneMatch}</p>}

        <div className="grid gap-5">
          {groups.map((g) => (
            <section key={g.type.id} aria-label={g.type.name}>
              <div className="mb-2 flex items-baseline gap-2.5 border-b-2 border-ink pb-1">
                <h3 className="flex-1 text-[15px] font-extrabold">{g.type.name}</h3>
                <span className="ref text-[13px] text-text-muted">{g.docs.length}</span>
              </div>
              <ul className="grid gap-2">
                {g.docs.map((d) => (
                  <li key={d.id}>
                    <button
                      type="button"
                      aria-pressed={shown?.id === d.id}
                      onClick={() => setSelected(d.id)}
                      className={`flex w-full items-start gap-3.5 bg-sheet px-3.5 py-3 text-left ${shown?.id === d.id ? 'border-[3px] border-ink' : 'border border-rule hover:border-ink'}`}
                    >
                      <Picture doc={d} />
                      <span className="min-w-0 flex-1">
                        <span className="block text-base font-extrabold break-words">{d.title}</span>
                        <span className="mt-0.5 block text-[13px] text-text-muted">
                          {d.date !== null && `${t.dated(day(d.date))} · `}
                          {t.added(d.added_by, day(d.added_at))}
                        </span>
                        <span className="mt-2 flex flex-wrap gap-1.5">
                          <AccessTag access={d.access} />
                          {d.versions > 1 && <span className="label-caps border border-rule px-[7px] py-[2px] text-text-muted">{t.versions(d.versions)}</span>}
                        </span>
                      </span>
                    </button>
                  </li>
                ))}
              </ul>
            </section>
          ))}
        </div>

        <p className="mt-5 text-[13px] text-text-muted">
          {t.scripts} <InLink href={tabHref(learner.id, 'assessment')}>{t.seeResults}</InLink>
        </p>
      </Column>

      <Column edge grow="flex-[1_1_340px]" label={t.selected}>
        {shown === undefined ? <p className="text-sm text-text-muted">{t.chooseOne}</p> : <Selected key={shown.id} doc={shown} state={data} onChanged={loaded.reload} />}
      </Column>
    </Columns>
  )
}

/** The selected document: its first page, what it is, its versions and what can be done. */
function Selected({ doc, state, onChanged }: { doc: LearnerDocument; state: DocumentsState; onChanged: () => void }) {
  const type = state.types.find((x) => x.id === doc.type_id)
  const detail = useLoad(`document:${doc.id}:${doc.versions}`, () => documentsApi.detail(doc.id))
  const canWrite = state.can_add.includes(doc.access)
  const chooser = useRef<HTMLInputElement>(null)
  const [busy, setBusy] = useState(false)
  const [trashing, setTrashing] = useState(false)
  const [reason, setReason] = useState('')
  const versions = detail.data?.versions ?? []

  const addVersion = async (file: File | undefined) => {
    if (file === undefined) return
    setBusy(true)
    const result = await documentsApi.newVersion(doc.id, file, await firstPagePicture(file))
    setBusy(false)
    if (chooser.current !== null) chooser.current.value = ''
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: t.versionAdded })
      onChanged()
    } else slips.wrong({ word: t.notSaved, title: result.kind === 'invalid' ? (Object.values(result.errors)[0]?.[0] ?? t.failed) : t.failed })
  }

  const trash = async () => {
    setBusy(true)
    const result = await documentsApi.trash(doc.id, reason.trim())
    setBusy(false)
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: t.trashed })
      onChanged()
    } else slips.wrong({ word: t.notSaved, title: t.failed })
  }

  return (
    <div className="grid gap-3.5">
      <div>
        <div className="label-caps text-text-muted">{type?.name ?? ''}</div>
        <SectionTitle>{doc.title}</SectionTitle>
        <AccessTag access={doc.access} />
      </div>

      <Picture doc={doc} large />
      {!doc.thumbnail && <p className="text-[13px] text-text-muted">{doc.access === 'confidential' ? t.noPicture : t.noPictureYet}</p>}

      <dl className="grid grid-cols-[110px_minmax(0,1fr)] gap-x-2.5 gap-y-1.5 text-sm">
        <dt className="text-text-muted">{t.fileLabel}</dt>
        <dd className="ref text-[13px]">
          {t.fileType[doc.file_type] ?? doc.file_type} · {fileSize(doc.size)}
        </dd>
        {doc.date !== null && (
          <>
            <dt className="text-text-muted">{t.dateLabel}</dt>
            <dd>{day(doc.date)}</dd>
          </>
        )}
        <dt className="text-text-muted">{t.addedLabel}</dt>
        <dd>{t.added(doc.added_by, day(doc.added_at))}</dd>
      </dl>

      <div className="flex flex-wrap gap-2">
        <a href={contentUrl(doc.id, true)} target="_blank" rel="noopener" className="inline-flex min-h-11 items-center border-2 border-ink bg-ink px-4 text-[15px] font-extrabold text-sheet no-underline">
          {t.open}
        </a>
        <a href={contentUrl(doc.id, false)} className="inline-flex min-h-11 items-center border-2 border-ink px-4 text-[15px] font-extrabold text-ink no-underline hover:bg-field">
          {t.download}
        </a>
        {canWrite && (
          <>
            <Button variant="secondary" busy={busy && !trashing} onClick={() => chooser.current?.click()}>
              {t.newVersion}
            </Button>
            <input ref={chooser} type="file" accept="application/pdf,image/jpeg,image/png" className="hidden" onChange={(e) => void addVersion(e.target.files?.[0])} />
          </>
        )}
      </div>

      {versions.length > 0 && (
        <div className="border-t border-rule pt-3">
          <div className="label-caps mb-1 text-text-muted">{t.versionsTitle}</div>
          <ul>
            {[...versions].reverse().map((v) => (
              <li key={v.version} className="flex gap-2.5 py-1.5 text-sm">
                <span className="ref">{t.versionName(v.version)}</span>
                <span className="min-w-0 flex-1 break-words">{v.name}</span>
                <span className="text-text-muted">{day(v.uploaded_at)}</span>
              </li>
            ))}
          </ul>
        </div>
      )}

      {canWrite && (
        <div className="border-t border-rule pt-3">
          {trashing ? (
            <div className="grid gap-2.5">
              <TextArea label={t.trashReason} value={reason} onChange={(e) => setReason(e.target.value)} hint={t.trashNote} required />
              <div className="flex flex-wrap gap-2">
                <Button busy={busy} disabled={reason.trim() === ''} onClick={() => void trash()}>
                  {t.trash}
                </Button>
                <Button variant="secondary" onClick={() => setTrashing(false)}>
                  {t.cancel}
                </Button>
              </div>
            </div>
          ) : (
            <>
              <button type="button" onClick={() => setTrashing(true)} className="min-h-11 text-[15px] font-extrabold text-red-text underline underline-offset-[3px]">
                {t.trash}
              </button>
              <p className="text-[13px] text-text-muted">{t.trashNote}</p>
            </>
          )}
        </div>
      )}
    </div>
  )
}
