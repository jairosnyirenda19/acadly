import { navigate } from '@acadly/kernel'
import { Button, Notice, slips } from '@acadly/ui'
import { useState, type MouseEvent, type ReactNode } from 'react'
import { copy } from '../copy'
import { Tag } from '../settings/parts'
import { assessmentApi, stateTone, type SheetState, type WorkingState } from './api'

const a = copy.assessment
const p = a.panel
const s = copy.settings

/** Follows a link inside the app; a click with a modifier key keeps the browser's own behaviour. */
export function follow(event: MouseEvent<HTMLAnchorElement>, path: string) {
  if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return
  event.preventDefault()
  navigate(path)
}

export function InLink({ href, children }: { href: string; children: ReactNode }) {
  return (
    <a href={href} onClick={(e) => follow(e, href)} className="inline-flex min-h-11 items-center text-sm font-extrabold text-ink underline underline-offset-[3px]">
      {children}
    </a>
  )
}

/** The way back to a list, above a screen's name. */
export function BackLink({ href, children }: { href: string; children: ReactNode }) {
  return (
    <a href={href} onClick={(e) => follow(e, href)} className="inline-flex min-h-11 items-center gap-1.5 text-sm font-extrabold text-ink no-underline underline-offset-[3px] hover:underline">
      <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="square">
        <path d="M15 6l-6 6 6 6" />
      </svg>
      {children}
    </a>
  )
}

export function StateTag({ state, label }: { state: SheetState['assessment']['state']; label: string }) {
  return <Tag tone={stateTone[state]}>{label}</Tag>
}

/** A compact band of up to three numbers: the phone's totals. */
export function SmallBand({ cells }: { cells: { label: string; value: string; tone?: 'ink' | 'attention' | 'plain' }[] }) {
  return (
    <div className="grid border-y-2 border-ink" style={{ gridTemplateColumns: `repeat(${cells.length}, minmax(0, 1fr))` }}>
      {cells.map((c, i) => (
        <section key={c.label} className={`min-w-0 px-3 pt-2.5 pb-3 md:px-6 ${i > 0 ? 'border-l-2 border-ink' : ''} ${c.tone === 'ink' ? 'bg-ink text-sheet' : c.tone === 'attention' ? 'bg-marigold' : 'bg-sheet'}`}>
          <h2 className={`label-caps break-words ${c.tone === 'ink' ? 'text-on-ink-muted' : c.tone === 'attention' ? '' : 'text-text-muted'}`}>{c.label}</h2>
          <div className={`type-numeral text-[40px] md:text-[64px] ${c.tone === 'ink' ? 'text-teal-bright' : ''}`}>{c.value}</div>
        </section>
      ))}
    </div>
  )
}

/**
 * Lock or return submitted marks (the approved office panel): the numbers,
 * whether every learner has a mark or a reason, a note, and the two steps.
 * The server decides who may: never the person who entered the marks.
 */
export function LockPanel({ sheet, onDone }: { sheet: SheetState; onDone: (next: SheetState) => void }) {
  const [note, setNote] = useState('')
  const [working, setWorking] = useState<'lock' | 'return' | null>(null)
  const [failed, setFailed] = useState<string | null>(null)
  const x = sheet.assessment
  const submitted = sheet.ladder.find((step) => step.key === 'submitted')

  const run = async (step: 'lock' | 'return') => {
    if (step === 'return' && note.trim() === '') {
      setFailed(p.needNote)

      return
    }
    setWorking(step)
    setFailed(null)
    const result = step === 'lock' ? await assessmentApi.lock(x.id) : await assessmentApi.giveBack(x.id, note.trim())
    setWorking(null)
    if (result.kind === 'saved') {
      setNote('')
      slips.done({ word: copy.slips.saved, title: step === 'lock' ? p.locked : p.returned })
      onDone(result.data)
    } else if (result.kind === 'invalid') setFailed(Object.values(result.errors)[0]?.[0] ?? s.failed)
    else setFailed(result.kind === 'refused' ? s.refused : s.failed)
  }

  return (
    <section aria-label={p.label} className="flex flex-col gap-3">
      {submitted?.by && submitted.at !== null && <div className="label-caps text-text-muted">{p.submittedBy(submitted.by, a.when(submitted.at))}</div>}
      <div className="type-display text-[22px] leading-none">{p.what(x.class, x.subject, x.title)}</div>
      <div className="grid grid-cols-3 border-2 border-ink bg-sheet">
        {[
          [p.average, a.pct(sheet.stats.average)],
          [p.highest, a.pct(sheet.stats.highest)],
          [p.belowHalf, String(sheet.stats.below_half)],
        ].map(([label, value], i) => (
          <div key={label} className={`px-3 py-2 ${i > 0 ? 'border-l-2 border-ink' : ''}`}>
            <div className="label-caps text-text-muted">{label}</div>
            <div className="type-numeral text-[32px]">{value}</div>
          </div>
        ))}
      </div>
      <p className="text-sm">{sheet.stats.missing > 0 ? p.incomplete(sheet.stats.missing) : p.complete(sheet.stats.marked - sheet.stats.absent, sheet.stats.absent)}</p>
      {x.state !== 'submitted' ? (
        <p className="text-sm text-text-muted">{p.waiting(x.state_label)}</p>
      ) : (
        <>
          {failed !== null && <Notice tone="wrong" title={failed} />}
          {sheet.can_return && (
            <label className="block">
              <span className="label-caps mb-1.5 block text-text-muted">{p.note}</span>
              <input
                value={note}
                onChange={(e) => setNote(e.target.value)}
                placeholder={p.notePlaceholder}
                maxLength={255}
                className="h-11 w-full border-0 border-b-2 border-ink bg-field px-3 text-[15px] text-ink outline-none focus:border-teal"
              />
            </label>
          )}
          <div className="flex flex-wrap gap-2.5">
            {sheet.can_lock && (
              <Button busy={working === 'lock'} disabled={working !== null} onClick={() => void run('lock')}>
                {working === 'lock' ? p.locking : p.lock}
              </Button>
            )}
            {sheet.can_return && (
              <Button variant="secondary" busy={working === 'return'} disabled={working !== null} onClick={() => void run('return')}>
                {working === 'return' ? p.giving : p.giveBack}
              </Button>
            )}
          </div>
          {sheet.lock_note !== null && <p className="text-[13px] font-bold">{sheet.lock_note}</p>}
        </>
      )}
      <p className="text-[13px] text-text-muted">{p.fourEyes}</p>
    </section>
  )
}

/** How one term grade is made: each part, its assessments and score, the sum, the grade. */
export function Working({ data, onClose }: { data: WorkingState; onClose?: () => void }) {
  const w = data.working
  const t = a.working
  const pieces = w.parts.filter((part) => part.score !== null).map((part) => t.piece(part.weight, part.score ?? 0))

  return (
    <section aria-label={t.title} className="flex flex-col gap-3">
      <div className="flex items-start gap-2">
        <div className="min-w-0 flex-1">
          <div className="label-caps text-text-muted">{t.title}</div>
          <div className="type-display text-[22px] leading-none">{t.who(data.learner.name, data.subject.name)}</div>
        </div>
        {onClose !== undefined && (
          <button type="button" onClick={onClose} className="h-11 shrink-0 border-2 border-ink px-3 text-sm font-extrabold hover:bg-field">
            {t.close}
          </button>
        )}
      </div>
      {w.parts.map((part) => (
        <div key={part.key} className="border-2 border-ink bg-sheet p-3">
          <div className="flex items-baseline gap-2">
            <span className="flex-1 text-sm font-extrabold">{t.part(part.label, part.weight)}</span>
            <span className="type-numeral text-2xl">{a.pct(part.score)}</span>
          </div>
          <p className="mt-1 text-[13px] text-text-muted">
            {part.assessments
              .map((x) => (x.value !== null ? t.line(x.title, String(x.value), x.out_of) : t.reasonLine(x.title, x.reason ?? t.notCounted)))
              .concat(part.assessments.length > 0 ? [part.rule_label] : [])
              .join(' · ')}
          </p>
          <p className="text-[13px] text-text-muted">{part.missing_label}</p>
        </div>
      ))}
      {w.pending.length > 0 && (
        <p className="text-[13px]">
          <strong>{t.pending}</strong> {w.pending.map((x) => x.title).join(', ')}
        </p>
      )}
      {w.ready ? (
        <div className="flex flex-wrap items-center gap-3 border-t-2 border-ink pt-3">
          <span className="ref flex-1 text-[13px]">{t.term(pieces.join(' + '), a.pct(w.percent))}</span>
          {w.grade !== null && <Tag tone={w.passes === false ? 'wrong' : 'done'}>{w.grade}</Tag>}
          {w.word !== null && <span className="text-sm font-extrabold">{w.word}</span>}
        </div>
      ) : (
        <p className="text-sm font-bold">{w.not_ready}</p>
      )}
      <p className="text-[13px] text-text-muted">{t.plan(w.plan.version, w.plan.default)}</p>
    </section>
  )
}
