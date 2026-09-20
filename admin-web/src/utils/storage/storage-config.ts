/**
 * 存储配置管理模块
 *
 * 提供统一的本地存储配置和工具方法
 *
 * ## 主要功能
 *
 * - 版本化存储键管理，支持多版本数据隔离
 * - 存储键名生成和解析（带版本前缀）
 * - 版本号提取和验证
 * - 存储键匹配的正则表达式生成
 * - 旧版本存储键兼容处理
 * - 升级和登出延迟配置
 * - 主题存储键配置
 *
 * ## 使用场景
 *
 * - Pinia Store 持久化存储
 * - 应用版本升级时的数据迁移
 * - 多版本数据清理
 * - 存储键的统一管理和规范
 *
 * 存储键格式：sys-v{version}-{storeId}
 * 例如：sys-v1.0.0-user, sys-v1.0.0-setting
 *
 * @module utils/storage/storage-config
 * @author Art Design Pro Team
 */
export class StorageConfig {
  /** 当前应用版本 */
  static readonly CURRENT_VERSION = __APP_VERSION__

  /** 存储键前缀 */
  static readonly STORAGE_PREFIX = 'sys-v'

  /** 版本键名 */
  static readonly VERSION_KEY = 'sys-version'

  /** 主题键名（index.html中使用了，如果修改，需要同步修改） */
  static readonly THEME_KEY = 'sys-theme'

  /** 上次登录用户ID键名（用于判断是否为同一用户登录） */
  static readonly LAST_USER_ID_KEY = 'sys-last-user-id'

  /** 响应式布局切换时暂存桌面端菜单类型 */
  static readonly RESPONSIVE_MENU_TYPE_KEY = 'sys-responsive-menu-type'

  /**
   * 含客户 / 留资 PII、需要在登出 / 切号 / 会话失效时清理的持久化 storeId 列表
   *
   * 这里的值对应各 store `persist.key` 的 storeId 维度（见 store/modules/*.ts），
   * 实际落盘键由 generateStorageKey() 加上版本前缀，因此**不得**在任何清理点硬编码裸键。
   * 清理端（store/modules/user.ts）与校验脚本（scripts/check-pii-storage-keys.ts）共用本常量，
   * 保证口径与写入端同源、不随 VITE_VERSION 漂移。
   */
  static readonly BUSINESS_PII_STORE_IDS = [
    'yimai-store',
    'yimai-ai-config',
    'yimai-sales-store'
  ] as const

  /**
   * 需要整段按前缀清理的 PII 键前缀
   *
   * 训练计划按用户维度分键（yimai-training-store:user:{userId}），
   * 无法用单个 storeId 表达，因此单独列出前缀。
   */
  static readonly BUSINESS_PII_KEY_PREFIXES = ['yimai-training-store'] as const

  /** 跳过升级检查的版本 */
  static readonly SKIP_UPGRADE_VERSION = '1.0.0'

  /** 升级处理延迟时间（毫秒） */
  static readonly UPGRADE_DELAY = 1000

  /** 登出延迟时间（毫秒） */
  static readonly LOGOUT_DELAY = 1000

  /**
   * 生成版本化的存储键名
   * @param storeId 存储ID
   * @param version 版本号，默认使用当前版本
   */
  static generateStorageKey(storeId: string, version: string = this.CURRENT_VERSION): string {
    return `${this.STORAGE_PREFIX}${version}-${storeId}`
  }

  /**
   * 生成旧版本的存储键名（不带分隔符）
   * @param version 版本号，默认使用当前版本
   */
  static generateLegacyKey(version: string = this.CURRENT_VERSION): string {
    return `${this.STORAGE_PREFIX}${version}`
  }

  /**
   * 创建存储键匹配的正则表达式
   * @param storeId 存储ID
   */
  static createKeyPattern(storeId: string): RegExp {
    return new RegExp(`^${this.STORAGE_PREFIX}[^-]+-${storeId}$`)
  }

  /**
   * 创建当前版本存储键匹配的正则表达式
   */
  static createCurrentVersionPattern(): RegExp {
    return new RegExp(`^${this.STORAGE_PREFIX}${this.CURRENT_VERSION}-`)
  }

  /**
   * 创建任意版本存储键匹配的正则表达式
   */
  static createVersionPattern(): RegExp {
    return new RegExp(`^${this.STORAGE_PREFIX}`)
  }

  /**
   * 检查是否为当前版本的键
   */
  static isCurrentVersionKey(key: string): boolean {
    return key.startsWith(`${this.STORAGE_PREFIX}${this.CURRENT_VERSION}`)
  }

  /**
   * 检查是否为版本化的键
   */
  static isVersionedKey(key: string): boolean {
    return key.startsWith(this.STORAGE_PREFIX)
  }

  /**
   * 从存储键中提取版本号
   */
  static extractVersionFromKey(key: string): string | null {
    const match = key.match(new RegExp(`^${this.STORAGE_PREFIX}([^-]+)`))
    return match ? match[1] : null
  }

  /**
   * 从存储键中提取存储ID
   */
  static extractStoreIdFromKey(key: string): string | null {
    const match = key.match(new RegExp(`^${this.STORAGE_PREFIX}[^-]+-(.+)$`))
    return match ? match[1] : null
  }

  /**
   * 判断某个键是否为指定 storeId 的持久化键（**版本号无关**）
   *
   * 语义等价于「key === generateStorageKey(storeId, 任意版本)」，
   * 但版本段允许含 '-'，比 createKeyPattern 的 `[^-]+` 更宽松：
   * 版本号一旦带连字符（如 3.1.44-beta），createKeyPattern 会**静默漏匹配**，
   * 该键就再没有任何清理路径 —— 正是本模块要防的 PII 残留形态。
   * （createKeyPattern 语义保持不变，仍供数据迁移使用。）
   *
   * @param key 待判断的存储键
   * @param storeId 存储ID
   * @param allowBare 是否把无版本前缀的裸键也算作命中（历史实现遗留）
   */
  static matchesStoreIdKey(key: string, storeId: string, allowBare: boolean = true): boolean {
    if (key === storeId) return allowBare
    if (!key.startsWith(this.STORAGE_PREFIX)) return false

    const rest = key.slice(this.STORAGE_PREFIX.length)
    const suffix = `-${storeId}`
    // 版本段必须非空，避免把畸形键 sys-v-{storeId} 当成有效键
    return rest.length > suffix.length && rest.endsWith(suffix)
  }

  /**
   * 列出某个 storeId 下**全部版本前缀**的实际落盘键（含当前版本与历史版本残留）
   *
   * 持久化插件写入的是 generateStorageKey(storeId)（形如 sys-v3.1.44-yimai-store），
   * 而裸键 yimai-store 只在旧版本 / 早期实现中出现，故两者都要覆盖。
   *
   * @param storeId 存储ID
   * @param keys 待检查的键集合，默认取 localStorage 的全部键
   */
  static listKeysForStoreId(storeId: string, keys?: readonly string[]): string[] {
    const candidates = keys ?? Object.keys(localStorage)
    return candidates.filter((key) => this.matchesStoreIdKey(key, storeId))
  }
}
