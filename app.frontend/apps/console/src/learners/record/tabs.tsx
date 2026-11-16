import { can, useAppContext, useLoad } from '@acadly/kernel'
import { Waiting } from '@acadly/ui'
import { copy } from '../../copy'
import { messageTone } from '../../messages/api'
import { day } from '../../settings/api'
import { Tag } from '../../settings/parts'
import { learnersApi, type LearnerState } from '../api'
import { Column, Columns, LedgerFrame, MarginCell, SectionTitle, Td, Th, follow } from './ui'

const t = copy.learners.record
const l = copy.learners
const m = copy.messages

export const channelWord = (channel: string) => (channel === 'sms' ? m.sms : channel === 'email' ? m.email : channel)

/** The notices sent to the family, loaded once and shared by the overview and the tab. */
export function useFamilyMessages(id: string) {
  return useLoad(`learner:messages:${id}`, () => learnersApi.messages(id))
}

/** Every notice sent about the learner: time, notice, to whom and how, what happened, the state. */
export function MessagesTab({ learner }: { learner: LearnerState }) {
  const context = useAppContext()
  const opens = can(context, 'notify.manage')
  const loaded = useFamilyMessages(learner.id)
  const data = loaded.data

  return (
    <Columns top>
      <Column label={l.record.tabs.messages}>
        <p className="mb-3 max-w-[70ch] text-sm text-text-muted">
          {t.messagesIntro(learner.first_name)} {opens && data !== undefined && data.messages.length > 0 && t.messagesOpen}
        </p>
        {data === undefined ? (
          <Waiting phase={loaded.phase} what={l.record.tabs.messages} labels={copy.loading} rows={4} onRetry={loaded.reload} />
        ) : data.messages.length === 0 ? (
          <p className="border-2 border-ink bg-sheet p-4 text-sm">{t.noMessages(learner.first_name)}</p>
        ) : (
          <LedgerFrame label={l.record.tabs.messages}>
            <table className="w-full min-w-[760px] border-collapse">
              <thead>
                <tr>
                  <Th>{t.colTime}</Th>
                  <Th>{t.colNotice}</Th>
                  <Th>{t.colTo}</Th>
                  <Th>{t.colWhat}</Th>
                  <Th>{t.colState}</Th>
                </tr>
              </thead>
              <tbody>
                {data.messages.map((msg) => {
                  const href = `/settings/messages/sent?message=${msg.id}`
                  const tone = messageTone[msg.state] ?? 'plain'

                  return (
                    <tr key={msg.id} className={tone === 'wrong' ? 'bg-red-wash' : opens ? 'hover:bg-paper' : ''}>
                      <MarginCell>{t.when(msg.at)}</MarginCell>
                      <Td wide>
                        {opens ? (
                          <a href={href} onClick={(e) => follow(e, href)} className="font-extrabold text-ink underline-offset-[3px] hover:underline">
                            {msg.notice}
                          </a>
                        ) : (
                          <span className="font-bold">{msg.notice}</span>
                        )}
                      </Td>
                      <Td>{t.sentTo(channelWord(msg.channel), msg.to)}</Td>
                      <Td wide>{msg.reason ?? ''}</Td>
                      <Td>
                        <Tag tone={tone}>{m.state[msg.state] ?? msg.state}</Tag>
                      </Td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </LedgerFrame>
        )}
      </Column>
    </Columns>
  )
}

/** One learner's class history: a line for each place, the current one marked. */
export function Placements({ learner }: { learner: LearnerState }) {
  return (
    <>
      <ol className="relative">
        <span aria-hidden="true" className="absolute top-2.5 bottom-6 left-[7px] w-0.5 bg-ink" />
        {learner.placements.map((p, i) => (
          <li key={i} className="relative pb-4.5 pl-[30px]">
            <span aria-hidden="true" className={`absolute top-0.5 left-0 size-4 border-2 border-ink ${p.now ? 'bg-teal' : 'bg-sheet'}`} />
            <div className="flex flex-wrap items-baseline gap-2">
              <span className="type-display text-[22px] leading-none">{p.class}</span>
              <Tag tone={p.now ? 'done' : 'plain'}>{p.now ? l.now : l.ended}</Tag>
            </div>
            <div className="mt-1 text-sm font-bold">
              {[p.campus, p.year, p.ends_on === null ? l.since(day(p.starts_on)) : l.between(day(p.starts_on), day(p.ends_on))].filter(Boolean).join(' · ')}
            </div>
            <div className="text-[13px] text-text-muted">
              {l.entry[p.entry_reason] ?? ''} {p.reason !== null ? l.reasonWas(p.reason) : ''}
            </div>
          </li>
        ))}
      </ol>
      <p className="text-[13px] text-text-muted">{l.timelineNote}</p>
    </>
  )
}

/** What was recorded, newest first; `limit` keeps the first few for the overview. */
export function Activity({ learner, limit }: { learner: LearnerState; limit?: number }) {
  const items = limit !== undefined ? learner.activity.slice(0, limit) : learner.activity

  return (
    <ul>
      {items.length === 0 && <li className="py-2 text-[13px] text-text-muted">{l.noActivity}</li>}
      {items.map((a, i) => (
        <li key={i} className="border-b border-rule py-2 text-[13px]">
          <span className="ref text-xs text-text-muted">{day(a.at)}</span>
          <br />
          <strong>{a.what}</strong> {l.activityLine(a.who)}
          {a.reason !== null && <span className="block text-text-muted">{l.reasonWas(a.reason)}</span>}
        </li>
      ))}
    </ul>
  )
}

/** The history tab: everything recorded, and the class history. */
export function HistoryTab({ learner }: { learner: LearnerState }) {
  return (
    <Columns top>
      <Column grow="flex-[1.4_1_360px]" label={t.historyTitle}>
        <SectionTitle>{t.historyTitle}</SectionTitle>
        <p className="mb-2 max-w-[65ch] text-[13px] text-text-muted">{t.historyNote}</p>
        <Activity learner={learner} />
      </Column>
      <Column edge tinted label={l.atSchool}>
        <SectionTitle>{l.atSchool}</SectionTitle>
        <Placements learner={learner} />
      </Column>
    </Columns>
  )
}
