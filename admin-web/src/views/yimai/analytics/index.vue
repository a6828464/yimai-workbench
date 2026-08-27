<template>
  <div class="p-4">
    <ElAlert
      title="经营看板：指标基于当前会员 / 留资 / 任务 / 续费数据实时计算"
      type="info"
      show-icon
      :closable="false"
      class="mb-4"
    />
    <ElRow :gutter="16" class="mb-4">
      <ElCol v-for="m in metricList" :key="m.label" :xs="12" :sm="12" :md="6" class="mb-4">
        <ElCard shadow="never">
          <div class="text-sm text-gray-500">{{ m.label }}</div>
          <div class="mt-2 text-2xl font-600" :class="m.warn ? 'text-warning' : 'text-g-900'">
            {{ m.value }}
          </div>
          <div class="mt-1 text-xs text-gray-400">{{ m.hint }}</div>
        </ElCard>
      </ElCol>
    </ElRow>

    <ElRow :gutter="16" class="mb-4">
      <ElCol :xs="24" :md="12" class="mb-4">
        <ElCard shadow="never">
          <template #header>
            <div class="flex-cb">
              <span class="font-500">近30天留资走势</span>
              <ElTag size="small" effect="plain">真实数据</ElTag>
            </div>
          </template>
          <div v-loading="trendLoading" style="height: 260px">
            <ArtLineChart
              v-if="trendData.length"
              :height="'260px'"
              :data="lineSeries"
              :x-axis-data="lineDates"
              smooth
              show-area-color
            />
            <ElEmpty v-else description="该时间段暂无留资记录" :image-size="60" />
          </div>
        </ElCard>
      </ElCol>
      <ElCol :xs="24" :md="12" class="mb-4">
        <ElCard shadow="never">
          <template #header>
            <div class="flex-cb">
              <span class="font-500">留资来源分布</span>
              <ElTag size="small" effect="plain">真实数据</ElTag>
            </div>
          </template>
          <div v-loading="channelLoading" style="height: 260px">
            <ArtRingChart
              v-if="channelRows.length"
              :height="'260px'"
              :data="channelRows.map((x) => ({ name: x.channel, value: x.leads }))"
              show-label
            />
            <ElEmpty v-else description="暂无留资记录" :image-size="60" />
          </div>
        </ElCard>
      </ElCol>
    </ElRow>

    <ElRow :gutter="16">
      <ElCol :xs="24" :md="12" class="mb-4">
        <ElCard shadow="never">
          <template #header><span class="font-500">活跃度（最近三个自然月有签到的会员）</span></template>
          <div class="grid grid-cols-4 gap-3 text-center py-2">
            <div>
              <div class="text-2xl font-600">{{ attend.m3 }}</div>
              <div class="text-xs text-gray-400 mt-1">M3 有签到</div>
            </div>
            <div>
              <div class="text-2xl font-600">{{ attend.m2 }}</div>
              <div class="text-xs text-gray-400 mt-1">M2 有签到</div>
            </div>
            <div>
              <div class="text-2xl font-600 text-danger">{{ attend.m1 }}</div>
              <div class="text-xs text-gray-400 mt-1">M1 有签到</div>
            </div>
            <div>
              <div class="text-2xl font-600">{{ trends.visit30 }}</div>
              <div class="text-xs text-gray-400 mt-1">30天到店</div>
            </div>
          </div>
          <div class="mt-2 text-xs text-gray-400">M1=最近完整月，M3=最早完整月。签到下降趋势用于出勤降低预警。</div>
        </ElCard>
      </ElCol>
      <ElCol :xs="24" :md="12" class="mb-4">
        <ElCard shadow="never">
          <template #header><span class="font-500">客户经营概览</span></template>
          <div class="grid grid-cols-3 gap-4 text-center">
            <div>
              <div class="text-2xl font-600">{{ d.totalCustomers }}</div>
              <div class="text-xs text-gray-400 mt-1">客户总数</div>
            </div>
            <div>
              <div class="text-2xl font-600">{{ d.totalMembers }}</div>
              <div class="text-xs text-gray-400 mt-1">在册会员</div>
            </div>
            <div>
              <div class="text-2xl font-600 text-danger">{{ d.unassigned }}</div>
              <div class="text-xs text-gray-400 mt-1">待分配</div>
            </div>
          </div>
        </ElCard>
      </ElCol>
      <ElCol :xs="24" :md="12" class="mb-4">
        <ElCard shadow="never">
          <template #header><span class="font-500">流程健康度</span></template>
          <div class="space-y-3">
            <div class="flex-cb text-sm">
              <span class="text-g-600">留资分配率</span>
              <span class="font-600">{{ d.assignRate }}%</span>
            </div>
            <div class="flex-cb text-sm">
              <span class="text-g-600">客户闭环率</span>
              <span class="font-600">{{ d.closureRate }}%</span>
            </div>
            <div class="flex-cb text-sm">
              <span class="text-g-600">任务完成率</span>
              <span class="font-600">{{ d.taskRate }}%（{{ d.doneTasks }}/{{ d.totalTasks }}）</span>
            </div>
            <div class="flex-cb text-sm">
              <span class="text-g-600">续费预警处理率</span>
              <span class="font-600">{{ d.renewalRate }}%（预警 {{ d.renewalTasks }} 人）</span>
            </div>
          </div>
        </ElCard>
      </ElCol>
    </ElRow>
  </div>
</template>

<script setup lang="ts">
  import { apiGet } from '@/api/backend'

  defineOptions({ name: 'YimaiAnalytics' })

  const d = ref<Record<string, number>>({
    totalCustomers: 0, totalMembers: 0, unassigned: 0,
    assignRate: 0, closureRate: 0, renewalTasks: 0, renewalRate: 0,
    taskRate: 0, doneTasks: 0, totalTasks: 0
  })

  const metricList = computed(() => [
    { label: '客户总数', value: d.value.totalCustomers, hint: '含会员与公海/新客', warn: false },
    { label: '在册会员', value: d.value.totalMembers, hint: '排除未建档新客', warn: false },
    { label: '待分配客户', value: d.value.unassigned, hint: '需要尽快指定负责人', warn: d.value.unassigned > 0 },
    { label: '续费预警待处理', value: `${d.value.renewalTasks} 人`, hint: `已处理 ${d.value.renewalRate}%`, warn: d.value.renewalTasks > 0 }
  ])

  // ---------- 近30天留资走势（真实数据） ----------
  const trendLoading = ref(false)
  const trendData = ref<{ date: string; 绿地店?: number; 东部店?: number }[]>([])

  const lineDates = computed(() => trendData.value.map((x) => x.date))
  const lineSeries = computed(() => [
    { name: '绿地店', data: trendData.value.map((x) => x['绿地店'] ?? 0), smooth: true, showAreaColor: true },
    { name: '东部店', data: trendData.value.map((x) => x['东部店'] ?? 0), smooth: true, showAreaColor: true }
  ])

  // ---------- 来源分布（真实数据） ----------
  const channelLoading = ref(false)
  const channelRows = ref<{ channel: string; leads: number }[]>([])

  // ---------- 活跃度 ----------
  const attend = ref({ m1: 0, m2: 0, m3: 0 })
  const trends = ref({ visit30: 0, activeCustomers: 0 })

  function last30Days(): { start: string; end: string } {
    const end = new Date()
    const start = new Date()
    start.setDate(start.getDate() - 29)
    const iso = (dt: Date) => dt.toISOString().slice(0, 10)
    return { start: iso(start), end: iso(end) }
  }

  async function loadTrends() {
    const { start, end } = last30Days()
    trendLoading.value = true
    channelLoading.value = true
    try {
      const t = await apiGet<{ daily: Record<string, unknown>[]; visit30: number; activeCustomers: number; attendanceSummary: { m1: number; m2: number; m3: number } }>('/analytics/trends', { start, end })
      trendData.value = (t.daily ?? []).map((day) => {
        const rec = day as unknown as { date: string; 绿地店?: { leads: number }; 东部店?: { leads: number } }
        return {
          date: rec.date.slice(5),
          绿地店: rec['绿地店']?.leads ?? 0,
          东部店: rec['东部店']?.leads ?? 0
        }
      })
      attend.value = t.attendanceSummary ?? { m1: 0, m2: 0, m3: 0 }
      trends.value = { visit30: t.visit30 ?? 0, activeCustomers: t.activeCustomers ?? 0 }
      const c = await apiGet<{ rows: { channel: string; leads: number }[]; total: number }>('/analytics/channels', { start, end })
      channelRows.value = (c.rows ?? []).sort((a, b) => b.leads - a.leads)
    } catch {
      /* keep zeros */
    } finally {
      trendLoading.value = false
      channelLoading.value = false
    }
  }

  onMounted(async () => {
    try {
      const res = await apiGet<Record<string, number>>('/analytics/summary')
      d.value = { ...d.value, ...res }
    } catch {
      /* keep zeros */
    }
    await loadTrends()
  })
</script>