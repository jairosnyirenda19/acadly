import { navigate } from '@acadly/kernel'
import { useState } from 'react'
import { classFile, studentApi, type Me } from './api'
import { copy } from './copy'
import { Line, Loading, Page, Primary, Section, Tag, Title, useAnswer } from './parts'

const t = copy.classroom

const when = (iso: string) => new Date(iso).toLocaleString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }).replace(' at ', ', ')
const kb = (n: number) => (n >= 1024 * 1024 ? `${(n / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(n / 1024))} KB`)

/** The student's course pages, on My courses (D-104). */
export function CoursePages() {
  const loaded = useAnswer(studentApi.classCourses, 'class-courses')
  if (loaded.refused || loaded.data === undefined || loaded.data.courses.length === 0) return null

  return (
    <Section title={copy.courses.title}>
      {loaded.data.courses.map((c) => (
        <Line
          key={c.id}
          strong={`${c.code} · ${c.title}`}
          sub={c.lecturers.join(', ')}
          href={`/course/${c.id}`}
          right={c.due > 0 ? <Tag tone="attention">{`! ${c.due}`}</Tag> : c.new > 0 ? <Tag tone="ink">{t.newTag}</Tag> : undefined}
        />
      ))}
    </Section>
  )
}

/** Work due soon, on Today (the approved board). */
export function DueSoon() {
  const loaded = useAnswer(studentApi.due, 'due')
  if (loaded.refused || loaded.data === undefined || loaded.data.due.length === 0) return null

  return (
    <Section title={copy.dueSoon}>
      {loaded.data.due.map((d) => (
        <Line key={d.id} strong={`${d.code} · ${d.title}`} sub={when(d.due_at)} href={`/task/${d.id}`} right={<Tag tone={t.tones[d.state] ?? 'plain'}>{t.states[d.state] ?? d.state}</Tag>} />
      ))}
    </Section>
  )
}

/** A course in the portal (the approved board): its work, announcements, resources and marks. */
export function CoursePage({ me, id }: { me: Me; id: string }) {
  const loaded = useAnswer(() => studentApi.classCourse(id), `class-course:${id}`)
  const [tab, setTab] = useState<'work' | 'resources' | 'marks'>('work')
  const c = loaded.data
  const read = (announcement: string) => void studentApi.readAnnouncement(announcement)

  return (
    <Page me={me} tab="courses">
      {c === undefined ? (
        <Loading failed={loaded.failed} onRetry={loaded.reload} />
      ) : (
        <>
          <Title>{c.course.code}</Title>
          <p className="-mt-2 text-[14px] text-text-muted">{`${c.course.title} · ${c.course.lecturers.join(', ')}`}</p>
          <div role="tablist" className="flex border-b-2 border-ink">
            {(['work', 'resources', 'marks'] as const).map((x) => (
              <button
                key={x}
                type="button"
                role="tab"
                aria-selected={x === tab}
                onClick={() => setTab(x)}
                className={`min-h-11 flex-1 border-b-4 ${x === tab ? 'border-teal font-extrabold' : 'border-transparent'}`}
              >
                {t.tabs[x]}
              </button>
            ))}
          </div>
          {tab === 'work' && (
            <>
              <Section title={t.due}>
                {c.tasks.length === 0 && <p className="px-3.5 py-3 text-[14px] text-text-muted">{t.noWork}</p>}
                {c.tasks.map((x) => (
                  <Line
                    key={x.id}
                    strong={x.title}
                    sub={`${t.kinds[x.kind] ?? x.kind} · ${t.dueOn(when(x.due_at))} · ${t.weight(x.weight)}`}
                    href={`/task/${x.id}`}
                    right={<Tag tone={t.tones[x.state] ?? 'plain'}>{t.states[x.state] ?? x.state}</Tag>}
                  />
                ))}
              </Section>
              <Section title={t.announcements}>
                {c.announcements.length === 0 && <p className="px-3.5 py-3 text-[14px] text-text-muted">{t.noAnnouncements}</p>}
                {c.announcements.map((a) => (
                  <details key={a.id} className="border-b border-rule px-3.5 py-2.5 last:border-b-0" onToggle={(e) => (e.currentTarget.open && !a.read ? read(a.id) : undefined)}>
                    <summary className="flex min-h-10 cursor-pointer items-center justify-between gap-2">
                      <span className="flex flex-col">
                        <strong>{a.title}</strong>
                        <span className="text-[13px] text-text-muted">{`${when(a.at)} · ${a.by}`}</span>
                      </span>
                      {!a.read && <Tag tone="ink">{t.newTag}</Tag>}
                    </summary>
                    <p className="mt-2 mb-0 text-[14px] whitespace-pre-line">{a.body}</p>
                  </details>
                ))}
              </Section>
              {c.coming.length > 0 && (
                <Section title={t.coming}>
                  {c.coming.map((x) => (
                    <Line key={x.id} strong={x.title} sub={t.shows(when(x.shows_at))} right={<Tag tone="plain">{t.notOpen}</Tag>} />
                  ))}
                </Section>
              )}
            </>
          )}
          {tab === 'resources' && (
            <Section>
              {c.resources.length === 0 && <p className="px-3.5 py-3 text-[14px] text-text-muted">{t.noResources}</p>}
              {[...new Set(c.resources.map((r) => r.section))].map((s) => (
                <div key={s}>
                  <div className="bg-field px-3.5 py-2 text-[14px] font-extrabold">{s}</div>
                  {c.resources
                    .filter((r) => r.section === s)
                    .map((r) => (
                      <a
                        key={r.id}
                        href={r.kind === 'link' ? (r.url ?? '#') : classFile(r.document_id ?? '')}
                        target={r.kind === 'link' ? '_blank' : undefined}
                        rel="noopener noreferrer"
                        className="flex min-h-12 items-center justify-between gap-2 border-b border-rule px-3.5 py-2.5 text-ink no-underline last:border-b-0 hover:bg-field"
                      >
                        <strong>{r.title}</strong>
                        <span className="text-[13px] text-text-muted">{r.pinned ? t.pinned : r.kind === 'link' ? t.link : ''}</span>
                      </a>
                    ))}
                </div>
              ))}
            </Section>
          )}
          {tab === 'marks' && (
            <>
              <div className="grid grid-cols-1 border-2 border-ink">
                <div className="bg-ink px-3.5 py-2.5 text-sheet">
                  <span className="label-caps text-on-ink-muted">{t.caSoFar}</span>
                  <div className="type-display text-[40px] leading-none text-teal-bright">{`${c.ca} / ${c.share}`}</div>
                  <span className="text-[13px] text-on-ink-soft">{t.caOf(c.share)}</span>
                </div>
              </div>
              <Section>
                {c.tasks.filter((x) => x.released).length === 0 && <p className="px-3.5 py-3 text-[14px] text-text-muted">{t.noMarks}</p>}
                {c.tasks
                  .filter((x) => x.released)
                  .map((x) => (
                    <Line key={x.id} strong={x.title} sub={`${x.mark ?? 0} / ${x.out_of} · ${t.weight(x.weight)}`} href={`/task/${x.id}`} />
                  ))}
              </Section>
            </>
          )}
        </>
      )}
    </Page>
  )
}

/** One assignment or exam paper (the approved boards): hand in, withdraw, history, and the mark once released. */
export function TaskPage({ me, id }: { me: Me; id: string }) {
  const loaded = useAnswer(() => studentApi.classTask(id), `class-task:${id}`)
  const [files, setFiles] = useState<File[]>([])
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState<string | null>(null)
  const d = loaded.data

  const handIn = async () => {
    setBusy(true)
    setMessage(null)
    const result = await studentApi.handIn(id, files)
    setBusy(false)
    if (result.kind === 'saved') {
      setFiles([])
      loaded.reload()
    } else setMessage(result.kind === 'invalid' && result.message !== '' ? result.message : t.failed)
  }
  const withdraw = async () => {
    if (!window.confirm(t.withdrawConfirm)) return
    const result = await studentApi.withdraw(id)
    if (result.kind === 'saved') loaded.reload()
    else setMessage(result.kind === 'invalid' && result.message !== '' ? result.message : t.failed)
  }

  return (
    <Page me={me} tab="courses">
      {d === undefined ? (
        <Loading failed={loaded.failed} onRetry={loaded.reload} />
      ) : (
        <>
          <a href={`/course/${d.course.id}`} onClick={(e) => (e.preventDefault(), navigate(`/course/${d.course.id}`))} className="text-[14px]">
            {`‹ ${d.course.code}`}
          </a>
          <Title>{d.task.title}</Title>
          {d.mark !== null ? (
            <>
              <div className="grid grid-cols-2 border-2 border-ink">
                <div className="bg-ink px-3.5 py-2.5 text-sheet">
                  <span className="label-caps text-on-ink-muted">{t.yourMark}</span>
                  <div className="type-display text-[36px] leading-none text-teal-bright">{`${d.mark.mark} / ${d.task.out_of}`}</div>
                </div>
                <div className="border-l-2 border-ink bg-sheet px-3.5 py-2.5">
                  <span className="label-caps text-text-muted">{t.countsFor}</span>
                  <div className="type-display text-[36px] leading-none">{`${Math.round((d.mark.mark / d.task.out_of) * d.task.weight * 10) / 10}%`}</div>
                </div>
              </div>
              {d.mark.was !== null && <p className="text-[13px] text-text-muted">{t.changed(d.mark.was, d.mark.reason)}</p>}
              <Section title={t.feedback(d.mark.by)}>
                {d.mark.feedback !== null && <p className="m-0 px-3.5 py-2.5 text-[14px] whitespace-pre-line">{d.mark.feedback}</p>}
                {d.mark.files.map((f) => (
                  <Line key={f.id} strong={f.name} sub={kb(f.size)} right={<a href={classFile(f.id)} className="font-bold">{t.open_}</a>} />
                ))}
              </Section>
              <p className="text-[13px] text-text-muted">{t.released}</p>
            </>
          ) : d.state === 'handed_in' ? (
            <div className="flex items-baseline gap-2.5 border border-teal bg-teal-wash px-3 py-2.5">
              <strong aria-hidden="true">✓</strong>
              <span>
                <b>{t.handedInTitle}.</b> {t.handedIn(when(d.history[0]?.at ?? d.task.due_at), when(d.task.due_at))}
              </span>
            </div>
          ) : d.state === 'locked' || d.state === 'late' ? (
            <Line strong={d.state === 'late' ? t.lateTitle : t.lockedTitle} sub={t.lockedNote} right={<Tag tone={t.tones[d.state] ?? 'plain'}>{t.states[d.state] ?? d.state}</Tag>} />
          ) : d.state === 'missing' ? (
            <Line strong={t.missingTitle} sub={t.missingNote} right={<Tag tone="wrong">{t.states.missing}</Tag>} />
          ) : (
            <div className="flex items-baseline gap-2.5 border border-marigold bg-marigold-wash px-3 py-2.5">
              <strong aria-hidden="true">!</strong>
              <span>
                {d.state === 'late_open' && d.task.late_until !== null ? t.lateNote(when(d.task.late_until)) : t.open(when(d.task.due_at), t.daysLeft(Math.floor((new Date(d.task.due_at).getTime() - Date.now()) / 86400000)))}{' '}
                {t.outOf(d.task.out_of, d.task.weight)}
              </span>
            </div>
          )}
          {d.task.instructions !== '' && (
            <Section title={t.instructions}>
              <p className="m-0 px-3.5 py-2.5 text-[14px] whitespace-pre-line">{d.task.instructions}</p>
              {d.task.files.map((f) => (
                <Line key={f.id} strong={f.name} sub={kb(f.size)} right={<a href={classFile(f.id)} className="font-bold">{t.open_}</a>} />
              ))}
            </Section>
          )}
          {d.files.length > 0 && (
            <Section title={t.what}>
              {d.files.map((f) => (
                <Line key={f.id} strong={f.name} sub={kb(f.size)} right={<a href={classFile(f.id)} className="font-bold">{t.open_}</a>} />
              ))}
            </Section>
          )}
          {d.can_withdraw && (
            <button type="button" onClick={() => void withdraw()} className="h-12 border-2 border-ink bg-sheet font-extrabold">
              {t.withdraw}
            </button>
          )}
          {d.can_hand_in && (
            <Section title={t.yourHandIn}>
              <div className="grid gap-2.5 px-3.5 py-3">
                <label className="grid cursor-pointer gap-1 border-2 border-dashed border-rule px-4 py-4 text-center text-[14px] text-text-muted">
                  {t.choose}
                  <span className="text-[12px]">{t.takes(d.task.accepts.map((k) => t.types[k] ?? k).join(', '), d.task.max_files, d.task.max_mb)}</span>
                  <input type="file" multiple={d.task.max_files > 1} className="sr-only" onChange={(e) => setFiles([...files, ...Array.from(e.target.files ?? [])].slice(0, d.task.max_files))} />
                </label>
                {files.map((f, i) => (
                  <Line
                    key={`${f.name}${i}`}
                    strong={f.name}
                    sub={kb(f.size)}
                    right={
                      <button type="button" onClick={() => setFiles(files.filter((_, j) => j !== i))} className="font-bold text-red-text">
                        {t.remove}
                      </button>
                    }
                  />
                ))}
              </div>
            </Section>
          )}
          {message !== null && (
            <div role="alert" className="flex items-baseline gap-2.5 border border-red-pen bg-red-wash px-3 py-2.5 text-red-text">
              <strong aria-hidden="true">×</strong>
              <span>{message}</span>
            </div>
          )}
          {d.can_hand_in && (
            <>
              <Primary blocked={files.length === 0} busy={busy} onClick={() => void handIn()}>
                {d.state === 'late_open' ? t.handInLate : t.handIn}
              </Primary>
              <p className="text-[13px] text-text-muted">{d.task.kind === 'exam' ? t.examNote : t.handInNote}</p>
            </>
          )}
          {d.history.length > 0 && (
            <Section title={t.history}>
              {d.history.map((h, i) => (
                <Line
                  key={i}
                  strong={h.action === 'hand_in' ? t.handedInWord : t.withdrawnWord}
                  sub={[when(h.at), h.files.join(', ')].filter((x) => x !== '').join(' · ')}
                  right={h.late ? <Tag tone="attention">{t.states.late}</Tag> : undefined}
                />
              ))}
            </Section>
          )}
        </>
      )}
    </Page>
  )
}
