<template>
  <div class="approval-page art-full-height">
    <ElCard class="art-table-card">
      <div class="mb-4 flex flex-wrap items-center gap-3">
        <ElSelect v-model="searchForm.status" placeholder="审批状态" clearable class="!w-40" @change="handleSearch">
          <ElOption v-for="s in STATUSES" :key="s" :label="s" :value="s" />
        </ElSelect>
        <ElButton @click="handleReset">重置</ElButton>
        <div class="flex-1" />
        <ElButton type="primary" plain @click="openCreate">发起价格审批</ElButton>
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

    <!-- 发起价格审批 -->
    <ElDialog v-model="createDlg" title="发起价格审批" width="480px" destroy-on-close>
      <ElForm label-width="96px">
        <ElFormItem label="客户姓名"><ElInput v-model="applyForm.customerName" placeholder="客户姓名" maxlength="20" /></ElFormItem>
        <ElFormItem label="卡项名称"><ElInput v-model="applyForm.cardName" placeholder="如：VIP私教50节" maxlength="40" /></ElFormItem>
        <ElFormItem label="标准 / 申请价">
          <div class="flex items-center gap-2">
            <ElInputNumber v-model="applyForm.standardPrice" :min="0" :step="100" controls-position="right" class="!w-36" placeholder="标准价" />
            <span class="text-gray-400">→</span>
            <ElInputNumber v-model="applyForm.requestPrice" :min="0" :step="100" controls-position="right" class="!w-36" placeholder="申请价" />
          </div>
        </ElFormItem>
        <ElFormItem label="申请原因"><ElInput v-model="applyForm.reason" type="textarea" :rows="2" maxlength="200" placeholder="写清让价背景，利于审批" /></ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="createDlg = false">取消</ElButton>
        <ElButton type="primary" :loading="applySaving" @click="doCreate">提交审批</ElButton>
      </template>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import ArtButtonTable from '@/components/core/forms/art-button-table/index.vue'
  import { useTable } from '@/hooks/core/useTable'
  import { queryApprovals, decideApproval, createApproval } from '@/api/yimai'
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
          if (row.status === '已通过') {
            return h(
              ArtButtonTable,
              { type: 'edit', title: '关联成交', onClick: () => linkDeal(row) }
            )
          }
          return h('span', { class: 'text-xs text-gray-400' }, '—')
        }
        }
      ]
    }
  })

  async function act(row: YimaiApproval, pass: boolean, finalStage = false) {
    if (!pass) {
      try {
        await decideApproval(row.id, '驳回')
        refreshData()
        ElMessage.warning('已驳回，决定已写入留痕日志')
      } catch (e) {
        console.error('[approvals.act]', e)
        ElMessage.error('操作失败，请稍后重试')
      }
      return
    }
    if (finalStage && !isBoss.value) {
      ElMessage.info('该单已到终审环节，需南哥拍板')
      return
    }
    const stage = row.status === '待店长初审' ? '初审' : '终审'
    try {
      await decideApproval(row.id, stage === '初审' ? '初审通过' : '终审通过')
      refreshData()
      ElMessage.success(`${stage}通过，决定已写入留痕日志`)
    } catch (e) {
      console.error('[approvals.act]', e)
      ElMessage.error('操作失败，请稍后重试')
    }
  }

  async function linkDeal(row: YimaiApproval) {
    try {
      await decideApproval(row.id, '关联成交')
      refreshData()
      ElMessage.success(`${row.customerName} 已标记「已关联成交」`)
    } catch (e) {
      console.error('[approvals.linkDeal]', e)
      ElMessage.error('操作失败，请稍后重试')
    }
  }

  // ---------- 发起价格审批 ----------
  const createDlg = ref(false)
  const applySaving = ref(false)
  const applyForm = reactive({ customerName: '', cardName: '', standardPrice: 3200, requestPrice: 3000, reason: '' })

  function openCreate() {
    Object.assign(applyForm, { customerName: '', cardName: '', standardPrice: 3200, requestPrice: 3000, reason: '' })
    createDlg.value = true
  }

  async function doCreate() {
    if (!applyForm.customerName.trim()) return ElMessage.warning('请填写客户姓名')
    if (!applyForm.cardName.trim()) return ElMessage.warning('请填写卡项名称')
    if (applyForm.requestPrice >= applyForm.standardPrice) return ElMessage.warning('申请价应低于标准价')
    applySaving.value = true
    try {
      await createApproval({ ...applyForm })
      ElMessage.success('审批单已提交，进入店长初审')
      createDlg.value = false
      await refreshData()
    } catch (e) {
      ElMessage.error(String((e as { message?: string }).message ?? e).slice(0, 120))
    } finally {
      applySaving.value = false
    }
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
