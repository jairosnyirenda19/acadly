import { useLoad } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { feesApi, kwachaText, money, parseKwacha, type FeeItem, type ShopItem, type TermFees } from '../fees/api'
import { Input, Select } from '../setup/parts'
import { day } from './api'
import { FeesPortalSettings } from './FeesPortalSettings'
import { FinanceOffices } from './FinanceOffices'
import { PageHead, Tag, SettingsTabs } from './parts'

const t = copy.settings.fees
type View = 'term' | 'items' | 'shop' | 'offices' | 'portal'

/** Settings › Fees (the approved design): the fees of a term by level, the plan, billing; the items; the shop list. */
export function FeesSettingsScreen() {
  const [view, setView] = useState<View>('term')

  return (
    <>
      <SettingsTabs />
      <PageHead kicker={t.kicker} title={t.title} intro={t.intro} />
      <nav aria-label={t.title} className="flex gap-6 border-b-2 border-ink px-[clamp(16px,3vw,40px)] text-[15px]">
        {(['term', 'items', 'shop', 'offices', 'portal'] as View[]).map((v) => (
          <button
            key={v}
            type="button"
            aria-current={view === v ? 'page' : undefined}
            onClick={() => setView(v)}
            className={`min-h-11 border-b-4 px-0.5 ${view === v ? 'border-teal font-extrabold' : 'border-transparent font-medium text-text-muted'}`}
          >
            {t.tabs[v]}
          </button>
        ))}
      </nav>
      <div className="px-[clamp(16px,3vw,40px)] pt-5 pb-8">
        {view === 'term' && <TermView />}
        {view === 'items' && <ItemsView />}
        {view === 'shop' && <ShopView />}
        {view === 'offices' && <FinanceOffices />}
        {view === 'portal' && <FeesPortalSettings />}
      </div>
    </>
  )
}

function TermView() {
  const terms = useLoad('fees:terms', () => feesApi.terms())
  const [chosen, setChosen] = useState<string>('')

  if (terms.data === undefined) return <Waiting phase={terms.phase} what={t.title} labels={copy.loading} rows={4} onRetry={terms.reload} />

  const list = terms.data.terms
  if (list.length === 0) return <p className="text-sm text-text-muted">{t.noTerms}</p>
  const key = (x: { period_id: string; division_id: string }) => `${x.period_id}:${x.division_id}`
  const current = list.find((x) => key(x) === chosen) ?? list.find((x) => x.state !== 'billed') ?? list[0]
  if (current === undefined) return null

  return (
    <div className="grid gap-5">
      <div className="flex flex-wrap items-end gap-3">
        <div className="w-full min-w-0 max-w-[420px] sm:w-auto sm:min-w-[280px]">
          <Select label={t.term} value={key(current)} onChange={(e) => setChosen(e.target.value)}>
            {list.map((x) => (
              <option key={key(x)} value={key(x)}>
                {x.name} · {x.year} · {x.division}
              </option>
            ))}
          </Select>
        </div>
        <span className="ml-auto">
          <Tag tone={current.state === 'billed' ? 'done' : 'attention'}>
            {current.state === 'billed' ? '✓ ' : '! '}
            {t.states[current.state] ?? current.state}
          </Tag>
        </span>
      </div>
      <TermForm key={key(current)} period={current.period_id} division={current.division_id} onChanged={() => (setChosen(key(current)), terms.reload())} />
    </div>
  )
}

function TermForm({ period, division, onChanged }: { period: string; division: string; onChanged: () => void }) {
  const loaded = useLoad(`fees:term:${period}:${division}`, () => feesApi.term(period, division))
  const data = loaded.data

  if (data === undefined) return <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} rows={6} onRetry={loaded.reload} />

  return <TermEditor data={data} onSaved={() => (loaded.reload(), onChanged())} />
}

function TermEditor({ data, onSaved }: { data: TermFees; onSaved: () => void }) {
  const items = data.items.filter((i) => !i.retired)
  const cell = (item: string, level: string) => `${item}:${level}`
  const [amounts, setAmounts] = useState<Record<string, string>>(() =>
    Object.fromEntries(data.amounts.map((a) => [cell(a.item_id, a.level_code), a.amount === 0 ? '' : kwachaText(a.amount)])),
  )
  const [plan, setPlan] = useState(() =>
    data.plan.length > 0
      ? data.plan.map((p) => ({ label: p.label, due_on: p.due_on, share: String(p.share_bp / 100) }))
      : [{ label: '', due_on: data.term.starts_on, share: '100' }],
  )
  // A college's amounts by programme and mode (D-116): "item:programme:mode:level" → text; a level of 'credit' prices per credit.
  const pcell = (item: string, programme: string, mode: string, level: string) => `${item}:${programme}:${mode}:${level}`
  const [byProgramme, setByProgramme] = useState<Record<string, string>>(() =>
    Object.fromEntries(data.programme_amounts.map((a) => [pcell(a.item_id, a.programme_id, a.mode, a.level_code), kwachaText(a.amount)])),
  )
  const [perCredit, setPerCredit] = useState<Record<string, boolean>>(() =>
    Object.fromEntries(data.programme_amounts.filter((a) => a.level_code === 'credit').map((a) => [`${a.item_id}:${a.programme_id}:${a.mode}`, true])),
  )
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [busy, setBusy] = useState(false)
  const locked = data.billed
  const shareSum = plan.reduce((a, p) => a + (Number(p.share) || 0), 0)
  const set = (i: number, key: 'label' | 'due_on' | 'share', value: string) => setPlan(plan.map((p, j) => (j === i ? { ...p, [key]: value } : p)))

  const save = async () => {
    setBusy(true)
    setErrors({})
    const result = await feesApi.saveTerm(data.term.period_id, data.term.division_id, {
      amounts: items.flatMap((i) => data.levels.map((l) => ({ item_id: i.id, level_code: l.code, amount: parseKwacha(amounts[cell(i.id, l.code)] ?? '') ?? 0 }))).filter((a) => a.amount > 0),
      plan: plan.map((p) => ({ label: p.label.trim(), due_on: p.due_on, share_bp: Math.round((Number(p.share) || 0) * 100) })),
      programme_amounts: Object.entries(byProgramme).flatMap(([key, text]) => {
        const [item_id = '', programme_id = '', mode = '', level_code = ''] = key.split(':')
        const amount = parseKwacha(text)
        const credit = perCredit[`${item_id}:${programme_id}:${mode}`] === true

        return amount === null || text.trim() === '' || (level_code === 'credit') !== credit ? [] : [{ item_id, programme_id, mode, level_code, amount }]
      }),
    })
    setBusy(false)
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: t.saved })
      onSaved()
    } else if (result.kind === 'invalid') setErrors(result.errors)
    else slips.wrong({ word: t.notSaved, title: t.failed })
  }

  const bill = async () => {
    setBusy(true)
    const result = await feesApi.bill(data.term.period_id, data.term.division_id)
    setBusy(false)
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: t.billedDone(result.data.learners, money(result.data.total)) })
      onSaved()
    } else slips.wrong({ word: t.notSaved, title: result.kind === 'invalid' ? (Object.values(result.errors)[0]?.[0] ?? t.failed) : t.failed })
  }

  const dayLearner = (level: string) => items.filter((i) => !i.optional).reduce((a, i) => a + (parseKwacha(amounts[cell(i.id, level)] ?? '') ?? 0), 0)
  const firstError = Object.values(errors)[0]?.[0]

  return (
    <div className="grid grid-cols-[minmax(0,1fr)] items-start gap-x-8 gap-y-6 xl:grid-cols-[minmax(0,1fr)_400px]">
      <section aria-label={t.byLevel} className="border-2 border-ink bg-sheet px-4 pt-3 pb-4">
        <h2 className="type-display mb-2 text-[26px] leading-none">{t.byLevel}</h2>
        {firstError !== undefined && (
          <div className="mb-3">
            <Notice tone="wrong" title={t.notSaved}>
              {firstError}
            </Notice>
          </div>
        )}
        <div role="region" aria-label={t.byLevel} tabIndex={0} className="overflow-x-auto">
          <table className="w-full min-w-[560px] border-collapse">
            <thead>
              <tr>
                <th scope="col" className="label-caps border-b-2 border-ink py-2 pr-3 text-left text-text-muted">
                  {t.item}
                </th>
                {data.levels.map((l) => (
                  <th key={l.code} scope="col" className="label-caps border-b-2 border-ink px-2 py-2 text-right text-text-muted">
                    {l.label}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {items.map((i) => (
                <tr key={i.id}>
                  <th scope="row" className="border-b border-rule py-2 pr-3 text-left align-middle">
                    <span className="block text-[15px] font-extrabold">{i.name}</span>
                    <span className="text-xs font-normal text-text-muted">{i.optional ? t.optionalLearners : t.everyLearner}</span>
                  </th>
                  {data.levels.map((l) => (
                    <td key={l.code} className="border-b border-rule px-2 py-1.5">
                      <input
                        aria-label={`${i.name} · ${l.label}`}
                        inputMode="decimal"
                        disabled={locked}
                        value={amounts[cell(i.id, l.code)] ?? ''}
                        onChange={(e) => setAmounts({ ...amounts, [cell(i.id, l.code)]: e.target.value })}
                        className="ref h-10 w-full min-w-[90px] border-0 border-b-2 border-ink bg-field px-2 text-right text-sm outline-none focus:border-teal disabled:border-rule disabled:text-text-muted"
                      />
                    </td>
                  ))}
                </tr>
              ))}
              <tr>
                <th scope="row" className="py-2.5 pr-3 text-left font-extrabold">
                  {t.dayLearner}
                </th>
                {data.levels.map((l) => (
                  <td key={l.code} className="ref px-2 py-2.5 text-right font-extrabold">
                    {money(dayLearner(l.code))}
                  </td>
                ))}
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      {data.programmes.length > 0 && (
        <ByProgramme
          data={data}
          items={items}
          locked={locked}
          value={(item, programme, mode, level) => byProgramme[pcell(item, programme, mode, level)] ?? ''}
          onValue={(item, programme, mode, level, text) => setByProgramme({ ...byProgramme, [pcell(item, programme, mode, level)]: text })}
          perCredit={(item, programme, mode) => perCredit[`${item}:${programme}:${mode}`] === true}
          onPerCredit={(item, programme, mode, on) => setPerCredit({ ...perCredit, [`${item}:${programme}:${mode}`]: on })}
        />
      )}

      <aside className="grid gap-5 xl:col-start-2 xl:row-start-1 xl:row-span-2">
        <section aria-label={t.plan} className="grid gap-3 border-2 border-ink bg-sheet px-4 py-3.5">
          <h2 className="type-display text-[26px] leading-none">{t.plan}</h2>
          {plan.map((p, i) => (
            <div key={i} className="grid grid-cols-[minmax(0,1fr)_140px_76px] items-end gap-2">
              <Input label={t.planName} value={p.label} disabled={locked} onChange={(e) => set(i, 'label', e.target.value)} />
              <Input label={t.planDate} type="date" value={p.due_on} disabled={locked} onChange={(e) => set(i, 'due_on', e.target.value)} />
              <Input label={t.planShare} inputMode="decimal" value={p.share} disabled={locked} onChange={(e) => set(i, 'share', e.target.value)} />
              {!locked && plan.length > 1 && (
                <button type="button" onClick={() => setPlan(plan.filter((_, j) => j !== i))} className="col-span-full min-h-9 justify-self-start text-[13px] font-bold underline">
                  {t.remove}
                </button>
              )}
            </div>
          ))}
          {!locked && plan.length < 6 && (
            <button type="button" onClick={() => setPlan([...plan, { label: '', due_on: data.term.ends_on, share: '' }])} className="min-h-11 justify-self-start text-sm font-extrabold underline">
              {t.addInstalment}
            </button>
          )}
          <p className="flex items-center gap-2 text-[13px]">
            <Tag tone={Math.abs(shareSum - 100) < 0.001 ? 'done' : 'wrong'}>{Math.abs(shareSum - 100) < 0.001 ? '✓' : '✕'}</Tag> {t.sharesAdd(String(shareSum))}
          </p>
          <p className="text-[13px] text-text-muted">{t.planNote}</p>
          {!locked && (
            <div>
              <Button busy={busy} variant="secondary" onClick={() => void save()}>
                {t.save}
              </Button>
            </div>
          )}
          {locked && <p className="text-sm">{t.billedNote(data.billed_learners)}</p>}
        </section>

        <section className="grid gap-2 bg-ink px-4 py-3.5 text-sheet">
          <div className="label-caps text-on-ink-muted">{t.billing(`${data.term.name} · ${data.term.division}`)}</div>
          <p className="text-[15px]">{data.plan.length === 0 ? t.setFirst : data.to_bill.learners > 0 ? t.toBill(data.to_bill.learners, money(data.to_bill.total)) : t.nobodyToBill}</p>
          <p className="text-[13px] text-on-ink-soft">
            {day(data.term.starts_on)} – {day(data.term.ends_on)}
          </p>
          <button
            type="button"
            disabled={busy || data.to_bill.learners === 0 || data.plan.length === 0}
            onClick={() => void bill()}
            className="min-h-12 bg-teal-bright text-base font-extrabold text-ink disabled:bg-disabled disabled:text-text-muted"
          >
            {data.billed ? t.billAgain : t.bill(data.term.name)}
          </button>
          <p className="text-xs text-on-ink-soft">{t.billHelp}</p>
        </section>
      </aside>
    </div>
  )
}

/** A college's fees by programme and mode of study, per year of study or per credit (D-116), for one fee item at a time. */
function ByProgramme({
  data,
  items,
  locked,
  value,
  onValue,
  perCredit,
  onPerCredit,
}: {
  data: TermFees
  items: FeeItem[]
  locked: boolean
  value: (item: string, programme: string, mode: string, level: string) => string
  onValue: (item: string, programme: string, mode: string, level: string, text: string) => void
  perCredit: (item: string, programme: string, mode: string) => boolean
  onPerCredit: (item: string, programme: string, mode: string, on: boolean) => void
}) {
  const [item, setItem] = useState(() => data.programme_amounts[0]?.item_id ?? items.find((i) => !i.optional)?.id ?? items[0]?.id ?? '')
  const rows = data.programmes.flatMap((p) => ['full_time', 'part_time'].map((mode) => ({ programme: p, mode })))
  const field = 'ref h-10 w-full min-w-[90px] border-0 border-b-2 border-ink bg-field px-2 text-right text-sm outline-none focus:border-teal disabled:border-rule disabled:text-text-muted'

  return (
    <section aria-label={t.byProgramme} className="border-2 border-ink bg-sheet px-4 pt-3 pb-4">
      <h2 className="type-display mb-1 text-[26px] leading-none">{t.byProgramme}</h2>
      <p className="mb-3 max-w-[70ch] text-[13px] text-text-muted">{t.byProgrammeIntro}</p>
      <div className="mb-3 max-w-[320px]">
        <Select label={t.programmeItem} value={item} onChange={(e) => setItem(e.target.value)}>
          {items.map((i) => (
            <option key={i.id} value={i.id}>
              {i.name}
            </option>
          ))}
        </Select>
      </div>
      <div role="region" aria-label={t.byProgramme} tabIndex={0} className="overflow-x-auto">
        <table className="w-full min-w-[720px] border-collapse">
          <thead>
            <tr>
              <th scope="col" className="label-caps border-b-2 border-ink py-2 pr-3 text-left text-text-muted">
                {t.programme}
              </th>
              <th scope="col" className="label-caps border-b-2 border-ink px-2 py-2 text-left text-text-muted">
                {t.mode}
              </th>
              <th scope="col" className="label-caps border-b-2 border-ink px-2 py-2 text-left text-text-muted">
                {t.perCredit}
              </th>
              {data.levels.map((l) => (
                <th key={l.code} scope="col" className="label-caps border-b-2 border-ink px-2 py-2 text-right text-text-muted">
                  {l.label}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.map(({ programme: p, mode }) => {
              const credit = perCredit(item, p.id, mode)
              const name = `${p.name} · ${t.modes[mode] ?? mode}`

              return (
                <tr key={`${p.id}:${mode}`}>
                  <th scope="row" className="min-w-[220px] border-b border-rule py-2 pr-3 text-left align-middle">
                    <span className="block text-[15px] font-extrabold">{p.name}</span>
                    <span className="ref text-xs font-normal text-text-muted">{p.code}</span>
                  </th>
                  <td className="border-b border-rule px-2 py-1.5 text-sm">{t.modes[mode] ?? mode}</td>
                  <td className="border-b border-rule px-2 py-1.5">
                    <input
                      type="checkbox"
                      aria-label={`${name} · ${t.perCredit}`}
                      checked={credit}
                      disabled={locked}
                      onChange={(e) => onPerCredit(item, p.id, mode, e.target.checked)}
                      className="size-5 accent-ink"
                    />
                  </td>
                  {credit ? (
                    <td colSpan={data.levels.length} className="border-b border-rule px-2 py-1.5">
                      <input
                        aria-label={t.perCreditField(name)}
                        inputMode="decimal"
                        disabled={locked}
                        value={value(item, p.id, mode, 'credit')}
                        onChange={(e) => onValue(item, p.id, mode, 'credit', e.target.value)}
                        className={`${field} max-w-[160px]`}
                      />
                    </td>
                  ) : (
                    data.levels.map((l) => (
                      <td key={l.code} className="border-b border-rule px-2 py-1.5">
                        <input
                          aria-label={`${name} · ${l.label}`}
                          inputMode="decimal"
                          disabled={locked}
                          value={value(item, p.id, mode, l.code)}
                          onChange={(e) => onValue(item, p.id, mode, l.code, e.target.value)}
                          className={field}
                        />
                      </td>
                    ))
                  )}
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
      <p className="mt-3 max-w-[70ch] text-[13px] text-text-muted">{t.perCreditNote}</p>
    </section>
  )
}

function ItemsView() {
  const loaded = useLoad('fees:items', () => feesApi.items())

  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={t.itemsTitle} labels={copy.loading} rows={4} onRetry={loaded.reload} />

  return <ItemsEditor initial={loaded.data.items} />
}

function ItemsEditor({ initial }: { initial: FeeItem[] }) {
  const [items, setItems] = useState<(Omit<FeeItem, 'id'> & { id: string | null })[]>(initial)
  const [busy, setBusy] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)

  const save = async () => {
    setBusy(true)
    setProblem(null)
    const result = await feesApi.saveItems(items.map((i) => ({ id: i.id, name: i.name.trim(), optional: i.optional, retired: i.retired })))
    setBusy(false)
    if (result.kind === 'saved') {
      setItems(result.data.items)
      slips.done({ word: copy.slips.saved, title: t.saveItems })
    } else setProblem(result.kind === 'invalid' ? (Object.values(result.errors)[0]?.[0] ?? t.failed) : t.failed)
  }

  const set = (i: number, patch: Partial<Omit<FeeItem, 'id'>>) => setItems(items.map((x, j) => (j === i ? { ...x, ...patch } : x)))

  return (
    <section aria-label={t.itemsTitle} className="grid max-w-[760px] gap-3 border-2 border-ink bg-sheet px-4 py-3.5">
      <h2 className="type-display text-[26px] leading-none">{t.itemsTitle}</h2>
      <p className="text-sm text-text-muted">{t.itemsNote}</p>
      {problem !== null && (
        <Notice tone="wrong" title={t.notSaved}>
          {problem}
        </Notice>
      )}
      {items.map((item, i) => (
        <div key={item.id ?? `new-${i}`} className={`grid grid-cols-[minmax(0,1fr)_auto_auto] items-end gap-3 border-b border-rule pb-2.5 ${item.retired ? 'opacity-70' : ''}`}>
          <Input label={t.itemName} value={item.name} onChange={(e) => set(i, { name: e.target.value })} />
          <label className="flex min-h-11 items-center gap-2 text-sm">
            <input type="checkbox" className="size-5 accent-ink" checked={item.optional} onChange={(e) => set(i, { optional: e.target.checked })} />
            {t.optional}
          </label>
          <label className="flex min-h-11 items-center gap-2 text-sm">
            <input type="checkbox" className="size-5 accent-ink" checked={item.retired} onChange={(e) => set(i, { retired: e.target.checked })} />
            {t.retired}
          </label>
        </div>
      ))}
      <div className="flex flex-wrap gap-2.5">
        <Button variant="secondary" onClick={() => setItems([...items, { id: null, name: '', optional: false, retired: false }])}>
          {t.addItem}
        </Button>
        <Button busy={busy} onClick={() => void save()}>
          {t.saveItems}
        </Button>
      </div>
    </section>
  )
}

function ShopView() {
  const loaded = useLoad('fees:shop', () => feesApi.shop())

  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={t.shopTitle} labels={copy.loading} rows={4} onRetry={loaded.reload} />

  return <ShopEditor initial={loaded.data.items} onSaved={loaded.reload} />
}

function ShopEditor({ initial, onSaved }: { initial: ShopItem[]; onSaved: () => void }) {
  const [items, setItems] = useState(() => initial.map((i) => ({ id: i.id as string | null, name: i.name, size: i.size ?? '', price: kwachaText(i.price), retired: i.retired })))
  const [busy, setBusy] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)
  const set = (i: number, patch: Partial<(typeof items)[number]>) => setItems(items.map((x, j) => (j === i ? { ...x, ...patch } : x)))

  const save = async () => {
    setBusy(true)
    setProblem(null)
    const result = await feesApi.saveShop(items.map((i) => ({ id: i.id, name: i.name.trim(), size: i.size.trim() === '' ? null : i.size.trim(), price: parseKwacha(i.price) ?? 0, retired: i.retired })))
    setBusy(false)
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: t.saveShop })
      onSaved()
    } else setProblem(result.kind === 'invalid' ? (Object.values(result.errors)[0]?.[0] ?? t.failed) : t.failed)
  }

  return (
    <section aria-label={t.shopTitle} className="grid max-w-[860px] gap-3 border-2 border-ink bg-sheet px-4 py-3.5">
      <h2 className="type-display text-[26px] leading-none">{t.shopTitle}</h2>
      <p className="text-sm text-text-muted">{t.shopNote}</p>
      {problem !== null && (
        <Notice tone="wrong" title={t.notSaved}>
          {problem}
        </Notice>
      )}
      {items.map((item, i) => (
        <div key={item.id ?? `new-${i}`} className={`grid grid-cols-1 items-end gap-3 border-b border-rule pb-2.5 sm:grid-cols-[minmax(0,1fr)_120px_140px_auto] ${item.retired ? 'opacity-70' : ''}`}>
          <Input label={t.shopName} value={item.name} onChange={(e) => set(i, { name: e.target.value })} />
          <Input label={t.shopSize} value={item.size} onChange={(e) => set(i, { size: e.target.value })} />
          <Input label={t.shopPrice} inputMode="decimal" value={item.price} onChange={(e) => set(i, { price: e.target.value })} />
          <label className="flex min-h-11 items-center gap-2 text-sm">
            <input type="checkbox" className="size-5 accent-ink" checked={item.retired} onChange={(e) => set(i, { retired: e.target.checked })} />
            {t.retired}
          </label>
        </div>
      ))}
      <div className="flex flex-wrap gap-2.5">
        <Button variant="secondary" onClick={() => setItems([...items, { id: null, name: '', size: '', price: '', retired: false }])}>
          {t.addShopItem}
        </Button>
        <Button busy={busy} onClick={() => void save()}>
          {t.saveShop}
        </Button>
      </div>
    </section>
  )
}
