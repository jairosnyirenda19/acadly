import { useId } from 'react'
import { copy } from '../copy'

const t = copy.learners

export type NoticesChannel = 'sms' | 'email'

/**
 * How a contact receives notices: SMS or email (the approved design). Email
 * can be chosen only when the contact has an email address; the line under
 * the choice says why when it cannot.
 */
export function NoticesBy({ value, hasEmail, onChange, error }: { value: NoticesChannel; hasEmail: boolean; onChange: (next: NoticesChannel) => void; error?: string | undefined }) {
  const id = useId()
  const options: NoticesChannel[] = ['sms', 'email']

  return (
    <div className="flex flex-col gap-1.5">
      <span id={id} className="label-caps text-[11px]">
        {t.noticesBy} <span className="font-semibold tracking-normal normal-case">· {t.required}</span>
      </span>
      <div role="radiogroup" aria-labelledby={id} className="flex flex-wrap gap-1.5">
        {options.map((o) => {
          const on = value === o
          const blocked = o === 'email' && !hasEmail

          return (
            <label
              key={o}
              className={`inline-flex h-10 items-center gap-2 border-2 px-3.5 text-sm font-extrabold ${on ? 'border-ink bg-ink text-sheet' : blocked ? 'border-rule bg-sheet text-text-muted' : 'cursor-pointer border-rule-dashed bg-sheet text-ink hover:bg-field'}`}
            >
              <input type="radio" name={id} checked={on} disabled={blocked} onChange={() => onChange(o)} className="size-4 accent-teal-bright" />
              {t.noticesChannel[o]}
            </label>
          )
        })}
      </div>
      <span className={`text-[13px] ${error !== undefined ? 'font-bold text-red-text' : 'text-text-muted'}`}>{error ?? (hasEmail ? t.noticesAsk : t.noticesNeedEmail)}</span>
    </div>
  )
}
