/**
 * 存储适配层（StorageLike shim）
 *
 * 目标：把 web 端 localStorage 语义平移到小程序，api/store 层不感知平台差异。
 * 实现：uni.getStorageSync 系 API（mp-weixin / app / h5 全端一致）。
 */

export interface StorageLike {
  getItem(key: string): string | null
  setItem(key: string, value: string): void
  removeItem(key: string): void
}

class UniStorage implements StorageLike {
  getItem(key: string): string | null {
    try {
      const v = uni.getStorageSync(key)
      // uni 对不存在 key 返回 ''，语义对齐 localStorage 的 null
      return v === '' || v === undefined || v === null ? null : String(v)
    } catch {
      return null
    }
  }

  setItem(key: string, value: string): void {
    try {
      uni.setStorageSync(key, value)
    } catch (e) {
      console.warn('[storage] setItem failed:', key, e)
    }
  }

  removeItem(key: string): void {
    try {
      uni.removeStorageSync(key)
    } catch {
      /* noop */
    }
  }
}

export const storage: StorageLike = new UniStorage()

/** 类型化读取 */
export function getJSON<T>(key: string): T | null {
  const raw = storage.getItem(key)
  if (raw === null) return null
  try {
    return JSON.parse(raw) as T
  } catch {
    return null
  }
}

export function setJSON(key: string, value: unknown): void {
  storage.setItem(key, JSON.stringify(value))
}
