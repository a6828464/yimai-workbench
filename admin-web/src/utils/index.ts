/**
 * Utils 工具函数统一导出
 * 提供向后兼容性和便捷导入
 *
 * @module utils/index
 * @author Art Design Pro Team
 */

// UI 相关
export * from './ui'

// 路由相关
export * from './router'

// 路由导航相关
export * from './navigation'

// 系统管理相关
export * from './sys'

// 常量定义相关
export * from './constants'

// 存储相关
export * from './storage'

// HTTP 相关
export * from './http'

// 表单相关
export * from './form'

// socket 相关
export * from './socket'

/**
 * 按本地时区格式化为 YYYY-MM-DD（避免 toISOString 在 UTC+8 早间跨天少一天）
 */
export function toLocalDateString(date: Date): string {
  const y = date.getFullYear()
  const m = String(date.getMonth() + 1).padStart(2, '0')
  const d = String(date.getDate()).padStart(2, '0')
  return `${y}-${m}-${d}`
}
