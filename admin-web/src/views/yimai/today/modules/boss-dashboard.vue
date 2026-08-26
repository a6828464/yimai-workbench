<template>
  <div class="p-4">
    <!-- 控制栏 -->
    <div class="mb-4 flex flex-wrap items-center gap-3">
      <span class="text-sm font-500">经营总览</span>
      <ElRadioGroup v-model="venueScope" @change="reload">
        <ElRadioButton value="双店">双店合计</ElRadioButton>
        <ElRadioButton value="绿地店">绿地店</ElRadioButton>
        <ElRadioButton value="东部店">东部店</ElRadioButton>
      </ElRadioGroup>
      <DateRangeControl :start="range[0]" :end="range[1]" :shortcuts="shortcuts" @change="onRangeChange" />
      <div class="flex-1" />
      <span class="text-xs text-gray-400">{{ scopeLabel }} · 默认为本月1号至今</span>
    </div>

    <!-- 门店经营 KPI（店长视角） -->
    <div class="text-sm font-500 mb-3 flex items-center gap-2">
      <span>门店经营</span>
      <span class="text-xs font-400 text-gray-400">约课 · 体验 · 成交 · 金额</span>
    </div>
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 mb-4">
      <YimaiKpiCard v-for="k in storeKpis" :key="k.label" v-bind="k" />
    </div>

    <!-- 双店对比 + 趋势 -->
    <ElRow :gutter="16" class="mb-5">
      <ElCol :xs="24" :lg="10" class="mb-4">
        <ElCard shadow="never">
          <template #header><span class="font-500">双店成交对比（人数）</span></template>
          <ArtBarChart
            height="260px"
            :data="compareSeries"
            :x-axis-data="compareLabels"
            bar-width="34"
            :border-radius="6"
            :loading="loading"
          />
        </ElCard>
      </ElCol>
      <ElCol :xs="24" :lg="14" class="mb-4">
        <ElCard shadow="never">
          <template #header><span class="font-500">成交金额趋势（元）</span></template>
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
      <span class="text-xs font-400 text-gray-400">留资 · 私域 · 到店 · 核销</span>
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
          <ArtBarChart
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
  </div>
</template>

<script setup lang="ts">
  import YimaiKpiCard from './kpi-card.vue'
  import { getDashboardSeries, getChannelBreakdown } from '@/api/yimai'
  import type { DashboardDayPoint, DashboardSummary, ChannelLeadItem } from '@/api/yimai'
  import { Ticket, User, ShoppingBag, Wallet, DataLine, Position, Coin, Odometer } from '@element-plus/icons-vue'
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

  function defaultRange(): [string, string] {
    const now = new Date()
    const first = new Date(now.getFullYear(), now.getMonth(), 1)
    return [iso(first), iso(now)]
  }

  function iso(d: Date): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
  }

  const shortcuts = [
    { text: '本月', value: () => [new Date(new Date().getFullYear(), new Date().getMonth(), 1), new Date()] },
    { text: '上周', value: () => { const e = new Date(); const s = new Date(); s.setDate(s.getDate() - 7); return [s, e] } },
    { text: '近30天', value: () => { const e = new Date(); const s = new Date(); s.setDate(s.getDate() - 30); return [s, e] } }
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

  const compareLabels = ['成交人数', '体验人数', '约课人数']
  const compareSeries = computed(() => [
    { name: '绿地店', data: [ldSummary.value?.dealCount ?? 0, ldSummary.value?.trialCount ?? 0, ldSummary.value?.bookingCount ?? 0], stack: undefined },
    { name: '东部店', data: [dbSummary.value?.dealCount ?? 0, dbSummary.value?.trialCount ?? 0, dbSummary.value?.bookingCount ?? 0] }
  ])

  const storeKpis = computed(() => [
    { label: '约课人数', value: summary.value?.bookingCount ?? '-', icon: markRaw(Ticket), accent: '#409EFF' },
    { label: '体验人数', value: summary.value?.trialCount ?? '-', icon: markRaw(User), accent: '#E6A23C' },
    { label: '成交人数', value: summary.value?.dealCount ?? '-', icon: markRaw(ShoppingBag), accent: '#67C23A' },
    { label: '成交率', value: summary.value ? `${summary.value.dealRate}` : '-', suffix: '%', hint: '成交 ÷ 到店体验', icon: markRaw(Odometer), accent: '#F56C6C' },
    { label: '成交金额', value: summary.value?.dealAmount ?? '-', prefix: '¥', hint: '工作台登记口径', icon: markRaw(Wallet), accent: '#9C27B0' }
  ])

  const mediaKpis = computed(() => [
    { label: '留资人数', value: summary.value?.leadCount ?? '-', icon: markRaw(DataLine), accent: '#409EFF' },
    { label: '转私域人数', value: summary.value?.privateDomainCount ?? '-', icon: markRaw(Position), accent: '#E6A23C' },
    { label: '到店人数', value: summary.value?.visitCount ?? '-', icon: markRaw(User), accent: '#9C27B0' },
    { label: '核销金额', value: summary.value?.redeemAmount ?? '-', prefix: '¥', hint: '平台团购券', icon: markRaw(Coin), accent: '#FF9800' }
  ])

  function onRangeChange(v: [string, string]) {
    range.value = v
    reload()
  }

  async function reload() {
    loading.value = true
    try {
      const requests: Promise<void>[] = []
      if (venueScope.value === '双店') {
        requests.push(
          (async () => { ldSummary.value = (await getDashboardSeries(range.value[0], range.value[1], '绿地店')).summary })(),
          (async () => { dbSummary.value = (await getDashboardSeries(range.value[0], range.value[1], '东部店')).summary })()
        )
      } else {
        ldSummary.value = null
        dbSummary.value = null
      }
      const [dash, ch] = await Promise.all([
        getDashboardSeries(range.value[0], range.value[1], venueScope.value),
        getChannelBreakdown(range.value[0], range.value[1], venueScope.value),
        ...requests
      ])
      daily.value = dash.daily
      summary.value = dash.summary
      channels.value = ch
    } finally {
      loading.value = false
    }
  }

  onMounted(reload)
</script>
