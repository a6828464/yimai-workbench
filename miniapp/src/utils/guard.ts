import { useUserStore } from '@/store/user'

/**
 * 页面级登录守卫：tab 页 onShow 调用。
 * 未登录则跳登录页，返回 false（调用方应停止后续数据加载）。
 */
export function requireLogin(): boolean {
  const user = useUserStore()
  if (!user.token) {
    uni.reLaunch({ url: '/pages/login/login' })
    return false
  }
  return true
}
