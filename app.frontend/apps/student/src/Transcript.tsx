import { useState } from 'react'
import { studentApi, transcriptCopy, transcriptFile, type Me, type Results, type Transcripts } from './api'
import { copy } from './copy'
import { Field, Line, Loading, Page, Primary, Section, Tag, Title, useAnswer } from './parts'
import { Back, Notice, Secondary, Totals, gpa, longDate } from './Results'

const t = copy.transcript
const a = copy.award

/** A day as a request is dated: Mon 2 Aug. */
const asked = (iso: string) => new Date(iso).toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' }).replace(',', '')

const openFile = (href: string) => window.open(href, '_blank', 'noopener')

/** My transcript (the approved board): the figures, the student's own copy, a request for an official one, and the requests made. */
export function TranscriptPage({ me }: { me: Me }) {
  const results = useAnswer(studentApi.results, 'results')
  const r = results.data

  return (
    <Page me={me} tab="more">
      <Back href="/more">{`‹ ${copy.more.title}`}</Back>
      <Title>{t.title}</Title>
      {r === undefined ? (
        results.refused ? <p className="text-text-muted">{copy.results.none}</p> : <Loading failed={results.failed} onRetry={results.reload} />
      ) : (
        <>
          <Totals first={[copy.results.cgpa, r.cumulative !== null ? gpa(r.cumulative) : copy.results.noMark]} second={[copy.results.passed, String(r.earned)]} />
          <Secondary href={transcriptCopy} file>
            {t.download}
          </Secondary>
          <p className="text-[13px] text-text-muted">{t.copyNote}</p>
          <Official />
        </>
      )}
    </Page>
  )
}

/** The request for an official transcript, and the requests made so far. */
function Official() {
  const list = useAnswer(studentApi.transcripts, 'transcripts')
  const [kind, setKind] = useState<'self' | 'organisation'>('self')
  const [organisation, setOrganisation] = useState('')
  const [email, setEmail] = useState('')
  const [purpose, setPurpose] = useState('')
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState<{ tone: 'done' | 'wrong'; text: string } | null>(null)

  const send = async () => {
    setBusy(true)
    setMessage(null)
    const result = await studentApi.askTranscript({
      for_kind: kind,
      organisation: kind === 'organisation' ? organisation.trim() : null,
      email: kind === 'organisation' ? email.trim() : null,
      purpose: purpose.trim() === '' ? null : purpose.trim(),
    })
    setBusy(false)
    if (result.kind === 'saved') {
      setMessage({ tone: 'done', text: t.sent })
      setOrganisation('')
      setEmail('')
      setPurpose('')
      list.reload()
    } else setMessage({ tone: 'wrong', text: result.kind === 'invalid' && result.message !== '' ? result.message : t.failed })
  }

  return (
    <>
      <Section title={t.ask}>
        <form
          className="grid gap-3 px-3.5 py-3"
          onSubmit={(e) => {
            e.preventDefault()
            void send()
          }}
        >
          <fieldset className="grid gap-1">
            <legend className="label-caps mb-1 text-text-muted">{t.sendTo}</legend>
            {(['self', 'organisation'] as const).map((k) => (
              <label key={k} className={`flex min-h-11 items-center gap-2.5 border-b-2 border-ink px-3 ${kind === k ? 'bg-field font-bold' : 'bg-sheet'}`}>
                <input type="radio" name="for_kind" className="size-5 accent-ink" checked={kind === k} onChange={() => setKind(k)} />
                <span>{t.kinds[k]}</span>
              </label>
            ))}
          </fieldset>
          {kind === 'organisation' && (
            <>
              <Field label={t.organisation} value={organisation} onChange={(e) => setOrganisation(e.target.value)} autoComplete="organization" required />
              <Field label={t.email} type="email" value={email} onChange={(e) => setEmail(e.target.value)} autoComplete="email" required />
            </>
          )}
          <Field label={t.purpose} value={purpose} onChange={(e) => setPurpose(e.target.value)} />
          {message !== null && (
            <Notice tone={message.tone} mark={message.tone === 'done' ? '✓' : '×'}>
              {message.text}
            </Notice>
          )}
          <Primary submit busy={busy}>
            {t.send}
          </Primary>
        </form>
      </Section>
      {list.data === undefined ? (
        !list.refused && <Loading failed={list.failed} onRetry={list.reload} />
      ) : (
        <Requests d={list.data} />
      )}
    </>
  )
}

function Issued({ id }: { id: string }) {
  return (
    <span className="flex shrink-0 items-center gap-2.5">
      <Tag tone="done">{t.issued}</Tag>
      <a href={transcriptFile(id)} onClick={(e) => (e.preventDefault(), openFile(transcriptFile(id)))} className="font-bold text-teal underline">
        {t.open}
      </a>
    </span>
  )
}

/** The requests made, newest first, each waiting or issued; and any transcript issued without a request here. */
function Requests({ d }: { d: Transcripts }) {
  const linked = new Set(d.requests.flatMap((q) => (q.issued !== null ? [q.issued.id] : [])))
  const loose = d.issued.filter((x) => !linked.has(x.id))

  return (
    <Section title={t.requests}>
      {d.requests.length === 0 && loose.length === 0 && <p className="px-3.5 py-2.5 text-[14px] text-text-muted">{t.noRequests}</p>}
      {d.requests.map((q) => (
        <Line
          key={q.id}
          strong={q.for_kind === 'organisation' && q.organisation !== null ? q.organisation : t.myself}
          sub={[q.issued !== null ? t.issuedOn(q.issued.reference, asked(q.issued.at)) : t.asked(asked(q.at)), q.purpose].filter((v) => v !== null && v !== '').join(' · ')}
          right={q.issued !== null ? <Issued id={q.issued.id} /> : <Tag tone="attention">{t.waiting}</Tag>}
        />
      ))}
      {loose.map((x) => (
        <Line key={x.id} strong={x.reference} sub={t.issuedOn(x.reference, asked(x.at))} right={<Issued id={x.id} />} />
      ))}
    </Section>
  )
}

/** My award (the approved board): shown once the academic board has confirmed the award. */
export function AwardPage({ me }: { me: Me }) {
  const loaded = useAnswer(studentApi.results, 'results')
  const r = loaded.data

  return (
    <Page me={me} tab="courses">
      <Back href="/results">{copy.results.back}</Back>
      <Title>{a.title}</Title>
      {r === undefined ? (
        loaded.refused ? <p className="text-text-muted">{copy.results.none}</p> : <Loading failed={loaded.failed} onRetry={loaded.reload} />
      ) : r.award === null ? (
        <p className="text-text-muted">{copy.results.none}</p>
      ) : (
        <Award r={r} award={r.award} />
      )}
    </Page>
  )
}

function Award({ r, award }: { r: Results; award: NonNullable<Results['award']> }) {
  const complete = r.decisions.find((d) => d.decision === 'complete')
  const conferred = award.state === 'conferred'
  const passedAll = r.semesters.every((s) => s.courses.every((c) => !c.counts || c.result === 'pass'))

  return (
    <>
      <Notice tone="done" mark="✓">
        <strong>{a.completed(award.programme)}</strong> {conferred ? a.conferred : complete !== undefined ? a.confirmedOn(longDate(complete.at)) : a.confirmed}
      </Notice>
      <div className="border-2 border-ink bg-ink px-4 py-3.5 text-sheet">
        <span className="label-caps text-on-ink-muted">{a.klass}</span>
        <div className="type-numeral text-[48px] leading-none text-accent uppercase">{award.class ?? copy.results.noMark}</div>
        <div className="text-on-ink-soft">{a.figures(award.cgpa !== null ? gpa(award.cgpa) : copy.results.noMark, award.credits)}</div>
      </div>
      <Section title={a.graduation}>
        <Line
          strong={award.graduation_on !== null ? longDate(award.graduation_on) : a.dateToSet}
          sub={a.onTheDay}
          right={conferred ? <Tag tone="done">{a.conferredTag}</Tag> : undefined}
        />
        <Line
          strong={a.certificate}
          sub={a.givenAt}
          right={award.certificate === 'cleared' ? <Tag tone="done">{a.cleared}</Tag> : <Tag tone="attention">{a.held}</Tag>}
        />
      </Section>
      <Section title={a.results}>
        <Line
          strong={a.semesters(r.semesters.length)}
          sub={passedAll ? a.allPassed : a.credits(r.earned)}
          href="/results"
          right={<span className="font-bold text-teal">{a.open}</span>}
        />
      </Section>
      <Secondary href="/transcript">{copy.results.myTranscript}</Secondary>
      <p className="text-[13px] text-text-muted">{a.note}</p>
    </>
  )
}
