import { useLoad } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useEffect, useState } from 'react'
import { copy } from '../copy'
import { firstError } from '../programmes/api'
import { Attention, Sheet } from '../programmes/parts'
import { PageHead, SmallButton, Tag } from '../settings/parts'
import { clockOf, lecturesApi, shortDay, useLectureDivision, type LectureView } from './api'
import { CoverTag } from './CoverScreen'

const t = copy.lectures
const l = t.lecture

type Student = LectureView['students'][number]

/** One lecture (D-102, the approved board): open attendance, show the code, see who scanned in, mark by hand, close. */
export function LectureScreen({ slot, day }: { slot: string; day: string }) {
  const { division } = useLectureDivision(['lectures.teach', 'lectures.cover', 'lectures.view'])
  if (division === null) {
    return (
      <div className="px-[clamp(16px,3vw,40px)] pt-7">
        <Attention>
          <b>{t.chooseTitle}.</b> {t.choose}
        </Attention>
      </div>
    )
  }

  return <Loaded key={`${division.id}${slot}${day}`} division={division.id} slot={slot} day={day} />
}

function Loaded({ division, slot, day }: { division: string; slot: string; day: string }) {
  const loaded = useLoad(`lecture:${division}:${slot}:${day}`, () => lecturesApi.lecture(division, slot, day))
  const [busy, setBusy] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)
  const scanning = loaded.data?.session?.scanning === true
  const { reload } = loaded

  // While scanning is open, the lists follow the scans.
  useEffect(() => {
    if (!scanning) return
    const every = window.setInterval(reload, 5000)

    return () => window.clearInterval(every)
  }, [scanning, reload])

  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={l.registered} labels={copy.loading} rows={8} onRetry={loaded.reload} />
  const data = loaded.data
  const x = data.lecture
  const s = data.session
  const marked = data.students.filter((p) => p.mark !== null && p.mark !== 'absent')
  const notYet = data.students.filter((p) => p.mark === null || p.mark === 'absent')
  const refused = data.students.filter((p) => p.refused !== null)

  const run = async (go: () => Promise<{ kind: string; errors?: Record<string, string[]> }>, title: string) => {
    setBusy(true)
    setProblem(null)
    const result = await go()
    setBusy(false)
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title })
      loaded.reload()
    } else setProblem(firstError(result, t.failed))
  }
  const mark = (p: Student, value: 'present' | 'late' | 'absent') => {
    if (s === null) return
    const reason = window.prompt(t.reasonPrompt)
    if (reason === null) return
    void run(() => lecturesApi.mark(division, s.id, { learner_id: p.learner_id, mark: value, reason }), l.marked)
  }
  const handButtons = (p: Student) =>
    s === null ? null : (
      <span className="flex flex-wrap gap-1.5">
        {p.mark !== 'present' && <SmallButton onClick={() => mark(p, 'present')}>{l.present}</SmallButton>}
        {p.mark !== 'late' && <SmallButton onClick={() => mark(p, 'late')}>{l.late}</SmallButton>}
        {s.closed && p.mark !== 'absent' && (
          <SmallButton tone="red" onClick={() => mark(p, 'absent')}>
            {l.absent}
          </SmallButton>
        )}
      </span>
    )

  return (
    <>
      <PageHead
        kicker={shortDay(x.held_on)}
        title={l.title(x.code, t.kinds[x.kind] ?? x.kind)}
        intro={l.lede(x.title, x.starts, x.ends, x.room)}
        actions={
          <div className="flex flex-wrap gap-2.5">
            {data.can_open && (
              <Button busy={busy} onClick={() => void run(() => lecturesApi.open(division, slot, day), l.opened)}>
                {l.open}
              </Button>
            )}
            {s !== null && !s.closed && (
              <>
                {s.scanning && (
                  <Button variant="secondary" onClick={() => window.open(`/lectures/screen/${division}/${s.id}`, '_blank', 'noopener')}>
                    {l.screen}
                  </Button>
                )}
                <Button variant="secondary" busy={busy} onClick={() => window.confirm(l.closeConfirm) && void run(() => lecturesApi.close(division, s.id), l.closedNow)}>
                  {l.close}
                </Button>
              </>
            )}
          </div>
        }
      />
      <div className="grid gap-4 px-[clamp(16px,3vw,40px)] pb-8">
        {problem !== null && (
          <Notice tone="wrong" title={t.notSaved}>
            {problem}
          </Notice>
        )}
        {x.state === 'cancelled' ? (
          <Attention>{l.cancelled}</Attention>
        ) : s === null ? (
          <p className="text-text-muted">{l.notOpen}</p>
        ) : s.closed ? (
          <div className="border-2 border-teal bg-teal-wash px-4 py-3">{l.closed}</div>
        ) : s.scanning ? (
          <Attention>
            <b>{l.scanningTitle}</b> {l.scanning(clockOf(s.scan_until))}
          </Attention>
        ) : (
          <Attention>{l.after}</Attention>
        )}
        {x.state !== 'on' && x.state !== 'cancelled' && (
          <div>
            <CoverTag state={x.state} />
          </div>
        )}
        <div className="grid grid-cols-2 border-2 border-ink md:grid-cols-4">
          {(
            [
              [l.registered, data.students.length, 'sheet'],
              [l.scanned, marked.length, 'ink'],
              [l.notYet, notYet.length, notYet.length > 0 && s !== null && !s.closed ? 'attention' : 'sheet'],
              [l.refused, refused.length, 'sheet'],
            ] as const
          ).map(([label, n, tone], i) => (
            <div key={label} className={`px-4 py-3 ${i > 0 ? 'md:border-l-2 md:border-ink' : ''} ${tone === 'ink' ? 'bg-ink text-sheet' : tone === 'attention' ? 'bg-marigold' : 'bg-sheet'}`}>
              <span className={`label-caps ${tone === 'ink' ? 'text-on-ink-muted' : 'text-text-muted'}`}>{label}</span>
              <div className={`type-display text-[44px] leading-none ${tone === 'ink' ? 'text-teal-bright' : ''}`}>{n}</div>
            </div>
          ))}
        </div>
        <div className="grid items-start gap-4 xl:grid-cols-2">
          <Sheet label={l.notYet}>
            <h2 className="label-caps border-b border-rule px-3.5 py-2.5 text-text-muted">{`${s?.closed === true ? l.absent : l.notYet} · ${notYet.length}`}</h2>
            <ul>
              {notYet.map((p) => (
                <li key={p.learner_id} className="flex min-h-12 flex-wrap items-center justify-between gap-2 border-b border-rule px-3.5 py-2 last:border-b-0">
                  <span className="flex min-w-0 flex-col">
                    <strong>{p.name}</strong>
                    <span className="font-mono text-[13px] text-text-muted">{p.number}</span>
                  </span>
                  <span className="flex flex-wrap items-center gap-2">
                    {p.refused !== null && <Tag tone="wrong">{`${l.why[p.refused.why] ?? p.refused.why} · ${p.refused.at}`}</Tag>}
                    {p.mark === 'absent' && <Tag tone="wrong">{l.marks.absent}</Tag>}
                    {handButtons(p)}
                  </span>
                </li>
              ))}
            </ul>
          </Sheet>
          <Sheet label={l.scanned}>
            <h2 className="label-caps border-b border-rule px-3.5 py-2.5 text-text-muted">{`${l.scanned} · ${marked.length}`}</h2>
            <ul>
              {marked.map((p) => (
                <li key={p.learner_id} className="flex min-h-12 flex-wrap items-center justify-between gap-2 border-b border-rule px-3.5 py-2 last:border-b-0">
                  <span className="flex min-w-0 flex-col">
                    <strong>{p.name}</strong>
                    <span className="text-[13px] text-text-muted">
                      <span className="font-mono">{p.number}</span>
                      {` · ${p.at ?? ''}${p.source === 'hand' ? ` · ${l.byHand}` : ''}`}
                    </span>
                  </span>
                  <span className="flex flex-wrap items-center gap-2">
                    <Tag tone={p.mark === 'late' ? 'attention' : 'done'}>{l.marks[p.mark ?? ''] ?? ''}</Tag>
                    {s?.closed === true && handButtons(p)}
                  </span>
                </li>
              ))}
            </ul>
          </Sheet>
        </div>
        <p className="text-[13px] text-text-muted">{l.note}</p>
      </div>
    </>
  )
}
