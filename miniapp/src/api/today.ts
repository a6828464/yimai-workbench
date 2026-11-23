import { http } from '@/utils/http'
import type { TodoGroup } from '@/types'

/** GET /today/summary：工作台汇总数字卡 */
export function fetchTodaySummary() {
  return http.get<Record<string, unknown>>('/today/summary')
}

/**
 * GET /today/todo：八组待办（bookings / renewals / churnRisks / birthdays /
 * trials / reviews / newLeads / tasks）。
 *
 * 返回形态历史上出现过两种（Record<组名, 条目[]> 与 TodoGroup[]），页面层兼容，
 * 这里保持宽类型不放窄 —— 类型收窄只会让「后端换形态」变成编译期错误而不是运行时降级。
 */
export function fetchTodayTodo() {
  return http.get<Record<string, TodoGroup['items']> | TodoGroup[]>('/today/todo')
}

/**
 * POST /today/todo/action 的入参。
 *
 * v3.19.0 起 `已沟通` 与 `已祝福` 都会带 remark（生日祝福的送法也要留痕），
 * 但 `remark` 仍是可选的：其它动作（已到店 / 爽约 / 已提交 / 已首响）一键闭环，不带内容。
 */
export interface TodoActionPayload {
  type: string
  key: string
  action: string
  /** 沟通/祝福内容；空串不发（后端 nullable，收到空串也会落一条空备注） */
  remark?: string
  customerId?: number
  leadId?: number
  /** 是否计入「已触达」（后端据此推进 lastTouch，仅沟通类动作用） */
  touch?: boolean
}

/** POST /today/todo/action */
export function todoAction(payload: TodoActionPayload) {
  // http.post 的 body 类型是 Record<string, unknown>（给的是「任意 JSON」而不是业务约束），
  // 这里用显式接口再转一次：调用方拿到的是有字段约束的签名，写错字段名在编译期就报。
  return http.post('/today/todo/action', payload as unknown as Record<string, unknown>)
}

/** GET /today/followups */
export function fetchFollowups() {
  return http.get<unknown[]>('/today/followups')
}

/** GET /today/alerts */
export function fetchAlerts() {
  return http.get<unknown[]>('/today/alerts')
}
