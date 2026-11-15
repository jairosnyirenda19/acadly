import { useCallback, useEffect, useRef, useState, useSyncExternalStore } from 'react'

/**
 * Loading a page's data (the approved "Loading" design), the same way on
 * every page:
 *
 *  - quiet:   under 0.2 s nothing changes, so nothing flashes;
 *  - waiting: after 0.2 s the page draws its dashed sheet;
 *  - slow:    after 8 s a line says the connection is slow;
 *  - ready, refused, failed: the answer.
 *
 * A page seen before shows what it had at once and updates in place. While
 * anything loads, the console's teal rule shows (see `useLoading`).
 */
export type LoadPhase = 'quiet' | 'waiting' | 'slow' | 'ready' | 'refused' | 'failed'

export const WAIT_AFTER = 200
export const SLOW_AFTER = 8000

/** What the fetch gave: the data, 'refused', or null when it could not be read. */
type Answer<T> = T | 'refused' | null

const cache = new Map<string, unknown>()

// ── The count of loads in flight, for the teal rule ──────────────────────────
let inFlight = 0
const listeners = new Set<() => void>()
const tell = () => listeners.forEach((l) => l())

export const loading = {
  start: () => {
    inFlight++
    tell()
  },
  stop: () => {
    inFlight = Math.max(0, inFlight - 1)
    tell()
  },
  count: () => inFlight,
  subscribe: (listener: () => void) => {
    listeners.add(listener)

    return () => {
      listeners.delete(listener)
    }
  },
}

/** Whether anything is loading now. */
export function useLoading(): boolean {
  return useSyncExternalStore(loading.subscribe, () => loading.count() > 0, () => false)
}

export interface Load<T> {
  /** The data: fresh, or what this page had last time while it loads again. */
  data: T | undefined
  phase: LoadPhase
  /** Load again, keeping what is shown until the answer comes. */
  reload: () => void
  /** Put new data in place, after a save that returned it. */
  set: (data: T) => void
}

/**
 * Loads a page's data by a key that names it (`people`, `person:<id>` …).
 * The key changing loads again.
 */
export function useLoad<T>(key: string, fetch: () => Promise<Answer<T>>): Load<T> {
  const [data, setData] = useState<T | undefined>(() => cache.get(key) as T | undefined)
  const [phase, setPhase] = useState<LoadPhase>(() => (cache.has(key) ? 'ready' : 'quiet'))
  const [round, setRound] = useState(0)
  const fetchRef = useRef(fetch)
  fetchRef.current = fetch

  useEffect(() => {
    let live = true
    const had = cache.has(key)
    setData(cache.get(key) as T | undefined)
    setPhase(had ? 'ready' : 'quiet')

    const wait = had ? undefined : window.setTimeout(() => live && setPhase('waiting'), WAIT_AFTER)
    const slow = had ? undefined : window.setTimeout(() => live && setPhase('slow'), SLOW_AFTER)
    loading.start()

    void fetchRef
      .current()
      .then((answer) => {
        if (!live) return
        if (answer === 'refused') setPhase('refused')
        else if (answer === null) setPhase(had ? 'ready' : 'failed')
        else {
          cache.set(key, answer)
          setData(answer)
          setPhase('ready')
        }
      })
      .catch(() => live && setPhase(had ? 'ready' : 'failed'))
      .finally(() => {
        window.clearTimeout(wait)
        window.clearTimeout(slow)
        loading.stop()
      })

    return () => {
      live = false
      window.clearTimeout(wait)
      window.clearTimeout(slow)
    }
  }, [key, round])

  const reload = useCallback(() => setRound((r) => r + 1), [])
  const set = useCallback(
    (next: T) => {
      cache.set(key, next)
      setData(next)
      setPhase('ready')
    },
    [key],
  )

  return { data, phase, reload, set }
}

/** Forget every remembered page: after signing out. */
export function forgetLoads(): void {
  cache.clear()
}
