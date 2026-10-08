/**
 * 收藏同步编排（两个工具 + 页面外壳共用）
 * =======================================
 *
 * 收藏的真源在服务端（`marketing_favorites` 表），本地 `marketing.favorites` 只是**缓存副本**。
 * 本文件是「拉取 + 旧收藏导入 + 用户可见反馈」的唯一编排点，避免两个视图各写一套。
 *
 * 两条触发（spec §5.5，都必须有）：
 * 1. **主触发**：登录态就绪后静默跑一次（`syncOnLogin`）——把「升级后在打开抽屉之前登出、
 *    PII 清理把尚未导入的旧收藏永久抹掉」这个丢失窗口从「用户想起来」压到秒级；
 * 2. **辅触发**：用户打开收藏抽屉时再跑一次（`refresh`）——用户在场，所以给可见反馈。
 *
 * 两者共用 store 里的去重键与 `favoritesImportedAt` 标记，天然幂等。
 *
 * @module marketing-favorites
 */

import { ElMessage } from 'element-plus'
import { useAiConfigStore } from '@/store/modules/ai-config'
import type { FavoriteItem } from '@/store/modules/ai-config'
import type { MarketingFavoriteWriteError } from '@/api/my'

/** 收藏来源状态：决定 UI 显示「暂无收藏」还是「离线，显示本机缓存」 */
export type FavoritesOrigin = 'ok' | 'offline-cached' | 'offline-nocache'

export function useMarketingFavorites() {
  const aiStore = useAiConfigStore()
  const loading = ref(false)

  /**
   * 三态判定（spec §4.4）：
   * - `ok`：云端可用；
   * - `offline-cached`：读不到，但有本机缓存 → 显示缓存 + 明确标注来源；
   * - `offline-nocache`：读不到且本机也没缓存（例如被 PII 清理过）→ **必须与「暂无收藏」区分**，
   *   否则又变成「收藏被删了」的体感 bug（O-14 同型）。
   */
  const origin = computed<FavoritesOrigin>(() => {
    if (!aiStore.marketing.favoritesOffline) return 'ok'
    return aiStore.marketing.favorites.length ? 'offline-cached' : 'offline-nocache'
  })

  /**
   * 尚未同步到服务端的本地旧收藏条数（持久化在 `marketing.favoritesPendingImport`）。
   * 抽屉必须把它显式标出来 —— 否则用户会把「本机待同步」误认为「已保存到云端」。
   */
  const pendingCount = computed(() => aiStore.marketing.favoritesPendingImport.length)

  const offlineText = computed(() => {
    if (origin.value === 'offline-cached') return '离线：正在显示本机缓存，云端收藏暂不可用'
    if (origin.value === 'offline-nocache')
      return '离线，且本机无缓存：读不到云端收藏（不代表收藏被删除，恢复网络后会自动重新拉取）'
    return ''
  })

  /** 辅触发：打开收藏抽屉时强制对齐云端，并跑一次**可见**反馈的旧收藏导入 */
  async function refresh(): Promise<void> {
    loading.value = true
    try {
      // 与主触发同口径（R3）：拿不到云端列表就**跳过本轮导入** ——
      // 去重集合是「云端现有条目」，离线时它没有意义；标记不写，下次再试。
      const ok = await aiStore.loadFavorites({ force: true })
      if (!ok) return
      const res = await aiStore.importLocalFavorites()
      if (res.ok) ElMessage.success(`已把本机 ${res.ok} 条收藏同步到云端`)
      if (res.failed) ElMessage.warning(`本机还有 ${res.failed} 条未同步，下次打开会自动重试`)
    } finally {
      loading.value = false
    }
  }

  /** 主触发：登录态就绪后静默跑（不弹 toast，失败也不打扰；标记未写入则下次重试） */
  async function syncOnLogin(): Promise<void> {
    const ok = await aiStore.loadFavorites()
    if (ok) await aiStore.importLocalFavorites()
  }

  /**
   * 写失败的提示文案（§4.4）：上限类是**用户可自行解决**的，原样透出后端中文提示；
   * 其余（网络/5xx）给通用文案。
   */
  function describeWriteError(error: MarketingFavoriteWriteError): string {
    return error.status === 422 && error.message.includes('上限')
      ? error.message
      : '收藏失败，请重试'
  }

  /**
   * 收藏一条：**提示的唯一出口**。
   *
   * - 成功：不弹（由调用方按自己的语境给成功提示，例如「已收藏方案 2」）；
   * - 失败：弹一次（失败原因由 api/store 作为数据上抛，视图不再重复弹 → §4.7 不弹两次）；
   * - 失败**不落本地**：用户当场看到失败并重试，而不是「本机有、云端无」的假成功。
   */
  async function saveFavorite(item: Omit<FavoriteItem, 'id' | 'createdAt'>): Promise<boolean> {
    const res = await aiStore.addFavorite(item)
    if (res.ok) return true
    ElMessage.error(describeWriteError(res.error))
    return false
  }

  return {
    aiStore,
    loading,
    origin,
    offlineText,
    pendingCount,
    refresh,
    syncOnLogin,
    saveFavorite,
    describeWriteError
  }
}
