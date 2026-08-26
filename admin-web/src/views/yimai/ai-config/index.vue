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
              <ElSelect v-model="form.model" filterable allow-create default-first-option>
                <ElOption v-for="m in presetModels" :key="m" :label="m" :value="m" />
              </ElSelect>
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
              当前阶段密钥保存在浏览器本地，仅限内部试用；阶段1后端上线后由 Laravel 代理调用，Key 仅存服务器环境变量，前端不再接触。
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
  import { ElMessage } from 'element-plus'

  defineOptions({ name: 'YimaiAiConfig' })

  const aiStore = useAiConfigStore()
  const yimaiStore = useYimaiStore()

  const form = reactive({ ...aiStore.config })
  const saving = ref(false)
  const testing = ref(false)
  const testResult = ref('')

  const presetModels = computed(() => {
    const preset = AI_PROVIDER_PRESETS.find((p) => p.label === form.providerLabel)
    return preset?.models.length ? preset.models : [form.model].filter(Boolean)
  })

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
      const resp = await fetch(`${aiStore.config.baseUrl.replace(/\/$/, '')}/chat/completions`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${aiStore.config.apiKey}` },
        body: JSON.stringify({
          model: aiStore.config.model,
          messages: [{ role: 'user', content: '回复OK' }],
          max_tokens: 5
        })
      })
      if (resp.ok) {
        testResult.value = '测试连接 ✓ 连通正常'
        ElMessage.success('连接成功，模型可用')
      } else {
        const t = await resp.text().catch(() => '')
        testResult.value = `失败 HTTP ${resp.status}`
        ElMessage.error(`连接失败：HTTP ${resp.status} ${t.slice(0, 120)}`)
      }
    } catch (e) {
      testResult.value = '网络错误'
      ElMessage.error(`无法访问接口：${String(e).slice(0, 100)}（可能是浏览器跨域限制，阶段1将由后端代理）`)
    } finally {
      testing.value = false
      setTimeout(() => (testResult.value = ''), 6000)
    }
  }
</script>
