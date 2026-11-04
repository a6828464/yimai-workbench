/**
 * 课后分析 / 训练计划（授课老师端）
 *
 * 契约全部照后端实现写，不照设计稿猜：
 *  - 列表 GET /post-class-reviews → {records,total,current,size,summary}
 *    行字段是 studentName / classAt（**不是** customerName / classDate —— 旧页面读错字段，
 *    真机上表现为「姓名一直显示会员、时间一直显示 —」）。
 *  - 详情 GET /post-class-reviews/{id} → ok({id} + payload + {status,share,redFlag,
 *    studentType,teacherName,classAt,createdAt,confirmedAt})；payload 带 observations /
 *    redFlags / feedback / plan / objective / script / generated。
 *  - 目录 GET /post-class-reviews/catalog → PostClassPlanEngine::catalog()
 *    （维度/类型/红线/场景文案的唯一来源，前端不抄一份）
 *  - 候选 GET /post-class-reviews/candidates?days= → {records,leads,members,pendingCount}
 *  - 写：POST /post-class-reviews、PUT /{id}、POST /{id}/confirm。
 *    写权限只有 SUPER/MANAGER/TEACHER（assertCanWrite）；SERVICE 只读，越权写会 403
 *    「仅授课老师、店长可填写课后分析」。
 *
 * 训练计划：
 *  - GET /training-plans → **裸数组**，元素 = {id, ...payload, sourceReviewId, sourceBodyTestId, venue}
 *  - POST /training-plans body 必须是 {plan:{...}} → {clientId, serverId}
 *  - PUT /training-plans/{id} body {plan:{...}} → {id}
 *  - 后端**没有** GET /training-plans/{id}：详情只能用列表行经本地草稿传递（见 savePlanDraft）。
 */

import { http } from '@/utils/http'
import { getJSON, setJSON, storage } from '@/utils/storage'

/* ==========================================================================
 * 课后分析
 * ========================================================================== */

/** 观察项等级（评分维度：轻 / 中 / 重） */
export type ReviewLevel = '轻' | '中' | '重'

export const REVIEW_LEVELS: ReviewLevel[] = ['轻', '中', '重']

export interface ReviewObservation {
  key: string
  level: ReviewLevel | string
}

export interface ReviewFeedback {
  bodyFeel: string
  like: string
  concern: string
}

export interface ReviewIssue {
  text: string
  basis: string
}

export interface ReviewPlanPhase {
  key: string
  name: string
  duration: string
  durationTimes: string
  goal: string
  focus: string[]
  courses: string[]
  observationKeys: string[]
}

export interface ReviewPlanFrequency {
  text: string
  min: number
  max: number
  stableMin: number
  stableMax: number
}

export interface ReviewPlan {
  phases: ReviewPlanPhase[]
  frequency: ReviewPlanFrequency
  courses: string[]
  homeWork: string[]
  cautions: string[]
  basis: { key: string; label: string; level: string; direction: string }[]
}

export interface ReviewObjective {
  observed: string[]
  plan: { name: string; duration: string; goal: string; focus: string[] }[]
  frequency: string
  homework: string[]
  nextStep: string
  disclaimer: string
}

/** 列表行（GET /post-class-reviews 的 records 元素） */
export interface ReviewRow {
  id: number
  venue: string
  scene: string
  sceneLabel: string
  studentName: string
  studentType: string
  teacherName: string
  classAt: string
  courseName: string
  redFlag: boolean
  status: string
  share?: { code?: string; enabled?: boolean; views?: number } | null
  createdAt: string
  leadId?: number | null
  leadStatus: string
  dealAmount: number
  dealAt?: string | null
}

export interface ReviewListResult {
  records: ReviewRow[]
  total: number
  current: number
  size: number
  summary: { total: number; confirmed: number; redFlag: number }
}

export interface ReviewListQuery {
  status?: string
  studentType?: string
  /** '1' = 只看命中红线 */
  redFlag?: string
  dateFrom?: string
  dateTo?: string
  venue?: string
  current?: number
  size?: number
}

/** 详情：show = payload 摊平 + 若干列字段；未列出的键留在索引签名里 */
export interface ReviewDetail {
  id: number
  scene?: string
  studentName?: string
  studentPhone?: string
  studentType?: string
  teacherName?: string
  classAt?: string
  courseName?: string
  venue?: string
  goalText?: string
  observations?: ReviewObservation[]
  redFlags?: string[]
  feedback?: Partial<ReviewFeedback>
  issues?: ReviewIssue[]
  plan?: ReviewPlan | null
  objective?: ReviewObjective | null
  script?: Record<string, string>
  generated?: {
    red_flag?: boolean
    student_type?: string
    cautions?: string[]
    notes?: string[]
    handoff?: Record<string, unknown>
  } | null
  status?: string
  redFlag?: boolean
  createdAt?: string
  confirmedAt?: string
  share?: { code?: string; enabled?: boolean; views?: number } | null
  leadId?: number | null
  customerId?: number | null
  bookingId?: number | null
  [key: string]: unknown
}

/* ---------- 规则库目录 ---------- */

export interface CatalogObservationItem {
  key: string
  label: string
  fast: boolean
  phase: number
}

export interface CatalogGroup {
  group: string
  label: string
  items: CatalogObservationItem[]
}

export interface CatalogType {
  key: string
  label: string
  alias: string
  frequencyText: string
  courses: string[]
  boundary: string
}

export interface CatalogRedFlag {
  key: string
  label: string
  hint: string
}

export interface ReviewCatalog {
  groups: CatalogGroup[]
  types: CatalogType[]
  redFlags: CatalogRedFlag[]
  cardDirections: string[]
  students: string[]
  /** PHP 侧是 ['轻'=>'轻',…] 关联数组，序列化成对象；老实现可能是数组，两种都接 */
  levels: Record<string, string> | string[]
  scenes: Record<string, string>
  speechRules: string[]
  disclaimer: string
}

/* ---------- 候选人群 ---------- */

export type CandidateSource = 'class' | 'lead' | 'member'

export interface ReviewCandidate {
  source: CandidateSource
  personType?: 'member' | 'lead' | 'none'
  memberCard?: string
  memberRemain?: number | null
  bookingId?: number
  classAt?: string
  date?: string
  time?: string
  venue: string
  courseName?: string
  teacherName?: string
  studentName: string
  phone?: string
  phoneTail?: string
  kind?: string
  isTrial?: boolean
  /** class 行才有：trial / private */
  scene?: string
  status?: string
  customerId?: number | null
  leadId?: number | null
  leadStatus?: string
  demand?: string
  leadSource?: string
  leadDate?: string
  mainCard?: string
  remainTimes?: number | null
  hasReview: boolean
}

export interface ReviewCandidates {
  records: ReviewCandidate[]
  leads: ReviewCandidate[]
  members: ReviewCandidate[]
  pendingCount: number
}

/* ---------- 提交体（= 控制器 validate 白名单 + phases 覆写） ---------- */

export interface ReviewPayload {
  scene: string
  studentType?: string
  studentName: string
  studentPhone?: string
  classAt?: string
  courseName?: string
  venue?: string
  goalText?: string
  observations?: ReviewObservation[]
  redFlags?: string[]
  feedback?: Partial<ReviewFeedback>
  issues?: ReviewIssue[]
  leadId?: number | null
  customerId?: number | null
  bookingId?: number | null
  /** 老师微调过的阶段目标（按 phase.key 覆盖生成结果） */
  phases?: { key: string; goal: string }[]
}

function obj(v: unknown): Record<string, unknown> {
  return v && typeof v === 'object' && !Array.isArray(v) ? (v as Record<string, unknown>) : {}
}

function asList<T>(raw: unknown, keys: string[]): T[] {
  if (Array.isArray(raw)) return raw as T[]
  const o = obj(raw)
  for (const k of keys) {
    if (Array.isArray(o[k])) return o[k] as T[]
  }
  return []
}

/* ---------- 接口 ---------- */

/** GET /post-class-reviews */
export function fetchReviews(params?: ReviewListQuery) {
  return http.get<ReviewListResult>('/post-class-reviews', params as Record<string, unknown>)
}

/** GET /post-class-reviews/{id}（403 = 不在可见范围） */
export function fetchReviewDetail(id: number) {
  return http.get<ReviewDetail>(`/post-class-reviews/${id}`)
}

/** GET /post-class-reviews/catalog：维度/类型/红线/场景的唯一文案来源 */
export function fetchReviewCatalog() {
  return http.get<ReviewCatalog>('/post-class-reviews/catalog')
}

/** GET /post-class-reviews/candidates?days= */
export function fetchReviewCandidates(days = 7) {
  return http.get<ReviewCandidates>('/post-class-reviews/candidates', { days })
}

/** POST /post-class-reviews */
export function createReview(body: ReviewPayload) {
  return http.post<{ id: number }>('/post-class-reviews', body as unknown as Record<string, unknown>)
}

/** PUT /post-class-reviews/{id} */
export function updateReview(id: number, body: ReviewPayload) {
  return http.put<{ id: number }>(
    `/post-class-reviews/${id}`,
    body as unknown as Record<string, unknown>
  )
}

/** POST /post-class-reviews/{id}/confirm（已确认会 422「该课后分析已确认」） */
export function confirmReview(id: number) {
  return http.post<{ id?: number; shareCode?: string }>(`/post-class-reviews/${id}/confirm`)
}

/** DELETE /post-class-reviews/{id} */
export function deleteReview(id: number) {
  return http.del<{ id: number }>(`/post-class-reviews/${id}`)
}

/** GET /post-class-reviews/pending-count：tab 角标（工作台/客户经营 hub 共用，签名保持不变） */
export function fetchReviewPendingCount() {
  return http.get<{ count?: number } | number>('/post-class-reviews/pending-count', undefined, {
    silent: true
  })
}

/* ==========================================================================
 * 训练计划
 * ========================================================================== */

export type TrainingPlanStatus = '待生成' | '待老师确认' | '已确认'

export interface PlanPhase {
  name: string
  duration: string
  items: string[]
}

export interface PlanContent {
  summary: string
  phases: PlanPhase[]
  cautions: string[]
}

/**
 * 训练计划（= TrainingPlanController::present 的返回 + payload 摊平）。
 * 字段名与 admin-web/src/store/modules/training.ts 的 TrainingPlan 一致：
 * 两端读同一份 payload，避免「电脑端看得见、手机端一片空白」。
 */
export interface TrainingPlan {
  id: number
  memberName: string
  age: string
  gender: string
  height: string
  weight: string
  bodyFat: string
  focus: string
  coreGoal: string
  freq: string
  stageWeeks: string
  stageGoal: string
  risks: string
  status: string
  content: PlanContent | null
  source: string
  createdBy: string
  createdAt: string
  confirmedAt: string
  images: { id: number; url: string; label: string }[]
  share: { enabled: boolean; code: string; views: number } | null
  venue: string
  sourceReviewId?: number | null
  sourceBodyTestId?: number | null
  [key: string]: unknown
}

function s(v: unknown): string {
  if (typeof v === 'string') return v
  if (typeof v === 'number') return String(v)
  return ''
}

function normalizePhase(raw: unknown): PlanPhase {
  const o = obj(raw)
  return {
    name: s(o.name),
    duration: s(o.duration),
    items: Array.isArray(o.items) ? o.items.map((x) => s(x)).filter(Boolean) : []
  }
}

function normalizeContent(raw: unknown): PlanContent | null {
  if (!raw || typeof raw !== 'object') return null
  const o = obj(raw)
  return {
    summary: s(o.summary),
    phases: Array.isArray(o.phases) ? o.phases.map(normalizePhase) : [],
    cautions: Array.isArray(o.cautions) ? o.cautions.map((x) => s(x)).filter(Boolean) : []
  }
}

/** 后端一行 → 前端模型（缺字段给空串，模板不需要到处 `??`） */
export function normalizePlan(raw: unknown): TrainingPlan {
  const o = obj(raw)
  return {
    ...o,
    id: Number(o.id) || 0,
    memberName: s(o.memberName) || '未命名',
    age: s(o.age),
    gender: s(o.gender),
    height: s(o.height),
    weight: s(o.weight),
    bodyFat: s(o.bodyFat),
    focus: s(o.focus),
    coreGoal: s(o.coreGoal),
    freq: s(o.freq),
    stageWeeks: s(o.stageWeeks),
    stageGoal: s(o.stageGoal),
    risks: s(o.risks),
    status: s(o.status) || '待生成',
    content: normalizeContent(o.content),
    source: s(o.source),
    createdBy: s(o.createdBy),
    createdAt: s(o.createdAt),
    confirmedAt: s(o.confirmedAt),
    images: Array.isArray(o.images) ? (o.images as TrainingPlan['images']) : [],
    share: (o.share as TrainingPlan['share']) ?? null,
    venue: s(o.venue),
    sourceReviewId: (o.sourceReviewId as number | null) ?? null,
    sourceBodyTestId: (o.sourceBodyTestId as number | null) ?? null
  }
}

/** GET /training-plans（裸数组；权限：超管全部 / 店长本店 / 其他人本人的） */
export async function fetchTrainingPlans() {
  const raw = await http.get<unknown>('/training-plans')
  return asList<unknown>(raw, ['records', 'items', 'list']).map(normalizePlan)
}

/** POST /training-plans（body 必须是 {plan}，否则 422「plan 必须是对象」） */
export function createTrainingPlan(plan: TrainingPlan) {
  return http.post<{ clientId?: number; serverId?: number }>('/training-plans', {
    plan: serializePlan(plan)
  })
}

/** PUT /training-plans/{id}（不可见会 404「计划不存在或不在你的可见范围内」） */
export function updateTrainingPlan(id: number, plan: TrainingPlan) {
  return http.put<{ id: number }>(`/training-plans/${id}`, { plan: serializePlan(plan) })
}

/** DELETE /training-plans/{id} */
export function deleteTrainingPlan(id: number) {
  return http.del<{ id: number }>(`/training-plans/${id}`)
}

/**
 * 提交体：只发 payload 字段。后端 attributes() 会把 payload 整体落库，
 * 同时把 profile/goal/content/status 同步到独立列；venue/归属/id 由服务端维护，不发。
 */
function serializePlan(plan: TrainingPlan): Record<string, unknown> {
  return {
    memberName: plan.memberName,
    age: plan.age,
    gender: plan.gender,
    height: plan.height,
    weight: plan.weight,
    bodyFat: plan.bodyFat,
    focus: plan.focus,
    coreGoal: plan.coreGoal,
    freq: plan.freq,
    stageWeeks: plan.stageWeeks,
    stageGoal: plan.stageGoal,
    risks: plan.risks,
    status: plan.status,
    content: plan.content,
    source: plan.source || 'fallback',
    createdBy: plan.createdBy,
    createdAt: plan.createdAt,
    confirmedAt: plan.confirmedAt,
    images: plan.images,
    share: plan.share
  }
}

/* ==========================================================================
 * 本地草稿：后端没有 GET /training-plans/{id}，详情只能由列表把整行传过去
 * ========================================================================== */

const PLAN_DRAFT_PREFIX = 'yimai-plan-draft-'

/** 列表点击时调用：整行存草稿，详情页按 id 取回 */
export function savePlanDraft(plan: TrainingPlan): void {
  try {
    setJSON(`${PLAN_DRAFT_PREFIX}${plan.id}`, plan)
  } catch {
    /* 存不下就靠详情页「回查列表」兜底 */
  }
}

export function readPlanDraft(id: number): TrainingPlan | null {
  const raw = getJSON<unknown>(`${PLAN_DRAFT_PREFIX}${id}`)
  if (!raw || typeof raw !== 'object') return null
  const plan = normalizePlan(raw)
  return plan.id ? plan : null
}

/** 新建成功后把草稿迁到服务端 id 上（否则详情页拿 clientId 找不到） */
export function movePlanDraft(fromId: number, plan: TrainingPlan): void {
  if (fromId && fromId !== plan.id) {
    try {
      storage.removeItem(`${PLAN_DRAFT_PREFIX}${fromId}`)
    } catch {
      /* noop */
    }
  }
  savePlanDraft(plan)
}

/** 新建表单初值（status 用后端默认「待生成」） */
export function emptyPlan(): TrainingPlan {
  return normalizePlan({
    id: 0,
    memberName: '',
    status: '待生成',
    source: 'fallback',
    content: { summary: '', phases: [], cautions: [] },
    images: [],
    share: { enabled: false, code: '', views: 0 }
  })
}
