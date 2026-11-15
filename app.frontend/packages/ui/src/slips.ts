/**
 * Slips: the console's short notices (the approved "Slips" design). A slip
 * says that something just happened, then leaves. This is the store; the
 * SlipPad component draws it. One store for the whole app.
 *
 *  - done leaves after 5 s, attention after 8 s;
 *  - not saved (wrong) stays until closed; working stays until finished;
 *  - the same message twice in a row is counted (x2) and its time restarts;
 *  - time only runs while the pad is not paused (pointer, focus, hidden tab).
 */

export type SlipKind = 'done' | 'attention' | 'wrong' | 'working'

export interface SlipInput {
  /** The state in words, above the sentence: Saved, Not saved, Sending … */
  word: string
  /** What happened, in one plain sentence. */
  title: string
  /** What happens next, or what to do. Optional. */
  detail?: string | undefined
  /** At most one action. */
  action?: { label: string; run: () => void } | undefined
}

export interface Slip extends SlipInput {
  id: number
  kind: SlipKind
  /** How many times the same message arrived in a row. */
  count: number
  /** Milliseconds this slip lives, or null when it stays. */
  life: number | null
  /** Milliseconds already run. */
  run: number
}

export const LIFE: Record<SlipKind, number | null> = { done: 5000, attention: 8000, wrong: null, working: null }

type Listener = () => void

export function createSlips() {
  let slips: Slip[] = []
  let next = 1
  const listeners = new Set<Listener>()

  const emit = () => {
    slips = [...slips]
    listeners.forEach((l) => l())
  }

  const same = (a: Slip, kind: SlipKind, input: SlipInput) => a.kind === kind && a.title === input.title && (a.detail ?? '') === (input.detail ?? '') && a.word === input.word

  const add = (kind: SlipKind, input: SlipInput): number => {
    const last = slips[slips.length - 1]

    if (last !== undefined && same(last, kind, input)) {
      slips = slips.map((s) => (s.id === last.id ? { ...s, count: s.count + 1, run: 0, action: input.action } : s))
      emit()

      return last.id
    }

    const slip: Slip = { ...input, id: next++, kind, count: 1, life: LIFE[kind], run: 0 }
    slips = [...slips, slip]
    emit()

    return slip.id
  }

  const close = (id: number) => {
    if (!slips.some((s) => s.id === id)) return
    slips = slips.filter((s) => s.id !== id)
    emit()
  }

  return {
    done: (input: SlipInput) => add('done', input),
    attention: (input: SlipInput) => add('attention', input),
    wrong: (input: SlipInput) => add('wrong', input),
    /** A slip that stays while work runs. Finish it to turn it into a done or not saved slip in place. */
    working: (input: SlipInput) => {
      const id = add('working', input)

      return {
        id,
        update: (change: Partial<SlipInput>) => {
          slips = slips.map((s) => (s.id === id ? { ...s, ...change } : s))
          emit()
        },
        finish: (kind: 'done' | 'wrong', result: SlipInput) => {
          if (!slips.some((s) => s.id === id)) {
            add(kind, result)

            return
          }
          slips = slips.map((s) => (s.id === id ? { ...s, ...result, kind, life: LIFE[kind], run: 0 } : s))
          emit()
        },
      }
    },
    close,
    /** Moves every running slip's time on; the ones that reach their life leave. */
    advance: (ms: number) => {
      if (!slips.some((s) => s.life !== null)) return
      const moved = slips.map((s) => (s.life === null ? s : { ...s, run: s.run + ms }))
      slips = moved.filter((s) => s.life === null || s.run < s.life)
      emit()
    },
    list: () => slips,
    subscribe: (listener: Listener) => {
      listeners.add(listener)

      return () => {
        listeners.delete(listener)
      }
    },
    clear: () => {
      slips = []
      emit()
    },
  }
}

export type Slips = ReturnType<typeof createSlips>

/** The app's one pad of slips. */
export const slips = createSlips()
