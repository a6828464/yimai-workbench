<template>
  <div class="art-full-height">
    <ElCard class="art-table-card">
      <div class="mb-4 flex flex-wrap items-center gap-3">
        <ElDatePicker
          v-model="filters.date"
          type="date"
          value-format="YYYY-MM-DD"
          placeholder="日志日期"
          class="!w-40"
          @change="load"
        />
        <ElSelect
          v-model="filters.level"
          placeholder="日志级别"
          clearable
          class="!w-32"
          @change="load"
        >
          <ElOption v-for="level in LEVELS" :key="level" :label="level" :value="level" />
        </ElSelect>
        <ElInput
          v-model="filters.keyword"
          placeholder="搜索消息或上下文"
          clearable
          class="!w-64"
          @keyup.enter="load"
        />
        <ElButton type="primary" @click="load">查询</ElButton>
        <ElButton @click="resetFilters">重置</ElButton>
        <div class="flex-1" />
        <ElButton @click="retentionVisible = true">保留策略</ElButton>
      </div>

      <ElTabs v-model="channel" @tab-change="load">
        <ElTabPane label="运行日志" name="runtime" />
        <ElTabPane label="错误日志" name="error" />
      </ElTabs>

      <div v-if="files.length" class="mb-3 text-xs text-gray-400">
        日志文件：{{ files.join('、') }}
      </div>

      <ElTable v-if="!isHandheld" v-loading="loading" :data="records" border stripe max-height="520">
        <ElTableColumn prop="time" label="时间" width="180" sortable />
        <ElTableColumn label="级别" width="100">
          <template #default="{ row }">
            <ElTag size="small" :type="levelType(row.level)">{{ row.level }}</ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn prop="message" label="日志消息" min-width="300" show-overflow-tooltip />
        <ElTableColumn label="上下文" min-width="260" show-overflow-tooltip>
          <template #default="{ row }">{{ formatContext(row.context) }}</template>
        </ElTableColumn>
      </ElTable>

      <!-- 手持设备：卡片列表。
           系统日志单次最多返回 500 条（实测 500 行 × 20196px），全量铺成卡片会明显拖慢滚动，
           所以默认只渲染前 DEFAULT_CARD_LIMIT 条，其余由用户点「显示全部」按需展开。 -->
      <div v-if="isHandheld" v-loading="loading" class="m-card-list min-h-[120px]">
        <MobileCard
          v-for="(row, i) in shownRecords"
          :key="`${row.time}-${i}`"
          :title="row.message || '—'"
          :subtitle="row.time"
          :tags="cardTags(row)"
          :note="contextText(row)"
          note-label="上下文"
          :actions="[{ text: '详情', type: 'primary', onClick: () => openDetail(row) }]"
        />
        <div v-if="!loading && !records.length" class="m-card-list__empty">暂无日志记录</div>
        <div v-if="!loading && records.length > shownRecords.length" class="pt-2 text-center">
          <ElButton link type="primary" @click="showAllLogs = true">
            显示全部 {{ records.length }} 条（当前 {{ shownRecords.length }} 条）
          </ElButton>
        </div>
      </div>
    </ElCard>

    <!-- 日志详情：卡片上的变更/上下文默认折叠，避免长 JSON 把一屏撑成几十屏 -->
    <ElDrawer v-model="detailVisible" title="日志详情" size="460px">
      <ElDescriptions v-if="detailRow" :column="1" border>
        <ElDescriptionsItem label="时间">{{ detailRow.time }}</ElDescriptionsItem>
        <ElDescriptionsItem label="级别">{{ detailRow.level }}</ElDescriptionsItem>
        <ElDescriptionsItem label="日志消息">{{ detailRow.message }}</ElDescriptionsItem>
        <ElDescriptionsItem label="上下文">{{ contextText(detailRow) }}</ElDescriptionsItem>
      </ElDescriptions>
    </ElDrawer>

    <ElDialog v-model="retentionVisible" title="系统日志保留策略" width="440px">
      <ElAlert
        title="超过保留期限的日志由服务端定期清理；选择永久保留时不会自动删除。"
        type="info"
        :closable="false"
        class="mb-5"
      />
      <ElForm label-width="110px">
        <ElFormItem label="系统日志">
          <ElSelect v-model="systemLogRetentionValue" class="!w-52">
            <ElOption
              v-for="option in RETENTION_OPTIONS"
              :key="String(option.value)"
              :label="option.label"
              :value="option.value"
            />
          </ElSelect>
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="retentionVisible = false">取消</ElButton>
        <ElButton type="primary" :loading="savingRetention" @click="saveRetention"
          >保存策略</ElButton
        >
      </template>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import { ElMessage } from 'element-plus'
  import {
    getRetentionSettings,
    getSystemLogs,
    updateRetentionSettings,
    type RetentionSettings,
    type SystemLogChannel,
    type SystemLogRecord
  } from '@/api/system-records'
  import { useDevice } from '@/hooks/core/useDevice'
  import type { MobileCardTag } from '@/components/business/mobile-card/types'

  defineOptions({ name: 'YimaiSystemLogs' })

  // 手持设备上用卡片列表代替宽表格（见下方 shownRecords / cardTags）
  const { isHandheld } = useDevice()

  /**
   * 卡片默认渲染条数上限
   *
   * 系统日志接口单次最多返回 500 条，全量铺成卡片 DOM 会明显拖慢滚动。
   * 首屏只渲染前 40 条，其余由「显示全部」按钮按需展开。
   */
  const DEFAULT_CARD_LIMIT = 40
  const showAllLogs = ref(false)
  const detailVisible = ref(false)
  const detailRow = ref<SystemLogRecord | null>(null)

  const LEVELS = ['DEBUG', 'INFO', 'WARN', 'ERROR']
  const RETENTION_OPTIONS = [
    { label: '保留 7 天', value: 7 },
    { label: '保留 30 天', value: 30 },
    { label: '保留 90 天', value: 90 },
    { label: '保留 180 天', value: 180 },
    { label: '永久保留', value: 'never' }
  ]
  const channel = ref<SystemLogChannel>('runtime')
  const filters = reactive({ date: '', keyword: '', level: '' })
  const records = ref<SystemLogRecord[]>([])
  const files = ref<string[]>([])
  const loading = ref(false)
  const retentionVisible = ref(false)
  const savingRetention = ref(false)
  const retention = reactive<RetentionSettings>({
    systemLogDays: 7,
    auditLogDays: null,
    modelGenerationDays: 90
  })
  const systemLogRetentionValue = computed<number | string>({
    get: () => retention.systemLogDays ?? 'never',
    set: (value) => (retention.systemLogDays = value === 'never' ? null : Number(value))
  })

  async function load() {
    loading.value = true
    try {
      const data = await getSystemLogs({ channel: channel.value, ...filters })
      records.value = data.records ?? []
      files.value = data.files ?? []
    } catch {
      records.value = []
      files.value = []
      ElMessage.error('系统日志加载失败')
    } finally {
      loading.value = false
    }
  }

  function resetFilters() {
    Object.assign(filters, { date: '', keyword: '', level: '' })
    load()
  }

  async function saveRetention() {
    savingRetention.value = true
    try {
      Object.assign(retention, await updateRetentionSettings({ ...retention }))
      retentionVisible.value = false
      ElMessage.success('系统日志保留策略已保存')
    } catch {
      ElMessage.error('保留策略保存失败')
    } finally {
      savingRetention.value = false
    }
  }

  function formatContext(context: unknown): string {
    if (context === null || context === undefined || context === '') return '—'
    if (typeof context === 'string') return context
    try {
      return JSON.stringify(context)
    } catch {
      return String(context)
    }
  }

  function levelType(level: string): 'danger' | 'warning' | 'info' | 'success' {
    if (level === 'ERROR') return 'danger'
    if (level === 'WARN') return 'warning'
    if (level === 'INFO') return 'success'
    return 'info'
  }

  /** 卡片默认只渲染前 N 条；切换筛选后要收回到首屏，避免上次的「显示全部」残留 */
  const shownRecords = computed(() =>
    showAllLogs.value ? records.value : records.value.slice(0, DEFAULT_CARD_LIMIT)
  )

  watch([() => channel.value, () => filters.date, () => filters.level, () => filters.keyword], () => {
    showAllLogs.value = false
  })

  /** 上下文文本：与表格列同一取数口径，保证卡片不丢信息 */
  function contextText(row: SystemLogRecord): string {
    return formatContext(row.context)
  }

  /** 级别标签；上下文很长时不放在标签里，改由 note 展示 */
  function cardTags(row: SystemLogRecord): MobileCardTag[] {
    return [{ text: row.level || '—', type: levelType(row.level), effect: 'dark' }]
  }

  function openDetail(row: SystemLogRecord): void {
    detailRow.value = row
    detailVisible.value = true
  }

  onMounted(async () => {
    const [, settings] = await Promise.all([load(), getRetentionSettings().catch(() => null)])
    if (settings) Object.assign(retention, settings)
  })
</script>
