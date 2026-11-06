import axios from 'axios'

/**
 * 统一 API 错误对象（带 HTTP 状态）
 *
 * ## 为什么需要它
 *
 * 改造前 `unwrap()` 抛的是**裸 `new Error(message)`**：HTTP 状态码（401/403/422）
 * 在抛出瞬间就丢了，视图层拿到的是一个只有字符串的 Error，无法区分「会话过期」
 * 「无权限」「参数不合法」「服务挂了」，于是全仓 22 处 catch 只能写死一句
 * 「XX 加载失败，请稍后重试」——会话过期被踢下线时照样弹「加载失败」，
 * 用户看到的是假原因，还会反复重试。
 *
 * 错误分类（status）与提示文案放在同一处维护，避免两边口径漂移。
 */
export class HttpError extends Error {
  /** HTTP 状态码；0 表示请求没走到服务端（网络错误）或纯业务错误 */
  public readonly status: number
  /** 业务码（后端 `{code}` 信封里的值）；未知时为 0 */
  public readonly code: number
  /** 后端原样返回的响应体，便于排查 */
  public readonly data?: unknown

  constructor(message: string, status = 0, options?: { code?: number; data?: unknown }) {
    super(message)
    this.name = 'HttpError'
    this.status = status
    this.code = options?.code ?? status
    this.data = options?.data
  }
}

/** axios 风格的错误形状（只读这几个字段，不额外引依赖） */
interface AxiosLikeError {
  message?: string
  response?: { status?: number; data?: { message?: string; errors?: Record<string, unknown> } }
}

/** 把任意 catch 到的错误归一成 HttpError（不改变 message） */
export function toHttpError(e: unknown): HttpError {
  if (e instanceof HttpError) return e

  if (e && typeof e === 'object') {
    const err = e as AxiosLikeError
    const status = Number(err.response?.status ?? 0)
    const bodyMessage = err.response?.data?.message
    const message = bodyMessage || err.message || '请求失败'
    if (status || bodyMessage) {
      return new HttpError(message, status, { data: err.response?.data })
    }
    return new HttpError(message, 0)
  }

  return new HttpError(typeof e === 'string' ? e : '请求失败', 0)
}

/** 会话过期（后端 401）：由下面的拦截器接管登出，视图不该再弹提示 */
export function isUnauthorizedError(e: unknown): boolean {
  return toHttpError(e).status === 401
}

/** 无权限（后端 403） */
export function isForbiddenError(e: unknown): boolean {
  return toHttpError(e).status === 403
}

/**
 * 统一的 API 失败提示。
 *
 * - **401**：不提示。401 拦截器已经会清 token + 跳登录，此时再弹一条
 *   「XX 加载失败」只会误导用户。
 * - **403**：明确说无权限，而不是「稍后重试」——后者会让用户一直重试。
 * - **422**：后端校验失败，把后端文案透出来（如「当前状态不允许该操作」）。
 * - **其他**：`${context}失败，请稍后重试`。
 *
 * @param e catch 到的任意错误（axios 错误 / HttpError / 其他）
 * @param context 上下文短语，如「会员列表加载」「删除留资」
 */
export function showApiError(e: unknown, context: string): void {
  const err = toHttpError(e)

  if (err.status === 401) {
    // 会话过期由路由守卫 / 拦截器接管，不打扰用户
    console.warn(`[api] 401 on ${context}:`, err.message)
    return
  }

  if (err.status === 403) {
    ElMessage.error(err.message && err.message !== '请求失败' ? err.message : '无权限执行此操作')
    return
  }

  if (err.status === 422) {
    ElMessage.error(
      err.message && err.message !== '请求失败' ? err.message : `${context}失败：参数不合法`
    )
    return
  }

  ElMessage.error(`${context}失败，请稍后重试`)
  console.error(`[api] ${context} failed:`, err)
}

export const USE_BACKEND = import.meta.env.VITE_USE_BACKEND === 'true'

const TOKEN_KEY = 'backend-token'

let sessionExpiredHandler: (() => void | Promise<void>) | undefined
let sessionExpiryInProgress: Promise<void> | null = null
let sessionExpired = false

export function registerSessionExpiredHandler(handler: () => void | Promise<void>) {
  sessionExpiredHandler = handler
}

function expireSession(): Promise<void> {
  if (sessionExpiryInProgress) return sessionExpiryInProgress
  if (sessionExpired) return Promise.resolve()

  sessionExpired = true
  clearBackendToken()
  sessionExpiryInProgress = Promise.resolve()
    .then(() => sessionExpiredHandler?.())
    .catch((error) => console.error('[Session] Failed to clear expired session:', error))
    .finally(() => {
      sessionExpiryInProgress = null
    })
  return sessionExpiryInProgress
}

export function setBackendToken(token: string) {
  sessionExpired = false
  localStorage.setItem(TOKEN_KEY, token)
}

export function getBackendToken(): string {
  return localStorage.getItem(TOKEN_KEY) ?? ''
}

export function clearBackendToken() {
  localStorage.removeItem(TOKEN_KEY)
}

declare global {
  interface Window {
    __YIMAI_API_BASE__?: string
  }
}

const http = axios.create({
  // 运行时配置（dist/config.js）> 构建时环境变量 > 默认 /api
  baseURL: window.__YIMAI_API_BASE__ || import.meta.env.VITE_API_BASE || '/api',
  timeout: 20000
})

/** API 基址（供 fetch 流式请求复用，含后端鉴权头） */
export const API_BASE = window.__YIMAI_API_BASE__ || import.meta.env.VITE_API_BASE || '/api'

/**
 * 拆 `{code, data, message}` 信封。
 *
 * 失败时抛 **带 status 的 HttpError**（不是裸 Error）：视图层要能区分
 * 401（会话过期，静默）/403（无权限）/422（参数不合法），见本文件 `showApiError()`。
 * 业务码本身不是 HTTP 状态时 status 记 0，message 原样保留。
 */
function unwrap<T>(body: {
  code?: number
  data?: T
  message?: string
  ok?: boolean
  errno?: number | string
  emsg?: string
}): T {
  if (body?.code !== undefined && body.code !== 0) {
    const code = Number(body.code)
    const status = code >= 400 && code <= 599 ? code : 0
    throw new HttpError(body.message || '请求失败', status, { code, data: body })
  }
  // 兼容契约外的失败形态：{ok:false} / {errno}（KeepYoga 风格），避免被静默判成功
  if (body?.ok === false) {
    throw new HttpError(body.message || body.emsg || '请求失败', 0, { data: body })
  }
  if (body?.errno !== undefined && String(body.errno) !== '0')
    throw new HttpError(body.emsg || body.message || '请求失败', 0, { data: body })
  return body?.data as T
}

http.interceptors.request.use((cfg) => {
  const token = getBackendToken()
  if (token) cfg.headers.Authorization = `Bearer ${token}`
  return cfg
})

http.interceptors.response.use(
  (resp) => resp,
  async (error) => {
    const status = error.response?.status
    if (status === 401) {
      const requestToken = String(error.config?.headers?.Authorization ?? '').replace(
        /^Bearer\s+/i,
        ''
      )
      const currentToken = getBackendToken()
      if (!requestToken || !currentToken || requestToken === currentToken) {
        await expireSession()
      }
    }
    if (status === 403) {
      // 归一化错误信息，供视图层展示明确的「无权限」提示，而非笼统的加载失败
      error.message = error.response?.data?.message || '无权限执行此操作'
    }
    return Promise.reject(error)
  }
)

export async function apiGet<T = unknown>(
  path: string,
  params?: Record<string, unknown>
): Promise<T> {
  const r = await http.get(path, { params })
  return unwrap<T>(r.data)
}

export async function apiPost<T = unknown>(
  path: string,
  body?: Record<string, unknown>,
  timeout?: number
): Promise<T> {
  const r = await http.post(path, body, { timeout })
  return unwrap<T>(r.data)
}

export async function apiPatch<T = unknown>(
  path: string,
  body?: Record<string, unknown>
): Promise<T> {
  const r = await http.patch(path, body)
  return unwrap<T>(r.data)
}

export async function apiPut<T = unknown>(
  path: string,
  body?: Record<string, unknown>
): Promise<T> {
  const r = await http.put(path, body)
  return unwrap<T>(r.data)
}

export async function apiDelete<T = unknown>(path: string): Promise<T> {
  const r = await http.delete(path)
  return unwrap<T>(r.data)
}

/**
 * 下载文件（同步产物 / 备份包等）。
 *
 * 三个必须踩准的点（t3 W-04 实测踩过）：
 * 1. **anchor 必须挂进 document**：Firefox 对游离 `<a>` 的 `click()` 不触发下载；
 * 2. **revoke 必须延后**：`URL.revokeObjectURL()` 同步跟 `click()` 会让 Safari/WebKit
 *    在下载真正开始前就把 blob URL 掐断，表现为「点了没反应 / 下载 0 字节」；
 * 3. **content-type 校验**：后端出错时常常仍返回 HTTP 200，但 body 是
 *    `{"message":"..."}` 的 JSON；不拦住就会把一段错误 JSON 存成 `.zip`。
 */
export async function apiDownload(path: string, filename: string): Promise<void> {
  const response = await http.get(path, { responseType: 'blob', timeout: 300000 })
  const blob = response.data as Blob
  const contentType = String(response.headers?.['content-type'] ?? '')

  if (contentType.includes('application/json') && blob instanceof Blob) {
    const text = await blob.text()
    let message = '下载失败'
    try {
      const body = JSON.parse(text) as { message?: string; emsg?: string }
      message = body?.message || body?.emsg || message
    } catch {
      /* 不是合法 JSON 就保留默认文案 */
    }
    throw new HttpError(message, 0, { data: text.slice(0, 200) })
  }

  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  link.style.display = 'none'
  document.body.appendChild(link)
  link.click()

  // 1s 后再回收：等浏览器真正开始读 blob（Safari 尤其需要），并清掉临时节点
  window.setTimeout(() => {
    link.parentNode?.removeChild(link)
    URL.revokeObjectURL(url)
  }, 1000)
}
