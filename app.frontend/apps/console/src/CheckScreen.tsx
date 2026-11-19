import { api, useLocation, type paths } from '@acadly/kernel'
import { Button } from '@acadly/ui'
import { useEffect, useState } from 'react'
import { copy } from './copy'
import { day } from './settings/api'

type Found = paths['/v1/check/{code}']['get']['responses'][200]['content']['application/json']
type Answer = { kind: 'found'; data: Found } | { kind: 'missing' } | { kind: 'failed' }

const t = copy.check

/**
 * The public document check (the approved design, D-096): anyone with a
 * document's code learns whether the school issued it and whether it was
 * cancelled. Its kind, number and date only: never a name, an amount or a mark.
 */
export function CheckScreen({ school }: { school: string | null }) {
  const { query } = useLocation()
  const [code, setCode] = useState(query.get('code') ?? '')
  const [answer, setAnswer] = useState<Answer | null>(null)
  const [busy, setBusy] = useState(false)

  const ask = async (value: string) => {
    if (value.trim() === '') return
    setBusy(true)
    try {
      const { data, response } = await api.GET('/v1/check/{code}', { params: { path: { code: value.trim() } } })
      setAnswer(data !== undefined ? { kind: 'found', data } : response.status === 404 ? { kind: 'missing' } : { kind: 'failed' })
    } catch {
      setAnswer({ kind: 'failed' })
    }
    setBusy(false)
  }

  // A code from the QR is checked at once.
  useEffect(() => {
    const first = query.get('code')
    if (first !== null) void ask(first)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  return (
    <div className="min-h-screen bg-paper">
      <header className="flex h-14 items-center bg-ink px-4 text-sheet">
        <span className="text-base font-extrabold">{school ?? ''}</span>
      </header>
      <main className="mx-auto grid max-w-[480px] gap-4 px-4 pt-6 pb-10">
        <div>
          <div className="label-caps text-text-muted">{t.kicker}</div>
          <h1 className="type-display mt-1 text-[40px] leading-[0.9]">{t.title}</h1>
          <p className="mt-2 text-[15px]">{t.intro}</p>
        </div>
        <form
          className="grid gap-3"
          onSubmit={(e) => {
            e.preventDefault()
            void ask(code)
          }}
        >
          <label htmlFor="check-code" className="text-sm font-extrabold">
            {t.code}
          </label>
          <input
            id="check-code"
            value={code}
            autoComplete="off"
            spellCheck={false}
            onChange={(e) => setCode(e.target.value.toUpperCase())}
            placeholder="XXXX-XXXX-XXXX"
            className="ref h-14 w-full border-0 border-b-[3px] border-ink bg-field px-3 text-xl font-extrabold tracking-[0.08em] text-ink outline-none focus:border-teal"
          />
          <Button className="h-13 text-base" busy={busy} disabled={code.trim() === ''}>
            {t.check}
          </Button>
        </form>

        {answer?.kind === 'found' && answer.data.state === 'genuine' && (
          <section role="status" className="grid gap-1.5 border-2 border-teal bg-sheet p-3.5">
            <span className="label-caps justify-self-start bg-teal px-[7px] py-[3px] text-sheet">✓ {t.genuine}</span>
            <b className="text-[17px]">{`${answer.data.kind} ${answer.data.reference}`}</b>
            <span className="text-sm">{t.issued(answer.data.school, day(answer.data.issued_on))}</span>
          </section>
        )}
        {answer?.kind === 'found' && answer.data.state === 'cancelled' && (
          <section role="status" className="grid gap-1.5 border-2 border-red-pen bg-red-wash p-3.5">
            <span className="label-caps justify-self-start bg-red-pen px-[7px] py-[3px] text-sheet">✕ {t.cancelled}</span>
            <b className="text-[17px]">{`${answer.data.kind} ${answer.data.reference}`}</b>
            <span className="text-sm">{t.cancelledOn(day(answer.data.cancelled?.on ?? ''), answer.data.cancelled?.by ?? '')}</span>
          </section>
        )}
        {answer?.kind === 'missing' && (
          <section role="status" className="grid gap-1.5 border-2 border-ink bg-sheet p-3.5">
            <span className="label-caps justify-self-start bg-field px-[7px] py-[3px] text-ink">{t.notFound}</span>
            <span className="text-sm">{t.notFoundText}</span>
          </section>
        )}
        {answer?.kind === 'failed' && <p className="text-sm text-red-text">{t.failed}</p>}
        <p className="text-xs text-text-muted">{t.note}</p>
      </main>
    </div>
  )
}
