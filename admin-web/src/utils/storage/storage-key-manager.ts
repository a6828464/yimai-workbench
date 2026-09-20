/**
 * 存储键名管理器模块
 *
 * 提供智能的版本化存储键管理和数据迁移功能
 *
 * ## 主要功能
 *
 * - 自动生成当前版本的存储键名
 * - 检测当前版本数据是否存在
 * - 查找其他版本的同名存储数据
 * - 自动将旧版本数据迁移到当前版本
 * - 数据迁移日志记录
 * - 迁移失败的错误处理
 *
 * ## 使用场景
 *
 * - Pinia Store 持久化插件中获取存储键
 * - 应用版本升级时自动迁移用户数据
 * - 避免版本升级导致的数据丢失
 * - 实现平滑的版本过渡
 *
 * ## 工作流程
 *
 * 1. 优先使用当前版本的存储键
 * 2. 如果当前版本无数据，查找其他版本的同名数据
 * 3. 找到旧版本数据后自动迁移到当前版本
 * 4. 返回当前版本的存储键供使用
 *
 * @module utils/storage/storage-key-manager
 * @author Art Design Pro Team
 */
import { StorageConfig } from './storage-config'

/**
 * 最小存储接口（与 pinia-plugin-persistedstate 的 StorageLike 形状一致）
 *
 * 只声明清理所需的方法，便于在 Node 环境下用假实现替换 localStorage。
 */
export interface StorageLike {
  getItem: (key: string) => string | null
  setItem: (key: string, value: string) => void
  removeItem: (key: string) => void
}

/**
 * 存储键名管理器
 * 负责处理版本化的存储键名生成和数据迁移
 */
export class StorageKeyManager {
  /**
   * 获取当前版本的存储键名
   */
  private getCurrentVersionKey(storeId: string): string {
    return StorageConfig.generateStorageKey(storeId)
  }

  /**
   * 检查当前版本的数据是否存在
   */
  private hasCurrentVersionData(key: string): boolean {
    return localStorage.getItem(key) !== null
  }

  /**
   * 查找其他版本的同名存储键
   */
  private findExistingKey(storeId: string): string | null {
    const storageKeys = Object.keys(localStorage)
    const pattern = StorageConfig.createKeyPattern(storeId)

    return storageKeys.find((key) => pattern.test(key) && localStorage.getItem(key)) || null
  }

  /**
   * 将数据从旧版本迁移到当前版本
   */
  private migrateData(fromKey: string, toKey: string): void {
    try {
      const existingData = localStorage.getItem(fromKey)
      if (existingData) {
        localStorage.setItem(toKey, existingData)
        console.info(`[Storage] 已迁移数据: ${fromKey} → ${toKey}`)
      }
    } catch (error) {
      console.warn(`[Storage] 数据迁移失败: ${fromKey}`, error)
    }
  }

  /**
   * 纯函数：从给定键集合中挑出所有需要清理的业务 PII 键
   *
   * 覆盖三种形态，且**不硬编码任何版本号**，故口径与写入端永远同源：
   * 1. 当前版本键 sys-v{CURRENT_VERSION}-{storeId}（持久化插件实际写入的键）
   * 2. 历史版本前缀残留键 sys-v{旧版本}-{storeId}（VITE_VERSION 每版必改，旧键无清理路径）
   * 3. 无版本前缀的裸键与按用户维度分键的前缀类键 yimai-training-store:user:{id}
   *
   * @param keys 待筛选的键集合（默认取 localStorage 全部键）
   * @param storeIds 业务 PII storeId 列表
   * @param prefixes 需整段按前缀清理的键前缀列表
   */
  static collectBusinessPiiKeys(
    keys: readonly string[],
    storeIds: readonly string[] = StorageConfig.BUSINESS_PII_STORE_IDS,
    prefixes: readonly string[] = StorageConfig.BUSINESS_PII_KEY_PREFIXES
  ): string[] {
    const doomed = new Set<string>()

    keys.forEach((key) => {
      // matchesStoreIdKey 版本号无关：当前版本与全部历史版本一次命中
      const ownedByStoreId = storeIds.some((storeId) =>
        StorageConfig.matchesStoreIdKey(key, storeId)
      )
      const ownedByPrefix = prefixes.some((prefix) => key.startsWith(prefix))

      if (ownedByStoreId || ownedByPrefix) doomed.add(key)
    })

    return [...doomed].sort()
  }

  /**
   * 清理业务 PII 持久化数据（登出 / 切号 / 会话失效共用）
   *
   * @param storage 目标存储，默认 localStorage
   * @returns 实际被移除的键列表（便于调用方与校验脚本核对）
   */
  purgeBusinessPiiKeys(storage: StorageLike | Storage = localStorage): string[] {
    const doomed = StorageKeyManager.collectBusinessPiiKeys(Object.keys(storage))

    doomed.forEach((key) => storage.removeItem(key))

    return doomed
  }

  /**
   * 获取持久化存储的键名（支持自动数据迁移）
   */
  getStorageKey(storeId: string): string {
    const currentKey = this.getCurrentVersionKey(storeId)

    // 优先使用当前版本的数据
    if (this.hasCurrentVersionData(currentKey)) {
      return currentKey
    }

    // 查找并迁移其他版本的数据
    const existingKey = this.findExistingKey(storeId)
    if (existingKey) {
      this.migrateData(existingKey, currentKey)
    }

    return currentKey
  }
}
