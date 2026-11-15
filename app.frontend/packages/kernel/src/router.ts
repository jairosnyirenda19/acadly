import { useEffect, useState } from 'react'

const EVENT = 'acadly:navigate'

/** Go to a path inside the app without reloading the page. */
export function navigate(path: string, replace = false): void {
  if (replace) {
    window.history.replaceState(null, '', path)
  } else {
    window.history.pushState(null, '', path)
  }

  window.dispatchEvent(new Event(EVENT))
}

/** The current path and query, updated on every navigation. */
export function useLocation(): { path: string; query: URLSearchParams } {
  const read = () => ({ path: window.location.pathname, query: new URLSearchParams(window.location.search) })
  const [location, setLocation] = useState(read)

  useEffect(() => {
    const update = () => setLocation(read())
    window.addEventListener('popstate', update)
    window.addEventListener(EVENT, update)

    return () => {
      window.removeEventListener('popstate', update)
      window.removeEventListener(EVENT, update)
    }
  }, [])

  return location
}
