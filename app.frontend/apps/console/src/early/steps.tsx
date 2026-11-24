import { copy } from '../copy'
import type { StepKey } from './api'

const t = copy.early

/** The reached steps, in order. "Not seen" is no step. */
export const REACHED: StepKey[] = ['starting', 'growing', 'can_do']
export const ALL_STEPS: StepKey[] = ['starting', 'growing', 'can_do', 'not_seen']

const strokes: Record<StepKey, string> = { starting: '/', growing: '//', can_do: '///', not_seen: '–' }
const fills: Record<StepKey, string> = { starting: 'bg-teal-wash text-ink', growing: 'bg-teal-mid text-ink', can_do: 'bg-teal text-sheet', not_seen: 'bg-field text-text-muted' }

export function isStep(value: string | null | undefined): value is StepKey {
  return value === 'starting' || value === 'growing' || value === 'can_do' || value === 'not_seen'
}

/** The words for each step: the school's, as the server sends them (D-109), else the console's own. */
export type StepWords = Record<StepKey, string>

export function stepWords(steps?: { key: string; label: string }[]): StepWords {
  const words: StepWords = { ...t.steps }
  for (const s of steps ?? []) if (isStep(s.key) && s.label.trim() !== '') words[s.key] = s.label

  return words
}

/** A step drawn as strokes. The word is always given: beside it, or to a screen reader. */
export function StepMark({ step, word = false, wide = 'w-11', decorative = false, words = t.steps }: { step: string | null; word?: boolean; wide?: string; decorative?: boolean; words?: StepWords }) {
  if (decorative) {
    return (
      <span aria-hidden="true" className={`inline-flex h-7 ${wide} items-center justify-center text-sm font-extrabold tracking-wide ${isStep(step) ? fills[step] : 'bg-field'}`}>
        {isStep(step) ? strokes[step] : ''}
      </span>
    )
  }

  if (!isStep(step)) {
    return (
      <span className={`inline-flex h-7 ${wide} items-center justify-center border border-dashed border-rule text-text-muted`} aria-label={t.cols.steps}>
        <span aria-hidden="true">·</span>
      </span>
    )
  }

  return (
    <span className="inline-flex items-center gap-2">
      <span className={`inline-flex h-7 ${wide} items-center justify-center text-sm font-extrabold tracking-wide ${fills[step]}`} aria-hidden={word ? true : undefined} aria-label={word ? undefined : words[step]} role={word ? undefined : 'img'}>
        <span aria-hidden="true">{strokes[step]}</span>
      </span>
      {word && <span className="text-[13px] font-bold">{words[step]}</span>}
    </span>
  )
}

/** The legend of the three steps. */
export function StepLegend({ words = t.steps }: { words?: StepWords }) {
  return (
    <ul className="grid gap-1.5">
      {REACHED.map((s) => (
        <li key={s} className="flex items-center gap-2.5">
          <StepMark step={s} wide="w-14" words={words} />
          <span>
            <b className="block text-sm">{words[s]}</b>
            <span className="text-[13px] text-text-muted">{t.stepsHelp[s]}</span>
          </span>
        </li>
      ))}
    </ul>
  )
}
