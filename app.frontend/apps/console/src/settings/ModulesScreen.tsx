import { api, contextChanged, useLoad, type paths } from '@acadly/kernel'
import { Button, slips, Waiting } from '@acadly/ui'
import { useState } from 'react'
import { copy } from '../copy'
import { errorFor, read, settle } from '../setup/api'
import { lessonsApi } from '../registers/api'
import { Input } from '../setup/parts'
import { PageHead, SettingsTabs, SmallButton, Tag } from './parts'

const t = copy.modules
const s = copy.settings

type ModulesState = paths['/v1/modules']['get']['responses'][200]['content']['application/json']
type ModuleRow = ModulesState['modules'][number]

const COLS = 'md:grid-cols-[minmax(140px,0.8fr)_minmax(240px,1.6fr)_minmax(160px,1fr)_minmax(200px,1.1fr)_120px]'

const day = (iso: string) => new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
const moment = (iso: string) => `${day(iso)} ${new Date(iso).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })}`

/** What the school uses (the approved design): each module, its state, and switching an optional one with a reason. */
export function ModulesScreen() {
  const loaded = useLoad('modules', () => read(api.GET('/v1/modules')))
  const [chosen, setChosen] = useState<string | null>(null)
  const state = loaded.data

  if (state === undefined) {
    return (
      <>
        <SettingsTabs current="modules" />
        <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} rows={6} onRetry={loaded.reload} />
      </>
    )
  }

  const module = state.modules.find((m) => m.key === chosen) ?? null

  return (
    <>
      <SettingsTabs current="modules" />
      <PageHead kicker={t.kicker} title={t.title} intro={t.intro} />
      <div className="mx-3 mt-4 mb-4 flex flex-wrap items-center gap-x-4 gap-y-2 border-2 border-ink bg-sheet px-4 py-3 md:mx-[clamp(16px,3vw,40px)]">
        <Tag tone={state.licence.status === 'valid' || state.licence.status === 'development' ? 'done' : 'attention'}>{t.licence[state.licence.status]}</Tag>
        <span className="text-sm font-bold">{t.licensee(state.licence.licensee, state.licence.expires_at !== null ? day(state.licence.expires_at) : null)}</span>
        <span className="ml-auto text-[13px] text-text-muted">{t.outside}</span>
      </div>
      <div className="flex flex-1 flex-wrap items-stretch border-t-2 border-ink">
        <section className="min-w-0 flex-[3_1_640px] bg-sheet px-3 pt-4 pb-6 md:px-[clamp(16px,3vw,40px)]">
          <div className={`label-caps hidden gap-3 border-b-2 border-ink px-4 pb-2 text-text-muted md:grid ${COLS}`}>
            <span>{t.colModule}</span>
            <span>{t.colWhat}</span>
            <span>{t.colNeeds}</span>
            <span>{t.colState}</span>
            <span />
          </div>
          {state.modules.map((m) => (
            <Row key={m.key} module={m} chosen={m.key === chosen} onChoose={() => setChosen(chosen === m.key ? null : m.key)} />
          ))}
          {state.modules.some((m) => m.key === 'attendance' && m.state === 'active') && <Levels />}
        </section>
        <section className="min-w-0 flex-[1_1_340px] border-ink bg-paper px-3 pt-4 pb-6 max-[959px]:border-t-2 md:px-[clamp(16px,3vw,32px)] min-[960px]:border-l-2">
          {module !== null && module.tier === 'optional' && (
            <SwitchPanel
              key={module.key}
              module={module}
              onCancel={() => setChosen(null)}
              onDone={(on) => {
                setChosen(null)
                loaded.reload()
                contextChanged()
                slips.done({ word: copy.slips.saved, title: on ? t.switchedOnSlip(module.name) : t.switchedOffSlip(module.name) })
              }}
            />
          )}
          <div className={module !== null ? 'mt-6 border-t-2 border-ink pt-3.5' : ''}>
            <div className="label-caps mb-2 text-text-muted">{t.history}</div>
            {state.history.length === 0 && <p className="text-sm text-text-muted">{t.noHistory}</p>}
            {state.history.map((h, i) => (
              <div key={i} className="border-b border-rule py-2 text-sm leading-normal">
                <span className="ref text-xs text-text-muted">{moment(h.at)}</span>
                <br />
                <strong>{t.historyLine(h.name, h.enabled)}</strong>
                {h.by !== null && <> · {h.by}</>}
                <br />
                <span className="text-text-muted">“{h.reason}”</span>
              </div>
            ))}
          </div>
        </section>
      </div>
    </>
  )
}

function Row({ module: m, chosen, onChoose }: { module: ModuleRow; chosen: boolean; onChoose: () => void }) {
  const core = m.tier === 'core'
  const tone = core ? 'locked' : m.state === 'active' ? 'done' : m.state === 'switched_off' ? 'plain' : 'plain'
  const last = m.last_switch
  const note = core
    ? t.coreNote
    : m.state === 'not_licensed'
      ? t.notLicensedNote
      : last !== null
        ? (last.enabled ? t.switchedOn : t.switchedOff)(day(last.at), last.by)
        : ''
  const canSwitch = !core && (m.state === 'active' || m.state === 'switched_off')

  return (
    <div className={`grid gap-1.5 border-b border-rule px-4 py-3.5 md:items-center md:gap-3 ${COLS} ${chosen ? 'bg-marigold-wash' : ''}`}>
      <span className="type-display text-[22px] leading-none">{m.name}</span>
      <span className="text-sm leading-normal">{m.summary}</span>
      <span className="text-[13px] text-text-muted">{m.needs.length === 0 ? '—' : m.needs.join(', ')}</span>
      <span className="grid justify-items-start gap-1">
        <Tag tone={tone}>{core ? t.state.core : (t.state[m.state] ?? m.state)}</Tag>
        {note !== '' && <span className="text-xs text-text-muted">{note}</span>}
      </span>
      <span className="md:text-right">{canSwitch && <SmallButton onClick={onChoose}>{m.switched_on ? t.switchOff : t.switchOn}</SmallButton>}</span>
    </div>
  )
}

function SwitchPanel({ module: m, onCancel, onDone }: { module: ModuleRow; onCancel: () => void; onDone: (on: boolean) => void }) {
  const turningOn = !m.switched_on
  const [reason, setReason] = useState('')
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [failed, setFailed] = useState<string | null>(null)
  const [working, setWorking] = useState(false)

  const submit = async () => {
    setWorking(true)
    setFailed(null)
    const result = await settle(api.PUT('/v1/modules/{key}', { params: { path: { key: m.key } }, body: { enabled: turningOn, reason } as never }))
    setWorking(false)

    if (result.kind === 'saved') onDone(turningOn)
    else if (result.kind === 'invalid') {
      setErrors(result.errors)
      const general = errorFor(result.errors, 'module')
      if (general !== undefined) setFailed(general)
    } else setFailed(result.kind === 'refused' ? s.refused : s.failed)
  }

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault()
        void submit()
      }}
    >
      <h2 className="type-display mb-3 text-[26px] leading-none">{turningOn ? t.onTitle(m.name) : t.offTitle(m.name)}</h2>
      <ul className="mb-3.5 list-disc pl-[18px] text-sm leading-normal">
        {(turningOn ? t.onPoints : t.offPoints).map((p) => (
          <li key={p}>{p}</li>
        ))}
      </ul>
      <Input label={t.reason} hint={t.reasonHint} required value={reason} onChange={(e) => setReason(e.target.value)} error={errorFor(errors, 'reason')} maxLength={1000} />
      {failed !== null && <p className="mt-2 text-[13px] font-bold text-red-text">{failed}</p>}
      <div className="mt-3.5 flex flex-wrap gap-2">
        {turningOn ? (
          <Button type="submit" busy={working}>
            {working ? s.saving : t.switchOn}
          </Button>
        ) : (
          <button
            type="submit"
            disabled={working}
            className="h-11 border-2 border-red-pen bg-sheet px-[18px] text-[15px] font-extrabold text-red-text hover:bg-red-wash disabled:opacity-60"
          >
            {working ? s.saving : t.switchOff}
          </button>
        )}
        <Button variant="secondary" onClick={onCancel}>
          {s.cancel}
        </Button>
      </div>
    </form>
  )
}

/** How registers are taken at each level (the approved design): set by the level's profile, not switched here. */
function Levels() {
  const loaded = useLoad('registers:levels', () => lessonsApi.levels())
  const levels = loaded.data?.levels ?? []

  if (levels.length === 0) return null

  return (
    <div className="mt-6 border-2 border-ink bg-sheet">
      <div className="px-4 pt-3.5 pb-2">
        <h2 className="type-display text-[26px] leading-none">{t.levelsTitle}</h2>
        <p className="mt-1.5 text-[13px] text-text-muted">{t.levelsIntro}</p>
      </div>
      <div className="label-caps hidden gap-3 border-y-2 border-ink px-4 py-2 text-text-muted md:grid md:grid-cols-[minmax(120px,0.8fr)_minmax(160px,1fr)_minmax(240px,2fr)]">
        <span>{t.colLevel}</span>
        <span>{t.colRegisters}</span>
        <span>{t.colMeans}</span>
      </div>
      {levels.map((l) => (
        <div key={l.division} className="grid gap-1.5 border-b border-rule px-4 py-3 md:grid-cols-[minmax(120px,0.8fr)_minmax(160px,1fr)_minmax(240px,2fr)] md:items-center md:gap-3">
          <span className="type-display text-xl leading-none">{l.division}</span>
          <span>
            <Tag tone="plain">{t.mode[l.mode] ?? l.mode}</Tag>
          </span>
          <span className="text-sm leading-normal">{t.modeMeans[l.mode] ?? ''}</span>
        </div>
      ))}
      <p className="px-4 py-3 text-[13px] text-text-muted">{t.levelsNote}</p>
    </div>
  )
}
