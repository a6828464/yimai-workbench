<template>
  <ElCard shadow="never" class="mb-4">
    <template #header>
      <div class="mk-card-head">
        <span class="font-500">朋友圈防折叠</span>
        <ElTag size="small" type="info">同一条文案换号再发，避免被折叠</ElTag>
      </div>
    </template>
    <div class="text-xs text-g-500 leading-5 mb-2">
      微信会把高度相似的朋友圈内容折叠成「一条相似内容」。把会被折叠的文案粘贴进来，AI
      会按选定版本数各改写一遍：{{ thresholdText }}。每个版本都会逐项校验。
    </div>
    <ElFormItem label="会被折叠的文案" :label-position="formLabelPosition">
      <ElInput
        v-model="source"
        type="textarea"
        :rows="5"
        placeholder="粘贴发朋友圈会被折叠的文案"
      />
    </ElFormItem>
    <ElFormItem label="补充要求（可选）" :label-position="formLabelPosition">
      <ElInput v-model="extra" placeholder="例：语气更口语一点 / 突出限时福利 / 换个场景开头" />
    </ElFormItem>
    <ElFormItem :label-position="formLabelPosition" label="一次生成版本数">
      <ElRadioGroup v-model="versions">
        <ElRadioButton v-for="n in versionOptions" :key="n" :value="n">{{ n }} 版</ElRadioButton>
      </ElRadioGroup>
      <div class="text-xs text-g-500 mt-1 leading-5">
        一次产出多版方便挑一版发；版本之间也会互相校验（不得出现连续
        {{ ANTI_FOLD_THRESHOLDS.maxCommonSubstring }} 字相同）。上限
        {{ ANTI_FOLD_MAX_VERSIONS }} 版。
      </div>
    </ElFormItem>
    <div class="mk-btn-row">
      <ElButton type="primary" :loading="generating" :disabled="!source.trim()" @click="generate"
        >生成 {{ versions }} 个防折叠版本</ElButton
      >
      <ElButton v-if="latestResult?.trim()" @click="source = latestResult">
        带入上方生成结果
      </ElButton>
    </div>

    <ElAlert
      v-if="warning"
      :title="warning"
      type="warning"
      show-icon
      :closable="false"
      class="mt-3"
    />
    <div v-if="variants.length || generating" v-loading="generating" class="mt-3">
      <div class="flex-cb flex-wrap gap-2 mb-2">
        <span class="text-xs text-g-500">共 {{ variants.length }} 个版本 · 逐版质检如下</span>
        <div class="flex-c gap-2 flex-wrap">
          <ElButton v-if="promptMessages.length" link size="small" @click="promptVisible = true">
            查看本次提示词
          </ElButton>
          <ElButton link size="small" @click="generate()">重新改写</ElButton>
        </div>
      </div>

      <div class="mk-result-list">
        <div v-for="(v, i) in variants" :key="v.id" class="mk-result-card">
          <div class="mk-result-card__head">
            <span class="mk-result-card__title">{{ v.label }}</span>
            <!-- 三态：有质检项才谈通过与否，没有就如实说没有 -->
            <template v-if="v.checks.length">
              <span v-if="v.passed" class="mk-result-card__ok">全部通过</span>
              <span v-else class="mk-result-card__warn">{{ failCount(v) }} 项不通过</span>
            </template>
            <span v-else class="mk-result-card__none">无质检数据</span>
          </div>

          <!-- 逐版质检数据（api 层 auditAntiFold + auditVariantDiversity；遍历渲染，不写死 4 项） -->
          <div v-if="v.checks.length" class="mk-check-list">
            <div
              v-for="c in v.checks"
              :key="c.label"
              class="mk-check-item"
              :class="{ 'is-fail': !c.passed }"
            >
              <span class="mk-check-item__label">{{ c.label }}</span>
              <span class="mk-check-item__detail">{{ c.detail || c.status }}</span>
            </div>
          </div>
          <div v-if="v.warnings.length" class="mk-warn-list">
            <div v-for="(w, wi) in v.warnings" :key="wi">⚠️ {{ w }}</div>
          </div>

          <div class="mk-moment-preview mt-2">
            <div v-if="editingId === v.id">
              <ElInput v-model="editingText" type="textarea" :rows="8" resize="none" />
              <div class="mk-btn-row mt-2">
                <ElButton size="small" @click="cancelEdit">取消</ElButton>
                <ElButton size="small" type="primary" @click="applyEdit(v, i)"
                  >保存并重算质检</ElButton
                >
              </div>
            </div>
            <p v-else class="mk-moment-preview__body">{{ v.content }}</p>
          </div>

          <div v-if="v.notes" class="mt-2 text-xs text-g-500 leading-5">📝 {{ v.notes }}</div>

          <div class="mk-btn-row mt-3">
            <ElButton type="primary" plain :disabled="!v.content" @click="copyText(v.content)"
              >复制这版</ElButton
            >
            <ElButton plain :disabled="!v.content" @click="startEdit(v)">编辑这版</ElButton>
            <ElButton text :disabled="!v.content" @click="applyToSource(v.content)"
              >作为原文再改</ElButton
            >
          </div>
        </div>
      </div>
    </div>

    <!-- 查看本次提示词（api 层返回的 prompt 原文，只读） -->
    <ElDrawer v-model="promptVisible" title="本次下发的提示词（首轮）" size="520px">
      <ElAlert
        title="这里展示首轮提示词原文；若「版本间差异」不达标，引擎还会追加一轮定向修复（含共享片段的修复指令），那一轮的提示词不在本次展示范围内。"
        type="info"
        show-icon
        :closable="false"
        class="mb-3"
      />
      <div v-for="(m, i) in promptMessages" :key="i" class="mk-prompt-block">
        <div class="mk-prompt-block__role">{{ m.role }}</div>
        <pre class="mk-prompt-block__body">{{ m.content }}</pre>
        <ElButton link type="primary" size="small" @click="copyText(m.content)">复制这段</ElButton>
      </div>
    </ElDrawer>
  </ElCard>
</template>

<script setup lang="ts">
  import {
    ANTI_FOLD_DEFAULT_VERSIONS,
    ANTI_FOLD_MAX_VERSIONS,
    ANTI_FOLD_THRESHOLDS,
    antiFoldThresholdText,
    auditAntiFold,
    auditVariantDiversity,
    generateAntiFoldCopy
  } from './marketing-api-bridge'
  import type { AntiFoldInput, AntiFoldVariant, PromptLayer } from './marketing-api-bridge'
  import { ElMessage } from 'element-plus'
  import { useDevice } from '@/hooks/core/useDevice'

  defineOptions({ name: 'AntiFoldTool' })

  /** 上方朋友圈生成结果，可一键带入 */
  defineProps<{ latestResult?: string }>()

  const { isHandheld } = useDevice()

  /**
   * 表单 label 位置：手持端（≤768）改到控件上方。
   *
   * 这几个 ElFormItem 没有外层 ElForm，所以只能用 form-item 自己的 label-position
   * （EP 的 form-item 支持该 prop，且无 form 时不会继承任何值）。
   * label 在左会把控件压到 48px 量级（label 68px + gap），
   * 改成 top 后控件拿回整行宽度。桌面端保持原有的 left/默认，断点与 handheld-only 一致。
   */
  const formLabelPosition = computed<'top' | undefined>(() =>
    isHandheld.value ? 'top' : undefined
  )

  const source = ref('')
  const extra = ref('')
  const generating = ref(false)
  const variants = ref<AntiFoldVariant[]>([])
  const warning = ref('')
  const promptMessages = ref<PromptLayer[]>([])
  const promptVisible = ref(false)

  /** 说明文案由 api 层阈值常量插值（改阈值时文案自动跟随，视图不重写数值） */
  const thresholdText = antiFoldThresholdText()

  /** 版本数：默认与上限都取 api 层常量 */
  const versionOptions = Array.from({ length: ANTI_FOLD_MAX_VERSIONS }, (_, i) => i + 1)
  const versions = ref<number>(ANTI_FOLD_DEFAULT_VERSIONS)

  function failCount(v: AntiFoldVariant): number {
    return v.checks.filter((c) => !c.passed).length
  }

  async function generate() {
    if (!source.value.trim()) {
      ElMessage.warning('请先粘贴会被折叠的文案')
      return
    }
    generating.value = true
    warning.value = ''
    try {
      const input: AntiFoldInput = {
        content: source.value,
        extra: extra.value,
        versions: versions.value
      }
      const res = await generateAntiFoldCopy(input)
      if (!res.variants.length) {
        warning.value = res.warning || '改写失败，请重试'
        variants.value = []
        return
      }
      variants.value = res.variants
      promptMessages.value = res.prompt
      if (res.warning) warning.value = res.warning
    } finally {
      generating.value = false
    }
  }

  /**
   * 手改某一版后重算质检：走 api 层纯函数（不再跑一次大模型）。
   * 除了该版的四项（首句/相同句占比/最长相同片段/篇幅偏差）之外，
   * 还要把「版本间差异」按改动后的全部版本重算一遍，否则那一项会停留在旧结论。
   * 单版本时 api 层的版本间差异恒为通过，属预期（只有一版时无对照可比）。
   */
  function auditOne(index: number) {
    const v = variants.value[index]
    if (!v) return
    const res = auditAntiFold(source.value, v.content)
    if (res) {
      v.checks = res.checks
      v.passed = res.passed
      v.warnings = res.warnings
    }
    // 版本间差异按同一批次的下标对齐（api 层按入参顺序返回）
    const diversity = auditVariantDiversity(variants.value.map((x) => x.content))
    if (diversity[index]) {
      const merged = [...v.checks.filter((c) => c.label !== '版本间差异'), diversity[index]]
      v.checks = merged
      v.passed = merged.every((c) => c.passed)
      v.warnings = merged.filter((c) => !c.passed).map((c) => `[${c.label}] ${c.detail}`)
    }
  }

  const editingId = ref('')
  const editingText = ref('')

  function startEdit(v: AntiFoldVariant) {
    editingId.value = v.id
    editingText.value = v.content
  }

  function cancelEdit() {
    editingId.value = ''
    editingText.value = ''
  }

  function applyEdit(v: AntiFoldVariant, index: number) {
    v.content = editingText.value
    cancelEdit()
    auditOne(index)
    ElMessage.success('已保存并重算质检')
  }

  function applyToSource(text: string) {
    source.value = text
    ElMessage.info('已作为新的原文，可再次改写')
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
