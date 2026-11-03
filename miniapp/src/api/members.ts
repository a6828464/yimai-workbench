/**
 * 会员管理（P0 口径：查 + 看卡项 + 少量工作流字段）
 *
 * 接口全部复用工作台同一套 /api（Laravel + Sanctum），后端零改动：
 *   GET   /customers                列表（服务端分页；list=清单名 走同一份清单扫描）
 *   GET   /customers/list-counts    清单徽标计数（必须带 type=member，否则徽标按全部客户算）
 *   GET   /customers/{id}           客户 360（主档 + 留痕 + 按手机号关联的前端客资）
 *   PATCH /customers/{id}           仅白名单字段：renewalPlan/decline/stopReason/expectedReturn/
 *                                   lastTouch/needsHelp/inRevive/birthday
 *
 * 角色可见范围由后端 scopeCustomersForUser() 决定，前端不做二次裁剪。
 */

import { http } from '@/utils/http'
import type {
  CustomerDetail,
  CustomerListResult,
  MiniappCustomer,
  MemberListCounts
} from '@/types'

export interface CustomerQuery {
  /** 姓名模糊 */
  name?: string
  /** 手机号 / 尾号模糊 */
  phone?: string
  /** 清单名（中文，见 MEMBER_LIST_OF_TAB）：待续课 / 出勤降低 / VIP / 预流失 / 待复活 / 炸弹会员 */
  list?: string
  venue?: string
  consultant?: string
  evaluationStatus?: string
  /** member = 只算正式会员（排除 layer=P5 的未成交客资） */
  type?: 'member' | 'lead'
  current?: number
  size?: number
}

/** 会员页页签 → 后端清单名。与 admin-web/src/views/yimai/members/index.vue 的 TAB_KEY_TO_LIST 同源 */
export const MEMBER_LIST_OF_TAB: Record<string, string> = {
  renewal: '待续课',
  decline: '出勤降低',
  vip: 'VIP',
  predrop: '预流失',
  revive: '待复活',
  bomb: '炸弹会员'
}

/** 会员页签（key + 文案 + 说明）。说明直接来自后端分层口径，不在前端另写一份 */
export const MEMBER_TABS: { key: string; label: string; hint?: string }[] = [
  { key: 'all', label: '总览' },
  { key: 'renewal', label: '待续课', hint: '命中续费判定：课时尾段 / 当前卡临期' },
  { key: 'decline', label: '出勤降低', hint: '近三档 30 天窗口逐月下降' },
  { key: 'vip', label: 'VIP', hint: '会员卡实收金额达阈值' },
  { key: 'predrop', label: '预流失', hint: '15–30 天未到店' },
  { key: 'revive', label: '待复活', hint: '超期未到店且仍有资产' },
  { key: 'bomb', label: '炸弹会员', hint: '次卡有余额、卡过期超 6 个月、且 6 个月未到店' }
]

export function fetchCustomers(query: CustomerQuery = {}) {
  const data: Record<string, unknown> = { type: 'member' }
  Object.entries(query).forEach(([k, v]) => {
    if (v !== undefined && v !== null && v !== '') data[k] = v
  })
  return http.get<CustomerListResult>('/customers', data)
}

/** 徽标计数：必须带 type，否则徽标数与点进去的行数不同源 */
export function fetchMemberListCounts(type: 'member' | 'lead' = 'member') {
  return http.get<MemberListCounts>('/customers/list-counts', { type })
}

export function fetchCustomerDetail(id: number) {
  return http.get<CustomerDetail>(`/customers/${id}`)
}

/** 只允许后端白名单里的字段；传其它键后端会静默丢弃（不是报错），所以这里显式收窄 */
export interface CustomerPatch {
  renewalPlan?: { intent: string; time: string; amount: string; course: string; issue: string }
  decline?: { reason: string; solution: string }
  stopReason?: string
  expectedReturn?: string
  lastTouch?: string | null
  needsHelp?: boolean
  inRevive?: boolean
  birthday?: string | null
}

export function updateCustomer(id: number, patch: CustomerPatch, action = '修改') {
  return http.patch<MiniappCustomer>(`/customers/${id}`, {
    ...patch,
    _action: action
  } as Record<string, unknown>)
}
