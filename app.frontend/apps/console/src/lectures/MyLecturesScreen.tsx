import { navigate, useLoad } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { firstError } from '../programmes/api'
import { Attention, DivisionChoice, Sheet } from '../programmes/parts'
import { PageHead, SmallButton, Tag } from '../settings/parts'
import { lecturesApi, shortDay, useLectureDivision, type LectureRow } from './api'
import { Arrange, CoverTag } from './CoverScreen'

const t = copy.lectures
const m = t.myDay

/** My lectures (D-102, the approved board): the lecturer's day, the cover asked of them, and the week ahead. */
export function MyLecturesScreen() {
  const { division, choices, choose } = useLectureDivision(['lectures.teach'])
  if (division === null) {
    return (
      <div className="px-[clamp(16px,3vw,40px)] pt-7">
        <Attention>
          <b>{t.chooseTitle}.</b> {t.choose}
        </Attention>
      </div>
    )
  }

  return <Loaded key={division.id} division={division.id} choice={<DivisionChoice choices={choices} division={division} choose={choose} />} />
}

function Loaded({ division, choice }: { division: string; choice: React.ReactNode }) {
  const loaded = useLoad(`lecture-my-day:${division}`, () => lecturesApi.myDay(division))
  const [arranging, setArranging] = useState<LectureRow | null>(null)
  const [problem, setProblem] = useState<string | null>(null)

  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={m.title} labels={copy.loading} rows={6} onRetry={loaded.reload} />
  const data = loaded.data
  const lecture = (l: LectureRow) => `/lectures/l/${l.slot_id}/${l.day}`
  const given = data.today.filter((l) => l.state !== 'cancelled' && l.held_on === data.day)
  const now = given.find((l) => l.ends > data.now)

  const act = async (l: LectureRow, action: 'accept' | 'decline' | 'away') => {
    let reason = ''
    if (action === 'away') {
      const given = window.prompt(t.reasonPrompt)
      if (given === null) return
      reason = given
    }
    setProblem(null)
    const result = await lecturesApi.act(division, l.slot_id, action, { day: l.day, reason })
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: t.cover.done })
      loaded.reload()
      if (action === 'away') setArranging({ ...l, state: 'away', reason })
    } else setProblem(firstError(result, t.failed))
  }

  return (
    <>
      <PageHead kicker={`${t.kicker} · ${shortDay(data.day)}`} title={m.title} intro="" actions={choice} />
      <div className="grid items-start gap-4 px-[clamp(16px,3vw,40px)] pb-8 xl:grid-cols-[minmax(0,1fr)_420px]">
        <div className="grid gap-4">
          {problem !== null && (
            <Notice tone="wrong" title={t.notSaved}>
              {problem}
            </Notice>
          )}
          {now !== undefined && (
            <Sheet label={m.now(now.starts, now.ends)} className="border-ink">
              <h2 className="label-caps border-b border-rule px-3.5 py-2.5 text-text-muted">{now.starts <= data.now ? m.now(now.starts, now.ends) : m.next(now.starts, now.ends)}</h2>
              <div className="grid gap-2.5 px-3.5 py-3">
                <span className="flex flex-col">
                  <strong className="text-[18px]">{`${now.code} · ${t.kinds[now.kind] ?? now.kind} · ${now.room}`}</strong>
                  <span className="text-[14px] text-text-muted">{`${now.title} · ${t.students(now.students)}`}</span>
                </span>
                <div>
                  <Button onClick={() => navigate(lecture(now))}>{now.taken === null ? m.open : m.openLecture}</Button>
                </div>
              </div>
            </Sheet>
          )}
          <Sheet label={m.today}>
            <h2 className="label-caps border-b border-rule px-3.5 py-2.5 text-text-muted">{m.today}</h2>
            {data.today.length === 0 ? (
              <p className="px-3.5 py-3 text-text-muted">{m.none}</p>
            ) : (
              <ul>
                {data.today.map((l) => (
                  <li key={`${l.slot_id}${l.day}`} className="flex min-h-12 items-center justify-between gap-3 border-b border-rule px-3.5 py-2.5 last:border-b-0">
                    <a href={lecture(l)} onClick={(e) => (e.preventDefault(), navigate(lecture(l)))} className="flex min-w-0 flex-col text-ink no-underline hover:underline">
                      <strong>{`${l.starts} · ${l.code} ${t.kinds[l.kind] ?? l.kind} · ${l.room}`}</strong>
                      <span className="text-[14px] text-text-muted">{l.cover !== null && l.lecturer !== l.cover ? m.covering(l.lecturer) : t.students(l.students)}</span>
                    </a>
                    {l.state !== 'on' ? <CoverTag state={l.state} /> : l.taken !== null ? <Tag tone={l.taken.closed ? 'done' : 'attention'}>{`${l.taken.present}/${l.students}`}</Tag> : null}
                  </li>
                ))}
              </ul>
            )}
          </Sheet>
          <Sheet label={m.asked}>
            <h2 className="label-caps border-b border-rule px-3.5 py-2.5 text-text-muted">{m.asked}</h2>
            {data.asked.length === 0 ? (
              <p className="px-3.5 py-3 text-text-muted">{m.noAsked}</p>
            ) : (
              <ul>
                {data.asked.map((l) => (
                  <li key={`${l.slot_id}${l.day}`} className="flex min-h-12 flex-wrap items-center justify-between gap-3 border-b border-rule px-3.5 py-2.5 last:border-b-0">
                    <span className="flex min-w-0 flex-col">
                      <strong>{`${shortDay(l.day)} · ${l.starts} · ${l.code} ${t.kinds[l.kind] ?? l.kind} · ${l.room}`}</strong>
                      <span className="text-[14px] text-text-muted">{m.from(l.lecturer)}</span>
                    </span>
                    <span className="flex gap-1.5">
                      <SmallButton onClick={() => void act(l, 'accept')}>{m.accept}</SmallButton>
                      <SmallButton tone="red" onClick={() => void act(l, 'decline')}>
                        {m.decline}
                      </SmallButton>
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </Sheet>
          <Sheet label={m.ahead}>
            <h2 className="label-caps border-b border-rule px-3.5 py-2.5 text-text-muted">{m.ahead}</h2>
            {data.ahead.length === 0 ? (
              <p className="px-3.5 py-3 text-text-muted">{m.noAhead}</p>
            ) : (
              <ul>
                {data.ahead.map((l) => (
                  <li key={`${l.slot_id}${l.day}`} className="flex min-h-12 flex-wrap items-center justify-between gap-3 border-b border-rule px-3.5 py-2.5 last:border-b-0">
                    <span className="flex min-w-0 flex-col">
                      <strong>{`${shortDay(l.day)} · ${t.span(l.starts, l.ends)} · ${l.code} ${t.kinds[l.kind] ?? l.kind} · ${l.room}`}</strong>
                      {l.state !== 'on' && <span className="text-[14px] text-text-muted">{m.states[l.state] ?? l.state}</span>}
                    </span>
                    {l.state === 'on' && l.lecturer_id !== '' && l.cover === null ? (
                      <SmallButton onClick={() => void act(l, 'away')}>{m.tell}</SmallButton>
                    ) : l.state === 'away' ? (
                      <SmallButton onClick={() => setArranging(l)}>{m.askColleague}</SmallButton>
                    ) : (
                      <CoverTag state={l.state} />
                    )}
                  </li>
                ))}
              </ul>
            )}
          </Sheet>
          <p className="text-[13px] text-text-muted">{m.roomNote}</p>
        </div>
        {arranging !== null && <Arrange key={`${arranging.slot_id}${arranging.day}`} division={division} l={arranging} can={false} done={() => (setArranging(null), loaded.reload())} />}
      </div>
    </>
  )
}
