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
  if (USE_BACKEND) {
    const form: Record<string, unknown> = { ...body }
    if (venueId && form['venue_id'] === undefined) form['venue_id'] = venueId
    const data = await apiPost<{ errno?: string | number } & Record<string, unknown>>('/ky/call', {
      path,
      form
    })
    if (String(data?.errno ?? '0') !== '0') {
      throw new Error(`${path} errno=${data?.errno} ${data?.emsg ?? ''}`)
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
  const resp = await fetch(`/ky${path}`, {
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
      return kyPost(path, body, venueId)
    }
    throw new Error(`${path} errno=${data.errno} ${data.emsg ?? ''}`)
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
      if (key === 'count' && obj === resp) continue
      if (obj[key] !== undefined && obj[key] !== '' && !Number.isNaN(Number(obj[key])))
        return Number(obj[key])
    }
    const fenye = obj.fenye as Record<string, unknown> | undefined
    if (fenye) {
      for (const key of ['record_count', 'total', 'count']) {
        if (fenye[key] !== undefined && fenye[key] !== '') return Number(fenye[key])
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
  for (const [key, path, body] of tasks) {
    try {
      const d = await kyPost(path, { ...common(vid), ...body }, vid)
      out[key] = extractTotal(d)
    } catch {
      /* 单项失败不影响其余 */
    }
  }
  return out
}

export interface KyTodayBookings {
  total: number
  trialHits: number
}

export async function fetchKyToday(storeKey: string, date?: string): Promise<KyTodayBookings> {
  const vid = KY_STORES[storeKey]
  const d = date ? date.replace(/-/g, '') : new Date().toISOString().slice(0, 10).replace(/-/g, '')
  const resp = await kyPost<{
    data?: {
      reservations?: Record<string, unknown>[]
      list?: Record<string, unknown>[]
      rows?: Record<string, unknown>[]
    }
  }>(
    '/course/api/queryreversionleague',
    {
      ...common(vid),
      page_index: 1,
      page_size: 200,
      s_date: d,
      e_date: d,
      status_code: 'all',
      course_type: 0,
      course_id: 0,
      coach_id: 0,
      m_card_id: 0,
      search: ''
    },
    vid
  )
  // 兼容 data.reservations / data.list / data.rows 多种返回结构
  const inner = resp.data
  const list = [...(inner?.reservations ?? []), ...(inner?.list ?? []), ...(inner?.rows ?? [])]
  const trials = list.filter((r) => String(r.m_name ?? '').includes('体验')).length
  return { total: list.length, trialHits: trials }
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
    '/member/api/getmembersbycondwithpager',
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
