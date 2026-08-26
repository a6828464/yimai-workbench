import { useUserStore } from '@/store/modules/user'
import { useYimaiStore } from '@/store/modules/yimai'
import { fetchKyMembers, KY_STORES } from './keepyoga'
import { USE_BACKEND, apiGet, apiPost, apiPatch, apiPut } from './backend'
import type { YimaiLead, YimaiAuditLog, YimaiCustomer as StoreCustomer, MemberRules } from '@/store/modules/yimai'

export type { YimaiLead, YimaiAuditLog, MemberRules }

let rulesCache: MemberRules = { renewalThreshold: 10, vipThreshold: 100, declineMode: 'strict' }

export async function refreshMemberRules(): Promise<MemberRules> {
  if (USE_BACKEND) {
    rulesCache = await apiGet<MemberRules>('/member-rules')
  }
  return rulesCache
}

/** 卓越店长训练营五张运营清单 */
export type MemberListKey = '待续课' | '出勤降低' | 'VIP' | '预流失' | '待复活'

export function getMemberRules(): MemberRules {
  if (USE_BACKEND) return rulesCache
  ensureSeeded()
  rulesCache = useYimaiStore().state.rules
  return rulesCache
}

export function setMemberRules(rules: MemberRules): void {
  if (USE_BACKEND) {
    void apiPut<MemberRules>('/member-rules', rules as unknown as Record<string, unknown>).then((r) => {
      rulesCache = r
    })
    return
  }
  useYimaiStore().setMemberRules(rules)
  rulesCache = rules
}

/**
 * 清单归入引擎（口径来源：卓越店长训练营会员管理板块）
 * - 待续课：最近月有出勤 且 剩余课时 < 阈值（默认10，严格小于）
 * - 出勤降低：strict=M1>M2>M3 连续三月递减 / recent=M2>M3
 * - VIP：累计购买私教课量 > 阈值（默认100，严格大于）
 * - 预流失：上月出勤、本月停训（M2>0且M3=0），或15-30天未到店
 * - 待复活：30天以上未到店但仍有卡项资产
 */
export function computeMemberLists(c: YimaiCustomer): MemberListKey[] {
  const rules = getMemberRules()
  const m1 = c.attendM1 ?? 0
  const m2 = c.attendM2 ?? 0
  const m3 = c.attendM3 ?? 0
  const out: MemberListKey[] = []

  if (m3 > 0 && c.remainTimes !== null && c.remainTimes < rules.renewalThreshold) out.push('待续课')
  if (rules.declineMode === 'strict' ? m1 > m2 && m2 > m3 : m2 > m3) out.push('出勤降低')
  if ((c.totalPurchased ?? 0) > rules.vipThreshold) out.push('VIP')

  const days = lastVisitDays(c.lastVisit)
  if ((m2 > 0 && m3 === 0) || (days >= 15 && days <= 30)) out.push('预流失')
  if (days > 30 && c.mainCard !== '—') out.push('待复活')

  return out
}

function lastVisitDays(date: string | null): number {
  if (!date) return 9999
  return Math.floor((Date.now() - new Date(date).getTime()) / 86400000)
}

export function updateMemberFields(id: number, patch: Partial<YimaiCustomer>, actionLabel?: string): boolean {
  if (USE_BACKEND) {
    void apiPatch(`/customers/${id}`, { ...patch, _action: actionLabel ?? '修改' } as Record<string, unknown>)
    return true
  }
  return useYimaiStore().updateMember(id, patch, actionLabel)
}

export interface YimaiCustomer extends StoreCustomer {
  externalId?: string
}

export interface YimaiTask {
  id: number
  title: string
  customerName: string
  venue: '绿地店' | '东部店'
  owner: string
  priority: '高' | '中' | '低'
  deadline: string
  status: '待接收' | '进行中' | '待验收' | '已完成' | '已退回' | '已逾期'
  standard: string
}

export interface YimaiApproval {
  id: number
  customerName: string
  applicant: string
  cardName: string
  standardPrice: number
  requestPrice: number
  reason: string
  status: '待店长初审' | '待老板终审' | '已通过' | '已驳回' | '已关联成交'
  applyTime: string
}

export interface YimaiSyncJob {
  id: number
  batchNo: string
  dataType: string
  venue: '绿地店' | '东部店' | '双店'
  dateRange: string
  totalCount: number
  successCount: number
  failCount: number
  status: '成功' | '部分失败' | '进行中' | '失败'
  finishedAt: string
}

type UserInfo = ReturnType<typeof useUserStore>['getUserInfo']

function actor() {
  const u = useUserStore().getUserInfo as UserInfo & { venue?: string | null; venues?: string[] }
  const roles = u.roles ?? []
  const isManager = roles.includes('R_MANAGER')
  const isTeacher = roles.includes('R_TEACHER')
  return {
    role: roles[0] ?? '',
    userName: u.userName ?? '',
    scopeVenue: isManager || isTeacher ? u.venue ?? null : null,
    venues: u.venues ?? [],
    isBoss: roles.includes('R_SUPER'),
    isSuper: roles.includes('R_SUPER'),
    isManager,
    isTeacher,
    isMedia: roles.includes('R_MEDIA')
  }
}

function inScope(venue: string, userVenue: string | null): boolean {
  return !userVenue || venue === userVenue
}

interface PageParams {
  current?: number
  size?: number
  [key: string]: unknown
}

function paginate<T>(list: T[], params: PageParams): T[] {
  const current = Number(params.current ?? 1)
  const size = Number(params.size ?? 20)
  return list.slice((current - 1) * size, current * size)
}

function ensureSeeded() {
  useYimaiStore().ensureSeed()
}

function allCustomers(): YimaiCustomer[] {
  ensureSeeded()
  return useYimaiStore().state.customers as YimaiCustomer[]
}

// ==================== 前端客资（留资） ====================

export function queryLeads(params: PageParams & { name?: string; venue?: string; status?: string; source?: string }) {
  if (USE_BACKEND) {
    return apiGet<{ records: YimaiLead[]; total: number }>('/leads', params as Record<string, unknown>)
  }
  ensureSeeded()
  const store = useYimaiStore()
  const a = actor()
  let list = store.state.leads.filter((l) => inScope(l.venue, a.scopeVenue))
  if (a.isTeacher) list = list.filter((l) => l.serviceTeacher === a.userName || !l.serviceTeacher)
  if (params.name) list = list.filter((l) => l.name.includes(String(params.name)))
  if (params.venue) list = list.filter((l) => l.venue === params.venue)
  if (params.status) list = list.filter((l) => l.status === params.status)
  if (params.source) list = list.filter((l) => l.source === params.source)
  const sorted = [...list].sort((x, y) => y.id - x.id)
  return Promise.resolve({ records: paginate(sorted, params), total: sorted.length })
}

export function addLead(data: Omit<YimaiLead, 'id' | 'status' | 'createdBy' | 'createdAt'> & { status?: YimaiLead['status'] }) {
  if (USE_BACKEND) {
    return apiPost<{ id: number }>('/leads', data as unknown as Record<string, unknown>)
  }
  return Promise.resolve(useYimaiStore().addLead(data))
}

export function updateLead(id: number, patch: Partial<YimaiLead>) {
  if (USE_BACKEND) {
    return apiPatch<unknown>(`/leads/${id}`, patch as Record<string, unknown>)
  }
  return Promise.resolve(useYimaiStore().updateLead(id, patch))
}

export function getLeadHistory(leadId: number): Promise<YimaiAuditLog[]> {
  if (USE_BACKEND) {
    return apiGet<YimaiAuditLog[]>(`/leads/${leadId}/history`)
  }
  return Promise.resolve(useYimaiStore().getLeadHistory(leadId))
}

export function canManageLead(lead: { venue: string; serviceTeacher?: string; createdBy?: string }): boolean {
  const a = actor()
  if (a.isManager) return lead.venue === a.scopeVenue
  if (a.isTeacher) {
    if (a.scopeVenue && lead.venue !== a.scopeVenue) return false
    return lead.serviceTeacher === a.userName || lead.createdBy === a.userName || !lead.serviceTeacher
  }
  return a.isSuper || a.isMedia
}

export function canAssignTeacher(): boolean {
  const a = actor()
  return a.isManager || a.isSuper
}

// ==================== 审计留痕 ====================

export function queryAuditLogs(params: PageParams & { operator?: string; module?: string; action?: string }) {
  if (USE_BACKEND) {
    return apiGet<{ records: YimaiAuditLog[]; total: number }>('/audit-logs', params as Record<string, unknown>)
      .then((d) => ({ records: d.records ?? [], total: d.records?.length ?? 0 }))
  }
  const a = actor()
  if (!a.isSuper && !a.isBoss) {
    return Promise.reject(new Error('无权限：仅老板可查看操作留痕'))
  }
  const store = useYimaiStore()
  let list = store.state.auditLogs
  if (a.isManager || a.isTeacher) list = list.filter((l) => !a.scopeVenue || l.venue === a.scopeVenue || l.venue === '双店')
  if (params.operator) list = list.filter((l) => l.operatorName.includes(String(params.operator)))
  if (params.module) list = list.filter((l) => l.module === params.module)
  if (params.action) list = list.filter((l) => l.action === params.action)
  return Promise.resolve({ records: paginate(list, params), total: list.length })
}

// ==================== 客户经营池 ====================

export function queryCustomers(
  params: PageParams & { name?: string; venue?: string; layer?: string; type?: 'all' | 'member' | 'lead'; list?: MemberListKey }
): Promise<{ records: YimaiCustomer[]; total: number; current: number; size: number }> {
  if (USE_BACKEND) {
    return apiGet<{ records: YimaiCustomer[]; total: number }>('/customers', {
      name: params.name, venue: params.venue, list: params.list, size: params.size
    } as Record<string, unknown>).then((d) => ({ ...d, current: 1, size: params.size ?? 20 }))
  }
  const a = actor()
  let list: YimaiCustomer[] = allCustomers().filter((c) => inScope(c.venue, a.scopeVenue))
  if (a.isMedia) list = list.filter((c) => c.layer === 'P5')
  if (a.isTeacher) {
    list = list.filter((c) => c.owner === a.userName)
    if (params.type === 'member') list = list.filter((c) => c.layer !== 'P5')
  }
  if (params.type === 'member') list = list.filter((c) => c.layer !== 'P5' && c.mainCard !== '—')
  if (params.type === 'lead') list = list.filter((c) => c.layer === 'P5')
  if (params.list) list = list.filter((c) => computeMemberLists(c).includes(params.list as MemberListKey))
  if (params.name) list = list.filter((c) => c.name.includes(String(params.name)))
  if (params.venue) list = list.filter((c) => c.venue === params.venue)
  if (params.layer) list = list.filter((c) => c.layer === params.layer)
  return Promise.resolve({ records: paginate(list, params), total: list.length, current: params.current ?? 1, size: params.size ?? 20 })
}

// ==================== 30天续费评估（口径来源：卓越店长训练营评估表） ====================

export interface EvalOption {
  label: string
  score: number
}

export interface EvalDimension {
  key: string
  title: string
  hint: string
  max?: number
  options: EvalOption[]
}

export const EVAL_DIMENSIONS: EvalDimension[] = [
  {
    key: 'attend',
    title: '最近30天出勤',
    hint: '确认方式：SaaS系统',
    options: [
      { label: '8次及以上', score: 25 },
      { label: '6次及以上', score: 20 },
      { label: '4次及以上', score: 10 },
      { label: '4次以下', score: 5 },
      { label: '0次', score: -10 }
    ]
  },
  {
    key: 'goal',
    title: '对后续训练有明确目标和期待',
    hint: '确认方式：聊天记录',
    options: [
      { label: '有书面计划并与客户讨论', score: 15 },
      { label: '讨论并形成认可的目标（无书面）', score: 10 },
      { label: '体测/评估/动作解锁并有进步', score: 5 },
      { label: '无相关交流', score: 0 }
    ]
  },
  {
    key: 'feedback',
    title: '训练后询问客户感受',
    hint: '确认方式：聊天记录',
    options: [
      { label: '询问3次以上且获得回复', score: 15 },
      { label: '询问3次以上但未获回复', score: 5 },
      { label: '未询问', score: 0 }
    ]
  },
  {
    key: 'moments',
    title: '朋友圈互动（最高15分）',
    hint: '确认方式：朋友圈截图',
    max: 15,
    options: [
      { label: '客户发了相关朋友圈', score: 5 },
      { label: '评论互动每次+2', score: 2 },
      { label: '点赞每次+1', score: 1 }
    ]
  },
  {
    key: 'daily',
    title: '日常交流（最高15分）',
    hint: '确认方式：聊天记录',
    max: 15,
    options: [
      { label: '有私人往来（吃饭/遛狗等）', score: 10 },
      { label: '3句以上有效交流每次+2', score: 2 },
      { label: '客户关心教练私人生活 +3', score: 3 }
    ]
  },
  {
    key: 'service',
    title: '服务动作（最高20分，可多选累计）',
    hint: '确认方式：说明和截图',
    max: 20,
    options: [
      { label: '针对性解决客户意见建议', score: 10 },
      { label: '提醒后获得服务（延期/请假等）', score: 8 },
      { label: '参加店内社群活动', score: 8 },
      { label: '任何形式小礼物', score: 8 }
    ]
  },
  {
    key: 'signal',
    title: '互动与转介绍信号（最高15分）',
    hint: '',
    max: 15,
    options: [
      { label: '带朋友来训练（不要求成交）', score: 10 },
      { label: '线上平台好评', score: 5 }
    ]
  },
  {
    key: 'minus',
    title: '减分项（可多选累计）',
    hint: '',
    options: [
      { label: '询问购买被推脱/拒绝', score: -20 },
      { label: '客户只与一名教练交流', score: -10 },
      { label: '问题或建议未被解决', score: -15 },
      { label: '没有询问感受或回访记录', score: -15 }
    ]
  }
]

export function evalTotalScore(answers: Record<string, number[]>): number {
  return EVAL_DIMENSIONS.reduce((sum, dim) => {
    const picks = answers[dim.key] ?? []
    let sub = picks.reduce((s, i) => s + (dim.options[i]?.score ?? 0), 0)
    if (dim.max !== undefined) sub = Math.min(sub, dim.max)
    if (sub > 0 && dim.max === undefined && dim.key === 'attend') sub = Math.min(sub, 25)
    return sum + sub
  }, 0)
}

// ==================== 任务中心 ====================

const TASKS: YimaiTask[] = [
  { id: 1, title: '新客首次响应', customerName: '郑好', venue: '绿地店', owner: '未分配', priority: '高', deadline: '2026-08-26 18:00', status: '已逾期', standard: '留资后24小时内完成首联并记录意向' },
  { id: 2, title: '预约确认', customerName: '赵一诺', venue: '绿地店', owner: '婷婷', priority: '高', deadline: '2026-08-27 12:00', status: '待接收', standard: '课前确认时间/课程/目标，发送到店准备' },
  { id: 3, title: '体验后跟进', customerName: '刘思颖', venue: '东部店', owner: '苏米', priority: '高', deadline: '2026-08-26 20:00', status: '进行中', standard: '体验后24小时内有效跟进并生成下次动作' },
  { id: 4, title: '临期沟通', customerName: '许静姝', venue: '东部店', owner: '苏米', priority: '中', deadline: '2026-08-28 18:00', status: '待验收', standard: '明确续费窗口并给出方案，记录客户反馈' },
  { id: 5, title: '低频唤醒', customerName: '李梦', venue: '绿地店', owner: '冰璐', priority: '中', deadline: '2026-08-29 18:00', status: '进行中', standard: '以恢复节奏切入，验收标准为重新预约' },
  { id: 6, title: '训练反馈补录', customerName: '陈晓芸', venue: '绿地店', owner: '娟子', priority: '低', deadline: '2026-08-30 18:00', status: '已退回', standard: '补全本次完成情况/客户感受/下次重点' },
  { id: 7, title: '预约确认', customerName: '冯悦', venue: '绿地店', owner: '娟子', priority: '低', deadline: '2026-08-27 12:00', status: '已完成', standard: '课前确认时间/课程/目标' }
]

export function queryTasks(params: PageParams & { status?: string; venue?: string }) {
  if (USE_BACKEND) {
    return apiGet<{ records: YimaiTask[]; total: number }>('/tasks', params as Record<string, unknown>)
      .then((d) => ({ records: d.records ?? [], total: d.records?.length ?? 0, current: 1, size: params.size ?? 20 }))
  }
  const a = actor()
  let list = TASKS.filter((t) => inScope(t.venue, a.scopeVenue))
  if (a.isTeacher) list = list.filter((t) => t.owner === a.userName || t.owner === '未分配')
  if (params.status) list = list.filter((t) => t.status === params.status)
  if (params.venue) list = list.filter((t) => t.venue === params.venue)
  return Promise.resolve({ records: paginate(list, params), total: list.length, current: params.current ?? 1, size: params.size ?? 20 })
}

// ==================== 价格审批 ====================

const APPROVALS: YimaiApproval[] = [
  { id: 1, customerName: '刘思颖', applicant: '苏米', cardName: '全能小班年卡', standardPrice: 8800, requestPrice: 7980, reason: '体验当天成交，竞品对比价差敏感', status: '待店长初审', applyTime: '2026-08-26 10:24' },
  { id: 2, customerName: '周雨彤', applicant: '冰璐', cardName: 'VIP私教50节', standardPrice: 22500, requestPrice: 19800, reason: '老客复购+过期余额折抵权益', status: '待老板终审', applyTime: '2026-08-25 16:40' },
  { id: 3, customerName: '吴佳宁', applicant: '芷晴', cardName: '私教小班10节', standardPrice: 3200, requestPrice: 2980, reason: '团课高频会员转化首单让利', status: '已通过', applyTime: '2026-08-24 11:05' },
  { id: 4, customerName: '孙美琪', applicant: '张青', cardName: '精品团课季卡', standardPrice: 1980, requestPrice: 1600, reason: '降频风险挽单特批', status: '已驳回', applyTime: '2026-08-23 14:32' },
  { id: 5, customerName: '王雅琴', applicant: '婷婷', cardName: '精品白领年卡（3次/周）', standardPrice: 6800, requestPrice: 6500, reason: '续费窗口期早鸟权益', status: '已关联成交', applyTime: '2026-08-20 09:18' }
]

export function queryApprovals(params: PageParams & { status?: string }) {
  if (USE_BACKEND) {
    return apiGet<{ records: YimaiApproval[]; total: number }>('/approvals', params as Record<string, unknown>)
      .then((d) => ({ records: d.records ?? [], total: d.records?.length ?? 0, current: 1, size: params.size ?? 20 }))
  }
  let list = [...APPROVALS]
  if (params.status) list = list.filter((x) => x.status === params.status)
  return Promise.resolve({ records: paginate(list, params), total: list.length, current: params.current ?? 1, size: params.size ?? 20 })
}

export function decideApproval(id: number, decision: '初审通过' | '终审通过' | '驳回') {
  if (USE_BACKEND) {
    return apiPost<boolean>(`/approvals/${id}/decide`, { decision })
  }
  useYimaiStore().decideApproval(id, decision)
  return Promise.resolve(true)
}

// ==================== 数据同步批次（示例结构） ====================

const SYNC_JOBS: YimaiSyncJob[] = [
  { id: 1, batchNo: 'IMP-20260826-001', dataType: '会员基础表', venue: '双店', dateRange: '全量', totalCount: 1820, successCount: 1820, failCount: 0, status: '成功', finishedAt: '2026-08-26 06:12' },
  { id: 2, batchNo: 'IMP-20260826-002', dataType: '会员卡表', venue: '双店', dateRange: '全量', totalCount: 3008, successCount: 3006, failCount: 2, status: '部分失败', finishedAt: '2026-08-26 06:25' },
  { id: 3, batchNo: 'IMP-20260826-003', dataType: '团课预约记录', venue: '绿地店', dateRange: '2026-08-19 ~ 2026-08-25', totalCount: 52, successCount: 52, failCount: 0, status: '成功', finishedAt: '2026-08-26 06:31' },
  { id: 4, batchNo: 'IMP-20260826-004', dataType: '私教预约记录', venue: '东部店', dateRange: '2026-08-19 ~ 2026-08-25', totalCount: 45, successCount: 45, failCount: 0, status: '成功', finishedAt: '2026-08-26 06:33' },
  { id: 5, batchNo: 'IMP-20260825-011', dataType: '变更记录', venue: '双店', dateRange: '2026-08-18 ~ 2026-08-25', totalCount: 135, successCount: 133, failCount: 2, status: '失败', finishedAt: '2026-08-25 06:40' },
  { id: 6, batchNo: 'IMP-20260826-005', dataType: '售卡统计', venue: '双店', dateRange: '2026-07-26 ~ 2026-08-25', totalCount: 41, successCount: 41, failCount: 0, status: '成功', finishedAt: '2026-08-26 06:47' }
]

export function querySyncJobs(params: PageParams & { status?: string; dataType?: string }) {
  if (USE_BACKEND) {
    return apiGet<{ records: YimaiSyncJob[]; total: number }>('/sync-jobs', {
      status: params.status,
      dataType: params.dataType
    } as Record<string, unknown>).then((d) => ({
      records: d.records ?? [],
      total: d.total ?? (d.records?.length ?? 0),
      current: params.current ?? 1,
      size: params.size ?? 20
    }))
  }
  const a = actor()
  let list = SYNC_JOBS.filter((s) => s.venue === '双店' || inScope(s.venue, a.scopeVenue))
  if (params.status) list = list.filter((s) => s.status === params.status)
  if (params.dataType) list = list.filter((s) => s.dataType.includes(String(params.dataType)))
  return Promise.resolve({ records: paginate(list, params), total: list.length, current: params.current ?? 1, size: params.size ?? 20 })
}

/** KeepYoga 全量导入：拉取门店全部会员并按外部ID upsert 进客户池 */
export async function importKyMembersToPool(storeKey: '绿地店' | '东部店'): Promise<{ created: number; updated: number; total: number }> {
  const rows = await fetchKyMembers(storeKey, '', 99999)

  if (USE_BACKEND) {
    // 服务端按 external_id 幂等合并，并记录同步批次
    const res = await apiPost<{ created: number; updated: number; total: number }>('/customers/import', {
      venue: storeKey,
      venueId: KY_STORES[storeKey],
      rows: rows.map((r) => ({ memberId: r.memberId, name: r.name, phone: r.phone, source: r.source }))
    })
    return { created: res.created, updated: res.updated, total: res.total }
  }

  ensureSeeded()
  const mapped: YimaiCustomer[] = rows.map((r, i) => ({
    id: 500000 + i,
    name: r.name || `会员${r.memberId}`,
    phone: r.phone,
    phoneTail: r.phone.slice(-4),
    venue: storeKey,
    source: r.source || 'KeepYoga',
    mainCard: '待同步卡项',
    remainTimes: null,
    expireDate: null,
    lastVisit: null,
    layer: 'P5',
    status: '暂缓',
    owner: '未分配',
    nextAction: '分配负责人并建档',
    nextActionTime: '',
    externalId: `ky:${KY_STORES[storeKey]}:${r.memberId}`
  }))
  const res = useYimaiStore().upsertImportedCustomers(mapped)
  useYimaiStore().auditImport(storeKey, res.created, res.updated)
  return { ...res, total: rows.length }
}

// ==================== 数据看板 ====================

export interface DashboardDayPoint {
  date: string
  label: string
  bookings: number
  trials: number
  visits: number
  deals: number
  amount: number
  leads: number
  privateDomain: number
  redeem: number
}

export interface DashboardSummary {
  bookingCount: number
  trialCount: number
  visitCount: number
  dealCount: number
  dealAmount: number
  dealRate: number
  leadCount: number
  privateDomainCount: number
  redeemAmount: number
  leadToVisitRate: number
}

export interface ChannelLeadItem {
  channel: string
  leads: number
}

const CHANNELS = ['大众点评', '美团', '抖音', '视频号', '小红书', '转介绍', '自然到店']
const CHANNEL_WEIGHTS = [0.18, 0.22, 0.24, 0.08, 0.12, 0.09, 0.07]

function seeded(seed: string): number {
  let h = 2166136261
  for (let i = 0; i < seed.length; i++) {
    h ^= seed.charCodeAt(i)
    h = Math.imul(h, 16777619)
  }
  return (h >>> 0) / 4294967295
}

function weekdayFactor(d: Date): number {
  const w = d.getDay()
  if (w === 0 || w === 6) return 1.75
  if (w === 5) return 1.25
  if (w === 1) return 0.85
  return 1
}

function buildVenueDays(start: string, end: string, venue: '绿地店' | '东部店'): DashboardDayPoint[] {
  const scale = venue === '绿地店' ? 1 : 0.62
  const points: DashboardDayPoint[] = []
  const cur = new Date(`${start}T00:00:00`)
  const stop = new Date(`${end}T00:00:00`)
  while (cur <= stop) {
    const iso = cur.toISOString().slice(0, 10)
    const wf = weekdayFactor(cur)
    const r = (k: string, lo: number, hi: number) => lo + seeded(`${iso}-${venue}-${k}`) * (hi - lo)
    const leads = Math.max(0, Math.round(r('leads', 2, 7) * wf * scale))
    const privateDomain = Math.round(leads * r('pv', 0.55, 0.8))
    const visits = Math.max(0, Math.round(r('visit', 2, 6) * wf * scale))
    const bookings = Math.max(visits, Math.round(r('book', 3, 9) * wf * scale))
    const trials = Math.max(0, Math.round(visits * r('trial', 0.55, 0.9)))
    const deals = Math.round(trials * r('deal', 0.12, 0.42))
    const amount = deals > 0 ? Math.round(deals * r('amt', 1600, 5200)) : 0
    const redeem = Math.round(amount * r('red', 0.15, 0.45)) + (seeded(`${iso}-${venue}-rc`) > 0.72 ? 299 : 0)
    points.push({
      date: iso,
      label: `${cur.getMonth() + 1}/${cur.getDate()}`,
      leads,
      privateDomain,
      visits,
      bookings,
      trials,
      deals,
      amount,
      redeem
    })
    cur.setDate(cur.getDate() + 1)
  }
  return points
}

function resolveDashboardVenues(scope: '双店' | '绿地店' | '东部店'): ('绿地店' | '东部店')[] {
  if (scope === '双店') return ['绿地店', '东部店']
  return [scope]
}

export function getDashboardSeries(
  startDate: string,
  endDate: string,
  scope: '双店' | '绿地店' | '东部店' = '双店'
): Promise<{ daily: DashboardDayPoint[]; summary: DashboardSummary }> {
  const a = actor()
  const venues = (a.isManager || a.isTeacher) && a.scopeVenue ? [a.scopeVenue as '绿地店' | '东部店'] : resolveDashboardVenues(scope)
  const merged = new Map<string, DashboardDayPoint>()
  for (const v of venues) {
    for (const p of buildVenueDays(startDate, endDate, v)) {
      const m = merged.get(p.date)
      if (!m) {
        merged.set(p.date, { ...p })
      } else {
        m.bookings += p.bookings
        m.trials += p.trials
        m.visits += p.visits
        m.deals += p.deals
        m.amount += p.amount
        m.leads += p.leads
        m.privateDomain += p.privateDomain
        m.redeem += p.redeem
      }
    }
  }
  const daily = [...merged.values()].sort((x, y) => x.date.localeCompare(y.date))
  if (a.isTeacher) {
    for (const p of daily) {
      p.bookings = Math.round(p.bookings * 0.28)
      p.trials = Math.round(p.trials * 0.28)
      p.visits = Math.round(p.visits * 0.28)
      p.deals = Math.round(p.deals * 0.28)
      p.amount = Math.round(p.amount * 0.28)
      p.leads = Math.round(p.leads * 0.28)
      p.privateDomain = Math.round(p.privateDomain * 0.28)
      p.redeem = Math.round(p.redeem * 0.28)
    }
  }
  const sum = (fn: (p: DashboardDayPoint) => number) => daily.reduce((s, p) => s + fn(p), 0)
  const visitCount = sum((p) => p.visits)
  const dealCount = sum((p) => p.deals)
  const leadCount = sum((p) => p.leads)
  const summary: DashboardSummary = {
    bookingCount: sum((p) => p.bookings),
    trialCount: sum((p) => p.trials),
    visitCount,
    dealCount,
    dealAmount: sum((p) => p.amount),
    dealRate: visitCount > 0 ? Number(((dealCount / visitCount) * 100).toFixed(1)) : 0,
    leadCount,
    privateDomainCount: sum((p) => p.privateDomain),
    redeemAmount: sum((p) => p.redeem),
    leadToVisitRate: leadCount > 0 ? Number(((visitCount / leadCount) * 100).toFixed(1)) : 0
  }
  return Promise.resolve({ daily, summary })
}

export function getChannelBreakdown(
  startDate: string,
  endDate: string,
  scope: '双店' | '绿地店' | '东部店' = '双店'
): Promise<ChannelLeadItem[]> {
  const a = actor()
  const venues = (a.isManager || a.isTeacher) && a.scopeVenue ? [a.scopeVenue as '绿地店' | '东部店'] : resolveDashboardVenues(scope)
  const all: DashboardDayPoint[] = []
  for (const v of venues) all.push(...buildVenueDays(startDate, endDate, v))
  const totalLeads = all.reduce((s, p) => s + p.leads, 0)
  return Promise.resolve(
    CHANNELS.map((channel, i) => {
      const jitter = 0.85 + seeded(`${startDate}-${endDate}-${scope}-${channel}`) * 0.3
      const weight = i === CHANNEL_WEIGHTS.length - 1 ? 1 - CHANNEL_WEIGHTS.slice(0, -1).reduce((x, y) => x + y, 0) : CHANNEL_WEIGHTS[i]
      return { channel, leads: Math.max(0, Math.round(totalLeads * weight * jitter)) }
    }).sort((x, y) => y.leads - x.leads)
  )
}

// ==================== 老师工作台概览 ====================

export interface TeacherOverview {
  memberCount: number
  resourceCount: number
  newResourceCount: number
  classCount: number
  servedCount: number
}

export async function getTeacherOverview(startDate: string, endDate: string): Promise<TeacherOverview> {
  const a = actor()

  if (USE_BACKEND) {
    // 成员/客资计数由服务端按角色口径计算；课时部分沿用看板演示序列（阶段2接入真实排课）
    const [members, leads] = await Promise.all([
      queryCustomers({ type: 'member', size: 9999 }),
      queryLeads({ size: 9999 })
    ])
    const myMembers = members.records.filter((c) => c.owner === a.userName && c.layer !== 'P5')
    const myLeads = leads.records.filter((l) => (l as unknown as { serviceTeacher?: string }).serviceTeacher === a.userName)
    const poolLeads = leads.records.filter(
      (l) => !(l as unknown as { serviceTeacher?: string }).serviceTeacher && l.status === '新留资'
    )
    const { daily } = await getDashboardSeries(startDate, endDate, '双店')
    return {
      memberCount: myMembers.length,
      resourceCount: myLeads.length + poolLeads.length,
      newResourceCount: poolLeads.length,
      classCount: Math.round(daily.reduce((s, p) => s + p.bookings, 0) * 0.6),
      servedCount: Math.round(daily.reduce((s, p) => s + p.trials + p.bookings * 0.35, 0))
    }
  }

  const myMembers = allCustomers().filter(
    (c) => c.owner === a.userName && c.layer !== 'P5' && inScope(c.venue, a.scopeVenue)
  )
  ensureSeeded()
  const myLeads = useYimaiStore().state.leads.filter(
    (l) => l.serviceTeacher === a.userName && inScope(l.venue, a.scopeVenue)
  )
  const poolLeads = useYimaiStore().state.leads.filter(
    (l) => !l.serviceTeacher && l.status === '新留资' && inScope(l.venue, a.scopeVenue)
  )
  const { daily } = await getDashboardSeries(startDate, endDate, '双店')
  const classCount = Math.round(daily.reduce((s, p) => s + p.bookings, 0) * 0.6)
  const servedCount = Math.round(daily.reduce((s, p) => s + p.trials + p.bookings * 0.35, 0))
  return {
    memberCount: myMembers.length,
    resourceCount: myLeads.length + poolLeads.length,
    newResourceCount: poolLeads.length,
    classCount,
    servedCount
  }
}

// ==================== 营销工具 ====================

export interface MarketingCopyResult {
  content: string
  tags: string[]
}

const HOOKS = ['你有没有发现', '练了很久却没变化？可能是方法不对。', '别再盲目跟练了，', '很多姐妹问我：']
const BODIES = [
  '{topic}不是靠蛮力，而是靠正确的发力顺序和呼吸配合。',
  '坚持{topic}一个月，身体会先于体重告诉你答案。',
  '{point}是我们这周课堂里大家进步最明显的部分。',
  '一麦的{topic}课程，从评估开始，为你定制专属练习路径。'
]
const CTAS = [
  '想体验的同学可以私信我预约体验课。',
  '评论区扣1，送你一次免费体态评估。',
  '到店聊聊你的目标，我们帮你规划下一阶段。',
  '点击主页联系方式，来店里练一节感受下。'
]

export function generateMarketingCopy(platform: string, topic: string, point: string): Promise<MarketingCopyResult> {
  const seedBase = `${Date.now()}-${topic}`
  const pick = (arr: string[], salt: string) => arr[Math.floor(seeded(seedBase + salt) * arr.length) % arr.length]
  const body = pick(BODIES, 'b').replace('{topic}', topic || '瑜伽练习').replace('{point}', point || topic || '核心激活')
  let content: string
  if (platform === '小红书') {
    content = [`${pick(HOOKS, 'h')}${body}`, '', `${point ? `今日重点：${point}。` : ''}${pick(CTAS, 'c')}`].join('\n')
  } else {
    content = `${pick(HOOKS, 'h')}${body}${point ? `\n${point}。` : ''}\n${pick(CTAS, 'c')}`
  }
  const tags = ['#一麦瑜伽', topic ? `#${topic.replace(/\s/g, '')}` : '#日常练习', platform === '小红书' ? '#瑜伽日常' : '']
    .filter(Boolean)
  useYimaiStore().writeAudit('生成', '营销工具', 0, `${platform} · ${topic || '自由创作'}`, '双店', `生成${platform}文案草稿：主题[${topic || '无'}]`)
  return Promise.resolve({ content, tags })
}

// ==================== 训练计划云端同步（阶段1） ====================

export async function loadTrainingPlansCloud(): Promise<Record<string, unknown>[] | null> {
  if (!USE_BACKEND) return null
  return apiGet<Record<string, unknown>[]>('/training-plans')
}

export async function syncTrainingPlansCloud(plans: unknown[]): Promise<void> {
  if (!USE_BACKEND) return
  await apiPut('/training-plans/bulk', { plans })
}

/** 发布对外分享快照（H5 跨设备访问） */
export function publishShare(type: string, token: string, payload: Record<string, unknown>): Promise<void> {
  if (!USE_BACKEND) return Promise.resolve()
  return apiPost('/shares/publish', { type, token, payload }).then(() => undefined)
}

// ==================== 今日工作台汇总 ====================

export function getTodaySummary(): Promise<{
  newLeads: number
  pendingFollowup: number
  expiringMembers: number
  riskCount: number
  pendingApprovals: number
  todayBookings: { 绿地店: number; 东部店: number }
  trialBookings: { 绿地店: number; 东部店: number }
  scopeLabel: string
  snapshotTime: string
}> {
  if (USE_BACKEND) return apiGet('/today/summary')

  const a = actor()
  const userVenue = a.scopeVenue
  const scopedCustomers = allCustomers().filter((c) => inScope(c.venue, userVenue))
  const scopedTasks = TASKS.filter((t) => inScope(t.venue, userVenue))
  ensureSeeded()
  const leads = useYimaiStore().state.leads.filter((l) => inScope(l.venue, userVenue))
  const pendingApprovals = APPROVALS.filter(
    (x) => x.status.startsWith('待') && (a.isBoss || a.isManager || inScope('双店', userVenue))
  ).length
  const snap = useYimaiStore().state.snapshot

  return Promise.resolve({
    newLeads: scopedCustomers.filter((c) => c.layer === 'P5').length + leads.filter((l) => l.status === '新留资').length,
    pendingFollowup: scopedCustomers.filter((c) => c.nextActionTime <= '2026-08-27').length,
    expiringMembers: scopedCustomers.filter((c) => computeMemberLists(c).includes('待续课')).length,
    riskCount:
      scopedTasks.filter((t) => t.status === '已逾期').length +
      scopedCustomers.filter((c) => c.owner === '未分配').length +
      leads.filter((l) => l.status === '新留资').length,
    pendingApprovals: a.isMedia ? 0 : pendingApprovals,
    todayBookings: snap
      ? {
          绿地店: !userVenue || userVenue === '绿地店' ? (snap.todayBookings['绿地店'] ?? 0) : 0,
          东部店: !userVenue || userVenue === '东部店' ? (snap.todayBookings['东部店'] ?? 0) : 0
        }
      : {
          绿地店: !userVenue || userVenue === '绿地店' ? 52 : 0,
          东部店: !userVenue || userVenue === '东部店' ? 45 : 0
        },
    trialBookings: snap
      ? {
          绿地店: !userVenue || userVenue === '绿地店' ? (snap.trialBookings['绿地店'] ?? 0) : 0,
          东部店: !userVenue || userVenue === '东部店' ? (snap.trialBookings['东部店'] ?? 0) : 0
        }
      : {
          绿地店: !userVenue || userVenue === '绿地店' ? 1 : 0,
          东部店: !userVenue || userVenue === '东部店' ? 2 : 0
        },
    scopeLabel: a.scopeVenue ? `本店 · ${a.scopeVenue}` : '双店',
    snapshotTime: a.isManager || a.isBoss ? (snap?.fetchedAt ?? '') : ''
  })
}

function calcDays(date: string | null): number {
  if (!date) return 9999
  return Math.floor((Date.now() - new Date(date).getTime()) / 86400000)
}

export function getFollowupQueue(): Promise<(YimaiCustomer & { lastVisitDays: number })[]> {
  if (USE_BACKEND) return apiGet<(YimaiCustomer & { lastVisitDays: number })[]>('/today/followups')

  const a = actor()
  const userVenue = a.scopeVenue
  const scoped = allCustomers().filter((c) => inScope(c.venue, userVenue))
  return Promise.resolve(
    scoped
      .filter((c) => ['P0', 'P1', 'P5'].includes(c.layer))
      .slice(0, 6)
      .map((c) => ({ ...c, lastVisitDays: calcDays(c.lastVisit) }))
  )
}

export function getRiskAlerts(): Promise<{ id: number; level: string; text: string; action: string }[]> {
  if (USE_BACKEND) return apiGet<{ id: number; level: string; text: string; action: string }[]>('/today/alerts')

  const a = actor()
  const userVenue = a.scopeVenue
  const scopedTasks = TASKS.filter((t) => inScope(t.venue, userVenue))
  ensureSeeded()
  const newLeads = useYimaiStore().state.leads.filter((l) => l.status === '新留资' && inScope(l.venue, userVenue))

  const alerts: { id: number; level: string; text: string; action: string }[] = []
  for (const l of newLeads.slice(0, 2)) {
    alerts.push({ id: 9000 + l.id, level: '高', text: `[${l.venue}] 新客资 ${l.name} 待首响（${l.source}）`, action: '24小时内完成首轮联系' })
  }
  alerts.push({ id: 1, level: '高', text: '郑好 留资超24小时未分配负责人', action: '立即分配首接老师' })
  alerts.push({ id: 2, level: '高', text: '变更记录批次 IMP-20260825-011 有2条失败', action: '查看错误明细并重试' })
  for (const t of scopedTasks.filter((t) => t.status === '已逾期')) {
    alerts.push({ id: 100 + t.id, level: '中', text: `任务「${t.title}-${t.customerName}」已逾期`, action: '提醒责任人完成闭环' })
  }
  alerts.push({ id: 4, level: '中', text: '许静姝 卡项3天后到期，尚无续费动作', action: '确认续费窗口沟通结果' })
  alerts.push({ id: 5, level: '低', text: '李梦 54天未到店且无未来预约', action: '检查服务断档原因' })

  return Promise.resolve(alerts)
}
