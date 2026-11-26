import { api, useLoad, type paths } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useEffect, useState } from 'react'
import { copy } from '../copy'
import { read, settle, type Saved } from '../setup/api'
import { Input, Select, TextArea } from '../setup/parts'
import { PageHead, SmallButton, Tag } from '../settings/parts'

type State = paths['/v1/notices']['get']['responses'][200]['content']['application/json']
type Reach = paths['/v1/notices/reach']['get']['responses'][200]['content']['application/json']
type Audience = 'school' | 'division' | 'level' | 'class'
type Done<T> = Promise<{ data?: T; error?: unknown; response: Response }>

const t = copy.notices

const noticesApi = {
  list: () => read(api.GET('/v1/notices')),
  reach: (q: { audience: Audience; division_id?: string; level_code?: string; class_id?: string }) => read(api.GET('/v1/notices/reach', { params: { query: q as never } })),
  publish: (body: Record<string, unknown>): Promise<Saved<{ id: string }>> => settle(api.POST('/v1/notices', { body: body as never }) as Done<{ id: string }>),
  withdraw: (id: string, reason: string) => settle(api.POST('/v1/notices/{id}/withdraw', { params: { path: { id } }, body: { reason } as never }) as Done<{ id: string }>),
}

const when = (iso: string) => new Date(iso).toLocaleString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })

/** Notices to families (D-115): what the school told families, how many read it, and the texts delivered. */
export function NoticesScreen() {
  const loaded = useLoad('notices', () => noticesApi.list())
  const [writing, setWriting] = useState(false)
  const data = loaded.data

  if (data === undefined) return <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} rows={6} onRetry={loaded.reload} />

  return (
    <>
      <PageHead
        kicker={t.kicker}
        title={t.title}
        intro={t.intro}
        actions={
          !writing && (data.choices.school || data.choices.divisions.length > 0) ? <Button onClick={() => setWriting(true)}>{t.new}</Button> : undefined
        }
      />
      <div className="grid gap-6 px-[clamp(16px,3vw,40px)] pb-8">
        {writing && (
          <Compose
            state={data}
            onDone={() => {
              setWriting(false)
              loaded.reload()
              slips.done({ word: copy.slips.saved, title: t.published })
            }}
            onCancel={() => setWriting(false)}
          />
        )}
        <section aria-label={t.title} className="border-2 border-ink bg-sheet px-4 pt-2 pb-4">
          {data.notices.length === 0 ? (
            <p className="py-3 text-sm text-text-muted">{t.none}</p>
          ) : (
            <ul>
              {data.notices.map((n) => (
                <NoticeRow key={n.id} notice={n} onChanged={loaded.reload} />
              ))}
            </ul>
          )}
          <p className="mt-3 text-[13px] text-text-muted">{t.readNote}</p>
        </section>
      </div>
    </>
  )
}

function NoticeRow({ notice: n, onChanged }: { notice: State['notices'][number]; onChanged: () => void }) {
  const [asking, setAsking] = useState(false)
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)

  const withdraw = async () => {
    setBusy(true)
    const result = await noticesApi.withdraw(n.id, reason.trim())
    setBusy(false)
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: t.withdrawn })
      onChanged()
    } else slips.wrong({ word: t.notSaved, title: result.kind === 'invalid' ? (Object.values(result.errors)[0]?.[0] ?? t.failed) : t.failed })
  }

  return (
    <li className="grid gap-x-4 gap-y-1 border-b border-rule py-3 md:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)_150px_130px_170px_auto] md:items-center">
      <span className="min-w-0">
        <b className={`block text-[15px] ${n.withdrawn !== null ? 'line-through' : ''}`}>{n.title}</b>
        <span className="block text-[13px] text-text-muted">{t.by(n.author)}</span>
      </span>
      <span className="text-sm">{n.for}</span>
      <span className="text-sm">{n.scheduled ? <Tag tone="attention">! {t.scheduled(when(n.publish_at))}</Tag> : when(n.publish_at)}</span>
      <span className="text-sm">{n.withdrawn !== null ? <span className="text-text-muted">{t.withdrawnLabel}</span> : t.read(n.read, n.families)}</span>
      <span className="text-sm">
        {n.withdrawn !== null ? (
          <Tag tone="plain">■ {t.withdrawnTag(n.withdrawn.reason)}</Tag>
        ) : n.texts === null ? (
          <span className="text-text-muted">{t.noText}</span>
        ) : n.texts.not_delivered > 0 ? (
          <Tag tone="attention">! {t.delivered(n.texts.delivered, n.texts.all)}</Tag>
        ) : (
          <Tag tone="done">✓ {t.delivered(n.texts.delivered, n.texts.all)}</Tag>
        )}
      </span>
      <span className="md:text-right">{n.withdrawn === null && !asking && <SmallButton onClick={() => setAsking(true)}>{t.withdraw}</SmallButton>}</span>
      {asking && (
        <div className="grid gap-2 md:col-span-6">
          <Input label={t.withdrawReason} required value={reason} onChange={(e) => setReason(e.target.value)} maxLength={255} hint={t.withdrawNote} />
          <div className="flex gap-2">
            <Button busy={busy} disabled={reason.trim() === ''} onClick={() => void withdraw()}>
              {t.withdraw}
            </Button>
            <Button variant="secondary" onClick={() => setAsking(false)}>
              {t.cancel}
            </Button>
          </div>
        </div>
      )}
    </li>
  )
}

function Compose({ state, onDone, onCancel }: { state: State; onDone: () => void; onCancel: () => void }) {
  const { choices } = state
  const first = choices.divisions[0]
  const [audience, setAudience] = useState<Audience>(first?.whole === true ? 'division' : first !== undefined ? 'class' : 'school')
  const [division, setDivision] = useState(first?.id ?? '')
  const [level, setLevel] = useState('')
  const [klass, setKlass] = useState('')
  const [title, setTitle] = useState('')
  const [body, setBody] = useState('')
  const [pin, setPin] = useState('')
  const [later, setLater] = useState('')
  const [texting, setTexting] = useState(false)
  const [text, setText] = useState('')
  const [reach, setReach] = useState<Reach | null>(null)
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [problem, setProblem] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const current = choices.divisions.find((d) => d.id === division)
  const ready = audience === 'school' || (audience === 'division' && division !== '') || (audience === 'level' && level !== '') || (audience === 'class' && klass !== '')

  useEffect(() => {
    let live = true
    setReach(null)
    if (!ready) return
    void noticesApi.reach({ audience, ...(audience === 'school' ? {} : audience === 'class' ? { class_id: klass } : { division_id: division }), ...(audience === 'level' ? { level_code: level } : {}) }).then((r) => {
      if (live && r !== null && r !== 'refused' && r !== 'missing') setReach(r)
    })

    return () => {
      live = false
    }
  }, [audience, division, level, klass, ready])

  const publish = async () => {
    setBusy(true)
    setErrors({})
    setProblem(null)
    const result = await noticesApi.publish({
      audience,
      division_id: audience === 'division' || audience === 'level' ? division : null,
      level_code: audience === 'level' ? level : null,
      class_id: audience === 'class' ? klass : null,
      title: title.trim(),
      body: body.trim(),
      sms_text: texting && later === '' ? text.trim() : null,
      pinned_until: pin === '' ? null : pin,
      publish_at: later === '' ? null : later,
    })
    setBusy(false)
    if (result.kind === 'saved') onDone()
    else if (result.kind === 'invalid') setErrors(result.errors)
    else setProblem(t.failed)
  }

  const options: [Audience, string, boolean][] = [
    ['school', t.audiences.school, choices.school],
    ['division', t.audiences.division, current?.whole === true],
    ['level', t.audiences.level, current?.whole === true],
    ['class', t.audiences.class, (current?.classes.length ?? 0) > 0],
  ]

  return (
    <section aria-label={t.new} className="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_380px]">
      <div className="grid gap-4 border-2 border-ink bg-sheet p-4">
        <h2 className="type-display text-[26px] leading-none">{t.new}</h2>
        {problem !== null && <Notice tone="wrong" title={problem} />}
        <Input label={t.titleField} required value={title} onChange={(e) => setTitle(e.target.value)} maxLength={120} error={errors.title?.[0]} />
        <TextArea label={t.body} required rows={5} value={body} onChange={(e) => setBody(e.target.value)} maxLength={4000} error={errors.body?.[0]} />
        <div className="grid gap-4 sm:grid-cols-2">
          <Input label={t.pinUntil} type="date" value={pin} onChange={(e) => setPin(e.target.value)} hint={t.pinHint} error={errors.pinned_until?.[0]} />
          <Input label={t.later} type="datetime-local" value={later} onChange={(e) => setLater(e.target.value)} hint={t.laterHint} />
        </div>
        <div className="grid gap-2 border-t border-rule pt-3">
          <label className={`flex items-center gap-2.5 text-[15px] font-extrabold ${later !== '' ? 'text-text-muted' : ''}`}>
            <input type="checkbox" checked={texting && later === ''} disabled={later !== ''} onChange={(e) => setTexting(e.target.checked)} className="size-5 accent-ink" />
            {t.alsoText}
          </label>
          {later !== '' && <p className="text-[13px] text-text-muted">{t.textNow}</p>}
          {texting && later === '' && (
            <TextArea
              label={t.textField}
              required
              rows={2}
              value={text}
              onChange={(e) => setText(e.target.value)}
              maxLength={state.text_room}
              hint={t.textHint(state.text_room - text.length)}
              error={errors.sms_text?.[0]}
            />
          )}
        </div>
        <div className="flex flex-wrap gap-2.5">
          <Button busy={busy} disabled={!ready || title.trim() === '' || body.trim() === '' || (texting && later === '' && text.trim() === '')} onClick={() => void publish()}>
            {reach !== null ? t.publishTo(reach.families) : t.publish}
          </Button>
          <Button variant="secondary" onClick={onCancel}>
            {t.cancel}
          </Button>
        </div>
      </div>
      <aside className="grid gap-3">
        <fieldset className="grid gap-2 border-2 border-ink bg-sheet p-4">
          <legend className="label-caps px-1 text-text-muted">{t.forWho}</legend>
          {choices.divisions.length > 1 && (
            <Select label={t.division} value={division} onChange={(e) => (setDivision(e.target.value), setLevel(''), setKlass(''))}>
              {choices.divisions.map((d) => (
                <option key={d.id} value={d.id}>
                  {d.name}
                </option>
              ))}
            </Select>
          )}
          {options
            .filter(([, , allowed]) => allowed)
            .map(([value, label]) => (
              <label key={value} className={`flex items-center gap-2.5 bg-sheet px-3 py-2.5 ${audience === value ? 'border-[3px] border-ink' : 'border border-rule'}`}>
                <input type="radio" name="notice-audience" checked={audience === value} onChange={() => setAudience(value)} className="size-5 accent-ink" />
                <span className="text-[15px] font-extrabold">{value === 'division' && current !== undefined ? `${label} · ${current.name}` : label}</span>
              </label>
            ))}
          {audience === 'level' && current !== undefined && (
            <Select label={t.level} required value={level} onChange={(e) => setLevel(e.target.value)} error={errors.level_code?.[0]}>
              <option value="">{t.choose}</option>
              {current.levels.map((l) => (
                <option key={l.code} value={l.code}>
                  {l.label}
                </option>
              ))}
            </Select>
          )}
          {audience === 'class' && current !== undefined && (
            <Select label={t.klass} required value={klass} onChange={(e) => setKlass(e.target.value)} error={errors.class_id?.[0]}>
              <option value="">{t.choose}</option>
              {current.classes.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </Select>
          )}
        </fieldset>
        {reach !== null && (
          <section className="border-2 border-ink bg-sheet px-4 py-3 text-sm">
            <div className="label-caps mb-1 text-text-muted">{t.reaches}</div>
            <p>{t.reachLine(reach.families, reach.learners)}</p>
            <p>{t.portalLine(reach.in_portal)}</p>
            {texting && later === '' && <p className="mt-1 font-bold">! {t.textsLine(reach.texts)}</p>}
          </section>
        )}
        <p className="text-[13px] text-text-muted">{t.onceNote}</p>
      </aside>
    </section>
  )
}
