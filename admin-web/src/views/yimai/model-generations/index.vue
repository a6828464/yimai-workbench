<template>
  <div class="art-full-height">
    <ElCard class="art-table-card">
      <div class="mb-4 flex flex-wrap items-center gap-3">
        <ElSelect
          v-model="filters.operatorId"
          placeholder="操作人"
          clearable
          filterable
          class="!w-36"
          @change="search"
        >
          <ElOption
            v-for="item in operators"
            :key="String(item.id)"
            :label="item.name"
            :value="item.id"
          />
        </ElSelect>
        <ElDatePicker
          v-model="filters.dateRange"
          type="daterange"
          value-format="YYYY-MM-DD"
          start-placeholder="开始日期"
          end-placeholder="结束日期"
          range-separator="~"
          class="!w-64"
          @change="search"
        />
        <ElSelect
          v-model="filters.featureType"
          placeholder="功能类型"
          clearable
          class="!w-36"
          @change="search"
        >
          <ElOption v-for="item in featureTypes" :key="item" :label="item" :value="item" />
        </ElSelect>
        <ElSelect
          v-model="filters.status"
          placeholder="状态"
          clearable
          class="!w-28"
          @change="search"
        >
          <ElOption v-for="item in statuses" :key="item" :label="statusLabel(item)" :value="item" />
        </ElSelect>
        <ElButton type="primary" @click="search">查询</ElButton>
        <ElButton @click="resetFilters">重置</ElButton>
        <div class="flex-1" />
        <ElButton @click="retentionVisible = true">保留策略</ElButton>
      </div>

      <ArtTableHeader :columns="[]" :loading="loading">
        <template #left>
          <span class="text-sm text-gray-400"
            >记录服务端模型与本地模板生成结果、耗时及 Token 用量</span
          >
        </template>
      </ArtTableHeader>

      <ElTable v-loading="loading" :data="records" border stripe>
        <ElTableColumn prop="createdAt" label="生成时间" width="170" sortable />
        <ElTableColumn label="操作人" width="150">
          <template #default="{ row }">
            {{ row.operatorName }}
            <ElTag size="small" effect="plain" class="ml-1">{{ row.operatorRole }}</ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn prop="featureType" label="功能" width="120" />
        <ElTableColumn label="来源" width="110">
          <template #default="{ row }">{{ sourceLabel(row.source) }}</template>
        </ElTableColumn>
        <ElTableColumn label="服务商 / 模型" min-width="190" show-overflow-tooltip>
          <template #default="{ row }">{{ row.provider || '—' }} / {{ row.model || '—' }}</template>
        </ElTableColumn>
        <ElTableColumn label="状态" width="90">
          <template #default="{ row }">
            <ElTag size="small" :type="isSuccess(row.status) ? 'success' : 'danger'">{{
              statusLabel(row.status)
            }}</ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn label="耗时" width="100">
          <template #default="{ row }">{{
            row.latencyMs == null ? '—' : `${row.latencyMs} ms`
          }}</template>
        </ElTableColumn>
        <ElTableColumn label="Token（入/出/总）" width="155">
          <template #default="{ row }">{{ tokenSummary(row) }}</template>
        </ElTableColumn>
        <ElTableColumn label="结果预览 / 错误" min-width="240" show-overflow-tooltip>
          <template #default="{ row }">
            <span :class="isSuccess(row.status) ? '' : 'text-red-500'">{{
              row.errorMessage || row.outputPreview || '—'
            }}</span>
          </template>
        </ElTableColumn>
      </ElTable>

      <div class="mt-4 flex justify-end">
        <ElPagination
          :current-page="page.current"
          :page-size="page.size"
          :total="total"
          layout="total, prev, pager, next"
          @current-change="changePage"
        />
      </div>
    </ElCard>

    <ElDialog v-model="retentionVisible" title="模型生成记录保留策略" width="440px">
      <ElAlert
        title="记录可能包含生成内容摘要，请按数据治理要求设置保留期限。"
        type="warning"
        :closable="false"
        class="mb-5"
      />
      <ElForm label-width="120px">
        <ElFormItem label="生成记录">
          <ElSelect v-model="modelGenerationRetentionValue" class="!w-52">
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
    getModelGenerations,
    getRetentionSettings,
    updateRetentionSettings,
    type ModelGenerationRecord,
    type RetentionSettings
  } from '@/api/system-records'

  defineOptions({ name: 'YimaiModelGenerations' })

  const RETENTION_OPTIONS = [
    { label: '保留 30 天', value: 30 },
    { label: '保留 90 天', value: 90 },
    { label: '保留 180 天', value: 180 },
    { label: '保留 365 天', value: 365 },
    { label: '永久保留', value: 'never' }
  ]
  const filters = reactive<{
    operatorId: number | string | undefined
    dateRange: string[]
    featureType: string
    status: string
  }>({ operatorId: undefined, dateRange: [], featureType: '', status: '' })
  const page = reactive({ current: 1, size: 10 })
  const records = ref<ModelGenerationRecord[]>([])
  const total = ref(0)
  const loading = ref(false)
  const featureTypes = ref<string[]>([])
  const statuses = ref<string[]>([])
  const operators = ref<Array<{ id: number | string; name: string }>>([])
  const retentionVisible = ref(false)
  const savingRetention = ref(false)
  const retention = reactive<RetentionSettings>({
    systemLogDays: 30,
    auditLogDays: null,
    modelGenerationDays: 90
  })
  const modelGenerationRetentionValue = computed<number | string>({
    get: () => retention.modelGenerationDays ?? 'never',
    set: (value) => (retention.modelGenerationDays = value === 'never' ? null : Number(value))
  })

  async function load() {
    loading.value = true
    try {
      const data = await getModelGenerations({
        current: page.current,
        size: page.size,
        operatorId: filters.operatorId,
        start: filters.dateRange?.[0],
        end: filters.dateRange?.[1],
        featureType: filters.featureType,
        status: filters.status
      })
      records.value = data.records
      total.value = data.total
      page.current = data.current
      page.size = data.size
      featureTypes.value =
        data.metadata?.featureTypes ?? unique(records.value.map((item) => item.featureType))
      statuses.value = data.metadata?.statuses ?? unique(records.value.map((item) => item.status))
      operators.value = (data.metadata?.operators ?? []).map((item) =>
        typeof item === 'string' ? { id: item, name: item } : item
      )
    } catch {
      records.value = []
      total.value = 0
      ElMessage.error('模型生成记录加载失败')
    } finally {
      loading.value = false
    }
  }

  function search() {
    page.current = 1
    load()
  }

  function changePage(current: number) {
    page.current = current
    load()
  }

  function resetFilters() {
    Object.assign(filters, { operatorId: undefined, dateRange: [], featureType: '', status: '' })
    search()
  }

  async function saveRetention() {
    savingRetention.value = true
    try {
      Object.assign(retention, await updateRetentionSettings({ ...retention }))
      retentionVisible.value = false
      ElMessage.success('模型生成记录保留策略已保存')
    } catch {
      ElMessage.error('保留策略保存失败')
    } finally {
      savingRetention.value = false
    }
  }

  function isSuccess(status: string): boolean {
    return ['success', 'succeeded', '成功'].includes(status.toLowerCase())
  }

  function statusLabel(status: string): string {
    if (isSuccess(status)) return '成功'
    if (['failed', 'failure', 'error', '失败'].includes(status.toLowerCase())) return '失败'
    return status || '未知'
  }

  function sourceLabel(source: string): string {
    if (source === 'llm') return '模型 API'
    if (source === 'fallback' || source === 'template') return '本地模板'
    return source || '—'
  }

  function tokenSummary(row: ModelGenerationRecord): string {
    if (row.inputTokens == null && row.outputTokens == null && row.totalTokens == null) return '—'
    return `${row.inputTokens ?? 0} / ${row.outputTokens ?? 0} / ${row.totalTokens ?? 0}`
  }

  function unique(values: string[]): string[] {
    return [...new Set(values.filter(Boolean))]
  }

  onMounted(async () => {
    const [, settings] = await Promise.all([load(), getRetentionSettings().catch(() => null)])
    if (settings) Object.assign(retention, settings)
  })
</script>
