import { navigate, useAppContext } from '@acadly/kernel'
import { useState, type ReactNode } from 'react'
import { copy } from '../copy'
import { Select } from '../setup/parts'
import type { Division } from '../shell/divisions'
import { useWorkIn } from '../shell/WorkIn'
import { programmeDivisions } from './api'

const t = copy.programmes

/**
 * The division a Programmes page works in: the one the account works in when
 * it may see programmes there, else the first where it may.
 */
export function useProgrammeDivision(): {
  division: Division | null
  choices: Division[]
  choose: (id: string) => void
} {
  const context = useAppContext()
  const { division: lens } = useWorkIn()
  const choices = programmeDivisions(context)
  const [picked, setPicked] = useState<string | null>(null)
  const division = choices.find((d) => d.id === picked) ?? choices.find((d) => d.id === lens?.id) ?? choices[0] ?? null

  return { division, choices, choose: setPicked }
}

/** A choice of division, shown only when the account sees programmes in more than one. */
export function DivisionChoice({ choices, division, choose }: { choices: Division[]; division: Division; choose: (id: string) => void }) {
  if (choices.length < 2) return null

  return (
    <div className="w-64">
      <Select label={t.division} value={division.id} onChange={(e) => choose(e.target.value)}>
        {choices.map((d) => (
          <option key={d.id} value={d.id}>
            {d.name}
          </option>
        ))}
      </Select>
    </div>
  )
}

export function NoDivision() {
  return (
    <div className="px-[clamp(16px,3vw,40px)] py-8">
      <Attention>{t.noDivision}</Attention>
    </div>
  )
}

/** Something that needs attention: marigold, with its mark. */
export function Attention({ children }: { children: ReactNode }) {
  return (
    <div className="border-2 border-marigold bg-marigold-wash px-4 py-3">
      <strong aria-hidden="true">! </strong>
      {children}
    </div>
  )
}

/** A sheet with an ink edge, the frame of each part of a page. */
export function Sheet({ children, label, className = '' }: { children: ReactNode; label?: string; className?: string }) {
  return (
    <section aria-label={label} className={`border-2 border-ink bg-sheet ${className}`}>
      {children}
    </section>
  )
}

/** The small label over a fact, with the fact under it. */
export function Fact({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="grid gap-0.5 border-r border-rule px-3.5 py-2.5 last:border-r-0">
      <span className="label-caps text-text-muted">{label}</span>
      <span className="text-[15px]">{children}</span>
    </div>
  )
}

export const th = 'label-caps border-b-2 border-ink px-3 py-2 text-left text-text-muted'
export const td = 'border-b border-rule px-3 py-2.5 align-baseline'

/** A link that moves inside the console. */
export function Go({ to, children, className = '' }: { to: string; children: ReactNode; className?: string }) {
  return (
    <a
      href={to}
      className={`text-ink underline decoration-rule underline-offset-4 hover:decoration-ink ${className}`}
      onClick={(event) => {
        event.preventDefault()
        navigate(to)
      }}
    >
      {children}
    </a>
  )
}
