import { navigate, useLocation } from '@acadly/kernel'
import { useEffect } from 'react'

const KEY = 'acadly:learners:list'

/** Keeps the address of the list (its filters, sort and page) for the way back from a record. */
export function useRememberList() {
  const { path, query } = useLocation()
  const search = query.toString()

  useEffect(() => {
    try {
      window.sessionStorage.setItem(KEY, `${path}${search !== '' ? `?${search}` : ''}`)
    } catch {
      // Without storage the way back opens the list as it first shows.
    }
  }, [path, search])
}

function listAddress(): string {
  try {
    const kept = window.sessionStorage.getItem(KEY)

    return kept !== null && kept.startsWith('/learners') ? kept : '/learners'
  } catch {
    return '/learners'
  }
}

/** Back to the list, as it was left. */
export function BackToList({ label }: { label: string }) {
  const href = listAddress()

  return (
    <a
      href={href}
      onClick={(event) => {
        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return
        event.preventDefault()
        navigate(listAddress())
      }}
      className="inline-flex min-h-11 items-center gap-1.5 text-sm font-extrabold text-ink no-underline underline-offset-[3px] hover:underline"
    >
      <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="square">
        <path d="M15 6l-6 6 6 6" />
      </svg>
      {label}
    </a>
  )
}
