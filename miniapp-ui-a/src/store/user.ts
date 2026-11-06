import { defineStore } from 'pinia'
import { getJSON, setJSON } from '@/utils/storage'
import { getToken, setToken, clearToken } from '@/utils/http'
import { canUse, primaryRole, roleLabel, type RoleCode } from '@/utils/permission'
import * as authApi from '@/api/auth'
import type { UserInfo } from '@/types'

const USER_INFO_KEY = 'yimai-user-info'

/** 「我的」页资料卡需要的展示字段（profile 接口比 /me 多 name/avatar/profile） */
export interface MyProfile {
  name: string
  nickname: string | null
  phone: string | null
  avatar: string | null
  email: string | null
  role: string
  roles: string[]
  venues: string[]
  profile: Record<string, unknown>
}

export const useUserStore = defineStore('user', {
  state: () => ({
    token: '' as string,
    info: null as UserInfo | null,
    /** GET /my/profile 的结果（惰性加载，用于个人信息页回填） */
    profile: null as MyProfile | null
  }),
  getters: {
    isLogin: (s) => !!s.token,
    roles: (s): string[] => s.info?.roles ?? [],
    staffName: (s) => s.info?.staffName ?? '',
    userName: (s) => s.info?.userName ?? '',
    venue: (s) => s.info?.venue ?? '',
    venues: (s) => s.info?.venues ?? [],
    /** 主角色码（超管 > 店长 > 服务 > 授课 > 新媒体） */
    primaryRole: (s): RoleCode | null => primaryRole(s.info?.roles),
    /** 「超管 · 店长」这样的短标签串 */
    roleText: (s): string => roleLabel(s.info?.roles),
    isSuper(): boolean {
      return this.roles.includes('R_SUPER')
    },
    isManager(): boolean {
      return this.roles.includes('R_MANAGER')
    },
    /** 任一功能权限判定（模板里也能直接调） */
    can(): (key: string) => boolean {
      return (key: string) => canUse(this.roles, key)
    }
  },
  actions: {
    /** 冷启动恢复（App.onLaunch 调） */
    restore() {
      this.token = getToken()
      this.info = getJSON<UserInfo>(USER_INFO_KEY)
    },
    async login(userName: string, password: string) {
      const res = await authApi.login(userName, password)
      this.applyLogin(res.token, res.userInfo)
    },
    /** 微信授权登录（后端 /auth/wx-login 已落地） */
    async loginWithWechat() {
      const res = await authApi.wxLogin()
      this.applyLogin(res.token, res.userInfo)
    },
    applyLogin(token: string, info: UserInfo) {
      this.token = token
      this.info = info
      setToken(token)
      setJSON(USER_INFO_KEY, info)
    },
    /** token 仍有效时刷新用户信息（进入工作台时调，失败不阻断） */
    async refreshMe() {
      try {
        this.info = await authApi.fetchMe()
        setJSON(USER_INFO_KEY, this.info)
      } catch {
        /* token 失效由 http 层 401 统一处理 */
      }
    },
    /** 更新本地缓存（个人资料保存后回填，避免再打一次 /me） */
    patchLocal(patch: Partial<UserInfo>) {
      if (!this.info) return
      this.info = { ...this.info, ...patch }
      setJSON(USER_INFO_KEY, this.info)
    },
    setProfile(p: MyProfile | null) {
      this.profile = p
    },
    logout() {
      authApi.logout()
      this.token = ''
      this.info = null
      this.profile = null
      clearToken()
      uni.removeStorageSync(USER_INFO_KEY)
      uni.reLaunch({ url: '/pages/login/login' })
    }
  }
})
