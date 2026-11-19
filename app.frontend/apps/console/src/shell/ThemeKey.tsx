import { keepAppearance, savedAppearance } from '@acadly/ui'
import { useEffect, useState } from 'react'
import { copy } from '../copy'

const isDark = () => document.documentElement.getAttribute('data-theme') === 'dark'

/**
 * The light and dark switch in the top bar, as in eOffice: one key, two
 * states. Untouched, the console follows the device; the first press makes
 * the choice and keeps it on this device. Your account keeps the third
 * choice, "Same as this device".
 */
export function ThemeKey() {
  const [dark, setDark] = useState(isDark)

  useEffect(() => {
    if (savedAppearance() !== 'device' || typeof window.matchMedia !== 'function') return
    const media = window.matchMedia('(prefers-color-scheme: dark)')
    const follow = () => setDark(isDark())
    media.addEventListener('change', follow)

    return () => media.removeEventListener('change', follow)
  }, [])

  const flip = () => {
    const next = !dark
    keepAppearance(next ? 'dark' : 'light')
    setDark(next)
  }
  const label = dark ? copy.appearance.toLight : copy.appearance.toDark

  return (
    <button type="button" onClick={flip} title={label} aria-label={label} className="grid size-11 shrink-0 place-items-center border-2 border-ink text-ink hover:bg-field">
      <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        {dark ? (
          <>
            <circle cx="12" cy="12" r="4" />
            <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" />
          </>
        ) : (
          <path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z" />
        )}
      </svg>
    </button>
  )
}
