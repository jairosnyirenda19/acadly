import { useId, useState, type InputHTMLAttributes, type ReactNode } from 'react'

interface FieldProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'id' | 'className'> {
  label: ReactNode
  /** Shown at the right of the label, such as a link. */
  aside?: ReactNode
  /** The field is part of a failed submission: the border turns red. */
  invalid?: boolean
}

const box = 'flex h-12 border-2 bg-sheet focus-within:outline-[3px] focus-within:outline-marigold'
const input = 'h-full min-w-0 flex-1 bg-transparent px-3.5 text-base text-ink outline-none'

function Label({ id, label, aside }: { id: string; label: ReactNode; aside?: ReactNode }) {
  return (
    <span className="flex items-baseline justify-between gap-3">
      <label htmlFor={id} className="label-caps text-[11px]">
        {label}
      </label>
      {aside}
    </span>
  )
}

/** A labelled single-line field, 48px, square. */
export function TextField({ label, aside, invalid = false, ...rest }: FieldProps) {
  const id = useId()

  return (
    <div className="flex flex-col gap-1.5">
      <Label id={id} label={label} aside={aside} />
      <div className={`${box} ${invalid ? 'border-red-pen' : 'border-ink'}`}>
        <input id={id} aria-invalid={invalid} className={input} {...rest} />
      </div>
    </div>
  )
}

interface PasswordFieldProps extends Omit<FieldProps, 'type'> {
  showLabel: string
  hideLabel: string
}

/** A password field with a Show / Hide control. */
export function PasswordField({ label, aside, invalid = false, showLabel, hideLabel, ...rest }: PasswordFieldProps) {
  const id = useId()
  const [shown, setShown] = useState(false)
  const edge = invalid ? 'border-red-pen' : 'border-ink'

  return (
    <div className="flex flex-col gap-1.5">
      <Label id={id} label={label} aside={aside} />
      <div className={`${box} ${edge}`}>
        <input id={id} type={shown ? 'text' : 'password'} aria-invalid={invalid} className={input} {...rest} />
        <button
          type="button"
          aria-pressed={shown}
          aria-controls={id}
          onClick={() => setShown(!shown)}
          className={`w-[72px] shrink-0 border-l-2 bg-paper text-[13px] font-extrabold text-ink hover:bg-field ${edge}`}
        >
          {shown ? hideLabel : showLabel}
        </button>
      </div>
    </div>
  )
}
