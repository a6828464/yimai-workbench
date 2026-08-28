<template>
  <div class="customer-page art-full-height">
    <ElCard class="art-table-card">
      <div class="mb-4 flex flex-wrap items-center gap-3">
        <ElInput
          v-model="searchForm.name"
          placeholder="客户姓名"
          clearable
          class="!w-36"
          @change="handleSearch"
        />
        <ElInput
          v-model="searchForm.phone"
          placeholder="手机号 / 尾号"
          clearable
          class="!w-40"
          @change="handleSearch"
        />
        <ElSelect
          v-model="searchForm.venue"
          placeholder="门店"
          clearable
          class="!w-32"
          @change="handleSearch"
        >
          <ElOption label="绿地店" value="绿地店" />
          <ElOption label="东部店" value="东部店" />
        </ElSelect>
        <ElSelect
          v-model="searchForm.layer"
          placeholder="经营分层"
          clearable
          class="!w-36"
          @change="handleSearch"
        >
          <ElOption v-for="(v, k) in LAYER_LABELS" :key="k" :label="`${k} ${v}`" :value="k" />
        </ElSelect>
        <ElSelect
          v-model="searchForm.list"
          placeholder="运营清单"
          clearable
          class="!w-32"
          @change="handleSearch"
        >
          <ElOption v-for="k in LIST_KEYS" :key="k" :label="k" :value="k" />
        </ElSelect>
        <ElSelect
          v-model="searchForm.haveCourse"
          placeholder="有课卡"
          clearable
          class="!w-28"
          @change="handleSearch"
        >
          <ElOption label="有课卡" value="true" />
          <ElOption label="无课卡" value="false" />
        </ElSelect>
        <ElSelect
          v-model="searchForm.remainRange"
          placeholder="剩余课时"
          clearable
          class="!w-32"
          @change="handleSearch"
        >
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

    <!-- 客户360详情 -->
    <ElDrawer
      v-model="detail.visible"
      size="600px"
      :title="`客户360 · ${detail.customer?.name ?? ''}`"
    >
      <div v-loading="detail.loading">
        <template v-if="detail.customer">
          <ElDescriptions :column="2" border size="small" class="mb-4">
            <ElDescriptionsItem label="姓名">{{ detail.customer.name }}</ElDescriptionsItem>
            <ElDescriptionsItem label="手机号"
              >{{ maskPhone(detail.customer.phone) }}
            </ElDescriptionsItem>
            <ElDescriptionsItem label="门店">{{ detail.customer.venue }}</ElDescriptionsItem>
            <ElDescriptionsItem label="来源">{{ detail.customer.source }}</ElDescriptionsItem>
            <ElDescriptionsItem label="分层">
              <ElTag size="small" :type="LAYER_TAG_TYPE[detail.customer.layer]"
                >{{ detail.customer.layer }} {{ LAYER_LABELS[detail.customer.layer] }}</ElTag
              >
            </ElDescriptionsItem>
            <ElDescriptionsItem label="负责人">{{ detail.customer.owner }}</ElDescriptionsItem>
            <ElDescriptionsItem label="主卡">{{ detail.customer.mainCard }}</ElDescriptionsItem>
            <ElDescriptionsItem label="剩余/到期">
              {{ detail.customer.remainTimes ?? '—' }}次{{
                detail.customer.expireDate ? ` · ${detail.customer.expireDate}` : ''
              }}
            </ElDescriptionsItem>
            <ElDescriptionsItem label="出勤 M1/M2/M3"
              >{{ detail.customer.attendM1 ?? 0 }}/{{ detail.customer.attendM2 ?? 0 }}/{{
                detail.customer.attendM3 ?? 0
              }}</ElDescriptionsItem
            >
            <ElDescriptionsItem label="最近到店">{{
              detail.customer.lastVisit ? `${daysAgo(detail.customer.lastVisit)}天前` : '—'
            }}</ElDescriptionsItem>
            <ElDescriptionsItem label="下次动作" :span="2"
              >{{ detail.customer.nextAction }}（{{
                detail.customer.nextActionTime
              }}）</ElDescriptionsItem
            >
          </ElDescriptions>

          <div class="text-sm font-500 mb-2">工作流留痕</div>
          <ElTimeline v-if="detail.logs?.length" class="mb-4">
            <ElTimelineItem
              v-for="(log, i) in detail.logs"
              :key="i"
              :timestamp="log.time"
              placement="top"
            >
              <div class="text-sm">
                <ElTag size="small" effect="plain">{{ log.action }}</ElTag>
                <span class="ml-2 text-gray-500"
                  >{{ log.operatorName }}({{ log.operatorRole }})</span
                >
              </div>
              <div class="text-xs text-gray-400 mt-1">{{ log.detail }}</div>
            </ElTimelineItem>
          </ElTimeline>
          <ElEmpty v-else description="暂无留痕记录" :image-size="50" class="mb-4" />

          <div class="text-sm font-500 mb-2">关联前端客资（按手机号）</div>
          <ElTable :data="detail.leads" size="small" border v-if="detail.leads?.length">
            <ElTableColumn prop="leadDate" label="日期" width="100" />
            <ElTableColumn prop="source" label="来源" width="110" />
            <ElTableColumn prop="status" label="状态" width="100" />
            <ElTableColumn prop="demand" label="需求" min-width="140" show-overflow-tooltip />
          </ElTable>
          <ElEmpty v-else description="无关联留资" :image-size="50" />
        </template>
      </div>
    </ElDrawer>
  </div>
</template>

<script setup lang="ts">
  import ArtButtonTable from '@/components/core/forms/art-button-table/index.vue'
  import { useTable } from '@/hooks/core/useTable'
  import { queryCustomers, queryLeads, getCustomerDetail, matchConsultants } from '@/api/yimai'
  import type { YimaiCustomer, YimaiAuditLog, YimaiLead } from '@/api/yimai'
  import { ElMessage, ElTag } from 'element-plus'
  import { useUserStore } from '@/store/modules/user'

  defineOptions({ name: 'YimaiCustomers' })

  const LIST_KEYS = ['待续课', '出勤降低', 'VIP', '预流失', '待复活']

  const userStore = useUserStore()
  const roles = computed(() => userStore.getUserInfo.roles ?? [])
  const isManager = computed(() => roles.value.includes('R_MANAGER'))
  const isMedia = computed(() => roles.value.includes('R_MEDIA'))
  const scopeHint = computed(() => {
    if (isManager.value)
      return `数据范围：本店（${userStore.getUserInfo.venue}）· 按手机号聚合全部卡项`
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
    phone: '',
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
  function maskPhone(p: string | undefined | null): string {
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
      apiFn: async (p: Parameters<typeof queryCustomers>[0]) => {
        const res = await queryCustomers(p)
        if (res.records?.length) {
          const leadsRes = await queryLeads({ current: 1, size: 5000 }).catch(() => ({
            records: [] as YimaiLead[]
          }))
          res.records = matchConsultants(res.records, leadsRes.records)
        }
        return res
      },
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
              h(
                'p',
                { class: 'text-xs text-gray-400' },
                `${row.phone ? maskPhone(row.phone) : `尾号${row.phoneTail || '—'}`} · ${row.source}`
              )
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
          formatter: (row: YimaiCustomer) =>
            row.consultant || h(ElTag, { size: 'small', type: 'danger' }, () => '待分配')
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
              h('p', { class: 'text-xs text-gray-400' }, [
                row.remainTimes === null ? '未购卡' : `剩余 ${row.remainTimes} 次`,
                expireText
              ])
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
            h(
              ElTag,
              { size: 'small', type: row.status === '跟进中' ? 'primary' : 'info' },
              () => row.status
            )
        },
        {
          prop: 'nextAction',
          label: '下次动作',
          minWidth: 200,
          formatter: (row: YimaiCustomer) =>
            h('div', [
              h('p', {}, row.nextAction),
              h(
                'p',
                { class: 'text-xs text-gray-400' },
                `${row.nextActionTime} · 负责人 ${row.owner}`
              )
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
    searchForm.value = {
      name: '',
      phone: '',
      venue: '',
      layer: '',
      list: '',
      haveCourse: '',
      remainRange: ''
    }
    resetSearchParams()
    getData()
  }

  function showDetail(row: YimaiCustomer) {
    detail.customer = row
    detail.visible = true
    detail.loading = true
    getCustomerDetail(row.id)
      .then((d) => {
        detail.customer = d.customer
        detail.logs = d.logs ?? []
        detail.leads = d.leads ?? []
      })
      .catch((e) => {
        ElMessage.error(`加载详情失败：${String(e).slice(0, 100)}`)
        detail.visible = false
      })
      .finally(() => {
        detail.loading = false
      })
  }

  const detail = reactive<{
    visible: boolean
    loading: boolean
    customer: YimaiCustomer | null
    logs: YimaiAuditLog[]
    leads: YimaiLead[]
  }>({ visible: false, loading: false, customer: null, logs: [], leads: [] })
</script>
