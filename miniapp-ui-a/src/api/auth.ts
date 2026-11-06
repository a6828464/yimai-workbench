import { http } from '@/utils/http'
import type { LoginResult, UserInfo } from '@/types'

/** POST /auth/login（throttle:10,1） */
export function login(userName: string, password: string) {
  return http.post<LoginResult>('/auth/login', { userName, password }, { silent: true })
}

/** GET /me */
export function fetchMe() {
  return http.get<UserInfo>('/me', undefined, { silent: true })
}

/** POST /auth/logout（fire-and-forget，本地先清） */
export function logout() {
  return http.post('/auth/logout', undefined, { silent: true }).catch(() => {})
}

/**
 * 微信授权登录（P2 合规硬门，后端 /auth/wx-login 待补 1.5 人日）。
 * wx.login → code → 后端 code2session → openid → Sanctum Token。
 * 后端落地前此函数保留为占位，调用方需降级到账号密码登录。
 */
export function wxLogin(): Promise<LoginResult> {
  return new Promise((resolve, reject) => {
    uni.login({
      provider: 'weixin',
      success: (res) => {
        if (!res.code) {
          reject(new Error('未取到微信登录凭证'))
          return
        }
        http
          .post<LoginResult>('/auth/wx-login', { code: res.code }, { silent: true })
          .then(resolve)
          .catch(reject)
      },
      fail: () => reject(new Error('微信登录不可用'))
    })
  })
}

export interface WxIdentity {
  openid: string
  bound: boolean
  boundAt?: string | null
  hasUnionid?: boolean
}

/** GET /auth/wx-identity（需登录）：只读自己的 openid，供本人报给管理员绑定 */
export function fetchWxIdentity() {
  return http.get<WxIdentity>('/auth/wx-identity', undefined, { silent: true })
}

/**
 * PUT /auth/wx-self-bind（需登录）：把「当前这个微信」绑到自己账号。
 *
 * 与管理员绑定的区别：openid 现取现用，不经人抄，也就抄不错。
 * 前提是已经用账号密码登录过 —— 这条路径不会替任何陌生人建号。
 */
export function wxSelfBind(): Promise<{ bound: boolean; boundAt?: string | null }> {
  return new Promise((resolve, reject) => {
    uni.login({
      provider: 'weixin',
      success: (res) => {
        if (!res.code) {
          reject(new Error('未取到微信登录凭证'))
          return
        }
        http
          .put<{ bound: boolean; boundAt?: string | null }>('/auth/wx-self-bind', { code: res.code }, { silent: true })
          .then(resolve)
          .catch(reject)
      },
      fail: () => reject(new Error('微信授权已取消'))
    })
  })
}
