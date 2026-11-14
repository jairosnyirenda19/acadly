import { getHealth, type Health } from '@acadly/kernel'
import { Button } from '@acadly/ui'
import { useCallback, useEffect, useState } from 'react'

type State = { kind: 'asking' } | { kind: 'answered'; health: Health } | { kind: 'silent' }

/**
 * Stage 0 screen: the console asks the API whether it is alive, through the
 * generated client, and shows the answer in the register-book look. It proves
 * the whole path: contract → client → kernel → screen → tokens.
 */
export function App() {
  const [state, setState] = useState<State>({ kind: 'asking' })

  const ask = useCallback(() => {
    setState({ kind: 'asking' })
    getHealth()
      .then((health) => setState({ kind: 'answered', health }))
      .catch(() => setState({ kind: 'silent' }))
  }, [])

  useEffect(ask, [ask])

  return (
    <div className="flex min-h-screen flex-wrap">
      <aside className="flex flex-[1_1_232px] flex-col bg-ink text-sheet">
        <div className="type-display px-5 pt-5 pb-4 text-[34px]">
          Acadly <span className="text-teal-bright">IQ-X</span>
        </div>
        <div className="border-y border-ink-rule px-5 py-3">
          <div className="label-caps text-on-ink-muted">Stage</div>
          <div className="font-extrabold">0 · Walking skeleton</div>
        </div>
        <nav aria-label="Main" className="py-3">
          <a href="#health" aria-current="page" className="block border-l-4 border-teal-bright py-[11px] pr-5 pl-4 font-extrabold text-sheet no-underline">
            System health
          </a>
        </nav>
      </aside>

      <main className="flex min-w-0 flex-[999_1_560px] flex-col">
        <div className="flex flex-wrap items-end gap-4 px-[clamp(16px,3vw,40px)] pt-7 pb-5">
          <div className="flex-[1_1_320px]">
            <div className="label-caps text-text-muted">The console asks the API</div>
            <h1 className="type-display mt-1 text-[88px]">System health</h1>
          </div>
          <Button variant="secondary" onClick={ask}>
            Ask again
          </Button>
        </div>

        {state.kind === 'asking' && (
          <p className="border-t-2 border-ink px-[clamp(16px,3vw,40px)] py-6 text-text-muted">Asking the API…</p>
        )}

        {state.kind === 'silent' && (
          <div className="border-t-2 border-ink bg-red-wash px-[clamp(16px,3vw,40px)] py-6">
            <div className="label-caps text-red-text">No answer</div>
            <p className="mt-1 font-extrabold">The API did not answer.</p>
            <p className="mt-1">Start it with <span className="ref">php artisan serve</span> in the <span className="ref">api</span> folder. Then ask again.</p>
          </div>
        )}

        {state.kind === 'answered' && <Answer health={state.health} />}
      </main>
    </div>
  )
}

function Answer({ health }: { health: Health }) {
  return (
    <>
      <div className="grid grid-cols-[repeat(auto-fit,minmax(210px,1fr))] border-y-2 border-ink">
        <section className="bg-ink px-[clamp(16px,3vw,40px)] pt-4 pb-5 text-sheet">
          <h2 className="label-caps text-on-ink-muted">The API says</h2>
          <div className="type-numeral text-[96px] text-teal-bright uppercase">{health.status}</div>
          <p className="text-on-ink-soft">{health.service}</p>
        </section>
        <section className="border-l-2 border-ink px-6 pt-4 pb-5">
          <h2 className="label-caps text-text-muted">Modules in this build</h2>
          <div className="type-numeral text-[96px]">{health.modules.length}</div>
          <p className="text-text-muted">Listed in config/acadly.php.</p>
        </section>
        <section className="border-l-2 border-ink px-6 pt-4 pb-5">
          <h2 className="label-caps text-text-muted">Release</h2>
          <div className="type-display pt-3 text-[34px]">{health.version}</div>
        </section>
      </div>

      <section className="ledger flex-1 [--margin:92px]" aria-label="Modules">
        <div className="label-caps flex h-9 items-center border-b-2 border-ink text-text-muted">
          <div className="w-[92px] text-center">Layer</div>
          <div className="pl-3">Module</div>
        </div>
        {health.modules.map((module) => (
          <div key={module.key} className="flex min-h-14 items-center border-b border-rule">
            <div className="label-caps w-[92px] text-center text-text-muted">{module.tier}</div>
            <div className="flex-1 pl-3 text-base font-extrabold">{module.key}</div>
            <div className="mr-5 grid size-11 place-items-center bg-teal text-[26px] font-extrabold text-sheet" role="img" aria-label="Present in the build">
              /
            </div>
          </div>
        ))}
      </section>
    </>
  )
}
