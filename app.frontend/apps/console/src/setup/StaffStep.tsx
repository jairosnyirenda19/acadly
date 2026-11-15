import { Button, Notice } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { errorFor, setupApi } from './api'
import { Column, Columns, Input, SectionTitle, Select, StepFooter, nn } from './parts'
import type { StepProps } from './types'

const t = copy.setup.steps.staff

const tones = {
  signed_in: { tag: 'bg-teal text-sheet', row: '' },
  active: { tag: 'bg-teal text-sheet', row: '' },
  sent: { tag: 'bg-teal text-sheet', row: '' },
  ready: { tag: 'bg-field text-ink', row: '' },
} as const

export function StaffStep({ setup, onSetup, layout, next }: StepProps) {
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [role, setRole] = useState(setup.roles[0]?.key ?? '')
  const [place, setPlace] = useState(0)
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [failed, setFailed] = useState(false)
  const [working, setWorking] = useState(false)

  const add = async () => {
    const where = setup.places[place]

    if (where === undefined) return

    setWorking(true)
    setFailed(false)
    const result = await setupApi.inviteStaff({ name, email, role, place_type: where.type, place_id: where.id })
    setWorking(false)

    if (result.kind === 'saved') {
      setErrors({})
      setName('')
      setEmail('')
      onSetup(result.data)
    } else if (result.kind === 'invalid') {
      setErrors(result.errors)
    } else {
      setFailed(true)
    }
  }

  const sendAndContinue = async () => {
    if (setup.waiting === 0) {
      next()

      return
    }

    setWorking(true)
    const result = await setupApi.sendInvitations()
    setWorking(false)

    if (result.kind === 'saved') {
      onSetup(result.data)
      next()
    } else {
      setFailed(true)
    }
  }

  return layout({
    title: t.title,
    intro: t.intro,
    footer: (
      <StepFooter step="staff" primary={setup.waiting > 0 ? t.send(setup.waiting) : copy.setup.continue} onPrimary={() => void sendAndContinue()} working={working} />
    ),
    children: (
      <Columns>
        <Column wide sheet>
          <div className="mb-6 grid grid-cols-[repeat(auto-fit,minmax(240px,1fr))] gap-3">
            <div aria-current="true" className="min-h-[110px] border-2 border-ink bg-ink p-4 text-sheet">
              <span className="type-display block text-[26px] leading-[0.9]">{t.oneTitle}</span>
              <span className="mt-2 block text-sm text-on-ink-soft">{t.oneText}</span>
            </div>
            <div aria-disabled="true" className="min-h-[110px] border-2 border-dashed border-rule-dashed bg-paper p-4 text-text-muted">
              <span className="type-display block text-[26px] leading-[0.9]">{t.sheetTitle}</span>
              <span className="mt-2 block text-sm">{t.sheetText}</span>
            </div>
          </div>

          {failed && (
            <div className="mb-4">
              <Notice tone="wrong" title={copy.setup.fixTitle}>
                {copy.setup.loadFailed}
              </Notice>
            </div>
          )}

          <form
            onSubmit={(event) => {
              event.preventDefault()
              void add()
            }}
            className="mb-6 grid grid-cols-[repeat(auto-fit,minmax(180px,1fr))] items-end gap-x-4.5 gap-y-3.5 border-2 border-ink bg-paper p-4"
          >
            <Input label={t.nameLabel} value={name} onChange={(e) => setName(e.target.value)} error={errorFor(errors, 'name')} maxLength={120} />
            <Input label={t.emailLabel} type="email" value={email} onChange={(e) => setEmail(e.target.value)} error={errorFor(errors, 'email')} />
            <Select label={t.roleLabel} value={role} onChange={(e) => setRole(e.target.value)} error={errorFor(errors, 'role')}>
              {setup.roles.map((r) => (
                <option key={r.key} value={r.key}>
                  {r.name}
                </option>
              ))}
            </Select>
            <Select label={t.placeLabel} value={String(place)} onChange={(e) => setPlace(Number(e.target.value))} error={errorFor(errors, 'place_id')}>
              {setup.places.map((p, i) => (
                <option key={`${p.type}:${p.id ?? ''}`} value={i}>
                  {p.name}
                </option>
              ))}
            </Select>
            <Button type="submit" variant="secondary" disabled={working}>
              {t.add}
            </Button>
          </form>

          <div className="overflow-x-auto">
            <div className="min-w-[560px]">
              <div className="label-caps grid grid-cols-[44px_minmax(150px,1.4fr)_minmax(110px,1fr)_minmax(110px,1fr)_124px] gap-3 border-b-2 border-ink pr-4 pb-2 text-text-muted">
                <span />
                <span>{t.person}</span>
                <span>{t.roleLabel}</span>
                <span>{t.placeLabel}</span>
                <span className="text-right">{t.invitation}</span>
              </div>
              <div className="ledger [--margin:44px]">
                {setup.staff.map((person, i) => (
                  <div key={`${person.id}:${person.role}:${person.works_in}`} className={`grid grid-cols-[44px_minmax(150px,1.4fr)_minmax(110px,1fr)_minmax(110px,1fr)_124px] items-center gap-3 border-b border-rule py-3 pr-4 ${tones[person.state].row}`}>
                    <div className="type-display text-center text-xl text-text-muted">{nn(i + 1)}</div>
                    <div className="min-w-0">
                      <div className="text-base font-extrabold">{person.name}</div>
                      <div className="ref text-xs break-all text-text-muted">{person.email}</div>
                    </div>
                    <div className="text-[15px] font-bold">{person.role}</div>
                    <div className="text-sm text-text-muted">{person.works_in}</div>
                    <div className="text-right">
                      <span className={`label-caps px-2 py-1 ${tones[person.state].tag}`}>{t.states[person.state]}</span>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          </div>
        </Column>

        <Column edge>
          <SectionTitle>{t.rolesSection}</SectionTitle>
          <p className="-mt-1.5 mb-3.5 text-sm text-text-muted">{t.rolesNote}</p>
          <div className="border-t-2 border-ink">
            {setup.roles.map((r) => (
              <div key={r.key} className="flex items-baseline gap-3 border-b border-rule py-2.5">
                <span className="flex-1 text-[15px] font-extrabold">{r.name}</span>
                <span className="text-[13px] text-text-muted">{r.scope}</span>
              </div>
            ))}
          </div>
          <div className="my-6 h-0.5 bg-ink" />
          <div className="label-caps mb-2 text-text-muted">{t.receivesSection}</div>
          <p className="text-sm leading-normal">{t.receives}</p>
        </Column>
      </Columns>
    ),
  })
}
