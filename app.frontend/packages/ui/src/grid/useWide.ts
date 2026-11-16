import { useSyncExternalStore } from 'react'

const QUERY = '(min-width: 768px)'

const subscribe = (change: () => void) => {
  if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return () => undefined
  const media = window.matchMedia(QUERY)
  media.addEventListener('change', change)

  return () => media.removeEventListener('change', change)
}

const read = () => typeof window !== 'undefined' && typeof window.matchMedia === 'function' && window.matchMedia(QUERY).matches

/** Whether the screen is wide enough for a table (768px and up); below it, phones get cards. */
export function useWide(): boolean {
  return useSyncExternalStore(subscribe, read, () => true)
}
