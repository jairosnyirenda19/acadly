import { api, navigate, useLoad } from '@acadly/kernel'
import { Waiting } from '@acadly/ui'
import { useEffect, useState } from 'react'
import { copy } from '../copy'
import { useDivisionColour, usePalette } from '../charts/palette'
import { read } from '../setup/api'
import { useWorkIn } from '../shell/WorkIn'

const t = copy.schoolLists

/**
 * All learners of the school, read-only (D-100), in the whole-school
 * overview: grouped by division, every row naming its campus. Opening a
 * learner moves the console into the learner's division first.
 */
export function SchoolLearnersScreen() {
  const [typed, setTyped] = useState('')
  const [q, setQ] = useState('')
  const [division, setDivision] = useState('')
  const [campus, setCampus] = useState('')
  const { enter } = useWorkIn()
  const p = usePalette()
  const colour = useDivisionColour()

  useEffect(() => {
    const timer = window.setTimeout(() => setQ(typed.trim()), 300)

    return () => window.clearTimeout(timer)
  }, [typed])

  const query = { ...(q !== '' ? { q } : {}), ...(division !== '' ? { division } : {}), ...(campus !== '' ? { campus } : {}) }
  const loaded = useLoad(`school:learners:${q}:${division}:${campus}`, () => read(api.GET('/v1/school/learners', { params: { query } })))
  const data = loaded.data
  const open = (divisionId: string, learnerId: string) => {
    enter(divisionId)
    navigate(`/learners/${learnerId}`)
  }

  return (
    <div className="grid gap-3 px-[clamp(16px,3vw,40px)] pt-6 pb-8">
      <div>
        <div className="label-caps text-text-muted">{t.learnersKicker}</div>
        <h1 className="type-display mt-1 text-[clamp(48px,6vw,88px)] leading-[0.86]">{t.learnersTitle}</h1>
      </div>
      {data !== undefined && (
        <p className="flex flex-wrap gap-2 border-2 border-ink bg-sheet px-3.5 py-2.5 text-[14px]">
          <b>{t.readOnly}</b>
          <span>{t.learnersIntro(data.total, data.groups.length)}</span>
        </p>
      )}
      <div className="flex flex-wrap gap-2">
        <label className="flex h-11 min-w-[260px] flex-1 items-center border-b-2 border-ink bg-field px-3">
          <span className="sr-only">{t.find}</span>
          <input value={typed} onChange={(e) => setTyped(e.target.value)} placeholder={t.find} className="w-full bg-transparent text-[15px] outline-none" />
        </label>
        <label className="flex h-11 items-center border-b-2 border-ink bg-field px-2">
          <span className="sr-only">{t.division}</span>
          <select value={division} onChange={(e) => setDivision(e.target.value)} className="bg-transparent text-[15px]">
            <option value="">{t.everyDivision}</option>
            {data?.groups.map((g) => (
              <option key={g.division_id} value={g.division_id}>
                {g.division}
              </option>
            ))}
          </select>
        </label>
        <label className="flex h-11 items-center border-b-2 border-ink bg-field px-2">
          <span className="sr-only">{t.campus}</span>
          <select value={campus} onChange={(e) => setCampus(e.target.value)} className="bg-transparent text-[15px]">
            <option value="">{t.everyCampus}</option>
            {data?.campuses.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </select>
        </label>
      </div>

      {data === undefined ? (
        <Waiting phase={loaded.phase} what={t.learnersTitle} labels={copy.loading} rows={8} onRetry={loaded.reload} />
      ) : data.groups.length === 0 ? (
        <p className="text-text-muted">{t.none}</p>
      ) : (
        <div className="overflow-x-auto border-2 border-ink">
          <table className="w-full border-collapse bg-sheet text-[15px]">
            <thead>
              <tr>
                {[t.code, t.learner, t.className, t.campus].map((h) => (
                  <th key={h} scope="col" className="label-caps border-b-2 border-ink px-3 py-2 text-left text-text-muted">
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            {data.groups.map((g) => (
              <tbody key={g.division_id}>
                <tr>
                  <th colSpan={4} scope="rowgroup" className="border-b-2 border-ink bg-field px-3 py-2 text-left">
                    <span aria-hidden="true" className="mr-2 inline-block size-3 align-[-1px]" style={{ background: colour(g.division_id, p) }} />
                    <b>{g.division}</b> · {t.learners(g.count)}
                  </th>
                </tr>
                {g.rows.map((r) => (
                  <tr key={r.id} className="hover:bg-field">
                    <td className="border-b border-rule px-3 py-2 font-mono text-[13px]">{r.code}</td>
                    <td className="border-b border-rule px-3 py-2">
                      <a href={`/learners/${r.id}`} onClick={(e) => (e.preventDefault(), open(g.division_id, r.id))} className="font-extrabold text-ink">
                        {r.name}
                      </a>
                    </td>
                    <td className="border-b border-rule px-3 py-2">{r.class}</td>
                    <td className="border-b border-rule px-3 py-2">{r.campus}</td>
                  </tr>
                ))}
                {g.shown < g.count && (
                  <tr>
                    <td colSpan={4} className="border-b border-rule px-3 py-2 text-[13px] text-text-muted">
                      {t.shown(g.shown, g.count)}
                    </td>
                  </tr>
                )}
              </tbody>
            ))}
          </table>
        </div>
      )}
    </div>
  )
}
