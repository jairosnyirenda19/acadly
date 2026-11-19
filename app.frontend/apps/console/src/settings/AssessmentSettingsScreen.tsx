import { navigate, useLoad, useLocation } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { assessmentApi, type Band, type Overall, type PlanPart, type PlansState, type Scale, type ScalesState } from '../assessment/api'
import { follow } from '../assessment/parts'
import { copy } from '../copy'
import { setupApi } from '../setup/api'
import { Input, Select } from '../setup/parts'
import { PageHead, SettingsTabs, Tag } from './parts'
import { WaitingFor } from '../approvals/WaitingFor'

const t = copy.assessment.settings
const s = copy.settings

const field = 'h-11 w-full min-w-0 border-0 border-b-2 border-ink bg-field px-2.5 text-[15px] text-ink outline-none focus:border-teal disabled:border-dashed disabled:border-rule-dashed disabled:bg-paper'

type View = 'plans' | 'scales'

const OVERALLS: Overall[] = ['average_percent', 'average_grade', 'best_six']

/** Settings › Assessment (the approved designs): grading plans and scales. */
export function AssessmentSettingsScreen({ view }: { view: View }) {
  return (
    <>
      <SettingsTabs current="assessment" />
      <PageHead kicker={t.kicker} title={t.title} intro={t.intro} />
      <nav aria-label={t.tabsLabel} className="flex gap-1 overflow-x-auto border-b-2 border-ink bg-sheet px-2 [scrollbar-width:none] md:px-[clamp(16px,3vw,40px)]">
        {(['plans', 'scales'] as const).map((key) => {
          const href = key === 'plans' ? '/settings/assessment' : '/settings/assessment/scales'

          return (
            <a
              key={key}
              href={href}
              aria-current={key === view ? 'page' : undefined}
              onClick={(e) => follow(e, href)}
              className={`inline-flex min-h-11 shrink-0 items-center border-b-4 px-3.5 pt-2.5 pb-2 text-[15px] whitespace-nowrap text-ink no-underline ${key === view ? 'border-teal font-extrabold' : 'border-transparent font-medium hover:bg-paper'}`}
            >
              {t.tabs[key]}
            </a>
          )
        })}
      </nav>
      {view === 'plans' ? <Plans /> : <Scales />}
    </>
  )
}

// ── Scales ───────────────────────────────────────────────────────────────────

/** Whether bands cover 0 to 100 once each, with no gap and no overlap. */
function covers(bands: Band[]): boolean {
  const sorted = [...bands].sort((x, y) => x.from - y.from)
  let next = 0

  for (const b of sorted) {
    if (!Number.isInteger(b.from) || !Number.isInteger(b.to) || b.from !== next || b.to < b.from) return false
    next = b.to + 1
  }

  return next === 101
}

/** The band that reaches 100%: the best grade, whatever its symbol. */
const top = (bands: Band[]) => [...bands].sort((x, y) => y.to - x.to)[0]
/** The band that starts at 0%: the lowest grade. */
const bottom = (bands: Band[]) => [...bands].sort((x, y) => x.from - y.from)[0]

function Scales() {
  const loaded = useLoad('assessment:scales', () => assessmentApi.scales())
  const data = loaded.data
  const { query } = useLocation()

  if (data === undefined) return <Waiting phase={loaded.phase} what={t.scalesLabel} labels={copy.loading} rows={6} onRetry={loaded.reload} />

  const chosen = data.scales.find((x) => x.id === query.get('scale')) ?? data.scales.find((x) => !x.retired) ?? data.scales[0]

  const add = async () => {
    const r = await assessmentApi.addScale({
      name: t.newName,
      overall: 'average_percent',
      pass_from: null,
      track: 'malawi',
      bands: [
        { grade: 'A', word: '', from: 50, to: 100 },
        { grade: 'F', word: '', from: 0, to: 49 },
      ],
    })
    if (r.kind === 'saved') {
      loaded.set(r.data)
      slips.done({ word: copy.slips.saved, title: t.added })
      const added = r.data.scales.find((x) => !data.scales.some((old) => old.id === x.id))
      if (added !== undefined) navigate(`/settings/assessment/scales?scale=${added.id}`, true)
    } else
      slips.wrong({
        word: copy.assessment.notSaved,
        title: r.kind === 'refused' ? s.refused : s.failed,
      })
  }

  return (
    <>
      {!data.can_edit && (
        <div className="px-4 py-3 md:px-[clamp(16px,3vw,40px)]">
          <Notice tone="wrong" title={t.readOnly} />
        </div>
      )}
      <LevelScales data={data} onSaved={loaded.set} />
      <div className="flex flex-1 flex-wrap items-stretch border-t-2 border-ink bg-sheet">
        <nav aria-label={t.scalesLabel} className="min-w-0 flex-[1_1_260px] border-ink bg-paper max-[767px]:border-b-2 md:border-r-2">
          {[...data.scales]
            .sort((x, y) => Number(y.used_by.length > 0) - Number(x.used_by.length > 0))
            .map((x, i, all) => {
              const href = `/settings/assessment/scales?scale=${x.id}`
              const best = top(x.bands)
              const worst = bottom(x.bands)
              const used = x.used_by.length > 0
              const heading = i === 0 || used !== (all[i - 1]?.used_by.length ?? 0) > 0

              return (
                <div key={x.id}>
                  {heading && <h2 className={`label-caps border-b border-rule px-4 pt-3 pb-1.5 ${used ? '' : 'text-text-muted'}`}>{used ? t.inUse : t.notInUse}</h2>}
                  <a
                    href={href}
                    aria-current={x.id === chosen?.id ? 'page' : undefined}
                    onClick={(e) => follow(e, href)}
                    className={`block border-b border-rule border-l-4 px-4 py-2.5 text-ink no-underline ${x.id === chosen?.id ? 'border-l-ink bg-sheet' : 'border-l-transparent hover:bg-sheet'}`}
                  >
                    <span className="flex items-baseline gap-2">
                      <span className="flex-1 text-[15px] font-extrabold">{x.name}</span>
                      {x.retired && <Tag tone="plain">{t.retired}</Tag>}
                    </span>
                    <span className="block text-[13px] text-text-muted">{x.used_by.length > 0 ? copy.and(x.used_by.map((u) => u.level)) : t.notUsed}</span>
                    {best !== undefined && worst !== undefined && <span className="ref block text-xs text-text-muted">{t.range(best.grade, worst.grade)}</span>}
                  </a>
                </div>
              )
            })}
          {data.can_edit && (
            <div className="p-4">
              <Button variant="secondary" onClick={() => void add()}>
                {t.add}
              </Button>
            </div>
          )}
        </nav>
        <section className="min-w-0 flex-[3_1_480px] px-4 pt-4.5 pb-6 md:px-7">
          {chosen !== undefined && <ScaleEditor key={`${chosen.id}:${JSON.stringify(chosen.bands)}:${chosen.name}`} scale={chosen} canEdit={data.can_edit} onSaved={loaded.set} />}
        </section>
      </div>
    </>
  )
}

function ScaleEditor({ scale, canEdit, onSaved }: { scale: Scale; canEdit: boolean; onSaved: (next: ScalesState) => void }) {
  const [name, setName] = useState(scale.name)
  const [bands, setBands] = useState<Band[]>(scale.bands)
  const [overall, setOverall] = useState<Overall>(scale.overall)
  const [pass, setPass] = useState(scale.pass_from ?? '')
  const [failed, setFailed] = useState<string | null>(null)
  const [working, setWorking] = useState(false)
  const numeric = bands.every((b) => b.grade.trim() !== '' && Number.isFinite(Number(b.grade)))
  const ok = covers(bands) && bands.every((b) => b.grade.trim() !== '') && (overall === 'average_percent' || numeric)
  const set = (i: number, patch: Partial<Band>) => setBands((all) => all.map((b, j) => (j === i ? { ...b, ...patch } : b)))
  const edit = canEdit && !scale.retired

  const save = async (retired: boolean) => {
    setWorking(true)
    setFailed(null)
    const r = await assessmentApi.saveScale(scale.id, {
      name: name.trim(),
      overall,
      pass_from: pass === '' ? null : pass,
      track: scale.track,
      bands,
      retired,
    })
    setWorking(false)
    if (r.kind === 'saved') {
      slips.done({
        word: copy.slips.saved,
        title: retired ? t.retiredDone : t.saved,
      })
      onSaved(r.data)
    } else setFailed(r.kind === 'invalid' ? (Object.values(r.errors)[0]?.[0] ?? s.failed) : r.kind === 'refused' ? s.refused : s.failed)
  }

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault()
        void save(false)
      }}
    >
      <div className="mb-2 flex flex-wrap items-baseline gap-2.5">
        <h2 className="type-display flex-1 text-[30px] leading-none">{scale.name}</h2>
        <span className="text-[13px] font-bold">{scale.used_by.length > 0 ? t.usedBy(copy.and(scale.used_by.map((u) => u.level))) : t.notUsed}</span>
      </div>
      <p className="mb-3.5 max-w-[70ch] border-l-4 border-marigold bg-marigold-wash px-3.5 py-2.5 text-sm">{t.bandsNote}</p>
      {failed !== null && (
        <div className="mb-3">
          <Notice tone="wrong" title={failed} />
        </div>
      )}
      <div className="mb-3 max-w-[420px]">
        <Input label={t.name} value={name} disabled={!edit} onChange={(e) => setName(e.target.value)} maxLength={60} />
      </div>
      <div role="region" aria-label={t.scalesLabel} tabIndex={0} className="overflow-x-auto">
        <table className="w-full min-w-[460px] border-collapse">
          <thead>
            <tr className="label-caps text-left text-text-muted">
              <th scope="col" className="w-20 border-b-2 border-ink pb-2">
                {t.colGrade}
              </th>
              <th scope="col" className="border-b-2 border-ink px-2 pb-2">
                {t.colWord}
              </th>
              <th scope="col" className="w-20 border-b-2 border-ink px-2 pb-2">
                {t.colFrom}
              </th>
              <th scope="col" className="w-20 border-b-2 border-ink px-2 pb-2">
                {t.colTo}
              </th>
              <th scope="col" className="w-24 border-b-2 border-ink pb-2" />
            </tr>
          </thead>
          <tbody>
            {bands.map((b, i) => (
              <tr key={i}>
                <td className="border-b border-rule py-1.5 pr-2">
                  <input aria-label={t.gradeFor(i + 1)} value={b.grade} disabled={!edit} maxLength={20} onChange={(e) => set(i, { grade: e.target.value })} className={`${field} ref font-extrabold`} />
                </td>
                <td className="border-b border-rule px-2 py-1.5">
                  <input aria-label={t.wordFor(b.grade)} value={b.word} disabled={!edit} maxLength={40} onChange={(e) => set(i, { word: e.target.value })} className={field} />
                </td>
                <td className="border-b border-rule px-2 py-1.5">
                  <input
                    aria-label={t.fromFor(b.grade)}
                    inputMode="numeric"
                    value={String(b.from)}
                    disabled={!edit}
                    onChange={(e) =>
                      set(i, {
                        from: Number(e.target.value.replace(/[^0-9]/g, '') || 0),
                      })
                    }
                    className={`${field} ref`}
                  />
                </td>
                <td className="border-b border-rule px-2 py-1.5">
                  <input
                    aria-label={t.toFor(b.grade)}
                    inputMode="numeric"
                    value={String(b.to)}
                    disabled={!edit}
                    onChange={(e) =>
                      set(i, {
                        to: Number(e.target.value.replace(/[^0-9]/g, '') || 0),
                      })
                    }
                    className={`${field} ref`}
                  />
                </td>
                <td className="border-b border-rule py-1.5 text-right">
                  {pass !== '' && b.grade === pass ? <Tag tone="plain">{t.passFrom(b.grade)}</Tag> : null}
                  {edit && bands.length > 2 && (
                    <button type="button" aria-label={t.removeBand(b.grade)} onClick={() => setBands((all) => all.filter((_, j) => j !== i))} className="ml-1 size-11 border-2 border-ink text-sm font-extrabold hover:bg-field">
                      <svg aria-hidden="true" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="square" className="mx-auto">
                        <path d="M6 6l12 12" />
                        <path d="M18 6L6 18" />
                      </svg>
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {!ok && <p className="mt-2 text-[13px] font-bold text-red-text">{t.gaps}</p>}
      {edit && (
        <button type="button" onClick={() => setBands((all) => [...all, { grade: '', word: '', from: 0, to: 0 }])} className="mt-2 h-11 border-2 border-dashed border-rule-dashed px-3 text-sm font-extrabold hover:bg-field">
          {t.addBand}
        </button>
      )}
      <div className="mt-4 grid max-w-[860px] gap-5 md:grid-cols-[1.6fr_1fr]">
        <fieldset>
          <legend className="label-caps mb-1.5 text-text-muted">{t.overall}</legend>
          <div className="grid gap-1.5">
            {OVERALLS.map((o) => {
              const on = overall === o
              const blocked = o !== 'average_percent' && !numeric

              return (
                <label
                  key={o}
                  className={`flex min-h-11 items-center gap-2.5 border-2 px-3 py-2 text-sm ${on ? 'border-ink bg-ink font-extrabold text-sheet' : blocked ? 'border-dashed border-rule-dashed text-text-muted' : 'cursor-pointer border-rule bg-sheet font-semibold'} has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-ink`}
                >
                  <input type="radio" name={`overall-${scale.id}`} className="size-4 shrink-0 accent-teal" checked={on} disabled={!edit || blocked} onChange={() => setOverall(o)} />
                  {t.overalls[o]}
                </label>
              )
            })}
          </div>
          {!numeric && <p className={`mt-1.5 text-[13px] ${overall !== 'average_percent' ? 'font-bold text-red-text' : 'text-text-muted'}`}>{t.overallNumbers}</p>}
        </fieldset>
        <div>
        <Select label={t.passNeeds} value={pass} disabled={!edit} onChange={(e) => setPass(e.target.value)}>
          <option value="">{t.noPass}</option>
          {bands
            .filter((b) => b.grade.trim() !== '')
            .map((b) => (
              <option key={b.grade} value={b.grade}>
                {t.passFrom(b.grade)}
              </option>
            ))}
        </Select>
          <p className="mt-3.5 border-2 border-ink px-3 py-2.5 text-[13px]">
            <strong className="block text-sm">{t.positionTitle}</strong>
            {t.positionNote}
          </p>
        </div>
      </div>
      {edit && (
        <footer className="mt-5 flex flex-wrap items-center gap-3 border-t-2 border-ink pt-4">
          <Button type="submit" busy={working} disabled={!ok || name.trim() === ''}>
            {t.save}
          </Button>
          <span className="flex-[1_1_260px] text-[13px] text-text-muted">{t.saveNote}</span>
          {scale.used_by.length === 0 ? (
            <Button variant="secondary" disabled={working} onClick={() => void save(true)}>
              {t.retire}
            </Button>
          ) : (
            <span className="basis-full text-[13px] text-text-muted">{t.retireBlocked}</span>
          )}
        </footer>
      )}
    </form>
  )
}

function LevelScales({ data, onSaved }: { data: ScalesState; onSaved: (next: ScalesState) => void }) {
  const choose = async (division_id: string, level_code: string, scale_id: string) => {
    const r = await assessmentApi.levelScale({
      division_id,
      level_code,
      scale_id,
    })
    if (r.kind === 'saved') {
      onSaved(r.data)
      slips.done({ word: copy.slips.saved, title: t.levelSaved })
    } else
      slips.wrong({
        word: copy.assessment.notSaved,
        title: r.kind === 'invalid' ? (Object.values(r.errors)[0]?.[0] ?? s.failed) : r.kind === 'refused' ? s.refused : s.failed,
      })
  }

  return (
    <section aria-label={t.levelsTitle} className="bg-sheet px-4 pt-4.5 pb-6 md:px-[clamp(16px,3vw,40px)]">
      <h2 className="type-display mb-1 text-[26px] leading-none">{t.levelsTitle}</h2>
      <p className="mb-3 max-w-[80ch] text-[13px] text-text-muted">{t.levelsNote}</p>
      {divisionsOf(data.levels).map((d) => (
        <div key={d.id} className="mb-3">
          <h3 className="label-caps mb-1 text-text-muted">{d.name}</h3>
          <ul className="grid gap-x-6 sm:grid-cols-2 xl:grid-cols-3">
            {d.levels.map((l) => (
              <li key={`${l.division_id}:${l.level_code}`} className="flex min-h-14 items-center gap-3 border-b border-rule py-1.5">
                <span className="w-24 shrink-0 text-sm font-extrabold">{l.level}</span>
                {l.takes_marks ? (
                  <span className="min-w-0 flex-1">
                    <select aria-label={t.levelScale(l.level)} value={l.scale_id ?? ''} disabled={!data.can_edit} onChange={(e) => void choose(l.division_id, l.level_code, e.target.value)} className={field}>
                      {l.scale_id === null && <option value="">{t.chooseScale}</option>}
                      {data.scales
                        .filter((x) => !x.retired || x.id === l.scale_id)
                        .map((x) => (
                          <option key={x.id} value={x.id}>
                            {x.name}
                          </option>
                        ))}
                    </select>
                  </span>
                ) : (
                  <span className="text-[13px] text-text-muted">{t.noMarks}</span>
                )}
              </li>
            ))}
          </ul>
        </div>
      ))}
    </section>
  )
}

/** The levels grouped by their division, in the order given. */
function divisionsOf<L extends { division_id: string; division: string }>(levels: L[]): { id: string; name: string; levels: L[] }[] {
  const out: { id: string; name: string; levels: L[] }[] = []

  for (const l of levels) {
    const d = out.find((x) => x.id === l.division_id)
    if (d !== undefined) d.levels.push(l)
    else out.push({ id: l.division_id, name: l.division, levels: [l] })
  }

  return out
}

// ── Grading plans ────────────────────────────────────────────────────────────

function Plans() {
  const scales = useLoad('assessment:scales', () => assessmentApi.scales())
  const academics = useLoad('academics', () => setupApi.academics())
  const { query } = useLocation()

  if (scales.data === undefined) return <Waiting phase={scales.phase} what={t.tabs.plans} labels={copy.loading} rows={6} onRetry={scales.reload} />
  if (academics.phase === 'refused') return <Message text={t.termsRefused} />
  if (academics.data === undefined) return <Waiting phase={academics.phase} what={t.tabs.plans} labels={copy.loading} rows={6} onRetry={academics.reload} />

  const levels = scales.data.levels.filter((l) => l.takes_marks)
  const level = levels.find((l) => l.division_id === query.get('division') && l.level_code === query.get('level')) ?? levels[0]
  if (level === undefined) return <Message text={t.noScale} />

  const terms = academics.data.levels.find((d) => d.division_id === level.division_id)?.terms ?? []
  const today = new Date().toISOString().slice(0, 10)
  const term = terms.find((x) => x.id === query.get('period')) ?? terms.find((x) => x.starts_on <= today && today <= x.ends_on) ?? terms[0]
  if (term === undefined) return <Message text={t.noTerms} />

  const subject = query.get('subject')
  const go = (patch: { division?: string; level?: string; period?: string; subject?: string | null }) => {
    const next = {
      division: level.division_id,
      level: level.level_code,
      period: term.id,
      subject,
      ...patch,
    }
    navigate(`/settings/assessment?division=${next.division}&level=${encodeURIComponent(next.level)}&period=${next.period}${next.subject !== null ? `&subject=${next.subject}` : ''}`)
  }

  return (
    <>
      <div className="flex flex-wrap items-end gap-x-4 gap-y-2.5 border-b border-rule bg-sheet px-4 py-3 md:px-[clamp(16px,3vw,40px)]">
        <div className="flex basis-full flex-col gap-2">
          {divisionsOf(levels).map((d) => (
            <div key={d.id} role="group" aria-label={d.name} className="flex flex-wrap items-center gap-1.5">
              <span className="label-caps w-24 shrink-0 text-text-muted">{d.name}</span>
              {d.levels.map((l) => {
                const on = l === level

                return (
                  <button
                    key={`${l.division_id}:${l.level_code}`}
                    type="button"
                    aria-pressed={on}
                    onClick={() =>
                      go({
                        division: l.division_id,
                        level: l.level_code,
                        subject: null,
                      })
                    }
                    className={`h-11 border-2 border-ink px-3 text-sm font-extrabold ${on ? 'bg-ink text-sheet' : 'bg-sheet hover:bg-field'}`}
                  >
                    {l.level}
                  </button>
                )
              })}
            </div>
          ))}
        </div>
        <label className="w-[240px]">
          <span className="label-caps mb-1.5 block text-text-muted">{t.term}</span>
          <select value={term.id} onChange={(e) => go({ period: e.target.value })} className={field}>
            {terms.map((x) => (
              <option key={x.id} value={x.id}>
                {x.name}
              </option>
            ))}
          </select>
        </label>
      </div>
      <PlanFor
        key={`${level.division_id}:${level.level_code}:${term.id}:${subject ?? ''}`}
        q={{
          division_id: level.division_id,
          level_code: level.level_code,
          period_id: term.id,
          subject_id: subject,
        }}
        go={go}
      />
    </>
  )
}

function Message({ text }: { text: string }) {
  return (
    <div className="px-4 py-5 md:px-[clamp(16px,3vw,40px)]">
      <Notice tone="wrong" title={text} />
    </div>
  )
}

function PlanFor({
  q,
  go,
}: {
  q: {
    division_id: string
    level_code: string
    period_id: string
    subject_id: string | null
  }
  go: (patch: { subject?: string | null }) => void
}) {
  const loaded = useLoad(`assessment:plan:${q.division_id}:${q.level_code}:${q.period_id}:${q.subject_id ?? ''}`, () => assessmentApi.plans(q))
  const data = loaded.data
  const [giving, setGiving] = useState('')

  if (data === undefined) return <Waiting phase={loaded.phase} what={t.tabs.plans} labels={copy.loading} rows={6} onRetry={loaded.reload} />

  const subjectName = data.subjects.find((x) => x.id === data.subject_id)?.name ?? null
  const own = data.subjects.filter((x) => x.own !== null)
  const without = data.subjects.filter((x) => x.own === null && x.id !== data.subject_id)

  return (
    <div className="flex flex-1 flex-wrap items-stretch bg-sheet">
      <section className="min-w-0 flex-[3_1_520px] px-4 pt-4.5 pb-6 md:px-7">
        <PlanEditor key={`${data.plan.id ?? 'default'}:${data.plan.version}`} data={data} q={q} title={subjectName !== null ? t.subjectPlan(subjectName, data.level.label) : t.schoolPlan(data.level.label)} onSaved={loaded.set} />
      </section>
      <aside aria-label={t.subjectsTitle} className="min-w-0 flex-[1_1_300px] border-ink bg-paper px-4 pt-4.5 pb-6 max-[767px]:border-t-2 md:border-l-2 md:px-6">
        <h2 className="type-display mb-2.5 text-[22px] leading-none">{t.subjectsTitle}</h2>
        <ul className="mb-3">
          <li className="border-b border-rule">
            <button
              type="button"
              aria-pressed={data.subject_id === null}
              onClick={() => go({ subject: null })}
              className={`flex min-h-12 w-full items-center gap-2 py-1.5 text-left text-sm ${data.subject_id === null ? 'font-extrabold' : ''}`}
            >
              <span className="flex-1">{t.allSubjects}</span>
              <Tag tone="plain">{t.schoolPlanTag}</Tag>
            </button>
          </li>
          {own.map((x) => (
            <li key={x.id} className="border-b border-rule">
              <button
                type="button"
                aria-pressed={data.subject_id === x.id}
                aria-label={t.openPlan(x.name)}
                onClick={() => go({ subject: x.id })}
                className={`flex min-h-12 w-full items-center gap-2 py-1.5 text-left text-sm ${data.subject_id === x.id ? 'font-extrabold' : ''}`}
              >
                <span className="min-w-0 flex-1">
                  <span className="block">{x.name}</span>
                  <span className="block text-xs text-text-muted">{x.own?.summary}</span>
                </span>
                <Tag tone="done">{t.ownPlanTag}</Tag>
              </button>
            </li>
          ))}
        </ul>
        {data.can_edit && without.length > 0 && (
          <div className="flex flex-wrap items-end gap-2">
            <div className="min-w-0 flex-1">
              <Select label={t.chooseSubject} value={giving} onChange={(e) => setGiving(e.target.value)}>
                <option value="">{t.chooseSubjectFirst}</option>
                {without.map((x) => (
                  <option key={x.id} value={x.id}>
                    {x.name}
                  </option>
                ))}
              </Select>
            </div>
            <Button variant="secondary" disabled={giving === ''} onClick={() => go({ subject: giving })}>
              {t.give}
            </Button>
          </div>
        )}
        <p className="mt-3 text-[13px] text-text-muted">{t.subjectsNote}</p>
      </aside>
    </div>
  )
}

function PlanEditor({
  data,
  q,
  title,
  onSaved,
}: {
  data: PlansState
  q: {
    division_id: string
    level_code: string
    period_id: string
    subject_id: string | null
  }
  title: string
  onSaved: (next: PlansState) => void
}) {
  const [mode, setMode] = useState(data.plan.mode)
  const [parts, setParts] = useState<PlanPart[]>(data.plan.parts)
  const [reason, setReason] = useState('')
  const [failed, setFailed] = useState<string | null>(null)
  const [working, setWorking] = useState(false)
  const edit = data.can_edit
  const total = parts.reduce((sum, p) => sum + (Number.isFinite(p.weight) ? p.weight : 0), 0)
  const kinds = parts.flatMap((p) => p.kinds)
  const twice = kinds.length !== new Set(kinds).size
  const nextVersion = data.versions.reduce((max, v) => Math.max(max, v.version), 0) + 1
  const ok = parts.length > 0 && total === 100 && !twice && parts.every((p) => p.label.trim() !== '') && (!data.begun || reason.trim() !== '')
  const set = (i: number, patch: Partial<PlanPart>) => setParts((all) => all.map((p, j) => (j === i ? { ...p, ...patch } : p)))
  const kindLabel = (k: string) => data.kinds.find((x) => x.value === k)?.label ?? k

  const save = async () => {
    setWorking(true)
    setFailed(null)
    const r = await assessmentApi.savePlan({
      ...q,
      mode,
      parts,
      reason: reason.trim(),
    })
    setWorking(false)
    if (r.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: r.data.waiting !== null && r.data.waiting.mine ? t.planAsked : t.planSaved })
      onSaved(r.data)
    } else setFailed(r.kind === 'invalid' ? (Object.values(r.errors)[0]?.[0] ?? s.failed) : r.kind === 'refused' ? s.refused : s.failed)
  }

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault()
        void save()
      }}
    >
      <div className="mb-3 flex flex-wrap items-baseline gap-2.5">
        <h2 className="type-display flex-1 text-[30px] leading-none">{title}</h2>
        <span className="text-[13px] font-bold">{t.inForce(data.period.name, data.plan.version, data.plan.default)}</span>
      </div>
      <p className="mb-3 max-w-[70ch] text-[13px] text-text-muted">{t.appliesTo(data.level.label)}</p>
      {data.waiting !== null && (
        <div className="mb-3">
          <WaitingFor
            title={t.planWaiting(data.waiting.summary)}
            askedBy={data.waiting.asked_by}
            waitingFor={data.waiting.waiting_for}
            requestId={data.waiting.id}
            mine={data.waiting.mine}
            onWithdrawn={() => void assessmentApi.plans(q).then((next) => next !== null && next !== 'refused' && onSaved(next))}
          >
            <span className="text-[13px]">“{data.waiting.reason}”</span>
          </WaitingFor>
        </div>
      )}
      {failed !== null && (
        <div className="mb-3">
          <Notice tone="wrong" title={failed} />
        </div>
      )}
      <fieldset className="mb-4">
        <legend className="label-caps mb-1.5 text-text-muted">{t.how}</legend>
        <div className="grid gap-2 sm:grid-cols-2">
          {data.modes.map((m) => {
            const on = mode === m.value

            return (
              <label
                key={m.value}
                className={`flex min-h-11 cursor-pointer flex-col gap-1 border-2 p-3.5 ${on ? 'border-ink bg-ink text-sheet' : 'border-rule-dashed bg-sheet'} has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-ink`}
              >
                <input type="radio" name="mode" className="sr-only" checked={on} disabled={!edit} onChange={() => setMode(m.value as typeof mode)} />
                <span className="type-display text-xl leading-none">{m.label}</span>
                <span className={`text-[13px] ${on ? 'text-on-ink-soft' : 'text-text-muted'}`}>{t.modeNotes[m.value] ?? ''}</span>
              </label>
            )
          })}
        </div>
      </fieldset>

      <ol className="flex flex-col gap-3">
        {parts.map((p, i) => (
          <li key={i} className="border-2 border-ink bg-sheet p-3">
            <div className="grid gap-2.5 sm:grid-cols-[minmax(0,1fr)_96px_auto]">
              <input aria-label={t.partFor(i + 1)} value={p.label} disabled={!edit} maxLength={60} onChange={(e) => set(i, { label: e.target.value })} className={`${field} font-extrabold`} />
              <span className="flex items-center gap-1">
                <input
                  aria-label={t.weightFor(p.label)}
                  inputMode="numeric"
                  value={String(p.weight)}
                  disabled={!edit}
                  onChange={(e) =>
                    set(i, {
                      weight: Number(e.target.value.replace(/[^0-9]/g, '') || 0),
                    })
                  }
                  className={`${field} ref text-right`}
                />
                <span aria-hidden="true">{t.percent}</span>
              </span>
              {edit && parts.length > 1 && (
                <button type="button" onClick={() => setParts((all) => all.filter((_, j) => j !== i))} className="h-11 px-2 text-sm font-extrabold text-red-text underline underline-offset-[3px]">
                  {t.removePart(p.label)}
                </button>
              )}
            </div>
            <fieldset className="mt-2.5">
              <legend className="label-caps mb-1.5 text-text-muted">{t.colTakes}</legend>
              <div className="flex flex-wrap gap-1.5">
                {data.kinds.map((k) => {
                  const on = p.kinds.includes(k.value)
                  const elsewhere = !on && parts.some((other, j) => j !== i && other.kinds.includes(k.value))

                  return (
                    <button
                      key={k.value}
                      type="button"
                      aria-pressed={on}
                      aria-label={t.kindIn(k.label, p.label)}
                      disabled={!edit || elsewhere}
                      onClick={() =>
                        set(i, {
                          kinds: on ? p.kinds.filter((x) => x !== k.value) : [...p.kinds, k.value],
                        })
                      }
                      className={`min-h-11 border-2 px-2.5 text-[13px] font-bold ${on ? 'border-ink bg-ink text-sheet' : elsewhere ? 'border-dashed border-rule-dashed text-text-muted' : 'border-ink bg-sheet hover:bg-field'}`}
                    >
                      {k.label}
                    </button>
                  )
                })}
              </div>
            </fieldset>
            <div className="mt-2.5 grid gap-2.5 sm:grid-cols-2">
              <label>
                <span className="label-caps mb-1.5 block text-text-muted">{t.colRule}</span>
                <span className="flex gap-2">
                  <select
                    aria-label={t.ruleFor(p.label)}
                    value={p.rule}
                    disabled={!edit}
                    onChange={(e) =>
                      set(i, {
                        rule: e.target.value as PlanPart['rule'],
                        best_n: e.target.value === 'best_n' ? (p.best_n ?? 3) : null,
                      })
                    }
                    className={field}
                  >
                    {data.rules.map((r) => (
                      <option key={r.value} value={r.value}>
                        {r.label}
                      </option>
                    ))}
                  </select>
                  {p.rule === 'best_n' && (
                    <input
                      aria-label={t.bestNFor(p.label)}
                      inputMode="numeric"
                      value={String(p.best_n ?? '')}
                      disabled={!edit}
                      onChange={(e) =>
                        set(i, {
                          best_n: Number(e.target.value.replace(/[^0-9]/g, '') || 0),
                        })
                      }
                      className={`${field} ref w-16 shrink-0 text-right`}
                    />
                  )}
                </span>
              </label>
              <label>
                <span className="label-caps mb-1.5 block text-text-muted">{t.colMissing}</span>
                <select aria-label={t.missingFor(p.label)} value={p.missing} disabled={!edit} onChange={(e) => set(i, { missing: e.target.value as PlanPart['missing'] })} className={field}>
                  {data.missing.map((m) => (
                    <option key={m.value} value={m.value}>
                      {m.label}
                    </option>
                  ))}
                </select>
              </label>
            </div>
            <span className="sr-only">{p.kinds.map(kindLabel).join(', ')}</span>
          </li>
        ))}
      </ol>

      <div className="mt-3 flex flex-wrap items-center gap-3">
        {edit && (
          <button
            type="button"
            onClick={() =>
              setParts((all) => [
                ...all,
                {
                  key: `part_${Date.now().toString(36)}`,
                  label: t.newPart,
                  weight: 0,
                  kinds: [],
                  rule: 'average',
                  best_n: null,
                  missing: 'left_out',
                },
              ])
            }
            className="h-11 border-2 border-dashed border-rule-dashed px-3 text-sm font-extrabold hover:bg-field"
          >
            {t.addPart}
          </button>
        )}
        <span className="ml-auto flex items-center gap-2 text-sm font-extrabold">
          {t.total(total)} <Tag tone={total === 100 ? 'done' : 'wrong'}>{total === 100 ? t.totalOk : t.totalWrong}</Tag>
        </span>
      </div>
      {twice && <p className="mt-2 text-[13px] font-bold text-red-text">{t.kindTwice}</p>}
      {parts.length === 0 && <p className="mt-2 text-[13px] font-bold text-red-text">{t.noParts}</p>}
      <p className="mt-3 text-sm">{data.scale !== null ? t.gradesWith(data.scale.name) : t.noScale}</p>

      {edit && (
        <footer className="mt-5 grid gap-3 border-t-2 border-ink pt-4">
          {data.begun && <Input label={t.reason} required hint={t.reasonHint} value={reason} onChange={(e) => setReason(e.target.value)} maxLength={255} />}
          <div className="flex flex-wrap items-center gap-3">
            <Button type="submit" busy={working} disabled={!ok}>
              {t.saveVersion(nextVersion)}
            </Button>
            <span className="flex-[1_1_240px] text-[13px] text-text-muted">{t.versionNote}</span>
          </div>
        </footer>
      )}

      <h3 className="label-caps mt-6 mb-1.5 text-text-muted">{t.versions}</h3>
      <ul className="text-[13px]">
        {data.versions.length === 0 && <li className="py-1.5 text-text-muted">{t.defaultVersion}</li>}
        {data.versions.map((v) => (
          <li key={v.version} className="border-b border-rule py-1.5">
            <strong>{t.versionLine(v.version, v.created_by, v.created_at !== null ? copy.assessment.when(v.created_at) : null)}</strong>
            <span className="block text-text-muted">{v.summary}</span>
            {v.reason !== null && <span className="block">{v.reason}</span>}
          </li>
        ))}
      </ul>
    </form>
  )
}
