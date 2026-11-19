import { useLoad } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { Input, Select } from '../setup/parts'
import { PageHead, SettingsTabs, SmallButton, Tag } from '../settings/parts'
import type { Division } from '../shell/divisions'
import { firstError, programmesApi, type Catalogue, type CourseInput } from './api'
import { DivisionChoice, NoDivision, Sheet, td, th, useProgrammeDivision } from './parts'

const t = copy.programmes
const k = t.catalogue

/** Programmes › Course catalogue (D-097, the approved board). */
export function CatalogueScreen() {
  const { division, choices, choose } = useProgrammeDivision()

  if (division === null) return <NoDivision />

  return <Loaded key={division.id} division={division} choices={choices} choose={choose} />
}

function Loaded({ division, choices, choose }: { division: Division; choices: Division[]; choose: (id: string) => void }) {
  const loaded = useLoad(`programmes:catalogue:${division.id}`, () => programmesApi.catalogue(division.id))
  const [find, setFind] = useState('')
  const [department, setDepartment] = useState('')
  const [editing, setEditing] = useState<{
    course: CourseInput
    locked: boolean
  } | null>(null)

  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={k.title} labels={copy.loading} rows={8} onRetry={loaded.reload} />

  const data = loaded.data
  const needle = find.trim().toLowerCase().replace(/\s+/g, '')
  const shown = data.courses.filter(
    (c) => (department === '' || c.department_id === department) && (needle === '' || `${c.code}${c.title}`.toLowerCase().replace(/\s+/g, '').includes(needle)),
  )
  const blank = (): CourseInput => ({
    id: null,
    department_id: data.departments[0]?.id ?? '',
    code: data.departments[0]?.prefixes[0] ?? '',
    title: '',
    credits: 15,
    lecture_hours: 3,
    tutorial_hours: 0,
    practical_hours: 0,
    needs: [],
    retired: false,
  })

  return (
    <>
      <SettingsTabs />
      <PageHead
        kicker={t.kicker}
        title={k.title}
        intro={k.intro}
        actions={
          <>
            <DivisionChoice choices={choices} division={division} choose={choose} />
            {data.can.manage && data.departments.length > 0 && editing === null && <Button onClick={() => setEditing({ course: blank(), locked: false })}>{k.add}</Button>}
          </>
        }
      />
      <div className="grid gap-4 px-[clamp(16px,3vw,40px)] pb-8">
        {editing !== null && (
          <CourseForm
            key={editing.course.id ?? 'new'}
            division={division.id}
            data={data}
            start={editing.course}
            locked={editing.locked}
            onDone={(saved) => {
              setEditing(null)
              if (saved) loaded.reload()
            }}
          />
        )}
        <div className="flex flex-wrap items-end gap-3">
          <div className="w-72">
            <Input label={k.find} placeholder={k.findHint} type="search" value={find} onChange={(e) => setFind(e.target.value)} />
          </div>
          <div className="w-64">
            <Select label={k.department} value={department} onChange={(e) => setDepartment(e.target.value)}>
              <option value="">{k.allDepartments}</option>
              {data.departments.map((d) => (
                <option key={d.id} value={d.id}>
                  {d.name}
                </option>
              ))}
            </Select>
          </div>
        </div>
        <Sheet className="overflow-x-auto">
          <table className="w-full border-collapse text-[15px]">
            <thead>
              <tr>
                <th className={th}>{k.columns.code}</th>
                <th className={th}>{k.columns.title}</th>
                <th className={th}>{k.columns.department}</th>
                <th className={th}>{k.columns.credits}</th>
                <th className={th}>{k.columns.hours}</th>
                <th className={th}>{k.columns.needs}</th>
                <th className={th}>{k.columns.used}</th>
                {data.can.manage && <th className={th} />}
              </tr>
            </thead>
            <tbody>
              {shown.length === 0 && (
                <tr>
                  <td colSpan={8} className={`${td} text-text-muted`}>
                    {k.none}
                  </td>
                </tr>
              )}
              {shown.map((c) => (
                <tr key={c.id} className={c.retired ? 'opacity-70' : ''}>
                  <td className={`${td} font-extrabold whitespace-nowrap`}>{c.code}</td>
                  <td className={td}>
                    {c.title} {c.retired && <Tag tone="locked">{t.retired}</Tag>}
                  </td>
                  <td className={td}>{c.department}</td>
                  <td className={td}>{c.credits}</td>
                  <td className={td}>{k.hours(c.lecture_hours, c.tutorial_hours, c.practical_hours)}</td>
                  <td className={td}>{c.needs.length === 0 ? <span className="text-text-muted">{t.none}</span> : c.needs.map((n) => n.code).join(' · ')}</td>
                  <td className={td}>{c.used_in.length === 0 ? <span className="text-text-muted">{k.notUsed}</span> : c.used_in.join(' · ')}</td>
                  {data.can.manage && (
                    <td className={`${td} text-right`}>
                      <SmallButton
                        onClick={() =>
                          setEditing({
                            locked: c.locked,
                            course: {
                              id: c.id,
                              department_id: c.department_id,
                              code: c.code,
                              title: c.title,
                              credits: c.credits,
                              lecture_hours: c.lecture_hours,
                              tutorial_hours: c.tutorial_hours,
                              practical_hours: c.practical_hours,
                              needs: c.needs.map((n) => n.id),
                              retired: c.retired,
                            },
                          })
                        }
                      >
                        {t.change}
                      </SmallButton>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </Sheet>
      </div>
    </>
  )
}

function CourseForm({ division, data, start, locked, onDone }: { division: string; data: Catalogue; start: CourseInput; locked: boolean; onDone: (saved: boolean) => void }) {
  const [c, setC] = useState(start)
  const [need, setNeed] = useState('')
  const [busy, setBusy] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)
  const code = (id: string) => data.courses.find((x) => x.id === id)?.code ?? ''
  const num = (key: 'credits' | 'lecture_hours' | 'tutorial_hours' | 'practical_hours') => ({
    inputMode: 'numeric' as const,
    value: String(c[key]),
    onChange: (e: { target: { value: string } }) => setC({ ...c, [key]: Number(e.target.value.replace(/\D/g, '')) }),
  })

  const save = async () => {
    setBusy(true)
    setProblem(null)
    const result = await programmesApi.saveCourse(division, {
      ...c,
      code: c.code.trim(),
      title: c.title.trim(),
    })
    setBusy(false)
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: c.title })
      onDone(true)
    } else setProblem(firstError(result, t.failed))
  }

  return (
    <Sheet className="grid gap-3 px-5 py-4">
      <h2 className="type-display text-[26px] leading-none">{c.id === null ? k.newTitle : k.editTitle}</h2>
      {problem !== null && (
        <Notice tone="wrong" title={t.notSaved}>
          {problem}
        </Notice>
      )}
      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <Input label={k.columns.code} hint={k.codeHint} value={c.code} onChange={(e) => setC({ ...c, code: e.target.value })} />
        <Input label={k.courseTitle} value={c.title} onChange={(e) => setC({ ...c, title: e.target.value })} />
        <Select label={k.department} value={c.department_id} onChange={(e) => setC({ ...c, department_id: e.target.value })}>
          {data.departments.map((d) => (
            <option key={d.id} value={d.id}>
              {d.name}
            </option>
          ))}
        </Select>
        <Input label={k.credits} disabled={locked} {...num('credits')} />
        <Input label={k.lecture} {...num('lecture_hours')} />
        <Input label={k.tutorial} {...num('tutorial_hours')} />
        <Input label={k.practical} {...num('practical_hours')} />
      </div>
      {locked && <p className="text-[13px] text-text-muted">{k.locked}</p>}
      <div className="grid gap-2">
        <span className="label-caps text-text-muted">{k.needs}</span>
        <div className="flex flex-wrap items-center gap-2">
          {c.needs.length === 0 && <span className="text-text-muted">{t.none}</span>}
          {c.needs.map((id) => (
            <span key={id} className="flex items-center gap-1.5 border-2 border-ink px-2 py-0.5">
              <strong>{code(id)}</strong>
              <button
                type="button"
                aria-label={`${copy.programmes.programme.remove} ${code(id)}`}
                className="px-1 font-extrabold"
                onClick={() => setC({ ...c, needs: c.needs.filter((n) => n !== id) })}
              >
                ×
              </button>
            </span>
          ))}
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <select aria-label={k.addNeed} value={need} onChange={(e) => setNeed(e.target.value)} className="h-10 min-w-56 border-b-2 border-ink bg-field px-2 text-sm">
            <option value="">{k.addNeed}</option>
            {data.courses
              .filter((x) => x.id !== c.id && !c.needs.includes(x.id) && !x.retired)
              .map((x) => (
                <option key={x.id} value={x.id}>
                  {x.code} · {x.title}
                </option>
              ))}
          </select>
          <SmallButton
            disabled={need === ''}
            onClick={() => {
              setC({ ...c, needs: [...c.needs, need] })
              setNeed('')
            }}
          >
            {copy.programmes.programme.add}
          </SmallButton>
        </div>
      </div>
      {c.id !== null && (
        <label className="flex min-h-11 items-center gap-2 text-sm">
          <input type="checkbox" className="size-5 accent-ink" checked={c.retired} onChange={(e) => setC({ ...c, retired: e.target.checked })} />
          {t.retired}
        </label>
      )}
      <div className="flex flex-wrap gap-2.5">
        <Button busy={busy} onClick={() => void save()}>
          {t.save}
        </Button>
        <Button variant="secondary" onClick={() => onDone(false)}>
          {t.cancel}
        </Button>
      </div>
    </Sheet>
  )
}
