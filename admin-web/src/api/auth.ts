import { HttpError } from '@/utils/http/error'
import { useUserStore } from '@/store/modules/user'
import { USE_BACKEND, apiGet, apiPost, apiPatch, setBackendToken } from './backend'

/**
 * 认证：VITE_USE_BACKEND=true 走 Laravel 后端，否则本地模拟
 */
interface LocalAccount extends Api.Auth.UserInfo {
  key: string
  roleLabel: string
}

const DEMO_PASSWORD = import.meta.env.VITE_DEMO_PASSWORD ?? ''

export const LOCAL_ACCOUNTS: LocalAccount[] = [
  { key: 'owner', userId: 1001, userName: '演示超管', roles: ['R_SUPER'], buttons: [], email: 'owner@example.invalid', venue: null, venues: ['绿地店', '东部店'], roleLabel: '超管' },
  { key: 'manager-green', userId: 1002, userName: '绿地店长', roles: ['R_MANAGER'], buttons: [], email: 'manager-green@example.invalid', venue: '绿地店', venues: ['绿地店'], roleLabel: '店长' },
  { key: 'manager-east', userId: 1003, userName: '东部店长', roles: ['R_MANAGER'], buttons: [], email: 'manager-east@example.invalid', venue: '东部店', venues: ['东部店'], roleLabel: '店长' },
  { key: 'teacher', userId: 1004, userName: '演示老师', roles: ['R_TEACHER'], buttons: [], email: 'teacher@example.invalid', venue: '绿地店', venues: ['绿地店'], roleLabel: '老师' },
  { key: 'media', userId: 1005, userName: '演示新媒体', roles: ['R_MEDIA'], buttons: [], email: 'media@example.invalid', venue: null, venues: ['绿地店', '东部店'], roleLabel: '新媒体' }
]

function findAccount(predicate: (a: LocalAccount) => boolean): LocalAccount | null {
  return LOCAL_ACCOUNTS.find(predicate) ?? null
}

export async function fetchLogin(params: Api.Auth.LoginParams): Promise<Api.Auth.LoginResponse> {
  if (USE_BACKEND) {
    try {
      const data = await apiPost<{ token: string; userInfo: Api.Auth.UserInfo }>('/auth/login', {
        userName: params.userName,
        password: params.password
      })
      setBackendToken(data.token)
      useUserStore().setUserInfo(data.userInfo)
      return { token: data.token, refreshToken: data.token }
    } catch (e: unknown) {
      const msg =
        (e as { response?: { data?: { message?: string } } }).response?.data?.message ?? '账号或密码错误'
      throw new HttpError(msg, 400)
    }
  }

  const name = params.userName.trim()
  const account = findAccount((a) => a.userName === name || a.key === name.toLowerCase())
  if (!DEMO_PASSWORD || !account || DEMO_PASSWORD !== params.password) {
    throw new HttpError('账号或密码错误', 400)
  }
  return {
    token: `local.${account.key}`,
    refreshToken: `local.refresh.${account.key}`
  }
}

export function fetchGetUserInfo(): Promise<Api.Auth.UserInfo> {
  if (USE_BACKEND) {
    return apiGet<Api.Auth.UserInfo>('/me')
  }
  const userStore = useUserStore()
  const token = userStore.accessToken
  const key = token.startsWith('local.') ? token.slice(6) : ''
  const account = findAccount((a) => a.key === key)
  if (!account) {
    // 与 axios 拦截器的 401 处理保持一致：先登出清理旧状态，再拒绝
    userStore.logOut()
    return Promise.reject(new HttpError('登录状态无效，请重新登录', 401))
  }
  const { key: _key, roleLabel: _roleLabel, ...userInfo } = account
  return Promise.resolve(userInfo)
}

/** 人员管理：账号清单（脱敏，不含密码） */
export interface AccountRow {
  key: string
  userName: string
  roleLabel: string
  roleCode: string
  venues: string[]
  email: string
  status: '启用' | '停用'
  self?: boolean
}

export async function listAccounts(): Promise<AccountRow[]> {
  if (USE_BACKEND) {
    return apiGet<AccountRow[]>('/accounts')
  }
  return LOCAL_ACCOUNTS.map((a) => ({
    key: a.key,
    userName: a.userName,
    roleLabel: a.roleLabel,
    roleCode: a.roles[0] ?? '',
    venues: a.venues ?? [],
    email: a.email,
    status: '启用'
  }))
}

/** 新增账号（仅超管，凭据由服务端保管） */
export async function createAccount(data: {
  userName: string
  name?: string
  roleCode: string
  venues: string[]
  email?: string
  password: string
}): Promise<{ key: string }> {
  if (!USE_BACKEND) throw new Error('演示模式不支持新增账号')
  return apiPost<{ key: string }>('/accounts', { ...data } as Record<string, unknown>)
}

/** 停用/启用/改角色/重置密码/删除（仅超管） */
export async function updateAccount(
  key: string,
  action: 'update' | 'disable' | 'enable' | 'resetPassword' | 'delete',
  data?: { roleCode?: string; venues?: string[]; password?: string }
): Promise<{ ok: boolean }> {
  if (!USE_BACKEND) throw new Error('演示模式不支持账号变更')
  return apiPatch<{ ok: boolean }>(`/accounts/${encodeURIComponent(key)}`, {
    action,
    ...data
  } as Record<string, unknown>)
}
