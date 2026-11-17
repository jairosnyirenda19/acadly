import { useLoad, useLocation } from '@acadly/kernel'
import { Button, Notice, Waiting } from '@acadly/ui'
import { copy } from '../copy'
import { assessmentApi, type Card, type CardsState } from './api'
import { BackLink } from './parts'

const a = copy.assessment
const t = a.card
const API = import.meta.env.VITE_API_URL ?? '/api'

/** Published report cards, one A4 page each (the approved design): printed from the browser. */
export function ReportCardScreen({ id }: { id: string }) {
  const { query } = useLocation()
  const learner = query.get('learner_id')
  const loaded = useLoad(`assessment:cards:${id}:${learner ?? ''}`, () => assessmentApi.cards(id, learner))
  const data = loaded.data

  if (data === undefined) return <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} rows={8} onRetry={loaded.reload} />

  return (
    <div className="min-h-screen bg-paper print:bg-sheet">
      <div className="flex flex-wrap items-center gap-3 border-b-2 border-ink bg-sheet px-4 py-2 print:hidden">
        <BackLink href={learner !== null ? `/learners/${learner}` : '/marks/publish'}>{t.back}</BackLink>
        <span className="flex-1" />
        <Button className="h-11" onClick={() => window.print()}>
          {t.print}
        </Button>
      </div>
      {data.cards.length === 0 ? (
        <div className="p-4">
          <Notice tone="wrong" title={copy.loading.failedTitle} />
        </div>
      ) : (
        data.cards.map((card) => <Page key={card.reference} card={card} data={data} />)
      )}
    </div>
  )
}

function Page({ card, data }: { card: Card; data: CardsState }) {
  const parts = card.subjects[0]?.parts ?? []

  return (
    <article className="mx-auto my-6 flex min-h-[297mm] w-full max-w-[210mm] flex-col bg-sheet px-[13mm] py-[11mm] text-ink break-after-page print:my-0 print:min-h-0 print:max-w-none">
      <header className="flex items-center gap-4 border-b-4 border-ink pb-3.5">
        {data.school.has_logo && <img src={`${API}/v1/school/logo`} alt={t.logo} className="max-h-16 max-w-16" />}
        <div className="min-w-0 flex-1">
          <div className="type-display text-[30px] leading-[0.9]">{data.school.name}</div>
        </div>
        <div className="text-right">
          <div className="label-caps text-text-muted">{t.title}</div>
          <div className="type-display text-[22px] leading-none">{card.period.name}</div>
        </div>
      </header>
      <dl className="grid grid-cols-[1.4fr_1fr_1fr_1fr] border-b-2 border-ink">
        {[
          [t.learner, card.learner.name],
          [t.class, card.class.name],
          [t.position, card.position !== null ? t.positionOf(card.position.position, card.position.of, card.position.shared) : null],
          [t.total, a.num(card.total.total)],
        ]
          .filter((x): x is [string, string] => x[1] !== null)
          .map(([k, v], i) => (
            <div key={k} className={`py-2.5 ${i > 0 ? 'border-l border-rule pl-3' : ''}`}>
              <dt className="label-caps text-text-muted">{k}</dt>
              <dd className={i === 0 ? 'text-xl font-extrabold' : 'type-display text-[22px] leading-tight'}>{v}</dd>
            </div>
          ))}
      </dl>
      <table className="mt-4 w-full border-collapse text-[13px]">
        <thead>
          <tr className="label-caps text-left text-text-muted">
            <th scope="col" className="border-b-2 border-ink pb-1.5">
              {t.subject}
            </th>
            {parts.map((p) => (
              <th key={p.label} scope="col" className="border-b-2 border-ink pb-1.5">
                {t.part(p.label, p.weight)}
              </th>
            ))}
            <th scope="col" className="border-b-2 border-ink pb-1.5">
              {t.termCol}
            </th>
            <th scope="col" className="border-b-2 border-ink pb-1.5">
              {t.grade}
            </th>
            <th scope="col" className="border-b-2 border-ink pb-1.5">
              {t.word}
            </th>
          </tr>
        </thead>
        <tbody>
          {card.subjects.map((s) => (
            <tr key={s.subject_id} className="border-b border-rule">
              <th scope="row" className="py-2 pr-2 text-left font-extrabold">
                {s.subject}
              </th>
              {parts.map((p) => {
                const mine = s.parts.find((x) => x.label === p.label)

                return (
                  <td key={p.label} className="ref py-2">
                    {mine !== undefined && mine.points !== null ? t.points(mine.points, mine.weight) : copy.dash}
                  </td>
                )
              })}
              <td className="ref py-2 font-extrabold">{a.pct(s.percent)}</td>
              <td className="py-2">{s.grade !== null && <span className="ref inline-block min-w-7 border border-ink px-1.5 text-center font-extrabold">{s.grade}</span>}</td>
              <td className="py-2">{s.word ?? ''}</td>
            </tr>
          ))}
        </tbody>
      </table>
      <div className="mt-2.5 flex flex-wrap gap-x-6 gap-y-1 text-xs text-text-muted">
        {card.scale !== null && <span>{t.scale(card.scale.name)}</span>}
        {card.lines.map((l) => (
          <span key={l.label}>
            {l.label}: <strong className="text-ink">{l.value}</strong>
          </span>
        ))}
      </div>
      <div className="mt-5 grid grid-cols-2 gap-5">
        <Remark title={t.classTeacher} who={card.comments.class_teacher?.by ?? null} text={card.comments.class_teacher?.text ?? null} sign={t.signature} />
        <Remark title={t.head} who={card.comments.head?.by ?? null} text={card.comments.head?.text ?? null} sign={t.stamp} />
      </div>
      <div className="flex-1" />
      <footer className="mt-6 flex flex-wrap items-end justify-between gap-2 border-t border-rule pt-2.5 text-[11px] text-text-muted">
        <span>{card.next_term !== null ? t.nextTerm(t.longDay(card.next_term)) : ''}</span>
        <span>{t.footer(t.longDay(data.publication.published_at), data.publication.version, card.reference)}</span>
      </footer>
    </article>
  )
}

function Remark({ title, who, text, sign }: { title: string; who: string | null; text: string | null; sign: string }) {
  return (
    <div className="border-t-2 border-ink pt-2">
      <div className="label-caps text-text-muted">{who !== null ? `${title} · ${who}` : title}</div>
      <p className="mt-1.5 min-h-14 text-[13px] leading-[1.45]">{text ?? t.noComment}</p>
      <div className="mt-4 w-[70%] border-b border-ink" />
      <div className="mt-1 text-[10px] text-text-muted">{sign}</div>
    </div>
  )
}
