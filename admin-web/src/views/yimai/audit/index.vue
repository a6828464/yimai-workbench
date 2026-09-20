<template>
  <div class="audit-page art-full-height">
    <ElCard class="art-table-card">
      <div class="mb-4 flex flex-wrap items-center gap-3">
        <ElSelect
          v-model="filters.operator"
          placeholder="操作人"
          clearable
          filterable
          class="!w-36"
          @change="search"
        >
          <ElOption v-for="item in metadata.operators" :key="item" :label="item" :value="item" />
        </ElSelect>
        <ElSelect
          v-model="filters.module"
          placeholder="模块"
          clearable
          class="!w-40"
          @change="search"
        >
          <ElOption v-for="item in metadata.modules" :key="item" :label="item" :value="item" />
        </ElSelect>
        <ElSelect
          v-model="filters.action"
          placeholder="动作"
          clearable
          class="!w-32"
          @change="search"
        >
          <ElOption v-for="item in metadata.actions" :key="item" :label="item" :value="item" />
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
        <ElButton type="primary" @click="search">查询</ElButton>
        <ElButton @click="resetFilters">重置</ElButton>
        <div class="flex-1" />
        <ElButton @click="retentionVisible = true">保留策略</ElButton>
      </div>

      <ArtTableHeader :columns="[]" :loading="loading">
        <template #left>
          <span class="text-sm text-gray-400"
            >人员修改类操作自动留痕，含操作人、时间、动作与字段级变更明细</span
          >
        </template>
      </ArtTableHeader>

      <!-- 手持设备：改用卡片列表。9 列表格在 390px 上只剩左右固定列，中间全被挤掉；
           日志类列表的核心是「谁、什么时候、对什么做了什么」，卡片按这个顺序排 -->
      <ElTable v-if="!isHandheld" v-loading="loading" :data="list" border stripe max-height="520">
        <ElTableColumn prop="time" label="时间" width="170" sortable />
        <ElTableColumn label="操作人" width="150">
          <template #default="{ row }">
            {{ row.operatorName }}
            <ElTag size="small" effect="plain" class="ml-1">{{ row.operatorRole }}</ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn prop="action" label="动作" width="100">
          <template #default="{ row }"
            ><ElTag size="small" :type="actionType(row.action)">{{ row.action }}</ElTag></template
          >
        </ElTableColumn>
        <ElTableColumn prop="module" label="模块" width="120" />
        <ElTableColumn prop="targetLabel" label="对象" min-width="160" show-overflow-tooltip />
        <ElTableColumn prop="venue" label="门店" width="90" />
        <ElTableColumn prop="detail" label="变更明细" min-width="260" show-overflow-tooltip />
        <ElTableColumn label="操作" width="90" fixed="right">
          <template #default="{ row }"
            ><ElButton link type="primary" size="small" @click="showDetail(row)"
              >溯源</ElButton
            ></template
          >
        </ElTableColumn>
      </ElTable>

      <!-- 手持设备：卡片列表。日志的读法是「谁 / 何时 / 对什么做了什么」，
           所以标题给操作对象、副标题给操作人+时间，动作与模块用标签，变更明细收进 note -->
      <div v-if="isHandheld" v-loading="loading" class="m-card-list min-h-[120px]">
        <MobileCard
          v-for="row in list"
          :key="row.id"
          :title="row.targetLabel || '—'"
          :subtitle="cardSubtitle(row)"
          :tags="cardTags(row)"
          :note="row.detail"
          note-label="变更明细"
          :actions="[{ text: '溯源', type: 'primary', onClick: () => showDetail(row) }]"
        />
        <div v-if="!loading && !list.length" class="m-card-list__empty">暂无操作日志</div>
      </div>

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

    <ElDrawer v-model="detail.visible" title="操作溯源" size="460px">
      <ElDescriptions v-if="detail.row" :column="1" border>
        <ElDescriptionsItem label="日志编号">{{ detail.row.id }}</ElDescriptionsItem>
        <ElDescriptionsItem label="操作时间">{{ detail.row.time || '—' }}</ElDescriptionsItem>
        <ElDescriptionsItem label="操作人">{{ cardSubtitle(detail.row) }}</ElDescriptionsItem>
        <ElDescriptionsItem label="所属模块">{{ detail.row.module || '—' }}</ElDescriptionsItem>
        <ElDescriptionsItem label="动作">{{ detail.row.action }}</ElDescriptionsItem>
        <ElDescriptionsItem label="操作对象">{{ detail.row.targetLabel }}</ElDescriptionsItem>
        <ElDescriptionsItem label="关联门店">{{ detail.row.venue }}</ElDescriptionsItem>
        <ElDescriptionsItem label="操作IP">{{ detail.row.ip || '—' }}</ElDescriptionsItem>
        <ElDescriptionsItem label="操作设备">{{
          prettyDevice(detail.row.userAgent)
        }}</ElDescriptionsItem>
        <ElDescriptionsItem label="变更明细">{{ detail.row.detail }}</ElDescriptionsItem>
      </ElDescriptions>
    </ElDrawer>

    <ElDialog v-model="retentionVisible" title="人员操作日志保留策略" width="440px">
      <ElAlert
        title="审计日志默认永久保留。缩短期限前请确认符合内部审计要求。"
        type="warning"
        :closable="false"
        class="mb-5"
      />
      <ElForm label-width="120px">
        <ElFormItem label="操作日志">
          <ElSelect v-model="auditRetentionValue" class="!w-52">
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
    getAuditLogs,
    getRetentionSettings,
    updateRetentionSettings,
    type AuditLogMetadata,
    type RetentionSettings
  } from '@/api/system-records'
  import type { YimaiAuditLog } from '@/store/modules/yimai'
  import { useDevice } from '@/hooks/core/useDevice'
  import type { MobileCardTag } from '@/components/business/mobile-card/types'

  defineOptions({ name: 'YimaiAudit' })

  // 手持设备上用卡片列表代替宽表格（见下方 cardTags）
  const { isHandheld } = useDevice()

  const RETENTION_OPTIONS = [
    { label: '保留 90 天', value: 90 },
    { label: '保留 180 天', value: 180 },
    { label: '保留 365 天', value: 365 },
    { label: '永久保留', value: 'never' }
  ]
  const loading = ref(false)
  const filters = reactive({ operator: '', module: '', action: '', dateRange: [] as string[] })
  const page = reactive({ current: 1, size: 20 })
  const list = ref<YimaiAuditLog[]>([])
  const total = ref(0)
  const metadata = reactive<AuditLogMetadata>({ operators: [], modules: [], actions: [] })
  const detail = reactive({ visible: false, row: null as YimaiAuditLog | null })
  const retentionVisible = ref(false)
  const savingRetention = ref(false)
  const retention = reactive<RetentionSettings>({
    systemLogDays: 7,
    // 与后端 retentionSettings() 的默认值一致（180 天）。此前这里写 null（= 永久保留），
    // 一旦设置接口失败，表单会显示一个比真实策略更宽松的值，容易让人误判合规口径。
    auditLogDays: 180,
    modelGenerationDays: 90
  })
  const auditRetentionValue = computed<number | string>({
    get: () => retention.auditLogDays ?? 'never',
    set: (value) => (retention.auditLogDays = value === 'never' ? null : Number(value))
  })

  async function load() {
    loading.value = true
    try {
      const data = await getAuditLogs({
        operator: filters.operator,
        module: filters.module,
        action: filters.action,
        start: filters.dateRange?.[0],
        end: filters.dateRange?.[1],
        ...page
      })
      list.value = data.records
      total.value = data.total
      page.current = data.current
      page.size = data.size
      Object.assign(metadata, data.metadata)
    } catch {
      list.value = []
      total.value = 0
      ElMessage.error('人员操作日志加载失败')
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
    Object.assign(filters, { operator: '', module: '', action: '', dateRange: [] })
    search()
  }

  function showDetail(row: YimaiAuditLog) {
    detail.row = row
    detail.visible = true
  }

  async function saveRetention() {
    savingRetention.value = true
    try {
      Object.assign(retention, await updateRetentionSettings({ ...retention }))
      retentionVisible.value = false
      ElMessage.success('人员操作日志保留策略已保存')
    } catch {
      ElMessage.error('保留策略保存失败')
    } finally {
      savingRetention.value = false
    }
  }

  function prettyDevice(ua: string | undefined | null): string {
    const value = String(ua ?? '').trim()
    if (!value) return '—'
    const isMobile = /Mobile|Android|iPhone|iPad/.test(value)
    const os = /iPhone|iPad/.test(value)
      ? 'iOS'
      : /Android/.test(value)
        ? 'Android'
        : /Mac OS X/.test(value)
          ? 'macOS'
          : /Windows/.test(value)
            ? 'Windows'
            : /Linux/.test(value)
              ? 'Linux'
              : '未知系统'
    const browser = /Edg\//.test(value)
      ? 'Edge'
      : /Chrome\//.test(value)
        ? 'Chrome'
        : /Firefox\//.test(value)
          ? 'Firefox'
          : /Safari\//.test(value)
            ? 'Safari'
            : '其他浏览器'
    return `${isMobile ? '移动端' : '桌面端'} · ${os} · ${browser}`
  }

  /**
   * 动作 → 标签语义色。
   *
   * action 类型上是 string，但历史日志行可能缺这个字段（返回 null）。
   * 原实现直接 `action.includes(...)`，缺字段时会抛
   * `Cannot read properties of null (reading 'includes')`，把整页列表渲染打断
   * （实测手机端整页只剩 32 字，卡片与表格都不出来）。这里先归一化成字符串。
   */
  function actionType(action: unknown): 'danger' | 'warning' | 'info' | 'success' | 'primary' {
    const a = action === null || action === undefined ? '' : String(action)
    if (a.includes('驳回') || a.includes('删除')) return 'danger'
    if (a === '新增') return 'success'
    if (a === '修改') return 'warning'
    return 'primary'
  }

  /**
   * 卡片副标题：时间 · 操作人（角色）
   *
   * 各段独立兜底：历史日志行可能缺时间/操作人/角色，任一段缺失只显示「—」，
   * 不再把 undefined 拼进文案（原实现是无兜底模板字符串）。
   */
  function cardSubtitle(row: YimaiAuditLog): string {
    const who = row.operatorName || '—'
    const role = row.operatorRole ? `（${row.operatorRole}）` : ''
    return `${row.time || '—'} · ${who}${role}`
  }

  /**
   * 卡片标签：动作（语义色，扫读时最先看到）+ 模块 + 门店
   *
   * 表格里的时间/操作人已移到副标题，动作/模块/门店改为标签 —— 三个字段一个都没丢。
   * 动作缺失时显示「—」，不渲染成 undefined 也不给空标签。
   */
  function cardTags(row: YimaiAuditLog): MobileCardTag[] {
    const tags: MobileCardTag[] = [
      { text: row.action || '—', type: actionType(row.action), effect: 'dark' }
    ]
    if (row.module) tags.push({ text: row.module, effect: 'plain' })
    if (row.venue) tags.push({ text: row.venue, effect: 'plain' })
    return tags
  }

  onMounted(async () => {
    const [, settings] = await Promise.all([load(), getRetentionSettings().catch(() => null)])
    if (settings) Object.assign(retention, settings)
  })
</script>
