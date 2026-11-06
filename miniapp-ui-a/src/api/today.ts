import { http } from '@/utils/http'
import type { TodoGroup } from '@/types'

/** GET /today/summary：工作台汇总数字卡 */
export function fetchTodaySummary() {
  return http.get<Record<string, unknown>>('/today/summary')
}

/** GET /today/todo：七组待办 */
export function fetchTodayTodo() {
  return http.get<Record<string, TodoGroup['items']> | TodoGroup[]>('/today/todo')
}

/** POST /today/todo/action */
export function todoAction(payload: {
  type: string
  key: string
  action: string
  remark?: string
  customerId?: number
  leadId?: number
  touch?: boolean
}) {
  return http.post('/today/todo/action', payload)
}

/** GET /today/followups */
export function fetchFollowups() {
  return http.get<unknown[]>('/today/followups')
}

/** GET /today/alerts */
export function fetchAlerts() {
  return http.get<unknown[]>('/today/alerts')
}
