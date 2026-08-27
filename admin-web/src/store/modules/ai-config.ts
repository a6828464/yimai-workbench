import { defineStore } from 'pinia'

export interface AiProviderPreset {
  label: string
  baseUrl: string
  models: string[]
}

export const AI_PROVIDER_PRESETS: AiProviderPreset[] = [
  { label: 'DeepSeek（深度求索）', baseUrl: 'https://api.deepseek.com/v1', models: ['deepseek-chat', 'deepseek-reasoner'] },
  { label: '通义千问 Qwen（阿里云）', baseUrl: 'https://dashscope.aliyuncs.com/compatible-mode/v1', models: ['qwen-plus', 'qwen-max', 'qwen-turbo'] },
  { label: 'Kimi / Moonshot（月之暗面）', baseUrl: 'https://api.moonshot.cn/v1', models: ['moonshot-v1-8k', 'moonshot-v1-32k'] },
  { label: '智谱 GLM', baseUrl: 'https://open.bigmodel.cn/api/paas/v4', models: ['glm-4-air', 'glm-4-plus'] },
  { label: '火山方舟 · 豆包', baseUrl: 'https://ark.cn-beijing.volces.com/api/v3', models: ['doubao-pro-32k'] },
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
  tags: string[]
  createdAt: string
}

interface MarketingState {
  nextFavoriteId: number
  favorites: FavoriteItem[]
}

export const useAiConfigStore = defineStore('aiConfigStore', () => {
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
    favorites: []
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
    return config.value.enabled && !!config.value.baseUrl && !!config.value.model && !!config.value.apiKey
  }

  function maskKey(): string {
    const k = config.value.apiKey
    if (k === SERVER_CONFIGURED_PLACEHOLDER) return '已配置（服务端数据库保管）'
    if (!k) return '未配置'
    if (k.length <= 10) return k.slice(0, 2) + '****'
    return `${k.slice(0, 6)}****${k.slice(-4)}`
  }

  function addFavorite(item: Omit<FavoriteItem, 'id' | 'createdAt'>): void {
    const d = new Date()
    const p = (n: number) => String(n).padStart(2, '0')
    marketing.value.favorites.unshift({
      ...item,
      id: marketing.value.nextFavoriteId++,
      createdAt: `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
    })
  }

  function removeFavorite(id: number): void {
    marketing.value.favorites = marketing.value.favorites.filter((f) => f.id !== id)
  }

  /** 生成调用记录（供配额统计，阶段1由后端接管） */
  const usageLog = ref<{ time: string; platform: string; source: 'llm' | 'fallback'; chars: number }[]>([])
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
    logUsage
  }
}, {
  persist: {
    key: 'yimai-ai-config',
    storage: localStorage,
    pick: ['marketing', 'usageLog']
  }
})
