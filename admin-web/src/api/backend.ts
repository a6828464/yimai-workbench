import axios from 'axios'

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

function unwrap<T>(body: { code?: number; data?: T; message?: string }): T {
  if (body?.code !== undefined && body.code !== 0) throw new Error(body.message || '请求失败')
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
      const requestToken = String(error.config?.headers?.Authorization ?? '').replace(/^Bearer\s+/i, '')
      const currentToken = getBackendToken()
      if (!requestToken || !currentToken || requestToken === currentToken) {
        await expireSession()
      }
    }
    return Promise.reject(error)
  }
)

export async function apiGet<T = unknown>(path: string, params?: Record<string, unknown>): Promise<T> {
  const r = await http.get(path, { params })
  return unwrap<T>(r.data)
}

export async function apiPost<T = unknown>(path: string, body?: Record<string, unknown>, timeout?: number): Promise<T> {
  const r = await http.post(path, body, { timeout })
  return unwrap<T>(r.data)
}

export async function apiPatch<T = unknown>(path: string, body?: Record<string, unknown>): Promise<T> {
  const r = await http.patch(path, body)
  return unwrap<T>(r.data)
}

export async function apiPut<T = unknown>(path: string, body?: Record<string, unknown>): Promise<T> {
  const r = await http.put(path, body)
  return unwrap<T>(r.data)
}
