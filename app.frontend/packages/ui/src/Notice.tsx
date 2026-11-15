import type { ReactNode } from 'react'

type Tone = 'wrong' | 'done'

const looks: Record<Tone, { box: string; mark: string; glyph: string }> = {
  wrong: { box: 'border-red-pen bg-red-wash', mark: 'bg-red-pen', glyph: '!' },
  done: { box: 'border-teal bg-teal-wash', mark: 'bg-teal', glyph: '/' },
}

/**
 * A boxed message with a mark and a title. Red is wrong or blocked; teal is
 * done. The mark and the words carry the meaning, not the colour alone.
 */
export function Notice({ tone, title, children }: { tone: Tone; title: ReactNode; children?: ReactNode }) {
  const look = looks[tone]

  return (
    <div role={tone === 'wrong' ? 'alert' : 'status'} className={`flex gap-3 border-l-4 px-3.5 py-3 text-sm leading-[1.45] ${look.box}`}>
      <span aria-hidden="true" className={`grid size-6 shrink-0 place-items-center text-base font-extrabold text-sheet ${look.mark}`}>
        {look.glyph}
      </span>
      <div>
        <div className="font-extrabold">{title}</div>
        {children}
      </div>
    </div>
  )
}
