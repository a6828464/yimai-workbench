<template>
  <div class="approval-page art-full-height">
    <ElCard class="art-table-card">
      <div class="mb-4 flex flex-wrap items-center gap-3">
        <ElSelect v-model="searchForm.status" placeholder="审批状态" clearable class="!w-40" @change="handleSearch">
          <ElOption v-for="s in STATUSES" :key="s" :label="s" :value="s" />
        </ElSelect>
        <ElButton @click="handleReset">重置</ElButton>
      </div>

      <ArtTableHeader v-model:columns="columnChecks" :loading="loading" @refresh="refreshData">
        <template #left>
          <span class="text-sm text-gray-400">未通过审批不能标记成交（硬机制）</span>
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
  import { queryApprovals, decideApproval } from '@/api/yimai'
  import type { YimaiApproval } from '@/api/yimai'
  import { ElMessage, ElTag } from 'element-plus'
  import { useUserStore } from '@/store/modules/user'

defineOptions({ name: 'YimaiApprovals' })

const userStore = useUserStore()
const isBoss = computed(() => (userStore.getUserInfo.roles ?? []).includes('R_BOSS'))

  const STATUSES = ['待店长初审', '待老板终审', '已通过', '已驳回', '已关联成交'] as const

  const STATUS_TAG: Record<string, 'danger' | 'warning' | 'info' | 'success' | 'primary'> = {
    待店长初审: 'warning',
    待老板终审: 'danger',
    已通过: 'success',
    已驳回: 'info',
    已关联成交: 'primary'
  }

  const searchForm = ref({ status: '' })

  function fmtPrice(v: number) {
    return `¥${v.toLocaleString()}`
  }

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
      apiFn: queryApprovals,
      apiParams: { current: 1, size: 20 },
      columnsFactory: () => [
        {
          prop: 'customerName',
          label: '客户 / 申请人',
          minWidth: 140,
          formatter: (row: YimaiApproval) =>
            h('div', [
              h('p', { class: 'font-500' }, row.customerName),
              h('p', { class: 'text-xs text-gray-400' }, `申请老师 ${row.applicant} · ${row.applyTime}`)
            ])
        },
        { prop: 'cardName', label: '卡项', minWidth: 160 },
        {
          prop: 'standardPrice',
          label: '标准价 → 申请价',
          width: 170,
          sortable: true,
          formatter: (row: YimaiApproval) => {
            const discount = ((row.requestPrice / row.standardPrice) * 10).toFixed(1)
            return h('div', [
              h('p', [
                h('span', { class: 'text-gray-400 line-through mr-2' }, fmtPrice(row.standardPrice)),
                h('span', { class: 'font-500 text-red-500' }, fmtPrice(row.requestPrice))
              ]),
              h('p', { class: 'text-xs text-gray-400' }, `${discount}折`)
            ])
          }
        },
        { prop: 'reason', label: '申请原因', minWidth: 200 },
        {
          prop: 'status',
          label: '状态',
          width: 110,
          formatter: (row: YimaiApproval) =>
            h(ElTag, { size: 'small', type: STATUS_TAG[row.status] }, () => row.status)
        },
        {
          prop: 'operation',
          label: '操作',
          width: 130,
          fixed: 'right',
        formatter: (row: YimaiApproval) => {
          if (row.status === '待店长初审') {
            return h('div', { class: 'flex gap-1' }, [
              h(ArtButtonTable, {
                type: 'edit',
                title: isBoss.value ? '越级终审通过' : '初审通过',
                onClick: () => act(row, true)
              }),
              h(ArtButtonTable, { type: 'delete', title: '驳回', onClick: () => act(row, false) })
            ])
          }
          if (row.status === '待老板终审') {
            return h('div', { class: 'flex gap-1' }, [
              h(
                ArtButtonTable,
                {
                  type: 'edit',
                  title: isBoss.value ? '终审通过' : '等待南哥终审',
                  onClick: () => act(row, true, true)
                }
              ),
              h(ArtButtonTable, { type: 'delete', title: '驳回', onClick: () => act(row, false) })
            ])
          }
          return h('span', { class: 'text-xs text-gray-400' }, '—')
        }
        }
      ]
    }
  })

  async function act(row: YimaiApproval, pass: boolean, finalStage = false) {
    if (!pass) {
      await decideApproval(row.id, '驳回')
      refreshData()
      ElMessage.warning('已驳回，决定已写入留痕日志')
      return
    }
    if (finalStage && !isBoss.value) {
      ElMessage.info('该单已到终审环节，需南哥拍板')
      return
    }
    const stage = row.status === '待店长初审' ? '初审' : '终审'
    await decideApproval(row.id, stage === '初审' ? '初审通过' : '终审通过')
    refreshData()
    ElMessage.success(`${stage}通过，决定已写入留痕日志`)
  }

  function handleSearch() {
    replaceSearchParams({ ...searchForm.value })
    getData()
  }

  function handleReset() {
    searchForm.value = { status: '' }
    resetSearchParams()
    getData()
  }
</script>
