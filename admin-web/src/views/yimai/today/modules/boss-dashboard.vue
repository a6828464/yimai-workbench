<template>
  <div class="p-4">
    <!-- 控制栏 -->
    <div class="mb-4 flex flex-wrap items-center gap-3">
      <span class="text-sm font-500">经营总览</span>
      <ElRadioGroup v-model="venueScope" @change="onVenueChange">
        <ElRadioButton value="双店">双店合计</ElRadioButton>
        <ElRadioButton value="绿地店">绿地店</ElRadioButton>
        <ElRadioButton value="东部店">东部店</ElRadioButton>
      </ElRadioGroup>
      <DateRangeControl
        :start="range[0]"
        :end="range[1]"
        :shortcuts="shortcuts"
        @change="onRangeChange"
      />
      <div class="flex-1" />
      <span class="text-xs text-gray-400">{{ scopeLabel }} · 默认为本月1号至今</span>
    </div>

    <div v-if="dashboardError" class="mb-4 text-sm text-orange-500">{{ dashboardError }}</div>
    <div v-if="todaySummaryError" class="mb-4 text-sm text-orange-500">
      {{ todaySummaryError }}
    </div>

    <div class="grid grid-cols-2 gap-3 mb-4">
      <YimaiKpiCard
        label="随心瑜今日预约"
        :value="todayBookingCount"
        :hint="todayKindHint"
        :icon="ticketIcon"
        accent="#409EFF"
      />
      <YimaiKpiCard
        label="随心瑜今日体验预约"
        :value="todayTrialCount"
        :hint="todaySummary?.snapshotTime ? `快照 ${todaySummary.snapshotTime}` : '尚无成功快照'"
        :icon="userIcon"
        accent="#E6A23C"
      />
    </div>

    <!-- 门店经营 KPI（店长视角） -->
    <div class="text-sm font-500 mb-3 flex items-center gap-2">
      <span>门店经营</span>
      <span class="text-xs font-400 text-gray-400">约课 · 上课班次 · 售卡 · 金额</span>
    </div>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
      <YimaiKpiCard v-for="k in storeKpis" :key="k.label" v-bind="k" />
    </div>

    <!-- 双店对比 + 趋势 -->
    <ElRow :gutter="16" class="mb-5">
      <ElCol v-if="venueScope === '双店'" :xs="24" :lg="10" class="mb-4">
        <ElCard shadow="never">
          <template #header><span class="font-500">双店门店经营对比</span></template>
          <div v-if="comparisonError" class="text-sm text-orange-500">
            {{ comparisonError }}
          </div>
          <ArtBarChart
            v-else
            height="260px"
            :data="compareSeries"
            :x-axis-data="compareLabels"
            bar-width="34"
            :border-radius="6"
            :loading="loading"
          />
        </ElCard>
      </ElCol>
      <ElCol :xs="24" :lg="venueScope === '双店' ? 14 : 24" class="mb-4">
        <ElCard shadow="never">
          <template #header><span class="font-500">售卡金额趋势（元）</span></template>
          <ArtLineChart
            height="260px"
            :data="amountSeries"
            :x-axis-data="labels"
            :show-area-color="true"
            :show-axis-line="false"
            :loading="loading"
          />
        </ElCard>
      </ElCol>
    </ElRow>

    <!-- 新媒体运营 KPI -->
    <div class="text-sm font-500 mb-3 flex items-center gap-2">
      <span>新媒体运营</span>
      <span class="text-xs font-400 text-gray-400">留资 · 到店 · 线上成交率 · 核销</span>
    </div>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
      <YimaiKpiCard v-for="k in mediaKpis" :key="k.label" v-bind="k" />
    </div>

    <ElRow :gutter="16">
      <ElCol :xs="24" :lg="14" class="mb-4">
        <ElCard shadow="never">
          <template #header>
            <div class="flex-cb">
              <span class="font-500">留资 / 到店趋势（人）</span>
              <div class="flex gap-3 text-xs text-gray-400">
                <span>— 留资</span><span style="color: var(--el-color-success)">— 到店</span>
              </div>
            </div>
          </template>
          <ArtLineChart
            height="240px"
            :data="mediaTrendSeries"
            :x-axis-data="labels"
            :show-axis-line="false"
            :loading="loading"
          />
        </ElCard>
      </ElCol>
      <ElCol :xs="24" :lg="10" class="mb-4">
        <ElCard shadow="never">
          <template #header><span class="font-500">各渠道留资对比</span></template>
          <div v-if="channelsError" class="text-sm text-orange-500">
            {{ channelsError }}
          </div>
          <ArtBarChart
            v-else
            height="240px"
            :data="channelSeries"
            :x-axis-data="channelLabels"
            bar-width="22"
            :border-radius="6"
            :loading="loading"
          />
        </ElCard>
      </ElCol>
    </ElRow>

    <ElCard shadow="never" class="mt-4">
      <template #header>
        <div class="flex-cb">
          <span class="font-500">随心瑜经营概览</span>
          <span class="text-xs text-gray-400">{{
            overviewFetchedAt ? `读取于 ${overviewFetchedAt}` : '尚未读取'
          }}</span>
        </div>
      </template>
      <div v-if="overviewError" class="text-sm text-orange-500">{{ overviewError }}</div>
      <div v-else class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div
          v-for="cell in overviewCells"
          :key="cell.label"
          class="rounded-lg bg-gray-50 dark:bg-gray-800 p-3 text-center"
        >
          <div class="text-lg font-600">{{ cell.value }}</div>
          <div class="mt-0.5 text-xs text-gray-400">{{ cell.label }}</div>
        </div>
      </div>
      <div v-if="overviewVisitorAvailable" class="mt-3 text-xs text-gray-400">
        本月访客（{{ venueScope }}）：新增 {{ overviewSum.monthNewVisitors }} · 访客上课
        {{ overviewSum.monthVisitorClasses }} · 转化会员
        {{ overviewSum.monthVisitorConversions }}
      </div>
      <div v-if="overviewVenueErrors.length" class="mt-2 text-xs text-orange-500">
        部分门店读取失败：{{ overviewVenueErrors.join('；') }}
      </div>
    </ElCard>

    <ElCard shadow="never" class="mt-4">
      <template #header>
        <div class="flex-cb">
          <span class="font-500">未完成合同签署</span>
          <div class="flex items-center gap-3">
            <span class="text-xs text-gray-400">{{ contractsFetchedAt || '尚未读取' }}</span>
            <ElButton
              link
              type="primary"
              :disabled="!contractPendingCount"
              @click="openContractDialog()"
            >
              查看未签名单
              <template v-if="contractPendingCount">（{{ contractPendingCount }}）</template>
            </ElButton>
          </div>
        </div>
      </template>
      <div v-if="contractError" class="text-sm text-orange-500">{{ contractError }}</div>
      <div v-else class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div
          v-for="(item, venue) in contractVenues"
          :key="venue"
          class="rounded-lg bg-gray-50 dark:bg-gray-800 p-3"
        >
          <div class="flex-cb mb-2">
            <span class="font-500">{{ venue }}</span>
            <ElButton
              v-if="!item.error && item.items?.length"
              link
              type="primary"
              size="small"
              @click="openContractDialog(venue)"
            >
              查看名单（{{ item.items.length }}）
            </ElButton>
          </div>
          <div v-if="item.error" class="text-sm text-orange-500">读取失败：{{ item.error }}</div>
          <template v-else>
            <div class="flex flex-wrap gap-4 text-sm">
              <span
                >签署中 <b>{{ item.signing ?? item.pendingCustomer + item.pendingVenue }}</b></span
              >
              <span
                >待会员签署 <b class="text-orange-500">{{ item.pendingCustomer }}</b></span
              >
              <span
                >待场馆签署 <b class="text-red-500">{{ item.pendingVenue }}</b></span
              >
              <span
                >已过期
                <b :class="(item.expired ?? 0) > 0 ? 'text-red-500' : ''">{{
                  item.expired ?? 0
                }}</b>
              </span>
            </div>
            <div v-if="!item.fieldConfirmed" class="mt-2 text-xs text-gray-400"
              >暂无可确认的双方签署字段；未知 {{ item.unknown }} 条，不计入未签</div
            >
          </template>
        </div>
      </div>
    </ElCard>

    <!-- 未签名单弹窗 -->
    <ElDialog
      v-model="contractDialog.visible"
      :title="`未完成合同签署名单${contractDialog.venue ? '（' + contractDialog.venue + '）' : ''}`"
      width="720px"
      destroy-on-close
    >
      <div v-if="contractError" class="text-sm text-orange-500 mb-3">{{ contractError }}</div>
      <ElAlert
        v-if="contractDialogVenueItem && !contractDialogVenueItem.fieldConfirmed"
        type="warning"
        :closable="false"
        class="mb-3"
        show-icon
      >
        上游签署字段尚未确认，未知
        {{ contractDialogVenueItem.unknown }} 条未计入；以下名单为已识别字段的未签合同。
      </ElAlert>
      <ElEmpty v-if="!contractDialog.items.length" description="暂无可识别的未签合同" />
      <ElTable v-else :data="contractDialog.items" border stripe size="small" max-height="460">
        <ElTableColumn prop="memberName" label="会员姓名" min-width="110" />
        <ElTableColumn label="签约时间/合同" min-width="160" show-overflow-tooltip>
          <template #default="{ row }">{{ row.name }}</template>
        </ElTableColumn>
        <ElTableColumn label="待会员签署" width="100" align="center">
          <template #default="{ row }">
            <ElTag v-if="row.customerState === 'incomplete'" size="small" type="warning"
              >待签</ElTag
            >
            <ElTag v-else-if="row.customerState === 'completed'" size="small" type="success"
              >已签</ElTag
            >
            <span v-else class="text-gray-400">未知</span>
          </template>
        </ElTableColumn>
        <ElTableColumn label="待场馆签署" width="100" align="center">
          <template #default="{ row }">
            <ElTag v-if="row.venueState === 'incomplete'" size="small" type="danger">待签</ElTag>
            <ElTag v-else-if="row.venueState === 'completed'" size="small" type="success"
              >已签</ElTag
            >
            <span v-else class="text-gray-400">未知</span>
          </template>
        </ElTableColumn>
        <ElTableColumn prop="statusRaw" label="上游状态" min-width="120" show-overflow-tooltip />
      </ElTable>
    </ElDialog>

    <!-- 今日待办（概要，点击进入完整待办页） -->
    <YimaiTodayTodo variant="summary" class="mt-4" />
  </div>
</template>

<script setup lang="ts">
  import YimaiKpiCard from './kpi-card.vue'
  import YimaiTodayTodo from './today-todo.vue'
  import {
    getDashboardSeries,
    getChannelBreakdown,
    getTodaySummary,
    getPendingContracts,
    getKyOverview
  } from '@/api/yimai'
  import type {
    DashboardDayPoint,
    DashboardSummary,
    ChannelLeadItem,
    TodaySummary,
    KyVenueOverview
  } from '@/api/yimai'
  import {
    Ticket,
    User,
    ShoppingBag,
    Wallet,
    DataLine,
    Coin,
    Odometer
  } from '@element-plus/icons-vue'
  import type { LineDataItem } from '@/types/component/chart'
  import DateRangeControl from './date-range-control.vue'

  defineOptions({ name: 'BossDashboard' })

  const loading = ref(true)
  const venueScope = ref<'双店' | '绿地店' | '东部店'>('双店')
  const range = ref<[string, string]>(defaultRange())
  const daily = ref<DashboardDayPoint[]>([])
  const summary = ref<DashboardSummary | null>(null)
  const channels = ref<ChannelLeadItem[]>([])
  const ldSummary = ref<DashboardSummary | null>(null)
  const dbSummary = ref<DashboardSummary | null>(null)
  const todaySummary = ref<TodaySummary | null>(null)
  const dashboardError = ref('')
  const comparisonError = ref('')
  const channelsError = ref('')
  const todaySummaryError = ref('')
  const overviewVenues = ref<Record<string, KyVenueOverview>>({})
  const overviewFetchedAt = ref('')
  const overviewError = ref('')
  const overviewLoaded = ref(false)
  const ticketIcon = markRaw(Ticket)
  const userIcon = markRaw(User)

  const money = (v: number): string =>
    Number(Math.round(v)).toLocaleString('zh-CN', { maximumFractionDigits: 0 })

  /** 随心瑜经营概览：按当前门店范围（双店/单店）汇总 */
  const overviewScopeVenues = computed(() => {
    const all = overviewVenues.value
    if (venueScope.value === '双店') return ['绿地店', '东部店'].map((v) => all[v]).filter(Boolean)
    return all[venueScope.value] ? [all[venueScope.value]] : []
  })
  const overviewSum = computed(() => {
    const venues = overviewScopeVenues.value.filter((v) => !v.error)
    const pick = (
      fn: (o: KyVenueOverview) => number,
      available?: (o: KyVenueOverview) => boolean
    ) => venues.filter((o) => (available ? available(o) : true)).reduce((sum, o) => sum + fn(o), 0)
    return {
      thisMonthRevenue: pick((o) => o.thisMonthRevenue),
      thisMonthUsage: pick((o) => o.thisMonthUsage),
      remainingAssets: pick((o) => o.remainingAssets),
      activeMembers: pick(
        (o) => o.activeMembers,
        (o) => o.activityAvailable
      ),
      thisMonthClassMembers: pick(
        (o) => o.thisMonthClassMembers,
        (o) => o.activityAvailable
      ),
      riskMembers: pick(
        (o) => o.riskMembers,
        (o) => o.activityAvailable
      ),
      inactiveMembers: pick(
        (o) => o.inactiveMembers,
        (o) => o.activityAvailable
      ),
      lostMembers: pick(
        (o) => o.lostMembers,
        (o) => o.activityAvailable
      ),
      monthNewVisitors: pick(
        (o) => o.monthNewVisitors,
        (o) => o.visitorAvailable
      ),
      monthVisitorClasses: pick(
        (o) => o.monthVisitorClasses,
        (o) => o.visitorAvailable
      ),
      monthVisitorConversions: pick(
        (o) => o.monthVisitorConversions,
        (o) => o.visitorAvailable
      ),
      activityAvailable: venues.some((o) => o.activityAvailable),
      visitorAvailable: venues.some((o) => o.visitorAvailable)
    }
  })
  const overviewCells = computed(() => {
    if (!overviewScopeVenues.value.length) return []
    const s = overviewSum.value
    return [
      { label: '本月收入（元）', value: `¥${money(s.thisMonthRevenue)}` },
      { label: '本月耗卡（元）', value: `¥${money(s.thisMonthUsage)}` },
      { label: '剩余资产（元）', value: `¥${money(s.remainingAssets)}` },
      { label: '活跃会员', value: s.activityAvailable ? String(s.activeMembers) : '—' },
      {
        label: '本月上课（人）',
        value: s.activityAvailable ? String(s.thisMonthClassMembers) : '—'
      },
      { label: '风险会员', value: s.activityAvailable ? String(s.riskMembers) : '—' },
      { label: '沉寂会员', value: s.activityAvailable ? String(s.inactiveMembers) : '—' },
      { label: '流失会员', value: s.activityAvailable ? String(s.lostMembers) : '—' }
    ]
  })
  const overviewVenueErrors = computed(() =>
    overviewScopeVenues.value
      .filter((v) => v.error)
      .map((v) => {
        const name = Object.entries(overviewVenues.value).find(([, o]) => o === v)?.[0] ?? ''
        return `${name}：${v.error}`
      })
  )
  const overviewVisitorAvailable = computed(() => overviewSum.value.visitorAvailable)

  function defaultRange(): [string, string] {
    const now = new Date()
    const first = new Date(now.getFullYear(), now.getMonth(), 1)
    return [iso(first), iso(now)]
  }

  function iso(d: Date): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
  }

  const shortcuts = [
    {
      text: '本月',
      value: () => [new Date(new Date().getFullYear(), new Date().getMonth(), 1), new Date()]
    },
    {
      text: '上周',
      value: () => {
        const now = new Date()
        const currentMonday = new Date(now)
        currentMonday.setDate(now.getDate() - ((now.getDay() + 6) % 7))
        const s = new Date(currentMonday)
        s.setDate(currentMonday.getDate() - 7)
        const e = new Date(s)
        e.setDate(s.getDate() + 6)
        return [s, e]
      }
    },
    {
      text: '近30天',
      value: () => {
        const e = new Date()
        const s = new Date()
        s.setDate(s.getDate() - 30)
        return [s, e]
      }
    }
  ]

  const scopeLabel = computed(() =>
    venueScope.value === '双店'
      ? `绿地 ¥${(ldSummary.value?.dealAmount ?? 0).toLocaleString()} · 东部 ¥${(dbSummary.value?.dealAmount ?? 0).toLocaleString()}`
      : '单店视图'
  )

  const labels = computed(() => daily.value.map((p) => p.label))
  const amountSeries = computed(() => daily.value.map((p) => p.amount))
  const mediaTrendSeries = computed<LineDataItem[]>(() => [
    { name: '留资', data: daily.value.map((p) => p.leads) },
    { name: '到店', data: daily.value.map((p) => p.visits), color: '#67C23A' }
  ])
  const channelLabels = computed(() => channels.value.map((c) => c.channel))
  const channelSeries = computed(() => channels.value.map((c) => c.leads))

  const compareLabels = ['售卡张数', '上课班次', '约课人数']
  const compareSeries = computed(() => [
    {
      name: '绿地店',
      data: [
        ldSummary.value?.cardSalesCount ?? 0,
        ldSummary.value?.classCount ?? 0,
        ldSummary.value?.bookingCount ?? 0
      ],
      stack: undefined
    },
    {
      name: '东部店',
      data: [
        dbSummary.value?.cardSalesCount ?? 0,
        dbSummary.value?.classCount ?? 0,
        dbSummary.value?.bookingCount ?? 0
      ]
    }
  ])
  const todayBookingCount = computed(() => {
    const tb = todaySummary.value?.todayBookings
    if (!tb) return '-'
    if (venueScope.value === '双店') return Number(tb['绿地店'] ?? 0) + Number(tb['东部店'] ?? 0)
    return tb[venueScope.value] ?? '-'
  })
  const todayTrialCount = computed(() => {
    const tb = todaySummary.value?.trialBookings
    if (!tb) return '-'
    if (venueScope.value === '双店') return Number(tb['绿地店'] ?? 0) + Number(tb['东部店'] ?? 0)
    return tb[venueScope.value] ?? '-'
  })
  const todayKindHint = computed(() => {
    const tk = todaySummary.value?.todayKinds
    if (!tk) return '来自最近成功快照'
    const venues =
      venueScope.value === '双店' ? (['绿地店', '东部店'] as const) : ([venueScope.value] as const)
    const sum = { 私教: 0, 小班: 0, 团课: 0 }
    for (const v of venues) {
      const k = tk[v]
      if (k) {
        sum.私教 += Number(k.私教 ?? 0)
        sum.小班 += Number(k.小班 ?? 0)
        sum.团课 += Number(k.团课 ?? 0)
      }
    }
    if (sum.私教 + sum.小班 + sum.团课 === 0) {
      return '团课 + 私教预约记录（在 KeepYoga 同步页「更新快照」后显示细分）'
    }
    return `私教 ${sum.私教} · 小班 ${sum.小班} · 团课 ${sum.团课}，来自最近成功快照`
  })

  const storeKpis = computed(() => [
    {
      label: '约课人数',
      value: summary.value?.bookingCount ?? '-',
      hint: summary.value?.bookingCount
        ? `私教 ${summary.value?.privateBookingCount ?? 0} · 小班 ${summary.value?.smallBookingCount ?? 0} · 团课 ${summary.value?.groupBookingCount ?? 0}`
        : '本时段暂无预约数据，可在 KeepYoga 同步后查看',
      icon: markRaw(Ticket),
      accent: '#409EFF'
    },
    {
      label: '上课班次',
      value: summary.value?.classCount ?? '-',
      hint: summary.value?.classCount
        ? `私教 ${summary.value?.privateClassCount ?? 0} · 小班 ${summary.value?.smallClassCount ?? 0} · 团课 ${summary.value?.groupClassCount ?? 0}`
        : '本时段暂无签到班次数据',
      icon: markRaw(User),
      accent: '#E6A23C'
    },
    {
      label: '售卡张数',
      value: summary.value?.cardSalesCount ?? '-',
      hint: '随心瑜会员卡实收口径（非财务流水）',
      icon: markRaw(ShoppingBag),
      accent: '#67C23A'
    },
    {
      label: '售卡金额',
      value: summary.value?.dealAmount ?? '-',
      prefix: '¥',
      hint: '随心瑜会员卡实收口径（非财务流水）',
      icon: markRaw(Wallet),
      accent: '#9C27B0'
    }
  ])

  const mediaKpis = computed(() => [
    {
      label: '留资人数',
      value: summary.value?.leadCount ?? '-',
      icon: markRaw(DataLine),
      accent: '#409EFF'
    },
    {
      label: '线上新客成交率',
      value: summary.value ? `${summary.value.onlineDealRate}` : '-',
      suffix: '%',
      hint: `${summary.value?.onlineDealCount ?? 0}/${summary.value?.onlineLeadCount ?? 0} 人`,
      icon: markRaw(Odometer),
      accent: '#E6A23C'
    },
    {
      label: '到店人数',
      value: summary.value?.visitCount ?? '-',
      icon: markRaw(User),
      accent: '#9C27B0'
    },
    {
      label: '核销金额',
      value: summary.value?.redeemAmount ?? '-',
      prefix: '¥',
      hint: '平台团购券',
      icon: markRaw(Coin),
      accent: '#FF9800'
    }
  ])

  const contractVenues = ref<Awaited<ReturnType<typeof getPendingContracts>>['venues']>({})
  const contractsFetchedAt = ref('')
  const contractError = ref('')
  const contractsLoaded = ref(false)

  /** 未签名单弹窗 */
  const contractDialog = reactive({
    visible: false,
    venue: '' as string,
    items: [] as Array<Record<string, string>>
  })

  const contractDialogVenueItem = computed(() =>
    contractDialog.venue ? (contractVenues.value[contractDialog.venue] ?? null) : null
  )

  /** 全部门店未签合同总数（含未知字段） */
  const contractPendingCount = computed(() =>
    Object.values(contractVenues.value).reduce((s, v) => s + (v.items?.length ?? 0), 0)
  )

  function openContractDialog(venue = '') {
    contractDialog.venue = venue
    const item = venue ? (contractVenues.value[venue] ?? null) : null
    contractDialog.items =
      (item?.items as unknown as Array<Record<string, string>> | undefined) ??
      Object.values(contractVenues.value).flatMap(
        (v) => (v.items as unknown as Array<Record<string, string>> | undefined) ?? []
      )
    contractDialog.visible = true
  }

  function onRangeChange(v: [string, string]) {
    range.value = v
    reload()
  }

  function onVenueChange() {
    reload()
  }

  function requestError(label: string, error: unknown): string {
    return `${label}读取失败：${String((error as { message?: string }).message ?? error).slice(0, 100)}`
  }

  async function reload(loadStatic = false) {
    loading.value = true
    try {
      const isDualStore = venueScope.value === '双店'
      const shouldLoadContracts = loadStatic || !contractsLoaded.value
      const shouldLoadOverview = loadStatic || !overviewLoaded.value
      ldSummary.value = null
      dbSummary.value = null
      comparisonError.value = ''

      const results = await Promise.allSettled([
        getDashboardSeries(range.value[0], range.value[1], venueScope.value),
        getChannelBreakdown(range.value[0], range.value[1], venueScope.value),
        getTodaySummary(),
        shouldLoadContracts ? getPendingContracts() : Promise.resolve(null),
        shouldLoadOverview ? getKyOverview() : Promise.resolve(null),
        isDualStore
          ? getDashboardSeries(range.value[0], range.value[1], '绿地店')
          : Promise.resolve(null),
        isDualStore
          ? getDashboardSeries(range.value[0], range.value[1], '东部店')
          : Promise.resolve(null)
      ] as const)

      if (results[0].status === 'fulfilled') {
        daily.value = results[0].value.daily
        summary.value = results[0].value.summary
        dashboardError.value = ''
      } else {
        daily.value = []
        summary.value = null
        dashboardError.value = requestError('经营数据', results[0].reason)
      }
      if (results[1].status === 'fulfilled') {
        channels.value = results[1].value
        channelsError.value = ''
      } else {
        channels.value = []
        channelsError.value = requestError('渠道数据', results[1].reason)
      }
      if (results[2].status === 'fulfilled') {
        todaySummary.value = results[2].value
        todaySummaryError.value = ''
      } else {
        todaySummary.value = null
        todaySummaryError.value = requestError('今日预约', results[2].reason)
      }
      if (shouldLoadContracts) {
        if (results[3].status === 'fulfilled' && results[3].value) {
          contractVenues.value = results[3].value.venues
          contractsFetchedAt.value = results[3].value.fetchedAt
          contractError.value = ''
          contractsLoaded.value = true
        } else if (results[3].status === 'rejected') {
          contractVenues.value = {}
          contractsFetchedAt.value = ''
          contractError.value = requestError('合同', results[3].reason)
        }
      }
      if (shouldLoadOverview) {
        if (results[4].status === 'fulfilled' && results[4].value) {
          overviewVenues.value = results[4].value.venues
          overviewFetchedAt.value = results[4].value.fetchedAt
          overviewError.value = ''
          overviewLoaded.value = true
        } else if (results[4].status === 'rejected') {
          overviewVenues.value = {}
          overviewFetchedAt.value = ''
          overviewError.value = requestError('经营概览', results[4].reason)
        }
      }
      if (isDualStore) {
        const comparisonErrors: string[] = []
        if (results[5].status === 'fulfilled' && results[5].value) {
          ldSummary.value = results[5].value.summary
        } else if (results[5].status === 'rejected') {
          comparisonErrors.push(requestError('绿地店经营数据', results[5].reason))
        }
        if (results[6].status === 'fulfilled' && results[6].value) {
          dbSummary.value = results[6].value.summary
        } else if (results[6].status === 'rejected') {
          comparisonErrors.push(requestError('东部店经营数据', results[6].reason))
        }
        comparisonError.value = comparisonErrors.join('；')
      }
    } finally {
      loading.value = false
    }
  }

  onMounted(() => reload(true))
</script>
