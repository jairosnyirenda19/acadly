import { navigate, useLoad } from '@acadly/kernel'
import { Button, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { day } from '../settings/api'
import { PageHead, Tag } from '../settings/parts'
import { earlyApi, firstError, type ReviewRow } from './api'
import { Tile } from './MyRoomScreen'
import { StepLegend, StepMark } from './steps'

const t = copy.early

/** The state of a review as a mark and a word. */
export function ReviewState({ state, label }: { state: string | null; label: string }) {
  if (state === 'checked' || state === 'shared') return <Tag tone="done">✓ {label}</Tag>
  if (state === 'ready') return <Tag tone="attention">! {label}</Tag>

  return <Tag tone="plain">{label}</Tag>
}

/** Progress reviews (the approved design): every child of the rooms you see, draft, check and share. */
export function ReviewsScreen() {
  const loaded = useLoad('early:reviews', () => earlyApi.reviews())
  const [busy, setBusy] = useState(false)
  const data = loaded.data

  if (data === undefined) return <Waiting phase={loaded.phase} what={t.reviewsTitle} labels={copy.loading} rows={6} onRetry={loaded.reload} />

  const checked = data.children.filter((c) => c.state === 'checked' && c.review_id !== null).map((c) => c.review_id ?? '')
  const tot = data.totals

  const share = async () => {
    setBusy(true)
    const result = await earlyApi.share(checked)
    setBusy(false)
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.sent, title: t.shared(result.data.shared) })
      loaded.reload()
    } else slips.wrong({ word: t.notSaved, title: firstError(result, t.failed) })
  }

  return (
    <>
      <PageHead kicker={copy.shell.groups.daily} title={t.reviewsTitle} intro={t.reviewsIntro} />
      <div className="grid gap-5 px-[clamp(16px,3vw,40px)] pb-8">
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          <Tile label={t.totalChildren} value={tot.children} note={t.childrenNote} tone="ink" />
          <Tile label={t.totalStarted} value={tot.started} note={t.totalStartedNote(tot.children - tot.started)} tone={tot.started < tot.children ? 'attention' : 'plain'} />
          <Tile label={t.totalReady} value={tot.ready} note={t.totalReadyNote} tone={tot.ready > 0 ? 'attention' : 'plain'} />
          <Tile label={t.totalChecked} value={tot.checked} note={t.totalCheckedNote} />
        </div>

        <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
          <section aria-label={t.reviewsTitle} className="min-w-0 border-2 border-ink bg-sheet px-4 pt-2 pb-4">
            <div className="label-caps hidden grid-cols-[200px_150px_minmax(0,1fr)_170px_90px] gap-3 border-b-2 border-ink py-2 text-text-muted lg:grid">
              <span>{t.cols.child}</span>
              <span>{t.cols.room}</span>
              <span>{t.cols.steps}</span>
              <span>{t.cols.state}</span>
              <span />
            </div>
            <ul>
              {data.children.map((c) => (
                <Row key={c.learner_id} row={c} />
              ))}
            </ul>
            <p className="mt-2.5 text-[13px] text-text-muted">{t.stepsNote}</p>
          </section>
          <aside className="grid content-start gap-4">
            {data.can_review && (
              <section className="grid gap-2 bg-ink p-4 text-sheet">
                <h2 className="label-caps text-on-ink-muted">{t.shareTitle}</h2>
                <p className="text-[15px]">{checked.length === 0 ? t.noneToShare : t.shareLine(checked.length)}</p>
                <p className="text-[13px] text-on-ink-soft">{t.shareNote}</p>
                {checked.length > 0 && (
                  <Button busy={busy} onClick={() => void share()}>
                    {t.share(checked.length)}
                  </Button>
                )}
              </section>
            )}
            <section className="border-2 border-ink bg-sheet p-4">
              <h2 className="type-display mb-2 text-[26px] leading-none">{t.stepsTitle}</h2>
              <StepLegend />
            </section>
          </aside>
        </div>
      </div>
    </>
  )
}

function Row({ row: c }: { row: ReviewRow }) {
  const href = `/reviews/${c.learner_id}`

  return (
    <li className="grid items-center gap-x-3 gap-y-1.5 border-b border-rule py-2.5 lg:grid-cols-[200px_150px_minmax(0,1fr)_170px_90px]">
      <a href={href} onClick={(e) => (e.preventDefault(), navigate(href))} className="text-[15px] font-bold text-ink no-underline hover:underline">
        {c.name}
      </a>
      <span className="text-sm text-text-muted">{c.room}</span>
      <span className="flex flex-wrap gap-1">
        {c.steps.map((s, i) => (
          <StepMark key={i} step={s} wide="w-9" />
        ))}
      </span>
      <span>
        <ReviewState state={c.state} label={c.state_label} />
        {c.by !== null && <span className="mt-0.5 block text-xs text-text-muted">{`${t.by(c.by)}${c.updated_at !== null ? `, ${day(c.updated_at)}` : ''}`}</span>}
      </span>
      <span className="lg:text-right">
        <Button variant="secondary" onClick={() => navigate(href)}>
          {t.open}
        </Button>
      </span>
    </li>
  )
}
