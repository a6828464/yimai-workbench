/**
 * 留资管理（P0 口径：列表 + 新增 + 状态/跟进更新）
 *
 * 复用工作台同一套 /api，后端零改动：
 *   GET    /leads            列表（服务端分页；响应里带 enums = 渠道/下单平台权威清单）
 *   GET    /leads/check      手机号查重（命中会员 / 已留资）
 *   POST   /leads            新增（name / venue / source 必填）
 *   PATCH  /leads/{id}       更新（只提交本次改动的字段更安全）
 *
 * 渠道枚举的**唯一权威**是后端 helpers.php 的 LEAD_SOURCES / ORDER_PLATFORMS；
 * 这里只放一份首屏兜底（与后端同值），列表加载回来后整体替换。
 */

import { http } from '@/utils/http'
import type { LeadCheckResult, LeadEnums, LeadListResult, MiniappLead } from '@/types'

export interface LeadQuery {
  name?: string
  /** 手机号 / 尾号 / 微信 */
  phone?: string
  status?: string
  venue?: string
  dateFrom?: string
  dateTo?: string
  current?: number
  size?: number
}

/** 留资状态（与 admin-web leads/index.vue 的 STATUS_LIST 逐字一致） */
export const LEAD_STATUSES = [
  '新留资',
  '已联系',
  '已约体验',
  '已体验',
  '已成交',
  '爽约',
  '已流失'
] as const

export const LEAD_GRADES = ['', 'A', 'B', 'C'] as const

/** 首屏兜底枚举：与后端 LEAD_SOURCES / ORDER_PLATFORMS 同值，列表返回后被整体替换 */
export const FALLBACK_LEAD_ENUMS: LeadEnums = {
  sources: [
    '大众点评',
    '美团',
    '抖音',
    '抖音直播',
    '抖音私信',
    '视频号',
    '小红书',
    '电话咨询',
    '转介绍',
    '会员转介绍',
    '老会员转介绍',
    '自然到店',
    '潜客激活',
    '到店',
    '抖音周年庆直播'
  ],
  orderPlatforms: ['大众点评', '美团', '抖音', '抖音直播', '小红书', '视频号', '其他']
}

/**
 * 新增表单的「精简来源清单」（v3.8.0 同口径）：
 * 全量枚举里 `抖音/抖音直播/抖音私信/抖音周年庆直播`、`转介绍/会员转介绍/老会员转介绍`
 * 是刻意并存的近重复项，日常录入只给主项，其余走 picker 的完整清单。
 */
export const LEAN_SOURCES = [
  '大众点评',
  '美团',
  '抖音',
  '视频号',
  '小红书',
  '电话咨询',
  '转介绍',
  '自然到店',
  '到店',
  '潜客激活'
]

export function fetchLeads(query: LeadQuery = {}) {
  const data: Record<string, unknown> = {}
  Object.entries(query).forEach(([k, v]) => {
    if (v !== undefined && v !== null && v !== '') data[k] = v
  })
  return http.get<LeadListResult>('/leads', data)
}

export function checkLead(phone: string) {
  return http.get<LeadCheckResult>('/leads/check', { phone })
}

/** 新增/编辑提交体：只收后端 $leadFields 白名单里的键 */
export interface LeadPayload {
  name: string
  venue: string
  source: string
  phone?: string
  wechat?: string
  demand?: string
  orderPlatform?: string
  referrer?: string
  status?: string
  grade?: string
  serviceTeacher?: string
  leadDate?: string
  dealCard?: string
  dealAmount?: number | null
  dealAt?: string
  redeemAmount?: number | null
  voucherCode?: string
  couponName?: string
  couponTotal?: number | null
  couponRemaining?: number | null
  remark?: string
}

export function createLead(payload: LeadPayload) {
  return http.post<{ id: number; warnings: { message: string }[] }>('/leads', {
    ...payload
  } as Record<string, unknown>)
}

export function updateLead(id: number, payload: Partial<LeadPayload>) {
  return http.patch<{ warnings: { message: string }[] }>(`/leads/${id}`, {
    ...payload
  } as Record<string, unknown>)
}

/** 编辑态传参：后端没有 GET /leads/{id}，列表行通过本地草稿带到表单页 */
export const LEAD_DRAFT_KEY = 'yimai-lead-draft'

export function stageLeadDraft(lead: MiniappLead) {
  uni.setStorageSync(LEAD_DRAFT_KEY, lead)
}

export function takeLeadDraft(): MiniappLead | null {
  const v = uni.getStorageSync(LEAD_DRAFT_KEY)
  uni.removeStorageSync(LEAD_DRAFT_KEY)
  return (v || null) as MiniappLead | null
}
