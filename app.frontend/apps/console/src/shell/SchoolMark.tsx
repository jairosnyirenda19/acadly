import { useAppContext } from '@acadly/kernel'
import { nameSize } from '@acadly/ui'
import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import { copy } from '../copy'

const API = import.meta.env.VITE_API_URL ?? '/api'

/** The school's logo, on a white square so any logo stays clear on the rail. */
export function SchoolLogo({ size = 40 }: { size?: number }) {
  const { school } = useAppContext()

  if (!school.has_logo) return null

  return (
    <span aria-hidden="true" className="grid shrink-0 place-items-center logo-plate border-2 border-accent p-0.5" style={{ width: size, height: size }}>
      <img src={`${API}/v1/school/logo`} alt="" className="max-h-full max-w-full object-contain" />
    </span>
  )
}

/**
 * The name at the top of the rail (D-096): the school's own, or Acadly IQ-X.
 * It is sized by its length and never cut: one line at full size, then
 * smaller, then two lines broken between words.
 */
export function SchoolMark({ phone = false }: { phone?: boolean }) {
  const { school } = useAppContext()
  const own = school.look.app_name

  if (own === null || own === '') {
    return (
      <span className={`type-display whitespace-nowrap ${phone ? 'text-2xl' : 'text-[32px]'} leading-[0.9]`}>
        {copy.setup.product} <span className={phone ? 'text-teal' : 'text-accent'}>{copy.setup.productAccent}</span>
      </span>
    )
  }

  return <FittedName own={own} phone={phone} short={school.short_name} accentLast={school.look.accent_last} />
}

/**
 * The school's own name, made to fit (D-096): it starts at the size its length
 * suggests and steps down a pixel at a time until it sits in its lines. It is
 * never cut, and never smaller than 15px; a phone shows the short name on one line.
 */
function FittedName({ own, phone, short, accentLast }: { own: string; phone: boolean; short: string; accentLast: boolean }) {
  const name = phone ? short : own
  const words = name.split(' ')
  const last = words.length > 1 && accentLast ? words.pop() : undefined
  const start = phone ? { px: 24, lines: 1 as const } : nameSize(name)
  const [px, setPx] = useState(start.px)
  const box = useRef<HTMLSpanElement>(null)

  useLayoutEffect(() => setPx(start.px), [name, start.px])

  // Measured again once the narrow face has loaded: a fallback font is wider.
  useEffect(() => {
    let live = true
    void document.fonts?.ready.then(() => live && setPx(start.px))

    return () => {
      live = false
    }
  }, [start.px])

  useLayoutEffect(() => {
    const el = box.current
    if (el === null) return
    // A line of narrow capitals is 0.92 of the size; a little more is allowed for rounding.
    if (el.scrollHeight > start.lines * px * 0.92 * 1.15 && px > 15) setPx(px - 1)
  }, [px, start.lines])

  return (
    <span ref={box} title={own} className="type-display block min-w-0 leading-[0.92] [overflow-wrap:normal]" style={{ fontSize: px }}>
      {last === undefined ? name : words.join(' ')}
      {last !== undefined && (
        <>
          {' '}
          <span className={phone ? 'text-teal' : 'text-accent'}>{last}</span>
        </>
      )}
    </span>
  )
}

/** The folded rail's mark: the logo, or the first two letters of the short name, the second in the accent. */
export function SchoolInitials() {
  const { school } = useAppContext()

  if (school.has_logo) return <SchoolLogo size={40} />

  const own = school.look.app_name
  const letters = (own === null || own === '' ? copy.setup.product : school.short_name)
    .split(/\s+/)
    .map((w) => w[0] ?? '')
    .join('')
    .slice(0, 2)
    .toUpperCase()

  return (
    <span aria-hidden="true" className="type-display grid size-10 place-items-center bg-accent text-xl text-ink">
      {letters.length > 1 ? letters : (own ?? copy.setup.product).slice(0, 1).toUpperCase()}
    </span>
  )
}
