import { http } from '@/utils/http'
import type { PageResult, TaskItem } from '@/types'

export function fetchTasks(params?: { status?: string; current?: number; size?: number }) {
  return http.get<PageResult<TaskItem>>('/tasks', params as Record<string, unknown>)
}

export function updateTask(id: number, patch: Partial<Pick<TaskItem, 'status'>>) {
  return http.patch<TaskItem>(`/tasks/${id}`, patch as Record<string, unknown>)
}
