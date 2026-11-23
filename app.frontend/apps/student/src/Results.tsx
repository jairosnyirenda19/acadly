import { navigate } from '@acadly/kernel'
import { useState, type ReactNode } from 'react'
import { resultsSlip, studentApi, type CourseResult as Course, type Me, type Results, type Semester } from './api'
import { copy } from './copy'
import { Line, Loading, Page, Section, Tag, Title, useAnswer } from './parts'

const t = copy.results

const gpa = (n: number) => n.toFixed(2)
const one = (n: number) => (Math.round(n * 10) / 10).toFixed(1)
const when = (iso: string) => new Date(iso).toLocaleString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }).replace(' at ', ', ')

/** A notice with its mark: teal for done, marigold for attention, red for barred or failed. */
function Notice({ tone, mark, children }: { tone: 'done' | 'attention' | 'wrong'; mark: string; children: ReactNode }) {
  const cls = { done: 'border-teal bg-teal-wash', attention: 'border-marigold bg-marigold-wash', wrong: 'border-red-pen bg-red-wash text-red-text' }[tone]

  return (
    <div role={tone === 'done' ? 'status' : 'alert'} className={`flex items-baseline gap-2.5 border px-3 py-2.5 ${cls}`}>
      <strong aria-hidden="true">{mark}</strong>
      <span>{children}</span>
    </div>
  )
}

/** Two totals side by side: the first on ink, the second on the sheet. */
function Totals({ first, second }: { first: [string, string]; second: [string, string] }) {
  return (
    <div className="grid grid-cols-2 border-2 border-ink">
      <div className="bg-ink px-3.5 py-2.5 text-sheet">
        <span className="label-caps text-on-ink-muted">{first[0]}</span>
        <div className="type-numeral text-[40px] leading-none text-accent">{first[1]}</div>
      </div>
      <div className="border-l-2 border-ink bg-sheet px-3.5 py-2.5">
        <span className="label-caps text-text-muted">{second[0]}</span>
        <div className="type-numeral text-[40px] leading-none">{second[1]}</div>
      </div>
    </div>
  )
}

function Back({ href, children }: { href: string; children: ReactNode }) {
  return (
    <a href={href} onClick={(e) => (e.preventDefault(), navigate(href))} className="min-h-11 text-[14px] font-bold text-teal">
      {children}
    </a>
  )
}

/** The way to Results, on My courses and More: shown only when the college keeps results here. */
export function ResultsEntry() {
  const loaded = useAnswer(studentApi.results, 'results')
  if (loaded.refused || loaded.data === undefined) return null
  const r = loaded.data
  const latest = r.semesters[0]
  const sub = r.supplementary.length > 0 ? undefined : latest?.gpa != null ? t.entryGpa(gpa(latest.gpa)) : t.entryNone

  return (
    <Section>
      <Line strong={t.entry} sub={sub} href="/results" right={r.supplementary.length > 0 ? <Tag tone="attention">{t.entrySupp(r.supplementary.length)}</Tag> : undefined} />
    </Section>
  )
}

/** The grade at the right of a course row: the grade alone for a pass, a word and mark for anything else. */
function CourseState({ c }: { c: Semester['courses'][number] }) {
  if (!c.released) return <Tag tone="plain">{t.notReleased}</Tag>
  if (c.result === 'pass') return <b className="tabular-nums">{c.grade}</b>
  const word = t.states[c.result] ?? c.result

  return <Tag tone={t.tones[c.result] ?? 'plain'}>{c.grade !== null && c.result !== 'barred' ? `${word} · ${c.grade}` : word}</Tag>
}

/** Results (the approved boards): the semester's courses, the GPA, the decision, exams to sit and the slip. */
export function ResultsPage({ me }: { me: Me }) {
  const loaded = useAnswer(studentApi.results, 'results')
  const [chosen, setChosen] = useState(0)
  const r = loaded.data

  return (
    <Page me={me} tab="courses">
      <Back href="/courses">{`‹ ${copy.courses.title}`}</Back>
      <Title>{t.title}</Title>
      {r === undefined ? (
        loaded.refused ? <p className="text-text-muted">{t.none}</p> : <Loading failed={loaded.failed} onRetry={loaded.reload} />
      ) : r.semesters.length === 0 && r.supplementary.length === 0 ? (
        <p className="text-text-muted">{t.none}</p>
      ) : (
        <SemesterView r={r} index={Math.min(chosen, Math.max(0, r.semesters.length - 1))} onChoose={setChosen} />
      )}
    </Page>
  )
}

function SemesterView({ r, index, onChoose }: { r: Results; index: number; onChoose: (i: number) => void }) {
  const s = r.semesters[index]
  const latest = index === 0
  const supp = r.supplementary.filter((x) => x.kind === 'supplementary')
  const deferred = r.supplementary.filter((x) => x.kind !== 'supplementary')
  const passed = s?.courses.filter((c) => c.result === 'pass') ?? []
  // The result that sends a course to the supplementary sitting, newest semester first.
  const before = (code: string) => r.semesters.flatMap((x) => x.courses).find((c) => c.code === code && c.result !== 'pass')

  return (
    <>
      {r.semesters.length > 1 && (
        <div role="tablist" aria-label={t.semesters} className="flex overflow-x-auto border-b-2 border-ink">
          {r.semesters.map((x, i) => (
            <button
              key={x.period.id}
              type="button"
              role="tab"
              aria-selected={i === index}
              onClick={() => onChoose(i)}
              className={`min-h-11 shrink-0 border-b-4 px-3 whitespace-nowrap ${i === index ? 'border-teal font-extrabold' : 'border-transparent'}`}
            >
              {x.period.name}
            </button>
          ))}
        </div>
      )}
      {latest && r.supplementary.length > 0 ? (
        <Notice tone="attention" mark="!">
          <strong>{supp.length > 0 ? t.suppTitle(supp.length) : t.deferredTitle(deferred.length)}</strong>{' '}
          {supp.length > 0 && deferred.length > 0 && <strong>{t.deferredTitle(deferred.length)} </strong>}
          {t.suppNote(s?.gpa != null ? gpa(s.gpa) : null)}
        </Notice>
      ) : (
        latest && s !== undefined && s.decision === 'pass' && <Notice tone="done" mark="✓"><strong>{t.out(s.period.name)}</strong></Notice>
      )}
      {s !== undefined && (
        <Totals first={[t.gpa, s.gpa !== null ? gpa(s.gpa) : t.noMark]} second={[t.passed, `${s.earned}/${s.credits}`]} />
      )}
      {latest && r.supplementary.length > 0 && (
        <>
          <Section title={t.toSit}>
            {r.supplementary.map((x) => {
              const was = before(x.code)
              const detail = x.kind === 'deferred' ? t.deferredWas : was?.result === 'barred' ? t.barredWas : was?.mark != null ? t.markWas(was.mark) : null

              return (
                <Line
                  key={`${x.code}:${x.kind}`}
                  strong={`${x.code} · ${x.title}`}
                  sub={[t.kinds[x.kind] ?? x.kind, x.exam_on !== null ? when(x.exam_on) : t.dateToSet, detail].filter((v) => v !== null).join(' · ')}
                  right={
                    x.kind === 'deferred' ? (
                      <Tag tone="attention">{t.states.deferred}</Tag>
                    ) : (
                      <Tag tone="wrong">{was?.result === 'barred' ? t.states.barred : `× ${was?.grade ?? t.states.fail}`}</Tag>
                    )
                  }
                />
              )
            })}
          </Section>
          <Section title={t.whatHappens}>
            <div className="grid gap-1.5 px-3.5 py-2.5 text-[14px]">
              {supp.length > 0 && t.suppRules.map((rule) => <span key={rule}>{rule}</span>)}
              {deferred.length > 0 && <span>{t.deferredRule}</span>}
            </div>
          </Section>
          {passed.length > 0 && (
            <Section title={t.passedSection}>
              <Line strong={passed.map((c) => c.code).join(' · ')} sub={t.credits(passed.reduce((n, c) => n + c.credits, 0))} right={<Tag tone="done">{t.passedTag}</Tag>} />
            </Section>
          )}
        </>
      )}
      {s !== undefined && (
        <Section title={`${t.courses} · ${s.period.name}`}>
          {s.courses.map((c) => (
            <Line
              key={c.offering_id}
              strong={`${c.code} · ${c.title}`}
              sub={[t.credits(c.credits), c.mark !== null ? String(c.mark) : null, c.sitting === 'supplementary' ? t.supplementarySitting : null, c.changed ? t.changed : null].filter((v) => v !== null).join(' · ')}
              href={`/results/${c.offering_id}`}
              right={<CourseState c={c} />}
            />
          ))}
        </Section>
      )}
      {s !== undefined && (
        <Section title={t.decision}>
          <Line
            strong={t.board}
            sub={r.cumulative !== null ? t.cumulative(gpa(r.cumulative)) : t.noCumulative}
            right={<Tag tone={t.decisionTones[s.decision] ?? 'plain'}>{t.decisions[s.decision] ?? s.decision}</Tag>}
          />
        </Section>
      )}
      {s !== undefined &&
        (r.slip_held ? (
          <>
            <button type="button" disabled className="h-12 bg-disabled font-extrabold text-text-muted">
              {t.slip}
            </button>
            <p className="text-[13px] text-text-muted">{t.slipHeld}</p>
          </>
        ) : (
          <a
            href={resultsSlip(s.period.id)}
            onClick={(e) => (e.preventDefault(), window.open(resultsSlip(s.period.id), '_blank', 'noopener'))}
            className="grid h-12 place-items-center border-2 border-ink bg-sheet font-extrabold text-ink no-underline"
          >
            {t.slip}
          </a>
        ))}
    </>
  )
}

/** One course's result (the approved board): the coursework parts adding up, the exam, the total and the grade. */
export function CourseResultPage({ me, id }: { me: Me; id: string }) {
  const loaded = useAnswer(() => studentApi.courseResult(id), `course-result:${id}`)
  const d = loaded.data

  return (
    <Page me={me} tab="courses">
      <Back href="/results">{t.back}</Back>
      {d === undefined ? (
        loaded.refused ? <p className="text-text-muted">{t.none}</p> : <Loading failed={loaded.failed} onRetry={loaded.reload} />
      ) : (
        <CourseResult d={d} />
      )}
    </Page>
  )
}

function CourseResult({ d }: { d: Course }) {
  const l = d.line
  const examShare = 100 - l.share
  const supp = l.kind === 'supplementary'
  const banner = l.result === 'fail' ? t.failBanner(d.pass_mark) : t.banners[l.result]
  const examSub =
    l.barred ? t.examBarred : l.absence === 'deferred' ? t.examDeferred : l.absence === 'absent' ? t.examAbsent(examShare) : l.exam !== null ? t.examOf(l.exam, examShare) : t.examMissing
  const examPart = l.barred || l.absence === 'deferred' || (l.exam === null && l.absence === null) ? null : l.absence === 'absent' ? 0 : ((l.exam ?? 0) * examShare) / 100

  return (
    <>
      <Title>{d.course.code}</Title>
      <p className="-mt-2 text-[14px] text-text-muted">{`${d.course.title} · ${d.course.period} · ${t.credits(d.course.credits)}`}</p>
      <Totals
        first={[t.grade, l.grade !== null ? (l.mark !== null ? `${l.grade} · ${l.mark}` : l.grade) : t.noMark]}
        second={[t.points, l.points !== null ? one(l.points) : t.noMark]}
      />
      {l.result === 'pass' || l.result === 'missing' ? (
        <Line strong={t.states[l.result]} sub={l.result === 'missing' ? t.pending : l.changed ? t.changed : undefined} right={<Tag tone={t.tones[l.result] ?? 'plain'}>{t.states[l.result]}</Tag>} />
      ) : (
        banner !== undefined && (
          <Notice tone={l.result === 'deferred' ? 'attention' : 'wrong'} mark={l.result === 'deferred' ? '!' : '×'}>
            <strong>{(t.states[l.result] ?? l.result).replace(/^[×!✓] /, '')}.</strong> {banner}
          </Notice>
        )
      )}
      <Section title={t.addsUp}>
        <Line strong={t.coursework} sub={t.share(l.share)} right={<b className="tabular-nums">{one(l.ca)}</b>} />
        <Line strong={supp ? t.suppExam : t.exam} sub={examSub} right={<b className="tabular-nums">{examPart !== null ? one(examPart) : t.noMark}</b>} />
        <Line strong={t.total} sub={supp && l.result === 'pass' ? t.suppCapped(d.pass_mark) : t.rounded} right={<b className="tabular-nums">{l.mark !== null ? l.mark : t.noMark}</b>} />
      </Section>
      {d.first !== null && l.kind !== 'first' && (
        <Section title={t.first}>
          <Line
            strong={t.firstLine(d.first.mark, d.first.grade)}
            sub={`${t.coursework} ${one(d.first.ca)}`}
            right={<Tag tone={t.tones[d.first.result] ?? 'plain'}>{t.states[d.first.result] ?? d.first.result}</Tag>}
          />
        </Section>
      )}
      {d.parts.length > 0 && (
        <Section title={t.parts}>
          {d.parts.map((p, i) => (
            <Line key={`${p.title}${i}`} strong={p.title} sub={p.mark !== null ? t.partOf(p.mark, p.out_of, p.weight) : t.partNotMarked(p.weight)} right={<span className="tabular-nums">{one(p.earned)}</span>} />
          ))}
        </Section>
      )}
      {l.rate !== null && (
        <Section title={t.attendance}>
          <Line
            strong={t.rate(l.rate)}
            sub={l.lifted !== null ? `${t.attendanceOf(l.attended, l.held)} · ${t.lifted(l.lifted)}` : t.attendanceOf(l.attended, l.held)}
            right={l.barred ? <Tag tone="wrong">{t.states.barred}</Tag> : undefined}
          />
        </Section>
      )}
      <p className="text-[13px] text-text-muted">{t.note}</p>
    </>
  )
}

/** The attendance-bar warning on Today (the approved board): only for a course close to or below the bar. */
export function BarWarning() {
  const loaded = useAnswer(studentApi.barAttendance, 'bar')
  if (loaded.refused || loaded.data === undefined) return null
  const { bar_percent: bar, courses } = loaded.data
  const shown = courses.filter((c) => c.state === 'close' || c.state === 'below')
  if (shown.length === 0) return null
  const b = copy.bar

  return (
    <div className="grid gap-2">
      {shown.map((c) =>
        c.state === 'below' ? (
          <Notice key={c.code} tone="wrong" mark="×">
            <strong>{b.below(c.code, c.rate)}</strong> {b.belowNote(bar)}
          </Notice>
        ) : (
          <Notice key={c.code} tone="attention" mark="!">
            <strong>{b.close(c.code, c.rate)}</strong> {b.closeNote(bar, c.held, c.attended)}
          </Notice>
        ),
      )}
      <p className="text-[13px] text-text-muted">{b.note}</p>
    </div>
  )
}

