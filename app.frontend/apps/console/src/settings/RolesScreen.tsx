import { Button, Notice } from '@acadly/ui'
import { Fragment, useEffect, useState } from 'react'
import { copy } from '../copy'
import { errorFor } from '../setup/api'
import { Input, Select } from '../setup/parts'
import { settingsApi, type RolesState } from './api'
import { PageHead, SettingsTabs, Tag } from './parts'

const t = copy.settings.roles
const s = copy.settings

type Role = RolesState['roles'][number]
/** role id => permission keys flipped from what is saved */
type Flips = Record<string, Set<string>>

const has = (role: Role, flips: Flips, key: string) => (role.system ? true : role.permissions.includes(key) !== (flips[role.id]?.has(key) ?? false))

export function RolesScreen() {
  const [state, setState] = useState<RolesState | 'refused' | null | undefined>(undefined)
  const [flips, setFlips] = useState<Flips>({})
  const [creating, setCreating] = useState<'new' | 'copy' | null>(null)
  const [message, setMessage] = useState<{ tone: 'done' | 'wrong'; text: string } | null>(null)
  const [working, setWorking] = useState(false)

  useEffect(() => {
    void settingsApi.roles().then(setState)
  }, [])

  if (state === undefined) return <SettingsTabs current="roles" />

  if (state === null || state === 'refused') {
    return (
      <>
        <SettingsTabs current="roles" />
        <div className="p-[clamp(16px,3vw,40px)]">
          <Notice tone="wrong" title={state === 'refused' ? s.refused : s.loadFailed} />
        </div>
      </>
    )
  }

  const total = state.groups.reduce((n, g) => n + g.permissions.length, 0)
  const changed = state.roles.filter((r) => (flips[r.id]?.size ?? 0) > 0)
  const changeCount = changed.reduce((n, r) => n + (flips[r.id]?.size ?? 0), 0)
  const affected = changed.reduce((n, r) => n + r.people, 0)

  const toggle = (role: Role, key: string) => {
    setFlips((current) => {
      const next = new Set(current[role.id] ?? [])
      if (next.has(key)) next.delete(key)
      else next.add(key)

      return { ...current, [role.id]: next }
    })
  }

  const save = async () => {
    setWorking(true)
    setMessage(null)
    let latest: RolesState = state

    for (const role of changed) {
      const permissions = state.groups.flatMap((g) => g.permissions.map((p) => p.key)).filter((key) => has(role, flips, key))
      const kept = role.permissions.filter((key) => !state.groups.some((g) => g.permissions.some((p) => p.key === key)))
      const result = await settingsApi.saveRole(role.id, { name: role.name, description: role.description, permissions: [...permissions, ...kept] })

      if (result.kind !== 'saved') {
        setWorking(false)
        setMessage({ tone: 'wrong', text: result.kind === 'refused' ? s.refused : result.kind === 'invalid' ? (Object.values(result.errors)[0]?.[0] ?? s.failed) : s.failed })

        return
      }

      latest = result.data
    }

    setWorking(false)
    setState(latest)
    setFlips({})
    setMessage({ tone: 'done', text: t.saved })
  }

  const cols = `minmax(300px,1.6fr) repeat(${state.roles.length}, minmax(112px,1fr))`

  return (
    <>
      <SettingsTabs current="roles" />
      <PageHead
        kicker={`${s.kicker} · ${t.title}`}
        title={t.title}
        intro={t.intro}
        actions={
          <>
            <Button variant="secondary" onClick={() => setCreating(creating === 'copy' ? null : 'copy')}>
              {t.copyRole}
            </Button>
            <Button onClick={() => setCreating(creating === 'new' ? null : 'new')}>{t.create}</Button>
          </>
        }
      />

      {creating !== null && (
        <CreateRole
          key={creating}
          mode={creating}
          state={state}
          onDone={(next) => {
            setState(next)
            setCreating(null)
          }}
          onCancel={() => setCreating(null)}
        />
      )}

      {changeCount > 0 && (
        <div className="flex flex-wrap items-center gap-3 border-t-2 border-ink bg-marigold px-[clamp(16px,3vw,40px)] py-3">
          <span className="label-caps">{t.notSaved}</span>
          <span className="flex-[1_1_320px] text-sm font-semibold">{t.changes(changeCount, changed.map((r) => r.name).join(', '), affected)}</span>
          <button type="button" onClick={() => setFlips({})} className="h-11 border-2 border-ink bg-sheet px-[18px] text-[15px] font-extrabold">
            {t.discard}
          </button>
          <button type="button" disabled={working} onClick={() => void save()} className="h-11 bg-ink px-[18px] text-[15px] font-extrabold text-sheet disabled:bg-disabled disabled:text-text-muted">
            {working ? s.saving : t.save}
          </button>
        </div>
      )}
      {message !== null && (
        <div className="px-[clamp(16px,3vw,40px)] py-3">
          <Notice tone={message.tone} title={message.text} />
        </div>
      )}

      <div className="flex-1 overflow-x-auto border-t-2 border-ink bg-sheet">
        <div style={{ minWidth: `${300 + state.roles.length * 120}px` }}>
          <div className="grid border-b-2 border-ink" style={{ gridTemplateColumns: cols }}>
            <div className="label-caps flex items-end px-[clamp(16px,3vw,40px)] py-3.5 text-text-muted">{t.permission}</div>
            {state.roles.map((role) => {
              const held = role.system ? total : state.groups.reduce((n, g) => n + g.permissions.filter((p) => has(role, flips, p.key)).length, 0)
              const dirty = (flips[role.id]?.size ?? 0) > 0

              return (
                <div key={role.id} className={`flex flex-col gap-1.5 border-l border-rule px-3 pt-3.5 pb-3 ${dirty ? 'bg-marigold-wash' : role.system ? 'bg-paper' : ''}`}>
                  <div className="type-display min-h-10 text-xl leading-none">{role.name}</div>
                  <div className="flex justify-between text-xs text-text-muted">
                    <span>{t.people(role.people)}</span>
                    <span className="ref">{t.held(held, total)}</span>
                  </div>
                  <div role="img" aria-label={t.share(held, total)} className="h-1.5 bg-field">
                    <div className={`h-1.5 ${role.system ? 'bg-rule-dashed' : 'bg-teal'}`} style={{ width: `${total === 0 ? 0 : Math.round((held / total) * 100)}%` }} />
                  </div>
                  <div className={`label-caps ${dirty ? 'text-ink' : 'text-text-muted'}`}>{role.system ? t.system : dirty ? t.changed : t.editable}</div>
                </div>
              )
            })}
          </div>

          {state.groups.map((group) => (
            <Fragment key={group.name}>
              <div className="label-caps border-b border-rule bg-field px-[clamp(16px,3vw,40px)] py-2">{group.name}</div>
              {group.permissions.map((p) => (
                <div key={p.key} className={`grid border-b border-rule ${p.sight ? 'bg-marigold-wash' : ''}`} style={{ gridTemplateColumns: cols }}>
                  <div className="px-[clamp(16px,3vw,40px)] py-3">
                    <div className="text-[15px] font-extrabold">
                      {p.label} {p.sight && <Tag tone="attention">{copy.settings.person.sight}</Tag>}
                    </div>
                    <div className="text-[13px] leading-snug">{p.help}</div>
                  </div>
                  {state.roles.map((role) => {
                    const on = has(role, flips, p.key)
                    const dirty = flips[role.id]?.has(p.key) ?? false
                    const locked = role.system || (!on && !p.yours)
                    const label = `${role.name}: ${p.label}, ${role.system ? t.locked : on ? t.granted : t.notGranted}${!role.system && !p.yours && !on ? `, ${t.notYours}` : ''}`

                    return (
                      <div key={role.id} className={`flex items-center justify-center border-l border-rule ${dirty ? 'bg-marigold-wash' : role.system ? 'bg-paper' : ''}`}>
                        <button
                          type="button"
                          aria-pressed={on}
                          aria-label={label}
                          title={label}
                          disabled={locked}
                          onClick={() => toggle(role, p.key)}
                          className={`size-11 text-[26px] leading-none font-extrabold ${
                            role.system
                              ? 'cursor-not-allowed border-2 border-rule-dashed bg-rule-dashed text-sheet'
                              : on
                                ? `bg-teal text-sheet ${dirty ? 'border-[3px] border-marigold' : 'border-2 border-teal'}`
                                : `bg-sheet text-ink ${dirty ? 'border-[3px] border-marigold' : 'border-2 border-rule-dashed'} disabled:cursor-not-allowed disabled:border-dashed disabled:bg-paper`
                          }`}
                        >
                          {on ? '/' : dirty ? '*' : ''}
                        </button>
                      </div>
                    )
                  })}
                </div>
              ))}
            </Fragment>
          ))}
        </div>
      </div>

      <div className="flex flex-wrap gap-5 border-t-2 border-ink px-[clamp(16px,3vw,40px)] py-3.5 text-[13px] text-text-muted">
        <span>
          <strong className="text-teal">/</strong> {t.legendGranted} <strong className="text-ink">*</strong> {t.legendChanged}
        </span>
        <span>
          <Tag tone="attention">{copy.settings.person.sight}</Tag> {t.legendSight}
        </span>
        <span>
          <Tag tone="locked">{t.system}</Tag> {t.legendSystem}
        </span>
        <span>{t.legendWhere}</span>
        <span>{t.legendAudit}</span>
      </div>
    </>
  )
}

function CreateRole({ mode, state, onDone, onCancel }: { mode: 'new' | 'copy'; state: RolesState; onDone: (next: RolesState) => void; onCancel: () => void }) {
  const [name, setName] = useState('')
  const [from, setFrom] = useState('')
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [failed, setFailed] = useState<string | null>(null)
  const [working, setWorking] = useState(false)
  const yours = new Set(state.groups.flatMap((g) => g.permissions.filter((p) => p.yours).map((p) => p.key)))

  const submit = async () => {
    const source = state.roles.find((r) => r.id === from)
    const permissions = source === undefined ? [] : (source.system ? [...yours] : source.permissions.filter((k) => yours.has(k)))
    setWorking(true)
    setFailed(null)
    const result = await settingsApi.createRole({ name, permissions })
    setWorking(false)

    if (result.kind === 'saved') onDone(result.data)
    else if (result.kind === 'invalid') setErrors(result.errors)
    else setFailed(result.kind === 'refused' ? s.refused : s.failed)
  }

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault()
        void submit()
      }}
      className="mx-[clamp(16px,3vw,40px)] mb-5 border-2 border-ink bg-sheet p-4.5"
    >
      <h2 className="type-display mb-3.5 text-[22px] leading-none">{mode === 'copy' ? t.copyTitle : t.newTitle}</h2>
      {failed !== null && (
        <div className="mb-3">
          <Notice tone="wrong" title={failed} />
        </div>
      )}
      <div className="grid grid-cols-[repeat(auto-fit,minmax(220px,1fr))] gap-x-5 gap-y-4">
        <Input label={t.name} required value={name} onChange={(e) => setName(e.target.value)} error={errorFor(errors, 'name')} maxLength={60} />
        {mode === 'copy' && (
          <Select label={t.copyFrom} value={from} onChange={(e) => setFrom(e.target.value)} hint={t.copyNote}>
            <option value="">{t.startEmpty}</option>
            {state.roles.map((r) => (
              <option key={r.id} value={r.id}>
                {r.name}
              </option>
            ))}
          </Select>
        )}
      </div>
      <div className="mt-4 flex flex-wrap gap-2.5">
        <Button type="submit" disabled={working}>
          {working ? s.saving : t.createSubmit}
        </Button>
        <Button variant="secondary" onClick={onCancel}>
          {s.cancel}
        </Button>
      </div>
    </form>
  )
}
