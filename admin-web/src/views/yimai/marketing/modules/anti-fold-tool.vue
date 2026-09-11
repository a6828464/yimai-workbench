<template>
  <ElCard shadow="never" class="mb-4">
    <template #header>
      <div class="flex-cb">
        <span class="font-500">朋友圈防折叠</span>
        <ElTag size="small" type="info">同一条文案换号再发，避免被折叠</ElTag>
      </div>
    </template>
    <div class="text-xs text-g-500 leading-5 mb-2">
      微信会把高度相似的朋友圈内容折叠成「一条相似内容」。把会被折叠的文案粘贴进来，AI
      会改写出一个意思不变、说法不同的版本：开头必换、超6成句子重写、不含连续10字相同。
    </div>
    <ElFormItem label="会被折叠的文案">
      <ElInput
        v-model="source"
        type="textarea"
        :rows="5"
        placeholder="粘贴发朋友圈会被折叠的文案"
      />
    </ElFormItem>
    <ElFormItem label="补充要求（可选）">
      <ElInput v-model="extra" placeholder="例：语气更口语一点 / 突出限时福利 / 换个场景开头" />
    </ElFormItem>
    <div class="flex gap-2">
      <ElButton type="primary" :loading="generating" :disabled="!source.trim()" @click="generate"
        >生成防折叠版本</ElButton
      >
      <ElButton v-if="latestResult?.trim()" @click="source = latestResult"
        >带入上方生成结果</ElButton
      >
    </div>

    <ElAlert
      v-if="warning"
      :title="warning"
      type="warning"
      show-icon
      :closable="false"
      class="mt-3"
    />
    <div v-if="output || generating" v-loading="generating" class="mt-3">
      <div class="flex-cb mb-1">
        <span class="text-xs text-g-500">
          改写结果 · {{ output.length }}字
          <template v-if="maxCommon >= 10">
            · <span class="text-red-500">与原文最长相同片段 {{ maxCommon }} 字，建议重新改写</span>
          </template>
        </span>
        <ElButton link type="primary" size="small" :disabled="!output" @click="copyText(output)"
          >复制</ElButton
        >
      </div>
      <div
        class="rounded-lg border border-gray-200 dark:border-gray-700 p-4 bg-white dark:bg-[#0d0d0d]"
      >
        <p class="text-[15px] leading-[1.8] text-g-800 whitespace-pre-wrap break-all">{{
          output
        }}</p>
      </div>
      <div v-if="notes" class="mt-2 text-xs text-g-500 leading-5">📝 {{ notes }}</div>
      <ElButton v-if="output" text class="mt-1" @click="generate()">重新改写</ElButton>
    </div>
  </ElCard>
</template>

<script setup lang="ts">
  import { generateAntiFoldCopy } from '@/api/ai'
  import { ElMessage } from 'element-plus'

  defineOptions({ name: 'AntiFoldTool' })

  /** 上方朋友圈生成结果，可一键带入 */
  defineProps<{ latestResult?: string }>()

  const source = ref('')
  const extra = ref('')
  const generating = ref(false)
  const output = ref('')
  const notes = ref('')
  const warning = ref('')

  /** 与原文的最长连续相同片段（≥10字大概率触发折叠），O(n·m)，文案长度内可接受 */
  const maxCommon = computed(() => longestCommonSubstring(source.value.trim(), output.value.trim()))

  function longestCommonSubstring(a: string, b: string): number {
    if (!a || !b) return 0
    let prev = new Array<number>(b.length + 1).fill(0)
    let best = 0
    for (let i = 1; i <= a.length; i++) {
      const cur = new Array<number>(b.length + 1).fill(0)
      for (let j = 1; j <= b.length; j++) {
        if (a[i - 1] === b[j - 1]) {
          cur[j] = prev[j - 1] + 1
          if (cur[j] > best) best = cur[j]
        }
      }
      prev = cur
    }
    return best
  }

  async function generate() {
    if (!source.value.trim()) {
      ElMessage.warning('请先粘贴会被折叠的文案')
      return
    }
    generating.value = true
    warning.value = ''
    try {
      const res = await generateAntiFoldCopy({ content: source.value, extra: extra.value })
      if (!res.content) {
        warning.value = res.warning ?? '改写失败，请重试'
        return
      }
      output.value = res.content
      notes.value = res.notes
      if (res.warning) warning.value = res.warning
    } finally {
      generating.value = false
    }
  }

  async function copyText(text: string) {
    try {
      await navigator.clipboard.writeText(text)
      ElMessage.success('已复制，可直接粘贴发圈')
    } catch {
      ElMessage.warning('复制失败，请手动选择复制')
    }
  }
</script>
