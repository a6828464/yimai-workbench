import { defineStore } from 'pinia'
import { getJSON, setJSON } from '@/utils/storage'
import { getToken, setToken, clearToken } from '@/utils/http'
import * as authApi from '@/api/auth'
import type { UserInfo } from '@/types'

const USER_INFO_KEY = 'yimai-user-info'

export const useUserStore = defineStore('user', {
  state: () => ({
    token: '' as string,
    info: null as UserInfo | null
  }),
  getters: {
    isLogin: (s) => !!s.token,
    roles: (s) => s.info?.roles ?? [],
    staffName: (s) => s.info?.staffName ?? '',
    venue: (s) => s.info?.venue ?? ''
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
    /** 微信授权登录（后端 /auth/wx-login 落地后启用） */
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
    logout() {
      authApi.logout()
      this.token = ''
      this.info = null
      clearToken()
      uni.removeStorageSync(USER_INFO_KEY)
      uni.reLaunch({ url: '/pages/login/login' })
    }
  }
})
