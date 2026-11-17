import { useLoad } from '@acadly/kernel'
import { Button, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { Tag } from '../settings/parts'
import { Attention, Total, TotalsBand } from '../setup/parts'
import { assessmentApi, type PublishingState, type PublishRow } from './api'
import { InLink } from './parts'

const a = copy.assessment
const t = a.publish

const tone = { ready: 'done', not_ready: 'attention', published: 'locked', changed: 'attention' } as const

/** Publish results (the approved design): each class with how ready it is, choose, then publish with what it means beside. */
export function PublishScreen() {
  const loaded = useLoad('assessment:publishing', () => assessmentApi.publishing())
  const [data, setData] = useState<PublishingState | null>(null)
  const [chosen, setChosen] = useState<string[]>([])
  const [open, setOpen] = useState<string | null>(null)
  const [settings, setSettings] = useState(false)
  const [busy, setBusy] = useState(false)
  const shown = data ?? loaded.data

  if (shown === undefined) return <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} band={[t.bandClasses, t.bandReady, t.bandNotReady]} rows={6} onRetry={loaded.reload} />

  const rows = shown.classes
  const can = (r: PublishRow) => r.state === 'ready' || (r.state === 'changed' && r.ready)
  const picked = rows.filter((r) => chosen.includes(r.class_id) && can(r))
  const toggle = (id: string) => setChosen(chosen.includes(id) ? chosen.filter((x) => x !== id) : [...chosen, id])

  const publish = async (ids: string[]) => {
    setBusy(true)
    const r = await assessmentApi.publish(ids)
    setBusy(false)
    if (r.kind === 'saved') {
      setData(r.data)
      setChosen([])
      slips.done({ word: copy.slips.saved, title: t.done(r.data.published.length) })
    } else {
      const reason = r.kind === 'invalid' ? Object.values(r.errors)[0]?.[0] : undefined
      slips.wrong({ word: t.failed, title: reason ?? (r.kind === 'refused' ? t.who : copy.settings.failed) })
    }
  }

  if (rows.length === 0) {
    return (
      <div className="m-4 border-2 border-ink bg-sheet p-4 md:mx-[clamp(16px,3vw,40px)]">
        <p className="type-display mb-1.5 text-[22px] leading-none">{t.empty}</p>
        <p className="text-sm">{t.emptyText}</p>
      </div>
    )
  }

  const count = (state: string) => rows.filter((r) => r.state === state).length

  return (
    <>
      <TotalsBand>
        <Total tone="ink" label={t.bandClasses} value={String(rows.length)} note={t.bandClassesNote} />
        <Total label={t.bandReady} value={String(count('ready'))} note={t.bandReadyNote} />
        <Total tone={count('not_ready') > 0 ? 'attention' : 'plain'} label={t.bandNotReady} value={String(count('not_ready'))} note={t.bandNotReadyNote} />
        <Total label={t.bandPublished} value={String(count('published') + count('changed'))} note={t.bandPublishedNote(count('changed'))} />
      </TotalsBand>
      <div className="flex flex-1 flex-wrap items-stretch bg-sheet">
        <section aria-label={t.tableLabel} className="min-w-0 flex-[3_1_620px] px-4 pt-4 pb-6 md:px-[clamp(16px,3vw,40px)]">
          <div className="mb-2 flex justify-end">
            <button type="button" aria-expanded={settings} onClick={() => setSettings(!settings)} className="min-h-11 text-sm font-extrabold underline underline-offset-[3px]">
              {t.settings}
            </button>
          </div>
          {settings && (
            <Settings
              value={shown.settings}
              onSaved={(next) => {
                setData(next)
                setSettings(false)
              }}
            />
          )}
          <ul>
            {rows.map((r) => (
              <Row
                key={r.class_id}
                row={r}
                chosen={chosen.includes(r.class_id)}
                canChoose={can(r)}
                open={open === r.class_id}
                busy={busy}
                onToggle={() => toggle(r.class_id)}
                onOpen={() => setOpen(open === r.class_id ? null : r.class_id)}
                onPublish={() => void publish([r.class_id])}
              />
            ))}
          </ul>
          {picked.length > 0 && (
            <div className="sticky bottom-0 mt-3 flex items-center gap-3 border-t-2 border-ink bg-sheet py-2.5">
              <span className="text-sm font-extrabold">{t.selected(picked.length)}</span>
            </div>
          )}
        </section>
        <aside className="min-w-0 flex-[1_1_330px] border-ink bg-paper px-4 pt-4 pb-6 max-[1023px]:border-t-2 md:px-6 min-[1024px]:border-l-2">
          {picked.length === 0 ? (
            <p className="text-sm text-text-muted">{t.pick}</p>
          ) : (
            <Confirm rows={picked} settings={shown.settings} busy={busy} onPublish={() => void publish(picked.map((r) => r.class_id))} onCancel={() => setChosen([])} />
          )}
          <p className="mt-3 text-xs text-text-muted">{t.who}</p>
        </aside>
      </div>
    </>
  )
}

function Row({ row, chosen, canChoose, open, busy, onToggle, onOpen, onPublish }: { row: PublishRow; chosen: boolean; canChoose: boolean; open: boolean; busy: boolean; onToggle: () => void; onOpen: () => void; onPublish: () => void }) {
  const missingComments = row.learners - row.comments
  const why =
    row.state === 'not_ready'
      ? (row.missing[0] ?? '')
      : row.state === 'ready'
        ? missingComments > 0
          ? t.why.noComments(missingComments)
          : t.why.ready
        : row.latest !== null
          ? row.state === 'changed'
            ? t.why.changed(a.day(row.latest.published_at))
            : t.why.published(row.latest.version, a.day(row.latest.published_at))
          : ''

  return (
    <li className={`border-b border-rule ${chosen ? 'bg-teal-wash' : ''}`}>
      <div className="grid grid-cols-[44px_minmax(0,1fr)_auto] items-center gap-x-3 py-2 md:grid-cols-[44px_minmax(0,1fr)_64px_72px_80px_96px_auto]">
        <span className="flex justify-center">
          <input type="checkbox" aria-label={t.choose(row.class)} checked={chosen} disabled={!canChoose} onChange={onToggle} className="size-5 accent-ink disabled:opacity-30" />
        </span>
        <span className="min-w-0">
          <span className="block text-[15px] font-extrabold">{row.class}</span>
          <span className="block text-xs text-text-muted">{[row.level, row.campus, row.period.name].filter((x) => x !== '').join(a.joiner)}</span>
          <span className="block text-[13px]">{why}</span>
          {row.state === 'not_ready' && row.missing.length > 1 && (
            <button type="button" aria-expanded={open} onClick={onOpen} className="min-h-9 text-[13px] font-extrabold underline underline-offset-[3px]">
              {open ? t.hideMissing : t.seeMissing}
            </button>
          )}
        </span>
        <span className="ref hidden text-sm md:block" aria-label={t.colLearners}>
          {row.learners}
        </span>
        <span className="ref hidden text-sm md:block" aria-label={t.colSubjects}>
          {a.fraction(row.subjects_ready, row.subjects)}
        </span>
        <span className="ref hidden text-sm md:block" aria-label={t.colComments}>
          {a.fraction(row.comments, row.learners)}
        </span>
        <span className="hidden md:block">
          <Tag tone={tone[row.state]}>{t.states[row.state]}</Tag>
        </span>
        <span className="flex flex-col items-end gap-1">
          <span className="md:hidden">
            <Tag tone={tone[row.state]}>{t.states[row.state]}</Tag>
          </span>
          {canChoose && (
            <Button variant="secondary" className="h-9 px-3 text-sm" disabled={busy} onClick={onPublish}>
              {row.state === 'changed' ? t.republish : t.publish}
            </Button>
          )}
          {row.latest !== null && <InLink href={`/marks/cards/${row.latest.id}`}>{t.cards}</InLink>}
          <InLink href={`/marks/comments?class_id=${row.class_id}`}>{t.comments}</InLink>
        </span>
      </div>
      {open && (
        <ul className="mb-2 ml-14 list-disc pl-4 text-[13px]">
          {row.missing.map((m) => (
            <li key={m}>{m}</li>
          ))}
        </ul>
      )}
    </li>
  )
}

function Confirm({ rows, settings, busy, onPublish, onCancel }: { rows: PublishRow[]; settings: PublishingState['settings']; busy: boolean; onPublish: () => void; onCancel: () => void }) {
  const short = rows.filter((r) => r.comments < r.learners)

  return (
    <div className="border-2 border-ink bg-sheet">
      <div className="border-b-2 border-ink px-4 py-3">
        <div className="label-caps text-text-muted">{t.panelKicker(rows.length)}</div>
        <div className="type-display mt-1 text-[28px] leading-[0.9]">{rows.map((r) => r.class).join(', ')}</div>
      </div>
      <div className="px-4 py-3 text-sm leading-normal">
        <div className="label-caps mb-1.5 text-text-muted">{t.whenTitle}</div>
        <ul className="list-disc pl-[18px]">
          {t.when.map((line) => (
            <li key={line}>{line}</li>
          ))}
          <li>{settings.show_position ? t.whenPosition : t.whenNoPosition}</li>
        </ul>
      </div>
      {short.map((r) => (
        <div key={r.class_id} className="mx-4 mb-2">
          <Attention title={t.attention}>{t.missingComments(r.learners - r.comments, r.class)}</Attention>
        </div>
      ))}
      <p className="px-4 py-2 text-[13px] text-text-muted">{t.later}</p>
      <div className="flex flex-wrap gap-2 px-4 pb-4">
        <Button className="h-11" disabled={busy} aria-busy={busy || undefined} onClick={onPublish}>
          {t.publishMany(rows.length)}
        </Button>
        <Button variant="secondary" className="h-11" onClick={onCancel}>
          {t.cancel}
        </Button>
      </div>
    </div>
  )
}

function Settings({ value, onSaved }: { value: PublishingState['settings']; onSaved: (next: PublishingState) => void }) {
  const [position, setPosition] = useState(value.show_position)
  const [head, setHead] = useState(value.head_comment)
  const [busy, setBusy] = useState(false)

  const save = async () => {
    setBusy(true)
    const r = await assessmentApi.cardSettings({ show_position: position, head_comment: head })
    setBusy(false)
    if (r.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: t.saved })
      onSaved(r.data)
    } else slips.wrong({ word: a.notSaved, title: copy.settings.failed })
  }

  return (
    <div className="mb-4 border-2 border-ink bg-paper p-4">
      <div className="label-caps mb-2 text-text-muted">{t.settingsTitle}</div>
      {[
        { label: t.showPosition, hint: t.showPositionHint, on: position, set: setPosition },
        { label: t.headComment, hint: t.headCommentHint, on: head, set: setHead },
      ].map((x) => (
        <label key={x.label} className="flex min-h-11 items-start gap-3 py-1.5">
          <input type="checkbox" checked={x.on} onChange={(e) => x.set(e.target.checked)} className="mt-0.5 size-5 accent-ink" />
          <span>
            <span className="block text-sm font-extrabold">{x.label}</span>
            <span className="block text-[13px] text-text-muted">{x.hint}</span>
          </span>
        </label>
      ))}
      <p className="my-2 text-[13px] text-text-muted">{t.settingsNote}</p>
      <Button className="h-11" disabled={busy} onClick={() => void save()}>
        {t.save}
      </Button>
    </div>
  )
}
