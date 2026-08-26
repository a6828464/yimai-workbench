<template>
  <div class="p-4">
    <ElRow :gutter="16">
      <ElCol :xs="24" :lg="14" class="mb-4">
        <ElCard shadow="never">
          <template #header>
            <div class="flex-cb">
              <span class="font-500">大模型接入配置</span>
              <ElTag :type="aiStore.isReady() ? 'success' : 'info'" size="small">
                {{ aiStore.isReady() ? '已启用' : '未启用' }}
              </ElTag>
            </div>
          </template>

          <ElForm label-width="96px">
            <ElFormItem label="启用AI生成">
              <ElSwitch v-model="form.enabled" />
              <span class="ml-3 text-xs text-gray-400">关闭后全店营销工具使用本地模板草稿</span>
            </ElFormItem>
            <ElFormItem label="服务商">
              <ElSelect v-model="form.providerLabel" @change="(v: string) => aiStore.applyPreset(v)">
                <ElOption v-for="p in AI_PROVIDER_PRESETS" :key="p.label" :label="p.label" :value="p.label" />
              </ElSelect>
            </ElFormItem>
            <ElFormItem label="接口地址">
              <ElInput v-model="form.baseUrl" placeholder="https://...（OpenAI兼容 /v1）" />
            </ElFormItem>
            <ElFormItem label="API Key">
              <ElInput v-model="form.apiKey" type="password" show-password placeholder="sk-..." />
            </ElFormItem>
            <ElFormItem label="模型">
              <div class="flex gap-2 w-full">
                <ElSelect v-model="form.model" filterable allow-create default-first-option class="flex-1">
                  <ElOption v-for="m in modelOptions" :key="m" :label="m" :value="m" />
                </ElSelect>
                <ElButton :loading="loadingModels" @click="loadModels">获取模型</ElButton>
              </div>
            </ElFormItem>
            <ElFormItem label="温度">
              <ElSlider v-model="form.temperature" :min="0" :max="1.5" :step="0.1" show-input class="!w-full !pr-2" />
            </ElFormItem>

            <ElAlert
              title="安全提示"
              type="warning"
              show-icon
              :closable="false"
              class="mb-4"
            >
              大模型调用经 Laravel 后端代理转发（规避浏览器跨域）。密钥当前保存在本地浏览器，仅内部使用；可随时在此更换服务商与模型。
            </ElAlert>

            <div class="flex gap-2">
              <ElButton type="primary" :loading="saving" @click="save">保存配置</ElButton>
              <ElButton :loading="testing" @click="testConnection">{{ testResult || '测试连接' }}</ElButton>
            </div>
          </ElForm>
        </ElCard>
      </ElCol>

      <ElCol :xs="24" :lg="10" class="mb-4">
        <ElCard shadow="never" class="mb-4">
          <template #header><span class="font-500">最近生成记录（本地）</span></template>
          <div v-if="aiStore.usageLog.length">
            <div v-for="(u, i) in aiStore.usageLog.slice(0, 8)" :key="i" class="flex-cb py-2 border-b border-gray-100 last:border-0 dark:border-gray-800 text-xs">
              <span>{{ u.time }}</span>
              <span>{{ u.platform }}</span>
              <ElTag size="small" :type="u.source === 'llm' ? 'success' : 'warning'">{{ u.source === 'llm' ? 'API' : '模板' }}</ElTag>
              <span class="text-gray-400">{{ u.chars }}字</span>
            </div>
          </div>
          <ElEmpty v-else description="暂无生成记录" :image-size="60" />
        </ElCard>

        <ElCard shadow="never">
          <template #header><span class="font-500">接入说明</span></template>
          <div class="text-xs leading-6 text-gray-500 dark:text-gray-400">
            · 使用 OpenAI 兼容协议（/chat/completions），DeepSeek / 通义千问 / Kimi / 智谱 / 豆包 均可直接接入<br />
            · 朋友圈按「人设+分类主题+语气目标+表达方式」编排提示词<br />
            · 小红书要求模型输出结构化 JSON（标题/正文/话题标签）<br />
            · 所有生成内容需人工确认后发布，系统不自动对外发送<br />
            · 每次生成自动写入操作留痕，可在「操作留痕」溯源
          </div>
        </ElCard>
      </ElCol>
    </ElRow>
  </div>
</template>

<script setup lang="ts">
  import { AI_PROVIDER_PRESETS, useAiConfigStore } from '@/store/modules/ai-config'
  import { useYimaiStore } from '@/store/modules/yimai'
  import { fetchAvailableModels } from '@/api/ai'
  import { USE_BACKEND, apiPost } from '@/api/backend'
  import { ElMessage } from 'element-plus'

  defineOptions({ name: 'YimaiAiConfig' })

  const aiStore = useAiConfigStore()
  const yimaiStore = useYimaiStore()

  const form = reactive({ ...aiStore.config })
  const saving = ref(false)
  const testing = ref(false)
  const testResult = ref('')
  const loadingModels = ref(false)
  const fetchedModels = ref<string[]>([])

  const presetModels = computed(() => {
    const preset = AI_PROVIDER_PRESETS.find((p) => p.label === form.providerLabel)
    return preset?.models.length ? preset.models : [form.model].filter(Boolean)
  })

  /** 模型下拉选项：优先展示从服务商拉取的真实列表 */
  const modelOptions = computed(() => (fetchedModels.value.length ? fetchedModels.value : presetModels.value))

  async function loadModels() {
    if (!form.baseUrl || !form.apiKey) {
      ElMessage.warning('请先填写接口地址和 API Key')
      return
    }
    loadingModels.value = true
    try {
      const models = await fetchAvailableModels(form.baseUrl, form.apiKey)
      fetchedModels.value = models
      ElMessage.success(`已获取 ${models.length} 个模型`)
      if (models.length && !models.includes(form.model)) form.model = models[0]
    } catch (e) {
      ElMessage.error(`获取模型失败：${extractErrMsg(e)}`)
    } finally {
      loadingModels.value = false
    }
  }

  function extractErrMsg(e: unknown): string {
    const resp = (e as { response?: { data?: { message?: string } } })?.response?.data
    if (resp?.message) return String(resp.message).slice(0, 160)
    const data = (e as { code?: number; message?: string }).message
    if (data) return String(data).slice(0, 160)
    return String(e).slice(0, 120)
  }

  async function save() {
    saving.value = true
    try {
      Object.assign(aiStore.config, form)
      yimaiStore.writeAudit(
        '修改',
        '模型配置',
        0,
        `大模型接入（${aiStore.config.providerLabel}）`,
        '双店',
        `启用[${aiStore.config.enabled ? '是' : '否'}] 模型[${aiStore.config.model}] Key[${aiStore.maskKey()}] 接口[${aiStore.config.baseUrl}]`
      )
      ElMessage.success('配置已保存')
    } finally {
      saving.value = false
    }
  }

  async function testConnection() {
    testing.value = true
    testResult.value = '测试中...'
    try {
      Object.assign(aiStore.config, form)
      if (!USE_BACKEND) {
        // 演示模式：浏览器直连（仅对支持CORS的服务商可用）
        const resp = await fetch(`${aiStore.config.baseUrl.replace(/\/$/, '')}/chat/completions`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${aiStore.config.apiKey}` },
          body: JSON.stringify({
            model: aiStore.config.model,
            messages: [{ role: 'user', content: '回复OK' }],
            max_tokens: 512
          })
        })
        if (!resp.ok) {
          const t = await resp.text().catch(() => '')
          throw new Error(`HTTP ${resp.status} ${t.slice(0, 200)}`)
        }
      } else {
        // 后端代理：max_tokens 给足余量，避免部分模型因 max_tokens 过小直接 400
        const r = await apiPost<{ code?: number; message?: string; content?: string }>('/ai/chat', {
          baseUrl: aiStore.config.baseUrl,
          apiKey: aiStore.config.apiKey,
          model: aiStore.config.model,
          messages: [{ role: 'user', content: '回复OK' }],
          temperature: 0.1,
          maxTokens: 512
        })
        // 后端失败时 HTTP 200 + code:1，需显式判断
        if (r && r.code !== undefined && r.code !== 0) {
          throw new Error(r.message || 'AI_ERROR')
        }
        if (!r?.content) throw new Error('响应缺少内容')
      }
      testResult.value = '测试连接 ✓ 连通正常'
      ElMessage.success('连接成功，模型可用')
    } catch (e) {
      testResult.value = '连接失败'
      ElMessage.error(`连接失败：${extractErrMsg(e)}${USE_BACKEND ? '' : '（演示模式受浏览器跨域限制，请启用后端模式）'}`)
    } finally {
      testing.value = false
      setTimeout(() => (testResult.value = ''), 6000)
    }
  }
</script>
