import axios from 'axios'

export const USE_BACKEND = import.meta.env.VITE_USE_BACKEND === 'true'

const TOKEN_KEY = 'backend-token'

export function setBackendToken(token: string) {
  localStorage.setItem(TOKEN_KEY, token)
}

export function getBackendToken(): string {
  return localStorage.getItem(TOKEN_KEY) ?? ''
}

export function clearBackendToken() {
  localStorage.removeItem(TOKEN_KEY)
}

const http = axios.create({
  baseURL: import.meta.env.VITE_API_BASE ?? '/api',
  timeout: 20000
})

http.interceptors.request.use((cfg) => {
  const token = getBackendToken()
  if (token) cfg.headers.Authorization = `Bearer ${token}`
  return cfg
})

http.interceptors.response.use(
  (resp) => resp,
  (error) => {
    const status = error.response?.status
    if (status === 401) {
      clearBackendToken()
      window.location.hash = '#/login'
    }
    return Promise.reject(error)
  }
)

export async function apiGet<T = unknown>(path: string, params?: Record<string, unknown>): Promise<T> {
  const r = await http.get(path, { params })
  return r.data?.data as T
}

export async function apiPost<T = unknown>(path: string, body?: Record<string, unknown>): Promise<T> {
  const r = await http.post(path, body)
  return r.data?.data as T
}

export async function apiPatch<T = unknown>(path: string, body?: Record<string, unknown>): Promise<T> {
  const r = await http.patch(path, body)
  return r.data?.data as T
}

export async function apiPut<T = unknown>(path: string, body?: Record<string, unknown>): Promise<T> {
  const r = await http.put(path, body)
  return r.data?.data as T
}
