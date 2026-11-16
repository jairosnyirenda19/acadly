import { can, navigate, useAppContext, useLoad, useLocation } from '@acadly/kernel'
import { Button, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { errorFor } from '../setup/api'
import { Input, Total, TotalsBand } from '../setup/parts'
import { SmallButton, Tag } from '../settings/parts'
import { clock, lessonsApi, type MissedState } from './api'
import { Glyph, RegistersHead, useSave } from './parts'

const t = copy.registers
const s = copy.settings

type Missed = MissedState['missed'][number]

const toldTone: Record<string, 'done' | 'attention' | 'wrong' | 'plain'> = {
  delivered: 'done',
  sent: 'plain',
  accepted: 'plain',
  waiting: 'plain',
  not_delivered: 'wrong',
  not_reachable: 'attention',
}

const COLS = 'md:grid-cols-[minmax(150px,1.2fr)_minmax(90px,0.7fr)_56px_minmax(190px,1.6fr)_minmax(120px,0.9fr)_minmax(140px,1fr)]'
const WORDING = '/settings/messages/wording?notice=attendance.lesson_missed'

/** Learners here this morning and absent from a lesson later (the approved design), to follow up the same day. */
export function MissedScreen() {
  const { query } = useLocation()
  const day = query.get('day')
  const context = useAppContext()
  const loaded = useLoad(`missed:${day ?? ''}`, () => lessonsApi.missed(day))
  const state = loaded.data

  if (state === undefined) {
    return <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} band={[t.bandMissed, t.bandFollowed, t.bandTold]} rows={6} onRetry={loaded.reload} />
  }

  const totals = state.totals
  const open = totals.missed - totals.followed_up

  return (
    <>
      <RegistersHead kicker={t.tabs.missed} intro={t.missedIntro} day={state.day} today={state.today} current="missed" tabs />
      <TotalsBand>
        <Total tone="ink" label={t.bandMissed} value={String(totals.missed)} note={t.bandMissedNote(totals.learners, totals.missed)} />
        <Total tone={open > 0 ? 'attention' : 'plain'} label={t.bandFollowed} value={String(totals.followed_up)} suffix={` / ${totals.missed}`} note={t.bandFollowedNote} />
        <Total label={t.bandTold} value={String(totals.told)} note={t.bandToldNote(state.notice_on)} />
      </TotalsBand>

      <div className="flex flex-1 flex-wrap items-stretch">
        <section className="min-w-0 flex-[3_1_640px] bg-sheet px-3 pt-4 pb-6 md:px-[clamp(16px,3vw,40px)]">
          {/* The ledger keeps its columns; when the page is narrower it scrolls sideways inside its own box. */}
          <div className="overflow-x-auto">
            <div className="md:min-w-[840px]">
              <div className={`label-caps hidden gap-3 border-b-2 border-ink px-4 pb-2 text-text-muted md:grid ${COLS}`}>
                <span>{t.colLearner}</span>
                <span>{t.colClass}</span>
                <span>{t.colMorning}</span>
                <span>{t.colMissed}</span>
                <span>{t.colContacts}</span>
                <span />
              </div>
              {state.missed.length === 0 && <p className="px-4 py-4 text-sm text-text-muted">{t.noMissed}</p>}
              {state.missed.map((m) => (
                <Row key={`${m.register_id}:${m.learner_id}`} missed={m} noticeOn={state.notice_on} onDone={loaded.reload} />
              ))}
            </div>
          </div>
          <p className="mt-3 text-[13px] text-text-muted">{t.missedRule}</p>
        </section>
        <section className="min-w-0 flex-[1_1_300px] border-ink bg-paper px-3 pt-4 pb-6 max-[999px]:border-t-2 md:px-[clamp(16px,3vw,32px)] min-[1000px]:border-l-2">
          <div className="border-2 border-ink bg-sheet px-4 pt-3.5 pb-4">
            <div className="mb-2 flex flex-wrap items-center gap-2">
              <span className="label-caps text-text-muted">{t.noticeTitle}</span>
              <Tag tone={state.notice_on ? 'done' : 'plain'}>{state.notice_on ? t.on : t.off}</Tag>
            </div>
            <div className="type-display mb-2.5 text-[22px] leading-none">{t.noticeName}</div>
            <p className="text-[13px] text-text-muted">{state.notice_on ? t.noticeOnText : t.noticeOffText}</p>
            {can(context, 'notify.manage') && (
              <div className="mt-3">
                <Button variant="secondary" onClick={() => navigate(WORDING)}>
                  {t.openWording}
                </Button>
              </div>
            )}
          </div>
        </section>
      </div>
    </>
  )
}

function Row({ missed: m, noticeOn, onDone }: { missed: Missed; noticeOn: boolean; onDone: () => void }) {
  const [calling, setCalling] = useState(false)
  const [note, setNote] = useState('')
  const save = useSave<unknown>()
  const followed = m.follow_ups.length > 0

  return (
    <div className={`border-b border-rule px-4 py-2.5 ${followed ? '' : 'bg-marigold-wash'}`}>
      <div className={`grid gap-2 md:items-center md:gap-3 ${COLS}`}>
        <span className="text-base font-extrabold">{m.learner}</span>
        <span className="type-display text-lg leading-none">{m.class}</span>
        <span className="flex items-center gap-2">
          <Glyph mark={m.morning} size="size-[30px] text-[15px]" />
          <span className="text-[13px] text-text-muted md:hidden">{t.colMorning}</span>
        </span>
        <span className="flex items-center gap-2 text-sm">
          <Glyph mark="absent" size="size-[30px] text-[15px]" />
          <span>
            <strong>{t.missedLesson(m.subject, m.period, m.starts)}</strong>
            <br />
            <span className="text-text-muted">{m.cover ? t.coverBy(m.taken_by) : m.taken_by}</span>
          </span>
        </span>
        <span className="flex flex-wrap gap-1">
          {!noticeOn && m.told.length === 0 && <Tag tone="plain">{t.noticeOff}</Tag>}
          {noticeOn && m.told.length === 0 && <Tag tone="attention">{t.notToldYet}</Tag>}
          {m.told.map((d, i) => (
            <span key={i} className="flex flex-wrap items-center gap-1.5 text-xs" title={d.to}>
              <span className="font-bold">{t.channel[d.channel] ?? d.channel}</span>
              <Tag tone={toldTone[d.state] ?? 'plain'}>{t.toldState[d.state] ?? d.state}</Tag>
              <span className="ref text-text-muted">{clock(d.at)}</span>
            </span>
          ))}
        </span>
        <span>
          {followed ? (
            <span className="grid justify-items-start gap-1">
              <Tag tone="done">{t.followedUp}</Tag>
              {m.follow_ups.map((f, i) => (
                <span key={i} className="text-xs text-text-muted">
                  {t.followUpLine(f.note, clock(f.at), f.by)}
                </span>
              ))}
            </span>
          ) : (
            m.can_follow_up && !calling && <SmallButton onClick={() => setCalling(true)}>{t.recordCall}</SmallButton>
          )}
        </span>
      </div>
      {calling && (
        <form
          className="mt-2.5 grid gap-2.5 border-2 border-ink bg-sheet p-3.5 md:grid-cols-[1fr_auto] md:items-end"
          onSubmit={(e) => {
            e.preventDefault()
            void save.run(lessonsApi.called(m.register_id, m.learner_id, note), () => {
              setCalling(false)
              setNote('')
              onDone()
              slips.done({ word: copy.slips.saved, title: t.callRecorded })
            })
          }}
        >
          <Input label={t.calledNote} hint={t.calledNoteHint} value={note} onChange={(e) => setNote(e.target.value)} error={errorFor(save.errors, 'note') ?? errorFor(save.errors, 'learner_id')} maxLength={255} />
          <div className="flex flex-wrap gap-2">
            <Button type="submit" busy={save.working}>
              {save.working ? s.saving : t.saveCall}
            </Button>
            <Button variant="secondary" onClick={() => setCalling(false)}>
              {s.cancel}
            </Button>
          </div>
          {save.failed !== null && <p className="text-[13px] font-bold text-red-text md:col-span-2">{save.failed}</p>}
        </form>
      )}
    </div>
  )
}
