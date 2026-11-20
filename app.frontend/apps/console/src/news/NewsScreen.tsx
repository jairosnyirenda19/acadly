import { api, useLoad, type paths } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { read, settle } from '../setup/api'
import { Input } from '../setup/parts'
import { day } from '../settings/api'
import { PageHead, SmallButton, Tag } from '../settings/parts'
import { useWorkIn } from '../shell/WorkIn'
import { Attention } from '../programmes/parts'

const t = copy.news

type Board = paths['/v1/news/{division}']['get']['responses'][200]['content']['application/json']
type Post = Board['posts'][number]
type Audience = 'everyone' | 'faculty' | 'department' | 'programme' | 'year' | 'staff'
interface Draft {
  title: string
  body: string
  audience: Audience
  audience_id: string
  year: string
  pinned_until: string
  publish_at: string
}

const blank: Draft = { title: '', body: '', audience: 'everyone', audience_id: '', year: '', pinned_until: '', publish_at: '' }

const newsApi = {
  load: (division: string) => read(api.GET('/v1/news/{division}', { params: { path: { division } } })),
  body: (d: Draft) => ({
    title: d.title,
    body: d.body,
    audience: d.audience,
    audience_id: d.audience_id === '' ? null : d.audience_id,
    year: d.year === '' ? null : Number(d.year),
    pinned_until: d.pinned_until === '' ? null : d.pinned_until,
    publish_at: d.publish_at === '' ? null : d.publish_at,
  }),
  publish: (division: string, d: Draft) => settle(api.POST('/v1/news/{division}', { params: { path: { division } }, body: newsApi.body(d) as never })),
  edit: (id: string, d: Draft) => settle(api.PUT('/v1/news/posts/{id}', { params: { path: { id } }, body: newsApi.body(d) as never })),
  withdraw: (id: string, reason: string) => settle(api.POST('/v1/news/posts/{id}/withdraw', { params: { path: { id } }, body: { reason } })),
}

/** News (D-101, the approved board): write and publish to an audience, and the news already out with how many have seen it. */
export function NewsScreen() {
  const { division } = useWorkIn()

  if (division === null) {
    return (
      <div className="px-[clamp(16px,3vw,40px)] pt-7">
        <Attention>
          <b>{t.chooseTitle}.</b> {t.choose}
        </Attention>
      </div>
    )
  }

  return <Loaded key={division.id} division={division.id} />
}

function Loaded({ division }: { division: string }) {
  const loaded = useLoad(`news:${division}`, () => newsApi.load(division))
  const [editing, setEditing] = useState<string | null>(null)
  const [draft, setDraft] = useState<Draft>(blank)
  const [busy, setBusy] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)

  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} rows={6} onRetry={loaded.reload} />

  const data = loaded.data
  const a = data.audiences
  const set = (patch: Partial<Draft>) => setDraft({ ...draft, ...patch })
  const save = async () => {
    setBusy(true)
    setProblem(null)
    const result = editing === null ? await newsApi.publish(division, draft) : await newsApi.edit(editing, draft)
    setBusy(false)
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: editing === null ? t.published : t.changed })
      setDraft(blank)
      setEditing(null)
      loaded.reload()
    } else setProblem(result.kind === 'invalid' ? (Object.values(result.errors)[0]?.[0] ?? t.failed) : t.failed)
  }
  const withdraw = async (p: Post) => {
    const reason = window.prompt(t.withdrawReason)
    if (reason === null) return
    const result = await newsApi.withdraw(p.id, reason)
    if (result.kind === 'saved') loaded.reload()
    else slips.wrong({ word: t.notSaved, title: result.kind === 'invalid' ? (Object.values(result.errors)[0]?.[0] ?? t.failed) : t.failed })
  }
  const choices: { key: Audience; label: string }[] = [
    { key: 'everyone', label: t.audiences.everyone },
    ...(a !== null && a.faculties.length > 0 ? [{ key: 'faculty' as const, label: t.audiences.faculty }] : []),
    ...(a !== null && a.departments.length > 0 ? [{ key: 'department' as const, label: t.audiences.department }] : []),
    ...(a !== null && a.programmes.length > 0 ? [{ key: 'programme' as const, label: t.audiences.programme }] : []),
    ...(a !== null && a.years.length > 0 ? [{ key: 'year' as const, label: t.audiences.year }] : []),
    { key: 'staff', label: t.audiences.staff },
  ]
  const options = a === null ? [] : draft.audience === 'faculty' ? a.faculties : draft.audience === 'department' ? a.departments : draft.audience === 'programme' ? a.programmes : []

  return (
    <>
      <PageHead kicker={t.kicker} title={data.can_publish ? t.writeTitle : t.title} intro={data.can_publish ? t.intro : t.readIntro} />
      <div className="grid items-start gap-4 px-[clamp(16px,3vw,40px)] pb-8 xl:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]">
        {data.can_publish && (
          <section aria-label={t.writeTitle} className="grid gap-3 border-2 border-ink bg-sheet px-4 py-3.5">
            {editing !== null && <Attention>{t.editingNote}</Attention>}
            {problem !== null && (
              <Notice tone="wrong" title={t.notSaved}>
                {problem}
              </Notice>
            )}
            <Input label={t.fields.title} value={draft.title} onChange={(e) => set({ title: e.target.value })} />
            <label className="grid gap-1">
              <span className="label-caps text-text-muted">{t.fields.body}</span>
              <textarea value={draft.body} onChange={(e) => set({ body: e.target.value })} rows={7} className="border-b-2 border-ink bg-field px-3 py-2 text-[15px]" />
            </label>
            <fieldset className="grid gap-1.5">
              <legend className="label-caps mb-1 text-text-muted">{t.fields.audience}</legend>
              {choices.map((c) => (
                <label key={c.key} className="flex min-h-11 items-center gap-2.5 text-[15px]">
                  <input type="radio" name="audience" className="size-5 accent-ink" checked={draft.audience === c.key} onChange={() => set({ audience: c.key, audience_id: '', year: '' })} />
                  {c.label}
                </label>
              ))}
            </fieldset>
            {options.length > 0 && (
              <label className="grid gap-1">
                <span className="label-caps text-text-muted">{t.audiences[draft.audience]}</span>
                <select value={draft.audience_id} onChange={(e) => set({ audience_id: e.target.value })} className="h-11 border-b-2 border-ink bg-field px-2">
                  <option value="">{t.chooseOne}</option>
                  {options.map((o) => (
                    <option key={o.id} value={o.id}>
                      {o.name}
                    </option>
                  ))}
                </select>
              </label>
            )}
            {draft.audience === 'year' && a !== null && (
              <label className="grid gap-1">
                <span className="label-caps text-text-muted">{t.audiences.year}</span>
                <select value={draft.year} onChange={(e) => set({ year: e.target.value })} className="h-11 border-b-2 border-ink bg-field px-2">
                  <option value="">{t.chooseOne}</option>
                  {a.years.map((y) => (
                    <option key={y} value={y}>
                      {t.yearOf(y)}
                    </option>
                  ))}
                </select>
              </label>
            )}
            <div className="flex flex-wrap gap-4">
              <label className="grid gap-1">
                <span className="label-caps text-text-muted">{t.fields.pinned}</span>
                <input type="date" value={draft.pinned_until} onChange={(e) => set({ pinned_until: e.target.value })} className="h-11 border-b-2 border-ink bg-field px-2" />
              </label>
              <label className="grid gap-1">
                <span className="label-caps text-text-muted">{t.fields.publishAt}</span>
                <input type="datetime-local" value={draft.publish_at} onChange={(e) => set({ publish_at: e.target.value })} className="h-11 border-b-2 border-ink bg-field px-2" />
              </label>
            </div>
            <div className="flex gap-2.5">
              <Button busy={busy} onClick={() => void save()}>
                {editing === null ? t.publish : t.saveChange}
              </Button>
              {editing !== null && (
                <Button variant="secondary" onClick={() => (setEditing(null), setDraft(blank))}>
                  {t.cancel}
                </Button>
              )}
            </div>
          </section>
        )}
        <section aria-label={t.listTitle} className="border-2 border-ink bg-sheet">
          <h2 className="border-b-2 border-ink px-4 py-2.5 text-[18px] font-extrabold">{t.listTitle}</h2>
          {data.posts.length === 0 ? (
            <p className="px-4 py-4 text-text-muted">{t.none}</p>
          ) : (
            <ol>
              {data.posts.map((p) => (
                <li key={p.id} className="grid gap-1 border-b border-rule px-4 py-3 last:border-b-0">
                  <span className="flex flex-wrap items-center gap-2">
                    <b className="flex-1">{p.title}</b>
                    {p.pinned && <Tag tone="plain">{t.pinned}</Tag>}
                    {p.scheduled ? <Tag tone="plain">{t.scheduled}</Tag> : <Tag tone="done">{t.live}</Tag>}
                    {p.updated && <Tag tone="plain">{t.updated}</Tag>}
                  </span>
                  <span className="text-[13px] text-text-muted">{`${p.audience_label} · ${day(p.publish_at)} · ${p.author}`}</span>
                  {p.seen !== null && <span className="text-[13px]">{p.reach !== null ? t.seenOf(p.seen, p.reach) : t.seen(p.seen)}</span>}
                  {!data.can_publish && <p className="text-[14px] whitespace-pre-line">{p.body}</p>}
                  {data.can_publish && (
                    <span className="flex gap-2">
                      <SmallButton
                        onClick={() => (
                          setEditing(p.id),
                          setDraft({ title: p.title, body: p.body, audience: p.audience as Audience, audience_id: p.audience_id ?? '', year: p.year !== null ? String(p.year) : '', pinned_until: p.pinned_until ?? '', publish_at: '' })
                        )}
                      >
                        {t.change}
                      </SmallButton>
                      <SmallButton tone="red" onClick={() => void withdraw(p)}>
                        {t.withdraw}
                      </SmallButton>
                    </span>
                  )}
                </li>
              ))}
            </ol>
          )}
        </section>
      </div>
    </>
  )
}
