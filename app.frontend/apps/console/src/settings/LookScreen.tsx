import { api, CONTEXT_CHANGED, useAppContext } from '@acadly/kernel'
import { applyLook, Button, contrast, isColour, nameSize, slips } from '@acadly/ui'
import { useRef, useState, type CSSProperties } from 'react'
import { copy } from '../copy'
import { settle, setupApi } from '../setup/api'
import { Input } from '../setup/parts'
import { ACCENT_CHOICES as ACCENTS, MAIN_CHOICES as MAINS, REGISTER_ACCENT as TEAL_BRIGHT, REGISTER_NAVY as NAVY, WHITE } from './lookChoices'
import { PageHead, SettingsTabs, Tag } from './parts'

const t = copy.look
const API = import.meta.env.VITE_API_URL ?? '/api'

type Done<T> = Promise<{ data?: T; error?: unknown; response: Response }>

function Swatch({ colour, on, label, onPick }: { colour: string; on: boolean; label: string; onPick: () => void }) {
  return (
    <button type="button" aria-pressed={on} aria-label={label} title={colour} onClick={onPick} className={`grid size-11 place-items-center bg-sheet ${on ? 'border-[3px] border-ink' : 'border border-rule'}`}>
      <span style={{ background: colour, width: on ? 28 : 34, height: on ? 28 : 34 } as CSSProperties} />
    </button>
  )
}

function Check({ ratio, need, label }: { ratio: number; need: number; label: string }) {
  const ok = ratio >= need

  return (
    <div className="flex items-center gap-2.5 border-b border-rule py-2 text-sm">
      {ok ? <Tag tone="done">✓ {t.passes}</Tag> : <Tag tone="wrong">✕ {t.tooLow}</Tag>}
      <span className="flex-1">{label}</span>
      <span className="ref font-extrabold">{`${ratio.toFixed(1)} : 1`}</span>
    </div>
  )
}

/** Settings › Branding (the approved design, D-096): the name shown, the short name, the logo and two colours. */
export function LookScreen() {
  const context = useAppContext()
  const saved = context.school.look
  const [name, setName] = useState(saved.app_name ?? '')
  const [short, setShort] = useState(context.school.short_name)
  const [main, setMain] = useState(saved.main.toUpperCase())
  const [accent, setAccent] = useState(saved.accent.toUpperCase())
  const [last, setLast] = useState(saved.accent_last)
  const [busy, setBusy] = useState(false)
  const [logoAt, setLogoAt] = useState(0)
  const file = useRef<HTMLInputElement>(null)
  const m = isColour(main) ? main : NAVY
  const a = isColour(accent) ? accent : TEAL_BRIGHT
  const onMain = contrast(WHITE, m)
  const accentOn = contrast(a, m)
  const readable = isColour(main) && isColour(accent) && onMain >= 4.5 && accentOn >= 3
  const shown = name.trim()

  const save = async (reset = false) => {
    setBusy(true)
    const body = reset ? { app_name: '', short_name: short, main: '', accent: '', accent_last: true } : { app_name: shown, short_name: short.trim(), main, accent, accent_last: last }
    const result = await settle(api.PUT('/v1/institution/look', { body: body as never }) as Done<{ main: string; accent: string }>)
    setBusy(false)
    if (result.kind === 'saved') {
      applyLook(result.data)
      if (reset) {
        setName('')
        setMain(NAVY)
        setAccent(TEAL_BRIGHT)
        setLast(true)
      }
      window.dispatchEvent(new Event(CONTEXT_CHANGED))
      slips.done({ word: copy.slips.saved, title: t.saved })
    } else slips.wrong({ word: t.notSaved, title: result.kind === 'invalid' ? (Object.values(result.errors)[0]?.[0] ?? t.failed) : t.failed })
  }

  const upload = async (f: File | undefined) => {
    if (f === undefined) return
    const result = await setupApi.uploadLogo(f)
    if (result.kind === 'saved') {
      setLogoAt(Date.now())
      window.dispatchEvent(new Event(CONTEXT_CHANGED))
      slips.done({ word: copy.slips.saved, title: t.logoSaved })
    } else slips.wrong({ word: t.notSaved, title: result.kind === 'invalid' ? (Object.values(result.errors)[0]?.[0] ?? t.failed) : t.failed })
  }

  const preview = shown === '' ? null : nameSize(shown)
  const words = shown.split(' ')
  const lastWord = last && words.length > 1 ? words.pop() : undefined

  return (
    <>
      <SettingsTabs />
      <PageHead kicker={t.kicker} title={t.title} intro={t.intro} />
      <div className="grid grid-cols-[minmax(0,1fr)] items-start gap-x-8 gap-y-6 px-[clamp(16px,3vw,40px)] pb-8 xl:grid-cols-[minmax(0,1fr)_480px]">
        <section className="grid gap-5 border-2 border-ink bg-sheet p-4">
          <div className="grid gap-4 md:grid-cols-[minmax(0,1fr)_220px]">
            <Input label={t.name} value={name} maxLength={40} placeholder={copy.setup.product + ' ' + copy.setup.productAccent} onChange={(e) => setName(e.target.value)} />
            <Input label={t.short} value={short} maxLength={16} onChange={(e) => setShort(e.target.value)} />
          </div>
          <p className="-mt-3 text-xs text-text-muted">{t.nameHint(shown.length)}</p>
          <label className="flex min-h-11 items-center gap-2.5 text-[15px]">
            <input type="checkbox" className="size-5 accent-ink" checked={last} onChange={(e) => setLast(e.target.checked)} />
            {t.accentLast}
          </label>

          <div className="grid grid-cols-[96px_minmax(0,1fr)] items-center gap-4">
            <span className="grid size-24 place-items-center border-2 border-dashed border-rule-dashed">
              {context.school.has_logo ? <img src={`${API}/v1/school/logo?v=${logoAt}`} alt={t.logo} className="max-h-20 max-w-20 object-contain" /> : <span className="px-2 text-center text-xs text-text-muted">{t.noLogo}</span>}
            </span>
            <span className="grid gap-1.5">
              <b className="text-[15px]">{t.logo}</b>
              <span className="text-[13px] text-text-muted">{t.logoHint}</span>
              <span>
                <input ref={file} type="file" accept="image/png,image/jpeg" className="sr-only" onChange={(e) => void upload(e.target.files?.[0])} />
                <Button variant="secondary" onClick={() => file.current?.click()}>
                  {context.school.has_logo ? t.replaceLogo : t.addLogo}
                </Button>
              </span>
            </span>
          </div>

          <fieldset className="grid gap-2">
            <legend className="label-caps mb-2 text-text-muted">{t.main}</legend>
            <div className="flex flex-wrap items-center gap-2">
              {MAINS.map((c) => (
                <Swatch key={c} colour={c} on={main === c} label={c} onPick={() => setMain(c)} />
              ))}
              <span className="w-[130px]">
                <Input label={t.code} value={main} maxLength={7} onChange={(e) => setMain(e.target.value.toUpperCase())} />
              </span>
            </div>
          </fieldset>
          <fieldset className="grid gap-2">
            <legend className="label-caps mb-2 text-text-muted">{t.accent}</legend>
            <div className="flex flex-wrap items-center gap-2">
              {ACCENTS.map((c) => (
                <Swatch key={c} colour={c} on={accent === c} label={c} onPick={() => setAccent(c)} />
              ))}
              <span className="w-[130px]">
                <Input label={t.code} value={accent} maxLength={7} onChange={(e) => setAccent(e.target.value.toUpperCase())} />
              </span>
            </div>
          </fieldset>

          <div>
            <div className="label-caps border-b-2 border-ink pb-1 text-text-muted">{t.checks}</div>
            <Check ratio={onMain} need={4.5} label={t.checkText} />
            <Check ratio={accentOn} need={3} label={t.checkAccent} />
            <p className="mt-2 text-[13px] text-text-muted">{t.checksNote}</p>
          </div>

          <div className="flex flex-wrap gap-2">
            <Button busy={busy} disabled={!readable || short.trim() === ''} onClick={() => void save()}>
              {t.save}
            </Button>
            <Button variant="secondary" busy={busy} onClick={() => void save(true)}>
              {t.reset}
            </Button>
          </div>
        </section>

        <aside className="grid content-start gap-3">
          <div className="label-caps text-text-muted">{t.preview}</div>
          <div className="flex border-2 border-ink" style={{ background: WHITE }}>
            <div className="w-[230px] shrink-0 pb-3 text-sheet" style={{ background: m }}>
              <div className="flex items-center gap-2.5 px-3.5 pt-4 pb-3.5">
                {context.school.has_logo && (
                  <span className="logo-plate grid size-10 shrink-0 place-items-center border-2 p-0.5" style={{ borderColor: a }}>
                    <img src={`${API}/v1/school/logo?v=${logoAt}`} alt="" className="max-h-full max-w-full object-contain" />
                  </span>
                )}
                {preview === null ? (
                  <span className="type-display text-[28px] leading-[0.9]">
                    {copy.setup.product} <span style={{ color: a }}>{copy.setup.productAccent}</span>
                  </span>
                ) : (
                  <span className="type-display min-w-0 leading-[0.92]" style={{ fontSize: Math.round(preview.px * 0.88), display: '-webkit-box', WebkitLineClamp: preview.lines, WebkitBoxOrient: 'vertical', overflow: 'hidden' }}>
                    {lastWord === undefined ? shown : words.join(' ')} {lastWord !== undefined && <span style={{ color: a }}>{lastWord}</span>}
                  </span>
                )}
              </div>
              {[t.pTodayItem, t.pRegisters, t.pMarks].map((x, i) => (
                <div key={x} className={`border-l-4 px-3.5 py-2 text-sm ${i === 0 ? 'font-extrabold' : 'font-medium opacity-80'}`} style={{ borderColor: i === 0 ? a : 'transparent' }}>
                  {x}
                </div>
              ))}
            </div>
            <div className="grid min-w-0 flex-1 content-start gap-2.5 p-3.5">
              <div className="type-display text-[30px] leading-none" style={{ color: m }}>
                {t.pToday}
              </div>
              <div className="px-3 py-2.5 text-sheet" style={{ background: m }}>
                <div className="label-caps opacity-80">{t.pPresent}</div>
                <div className="type-numeral text-[36px]" style={{ color: a }}>
                  94%
                </div>
              </div>
              <div className="flex flex-wrap gap-1.5">
                <Tag tone="done">✓ {t.pTaken}</Tag>
                <Tag tone="attention">! {t.pOpen}</Tag>
                <Tag tone="wrong">✕ {t.pAbsent}</Tag>
              </div>
            </div>
          </div>
          <p className="text-[13px] text-text-muted">{t.fixed}</p>
        </aside>
      </div>
    </>
  )
}
