import { navigate, useLoad } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { Input, Select } from '../setup/parts'
import { PageHead, SettingsTabs, SmallButton, Tag } from '../settings/parts'
import type { Division } from '../shell/divisions'
import { firstError } from './api'
import { Attention, DivisionChoice, Go, NoDivision, Sheet, td, th, useProgrammeDivision } from './parts'
import { registrationApi, type StudentRow, type Students } from './registrationApi'
import { RegTag } from './RegistrationDeskScreen'

const t = copy.programmes
const s = t.students

const MODES = ['full_time', 'part_time'] as const
const modeLabel = (mode: string) => (mode === 'part_time' ? s.modes.part_time : s.modes.full_time)

/** Programmes › Students (D-098, the approved board): who is in which programme, and their registration this semester. */
export function StudentsScreen() {
  const { division, choices, choose } = useProgrammeDivision()
  const [period, setPeriod] = useState<string | null>(null)

  if (division === null) return <NoDivision />

  return <Loaded key={`${division.id}:${period ?? ''}`} division={division} choices={choices} choose={choose} period={period} setPeriod={setPeriod} />
}

function Loaded({
  division,
  choices,
  choose,
  period,
  setPeriod,
}: {
  division: Division
  choices: Division[]
  choose: (id: string) => void
  period: string | null
  setPeriod: (id: string) => void
}) {
  const loaded = useLoad(`programmes:students:${division.id}:${period ?? ''}`, () => registrationApi.students(division.id, period))
  const [query, setQuery] = useState('')
  const [programme, setProgramme] = useState('')
  const [year, setYear] = useState('')
  const [changing, setChanging] = useState<string | null>(null)

  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={s.title} labels={copy.loading} rows={8} onRetry={loaded.reload} />

  const data = loaded.data
  const current = data.period
  const active = data.students.filter((x) => x.status === 'active')
  const registered = active.filter((x) => x.registration?.state === 'registered').length
  const years = [...new Set(data.students.map((x) => x.year))].sort((a, b) => a - b)
  const programmes = [...new Map(data.students.map((x) => [x.programme.id, x.programme.code])).entries()].sort((a, b) => a[1].localeCompare(b[1]))
  const q = query.trim().toLowerCase()
  const shown = data.students.filter(
    (x) =>
      (q === '' || x.name.toLowerCase().includes(q) || x.code.toLowerCase().includes(q)) &&
      (programme === '' || x.programme.id === programme) &&
      (year === '' || String(x.year) === year),
  )

  return (
    <>
      <SettingsTabs />
      <PageHead kicker={t.kicker} title={s.title} intro={s.intro} actions={<DivisionChoice choices={choices} division={division} choose={choose} />} />
      <div className="grid gap-4 px-[clamp(16px,3vw,40px)] pb-8">
        {current !== null && (
          <nav aria-label={s.title} className="flex gap-0 overflow-x-auto border-b border-rule">
            {data.periods.map((x) => (
              <button
                key={x.id}
                type="button"
                aria-current={x.id === current.id ? 'page' : undefined}
                onClick={() => setPeriod(x.id)}
                className={`min-h-11 shrink-0 border-b-4 px-4 text-[15px] whitespace-nowrap ${x.id === current.id ? 'border-teal font-extrabold text-ink' : 'border-transparent text-text-muted'}`}
              >
                {x.name}
              </button>
            ))}
          </nav>
        )}

        <div className={`grid gap-6 ${data.can.register ? 'xl:grid-cols-[minmax(0,1fr)_340px]' : ''} items-start`}>
          <div className="grid gap-3">
            <Sheet className="grid sm:grid-cols-3">
              <Count n={active.length} label={s.inProgrammes} />
              <Count n={registered} label={s.registered(current?.name ?? '')} tone="done" />
              <Count n={active.length - registered} label={s.notRegistered} />
            </Sheet>

            <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_200px_160px]">
              <Input label={s.find} placeholder={s.findHint} type="search" value={query} onChange={(e) => setQuery(e.target.value)} />
              <Select label={s.programme} value={programme} onChange={(e) => setProgramme(e.target.value)}>
                <option value="">{s.allProgrammes}</option>
                {programmes.map(([id, code]) => (
                  <option key={id} value={id}>
                    {code}
                  </option>
                ))}
              </Select>
              <Select label={s.columns.year} value={year} onChange={(e) => setYear(e.target.value)}>
                <option value="">{s.allYears}</option>
                {years.map((y) => (
                  <option key={y} value={String(y)}>
                    {s.year(y)}
                  </option>
                ))}
              </Select>
            </div>

            <Sheet className="overflow-x-auto">
              <table className="w-full min-w-[760px] border-collapse text-[15px]">
                <thead>
                  <tr>
                    <th className={th}>{s.columns.code}</th>
                    <th className={th}>{s.columns.student}</th>
                    <th className={th}>{s.columns.programme}</th>
                    <th className={th}>{s.columns.curriculum}</th>
                    <th className={th}>{s.columns.year}</th>
                    <th className={th}>{s.columns.mode}</th>
                    <th className={th}>{current?.name ?? s.columns.semester}</th>
                    {data.can.register && <th className={th} />}
                  </tr>
                </thead>
                <tbody>
                  {shown.length === 0 && (
                    <tr>
                      <td colSpan={8} className={`${td} text-text-muted`}>
                        {data.students.length === 0 ? s.none : s.noMatch}
                      </td>
                    </tr>
                  )}
                  {shown.map((x) =>
                    changing === x.id ? (
                      <tr key={x.id}>
                        <td colSpan={8} className="border-b border-rule p-0">
                          <StatusForm
                            division={division.id}
                            student={x}
                            onDone={(saved) => {
                              setChanging(null)
                              if (saved) loaded.reload()
                            }}
                          />
                        </td>
                      </tr>
                    ) : (
                      <tr key={x.id}>
                        <td className={`${td} font-mono text-sm`}>{x.code}</td>
                        <td className={td}>
                          <Go to={`/learners/${x.learner_id}`} className="font-extrabold">
                            {x.name}
                          </Go>
                        </td>
                        <td className={td}>{x.programme.code}</td>
                        <td className={td}>{x.curriculum}</td>
                        <td className={td}>{s.year(x.year)}</td>
                        <td className={td}>{modeLabel(x.mode)}</td>
                        <td className={td}>
                          {x.status !== 'active' ? (
                            <Tag tone="plain">{s.statuses[x.status as keyof typeof s.statuses] ?? x.status}</Tag>
                          ) : x.registration === null ? (
                            <span className="text-text-muted">{s.notStarted}</span>
                          ) : (
                            <button type="button" onClick={() => navigate(`/programmes/r/${x.registration?.id ?? ''}`)} className="cursor-pointer">
                              <RegTag state={x.registration.state} />
                            </button>
                          )}
                        </td>
                        {data.can.register && (
                          <td className={`${td} text-right`}>
                            <SmallButton onClick={() => setChanging(x.id)}>{s.changeStatus}</SmallButton>
                          </td>
                        )}
                      </tr>
                    ),
                  )}
                </tbody>
              </table>
            </Sheet>
            <p className="text-sm text-text-muted">{s.note}</p>
          </div>

          {data.can.register && <PlaceForm division={division.id} data={data} onPlaced={loaded.reload} />}
        </div>
      </div>
    </>
  )
}

function Count({ n, label, tone }: { n: number; label: string; tone?: 'done' }) {
  return (
    <div className="grid border-r border-rule px-4 py-3 last:border-r-0">
      <span className={`type-display text-[32px] leading-none ${tone === 'done' ? 'text-teal' : ''}`}>{n}</span>
      <span className="text-text-muted">{label}</span>
    </div>
  )
}

function PlaceForm({ division, data, onPlaced }: { division: string; data: Students; onPlaced: () => void }) {
  const [learner, setLearner] = useState('')
  const [programmeId, setProgrammeId] = useState(data.programmes[0]?.id ?? '')
  const [entry, setEntry] = useState('1')
  const [mode, setMode] = useState<string>('full_time')
  const [busy, setBusy] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)
  const programme = data.programmes.find((p) => p.id === programmeId)

  const place = async () => {
    setBusy(true)
    setProblem(null)
    const result = await registrationApi.place(division, { learner_id: learner, programme_id: programmeId, entry_year: Number(entry), mode })
    setBusy(false)
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: s.placed(data.candidates.find((c) => c.id === learner)?.name ?? '') })
      setLearner('')
      onPlaced()
    } else setProblem(firstError(result, t.failed))
  }

  return (
    <Sheet label={s.place} className="grid gap-3 p-4">
      <h2 className="type-display border-b-2 border-ink pb-1.5 text-2xl">{s.place}</h2>
      {problem !== null && (
        <Notice tone="wrong" title={t.notSaved}>
          {problem}
        </Notice>
      )}
      {data.candidates.length === 0 ? (
        <Attention>{s.noCandidates}</Attention>
      ) : (
        <>
          <Select label={s.student} value={learner} onChange={(e) => setLearner(e.target.value)}>
            <option value="">{s.chooseStudent}</option>
            {data.candidates.map((c) => (
              <option key={c.id} value={c.id}>
                {`${c.name} · ${c.code}`}
              </option>
            ))}
          </Select>
          <Select label={s.programme} hint={s.programmeHint} value={programmeId} onChange={(e) => setProgrammeId(e.target.value)}>
            {data.programmes.map((p) => (
              <option key={p.id} value={p.id}>
                {`${p.code} · ${p.name}`}
              </option>
            ))}
          </Select>
          <div className="grid gap-0.5">
            <span className="label-caps text-text-muted">{s.curriculum}</span>
            <span>{programme?.curriculum != null ? s.newest(programme.curriculum) : s.noCurriculum}</span>
          </div>
          <Select label={s.startsIn} hint={s.startsHint} value={entry} onChange={(e) => setEntry(e.target.value)}>
            {Array.from({ length: programme?.years ?? 1 }, (_, i) => i + 1).map((y) => (
              <option key={y} value={String(y)}>
                {s.startsInYear(y)}
              </option>
            ))}
          </Select>
          <Select label={s.mode} value={mode} onChange={(e) => setMode(e.target.value)}>
            {MODES.map((m) => (
              <option key={m} value={m}>
                {modeLabel(m)}
              </option>
            ))}
          </Select>
          <Button busy={busy} disabled={learner === '' || programme?.curriculum == null} onClick={() => void place()}>
            {s.placeButton}
          </Button>
        </>
      )}
      <p className="border-t border-rule pt-2.5 text-sm text-text-muted">{s.placeNote}</p>
    </Sheet>
  )
}

function StatusForm({ division, student, onDone }: { division: string; student: StudentRow; onDone: (saved: boolean) => void }) {
  const [status, setStatus] = useState(student.status === 'active' ? 'leave' : 'active')
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)

  const save = async () => {
    setBusy(true)
    setProblem(null)
    const result = await registrationApi.status(division, student.id, { status, reason })
    setBusy(false)
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: s.statusSaved })
      onDone(true)
    } else setProblem(firstError(result, t.failed))
  }

  return (
    <div className="grid gap-3 bg-paper px-4 py-3.5">
      <strong>{`${student.name} · ${student.code}`}</strong>
      {problem !== null && (
        <Notice tone="wrong" title={t.notSaved}>
          {problem}
        </Notice>
      )}
      <div className="grid gap-3 sm:grid-cols-[220px_minmax(0,1fr)]">
        <Select label={s.newStatus} value={status} onChange={(e) => setStatus(e.target.value)}>
          {(['active', 'leave', 'withdrawn'] as const)
            .filter((x) => x !== student.status)
            .map((x) => (
              <option key={x} value={x}>
                {s.statuses[x]}
              </option>
            ))}
        </Select>
        <Input label={s.reason} value={reason} onChange={(e) => setReason(e.target.value)} />
      </div>
      <div className="flex flex-wrap gap-2.5">
        <Button busy={busy} disabled={reason.trim() === ''} onClick={() => void save()}>
          {s.saveStatus}
        </Button>
        <Button variant="secondary" onClick={() => onDone(false)}>
          {t.cancel}
        </Button>
      </div>
    </div>
  )
}
