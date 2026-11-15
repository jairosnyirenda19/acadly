import { navigate } from '@acadly/kernel'
import { Notice } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { setupApi } from './api'
import { Column, Columns, SectionTitle, StepFooter, Total, TotalsBand, nn, stepPath, type StepKey } from './parts'
import type { StepProps } from './types'

const t = copy.setup.steps.review
const REQUIRED: StepKey[] = ['school', 'levels', 'year', 'classes']

export function ReviewStep({ institution, academics, setup, onSetup, layout, next }: StepProps) {
  const [working, setWorking] = useState(false)
  const [refused, setRefused] = useState<string[]>([])

  const done = Object.fromEntries(setup.steps.map((s) => [s.key, s.done])) as Record<string, boolean>
  const complete = REQUIRED.filter((key) => done[key] === true).length
  const blockers = setup.blockers as (keyof typeof t.blockers)[]

  const open = async () => {
    setWorking(true)
    const result = await setupApi.open()
    setWorking(false)

    if (result.kind === 'saved') {
      onSetup(result.data)
      next()
    } else if (result.kind === 'invalid') {
      setRefused(result.errors.setup ?? [])
    } else {
      setRefused([copy.setup.loadFailed])
    }
  }

  const rows: { key: StepKey | 'administrator'; title: string; done: boolean; optional?: boolean }[] = [
    ...REQUIRED.map((key) => ({ key, title: t.items[key as keyof typeof t.items], done: done[key] === true })),
    { key: 'administrator', title: t.items.administrator, done: setup.administrators > 0 },
    { key: 'staff', title: t.items.staff, done: setup.waiting === 0, optional: true },
  ]

  return layout({
    title: t.title,
    intro: t.intro,
    footer: (
      <StepFooter
        step="review"
        primary={t.open}
        onPrimary={() => void open()}
        working={working}
        blocked={setup.open ? t.opened : blockers.length > 0 ? t.blocked : undefined}
      />
    ),
    children: (
      <>
        <TotalsBand>
          <Total tone="ink" label={t.bandReady} value={String(complete + (setup.administrators > 0 ? 1 : 0))} suffix={` / ${REQUIRED.length + 1}`} note={t.bandReadyNote(complete + (setup.administrators > 0 ? 1 : 0), REQUIRED.length + 1)} />
          <Total label={t.bandLevels} value={String(institution.divisions.length)} note={institution.divisions.map((d) => d.name).join(', ')} />
          <Total label={t.bandCampuses} value={String(institution.campuses.length)} note={institution.campuses.map((c) => c.name).join(', ')} />
          <Total label={t.bandClasses} value={String(academics.classes)} note={copy.setup.steps.classes.bandAllNote(academics.offerings.length)} />
          {setup.waiting > 0 && <Total tone="attention" label={t.bandWaiting} value={String(setup.waiting)} note={t.bandWaitingNote} />}
        </TotalsBand>
        <Columns>
          <Column wide sheet>
            {setup.open && (
              <div className="mb-5">
                <Notice tone="done" title={t.openedTitle}>
                  {t.opened}
                </Notice>
              </div>
            )}
            {refused.length > 0 && (
              <div className="mb-5">
                <Notice tone="wrong" title={copy.setup.fixTitle}>
                  {refused.join(' ')}
                </Notice>
              </div>
            )}
            <SectionTitle>{t.checklist}</SectionTitle>
            <div className="ledger border-t-2 border-ink [--margin:48px]">
              {rows.map((row, i) => {
                const attention = !row.done
                const state = row.done ? t.complete : row.optional === true ? t.optional : t.incomplete

                return (
                  <div key={row.key} className={`flex flex-wrap items-center gap-3.5 border-b border-rule py-3.5 pr-4 ${attention ? 'bg-marigold-wash' : ''}`}>
                    <div className="type-display w-12 text-center text-xl text-text-muted">{nn(i + 1)}</div>
                    {row.done ? (
                      <span aria-hidden="true" className="grid size-7 shrink-0 place-items-center bg-teal text-lg font-extrabold text-sheet">
                        /
                      </span>
                    ) : (
                      <span aria-hidden="true" className="grid size-7 shrink-0 place-items-center border-2 border-ink bg-marigold text-base font-extrabold">
                        !
                      </span>
                    )}
                    <div className="min-w-0 flex-[1_1_260px]">
                      <div className={`label-caps ${row.done ? 'text-teal' : ''}`}>
                        <span className={row.done ? '' : 'bg-marigold px-1.5 py-0.5'}>{state}</span>
                      </div>
                      <div className="text-base font-extrabold">{row.title}</div>
                      {!row.done && row.key !== 'staff' && <div className="text-sm text-text-muted">{t.blockers[row.key as keyof typeof t.blockers]}</div>}
                    </div>
                    {row.key !== 'administrator' && (
                      <a
                        href={stepPath(row.key)}
                        onClick={(event) => {
                          event.preventDefault()
                          navigate(stepPath(row.key as StepKey))
                        }}
                        className="text-sm font-bold"
                      >
                        {t.edit}
                      </a>
                    )}
                  </div>
                )
              })}
            </div>
          </Column>

          <Column edge>
            <SectionTitle>{t.whatSection}</SectionTitle>
            <div className="border-t-2 border-ink">
              {t.what.map(([strong, rest]) => (
                <div key={strong} className="border-b border-rule py-3 text-sm leading-normal">
                  <strong>{strong}</strong> {rest}
                </div>
              ))}
            </div>
            <p className="mt-3.5 text-[13px] text-text-muted">{t.whatNote}</p>
          </Column>
        </Columns>
      </>
    ),
  })
}
