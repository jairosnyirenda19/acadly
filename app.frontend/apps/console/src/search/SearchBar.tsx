import { navigate, useAppContext } from '@acadly/kernel'
import { useEffect, useRef, useState } from 'react'
import { copy } from '../copy'
import { Icon } from '../shell/icons'
import { screens, searchFor, toFound, type Found } from './search'

const t = copy.search

interface Band {
  key: string
  label: string
  hits: Found[]
  more: boolean
}

/**
 * Search everything (the approved design, 12-search §8, D-089): the bar on
 * every console screen. Ctrl K or / puts the cursor in it; arrow keys move;
 * Enter opens; Esc closes. On a phone it takes the whole screen.
 */
export function SearchBar({ phone }: { phone: boolean }) {
  const context = useAppContext()
  const [text, setText] = useState('')
  const [open, setOpen] = useState(false)
  const [bands, setBands] = useState<Band[]>([])
  const [failed, setFailed] = useState<string[]>([])
  const [at, setAt] = useState(0)
  const input = useRef<HTMLInputElement>(null)
  const asked = useRef(0)

  // Ctrl K or / puts the cursor in the bar, from anywhere but a field.
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      const typing = e.target instanceof HTMLElement && (e.target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName))

      if ((e.key === 'k' && (e.ctrlKey || e.metaKey)) || (e.key === '/' && !typing)) {
        e.preventDefault()
        setOpen(true)
        // The bar is there on a computer; on a phone it appears with the panel.
        input.current?.focus()
        window.setTimeout(() => input.current?.focus(), 0)
      }
    }
    window.addEventListener('keydown', onKey)

    return () => window.removeEventListener('keydown', onKey)
  }, [])

  // The search waits 200 ms after the last key, and an older answer never replaces a newer one.
  useEffect(() => {
    const words = text.trim()
    const mine = ++asked.current

    if (words.length < 2) return

    const wait = window.setTimeout(() => {
      void searchFor(words).then((state) => {
        if (mine !== asked.current) return
        const found = screens(context, words)
        const next: Band[] = found.length > 0 ? [{ key: 'screens', label: t.screens, hits: found, more: false }] : []
        for (const g of state?.groups ?? []) next.push({ key: g.key, label: g.label, hits: g.hits.map(toFound), more: g.more })
        setBands(next)
        setFailed(state === null ? [t.unavailable] : state.failed.map((f) => f.label))
        setAt(0)
      })
    }, 200)

    return () => window.clearTimeout(wait)
  }, [text, context])

  const flat = text.trim().length >= 2 ? bands.flatMap((b) => b.hits) : []
  const close = () => {
    setOpen(false)
    setText('')
    setBands([])
    input.current?.blur()
  }
  const go = (path: string) => {
    close()
    navigate(path)
  }
  const onKey = (e: React.KeyboardEvent) => {
    if (e.key === 'Escape') close()
    else if (e.key === 'ArrowDown') {
      e.preventDefault()
      setAt(Math.min(at + 1, flat.length - 1))
    } else if (e.key === 'ArrowUp') {
      e.preventDefault()
      setAt(Math.max(at - 1, 0))
    } else if (e.key === 'Enter' && flat[at] !== undefined) {
      e.preventDefault()
      go(flat[at].path)
    }
  }

  const field = (
    <label className={`flex min-w-0 items-center gap-2.5 border-2 border-ink bg-sheet px-3 ${phone ? 'h-12 flex-1' : 'h-11 w-full'}`}>
      <Icon name="search" size={18} />
      <span className="sr-only">{t.label}</span>
      <input
        ref={input}
        type="search"
        value={text}
        placeholder={t.placeholder}
        onChange={(e) => {
          setText(e.target.value)
          setOpen(true)
        }}
        onFocus={() => setOpen(true)}
        onKeyDown={onKey}
        role="combobox"
        aria-expanded={open && flat.length > 0}
        aria-controls="search-panel"
        aria-activedescendant={flat[at] !== undefined ? `hit-${at}` : undefined}
        className="h-9 min-w-0 flex-1 bg-transparent text-[15px] font-bold outline-none"
      />
      {!phone && <span className="ref border border-rule-dashed px-1.5 text-[11px] text-text-muted">Ctrl K</span>}
    </label>
  )

  let index = -1
  const panel = open && (
    <div id="search-panel" role="listbox" aria-label={t.label} className={`border-2 border-ink bg-sheet ${phone ? 'flex-1 overflow-y-auto border-x-0 border-b-0' : 'absolute top-full right-0 left-0 z-40 mt-[-2px] max-h-[70vh] overflow-y-auto'}`}>
      {text.trim().length < 2 ? (
        <p className="px-3.5 py-3 text-sm text-text-muted">{t.hint}</p>
      ) : (
        <>
          {bands.length === 0 && <p className="px-3.5 py-3 text-sm">{t.nothing(text.trim())}</p>}
          {bands.map((b) => (
            <div key={b.key}>
              <div className="label-caps flex border-t border-rule bg-field px-3.5 py-1.5">
                <span className="flex-1">{b.label}</span>
              </div>
              {b.hits.map((h) => {
                index++
                const mine = index

                return (
                  <a
                    key={h.key}
                    id={`hit-${mine}`}
                    role="option"
                    aria-selected={mine === at}
                    href={h.path}
                    onMouseEnter={() => setAt(mine)}
                    onClick={(e) => {
                      e.preventDefault()
                      go(h.path)
                    }}
                    className={`flex items-center gap-3 border-t border-rule px-3.5 py-2.5 text-ink no-underline ${mine === at ? 'bg-field shadow-[inset_6px_0_0_var(--color-ink)]' : ''}`}
                  >
                    <span className="min-w-0 flex-1">
                      <span className="block truncate text-[15px] font-extrabold">{h.title}</span>
                      <span className="block truncate text-[13px] text-text-muted">
                        {h.reference !== null && <span className="ref mr-1.5 font-bold text-ink">{h.reference}</span>}
                        {h.subtitle}
                      </span>
                    </span>
                    {h.state !== null && <span className="label-caps text-teal">{h.state}</span>}
                  </a>
                )
              })}
              {b.more && (
                <a
                  href={`/search?q=${encodeURIComponent(text.trim())}&in=${b.key}`}
                  onClick={(e) => {
                    e.preventDefault()
                    go(`/search?q=${encodeURIComponent(text.trim())}&in=${b.key}`)
                  }}
                  className="block border-t border-rule px-3.5 py-2 text-sm font-extrabold text-ink"
                >
                  {t.seeAll(b.label)} ›
                </a>
              )}
            </div>
          ))}
          {failed.length > 0 && <p className="border-t border-rule px-3.5 py-2 text-[13px] text-red-pen">{t.failed(failed.join(', '))}</p>}
        </>
      )}
      <p className="border-t-2 border-ink px-3.5 py-2.5 text-[13px] text-text-muted">{t.never}</p>
    </div>
  )

  if (phone) {
    return (
      <>
        <button
          type="button"
          aria-label={t.label}
          onClick={() => {
            setOpen(true)
            window.setTimeout(() => input.current?.focus(), 0)
          }}
          className="grid size-11 shrink-0 place-items-center border-2 border-ink text-ink"
        >
          <Icon name="search" size={18} />
        </button>
        {open && (
          <div role="dialog" aria-label={t.label} className="fixed inset-0 z-50 flex flex-col bg-paper">
            <div className="flex items-center gap-2 border-b-2 border-ink bg-sheet px-3 py-2">
              {field}
              <button type="button" onClick={close} className="h-12 px-2 text-[15px] font-extrabold">
                {t.close}
              </button>
            </div>
            {panel}
          </div>
        )}
      </>
    )
  }

  return (
    <div
      className="relative max-w-[620px] min-w-0 flex-[1_1_280px]"
      onBlur={(e) => {
        if (!e.currentTarget.contains(e.relatedTarget as Node | null)) setOpen(false)
      }}
    >
      {field}
      {panel}
    </div>
  )
}
