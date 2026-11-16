import { navigate, useLoad, useLocation } from '@acadly/kernel'
import { Notice, Waiting } from '@acadly/ui'
import { copy } from '../copy'
import { Total, TotalsBand } from '../setup/parts'
import { assessmentApi, summaryText, type ResultsState } from './api'
import { BackLink, Working } from './parts'

const a = copy.assessment
const t = a.results

/** A class's term grades (the approved design): learners by subjects, and how a chosen grade is made. */
export function ResultsScreen() {
  const { query } = useLocation()
  const classId = query.get('class_id') ?? ''
  const periodId = query.get('period_id')
  const learner = query.get('learner')
  const subject = query.get('subject')
  const loaded = useLoad(`assessment:results:${classId}:${periodId ?? ''}`, () => (classId === '' ? Promise.resolve(null) : assessmentApi.results(classId, periodId)))
  const data = loaded.data

  if (classId === '') {
    return (
      <div className="px-4 py-6 md:px-[clamp(16px,3vw,40px)]">
        <BackLink href="/marks/all">{a.sheet.back}</BackLink>
        <Notice tone="wrong" title={t.noClass} />
      </div>
    )
  }

  if (data === undefined) return <Waiting phase={loaded.phase} what={t.tableLabel} labels={copy.loading} band={[t.bandLearners, t.bandReady, t.bandBelow]} rows={8} onRetry={loaded.reload} />

  const base = `/marks/results?class_id=${encodeURIComponent(classId)}&period_id=${encodeURIComponent(data.period.id)}`
  const choose = (learnerId: string, subjectId: string) => navigate(`${base}&learner=${encodeURIComponent(learnerId)}&subject=${encodeURIComponent(subjectId)}`)
  const waiting = data.subjects.find((x) => !x.ready)?.waiting ?? null

  return (
    <>
      <div className="px-4 pt-1 pb-4 md:px-[clamp(16px,3vw,40px)]">
        <BackLink href="/marks/all">{a.sheet.back}</BackLink>
        <div className="label-caps text-text-muted">{t.kicker(data.class.level, data.period.name)}</div>
        <h1 className="type-display mt-1 mb-2 text-[clamp(44px,7vw,88px)]">{data.class.name}</h1>
        <p className="max-w-[680px] text-[15px] text-text-muted">{t.intro}</p>
      </div>
      <TotalsBand>
        <Total tone="ink" label={t.bandLearners} value={String(data.totals.learners)} note={t.bandLearnersNote(data.class.name)} />
        <Total label={t.bandReady} value={String(data.totals.subjects_ready)} suffix={t.bandReadyOf(data.totals.subjects)} note={t.bandReadyNote(waiting)} />
        <Total label={t.bandRanked} value={String(data.totals.ranked)} suffix={t.bandReadyOf(data.totals.learners)} note={t.bandRankedNote} />
        <Total tone={data.totals.below_pass > 0 ? 'attention' : 'plain'} label={t.bandBelow} value={String(data.totals.below_pass)} note={t.bandBelowNote} />
      </TotalsBand>
      <div className="flex flex-1 flex-wrap items-stretch bg-sheet">
        <section aria-label={t.tableLabel} className="min-w-0 flex-[3_1_560px] px-4 pt-4 pb-6 md:px-[clamp(16px,3vw,40px)]">
          <Grid data={data} chosen={learner !== null && subject !== null ? { learner, subject } : null} onChoose={choose} />
          {data.scale !== null && <p className="mt-3 max-w-[70ch] text-[13px] text-text-muted">{t.legend(data.scale.name, data.scale.overall)}</p>}
        </section>
        {learner !== null && subject !== null && (
          <aside className="min-w-0 flex-[1_1_340px] border-ink bg-paper px-4 pt-4.5 pb-6 max-[1023px]:border-t-2 md:px-7 min-[1024px]:border-l-2">
            <WorkingFor classId={classId} periodId={data.period.id} learnerId={learner} subjectId={subject} onClose={() => navigate(base)} />
          </aside>
        )}
      </div>
    </>
  )
}

/** Ranked learners first, by position; then the rest as the class lists them. */
const byPosition = (learners: ResultsState['learners']) =>
  learners
    .map((l, i) => ({ l, i }))
    .sort((x, y) => (x.l.position?.position ?? Infinity) - (y.l.position?.position ?? Infinity) || x.i - y.i)
    .map((x) => x.l)

function Grid({ data, chosen, onChoose }: { data: ResultsState; chosen: { learner: string; subject: string } | null; onChoose: (learner: string, subject: string) => void }) {
  return (
    <div role="region" aria-label={t.tableLabel} tabIndex={0} className="overflow-x-auto">
      <table className="w-full min-w-[620px] border-collapse">
        <thead>
          <tr className="label-caps text-text-muted">
            <th scope="col" className="w-16 border-b-2 border-ink pb-2 text-center">
              {t.colPosition}
            </th>
            <th scope="col" className="border-b-2 border-ink px-3 pb-2 text-left">
              {t.colLearner}
            </th>
            {data.subjects.map((x) => (
              <th key={x.id} scope="col" className="border-b-2 border-ink px-1 pb-2 text-center" title={x.name}>
                <abbr title={x.name} className="no-underline">
                  {x.short}
                </abbr>
              </th>
            ))}
            <th scope="col" className="border-b-2 border-ink px-3 pb-2 text-right">
              {t.colTotal}
            </th>
            <th scope="col" className="border-b-2 border-ink px-3 pb-2 text-right whitespace-nowrap">
              {t.summary[data.summary_kind]}
            </th>
          </tr>
        </thead>
        <tbody>
          {byPosition(data.learners).map((l) => (
            <tr key={l.id} className={l.below_pass ? 'bg-marigold-wash' : ''}>
              <td className="type-display w-16 border-r-2 border-b border-r-red-pen border-b-rule text-center text-2xl">
                {l.position !== null ? (
                  <span aria-label={t.positionLabel(l.position.position, l.position.of, l.position.shared)}>{t.position(l.position.position, l.position.shared)}</span>
                ) : (
                  <span className="text-text-muted">{copy.dash}</span>
                )}
              </td>
              <th scope="row" className="min-w-[160px] border-b border-rule px-3 py-1.5 text-left text-sm font-extrabold">
                {l.name}
                {l.position === null && <span className="block text-xs font-bold text-text-muted">{t.notRanked}</span>}
              </th>
              {data.subjects.map((x) => {
                const g = l.grades.find((grade) => grade.subject_id === x.id)
                const on = chosen?.learner === l.id && chosen.subject === x.id
                const ready = g !== undefined && g.ready && g.grade !== null

                return (
                  <td key={x.id} className="border-b border-rule px-1 py-1 text-center">
                    <button
                      type="button"
                      aria-pressed={on}
                      aria-label={t.gradeLabel(l.name, x.name, ready ? (g.grade ?? '') : t.notReady)}
                      onClick={() => onChoose(l.id, x.id)}
                      className={`ref size-11 text-[15px] font-extrabold ${on ? 'bg-ink text-sheet' : ready && g.passes === false ? 'border-[3px] border-red-pen bg-sheet text-red-text' : ready ? 'border-2 border-ink bg-sheet hover:bg-field' : 'border-2 border-dashed border-rule-dashed text-text-muted'}`}
                    >
                      {ready ? g.grade : copy.dash}
                    </button>
                  </td>
                )
              })}
              <td className="ref border-b border-rule px-3 text-right text-[15px] font-extrabold">{l.total.total > 0 ? a.num(l.total.total) : copy.dash}</td>
              <td className="type-numeral border-b border-rule px-3 text-right text-2xl">{l.summary.value !== null ? summaryText(data.summary_kind, l.summary.value) : copy.dash}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function WorkingFor({ classId, periodId, learnerId, subjectId, onClose }: { classId: string; periodId: string; learnerId: string; subjectId: string; onClose: () => void }) {
  const loaded = useLoad(`assessment:working:${classId}:${periodId}:${learnerId}:${subjectId}`, () =>
    assessmentApi.working({ class_id: classId, learner_id: learnerId, subject_id: subjectId, period_id: periodId }),
  )

  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={a.working.title} labels={copy.loading} rows={3} onRetry={loaded.reload} />

  return <Working data={loaded.data} onClose={onClose} />
}
