import { useLoad } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { Input } from '../setup/parts'
import { PageHead, SettingsTabs, SmallButton, Tag } from '../settings/parts'
import type { Division } from '../shell/divisions'
import { firstError, programmesApi, type Offering, type Offerings } from './api'
import { Attention, DivisionChoice, NoDivision, Sheet, td, th, useProgrammeDivision } from './parts'

const t = copy.programmes
const o = t.offerings

/** Programmes › Offerings of a semester (D-097, the approved board). */
export function OfferingsScreen() {
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
  const loaded = useLoad(`programmes:offerings:${division.id}:${period ?? ''}`, () => programmesApi.offerings(division.id, period))
  const [busy, setBusy] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)
  const [editing, setEditing] = useState<string | null>(null)

  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={o.title} labels={copy.loading} rows={8} onRetry={loaded.reload} />

  const data = loaded.data
  const current = data.period
  const ready = data.offerings.filter((x) => x.ready).length

  const prepare = async () => {
    if (current === null) return
    setBusy(true)
    setProblem(null)
    const result = await programmesApi.prepare(division.id, current.id)
    setBusy(false)
    if (result.kind === 'saved') {
      slips.done({
        word: copy.slips.saved,
        title: o.prepared(result.data.added),
      })
      loaded.reload()
    } else setProblem(firstError(result, t.failed))
  }

  const addGroup = async (courseId: string) => {
    if (current === null) return
    const result = await programmesApi.addGroup(division.id, current.id, courseId)
    if (result.kind === 'saved') loaded.reload()
    else setProblem(firstError(result, t.failed))
  }

  return (
    <>
      <SettingsTabs />
      <PageHead kicker={t.kicker} title={o.title} intro={o.intro} actions={<DivisionChoice choices={choices} division={division} choose={choose} />} />
      <div className="grid gap-4 px-[clamp(16px,3vw,40px)] pb-8">
        {current === null ? (
          <Attention>{o.noSemester}</Attention>
        ) : (
          <>
            <nav aria-label={o.title} className="flex gap-0 overflow-x-auto border-b border-rule">
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

            <Sheet className="grid sm:grid-cols-3">
              <Count n={data.offerings.length} label={o.count} />
              <Count n={ready} label={o.ready} tone="done" />
              <Count n={data.offerings.length - ready} label={o.noLecturer} />
            </Sheet>

            {problem !== null && (
              <Notice tone="wrong" title={t.notSaved}>
                {problem}
              </Notice>
            )}
            {data.missing > 0 && (
              <div className="flex flex-wrap items-center justify-between gap-3 border-2 border-marigold bg-marigold-wash px-4 py-3">
                <span>
                  <strong aria-hidden="true">! </strong>
                  {o.missing(data.missing)}
                </span>
                {data.can.offer && (
                  <Button busy={busy} onClick={() => void prepare()}>
                    {o.prepare}
                  </Button>
                )}
              </div>
            )}

            <Sheet className="overflow-x-auto">
              <table className="w-full border-collapse text-[15px]">
                <thead>
                  <tr>
                    <th className={th}>{o.columns.course}</th>
                    <th className={th}>{o.columns.group}</th>
                    <th className={th}>{o.columns.lecturer}</th>
                    <th className={th}>{o.columns.limit}</th>
                    <th className={th}>{o.columns.state}</th>
                    {data.can.offer && <th className={th} />}
                  </tr>
                </thead>
                <tbody>
                  {data.offerings.length === 0 && (
                    <tr>
                      <td colSpan={6} className={`${td} text-text-muted`}>
                        {o.noOfferings}
                      </td>
                    </tr>
                  )}
                  {data.offerings.map((x) =>
                    editing === x.id ? (
                      <tr key={x.id}>
                        <td colSpan={6} className="border-b border-rule p-0">
                          <OfferingForm
                            division={division.id}
                            offering={x}
                            staff={data.staff}
                            onDone={(saved) => {
                              setEditing(null)
                              if (saved) loaded.reload()
                            }}
                          />
                        </td>
                      </tr>
                    ) : (
                      <tr key={x.id}>
                        <td className={td}>
                          <strong>{x.course.code}</strong> {x.course.title}
                        </td>
                        <td className={td}>{x.group}</td>
                        <td className={td}>
                          {x.lecturers.length === 0 && x.assistants.length === 0 ? (
                            <span className="text-text-muted">{t.none}</span>
                          ) : (
                            [...x.lecturers.map((p) => p.name), ...x.assistants.map((p) => o.assistant(p.name))].join(' · ')
                          )}
                        </td>
                        <td className={td}>{x.limit ?? <span className="text-text-muted">{t.none}</span>}</td>
                        <td className={td}>{x.ready ? <Tag tone="done">{o.readyTag}</Tag> : <Tag tone="attention">{o.noLecturerTag}</Tag>}</td>
                        {data.can.offer && (
                          <td className={`${td} text-right whitespace-nowrap`}>
                            <span className="inline-flex gap-1.5">
                              <SmallButton onClick={() => setEditing(x.id)}>{o.edit}</SmallButton>
                              <SmallButton onClick={() => void addGroup(x.course.id)}>{o.addGroup}</SmallButton>
                            </span>
                          </td>
                        )}
                      </tr>
                    ),
                  )}
                </tbody>
              </table>
            </Sheet>
            <p className="text-sm text-text-muted">{o.note}</p>
          </>
        )}
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

function OfferingForm({ division, offering, staff, onDone }: { division: string; offering: Offering; staff: Offerings['staff']; onDone: (saved: boolean) => void }) {
  const [lecturers, setLecturers] = useState(offering.lecturers.map((p) => p.id))
  const [assistants, setAssistants] = useState(offering.assistants.map((p) => p.id))
  const [limit, setLimit] = useState(offering.limit === null ? '' : String(offering.limit))
  const [busy, setBusy] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)
  const taken = new Set([...lecturers, ...assistants])
  const nameOf = (id: string) => staff.find((p) => p.id === id)?.name ?? ''

  const save = async () => {
    setBusy(true)
    setProblem(null)
    const result = await programmesApi.saveOffering(division, offering.id, {
      limit: limit === '' ? null : Number(limit),
      lecturers,
      assistants,
    })
    setBusy(false)
    if (result.kind === 'saved') {
      slips.done({
        word: copy.slips.saved,
        title: `${offering.course.code} ${offering.group}`,
      })
      onDone(true)
    } else setProblem(firstError(result, t.failed))
  }

  const people = (label: string, list: string[], set: (l: string[]) => void, add: string) => (
    <div className="grid gap-1.5">
      <span className="label-caps text-text-muted">{label}</span>
      <div className="flex flex-wrap items-center gap-2">
        {list.map((id) => (
          <span key={id} className="flex items-center gap-1.5 border-2 border-ink px-2 py-0.5">
            {nameOf(id)}
            <button type="button" aria-label={`${t.programme.remove} ${nameOf(id)}`} className="px-1 font-extrabold" onClick={() => set(list.filter((x) => x !== id))}>
              ×
            </button>
          </span>
        ))}
        <select
          aria-label={add}
          value=""
          onChange={(e) => e.target.value !== '' && set([...list, e.target.value])}
          className="h-10 min-w-52 border-b-2 border-ink bg-field px-2 text-sm"
        >
          <option value="">{add}</option>
          {staff
            .filter((p) => !taken.has(p.id))
            .map((p) => (
              <option key={p.id} value={p.id}>
                {p.name}
              </option>
            ))}
        </select>
      </div>
    </div>
  )

  return (
    <div className="grid gap-3 bg-paper px-4 py-3.5">
      <strong>
        {offering.course.code} {offering.course.title} · {offering.group}
      </strong>
      {problem !== null && (
        <Notice tone="wrong" title={t.notSaved}>
          {problem}
        </Notice>
      )}
      <div className="grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_180px]">
        {people(o.lecturers, lecturers, setLecturers, o.addLecturer)}
        {people(o.assistants, assistants, setAssistants, o.addAssistant)}
        <Input label={o.limit} hint={o.limitHint} inputMode="numeric" value={limit} onChange={(e) => setLimit(e.target.value.replace(/\D/g, ''))} />
      </div>
      <div className="flex flex-wrap gap-2.5">
        <Button busy={busy} onClick={() => void save()}>
          {t.save}
        </Button>
        <Button variant="secondary" onClick={() => onDone(false)}>
          {t.cancel}
        </Button>
      </div>
    </div>
  )
}
