import { HttpError } from '@/utils/http/error'
import { useUserStore } from '@/store/modules/user'

/**
 * 本地模拟认证（阶段1接入 Laravel 后端后替换）
 * 角色：超管(R_SUPER) / 店长(R_MANAGER) / 老师(R_TEACHER) / 新媒体(R_MEDIA)
 */
interface LocalAccount extends Api.Auth.UserInfo {
  password: string
  key: string
  roleLabel: string
}

export const LOCAL_ACCOUNTS: LocalAccount[] = [
  { key: 'nange', password: 'yimai123', userId: 1001, userName: '南哥', roles: ['R_SUPER'], buttons: [], email: 'nange@yimai.local', venue: null, venues: ['绿地店', '东部店'], roleLabel: '超管' },
  { key: 'wangdz', password: 'yimai123', userId: 1002, userName: '王店长', roles: ['R_MANAGER'], buttons: [], email: 'wangdz@yimai.local', venue: '绿地店', venues: ['绿地店'], roleLabel: '店长' },
  { key: 'lidz', password: 'yimai123', userId: 1003, userName: '李店长', roles: ['R_MANAGER'], buttons: [], email: 'lidz@yimai.local', venue: '东部店', venues: ['东部店'], roleLabel: '店长' },
  { key: 'huangmin', password: 'yimai123', userId: 1004, userName: '黄敏', roles: ['R_TEACHER'], buttons: [], email: 'huangmin@yimai.local', venue: null, venues: ['绿地店', '东部店'], roleLabel: '老师' },
  { key: 'tingting', password: 'yimai123', userId: 1005, userName: '婷婷', roles: ['R_TEACHER'], buttons: [], email: 'tingting@yimai.local', venue: '绿地店', venues: ['绿地店'], roleLabel: '老师' },
  { key: 'ayu', password: 'yimai123', userId: 1006, userName: '阿玉', roles: ['R_MEDIA'], buttons: [], email: 'ayu@yimai.local', venue: null, venues: ['绿地店', '东部店'], roleLabel: '新媒体' }
]

function findAccount(predicate: (a: LocalAccount) => boolean): LocalAccount | null {
  return LOCAL_ACCOUNTS.find(predicate) ?? null
}

export async function fetchLogin(params: Api.Auth.LoginParams): Promise<Api.Auth.LoginResponse> {
  const name = params.userName.trim()
  const account = findAccount((a) => a.userName === name || a.key === name.toLowerCase())
  if (!account || account.password !== params.password) {
    throw new HttpError('账号或密码错误', 400)
  }
  return {
    token: `local.${account.key}`,
    refreshToken: `local.refresh.${account.key}`
  }
}

export function fetchGetUserInfo(): Promise<Api.Auth.UserInfo> {
  const userStore = useUserStore()
  const token = userStore.accessToken
  const key = token.startsWith('local.') ? token.slice(6) : ''
  const account = findAccount((a) => a.key === key)
  if (!account) {
    // 与 axios 拦截器的 401 处理保持一致：先登出清理旧状态，再拒绝
    userStore.logOut()
    return Promise.reject(new HttpError('登录状态无效，请重新登录', 401))
  }
  const { password: _password, key: _key, roleLabel: _roleLabel, ...userInfo } = account
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
  status: '启用'
}

export function listAccounts(): AccountRow[] {
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
