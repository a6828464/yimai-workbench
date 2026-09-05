<template>
  <div class="p-4">
    <!-- 控制栏 -->
    <div class="mb-4 flex flex-wrap items-center gap-3">
      <span class="text-sm font-500">经营数据</span>
      <ElTag size="small" effect="plain">本店 · {{ userStore.getUserInfo.venue }}</ElTag>
      <DateRangeControl
        :start="range[0]"
        :end="range[1]"
        :shortcuts="shortcuts"
        @change="onRangeChange"
      />
      <div class="flex-1" />
      <span class="text-xs text-gray-400">默认为本月1号至今，可自行调整</span>
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

    <!-- KPI -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
      <YimaiKpiCard v-for="k in kpis" :key="k.label" v-bind="k" />
    </div>

    <!-- 图表区 -->
    <ElRow :gutter="16" class="mb-4">
      <ElCol :xs="24" :lg="14" class="mb-4">
        <ElCard shadow="never">
          <template #header><span class="font-500">售卡金额趋势（元）</span></template>
          <ArtLineChart
            height="280px"
            :data="amountSeries"
            :x-axis-data="labels"
            :show-area-color="true"
            :show-axis-line="false"
            :loading="loading"
          />
        </ElCard>
      </ElCol>
      <ElCol :xs="24" :lg="10" class="mb-4">
        <ElCard shadow="never">
          <template #header>
            <div class="flex-cb">
              <span class="font-500">约课 / 上课班次 / 售卡（张）</span>
              <div class="flex gap-3 text-xs text-gray-400">
                <span>— 约课</span><span style="color: var(--el-color-primary)">— 上课</span
                ><span style="color: var(--el-color-success)">— 售卡</span>
              </div>
            </div>
          </template>
          <ArtLineChart
            height="280px"
            :data="funnelSeries"
            :x-axis-data="labels"
            :show-axis-line="false"
            :loading="loading"
          />
        </ElCard>
      </ElCol>
    </ElRow>

    <ElCard shadow="never" class="mb-4">
      <template #header><span class="font-500">未完成合同签署</span></template>
      <div v-if="contractError" class="text-sm text-orange-500">{{ contractError }}</div>
      <div v-else-if="contractVenue" class="flex flex-wrap gap-5 text-sm">
        <span
          >待会员签署 <b class="text-orange-500">{{ contractVenue.pendingCustomer }}</b></span
        >
        <span
          >待场馆签署 <b class="text-red-500">{{ contractVenue.pendingVenue }}</b></span
        >
        <span v-if="!contractVenue.fieldConfirmed" class="text-xs text-gray-400"
          >上游签署字段尚未确认，未知 {{ contractVenue.unknown }} 条未计入</span
        >
      </div>
    </ElCard>

    <!-- 待办区 -->
    <ElRow :gutter="16">
      <ElCol :xs="24" :lg="14" class="mb-4">
        <ElCard shadow="never">
          <template #header>
            <div class="flex items-center justify-between">
              <span class="font-500">待跟进队列</span>
              <ElButton link type="primary" @click="$router.push('/yimai/members')"
                >进入会员管理</ElButton
              >
            </div>
          </template>
          <div v-loading="loading">
            <div
              v-for="c in followups"
              :key="c.id"
              class="follow-item flex items-center justify-between py-3"
            >
              <div class="min-w-0">
                <div class="flex items-center gap-2">
                  <ElTag :type="layerConfig[c.layer].type" size="small" effect="dark">{{
                    c.layer
                  }}</ElTag>
                  <span class="font-500">{{ c.name }}</span>
                  <span class="text-xs text-gray-400">尾号{{ c.phoneTail }}</span>
                </div>
                <div class="mt-1 truncate text-xs text-gray-500">
                  下一步：{{ c.nextAction }}（{{ c.nextActionTime }}）
                </div>
              </div>
              <div
                class="shrink-0 text-right text-xs"
                :class="(c.lastVisitDays ?? 99) > 30 ? 'text-red-500' : 'text-gray-400'"
              >
                {{ c.lastVisit ? `${c.lastVisitDays}天未到店` : '未到店' }}
              </div>
            </div>
            <ElEmpty
              v-if="!loading && !followups.length"
              description="暂无待跟进对象"
              :image-size="70"
            />
          </div>
        </ElCard>
      </ElCol>
      <ElCol :xs="24" :lg="10" class="mb-4">
        <ElCard shadow="never">
          <template #header><span class="font-500">风险提醒</span></template>
          <div v-loading="loading">
            <div
              v-for="r in risks"
              :key="r.id"
              class="flex items-start gap-3 py-3 border-b border-gray-100 last:border-0 dark:border-gray-800"
            >
              <ElTag
                :type="r.level === '高' ? 'danger' : r.level === '中' ? 'warning' : 'info'"
                size="small"
                >{{ r.level }}</ElTag
              >
              <div class="min-w-0 flex-1">
                <div class="text-sm leading-5">{{ r.text }}</div>
                <div class="mt-1 text-xs text-primary">建议动作：{{ r.action }}</div>
              </div>
            </div>
            <ElEmpty v-if="!loading && !risks.length" description="暂无风险" :image-size="70" />
          </div>
        </ElCard>
      </ElCol>
    </ElRow>
  </div>
</template>

<script setup lang="ts">
  import YimaiKpiCard from './kpi-card.vue'
  import {
    getDashboardSeries,
    getFollowupQueue,
    getRiskAlerts,
    getTodaySummary,
    getPendingContracts
  } from '@/api/yimai'
  import type {
    YimaiCustomer,
    DashboardDayPoint,
    DashboardSummary,
    TodaySummary,
    PendingContracts
  } from '@/api/yimai'
  import { useUserStore } from '@/store/modules/user'
  import DateRangeControl from './date-range-control.vue'
  import { User, Ticket, ShoppingBag, Wallet } from '@element-plus/icons-vue'
  import type { LineDataItem } from '@/types/component/chart'

  defineOptions({ name: 'ManagerDashboard' })

  const userStore = useUserStore()

  const loading = ref(true)
  const range = ref<[string, string]>(defaultRange())
  const daily = ref<DashboardDayPoint[]>([])
  const summary = ref<DashboardSummary | null>(null)
  const followups = ref<(YimaiCustomer & { lastVisitDays: number })[]>([])
  const risks = ref<Awaited<ReturnType<typeof getRiskAlerts>>>([])
  const todaySummary = ref<TodaySummary | null>(null)
  const ticketIcon = markRaw(Ticket)
  const userIcon = markRaw(User)

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
        const e = new Date()
        const s = new Date()
        s.setDate(s.getDate() - 7)
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

  const labels = computed(() => daily.value.map((p) => p.label))
  const amountSeries = computed(() => daily.value.map((p) => p.amount))
  const funnelSeries = computed<LineDataItem[]>(() => [
    { name: '约课', data: daily.value.map((p) => p.bookings) },
    { name: '上课班次', data: daily.value.map((p) => p.classes) },
    { name: '售卡', data: daily.value.map((p) => p.cardSales) }
  ])
  const todayBookingCount = computed(() => {
    const venue = userStore.getUserInfo.venue as '绿地店' | '东部店'
    return todaySummary.value?.todayBookings?.[venue] ?? '-'
  })
  const todayTrialCount = computed(() => {
    const venue = userStore.getUserInfo.venue as '绿地店' | '东部店'
    return todaySummary.value?.trialBookings?.[venue] ?? '-'
  })
  const todayKindHint = computed(() => {
    const venue = userStore.getUserInfo.venue as '绿地店' | '东部店'
    const kinds = todaySummary.value?.todayKinds?.[venue]
    const total = (kinds?.私教 ?? 0) + (kinds?.小班 ?? 0) + (kinds?.团课 ?? 0)
    if (!kinds || total === 0) return '团课 + 私教预约记录（在 KeepYoga 同步页点「更新快照」可显示细分）'
    return `私教 ${kinds.私教} · 小班 ${kinds.小班} · 团课 ${kinds.团课}，来自最近成功快照`
  })

  const kpis = computed(() => [
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
  const contractVenue = ref<
    Awaited<ReturnType<typeof getPendingContracts>>['venues'][string] | null
  >(null)
  const contractError = ref('')

  const layerConfig: Record<
    string,
    { type: 'danger' | 'warning' | 'info' | 'success' | 'primary' }
  > = {
    P0: { type: 'danger' },
    P1: { type: 'warning' },
    P2: { type: 'warning' },
    P3: { type: 'info' },
    P4: { type: 'success' },
    P5: { type: 'primary' }
  }

  function onRangeChange(v: [string, string]) {
    range.value = v
    reload()
  }

  async function reload() {
    loading.value = true
    try {
      const settled = await Promise.allSettled([
        getDashboardSeries(
          range.value[0],
          range.value[1],
          userStore.getUserInfo.venue as '绿地店' | '东部店'
        ),
        getFollowupQueue(),
        getRiskAlerts(),
        getTodaySummary()
      ])
      const contracts = await getPendingContracts().catch((error) => {
        contractError.value = `合同读取失败：${String((error as { message?: string }).message ?? error).slice(0, 100)}`
        return { venues: {} as PendingContracts['venues'], fetchedAt: '' }
      })
      const dash = settled[0].status === 'fulfilled' ? settled[0].value : null
      const f = settled[1].status === 'fulfilled' ? settled[1].value : null
      const r = settled[2].status === 'fulfilled' ? settled[2].value : null
      const today = settled[3].status === 'fulfilled' ? settled[3].value : null
      if (dash) {
        daily.value = dash.daily
        summary.value = dash.summary
      }
      if (f) followups.value = f
      if (r) risks.value = r
      if (today) todaySummary.value = today
      const venue = userStore.getUserInfo.venue as '绿地店' | '东部店'
      contractVenue.value = contracts.venues[venue] ?? null
    } finally {
      loading.value = false
    }
  }

  onMounted(reload)
</script>

<style scoped lang="scss">
  .follow-item {
    border-bottom: 1px solid var(--el-border-color-lighter);

    &:last-of-type {
      border-bottom: 0;
    }
  }
</style>
