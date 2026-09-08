import { USE_BACKEND, apiGet, apiPut } from './backend'
import { useAiConfigStore } from '@/store/modules/ai-config'
import { useUserStore } from '@/store/modules/user'
import { useYimaiStore } from '@/store/modules/yimai'
import type { YimaiAuditLog } from '@/store/modules/yimai'

export type SystemLogChannel = 'runtime' | 'error'

export interface SystemLogRecord {
  time: string
  level: string
  message: string
  context?: unknown
}

export interface RetentionSettings {
  systemLogDays: number | null
  auditLogDays: number | null
  modelGenerationDays: number | null
}

export interface AuditLogMetadata {
  operators: string[]
  modules: string[]
  actions: string[]
}

export interface ModelGenerationOperator {
  id: number | string
  name: string
}

export interface ModelGenerationRecord {
  id: number | string
  createdAt: string
  operatorName: string
  operatorRole: string
  featureType: string
  source: string
  provider: string
  model: string
  status: string
  latencyMs: number | null
  inputTokens: number | null
  outputTokens: number | null
  totalTokens: number | null
  outputPreview: string
  errorMessage: string | null
}

export interface ModelGenerationMetadata {
  operators?: Array<ModelGenerationOperator | string>
  featureTypes?: string[]
  statuses?: string[]
}

const DEFAULT_RETENTION: RetentionSettings = {
  systemLogDays: 7,
  auditLogDays: null,
  modelGenerationDays: 90
}
const DEMO_RETENTION_KEY = 'yimai-system-retention'

export function getSystemLogs(params: {
  channel: SystemLogChannel
  date?: string
  keyword?: string
  level?: string
}): Promise<{ records: SystemLogRecord[]; files?: string[] }> {
  if (USE_BACKEND) {
    return apiGet('/system/logs', params as Record<string, unknown>)
  }

  const now = new Date()
  const date = params.date || formatDate(now)
  const records: SystemLogRecord[] = [
    {
      time: `${date} 09:31:18`,
      level: 'INFO',
      message: '应用服务运行正常',
      context: { mode: 'demo', uptime: '2d 6h' }
    },
    {
      time: `${date} 09:28:04`,
      level: 'WARN',
      message: '模型服务响应时间超过预警阈值',
      context: { provider: 'DeepSeek', latencyMs: 3280 }
    },
    {
      time: `${date} 08:55:42`,
      level: 'ERROR',
      message: '演示错误日志：外部服务请求失败',
      context: { retryable: true }
    }
  ]
  const channelRecords =
    params.channel === 'error' ? records.filter((item) => item.level === 'ERROR') : records
  const keyword = params.keyword?.trim().toLowerCase()
  return Promise.resolve({
    records: channelRecords.filter(
      (item) =>
        (!params.level || item.level === params.level) &&
        (!keyword ||
          item.message.toLowerCase().includes(keyword) ||
          JSON.stringify(item.context).toLowerCase().includes(keyword))
    ),
    files: [`${params.channel}-${date}.log`]
  })
}

export function getRetentionSettings(): Promise<RetentionSettings> {
  if (USE_BACKEND) return apiGet('/system/retention')
  const saved = localStorage.getItem(DEMO_RETENTION_KEY)
  if (!saved) return Promise.resolve({ ...DEFAULT_RETENTION })
  try {
    return Promise.resolve({ ...DEFAULT_RETENTION, ...JSON.parse(saved) })
  } catch {
    return Promise.resolve({ ...DEFAULT_RETENTION })
  }
}

export function updateRetentionSettings(settings: RetentionSettings): Promise<RetentionSettings> {
  if (USE_BACKEND) {
    return apiPut('/system/retention', settings as unknown as Record<string, unknown>)
  }
  localStorage.setItem(DEMO_RETENTION_KEY, JSON.stringify(settings))
  return Promise.resolve({ ...settings })
}

export function getAuditLogs(params: {
  current?: number
  size?: number
  operator?: string
  module?: string
  action?: string
  start?: string
  end?: string
}): Promise<{
  records: YimaiAuditLog[]
  total: number
  current: number
  size: number
  metadata: AuditLogMetadata
}> {
  if (USE_BACKEND) {
    return apiGet<{
      records?: YimaiAuditLog[]
      total?: number
      current?: number
      size?: number
      metadata?: Partial<AuditLogMetadata>
      operators?: string[]
      modules?: string[]
      actions?: string[]
    }>('/audit-logs', params as Record<string, unknown>).then((data) => ({
      records: data.records ?? [],
      total: data.total ?? 0,
      current: data.current ?? params.current ?? 1,
      size: data.size ?? params.size ?? 20,
      metadata: {
        operators: data.metadata?.operators ?? data.operators ?? [],
        modules: data.metadata?.modules ?? data.modules ?? [],
        actions: data.metadata?.actions ?? data.actions ?? []
      }
    }))
  }

  const all = [...useYimaiStore().state.auditLogs]
  let records = all
  if (params.operator) records = records.filter((item) => item.operatorName === params.operator)
  if (params.module) records = records.filter((item) => item.module === params.module)
  if (params.action) records = records.filter((item) => item.action === params.action)
  if (params.start) records = records.filter((item) => item.time >= String(params.start))
  if (params.end) records = records.filter((item) => item.time <= `${params.end} 23:59:59`)
  const current = Number(params.current ?? 1)
  const size = Number(params.size ?? 20)
  return Promise.resolve({
    records: records.slice((current - 1) * size, current * size),
    total: records.length,
    current,
    size,
    metadata: {
      operators: unique(all.map((item) => item.operatorName)),
      modules: unique(all.map((item) => item.module)),
      actions: unique(all.map((item) => item.action))
    }
  })
}

export function getModelGenerations(params: {
  current?: number
  size?: number
  operatorId?: number | string
  start?: string
  end?: string
  featureType?: string
  status?: string
}): Promise<{
  records: ModelGenerationRecord[]
  total: number
  current: number
  size: number
  metadata?: ModelGenerationMetadata
}> {
  if (USE_BACKEND) {
    return apiGet<{
      records?: ModelGenerationRecord[]
      total?: number
      current?: number
      size?: number
      metadata?: ModelGenerationMetadata
    }>('/model-generations', params as Record<string, unknown>).then((data) => ({
      records: data.records ?? [],
      total: data.total ?? data.records?.length ?? 0,
      current: data.current ?? params.current ?? 1,
      size: data.size ?? params.size ?? 10,
      metadata: data.metadata
    }))
  }

  const aiStore = useAiConfigStore()
  const user = useUserStore().getUserInfo
  const all: ModelGenerationRecord[] = aiStore.usageLog.map((item, index) => ({
    id: `demo-${index + 1}`,
    createdAt: item.time,
    operatorName: user.userName || '演示用户',
    operatorRole: user.roles?.[0] || '演示角色',
    featureType: item.platform,
    source: item.source,
    provider: item.source === 'llm' ? aiStore.config.providerLabel : '本地模板',
    model: item.source === 'llm' ? aiStore.config.model : 'template',
    status: 'success',
    latencyMs: null,
    inputTokens: null,
    outputTokens: null,
    totalTokens: null,
    outputPreview: `已生成约 ${item.chars} 字内容`,
    errorMessage: null
  }))
  let records = all
  if (params.featureType)
    records = records.filter((item) => item.featureType === params.featureType)
  if (params.status) records = records.filter((item) => item.status === params.status)
  if (params.start) records = records.filter((item) => item.createdAt >= String(params.start))
  if (params.end) records = records.filter((item) => item.createdAt <= `${params.end} 23:59:59`)
  const current = Number(params.current ?? 1)
  const size = Number(params.size ?? 10)
  return Promise.resolve({
    records: records.slice((current - 1) * size, current * size),
    total: records.length,
    current,
    size,
    metadata: {
      operators: [{ id: 'demo', name: user.userName || '演示用户' }],
      featureTypes: unique(all.map((item) => item.featureType)),
      statuses: unique(all.map((item) => item.status))
    }
  })
}

function unique(values: string[]): string[] {
  return [...new Set(values.filter(Boolean))]
}

function formatDate(date: Date): string {
  const pad = (value: number) => String(value).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}
