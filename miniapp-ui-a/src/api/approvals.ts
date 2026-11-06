import { http } from '@/utils/http'
import type { ApprovalItem, PageResult } from '@/types'

/**
 * POST /approvals 的提交体（字段与校验口径逐条照后端 ApprovalController::store 写，不照 web 表单猜）：
 *  - customerName  required|string|max:20
 *  - cardName      required|string|max:40
 *  - standardPrice required|integer|min:0   ← 单位是「元」的整数，不是分（见下）
 *  - requestPrice  required|integer|min:0
 *  - reason        nullable|string|max:200
 *  - venue         nullable|string|in:绿地店,东部店（店长会被后端强制改成本人门店，传了也不生效）
 *
 * 金额单位为什么要强调：迁移里是 `unsignedBigInteger('standard_price')`、模型无 casts、
 * web 端 `fmtPrice(v) => \`¥${v.toLocaleString()}\`` 直接展示 —— 全链路都是「元」。
 * 小程序审批列表此前按 `(v/100)` 显示（把 3200 元显示成 ¥32），本次一并修正（见 approvals.vue）。
 */
export interface CreateApprovalPayload {
  customerName: string
  /** 卡项名称，如「VIP私教50节」 */
  cardName: string
  /** 标准价（元，整数） */
  standardPrice: number
  /** 申请价（元，整数）；后端要求必须 < standardPrice，否则 422「申请价应低于标准价」 */
  requestPrice: number
  reason?: string
  venue?: '绿地店' | '东部店'
}

export function fetchApprovals(params?: { status?: string; current?: number; size?: number }) {
  return http.get<PageResult<ApprovalItem>>('/approvals', params as Record<string, unknown>)
}

/**
 * 发起价格审批（店长及以上）。后端另有角色闸：非 SUPER/MANAGER 返 403「仅店长及以上可发起价格审批」，
 * 与本页 requireFeature('approvals') 的 MGMT 口径一致，不会放开给一线。
 */
export function createApproval(body: CreateApprovalPayload) {
  return http.post<ApprovalItem>('/approvals', body as unknown as Record<string, unknown>)
}

export function decideApproval(id: number, decision: '初审通过' | '终审通过' | '驳回' | '关联成交') {
  return http.post<ApprovalItem>(`/approvals/${id}/decide`, { decision })
}

