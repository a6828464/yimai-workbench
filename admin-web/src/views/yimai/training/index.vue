<template>
  <div class="p-4">
    <ElAlert
      title="计划由AI生成草稿，必须有权限的老师确认后才可分享给会员；高风险情况需先经医疗专业评估"
      type="info"
      show-icon
      :closable="false"
      class="mb-4"
    />

    <ElCard shadow="never">
      <template #header>
        <div class="flex-cb">
          <span class="font-500">会员训练计划</span>
          <ElButton type="primary" v-ripple @click="openCreate">新建计划</ElButton>
        </div>
      </template>

      <ElTable :data="plans" border stripe v-loading="loading">
        <ElTableColumn label="会员 / 目标" min-width="180">
          <template #default="{ row }">
            <div class="font-500">{{ row.memberName }}</div>
            <div class="text-xs text-gray-400">{{ row.coreGoal }}</div>
          </template>
        </ElTableColumn>
        <ElTableColumn prop="freq" label="频率" width="120" />
        <ElTableColumn label="阶段" width="90">
          <template #default="{ row }">{{ row.stageWeeks }}周</template>
        </ElTableColumn>
        <ElTableColumn prop="risks" label="风险提示" min-width="130" show-overflow-tooltip>
          <template #default="{ row }">
            <span :class="detectHighRisk(row.risks) ? 'font-500 text-red-500' : 'text-gray-400'">
              {{ row.risks || '无' }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn label="状态" width="110">
          <template #default="{ row }">
            <ElTag size="small" :type="statusType(row.status)" effect="dark">{{ row.status }}</ElTag>
            <ElTag v-if="row.status !== '待生成'" size="small" effect="plain" class="ml-1">
              {{ row.source === 'llm' ? 'AI' : '模板' }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn prop="createdBy" label="创建人" width="85" />
        <ElTableColumn label="操作" width="200" fixed="right">
          <template #default="{ row }">
            <ElButton link type="primary" size="small" @click="openDetail(row)">查看</ElButton>
            <ElButton v-if="row.status === '待生成'" link type="warning" size="small" @click="openEdit(row)">继续录入</ElButton>
            <ElButton v-if="row.status === '待老师确认'" link type="success" size="small" @click="confirm(row)">确认计划</ElButton>
            <ElButton v-if="row.status === '已确认'" link type="primary" size="small" @click="openDetail(row)">分享/图片</ElButton>
            <ElButton link type="danger" size="small" @click="removePlan(row)">删除</ElButton>
          </template>
        </ElTableColumn>
      </ElTable>
    </ElCard>

    <!-- 录入/编辑弹窗 -->
    <ElDialog v-model="dialog.visible" title="会员训练计划 · 情况录入" width="680px" destroy-on-close>
      <ElForm :model="form" label-width="92px">
        <div class="text-xs text-gray-400 mb-2">① 会员情况</div>
        <ElRow :gutter="12">
          <ElCol :xs="12" :md="8"><ElFormItem label="姓名" required><ElInput v-model="form.memberName" /></ElFormItem></ElCol>
          <ElCol :xs="8" :md="4"><ElFormItem label="年龄"><ElInput v-model="form.age" /></ElFormItem></ElCol>
          <ElCol :xs="8" :md="4"><ElFormItem label="性别"><ElSelect v-model="form.gender"><ElOption label="女" value="女" /><ElOption label="男" value="男" /></ElSelect></ElFormItem></ElCol>
          <ElCol :xs="8" :md="4"><ElFormItem label="身高cm"><ElInput v-model="form.height" /></ElFormItem></ElCol>
          <ElCol :xs="8" :md="4"><ElFormItem label="体重kg"><ElInput v-model="form.weight" /></ElFormItem></ElCol>
        </ElRow>
        <ElRow :gutter="12">
          <ElCol :xs="12" :md="8"><ElFormItem label="体脂率%"><ElInput v-model="form.bodyFat" /></ElFormItem></ElCol>
          <ElCol :xs="12" :md="16"><ElFormItem label="关注要点"><ElInput v-model="form.focus" placeholder="如：肩颈紧张、核心无力、产后腹直肌分离" /></ElFormItem></ElCol>
        </ElRow>

        <div class="text-xs text-gray-400 mb-2 mt-3">② 目标与阶段</div>
        <ElRow :gutter="12">
          <ElCol :xs="24" :md="14"><ElFormItem label="核心目标" required><ElInput v-model="form.coreGoal" placeholder="如：改善骨盆前倾" /></ElFormItem></ElCol>
          <ElCol :xs="12" :md="5"><ElFormItem label="频率"><ElInput v-model="form.freq" placeholder="每周2-3次" /></ElFormItem></ElCol>
          <ElCol :xs="12" :md="5"><ElFormItem label="周期(周)"><ElInput v-model="form.stageWeeks" /></ElFormItem></ElCol>
          <ElCol :span="24"><ElFormItem label="阶段目标"><ElInput v-model="form.stageGoal" maxlength="60" show-word-limit /></ElFormItem></ElCol>
          <ElCol :span="24">
            <ElFormItem label="当前风险">
              <ElInput v-model="form.risks" placeholder="无特殊风险 / 如：孕产、术后、腰痛等（将触发转介提醒）" />
            </ElFormItem>
          </ElCol>
        </ElRow>

        <ElAlert
          v-if="highRisk"
          title="检测到高风险关键词：此类情况不适用常规训练模板，请确保已由医疗专业人员评估，必要时直接转介。"
          type="error"
          show-icon
          :closable="false"
          class="mb-2"
        />
        <ElCheckbox v-if="highRisk" v-model="form.riskAck">
          我已知悉上述风险属于医疗评估范畴，本计划仅作为安全范围内的练习参考
        </ElCheckbox>
      </ElForm>

      <!-- 生成结果预览 -->
      <template v-if="draftContent">
        <div class="text-xs text-gray-400 mt-4 mb-2">③ 训练计划草稿（{{ genSource === 'llm' ? '大模型生成' : '本地模板' }} · 需老师确认）</div>
        <ElAlert v-if="genWarning" :title="genWarning" type="warning" show-icon :closable="false" class="mb-2" />
        <div class="plan-preview">
          <p class="font-500 mb-2">{{ draftContent.summary }}</p>
          <div v-for="(ph, i) in draftContent.phases" :key="i" class="mb-2">
            <div class="text-sm font-500">{{ ph.name }}（{{ ph.duration }}）</div>
            <ul class="list-disc pl-5 text-sm leading-6 text-gray-600 dark:text-gray-300">
              <li v-for="it in ph.items" :key="it">{{ it }}</li>
            </ul>
          </div>
          <div class="text-sm font-500 mb-1">注意事项</div>
          <ul class="list-disc pl-5 text-sm leading-6 text-orange-600 dark:text-orange-400">
            <li v-for="ct in draftContent.cautions" :key="ct">{{ ct }}</li>
          </ul>
        </div>
      </template>

      <template #footer>
        <ElButton @click="dialog.visible = false">取消</ElButton>
        <ElButton plain :loading="dialog.savingDraft" :disabled="!form.memberName || !form.coreGoal" @click="saveOnly">保存信息</ElButton>
        <ElButton
          type="primary"
          :loading="dialog.generating"
          :disabled="!canGenerate"
          @click="generate"
        >
          AI 生成草稿
        </ElButton>
      </template>
    </ElDialog>

    <!-- 详情抽屉 -->
    <ElDrawer v-model="detail.visible" size="480px" :title="detail.row ? `训练计划 #${detail.row.id} · ${detail.row.memberName}` : ''">
      <template v-if="detail.row && detail.row.content">
        <ElTag size="small" effect="dark" :type="statusType(detail.row.status)" class="mb-3">
          {{ detail.row.status }} · {{ detail.row.source === 'llm' ? 'AI生成' : '本地模板' }}
        </ElTag>
        <p class="text-sm leading-6 mb-3">{{ detail.row.content.summary }}</p>
        <div v-for="(ph, i) in detail.row.content.phases" :key="i" class="mb-3">
          <div class="text-sm font-600">{{ ph.name }}（{{ ph.duration }}）</div>
          <ul class="list-disc pl-5 text-sm leading-6 text-gray-600 dark:text-gray-300">
            <li v-for="it in ph.items" :key="it">{{ it }}</li>
          </ul>
        </div>
        <div class="text-sm font-600 mb-1">注意事项</div>
        <ul class="list-disc pl-5 text-sm leading-6 text-orange-600 dark:text-orange-400">
          <li v-for="ct in detail.row.content.cautions" :key="ct">{{ ct }}</li>
        </ul>
        <div class="mt-4 flex gap-2">
          <ElButton type="primary" plain @click="copyPlan">复制全文发会员</ElButton>
          <span v-if="detail.row.confirmedAt" class="text-xs text-gray-400 self-center">确认于 {{ detail.row.confirmedAt }} · {{ detail.row.createdBy }}</span>
        </div>

        <!-- 图片管理（对比照） -->
        <div class="mt-5 pt-4 border-t border-gray-100 dark:border-gray-700">
          <div class="text-sm font-600 mb-2">对比照 / 建档照（分享页展示）</div>
          <div class="flex flex-wrap gap-2 mb-2">
            <div v-for="img in detail.row.images" :key="img.id" class="relative group w-24 h-24 rounded-lg overflow-hidden border border-gray-200 dark:border-gray-600">
              <img :src="img.url" class="w-full h-full object-cover" />
              <span class="absolute bottom-0 inset-x-0 bg-black/50 text-white text-xs text-center py-0.5">{{ img.label }}</span>
              <button
                class="absolute top-1 right-1 hidden group-hover:flex w-5 h-5 items-center justify-center rounded-full bg-red-500 text-white text-xs"
                @click="trainingStore.removePlanImage(detail.row!.id, img.id)"
              >×</button>
            </div>
            <label
              v-if="detail.row.images.length < 8"
              class="w-24 h-24 flex-col-c cursor-pointer rounded-lg border border-dashed border-gray-300 dark:border-gray-500 text-gray-400 hover:border-[var(--main-color)]"
            >
              <span class="text-xl leading-none mb-1">+</span>
              <span class="text-xs">上传照片</span>
              <input type="file" accept="image/*" class="hidden" @change="onPickImage($event)" />
            </label>
          </div>
          <p class="text-xs text-gray-400">支持 jpg/png，自动压缩至720px宽；建议颈部以下体态照，须取得会员授权</p>
        </div>

        <!-- H5 分享 -->
        <div v-if="detail.row.status === '已确认'" class="mt-5 pt-4 border-t border-gray-100 dark:border-gray-700">
          <div class="text-sm font-600 mb-2">H5 分享页</div>
          <div class="flex flex-wrap items-center gap-3">
            <ElSwitch
              :model-value="detail.row.share.enabled"
              active-text="开启"
              inactive-text="停用"
              @change="(v: string | number | boolean) => trainingStore.setPlanShare(detail.row!.id, Boolean(v))"
            />
            <template v-if="detail.row.share.enabled">
              <code class="text-xs bg-gray-100 dark:bg-gray-800 px-2 py-1 rounded">{{ shareUrl(detail.row) }}</code>
              <ElButton size="small" type="primary" plain @click="copyShare(shareUrl(detail.row))">复制链接</ElButton>
              <ElButton size="small" plain @click="openSharePreview(detail.row)">预览</ElButton>
              <span class="text-xs text-gray-400">访问 {{ detail.row.share.views }}</span>
            </template>
            <span v-else class="text-xs text-gray-400">开启后生成会员可打开的排版H5链接</span>
          </div>
        </div>
      </template>
      <ElEmpty v-else-if="detail.row" description="草稿尚未生成" />
    </ElDrawer>
  </div>
</template>

<script setup lang="ts">
  import { useTrainingStore } from '@/store/modules/training'
  import type { TrainingPlan, PlanContent } from '@/store/modules/training'
  import { generateTrainingPlan, detectHighRisk } from '@/api/ai'
  import { loadTrainingPlansCloud, syncTrainingPlansCloud } from '@/api/yimai'
  import { USE_BACKEND } from '@/api/backend'
  import { useUserStore } from '@/store/modules/user'
  import { ElMessage, ElTag } from 'element-plus'

  defineOptions({ name: 'YimaiTraining' })

  const trainingStore = useTrainingStore()
  const plans = computed(() => trainingStore.state.plans)
  const loading = ref(false)

  // ---------- 云端同步（后端模式：进入加载远端，变更防抖回写） ----------
  let cloudReady = false
  let syncing = false
  let pendingSync: ReturnType<typeof setTimeout> | null = null

  onMounted(async () => {
    const userId = String(useUserStore().getUserInfo.userId ?? '')
    trainingStore.loadForUser(userId)
    if (!USE_BACKEND) {
      cloudReady = true
      return
    }
    try {
      const remote = await loadTrainingPlansCloud()
      if (trainingStore.loadedUserId === userId) {
        trainingStore.replacePlans((remote ?? []) as unknown as TrainingPlan[])
      }
    } catch (e) {
      ElMessage.warning(`云端训练计划加载失败：${String(e).slice(0, 80)}`)
    } finally {
      cloudReady = true
    }
  })

  watch(
    plans,
    () => {
      const userId = trainingStore.loadedUserId
      if (!USE_BACKEND || !cloudReady || syncing || !userId) return
      if (pendingSync) clearTimeout(pendingSync)
      pendingSync = setTimeout(async () => {
        if (trainingStore.loadedUserId !== userId) return
        syncing = true
        try {
          await syncTrainingPlansCloud(JSON.parse(JSON.stringify(plans.value)))
        } catch {
          /* 静默重试交给下一次变更 */
        } finally {
          syncing = false
        }
      }, 800)
    },
    { deep: true }
  )

  onBeforeUnmount(() => {
    cloudReady = false
    if (pendingSync) clearTimeout(pendingSync)
  })

  function emptyForm() {
    return {
      id: 0,
      memberName: '',
      age: '',
      gender: '女' as '女' | '男',
      height: '',
      weight: '',
      bodyFat: '',
      focus: '',
      coreGoal: '',
      freq: '每周2-3次',
      stageWeeks: '4',
      stageGoal: '',
      risks: '',
      riskAck: false
    }
  }

  const dialog = reactive({ visible: false, savingDraft: false, generating: false })
  const form = reactive(emptyForm())
  const draftContent = ref<PlanContent | null>(null)
  const genSource = ref<'llm' | 'fallback'>('fallback')
  const genWarning = ref('')
  const currentId = ref(0)

  const highRisk = computed(() => detectHighRisk(form.risks))
  const canGenerate = computed(() => Boolean(form.memberName && form.coreGoal) && (!highRisk.value || form.riskAck))

  const detail = reactive<{ visible: boolean; row: TrainingPlan | null }>({ visible: false, row: null })

  function openCreate() {
    Object.assign(form, emptyForm())
    draftContent.value = null
    genWarning.value = ''
    currentId.value = 0
    dialog.visible = true
  }

  function openEdit(row: TrainingPlan) {
    Object.assign(form, {
      id: row.id,
      memberName: row.memberName,
      age: row.age,
      gender: row.gender,
      height: row.height,
      weight: row.weight,
      bodyFat: row.bodyFat,
      focus: row.focus,
      coreGoal: row.coreGoal,
      freq: row.freq,
      stageWeeks: row.stageWeeks,
      stageGoal: row.stageGoal,
      risks: row.risks,
      riskAck: false
    })
    draftContent.value = row.content
    genSource.value = (row.source as 'llm' | 'fallback') || 'fallback'
    currentId.value = row.id
    dialog.visible = true
  }

  function payload() {
    return {
      id: currentId.value || undefined,
      memberName: form.memberName.trim(),
      age: form.age,
      gender: form.gender,
      height: form.height,
      weight: form.weight,
      bodyFat: form.bodyFat,
      focus: form.focus,
      coreGoal: form.coreGoal.trim(),
      freq: form.freq,
      stageWeeks: form.stageWeeks,
      stageGoal: form.stageGoal,
      risks: form.risks || '无特殊风险'
    }
  }

  async function saveOnly() {
    dialog.savingDraft = true
    try {
      currentId.value = trainingStore.saveDraft(payload())
      ElMessage.success('会员情况已保存')
      dialog.visible = false
    } finally {
      dialog.savingDraft = false
    }
  }

  async function generate() {
    dialog.generating = true
    genWarning.value = ''
    try {
      const id = trainingStore.saveDraft(payload())
      currentId.value = id
      const res = await generateTrainingPlan({
        memberName: form.memberName,
        age: form.age,
        gender: form.gender,
        height: form.height,
        weight: form.weight,
        bodyFat: form.bodyFat,
        focus: form.focus,
        coreGoal: form.coreGoal,
        freq: form.freq,
        stageWeeks: form.stageWeeks,
        stageGoal: form.stageGoal,
        risks: form.risks
      })
      draftContent.value = res.content as PlanContent
      genSource.value = res.source
      genWarning.value = res.warning ?? ''
      trainingStore.attachContent(id, res.content as PlanContent, res.source)
    } finally {
      dialog.generating = false
    }
  }

  function confirm(row: TrainingPlan) {
    trainingStore.confirmPlan(row.id)
    ElMessage.success('计划已确认生效')
  }

  function removePlan(row: TrainingPlan) {
    trainingStore.removePlan(row.id)
    ElMessage.success('已删除')
  }

  function openDetail(row: TrainingPlan) {
    detail.row = row
    detail.visible = true
  }

  async function copyPlan() {
    const r = detail.row
    if (!r?.content) return
    const text = [
      `${r.memberName} 的训练计划`,
      r.content.summary,
      '',
      ...r.content.phases.flatMap((p) => [`【${p.name} · ${p.duration}】`, ...p.items.map((i) => `- ${i}`), '']),
      '注意事项：',
      ...r.content.cautions.map((c) => `- ${c}`)
    ].join('\n')
    try {
      await navigator.clipboard.writeText(text)
      ElMessage.success('全文已复制，可粘贴发送给会员')
    } catch {
      ElMessage.warning('复制失败，请手动选择复制')
    }
  }

  function statusType(s: string): 'info' | 'warning' | 'success' {
    if (s === '待生成') return 'info'
    if (s === '待老师确认') return 'warning'
    return 'success'
  }

  // ---------- 图片上传（压缩为dataURL） ----------
  function compressImage(file: File): Promise<string> {
    return new Promise((resolve, reject) => {
      const reader = new FileReader()
      reader.onload = () => {
        const img = new Image()
        img.onload = () => {
          const maxW = 720
          const scale = Math.min(1, maxW / (img.width || maxW))
          const canvas = document.createElement('canvas')
          canvas.width = Math.max(1, Math.round(img.width * scale))
          canvas.height = Math.max(1, Math.round(img.height * scale))
          canvas.getContext('2d')?.drawImage(img, 0, 0, canvas.width, canvas.height)
          resolve(canvas.toDataURL('image/jpeg', 0.68))
        }
        img.onerror = () => reject(new Error('图片解析失败'))
        img.src = String(reader.result)
      }
      reader.onerror = () => reject(new Error('读取失败'))
      reader.readAsDataURL(file)
    })
  }

  async function onPickImage(e: Event) {
    const input = e.target as HTMLInputElement
    const file = input.files?.[0]
    if (!file || !detail.row) return
    if (file.size > 8 * 1024 * 1024) {
      ElMessage.warning('图片过大，请选择8MB以内')
      input.value = ''
      return
    }
    try {
      const url = await compressImage(file)
      trainingStore.addPlanImage(detail.row.id, url, `对比照${detail.row.images.length + 1}`)
      ElMessage.success('已添加')
    } catch {
      ElMessage.error('图片处理失败')
    } finally {
      input.value = ''
    }
  }

  // ---------- 分享 ----------
  function shareUrl(row: TrainingPlan): string {
    return `${window.location.origin}${window.location.pathname}#/s/plan/${row.share.code}`
  }

  async function copyShare(url: string) {
    try {
      await navigator.clipboard.writeText(url)
      ElMessage.success('链接已复制，可发送给会员')
    } catch {
      ElMessage.warning('复制失败，请手动复制')
    }
  }

  function openSharePreview(row: TrainingPlan) {
    window.open(shareUrl(row), '_blank')
  }
</script>

<style scoped lang="scss">
  .plan-preview {
    max-height: 320px;
    padding: 10px 14px;
    overflow-y: auto;
    background: var(--el-fill-color-light);
    border-radius: 8px;
  }
</style>
