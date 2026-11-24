import { navigate, useLocation } from '@acadly/kernel'
import { Button, Notice } from '@acadly/ui'
import { useCallback, useEffect, useState, type ReactNode } from 'react'
import { copy } from '../copy'
import { setupApi, type AcademicsState, type InstitutionState, type SetupState } from './api'
import { ClassesStep } from './ClassesStep'
import { LevelsStep } from './LevelsStep'
import { STEPS, SetupLayout, stepPath, type StepKey } from './parts'
import { ReviewStep } from './ReviewStep'
import { SchoolStep } from './SchoolStep'
import { StaffStep } from './StaffStep'
import type { StepProps } from './types'
import { YearStep } from './YearStep'

const t = copy.setup

interface Loaded {
  institution: InstitutionState
  academics: AcademicsState
  setup: SetupState
}

const SCREENS: Record<StepKey, (props: StepProps) => ReactNode> = {
  school: SchoolStep,
  levels: LevelsStep,
  year: YearStep,
  classes: ClassesStep,
  staff: StaffStep,
  review: ReviewStep,
}

/**
 * School setup for the first administrator (the approved setup screens):
 * six steps, each saved on its own. Opening the school ends it.
 */
export function SetupWizard({ onOpened, onSignedOut }: { onOpened: () => void; onSignedOut: () => void }) {
  const { path } = useLocation()
  const [loaded, setLoaded] = useState<Loaded | 'refused' | null | undefined>(undefined)

  const load = useCallback(async () => {
    const [institution, academics, setup] = await Promise.all([setupApi.institution(), setupApi.academics(), setupApi.setup()])

    if (institution === 'refused' || academics === 'refused' || setup === 'refused') return 'refused' as const
    if (institution === null || academics === null || setup === null || institution === 'missing' || academics === 'missing' || setup === 'missing') return null

    return { institution, academics, setup }
  }, [])

  useEffect(() => {
    let current = true
    void load().then((value) => {
      if (current) setLoaded(value)
    })

    return () => {
      current = false
    }
  }, [load])

  const requested = path.startsWith('/setup/') ? (path.slice('/setup/'.length) as StepKey) : null
  const step: StepKey = requested !== null && STEPS.includes(requested) ? requested : 'school'

  useEffect(() => {
    if (requested === null || !STEPS.includes(requested)) navigate(stepPath('school'), true)
  }, [requested])

  if (loaded === undefined) return <div className="min-h-screen bg-paper" />

  if (loaded === null || loaded === 'refused') {
    return (
      <div className="flex min-h-screen items-center justify-center bg-paper p-6">
        <div className="flex w-full max-w-[420px] flex-col gap-5">
          <Notice tone="wrong" title={loaded === 'refused' ? t.refusedTitle : t.loadFailedTitle}>
            {loaded === 'refused' ? t.refused : t.loadFailed}
          </Notice>
          {loaded === null && (
            <Button variant="secondary" onClick={() => window.location.reload()}>
              {t.retry}
            </Button>
          )}
        </div>
      </div>
    )
  }

  const update = (patch: Partial<Loaded>) => setLoaded({ ...loaded, ...patch })
  const done = Object.fromEntries(loaded.setup.steps.map((s) => [s.key, s.done])) as Partial<Record<StepKey, boolean>>
  const Screen = SCREENS[step]

  const next = () => {
    void load().then((value) => {
      if (value !== null && value !== 'refused') {
        setLoaded(value)

        if (value.setup.open) {
          onOpened()

          return
        }
      }

      const following = STEPS[STEPS.indexOf(step) + 1]

      if (following !== undefined) navigate(stepPath(following))
    })
  }

  return (
    <Screen
      key={step}
      institution={loaded.institution}
      onInstitution={(institution) => update({ institution })}
      academics={loaded.academics}
      onAcademics={(academics) => update({ academics })}
      setup={loaded.setup}
      onSetup={(setup) => update({ setup })}
      next={next}
      layout={(page) => (
        <SetupLayout step={step} done={done} title={page.title} intro={page.intro} footer={page.footer} onSignedOut={onSignedOut}>
          {page.children}
        </SetupLayout>
      )}
    />
  )
}
