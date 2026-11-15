import { navigate, signOut, useSchool } from '@acadly/kernel'
import { Button } from '@acadly/ui'
import { useId, type ReactNode, type SelectHTMLAttributes, type TextareaHTMLAttributes, type InputHTMLAttributes } from 'react'
import { copy } from '../copy'

const t = copy.setup

export const STEPS = ['school', 'levels', 'year', 'classes', 'staff', 'review'] as const
export type StepKey = (typeof STEPS)[number]

/** A step's path inside the console. */
export const stepPath = (step: StepKey) => `/setup/${step}`

function initials(name: string): string {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .join('')
}

/** The frame of every setup step (the approved setup screens). */
export function SetupLayout({
  step,
  done,
  title,
  intro,
  children,
  footer,
  onSignedOut,
}: {
  step: StepKey
  done: Partial<Record<StepKey, boolean>>
  title: string
  intro: string
  children: ReactNode
  footer: ReactNode
  onSignedOut: () => void
}) {
  const { user } = useSchool()
  const index = STEPS.indexOf(step)

  return (
    <div className="flex min-h-screen flex-wrap items-stretch bg-paper">
      <aside className="flex max-w-full flex-[1_1_260px] flex-col bg-ink text-sheet">
        <div className="type-display px-5 pt-5 pb-4 text-[34px] leading-[0.9]">
          {t.product} <span className="text-teal-bright">{t.productAccent}</span>
        </div>
        <div className="border-y border-ink-rule px-5 py-3">
          <div className="label-caps text-on-ink-muted">{t.railLabel}</div>
          <div className="text-[15px] font-extrabold">{t.stepOf(index + 1, STEPS.length)}</div>
          <div className="text-[13px] text-on-ink-soft">{t.railNote}</div>
        </div>
        <nav aria-label={t.railLabel} className="flex flex-col py-3">
          {STEPS.map((key, i) => {
            const current = key === step
            const finished = done[key] === true && !current
            const state = current ? t.stepState.current : finished ? t.stepState.done : t.stepState.todo

            return (
              <a
                key={key}
                href={stepPath(key)}
                aria-current={current ? 'step' : undefined}
                onClick={(event) => {
                  event.preventDefault()
                  navigate(stepPath(key))
                }}
                className={`flex items-center gap-3 border-l-4 py-2.5 pr-5 pl-4 text-[15px] no-underline ${current ? 'border-teal-bright font-extrabold text-sheet' : finished ? 'border-transparent text-on-ink-soft' : 'border-transparent text-on-ink-muted'}`}
              >
                {finished ? (
                  <span aria-hidden="true" className="grid size-6 shrink-0 place-items-center bg-teal text-base font-extrabold text-sheet">
                    /
                  </span>
                ) : current ? (
                  <span aria-hidden="true" className="size-6 shrink-0 border-[3px] border-sheet bg-teal-bright" />
                ) : (
                  <span aria-hidden="true" className="size-6 shrink-0 border-2 border-dashed border-on-ink-muted" />
                )}
                <span className="min-w-0 flex-1">
                  <span className="ref block text-[11px] text-on-ink-muted">{String(i + 1).padStart(2, '0')}</span>
                  {t.steps[key].name}
                  <span className="sr-only"> · {state}</span>
                </span>
              </a>
            )
          })}
        </nav>
        <div className="mt-auto border-t border-ink-rule px-5 py-3.5 text-[13px]">
          <button
            type="button"
            onClick={() => {
              void signOut().then(onSignedOut)
            }}
            className="font-bold text-sheet"
          >
            {t.signOut}
          </button>
        </div>
      </aside>

      <main className="flex min-w-0 flex-[999_1_560px] flex-col">
        <header className="flex flex-wrap items-center gap-3 border-b-2 border-ink bg-paper px-[clamp(16px,3vw,40px)] py-2.5">
          <div className="flex h-11 flex-[1_1_280px] items-center gap-2.5 text-sm text-text-muted">
            <span className="label-caps bg-teal px-2 py-1 text-sheet">{t.saved}</span>
            {t.savedNote}
          </div>
          <div className="ml-auto flex items-center gap-2">
            <span className="w-[176px] text-right leading-tight">
              <span className="block truncate text-sm font-extrabold">{user.name}</span>
              <span className="block truncate text-xs text-text-muted">{t.roleLine}</span>
            </span>
            <span aria-hidden="true" className="type-display grid size-11 place-items-center bg-ink text-[22px] text-sheet">
              {initials(user.name)}
            </span>
          </div>
        </header>

        <div className="px-[clamp(16px,3vw,40px)] pt-7 pb-5.5">
          <div className="label-caps text-text-muted">{t.stepOf(index + 1, STEPS.length)}</div>
          <h1 className="type-display mt-1 mb-2.5 text-[clamp(56px,7vw,96px)]">{title}</h1>
          <p className="max-w-[70ch] text-[15px] leading-normal text-text-muted">{intro}</p>
        </div>

        {children}

        <footer className="mt-auto flex flex-wrap items-center gap-3 border-t-2 border-ink bg-sheet px-[clamp(16px,3vw,40px)] py-4">{footer}</footer>
      </main>
    </div>
  )
}

/** Back, the note and the main action. A blocked action says why beside it. */
export function StepFooter({
  step,
  primary,
  onPrimary,
  working,
  blocked,
}: {
  step: StepKey
  primary: string
  onPrimary: () => void
  working: boolean
  blocked?: string | undefined
}) {
  const index = STEPS.indexOf(step)
  const previous = STEPS[index - 1]

  return (
    <>
      {previous !== undefined && (
        <Button variant="secondary" onClick={() => navigate(stepPath(previous))}>
          {t.back}
        </Button>
      )}
      <span className="flex-[1_1_240px] text-sm text-text-muted">{blocked ?? t.later}</span>
      <Button disabled={working || blocked !== undefined} onClick={onPrimary}>
        {working ? t.saving : primary}
      </Button>
    </>
  )
}

export function SectionTitle({ children, aside }: { children: ReactNode; aside?: ReactNode }) {
  return (
    <div className="mb-3.5 flex items-baseline gap-3">
      <h2 className="type-display flex-1 text-[26px] leading-none">{children}</h2>
      {aside}
    </div>
  )
}

function LabelLine({ id, label, required }: { id: string; label: string; required?: boolean | undefined }) {
  return (
    <label htmlFor={id} className="label-caps mb-1.5 block text-text-muted">
      {label}
      {required === true && <span className="font-semibold tracking-normal normal-case"> · {t.required}</span>}
    </label>
  )
}

const control = 'h-11 w-full border-0 border-b-2 bg-field px-3 text-[15px] text-ink outline-none focus:border-teal'

function Hint({ error, hint }: { error?: string | undefined; hint?: string | undefined }) {
  if (error !== undefined) return <div className="mt-1.5 text-[13px] font-bold text-red-text">{error}</div>
  if (hint !== undefined) return <div className="mt-1.5 text-[13px] text-text-muted">{hint}</div>

  return null
}

type Common = { label: string; hint?: string | undefined; error?: string | undefined; required?: boolean | undefined; wide?: boolean | undefined }

export function Input({ label, hint, error, required, wide, ...rest }: Common & InputHTMLAttributes<HTMLInputElement>) {
  const id = useId()

  return (
    <div className={wide === true ? 'col-span-full' : ''}>
      <LabelLine id={id} label={label} required={required} />
      <input id={id} aria-invalid={error !== undefined} className={`${control} ${error !== undefined ? 'border-b-[3px] border-red-pen text-red-text' : 'border-ink'}`} {...rest} />
      <Hint error={error} hint={hint} />
    </div>
  )
}

export function TextArea({ label, hint, error, required, wide, ...rest }: Common & TextareaHTMLAttributes<HTMLTextAreaElement>) {
  const id = useId()

  return (
    <div className={wide === true ? 'col-span-full' : ''}>
      <LabelLine id={id} label={label} required={required} />
      <textarea
        id={id}
        rows={3}
        aria-invalid={error !== undefined}
        className={`${control} h-auto resize-y py-2.5 ${error !== undefined ? 'border-red-pen' : 'border-ink'}`}
        {...rest}
      />
      <Hint error={error} hint={hint} />
    </div>
  )
}

export function Select({ label, hint, error, required, wide, children, ...rest }: Common & SelectHTMLAttributes<HTMLSelectElement>) {
  const id = useId()

  return (
    <div className={wide === true ? 'col-span-full' : ''}>
      <LabelLine id={id} label={label} required={required} />
      <select id={id} aria-invalid={error !== undefined} className={`${control} ${error !== undefined ? 'border-red-pen' : 'border-ink'}`} {...rest}>
        {children}
      </select>
      <Hint error={error} hint={hint} />
    </div>
  )
}

export function ReadOnly({ label, value, hint }: { label: string; value: string; hint?: string | undefined }) {
  return (
    <div>
      <div className="label-caps mb-1.5 text-text-muted">{label}</div>
      <div className="flex h-11 items-center border-b-2 border-dashed border-rule-dashed bg-paper px-3 text-[15px] text-text-muted">{value}</div>
      <Hint hint={hint} />
    </div>
  )
}

/** One cell of a totals band. `tone` ink is the first cell; marigold needs attention. */
export function Total({ label, value, note, tone = 'plain', suffix }: { label: string; value: string; note: string; tone?: 'ink' | 'plain' | 'attention'; suffix?: string | undefined }) {
  const box = tone === 'ink' ? 'bg-ink text-sheet' : tone === 'attention' ? 'bg-marigold' : ''
  const labelColour = tone === 'ink' ? 'text-on-ink-muted' : tone === 'attention' ? '' : 'text-text-muted'
  const noteColour = tone === 'ink' ? 'text-on-ink-soft' : tone === 'attention' ? 'font-semibold' : 'text-text-muted'

  return (
    <section className={`border-ink px-[clamp(16px,3vw,24px)] pt-4.5 pb-5 not-first:border-l-2 ${box}`}>
      <h2 className={`label-caps ${labelColour}`}>{label}</h2>
      <div className={`type-numeral text-[clamp(64px,7vw,104px)] ${tone === 'ink' ? 'text-teal-bright' : ''}`}>
        {value}
        {suffix !== undefined && <span className={`text-[44px] ${tone === 'ink' ? 'text-sheet' : 'text-text-muted'}`}>{suffix}</span>}
      </div>
      <div className={`text-sm ${noteColour}`}>{note}</div>
    </section>
  )
}

export function TotalsBand({ children }: { children: ReactNode }) {
  return <div className="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] border-y-2 border-ink">{children}</div>
}

/** A boxed attention line: marigold edge, the words carry the meaning. */
export function Attention({ title, children }: { title: string; children: ReactNode }) {
  return (
    <div role="status" className="border-l-4 border-marigold bg-marigold-wash px-4 py-3 text-sm">
      <strong>{title}</strong> {children}
    </div>
  )
}

/** The page under the head: one or two ruled columns. */
export function Columns({ children }: { children: ReactNode }) {
  return <div className="flex flex-1 flex-wrap items-stretch border-t-2 border-ink">{children}</div>
}

export function Column({ children, wide = false, sheet = false, edge = false }: { children: ReactNode; wide?: boolean; sheet?: boolean; edge?: boolean }) {
  return (
    <section
      className={`min-w-0 px-[clamp(16px,3vw,40px)] pt-6 pb-8 ${wide ? 'flex-[3_1_560px]' : 'flex-[2_1_340px]'} ${sheet ? 'bg-sheet' : ''} ${edge ? 'border-l-2 border-ink' : ''}`}
    >
      {children}
    </section>
  )
}

/** Two digits, for the number left of the red margin line. */
export const nn = (n: number) => String(n).padStart(2, '0')
