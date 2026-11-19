import { useLoad } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useState, type ReactNode } from 'react'
import { copy } from '../copy'
import type { Saved } from '../setup/api'
import { Input, Select } from '../setup/parts'
import { SettingsTabs, SmallButton } from '../settings/parts'
import type { Division } from '../shell/divisions'
import { firstError, programmesApi, type Award, type Course, type DraftInput, type Problem, type ProgrammeView, type Version } from './api'
import { ProgrammeForm } from './ProgrammeListScreen'
import { Fact, Go, NoDivision, Sheet, td, th, useProgrammeDivision } from './parts'

const t = copy.programmes
const p = t.programme

/** Programmes › one programme and its curriculum versions (D-097, the approved board). */
export function ProgrammeScreen({ id }: { id: string }) {
  const { division } = useProgrammeDivision()
  const [version, setVersion] = useState<string | null>(null)

  if (division === null) return <NoDivision />

  return <Loaded key={`${division.id}:${version ?? ''}`} division={division} id={id} version={version} setVersion={setVersion} />
}

function Loaded({ division, id, version, setVersion }: { division: Division; id: string; version: string | null; setVersion: (v: string | null) => void }) {
  const loaded = useLoad(`programmes:programme:${id}:${version ?? ''}`, () => programmesApi.programme(division.id, id, version))
  const catalogue = useLoad(`programmes:catalogue:${division.id}`, () => programmesApi.catalogue(division.id))

  if (loaded.data === undefined || catalogue.data === undefined) {
    return <Waiting phase={loaded.data === undefined ? loaded.phase : catalogue.phase} what={t.list.title} labels={copy.loading} rows={8} onRetry={loaded.reload} />
  }

  return (
    <Page
      key={loaded.data.version?.id ?? 'none'}
      division={division.id}
      data={loaded.data}
      courses={catalogue.data.courses}
      departments={catalogue.data.departments}
      reload={loaded.reload}
      setVersion={setVersion}
    />
  )
}

interface Draft {
  groups: {
    key: string
    year: number
    semester: number
    label: string
    choose: number
  }[]
  courses: {
    course_id: string
    year: number
    semester: number
    group: string | null
  }[]
}

const toDraft = (v: Version): Draft => ({
  groups: v.groups.map((g) => ({
    key: g.id,
    year: g.year,
    semester: g.semester,
    label: g.label,
    choose: g.choose,
  })),
  courses: v.courses.map((c) => ({
    course_id: c.course_id,
    year: c.year,
    semester: c.semester,
    group: c.group_id,
  })),
})

function problemText(problem: Problem): string {
  const n = (k: string) => Number(problem[k] ?? 0)
  const w = (k: string) => String(problem[k] ?? '')
  switch (problem.kind) {
    case 'empty':
      return p.problem.empty(n('year'), n('semester'))
    case 'load':
      return p.problem.load(n('year'), n('semester'), n('credits'), n('min'), n('max'))
    case 'total':
      return p.problem.total(n('credits'), n('needed'))
    case 'needs':
      return p.problem.needs(w('course'), w('needs'))
    case 'retired':
      return p.problem.retired(w('course'))
    default:
      return p.problem.group(w('label'), n('choose'), n('options'))
  }
}

function Page({
  division,
  data,
  courses,
  departments,
  reload,
  setVersion,
}: {
  division: string
  data: ProgrammeView
  courses: Course[]
  departments: { id: string; name: string }[]
  reload: () => void
  setVersion: (v: string | null) => void
}) {
  const programme = data.programme
  const version = data.version
  const [draft, setDraft] = useState<Draft | null>(version === null ? null : toDraft(version))
  const [dirty, setDirty] = useState(false)
  const [year, setYear] = useState(1)
  const [editing, setEditing] = useState(false)
  const [busy, setBusy] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)
  const editable = data.can.manage && version !== null && !version.released
  const hasDraft = data.versions.some((v) => v.released_at === null)
  const released = data.versions.some((v) => v.released_at !== null)
  const byId = new Map(courses.map((c) => [c.id, c]))
  const credits = (courseId: string) => byId.get(courseId)?.credits ?? 0

  const change = (next: Draft) => {
    setDraft(next)
    setDirty(true)
  }

  // Credits as drawn now, the same way the server counts them.
  const slotCredits = (y: number, s: number): number => {
    if (draft === null) return 0
    const core = draft.courses.filter((c) => c.year === y && c.semester === s && c.group === null).reduce((n, c) => n + credits(c.course_id), 0)
    const groups = draft.groups
      .filter((g) => g.year === y && g.semester === s)
      .reduce(
        (n, g) =>
          n +
          draft.courses
            .filter((c) => c.group === g.key)
            .map((c) => credits(c.course_id))
            .sort((a, b) => a - b)
            .slice(0, g.choose)
            .reduce((x, c) => x + c, 0),
        0,
      )
    return core + groups
  }
  const yearCredits = (y: number) => Array.from({ length: programme.semesters }, (_, i) => slotCredits(y, i + 1)).reduce((a, b) => a + b, 0)

  const act = async <T,>(run: () => Promise<Saved<T>>, done: string) => {
    setBusy(true)
    setProblem(null)
    const result = await run()
    setBusy(false)
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: done })
      setDirty(false)
      reload()
    } else setProblem(firstError(result, t.failed))
  }

  const saveDraft = () => draft !== null && version !== null && void act(() => programmesApi.saveDraft(division, version.id, draft satisfies DraftInput), p.saveDraft)
  const release = () => version !== null && void act(() => programmesApi.release(division, version.id), p.released)
  const newVersion = async () => {
    setBusy(true)
    const result = await programmesApi.newVersion(division, programme.id)
    setBusy(false)
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: p.newVersion })
      setVersion(result.data.id)
    } else setProblem(firstError(result, t.failed))
  }

  const checks = version?.problems ?? []
  const has = (...kinds: string[]) => checks.filter((c) => kinds.includes(String(c.kind)))

  return (
    <>
      <SettingsTabs />
      <div className="grid gap-3.5 border-b border-rule bg-sheet px-[clamp(16px,3vw,40px)] pt-6 pb-5">
        <span className="text-[13px] text-text-muted">
          <Go to="/programmes/list">{t.list.title}</Go> · {p.crumbs(programme.faculty, programme.department)}
        </span>
        <div className="flex flex-wrap items-end justify-between gap-3">
          <h1 className="type-display text-[clamp(36px,4vw,48px)] leading-none">
            {programme.name} · {programme.code}
          </h1>
          <div className="flex flex-wrap gap-2.5">
            {data.can.manage && !editing && (
              <Button variant="secondary" onClick={() => setEditing(true)}>
                {t.change}
              </Button>
            )}
            {data.can.manage && released && !hasDraft && (
              <Button variant="secondary" busy={busy} onClick={() => void newVersion()}>
                {p.newVersion}
              </Button>
            )}
          </div>
        </div>
        <div className="grid border border-rule sm:grid-cols-3 xl:grid-cols-5">
          <Fact label={p.award}>{t.awards[programme.award as Award] ?? programme.award}</Fact>
          <Fact label={p.duration}>{p.durationValue(programme.years, programme.semesters)}</Fact>
          <Fact label={p.toGraduate}>{p.creditsValue(programme.credits)}</Fact>
          <Fact label={p.load}>{p.loadValue(programme.min_load, programme.max_load)}</Fact>
          <Fact label={p.curriculum}>
            {data.versions.length > 1 ? (
              <select aria-label={p.versions} className="bg-transparent font-extrabold" value={version?.id ?? ''} onChange={(e) => setVersion(e.target.value)}>
                {data.versions.map((v) => (
                  <option key={v.id} value={v.id}>
                    {v.released_at === null ? p.versionDraft(v.label) : p.versionReleased(v.label)}
                  </option>
                ))}
              </select>
            ) : version === null ? (
              t.none
            ) : version.released ? (
              <span className="font-extrabold text-teal">{p.versionReleased(version.label)}</span>
            ) : (
              <span className="font-extrabold">{p.versionDraft(version.label)}</span>
            )}
          </Fact>
        </div>
        <p className="text-sm text-text-muted">{p.keeps}</p>
      </div>

      <div className="grid gap-4 px-[clamp(16px,3vw,40px)] pt-5 pb-8">
        {editing && (
          <ProgrammeForm
            division={division}
            departments={departments}
            start={{
              id: programme.id,
              department_id: programme.department_id,
              code: programme.code,
              name: programme.name,
              award: programme.award as Award,
              years: programme.years,
              semesters: programme.semesters,
              credits: programme.credits,
              min_load: programme.min_load,
              max_load: programme.max_load,
              open: programme.open,
              retired: programme.retired,
            }}
            locked={released}
            onDone={(saved) => {
              setEditing(false)
              if (saved !== null) reload()
            }}
          />
        )}
        {problem !== null && (
          <Notice tone="wrong" title={t.notSaved}>
            {problem}
          </Notice>
        )}

        <nav aria-label={p.curriculum} className="flex gap-0 overflow-x-auto border-b border-rule">
          {Array.from({ length: programme.years }, (_, i) => i + 1).map((y) => (
            <button
              key={y}
              type="button"
              aria-current={y === year ? 'page' : undefined}
              onClick={() => setYear(y)}
              className={`min-h-11 shrink-0 border-b-4 px-4 text-[15px] whitespace-nowrap ${y === year ? 'border-teal font-extrabold text-ink' : 'border-transparent text-text-muted'}`}
            >
              {p.yearTab(y, yearCredits(y))}
            </button>
          ))}
        </nav>

        {draft !== null && (
          <div className="grid gap-4 lg:grid-cols-2">
            {Array.from({ length: programme.semesters }, (_, i) => i + 1).map((s) => (
              <SemesterTable
                key={`${year}.${s}`}
                year={year}
                semester={s}
                draft={draft}
                change={change}
                editable={editable}
                courses={courses}
                byId={byId}
                credits={slotCredits(year, s)}
                min={programme.min_load}
                max={programme.max_load}
              />
            ))}
          </div>
        )}

        {editable && (
          <div className="flex flex-wrap items-center gap-2.5">
            <Button busy={busy} disabled={!dirty} onClick={saveDraft}>
              {p.saveDraft}
            </Button>
            <Button variant="secondary" busy={busy} disabled={dirty || checks.length > 0} onClick={release}>
              {p.release}
            </Button>
            {dirty && <span className="text-sm text-text-muted">{p.unsaved}</span>}
          </div>
        )}

        {version !== null && (
          <div className="grid gap-4 lg:grid-cols-2">
            <Sheet label={p.checksTitle}>
              <div className="label-caps border-b-2 border-ink px-4 py-2.5 text-text-muted">{p.checksTitle}</div>
              {version.released && <Line ok text={p.releasedText} />}
              {!version.released && (
                <>
                  <CheckLine problems={has('retired')} ok={p.checkCatalogue} />
                  <CheckLine problems={has('needs')} ok={p.checkOrder} />
                  <CheckLine problems={has('empty', 'load', 'total')} ok={p.checkCredits} />
                  <CheckLine problems={has('group')} ok={p.checkGroups} />
                </>
              )}
            </Sheet>
            <Sheet className="grid gap-2 px-4 py-3.5">
              <strong>{p.gets}</strong>
              <span className="text-text-muted">{p.getsText}</span>
            </Sheet>
          </div>
        )}
      </div>
    </>
  )
}

function Line({ ok, text }: { ok: boolean; text: string }) {
  return (
    <div className={`flex gap-2.5 border-b border-rule px-4 py-2.5 last:border-b-0 ${ok ? '' : 'bg-marigold-wash'}`}>
      <span aria-hidden="true" className={`font-extrabold ${ok ? 'text-teal' : ''}`}>
        {ok ? '✓' : '!'}
      </span>
      <span>{text}</span>
    </div>
  )
}

function CheckLine({ problems, ok }: { problems: Problem[]; ok: string }) {
  if (problems.length === 0) return <Line ok text={ok} />

  return (
    <>
      {problems.map((x, i) => (
        <Line key={i} ok={false} text={problemText(x)} />
      ))}
    </>
  )
}

function SemesterTable({
  year,
  semester,
  draft,
  change,
  editable,
  courses,
  byId,
  credits,
  min,
  max,
}: {
  year: number
  semester: number
  draft: Draft
  change: (d: Draft) => void
  editable: boolean
  courses: Course[]
  byId: Map<string, Course>
  credits: number
  min: number
  max: number
}) {
  const [adding, setAdding] = useState('')
  const [group, setGroup] = useState<{ label: string; choose: string } | null>(null)
  const here = (c: { year: number; semester: number }) => c.year === year && c.semester === semester
  const core = draft.courses.filter((c) => here(c) && c.group === null)
  const groups = draft.groups.filter(here)
  const used = new Set(draft.courses.map((c) => c.course_id))
  const free = courses.filter((c) => !c.retired && !used.has(c.id))
  const inLoad = credits >= min && credits <= max

  const remove = (courseId: string) =>
    change({
      ...draft,
      courses: draft.courses.filter((c) => c.course_id !== courseId),
    })
  const add = (courseId: string, inGroup: string | null) =>
    courseId !== '' &&
    change({
      ...draft,
      courses: [...draft.courses, { course_id: courseId, year, semester, group: inGroup }],
    })
  const removeGroup = (key: string) =>
    change({
      groups: draft.groups.filter((g) => g.key !== key),
      courses: draft.courses.filter((c) => c.group !== key),
    })

  const row = (courseId: string, kind: string, indent: boolean) => {
    const c = byId.get(courseId)
    return (
      <tr key={courseId}>
        <td className={`${td} ${indent ? 'pl-7' : ''}`}>
          <strong>{c?.code}</strong> {c?.title}
        </td>
        <td className={td}>{c?.credits}</td>
        <td className={td}>{kind}</td>
        <td className={td}>{c === undefined || c.needs.length === 0 ? <span className="text-text-muted">{t.none}</span> : c.needs.map((n) => n.code).join(' · ')}</td>
        {editable && (
          <td className={`${td} w-24 text-right`}>
            <SmallButton onClick={() => remove(courseId)}>{p.remove}</SmallButton>
          </td>
        )}
      </tr>
    )
  }

  return (
    <Sheet>
      <div className="flex items-baseline justify-between px-3.5 py-3">
        <strong className="type-display text-[22px]">{p.semester(semester)}</strong>
        <span className={`font-extrabold ${inLoad ? 'text-teal' : ''}`}>
          {inLoad ? '✓' : '!'} {p.slotCredits(credits)}
        </span>
      </div>
      <div className="overflow-x-auto">
        <table className="w-full border-collapse text-[15px]">
          <thead>
            <tr>
              <th className={th}>{p.columns.course}</th>
              <th className={th}>{p.columns.credits}</th>
              <th className={th}>{p.columns.kind}</th>
              <th className={th}>{p.columns.needs}</th>
              {editable && <th className={th} />}
            </tr>
          </thead>
          <tbody>
            {core.length === 0 && groups.length === 0 && (
              <tr>
                <td colSpan={5} className={`${td} text-text-muted`}>
                  {p.empty}
                </td>
              </tr>
            )}
            {core.map((c) => row(c.course_id, p.core, false))}
            {groups.map((g) => {
              const options = draft.courses.filter((c) => c.group === g.key)
              const groupCredits = options
                .map((o) => byId.get(o.course_id)?.credits ?? 0)
                .sort((a, b) => a - b)
                .slice(0, g.choose)
                .reduce((a, b) => a + b, 0)
              return (
                <GroupRows
                  key={g.key}
                  label={p.group(g.label, g.choose, options.length, groupCredits)}
                  editable={editable}
                  onRemove={() => removeGroup(g.key)}
                  free={free}
                  onAdd={(id) => add(id, g.key)}
                >
                  {options.map((o) => row(o.course_id, p.elective, true))}
                </GroupRows>
              )
            })}
          </tbody>
        </table>
      </div>
      {editable && (
        <div className="grid gap-2.5 px-3.5 py-3">
          <div className="flex flex-wrap items-end gap-2">
            <div className="min-w-56 flex-1">
              <Select label={p.addCore} value={adding} onChange={(e) => setAdding(e.target.value)}>
                <option value="">{p.chooseCourse}</option>
                {free.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.code} · {c.title}
                  </option>
                ))}
              </Select>
            </div>
            <Button
              variant="secondary"
              disabled={adding === ''}
              onClick={() => {
                add(adding, null)
                setAdding('')
              }}
            >
              {p.add}
            </Button>
          </div>
          {group === null ? (
            <div>
              <Button variant="secondary" onClick={() => setGroup({ label: '', choose: '1' })}>
                {p.addGroup}
              </Button>
            </div>
          ) : (
            <div className="flex flex-wrap items-end gap-2">
              <div className="min-w-48 flex-1">
                <Input label={p.groupName} value={group.label} onChange={(e) => setGroup({ ...group, label: e.target.value })} />
              </div>
              <div className="w-36">
                <Input
                  label={p.choose}
                  inputMode="numeric"
                  value={group.choose}
                  onChange={(e) =>
                    setGroup({
                      ...group,
                      choose: e.target.value.replace(/\D/g, ''),
                    })
                  }
                />
              </div>
              <Button
                disabled={group.label.trim() === '' || Number(group.choose) < 1}
                onClick={() => {
                  change({
                    ...draft,
                    groups: [
                      ...draft.groups,
                      {
                        key: `new-${Date.now()}`,
                        year,
                        semester,
                        label: group.label.trim(),
                        choose: Number(group.choose),
                      },
                    ],
                  })
                  setGroup(null)
                }}
              >
                {p.add}
              </Button>
              <Button variant="secondary" onClick={() => setGroup(null)}>
                {t.cancel}
              </Button>
            </div>
          )}
        </div>
      )}
    </Sheet>
  )
}

function GroupRows({
  label,
  editable,
  onRemove,
  free,
  onAdd,
  children,
}: {
  label: string
  editable: boolean
  onRemove: () => void
  free: Course[]
  onAdd: (id: string) => void
  children: ReactNode
}) {
  const [pick, setPick] = useState('')

  return (
    <>
      <tr>
        <td colSpan={4} className={`${td} bg-field`}>
          <strong>{label}</strong>
        </td>
        {editable && (
          <td className={`${td} bg-field text-right`}>
            <SmallButton onClick={onRemove}>{p.remove}</SmallButton>
          </td>
        )}
      </tr>
      {children}
      {editable && (
        <tr>
          <td colSpan={5} className={`${td} pl-7`}>
            <div className="flex flex-wrap items-center gap-2">
              <select aria-label={p.addToGroup} value={pick} onChange={(e) => setPick(e.target.value)} className="h-9 min-w-52 border-b-2 border-ink bg-field px-2 text-sm">
                <option value="">{p.addToGroup}</option>
                {free.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.code} · {c.title}
                  </option>
                ))}
              </select>
              <SmallButton
                disabled={pick === ''}
                onClick={() => {
                  onAdd(pick)
                  setPick('')
                }}
              >
                {p.add}
              </SmallButton>
            </div>
          </td>
        </tr>
      )}
    </>
  )
}
