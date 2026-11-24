import { useLoad } from '@acadly/kernel'
import { Button, Notice, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { conductApi, LEVELS, type KindList, type Level } from '../conduct/api'
import { EntryTag } from '../learners/record/ConductPart'
import { copy } from '../copy'
import { Input, Select } from '../setup/parts'
import { PageHead, SettingsTabs } from './parts'

const t = copy.settings.conduct
const c = copy.conduct

/** Settings › Conduct (the approved design): kinds, levels, when families are told, learners to watch. */
export function ConductSettingsScreen() {
  const loaded = useLoad('conduct:kinds', () => conductApi.kinds())

  if (loaded.data === undefined) return <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} rows={6} onRetry={loaded.reload} />

  return <Editor data={loaded.data} onSaved={loaded.reload} />
}

function Editor({ data, onSaved }: { data: KindList; onSaved: () => void }) {
  const [kinds, setKinds] = useState(() => data.kinds.map((k) => ({ ...k, id: k.id as string | null, level: k.level as Level | null })))
  const [watch, setWatch] = useState({
    incidents: String(data.watch.incidents),
    days: String(data.watch.days),
    open: data.watch.open_serious,
    missedOn: data.watch.missed_incidents > 0,
    missedIncidents: String(Math.max(1, data.watch.missed_incidents)),
    missedLessons: String(data.watch.missed_lessons),
    missedDays: String(data.watch.missed_days),
    portal: data.watch.portal,
  })
  const [busy, setBusy] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)
  const set = (i: number, patch: Partial<(typeof kinds)[number]>) => setKinds(kinds.map((k, j) => (j === i ? { ...k, ...patch } : k)))

  const saveKinds = async () => {
    setBusy(true)
    setProblem(null)
    const result = await conductApi.saveKinds(kinds.map((k) => ({ id: k.id, name: k.name.trim(), commendation: k.commendation, level: k.commendation ? null : (k.level ?? 'minor'), always: k.always, retired: k.retired })))
    setBusy(false)
    if (result.kind === 'saved') {
      slips.done({ word: copy.slips.saved, title: t.saved })
      onSaved()
    } else setProblem(result.kind === 'invalid' ? (Object.values(result.errors)[0]?.[0] ?? t.failed) : t.failed)
  }

  const saveWatch = async () => {
    setBusy(true)
    const result = await conductApi.saveWatch({
      incidents: Number(watch.incidents),
      days: Number(watch.days),
      open_serious: watch.open,
      missed_incidents: watch.missedOn ? Number(watch.missedIncidents) : 0,
      missed_lessons: Number(watch.missedLessons),
      missed_days: Number(watch.missedDays),
      portal: watch.portal,
    })
    setBusy(false)
    if (result.kind === 'saved') slips.done({ word: copy.slips.saved, title: t.saved })
    else slips.wrong({ word: t.notSaved, title: result.kind === 'invalid' ? (Object.values(result.errors)[0]?.[0] ?? t.failed) : t.failed })
  }

  return (
    <>
      <SettingsTabs />
      <PageHead kicker={t.kicker} title={t.title} intro={t.intro} />
      <div className="grid items-start gap-x-8 gap-y-6 px-[clamp(16px,3vw,40px)] pb-8 xl:grid-cols-[minmax(0,1fr)_420px]">
        <section aria-label={t.kindsTitle} className="grid gap-3 border-2 border-ink bg-sheet px-4 py-3.5">
          <h2 className="type-display text-[26px] leading-none">{t.kindsTitle}</h2>
          {problem !== null && (
            <Notice tone="wrong" title={t.notSaved}>
              {problem}
            </Notice>
          )}
          {kinds.map((k, i) => (
            <div key={k.id ?? `new-${i}`} className={`grid items-end gap-3 border-b border-rule pb-2.5 sm:grid-cols-[minmax(0,1fr)_150px_140px] ${k.retired ? 'opacity-70' : ''}`}>
              <Input label={t.name} value={k.name} onChange={(e) => set(i, { name: e.target.value })} />
              <Select label={t.is} value={k.commendation ? 'c' : 'i'} onChange={(e) => set(i, { commendation: e.target.value === 'c', level: e.target.value === 'c' ? null : (k.level ?? 'minor') })}>
                <option value="i">{c.incident}</option>
                <option value="c">{c.commendation}</option>
              </Select>
              {k.commendation ? (
                <div className="pb-2.5">
                  <EntryTag entry={{ commendation: true, level: null }} />
                </div>
              ) : (
                <Select label={t.startsAt} value={k.level ?? 'minor'} onChange={(e) => set(i, { level: e.target.value as Level })}>
                  {LEVELS.map((l) => (
                    <option key={l} value={l}>
                      {c.levels[l]}
                    </option>
                  ))}
                </Select>
              )}
              <div className="col-span-full flex flex-wrap gap-x-5">
                <label className="flex min-h-10 items-center gap-2 text-sm">
                  <input type="checkbox" className="size-5 accent-ink" checked={k.always || k.level === 'serious'} disabled={!k.commendation && k.level === 'serious'} onChange={(e) => set(i, { always: e.target.checked })} />
                  {t.always}
                </label>
                <label className="flex min-h-10 items-center gap-2 text-sm">
                  <input type="checkbox" className="size-5 accent-ink" checked={k.retired} onChange={(e) => set(i, { retired: e.target.checked })} />
                  {t.retired}
                </label>
              </div>
            </div>
          ))}
          <div className="flex flex-wrap gap-2.5">
            <Button variant="secondary" onClick={() => setKinds([...kinds, { id: null, name: '', commendation: false, level: 'minor', always: false, retired: false }])}>
              {t.addKind}
            </Button>
            <Button busy={busy} onClick={() => void saveKinds()}>
              {t.saveKinds}
            </Button>
          </div>
        </section>

        <aside className="grid gap-5">
          <section className="grid gap-2.5 border-2 border-ink bg-sheet px-4 py-3.5">
            <h2 className="type-display text-[26px] leading-none">{t.byLevel}</h2>
            {LEVELS.map((l) => (
              <div key={l} className="flex items-center gap-2.5 text-sm">
                <EntryTag entry={{ commendation: false, level: l }} />
                <span>{t.levelRules[l]}</span>
              </div>
            ))}
            <p className="text-[13px] text-text-muted">{t.levelNote}</p>
          </section>
          <section className="grid gap-2.5 border-2 border-ink bg-sheet px-4 py-3.5">
            <h2 className="type-display text-[26px] leading-none">{t.watchTitle}</h2>
            <div className="flex flex-wrap items-end gap-2 text-[15px]">
              <div className="w-20">
                <Input label={c.incidents} inputMode="numeric" value={watch.incidents} onChange={(e) => setWatch({ ...watch, incidents: e.target.value })} />
              </div>
              <span className="pb-3">{t.incidentsIn}</span>
              <div className="w-20">
                <Input label={t.daysWord} inputMode="numeric" value={watch.days} onChange={(e) => setWatch({ ...watch, days: e.target.value })} />
              </div>
              <span className="pb-3">{t.daysWord}</span>
            </div>
            <label className="flex items-center gap-2 text-[15px]">
              <input type="checkbox" className="size-5 accent-ink" checked={watch.open} onChange={(e) => setWatch({ ...watch, open: e.target.checked })} />
              {t.openSerious}
            </label>
            <fieldset className="grid gap-2 border-t border-rule pt-2.5">
              <legend className="sr-only">{t.missedTitle}</legend>
              <label className="flex items-center gap-2 text-[15px] font-extrabold">
                <input type="checkbox" className="size-5 accent-ink" checked={watch.missedOn} onChange={(e) => setWatch({ ...watch, missedOn: e.target.checked })} />
                {t.missedTitle}
              </label>
              {watch.missedOn && (
                <div className="flex flex-wrap items-end gap-2 text-[15px]">
                  <div className="w-20">
                    <Input label={c.incidents} inputMode="numeric" value={watch.missedIncidents} onChange={(e) => setWatch({ ...watch, missedIncidents: e.target.value })} />
                  </div>
                  <span className="pb-3">{t.missedAnd}</span>
                  <div className="w-20">
                    <Input label={t.missedLessons} inputMode="numeric" value={watch.missedLessons} onChange={(e) => setWatch({ ...watch, missedLessons: e.target.value })} />
                  </div>
                  <span className="pb-3">{t.missedIn}</span>
                  <div className="w-20">
                    <Input label={t.daysWord} inputMode="numeric" value={watch.missedDays} onChange={(e) => setWatch({ ...watch, missedDays: e.target.value })} />
                  </div>
                  <span className="pb-3">{t.daysWord}</span>
                </div>
              )}
              <p className="text-[13px] text-text-muted">{t.missedNote}</p>
            </fieldset>
            <p className="text-[13px] text-text-muted">{t.watchNote}</p>
            <div className="grid gap-1.5 border-t border-rule pt-2.5">
              <h3 className="text-[15px] font-extrabold">{t.portalTitle}</h3>
              <label className="flex items-center gap-2 text-[15px]">
                <input type="checkbox" className="size-5 accent-ink" checked={watch.portal} onChange={(e) => setWatch({ ...watch, portal: e.target.checked })} />
                {t.portalField}
              </label>
              <p className="text-[13px] text-text-muted">{t.portalNote}</p>
            </div>
            <div>
              <Button variant="secondary" busy={busy} onClick={() => void saveWatch()}>
                {t.saveWatch}
              </Button>
            </div>
          </section>
        </aside>
      </div>
    </>
  )
}
