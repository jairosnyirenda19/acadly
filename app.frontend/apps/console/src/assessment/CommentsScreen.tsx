import { useLoad, useLocation } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useEffect, useState } from 'react'
import { copy } from '../copy'
import { Tag } from '../settings/parts'
import { assessmentApi, type CommentsState } from './api'
import { BackLink } from './parts'

const a = copy.assessment
const t = a.comments

/** Report comments (the approved phone design): one learner at a time, their term at a glance, and the comment. */
export function CommentsScreen() {
  const { query } = useLocation()
  const classId = query.get('class_id') ?? ''
  const loaded = useLoad(`assessment:comments:${classId}`, () => (classId === '' ? Promise.resolve(null) : assessmentApi.comments(classId)))
  const [data, setData] = useState<CommentsState | null>(null)
  const [index, setIndex] = useState(0)
  const shown = data ?? loaded.data

  if (classId === '') {
    return (
      <div className="px-4 py-6">
        <BackLink href="/marks">{t.backMarks}</BackLink>
        <Notice tone="wrong" title={t.noLearners} />
      </div>
    )
  }

  if (shown === undefined) return <Waiting phase={loaded.phase} what={a.marks.looksAfter} labels={copy.loading} rows={4} onRetry={loaded.reload} />

  if (shown.learners.length === 0) {
    return (
      <div className="px-4 py-6 md:px-[clamp(16px,3vw,40px)]">
        <BackLink href="/marks">{t.backMarks}</BackLink>
        <Notice tone="wrong" title={t.noLearners} />
      </div>
    )
  }

  const at = Math.min(index, shown.learners.length - 1)

  return <OneLearner key={shown.learners[at]?.id} data={shown} at={at} onData={setData} onMove={setIndex} />
}

function OneLearner({ data, at, onData, onMove }: { data: CommentsState; at: number; onData: (next: CommentsState) => void; onMove: (n: number) => void }) {
  const row = data.learners[at]
  const [own, setOwn] = useState(row?.comments.class_teacher?.text ?? '')
  const [head, setHead] = useState(row?.comments.head?.text ?? '')
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    window.scrollTo({ top: 0 })
  }, [])

  if (row === undefined) return null

  const last = at >= data.learners.length - 1

  const save = async (then: number | null) => {
    const writes: { author: 'class_teacher' | 'head'; text: string }[] = []
    if (data.can_class && own.trim() !== (row.comments.class_teacher?.text ?? '')) writes.push({ author: 'class_teacher', text: own })
    if (data.can_head && head.trim() !== (row.comments.head?.text ?? '')) writes.push({ author: 'head', text: head })
    setBusy(true)
    let next: CommentsState | null = null
    for (const w of writes) {
      const r = await assessmentApi.comment({ class_id: data.class.id, learner_id: row.id, ...w })
      if (r.kind !== 'saved') {
        setBusy(false)
        const reason = r.kind === 'invalid' ? Object.values(r.errors)[0]?.[0] : undefined
        slips.wrong({ word: t.failed, title: reason ?? copy.settings.failed })

        return
      }
      next = r.data
    }
    setBusy(false)
    if (next !== null) {
      onData(next)
      if (then === null) slips.done({ word: copy.slips.saved, title: t.saved })
    }
    if (then !== null) onMove(then)
  }

  return (
    <div className="flex flex-1 flex-col bg-paper">
      <div className="px-4 pt-2 pb-2.5 md:px-[clamp(16px,3vw,40px)]">
        <BackLink href="/marks">{t.back(data.class.name)}</BackLink>
        <div className="label-caps text-text-muted">{t.kicker(at + 1, data.learners.length, data.written)}</div>
        <h1 className="type-display mt-0.5 text-[clamp(40px,7vw,72px)]">{row.name}</h1>
      </div>
      <div className="grid grid-cols-2 border-y-2 border-ink bg-sheet">
        <section className="bg-ink px-4 pt-2 pb-2.5 md:px-[clamp(16px,3vw,40px)]">
          <h2 className="label-caps text-on-ink-muted">{t.position}</h2>
          <div className="type-numeral text-[34px] text-teal-bright">
            {row.position !== null ? `${row.position.shared ? '=' : ''}${row.position.position}` : copy.dash} {row.position !== null && <span className="text-lg text-sheet">{t.of(row.position.of)}</span>}
          </div>
        </section>
        <section className="border-l-2 border-ink px-4 pt-2 pb-2.5">
          <h2 className="label-caps text-text-muted">{t.total}</h2>
          <div className="type-numeral text-[34px]">{a.num(row.total.total)}</div>
        </section>
      </div>
      <ul aria-label={t.gradesLabel} className="flex flex-wrap gap-x-3 gap-y-1.5 border-b border-rule bg-sheet px-4 py-2.5 md:px-[clamp(16px,3vw,40px)]">
        {row.grades.map((g) => (
          <li key={g.subject} className="inline-flex items-center gap-1 text-xs font-extrabold">
            <abbr title={g.subject} className="no-underline">
              {g.short}
            </abbr>
            {g.grade !== null ? <Tag tone="plain">{g.grade}</Tag> : <span className="text-text-muted">{copy.dash}</span>}
          </li>
        ))}
      </ul>
      <div className="flex-1 bg-sheet px-4 py-3 md:px-[clamp(16px,3vw,40px)]">
        <Comment label={t.classTeacher} value={own} onChange={setOwn} max={data.max} canWrite={data.can_class} by={row.comments.class_teacher?.by ?? null} />
        {data.head_comment && <Comment label={t.head} value={head} onChange={setHead} max={data.max} canWrite={data.can_head} by={row.comments.head?.by ?? null} />}
        {row.lines.map((l) => (
          <div key={l.label} className="mt-3.5 border border-rule px-3 py-2.5 text-[13px]">
            <div className="label-caps text-text-muted">{l.label}</div>
            <strong>{l.value}</strong>
          </div>
        ))}
      </div>
      <div className="sticky bottom-0 flex gap-2 border-t-2 border-ink bg-sheet px-4 py-2.5 md:px-[clamp(16px,3vw,40px)]">
        <Button variant="secondary" className="h-12 flex-1" disabled={busy || at === 0} onClick={() => void save(at - 1)}>
          ‹ {t.previous}
        </Button>
        <Button className="h-12 flex-[1.4]" disabled={busy} aria-busy={busy || undefined} onClick={() => void save(last ? null : at + 1)}>
          {last ? t.last : `${t.next} ›`}
        </Button>
      </div>
    </div>
  )
}

function Comment({ label, value, onChange, max, canWrite, by }: { label: string; value: string; onChange: (v: string) => void; max: number; canWrite: boolean; by: string | null }) {
  const id = `comment-${label.length}-${label.charCodeAt(0)}`

  return (
    <div className="mb-3">
      <label htmlFor={id} className="label-caps mb-1.5 block text-text-muted">
        {label}
      </label>
      <textarea
        id={id}
        value={value}
        maxLength={max}
        readOnly={!canWrite}
        onChange={(e) => onChange(e.target.value)}
        rows={5}
        className="block w-full border-0 border-b-2 border-ink bg-field p-2.5 text-[15px] leading-[1.45] read-only:bg-paper"
      />
      <div className="mt-1.5 flex justify-between gap-2 text-xs text-text-muted">
        <span>{by !== null && !canWrite ? t.by(by) : t.shown}</span>
        <span className="ref">{t.count(value.length, max)}</span>
      </div>
    </div>
  )
}
