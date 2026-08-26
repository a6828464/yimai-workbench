<template>
  <div class="p-4">
    <!-- 控制栏 -->
    <div class="mb-4 flex flex-wrap items-center gap-3">
      <span class="text-sm font-500">经营数据</span>
      <ElTag size="small" effect="plain">本店 · {{ userStore.getUserInfo.venue }}</ElTag>
      <DateRangeControl :start="range[0]" :end="range[1]" :shortcuts="shortcuts" @change="onRangeChange" />
      <div class="flex-1" />
      <span class="text-xs text-gray-400">默认为本月1号至今，可自行调整</span>
    </div>

    <!-- KPI -->
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 mb-4">
      <YimaiKpiCard v-for="k in kpis" :key="k.label" v-bind="k" />
    </div>

    <!-- 图表区 -->
    <ElRow :gutter="16" class="mb-4">
      <ElCol :xs="24" :lg="14" class="mb-4">
        <ElCard shadow="never">
          <template #header><span class="font-500">成交金额趋势（元）</span></template>
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
              <span class="font-500">约课 / 体验 / 成交（人）</span>
              <div class="flex gap-3 text-xs text-gray-400">
                <span>— 约课</span><span style="color: var(--el-color-primary)">— 体验</span><span style="color: var(--el-color-success)">— 成交</span>
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

    <!-- 待办区 -->
    <ElRow :gutter="16">
      <ElCol :xs="24" :lg="14" class="mb-4">
        <ElCard shadow="never">
          <template #header>
            <div class="flex items-center justify-between">
              <span class="font-500">待跟进队列</span>
              <ElButton link type="primary" @click="$router.push('/yimai/customers')">进入客户经营池</ElButton>
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
                  <ElTag :type="layerConfig[c.layer].type" size="small" effect="dark">{{ c.layer }}</ElTag>
                  <span class="font-500">{{ c.name }}</span>
                  <span class="text-xs text-gray-400">尾号{{ c.phoneTail }}</span>
                </div>
                <div class="mt-1 truncate text-xs text-gray-500">
                  下一步：{{ c.nextAction }}（{{ c.nextActionTime }}）
                </div>
              </div>
              <div class="shrink-0 text-right text-xs" :class="(c.lastVisitDays ?? 99) > 30 ? 'text-red-500' : 'text-gray-400'">
                {{ c.lastVisit ? `${c.lastVisitDays}天未到店` : '未到店' }}
              </div>
            </div>
            <ElEmpty v-if="!loading && !followups.length" description="暂无待跟进对象" :image-size="70" />
          </div>
        </ElCard>
      </ElCol>
      <ElCol :xs="24" :lg="10" class="mb-4">
        <ElCard shadow="never">
          <template #header><span class="font-500">风险提醒</span></template>
          <div v-loading="loading">
            <div v-for="r in risks" :key="r.id" class="flex items-start gap-3 py-3 border-b border-gray-100 last:border-0 dark:border-gray-800">
              <ElTag :type="r.level === '高' ? 'danger' : r.level === '中' ? 'warning' : 'info'" size="small">{{ r.level }}</ElTag>
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
  import { getDashboardSeries, getFollowupQueue, getRiskAlerts } from '@/api/yimai'
  import type { YimaiCustomer, DashboardDayPoint, DashboardSummary } from '@/api/yimai'
  import { useUserStore } from '@/store/modules/user'
  import DateRangeControl from './date-range-control.vue'
  import { DataAnalysis, User, Ticket, ShoppingBag, Wallet, Odometer } from '@element-plus/icons-vue'
  import type { LineDataItem } from '@/types/component/chart'

  defineOptions({ name: 'ManagerDashboard' })

  const userStore = useUserStore()

  const loading = ref(true)
  const range = ref<[string, string]>(defaultRange())
  const daily = ref<DashboardDayPoint[]>([])
  const summary = ref<DashboardSummary | null>(null)
  const followups = ref<(YimaiCustomer & { lastVisitDays: number })[]>([])
  const risks = ref<Awaited<ReturnType<typeof getRiskAlerts>>>([])

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
  const amountSeries = computed(() => daily.value.map((p) => p.amount))
  const funnelSeries = computed<LineDataItem[]>(() => [
    { name: '约课', data: daily.value.map((p) => p.bookings) },
    { name: '体验', data: daily.value.map((p) => p.trials) },
    { name: '成交', data: daily.value.map((p) => p.deals) }
  ])

  const kpis = computed(() => [
    { label: '约课人数', value: summary.value?.bookingCount ?? '-', icon: markRaw(Ticket), accent: '#409EFF' },
    { label: '体验人数', value: summary.value?.trialCount ?? '-', icon: markRaw(User), accent: '#E6A23C' },
    { label: '成交人数', value: summary.value?.dealCount ?? '-', icon: markRaw(ShoppingBag), accent: '#67C23A' },
    { label: '成交率', value: summary.value ? `${summary.value.dealRate}` : '-', suffix: '%', hint: '成交 ÷ 到店体验', icon: markRaw(Odometer), accent: '#F56C6C' },
    { label: '成交金额', value: summary.value?.dealAmount ?? '-', prefix: '¥', hint: '统计口径：工作台登记成交', icon: markRaw(Wallet), accent: '#9C27B0' }
  ])

  const layerConfig: Record<string, { type: 'danger' | 'warning' | 'info' | 'success' | 'primary' }> = {
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
      const [dash, f, r] = await Promise.all([
        getDashboardSeries(range.value[0], range.value[1], '双店'),
        getFollowupQueue(),
        getRiskAlerts()
      ])
      daily.value = dash.daily
      summary.value = dash.summary
      followups.value = f
      risks.value = r
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
