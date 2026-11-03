import { http } from '@/utils/http'
import type { ApprovalItem, PageResult } from '@/types'

export function fetchApprovals(params?: { status?: string; current?: number; size?: number }) {
  return http.get<PageResult<ApprovalItem>>('/approvals', params as Record<string, unknown>)
}

export function decideApproval(id: number, decision: '初审通过' | '终审通过' | '驳回' | '关联成交') {
  return http.post<ApprovalItem>(`/approvals/${id}/decide`, { decision })
}
