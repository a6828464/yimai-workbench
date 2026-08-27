<template>
  <div class="customer-page art-full-height">
    <ElCard class="art-table-card">
      <div class="mb-4 flex flex-wrap items-center gap-3">
        <ElInput
          v-model="searchForm.name"
          placeholder="客户姓名 / 尾号"
          clearable
          class="!w-45"
          @change="handleSearch"
        />
        <ElSelect v-model="searchForm.venue" placeholder="门店" clearable class="!w-32" @change="handleSearch">
          <ElOption label="绿地店" value="绿地店" />
          <ElOption label="东部店" value="东部店" />
        </ElSelect>
        <ElSelect v-model="searchForm.layer" placeholder="经营分层" clearable class="!w-36" @change="handleSearch">
          <ElOption v-for="(v, k) in LAYER_LABELS" :key="k" :label="`${k} ${v}`" :value="k" />
        </ElSelect>
        <ElSelect v-model="searchForm.list" placeholder="运营清单" clearable class="!w-32" @change="handleSearch">
          <ElOption v-for="k in LIST_KEYS" :key="k" :label="k" :value="k" />
        </ElSelect>
        <ElSelect v-model="searchForm.haveCourse" placeholder="有课卡" clearable class="!w-28" @change="handleSearch">
          <ElOption label="有课卡" value="true" />
          <ElOption label="无课卡" value="false" />
        </ElSelect>
        <ElSelect v-model="searchForm.remainRange" placeholder="剩余课时" clearable class="!w-32" @change="handleSearch">
          <ElOption label="≤ 5 节" value="5" />
          <ElOption label="≤ 10 节" value="10" />
          <ElOption label="≤ 20 节" value="20" />
        </ElSelect>
        <ElButton type="primary" plain @click="handleSearch">查询</ElButton>
        <ElButton @click="handleReset">重置</ElButton>
      </div>

      <ArtTableHeader v-model:columns="columnChecks" :loading="loading" @refresh="refreshData">
        <template #left>
          <span class="text-sm text-gray-400">{{ scopeHint }}</span>
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
  import { queryCustomers } from '@/api/yimai'
  import type { YimaiCustomer } from '@/api/yimai'
  import { ElMessage, ElTag } from 'element-plus'
  import { useUserStore } from '@/store/modules/user'

  defineOptions({ name: 'YimaiCustomers' })

  const LIST_KEYS = ['待续课', '出勤降低', 'VIP', '预流失', '待复活']

  const userStore = useUserStore()
  const roles = computed(() => userStore.getUserInfo.roles ?? [])
  const isManager = computed(() => roles.value.includes('R_MANAGER'))
  const isMedia = computed(() => roles.value.includes('R_MEDIA'))
  const scopeHint = computed(() => {
    if (isManager.value) return `数据范围：本店（${userStore.getUserInfo.venue}）· 按手机号聚合全部卡项`
    if (isMedia.value) return '数据范围：前端客资（P5新客转化层）· 成交后移交店长团队'
    return '数据范围：双店 · 按手机号聚合全部卡项，分层口径 P0-P5'
  })

  const LAYER_LABELS: Record<string, string> = {
    P0: '续费窗口',
    P1: '高资产低活跃',
    P2: '频次下降',
    P3: '过期有余额',
    P4: '可升级',
    P5: '新客转化'
  }

  const LAYER_TAG_TYPE: Record<string, 'danger' | 'warning' | 'info' | 'success' | 'primary'> = {
    P0: 'danger',
    P1: 'warning',
    P2: 'warning',
    P3: 'info',
    P4: 'success',
    P5: 'primary'
  }

  const searchForm = ref({
    name: '',
    venue: '',
    layer: '',
    list: '',
    haveCourse: '',
    remainRange: ''
  })

  function daysAgo(date: string | null) {
    if (!date) return null
    return Math.floor((Date.now() - new Date(date).getTime()) / 86400000)
  }

  /** 内部工作台展示完整手机号 */
  function maskPhone(p: string): string {
    const v = String(p ?? '').trim()
    return v || '—'
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
      apiFn: queryCustomers,
      apiParams: {
        current: 1,
        size: 20
      },
      columnsFactory: () => [
        {
          prop: 'name',
          label: '客户',
          minWidth: 150,
          formatter: (row: YimaiCustomer) =>
            h('div', [
              h('p', { class: 'font-500' }, row.name),
              h('p', { class: 'text-xs text-gray-400' }, `${row.phone ? maskPhone(row.phone) : `尾号${row.phoneTail || '—'}`} · ${row.source}`)
            ])
        },
        {
          prop: 'venue',
          label: '门店',
          width: 90,
          formatter: (row: YimaiCustomer) =>
            h(ElTag, { size: 'small', effect: 'plain' }, () => row.venue)
        },
        {
          prop: 'layer',
          label: '分层',
          width: 130,
          formatter: (row: YimaiCustomer) =>
            h(
              ElTag,
              { size: 'small', type: LAYER_TAG_TYPE[row.layer], effect: 'dark' },
              () => `${row.layer} ${LAYER_LABELS[row.layer]}`
            )
        },
        {
          prop: 'consultant',
          label: '会籍顾问',
          width: 110,
          formatter: (row: YimaiCustomer) => row.consultant || h(ElTag, { size: 'small', type: 'danger' }, () => '待分配')
        },
        {
          prop: 'mainCard',
          label: '主卡 / 剩余',
          minWidth: 180,
          formatter: (row: YimaiCustomer) => {
            const expireText =
              row.expireDate && new Date(row.expireDate).getTime() - Date.now() < 60 * 86400000
                ? h('span', { class: 'text-red-500' }, ` · ${row.expireDate} 到期`)
                : row.expireDate
                  ? ` · ${row.expireDate}`
                  : ''
            return h('div', [
              h('p', {}, row.mainCard),
              h(
                'p',
                { class: 'text-xs text-gray-400' },
                [
                  row.remainTimes === null ? '未购卡' : `剩余 ${row.remainTimes} 次`,
                  expireText
                ]
              )
            ])
          }
        },
        {
          prop: 'lastVisit',
          label: '最近到店',
          width: 100,
          sortable: true,
          formatter: (row: YimaiCustomer) => {
            const d = daysAgo(row.lastVisit)
            if (d === null) return h('span', { class: 'text-gray-400' }, '未到店')
            return h('span', { class: d > 30 ? 'font-500 text-red-500' : '' }, `${d}天前`)
          }
        },
        {
          prop: 'status',
          label: '状态',
          width: 90,
          formatter: (row: YimaiCustomer) =>
            h(ElTag, { size: 'small', type: row.status === '跟进中' ? 'primary' : 'info' }, () => row.status)
        },
        {
          prop: 'nextAction',
          label: '下次动作',
          minWidth: 200,
          formatter: (row: YimaiCustomer) =>
            h('div', [
              h('p', {}, row.nextAction),
              h('p', { class: 'text-xs text-gray-400' }, `${row.nextActionTime} · 负责人 ${row.owner}`)
            ])
        },
        {
          prop: 'operation',
          label: '操作',
          width: 90,
          fixed: 'right',
          formatter: (row: YimaiCustomer) =>
            h(ArtButtonTable, { type: 'more', onClick: () => showDetail(row) })
        }
      ]
    }
  })

  function handleSearch() {
    const p = { ...searchForm.value }
    replaceSearchParams({
      ...p,
      list: (p.list as '待续课' | '出勤降低' | 'VIP' | '预流失' | '待复活' | '') || undefined
    })
    getData()
  }

  function handleReset() {
    searchForm.value = { name: '', venue: '', layer: '', list: '', haveCourse: '', remainRange: '' }
    resetSearchParams()
    getData()
  }

  function showDetail(_row: YimaiCustomer) {
    ElMessage.info('客户360详情（时间线/卡项/跟进）在阶段1交付')
  }
</script>
