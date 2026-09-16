<template>
  <div class="p-4">
    <!-- 控制栏 -->
    <div class="mb-4 flex flex-wrap items-center gap-3">
      <span class="text-sm font-500">我的工作台</span>
      <ElTag size="small" effect="dark" :type="isCoach ? 'danger' : 'success'">{{
        roleLabel
      }}</ElTag>
      <ElTag size="small" effect="plain">{{ scopeLabel }}</ElTag>
      <DateRangeControl
        :start="range[0]"
        :end="range[1]"
        :shortcuts="shortcuts"
        @change="onRangeChange"
      />
      <div class="flex-1" />
      <ElBadge v-if="pendingReviewCount > 0" :value="pendingReviewCount" type="danger">
        <ElButton type="primary" size="small" @click="$router.push('/yimai/post-class')">
          填写课后分析
        </ElButton>
      </ElBadge>
      <ElButton v-else plain size="small" @click="$router.push('/yimai/post-class')">
        课后分析
      </ElButton>
      <ElButton v-if="isDualStore" plain size="small" @click="$router.push('/yimai/store-select')">
        切换门店
      </ElButton>
    </div>

    <ElAlert v-if="loadError" type="warning" :closable="false" class="mb-4" show-icon>
      {{ loadError }}
    </ElAlert>

    <!-- KPI -->
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3 mb-4">
      <YimaiKpiCard v-for="k in kpis" :key="k.label" v-bind="k" />
    </div>

    <!-- 图表区 -->
    <ElRow :gutter="16" class="mb-4">
      <ElCol :xs="24" :lg="isCoach ? 14 : 12" class="mb-4">
        <ElCard shadow="never">
          <template #header>
            <span class="font-500">{{ isCoach ? '我的上课 / 服务人次趋势' : '我的客资趋势' }}</span>
          </template>
          <ArtLineChart
            height="260px"
            :data="trendSeries"
            :x-axis-data="labels"
            :show-area-color="true"
            :show-axis-line="false"
            :loading="loading"
          />
        </ElCard>
      </ElCol>
      <ElCol :xs="24" :lg="isCoach ? 10 : 12" class="mb-4">
        <ElCard shadow="never">
          <template #header>
            <span class="font-500">我的客资跟进漏斗</span>
          </template>
          <ArtBarChart
            height="260px"
            :data="leadFunnelSeries"
            :x-axis-data="leadFunnelLabels"
            bar-width="30"
            :border-radius="6"
            :loading="loading"
          />
        </ElCard>
      </ElCol>
    </ElRow>

    <!-- 今日课程（授课老师的核心视图） -->
    <ElRow v-if="isCoach" :gutter="16" class="mb-4">
      <ElCol :span="24">
        <ElCard shadow="never">
          <template #header>
            <div class="flex items-center justify-between">
              <span class="font-500">今日课程（{{ todayClasses.length }} 节）</span>
              <span class="text-xs text-gray-400">来自随心瑜排课事实</span>
            </div>
          </template>
          <ElTable v-if="!isHandheld" :data="todayClasses" size="default" v-loading="loading">
            <ElTableColumn prop="time" label="时间" width="90" />
            <ElTableColumn prop="memberName" label="学员" width="120" />
            <ElTableColumn prop="course" label="课程" min-width="150" show-overflow-tooltip />
            <ElTableColumn prop="kind" label="课型" width="90">
              <template #default="{ row }">
                <ElTag size="small" :type="row.kind === '私教' ? 'danger' : 'info'" effect="plain">
                  {{ row.kind }}
                </ElTag>
              </template>
            </ElTableColumn>
            <ElTableColumn label="性质" width="90">
              <template #default="{ row }">
                <ElTag v-if="row.isTrial" size="small" type="warning" effect="dark">体验课</ElTag>
                <span v-else class="text-xs text-gray-400">常规</span>
              </template>
            </ElTableColumn>
            <ElTableColumn prop="status" label="状态" width="100">
              <template #default="{ row }">
                <ElTag size="small" :type="row.status === 'signed' ? 'success' : 'primary'">
                  {{ row.status === 'signed' ? '已签到' : '已预约' }}
                </ElTag>
              </template>
            </ElTableColumn>
          </ElTable>
          <ElEmpty
            v-if="!isHandheld && !loading && !todayClasses.length"
            description="今天没有排课"
            :image-size="70"
          />

          <!-- 手持设备：卡片列表。6 列合计约 640px，390px 屏上横滑也看不到完整一行 -->
          <div v-if="isHandheld" v-loading="loading" class="m-card-list min-h-[120px]">
            <MobileCard
              v-for="item in todayClassCardRows"
              :key="item.id"
              :title="item.title"
              :subtitle="item.subtitle"
              :tags="item.tags"
              :metrics="item.metrics"
              :actions="item.actions"
            />
            <div v-if="!loading && !todayClassCardRows.length" class="m-card-list__empty">
              暂无数据
            </div>
          </div>
        </ElCard>
      </ElCol>
    </ElRow>

    <!-- 今日待办（概要，点击进入完整待办页） -->
    <YimaiTodayTodo variant="summary" class="mb-4" />

    <!-- 我的客资池 -->
    <ElRow :gutter="16">
      <ElCol :span="24">
        <ElCard shadow="never">
          <template #header>
            <div class="flex items-center justify-between">
              <span class="font-500">{{ isCoach ? '我的相关客资' : '待我跟进的客资' }}</span>
              <ElButton link type="primary" @click="$router.push('/yimai/leads')"
                >进入留资管理</ElButton
              >
            </div>
          </template>
          <ElTable v-if="!isHandheld" :data="myLeads" size="default" v-loading="loading">
            <ElTableColumn prop="name" label="客户" width="110" />
            <ElTableColumn prop="venue" label="门店" width="90" />
            <ElTableColumn prop="demand" label="需求" min-width="120" show-overflow-tooltip />
            <ElTableColumn prop="source" label="来源" width="110" />
            <ElTableColumn prop="status" label="状态" width="95">
              <template #default="{ row }">
                <ElTag
                  size="small"
                  :type="
                    row.status === '已成交'
                      ? 'success'
                      : row.status === '新留资'
                        ? 'danger'
                        : 'primary'
                  "
                >
                  {{ row.status }}
                </ElTag>
              </template>
            </ElTableColumn>
            <ElTableColumn prop="remark" label="备注" min-width="160" show-overflow-tooltip />
          </ElTable>
          <ElEmpty
            v-if="!isHandheld && !loading && !myLeads.length"
            description="暂无待跟进客资"
            :image-size="70"
          />

          <!-- 手持设备：卡片列表。6 列合计约 685px，窄屏只剩左右两列可见 -->
          <div v-if="isHandheld" v-loading="loading" class="m-card-list min-h-[120px]">
            <MobileCard
              v-for="item in leadCardRows"
              :key="item.id"
              :title="item.title"
              :subtitle="item.subtitle"
              :tags="item.tags"
              :note="item.note"
            />
            <div v-if="!loading && !leadCardRows.length" class="m-card-list__empty">暂无数据</div>
          </div>
        </ElCard>
      </ElCol>
    </ElRow>
  </div>
</template>

<script setup lang="ts">
  import YimaiKpiCard from './kpi-card.vue'
  import YimaiTodayTodo from './today-todo.vue'
  import { getPostClassPendingCount, getTeacherOverview, queryLeads } from '@/api/yimai'
  import type { YimaiLead, TeacherOverview, TeacherTodayClass } from '@/api/yimai'
  import { useUserStore } from '@/store/modules/user'
  import { useDevice } from '@/hooks/core/useDevice'
  import type {
    MobileCardAction,
    MobileCardMetric,
    MobileCardTag
  } from '@/components/business/mobile-card/types'
  import {
    User,
    Calendar,
    Medal,
    Aim,
    Place,
    Odometer,
    Wallet,
    Trophy
  } from '@element-plus/icons-vue'
  import type { LineDataItem } from '@/types/component/chart'
  import DateRangeControl from './date-range-control.vue'

  defineOptions({ name: 'TeacherDashboard' })

  // 手持设备上用卡片列表代替两张宽表格（卡片字段见下方「移动端卡片」）
  const { isHandheld } = useDevice()
  const router = useRouter()

  const userStore = useUserStore()
  const staffName = computed(() => userStore.getUserInfo.staffName ?? '')
  const roles = computed(() => userStore.getUserInfo.roles ?? [])
  const venues = computed(() => userStore.getUserInfo.venues ?? [])
  const isDualStore = computed(() => venues.value.length > 1)
  // 服务老师（会籍顾问）与授课老师（私教主教练）共用此工作台，卡片口径不同
  const isCoach = computed(() => roles.value.includes('R_TEACHER'))

  const loading = ref(true)
  const loadError = ref('')
  const range = ref<[string, string]>(defaultRange())
  const overview = ref<TeacherOverview | null>(null)
  const myLeads = ref<YimaiLead[]>([])
  const pendingReviewCount = ref(0)
  const leadLadder = reactive<Record<string, number>>({
    新留资: 0,
    已联系: 0,
    已约体验: 0,
    已体验: 0,
    已成交: 0
  })

  const roleLabel = computed(
    () => overview.value?.roleLabel ?? (isCoach.value ? '授课老师' : '服务老师')
  )
  const scopeLabel = computed(
    () => overview.value?.scopeLabel ?? userStore.getUserInfo.venue ?? '未选择门店'
  )
  const todayClasses = computed<TeacherTodayClass[]>(() => overview.value?.todayClasses ?? [])

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

  const labels = computed(() => (overview.value?.series ?? []).map((p) => p.label))
  // 真实排课趋势（不再用「当日预约 × 0.6」的估算值）
  const trendSeries = computed<LineDataItem[]>(() => {
    const series = overview.value?.series ?? []
    return [
      {
        name: isCoach.value ? '上课节数' : '客资数',
        data: series.map((p) =>
          isCoach.value ? p.classes : ((p as unknown as { leads?: number }).leads ?? 0)
        )
      },
      {
        name: '服务人次',
        data: series.map((p) => p.served),
        color: '#67C23A'
      }
    ]
  })
  const leadFunnelLabels = computed(() =>
    Object.keys(leadLadder).map((s) => `${s} ${leadLadder[s]}`)
  )
  const leadFunnelSeries = computed(() => Object.keys(leadLadder).map((s) => leadLadder[s]))

  const kpiList = computed(() => {
    const ov = overview.value
    const num = (v: number | undefined | null) => (ov ? (v ?? 0) : '-')
    if (isCoach.value) {
      // 授课老师（私教主教练）：看得见自己上过课的学员与本人名下的会籍会员
      const k = ov?.kindCount ?? {}
      return [
        {
          label: '我的学员（私教）',
          value: num(ov?.teachStudentCount),
          hint: '仅私教，不含小班/团课',
          icon: markRaw(User),
          accent: '#409EFF'
        },
        {
          label: '我的会籍会员',
          value: num(ov?.serviceMemberCount),
          hint: '挂在自己名下',
          icon: markRaw(Trophy),
          accent: '#9C27B0'
        },
        {
          label: '我的课（节）',
          value: num(ov?.classCount),
          hint: ov
            ? `私教 ${k['私教'] ?? 0} · 小班 ${k['小班'] ?? 0} · 团课 ${k['团课'] ?? 0}`
            : '',
          icon: markRaw(Calendar),
          accent: '#67C23A'
        },
        {
          label: '服务人次',
          value: num(ov?.servedCount),
          hint: '同期实际服务人数',
          icon: markRaw(Medal),
          accent: '#00BCD4'
        },
        {
          label: '今日课程',
          value: num(ov?.todayClassCount),
          hint: ov ? `今日 ${ov.todayClasses.length} 节` : '',
          icon: markRaw(Place),
          accent: '#E6A23C'
        },
        {
          label: '成交率',
          value: ov ? `${ov.dealRate}` : '-',
          suffix: '%',
          hint: ov ? `${ov.dealCount} 成交 / ${ov.visitCount} 到店` : '',
          icon: markRaw(Odometer),
          accent: '#F56C6C'
        },
        {
          label: '成交金额',
          value: `¥${Number(Math.round(ov?.dealAmount ?? 0)).toLocaleString('zh-CN', { maximumFractionDigits: 0 })}`,
          hint: `${ov?.dealCount ?? 0} 人`,
          icon: markRaw(Wallet),
          accent: '#FF9800'
        }
      ]
    }
    // 服务老师（会籍顾问）：只看自己名下的会员与客资
    return [
      {
        label: '我的会员',
        value: num(ov?.memberCount),
        hint: '会籍归属本人',
        icon: markRaw(User),
        accent: '#409EFF'
      },
      {
        label: '我的客资',
        value: num(ov?.myLeadCount),
        hint: '名下全部客资',
        icon: markRaw(Aim),
        accent: '#E6A23C'
      },
      {
        label: '待承接',
        value: num(ov?.newResourceCount),
        hint: '新留资待首响',
        icon: markRaw(Place),
        accent: '#F56C6C'
      },
      {
        label: '到店数',
        value: num(ov?.visitCount),
        hint: '已体验 / 已成交',
        icon: markRaw(Medal),
        accent: '#00BCD4'
      },
      {
        label: '成交率',
        value: ov ? `${ov.dealRate}` : '-',
        suffix: '%',
        hint: ov ? `${ov.dealCount} 成交 / ${ov.visitCount} 到店` : '',
        icon: markRaw(Odometer),
        accent: '#9C27B0'
      },
      {
        label: '成交金额',
        value: `¥${Number(Math.round(ov?.dealAmount ?? 0)).toLocaleString('zh-CN', { maximumFractionDigits: 0 })}`,
        hint: `${ov?.dealCount ?? 0} 人`,
        icon: markRaw(Wallet),
        accent: '#FF9800'
      }
    ]
  })

  const kpis = computed(() => kpiList.value)

  // ---------- 移动端卡片 ----------
  //
  // 两张表各 6 列（640px / 685px），390px 屏上横滑也只能看到两三列，
  // 所以窄屏换卡片：每张只留老师当场要看的字段，明细留在桌面端表格。

  /** 今日课程标签：课型 + 性质 + 签到状态（对应表格里的三列） */
  function courseCardTags(row: TeacherTodayClass): MobileCardTag[] {
    return [
      { text: row.kind, type: row.kind === '私教' ? 'danger' : 'info', effect: 'plain' },
      row.isTrial
        ? { text: '体验课', type: 'warning', effect: 'dark' }
        : { text: '常规', effect: 'plain' },
      {
        text: row.status === 'signed' ? '已签到' : '已预约',
        type: row.status === 'signed' ? 'success' : 'primary'
      }
    ]
  }

  /** 今日课程指标：只要「几点上课」，学员与课程名已经在标题区 */
  function courseCardMetrics(row: TeacherTodayClass): MobileCardMetric[] {
    return [{ label: '时间', value: row.time }]
  }

  /**
   * 今日课程操作：课后分析入口
   *
   * 桌面端每行没有按钮，只能走顶部入口；老师下课后人还在教室，从课表直接点进去更顺手。
   * 只在卡片上补，桌面端表格保持不变。
   */
  function courseCardActions(): MobileCardAction[] {
    return [{ text: '填写分析', type: 'primary', onClick: () => router.push('/yimai/post-class') }]
  }

  /** 预计算一次，避免模板里对每行重复调用多个函数 */
  const todayClassCardRows = computed(() =>
    todayClasses.value.map((row) => ({
      id: row.id,
      title: row.memberName,
      subtitle: row.course,
      tags: courseCardTags(row),
      metrics: courseCardMetrics(row),
      actions: courseCardActions()
    }))
  )

  /** 客资标签：门店 + 来源 + 状态（状态沿用桌面端的 success / danger / primary 三档） */
  function leadCardTags(row: YimaiLead): MobileCardTag[] {
    const tags: MobileCardTag[] = [{ text: row.venue, effect: 'plain' }]
    if (row.source) tags.push({ text: row.source, effect: 'plain' })
    tags.push({
      text: row.status,
      type: row.status === '已成交' ? 'success' : row.status === '新留资' ? 'danger' : 'primary'
    })
    return tags
  }

  const leadCardRows = computed(() =>
    myLeads.value.map((row) => ({
      id: row.id,
      title: row.name,
      subtitle: row.demand,
      tags: leadCardTags(row),
      note: row.remark || ''
    }))
  )

  function onRangeChange(v: [string, string]) {
    range.value = v
    reload()
  }

  async function reload() {
    loading.value = true
    loadError.value = ''
    const settled = await Promise.allSettled([
      getTeacherOverview(range.value[0], range.value[1]),
      queryLeads({ current: 1, size: 50 }),
      getPostClassPendingCount(3)
    ])
    if (settled[0].status === 'fulfilled') {
      overview.value = settled[0].value
    } else {
      loadError.value =
        (settled[0].reason as { message?: string })?.message ?? '老师工作台数据加载失败，请稍后重试'
    }
    if (settled[1].status === 'fulfilled') {
      const all = settled[1].value.records
      myLeads.value = isCoach.value
        ? all.filter(
            (l) => l.serviceTeacher === staffName.value || l.trialTeacher === staffName.value
          )
        : all
      Object.keys(leadLadder).forEach((k) => (leadLadder[k] = 0))
      myLeads.value.forEach((l) => {
        if (leadLadder[l.status] !== undefined) leadLadder[l.status] += 1
      })
    }
    if (settled[2].status === 'fulfilled') {
      pendingReviewCount.value = settled[2].value.count ?? 0
    }
    loading.value = false
  }

  onMounted(reload)
</script>
