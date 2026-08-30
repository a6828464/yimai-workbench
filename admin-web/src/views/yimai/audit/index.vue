<template>
  <div class="audit-page art-full-height">
    <ElCard class="art-table-card">
      <div class="mb-4 flex flex-wrap items-center gap-3">
        <ElInput
          v-model="filters.operator"
          placeholder="操作人"
          clearable
          class="!w-36"
          @change="search"
        />
        <ElSelect
          v-model="filters.module"
          placeholder="模块"
          clearable
          class="!w-40"
          @change="search"
        >
          <ElOption v-for="m in MODULES" :key="m" :label="m" :value="m" />
        </ElSelect>
        <ElSelect
          v-model="filters.action"
          placeholder="动作"
          clearable
          class="!w-32"
          @change="search"
        >
          <ElOption label="新增" value="新增" />
          <ElOption label="修改" value="修改" />
          <ElOption v-for="a in ACTIONS" :key="a" :label="a" :value="a" />
        </ElSelect>
        <ElButton @click="search">查询</ElButton>
        <ElButton @click="resetFilters">重置</ElButton>
      </div>

      <ArtTableHeader :columns="[]" :loading="loading">
        <template #left>
          <span class="text-sm text-gray-400"
            >所有修改类操作自动留痕，含操作人、时间、动作与字段级变更明细</span
          >
        </template>
      </ArtTableHeader>

      <ElTable v-loading="loading" :data="list" border stripe>
        <ElTableColumn prop="time" label="时间" width="140" sortable />
        <ElTableColumn label="操作人" width="130">
          <template #default="{ row }">
            {{ row.operatorName }}
            <ElTag size="small" effect="plain" class="ml-1">{{ row.operatorRole }}</ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn prop="action" label="动作" width="100">
          <template #default="{ row }">
            <ElTag size="small" :type="actionType(row.action)">{{ row.action }}</ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn prop="module" label="模块" width="100" />
        <ElTableColumn prop="targetLabel" label="对象" min-width="160" show-overflow-tooltip />
        <ElTableColumn prop="venue" label="门店" width="85" />
        <ElTableColumn prop="detail" label="变更明细" min-width="260" show-overflow-tooltip />
        <ElTableColumn label="操作" width="90" fixed="right">
          <template #default="{ row }">
            <ElButton link type="primary" size="small" @click="showDetail(row)">溯源</ElButton>
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

    <ElDrawer v-model="detail.visible" title="操作溯源" size="460px">
      <template v-if="detail.row">
        <el-descriptions :column="1" border>
          <ElDescriptionsItem label="日志编号">{{ detail.row.id }}</ElDescriptionsItem>
          <ElDescriptionsItem label="操作时间">{{ detail.row.time }}</ElDescriptionsItem>
          <ElDescriptionsItem label="操作人">{{
            `${detail.row.operatorName}（${detail.row.operatorRole}）`
          }}</ElDescriptionsItem>
          <ElDescriptionsItem label="所属模块">{{ detail.row.module }}</ElDescriptionsItem>
          <ElDescriptionsItem label="动作">{{ detail.row.action }}</ElDescriptionsItem>
          <ElDescriptionsItem label="操作对象">{{ detail.row.targetLabel }}</ElDescriptionsItem>
          <ElDescriptionsItem label="关联门店">{{ detail.row.venue }}</ElDescriptionsItem>
          <ElDescriptionsItem label="操作IP">{{ detail.row.ip || '—' }}</ElDescriptionsItem>
          <ElDescriptionsItem label="操作设备">{{
            prettyDevice(detail.row.userAgent)
          }}</ElDescriptionsItem>
          <ElDescriptionsItem label="变更明细">{{ detail.row.detail }}</ElDescriptionsItem>
        </el-descriptions>
      </template>
    </ElDrawer>
  </div>
</template>

<script setup lang="ts">
  import { queryAuditLogs } from '@/api/yimai'
  import type { YimaiAuditLog } from '@/api/yimai'

  defineOptions({ name: 'YimaiAudit' })

  const ACTIONS = ['初审通过', '终审通过', '驳回'] as const

  const MODULES = [
    '前端客资',
    '会员管理',
    '任务中心',
    '价格审批',
    '训练计划',
    '营销工具',
    'KeepYoga同步',
    '人员管理',
    '模型配置'
  ]

  const loading = ref(false)
  const filters = ref({ operator: '', module: '', action: '' })
  const page = ref({ current: 1, size: 20 })
  const list = ref<YimaiAuditLog[]>([])
  const total = ref(0)

  const detail = reactive({ visible: false, row: null as YimaiAuditLog | null })

  async function load() {
    loading.value = true
    try {
      const res = await queryAuditLogs({ ...filters.value, ...page.value })
      list.value = res.records
      total.value = res.total
      page.value.current = res.current
      page.value.size = res.size
    } catch {
      list.value = []
      total.value = 0
    } finally {
      loading.value = false
    }
  }

  function search() {
    page.value.current = 1
    load()
  }

  function changePage(current: number) {
    page.value.current = current
    load()
  }

  function resetFilters() {
    filters.value = { operator: '', module: '', action: '' }
    search()
  }

  function showDetail(row: YimaiAuditLog) {
    detail.row = row
    detail.visible = true
  }

  /** 从 User-Agent 提炼设备信息 */
  function prettyDevice(ua: string | undefined | null): string {
    const s = String(ua ?? '').trim()
    if (!s) return '—'
    const isMobile = /Mobile|Android|iPhone|iPad/.test(s)
    const os = /iPhone|iPad/.test(s)
      ? 'iOS'
      : /Android/.test(s)
        ? 'Android'
        : /Mac OS X/.test(s)
          ? 'macOS'
          : /Windows/.test(s)
            ? 'Windows'
            : /Linux/.test(s)
              ? 'Linux'
              : '未知系统'
    const browser = /Edg\//.test(s)
      ? 'Edge'
      : /Chrome\//.test(s)
        ? 'Chrome'
        : /Firefox\//.test(s)
          ? 'Firefox'
          : /Safari\//.test(s)
            ? 'Safari'
            : '其他浏览器'
    return `${isMobile ? '移动端' : '桌面端'} · ${os} · ${browser}`
  }

  function actionType(action: string): 'danger' | 'warning' | 'info' | 'success' | 'primary' {
    if (action.includes('驳回')) return 'danger'
    if (action === '新增') return 'success'
    if (action === '修改') return 'warning'
    return 'primary'
  }

  onMounted(load)
</script>
