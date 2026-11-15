import type { ButtonHTMLAttributes, ReactNode } from 'react'

type Variant = 'primary' | 'secondary'

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: Variant
  /** Work is running: the button keeps its place, shows a dashed cell and cannot be pressed again. */
  busy?: boolean
  children: ReactNode
}

const base = 'inline-flex h-11 items-center justify-center px-[18px] text-[15px] font-extrabold transition-colors'

const variants: Record<Variant, string> = {
  primary: 'bg-teal text-sheet hover:bg-ink',
  secondary: 'border-2 border-ink bg-transparent text-ink hover:bg-field',
}

const blocked = 'bg-disabled text-text-muted'

/**
 * The register book's button: square, 44px, heavy. A disabled button is
 * "blocked", and the screen that shows one must say why beside it (DESIGN.md).
 */
export function Button({ variant = 'primary', type = 'button', disabled, busy = false, className, children, ...rest }: ButtonProps) {
  const look = disabled || busy ? blocked : variants[variant]

  return (
    <button type={type} disabled={disabled || busy} aria-busy={busy || undefined} className={[base, look, busy ? 'gap-2.5' : '', className].filter(Boolean).join(' ')} {...rest}>
      {busy && <span aria-hidden="true" className="size-3.5 shrink-0 border-2 border-dashed border-text-muted" />}
      {children}
    </button>
  )
}
