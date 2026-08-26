<template>
  <div class="task-page art-full-height">
    <ElCard class="art-table-card">
      <div class="mb-4 flex flex-wrap items-center gap-3">
        <ElSelect v-model="searchForm.status" placeholder="任务状态" clearable class="!w-36" @change="handleSearch">
          <ElOption v-for="s in STATUSES" :key="s" :label="s" :value="s" />
        </ElSelect>
        <ElSelect v-model="searchForm.venue" placeholder="门店" clearable class="!w-32" @change="handleSearch">
          <ElOption label="绿地店" value="绿地店" />
          <ElOption label="东部店" value="东部店" />
        </ElSelect>
        <ElButton @click="handleReset">重置</ElButton>
      </div>

      <ArtTableHeader v-model:columns="columnChecks" :loading="loading" @refresh="refreshData">
        <template #left>
          <ElButton type="primary" v-ripple>新建任务</ElButton>
        </template>
      </ArtTableHeader>

      <ArtTable
        :loading="loading"
        :data="data"
        :columns="columns"
        :pagination="pagination"
        @pagination:size-change="handleSizeChange"
        @pagination:current-change="handleCurrentChange"
      />
    </ElCard>
  </div>
</template>

<script setup lang="ts">
  import ArtButtonTable from '@/components/core/forms/art-button-table/index.vue'
  import { useTable } from '@/hooks/core/useTable'
  import { queryTasks } from '@/api/yimai'
  import type { YimaiTask } from '@/api/yimai'
  import { ElMessage, ElTag } from 'element-plus'

  defineOptions({ name: 'YimaiTasks' })

  const STATUSES = ['待接收', '进行中', '待验收', '已完成', '已退回', '已逾期'] as const

  const STATUS_TAG: Record<string, 'danger' | 'warning' | 'info' | 'success' | 'primary'> = {
    待接收: 'info',
    进行中: 'primary',
    待验收: 'warning',
    已完成: 'success',
    已退回: 'danger',
    已逾期: 'danger'
  }

  const searchForm = ref({
    status: '',
    venue: ''
  })

  const {
    columns,
    columnChecks,
    data,
    loading,
    pagination,
    getData,
    replaceSearchParams,
    resetSearchParams,
    refreshData,
    handleSizeChange,
    handleCurrentChange
  } = useTable({
    core: {
      apiFn: queryTasks,
      apiParams: { current: 1, size: 20 },
      columnsFactory: () => [
        {
          prop: 'title',
          label: '任务 / 客户',
          minWidth: 160,
          formatter: (row: YimaiTask) =>
            h('div', [
              h('p', { class: 'font-500' }, row.title),
              h('p', { class: 'text-xs text-gray-400' }, `${row.customerName} · ${row.venue}`)
            ])
        },
        {
          prop: 'owner',
          label: '负责人',
          width: 90,
          formatter: (row: YimaiTask) =>
            row.owner === '未分配'
              ? h('span', { class: 'font-500 text-red-500' }, '未分配')
              : h('span', {}, row.owner)
        },
        {
          prop: 'priority',
          label: '优先级',
          width: 80,
          formatter: (row: YimaiTask) =>
            h(ElTag, { size: 'small', type: row.priority === '高' ? 'danger' : row.priority === '中' ? 'warning' : 'info' }, () => row.priority)
        },
        {
          prop: 'standard',
          label: '验收标准',
          minWidth: 220
        },
        {
          prop: 'deadline',
          label: '截止时间',
          width: 140,
          sortable: true
        },
        {
          prop: 'status',
          label: '状态',
          width: 90,
          formatter: (row: YimaiTask) =>
            h(ElTag, { size: 'small', type: STATUS_TAG[row.status] }, () => row.status)
        },
        {
          prop: 'operation',
          label: '操作',
          width: 90,
          fixed: 'right',
          formatter: (row: YimaiTask) =>
            h(ArtButtonTable, { type: 'more', onClick: () => showDetail(row) })
        }
      ]
    }
  })

  function handleSearch() {
    replaceSearchParams({ ...searchForm.value })
    getData()
  }

  function handleReset() {
    searchForm.value = { status: '', venue: '' }
    resetSearchParams()
    getData()
  }

  function showDetail(_row: YimaiTask) {
    ElMessage.info('任务过程反馈与店长验收在阶段1交付')
  }
</script>
