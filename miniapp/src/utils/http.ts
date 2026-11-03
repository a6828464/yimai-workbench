/**
 * HTTP core（wx.request 封装）
 *
 * 复刻 admin-web/src/api/backend.ts 的契约（docs/miniapp-migration/04 的 R4 闸门）：
 * 1. statusCode 闸门：wx.request「只要收到返回，无论 statusCode 多少都进 success」，
 *    必须自己判 2xx，否则「密码错也显示登录成功但白屏」。
 * 2. 三形态 unwrap：
 *    - {code:0, data}  主契约（code!==0 → throw message）
 *    - {ok:false}      KeepYoga 风格失败
 *    - {errno,emsg}    KeepYoga 风格失败
 * 3. 401 会话过期：清 token → reLaunch 登录页（带防抖，与 web 端同策略）。
 */

import { storage } from './storage'

const TOKEN_KEY = 'backend-token'

export const API_BASE: string =
  (import.meta.env.VITE_API_BASE as string | undefined) || 'https://oa.nbyimai.com/api'

export class HttpError extends Error {
  code: number
  statusCode?: number
  constructor(message: string, code = -1, statusCode?: number) {
    super(message)
    this.name = 'HttpError'
    this.code = code
    this.statusCode = statusCode
  }
}

export function getToken(): string {
  return storage.getItem(TOKEN_KEY) ?? ''
}

export function setToken(token: string): void {
  storage.setItem(TOKEN_KEY, token)
}

export function clearToken(): void {
  storage.removeItem(TOKEN_KEY)
}

/** 401 防抖：3s 内只触发一次跳登录 */
let sessionExpiring = false
let sessionExpireTimer: ReturnType<typeof setTimeout> | null = null

function expireSession(): void {
  if (sessionExpiring) return
  sessionExpiring = true
  clearToken()
  uni.showToast({ title: '登录已过期，请重新登录', icon: 'none' })
  setTimeout(() => {
    uni.reLaunch({ url: '/pages/login/login' })
  }, 600)
  sessionExpireTimer = setTimeout(() => {
    sessionExpiring = false
    sessionExpireTimer = null
  }, 3000)
}

interface RawBody {
  code?: number
  data?: unknown
  message?: string
  msg?: string
  ok?: boolean
  errno?: number | string
  emsg?: string
}

/** 三形态解包（与 web 端 unwrap 逐行对齐） */
function unwrap<T>(body: RawBody | null | undefined): T {
  if (body === null || body === undefined) throw new HttpError('响应为空')
  if (body.code !== undefined && body.code !== 0) {
    throw new HttpError(body.message || body.msg || '请求失败', body.code)
  }
  if (body.ok === false) throw new HttpError(body.message || body.emsg || '请求失败')
  if (body.errno !== undefined && String(body.errno) !== '0') {
    throw new HttpError(body.emsg || body.message || '请求失败')
  }
  return body.data as T
}

interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  /** query（GET/DELETE）或 body（其余） */
  data?: Record<string, unknown>
  timeout?: number
  /** 静默模式：不弹错误 toast */
  silent?: boolean
  header?: Record<string, string>
}

function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const { method = 'GET', data, timeout = 20000, silent = false, header = {} } = options
  const token = getToken()

  return new Promise<T>((resolve, reject) => {
    uni.request({
      url: API_BASE + path,
      // uni.request 的 method 联合类型不含 PATCH，但 mp-weixin 运行时支持；按 any 断言
      method: method as 'GET',
      data,
      timeout,
      header: {
        'Content-Type': 'application/json',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
        ...header
      },
      success: (res) => {
        // statusCode 闸门（R4）：非 2xx 一律不进 unwrap
        const status = res.statusCode
        if (status === 401) {
          expireSession()
          reject(new HttpError('登录已过期', 401, 401))
          return
        }
        if (status === 403) {
          const msg = (res.data as RawBody)?.message || '无权限执行此操作'
          if (!silent) uni.showToast({ title: msg, icon: 'none' })
          reject(new HttpError(msg, 403, 403))
          return
        }
        if (status < 200 || status >= 300) {
          const msg = (res.data as RawBody)?.message || `服务异常（${status}）`
          if (!silent) uni.showToast({ title: msg, icon: 'none' })
          reject(new HttpError(msg, status, status))
          return
        }
        try {
          resolve(unwrap<T>(res.data as RawBody))
        } catch (e) {
          if (!silent && e instanceof Error) uni.showToast({ title: e.message, icon: 'none' })
          reject(e)
        }
      },
      fail: (err) => {
        const msg = err.errMsg?.includes('timeout') ? '请求超时，请重试' : '网络异常，请检查网络'
        if (!silent) uni.showToast({ title: msg, icon: 'none' })
        reject(new HttpError(msg, -1))
      }
    })
  })
}

export const http = {
  get<T>(path: string, data?: Record<string, unknown>, opts?: Partial<RequestOptions>) {
    return request<T>(path, { ...opts, method: 'GET', data })
  },
  post<T>(path: string, data?: Record<string, unknown>, opts?: Partial<RequestOptions>) {
    return request<T>(path, { ...opts, method: 'POST', data })
  },
  put<T>(path: string, data?: Record<string, unknown>, opts?: Partial<RequestOptions>) {
    return request<T>(path, { ...opts, method: 'PUT', data })
  },
  patch<T>(path: string, data?: Record<string, unknown>, opts?: Partial<RequestOptions>) {
    return request<T>(path, { ...opts, method: 'PATCH', data })
  },
  del<T>(path: string, data?: Record<string, unknown>, opts?: Partial<RequestOptions>) {
    return request<T>(path, { ...opts, method: 'DELETE', data })
  }
}
