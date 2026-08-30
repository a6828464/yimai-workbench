<template>
  <div class="p-4">
    <!-- 控制栏 -->
    <div class="mb-4 flex flex-wrap items-center gap-3">
      <span class="text-sm font-500">我的工作台</span>
      <ElTag size="small" effect="plain">{{ scopeLabel }}</ElTag>
      <DateRangeControl :start="range[0]" :end="range[1]" :shortcuts="shortcuts" @change="onRangeChange" />
      <div class="flex-1" />
      <ElButton v-if="isDualStore" plain size="small" @click="$router.push('/yimai/store-select')">
        切换门店
      </ElButton>
      <span class="text-xs text-gray-400">默认为本月1号至今</span>
    </div>

    <!-- KPI -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
      <YimaiKpiCard v-for="k in kpis" :key="k.label" v-bind="k" />
    </div>

    <!-- 图表区 -->
    <ElRow :gutter="16" class="mb-4">
      <ElCol :xs="24" :lg="14" class="mb-4">
        <ElCard shadow="never">
          <template #header><span class="font-500">上课 / 服务人次趋势</span></template>
          <ArtLineChart
            height="270px"
            :data="classSeries"
            :x-axis-data="labels"
            :show-area-color="true"
            :show-axis-line="false"
            :loading="loading"
          />
        </ElCard>
      </ElCol>
      <ElCol :xs="24" :lg="10" class="mb-4">
        <ElCard shadow="never">
          <template #header><span class="font-500">我的客资跟进漏斗</span></template>
          <ArtBarChart
            height="270px"
            :data="leadFunnelSeries"
            :x-axis-data="leadFunnelLabels"
            bar-width="30"
            :border-radius="6"
            :loading="loading"
          />
        </ElCard>
      </ElCol>
    </ElRow>

    <!-- 我的客资池 -->
    <ElRow :gutter="16">
      <ElCol :span="24">
        <ElCard shadow="never">
          <template #header>
            <div class="flex items-center justify-between">
              <span class="font-500">待我跟进的客资</span>
              <ElButton link type="primary" @click="$router.push('/yimai/leads')">进入留资管理</ElButton>
            </div>
          </template>
          <ElTable :data="myLeads" size="default" v-loading="loading">
            <ElTableColumn prop="name" label="客户" width="110" />
            <ElTableColumn prop="venue" label="门店" width="90" />
            <ElTableColumn prop="demand" label="需求" min-width="120" show-overflow-tooltip />
            <ElTableColumn prop="source" label="来源" width="110" />
            <ElTableColumn prop="status" label="状态" width="95">
              <template #default="{ row }">
                <ElTag size="small" :type="row.status === '已成交' ? 'success' : row.status === '新留资' ? 'danger' : 'primary'">
                  {{ row.status }}
                </ElTag>
              </template>
            </ElTableColumn>
            <ElTableColumn prop="remark" label="备注" min-width="160" show-overflow-tooltip />
          </ElTable>
          <ElEmpty v-if="!loading && !myLeads.length" description="暂无待跟进客资" :image-size="70" />
        </ElCard>
      </ElCol>
    </ElRow>
  </div>
</template>

<script setup lang="ts">
  import YimaiKpiCard from './kpi-card.vue'
  import {
    getDashboardSeries,
    getTeacherOverview,
    queryLeads
  } from '@/api/yimai'
  import type { YimaiLead, DashboardDayPoint, DashboardSummary, TeacherOverview } from '@/api/yimai'
  import { useUserStore } from '@/store/modules/user'
  import { User, Calendar, Medal, Aim, Place, Odometer, Wallet } from '@element-plus/icons-vue'
  import type { LineDataItem } from '@/types/component/chart'
  import DateRangeControl from './date-range-control.vue'

  defineOptions({ name: 'TeacherDashboard' })

  const userStore = useUserStore()
  const userName = computed(() => userStore.getUserInfo.userName ?? '')
  const venues = computed(() => userStore.getUserInfo.venues ?? [])
  const isDualStore = computed(() => venues.value.length > 1)
  const scopeLabel = computed(() => userStore.getUserInfo.venue ?? '未选择门店')

  const loading = ref(true)
  const range = ref<[string, string]>(defaultRange())
  const daily = ref<DashboardDayPoint[]>([])
  const summary = ref<DashboardSummary | null>(null)
  const overview = ref<TeacherOverview | null>(null)
  const myLeads = ref<YimaiLead[]>([])

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
  const classSeries = computed<LineDataItem[]>(() => [
    { name: '上课节数', data: daily.value.map((p) => Math.round(p.bookings * 0.6)) },
    { name: '服务人次', data: daily.value.map((p) => p.trials + Math.round(p.bookings * 0.35)), color: '#67C23A' }
  ])
  const leadFunnelLabels = computed(() =>
    ['新留资', '已联系', '已约体验', '已体验', '已成交'].map(
      (s) => `${s} ${myLeads.value.filter((l) => l.status === s).length}`
    )
  )
  const leadFunnelSeries = computed(() =>
    ['新留资', '已联系', '已约体验', '已体验', '已成交'].map((s) =>
      myLeads.value.filter((l) => l.status === s).length
    )
  )

  const kpis = computed(() => [
    { label: '所属会员人数', value: overview.value?.memberCount ?? '-', icon: markRaw(User), accent: '#409EFF' },
    { label: '服务会员人次', value: overview.value?.servedCount ?? '-', icon: markRaw(Medal), accent: '#9C27B0' },
    { label: '上课节数', value: overview.value?.classCount ?? '-', icon: markRaw(Calendar), accent: '#67C23A' },
    { label: '资源数', value: overview.value?.resourceCount ?? '-', hint: overview.value ? `其中待分配 ${overview.value.newResourceCount}` : '', icon: markRaw(Aim), accent: '#E6A23C' },
    { label: '到店数', value: summary.value?.visitCount ?? '-', icon: markRaw(Place), accent: '#00BCD4' },
    { label: '成交率', value: summary.value ? `${summary.value.dealRate}` : '-', suffix: '%', icon: markRaw(Odometer), accent: '#F56C6C' },
    { label: '成交金额', value: summary.value?.dealAmount ?? '-', prefix: '¥', icon: markRaw(Wallet), accent: '#FF9800' }
  ])

  function onRangeChange(v: [string, string]) {
    range.value = v
    reload()
  }

  async function reload() {
    loading.value = true
    try {
      const settled = await Promise.allSettled([
        getDashboardSeries(range.value[0], range.value[1], '双店'),
        getTeacherOverview(range.value[0], range.value[1]),
        queryLeads({ current: 1, size: 50 })
      ])
      const dash = settled[0].status === 'fulfilled' ? settled[0].value : null
      const ov = settled[1].status === 'fulfilled' ? settled[1].value : null
      const leads = settled[2].status === 'fulfilled' ? settled[2].value : null
      if (dash) {
        daily.value = dash.daily
        summary.value = dash.summary
      }
      if (ov) overview.value = ov
      if (leads) {
        myLeads.value = leads.records.filter(
          (l) => l.serviceTeacher === userName.value || !l.serviceTeacher
        )
      }
    } finally {
      loading.value = false
    }
  }

  onMounted(reload)
</script>
