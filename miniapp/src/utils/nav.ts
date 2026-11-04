/**
 * 导航与页面级权限守卫
 *
 * 两类跳转必须区分，否则小程序会报
 * 「switchTab:fail can not switch to no-tabBar page」：
 *  tabBar 页（工作台 / 客户经营 / AI 助手 / 我的）→ uni.switchTab
 *  其余页面 → uni.navigateTo
 * 这里用一份白名单收口，页面里不再各写一遍。
 */

import { FEATURES, canUse, type FeatureDef } from './permission'
import { useUserStore } from '@/store/user'

/** 当前 tabBar 的四个页面（pages.json tabBar.list 同步） */
export const TAB_PAGES = new Set<string>([
  '/pages/workbench/workbench',
  '/pages/crm/crm',
  '/pages/ai/ai',
  '/pages/profile/profile'
])

export function isTabPage(path: string): boolean {
  return TAB_PAGES.has(path.split('?')[0])
}

/** 统一跳转：自动判断 switchTab / navigateTo */
export function goPath(path: string): void {
  const clean = path.split('?')[0]
  if (isTabPage(clean)) {
    uni.switchTab({ url: clean })
    return
  }
  uni.navigateTo({ url: path })
}

/**
 * 按功能 key 跳转（带权限判定）。
 * 无权限时不跳转、给一句可读提示并返回 false，调用方无需再判断。
 */
export function goFeature(key: string): boolean {
  const f = FEATURES[key]
  if (!f) return false
  const user = useUserStore()
  if (!canUse(user.roles, key)) {
    uni.showToast({ title: '当前账号无权访问该功能', icon: 'none' })
    return false
  }
  goPath(f.path)
  return true
}

/** 取功能定义（页面标题/说明复用同一份文案） */
export function featureOf(key: string): FeatureDef | undefined {
  return FEATURES[key]
}

/**
 * 页面级守卫：子页 onShow 调用。
 * 未登录 → 回登录页；有登录但无该功能权限 → 提示 + 返回上一页（无上一页则回工作台）。
 * 返回 true 表示可以继续加载数据。
 */
export function requireFeature(key: string): boolean {
  const user = useUserStore()
  if (!user.token) {
    uni.reLaunch({ url: '/pages/login/login' })
    return false
  }
  if (!canUse(user.roles, key)) {
    uni.showToast({ title: '当前账号无权访问该功能', icon: 'none' })
    const pages = getCurrentPages()
    if (pages.length > 1) {
      uni.navigateBack({ delta: 1 })
    } else {
      uni.switchTab({ url: '/pages/workbench/workbench' })
    }
    return false
  }
  return true
}

/**
 * 仅超管页面守卫（客户经营池等）。
 * 与 requireFeature 的差别：这里不依赖注册表，给「注册表里没有的临时页」兜底。
 */
export function requireSuper(): boolean {
  const user = useUserStore()
  if (!user.token) {
    uni.reLaunch({ url: '/pages/login/login' })
    return false
  }
  if (!(user.roles ?? []).includes('R_SUPER')) {
    uni.showToast({ title: '仅超级管理员可访问', icon: 'none' })
    const pages = getCurrentPages()
    if (pages.length > 1) uni.navigateBack({ delta: 1 })
    else uni.switchTab({ url: '/pages/workbench/workbench' })
    return false
  }
  return true
}

/** 复制文本（统一成功提示，避免各页文案不一） */
export function copyText(text: string, tip = '已复制'): void {
  if (!text) {
    uni.showToast({ title: '没有可复制的内容', icon: 'none' })
    return
  }
  uni.setClipboardData({
    data: text,
    success: () => uni.showToast({ title: tip, icon: 'none' })
  })
}

/** 拨号 */
export function callPhone(phone?: string | null): void {
  const v = (phone ?? '').trim()
  if (!v) {
    uni.showToast({ title: '没有手机号', icon: 'none' })
    return
  }
  uni.makePhoneCall({ phoneNumber: v.replace(/[^0-9+]/g, '') })
}
