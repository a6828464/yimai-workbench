import { HttpError } from '@/utils/http/error'
import { useUserStore } from '@/store/modules/user'
import { USE_BACKEND, apiGet, apiPost, apiPatch, apiPut, setBackendToken } from './backend'

/**
 * 认证：VITE_USE_BACKEND=true 走 Laravel 后端，否则本地模拟
 */
interface LocalAccount extends Api.Auth.UserInfo {
  key: string
  roleLabel: string
}

const DEMO_PASSWORD = import.meta.env.VITE_DEMO_PASSWORD ?? ''

export const LOCAL_ACCOUNTS: LocalAccount[] = [
  {
    key: 'owner',
    userId: 1001,
    userName: '演示超管',
    staffName: '演示超管',
    roles: ['R_SUPER'],
    buttons: [],
    email: 'owner@example.invalid',
    venue: null,
    venues: ['绿地店', '东部店'],
    roleLabel: '超管'
  },
  {
    key: 'manager-green',
    userId: 1002,
    userName: '绿地店长',
    staffName: '绿地店长',
    roles: ['R_MANAGER'],
    buttons: [],
    email: 'manager-green@example.invalid',
    venue: '绿地店',
    venues: ['绿地店'],
    roleLabel: '店长'
  },
  {
    key: 'manager-east',
    userId: 1003,
    userName: '东部店长',
    staffName: '东部店长',
    roles: ['R_MANAGER'],
    buttons: [],
    email: 'manager-east@example.invalid',
    venue: '东部店',
    venues: ['东部店'],
    roleLabel: '店长'
  },
  {
    key: 'service',
    userId: 1004,
    userName: '演示服务老师',
    staffName: '演示服务老师',
    roles: ['R_SERVICE'],
    buttons: [],
    email: 'service@example.invalid',
    venue: '绿地店',
    venues: ['绿地店'],
    roleLabel: '服务老师'
  },
  {
    key: 'teacher',
    userId: 1005,
    userName: '演示授课老师',
    staffName: '演示授课老师',
    roles: ['R_TEACHER'],
    buttons: [],
    email: 'teacher@example.invalid',
    venue: '绿地店',
    venues: ['绿地店'],
    roleLabel: '授课老师'
  },
  {
    key: 'media',
    userId: 1006,
    userName: '演示新媒体',
    staffName: '演示新媒体',
    roles: ['R_MEDIA'],
    buttons: [],
    email: 'media@example.invalid',
    venue: null,
    venues: ['绿地店', '东部店'],
    roleLabel: '新媒体'
  }
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
        (e as { response?: { data?: { message?: string } } }).response?.data?.message ??
        '账号或密码错误'
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
  // 显式挑出 UserInfo 需要的字段：LocalAccount 的 key / roleLabel 只是本地演示用的标签，
  // 不该混进登录用户信息（原来的 `...rest` 解构会留下两个未使用变量，lint 不认）。
  const userInfo: Api.Auth.UserInfo = {
    buttons: account.buttons,
    roles: account.roles,
    userId: account.userId,
    userName: account.userName,
    staffName: account.staffName,
    email: account.email,
    venue: account.venue,
    venues: account.venues
  }
  return Promise.resolve(userInfo)
}

/** 人员管理：账号清单（脱敏，不含密码） */
export interface AccountRow {
  key: string
  userName: string
  roleLabel: string
  /** 主角色（向后兼容） */
  roleCode: string
  /** 全部角色：账号支持多角色叠加，可见范围取并集 */
  roles: string[]
  venues: string[]
  email: string
  status: '启用' | '停用'
  self?: boolean
  /** 该账号在业务归属列里可能出现的其它名字（见后端 staffNames） */
  aliases?: string[]
}

/** 系统为某个悬空姓名猜的账号（带理由与可信度；只建议，不自动绑定） */
export interface StaffSuggestion {
  id: number
  key: string
  name: string
  /** 为什么猜是他（同表已绑定过 / 昵称一致 / 姓名互为包含） */
  reason: string
  confidence: 'high' | 'medium' | 'low'
}

/** 某个归属列的 id 覆盖率：还差多少行没落 id */
export interface StaffCoverage {
  ref: string
  label: string
  total: number
  withId: number
  missing: number
}

/** 人员身份归一总览 */
export interface StaffMapping {
  accounts: {
    key: string
    name: string
    nickname?: string
    aliases: string[]
    roles?: string[]
    venue?: string | null
    status?: string
    /** 该账号名下的数据行数（按姓名 + id 并集），认领/改名后会立刻变化 */
    footprint?: Record<string, number>
  }[]
  /** 业务数据里出现过、但对不上任何账号的姓名 —— 非空即表示有数据的归属悬空了 */
  unmapped: {
    name: string
    counts: Record<string, number>
    suggestions?: StaffSuggestion[]
  }[]
  /** 归属 id 指向已不存在账号的行（账号删掉重建留下的），需要清理 */
  staleIds: { table: string; column: string; user_id: number; rows: number }[]
  coverage?: StaffCoverage[]
  totals?: { unmapped: number; missingIds: number }
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
    roles: [...a.roles],
    venues: a.venues ?? [],
    email: a.email,
    status: '启用'
  }))
}

/** 新增账号（仅超管，凭据由服务端保管） */
export async function createAccount(data: {
  userName: string
  name?: string
  /** 可多选：权限叠加，可见范围取并集 */
  roles: string[]
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
  data?: {
    /** 姓名＝归属名（改名时后端会把旧名登记为别名，历史数据不断链） */
    name?: string
    roles?: string[]
    roleCode?: string
    venues?: string[]
    password?: string
  }
): Promise<{ ok: boolean }> {
  if (!USE_BACKEND) throw new Error('演示模式不支持账号变更')
  return apiPatch<{ ok: boolean }>(`/accounts/${encodeURIComponent(key)}`, {
    action,
    ...data
  } as Record<string, unknown>)
}

/**
 * 保存人员列表的**手动顺序**（仅超管）
 *
 * 传的是完整顺序而不是「谁挪到第几位」：拖动一次可能跨十几行，增量需要服务端
 * 复现前端排序算法才对得上；传全量等于「前端看到什么就存什么」，不会静默错位。
 * 后端会把这批账号重写成 10/20/30…，入参里没出现的账号按原顺序接在末尾。
 */
export async function reorderAccounts(keys: string[]): Promise<{ updated: number }> {
  if (!USE_BACKEND) throw new Error('演示模式不支持调整顺序')
  return apiPut<{ updated: number }>('/accounts/order', { order: keys })
}

/**
 * 人员归属映射总览（仅超管）
 *
 * 归属列存的是姓名，不是外键。未映射名单非空就说明有人看不到属于自己的数据。
 */
export async function getStaffMapping(): Promise<StaffMapping> {
  if (!USE_BACKEND) return { accounts: [], unmapped: [], staleIds: [] }
  return apiGet<StaffMapping>('/accounts/staff-mapping')
}

/** 保存某账号的别名（整体替换，仅超管） */
export async function saveStaffAliases(
  key: string,
  aliases: string[]
): Promise<{ aliases: string[] }> {
  if (!USE_BACKEND) throw new Error('演示模式不支持修改别名')
  return apiPut<{ aliases: string[] }>(`/accounts/${encodeURIComponent(key)}/aliases`, { aliases })
}

/**
 * 认领一个悬空姓名到某个账号（仅超管）。
 *
 * 「随心瑜叫苏米、留资写蒙澍南、工作台注册叫澍南」的统一动作：认领后写进别名表，
 * 并回填所有历史行的归属 id —— 本人刷新即能看到。
 */
export async function assignStaffName(
  name: string,
  userKey: string
): Promise<{ alias: string; user: number; backfilled: number }> {
  if (!USE_BACKEND) throw new Error('演示模式不支持认领归属')
  return apiPost<{ alias: string; user: number; backfilled: number }>(
    '/accounts/staff-mapping/assign',
    { name, userKey }
  )
}

/** 全量补齐归属 id（仅超管，可重复执行） */
export async function repairStaffMapping(opts?: {
  clearStale?: boolean
}): Promise<{ backfilled: number; cleared: number; coverage: StaffCoverage[] }> {
  if (!USE_BACKEND) throw new Error('演示模式不支持修复归属')
  return apiPost<{ backfilled: number; cleared: number; coverage: StaffCoverage[] }>(
    '/accounts/staff-mapping/repair',
    { ...opts }
  )
}

/** 账号合并：把 from 里的人连数据一起并进 into（仅超管） */
export async function mergeStaffAccount(
  into: string,
  from: string[]
): Promise<{ into: number; rows: number; aliases: number; backfilled: number; skipped: string[] }> {
  if (!USE_BACKEND) throw new Error('演示模式不支持合并账号')
  return apiPost<{
    into: number
    rows: number
    aliases: number
    backfilled: number
    skipped: string[]
  }>('/accounts/staff-mapping/merge', { into, from })
}

/** 归属下拉的数据源：账号（规范名），而不是历史自由文本 */
export interface StaffOption {
  id: number
  /** 归属列里应该写入的规范名 */
  name: string
  /** 该账号的全部可用名字（含别名/昵称） */
  aliases: string[]
  roles: string[]
  venue: string | null
  self: boolean
}

export async function listStaffOptions(): Promise<StaffOption[]> {
  if (!USE_BACKEND) return []
  const data = await apiGet<{ staff: StaffOption[] }>('/staff/options')
  return data.staff ?? []
}
