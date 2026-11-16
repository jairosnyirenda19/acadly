import { useEffect, useRef, type ReactNode } from 'react'
import { GridIcon } from './icons'

/**
 * A square checkbox: a real input, named, drawn as a 22px box inside a 44px
 * place to tap. Checked is a solid ink box with a white stroke; part of a set
 * is a solid box with a bar.
 */
export function Check({
  checked,
  mixed = false,
  onChange,
  label,
  children,
  disabled = false,
  onInk = false,
  className = '',
}: {
  checked: boolean
  mixed?: boolean
  onChange: (checked: boolean) => void
  /** The accessible name, when no visible words follow the box. */
  label?: string | undefined
  /** Visible words beside the box; they name it. */
  children?: ReactNode
  disabled?: boolean
  onInk?: boolean
  className?: string
}) {
  const input = useRef<HTMLInputElement>(null)

  useEffect(() => {
    if (input.current !== null) input.current.indeterminate = mixed
  }, [mixed])

  const filled = checked || mixed
  const box = onInk
    ? `${filled ? 'border-sheet bg-sheet text-ink' : 'border-sheet bg-transparent'} peer-focus-visible:outline-teal-bright`
    : `${filled ? 'border-ink bg-ink text-sheet' : 'border-ink bg-sheet'} peer-focus-visible:outline-ink`

  return (
    <label className={`relative inline-flex min-h-11 min-w-11 items-center gap-2.5 ${disabled ? 'cursor-not-allowed' : 'cursor-pointer'} ${children === undefined ? 'justify-center' : ''} ${className}`}>
      <input
        ref={input}
        type="checkbox"
        className="peer sr-only"
        checked={checked}
        disabled={disabled}
        aria-label={label}
        onChange={(event) => onChange(event.target.checked)}
      />
      <span
        aria-hidden="true"
        className={`grid size-[22px] shrink-0 place-items-center border-2 peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 ${disabled ? 'border-rule-dashed bg-disabled text-text-muted' : box}`}
      >
        {mixed ? <span className="h-[3px] w-2.5 bg-current" /> : checked ? <GridIcon name="check" size={14} /> : null}
      </span>
      {children}
    </label>
  )
}
