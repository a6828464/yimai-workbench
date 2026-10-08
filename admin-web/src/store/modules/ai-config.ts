import { defineStore } from 'pinia'
import { USE_BACKEND, getBackendToken } from '@/api/backend'
import { fetchMarketingFavorites, saveMarketingFavorite, removeMarketingFavorite } from '@/api/my'
import type {
  MarketingFavoriteItem,
  MarketingFavoriteInput,
  MarketingFavoriteSaveResult
} from '@/api/my'

export interface AiProviderPreset {
  label: string
  baseUrl: string
  models: string[]
}

export const AI_PROVIDER_PRESETS: AiProviderPreset[] = [
  {
    label: 'DeepSeek（深度求索）',
    baseUrl: 'https://api.deepseek.com/v1',
    models: ['deepseek-chat', 'deepseek-reasoner']
  },
  {
    label: '通义千问 Qwen（阿里云）',
    baseUrl: 'https://dashscope.aliyuncs.com/compatible-mode/v1',
    models: ['qwen-plus', 'qwen-max', 'qwen-turbo']
  },
  {
    label: 'Kimi / Moonshot（月之暗面）',
    baseUrl: 'https://api.moonshot.cn/v1',
    models: ['moonshot-v1-8k', 'moonshot-v1-32k']
  },
  {
    label: '智谱 GLM',
    baseUrl: 'https://open.bigmodel.cn/api/paas/v4',
    models: ['glm-4-air', 'glm-4-plus']
  },
  {
    label: '火山方舟 · 豆包',
    baseUrl: 'https://ark.cn-beijing.volces.com/api/v3',
    models: ['doubao-pro-32k']
  },
  { label: 'OpenAI', baseUrl: 'https://api.openai.com/v1', models: ['gpt-4o-mini', 'gpt-4o'] },
  { label: '自定义（OpenAI 兼容）', baseUrl: '', models: [] }
]

export interface AiConfig {
  enabled: boolean
  providerLabel: string
  baseUrl: string
  apiKey: string
  model: string
  temperature: number
}

/** 后端模式下密钥在服务端数据库，前端用该占位符表示「已配置」 */
export const SERVER_CONFIGURED_PLACEHOLDER = 'server-configured'

export interface FavoriteItem {
  id: number
  platform: string
  title: string
  content: string
  /** 首评/置顶评论（朋友圈收藏）。服务端缺失为 null；改造前的本地老数据可能连键都没有 */
  reply?: string | null
  /** 封面文案（小红书收藏）。服务端缺失为 null；改造前的本地老数据可能连键都没有 */
  coverText?: string | null
  tags: string[]
  createdAt: string
}

interface MarketingState {
  /**
   * 兼容保留：本地 id 发号器。
   * 停用（服务端 id 为准）：后端模式下收藏 id 一律来自服务端；
   * 仅演示模式（USE_BACKEND=false，无服务端）仍用它发号。
   * 退役条件：确认无历史数据引用本地 id 后可删（不删导出、不改形状）。
   */
  nextFavoriteId: number
  /** 服务端收藏列表的**缓存副本**（语义已变更：不再是真源） */
  favorites: FavoriteItem[]
  /**
   * 是否**至少成功拉取过一次**云端（可跨会话，仅供 UI 判断缓存可信度）。
   * 注意：**不**用它判断「本 session 已拉过」—— 那件事由模块级的 `fetchedForToken` 承担，
   * 否则新会话会短路掉拉取并导致「去重集自比」的假成功（见其注释）。
   */
  favoritesLoaded: boolean
  /** 上次拉取是否失败（决定 UI 是否显示离线提示） */
  favoritesOffline: boolean
  /** 本地旧收藏一次性导入的完成标记（'' = 未导入；跨会话保留，否则每次登录都重跑） */
  favoritesImportedAt: string
  /**
   * **待导入服务端的本地旧收藏**（升级迁移的 pending 集合）。
   *
   * 与 `favorites` 同住一个 `persist.key`（不新增存储键 → PII 门禁零改动），但语义不同：
   * `favorites` 是「服务端数据的缓存副本」，本字段是「尚未同步到服务端的本地条目」。
   * 持久化是必须的 —— 原实现把快照放在模块级变量里，刷新页面即丢，
   * 于是 `failed > 0` 的条目再也回不来（RV2 R1 的修复点）。
   * 每条 POST 成功即从本数组移除；全部清空才算导入完成（写 `favoritesImportedAt`）。
   */
  favoritesPendingImport: FavoriteItem[]
}

/**
 * 「本 session 是否已成功拉到云端」——**模块级、不进持久化状态**（R5/t27 的修复点）。
 *
 * 为什么不能用持久化的 `favoritesLoaded` 做这件事：它会随 `yimai-ai-config` 落盘，
 * 于是新会话一进来就"看起来已拉过"，`loadFavorites()` 直接短路 → 去重集合变成**本机缓存自己**
 * （自比），待导入条目会被判成「云端已有」而被移出 pending → 未导入却"完成"；
 * 演示构建切到后端构建时同样会踩到。
 *
 * 这里存的是**拉取时所用的 token**：token 变了（登出再登入、换账号）说明打开的是另一份会话数据，
 * 必须重新拉取，避免把上一个账号的缓存当成当前账号的收藏展示。
 */
let fetchedForToken: string | null = null

/** 本 session（且同一登录身份）是否已成功拉到云端 */
function sessionFetched(): boolean {
  return fetchedForToken !== null && fetchedForToken === getBackendToken()
}

export const useAiConfigStore = defineStore(
  'aiConfigStore',
  () => {
    const config = ref<AiConfig>({
      enabled: false,
      providerLabel: 'DeepSeek（深度求索）',
      baseUrl: 'https://api.deepseek.com/v1',
      apiKey: '',
      model: 'deepseek-chat',
      temperature: 0.8
    })

    const marketing = ref<MarketingState>({
      nextFavoriteId: 1,
      favorites: [],
      favoritesLoaded: false,
      favoritesOffline: false,
      favoritesImportedAt: '',
      favoritesPendingImport: []
    })

    function applyPreset(label: string) {
      const preset = AI_PROVIDER_PRESETS.find((p) => p.label === label)
      if (!preset) return
      config.value.providerLabel = preset.label
      if (preset.baseUrl) config.value.baseUrl = preset.baseUrl
      if (preset.models.length && !preset.models.includes(config.value.model)) {
        config.value.model = preset.models[0]
      }
    }

    function isReady(): boolean {
      // 后端模式密钥由服务端保管：水合后 apiKey 为占位符（server-configured），视为已配置
      return (
        config.value.enabled &&
        !!config.value.baseUrl &&
        !!config.value.model &&
        !!config.value.apiKey
      )
    }

    function maskKey(): string {
      const k = config.value.apiKey
      if (k === SERVER_CONFIGURED_PLACEHOLDER) return '已配置（服务端数据库保管）'
      if (!k) return '未配置'
      if (k.length <= 10) return k.slice(0, 2) + '****'
      return `${k.slice(0, 6)}****${k.slice(-4)}`
    }

    /**
     * 新增收藏。
     *
     * 后端模式：**先写服务端，成功后才插入本地缓存**（失败不落本地 —— 落本地会造成
     * 「本机有、云端无」，正是本次要修的跨设备不一致的同型复现）。
     * 演示模式：保持改造前的本地行为（本地发号），行为逐项不变。
     *
     * 返回值：成功 `{ ok: true, id }`；失败 `{ ok: false, error }` —— 失败原因只作为**数据**
     * 上抛，提示文案由视图层单点给出（api/store 层不引入 UI 依赖，见 my.ts 的说明）。
     */
    async function addFavorite(
      item: Omit<FavoriteItem, 'id' | 'createdAt'>
    ): Promise<MarketingFavoriteSaveResult> {
      const payload: MarketingFavoriteInput = {
        platform: item.platform,
        title: item.title,
        content: item.content,
        reply: item.reply ?? '',
        coverText: item.coverText ?? '',
        tags: item.tags ?? []
      }
      if (!USE_BACKEND) {
        marketing.value.favorites.unshift({
          ...payload,
          title: payload.title ?? '',
          reply: payload.reply,
          coverText: payload.coverText,
          tags: payload.tags ?? [],
          id: marketing.value.nextFavoriteId++,
          createdAt: nowText()
        })
        return { ok: true, id: marketing.value.favorites[0].id }
      }
      const saved = await saveMarketingFavorite(payload)
      if (!saved.ok) return saved
      marketing.value.favorites.unshift({
        ...payload,
        title: payload.title ?? '',
        reply: payload.reply,
        coverText: payload.coverText,
        tags: payload.tags ?? [],
        id: saved.id,
        createdAt: nowText()
      })
      return saved
    }

    /**
     * 删除收藏：先删服务端，成功后从缓存移除。
     * 失败时列表**保持不动**（服务端幂等，下次拉取会自动对齐）并 console.warn，不打扰用户。
     */
    async function removeFavorite(id: number): Promise<boolean> {
      if (!USE_BACKEND) {
        marketing.value.favorites = marketing.value.favorites.filter((f) => f.id !== id)
        return true
      }
      try {
        await removeMarketingFavorite(id)
      } catch (e) {
        console.warn('[favorites] 删除失败，列表保持不动（服务端幂等，下次拉取自动对齐）', e)
        return false
      }
      marketing.value.favorites = marketing.value.favorites.filter((f) => f.id !== id)
      return true
    }

    /**
     * 拉取云端收藏并覆盖本地缓存，返回是否成功。
     *
     * - 失败（离线 / 未登录 / 后端 5xx）：置 `favoritesOffline = true` 并**保留现有缓存**
     *   —— 清空会让用户在断网瞬间以为「收藏没了」，比原 bug 更吓人（规格 §4.3 硬约束 2）。
     * - 演示模式：本地缓存就是真源，直接视为已加载。
     * - `force` 用于「打开收藏抽屉」时强制对齐（默认每次会话只拉一次）。
     */
    async function loadFavorites(opts?: { force?: boolean }): Promise<boolean> {
      // 覆盖缓存前先把「升级前留在 favorites 里的本地旧收藏」搬进 pending（同键持久化）
      capturePendingImport()
      if (!USE_BACKEND) {
        marketing.value.favoritesLoaded = true
        marketing.value.favoritesOffline = false
        return true
      }
      if (sessionFetched() && !opts?.force) return true
      const list = await fetchMarketingFavorites()
      if (list === null) {
        marketing.value.favoritesOffline = true
        return false
      }
      marketing.value.favorites = list.map(normalizeFavorite)
      marketing.value.favoritesLoaded = true
      marketing.value.favoritesOffline = false
      fetchedForToken = getBackendToken()
      return true
    }

    /**
     * 本地旧收藏**一次性导入**服务端（规格 §4.5）。
     *
     * 去重键 `platform\0title\0content`（`\0` 在正常文案里不可能出现，无碰撞且排障可读）；
     * 单条失败不中断其余；**有失败就不写标记**，下次再跑（去重键保证已成功的不会重复 POST）。
     * 全部成功才清空本地数组并写入标记 —— 标记同时是「已导入」与「已清空」的见证。
     */
    async function importLocalFavorites(): Promise<{
      total: number
      ok: number
      failed: number
    }> {
      if (!USE_BACKEND) {
        // 演示模式：这条流程**根本不存在**（没有服务端可导）。
        // 因此这里是一个纯 no-op —— 不 capture、不导入、不清空 pending、不写标记、也不动 favoritesLoaded：
        // 演示模式下的「云端列表」其实就是本机缓存自己，一旦拿它当去重集，所有条目都会被判成
        // 「云端已有」而移出 pending —— 未导入却"完成"，切到后端构建后既无 pending 可导、
        // favorites 又被服务端空列表覆盖，数据就没了（R5）。
        return { total: 0, ok: 0, failed: 0 }
      }
      if (marketing.value.favoritesImportedAt) return { total: 0, ok: 0, failed: 0 }

      // 前置条件（R3 的结构性保证）：必须先成功拉到云端列表。
      // 否则去重集合里装的是本机缓存自己 —— 待导入条目会「自己和自己撞键」被全部跳过，
      // 于是没导入任何东西却写了完成标记（静默假成功）。视图层已做同样守卫，这里再兜一层。
      if (!sessionFetched() || marketing.value.favoritesOffline) {
        return { total: 0, ok: 0, failed: 0 }
      }

      const pending = marketing.value.favoritesPendingImport
      if (!pending.length) {
        marketing.value.favoritesImportedAt = nowText()
        return { total: 0, ok: 0, failed: 0 }
      }

      // 去重集合 = 云端缓存里的现有条目（因此本函数只在 loadFavorites 成功后才应被调用）
      const seen = new Set(marketing.value.favorites.map(favoriteKey))
      let ok = 0
      let failed = 0
      const stillPending: FavoriteItem[] = []

      for (const item of pending) {
        const key = favoriteKey(item)
        // 云端已有同内容（先前已导过，或本机重复）：从 pending 移除、不计失败、不再重复 POST
        if (seen.has(key)) continue
        const saved = await saveMarketingFavorite({
          platform: item.platform,
          title: item.title,
          content: item.content,
          reply: item.reply ?? '',
          coverText: item.coverText ?? '',
          tags: item.tags ?? []
        })
        if (saved.ok) {
          ok++
          seen.add(key)
        } else {
          // 逐条失败只计数（不弹提示），失败的**留在 pending**：刷新/下次触发继续重试
          failed++
          stillPending.push(item)
        }
      }

      marketing.value.favoritesPendingImport = stillPending
      if (!stillPending.length) {
        marketing.value.favoritesImportedAt = nowText()
        // 导入后让缓存换成服务端行（拿到服务端 id），而不是把本地数组留在缓存里
        await loadFavorites({ force: true })
      }
      return { total: pending.length, ok, failed }
    }

    /** 归一化服务端返回项：tags 缺失/为 null 一律按 []，reply/coverText 缺失保持缺省（老数据） */
    function normalizeFavorite(raw: MarketingFavoriteItem): FavoriteItem {
      return {
        id: raw.id,
        platform: raw.platform,
        title: raw.title ?? '',
        content: raw.content ?? '',
        reply: raw.reply || undefined,
        coverText: raw.coverText || undefined,
        tags: Array.isArray(raw.tags) ? raw.tags : [],
        createdAt: raw.createdAt ?? ''
      }
    }

    /** 去重键（规格 §4.5 步骤 2）：`platform\0title\0content` */
    function favoriteKey(f: { platform: string; title: string; content: string }): string {
      return `${f.platform}\u0000${f.title}\u0000${f.content}`
    }

    /**
     * 升级迁移：把「改造前直接住在 `favorites` 里的本地旧收藏」搬进 `favoritesPendingImport`。
     *
     * 三个必须满足的前提：
     * 1. 只在**第一次用服务端数据覆盖 `favorites` 之前**调用（loadFavorites 开头）；
     *    持久化插件的状态在 store setup 之后才水合，所以不能在 setup 里做。
     * 2. 只在后端模式做（演示模式下 `favorites` 就是真源，不能搬走）。
     * 3. 已导入完成（有标记）或已有 pending（上次没导完）时都不再迁移，避免重复搬运。
     *
     * 搬进同键字段后即随 `yimai-ai-config` 落盘 → 刷新/重登后仍能继续重试。
     */
    function capturePendingImport(): void {
      if (!USE_BACKEND) return
      if (marketing.value.favoritesImportedAt) return
      if (marketing.value.favoritesPendingImport.length) return
      if (!marketing.value.favorites.length) return
      marketing.value.favoritesPendingImport = [...marketing.value.favorites]
    }

    function nowText(): string {
      const d = new Date()
      const p = (n: number) => String(n).padStart(2, '0')
      return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
    }

    /** 生成调用记录（供配额统计，阶段1由后端接管） */
    const usageLog = ref<
      { time: string; platform: string; source: 'llm' | 'fallback'; chars: number }[]
    >([])
    function logUsage(platform: string, source: 'llm' | 'fallback', chars: number) {
      const d = new Date()
      usageLog.value.unshift({ time: d.toLocaleString('zh-CN'), platform, source, chars })
      if (usageLog.value.length > 200) usageLog.value.pop()
    }

    return {
      config,
      marketing,
      usageLog,
      applyPreset,
      isReady,
      maskKey,
      addFavorite,
      removeFavorite,
      loadFavorites,
      importLocalFavorites,
      logUsage
    }
  },
  {
    persist: {
      key: 'yimai-ai-config',
      storage: localStorage,
      pick: ['marketing', 'usageLog']
    }
  }
)
