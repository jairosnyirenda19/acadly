import { useLoad } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { Input, Select } from '../setup/parts'
import { PageHead, SettingsTabs, Tag } from '../settings/parts'
import type { Division } from '../shell/divisions'
import { firstError, programmesApi, type Department, type DepartmentInput, type FacultyInput, type Structure } from './api'
import { DivisionChoice, Fact, Go, NoDivision, Sheet, useProgrammeDivision } from './parts'

const t = copy.programmes
const s = t.structure

const day = (iso: string) =>
  new Date(`${iso}T00:00:00`).toLocaleDateString('en-GB', {
    day: 'numeric',
    month: 'short',
  })

/** Programmes › Faculties and departments (D-097, the approved board). */
export function StructureScreen() {
  const { division, choices, choose } = useProgrammeDivision()

  if (division === null) return <NoDivision />

  return <Loaded key={division.id} division={division} choices={choices} choose={choose} />
}

function Loaded({ division, choices, choose }: { division: Division; choices: Division[]; choose: (id: string) => void }) {
  const loaded = useLoad(`programmes:structure:${division.id}`, () => programmesApi.structure(division.id))

  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={s.title} labels={copy.loading} rows={6} onRetry={loaded.reload} />

  return <Page division={division} choices={choices} choose={choose} data={loaded.data} reload={loaded.reload} />
}

type Editing = { kind: 'faculties' } | { kind: 'department'; department: DepartmentInput } | null

function Page({ division, choices, choose, data, reload }: { division: Division; choices: Division[]; choose: (id: string) => void; data: Structure; reload: () => void }) {
  const [selected, setSelected] = useState<string | null>(data.faculties.flatMap((f) => f.departments)[0]?.id ?? null)
  const [editing, setEditing] = useState<Editing>(null)
  const department = data.faculties.flatMap((f) => f.departments.map((d) => ({ ...d, faculty: f }))).find((d) => d.id === selected) ?? null
  const periodsWord = (division.vocabulary as Record<string, { many: string } | undefined>).period?.many ?? ''
  const words = Object.values(division.vocabulary as Record<string, { one: string } | undefined>)
    .map((w) => w?.one)
    .filter((w): w is string => w !== undefined)

  const newDepartment = () =>
    setEditing({
      kind: 'department',
      department: {
        id: null,
        faculty_id: data.faculties.find((f) => !f.retired)?.id ?? '',
        name: '',
        code: '',
        prefixes: [],
        head_user_id: null,
        retired: false,
      },
    })

  return (
    <>
      <SettingsTabs />
      <PageHead
        kicker={t.kicker}
        title={s.title}
        intro={s.intro}
        actions={
          <>
            <DivisionChoice choices={choices} division={division} choose={choose} />
            {data.can.manage && (
              <Button variant="secondary" onClick={() => setEditing({ kind: 'faculties' })}>
                {data.faculties.length === 0 ? s.addFaculty : s.editFaculties}
              </Button>
            )}
            {data.can.manage && data.faculties.some((f) => !f.retired) && <Button onClick={newDepartment}>{s.addDepartment}</Button>}
          </>
        }
      />
      <div className="grid items-start gap-5 px-[clamp(16px,3vw,40px)] pb-8 xl:grid-cols-[440px_minmax(0,1fr)]">
        <Sheet label={s.facultiesTitle}>
          <table className="w-full border-collapse text-[15px]">
            <thead>
              <tr>
                <th className="label-caps border-b-2 border-ink px-3 py-2 text-left text-text-muted">{s.columns.place}</th>
                <th className="label-caps w-16 border-b-2 border-ink px-2 py-2 text-left text-text-muted">{s.columns.programmes}</th>
                <th className="label-caps w-16 border-b-2 border-ink px-2 py-2 text-left text-text-muted">{s.columns.courses}</th>
                <th className="label-caps w-14 border-b-2 border-ink px-2 py-2 text-left text-text-muted">{s.columns.staff}</th>
              </tr>
            </thead>
            <tbody>
              {data.faculties.length === 0 && (
                <tr>
                  <td colSpan={4} className="px-3 py-3 text-text-muted">
                    {s.noFaculty}
                  </td>
                </tr>
              )}
              {data.faculties.map((f) => (
                <FacultyRows
                  key={f.id}
                  faculty={f}
                  selected={selected}
                  onSelect={(id) => {
                    setSelected(id)
                    setEditing(null)
                  }}
                />
              ))}
            </tbody>
          </table>
        </Sheet>

        <div className="grid min-w-0 gap-4">
          {editing?.kind === 'faculties' && (
            <FacultiesEditor
              division={division.id}
              data={data}
              onDone={(saved) => {
                setEditing(null)
                if (saved) reload()
              }}
            />
          )}
          {editing?.kind === 'department' && (
            <DepartmentEditor
              division={division.id}
              data={data}
              start={editing.department}
              onDone={(id) => {
                setEditing(null)
                if (id !== null) {
                  setSelected(id)
                  reload()
                }
              }}
            />
          )}
          {editing === null && department !== null && (
            <DepartmentView
              department={department}
              facultyName={department.faculty.name}
              programmes={data.programmes.filter((p) => p.department_id === department.id)}
              canManage={data.can.manage}
              onChange={() =>
                setEditing({
                  kind: 'department',
                  department: {
                    id: department.id,
                    faculty_id: department.faculty.id,
                    name: department.name,
                    code: department.code,
                    prefixes: department.prefixes,
                    head_user_id: department.head?.id ?? null,
                    retired: department.retired,
                  },
                })
              }
            />
          )}
          {editing === null && department === null && data.faculties.length > 0 && <p className="text-text-muted">{s.chooseDepartment}</p>}

          <div className="grid gap-4 md:grid-cols-2">
            <Sheet className="grid gap-1.5 px-4 py-3.5">
              <strong>{s.wordsTitle}</strong>
              <span className="text-text-muted">{words.join(' · ')}</span>
              <span className="text-[13px] text-text-muted">{s.wordsNote}</span>
            </Sheet>
            <Sheet className="grid gap-1.5 px-4 py-3.5">
              <strong>{s.yearTitle(periodsWord)}</strong>
              {data.periods.length === 0 && <span className="text-text-muted">{s.noYear}</span>}
              {data.periods.map((p) => (
                <span key={p.id} className="flex justify-between gap-3 border-b border-rule py-1 last:border-b-0">
                  <span>{p.name}</span>
                  <span className="text-text-muted">{s.semesterLine(day(p.starts_on), day(p.ends_on))}</span>
                </span>
              ))}
            </Sheet>
          </div>
        </div>
      </div>
    </>
  )
}

function FacultyRows({ faculty, selected, onSelect }: { faculty: Structure['faculties'][number]; selected: string | null; onSelect: (id: string) => void }) {
  const sum = (key: 'programmes' | 'courses' | 'staff') => faculty.departments.reduce((n, d) => n + d[key], 0)

  return (
    <>
      <tr className="bg-field">
        <td className="border-b border-rule px-3 py-2.5 font-extrabold">
          {faculty.name} {faculty.retired && <Tag tone="locked">{t.retired}</Tag>}
        </td>
        <td className="border-b border-rule px-2 py-2.5">{sum('programmes')}</td>
        <td className="border-b border-rule px-2 py-2.5">{sum('courses')}</td>
        <td className="border-b border-rule px-2 py-2.5">{sum('staff')}</td>
      </tr>
      {faculty.departments.map((d) => (
        <tr key={d.id} className={d.id === selected ? 'bg-teal-wash' : ''}>
          <td className={`border-b border-rule py-0 pr-3 pl-6 ${d.id === selected ? 'border-l-4 border-l-teal' : ''}`}>
            <button
              type="button"
              aria-current={d.id === selected ? 'true' : undefined}
              onClick={() => onSelect(d.id)}
              className="min-h-11 w-full text-left text-ink hover:underline"
            >
              {d.name} {d.retired && <Tag tone="locked">{t.retired}</Tag>}
            </button>
          </td>
          <td className="border-b border-rule px-2">{d.programmes}</td>
          <td className="border-b border-rule px-2">{d.courses}</td>
          <td className="border-b border-rule px-2">{d.staff}</td>
        </tr>
      ))}
      {faculty.departments.length === 0 && !faculty.retired && (
        <tr>
          <td colSpan={4} className="border-b border-rule bg-marigold-wash px-3 py-2 text-sm">
            <strong aria-hidden="true">! </strong>
            {s.noDepartment}
          </td>
        </tr>
      )}
    </>
  )
}

function DepartmentView({
  department,
  facultyName,
  programmes,
  canManage,
  onChange,
}: {
  department: Department
  facultyName: string
  programmes: Structure['programmes']
  canManage: boolean
  onChange: () => void
}) {
  return (
    <Sheet className="grid gap-3.5 px-5 py-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="grid">
          <span className="text-[13px] text-text-muted">{facultyName}</span>
          <h2 className="type-display text-[28px] leading-tight">{s.department(department.name)}</h2>
        </div>
        {canManage && (
          <Button variant="secondary" onClick={onChange}>
            {t.change}
          </Button>
        )}
      </div>
      <div className="grid border border-rule sm:grid-cols-3">
        <Fact label={s.head}>{department.head?.name ?? s.noHead}</Fact>
        <Fact label={t.code}>{department.code}</Fact>
        <Fact label={s.prefixes}>{department.prefixes.length === 0 ? t.none : department.prefixes.join(' · ')}</Fact>
      </div>
      <div className="grid">
        <div className="label-caps border-b-2 border-ink pb-1.5 text-text-muted">{s.runs}</div>
        {programmes.length === 0 && <p className="py-2.5 text-text-muted">{s.noProgrammes}</p>}
        {programmes.map((p) => (
          <div key={p.id} className="flex flex-wrap justify-between gap-2 border-b border-rule py-2.5 last:border-b-0">
            <span>
              <Go to={`/programmes/p/${p.id}`}>
                <strong>{p.name}</strong>
              </Go>{' '}
              · {s.programmeLine(p.code, p.years, p.credits)}
            </span>
            {p.retired ? (
              <Tag tone="locked">{t.retired}</Tag>
            ) : p.released && p.open ? (
              <span className="font-extrabold text-teal">✓ {t.inUse}</span>
            ) : (
              <span className="text-text-muted">{p.released ? s.closed : s.draftOnly}</span>
            )}
          </div>
        ))}
      </div>
    </Sheet>
  )
}

function FacultiesEditor({ division, data, onDone }: { division: string; data: Structure; onDone: (saved: boolean) => void }) {
  const [rows, setRows] = useState<FacultyInput[]>(() => {
    const now = data.faculties.map((f) => ({
      id: f.id as string | null,
      name: f.name,
      code: f.code,
      retired: f.retired,
    }))
    return now.length === 0 ? [{ id: null, name: '', code: '', retired: false }] : now
  })
  const [busy, setBusy] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)
  const set = (i: number, patch: Partial<FacultyInput>) => setRows(rows.map((r, j) => (j === i ? { ...r, ...patch } : r)))

  const save = async () => {
    setBusy(true)
    setProblem(null)
    const result = await programmesApi.saveFaculties(
      division,
      rows.map((r) => ({ ...r, name: r.name.trim(), code: r.code.trim() })),
    )
    setBusy(false)
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: s.facultiesTitle })
      onDone(true)
    } else setProblem(firstError(result, t.failed))
  }

  return (
    <Sheet label={s.facultiesTitle} className="grid gap-3 px-5 py-4">
      <h2 className="type-display text-[26px] leading-none">{s.facultiesTitle}</h2>
      {problem !== null && (
        <Notice tone="wrong" title={t.notSaved}>
          {problem}
        </Notice>
      )}
      {rows.map((r, i) => (
        <div key={r.id ?? `new-${i}`} className={`grid items-end gap-3 border-b border-rule pb-2.5 sm:grid-cols-[minmax(0,1fr)_140px_auto] ${r.retired ? 'opacity-70' : ''}`}>
          <Input label={s.facultyName} value={r.name} onChange={(e) => set(i, { name: e.target.value })} />
          <Input label={t.code} value={r.code} onChange={(e) => set(i, { code: e.target.value })} />
          <label className="flex min-h-11 items-center gap-2 text-sm">
            <input type="checkbox" className="size-5 accent-ink" checked={r.retired} onChange={(e) => set(i, { retired: e.target.checked })} />
            {t.retired}
          </label>
        </div>
      ))}
      <div className="flex flex-wrap gap-2.5">
        <Button variant="secondary" onClick={() => setRows([...rows, { id: null, name: '', code: '', retired: false }])}>
          {s.addFaculty}
        </Button>
        <Button busy={busy} onClick={() => void save()}>
          {s.saveFaculties}
        </Button>
        <Button variant="secondary" onClick={() => onDone(false)}>
          {t.cancel}
        </Button>
      </div>
    </Sheet>
  )
}

function DepartmentEditor({ division, data, start, onDone }: { division: string; data: Structure; start: DepartmentInput; onDone: (id: string | null) => void }) {
  const [d, setD] = useState(start)
  const [prefixes, setPrefixes] = useState(start.prefixes.join(' '))
  const [busy, setBusy] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)

  const save = async () => {
    setBusy(true)
    setProblem(null)
    const result = await programmesApi.saveDepartment(division, {
      ...d,
      name: d.name.trim(),
      code: d.code.trim(),
      prefixes: prefixes.split(/[\s,]+/).filter((p) => p !== ''),
    })
    setBusy(false)
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: d.name })
      onDone(result.data.id)
    } else setProblem(firstError(result, t.failed))
  }

  return (
    <Sheet className="grid gap-3 px-5 py-4">
      <h2 className="type-display text-[26px] leading-none">{d.id === null ? s.newDepartment : s.department(start.name)}</h2>
      {problem !== null && (
        <Notice tone="wrong" title={t.notSaved}>
          {problem}
        </Notice>
      )}
      <div className="grid gap-3 sm:grid-cols-2">
        <Select label={s.faculty} value={d.faculty_id} onChange={(e) => setD({ ...d, faculty_id: e.target.value })}>
          {data.faculties
            .filter((f) => !f.retired || f.id === d.faculty_id)
            .map((f) => (
              <option key={f.id} value={f.id}>
                {f.name}
              </option>
            ))}
        </Select>
        <Input label={s.departmentName} value={d.name} onChange={(e) => setD({ ...d, name: e.target.value })} />
        <Input label={t.code} value={d.code} onChange={(e) => setD({ ...d, code: e.target.value })} />
        <Input label={s.prefixes} hint={s.prefixesHint} value={prefixes} onChange={(e) => setPrefixes(e.target.value)} />
        <Select
          label={s.head}
          value={d.head_user_id ?? ''}
          onChange={(e) =>
            setD({
              ...d,
              head_user_id: e.target.value === '' ? null : e.target.value,
            })
          }
        >
          <option value="">{s.noHead}</option>
          {data.staff.map((p) => (
            <option key={p.id} value={p.id}>
              {p.name}
            </option>
          ))}
        </Select>
        {d.id !== null && (
          <label className="flex min-h-11 items-end gap-2 pb-2 text-sm">
            <input type="checkbox" className="size-5 accent-ink" checked={d.retired} onChange={(e) => setD({ ...d, retired: e.target.checked })} />
            {t.retired}
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
