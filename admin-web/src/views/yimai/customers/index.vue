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
          v-if="!isManager"
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

      <!-- 宽表格带左右固定列，手机上固定列会吃掉整个可见宽度，所以窄屏整体换卡片列表 -->
      <template v-if="!isHandheld">
        <ArtTable
          :loading="loading"
          :data="data"
          :columns="columns"
          :pagination="pagination"
          @pagination:size-change="handleSizeChange"
          @pagination:current-change="handleCurrentChange"
        />
      </template>

      <div v-else v-loading="loading" class="m-card-list min-h-[120px]">
        <MobileCard
          v-for="item in cardRows"
          :key="item.id"
          :title="item.title"
          :subtitle="item.subtitle"
          :tags="item.tags"
          :metrics="item.metrics"
          :note="item.note.text"
          :note-label="item.note.label"
          :actions="item.actions"
        >
          <div class="customer-card__extra">
            <div class="customer-card__line">
              <span class="customer-card__label">主卡</span>
              <span :class="{ 'is-danger': item.mainCard.danger }">{{ item.mainCard.text }}</span>
            </div>
          </div>
        </MobileCard>
        <div v-if="!loading && !cardRows.length" class="m-card-list__empty">暂无数据</div>
      </div>

      <!-- 卡片列表不自带分页，这里复用表格同一套分页状态，否则手机上只能看第一页 -->
      <div v-if="isHandheld" class="mt-4 flex justify-end">
        <ElPagination
          :current-page="pagination.current"
          :page-size="pagination.size"
          :total="pagination.total"
          layout="total, prev, pager, next"
          @current-change="handleCurrentChange"
        />
      </div>
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
            <ElDescriptionsItem label="手机号">
              <span class="tabular-nums">{{ detail.customer.phone || '—' }}</span>
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
  import { queryCustomers, getCustomerDetail } from '@/api/yimai'
  import type { YimaiCustomer, YimaiAuditLog, YimaiLead } from '@/api/yimai'
  import { ElMessage, ElTag } from 'element-plus'
  import { useUserStore } from '@/store/modules/user'
  import { useDevice } from '@/hooks/core/useDevice'
  import type {
    MobileCardAction,
    MobileCardMetric,
    MobileCardTag
  } from '@/components/business/mobile-card/types'

  defineOptions({ name: 'YimaiCustomers' })

  // 手持设备上用卡片列表代替宽表格（见下方 cardTags / cardMetrics / cardNote）
  const { isHandheld } = useDevice()

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
      // 会籍顾问回填已由后端 /customers 在分页内完成（按手机号取留资服务老师），前端不再全量拉留资
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
              h(
                'p',
                { class: 'text-xs text-gray-400' },
                `${row.phone || (row.phoneTail ? '尾号' + row.phoneTail : '—')} · ${row.source}`
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

  // ---------- 移动端卡片 ----------
  //
  // 表格 9 列（含左右固定列），手机上固定列就占满了可见宽度，窄屏一律换成卡片。
  // 卡片只保留经营动作必需的信息：门店/顾问/分层/状态（标签）、剩余课时与最近到店（指标）、
  // 主卡与下一步动作（补充信息），明细仍走客户360抽屉。

  /** 顶部标签：门店 + 顾问（空则标红「待分配」）+ 分层 + 状态，口径与表格列一致 */
  function cardTags(row: YimaiCustomer): MobileCardTag[] {
    return [
      { text: row.venue, effect: 'plain' },
      row.consultant
        ? { text: row.consultant, effect: 'plain' }
        : { text: '待分配', type: 'danger', effect: 'plain' },
      {
        text: `${row.layer} ${LAYER_LABELS[row.layer]}`,
        type: LAYER_TAG_TYPE[row.layer],
        effect: 'dark'
      },
      { text: row.status, type: row.status === '跟进中' ? 'primary' : 'info', effect: 'plain' }
    ]
  }

  /** 关键指标：剩余课时 + 最近到店（超过 30 天未到店标红，与表格同口径） */
  function cardMetrics(row: YimaiCustomer): MobileCardMetric[] {
    const visitDays = daysAgo(row.lastVisit)
    return [
      {
        label: '剩余课时',
        value: row.remainTimes === null ? '未购卡' : row.remainTimes,
        unit: row.remainTimes === null ? '' : '次'
      },
      {
        label: '最近到店',
        value: visitDays === null ? '未到店' : `${visitDays}天前`,
        danger: visitDays !== null && visitDays > 30
      }
    ]
  }

  /** 主卡说明：主卡 / 剩余 / 到期，60 天内到期标红（沿用表格「主卡 / 剩余」列判定） */
  function cardMainCard(row: YimaiCustomer): { text: string; danger: boolean } {
    const remainText = row.remainTimes === null ? '未购卡' : `剩余 ${row.remainTimes} 次`
    const expireText = row.expireDate ? ` · ${row.expireDate} 到期` : ''
    const nearExpire =
      !!row.expireDate && new Date(row.expireDate).getTime() - Date.now() < 60 * 86400000
    return { text: `${row.mainCard} · ${remainText}${expireText}`, danger: nearExpire }
  }

  /** 补充说明：下一步动作 —— 经营池每天最需要盯的就是这一条 */
  function cardNote(row: YimaiCustomer): { text: string; label: string } {
    return {
      label: '下次动作',
      text: `${row.nextAction} · ${row.nextActionTime} · 负责人 ${row.owner}`
    }
  }

  /** 卡片操作：桌面端也只有「详情」一个入口，保持一致 */
  function cardActions(row: YimaiCustomer): MobileCardAction[] {
    return [{ text: '查看详情', type: 'primary', onClick: () => showDetail(row) }]
  }

  /** 预计算一次，避免模板里对每行重复调用四个函数 */
  const cardRows = computed(() =>
    data.value.map((row) => ({
      id: row.id,
      title: row.name,
      subtitle: `${row.phone || (row.phoneTail ? `尾号${row.phoneTail}` : '—')} · ${row.source}`,
      tags: cardTags(row),
      metrics: cardMetrics(row),
      mainCard: cardMainCard(row),
      note: cardNote(row),
      actions: cardActions(row)
    }))
  )
</script>

<style scoped lang="scss">
  // 移动端卡片里的补充信息（主卡 / 剩余 / 到期）
  .customer-card {
    &__extra {
      margin-top: 10px;
      padding-top: 10px;
      border-top: 1px dashed var(--art-card-border);
    }

    &__line {
      font-size: 13px;
      line-height: 1.6;
      color: var(--art-gray-700);

      .is-danger {
        font-weight: 500;
        color: var(--el-color-danger);
      }
    }

    &__label {
      display: inline-block;
      min-width: 3.5em;
      margin-right: 4px;
      color: var(--art-gray-500);
    }
  }
</style>
