/**
 * 单份内容的对外分享（客户分析 / 训练计划）—— 前端唯一出口
 *
 * 后端（ShareTokenController，v3.20.0）两个接口：
 *  1. `POST /share/{kind}/{id}/token`（**需登录**，员工在详情页点「分享给客户」时调）
 *     → `{ok({kind, id, token, needCode})}`
 *       失败口径：403 无权分享 / 422 命中红线或未确认或校验码格式不对。
 *  2. `GET /public/share/{token}?code=后四位`（**免登录**，客户点开链接时调）
 *     → `ok(白名单投影)`；失败：410 失效 / 401 需要校验码 / 403 校验码不对 / 429 限流。
 *
 * ## 为什么第 2 个接口不走 `utils/http`
 *
 * `utils/http` 把 **401 一律当「登录过期」**：它会 `expireSession()` → 清 token →
 * `reLaunch` 登录页。而公开分享的 401 含义是「请输手机号后四位」，两者状态码相同、
 * 语义完全不同。若复用 http，客户一进分享页就被弹去登录页 —— 正是馆主最不想要的那种体验。
 * 所以这里用裸 `uni.request` 自己判状态码，且**不带 Authorization**：
 * 公开接口不需要登录态，也没必要把员工 token 带出后台边界。
 *
 * ## 缓存
 *
 * 后四位校验通过后，同一进程内（= 不杀小程序）再进这个链接不必重输 —— 缓存放在模块作用域，
 * **刻意不写 storage**：一旦落盘，等于把「客户看过什么」和校验凭据留在设备上，
 * 而分享页恰恰是给非员工看的。App 重启 / 小程序被回收后缓存自然消失。
 */

import { API_BASE, http } from '@/utils/http'

export type ShareKind = 'review' | 'plan'

/** POST /share/{kind}/{id}/token 的返回 */
export interface ShareTokenResult {
  kind: ShareKind
  id: number
  token: string
  /**
   * 这份内容是否需要客户输入手机号后四位。
   * `false` 意味着内容里没有可用手机号（训练计划常见）→ 只靠 256 bit token 保护。
   * 前端**必须**把这件事告诉人（生成入口提示 + 公开页常驻小字），
   * 不能把无码链接当普通链接发出去。
   */
  needCode: boolean
}

/* ==========================================================================
 * 公开投影的形状（后端 reviewPublicView / planPublicView 逐字段白名单）
 *
 * 这里**只声明后端真的会给的字段**，页面也只渲染这些 —— 多一个字段就可能把
 * 手机号/归属账号/内部备注/金额带到对客页上。类型写在本模块内（不动 types/index.ts）。
 * ========================================================================== */

export interface PublicReviewDimension {
  key: string
  label: string
  /** 老师的观察档位（轻 / 中 / 重），不是数值分 */
  level: string
}

export interface PublicReviewSuggestion {
  name: string
  duration: string
  goal: string
  focus: string
}

/** 客户分析（课后分析）对客视图 */
export interface PublicReviewShare {
  kind: 'review'
  teacherName: string
  /** 结论：老师看到的主要问题（objective.observed） */
  conclusion: string
  dimensions: PublicReviewDimension[]
  /** 建议：分阶段方案 */
  suggestions: PublicReviewSuggestion[]
  /** 下一步建议 */
  nextStep: string
  confirmedAt: string
}

export interface PublicPlanAction {
  name: string
  sets: string
  reps: string
  rest: string
  note: string
}

export interface PublicPlanPhase {
  name: string
  goal: string
  weeks: string
  actions: PublicPlanAction[]
}

/** 训练计划对客视图 */
export interface PublicPlanShare {
  kind: 'plan'
  teacherName: string
  coreGoal: string
  summary: string
  phases: PublicPlanPhase[]
  cautions: string[]
  confirmedAt: string
}

export type PublicShareContent = PublicReviewShare | PublicPlanShare

/** 公开页的失败类型（页面据此给不同人话文案，不把后端中文原文怼给客户） */
export type ShareFailKind =
  /** 410：token 不存在 / 已停用 / 被篡改 */
  | 'gone'
  /** 401：需要输入手机号后四位 */
  | 'need-code'
  /** 403：后四位不对 */
  | 'bad-code'
  /** 429：公开接口限流（60/min） */
  | 'busy'
  /** 网络 / 其它 */
  | 'network'

export class ShareError extends Error {
  readonly kind: ShareFailKind
  readonly status: number

  constructor(kind: ShareFailKind, status = 0, message = '') {
    super(message || kind)
    this.name = 'ShareError'
    this.kind = kind
    this.status = status
  }
}

/**
 * 生成（或换发）某份内容的分享 token。
 *
 * `silent: true`：422/403 的中文提示要由页面按场景决定怎么展示（例如红线/未确认要
 * 说「先确认再分享」），交给 http 层直接 toast 会丢掉这层上下文。
 * 换发是幂等的：同一个 id 再点一次会得到一个**新** token，旧链接随即失效 ——
 * 这正是「链接被转错群了」时的撤回手段，页面文案要说明。
 */
export async function issueShareToken(kind: ShareKind, id: number): Promise<ShareTokenResult> {
  return http.post<ShareTokenResult>(`/share/${kind}/${id}/token`, {}, { silent: true })
}

/** 状态码 → 失败类型（后端只用 410/401/403/429 这四种） */
function failureOf(status: number): ShareError {
  if (status === 410) return new ShareError('gone', status)
  if (status === 401) return new ShareError('need-code', status)
  if (status === 403) return new ShareError('bad-code', status)
  if (status === 429) return new ShareError('busy', status)
  return new ShareError('network', status)
}

/**
 * 读取公开分享内容（免登录）。
 *
 * @param token 分享 token（64 位 hex；形态不对后端会直接 410，不用前端预判）
 * @param code  手机号后四位；第一次不带，401 后再带上来
 */
export function fetchPublicShare(token: string, code = ''): Promise<PublicShareContent> {
  const query = code ? `?code=${encodeURIComponent(code)}` : ''
  return new Promise((resolve, reject) => {
    uni.request({
      url: `${API_BASE}/public/share/${encodeURIComponent(token)}${query}`,
      method: 'GET',
      timeout: 15000,
      // 不带 Authorization：见文件头「为什么不走 utils/http」
      success: (res) => {
        const status = Number(res.statusCode ?? 0)
        const body = (res.data ?? {}) as { code?: number; data?: unknown }
        if (status >= 200 && status < 300 && Number(body.code) === 0 && body.data) {
          resolve(body.data as PublicShareContent)
          return
        }
        reject(failureOf(status))
      },
      fail: () => reject(new ShareError('network', 0))
    })
  })
}

/* ---------- 进程内缓存（不落盘） ---------- */

interface CachedShare {
  content: PublicShareContent
  /** 这份内容是否「无需校验码」（决定了页面顶部要不要常驻风险提示） */
  noCode: boolean
}

/** 最多留 3 份，够「看过 A 再看 B 再回来」用，不至于把内存当存储用 */
const MAX_CACHE = 3
const cache = new Map<string, CachedShare>()

export function cachedPublicShare(token: string): CachedShare | null {
  return cache.get(token) ?? null
}

export function rememberPublicShare(token: string, entry: CachedShare): void {
  if (cache.size >= MAX_CACHE) {
    const oldest = cache.keys().next().value
    if (oldest !== undefined) cache.delete(oldest)
  }
  cache.set(token, entry)
}

/** 分享落地页路径（固定到这一份内容，不可能是别人的） */
export function shareViewPath(token: string): string {
  return `/pkg-public/share/view?t=${encodeURIComponent(token)}`
}
