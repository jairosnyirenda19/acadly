import { navigate, useLoad } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { Input, Select } from '../setup/parts'
import { PageHead, SettingsTabs, Tag } from '../settings/parts'
import type { Division } from '../shell/divisions'
import { AWARDS, firstError, programmesApi, type Award, type ProgrammeInput, type Structure } from './api'
import { DivisionChoice, Go, NoDivision, Sheet, td, th, useProgrammeDivision } from './parts'

const t = copy.programmes
const l = t.list

/** Programmes › Programmes: every programme of the division, and a new one. */
export function ProgrammeListScreen() {
  const { division, choices, choose } = useProgrammeDivision()

  if (division === null) return <NoDivision />

  return <Loaded key={division.id} division={division} choices={choices} choose={choose} />
}

function Loaded({ division, choices, choose }: { division: Division; choices: Division[]; choose: (id: string) => void }) {
  const loaded = useLoad(`programmes:structure:${division.id}`, () => programmesApi.structure(division.id))
  const [adding, setAdding] = useState(false)

  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={l.title} labels={copy.loading} rows={6} onRetry={loaded.reload} />

  const data = loaded.data
  const departments = data.faculties.flatMap((f) => f.departments)
  const departmentName = (id: string) => departments.find((d) => d.id === id)?.name ?? ''
  const usable = departments.filter((d) => !d.retired)

  return (
    <>
      <SettingsTabs />
      <PageHead
        kicker={t.kicker}
        title={l.title}
        intro={l.intro}
        actions={
          <>
            <DivisionChoice choices={choices} division={division} choose={choose} />
            {data.can.manage && usable.length > 0 && !adding && <Button onClick={() => setAdding(true)}>{l.add}</Button>}
          </>
        }
      />
      <div className="grid gap-5 px-[clamp(16px,3vw,40px)] pb-8">
        {adding && (
          <ProgrammeForm
            division={division.id}
            departments={usable}
            start={{
              id: null,
              department_id: usable[0]?.id ?? '',
              code: '',
              name: '',
              award: 'bachelor',
              years: 4,
              semesters: 2,
              credits: 480,
              min_load: 45,
              max_load: 75,
              open: false,
              retired: false,
            }}
            locked={false}
            onDone={(id) => {
              setAdding(false)
              if (id !== null) navigate(`/programmes/p/${id}`)
            }}
          />
        )}
        <Sheet className="overflow-x-auto">
          <table className="w-full border-collapse text-[15px]">
            <thead>
              <tr>
                <th className={th}>{l.columns.code}</th>
                <th className={th}>{l.columns.name}</th>
                <th className={th}>{l.columns.award}</th>
                <th className={th}>{l.columns.length}</th>
                <th className={th}>{l.columns.credits}</th>
                <th className={th}>{l.columns.department}</th>
                <th className={th}>{l.columns.state}</th>
              </tr>
            </thead>
            <tbody>
              {data.programmes.length === 0 && (
                <tr>
                  <td colSpan={7} className={`${td} text-text-muted`}>
                    {l.none}
                  </td>
                </tr>
              )}
              {data.programmes.map((p) => (
                <tr key={p.id}>
                  <td className={`${td} font-extrabold`}>{p.code}</td>
                  <td className={td}>
                    <Go to={`/programmes/p/${p.id}`}>{p.name}</Go>
                  </td>
                  <td className={td}>{t.awards[p.award as Award] ?? p.award}</td>
                  <td className={td}>{l.years(p.years)}</td>
                  <td className={td}>{t.programme.creditsValue(p.credits)}</td>
                  <td className={td}>{departmentName(p.department_id)}</td>
                  <td className={td}>
                    <ProgrammeState programme={p} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </Sheet>
      </div>
    </>
  )
}

export function ProgrammeState({ programme }: { programme: Structure['programmes'][number] }) {
  if (programme.retired) return <Tag tone="locked">{t.retired}</Tag>
  if (!programme.released) return <Tag tone="plain">{l.draft}</Tag>

  return (
    <span className="flex flex-wrap gap-1.5">
      <Tag tone="done">✓ {l.released}</Tag>
      {programme.open && <Tag tone="plain">{l.open}</Tag>}
    </span>
  )
}

/** Adds or changes a programme. */
export function ProgrammeForm({
  division,
  departments,
  start,
  locked,
  onDone,
}: {
  division: string
  departments: { id: string; name: string }[]
  start: ProgrammeInput
  locked: boolean
  onDone: (id: string | null) => void
}) {
  const f = t.form
  const [p, setP] = useState(start)
  const [busy, setBusy] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)
  const num = (key: 'years' | 'semesters' | 'credits' | 'min_load' | 'max_load') => ({
    inputMode: 'numeric' as const,
    value: String(p[key]),
    onChange: (e: { target: { value: string } }) => setP({ ...p, [key]: Number(e.target.value.replace(/\D/g, '')) }),
  })

  const save = async () => {
    setBusy(true)
    setProblem(null)
    const result = await programmesApi.saveProgramme(division, {
      ...p,
      code: p.code.trim(),
      name: p.name.trim(),
    })
    setBusy(false)
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: p.name })
      onDone(result.data.id)
    } else setProblem(firstError(result, t.failed))
  }

  return (
    <Sheet className="grid gap-3 px-5 py-4">
      <h2 className="type-display text-[26px] leading-none">{p.id === null ? f.newTitle : f.editTitle}</h2>
      {problem !== null && (
        <Notice tone="wrong" title={t.notSaved}>
          {problem}
        </Notice>
      )}
      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <Input label={t.name} value={p.name} onChange={(e) => setP({ ...p, name: e.target.value })} />
        <Input label={t.code} value={p.code} onChange={(e) => setP({ ...p, code: e.target.value })} />
        <Select label={f.department} value={p.department_id} onChange={(e) => setP({ ...p, department_id: e.target.value })}>
          {departments.map((d) => (
            <option key={d.id} value={d.id}>
              {d.name}
            </option>
          ))}
        </Select>
        <Select label={f.award} value={p.award} onChange={(e) => setP({ ...p, award: e.target.value as Award })}>
          {AWARDS.map((a) => (
            <option key={a} value={a}>
              {t.awards[a]}
            </option>
          ))}
        </Select>
        <Input label={f.years} disabled={locked} {...num('years')} />
        <Input label={f.semesters} disabled={locked} {...num('semesters')} />
        <Input label={f.credits} disabled={locked} {...num('credits')} />
        <div className="grid grid-cols-2 gap-3">
          <Input label={f.minLoad} {...num('min_load')} />
          <Input label={f.maxLoad} {...num('max_load')} />
        </div>
      </div>
      {locked && <p className="text-[13px] text-text-muted">{f.locked}</p>}
      <div className="flex flex-wrap gap-x-5">
        <label className="flex min-h-11 items-center gap-2 text-sm">
          <input type="checkbox" className="size-5 accent-ink" checked={p.open} onChange={(e) => setP({ ...p, open: e.target.checked })} />
          {f.open}
        </label>
        {p.id !== null && (
          <label className="flex min-h-11 items-center gap-2 text-sm">
            <input type="checkbox" className="size-5 accent-ink" checked={p.retired} onChange={(e) => setP({ ...p, retired: e.target.checked })} />
            {f.retired}
          </label>
        )}
      </div>
      <div className="flex flex-wrap gap-2.5">
        <Button busy={busy} onClick={() => void save()}>
          {t.save}
        </Button>
        <Button variant="secondary" onClick={() => onDone(null)}>
          {t.cancel}
        </Button>
      </div>
    </Sheet>
  )
}
