import { navigate, useLoad } from '@acadly/kernel'
import { Button, Waiting } from '@acadly/ui'
import { copy } from '../copy'
import { day } from '../settings/api'
import { PageHead, Tag } from '../settings/parts'
import { conductApi } from './api'

const t = copy.conduct

/** The conduct overview (the approved design): open serious incidents, learners to watch, by kind. */
export function OverviewScreen() {
  const loaded = useLoad('conduct:overview', () => conductApi.overview())
  const o = loaded.data

  if (o === undefined) return <Waiting phase={loaded.phase} what={t.overviewTitle} labels={copy.loading} rows={6} onRetry={loaded.reload} />

  const oldest = o.open_cases.reduce((a, c) => Math.max(a, c.days), 0)
  const cells = [
    { label: t.commendationsTerm, value: String(o.commendations), note: t.upOn(o.commendations_before), ink: true },
    { label: t.incidentsTerm, value: String(o.incidents), note: t.levelsNote(o.levels.minor ?? 0, o.levels.moderate ?? 0, o.levels.serious ?? 0) },
    { label: t.openCount, value: String(o.open_cases.length), note: o.open_cases.length > 0 ? t.oldest(oldest) : t.noneOpen, attention: o.open_cases.length > 0 },
    { label: t.toWatch, value: String(o.watch.length), note: t.toWatchNote, attention: o.watch.length > 0 },
  ]

  return (
    <>
      <PageHead kicker={t.overviewKicker} title={t.overviewTitle} intro="" actions={<Button onClick={() => navigate('/conduct/record')}>{t.recordTitle}</Button>} />
      <div className="grid gap-6 px-[clamp(16px,3vw,40px)] pb-8">
        <div className="grid grid-cols-2 border-2 border-ink lg:grid-cols-4">
          {cells.map((c, i) => (
            <section key={c.label} className={`min-w-0 px-3.5 pt-2.5 pb-3 ${c.ink === true ? 'bg-ink text-sheet' : c.attention === true ? 'bg-marigold-wash' : 'bg-sheet'} ${i > 0 ? 'border-ink lg:border-l-2 max-lg:[&:nth-child(even)]:border-l-2 max-lg:[&:nth-child(n+3)]:border-t-2' : ''}`}>
              <h3 className={`label-caps ${c.ink === true ? 'text-on-ink-muted' : 'text-text-muted'}`}>{c.label}</h3>
              <div className={`type-numeral text-[clamp(30px,3vw,40px)] ${c.ink === true ? 'text-teal-bright' : ''}`}>{c.value}</div>
              <p className={`text-[13px] ${c.ink === true ? 'text-on-ink-soft' : 'text-text-muted'}`}>{c.note}</p>
            </section>
          ))}
        </div>

        <div className="grid grid-cols-[minmax(0,1fr)] items-start gap-x-8 gap-y-6 xl:grid-cols-[minmax(0,1fr)_480px]">
          <div className="grid gap-5">
            <section className="border-2 border-ink bg-sheet px-4 py-3" aria-label={t.openTitle}>
              <h2 className="type-display mb-2 text-[26px] leading-none">{t.openTitle}</h2>
              {o.open_cases.length === 0 && <p className="text-sm text-text-muted">{t.noneOpen}</p>}
              <ul>
                {o.open_cases.map((c) => (
                  <li key={c.id}>
                    <a
                      href={`/conduct/incidents/${c.id}`}
                      onClick={(e) => (e.preventDefault(), navigate(`/conduct/incidents/${c.id}`))}
                      className="grid items-center gap-x-3 gap-y-1 border-b border-rule py-2.5 text-ink no-underline hover:bg-paper md:grid-cols-[150px_minmax(0,1fr)_160px_80px]"
                    >
                      <span className="ref text-[13px]">{c.number}</span>
                      <span>
                        <b>
                          {c.kind} · {day(c.happened_on)}
                        </b>
                        <span className="block text-xs text-text-muted">{[c.learners, c.lead].filter(Boolean).join(' · ')}</span>
                      </span>
                      <span>
                        <Tag tone="attention">! {c.state_label}</Tag>
                      </span>
                      <span className="text-right text-[13px]">{t.ageDays(c.days)}</span>
                    </a>
                  </li>
                ))}
              </ul>
            </section>

            <section className="border-2 border-ink bg-sheet px-4 py-3" aria-label={t.watchTitle}>
              <div className="mb-2 flex flex-wrap items-baseline gap-2.5">
                <h2 className="type-display flex-1 text-[26px] leading-none">{t.watchTitle}</h2>
                <span className="text-[13px] text-text-muted">{t.eachSaysWhy}</span>
              </div>
              {o.watch.length === 0 && <p className="text-sm text-text-muted">{t.nobodyToWatch}</p>}
              <ul>
                {o.watch.map((w) => (
                  <li key={w.id}>
                    <a
                      href={`/learners/${w.id}?tab=conduct`}
                      onClick={(e) => (e.preventDefault(), navigate(`/learners/${w.id}?tab=conduct`))}
                      className="grid gap-x-3 border-b border-rule py-2.5 text-ink no-underline hover:bg-paper md:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]"
                    >
                      <span>
                        <b className="text-[15px]">{w.name}</b>
                        <span className="block text-xs text-text-muted">{w.class}</span>
                      </span>
                      <span className="text-sm">{w.reasons.join(' ')}</span>
                    </a>
                  </li>
                ))}
              </ul>
              <p className="mt-2 text-[13px] text-text-muted">{t.rulesLine(
                  o.rules.incidents,
                  o.rules.days,
                  o.rules.open_serious,
                  o.rules.missed_incidents > 0 ? { incidents: o.rules.missed_incidents, lessons: o.rules.missed_lessons, days: o.rules.missed_days } : null,
                )}</p>
            </section>
          </div>

          <section className="border-2 border-ink bg-sheet px-4 py-3" aria-label={t.byKind}>
            <h2 className="type-display mb-2 text-[26px] leading-none">{t.byKind}</h2>
            <table className="w-full border-collapse text-sm">
              <thead>
                <tr>
                  {[t.cols.kind, t.cols.now, t.cols.before, t.cols.change].map((h, i) => (
                    <th key={h} scope="col" className={`label-caps border-b-2 border-ink py-1.5 text-text-muted ${i === 0 ? 'text-left' : 'text-right'}`}>
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {o.kinds.map((k) => {
                  const change = k.now - k.before

                  return (
                    <tr key={k.name}>
                      <th scope="row" className="border-b border-rule py-2 text-left font-extrabold">
                        {k.name}
                        <span className="block text-[11px] font-normal text-text-muted">{k.commendation ? t.commendation : t.incident}</span>
                      </th>
                      <td className="ref border-b border-rule text-right">{k.now}</td>
                      <td className="ref border-b border-rule text-right">{k.before}</td>
                      <td className="ref border-b border-rule text-right">{change > 0 ? `+${change}` : change < 0 ? `−${Math.abs(change)}` : '0'}</td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </section>
        </div>
      </div>
    </>
  )
}
