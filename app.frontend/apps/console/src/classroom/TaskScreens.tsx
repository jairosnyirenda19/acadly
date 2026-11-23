import { navigate, useLoad } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { firstError } from '../programmes/api'
import { Attention, Sheet } from '../programmes/parts'
import { PageHead, SmallButton, Tag } from '../settings/parts'
import { Input, Select } from '../setup/parts'
import { classroomApi, fileUrl, local, size, useClassroomDivision, when, type HandIns, type TaskInput } from './api'
import { DocumentPages } from './DocumentPages'

const t = copy.classroom

function NoDivision() {
  return (
    <div className="px-[clamp(16px,3vw,40px)] pt-7">
      <Attention>
        <b>{t.chooseTitle}.</b> {t.choose}
      </Attention>
    </div>
  )
}

/** Set or change an assignment or exam paper (D-104, the approved board). */
export function TaskFormScreen({ course, task }: { course: string; task: string | null }) {
  const { division } = useClassroomDivision()
  if (division === null) return <NoDivision />

  return <TaskForm key={`${division.id}${task ?? ''}`} division={division.id} course={course} task={task} />
}

function TaskForm({ division, course, task }: { division: string; course: string; task: string | null }) {
  const f = t.form
  const page = useLoad(`classroom-course:${division}:${course}`, () => classroomApi.course(division, course))
  const existing = useLoad(`classroom-task-edit:${division}:${task ?? ''}`, () => (task === null ? Promise.resolve(null) : classroomApi.task(division, task)))
  const [draft, setDraft] = useState<TaskInput | null>(null)
  const [busy, setBusy] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)
  const [file, setFile] = useState<File | null>(null)

  if (page.data === undefined || (task !== null && existing.data === undefined)) return <Waiting phase={page.phase} what={f.newTitle} labels={copy.loading} rows={6} onRetry={page.reload} />
  const old = existing.data !== null && existing.data !== undefined && typeof existing.data === 'object' && 'task' in existing.data ? existing.data.task : null
  const d: TaskInput = draft ?? {
    id: old?.id ?? null,
    kind: old?.kind === 'exam' ? 'exam' : 'assignment',
    title: old?.title ?? '',
    instructions: old?.instructions ?? '',
    shows_at: local(old?.shows_at ?? null),
    due_at: local(old?.due_at ?? null),
    late_until: old?.late_until !== undefined && old.late_until !== null ? local(old.late_until) : '',
    out_of: old?.out_of ?? 100,
    weight: old?.weight ?? 0,
    accepts: old?.accepts ?? ['pdf', 'word'],
    max_files: old?.max_files ?? 1,
    max_mb: old?.max_mb ?? 20,
  }
  const set = (patch: Partial<TaskInput>) => setDraft({ ...d, ...patch })
  const exam = d.kind === 'exam'
  const save = async () => {
    setBusy(true)
    setProblem(null)
    const result = await classroomApi.saveTask(division, course, { ...d, late_until: exam || d.late_until === '' ? null : d.late_until })
    setBusy(false)
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: f.saved })
      navigate(`/classroom/t/${result.data.id}`)
    } else setProblem(firstError(result, t.failed))
  }
  const addFile = async () => {
    if (file === null || task === null) return
    const result = await classroomApi.addTaskFile(division, task, file)
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: f.fileAdded })
      setFile(null)
      existing.reload()
    } else setProblem(firstError(result, t.failed))
  }

  return (
    <>
      <PageHead kicker={`${t.kicker} · ${page.data.course.code} ${page.data.course.title}`} title={task === null ? f.newTitle : f.editTitle} intro="" />
      <div className="grid items-start gap-4 px-[clamp(16px,3vw,40px)] pb-8 xl:grid-cols-[minmax(0,1fr)_440px]">
        <div className="grid gap-3">
          {problem !== null && (
            <Notice tone="wrong" title={t.notSaved}>
              {problem}
            </Notice>
          )}
          <Sheet label={f.what}>
            <h2 className="label-caps border-b border-rule px-4 py-2.5 text-text-muted">{f.what}</h2>
            <div className="grid gap-3 px-4 py-3">
              <div role="group" aria-label={f.what} className="flex gap-2">
                {(['assignment', 'exam'] as const).map((k) => (
                  <button
                    key={k}
                    type="button"
                    aria-pressed={d.kind === k}
                    onClick={() => set({ kind: k })}
                    className={`min-h-10 border-2 border-ink px-3.5 font-bold ${d.kind === k ? 'bg-ink text-sheet' : 'bg-sheet text-ink'}`}
                  >
                    {f.kind[k]}
                  </button>
                ))}
              </div>
              <Input label={f.title} value={d.title} onChange={(e) => set({ title: e.target.value })} />
              <label className="grid gap-1">
                <span className="label-caps text-text-muted">{f.instructions}</span>
                <textarea rows={7} value={d.instructions} onChange={(e) => set({ instructions: e.target.value })} className="border-b-2 border-ink bg-field px-3 py-2 text-[15px]" />
              </label>
              <div className="grid gap-2">
                <span className="label-caps text-text-muted">{f.files}</span>
                {task === null ? (
                  <p className="text-[14px] text-text-muted">{f.filesAfter}</p>
                ) : (
                  <>
                    {(old?.files ?? []).map((x) => (
                      <a key={x.id} href={fileUrl(x.id)}>{`${x.name} · ${size(x.size)}`}</a>
                    ))}
                    <span className="flex flex-wrap items-center gap-2">
                      <input type="file" onChange={(e) => setFile(e.target.files?.[0] ?? null)} className="min-h-11 text-[14px]" />
                      {file !== null && <SmallButton onClick={() => void addFile()}>{f.addFile}</SmallButton>}
                    </span>
                  </>
                )}
              </div>
            </div>
          </Sheet>
          <Sheet label={f.accepts}>
            <h2 className="label-caps border-b border-rule px-4 py-2.5 text-text-muted">{f.accepts}</h2>
            <div className="grid gap-3 px-4 py-3">
              <div className="grid grid-cols-2 gap-1 sm:grid-cols-3">
                {page.data.file_types.map((k) => (
                  <label key={k} className="flex min-h-10 items-center gap-2.5">
                    <input
                      type="checkbox"
                      className="size-5 accent-ink"
                      checked={d.accepts.includes(k)}
                      onChange={(e) => set({ accepts: e.target.checked ? [...d.accepts, k] : d.accepts.filter((x) => x !== k) })}
                    />
                    {f.types[k] ?? k}
                  </label>
                ))}
              </div>
              <div className="grid grid-cols-2 gap-3">
                <Input label={f.maxFiles} inputMode="numeric" value={String(d.max_files)} onChange={(e) => set({ max_files: Number(e.target.value.replace(/\D/g, '')) })} />
                <Input label={f.maxMb} inputMode="numeric" value={String(d.max_mb)} onChange={(e) => set({ max_mb: Number(e.target.value.replace(/\D/g, '')) })} />
              </div>
              <p className="text-[13px] text-text-muted">{f.typesNote}</p>
            </div>
          </Sheet>
        </div>
        <div className="grid gap-3">
          <Sheet label={f.when}>
            <h2 className="label-caps border-b border-rule px-4 py-2.5 text-text-muted">{f.when}</h2>
            <div className="grid gap-3 px-4 py-3">
              <Input label={exam ? f.startsAt : f.showsAt} type="datetime-local" value={d.shows_at} onChange={(e) => set({ shows_at: e.target.value })} />
              <Input label={exam ? f.endsAt : f.dueAt} type="datetime-local" value={d.due_at} onChange={(e) => set({ due_at: e.target.value })} />
              {!exam && <Input label={f.lateUntil} type="datetime-local" value={d.late_until ?? ''} onChange={(e) => set({ late_until: e.target.value })} />}
              <p className="text-[13px] text-text-muted">{exam ? f.examNote : f.lateNote}</p>
            </div>
          </Sheet>
          <Sheet label={f.marks}>
            <h2 className="label-caps border-b border-rule px-4 py-2.5 text-text-muted">{f.marks}</h2>
            <div className="grid grid-cols-2 gap-3 px-4 py-3">
              <Input label={f.outOf} inputMode="numeric" value={String(d.out_of)} onChange={(e) => set({ out_of: Number(e.target.value.replace(/\D/g, '')) })} />
              <Input label={f.weight} inputMode="decimal" value={String(d.weight)} onChange={(e) => set({ weight: Number(e.target.value.replace(/[^\d.]/g, '')) })} />
            </div>
            <p className="px-4 pb-3 text-[13px] text-text-muted">{t.tasks.weights(page.data.weights, page.data.share)}</p>
          </Sheet>
          <div>
            <Button busy={busy} onClick={() => void save()}>
              {f.save}
            </Button>
          </div>
        </div>
      </div>
    </>
  )
}

/** Every hand-in for one assignment or exam paper (D-104, the approved board). */
export function HandInsScreen({ task }: { task: string }) {
  const { division } = useClassroomDivision()
  if (division === null) return <NoDivision />

  return <HandInList key={`${division.id}${task}`} division={division.id} task={task} />
}

type Filter = 'all' | 'toMark' | 'late' | 'missing' | 'marked'

function HandInList({ division, task }: { division: string; task: string }) {
  const h = t.handIns
  const loaded = useLoad(`classroom-task:${division}:${task}`, () => classroomApi.task(division, task))
  const [filter, setFilter] = useState<Filter>('all')
  const [busy, setBusy] = useState(false)
  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={h.title('')} labels={copy.loading} rows={8} onRetry={loaded.reload} />
  const data = loaded.data
  const x = data.task
  const c = data.counts
  const due = new Date(x.due_at) <= new Date()
  const final = (r: HandIns['rows'][number]) => r.state === 'locked' || r.state === 'late'
  const shown = data.rows.filter((r) =>
    filter === 'all' ? true : filter === 'toMark' ? final(r) && r.mark === null : filter === 'late' ? r.state === 'late' : filter === 'missing' ? r.state === 'missing' || r.state === 'late_open' : r.mark !== null,
  )
  const next = data.rows.find((r) => final(r) && r.mark === null)
  const release = async () => {
    setBusy(true)
    const result = await classroomApi.release(division, task)
    setBusy(false)
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: h.releasedDone })
      loaded.reload()
    } else slips.wrong({ word: t.notSaved, title: firstError(result, t.failed) })
  }

  return (
    <>
      <PageHead
        kicker={`${t.kicker} · ${data.course.code} ${data.course.title}`}
        title={h.title(x.title)}
        intro={[h.lede(t.kinds[x.kind] ?? x.kind, when(x.due_at), x.out_of, x.weight), x.late_until !== null ? h.lateUntil(when(x.late_until)) : null].filter((v) => v !== null).join(' · ')}
        actions={
          data.teaches ? (
            <div className="flex flex-wrap gap-2.5">
              <Button variant="secondary" onClick={() => navigate(`/classroom/c/${data.course.id}/task/${task}`)}>
                {t.tasks.open}
              </Button>
              {next !== undefined && due && <Button onClick={() => navigate(`/classroom/t/${task}/w/${next.learner_id}`)}>{h.next}</Button>}
              {x.released ? (
                <Tag tone="done">{h.released}</Tag>
              ) : (
                due && (
                  <Button variant="secondary" busy={busy} onClick={() => void release()}>
                    {h.release}
                  </Button>
                )
              )}
            </div>
          ) : undefined
        }
      />
      <div className="grid gap-4 px-[clamp(16px,3vw,40px)] pb-8">
        <div className={`flex items-baseline gap-2.5 border px-3 py-2.5 ${due ? 'border-teal bg-teal-wash' : 'border-marigold bg-marigold-wash'}`}>
          <strong aria-hidden="true">{due ? '✓' : '!'}</strong>
          <span>
            <b>{due ? h.locked : h.open}</b> {due ? [h.lockedNote(c.handed_in), x.late_until !== null && !data.over ? h.lateNote(when(x.late_until)) : ''].join(' ') : h.openNote}
          </span>
        </div>
        <div className="grid grid-cols-2 border-2 border-ink md:grid-cols-5">
          {(
            [
              [h.registered, String(c.registered), 'sheet'],
              [h.handedIn, String(c.handed_in), 'ink'],
              [h.late, String(c.late), 'sheet'],
              [h.missing, String(c.missing), c.missing > 0 ? 'attention' : 'sheet'],
              [h.marked, h.markedOf(c.marked, c.marked + c.to_mark), 'sheet'],
            ] as const
          ).map(([label, value, tone], i) => (
            <div key={label} className={`px-4 py-3 ${i > 0 ? 'md:border-l-2 md:border-ink' : ''} ${tone === 'ink' ? 'bg-ink text-sheet' : tone === 'attention' ? 'bg-marigold' : 'bg-sheet'}`}>
              <span className={`label-caps ${tone === 'ink' ? 'text-on-ink-muted' : 'text-text-muted'}`}>{label}</span>
              <div className={`type-display text-[40px] leading-none ${tone === 'ink' ? 'text-teal-bright' : ''}`}>{value}</div>
            </div>
          ))}
        </div>
        <div role="group" className="flex flex-wrap gap-2">
          {(Object.keys(h.filters) as Filter[]).map((k) => (
            <button
              key={k}
              type="button"
              aria-pressed={filter === k}
              onClick={() => setFilter(k)}
              className={`min-h-11 border px-3.5 ${filter === k ? 'border-ink bg-ink text-sheet' : 'border-rule bg-sheet text-ink'}`}
            >
              {h.filters[k]}
            </button>
          ))}
        </div>
        <Sheet label={h.title(x.title)} className="overflow-x-auto">
          <table className="w-full min-w-[820px] border-collapse">
            <thead>
              <tr>
                {Object.values(h.columns).map((col) => (
                  <th key={col} className="label-caps border-b-2 border-ink px-3 py-2 text-left text-text-muted">
                    {col}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {shown.map((r) => (
                <tr key={r.learner_id}>
                  <td className="border-b border-rule px-3 py-2.5">
                    <strong>{r.name}</strong>
                  </td>
                  <td className="border-b border-rule px-3 py-2.5 font-mono">{r.number}</td>
                  <td className="border-b border-rule px-3 py-2.5">
                    <Tag tone={r.state === 'late' ? 'attention' : r.state === 'missing' || r.state === 'late_open' ? 'wrong' : r.state === 'open' ? 'plain' : 'done'}>{h.states[r.state] ?? r.state}</Tag>
                  </td>
                  <td className="border-b border-rule px-3 py-2.5 text-[14px]">{r.at !== null ? when(r.at) : r.withdrawn_at !== null ? h.withdrawn(when(r.withdrawn_at)) : h.none}</td>
                  <td className="border-b border-rule px-3 py-2.5">{r.times}</td>
                  <td className="border-b border-rule px-3 py-2.5 text-[14px]">
                    {r.files.length === 0
                      ? h.none
                      : r.files.map((file) => (
                          <a key={file.id} href={fileUrl(file.id)} className="block">{`${file.name} · ${size(file.size)}`}</a>
                        ))}
                  </td>
                  <td className="border-b border-rule px-3 py-2.5">
                    {r.mark !== null ? (
                      <b>{`${r.mark.mark} / ${x.out_of}`}</b>
                    ) : final(r) && due && data.teaches ? (
                      <a href={`/classroom/t/${task}/w/${r.learner_id}`} onClick={(e) => (e.preventDefault(), navigate(`/classroom/t/${task}/w/${r.learner_id}`))} className="font-bold">
                        {h.markIt}
                      </a>
                    ) : null}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </Sheet>
        <p className="text-[13px] text-text-muted">{h.hidden}</p>
        {x.instructions !== '' && (
          <Sheet label={h.instructions}>
            <h2 className="label-caps border-b border-rule px-4 py-2.5 text-text-muted">{h.instructions}</h2>
            <p className="m-0 px-4 py-3 whitespace-pre-line">{x.instructions}</p>
          </Sheet>
        )}
      </div>
    </>
  )
}

/** Marking one hand-in, its pages shown in place (D-104, D-103, the approved board). */
export function MarkingScreen({ task, learner }: { task: string; learner: string }) {
  const { division } = useClassroomDivision()
  if (division === null) return <NoDivision />

  return <Marking key={`${division.id}${task}${learner}`} division={division.id} task={task} learner={learner} />
}

function Marking({ division, task, learner }: { division: string; task: string; learner: string }) {
  const m = t.marking
  const loaded = useLoad(`classroom-work:${division}:${task}:${learner}`, () => classroomApi.work(division, task, learner))
  const [open, setOpen] = useState(0)
  const [draft, setDraft] = useState<{ mark: string; feedback: string; reason: string; file: File | null } | null>(null)
  const [busy, setBusy] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)
  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={m.mark} labels={copy.loading} rows={8} onRetry={loaded.reload} />
  const w = loaded.data
  const d = draft ?? { mark: w.mark !== null ? String(w.mark.mark) : '', feedback: w.mark?.feedback ?? '', reason: '', file: null }
  const last = [...w.history].reverse().find((e) => e.action === 'hand_in')
  const file = w.files[open] ?? w.files[0]
  const go = (to: string | null) => to !== null && navigate(`/classroom/t/${task}/w/${to}`)
  const save = async (andNext: boolean) => {
    setBusy(true)
    setProblem(null)
    const result = await classroomApi.mark(division, task, { learner_id: learner, ...d })
    setBusy(false)
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: m.saved })
      if (andNext && w.position.next !== null) go(w.position.next)
      else if (andNext) navigate(`/classroom/t/${task}`)
      else {
        setDraft(null)
        loaded.reload()
      }
    } else setProblem(firstError(result, t.failed))
  }

  return (
    <>
      <PageHead
        kicker={w.position.of > 0 ? m.kicker(w.task.title, w.position.index, w.position.of) : w.task.title}
        title={w.student.name}
        intro={m.lede(w.student.number, last !== undefined ? when(last.at) : '', t.handIns.states[w.state] ?? w.state)}
        actions={
          <div className="flex flex-wrap gap-2.5">
            <Button variant="secondary" onClick={() => navigate(`/classroom/t/${task}`)}>
              {m.back}
            </Button>
            <Button variant="secondary" onClick={() => go(w.position.previous)}>
              {m.previous}
            </Button>
            <Button variant="secondary" onClick={() => go(w.position.next)}>
              {m.next}
            </Button>
          </div>
        }
      />
      <div className="grid items-start gap-4 px-[clamp(16px,3vw,40px)] pb-8 xl:grid-cols-[minmax(0,1fr)_420px]">
        <Sheet label={m.file}>
          <div className="flex flex-wrap gap-2 border-b border-rule px-4 py-2.5">
            {w.files.length === 0 && <span className="text-text-muted">{m.noFile}</span>}
            {w.files.map((x, i) => (
              <button key={x.id} type="button" aria-pressed={i === open} onClick={() => setOpen(i)} className={`min-h-10 border px-3 text-[14px] ${i === open ? 'border-ink bg-ink text-sheet' : 'border-rule bg-sheet'}`}>
                {`${x.name} · ${size(x.size)}`}
              </button>
            ))}
          </div>
          {file !== undefined && <DocumentPages key={file.id} id={file.id} name={file.name} />}
        </Sheet>
        <div className="grid gap-3">
          {problem !== null && (
            <Notice tone="wrong" title={t.notSaved}>
              {problem}
            </Notice>
          )}
          <Sheet label={m.mark}>
            <h2 className="label-caps border-b border-rule px-4 py-2.5 text-text-muted">{m.mark}</h2>
            <div className="grid gap-3 px-4 py-3">
              {w.mark !== null && <p className="text-[14px] text-text-muted">{m.current(w.mark.mark, w.mark.by)}</p>}
              <div className="grid grid-cols-[1fr_auto] items-end gap-2">
                <Input label={m.mark} inputMode="decimal" value={d.mark} onChange={(e) => setDraft({ ...d, mark: e.target.value.replace(/[^\d.]/g, '') })} />
                <span className="pb-2 text-[20px] font-extrabold">{m.outOf(w.task.out_of)}</span>
              </div>
              <label className="grid gap-1">
                <span className="label-caps text-text-muted">{m.feedback}</span>
                <textarea rows={7} value={d.feedback} onChange={(e) => setDraft({ ...d, feedback: e.target.value })} className="border-b-2 border-ink bg-field px-3 py-2 text-[15px]" />
              </label>
              {w.task.released && (
                <>
                  <Input label={m.reason} value={d.reason} onChange={(e) => setDraft({ ...d, reason: e.target.value })} />
                  <p className="text-[13px] text-text-muted">{m.reasonNote}</p>
                </>
              )}
              <label className="grid gap-1">
                <span className="label-caps text-text-muted">{m.markedFile}</span>
                <input type="file" onChange={(e) => setDraft({ ...d, file: e.target.files?.[0] ?? null })} className="min-h-11 text-[14px]" />
              </label>
              {(w.mark?.files ?? []).map((x) => (
                <a key={x.id} href={fileUrl(x.id)}>{`${x.name} · ${size(x.size)}`}</a>
              ))}
              {w.teaches && (
                <div className="flex flex-wrap gap-2">
                  <Button busy={busy} onClick={() => void save(true)}>
                    {m.saveNext}
                  </Button>
                  <Button variant="secondary" busy={busy} onClick={() => void save(false)}>
                    {m.save}
                  </Button>
                </div>
              )}
            </div>
          </Sheet>
          <Sheet label={m.history}>
            <h2 className="label-caps border-b border-rule px-4 py-2.5 text-text-muted">{m.history}</h2>
            <ul className="m-0 list-none p-0">
              {[...w.history].reverse().map((e, i) => (
                <li key={i} className="flex min-h-11 items-center justify-between gap-2 border-b border-rule px-4 py-2 text-[14px] last:border-b-0">
                  <span>{`${e.action === 'hand_in' ? m.handedIn : m.withdrew} · ${when(e.at)}`}</span>
                  {e.late && <Tag tone="attention">{m.lateTag}</Tag>}
                </li>
              ))}
            </ul>
          </Sheet>
        </div>
      </div>
    </>
  )
}

/** The course's continuous assessment (D-104, the approved board). */
export function AssessmentScreen({ course }: { course: string }) {
  const { division } = useClassroomDivision()
  if (division === null) return <NoDivision />

  return <Assessment key={`${division.id}${course}`} division={division.id} course={course} />
}

function Assessment({ division, course }: { division: string; course: string }) {
  const a = t.assessment
  const loaded = useLoad(`classroom-assessment:${division}:${course}`, () => classroomApi.assessment(division, course))
  const page = useLoad(`classroom-course:${division}:${course}`, () => classroomApi.course(division, course))
  const [share, setShare] = useState<string | null>(null)
  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={a.title} labels={copy.loading} rows={8} onRetry={loaded.reload} />
  const data = loaded.data
  const saveShare = async () => {
    const result = await classroomApi.share(division, course, Number(share ?? data.share))
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: a.shareSaved })
      setShare(null)
      loaded.reload()
    } else slips.wrong({ word: t.notSaved, title: firstError(result, t.failed) })
  }
  const missing = data.rows.filter((r) => r.missing > 0).length

  return (
    <>
      <PageHead kicker={`${t.kicker} · ${data.course.code} ${data.course.title}`} title={a.title} intro={a.lede(data.share)} />
      <div className="grid gap-4 px-[clamp(16px,3vw,40px)] pb-8">
        <nav className="flex border-b-2 border-ink">
          {(['resources', 'announcements', 'tasks', 'assessment'] as const).map((x) => (
            <button
              key={x}
              type="button"
              aria-current={x === 'assessment' ? 'page' : undefined}
              onClick={() => navigate(x === 'resources' ? `/classroom/c/${course}` : x === 'assessment' ? `/classroom/c/${course}/assessment` : `/classroom/c/${course}?tab=${x}`)}
              className={`min-h-11 border-b-4 px-4 whitespace-nowrap ${x === 'assessment' ? 'border-teal font-extrabold' : 'border-transparent'}`}
            >
              {t.tabs[x]}
            </button>
          ))}
        </nav>
        <div className="grid grid-cols-1 border-2 border-ink sm:grid-cols-3">
          {(
            [
              [a.average, data.average === null ? '—' : `${data.average} / ${data.share}`, 'ink'],
              [a.released, a.releasedOf(data.released, data.tasks.length), 'sheet'],
              [a.missing, String(missing), missing > 0 ? 'attention' : 'sheet'],
            ] as const
          ).map(([label, value, tone], i) => (
            <div key={label} className={`px-4 py-3 ${i > 0 ? 'sm:border-l-2 sm:border-ink' : ''} ${tone === 'ink' ? 'bg-ink text-sheet' : tone === 'attention' ? 'bg-marigold' : 'bg-sheet'}`}>
              <span className={`label-caps ${tone === 'ink' ? 'text-on-ink-muted' : 'text-text-muted'}`}>{label}</span>
              <div className={`type-display text-[40px] leading-none ${tone === 'ink' ? 'text-teal-bright' : ''}`}>{value}</div>
            </div>
          ))}
        </div>
        <Sheet label={a.title} className="overflow-x-auto">
          <table className="w-full min-w-[720px] border-collapse">
            <thead>
              <tr>
                <th className="label-caps border-b-2 border-ink px-3 py-2 text-left text-text-muted">{a.student}</th>
                {data.tasks.map((x) => (
                  <th key={x.id} className="label-caps border-b-2 border-ink px-3 py-2 text-right text-text-muted">{`${x.title} · /${x.out_of} · ${x.weight}%`}</th>
                ))}
                <th className="label-caps border-b-2 border-ink px-3 py-2 text-right text-text-muted">{a.soFar(data.share)}</th>
                <th className="border-b-2 border-ink" />
              </tr>
            </thead>
            <tbody>
              {data.rows.map((r) => (
                <tr key={r.learner_id}>
                  <td className="border-b border-rule px-3 py-2.5">
                    <strong>{r.name}</strong>
                  </td>
                  {r.cells.map((cell, i) => (
                    <td key={i} className="border-b border-rule px-3 py-2.5 text-right text-[14px]">
                      {cell.mark !== null ? (
                        cell.released ? (
                          cell.mark
                        ) : (
                          <span className="text-text-muted">{`${cell.mark} · ${a.notReleased}`}</span>
                        )
                      ) : cell.state === 'missing' || cell.state === 'late_open' ? (
                        <span className="text-text-muted">{a.notIn}</span>
                      ) : cell.state === 'locked' || cell.state === 'late' ? (
                        <span className="text-text-muted">{a.toMark}</span>
                      ) : null}
                    </td>
                  ))}
                  <td className="border-b border-rule px-3 py-2.5 text-right">
                    <b>{r.total}</b>
                  </td>
                  <td className="border-b border-rule px-3 py-2.5">{r.missing > 0 && <Tag tone="wrong">{a.missingTag}</Tag>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </Sheet>
        {page.data?.teaches === true && (
          <div className="flex items-end gap-2">
            <div className="w-56">
              <Select label={a.share} value={share ?? String(data.share)} onChange={(e) => setShare(e.target.value)}>
                {[0, 10, 20, 25, 30, 40, 50, 60, 70, 100].map((n) => (
                  <option key={n} value={n}>
                    {n}
                  </option>
                ))}
              </Select>
            </div>
            <SmallButton onClick={() => void saveShare()}>{a.saveShare}</SmallButton>
          </div>
        )}
        <p className="text-[13px] text-text-muted">{a.note}</p>
      </div>
    </>
  )
}
