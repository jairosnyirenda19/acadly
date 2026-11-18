import { api, type paths } from '@acadly/kernel'
import { read, settle } from '../setup/api'

type Get<P extends keyof paths> = paths[P] extends { get: { responses: { 200: { content: { 'application/json': infer T } } } } } ? T : never

export type LearnerFees = Get<'/v1/fees/learners/{id}'>
export type Instalment = LearnerFees['standing']['instalments'][number]
export type StatementLine = LearnerFees['statement'][number]
export type FeeCharge = LearnerFees['charges'][number]
export type Receipt = Get<'/v1/fees/payments/{id}'>
export type Desk = Get<'/v1/fees/desk'>
export type TermList = Get<'/v1/fees/terms'>
export type TermFees = Get<'/v1/fees/terms/{period}/{division}'>
export type FeeItem = TermFees['items'][number]
export type ShopList = Get<'/v1/fees/shop'>
export type ShopItem = ShopList['items'][number]
export type Method = 'cash' | 'bank' | 'airtel_money' | 'mpamba' | 'cheque'
export type AdjustmentKind = 'discount' | 'bursary' | 'waiver' | 'write_off'

type Done<T> = Promise<{ data?: T; error?: unknown; response: Response }>

const API: string = import.meta.env.VITE_API_URL ?? '/api'

export const METHODS: Method[] = ['cash', 'bank', 'airtel_money', 'mpamba', 'cheque']

export const feesApi = {
  learner: (id: string) => read(api.GET('/v1/fees/learners/{id}', { params: { path: { id } } })),
  receipt: (id: string) => read(api.GET('/v1/fees/payments/{id}', { params: { path: { id } } })),
  desk: () => read(api.GET('/v1/fees/desk')),
  terms: () => read(api.GET('/v1/fees/terms')),
  term: (period: string, division: string) => read(api.GET('/v1/fees/terms/{period}/{division}', { params: { path: { period, division } } })),
  shop: () => read(api.GET('/v1/fees/shop')),
  items: () => read(api.GET('/v1/fees/items')),

  pay: (body: { method: Method; reference: string; received_on: string; payer_name: string; payer_phone: string; amount: number; shares: { learner_id: string; amount: number }[]; tell: boolean }) =>
    settle(api.POST('/v1/fees/payments', { body: body as never }) as Done<{ id: string }>),
  cancel: (id: string, reason: string) => settle(api.POST('/v1/fees/payments/{id}/cancel', { params: { path: { id } }, body: { reason } as never }) as Done<{ number: string }>),
  plan: (charge: string, reason: string, instalments: { label: string; due_on: string; amount: number }[]) =>
    settle(api.POST('/v1/fees/charges/{id}/plan', { params: { path: { id: charge } }, body: { reason, instalments } as never }) as Done<{ id: string }>),
  adjust: (charge: string, kind: AdjustmentKind, amount: number, reason: string) =>
    settle(api.POST('/v1/fees/charges/{id}/adjust', { params: { path: { id: charge } }, body: { kind, amount, reason } as never }) as Done<{ number: string }>),
  optional: (learner: string, items: string[]) => settle(api.PUT('/v1/fees/learners/{id}/optional', { params: { path: { id: learner } }, body: { items } as never }) as Done<{ id: string }>),
  sale: (body: { learner_id: string; lines: { item_id: string; quantity: number }[]; pay_now: Method | null; reference: string; payer_name: string }) =>
    settle(api.POST('/v1/fees/shop/sale', { body: body as never }) as Done<{ charge_id: string; payment_id: string | null }>),

  saveTerm: (period: string, division: string, body: { amounts: { item_id: string; level_code: string; amount: number }[]; plan: { label: string; due_on: string; share_bp: number }[] }) =>
    settle(api.PUT('/v1/fees/terms/{period}/{division}', { params: { path: { period, division } }, body: body as never }) as Done<{ saved: boolean }>),
  bill: (period: string, division: string) => settle(api.POST('/v1/fees/terms/{period}/{division}/bill', { params: { path: { period, division } } }) as Done<{ learners: number; total: number }>),
  saveItems: (items: { id: string | null; name: string; optional: boolean; retired: boolean }[]) => settle(api.PUT('/v1/fees/items', { body: { items } as never }) as Done<{ items: FeeItem[] }>),
  saveShop: (items: { id: string | null; name: string; size: string | null; price: number; retired: boolean }[]) =>
    settle(api.PUT('/v1/fees/shop', { body: { items } as never }) as Done<{ saved: boolean }>),

  closeDay: (day: string, counted: number, note: string) => settle(api.POST('/v1/fees/days/{day}/close', { params: { path: { day } }, body: { counted, note } as never }) as Done<{ difference: number }>),
  remind: (learnerIds: string[]) => settle(api.POST('/v1/fees/reminders', { body: { learner_ids: learnerIds } as never }) as Done<{ sent: number }>),
  exportUrl: (from: string, to: string) => `${API}/v1/fees/export?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`,
}

/** Money is kept as whole tambala. "MK 150,000"; tambala only when there are some. */
export function money(minor: number): string {
  const sign = minor < 0 ? '−' : ''
  const abs = Math.abs(minor)
  const kwacha = Math.floor(abs / 100).toLocaleString('en-GB')
  const tambala = abs % 100

  return `${sign}MK ${kwacha}${tambala > 0 ? `.${String(tambala).padStart(2, '0')}` : ''}`
}

/** A number without the currency, for ledger columns: "150,000". */
export function figure(minor: number): string {
  return money(minor).replace('MK ', '')
}

/** What a person typed, in kwacha ("150,000" or "150000.50"), as tambala; null when it is not an amount. */
export function parseKwacha(text: string): number | null {
  const clean = text.replace(/[,\s]/g, '').replace(/^MK/i, '')
  if (!/^\d+(\.\d{1,2})?$/.test(clean)) return null
  const [whole, part = ''] = clean.split('.') as [string, string?]

  return Number(whole) * 100 + Number(part.padEnd(2, '0'))
}

/** An amount in tambala as kwacha to edit: "150000" or "150000.50". */
export function kwachaText(minor: number): string {
  return minor % 100 === 0 ? String(minor / 100) : (minor / 100).toFixed(2)
}

/** Today as YYYY-MM-DD, in local time. */
export function today(): string {
  const d = new Date()

  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

/**
 * The default split of one payment across children: each child's amount due
 * now first, in order, then what is left of the year; anything more goes to
 * the first child as credit.
 */
export function splitPayment(amount: number, children: { id: string; due_now: number; left: number }[]): Record<string, number> {
  const shares: Record<string, number> = {}
  let rest = amount

  for (const pass of ['due_now', 'left'] as const) {
    for (const c of children) {
      const room = (pass === 'due_now' ? c.due_now : c.left) - (shares[c.id] ?? 0)
      const take = Math.max(0, Math.min(rest, room))
      shares[c.id] = (shares[c.id] ?? 0) + take
      rest -= take
    }
  }

  const first = children[0]
  if (rest > 0 && first !== undefined) shares[first.id] = (shares[first.id] ?? 0) + rest

  return shares
}
