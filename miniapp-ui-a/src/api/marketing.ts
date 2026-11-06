/**
 * 营销工具 + AI 文案（小程序端）
 *
 * 契约按后端**实际实现**写，不按设计方案里的占位字段写：
 *  - POST /ai/chat                     → ok({content})  ← 已解包，拿到的是 {content:string}
 *  - GET  /marketing/history?platform=朋友圈|小红书
 *  - POST /marketing/history           {platform, title?, content, reply?, source?}
 *  - DEL  /marketing/history/{id}
 *  - GET  /marketing/favorites?platform=朋友圈|小红书
 *  - POST /marketing/favorites         {platform, title?, content, reply?, coverText?, tags?}
 *  - DEL  /marketing/favorites/{id}
 *
 * 两个容易踩的坑（都已核对源码）：
 *  1. 平台枚举是**中文** `朋友圈` / `小红书`（MarketingController::saveHistory 的 in: 规则），
 *     不是 `moments` / `xhs`；传错直接 422。
 *  2. 历史查询参数叫 `platform`，不是 `kind`。后端只做真值过滤，不做 in: 校验。
 *
 * 收藏与历史的分工（后端有意为之）：历史超 300 条静默删旧（可再生成）；
 * 收藏超 300 条**拒绝新增**并给中文原文 —— 所以这里的写收藏必须把 422 原文透出去。
 */

import { http, HttpError } from '@/utils/http'
import { getJSON, setJSON } from '@/utils/storage'
import { PERSONA_ROLE_OPTIONS, cachedPersonaSnapshot } from '@/api/profile'
import { useUserStore } from '@/store/user'

/* ==========================================================================
 * 平台与 AI 特征
 * ========================================================================== */

/** 后端唯一认的两种平台值（中文枚举，勿改成英文 id） */
export type MarketingPlatform = '朋友圈' | '小红书'

export const MARKETING_PLATFORMS: MarketingPlatform[] = ['朋友圈', '小红书']

/** POST /ai/chat 的 featureType 白名单（AiController 的 in: 规则） */
export type AiFeatureType = 'marketing_moments' | 'marketing_xhs' | 'chat'

export const PLATFORM_FEATURE: Record<MarketingPlatform, AiFeatureType> = {
  朋友圈: 'marketing_moments',
  小红书: 'marketing_xhs'
}

/** 语气选项（表单档位，写进 user prompt） */
export const TONES = ['自然真实', '专业干货', '轻松幽默', '温暖治愈', '元气满满'] as const
export type Tone = (typeof TONES)[number]

/** 长度档：短/中/长 → 字数区间。朋友圈整体收在 120-200 字内（品牌硬口径）。 */
export interface LengthOption {
  id: 'short' | 'medium' | 'long'
  label: string
  /** 朋友圈区间 */
  moments: [number, number]
  /** 小红书正文区间 */
  xhs: [number, number]
}

export const LENGTHS: LengthOption[] = [
  { id: 'short', label: '短', moments: [120, 145], xhs: [280, 420] },
  { id: 'medium', label: '中', moments: [145, 175], xhs: [420, 620] },
  { id: 'long', label: '长', moments: [175, 200], xhs: [620, 900] }
]

/** 人设兜底清单：单一来源在 `api/profile.ts` 的 PERSONA_ROLE_OPTIONS（人设页用同一份） */
export const PERSONA_ROLES = PERSONA_ROLE_OPTIONS

/** /my/profile 里的 profile.persona.role 缺失时的默认人设 */
export const DEFAULT_PERSONA_ROLE = '会籍顾问'

/* --------------------------------------------------------------------------
 * 人设上下文（身份 + 目标人群）
 *
 * 为什么要这一层：文案的「我是谁 / 写给谁看」直接决定内容，硬编码「会籍顾问」
 * 会让老师和管理层生成的文案全都串味。口径来自 `/my/profile` 的 profile.persona
 * （人设设置页 `pages/profile/persona` 写的同一份数据），不新增接口。
 *
 * 取值优先级（高 → 低）：
 *   1. 调用方显式传入（picker 里用户临时改的 / 人设页刚存的）
 *   2. pinia `user.profile.profile.persona`（本次冷启动内访问过 我的/人设/通知页就有）
 *   3. DEFAULT_PERSONA_ROLE「会籍顾问」+ 空人群（不编造人群，缺就整行不写）
 * -------------------------------------------------------------------------- */

export interface PersonaContext {
  /** 身份角色，如「全职老师」 */
  role: string
  /** 目标人群（可为空数组：没填就不写进提示词） */
  audiences: string[]
}

export const DEFAULT_PERSONA: PersonaContext = { role: DEFAULT_PERSONA_ROLE, audiences: [] }

function personaObj(v: unknown): Record<string, unknown> {
  return v && typeof v === 'object' && !Array.isArray(v) ? (v as Record<string, unknown>) : {}
}

/** 从 `/my/profile` 的响应（或其中 profile 字段）里读 persona；缺失给空值，不伪造 */
export function personaContextFromPayload(payload: unknown): PersonaContext {
  const root = personaObj(payload)
  // 兼容两种入参：整个响应 {profile:{persona}} 或已解出的 profile 本身
  const profile = root.persona ? root : personaObj(root.profile)
  const persona = personaObj(profile.persona)
  const role = typeof persona.role === 'string' ? persona.role.trim() : ''
  const audiences = Array.isArray(persona.audiences)
    ? persona.audiences.map((x) => (typeof x === 'string' ? x.trim() : '')).filter(Boolean)
    : []
  return { role, audiences }
}

/**
 * 读 pinia 里缓存的 `/my/profile`（`user.profile.profile.persona`）。
 * 拿不到就返回 DEFAULT_PERSONA —— 这里绝不能抛：它会被提示词构建同步调用。
 */
export function activePersonaContext(): PersonaContext {
  // 1) pinia 里本次会话刚拉到的 profile
  try {
    const store = useUserStore()
    const ctx = personaContextFromPayload(store.profile?.profile)
    if (ctx.role || ctx.audiences.length) return { role: ctx.role || DEFAULT_PERSONA.role, audiences: ctx.audiences }
  } catch {
    /* pinia 未就绪（如脚本/单测环境）→ 继续走本地快照 */
  }
  // 2) 上次 GET/PUT /my/profile 落下的本地快照（冷启动也能读到）
  const snap = cachedPersonaSnapshot()
  if (snap) return { role: snap.role || DEFAULT_PERSONA.role, audiences: snap.audiences }
  // 3) 兜底：会籍顾问 + 不写人群
  return { role: DEFAULT_PERSONA.role, audiences: [] }
}

/** 合并优先级：显式入参 > 缓存 > 默认 */
function resolvePersona(input: PromptInput): PersonaContext {
  const cached = activePersonaContext()
  const role = (input.personaRole || '').trim() || cached.role || DEFAULT_PERSONA_ROLE
  const source = input.personaAudiences ?? cached.audiences
  const audiences = source.map((x) => (x || '').trim()).filter(Boolean)
  return { role, audiences }
}

export function isMarketingPlatform(v: unknown): v is MarketingPlatform {
  return v === '朋友圈' || v === '小红书'
}

/* ==========================================================================
 * 防御取值工具
 * 目的：后端字段名/可空性变化时不炸页面（收藏的 tags 列老数据可能整键缺失）
 * ========================================================================== */

function obj(v: unknown): Record<string, unknown> {
  return v && typeof v === 'object' && !Array.isArray(v) ? (v as Record<string, unknown>) : {}
}

function str(v: unknown): string {
  if (typeof v === 'string') return v
  if (typeof v === 'number') return String(v)
  return ''
}

function num(v: unknown, fallback = 0): number {
  const n = typeof v === 'number' ? v : Number(v)
  return Number.isFinite(n) ? n : fallback
}

/** 列表响应既可能是裸数组，也可能是 {records|list|items|rows} 包裹 */
function asList(raw: unknown): unknown[] {
  if (Array.isArray(raw)) return raw
  const o = obj(raw)
  for (const key of ['records', 'list', 'items', 'rows']) {
    if (Array.isArray(o[key])) return o[key] as unknown[]
  }
  return []
}

/* ==========================================================================
 * AI 生成
 * ========================================================================== */

export interface ChatMessage {
  role: 'system' | 'user' | 'assistant'
  content: string
}

export interface AiChatInput {
  messages: ChatMessage[]
  temperature?: number
  maxTokens?: number
  featureType?: AiFeatureType
}

/**
 * 兼容三种可能的外壳：已解包的 {content} / 再包一层 {data:{content}} / 纯字符串。
 * 后端 ok() 是 {code:0,data:{content}}，http 层已 unwrap，正常只命中第一种。
 */
function extractContent(raw: unknown): string {
  if (typeof raw === 'string') return raw
  const o = obj(raw)
  const direct = o.content ?? o.text
  if (typeof direct === 'string' && direct.trim()) return direct
  const nested = obj(o.data)
  const inner = nested.content ?? nested.text
  if (typeof inner === 'string' && inner.trim()) return inner
  return ''
}

/**
 * POST /ai/chat
 *
 * silent: 由页面统一渲染可读错误（未配置 AI 要「不红屏」的提示卡，而不是一过性 toast）。
 * timeout: 后端最长等上游 120s，20s 默认值会把正常的长文案直接掐断。
 */
export async function aiChat(input: AiChatInput): Promise<string> {
  const body: Record<string, unknown> = {
    messages: input.messages,
    featureType: input.featureType ?? 'chat'
  }
  if (input.temperature !== undefined) body.temperature = input.temperature
  if (input.maxTokens !== undefined) body.maxTokens = input.maxTokens

  const raw = await http.post<unknown>('/ai/chat', body, { silent: true, timeout: 120000 })
  const content = extractContent(raw).trim()
  if (!content) throw new HttpError('模型没有返回内容，请重试', -1)
  return content
}

/** 未配置模型：后端 422 {code:1,message:'尚未配置 API Key'} */
export function isAiNotConfigured(e: unknown): boolean {
  const msg = e instanceof Error ? e.message : typeof e === 'string' ? e : ''
  return /尚未配置|未配置\s*API\s*Key|未配置模型|not configured/i.test(msg)
}

/** 把异常翻成用户能懂的一句话（配置类问题给行动指引，其余原样透出） */
export function aiReadableError(e: unknown): string {
  if (isAiNotConfigured(e)) {
    return '管理员还没有配置 AI 模型，暂时生成不了。可在电脑端「设置 → 模型配置」配好后再回来。'
  }
  if (e instanceof HttpError) {
    if (e.statusCode === 429) return e.message || '今日 AI 生成次数已达上限，请明天再试'
    if (e.statusCode === 403) return '当前账号没有权限使用该功能'
    return e.message || '生成失败，请稍后重试'
  }
  if (e instanceof Error) return e.message || '生成失败，请稍后重试'
  return '生成失败，请稍后重试'
}

/* ==========================================================================
 * 生成历史（服务端）
 * ========================================================================== */

export interface MarketingHistoryItem {
  id: number
  platform: string
  title: string
  content: string
  reply: string
  source: string
  createdAt: string
}

function normalizeHistoryRow(raw: unknown): MarketingHistoryItem | null {
  const o = obj(raw)
  const content = str(o.content)
  if (!content) return null
  return {
    id: num(o.id),
    platform: str(o.platform),
    title: str(o.title),
    content,
    reply: str(o.reply),
    source: str(o.source),
    createdAt: str(o.createdAt) || str(o.created_at)
  }
}

/** GET /marketing/history?platform= */
export async function fetchMarketingHistory(platform?: MarketingPlatform): Promise<MarketingHistoryItem[]> {
  const raw = await http.get<unknown>(
    '/marketing/history',
    platform ? { platform } : undefined,
    { silent: true }
  )
  return asList(raw)
    .map(normalizeHistoryRow)
    .filter((x): x is MarketingHistoryItem => x !== null)
}

export interface SaveHistoryInput {
  platform: MarketingPlatform
  title?: string
  content: string
  reply?: string
  source?: 'llm' | 'fallback'
}

/** POST /marketing/history（失败不打断主流程，由调用方 catch 后忽略） */
export function saveMarketingHistory(input: SaveHistoryInput) {
  return http.post<{ id?: number }>(
    '/marketing/history',
    {
      platform: input.platform,
      title: input.title ?? '',
      content: input.content,
      reply: input.reply ?? '',
      source: input.source ?? 'llm'
    },
    { silent: true }
  )
}

/** DELETE /marketing/history/{id} */
export function removeMarketingHistory(id: number) {
  return http.del<unknown>(`/marketing/history/${id}`, undefined, { silent: true })
}

/* ==========================================================================
 * 收藏（服务端持久化）
 * ========================================================================== */

export interface MarketingFavoriteItem {
  id: number
  platform: string
  title: string
  content: string
  reply: string
  coverText: string
  tags: string[]
  createdAt: string
}

function normalizeFavoriteRow(raw: unknown): MarketingFavoriteItem | null {
  const o = obj(raw)
  const content = str(o.content)
  if (!content) return null
  const tags = Array.isArray(o.tags) ? o.tags.map((t) => str(t)).filter(Boolean) : []
  return {
    id: num(o.id),
    platform: str(o.platform),
    title: str(o.title),
    content,
    reply: str(o.reply),
    coverText: str(o.coverText),
    tags,
    createdAt: str(o.createdAt) || str(o.created_at)
  }
}

/** GET /marketing/favorites?platform= */
export async function fetchMarketingFavorites(platform?: MarketingPlatform): Promise<MarketingFavoriteItem[]> {
  const raw = await http.get<unknown>(
    '/marketing/favorites',
    platform ? { platform } : undefined,
    { silent: true }
  )
  return asList(raw)
    .map(normalizeFavoriteRow)
    .filter((x): x is MarketingFavoriteItem => x !== null)
}

export interface SaveFavoriteInput {
  platform: MarketingPlatform
  title?: string
  content: string
  reply?: string
  coverText?: string
  tags?: string[]
}

/**
 * POST /marketing/favorites
 * 不做兜底吞错：上限 422 的中文原文要原样给用户看（「收藏已达上限 300 条…」）。
 */
export async function saveMarketingFavorite(input: SaveFavoriteInput): Promise<number> {
  const res = await http.post<unknown>(
    '/marketing/favorites',
    {
      platform: input.platform,
      title: input.title ?? '',
      content: input.content,
      reply: input.reply ?? '',
      coverText: input.coverText ?? '',
      tags: input.tags ?? []
    },
    { silent: true }
  )
  return num(obj(res).id)
}

/** 写收藏失败的原因（区分「超上限」与「网络/其它」，文案不同） */
export function favoriteWriteError(e: unknown): string {
  const msg = e instanceof Error ? e.message : typeof e === 'string' ? e : ''
  if (/上限|最多/.test(msg)) return msg
  if (e instanceof HttpError && e.statusCode === 422) return msg || '收藏失败，请稍后重试'
  return msg || '收藏失败，请检查网络后重试'
}

/** DELETE /marketing/favorites/{id}（服务端幂等：他人/不存在 id 也回 ok） */
export function removeMarketingFavorite(id: number) {
  return http.del<unknown>(`/marketing/favorites/${id}`, undefined, { silent: true })
}

/* ==========================================================================
 * 最近生成（本地缓存，key: yimai-ai-recent）
 * 用途：AI 助手 hub 首页「最近生成 3 条」。服务端历史要等网络，hub 首屏必须秒开。
 * ========================================================================== */

export const RECENT_STORAGE_KEY = 'yimai-ai-recent'

/** 本地最多留 8 条（hub 只展示 3 条，多留几条给「换一屏」留余地） */
const RECENT_LIMIT = 8

export interface RecentGeneration {
  id: string
  platform: string
  title: string
  preview: string
  createdAt: string
}

function pad2(n: number): string {
  return String(n).padStart(2, '0')
}

/** 本地时间 `YYYY-MM-DD HH:mm`（与后端 createdAt 同格式，展示层不用再判断） */
export function formatLocalTime(d = new Date()): string {
  return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())} ${pad2(d.getHours())}:${pad2(
    d.getMinutes()
  )}`
}

function normalizeRecentRow(raw: unknown): RecentGeneration | null {
  const o = obj(raw)
  const preview = str(o.preview) || str(o.content)
  if (!preview) return null
  return {
    id: str(o.id) || `${Date.now()}-${Math.random().toString(16).slice(2, 8)}`,
    platform: str(o.platform),
    title: str(o.title),
    preview,
    createdAt: str(o.createdAt)
  }
}

/** 读最近生成（坏数据静默丢弃，不抛错 —— hub 首屏不能因为缓存脏了就白屏） */
export function listRecentGenerations(limit = 3): RecentGeneration[] {
  const raw = getJSON<unknown>(RECENT_STORAGE_KEY)
  const rows = Array.isArray(raw) ? raw : asList(raw)
  return rows
    .map(normalizeRecentRow)
    .filter((x): x is RecentGeneration => x !== null)
    .slice(0, Math.max(1, limit))
}

/** 写一条最近生成（新的在前，自动截断） */
export function pushRecentGeneration(item: {
  platform: string
  title?: string
  preview: string
  createdAt?: string
}): RecentGeneration[] {
  const preview = (item.preview ?? '').trim()
  if (!preview) return listRecentGenerations(RECENT_LIMIT)
  const row: RecentGeneration = {
    id: `${Date.now()}-${Math.random().toString(16).slice(2, 8)}`,
    platform: item.platform,
    title: (item.title ?? '').trim(),
    preview: preview.slice(0, 300),
    createdAt: item.createdAt || formatLocalTime()
  }
  const next = [row, ...listRecentGenerations(RECENT_LIMIT)].slice(0, RECENT_LIMIT)
  setJSON(RECENT_STORAGE_KEY, next)
  return next
}

/** 清空最近生成（hub 的「清空」按钮，调用前需二次确认） */
export function clearRecentGenerations(): void {
  setJSON(RECENT_STORAGE_KEY, [])
}

/* ==========================================================================
 * 提示词构建（营销文案）
 * 口径来源：方案 §5.6 + 品牌硬约束（一麦瑜伽·宁波 / 口语化 / 不堆 emoji / 朋友圈 120-200 字 /
 * 小红书带标题·分段·5 个话题标签）。
 * 写成函数而不是模板字符串常量：平台 × 长度 × 语气 × 人设都是变量，拼在页面里会散成多份。
 * ========================================================================== */

export interface PromptInput {
  platform: MarketingPlatform
  theme: string
  personaRole: string
  /**
   * 目标人群（人设设置页的 persona.audiences）。
   * 不传则读 pinia 里的人设缓存；两边都没有就整行不写 —— 不编造人群。
   */
  personaAudiences?: string[]
  tone: Tone
  length: LengthOption
  sellingPoint?: string
  /** 重新生成时给「别重复」的参照 */
  avoid?: string
}

const BRAND = '一麦瑜伽·宁波'

/** 三条候选的分隔约定：单独一行 ---（页面按它切分，切不出就整段一条） */
const SEPARATOR_RULE = '一次输出 3 条彼此不同的文案，每条之间用单独一行 "---" 分隔；不要写「第1条」这类前缀，不要在开头或结尾加任何说明。'

export function buildSystemPrompt(platform: MarketingPlatform): string {
  if (platform === '小红书') {
    return [
      `你是「${BRAND}」的小红书账号主理人，写给宁波本地想练瑜伽 / 普拉提的女生看。`,
      '硬性要求：',
      '1. 结构固定：第一行是标题（不超过 20 字，埋搜索关键词，如「宁波普拉提」「久坐肩颈」）；空一行；正文分 2-4 段，每段 2-4 行；最后单独一行放 5 个话题标签（每个以 # 开头，至少 2 个带「宁波」）。',
      '2. 正文口语化、真诚、具体：写清谁适合、怎么开始、练完是什么感觉，给 2-3 个可执行的小步骤。',
      '3. 不要 emoji 堆砌：全文最多 2 个，可以一个都不放；不要颜文字、不要一排表情。',
      '4. 不写医疗承诺（治愈 / 根治 / 矫正疾病）、不写绝对化用语（最 / 第一 / 唯一）、不写暴瘦暴汗这类夸张话。',
      '5. 品牌固定「一麦瑜伽·宁波」，不编造价格、活动、优惠和具体人名。',
      '6. 除话题标签的 # 之外不要任何 Markdown 标记（不用 ** 加粗、不用 ## 标题）。',
      '7. ' + SEPARATOR_RULE
    ].join('\n')
  }
  return [
    `你是「${BRAND}」的资深会籍顾问，写的是自己店里真实发生的事。`,
    '硬性要求：',
    '1. 第一人称、口语化，像真人随手发朋友圈：允许短句、停顿、轻微情绪，不要广告腔和模板句。',
    '2. 全文 120-200 字（按用户在下面给的长度档取区间）。',
    '3. 不要 emoji 堆砌：全文最多 1 个，可以一个都不放；不要颜文字、不要一排表情。',
    '4. 不写医疗承诺（治愈 / 根治 / 包好）、不写绝对化用语（最 / 第一 / 唯一）、不写暴瘦暴汗。',
    '5. 品牌固定「一麦瑜伽·宁波」，不编造价格、活动、优惠和具体人名。',
    '6. 不要标题、不要序号、不要 Markdown 标记；结尾可以有一句轻量的引导（想练就来看看 / 评论区说一声）。',
    '7. 落到具体场景和身体感受：谁、什么时候、做了什么、身体什么变化，不要空泛抒情。',
    '8. ' + SEPARATOR_RULE
  ].join('\n')
}

export function buildUserPrompt(input: PromptInput): string {
  const [min, max] = input.platform === '小红书' ? input.length.xhs : input.length.moments
  const persona = resolvePersona(input)
  const lines = [
    `【平台】${input.platform}`,
    `【主题】${input.theme.trim()}`,
    `【我的身份】${persona.role}`,
    // 人群只在真的填了的时候出现：空数组会让模型自己编目标客户
    ...(persona.audiences.length ? [`【目标人群】${persona.audiences.join('、')}`] : []),
    `【语气】${input.tone}`,
    `【篇幅】${input.length.label}（${min}-${max} 字${input.platform === '小红书' ? '，指正文不含标题和话题标签' : ''}）`,
    `【门店 / 课程卖点】${(input.sellingPoint ?? '').trim() || '未提供 —— 不要编造具体活动、价格和优惠'}`,
    '请直接给正文，不要解释你的思路。'
  ]
  if (input.avoid) {
    lines.push(
      `【避免重复】上一条是：「${input.avoid.slice(0, 300)}」—— 换一个开头、角度和例子，不要重复同样的句式。`
    )
  }
  return lines.join('\n')
}

/* ==========================================================================
 * 候选切分
 * ========================================================================== */

/** 行首编号：1. / 1、/ 1) / (1) / 一、/ 【1】 / ① */
const NUMBERED_LINE = /^\s*(?:[（(]?\d{1,2}[）).、]|[一二三四五]、|[【\[]\d{1,2}[】\]]|[①②③④⑤])\s*/

/**
 * 把一次生成的整段文本切成最多 3 条候选。
 *
 * 顺序（前者命中就不再看后者）：
 *  1. 显式分隔线 `---`
 *  2. 行首编号（至少 2 段、且每段 ≥ 30 字，避免把正文里的「1. 吸气」当成候选边界）
 *  3. 空行分段（仅朋友圈用：小红书正文本身就有空行，按空行切会切碎一篇笔记）
 *  4. 整段一条
 */
export function splitCandidates(text: string, platform: MarketingPlatform): string[] {
  const raw = (text ?? '').replace(/\r\n/g, '\n').trim()
  if (!raw) return []

  const byRule = raw
    .split(/\n\s*-{3,}\s*\n/)
    .map((s) => s.trim())
    .filter(Boolean)
  if (byRule.length > 1) return byRule.slice(0, 3)

  const numbered = splitByNumberedLines(raw)
  if (numbered.length > 1) return numbered.slice(0, 3)

  if (platform === '朋友圈') {
    const byBlank = raw
      .split(/\n\s*\n+/)
      .map((s) => s.trim())
      .filter(Boolean)
    /**
     * 空行分段的两个闸门，都是为了不把「一条朋友圈里的自然分段」误切成多条：
     *  - 每段 ≥ 40 字：3 条候选（每条 120-200 字）任何一段都不会这么短；
     *  - 全文 ≥ 260 字：单条朋友圈按品牌口径最多 200 字，够不到 260，所以单条绝不会被切。
     * 小红书永远不走这条分支（正文本身就有空行，切了必然切碎一篇笔记）。
     */
    const total = raw.replace(/\s/g, '').length
    if (
      byBlank.length > 1 &&
      byBlank.length <= 3 &&
      total >= 260 &&
      byBlank.every((s) => s.length >= 40)
    ) {
      return byBlank.slice(0, 3)
    }
  }
  return [raw]
}

/* ==========================================================================
 * 谈单话术三段解析（开场 / 价值 / 收尾）
 * 与 splitCandidates 同类：都是「把模型的一段文本还原成结构化块」。
 * 放在 api 层而不是页面里：纯函数，可离线断言（页面里的逻辑只能靠手点）。
 * ========================================================================== */

export interface ScriptSection {
  label: string
  content: string
}

const SCRIPT_LABELS = ['开场', '价值', '收尾']

/**
 * 解析「开场 / 价值 / 收尾」三段。
 *
 * 三段缺任何一段就返回空数组 —— 让调用方整段回退展示原文，而不是把半截话术
 * 当成「完整的三段」展示（半截话术最容易被直接复制发给客户）。
 */
export function parseScriptSections(text: string): ScriptSection[] {
  const lines = (text ?? '').replace(/\r\n/g, '\n').split('\n')
  const found: ScriptSection[] = []
  let current: ScriptSection | null = null

  for (const line of lines) {
    const m = line.match(/^\s*[【\[]?\s*(开场|价值|收尾)\s*[】\]]?\s*[:：]?\s*(.*)$/)
    if (m) {
      if (current) found.push(current)
      current = { label: m[1], content: (m[2] ?? '').trim() }
      continue
    }
    if (current) {
      const next = line.trim()
      current.content = current.content ? `${current.content}\n${next}` : next
    }
  }
  if (current) found.push(current)

  const kept = found.filter((s) => s.content.trim().length > 0)
  if (kept.length < SCRIPT_LABELS.length) return []
  // 模型偶尔调换段落顺序：按「开场 / 价值 / 收尾」稳定输出
  return SCRIPT_LABELS.map((label) => kept.find((s) => s.label === label)).filter(
    (s): s is ScriptSection => !!s
  )
}

function splitByNumberedLines(raw: string): string[] {
  const lines = raw.split('\n')
  const chunks: string[] = []
  let current: string[] | null = null
  for (const line of lines) {
    if (NUMBERED_LINE.test(line)) {
      if (current) chunks.push(current.join('\n').trim())
      current = [line.replace(NUMBERED_LINE, '')]
      continue
    }
    if (current) current.push(line)
  }
  if (current) chunks.push(current.join('\n').trim())
  const kept = chunks.filter(Boolean)
  if (kept.length < 2 || kept.some((c) => c.length < 30)) return []
  return kept
}
