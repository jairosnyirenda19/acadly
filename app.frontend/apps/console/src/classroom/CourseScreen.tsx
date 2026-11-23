import { navigate, useLoad } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { firstError } from '../programmes/api'
import { Attention, Sheet } from '../programmes/parts'
import { PageHead, SmallButton, Tag } from '../settings/parts'
import { Input } from '../setup/parts'
import { classroomApi, fileUrl, size, useClassroomDivision, when, type CoursePage, type TaskRow } from './api'

const t = copy.classroom

type Tab = 'resources' | 'announcements' | 'tasks' | 'assessment'

/** A course page (D-104, the approved boards): resources, announcements, assignments and exams, continuous assessment. */
export function CourseScreen({ id, tab }: { id: string; tab: Tab }) {
  const { division } = useClassroomDivision()
  if (division === null) {
    return (
      <div className="px-[clamp(16px,3vw,40px)] pt-7">
        <Attention>
          <b>{t.chooseTitle}.</b> {t.choose}
        </Attention>
      </div>
    )
  }

  return <Loaded key={`${division.id}${id}`} division={division.id} id={id} tab={tab} />
}

function Loaded({ division, id, tab }: { division: string; id: string; tab: Tab }) {
  const loaded = useLoad(`classroom-course:${division}:${id}`, () => classroomApi.course(division, id))
  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} rows={8} onRetry={loaded.reload} />
  const data = loaded.data
  const c = data.course
  const go = (to: Tab) => navigate(to === 'resources' ? `/classroom/c/${id}` : `/classroom/c/${id}?tab=${to}`)

  return (
    <>
      <PageHead
        kicker={`${t.kicker} · ${c.period}`}
        title={`${c.code} · ${c.title}`}
        intro={[c.lecturers.join(', '), t.students(c.students), c.department].filter((x) => x !== '').join(' · ')}
        actions={data.teaches ? <Button onClick={() => navigate(`/classroom/c/${id}/task`)}>{t.tasks.set}</Button> : undefined}
      />
      <div className="px-[clamp(16px,3vw,40px)] pb-8">
        <nav role="tablist" aria-label={c.code} className="mb-4 flex overflow-x-auto border-b-2 border-ink">
          {(['resources', 'announcements', 'tasks', 'assessment'] as Tab[]).map((x) => (
            <button
              key={x}
              type="button"
              role="tab"
              aria-selected={x === tab}
              onClick={() => (x === 'assessment' ? navigate(`/classroom/c/${id}/assessment`) : go(x))}
              className={`min-h-11 shrink-0 border-b-4 px-4 text-[15px] whitespace-nowrap ${x === tab ? 'border-teal font-extrabold' : 'border-transparent font-medium hover:bg-paper'}`}
            >
              {t.tabs[x]}
            </button>
          ))}
        </nav>
        {tab === 'resources' && <Resources division={division} id={id} data={data} reload={loaded.reload} />}
        {tab === 'announcements' && <Announcements division={division} id={id} data={data} reload={loaded.reload} />}
        {tab === 'tasks' && <Tasks id={id} data={data} />}
      </div>
    </>
  )
}

function Resources({ division, id, data, reload }: { division: string; id: string; data: CoursePage; reload: () => void }) {
  const r = t.resources
  const blank = { section: '', title: '', url: '', shows_from: '', pinned: false, file: null as File | null }
  const [draft, setDraft] = useState(blank)
  const [busy, setBusy] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)
  const sections = [...new Set(data.resources.map((x) => x.section))]
  const add = async () => {
    setBusy(true)
    setProblem(null)
    const result = await classroomApi.addResource(division, id, draft)
    setBusy(false)
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: r.added })
      setDraft(blank)
      reload()
    } else setProblem(firstError(result, t.failed))
  }
  const remove = async (resource: string) => {
    if (!window.confirm(r.removeConfirm)) return
    const result = await classroomApi.removeResource(division, resource)
    if (result.kind === 'saved') reload()
    else slips.wrong({ word: t.notSaved, title: firstError(result, t.failed) })
  }

  return (
    <div className="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_400px]">
      <Sheet label={r.list}>
        {data.resources.length === 0 && <p className="px-4 py-4 text-text-muted">{r.none}</p>}
        {sections.map((s) => (
          <div key={s} className="grid">
            <div className="bg-field px-4 py-2 text-[14px] font-extrabold">{s}</div>
            {data.resources
              .filter((x) => x.section === s)
              .map((x) => (
                <div key={x.id} className="flex min-h-12 items-center justify-between gap-3 border-b border-rule px-4 py-2.5 last:border-b-0">
                  <span className="flex min-w-0 flex-col">
                    {x.kind === 'link' ? (
                      <a href={x.url ?? '#'} target="_blank" rel="noopener noreferrer">
                        {x.title}
                      </a>
                    ) : (
                      <a href={fileUrl(x.document_id ?? '')}>{x.title}</a>
                    )}
                    <span className="text-[13px] text-text-muted">
                      {[x.kind === 'link' ? r.link : `${x.name ?? ''} · ${size(x.size ?? 0)}`, x.shows_from !== null ? r.from(x.shows_from) : null].filter((v) => v !== null).join(' · ')}
                    </span>
                  </span>
                  <span className="flex items-center gap-2">
                    {x.pinned && <Tag tone="plain">{r.pinnedTag}</Tag>}
                    {data.teaches && (
                      <SmallButton tone="red" onClick={() => void remove(x.id)}>
                        {r.remove}
                      </SmallButton>
                    )}
                  </span>
                </div>
              ))}
          </div>
        ))}
      </Sheet>
      {data.teaches && (
        <Sheet label={r.add}>
          <h2 className="label-caps border-b border-rule px-4 py-2.5 text-text-muted">{r.add}</h2>
          <div className="grid gap-3 px-4 py-3">
            {problem !== null && (
              <Notice tone="wrong" title={t.notSaved}>
                {problem}
              </Notice>
            )}
            <Input label={r.section} value={draft.section} list="classroom-sections" onChange={(e) => setDraft({ ...draft, section: e.target.value })} />
            <datalist id="classroom-sections">
              {sections.map((s) => (
                <option key={s} value={s} />
              ))}
            </datalist>
            <Input label={r.title} value={draft.title} onChange={(e) => setDraft({ ...draft, title: e.target.value })} />
            <label className="grid gap-1">
              <span className="label-caps text-text-muted">{r.file}</span>
              <input type="file" onChange={(e) => setDraft({ ...draft, file: e.target.files?.[0] ?? null })} className="min-h-11 text-[14px]" />
            </label>
            {draft.file === null && <Input label={r.url} value={draft.url} placeholder={r.urlHint} onChange={(e) => setDraft({ ...draft, url: e.target.value })} />}
            <Input label={r.showsFrom} type="date" value={draft.shows_from} onChange={(e) => setDraft({ ...draft, shows_from: e.target.value })} />
            <label className="flex min-h-11 items-center gap-2.5">
              <input type="checkbox" className="size-5 accent-ink" checked={draft.pinned} onChange={(e) => setDraft({ ...draft, pinned: e.target.checked })} />
              {r.pinned}
            </label>
            <div>
              <Button busy={busy} onClick={() => void add()}>
                {r.save}
              </Button>
            </div>
            <p className="text-[13px] text-text-muted">{r.note}</p>
          </div>
        </Sheet>
      )}
    </div>
  )
}

function Announcements({ division, id, data, reload }: { division: string; id: string; data: CoursePage; reload: () => void }) {
  const a = t.announcements
  const [draft, setDraft] = useState({ title: '', body: '', sms: false })
  const [busy, setBusy] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)
  const post = async () => {
    setBusy(true)
    setProblem(null)
    const result = await classroomApi.announce(division, id, draft)
    setBusy(false)
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: a.done })
      setDraft({ title: '', body: '', sms: false })
      reload()
    } else setProblem(firstError(result, t.failed))
  }

  return (
    <div className="grid items-start gap-4 xl:grid-cols-2">
      {data.teaches && (
        <Sheet label={a.write(data.course.students)}>
          <h2 className="label-caps border-b border-rule px-4 py-2.5 text-text-muted">{a.write(data.course.students)}</h2>
          <div className="grid gap-3 px-4 py-3">
            {problem !== null && (
              <Notice tone="wrong" title={t.notSaved}>
                {problem}
              </Notice>
            )}
            <Input label={a.title} value={draft.title} onChange={(e) => setDraft({ ...draft, title: e.target.value })} />
            <label className="grid gap-1">
              <span className="label-caps text-text-muted">{a.body}</span>
              <textarea rows={5} value={draft.body} onChange={(e) => setDraft({ ...draft, body: e.target.value })} className="border-b-2 border-ink bg-field px-3 py-2 text-[15px]" />
            </label>
            <label className="flex min-h-11 items-center gap-2.5">
              <input type="checkbox" className="size-5 accent-ink" checked={draft.sms} onChange={(e) => setDraft({ ...draft, sms: e.target.checked })} />
              {a.sms}
            </label>
            <p className="text-[13px] text-text-muted">{a.smsNote}</p>
            <div>
              <Button busy={busy} onClick={() => void post()}>
                {a.post}
              </Button>
            </div>
          </div>
        </Sheet>
      )}
      <Sheet label={a.posted}>
        <h2 className="label-caps border-b border-rule px-4 py-2.5 text-text-muted">{a.posted}</h2>
        {data.announcements.length === 0 && <p className="px-4 py-4 text-text-muted">{a.none}</p>}
        {data.announcements.map((x) => (
          <div key={x.id} className="grid gap-1 border-b border-rule px-4 py-3 last:border-b-0">
            <span className="flex items-center justify-between gap-2">
              <strong>{x.title}</strong>
              <Tag tone={x.sms ? 'plain' : 'plain'}>{x.sms ? a.portalSms : a.portal}</Tag>
            </span>
            <span className="text-[13px] text-text-muted">{`${when(x.at)} · ${x.by} · ${a.read(x.read, data.course.students)}`}</span>
            <p className="m-0 text-[14px] whitespace-pre-line">{x.body}</p>
          </div>
        ))}
      </Sheet>
    </div>
  )
}

/** A task's state for the lecturer: released, not open yet, or how many are in. */
export function TaskTag({ task }: { task: TaskRow }) {
  if (task.released) return <Tag tone="done">{t.tasks.released}</Tag>
  if (new Date(task.shows_at) > new Date()) return <Tag tone="plain">{t.tasks.notOpen}</Tag>
  const c = task.counts
  const inNow = c.handed_in + c.late

  return <Tag tone={c.to_mark > 0 || inNow < c.registered ? 'attention' : 'done'}>{c.to_mark > 0 ? t.toMark(c.to_mark) : t.tasks.inCount(inNow, c.registered)}</Tag>
}

function Tasks({ id, data }: { id: string; data: CoursePage }) {
  const k = t.tasks
  const full = Math.abs(data.weights - data.share) < 0.01

  return (
    <div className="grid max-w-[900px] gap-3">
      <div className={`flex items-baseline gap-2.5 border px-3 py-2.5 ${full ? 'border-teal bg-teal-wash' : 'border-marigold bg-marigold-wash'}`}>
        <strong aria-hidden="true">{full ? '✓' : '!'}</strong>
        <span>{full ? k.weightsFull(data.share) : k.weights(data.weights, data.share)}</span>
      </div>
      <Sheet label={t.tabs.tasks}>
        {data.tasks.length === 0 && <p className="px-4 py-4 text-text-muted">{k.none}</p>}
        {data.tasks.map((x) => (
          <a
            key={x.id}
            href={`/classroom/t/${x.id}`}
            onClick={(e) => (e.preventDefault(), navigate(`/classroom/t/${x.id}`))}
            className="flex min-h-14 items-center justify-between gap-3 border-b border-rule px-4 py-3 text-ink no-underline last:border-b-0 hover:bg-paper"
          >
            <span className="flex min-w-0 flex-col">
              <strong>{x.title}</strong>
              <span className="text-[14px] text-text-muted">{`${t.kinds[x.kind] ?? x.kind} · ${k.due(when(x.due_at))} · ${k.outOf(x.out_of)} · ${k.weight(x.weight)}`}</span>
            </span>
            <TaskTag task={x} />
          </a>
        ))}
      </Sheet>
      {data.teaches && (
        <div>
          <Button onClick={() => navigate(`/classroom/c/${id}/task`)}>{k.set}</Button>
        </div>
      )}
    </div>
  )
}
