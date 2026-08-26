<template>
  <div class="p-4">
    <!-- 控制栏 -->
    <div class="mb-4 flex flex-wrap items-center gap-3">
      <span class="text-sm font-500">运营数据</span>
      <ElRadioGroup v-model="venueScope" @change="reload">
        <ElRadioButton value="双店">全部门店</ElRadioButton>
        <ElRadioButton value="绿地店">绿地店</ElRadioButton>
        <ElRadioButton value="东部店">东部店</ElRadioButton>
      </ElRadioGroup>
      <DateRangeControl :start="range[0]" :end="range[1]" :shortcuts="shortcuts" @change="onRangeChange" />
      <div class="flex-1" />
      <span class="text-xs text-gray-400">默认为本月1号至今</span>
    </div>

    <!-- KPI -->
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-4">
      <YimaiKpiCard v-for="k in kpis" :key="k.label" v-bind="k" />
    </div>

    <!-- 图表区 -->
    <ElRow :gutter="16" class="mb-4">
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
            height="280px"
            :data="trendSeries"
            :x-axis-data="labels"
            :show-area-color="false"
            :show-axis-line="false"
            :loading="loading"
          />
        </ElCard>
      </ElCol>
      <ElCol :xs="24" :lg="10" class="mb-4">
        <ElCard shadow="never">
          <template #header><span class="font-500">各渠道留资对比</span></template>
          <ArtBarChart
            height="280px"
            :data="channelSeries"
            :x-axis-data="channelLabels"
            bar-width="26"
            :border-radius="6"
            :loading="loading"
          />
        </ElCard>
      </ElCol>
    </ElRow>

    <!-- 转化漏斗 -->
    <ElRow :gutter="16">
      <ElCol :span="24" class="mb-4">
        <ElCard shadow="never">
          <template #header><span class="font-500">留资转化漏斗</span></template>
          <ElTable :data="funnelRows" :show-header="false" size="large">
            <ElTableColumn prop="stage" label="阶段" width="160" />
            <ElTableColumn label="数量" width="220">
              <template #default="{ row }">
                <div class="w-full max-w-50">
                  <ElProgress
                    :percentage="row.percent"
                    :stroke-width="16"
                    :format="() => ''"
                  />
                </div>
              </template>
            </ElTableColumn>
            <ElTableColumn label="" min-width="200">
              <template #default="{ row }">
                <span class="font-500">{{ row.value }}</span> 人
                <span v-if="row.rateText" class="ml-3 text-xs text-gray-400">{{ row.rateText }}</span>
              </template>
            </ElTableColumn>
          </ElTable>
        </ElCard>
      </ElCol>
    </ElRow>
  </div>
</template>

<script setup lang="ts">
  import YimaiKpiCard from './kpi-card.vue'
  import { getDashboardSeries, getChannelBreakdown } from '@/api/yimai'
  import type { DashboardDayPoint, DashboardSummary, ChannelLeadItem } from '@/api/yimai'
  import { DataLine, UserFilled, Position, ShoppingBag, Coin, Odometer } from '@element-plus/icons-vue'
  import type { LineDataItem } from '@/types/component/chart'
  import DateRangeControl from './date-range-control.vue'

  defineOptions({ name: 'MediaDashboard' })

  const loading = ref(true)
  const venueScope = ref<'双店' | '绿地店' | '东部店'>('双店')
  const range = ref<[string, string]>(defaultRange())
  const daily = ref<DashboardDayPoint[]>([])
  const summary = ref<DashboardSummary | null>(null)
  const channels = ref<ChannelLeadItem[]>([])

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

  const labels = computed(() => daily.value.map((p) => p.label))
  const trendSeries = computed<LineDataItem[]>(() => [
    { name: '留资', data: daily.value.map((p) => p.leads) },
    { name: '到店', data: daily.value.map((p) => p.visits), color: '#67C23A' }
  ])
  const channelLabels = computed(() => channels.value.map((c) => c.channel))
  const channelSeries = computed(() => channels.value.map((c) => c.leads))

  const kpis = computed(() => [
    { label: '留资人数', value: summary.value?.leadCount ?? '-', icon: markRaw(DataLine), accent: '#409EFF' },
    { label: '转私域人数', value: summary.value?.privateDomainCount ?? '-', icon: markRaw(Position), accent: '#E6A23C' },
    { label: '到店人数', value: summary.value?.visitCount ?? '-', icon: markRaw(UserFilled), accent: '#9C27B0' },
    { label: '成交人数', value: summary.value?.dealCount ?? '-', icon: markRaw(ShoppingBag), accent: '#67C23A' },
    { label: '成交率', value: summary.value ? `${summary.value.dealRate}` : '-', suffix: '%', hint: '成交 ÷ 到店', icon: markRaw(Odometer), accent: '#F56C6C' },
    { label: '核销金额', value: summary.value?.redeemAmount ?? '-', prefix: '¥', hint: '平台团购券核销', icon: markRaw(Coin), accent: '#FF9800' }
  ])

  const funnelRows = computed(() => {
    const s = summary.value
    if (!s) return []
    const pct = (v: number) => (s.leadCount > 0 ? Math.round((v / s.leadCount) * 100) : 0)
    return [
      { stage: '留资', value: s.leadCount, percent: 100, rateText: '' },
      { stage: '添加私域', value: s.privateDomainCount, percent: pct(s.privateDomainCount), rateText: `留资转化 ${pct(s.privateDomainCount)}%` },
      { stage: '到店', value: s.visitCount, percent: pct(s.visitCount), rateText: `留资→到店 ${s.leadToVisitRate}%` },
      { stage: '成交', value: s.dealCount, percent: pct(s.dealCount), rateText: `整体成交率 ${s.dealRate}%` }
    ]
  })

  function onRangeChange(v: [string, string]) {
    range.value = v
    reload()
  }

  async function reload() {
    loading.value = true
    try {
      const [dash, ch] = await Promise.all([
        getDashboardSeries(range.value[0], range.value[1], venueScope.value),
        getChannelBreakdown(range.value[0], range.value[1], venueScope.value)
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
