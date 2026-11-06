/**
 * 客户经营池（GET /customers，超管全量视图）
 *
 * 与会员管理（src/api/members.ts，type=member）的区别：
 *  - 经营池**不带 type**，后端 scopedCustomerQuery() 对超管返回全量客户（含未成交客资 layer=P5）；
 *  - 多出分层 / 归属 / 评估等经营维度筛选，正是「经营池」相对「会员管理」的定位差。
 *
 * 参数逐个对齐 backend/app/Http/Controllers/CustomerController.php::index：
 *   name / phone（phone 同时匹配 phone_tail）/ venue / list / layer / owner / status /
 *   consultant / source / type / haveCourse('true'|'false') / remainMax / evaluationStatus /
 *   current / size
 * 其中：
 *  - `consultant=待分配` 是后端的**特殊值**，表示 consultant 为空/null；传空串等于不筛选；
 *  - `evaluationStatus` 只认 未评估 / 高机会 / 重点培育 / 风险修复 / 已过期（其它值后端 match 到 default 即不过滤）；
 *  - `layer` 只认 P0–P5（派生列，见 helpers.php::layerDefinitions()）。
 */

import { http } from '@/utils/http'
import type { MiniappCustomer, PageResult } from '@/types'

export type PoolLayer = 'P0' | 'P1' | 'P2' | 'P3' | 'P4' | 'P5'

export type PoolEvaluation = '未评估' | '高机会' | '重点培育' | '风险修复' | '已过期'

export type PoolType = 'member' | 'lead'

export interface PoolQuery {
  name?: string
  /** 后端同时模糊匹配 phone 与 phone_tail */
  phone?: string
  venue?: string
  /** 五清单名（复用 members.ts 的 MEMBER_LIST_OF_TAB；本页暂不出清单筛选，保留能力） */
  list?: string
  layer?: PoolLayer | ''
  owner?: string
  status?: string
  /** 顾问；'待分配' 表示空归属（后端特殊值） */
  consultant?: string
  source?: string
  type?: PoolType
  haveCourse?: 'true' | 'false'
  remainMax?: number
  evaluationStatus?: PoolEvaluation | ''
  current?: number
  size?: number
}

/** 后端「归属为空」的查询特殊值：写空串会被当成不筛选，必须用这个字面量 */
export const POOL_CONSULTANT_UNASSIGNED = '待分配'

/**
 * 经营分层 P0–P5。
 * label/desc 逐字来自 backend/app/Support/helpers.php::layerDefinitions()，
 * 不在前端另起一套解释——口径只能有一处。
 */
export const POOL_LAYERS: { key: PoolLayer; label: string; desc: string }[] = [
  { key: 'P0', label: '续费窗口', desc: '命中续费判定（课时尾段 / 当前卡临期）' },
  { key: 'P1', label: '高资产低活跃', desc: '停练超阈值、或人工标记待复活（有卡项资产）' },
  { key: 'P2', label: '频次下降', desc: '仍在出勤，但近三档 30 天窗口逐月下降' },
  { key: 'P3', label: '过期有余额', desc: '有过期实例且仍有余额（未命中待续费）' },
  { key: 'P4', label: '可升级', desc: '有卡项资产，未命中以上任何一层' },
  { key: 'P5', label: '新客转化', desc: '无卡项资产（前端客资 / 留资）' }
]

export const POOL_LAYER_MAP: Record<PoolLayer, { label: string; desc: string }> = POOL_LAYERS.reduce(
  (acc, item) => {
    acc[item.key] = { label: item.label, desc: item.desc }
    return acc
  },
  {} as Record<PoolLayer, { label: string; desc: string }>
)

/** 评估状态（后端 match 分支共 5 个取值） */
export const POOL_EVALUATIONS: PoolEvaluation[] = ['未评估', '高机会', '重点培育', '风险修复', '已过期']

/**
 * 门店白名单（两店实际值）。
 * 后端 GET /customers 的 `venue` 是 `where('venue', $v)` 直等匹配、不做白名单校验，
 * 所以候选值必须由前端给全，写错就是「筛出 0 条」而不是报错。
 */
export const POOL_VENUES = ['绿地店', '东部店'] as const

/** GET /customers：分页取经营池（空值不传，避免后端把空串当筛选条件） */
export function fetchCustomerPool(query: PoolQuery = {}) {
  const data: Record<string, unknown> = {}
  Object.entries(query).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') data[key] = value
  })
  return http.get<PageResult<MiniappCustomer>>('/customers', data)
}

export interface CustomerOptionsResult {
  /** 去重后的顾问名（后端只回已填 consultant 的人） */
  consultants: string[]
}

/** GET /customers/options：归属（顾问）筛选项的权威来源 */
export function fetchCustomerOptions() {
  return http.get<CustomerOptionsResult>('/customers/options')
}
