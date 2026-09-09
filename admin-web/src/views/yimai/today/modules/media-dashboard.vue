<template>
  <div class="p-4">
    <!-- 控制栏 -->
    <div class="mb-4 flex flex-wrap items-center gap-3">
      <span class="text-sm font-500">运营数据</span>
      <ElRadioGroup v-model="venueScope" @change="reload">
        <ElRadioButton v-if="allowedVenues.length > 1" value="双店">全部门店</ElRadioButton>
        <ElRadioButton v-for="venue in allowedVenues" :key="venue" :value="venue">
          {{ venue }}
        </ElRadioButton>
      </ElRadioGroup>
      <DateRangeControl
        :start="range[0]"
        :end="range[1]"
        :shortcuts="shortcuts"
        @change="onRangeChange"
      />
      <div class="flex-1" />
      <span class="text-xs text-gray-400">默认为本月1号至今</span>
    </div>
    <ElAlert v-if="dashboardError" class="mb-4" type="error" show-icon :closable="false">
      {{ dashboardError }}
    </ElAlert>

    <!-- KPI -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
      <YimaiKpiCard v-for="k in kpis" :key="k.label" v-bind="k" />
    </div>

    <!-- 今日待办（概要，新媒体：新客首响 + 今日体验课） -->
    <YimaiTodayTodo variant="summary" class="mb-4" />

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

    <!-- 各平台金额 -->
    <ElRow :gutter="16" class="mb-4">
      <ElCol :xs="24" :lg="12" class="mb-4">
        <ElCard shadow="never">
          <template #header>
            <div class="flex-cb">
              <span class="font-500">各平台成交金额（树状图）</span>
              <ElTag size="small" effect="plain">¥ 元</ElTag>
            </div>
          </template>
          <div style="height: 300px">
            <ArtTreeMapChart
              v-if="dealRows.length"
              height="300px"
              :data="dealRows.map((x) => ({ name: x.platform, value: x.deal }))"
            />
            <ElEmpty v-else description="该时间段暂无成交数据" :image-size="60" />
          </div>
        </ElCard>
      </ElCol>
      <ElCol :xs="24" :lg="12" class="mb-4">
        <ElCard shadow="never">
          <template #header><span class="font-500">各平台核销金额</span></template>
          <ArtBarChart
            v-if="redeemRows.length"
            height="300px"
            :data="redeemRows.map((x) => x.redeem)"
            :x-axis-data="redeemRows.map((x) => x.platform)"
            bar-width="26"
            :border-radius="6"
            show-split-line
          />
          <ElEmpty v-else description="该时间段暂无核销数据" :image-size="60" />
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
                  <ElProgress :percentage="row.percent" :stroke-width="16" :format="() => ''" />
                </div>
              </template>
            </ElTableColumn>
            <ElTableColumn label="" min-width="200">
              <template #default="{ row }">
                <span class="font-500">{{ row.value }}</span> 人
                <span v-if="row.rateText" class="ml-3 text-xs text-gray-400">{{
                  row.rateText
                }}</span>
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
  import YimaiTodayTodo from './today-todo.vue'
  import { getDashboardSeries, getChannelBreakdown, getPlatformAmounts } from '@/api/yimai'
  import type {
    DashboardDayPoint,
    DashboardSummary,
    ChannelLeadItem,
    PlatformAmountItem
  } from '@/api/yimai'
  import { DataLine, UserFilled, ShoppingBag, Coin, Odometer, Money } from '@element-plus/icons-vue'
  import type { LineDataItem } from '@/types/component/chart'
  import DateRangeControl from './date-range-control.vue'
  import { useUserStore } from '@/store/modules/user'

  defineOptions({ name: 'MediaDashboard' })

  const loading = ref(true)
  const dashboardError = ref('')
  let reloadSequence = 0
  const userStore = useUserStore()
  const allowedVenues = computed(() =>
    (userStore.getUserInfo.venues ?? []).filter(
      (venue): venue is '绿地店' | '东部店' => venue === '绿地店' || venue === '东部店'
    )
  )
  const venueScope = ref<'双店' | '绿地店' | '东部店'>(
    allowedVenues.value.length > 1 ? '双店' : (allowedVenues.value[0] ?? '双店')
  )
  const range = ref<[string, string]>(defaultRange())
  const daily = ref<DashboardDayPoint[]>([])
  const summary = ref<DashboardSummary | null>(null)
  const channels = ref<ChannelLeadItem[]>([])
  const platforms = ref<PlatformAmountItem[]>([])
  const platformTotal = ref<{ deal: number; redeem: number } | null>(null)

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
  const trendSeries = computed<LineDataItem[]>(() => [
    { name: '留资', data: daily.value.map((p) => p.leads) },
    { name: '到店', data: daily.value.map((p) => p.visits), color: '#67C23A' }
  ])
  const channelLabels = computed(() => channels.value.map((c) => c.channel))
  const channelSeries = computed(() => channels.value.map((c) => c.leads))
  const dealRows = computed(() => platforms.value.filter((p) => p.deal > 0))
  const redeemRows = computed(() => platforms.value.filter((p) => p.redeem > 0))

  const kpis = computed(() => [
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
      icon: markRaw(UserFilled),
      accent: '#9C27B0'
    },
    {
      label: '成交人数',
      value: summary.value?.dealCount ?? '-',
      icon: markRaw(ShoppingBag),
      accent: '#67C23A'
    },
    {
      label: '所选周期成交金额',
      value: platformTotal.value?.deal ?? '-',
      prefix: '¥',
      hint: `${summary.value?.dealCount ?? 0} 人成交`,
      icon: markRaw(Money),
      accent: '#67C23A'
    },
    {
      label: '核销金额',
      value: platformTotal.value?.redeem ?? '-',
      prefix: '¥',
      hint: '平台团购券核销',
      icon: markRaw(Coin),
      accent: '#FF9800'
    }
  ])

  const funnelRows = computed(() => {
    const s = summary.value
    if (!s) return []
    const pct = (v: number) =>
      s.leadCount > 0 ? Math.min(100, Math.round((v / s.leadCount) * 100)) : 0
    return [
      { stage: '留资', value: s.leadCount, percent: s.leadCount > 0 ? 100 : 0, rateText: '' },
      {
        stage: '到店',
        value: s.visitCount,
        percent: pct(s.visitCount),
        rateText: `留资→到店 ${s.leadToVisitRate}%`
      },
      {
        stage: '线上新客成交',
        value: s.onlineDealCount,
        percent: pct(s.onlineDealCount),
        rateText: `线上新客成交率 ${s.onlineDealRate}%`
      }
    ]
  })

  function onRangeChange(v: [string, string]) {
    range.value = v
    reload()
  }

  async function reload() {
    const sequence = ++reloadSequence
    loading.value = true
    dashboardError.value = ''
    try {
      const settled = await Promise.allSettled([
        getDashboardSeries(range.value[0], range.value[1], venueScope.value),
        getChannelBreakdown(range.value[0], range.value[1], venueScope.value),
        getPlatformAmounts(range.value[0], range.value[1], venueScope.value)
      ])
      const dash = settled[0].status === 'fulfilled' ? settled[0].value : null
      const ch = settled[1].status === 'fulfilled' ? settled[1].value : null
      const plat = settled[2].status === 'fulfilled' ? settled[2].value : null
      if (sequence !== reloadSequence) return
      if (dash) {
        daily.value = dash.daily
        summary.value = dash.summary
      } else {
        daily.value = []
        summary.value = null
      }
      channels.value = ch ?? []
      if (plat) {
        platforms.value = plat.rows
        platformTotal.value = { deal: plat.totalDeal, redeem: plat.totalRedeem }
      } else {
        platforms.value = []
        platformTotal.value = null
      }
      const requestNames = ['趋势与指标', '渠道数据', '平台金额']
      const failedNames = settled.flatMap((item, index) =>
        item.status === 'rejected' ? [requestNames[index]] : []
      )
      if (failedNames.length > 0) {
        dashboardError.value = `${failedNames.join('、')}加载失败，请稍后重试`
      }
    } finally {
      if (sequence === reloadSequence) loading.value = false
    }
  }

  onMounted(reload)
</script>
