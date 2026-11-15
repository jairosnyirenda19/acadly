import { Button, Notice } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { errorFor, setupApi, type InstitutionState } from './api'
import { Attention, Column, Columns, Input, SectionTitle, StepFooter, TextArea, nn } from './parts'
import type { StepProps } from './types'

const t = copy.setup.steps.levels

type Campus = InstitutionState['campuses'][number]

function CampusForm({ campus, onDone }: { campus: Campus | null; onDone: (state: InstitutionState | null) => void }) {
  const [name, setName] = useState(campus?.name ?? '')
  const [place, setPlace] = useState(campus?.place ?? '')
  const [address, setAddress] = useState(campus?.address ?? '')
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [working, setWorking] = useState(false)

  const save = async () => {
    setWorking(true)
    const body = { name, place, address: address || null }
    const result = campus === null ? await setupApi.addCampus(body) : await setupApi.updateCampus(campus.id, body)
    setWorking(false)

    if (result.kind === 'saved') onDone(result.data)
    else if (result.kind === 'invalid') setErrors(result.errors)
  }

  return (
    <div className="mt-4 grid gap-3.5 border-2 border-ink bg-paper p-4">
      <Input label={t.campusName} required value={name} onChange={(e) => setName(e.target.value)} error={errorFor(errors, 'name')} maxLength={80} />
      <Input label={t.campusPlace} required value={place} onChange={(e) => setPlace(e.target.value)} error={errorFor(errors, 'place')} maxLength={120} />
      <TextArea label={t.campusAddress} value={address} onChange={(e) => setAddress(e.target.value)} error={errorFor(errors, 'address')} />
      <div className="flex flex-wrap gap-2">
        <Button disabled={working} onClick={() => void save()}>
          {working ? copy.setup.saving : t.saveCampus}
        </Button>
        <Button variant="secondary" onClick={() => onDone(null)}>
          {t.cancel}
        </Button>
      </div>
    </div>
  )
}

export function LevelsStep({ institution, onInstitution, layout, next }: StepProps) {
  const [editing, setEditing] = useState<Campus | 'new' | null>(null)
  const [problem, setProblem] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const chosen = new Set(institution.divisions.map((d) => d.profile))
  const divisionOf = (profile: string) => institution.divisions.find((d) => d.profile === profile)
  const taught = (divisionId: string, campusId: string) => institution.offerings.some((o) => o.division_id === divisionId && o.campus_id === campusId)
  const missing = institution.divisions.filter((d) => !institution.offerings.some((o) => o.division_id === d.id))

  const toggleLevel = async (profile: string) => {
    const keep = institution.profiles.filter((p) => (p.key === profile ? !chosen.has(p.key) : chosen.has(p.key)))

    if (keep.length === 0) {
      setProblem(t.chooseOne)

      return
    }

    setBusy(true)
    const result = await setupApi.saveLevels(keep.map((p) => ({ profile: p.key, name: divisionOf(p.key)?.name ?? null })))
    setBusy(false)

    if (result.kind === 'saved') {
      setProblem(null)
      onInstitution(result.data)
    } else {
      setProblem(result.kind === 'invalid' ? (Object.values(result.errors)[0]?.[0] ?? copy.setup.fix) : copy.setup.loadFailed)
    }
  }

  const toggleCell = async (divisionId: string, campusId: string) => {
    setBusy(true)
    const result = await setupApi.setOffering(divisionId, campusId, !taught(divisionId, campusId))
    setBusy(false)

    if (result.kind === 'saved') onInstitution(result.data)
    else setProblem(copy.setup.loadFailed)
  }

  const blocked = institution.divisions.length === 0 ? t.chooseOne : missing.length > 0 ? t.gap(missing.map((d) => d.name).join(' and ')) : undefined

  return layout({
    title: t.title,
    intro: t.intro,
    footer: <StepFooter step="levels" primary={copy.setup.continue} onPrimary={next} working={busy} blocked={blocked} />,
    children: (
      <Columns>
        <section className="flex-[1_1_100%] border-b-2 border-ink bg-sheet px-[clamp(16px,3vw,40px)] pt-6 pb-7">
          <SectionTitle>{t.levelsSection}</SectionTitle>
          <p className="-mt-1.5 mb-4 max-w-[70ch] text-sm text-text-muted">{t.levelsNote}</p>
          {problem !== null && (
            <div className="mb-4">
              <Notice tone="wrong" title={copy.setup.fixTitle}>
                {problem}
              </Notice>
            </div>
          )}
          <div className="grid grid-cols-[repeat(auto-fit,minmax(230px,1fr))] gap-3">
            {institution.profiles.map((profile) => {
              const on = chosen.has(profile.key)

              return (
                <button
                  key={profile.key}
                  type="button"
                  aria-pressed={on}
                  disabled={busy}
                  onClick={() => void toggleLevel(profile.key)}
                  className={`flex min-h-[172px] flex-col gap-2 border-2 p-4 text-left ${on ? 'border-ink bg-ink text-sheet' : 'border-rule-dashed bg-sheet text-ink hover:bg-field'}`}
                >
                  <span className="flex w-full items-center gap-2">
                    <span className="type-display flex-1 text-[30px] leading-[0.9]">{profile.title}</span>
                    <span className={`label-caps px-1.5 py-[3px] text-ink ${on ? 'bg-teal-bright' : 'bg-field'}`}>{on ? t.runs : t.notRun}</span>
                  </span>
                  <span className={`text-sm leading-[1.45] ${on ? 'text-on-ink-soft' : 'text-text-muted'}`}>{profile.summary}</span>
                  <span className={`ref mt-auto text-xs ${on ? 'text-on-ink-soft' : 'text-text-muted'}`}>{profile.words}</span>
                </button>
              )
            })}
            <div aria-disabled="true" className="flex min-h-[172px] flex-col gap-2 border-2 border-dashed border-rule-dashed bg-paper p-4 text-text-muted">
              <span className="flex w-full items-center gap-2">
                <span className="type-display flex-1 text-[30px] leading-[0.9]">{t.collegeTitle}</span>
                <span className="label-caps bg-disabled px-1.5 py-[3px] text-ink">{t.later}</span>
              </span>
              <span className="text-sm leading-[1.45]">{t.collegeText}</span>
              <span className="ref mt-auto text-xs">{t.collegeWords}</span>
            </div>
          </div>
        </section>

        <Column wide>
          <SectionTitle aside={<span className="text-[13px] text-text-muted">{t.matrixNote}</span>}>{t.matrixSection}</SectionTitle>
          <div className="overflow-x-auto border-2 border-ink bg-sheet">
            <table className="w-full min-w-[520px] border-collapse">
              <thead>
                <tr className="border-b-2 border-ink bg-paper">
                  <th className="label-caps px-4 py-2.5 text-left text-text-muted">{t.campus}</th>
                  {institution.divisions.map((d) => (
                    <th key={d.id} className="label-caps w-[120px] border-l border-rule px-2 py-2.5 text-center text-text-muted">
                      {d.name}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {institution.campuses.map((campus) => (
                  <tr key={campus.id} className="h-16 border-b border-rule">
                    <th scope="row" className="px-4 py-2.5 text-left font-normal">
                      <span className="block text-base font-extrabold">{campus.name}</span>
                      <span className="text-[13px] text-text-muted">{campus.place}</span>
                    </th>
                    {institution.divisions.map((d) => {
                      const on = taught(d.id, campus.id)

                      return (
                        <td key={d.id} className="border-l border-rule text-center">
                          <button
                            type="button"
                            aria-pressed={on}
                            aria-label={t.cellLabel(d.name, campus.name, on)}
                            disabled={busy}
                            onClick={() => void toggleCell(d.id, campus.id)}
                            className={`size-11 text-[26px] leading-none font-extrabold text-sheet ${on ? 'bg-teal' : 'border-2 border-dashed border-rule-dashed bg-sheet'}`}
                          >
                            {on ? '/' : ''}
                          </button>
                        </td>
                      )
                    })}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="mt-3 flex flex-wrap gap-4 text-[13px] text-text-muted">
            <span className="inline-flex items-center gap-1.5">
              <span aria-hidden="true" className="size-4 bg-teal" />
              {t.taught}
            </span>
            <span className="inline-flex items-center gap-1.5">
              <span aria-hidden="true" className="size-4 border-2 border-dashed border-rule-dashed bg-sheet" />
              {t.notTaught}
            </span>
          </div>
          {missing.length > 0 && (
            <div className="mt-4">
              <Attention title={t.gapTitle}>{t.gap(missing.map((d) => d.name).join(' and '))}</Attention>
            </div>
          )}
        </Column>

        <Column edge sheet>
          <SectionTitle>{t.campusesSection}</SectionTitle>
          <div className="ledger border-t-2 border-ink [--margin:40px]">
            {institution.campuses.map((campus, i) => (
              <div key={campus.id} className="flex items-center gap-3 border-b border-rule py-3.5">
                <div className="type-display w-10 text-center text-xl text-text-muted">{nn(i + 1)}</div>
                <div className="min-w-0 flex-1 pl-1.5">
                  <div className="text-base font-extrabold">{campus.name}</div>
                  <div className="text-[13px] text-text-muted">{campus.place}</div>
                </div>
                <button type="button" onClick={() => setEditing(campus)} className="text-sm font-bold text-teal hover:text-ink">
                  {t.editCampus}
                </button>
              </div>
            ))}
          </div>
          {editing === null ? (
            <Button variant="secondary" className="mt-4" onClick={() => setEditing('new')}>
              {t.addCampus}
            </Button>
          ) : (
            <CampusForm
              key={editing === 'new' ? 'new' : editing.id}
              campus={editing === 'new' ? null : editing}
              onDone={(state) => {
                if (state !== null) onInstitution(state)
                setEditing(null)
              }}
            />
          )}
          <p className="mt-3.5 text-[13px] text-text-muted">{t.oneCampusNote}</p>
        </Column>
      </Columns>
    ),
  })
}
