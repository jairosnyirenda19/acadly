import { navigate, signOut } from '@acadly/kernel'
import { useState } from 'react'
import { day, money, studentApi, type Me, type Registration } from './api'
import { CoursePages, DueSoon } from './Classroom'
import { LecturesNow } from './Lectures'
import { BarWarning, ResultsEntry, StandingEntries } from './Results'
import { copy } from './copy'
import { Field, Line, Loading, Page, Primary, Section, Tag, Title, useAnswer } from './parts'

const longDay = (d: Date) => d.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long' })

/** Today (the approved boards): the lecture open for scanning and the day's lectures, registration, fees, the newest news. */
export function TodayPage({ me }: { me: Me }) {
  const t = copy.today
  const reg = useAnswer(studentApi.registration, 'reg')
  const fees = useAnswer(studentApi.fees, 'fees')
  const news = useAnswer(studentApi.news, 'news')
  const r = reg.data?.registration ?? null
  const f = fees.data?.fees ?? null
  const next = f?.instalments.find((i) => i.state !== 'paid')

  return (
    <Page me={me} tab="today">
      <Title>{t.title(longDay(new Date()))}</Title>
      <BarWarning />
      <LecturesNow />
      <DueSoon />
      {r !== null && (
        <Section title={`${t.registration} · ${r.period}`}>
          {r.editable ? (
            <div className="grid gap-2.5 px-3.5 py-3">
              <div className="flex items-center justify-between gap-2">
                <strong>{t.chooseOpen}</strong>
                {r.window !== null && <Tag tone="attention">{t.openUntil(day(r.window.closes_on))}</Tag>}
              </div>
              <span className="text-[14px] text-text-muted">{t.openNote}</span>
              <Primary onClick={() => navigate('/registration')}>{t.openRegistration}</Primary>
            </div>
          ) : (
            <Line strong={t.states[r.state] ?? r.state} sub={`${r.courses.length} · ${r.courses.reduce((s, c) => s + c.credits, 0)} credits`} href="/registration" right={<Tag tone={r.state === 'registered' ? 'done' : 'plain'}>{t.states[r.state] ?? r.state}</Tag>} />
          )}
        </Section>
      )}
      {f !== null && (
        <Section title={t.fees}>
          <Line
            strong={f.left > 0 ? t.toPay(money(f.left)) : t.paidUp}
            sub={next !== undefined ? t.nextDue(money(next.amount - next.covered), day(next.due_on)) : undefined}
            href="/fees"
            right={f.due_now > 0 ? <Tag tone="attention">{`! ${money(f.due_now)}`}</Tag> : undefined}
          />
        </Section>
      )}
      <Section title={t.news}>
        {(news.data?.posts ?? []).length === 0 ? (
          <p className="px-3.5 py-3 text-[14px] text-text-muted">{t.noNews}</p>
        ) : (
          (news.data?.posts ?? []).slice(0, 3).map((p) => <Line key={p.id} strong={p.title} sub={`${p.audience_label} · ${day(p.publish_at)}`} href={`/news/${p.id}`} right={!p.read ? <Tag tone="ink">{t.new}</Tag> : undefined} />)
        )}
      </Section>
    </Page>
  )
}

export function CoursesPage({ me }: { me: Me }) {
  const t = copy.courses
  const loaded = useAnswer(studentApi.courses, 'courses')
  const c = loaded.data

  return (
    <Page me={me} tab="courses">
      <Title>{t.title}</Title>
      {c === undefined ? (
        <Loading failed={loaded.failed} onRetry={loaded.reload} />
      ) : c.courses.length === 0 ? (
        <p className="text-text-muted">{t.none}</p>
      ) : (
        <>
          <div className="grid grid-cols-3 gap-0.5 border-2 border-ink bg-ink">
            {[
              [String(c.courses.length), t.courses],
              [String(c.credits), t.credits],
              [copy.today.states[c.state ?? ''] ?? '', copy.registration.state],
            ].map(([n, l], i) => (
              <span key={l} className={`px-3 py-2.5 ${i === 0 ? 'bg-ink text-sheet' : 'bg-sheet'}`}>
                <b className={`type-numeral block text-[26px] leading-[0.9] ${i === 0 ? 'text-accent' : ''}`}>{n}</b>
                <span className="text-[12px]">{l}</span>
              </span>
            ))}
          </div>
          <Section title={c.semester ?? ''}>
            {c.courses.map((x) => (
              <Line key={x.code} strong={`${x.code} · ${x.title}`} sub={`${x.credits} credits · ${x.lecturers.length > 0 ? x.lecturers.join(', ') : t.noLecturer}`} right={<Tag tone={x.kind === 'core' ? 'plain' : 'done'}>{x.kind === 'core' ? t.core : t.elective}</Tag>} />
            ))}
          </Section>
          <CoursePages />
          <ResultsEntry />
          <Section>
            <Line strong={copy.more.attendance} href="/attendance" />
          </Section>
          <p className="text-[13px] text-text-muted">{t.note}</p>
        </>
      )}
    </Page>
  )
}

/** Registration (the approved board): core courses in already, the electives to choose, the checks, and sending it. */
export function RegistrationPage({ me }: { me: Me }) {
  const t = copy.registration
  const loaded = useAnswer(studentApi.registration, 'registration')
  const r = loaded.data?.registration ?? null

  return (
    <Page me={me} tab="more">
      <Title>{t.title}</Title>
      {loaded.data === undefined ? <Loading failed={loaded.failed} onRetry={loaded.reload} /> : r === null ? <p className="text-text-muted">{t.none}</p> : <Chooser key={`${r.id}:${r.version}:${r.state}`} r={r} onDone={loaded.reload} />}
    </Page>
  )
}

function Chooser({ r, onDone }: { r: Registration; onDone: () => void }) {
  const t = copy.registration
  const chosen = new Set(r.courses.map((c) => c.offering_id))
  const [picked, setPicked] = useState<Set<string>>(chosen)
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState<{ tone: 'done' | 'wrong'; text: string } | null>(null)
  const byCourse = (courseId: string) => r.offerings.filter((o) => o.course.id === courseId)
  const coreOfferings = r.core.flatMap((id) => byCourse(id).slice(0, 1))
  const submit = r.steps.find((s) => s.step === 'submit_own')
  const changed = [...picked].sort().join() !== [...chosen].sort().join()
  const fees = r.fees

  const toggle = (groupCourses: string[], offeringId: string, choose: number) => {
    const next = new Set(picked)
    if (next.has(offeringId)) next.delete(offeringId)
    else {
      if (choose === 1) for (const o of r.offerings.filter((x) => groupCourses.includes(x.course.id))) next.delete(o.id)
      next.add(offeringId)
    }
    setPicked(next)
  }
  const save = async (): Promise<boolean> => {
    const all = new Set([...coreOfferings.map((o) => o.id), ...picked])
    const result = await studentApi.saveCourses([...all])
    if (result.kind !== 'saved') setMessage({ tone: 'wrong', text: result.kind === 'invalid' ? result.message : copy.failed })

    return result.kind === 'saved'
  }
  const send = async () => {
    setBusy(true)
    setMessage(null)
    if (changed && !(await save())) return setBusy(false)
    const result = await studentApi.submit()
    setBusy(false)
    if (result.kind === 'saved') {
      setMessage({ tone: 'done', text: t.sent })
      onDone()
    } else setMessage({ tone: 'wrong', text: result.kind === 'invalid' ? result.message : copy.failed })
  }

  return (
    <>
      {message !== null && (
        <p role="status" className={`border-2 px-3 py-2 ${message.tone === 'done' ? 'border-teal bg-teal-wash' : 'border-red-pen bg-red-wash text-red-text'}`}>
          {message.text}
        </p>
      )}
      <Section title={`${t.state} · ${r.period}`}>
        <Line strong={copy.today.states[r.state] ?? r.state} sub={`${r.courses.length} · ${r.courses.reduce((s, c) => s + c.credits, 0)} credits`} right={r.state === 'registered' ? <a className="font-bold text-teal underline" href="/api/v1/student/registration/slip" target="_blank" rel="noreferrer">{t.slip}</a> : undefined} />
      </Section>
      <Section title={t.core}>
        <p className="px-3.5 py-2.5 text-[14px]">{r.core.map((id) => byCourse(id)[0]?.course.code ?? '').filter((x) => x !== '').join(' · ')}</p>
      </Section>
      {r.groups.map((g) => (
        <Section key={g.id} title={t.choose(g.label, g.choose)}>
          {g.courses.flatMap((id) => byCourse(id)).map((o) => {
            const on = picked.has(o.id)
            const full = o.seats_left === 0 && !on

            return (
              <label key={o.id} className={`flex min-h-12 items-center gap-2.5 border-b border-rule px-3.5 py-2.5 last:border-b-0 ${on ? 'border-l-[6px] border-l-ink bg-field pl-2' : ''}`}>
                <input
                  type={g.choose === 1 ? 'radio' : 'checkbox'}
                  name={g.id}
                  className="size-5 accent-ink"
                  checked={on}
                  disabled={!r.editable || full}
                  onChange={() => toggle(g.courses, o.id, g.choose)}
                />
                <span className="flex flex-1 flex-col">
                  <strong>{`${o.course.code} · ${o.course.title}`}</strong>
                  <span className="text-[14px] text-text-muted">{`${t.credits(o.credits)}${o.seats_left !== null ? ` · ${o.seats_left === 0 ? t.full : t.seatsLeft(o.seats_left)}` : ''}`}</span>
                </span>
              </label>
            )
          })}
        </Section>
      ))}
      {r.editable && <Section title={t.checks}>
        {r.checks.problems.map((p, i) => (
          <Line key={i} strong={p.text} right={<Tag tone="attention">!</Tag>} />
        ))}
        <Line strong={t.load(r.checks.load, r.checks.min, r.checks.max)} right={<Tag tone={r.checks.load >= r.checks.min && r.checks.load <= r.checks.max ? 'done' : 'attention'}>{r.checks.load >= r.checks.min && r.checks.load <= r.checks.max ? '✓' : '!'}</Tag>} />
        {fees.known && <Line strong={fees.cleared ? t.feesOk : t.fees} sub={fees.cleared ? undefined : t.feesMore(money(fees.more))} right={<Tag tone={fees.cleared ? 'done' : 'attention'}>{fees.cleared ? '✓' : '!'}</Tag>} />}
      </Section>}
      {r.editable ? (
        <>
          {changed && (
            <button type="button" className="h-11 border-2 border-ink font-extrabold" onClick={() => void save().then((ok) => ok && onDone())}>
              {t.save}
            </button>
          )}
          <Primary busy={busy} blocked={submit === undefined || (!changed && !submit.permitted)} onClick={() => void send()}>
            {t.send}
          </Primary>
          {submit !== undefined && !submit.permitted && !changed && submit.blocked_reason !== null && <p className="text-[13px] text-text-muted">{`${t.blocked} ${submit.blocked_reason}`}</p>}
        </>
      ) : (
        r.state === 'draft' && <p className="text-text-muted">{t.closed}</p>
      )}
    </>
  )
}

export function FeesPage({ me }: { me: Me }) {
  const t = copy.fees
  const loaded = useAnswer(studentApi.fees, 'fees')
  const reg = useAnswer(studentApi.registration, 'reg')
  const f = loaded.data?.fees ?? null
  const registered = reg.data?.registration?.state === 'registered'

  return (
    <Page me={me} tab="fees">
      <Title>{t.title}</Title>
      {loaded.data === undefined ? (
        <Loading failed={loaded.failed} onRetry={loaded.reload} />
      ) : f === null ? (
        <p className="text-text-muted">{t.none}</p>
      ) : (
        <>
          <div className="flex items-end justify-between gap-3 bg-ink px-3.5 py-3 text-sheet">
            <span>
              <span className="label-caps block text-on-ink-muted">{t.toPay}</span>
              <b className="type-numeral text-[40px] leading-[0.9] text-accent">{money(f.left)}</b>
            </span>
            <span className="text-right text-[13px] text-on-ink-soft">
              {t.billed(money(f.billed))}
              <br />
              {t.paid(money(f.paid))}
            </span>
          </div>
          <Section title={t.instalments}>
            {f.instalments.map((i, n) => (
              <Line key={n} strong={i.label} sub={`${day(i.due_on)} · ${money(i.amount)}`} right={<Tag tone={i.state === 'paid' ? 'done' : i.state === 'overdue' ? 'attention' : i.state === 'part' ? 'attention' : 'plain'}>{t.states[i.state] ?? i.state}</Tag>} />
            ))}
          </Section>
          <Section title={t.payments}>
            {f.payments.length === 0 ? <p className="px-3.5 py-3 text-[14px] text-text-muted">{t.noPayments}</p> : f.payments.map((p) => <Line key={p.number} strong={p.number} sub={day(p.received_on)} right={<b className="tabular-nums">{money(p.amount)}</b>} />)}
          </Section>
        </>
      )}
      <Section title={t.documents}>
        {f !== null && <Line strong={t.statement} sub={t.statementNote} href="/api/v1/student/fees/statement" onClick={() => window.open('/api/v1/student/fees/statement', '_blank', 'noopener')} right={<Tag tone="plain">{t.open}</Tag>} />}
        {registered && <Line strong={t.slip} sub={t.slipNote} href="/api/v1/student/registration/slip" onClick={() => window.open('/api/v1/student/registration/slip', '_blank', 'noopener')} right={<Tag tone="plain">{t.open}</Tag>} />}
      </Section>
      <p className="text-[13px] text-text-muted">{t.note}</p>
    </Page>
  )
}

export function NewsPage({ me }: { me: Me }) {
  const t = copy.news
  const loaded = useAnswer(studentApi.news, 'news')
  const posts = loaded.data?.posts

  return (
    <Page me={me} tab="news">
      <Title>{t.title}</Title>
      {posts === undefined ? (
        <Loading failed={loaded.failed} onRetry={loaded.reload} />
      ) : posts.length === 0 ? (
        <p className="text-text-muted">{t.none}</p>
      ) : (
        <Section>
          {posts.map((p) => (
            <Line
              key={p.id}
              strong={p.title}
              sub={`${p.author} · ${p.audience_label} · ${day(p.publish_at)}`}
              href={`/news/${p.id}`}
              right={<span className="flex gap-1">{p.pinned && <Tag tone="plain">{t.pinned}</Tag>}{!p.read && <Tag tone="ink">{copy.today.new}</Tag>}</span>}
            />
          ))}
        </Section>
      )}
    </Page>
  )
}

export function ArticlePage({ me, id }: { me: Me; id: string }) {
  const t = copy.news
  const loaded = useAnswer(() => studentApi.post(id), id)
  const p = loaded.data?.post

  return (
    <Page me={me} tab="news">
      <a href="/news" onClick={(e) => (e.preventDefault(), navigate('/news'))} className="min-h-11 font-bold text-teal">
        {t.back}
      </a>
      {p === undefined ? (
        loaded.refused ? <p className="text-text-muted">{t.none}</p> : <Loading failed={loaded.failed} onRetry={loaded.reload} />
      ) : (
        <article className="grid gap-3">
          <span className="label-caps text-text-muted">{`${p.author} · ${longDay(new Date(p.publish_at))}`}</span>
          <h1 className="type-display text-[34px] leading-[0.92] uppercase">{p.title}</h1>
          <span className="flex gap-1.5">
            <Tag tone="plain">{p.audience_label}</Tag>
            {p.pinned && <Tag tone="ink">{t.pinned}</Tag>}
            {p.updated && <Tag tone="plain">{t.updated}</Tag>}
          </span>
          <p className="whitespace-pre-line">{p.body}</p>
        </article>
      )}
    </Page>
  )
}

export function MorePage({ me, onSignedOut }: { me: Me; onSignedOut: () => void }) {
  const t = copy.more

  return (
    <Page me={me} tab="more">
      <Title>{t.title}</Title>
      <Section>
        <Line strong={t.week} href="/week" />
        <Line strong={t.attendance} href="/attendance" />
        <Line strong={t.registration} href="/registration" />
        <Line strong={t.phone} href="/phone" />
        <Line strong={t.account} href="/account" />
      </Section>
      <ResultsEntry />
      <StandingEntries />
      <button type="button" className="h-11 border-2 border-ink font-extrabold" onClick={() => void signOut().then(onSignedOut)}>
        {t.signOut}
      </button>
    </Page>
  )
}

export function AccountPage({ me, onSignedOut }: { me: Me; onSignedOut: () => void }) {
  const t = copy.account
  const [open, setOpen] = useState(false)
  const [current, setCurrent] = useState('')
  const [next, setNext] = useState('')
  const [message, setMessage] = useState<{ tone: 'done' | 'wrong'; text: string } | null>(null)
  const change = async () => {
    const result = await studentApi.password(current, next)
    if (result.kind === 'saved') {
      setMessage({ tone: 'done', text: t.passwordSaved })
      setOpen(false)
      setCurrent('')
      setNext('')
    } else setMessage({ tone: 'wrong', text: result.kind === 'invalid' ? result.message : t.passwordFailed })
  }

  return (
    <Page me={me} tab="more">
      <Title>{t.title}</Title>
      {message !== null && (
        <p role="status" className={`border-2 px-3 py-2 ${message.tone === 'done' ? 'border-teal bg-teal-wash' : 'border-red-pen bg-red-wash text-red-text'}`}>
          {message.text}
        </p>
      )}
      <Section title={t.who}>
        <div className="grid gap-0.5 px-3.5 py-2.5">
          <strong>{me.name}</strong>
          <span className="text-[14px] text-text-muted">{`${me.number} · ${me.programme.name} · Year ${me.year}`}</span>
          <span className="text-[13px] text-text-muted">{t.keptBy}</span>
        </div>
      </Section>
      <Section title={t.signingIn}>
        <Line strong={t.email} sub={me.email ?? t.noEmail} />
        <Line strong={t.phone} sub={me.phone ?? t.noPhone} />
        <Line strong={t.password} right={<button type="button" className="font-bold text-teal underline" onClick={() => setOpen(!open)}>{t.change}</button>} />
      </Section>
      {open && (
        <Section>
          <div className="grid gap-3 px-3.5 py-3">
            <Field label={t.current} type="password" value={current} onChange={(e) => setCurrent(e.target.value)} autoComplete="current-password" />
            <Field label={t.next} hint={copy.setUp.passwordHint} type="password" value={next} onChange={(e) => setNext(e.target.value)} autoComplete="new-password" />
            <Primary onClick={() => void change()}>{t.savePassword}</Primary>
          </div>
        </Section>
      )}
      <button type="button" className="h-11 border-2 border-ink font-extrabold" onClick={() => void signOut().then(onSignedOut)}>
        {t.signOut}
      </button>
    </Page>
  )
}
