import { useLoad } from '@acadly/kernel'
import { Button } from '@acadly/ui'
import type { ReactNode } from 'react'
import { portalApi, readCard, type AttendanceState } from './api'
import { useFamily } from './Family'
import { Bar, Loading, Mark, Screen, shortDay, longDay, useWords, when, type Tone, Note } from './parts'

/** The frame of a child's page: the way back to the child, then the page. */
function ChildPage<T>({ id, phase, data, onRetry, children, foot }: { id: string; phase: string; data: T | undefined; onRetry: () => void; children: (data: T) => ReactNode; foot?: ReactNode }) {
  const t = useWords()
  const family = useFamily().data
  const child = family?.children.find((c) => c.id === id)
  const bar = <Bar school={family?.school.name ?? ''} back={{ to: `/child/${id}`, label: child?.first_name ?? t.back }} />

  if (phase === 'refused') {
    return (
      <Screen bar={<Bar school={family?.school.name ?? ''} back={{ to: '/', label: t.home.title }} />}>
        <div className="p-4">
          <Note tone="attention" title={t.home.none}>
            {t.home.noneNote}
          </Note>
        </div>
      </Screen>
    )
  }

  if (data === undefined) return <Screen bar={bar}>{<Loading failed={phase === 'failed'} onRetry={onRetry} />}</Screen>

  return (
    <Screen bar={bar} foot={foot}>
      {children(data)}
    </Screen>
  )
}

/** The report card on a phone (the approved design): position and days first, then each subject. */
export function CardPage({ id }: { id: string }) {
  const t = useWords()
  const c = t.card
  const loaded = useLoad(`portal:card:${id}`, () => portalApi.card(id))
  const published = loaded.data?.card ?? null

  return (
    <ChildPage
      id={id}
      phase={loaded.phase}
      data={loaded.data}
      onRetry={loaded.reload}
      foot={
        published !== null ? (
          <Button className="h-14 w-full text-lg" onClick={() => window.print()}>
            {c.save}
          </Button>
        ) : undefined
      }
    >
      {(data) => {
        if (data.card === null) {
          return (
            <div className="px-4 pt-4">
              <h1 className="type-display mb-3 text-[34px] leading-[0.9]">{data.child.name}</h1>
              <Note tone="plain" title={c.none}>
                {c.noneNote}
              </Note>
            </div>
          )
        }

        const card = readCard(data.card.card)

        return (
          <>
            <div className="px-4 pt-4 pb-2.5">
              <div className="label-caps text-[11px] text-text-muted">{c.kicker(card.period?.name ?? '')}</div>
              <h1 className="type-display mt-1 text-[34px] leading-[0.9]">{card.learner?.name ?? data.child.name}</h1>
              <div className="mt-1 text-sm">
                {data.card.school.name} · {data.child.class}
              </div>
            </div>
            <div className="grid grid-cols-2 border-y-2 border-ink bg-sheet">
              {card.position !== null ? (
                <div className="bg-ink px-4 py-2.5 text-sheet">
                  <div className="label-caps text-[10px] text-on-ink-muted">{c.position}</div>
                  <div className="type-display text-[38px] leading-[0.9] text-teal-bright">
                    {card.position.position}{' '}
                    <span className="text-xl text-sheet">
                      {c.of(card.position.of)}
                      {card.position.shared && ` · ${c.shared}`}
                    </span>
                  </div>
                </div>
              ) : (
                <div className="bg-ink" />
              )}
              <div className="border-l-2 border-ink px-4 py-2.5">
                {card.lines.slice(0, 1).map((l) => (
                  <div key={l.label}>
                    <div className="label-caps text-[10px] text-text-muted">{l.label}</div>
                    <div className="text-lg leading-tight font-extrabold">{l.value}</div>
                  </div>
                ))}
              </div>
            </div>
            <div className="bg-sheet px-4 pt-1 pb-5">
              <h2 className="sr-only">{c.subjects}</h2>
              <ul>
                {card.subjects.map((s) => (
                  <li key={s.subject} className="flex min-h-[52px] items-center gap-2.5 border-b border-rule">
                    <span className="min-w-0 flex-1 text-[17px] font-extrabold">{s.subject}</span>
                    {s.ready ? (
                      <>
                        <span className="font-mono text-base">{s.percent !== null ? `${Math.round(s.percent)}%` : ''}</span>
                        <span className="grid h-8 w-9 place-items-center border-2 border-ink text-[17px] font-extrabold">{s.grade ?? ''}</span>
                        <span className="w-[100px] text-sm">{s.word ?? ''}</span>
                      </>
                    ) : (
                      <span className="text-sm text-text-muted">{c.notReady}</span>
                    )}
                  </li>
                ))}
              </ul>
              {card.lines.slice(1).map((l) => (
                <div key={l.label} className="flex justify-between gap-3 border-b border-rule py-2 text-[15px]">
                  <span className="font-extrabold">{l.label}</span>
                  <span>{l.value}</span>
                </div>
              ))}
              {(
                [
                  [c.classTeacher, card.comments.class_teacher],
                  [c.head, card.comments.head],
                ] as const
              ).map(([label, said]) =>
                said === null ? null : (
                  <div key={label} className="mt-3 border-l-[6px] border-ink bg-paper px-3 py-2.5">
                    <div className="label-caps text-[10px] text-text-muted">
                      {label}
                      {said.by !== '' && ` · ${said.by}`}
                    </div>
                    <div className="text-base leading-snug">{said.text}</div>
                  </div>
                ),
              )}
              <p className="mt-4 text-xs text-text-muted">{c.reference(data.card.reference)}</p>
            </div>
          </>
        )
      }}
    </ChildPage>
  )
}

const markTone: Record<string, Tone> = { present: 'done', late: 'attention', absent: 'wrong', excused: 'plain' }

/** Attendance this term: the totals, then every school morning by week. */
export function AttendancePage({ id }: { id: string }) {
  const loaded = useLoad(`portal:attendance:${id}`, () => portalApi.attendance(id))

  return (
    <ChildPage id={id} phase={loaded.phase} data={loaded.data} onRetry={loaded.reload}>
      {(data) => <Attendance data={data} />}
    </ChildPage>
  )
}

function Attendance({ data }: { data: AttendanceState }) {
  const a = useWords().attendance
  const term = data.attendance

  if (term === null || term.totals.marked === 0) {
    return (
      <div className="px-4 pt-4">
        <h1 className="type-display mb-3 text-[34px] leading-[0.9]">{data.child.name}</h1>
        <Note tone="plain" title={a.none} />
      </div>
    )
  }

  const tot = term.totals

  return (
    <>
      <div className="px-4 pt-4 pb-2.5">
        <div className="label-caps text-[11px] text-text-muted">{a.kicker(term.term?.name ?? '')}</div>
        <h1 className="type-display mt-1 text-[34px] leading-[0.9]">{data.child.name}</h1>
      </div>
      <div className="grid grid-cols-2 border-y-2 border-ink bg-sheet">
        <div className="px-4 py-2.5">
          <div className="label-caps text-[10px] text-text-muted">{a.present}</div>
          <div className="type-display text-[38px] leading-[0.9]">
            {tot.present} <span className="text-xl">{a.ofDays(tot.marked)}</span>
          </div>
        </div>
        <div className="border-l-2 border-ink px-4 py-2.5">
          <div className="label-caps text-[10px] text-text-muted">{a.absent}</div>
          <div className="type-display text-[38px] leading-[0.9]">
            {tot.absent} <span className="text-xl">{a.days.toLowerCase()}</span>
          </div>
        </div>
      </div>
      <div className="bg-sheet px-4 pt-3 pb-6">
        {tot.last_absent !== null && <p className="mb-3 text-[15px]">{a.lastAbsent(longDay(tot.last_absent))}</p>}
        <div aria-hidden="true" className="mb-1 grid grid-cols-[1fr_repeat(5,36px)] gap-1.5 text-center text-[11px] font-extrabold text-text-muted">
          <span />
          {a.weekdays.map((d) => (
            <span key={d}>{d}</span>
          ))}
        </div>
        <ul>
          {[...term.weeks]
            .filter((w) => w.days.some((d) => !d.future))
            .reverse()
            .map((w) => (
            <li key={w.week} className="grid grid-cols-[1fr_repeat(5,36px)] items-center gap-1.5 border-b border-rule py-1.5">
              <span className="text-sm font-bold">{a.week(shortDay(w.week))}</span>
              {w.days.map((d) => (
                <span key={d.day} className="grid place-items-center" title={d.mark !== null ? a.marks[d.mark] : undefined}>
                  {d.mark !== null ? (
                    <>
                      <Mark tone={markTone[d.mark] ?? 'plain'} />
                      <span className="sr-only">
                        {longDay(d.day)}: {a.marks[d.mark]}
                      </span>
                    </>
                  ) : !d.school_day ? (
                    <span className="text-text-muted">
                      –<span className="sr-only">{a.noSchool}</span>
                    </span>
                  ) : (
                    <span className="size-[26px]" />
                  )}
                </span>
              ))}
            </li>
          ))}
        </ul>
        <div className="mt-4 flex flex-wrap gap-x-4 gap-y-2 text-sm">
          <span className="label-caps w-full text-[10px] text-text-muted">{a.key}</span>
          {(['present', 'late', 'absent', 'excused'] as const).map((m) => (
            <span key={m} className="flex items-center gap-1.5">
              <Mark tone={markTone[m] ?? 'plain'} />
              {a.marks[m]}
            </span>
          ))}
        </div>
      </div>
    </>
  )
}

/** The text messages the school sent to this phone about the child. */
export function MessagesPage({ id }: { id: string }) {
  const t = useWords()
  const m = t.messages
  const loaded = useLoad(`portal:messages:${id}`, () => portalApi.messages(id))

  return (
    <ChildPage id={id} phase={loaded.phase} data={loaded.data} onRetry={loaded.reload}>
      {(data) => (
        <div className="px-4 pt-4 pb-6">
          <div className="label-caps text-[11px] text-text-muted">{m.kicker}</div>
          <h1 className="type-display mt-1 mb-3 text-[34px] leading-[0.9]">{data.child.name}</h1>
          {data.messages.length === 0 && (
            <Note tone="plain" title={m.none}>
              {m.noneNote}
            </Note>
          )}
          <ul>
            {data.messages.map((msg) => (
              <li key={msg.id} className="mb-2.5 border-2 border-ink bg-sheet px-3.5 py-3">
                <div className="text-base leading-normal">{msg.text}</div>
                <div className="mt-1.5 text-xs text-text-muted">
                  {msg.notice !== '' && `${msg.notice} · `}
                  {when(msg.at)}
                </div>
              </li>
            ))}
          </ul>
        </div>
      )}
    </ChildPage>
  )
}
