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

/**
 * 体验课类型（就是 `leads.grade` 这一列）—— 与 admin-web `leads/index.vue` 的 `TRIAL_TYPES` **逐字同源**。
 *
 * 历史（真实数据语义分裂）：这一列原本是「客户分级 A/B/C」，网页端后来把它改成
 * 「体验课类型」（注释写着「替代原客户分级」），小程序没跟上：
 *   · 首页把网页写入的「私教小班」拼成「私教小班级」；
 *   · 编辑页 picker 只有 A/B/C，`indexOf('私教小班')` 得 -1 → 显示「未分级」，用户改不动也看不明白。
 * 现在两端统一到这一组字面量。**改这里必须同时改 admin-web**，否则立刻又是一次语义分裂；
 * 更好的收敛是后端下发 enums（与 LEAD_SOURCES 一样），但那是后端契约改动，不在本轮范围。
 */
export const LEAD_TRIAL_TYPES = ['定制私教', '私教小班', '精品团课', '其他'] as const
export type LeadTrialType = (typeof LEAD_TRIAL_TYPES)[number]

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
  /** 逐节体验课卡片（后端 trial_cards JSON 透传；网页端同一结构） */
  trialCards?: Array<{
    session: number
    date?: string
    time: string
    topic: string
    teacher: string
    couponName: string
    voucherCode: string
    redeem: number | null
    total: number | null
    attended: boolean
    noShow: boolean
    cancelled: boolean
  }>
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
