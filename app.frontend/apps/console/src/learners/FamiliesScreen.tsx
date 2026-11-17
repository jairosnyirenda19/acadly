import { api, forgetLoads, navigate, useLoad, type paths } from '@acadly/kernel'
import { Button, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { Input, Total, TotalsBand } from '../setup/parts'
import { read, settle } from '../setup/api'
import { PageHead, SmallButton, Tag } from '../settings/parts'

type FamiliesState = paths['/v1/families']['get']['responses'][200]['content']['application/json']
type Family = FamiliesState['families'][number]
type Child = Family['children'][number]

const t = copy.families

const familiesApi = {
  all: () => read(api.GET('/v1/families')),
  link: (id: string, action: string, reason: string) =>
    settle(api.POST('/v1/families/links/{id}', { params: { path: { id } }, body: { action, reason } as never }) as Promise<{ data?: FamiliesState; error?: unknown; response: Response }>),
}

/** What the office may do with a link in each state. */
const actionsFor: Record<string, string[]> = {
  requested: ['revoke'],
  active: ['suspend', 'revoke'],
  suspended: ['reinstate', 'revoke'],
  disputed: ['recheck', 'revoke'],
}

const linkTone = (state: string) => (state === 'active' ? 'done' : state === 'disputed' ? 'attention' : state === 'suspended' ? 'wrong' : 'plain')

const day = (iso: string) => new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })

/** Families (the approved design): learners grouped by contact phone number, and what needs checking. */
export function FamiliesScreen() {
  const loaded = useLoad('families', () => familiesApi.all())
  const [filter, setFilter] = useState<'all' | 'check'>('all')
  const [find, setFind] = useState('')
  const data = loaded.data

  if (data === undefined) {
    return <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} band={[t.bandFamilies, t.bandPortal, t.bandCheck]} rows={6} onRetry={loaded.reload} />
  }

  const needle = find.trim().toLowerCase()
  const digits = needle.replace(/\D/g, '')
  const shown = data.families.filter(
    (f) =>
      (filter === 'all' || f.checks.length > 0) &&
      (needle === '' ||
        f.names.some((n) => n.toLowerCase().includes(needle)) ||
        f.children.some((c) => c.name.toLowerCase().includes(needle)) ||
        (digits !== '' && (f.phone ?? '').replace(/\D/g, '').includes(digits))),
  )

  return (
    <>
      <PageHead
        kicker={t.kicker}
        title={t.title}
        intro={t.intro}
        actions={
          <Button variant="secondary" onClick={() => navigate('/learners')}>
            {t.back}
          </Button>
        }
      />
      <TotalsBand compact>
        <Total compact label={t.bandFamilies} value={String(data.totals.families)} note={t.bandFamiliesNote} />
        <Total compact label={t.bandPortal} value={String(data.totals.in_portal)} note={t.bandPortalNote} />
        <Total compact tone={data.totals.to_check > 0 ? 'attention' : 'ink'} label={t.bandCheck} value={String(data.totals.to_check)} note={t.bandCheckNote} />
      </TotalsBand>
      <section aria-label={t.title} className="flex-1 bg-sheet px-4 pt-4 pb-8 md:px-[clamp(16px,3vw,40px)]">
        <div className="mb-3 flex flex-wrap items-end gap-3">
          <div role="group" aria-label={t.title} className="flex border-2 border-ink text-sm font-extrabold">
            {(
              [
                ['all', t.all],
                ['check', `${t.toCheck} · ${data.totals.to_check}`],
              ] as const
            ).map(([key, label]) => (
              <button key={key} type="button" aria-pressed={filter === key} onClick={() => setFilter(key)} className={`min-h-11 px-3.5 ${filter === key ? 'bg-ink text-sheet' : ''}`}>
                {label}
              </button>
            ))}
          </div>
          <div className="w-full max-w-[320px]">
            <Input label={t.find} value={find} onChange={(e) => setFind(e.target.value)} />
          </div>
        </div>

        {data.families.length === 0 ? (
          <div className="py-4">
            <p className="text-[15px] font-extrabold">{t.none}</p>
            <p className="text-sm text-text-muted">{t.noneNote}</p>
          </div>
        ) : shown.length === 0 ? (
          <p className="py-4 text-sm text-text-muted">{t.noMatch}</p>
        ) : (
          <>
            <div className="label-caps hidden grid-cols-[minmax(0,1.1fr)_130px_minmax(0,1.6fr)_170px] gap-3 border-b-2 border-ink pb-2 text-[10px] text-text-muted md:grid">
              <span>{t.contact}</span>
              <span>{t.phone}</span>
              <span>{t.children}</span>
              <span>{t.portal}</span>
            </div>
            <ul>
              {shown.map((f) => (
                <FamilyRow key={f.phone ?? f.names.join()} family={f} onChanged={loaded.set} />
              ))}
            </ul>
          </>
        )}
      </section>
    </>
  )
}

function FamilyRow({ family, onChanged }: { family: Family; onChanged: (next: FamiliesState) => void }) {
  return (
    <li className="grid gap-x-3 gap-y-1.5 border-b border-rule py-3 md:grid-cols-[minmax(0,1.1fr)_130px_minmax(0,1.6fr)_170px]">
      <span className="text-[15px] font-extrabold">{family.names.join(' · ')}</span>
      <span className="font-mono text-sm">{family.phone}</span>
      <span className="grid gap-1.5">
        {family.children.map((c) => (
          <ChildLine key={c.id} child={c} onChanged={onChanged} />
        ))}
        {family.checks.map((c, i) => (
          <span key={i} className="border-l-4 border-marigold bg-marigold-wash px-2.5 py-1.5 text-[13px]">
            <strong aria-hidden="true">! </strong>
            {c.kind === 'not_their_child' ? t.notTheirChild(c.learner ?? '') : t.namesDiffer}
          </span>
        ))}
      </span>
      <span className="text-sm">
        {family.portal.signed_in && family.portal.last_signed_in_at !== null ? (
          <Tag tone="done">✓ {t.signedIn(day(family.portal.last_signed_in_at))}</Tag>
        ) : (
          <span className="text-text-muted">{t.notSignedIn}</span>
        )}
      </span>
    </li>
  )
}

function ChildLine({ child, onChanged }: { child: Child; onChanged: (next: FamiliesState) => void }) {
  const [acting, setActing] = useState<string | null>(null)
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const link = child.link

  const send = async () => {
    if (link === null || acting === null) return
    setBusy(true)
    const r = await familiesApi.link(link.id, acting, reason.trim())
    setBusy(false)

    if (r.kind === 'saved') {
      forgetLoads()
      slips.done({ word: copy.slips.saved, title: t.done })
      setActing(null)
      setReason('')
      onChanged(r.data)
    } else slips.wrong({ word: copy.settings.failed, title: t.failed })
  }

  return (
    <span className="block">
      <span className="flex flex-wrap items-center gap-2 text-sm">
        <a href={`/learners/${child.id}`} onClick={(e) => (e.preventDefault(), navigate(`/learners/${child.id}`))} className="font-extrabold text-ink">
          {child.name}
        </a>
        <span className="text-text-muted">
          {child.class} · {child.relationship}
        </span>
        {link !== null ? <Tag tone={linkTone(link.state)}>{link.label}</Tag> : <span className="text-xs text-text-muted">{t.noLink}</span>}
        {link !== null &&
          acting === null &&
          (actionsFor[link.state] ?? []).map((a) => (
            <SmallButton key={a} tone={a === 'revoke' || a === 'suspend' ? 'red' : 'ink'} onClick={() => setActing(a)}>
              {t.actions[a] ?? a}
            </SmallButton>
          ))}
      </span>
      {acting !== null && (
        <span className="mt-2 grid max-w-[420px] gap-2">
          <Input label={`${t.actions[acting] ?? acting} · ${t.reason}`} hint={t.reasonHint} required value={reason} maxLength={255} onChange={(e) => setReason(e.target.value)} />
          <span className="flex flex-wrap gap-2">
            <Button busy={busy} disabled={reason.trim() === ''} onClick={() => void send()}>
              {t.confirm}
            </Button>
            <Button variant="secondary" disabled={busy} onClick={() => setActing(null)}>
              {t.cancel}
            </Button>
          </span>
        </span>
      )}
    </span>
  )
}
