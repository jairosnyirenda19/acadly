import { api, type paths } from '@acadly/kernel'
import { gridApiQuery, type GridQuery } from '@acadly/ui'
import { read, settle } from '../setup/api'

export type ChannelsState = paths['/v1/messages/channels']['get']['responses'][200]['content']['application/json']
export type NoticesState = paths['/v1/messages/notices']['get']['responses'][200]['content']['application/json']
export type NoticeState = paths['/v1/messages/notices/{key}']['get']['responses'][200]['content']['application/json']
export type SentGrid = paths['/v1/messages/sent/grid']['get']['responses'][200]['content']['application/json']
export type SentRow = SentGrid['rows'][number]
export type SentState = paths['/v1/messages/sent']['get']['responses'][200]['content']['application/json']
export type MessageState = paths['/v1/messages/sent/{id}']['get']['responses'][200]['content']['application/json']

type Done<T> = Promise<{ data?: T; error?: unknown; response: Response }>

export const messagesApi = {
  channels: () => read(api.GET('/v1/messages/channels')),
  saveSms: (body: { provider: string; sender: string; username: string; key: string }) =>
    settle(api.PUT('/v1/messages/channels/sms', { body: body as never }) as Done<ChannelsState>),
  saveEmail: (body: { from_name: string; reply_to: string }) => settle(api.PUT('/v1/messages/channels/email', { body: body as never }) as Done<ChannelsState>),
  saveLimits: (body: { quiet_from: string | null; quiet_to: string | null; sms_per_month: number | null }) =>
    settle(api.PUT('/v1/messages/limits', { body: body as never }) as Done<ChannelsState>),
  test: (channel: 'sms' | 'email', address: string) => settle(api.POST('/v1/messages/test', { body: { channel, address } as never }) as Done<{ id: string }>),

  notices: () => read(api.GET('/v1/messages/notices')),
  notice: (key: string) => read(api.GET('/v1/messages/notices/{key}', { params: { path: { key } } })),
  saveNotice: (key: string, body: { subject: string | null; lines: string[] | null; short: string | null; enabled: boolean; contacts: string | null }) =>
    settle(api.PUT('/v1/messages/notices/{key}', { params: { path: { key } }, body: body as never }) as Done<NoticeState>),
  resetNotice: (key: string) => settle(api.DELETE('/v1/messages/notices/{key}/wording', { params: { path: { key } } }) as Done<NoticeState>),

  sent: (day: string | null, channel: string, state: string, q: string) =>
    read(
      api.GET('/v1/messages/sent', {
        params: { query: { ...(day !== null ? { day } : {}), ...(channel !== '' ? { channel } : {}), ...(state !== '' ? { state } : {}), ...(q !== '' ? { q } : {}) } as never },
      }),
    ),
  /** One page of the sent messages, for the data grid. */
  sentGrid: (query: GridQuery) => read(api.GET('/v1/messages/sent/grid', { params: { query: gridApiQuery(query) as never } })),
  message: (id: string) => read(api.GET('/v1/messages/sent/{id}', { params: { path: { id } } })),
  again: (id: string) => settle(api.POST('/v1/messages/sent/{id}/again', { params: { path: { id } } }) as Done<MessageState>),
}

const BASIC = '@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà'
const EXTENSION = '^{}\\[~]|€\f'

/** How many SMS units a text uses: the same count the server makes (GSM 03.38, or Unicode). */
export function smsLength(text: string): { characters: number; units: number; unicode: boolean } {
  let septets = 0

  for (const char of text) {
    if (BASIC.includes(char)) septets += 1
    else if (EXTENSION.includes(char)) septets += 2
    else {
      const length = text.length

      return { characters: length, units: length <= 70 ? 1 : Math.ceil(length / 67), unicode: true }
    }
  }

  return { characters: septets, units: septets <= 160 ? 1 : Math.ceil(septets / 153), unicode: false }
}

/** Fills `:fact` with its example, as the server fills it with the real fact. */
export function fill(template: string, facts: Record<string, string | null | undefined>): string {
  return template.replace(/:([a-z][a-z0-9_]*)/g, (_, name: string) => {
    const value = facts[name]

    return value !== null && value !== undefined && value.trim() !== '' ? value : '—'
  })
}

/** The time of a moment: 07:42. */
export function clock(iso: string): string {
  return new Date(iso).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })
}

/** The time with seconds: 07:43:05. */
export function clockSeconds(iso: string): string {
  return new Date(iso).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
}

/** The tone of each message state: a word on a colour, never colour alone. */
export const messageTone: Record<string, 'done' | 'attention' | 'wrong' | 'plain'> = {
  delivered: 'done',
  sent: 'plain',
  accepted: 'plain',
  waiting: 'plain',
  not_delivered: 'wrong',
  not_reachable: 'attention',
  over_allowance: 'attention',
  not_sent: 'plain',
}
