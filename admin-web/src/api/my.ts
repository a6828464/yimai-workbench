/**
 * 个人中心 /「我的资料」统一数据层。
 * - 后端模式：资料（手机/头像/性别/年龄/年限/专业/发圈人设/小红书人设）按账号存服务端，多设备共用
 * - 演示模式：回退 localStorage
 */
import { ref } from 'vue'
import type { HttpError } from '@/utils/http/error'
import { USE_BACKEND, apiGet, apiPut, apiPost, apiDelete } from './backend'

export interface MomentsPersonaPrefs {
  role: string
  audiences: string[]
}

export interface XhsPersonaPrefs {
  ipType: '个人IP' | '门店IP'
  accountName: string
  role: string
  audiences: string[]
  /** 单选表达风格（历史字段，保留不动；新数据同时写 styles） */
  style: string
  /**
   * 多选表达风格（营销工具小红书侧用；上限见 api/ai.ts 的 XHS_STYLE_MAX）。
   * 可选字段：老账号数据没有该键，读取时回落为 [style]，因此不影响历史数据。
   */
  styles?: string[]
  conversion: string
  localFocus: boolean
}

/** 用户自助资料（个人中心维护，营销工具自动取用） */
export interface MyProfile {
  /** 全部角色（账号支持多角色叠加） */
  roles?: string[]
  name: string
  nickname: string
  phone: string
  avatar: string
  email: string
  role: string
  venues: string[]
  gender: string
  age: string
  years: string
  specialties: string[]
  persona: MomentsPersonaPrefs
  xhs: XhsPersonaPrefs
}

export interface MarketingHistoryItem {
  id: number
  platform: string
  title: string
  content: string
  reply: string
  source: string
  createdAt: string
}

export const MY_PROFILE_DEFAULTS = {
  phone: '',
  avatar: '',
  gender: '女',
  age: '',
  years: '',
  specialties: ['瑜伽', '普拉提'],
  persona: { role: '全职老师', audiences: ['上班族', '想改善体态的人'] },
  xhs: {
    ipType: '个人IP' as const,
    accountName: '',
    role: '全职老师',
    audiences: ['零基础女性', '久坐肩颈人群'],
    style: '真实接地气',
    styles: ['真实接地气'] as string[],
    conversion: '评论区留言',
    localFocus: true
  }
}

/** 完整默认资料（组件初始渲染兜底，fetchMyProfile 后覆盖） */
export function defaultMyProfile(): MyProfile {
  return {
    name: '',
    nickname: '',
    email: '',
    role: '',
    venues: [],
    ...clone(MY_PROFILE_DEFAULTS)
  }
}

function clone<T>(v: T): T {
  return JSON.parse(JSON.stringify(v)) as T
}

// ---------- 演示模式本地回退 ----------
const local = ref<MyProfile | null>(null)

function readLocal(): MyProfile {
  if (local.value) return local.value
  let stored: Partial<MyProfile> | null = null
  try {
    stored = JSON.parse(localStorage.getItem('yimai-my-profile') || 'null')
  } catch {
    stored = null
  }
  const defaults = defaultMyProfile()
  local.value = stored ? mergeProfile(defaults, stored) : defaults
  return local.value
}

function mergeProfile(cur: MyProfile, patch: Partial<MyProfile>): MyProfile {
  return {
    ...cur,
    ...patch,
    persona: { ...cur.persona, ...(patch.persona ?? {}) },
    xhs: normalizeXhsPrefs({ ...cur.xhs, ...(patch.xhs ?? {}) })
  }
}

function toPayload(p: MyProfile): Record<string, unknown> {
  return {
    nickname: p.nickname,
    email: p.email,
    phone: p.phone,
    avatar: p.avatar || null,
    profile: {
      gender: p.gender,
      age: p.age,
      years: p.years,
      specialties: p.specialties,
      persona: p.persona,
      xhs: p.xhs
    }
  }
}

interface MyProfileResponse {
  name: string
  nickname: string | null
  phone: string | null
  avatar: string | null
  email: string | null
  role: string
  venues: string[]
  profile: Partial<{
    gender: string
    age: string
    years: string
    specialties: string[]
    persona: Partial<MomentsPersonaPrefs>
    xhs: Partial<XhsPersonaPrefs>
  }> | null
}

function normalizeProfile(d: MyProfileResponse): MyProfile {
  return {
    name: d.name ?? '',
    nickname: d.nickname ?? '',
    phone: d.phone ?? '',
    avatar: d.avatar ?? '',
    email: d.email ?? '',
    role: d.role ?? '',
    venues: d.venues ?? [],
    gender: d.profile?.gender ?? MY_PROFILE_DEFAULTS.gender,
    age: d.profile?.age ?? '',
    years: d.profile?.years ?? '',
    specialties: d.profile?.specialties?.length
      ? d.profile.specialties
      : clone(MY_PROFILE_DEFAULTS.specialties),
    persona: { ...clone(MY_PROFILE_DEFAULTS.persona), ...(d.profile?.persona ?? {}) },
    xhs: normalizeXhsPrefs({ ...clone(MY_PROFILE_DEFAULTS.xhs), ...(d.profile?.xhs ?? {}) })
  }
}

/**
 * 小红书人设兜底：老账号数据只有单选 `style`、没有 `styles`，
 * 读取时回落为 `[style]`（不丢用户已选的风格）；两者都没有时用默认值。
 */
function normalizeXhsPrefs(xhs: XhsPersonaPrefs): XhsPersonaPrefs {
  const styles = Array.isArray(xhs.styles) ? xhs.styles.filter(Boolean) : []
  if (styles.length) return { ...xhs, styles, style: xhs.style || styles[0] }
  const fallback = xhs.style || MY_PROFILE_DEFAULTS.xhs.style
  return { ...xhs, style: fallback, styles: [fallback] }
}

// ---------- 我的资料 ----------

let cached: MyProfile | null = null

/** 拉取我的资料（模块级缓存；force 重拉） */
export async function fetchMyProfile(force = false): Promise<MyProfile> {
  if (cached && !force) return cached
  if (!USE_BACKEND) {
    cached = readLocal()
    return cached
  }
  const d = await apiGet<MyProfileResponse>('/my/profile')
  cached = normalizeProfile(d)
  return cached
}

/** 保存我的资料（局部字段合并后整体提交） */
export async function saveMyProfile(patch: Partial<MyProfile>): Promise<MyProfile> {
  if (!USE_BACKEND) {
    const next = mergeProfile(cached ?? readLocal(), patch)
    localStorage.setItem('yimai-my-profile', JSON.stringify(next))
    cached = next
    return next
  }
  const current = cached ?? (await fetchMyProfile())
  const next = mergeProfile(current, patch)
  const saved = await apiPut<MyProfileResponse>('/my/profile', toPayload(next))
  cached = normalizeProfile(saved)
  return cached
}

export async function changeMyPassword(oldPassword: string, newPassword: string): Promise<void> {
  if (!USE_BACKEND) {
    await new Promise((r) => setTimeout(r, 300))
    return
  }
  await apiPut('/my/password', { oldPassword, newPassword })
}

// ---------- 生成历史 ----------

const localHistory = ref<MarketingHistoryItem[] | null>(null)
let localHistoryId = 1

function readLocalHistory(): MarketingHistoryItem[] {
  if (localHistory.value) return localHistory.value
  try {
    localHistory.value = JSON.parse(localStorage.getItem('yimai-marketing-history') || '[]')
  } catch {
    localHistory.value = []
  }
  return localHistory.value!
}

export async function fetchMarketingHistory(platform: string): Promise<MarketingHistoryItem[]> {
  if (USE_BACKEND) {
    try {
      return await apiGet<MarketingHistoryItem[]>('/marketing/history', { platform })
    } catch {
      /* 退回本地 */
    }
  }
  return readLocalHistory().filter((h) => h.platform === platform)
}

/** 生成成功后落历史（静默失败，不打断主流程） */
export async function saveMarketingHistory(item: {
  platform: string
  title: string
  content: string
  reply?: string
  source: string
}): Promise<void> {
  if (USE_BACKEND) {
    try {
      await apiPost('/marketing/history', item)
      return
    } catch {
      /* 落到本地 */
    }
  }
  const d = new Date()
  const p = (n: number) => String(n).padStart(2, '0')
  const list = readLocalHistory()
  list.unshift({
    ...item,
    id: localHistoryId++,
    reply: item.reply ?? '',
    createdAt: `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
  })
  if (list.length > 300) list.length = 300
  localStorage.setItem('yimai-marketing-history', JSON.stringify(list))
}

export async function removeMarketingHistory(id: number): Promise<void> {
  if (USE_BACKEND) {
    try {
      await apiDelete(`/marketing/history/${id}`)
      return
    } catch {
      /* 本地也删一次 */
    }
  }
  const list = readLocalHistory().filter((h) => h.id !== id)
  localStorage.setItem('yimai-marketing-history', JSON.stringify(list))
}

// ---------- 收藏（服务端持久化；本地仅缓存） ----------
//
// 规格：docs/营销工具/06-收藏服务端持久化-设计规格.md §4.1-§4.4
// 与「生成历史」的关键差别：**收藏是劳动成果、不可再生**，所以这里不做任何静默兜底 ——
// 读不到返回 null（区别于「云端 0 条」），写失败返回 null 且**不落本地**
// （落本地会让「本机有、云端无」，正是这次要修的跨设备不一致 bug 的同型复现）。

/** 收藏项（与服务端 GET /marketing/favorites 的 data 元素同构） */
export interface MarketingFavoriteItem {
  id: number
  platform: string
  title: string
  content: string
  /** 首评/置顶评论（朋友圈收藏用）。服务端缺失时返回 null，老数据（改造前）可能连键都没有 */
  reply?: string | null
  /** 封面文案（小红书收藏用）。同上：服务端缺失为 null，老数据可能无键 */
  coverText?: string | null
  tags: string[]
  createdAt: string
}

/** 新增收藏入参（无 id / createdAt / user_id —— 三者都由服务端决定） */
export interface MarketingFavoriteInput {
  platform: string
  title?: string
  content: string
  reply?: string
  coverText?: string
  tags?: string[]
}

/**
 * 读：失败/离线/未登录返回 `null`（让调用方区分「云端确实 0 条」与「读不到」）。
 * 返回 `[]` 会把两者混为一谈 —— 那正是「收藏看起来没了」的体感 bug 的来源。
 */
export async function fetchMarketingFavorites(
  platform?: string
): Promise<MarketingFavoriteItem[] | null> {
  if (!USE_BACKEND) {
    // 演示模式：没有云端可读。收藏真源是 ai-config store 的本地持久化缓存
    // （规格 §4.2「演示模式必须完整保留既有行为」），故这里返回 null 表示「无服务端」，
    // 由 store 在演示模式下直接以本地缓存为准，不调用本函数。
    return null
  }
  try {
    return await apiGet<MarketingFavoriteItem[]>('/marketing/favorites', { platform })
  } catch {
    return null
  }
}

/**
 * 写失败的原因（供上层决定提示文案：422 上限类要原样透出后端中文提示）。
 *
 * 为什么不用 `null` 表示失败：`null` 会丢家庭「是网络失败还是超上限」，
 * 而 §4.4 要求这两者文案不同；同时**这一层不能弹提示** ——
 * api 层一旦 import element-plus，SSR 冒烟门禁（`pnpm check:ai-smoke`）会在
 * `document is not defined` 处直接红掉（element-plus 在导入期就摸 document）。
 * 所以失败原因作为数据上抛，提示由视图层单点给出（`useMarketingFavorites().saveFavorite`），
 * 天然满足 §4.7「同一错误不弹两次」。
 */
export interface MarketingFavoriteWriteError {
  status?: number
  message: string
}

export type MarketingFavoriteSaveResult =
  | { ok: true; id: number }
  | { ok: false; error: MarketingFavoriteWriteError }

/**
 * 把写失败的原始异常归一成可展示原因。**两套 HTTP 栈的真实形状都要认**：
 *
 * - 项目自研栈 `utils/http` 的 `HttpError`：`{ code, data: { message }, message }`
 *   （`code` 就是 HTTP 状态码，见 `error.ts:75`）—— 仅用 `import type` 引入类型，
 *   不做运行期导入：`error.ts` 引了 element-plus，运行期导入会打挂 SSR 冒烟门禁；
 * - `api/backend.ts` 的 axios 直连：`AxiosError { response: { status, data: { message } } }`。
 *
 * 两者都不匹配时回落到 `error.message`，保证「失败原因」永不静默丢失。
 */
export function toFavoriteWriteError(e: unknown): MarketingFavoriteWriteError {
  const err = e as Partial<HttpError> & {
    message?: string
    response?: { status?: number; data?: { message?: string } }
  }
  const status = typeof err?.code === 'number' ? err.code : err?.response?.status
  const dataMessage =
    (err?.data as { message?: string } | undefined)?.message ?? err?.response?.data?.message
  return { status, message: dataMessage ?? err?.message ?? '' }
}

/**
 * 写：成功返回服务端 id；失败返回 `{ ok: false, error }` 且**不落本地**（见上方说明）。
 *
 * 与规格 §4.1 的差异（有意的）：规格写的是 `{id} | null`，但 `null` 无法承载
 * 「422 上限 → 透出后端原文 / 其余 → 通用文案」所需的区分度（§4.4 明列），
 * 故改为可判别联合；调用点用 `res.ok` 分支即可，语义与 `null` 判定等价。
 */
export async function saveMarketingFavorite(
  item: MarketingFavoriteInput
): Promise<MarketingFavoriteSaveResult> {
  if (!USE_BACKEND) {
    return { ok: false, error: { message: '演示模式无服务端收藏' } }
  }
  try {
    const data = await apiPost<{ id: number }>('/marketing/favorites', {
      platform: item.platform,
      title: item.title ?? '',
      content: item.content,
      reply: item.reply ?? '',
      coverText: item.coverText ?? '',
      tags: item.tags ?? []
    })
    return { ok: true, id: data.id }
  } catch (e) {
    return { ok: false, error: toFavoriteWriteError(e) }
  }
}

/**
 * 删：幂等；失败时**向调用方抛错但不弹提示**（§4.4「删失败静默」＝不打扰用户）。
 * 由 store 捕获后 `console.warn` 并保持列表不动 —— 服务端幂等，下次拉取会自动对齐。
 */
export async function removeMarketingFavorite(id: number): Promise<void> {
  if (!USE_BACKEND) return
  await apiDelete(`/marketing/favorites/${id}`)
}
