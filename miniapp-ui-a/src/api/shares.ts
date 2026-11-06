/**
 * 客户分享页（谈单工具）状态接口
 *
 *  - GET  /shares/current?type=sales   → 本人名下最近一条 sales 分享
 *  - POST /shares/disable {type:'sales'}
 *
 * 后端口径（ShareController）：
 *  - 端点整体走 MGMT 门禁 `assertCanManageSales`（R_SUPER / R_MANAGER），与小程序
 *    `requireFeature('sales')` 同口径；非 MGMT 角色会拿到 403。
 *  - `token` / `code` 只有 `token_source='server'` 的行才下发；来源不明的存量行
 *    返回 `needsRepublish:true`（链接实际打不开），前端必须提示「请重新开启分享」，
 *    不能显示成「分享中」。
 *  - `views` 来自快照 payload.share.views。
 *
 * 客户链接构成（与 admin-web 逐字节一致）：`${hash 路由基址}#/s/{code}`。
 * web 端是 `${origin}${pathname}#/s/{code}`，小程序里没有 window，用 VITE_SHARE_BASE 取代。
 */

import { http, HttpError } from '@/utils/http'

export type ShareType = 'sales'

/** 对外分享基址（生产默认 https://oa.nbyimai.com；尾部斜杠统一裁掉，避免 //#/s） */
export const SHARE_BASE: string =
  (import.meta.env.VITE_SHARE_BASE as string | undefined)?.replace(/\/+$/, '') || 'https://oa.nbyimai.com'

export interface ShareStatus {
  enabled: boolean
  token: string | null
  code: string | null
  views: number
  /** 启用中但分享码不可信 → 必须重新开启换发新码 */
  needsRepublish: boolean
  /** 超管查看他人分享 */
  viewingOther: boolean
  ownerName: string | null
  ownerUserId: number | null
}

function obj(v: unknown): Record<string, unknown> {
  return v && typeof v === 'object' && !Array.isArray(v) ? (v as Record<string, unknown>) : {}
}

function str(v: unknown): string {
  return typeof v === 'string' ? v : typeof v === 'number' ? String(v) : ''
}

function nullableStr(v: unknown): string | null {
  const s = str(v).trim()
  return s ? s : null
}

function bool(v: unknown): boolean {
  if (typeof v === 'boolean') return v
  if (typeof v === 'number') return v === 1
  if (typeof v === 'string') return v === '1' || v.toLowerCase() === 'true'
  return false
}

/** 空态（未发布过 / 全部字段缺失时的安全默认值） */
export const EMPTY_SHARE: ShareStatus = {
  enabled: false,
  token: null,
  code: null,
  views: 0,
  needsRepublish: false,
  viewingOther: false,
  ownerName: null,
  ownerUserId: null
}

/** GET /shares/current?type=sales */
export async function fetchCurrentShare(type: ShareType = 'sales'): Promise<ShareStatus> {
  const raw = await http.get<unknown>('/shares/current', { type }, { silent: true })
  const o = obj(raw)
  if (!Object.keys(o).length) return { ...EMPTY_SHARE }
  const code = nullableStr(o.code) ?? nullableStr(o.token)
  return {
    enabled: bool(o.enabled),
    token: nullableStr(o.token),
    code,
    views: Number.isFinite(Number(o.views)) ? Number(o.views) : 0,
    needsRepublish: bool(o.needsRepublish),
    viewingOther: bool(o.viewingOther),
    ownerName: nullableStr(o.ownerName),
    ownerUserId: Number.isFinite(Number(o.ownerUserId)) ? Number(o.ownerUserId) : null
  }
}

/** 客户链接：`${VITE_SHARE_BASE}/#/s/{code}`（与 web 端 hash 路由一致） */
export function buildShareLink(code: string): string {
  const c = (code ?? '').trim()
  if (!c) return ''
  return `${SHARE_BASE}/#/s/${encodeURIComponent(c)}`
}

/**
 * 停用本人分享：POST /shares/disable {type:'sales'}
 *
 * 为什么要在这里做一次错误翻译：http 层非 2xx 只读响应体的 `message` 字段，
 * 而 disable 的两个可读错误（503 结构未升级 / 422 未找到本人分享）用的是
 * `{errno, emsg}` 形态 —— 不翻译就会退化成「服务异常（503）」，用户无从下手。
 */
export async function disableShare(type: ShareType = 'sales'): Promise<number> {
  try {
    const res = await http.post<unknown>('/shares/disable', { type }, { silent: true })
    const affected = obj(res).affected
    return Number.isFinite(Number(affected)) ? Number(affected) : 0
  } catch (e) {
    throw new HttpError(readableDisableError(e), e instanceof HttpError ? e.code : -1, e instanceof HttpError ? e.statusCode : undefined)
  }
}

function readableDisableError(e: unknown): string {
  const status = e instanceof HttpError ? e.statusCode : undefined
  if (status === 503) return '数据库结构升级尚未完成（分享开关字段缺失），请稍后再试'
  if (status === 422) return '没有找到可以停用的分享记录，请到电脑端「营销工具 → 谈单工具」确认'
  if (status === 403) return '当前账号无权停用该分享'
  if (e instanceof HttpError && e.message) return e.message
  return '停用失败，请检查网络后重试'
}
