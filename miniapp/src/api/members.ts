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
  /** 人工经营状态（待复活会联动 in_revive；见后端 Customer::MEMBER_STATES） */
  memberState?: string
}

export function updateCustomer(id: number, patch: CustomerPatch, action = '修改') {
  return http.patch<MiniappCustomer>(`/customers/${id}`, {
    ...patch,
    _action: action
  } as Record<string, unknown>)
}


/* ==========================================================================
 * 续费评估（GET/PUT /customers/{id}/renewal-evaluation）
 *
 * 口径与网页端 admin-web/src/api/yimai.ts 完全一致：
 *  总分 = 出勤分 + 卡项窗口分 + 5 个维度 + 风险扣分（0..100）
 *  出勤：>=8 次 25 / >=6 次 20 / >=4 次 10 / >0 次 5 / 0 次 0
 *  卡项窗口：后端给 0 或 10（<=10 节视为临期）
 *  等级：>=70 高机会 / >=40 重点培育 / 其余 风险修复
 * 前端算分只是为了「填的时候实时看到」，最终分数仍以服务端为准（PUT 会返回 evaluation）。
 * ========================================================================== */

export interface RenewalEvaluationAnswers {
  goal: string
  feedback: string
  wechat: string
  intent: string
  service: string
  risks: string[]
  /** 只读，服务端回填 */
  attendanceCount?: number
  cardWindow?: number
}

export interface RenewalEvalOption {
  key: string
  label: string
  score: number
}

export interface RenewalEvalDimension {
  key: 'goal' | 'feedback' | 'wechat' | 'intent' | 'service'
  title: string
  hint: string
  options: RenewalEvalOption[]
}

export interface RenewalEvaluationContext {
  attendanceCount: number
  cardWindow: number
  lastVisit: string | null
  remainTimes: number | null
  expireDate: string | null
  attendM1: number
  attendM2: number
  attendM3: number
  latest: {
    id: number
    score: number
    level: 'high' | 'medium' | 'low'
    answers: RenewalEvaluationAnswers
    remark?: string
    evaluatorName: string
    evaluatedAt: string
  } | null
}

export const EVAL_DIMENSIONS: RenewalEvalDimension[] = [
  {
    key: 'goal',
    title: '训练效果与目标进展',
    hint: '根据训练计划、体测进展和客户认可程度单选',
    options: [
      { key: 'written_plan', label: '有明确计划并与客户确认', score: 15 },
      { key: 'agreed_goal', label: '已形成客户认可的阶段目标', score: 10 },
      { key: 'visible_progress', label: '有可感知进步，但目标尚不清晰', score: 5 },
      { key: 'none', label: '暂无明确目标或进展', score: 0 }
    ]
  },
  {
    key: 'feedback',
    title: '课后反馈与满意度',
    hint: '按最近 30 天实际课后反馈情况单选',
    options: [
      { key: 'replied', label: '多次询问且获得有效回复', score: 15 },
      { key: 'no_reply', label: '已主动询问，但回复较少', score: 5 },
      { key: 'none', label: '没有课后反馈记录', score: 0 }
    ]
  },
  {
    key: 'wechat',
    title: '企业微信有效沟通与客户回应',
    hint: '有效沟通指客户表达感受、目标、困难、课程或续费问题',
    options: [
      { key: 'proactive', label: '客户主动咨询或主动反馈', score: 15 },
      { key: 'two_way', label: '近 30 天有两次以上有效双向沟通', score: 10 },
      { key: 'shallow', label: '客户有回复，但沟通较浅', score: 5 },
      { key: 'no_response', label: '多次联系无有效回应', score: 0 },
      { key: 'refused', label: '明确拒绝继续沟通或续费', score: 0 }
    ]
  },
  {
    key: 'intent',
    title: '续费意向信号',
    hint: '是否主动或正向讨论课种、价格、档期与续费时间',
    options: [
      { key: 'asked_plan', label: '主动询问方案、价格或档期', score: 10 },
      { key: 'positive', label: '正向回应续费沟通', score: 8 },
      { key: 'uncertain', label: '有意向但尚未明确', score: 4 },
      { key: 'none', label: '暂无续费意向信号', score: 0 }
    ]
  },
  {
    key: 'service',
    title: '服务体验与问题处理',
    hint: '选择最符合当前客户服务状态的一项',
    options: [
      { key: 'resolved', label: '针对性解决问题并获客户认可', score: 10 },
      { key: 'handled', label: '已完成服务处理，等待后续反馈', score: 8 },
      { key: 'normal', label: '暂无问题，服务过程正常', score: 5 },
      { key: 'unresolved', label: '仍有待解决的问题或建议', score: 0 }
    ]
  }
]

export const EVAL_RISKS: RenewalEvalOption[] = [
  { key: 'purchase_refused', label: '明确推脱或拒绝购买', score: -10 },
  { key: 'long_no_response', label: '长期联系无回应', score: -10 },
  { key: 'complaint_unresolved', label: '投诉或服务问题尚未解决', score: -15 }
]

/** 等级文案（与网页端 renewalLevelLabel 同源） */
export function evalLevelLabel(level?: string | null): string {
  const map: Record<string, string> = { high: '高机会', medium: '重点培育', low: '风险修复' }
  return map[String(level || '')] || '待评估'
}

/** 实时算分（最终以服务端返回为准） */
export function evalTotalScore(answers: RenewalEvaluationAnswers): number {
  const attendance = answers.attendanceCount ?? 0
  const attendanceScore =
    attendance >= 8 ? 25 : attendance >= 6 ? 20 : attendance >= 4 ? 10 : attendance > 0 ? 5 : 0
  let score = attendanceScore + Math.min(10, Math.max(0, answers.cardWindow ?? 0))
  for (const dim of EVAL_DIMENSIONS) {
    score += dim.options.find((o) => o.key === answers[dim.key])?.score ?? 0
  }
  for (const risk of answers.risks ?? []) {
    score += EVAL_RISKS.find((r) => r.key === risk)?.score ?? 0
  }
  return Math.min(100, Math.max(0, Math.round(score)))
}

export function fetchRenewalEvaluation(id: number): Promise<RenewalEvaluationContext> {
  return http.get<RenewalEvaluationContext>(`/customers/${id}/renewal-evaluation`)
}

export function saveRenewalEvaluation(
  id: number,
  data: { answers: RenewalEvaluationAnswers; remark: string }
): Promise<{ evaluation: RenewalEvaluationContext['latest']; context: RenewalEvaluationContext }> {
  return http.put(`/customers/${id}/renewal-evaluation`, {
    answers: data.answers,
    remark: data.remark
  } as Record<string, unknown>)
}
