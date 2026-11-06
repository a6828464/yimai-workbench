/**
 * 经营看板接口（工作台首页用）
 *
 * 口径来源：backend/app/Http/Controllers/AnalyticsController.php
 *  - GET /analytics/summary  角色收窄后的客户/会员/续费/任务指标
 *  - GET /analytics/trends?start&end  按日 × 门店的经营走势 + 区间汇总
 *
 * 类型为什么写在这里而不是 src/types：
 *  - src/types 是并行任务的共享文件，本任务不允许改；
 *  - 这两个端点的返回形状只有工作台消费，就近声明比塞进大杂烩类型文件更好维护。
 *
 * 两个请求都用 silent：页面自己渲染失败态（ym-empty + 重试），
 * 不要再让 http 层额外弹一次 toast（工作台首屏一次进 4 个请求，弹 4 个 toast 无法收场）。
 */

import { http } from '@/utils/http'

/* ==========================================================================
 * GET /analytics/summary
 * ========================================================================== */

/** 单课型的收入结构（分子=课时耗卡金额，分母=售卡实收，两个分母别混用） */
export interface RevenueMixItem {
  label: string
  amount: number
  /** 该类耗卡金额 ÷ 售卡金额（结构参照，不是财务耗卡率） */
  ratio: number
  /** 三类耗卡内部占比，相加 = 100 */
  share: number
  classCount: number
}

export interface RevenueMix {
  private: RevenueMixItem
  small: RevenueMixItem
  group: RevenueMixItem
  cardSales: number
  consumptionTotal: number
  degraded: {
    bookingsWithoutAmount: number
    note: string
  }
}

/** 经营看板核心指标（按当前账号可见范围收窄） */
export interface AnalyticsSummary {
  totalCustomers: number
  totalMembers: number
  unassigned: number
  /** 留资分配率（%） */
  assignRate: number
  /** 客户闭环率（%） */
  closureRate: number
  /** 待续课数（= 会员清单「待续课」） */
  renewalTasks: number
  /** 续费已处理率（%） */
  renewalRate: number
  /** 任务完成率（%） */
  taskRate: number
  doneTasks: number
  totalTasks: number
  revenueMix: RevenueMix
}

export function fetchAnalyticsSummary(): Promise<AnalyticsSummary> {
  return http.get<AnalyticsSummary>('/analytics/summary', undefined, { silent: true })
}

/* ==========================================================================
 * GET /analytics/trends
 * ========================================================================== */

/** 单个门店在某一天的经营指标 */
export interface TrendVenueMetrics {
  /** 留资条数 */
  leads: number
  /** 约课/预约人数（CRM 状态 ∪ 随心瑜排课，取大值） */
  booked: number
  /** 到店体验人数 */
  experienced: number
  /** 留资状态机口径的成交条数 */
  deals: number
  /** 售卡张数 */
  cardSales: number
  /** 已签到班次数 */
  classes: number
  /** 售卡实收金额 */
  amount: number
  /** 核销金额 */
  redeem: number
  /** 线上（新媒体登记来源）留资 */
  onlineLeads: number
  onlineVisits: number
  onlineDeals: number
}

/**
 * 一天的走势行。
 *
 * ⚠️ 形状是「一天一条，门店作为二级键平铺」，不是 `{date, venues:[...]}`：
 *   `{ date: '2026-11-04', '绿地店': {...}, '东部店': {...}, '双店': {...} }`
 * 门店键由数据决定（留资 venue 为空时后端落 '双店'，排课 venue 为空时可能是空串），
 * 所以聚合**只能遍历非 date 键求和**，绝不能硬编码两个门店名 —— 新增门店会静默漏算。
 */
export interface TrendDay {
  date: string
  [venueKey: string]: string | TrendVenueMetrics | undefined
}

export interface TrendSummary {
  memberTotal: number
  /** 留资条数（区间内按 lead_date 分桶） */
  leadCount: number
  /** 预约人数 */
  bookingCount: number
  /** 到店人数（三来源并集、按人去重；分子分母同源） */
  visitCount: number
  visitBreakdown: {
    fromBooking: number
    fromLeadStatus: number
    fromTrialCard: number
    total: number
    onlineTotal: number
  }
  trialCount: number
  /** 成交人数（到店过的人里的成交） */
  dealCount: number
  cardSalesCount: number
  classCount: number
  dealAmount: number
  redeemAmount: number
  registeredDealCount: number
  registeredDealAmount: number
  /** 成交率（%）= dealCount / visitCount */
  dealRate: number
  /** 留资→到店（%）= visitCount / leadCount */
  leadToVisitRate: number
  onlineLeadCount: number
  onlineVisitCount: number
  onlineDealCount: number
  onlineDealRate: number
  onlineLeadToVisitRate: number
  [key: string]: unknown
}

export interface TrendsResult {
  daily: TrendDay[]
  summary: TrendSummary
  /** 近 30 天有到店记录的客户数 */
  visit30: number
  /** 近 30 天有出勤的客户数（attend_m3 > 0） */
  activeCustomers: number
  /** 三个连续且等长的 30 天窗口（再前 / 前 / 近） */
  attendanceSummary: { m1: number; m2: number; m3: number }
  period: { start: string; end: string }
}

export interface TrendsQuery {
  /** YYYY-MM-DD */
  start: string
  /** YYYY-MM-DD */
  end: string
  /** 门店；不传 = 全门店（后端只接受空串或已存在的门店名） */
  venue?: string
}

export function fetchTrends(query: TrendsQuery): Promise<TrendsResult> {
  const data: Record<string, unknown> = { start: query.start, end: query.end }
  if (query.venue) data.venue = query.venue
  return http.get<TrendsResult>('/analytics/trends', data, { silent: true })
}
