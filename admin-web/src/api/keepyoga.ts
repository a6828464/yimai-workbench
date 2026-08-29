/**
 * KeepYoga 只读客户端
 * - 后端模式（USE_BACKEND）：凭据与 access_token 全部由 Laravel 服务端持有，
 *   前端仅调用 /ky/session 与 /ky/call 代理接口
 * - 演示模式：沿用 Vite 开发服务器代理（.env.local 凭据），token 存于前端内存
 */
import { USE_BACKEND, apiPost } from './backend'

const BRAND_ID = '108193'
const VERSION = '10.1.3'

export const KY_STORES: Record<string, string> = { 绿地店: '1', 东部店: '4250' }

let cachedToken = ''

export async function kySession(force = false): Promise<string> {
  if (USE_BACKEND) {
    // 会话由服务端建立并缓存，浏览器不接触 token
    await apiPost('/ky/session', force ? { force: true } : undefined)
    return 'backend'
  }
  if (cachedToken && !force) return cachedToken
  const resp = await fetch('/api/ky/session', { method: 'POST' })
  const data = await resp.json()
  if (!data.ok || !data.token) throw new Error(data.error || '登录失败')
  cachedToken = data.token
  return cachedToken
}

async function kyPost<T = unknown>(
  path: string,
  body: Record<string, unknown>,
  venueId?: string
): Promise<T> {
  const normalizedPath = path.replace(/^\/+/, '')
  if (USE_BACKEND) {
    const form: Record<string, unknown> = { ...body }
    if (venueId && form['venue_id'] === undefined) form['venue_id'] = venueId
    const data = await apiPost<{ errno?: string | number } & Record<string, unknown>>('/ky/call', {
      path: normalizedPath,
      form
    })
    if (String(data?.errno ?? '0') !== '0') {
      throw new Error(`${normalizedPath} errno=${data?.errno} ${data?.emsg ?? ''}`)
    }
    return data as T
  }
  const token = await kySession()
  const params: Record<string, string> = {
    access_token: token,
    brand_id: BRAND_ID,
    version: VERSION,
    os: 'pc'
  }
  if (venueId) params['venue_id'] = venueId
  for (const [k, v] of Object.entries(body)) params[k] = String(v)
  const resp = await fetch(`/ky/${normalizedPath}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams(params).toString()
  })
  const data = await resp.json()
  if (String(data.errno ?? '0') !== '0') {
    // token 过期重试一次
    if (String(data.errno) === '6') {
      cachedToken = ''
      await kySession(true)
      return kyPost(normalizedPath, body, venueId)
    }
    throw new Error(`${normalizedPath} errno=${data.errno} ${data.emsg ?? ''}`)
  }
  return data as T
}

function common(venueId: string): Record<string, unknown> {
  return { venue_id: venueId }
}

export interface KyCounts {
  members: number | string
  visitors: number | string
  mcards: number | string
  contracts: number | string
}

function extractTotal(response: unknown): number | string {
  if (!response || typeof response !== 'object') return '-'
  const resp = response as Record<string, unknown>
  const scopes: Record<string, unknown>[] = []
  if (resp.data && typeof resp.data === 'object') scopes.push(resp.data as Record<string, unknown>)
  scopes.push(resp)
  for (const obj of scopes) {
    for (const key of ['total', 'count', 'recordsTotal', 'total_count']) {
      if (obj[key] !== undefined && obj[key] !== '' && !Number.isNaN(Number(obj[key])))
        return Number(obj[key])
    }
    const fenye = obj.fenye as Record<string, unknown> | undefined
    if (fenye) {
      for (const key of ['record_count', 'total', 'count']) {
        if (fenye[key] !== undefined && fenye[key] !== '') return Number(fenye[key])
      }
    }
    for (const pageKey of ['pagination', 'page']) {
      const page = obj[pageKey] as Record<string, unknown> | undefined
      if (!page) continue
      for (const key of ['total', 'count', 'record_count', 'totalCount']) {
        if (page[key] !== undefined && page[key] !== '' && !Number.isNaN(Number(page[key])))
          return Number(page[key])
      }
    }
  }
  return '-'
}

export async function fetchKyCounts(storeKey: string): Promise<KyCounts> {
  const vid = KY_STORES[storeKey]
  const out: KyCounts = { members: '-', visitors: '-', mcards: '-', contracts: '-' }
  const tasks: [keyof KyCounts, string, Record<string, unknown>][] = [
    [
      'members',
      '/member/api/getmembersbycondwithpager',
      { page_index: 1, page_size: 1, cond: '', consultant_id: -1 }
    ],
    [
      'visitors',
      '/member/api/getvisitors',
      {
        page: '1',
        page_size: '1',
        cond: '',
        source_id: -1,
        activity_id: -1,
        activity_code: '',
        consultant_id: -1,
        begin_time: '',
        end_time: '',
        revisit_status: '',
        trainer_id: -1
      }
    ],
    [
      'mcards',
      '/mcard/api/getmcardsbycond',
      { page_index: 1, page_size: 1, cond: '', search: '', consultant_id: -1 }
    ],
    [
      'contracts',
      '/venue/api/getallcontractlist',
      {
        page_index: '1',
        page_size: '1',
        contract_status: '0',
        contract_name: '',
        initiator_emp_name: '',
        venue_signatory_emp_name: '',
        customer_signatory_search: '',
        initiator_start_date: '',
        initiator_end_date: ''
      }
    ]
  ]
  const errors: string[] = []
  for (const [key, path, body] of tasks) {
    try {
      const d = await kyPost(path, { ...common(vid), ...body }, vid)
      out[key] = extractTotal(d)
    } catch (error) {
      errors.push(String((error as { message?: string }).message ?? error))
    }
  }
  if (Object.values(out).every((value) => value === '-')) {
    throw new Error(errors[0] || '上游响应中没有可识别的计数字段')
  }
  return out
}

export interface KyTodayBookings {
  total: number
  trialHits: number
}

function localDate(): string {
  const now = new Date()
  return `${now.getFullYear()}${String(now.getMonth() + 1).padStart(2, '0')}${String(now.getDate()).padStart(2, '0')}`
}

function bookingRows(response: unknown): Record<string, unknown>[] {
  if (!response || typeof response !== 'object') return []
  const data = (response as { data?: unknown }).data
  if (Array.isArray(data))
    return data.filter((row): row is Record<string, unknown> => !!row && typeof row === 'object')
  if (!data || typeof data !== 'object') return []
  const inner = data as Record<string, unknown>
  for (const key of ['reservations', 'list', 'rows']) {
    if (Array.isArray(inner[key])) {
      return (inner[key] as unknown[]).filter(
        (row): row is Record<string, unknown> => !!row && typeof row === 'object'
      )
    }
  }
  return []
}

function isTrialBooking(row: Record<string, unknown>): boolean {
  return [
    'm_name',
    'member_name',
    'course_name',
    'course_title',
    'card_title',
    'card_name',
    'remark'
  ].some((key) => String(row[key] ?? '').includes('体验'))
}

export async function fetchKyToday(storeKey: string, date?: string): Promise<KyTodayBookings> {
  const vid = KY_STORES[storeKey]
  const d = date ? date.replace(/-/g, '') : localDate()
  const list: Record<string, unknown>[] = []
  for (const path of ['course/api/queryreversionleague', 'course/api/queryreversionprivate']) {
    let previousPageSignature = ''
    for (let page = 1; page <= 100; page++) {
      const rows = bookingRows(
        await kyPost(
          path,
          {
            ...common(vid),
            page_index: page,
            page_size: 200,
            s_date: d,
            e_date: d,
            status_code: 'all',
            ...(path.includes('league') ? { course_type: 0 } : {}),
            course_id: 0,
            coach_id: 0,
            m_card_id: 0,
            search: ''
          },
          vid
        )
      )
      const signature = JSON.stringify(rows)
      if (page > 1 && signature === previousPageSignature) break
      previousPageSignature = signature
      list.push(...rows.map((row) => ({ ...row, __source: path })))
      if (rows.length < 200) break
    }
  }
  const unique = [
    ...new Map(
      list.map((row) => {
        const id = row.id ?? row.reservation_id
        const fallback = `${row.m_id ?? row.member_id ?? row.m_name ?? ''}:${row.start_time ?? row.course_date ?? ''}:${row.course_name ?? ''}`
        return [`${row.__source}:${id ?? fallback}`, row]
      })
    ).values()
  ]
  const active = unique.filter((row) => {
    const status = String(row.status_desc ?? row.status_name ?? row.status ?? '')
    return !/(取消|爽约|作废|未到)/u.test(status)
  })
  return { total: active.length, trialHits: active.filter(isTrialBooking).length }
}

export interface KyMemberRow {
  memberId: string
  name: string
  phone: string
  source: string
  consultant: string
  createdAt: string
}

function rawPhone(v: unknown): string {
  const digits = String(v ?? '').replace(/\D/g, '')
  // 保留以1开头的11位大陆手机号；座机或其他原样返回数字串
  return /^1\d{10}$/.test(digits) ? digits : digits.slice(0, 16)
}

function pick(row: Record<string, unknown>, keys: string[]): string {
  for (const k of keys) {
    if (row[k] !== undefined && row[k] !== null && row[k] !== '') return String(row[k]).slice(0, 60)
  }
  return ''
}

export async function fetchKyMembers(
  storeKey: string,
  cond: string,
  limit = 20
): Promise<KyMemberRow[]> {
  const vid = KY_STORES[storeKey]
  const resp = await kyPost<{
    data?: { members?: Record<string, unknown>[]; list?: Record<string, unknown>[] }
  }>(
    'member/api/getmembersbycondwithpager',
    {
      ...common(vid),
      page_index: 1,
      page_size: Math.min(limit, 10000),
      cond,
      consultant_id: -1
    },
    vid
  )
  const rows = [...(resp.data?.members ?? []), ...(resp.data?.list ?? [])]
  return rows.slice(0, limit || Infinity).map((r) => ({
    memberId: pick(r, ['member_id', 'id']),
    name: pick(r, ['name', 'member_name']),
    phone: rawPhone(r.phone ?? r.mobile),
    source: pick(r, ['source_title', 'source']),
    consultant: pick(r, ['consultant_name', 'consultant']),
    createdAt: (() => {
      const t = Number(r.create_time ?? 0)
      return t > 0 ? new Date(t * 1000).toISOString().slice(0, 10) : ''
    })()
  }))
}
