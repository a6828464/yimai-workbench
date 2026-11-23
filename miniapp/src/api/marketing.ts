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
  /**
   * 图片载荷（`data:image/(jpeg|png|webp);base64,...`），最多 `MOMENTS_MAX_IMAGES` 张。
   * 不传 / 空数组 → 请求体里**没有** `attachments` 这个键，与加图片功能之前逐字一致。
   */
  attachments?: string[]
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

  /**
   * 有图才加 `attachments` 键。
   * 无图时 payload 与加图片功能之前**逐字节一致**（后端 VisionAttachments 也是「空数组不追加」），
   * 这样已有的 3 条候选生成链路不会因为新增图片能力出现任何行为漂移。
   */
  const attachments = (input.attachments ?? [])
    .filter((u): u is string => typeof u === 'string' && u.startsWith('data:image/'))
    .slice(0, MOMENTS_MAX_IMAGES)
  if (attachments.length) body.attachments = attachments

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

/* ==========================================================================
 * 朋友圈配图 / 读图
 *
 * 口径与 web 端 `admin-web/src/api/ai.ts`（MOMENTS_* 系列）保持一致，
 * 但这里只搬「小程序需要的那一小块」：张数上限、单张体积上限、模型读图判定、
 * 以及从模型原文里捡回「图片理解」。不搬 PC 的 JSON schema（本端提示词要的是
 * 纯文本候选，加了 JSON 会让 splitCandidates 与「复制直接发圈」都变脏）。
 *
 * 后端契约（backend/app/Support/VisionAttachments.php，已核对源码）：
 *  - `attachments` 最多 3 个，形态必须是 `data:image/(jpeg|png|webp);base64,` 内联载荷；
 *  - 单张解码后 ≤1.5MB、多图合计 ≤4MB，超了直接 422 中文提示（不静默截断）。
 * 因此本机压到单张 ≤200KB 留足余量 —— 微信里 3 张原图动辄 6~15MB，base64 后必然 413。
 * ========================================================================== */

/** 一次最多几张图（与后端 VisionAttachments::MAX_IMAGES、web 端 MOMENTS_MAX_IMAGES 同口径） */
export const MOMENTS_MAX_IMAGES = 3

/** 单张 dataURL 解码后的目标体积上限：200KB（后端单张上限 1.5MB，余量 ×7） */
export const MOMENTS_IMAGE_MAX_BYTES = 200 * 1024

/** 有图但该张没填说明时的逐图占位：不编造图片内容，让模型自己看图 */
export const MOMENTS_IMAGE_NO_NOTE = '用户上传图片，未填说明'

/**
 * 读图（多模态）模型能力词表。
 *
 * 与 web 端 `MOMENTS_VISION_MODEL_TOKENS` 保持同一份口径（新增型号时两边一起改）。
 * 只认显式能力词，不做模糊猜测 —— 猜错会让店员误以为图片已被识别（典型的假绿）。
 */
export const VISION_MODEL_TOKENS: RegExp[] = [
  /(?:^|[-_.\s])vl\d*(?:[-_.\s]|$)/i, // qwen3-vl-max / deepseek-vl2 / mimo-vl
  /internvl/i,
  /minicpm-v/i,
  /step-1v/i,
  /qwen[\w.]*-vl/i, // qwen-vl / qwen3-vl / qwen2.5-vl
  /glm-4(?:\.\d+)?v/i, // glm-4v / glm-4.5v / glm-4.1v
  /vision/i, // yi-vision / doubao-1.5-vision
  /seed-[\w.]*-vision/i,
  /(?:^|[-_.\s])vl\b/i,
  /gpt-4o/i,
  /gpt-4\.1/i,
  /gpt-4-turbo/i,
  /gpt-5/i,
  /(?:^|[-_.\s])o[34](?:[-_.\s]|$)/i,
  /claude/i,
  /sonnet/i,
  /opus/i,
  /gemini/i,
  /moonshot[\w.-]*vision/i,
  /kimi[\w.-]*vl/i,
  /llava/i,
  /pixtral/i
]

/** 模型名看起来能不能读图（空名一律判否：未知 ≠ 能读） */
export function supportsVisionModel(model: string): boolean {
  const name = String(model ?? '').trim()
  return !!name && VISION_MODEL_TOKENS.some((re) => re.test(name))
}

/** 换模型的去处：小程序端没有模型配置页，唯一入口是电脑端工作台 */
export const VISION_MODEL_TIP =
  '在电脑端工作台「设置 → 模型配置」把模型换成支持读图的型号（如 gpt-4o / qwen3-vl-max / glm-4v）后重试，文案才会结合图片里的具体内容。'

export interface AiModelInfo {
  /** 当前配置的模型名（读不到就是空串，不猜） */
  model: string
  /** 是否启用（后端 enabled 缺省按启用处理） */
  enabled: boolean
  /** 是否已配好 API Key */
  configured: boolean
}

/**
 * GET /ai/config（AiController::configShow，全部登录角色可读、不含密钥明文）。
 * 只为了在选图后立刻说清「这模型看不看得懂图」—— 读失败就当未知，不打扰用户。
 */
export async function fetchAiModelInfo(): Promise<AiModelInfo> {
  const raw = await http.get<unknown>('/ai/config', undefined, { silent: true })
  const o = obj(raw)
  return {
    model: str(o.model),
    enabled: o.enabled !== false,
    configured: o.configured === true
  }
}

export interface VisionGuard {
  /** 是否要显示显眼提示（无图恒 false） */
  blocked: boolean
  /** 本次是否已选图 */
  hasImages: boolean
  /** 能力判定结果（来自词表） */
  supportsVision: boolean
  /** 当前模型名（可能为空串） */
  model: string
  /** 提示主句（blocked=false 时为空串） */
  title: string
  /** 原因与可照做的修复动作（blocked=false 时为空串） */
  detail: string
}

/**
 * 生成前的读图守卫：**有图 + 当前模型看不了图**时，在「点生成之前」就把话说清楚。
 *
 * 为什么不在生成之后再提示：那时店员眼睛已经落在文案上，角落一行小字等于没说，
 * 「传了 3 张图、文案却跟图无关」会被理解成功能坏了。
 *
 * 判定仍只走 `supportsVisionModel`（词表单一事实来源，视图不另写一套正则）。
 * **不硬拦生成**：按模型名判定是启发式，判错的方向不能是「让人用不了功能」。
 */
export function resolveVisionGuard(input: {
  hasImages: boolean
  imageCount?: number
  model: string
  aiReady: boolean
}): VisionGuard {
  const model = String(input.model ?? '').trim()
  const supportsVision = supportsVisionModel(model)
  const count = Math.max(0, Math.trunc(Number(input.imageCount ?? 0)) || 0)
  const base: VisionGuard = { blocked: false, hasImages: !!input.hasImages, supportsVision, model, title: '', detail: '' }
  // 没选图就不谈读图：与图无关的提醒只是噪音
  if (!input.hasImages) return base
  const howMany = count > 0 ? `已选 ${Math.min(count, MOMENTS_MAX_IMAGES)} 张图` : '已选图片'
  if (!input.aiReady) {
    return {
      ...base,
      blocked: true,
      title: `${howMany}，但还没接入大模型，图片不会被识别`,
      detail:
        '先让管理员在电脑端「设置 → 模型配置」接入并启用大模型、且选支持读图的型号，模型才能看图写文案；未接入时图片不会被任何模型看到。'
    }
  }
  if (!supportsVision) {
    return {
      ...base,
      blocked: true,
      title: `${howMany}，但当前模型${model ? `「${model}」` : ''}看不了图，图片内容不会被识别`,
      detail: `本次仍会把图片一起发给模型，但这个型号不支持读图，文案只能凭主题写，自然对不上图。${VISION_MODEL_TIP}（读图能力按模型名判定，若你确认该型号能读图，可以直接继续生成。）`
    }
  }
  return base
}

/**
 * 后端 422「带图但模型不读图」的语义。
 * AiController 在 `VisionAttachments::looksLikeImageUnsupported()` 命中时回
 * `{code:1, visionUnsupported:true, message:'当前模型不支持图片输入，请在「模型配置」…'}`，
 * http 层只把 message 透出来，所以这里按文案判（同一句由后端写死，不会漂）。
 */
export function isVisionUnsupported(e: unknown): boolean {
  const msg = e instanceof Error ? e.message : typeof e === 'string' ? e : ''
  return /不支持图片输入|不支持读图|vision[\s_-]*unsupported/i.test(msg)
}

/** 把异常翻成用户能懂的一句话（配置类问题给行动指引，其余原样透出） */
export function aiReadableError(e: unknown): string {
  if (isAiNotConfigured(e)) {
    return '管理员还没有配置 AI 模型，暂时生成不了。可在电脑端「设置 → 模型配置」配好后再回来。'
  }
  if (isVisionUnsupported(e)) {
    return `当前模型不支持读图，这次图片没被识别。${VISION_MODEL_TIP}`
  }
  if (e instanceof HttpError) {
    if (e.statusCode === 429) return e.message || '今日 AI 生成次数已达上限，请明天再试'
    if (e.statusCode === 403) return '当前账号没有权限使用该功能'
    if (e.statusCode === 422) {
      // 422 是带图链路最常见的「图不对」：张数/体积/格式的中文原文必须原样给用户看
      return e.message || '图片或参数不符合要求，请调整后重试'
    }
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
  /**
   * 本次随请求下发的图片逐张说明（顺序 = 图片顺序，长度 = 图片张数）。
   * 不传 / 空数组时提示词与加图片能力之前**逐字一致**（无图时不许出现任何图片段落）。
   */
  imageNotes?: string[]
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

/**
 * 带图时的三段追加提示（逐图说明 / 看图要求 / 输出顺序）。
 *
 * 为什么要求模型**先输出图片理解**：只给 3 条文案时，「模型真看了图」和「模型压根没看图、
 * 凭主题套模板」在界面上完全同形。让它先把看到的画面说出来，店员能一眼核对
 * （图里明明是普拉提核心床，模型却说「空中吊床」→ 立刻发现没读对）。
 *
 * 措辞约束：这里**不写**「图1：…」这样的示例行以外的逐图内容，也不替模型编任何描述。
 */
function imagePromptLines(notes: string[], count: number): string[] {
  const out = ['【图片逐张说明】']
  for (let i = 0; i < count; i += 1) {
    out.push(`图${i + 1}：${(notes[i] ?? '').trim() || MOMENTS_IMAGE_NO_NOTE}`)
  }
  out.push(
    `【看图要求】本次已随请求附带 ${count} 张图片，它们是这条朋友圈要配的图：正文必须落在图片里的具体元素上（例如「这张是空中吊床」「垫子上还留着刚才的汗印」），不要写跟图无关的通用模板句；某一张看不清就写「看不清」，不要编。`
  )
  out.push(
    '【输出顺序】先在开头单独输出一段【图片理解】：每行一条，格式「图1：看到了什么」，逐张交代场景、人物、动作/体态、器械/道具、色调，画面里有可辨认的文字（课表、标语、器材上的字）就照抄；这一段之后空一行，再按上面的分隔约定输出 3 条文案。这一段是必给的（用来核对是否真的看清了图），它不是「解释思路」。'
  )
  return out
}

export function buildUserPrompt(input: PromptInput): string {
  const [min, max] = input.platform === '小红书' ? input.length.xhs : input.length.moments
  const persona = resolvePersona(input)
  const notes = (input.imageNotes ?? []).slice(0, MOMENTS_MAX_IMAGES)
  const lines = [
    `【平台】${input.platform}`,
    `【主题】${input.theme.trim()}`,
    `【我的身份】${persona.role}`,
    // 人群只在真的填了的时候出现：空数组会让模型自己编目标客户
    ...(persona.audiences.length ? [`【目标人群】${persona.audiences.join('、')}`] : []),
    `【语气】${input.tone}`,
    `【篇幅】${input.length.label}（${min}-${max} 字${input.platform === '小红书' ? '，指正文不含标题和话题标签' : ''}）`,
    `【门店 / 课程卖点】${(input.sellingPoint ?? '').trim() || '未提供 —— 不要编造具体活动、价格和优惠'}`
  ]
  // 无图：尾行与改造前逐字一致（新增图片能力不许改动既有请求体）
  if (notes.length) lines.push(...imagePromptLines(notes, notes.length))
  else lines.push('请直接给正文，不要解释你的思路。')
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
 * 「图片理解」解析
 *
 * 为什么要解析：只给 3 条文案时，店员无法判断文案是真的用了图，还是模型没看图、
 * 凭主题套了个通用模板 —— 后者在界面上与前者**完全同形**。把模型自述「看到了什么」
 * 单独摆出来，才能一眼核对（图里明明是普拉提核心床，模型却说「空中吊床」→ 立刻发现）。
 *
 * 解析顺序（前者命中就不再看后者）：
 *  1. JSON 字段（imageUnderstanding / imageReadback / vision …）—— 模型偶发按 JSON 回；
 *  2. 文本块「【图片理解】」标题下的逐图行（本端提示词要求的格式）；
 *  3. 正文开头直接就是「图1：…」的逐图段（模型省了标题）。
 * 都取不到就返回 undefined —— **绝不**拿主题或图片说明拼一段像样的描述（那是造假）。
 * ========================================================================== */

/** 单张图片的理解条目：index 与界面上的「图N」一一对应 */
export interface MomentsImageReadItem {
  index: number
  detail: string
}

/** 一次生成里模型先说出来的「图片理解」 */
export interface MomentsImageReadback {
  /** 逐张条目（模型写成一段整体描述时为空数组，界面按 text 整段渲染） */
  items: MomentsImageReadItem[]
  /** 模型自述原文（已剥代码块与「图片理解：」前缀，逐图分张时按「图N：…」逐行） */
  text: string
}

/** 图片理解字段的候选键（归一化后比较：小写、去分隔符） */
const READBACK_JSON_KEYS = [
  'imageunderstanding',
  'imagereadback',
  'imageread',
  'imageanalysis',
  'imagedescription',
  'imagedesc',
  'vision',
  'understanding',
  'pictureunderstanding',
  'perception'
]

/** 「图片理解」小标题行（允许【】/[]/冒号的各种写法） */
const READBACK_HEADER_LINE = /^\s*(?:【|\[)?\s*图片理解\s*(?:】|\])?\s*[：:]?\s*(.*)$/

/** 逐图行首：`图1：` / `第2张：` / `3.` / `1、` */
const READBACK_ITEM_LINE = /^\s*(?:图\s*(\d+)|第\s*(\d+)\s*[张幅]|(\d+)\s*[.、．)）:：])\s*[：:、.．\-\s]*(.*)$/

/** 「这一段是逐图说明」的强标记（没有标题时用它判断开头是不是图片理解） */
const READBACK_ITEM_MARKER = /^\s*(?:图\s*\d+|第\s*\d+\s*[张幅])/

/** 图片理解段落的终止标记：文案分隔线 / 文案小标题 */
const READBACK_STOP_LINE = /^\s*(?:-{3,}|(?:【|\[)\s*(?:文案|正文|文案正文|生成结果|候选))/

/** 字符串类键（这些是整句描述，不加中文前缀） */
const READBACK_STRUCT_LABELS: Record<string, string> = {
  scene: '场景',
  scenes: '场景',
  place: '场景',
  people: '人物',
  person: '人物',
  subject: '人物',
  action: '动作',
  pose: '体态',
  motion: '动作',
  equipment: '器械',
  equipments: '器械',
  prop: '道具',
  props: '道具',
  tone: '色调',
  color: '色调',
  colors: '色调',
  lighting: '色调'
}

/** 结构化描述的锚点键：至少命中一个才给字段加中文前缀（否则会把整句读成某个维度） */
const READBACK_LABEL_ANCHORS = new Set(Object.keys(READBACK_STRUCT_LABELS))

/** 序号类键不参与描述拼接（避免「1；空中瑜伽教室」这种噪音） */
const READBACK_INDEX_KEY = /^(index|idx|i|n|no|order|image|img|photo|图|序号)$/i

/** 剥代码块围栏与「图片理解：」前缀 */
function cleanReadbackText(text: unknown): string {
  let s = String(text ?? '').trim()
  s = s
    .replace(/^```[a-zA-Z]*\s*/, '')
    .replace(/```$/, '')
    .trim()
  s = s.replace(/^(?:【|\[)?\s*图片理解\s*(?:】|\])?\s*[：:]\s*/, '').trim()
  return s
}

/** 任意值 → 一段描述文本（字符串原样；数组/对象按字段拼，结构化字段加中文前缀便于对读） */
function readbackTextOf(value: unknown, withLabels: boolean): string {
  if (typeof value === 'string') return cleanReadbackText(value)
  if (Array.isArray(value)) {
    return value
      .map((v) => readbackTextOf(v, withLabels))
      .filter(Boolean)
      .join('；')
  }
  if (value && typeof value === 'object') {
    const entries = Object.entries(value as Record<string, unknown>).filter(([k]) => !READBACK_INDEX_KEY.test(k.trim()))
    const structured = withLabels && entries.some(([k]) => READBACK_LABEL_ANCHORS.has(k.trim().toLowerCase()))
    const parts: string[] = []
    for (const [k, v] of entries) {
      const text = readbackTextOf(v, withLabels)
      if (!text) continue
      const label = structured ? READBACK_STRUCT_LABELS[k.trim().toLowerCase()] : ''
      parts.push(label ? `${label}：${text}` : text)
    }
    return parts.join('；')
  }
  return ''
}

/** 序号去重、缺失序号按出现顺序补正（保证与界面「图N」同序可分张） */
function normalizeReadItems(items: MomentsImageReadItem[]): MomentsImageReadItem[] {
  const used = new Set<number>()
  return items.map((it, i) => {
    const want = Number.isFinite(it.index) && it.index > 0 ? Math.trunc(it.index) : i + 1
    const index = used.has(want) ? i + 1 : want
    used.add(index)
    return { index, detail: it.detail.trim() }
  })
}

/** 把一段文本按「图N：」拆成逐张条目；没分张时返回空数组（调用方改走整段展示） */
function collectReadItems(block: string[], maxImages: number): MomentsImageReadItem[] {
  const items: MomentsImageReadItem[] = []
  let current: MomentsImageReadItem | null = null
  let auto = 1
  const flush = (): void => {
    if (current && current.detail.trim()) items.push({ index: current.index, detail: current.detail.trim() })
    current = null
  }
  for (const line of block) {
    const m = line.match(READBACK_ITEM_LINE)
    if (m && (m[1] || m[2] || m[3])) {
      flush()
      const picked = Number(m[1] || m[2] || m[3])
      const index = Number.isFinite(picked) && picked > 0 ? Math.trunc(picked) : auto
      auto = Math.max(auto, index) + 1
      current = { index, detail: (m[4] ?? '').trim() }
      continue
    }
    if (current) current.detail = `${current.detail} ${line.trim()}`.trim()
    else current = { index: auto, detail: line.trim() }
  }
  flush()
  return normalizeReadItems(items.slice(0, Math.max(1, maxImages)))
}

/** 任意形态的图片理解值 → { items, text }；拿不到任何可用文本时返回 undefined */
function readbackFromValue(value: unknown, maxImages: number): MomentsImageReadback | undefined {
  if (typeof value === 'string') {
    const text = cleanReadbackText(value)
    if (!text) return undefined
    // 没有逐图编号（模型写成一段整体描述）时不冒充分张：items 留空，界面按整段渲染
    const items = READBACK_ITEM_MARKER.test(text) ? collectReadItems(text.split(/\r?\n/), maxImages) : []
    return { items, text }
  }
  if (Array.isArray(value)) {
    const items = value
      .map((v, i) => {
        const detail = readbackTextOf(v, true)
        if (!detail) return null
        const rawIndex =
          v && typeof v === 'object'
            ? Number(
                (v as Record<string, unknown>).index ??
                  (v as Record<string, unknown>).idx ??
                  (v as Record<string, unknown>).image ??
                  (v as Record<string, unknown>).n
              )
            : NaN
        return { index: Number.isFinite(rawIndex) && rawIndex > 0 ? rawIndex : i + 1, detail }
      })
      .filter((it): it is MomentsImageReadItem => !!it)
    if (!items.length) return undefined
    const normalized = normalizeReadItems(items)
    return { items: normalized, text: normalized.map((it) => `图${it.index}：${it.detail}`).join('\n') }
  }
  if (value && typeof value === 'object') {
    const o = value as Record<string, unknown>
    // `{items:[…]}` / `{images:[…]}` 这类再包一层
    for (const key of ['items', 'list', 'images', 'perImage', 'per_image']) {
      if (o[key] !== undefined) {
        const inner = readbackFromValue(o[key], maxImages)
        if (inner) return inner
      }
    }
    // `{"1":"…","2":"…"}` 这种序号键写法
    const keys = Object.keys(o).filter((k) => k.trim())
    if (keys.length && keys.every((k) => /\d/.test(k))) {
      const items = keys
        .map((k) => ({ index: Number(k.replace(/\D/g, '')) || 0, detail: readbackTextOf(o[k], true) }))
        .filter((it) => !!it.detail)
        .sort((a, b) => a.index - b.index)
      if (items.length) {
        const normalized = normalizeReadItems(items)
        return { items: normalized, text: normalized.map((it) => `图${it.index}：${it.detail}`).join('\n') }
      }
    }
    // 单张的结构化对象：整段展示（不冒充多张）
    const detail = readbackTextOf(o, true)
    return detail ? { items: [{ index: 1, detail }], text: detail } : undefined
  }
  return undefined
}

/** 模型按 JSON 回时：捡 imageUnderstanding 等键，并尽量把成稿也一起取出来 */
function readbackFromJson(
  raw: string,
  maxImages: number
): { readback: MomentsImageReadback; cleaned: string } | undefined {
  const start = raw.indexOf('{')
  const end = raw.lastIndexOf('}')
  if (start < 0 || end <= start) return undefined
  let parsed: unknown
  try {
    parsed = JSON.parse(
      raw
        .slice(start, end + 1)
        .replace(/^```[a-zA-Z]*\s*/, '')
        .replace(/```$/, '')
    )
  } catch {
    return undefined
  }
  const o = obj(parsed)
  const hitKey = Object.keys(o).find((k) =>
    READBACK_JSON_KEYS.includes(
      k
        .trim()
        .toLowerCase()
        .replace(/[_\-\s]/g, '')
    )
  )
  if (!hitKey) return undefined
  const readback = readbackFromValue(o[hitKey], maxImages)
  if (!readback) return undefined
  const drafts = Array.isArray(o.drafts) ? o.drafts : Array.isArray(o.candidates) ? o.candidates : []
  const fromDrafts = drafts.map((d) => str(obj(d).content)).filter(Boolean)
  // 有 drafts 就用 drafts 当初稿；否则退回 JSON 之前那一段自然语言
  const cleaned = fromDrafts.length ? fromDrafts.join('\n\n---\n\n') : raw.slice(0, start).trim()
  return { readback, cleaned }
}

/** 从行数组里挖掉 [from, to)，并清掉遗留的「【文案】」小标题与空行 */
function stripReadbackLines(lines: string[], from: number, to: number): string {
  const kept = [...lines.slice(0, from), ...lines.slice(to)]
  const isBlank = (s: string): boolean => !s.trim()
  const isDocHeader = (s: string): boolean => READBACK_STOP_LINE.test(s) && !/-{3,}/.test(s)
  while (kept.length && isBlank(kept[0])) kept.shift()
  while (kept.length && (isDocHeader(kept[0]) || /^\s*-{3,}\s*$/.test(kept[0]))) kept.shift()
  while (kept.length && isBlank(kept[0])) kept.shift()
  return kept.join('\n').trim()
}

/**
 * 文本块路径：`【图片理解】` 标题块，或正文开头直接就是「图1：…」的逐图段。
 * 拿不到就当没有（cleaned 原样返回，文案生成不受影响）。
 */
function readbackBlockPath(raw: string, maxImages: number): { readback?: MomentsImageReadback; cleaned: string } {
  const lines = raw.replace(/\r\n/g, '\n').split('\n')
  let start = -1
  let seed: string[] = []

  for (let i = 0; i < lines.length; i += 1) {
    if (!/图片理解/.test(lines[i])) continue
    const m = lines[i].match(READBACK_HEADER_LINE)
    if (!m) continue
    start = i
    const rest = (m[1] ?? '').trim()
    if (rest) seed = [rest]
    break
  }

  let end = -1
  if (start >= 0) {
    end = start + 1
  } else {
    // 模型省了标题：只有正文第一行就是「图N：/第N张：」时才认这一段
    const first = lines.findIndex((l) => !!l.trim())
    if (first < 0 || !READBACK_ITEM_MARKER.test(lines[first])) return { cleaned: raw }
    start = first
    end = start
  }

  const block: string[] = [...seed]
  let seen = 0
  for (; end < lines.length; end += 1) {
    const line = lines[end]
    if (!line.trim()) break // 空行 = 图片理解段落结束（提示词要求这一段后空一行）
    if (READBACK_STOP_LINE.test(line)) break
    const m = line.match(READBACK_ITEM_LINE)
    if (m && (m[1] || m[2] || m[3])) {
      const picked = Number(m[1] || m[2] || m[3])
      // 图号越界（模型漏了空行，直接把文案写在后面）→ 不当图片理解，从这里截断
      if (Number.isFinite(picked) && picked > Math.max(maxImages, seen + 1)) break
      seen = Number.isFinite(picked) && picked > 0 ? picked : seen + 1
    }
    block.push(line)
  }

  const blockText = block.join('\n').trim()
  /**
   * 逐图条目只在**文本里真的有逐图编号**时才建。
   * 模型写成一段整体描述（「这一张是空中瑜伽教室…」）时，硬按行拆会造出一个假的「图1」，
   * 界面就会把一段通述冒充成逐张核对 —— 这种情况 items 留空，按 text 整段渲染。
   */
  const items = block.some((line) => READBACK_ITEM_MARKER.test(line)) ? collectReadItems(block, maxImages) : []
  if (!items.length && !blockText) return { cleaned: raw }
  const readback: MomentsImageReadback = items.length
    ? { items, text: items.map((it) => `图${it.index}：${it.detail}`).join('\n') }
    : { items: [], text: blockText }
  return { readback, cleaned: stripReadbackLines(lines, start, end) }
}

/**
 * 从模型原文里捡回「图片理解」，并返回**去掉这一段后的正文**（供 splitCandidates 切候选）。
 *
 * 只在本次真的带图时调用：无图生成的正文里出现「图1：」属于正常文案，不该被吞掉。
 */
export function extractMomentsImageReadback(
  content: string,
  maxImages = MOMENTS_MAX_IMAGES
): { readback?: MomentsImageReadback; cleaned: string } {
  const raw = String(content ?? '')
  if (!raw.trim()) return { cleaned: raw }
  const limit = Math.max(1, Math.trunc(maxImages) || MOMENTS_MAX_IMAGES)
  const fromJson = readbackFromJson(raw, limit)
  if (fromJson) return { readback: fromJson.readback, cleaned: fromJson.cleaned || raw }
  return readbackBlockPath(raw, limit)
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
