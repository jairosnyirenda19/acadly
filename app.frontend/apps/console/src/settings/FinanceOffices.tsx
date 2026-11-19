import { api, useLoad, type paths } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { read, settle } from '../setup/api'
import { Input } from '../setup/parts'

const t = copy.settings.fees.offices

type Offices = paths['/v1/fees/offices']['get']['responses'][200]['content']['application/json']
type Office = { id: string | null; name: string; offerings: string[]; staff: string[] }

const officesApi = {
  load: () => read(api.GET('/v1/fees/offices')),
  save: (offices: Office[], ownStaff: string[]) =>
    settle(api.PUT('/v1/fees/offices', { body: { offices, own_staff: ownStaff } as never }) as Promise<{ data?: { saved: boolean }; error?: unknown; response: Response }>),
}

/** Who works in one office (D-100): the people named, and a way to add one who holds a fees role. */
function Staff({ people, chosen, onChange, label }: { people: Offices['people']; chosen: string[]; onChange: (next: string[]) => void; label: string }) {
  const name = (id: string) => people.find((p) => p.id === id)?.name ?? ''
  const left = people.filter((p) => !chosen.includes(p.id))

  return (
    <div className="grid gap-1.5">
      <span className="label-caps text-text-muted">{label}</span>
      {chosen.length === 0 ? (
        <p className="flex items-start gap-2 bg-marigold-wash px-3 py-2 text-[14px]">
          <span className="label-caps bg-marigold px-[7px] py-[3px] text-ink">!</span>
          <span>{t.noStaff}</span>
        </p>
      ) : (
        <ul className="border-t border-rule">
          {chosen.map((id) => (
            <li key={id} className="flex min-h-11 items-center gap-3 border-b border-rule">
              <span className="flex-1 font-extrabold">{name(id)}</span>
              <button type="button" className="text-[14px] font-extrabold underline" onClick={() => onChange(chosen.filter((x) => x !== id))}>
                {t.removePerson(name(id))}
              </button>
            </li>
          ))}
        </ul>
      )}
      {left.length > 0 && (
        <label className="flex h-11 max-w-sm items-center border-b-2 border-ink bg-field px-2">
          <span className="sr-only">{t.addPerson}</span>
          <select value="" onChange={(e) => e.target.value !== '' && onChange([...chosen, e.target.value])} className="w-full bg-transparent text-[15px]">
            <option value="">{t.addPerson}</option>
            {left.map((p) => (
              <option key={p.id} value={p.id}>
                {p.name}
              </option>
            ))}
          </select>
        </label>
      )}
    </div>
  )
}

/**
 * Settings › Fees › Finance offices (D-099): one office for the whole school,
 * or the levels at each campus split among named offices. Each office is a
 * place to work in: inside it, only its learners' fees.
 */
export function FinanceOffices() {
  const loaded = useLoad('fees:offices', () => officesApi.load())

  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} rows={4} onRetry={loaded.reload} />

  return <Editor data={loaded.data} onSaved={loaded.reload} />
}

function Editor({ data, onSaved }: { data: Offices; onSaved: () => void }) {
  const named = data.offices.filter((o) => !o.own)
  const [split, setSplit] = useState(named.length > 0)
  const [offices, setOffices] = useState<Office[]>(
    named.length > 0 ? named.map((o) => ({ id: o.id, name: o.name, offerings: o.offerings, staff: o.staff })) : [{ id: null, name: '', offerings: [], staff: [] }],
  )
  const [ownStaff, setOwnStaff] = useState<string[]>(data.offices.find((o) => o.own)?.staff ?? [])
  const [busy, setBusy] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)
  const label = (id: string) => {
    const o = data.offerings.find((x) => x.id === id)
    return o === undefined ? '' : `${o.division} · ${o.campus}`
  }
  const takenBy = (offering: string, except: number) => offices.findIndex((o, i) => i !== except && o.offerings.includes(offering))
  const rest = data.offerings.filter((o) => !offices.some((x) => x.offerings.includes(o.id)))

  const set = (i: number, patch: Partial<Office>) => setOffices(offices.map((o, j) => (j === i ? { ...o, ...patch } : o)))
  const toggle = (i: number, offering: string, on: boolean) => {
    const o = offices[i]
    if (o === undefined) return
    set(i, { offerings: on ? [...o.offerings, offering] : o.offerings.filter((x) => x !== offering) })
  }

  const save = async () => {
    setBusy(true)
    setProblem(null)
    const result = await officesApi.save(split ? offices.map((o) => ({ ...o, name: o.name.trim() })) : [], !split || rest.length > 0 ? ownStaff : [])
    setBusy(false)
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: t.saved })
      onSaved()
    } else setProblem(result.kind === 'invalid' ? (Object.values(result.errors)[0]?.[0] ?? t.failed) : t.failed)
  }

  return (
    <section aria-label={t.title} className="grid max-w-[860px] gap-4 border-2 border-ink bg-sheet px-4 py-3.5">
      <h2 className="type-display text-[26px] leading-none">{t.title}</h2>
      <p className="text-sm text-text-muted">{t.intro}</p>
      {problem !== null && (
        <Notice tone="wrong" title={t.notSaved}>
          {problem}
        </Notice>
      )}
      <fieldset className="grid gap-2">
        <legend className="label-caps mb-1 text-text-muted">{t.how}</legend>
        {([false, true] as const).map((v) => (
          <label key={String(v)} className={`flex min-h-12 items-start gap-3 border-2 px-3 py-2.5 ${split === v ? 'border-ink bg-paper' : 'border-rule'}`}>
            <input type="radio" name="offices" className="mt-1 size-5 accent-ink" checked={split === v} onChange={() => setSplit(v)} />
            <span>
              <strong className="block">{v ? t.split : t.one}</strong>
              <span className="text-sm text-text-muted">{v ? t.splitNote : t.oneNote}</span>
            </span>
          </label>
        ))}
      </fieldset>

      {split && (
        <>
          {offices.map((o, i) => (
            <div key={o.id ?? `new-${i}`} className="grid gap-2.5 border-2 border-ink px-3.5 py-3">
              <div className="flex flex-wrap items-end gap-3">
                <div className="min-w-64 flex-1">
                  <Input label={t.name} value={o.name} onChange={(e) => set(i, { name: e.target.value })} />
                </div>
                <Button variant="secondary" onClick={() => setOffices(offices.filter((_, j) => j !== i))}>
                  {t.remove}
                </Button>
              </div>
              <span className="label-caps text-text-muted">{t.serves}</span>
              <div className="grid gap-1.5 sm:grid-cols-2">
                {data.offerings.map((x) => {
                  const other = takenBy(x.id, i)
                  return (
                    <label key={x.id} className={`flex min-h-11 items-center gap-2.5 text-[15px] ${other >= 0 ? 'text-text-muted' : ''}`}>
                      <input
                        type="checkbox"
                        className="size-5 accent-ink"
                        disabled={other >= 0}
                        checked={o.offerings.includes(x.id)}
                        onChange={(e) => toggle(i, x.id, e.target.checked)}
                      />
                      {label(x.id)}
                      {other >= 0 && <span className="text-sm">{t.servedBy(offices[other]?.name || t.another)}</span>}
                    </label>
                  )
                })}
              </div>
              <Staff people={data.people} chosen={o.staff} onChange={(staff) => set(i, { staff })} label={t.staff} />
            </div>
          ))}
          <div>
            <Button variant="secondary" onClick={() => setOffices([...offices, { id: null, name: '', offerings: [], staff: [] }])}>
              {t.add}
            </Button>
          </div>
          <p className="text-sm">
            <strong>{t.ownOffice}</strong> {rest.length === 0 ? t.ownNone : rest.map((x) => label(x.id)).join(', ')}
          </p>
          {rest.length > 0 && <Staff people={data.people} chosen={ownStaff} onChange={setOwnStaff} label={t.ownStaff} />}
        </>
      )}
      {!split && <Staff people={data.people} chosen={ownStaff} onChange={setOwnStaff} label={t.staff} />}
      <p className="text-sm text-text-muted">{t.staffNote}</p>

      <p className="border-t border-rule pt-2.5 text-sm text-text-muted">{t.note}</p>
      <div>
        <Button busy={busy} onClick={() => void save()}>
          {t.save}
        </Button>
      </div>
    </section>
  )
}
