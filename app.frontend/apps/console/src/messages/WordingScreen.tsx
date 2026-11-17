import { navigate, useLoad, useLocation } from '@acadly/kernel'
import { Button, slips, Waiting } from '@acadly/ui'
import { useRef, useState } from 'react'
import { copy } from '../copy'
import { errorFor, type Saved } from '../setup/api'
import { Input, TextArea } from '../setup/parts'
import { SettingsTabs, Tag } from '../settings/parts'
import { fill, messagesApi, smsLength, type NoticesState, type NoticeState } from './api'
import { Choice, Heading, MessagesHead } from './parts'

const t = copy.messages
const s = copy.settings

/** The words of each notice (the approved design): the list, the editor and the preview. */
export function WordingScreen() {
  const { query } = useLocation()
  const loaded = useLoad('messages:notices', () => messagesApi.notices())
  const list = loaded.data

  if (list === undefined) {
    return (
      <>
        <SettingsTabs current="messages" />
        <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} rows={8} onRetry={loaded.reload} />
      </>
    )
  }

  const chosen = query.get('notice') ?? list.notices.find((n) => n.audience === 'contacts')?.key ?? list.notices[0]?.key ?? null

  return (
    <>
      <MessagesHead current="wording" intro={t.wordingIntro} />
      <div className="flex flex-1 flex-wrap items-stretch">
        <NoticeList list={list} chosen={chosen} />
        {chosen !== null && <Editor key={chosen} noticeKey={chosen} onSaved={loaded.reload} />}
      </div>
    </>
  )
}

function NoticeList({ list, chosen }: { list: NoticesState; chosen: string | null }) {
  const groups = [...new Set(list.notices.map((n) => n.group))]

  return (
    <nav aria-label={t.noticesLabel} className="min-w-0 flex-[0_0_300px] border-ink bg-paper max-[899px]:flex-[1_1_100%] max-[899px]:border-b-2 min-[900px]:border-r-2">
      {groups.map((g) => (
        <div key={g}>
          <div className="label-caps border-b border-rule bg-field px-4 pt-3.5 pb-1.5 text-text-muted">{g}</div>
          {list.notices
            .filter((n) => n.group === g)
            .map((n) => (
              <a
                key={n.key}
                href={`/settings/messages/wording?notice=${n.key}`}
                aria-current={n.key === chosen ? 'true' : undefined}
                onClick={(e) => {
                  e.preventDefault()
                  navigate(`/settings/messages/wording?notice=${n.key}`)
                }}
                className={`grid grid-cols-[1fr_auto] gap-x-2.5 gap-y-1 border-b border-l-4 border-b-rule px-4 py-3 text-ink no-underline ${n.key === chosen ? 'border-l-teal bg-sheet' : 'border-l-transparent hover:bg-sheet'}`}
              >
                <span className="text-[15px] font-extrabold">{n.name}</span>
                <span className="flex items-start gap-1 self-start">
                  {n.own_words && <Tag tone="plain">{t.ownWords}</Tag>}
                  <Tag tone={n.enabled ? 'done' : 'plain'}>{n.enabled ? t.on : t.off}</Tag>
                </span>
                <span className="text-[13px] text-text-muted">{t.audience[n.audience]}</span>
              </a>
            ))}
        </div>
      ))}
    </nav>
  )
}

function Editor({ noticeKey, onSaved }: { noticeKey: string; onSaved: () => void }) {
  const loaded = useLoad(`messages:notice:${noticeKey}`, () => messagesApi.notice(noticeKey))
  const notice = loaded.data

  if (notice === undefined) {
    return (
      <div className="min-w-0 flex-[1_1_420px]">
        <Waiting phase={loaded.phase} what={t.title} labels={copy.loading} rows={6} onRetry={loaded.reload} />
      </div>
    )
  }

  return (
    <Form
      key={JSON.stringify(notice.own) + String(notice.enabled) + String(notice.contacts)}
      notice={notice}
      onSaved={(next) => {
        loaded.set(next)
        onSaved()
      }}
    />
  )
}

function Form({ notice, onSaved }: { notice: NoticeState; onSaved: (next: NoticeState) => void }) {
  const own = notice.own
  const declared = notice.declared
  const [enabled, setEnabled] = useState(notice.enabled)
  const [contacts, setContacts] = useState(notice.contacts ?? 'main')
  const [short, setShort] = useState(own?.short ?? declared.short ?? '')
  const [subject, setSubject] = useState(own?.subject ?? declared.subject)
  const [body, setBody] = useState((own?.lines ?? declared.lines).join('\n\n'))
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [failed, setFailed] = useState<string | null>(null)
  const [working, setWorking] = useState(false)
  const smsBox = useRef<HTMLTextAreaElement>(null)
  const sms = notice.channels.includes('sms')
  const email = notice.channels.includes('email') || notice.channels.includes('in_app')
  const examples = notice.examples as Record<string, string | null>
  const portalLine = notice.portal_line
  const length = smsLength(`${fill(short, examples).trimEnd()}${portalLine !== null ? ` ${portalLine}` : ''}`)

  const run = async (request: Promise<Saved<NoticeState>>, title: string) => {
    setWorking(true)
    setFailed(null)
    setErrors({})
    const result = await request
    setWorking(false)

    if (result.kind === 'saved') {
      onSaved(result.data)
      slips.done({ word: copy.slips.saved, title })
    } else if (result.kind === 'invalid') setErrors(result.errors)
    else setFailed(result.kind === 'refused' ? s.refused : s.failed)
  }

  const insert = (fact: string) => {
    const box = smsBox.current
    const token = `:${fact}`

    if (box === null) {
      setShort((v) => `${v}${token}`)

      return
    }

    const start = box.selectionStart
    const end = box.selectionEnd
    setShort((v) => v.slice(0, start) + token + v.slice(end))
    window.requestAnimationFrame(() => {
      box.focus()
      box.setSelectionRange(start + token.length, start + token.length)
    })
  }

  const lines = body
    .split(/\n\s*\n/)
    .map((l) => l.trim())
    .filter((l) => l !== '')

  return (
    <>
      <form
        className="min-w-0 flex-[1_1_420px] bg-sheet px-3 pt-5 pb-7 md:px-[clamp(16px,3vw,32px)]"
        onSubmit={(e) => {
          e.preventDefault()
          void run(
            messagesApi.saveNotice(notice.key, { subject, lines, short: sms ? short : null, enabled, contacts: notice.contacts !== null ? contacts : null }),
            t.wordingSaved,
          )
        }}
      >
        <Heading aside={<Tag tone={notice.enabled ? 'done' : 'plain'}>{notice.enabled ? t.on : t.off}</Tag>}>{notice.name}</Heading>
        <div className="mb-4 grid gap-4 sm:grid-cols-2">
          {notice.can_switch_off ? (
            <Choice
              name="enabled"
              label={t.sendIt}
              value={enabled ? 'on' : 'off'}
              options={[
                { value: 'on', label: t.sendItOn },
                { value: 'off', label: t.sendItOff },
              ]}
              onChange={(v) => setEnabled(v === 'on')}
            />
          ) : (
            <p className="text-sm text-text-muted">{t.alwaysSent}</p>
          )}
          {notice.contacts !== null && (
            <Choice
              name="contacts"
              label={t.whoIsTold}
              value={contacts}
              options={[
                { value: 'main', label: t.contacts.main ?? '' },
                { value: 'every', label: t.contacts.every ?? '' },
              ]}
              onChange={setContacts}
            />
          )}
        </div>
        {sms && (
          <div className="mb-4">
            <TextArea ref={smsBox} label={t.smsWords} required rows={3} value={short} onChange={(e) => setShort(e.target.value)} error={errorFor(errors, 'short')} />
            <div className="mt-2 flex flex-wrap items-baseline gap-x-3.5 gap-y-1">
              <span className={`text-sm font-extrabold ${length.units > 1 ? 'text-red-text' : ''}`}>{t.smsCount(length.characters, length.unicode ? 70 : notice.budget, length.units)}</span>
              <span className="text-[13px] text-text-muted">{t.smsCountNote}</span>
            </div>
            {portalLine !== null && (
              <p className="mt-2 text-[13px]">
                <strong>{t.portalLine}</strong> “{portalLine}”
              </p>
            )}
            <div className="mt-3 flex flex-wrap items-center gap-1.5" aria-label={t.facts}>
              <span className="label-caps mr-1 text-text-muted">{t.facts}</span>
              {notice.facts.map((f) => (
                <button key={f} type="button" onClick={() => insert(f)} className="ref h-[30px] border-2 border-ink bg-sheet px-2.5 text-[13px] font-bold text-ink hover:bg-field">
                  :{f}
                </button>
              ))}
            </div>
          </div>
        )}
        {email && (
          <div className="grid gap-3.5">
            <Input label={t.emailSubject} required value={subject} onChange={(e) => setSubject(e.target.value)} error={errorFor(errors, 'subject')} maxLength={300} />
            <TextArea label={t.emailText} required rows={5} value={body} onChange={(e) => setBody(e.target.value)} error={errorFor(errors, 'lines')} />
          </div>
        )}
        {failed !== null && <p className="mt-2 text-[13px] font-bold text-red-text">{failed}</p>}
        <div className="mt-4 flex flex-wrap gap-2">
          <Button type="submit" busy={working}>
            {working ? s.saving : t.saveWording}
          </Button>
          {own !== null && (
            <Button variant="secondary" disabled={working} onClick={() => void run(messagesApi.resetNotice(notice.key), t.wordingReset)}>
              {t.useProduct}
            </Button>
          )}
        </div>
        <p className="mt-2.5 text-[13px] text-text-muted">{t.wordingNote}</p>
      </form>
      <aside className="min-w-[260px] flex-[0_1_320px] border-ink bg-paper px-3 pt-5 pb-7 max-[1199px]:flex-[1_1_100%] max-[1199px]:border-t-2 md:px-7 min-[1200px]:border-l-2">
        {sms && (
          <>
            <div className="label-caps mb-2.5 text-text-muted">{t.previewSms}</div>
            <div className="border-2 border-ink bg-sheet p-3.5 text-sm leading-normal">{fill(short, examples)}</div>
          </>
        )}
        {email && (
          <>
            <div className={`label-caps mb-2.5 text-text-muted ${sms ? 'mt-5' : ''}`}>{t.previewEmail}</div>
            <div className="border-2 border-ink bg-sheet p-3.5 text-sm leading-normal">
              <div className="mb-2 font-extrabold">{fill(subject, examples)}</div>
              {lines.map((l, i) => (
                <p key={i} className={i > 0 ? 'mt-2' : ''}>
                  {fill(l, examples)}
                </p>
              ))}
            </div>
          </>
        )}
        <p className="mt-2.5 text-[13px] text-text-muted">{t.previewNote}</p>
      </aside>
    </>
  )
}
