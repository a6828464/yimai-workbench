<template>
  <div class="p-4">
    <ElAlert
      title="经营看板：指标基于当前会员 / 留资 / 任务 / 续费数据实时计算"
      type="info"
      show-icon
      :closable="false"
      class="mb-4"
    />
    <ElAlert
      v-if="analyticsError"
      :title="analyticsError"
      type="error"
      show-icon
      :closable="false"
      class="mb-4"
    />

    <!-- 同栏目内的「薪酬计算」入口 + 概括（含两店分别数据）。
         该页仅超管可见（路由 meta.roles = SUPER，与经营看板同组），所以这里不需要再判角色；
         但入口必须放在看板里 —— 用户原话是「经营看板里面给我加一个薪酬计算的栏目」，
         只挂侧边菜单不算「在看板里」。

         ⚠️ 两店相加 ≠ 合并合计：跨店授课的老师会同时出现在两店的工资表里
         （所属门店一处、实际授课门店一处），这是引擎的规定（S1:161-164），
         不这样就会出现「她在那家店的课时费凭空消失」。所以「合并」一列走的是
         各自所属门店口径，与两店列**不是加法关系**，界面上必须写清楚。 -->
    <ElCard shadow="never" class="mb-4 payroll-entry">
      <div class="payroll-entry__row">
        <div class="payroll-entry__text">
          <div class="payroll-entry__title">薪酬计算</div>
          <div class="payroll-entry__desc">
            老师课时数（课次口径，45/60 分钟可辨识）、课时费与身份标签设置、业绩表导入、考勤社保个税月度输入与工资计算
          </div>
        </div>
        <ElButton type="primary" class="payroll-entry__btn" @click="goPayroll">
          进入薪酬计算
          <ArtSvgIcon icon="ri:arrow-right-line" class="ml-1" />
        </ElButton>
      </div>

      <div v-if="payrollError" class="mt-3 text-sm text-orange-500">{{ payrollError }}</div>
      <template v-else>
        <div class="payroll-summary mt-4">
          <div class="payroll-summary__head">
            <span class="font-500">{{ payrollMonth }} 工资概括</span>
            <div class="flex items-center gap-2">
              <ElTag v-if="payrollBlocked" size="small" type="danger" effect="dark">
                结果不可用于交付
              </ElTag>
              <span v-if="payrollPendingCount > 0" class="text-xs text-warning">
                {{ payrollPendingCount }} 人待完善，未计入
              </span>
              <span class="text-xs text-gray-400">数据来自薪酬计算（月度输入未填时按默认值，明细见该页）</span>
            </div>
          </div>

          <ElTable :data="payrollRows" border stripe size="small">
            <ElTableColumn prop="venue" label="门店" width="110" />
            <ElTableColumn prop="headcount" label="人数" align="right" width="80" />
            <ElTableColumn label="总课时" align="right" width="90">
              <template #default="{ row }">{{ row.hours }} 节</template>
            </ElTableColumn>
            <ElTableColumn label="应发合计" align="right">
              <template #default="{ row }">¥{{ money(row.gross) }}</template>
            </ElTableColumn>
            <ElTableColumn label="社保合计" align="right">
              <template #default="{ row }">¥{{ money(row.socialSecurity) }}</template>
            </ElTableColumn>
            <ElTableColumn label="个税合计" align="right">
              <template #default="{ row }">¥{{ money(row.tax) }}</template>
            </ElTableColumn>
            <ElTableColumn label="实发合计" align="right">
              <template #default="{ row }">
                <span class="font-600">¥{{ money(row.net) }}</span>
              </template>
            </ElTableColumn>
          </ElTable>
          <div class="mt-2 text-xs text-gray-400">
            「合并」按各自所属门店口径统计（同一人只算一次）；两家门店列各自含跨店授课的课时，
            所以<b>两店相加不等于合并</b> —— 跨店老师在两店的工资表里各出现一次，这是规定口径，不是重复计算。
          </div>
        </div>
      </template>
    </ElCard>

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
      <ElCol :span="24" class="mb-4">
        <ElCard shadow="never">
          <template #header>
            <div class="flex-cb">
              <span class="font-500">新媒体线上运营业绩（当月）</span>
              <ElTag size="small" effect="plain">时效内线上新客</ElTag>
            </div>
          </template>
          <div v-if="!media" class="text-sm text-gray-400">暂无数据</div>
          <template v-else>
            <div class="grid grid-cols-3 gap-4 text-center">
              <div>
                <div class="text-2xl font-600 text-success">¥{{ media.visitRewardAmount }}</div>
                <div class="text-xs text-gray-400 mt-1">新客到店奖励</div>
                <div class="text-xs text-gray-400 mt-1">
                  {{ media.breakdown.validVisitCount }} 人 × {{ media.params.visitReward }} 元
                </div>
              </div>
              <div>
                <div class="text-2xl font-600">{{ media.dealRate }}%</div>
                <div class="text-xs text-gray-400 mt-1">线上新客成交率</div>
                <div class="text-xs text-gray-400 mt-1">
                  {{ media.breakdown.validDealCount }} ÷ {{ media.breakdown.validVisitCount }}（分子分母同源）
                </div>
              </div>
              <div>
                <div class="text-2xl font-600 text-danger">¥{{ media.commissionAmount }}</div>
                <div class="text-xs text-gray-400 mt-1">核销提成</div>
                <div class="text-xs text-gray-400 mt-1">
                  成交率 × 时效内核销 ¥{{ media.breakdown.validRedeemAmount }}
                </div>
              </div>
            </div>

            <!-- 口径明细：运营要能自己把账对上（分母含上月留资本月到店的人是易错点） -->
            <ElDescriptions :column="2" border size="small" class="mt-4">
              <ElDescriptionsItem label="有效到店人数">
                {{ media.breakdown.validVisitCount }} 人
                <span class="text-gray-400">
                  （其中上月留资本月到店 {{ media.breakdown.validVisitsFromPrevMonth }} 人，已计入成交率分母）
                </span>
              </ElDescriptionsItem>
              <ElDescriptionsItem label="有效成交人数">
                {{ media.breakdown.validDealCount }} 人
                <span class="text-gray-400">
                  （其中上月留资本月成交 {{ media.breakdown.validDealsFromPrevMonth }} 人）
                </span>
              </ElDescriptionsItem>
              <ElDescriptionsItem label="时效内核销金额">
                ¥{{ media.breakdown.validRedeemAmount }}
              </ElDescriptionsItem>
              <ElDescriptionsItem label="已排除核销金额">
                ¥{{ media.breakdown.excludedRedeemAmount }}
                <span class="text-gray-400">（线下渠道或超出时效）</span>
              </ElDescriptionsItem>
            </ElDescriptions>

            <div class="mt-3 text-xs text-gray-500">
              <div v-if="mediaRange.start">
                取数区间：{{ mediaRange.start }} ~ {{ mediaRange.end }}（自然月至今，非上方近30天滚动窗口）
              </div>
              <div>口径：{{ media.params.rule }}；线下渠道不计入。</div>
              <div>到店奖励 = {{ media.formula.visitReward }}</div>
              <div>成交率 = {{ media.formula.dealRate }}</div>
              <div>核销提成 = {{ media.formula.commission }}</div>
              <div v-if="media.breakdown.unpairedVisitCount > 0" class="text-warning mt-1">
                另有 {{ media.breakdown.unpairedVisitCount }} 条到店记录因留资日期缺失无法核对时效，未计入；
                请检查数据完整性。
              </div>
            </div>
          </template>
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
  import { fetchPayrollCalculate } from '@/api/payroll'
  import { toLocalDateString } from '@/utils'
  import { useRouter } from 'vue-router'
  import type { MediaPerformance } from '@/api/yimai'

  defineOptions({ name: 'YimaiAnalytics' })

  const router = useRouter()

  /** 跳到同栏目下的「薪酬计算」页（仅超管，路由组已限） */
  function goPayroll() {
    router.push('/yimai/payroll')
  }

  // ---------- 薪酬概括（含两店分别数据） ----------
  //
  // 口径说明（重要）：合并视图与单店视图**不是加法关系**。
  // PayrollService::calculate 的候选集合含「跨店授课的老师」（所属门店一处、
  // 本月实际在另一家店上课）—— 引擎规定这类人必须出现在**两家店**的工资表里，
  // 否则她那部分课时费凭空消失（S1:161-164）。所以：
  //   · 两店列各自含跨店课时，相加 > 合并；
  //   · 合并列按各自所属门店口径统计，同一人只算一次。
  // 界面上必须写清这一点，否则用户会以为系统重复计算了。
  const payrollMonth = ref('')
  const payrollRows = ref<
    {
      venue: string
      headcount: number
      hours: number
      gross: number
      socialSecurity: number
      tax: number
      net: number
    }[]
  >([])
  const payrollError = ref('')
  const payrollBlocked = ref(false)
  const payrollPendingCount = ref(0)

  async function loadPayrollSummary() {
    const now = new Date()
    const month = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
    payrollMonth.value = month
    payrollError.value = ''
    try {
      // 三个视图并发：合并 + 两店。任一个失败不影响另外两个（分店视图仍可看）
      const [all, green, east] = await Promise.allSettled([
        fetchPayrollCalculate(month, null),
        fetchPayrollCalculate(month, '绿地店'),
        fetchPayrollCalculate(month, '东部店')
      ])

      const rows: typeof payrollRows.value = []
      const pick = (
        venue: string,
        r: PromiseSettledResult<Awaited<ReturnType<typeof fetchPayrollCalculate>>>
      ) => {
        if (r.status !== 'fulfilled') return
        const t = r.value.storeTotal
        rows.push({
          venue,
          headcount: t.headcount,
          hours: t.hours,
          gross: t.gross,
          socialSecurity: t.socialSecurity,
          tax: t.tax,
          net: t.net
        })
      }
      pick('合并（按所属门店）', all)
      pick('绿地店', green)
      pick('东部店', east)
      payrollRows.value = rows

      if (all.status === 'fulfilled') {
        payrollBlocked.value = (all.value.blocked ?? []).length > 0
        // 「待完善」人数：这些人整行不参与计算，必须在概括里显式提示，
        // 否则用户会以为工资算全了（实际少人）。
        // 用后端结构化下发的 pendingNames，不去正则解析那句人话（改文案就断）。
        payrollPendingCount.value = (all.value.pendingNames ?? []).length
      }

      const failed = [all, green, east].filter((r) => r.status === 'rejected').length
      if (failed === 3) {
        payrollError.value = '薪酬数据加载失败，请稍后重试'
      } else if (failed > 0) {
        payrollError.value = '部分门店薪酬数据读取失败，已显示其余部分'
      }
    } catch (e) {
      payrollRows.value = []
      payrollError.value = '薪酬数据加载失败，请稍后重试'
    }
  }

  /** 金额千分位（与薪酬页一致，避免两处格式不同让人对不上账） */
  function money(v: number | undefined): string {
    return Number(v ?? 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
  }

  /** 新媒体线上运营业绩（到店奖励 + 核销提成）；后端未返回时为 undefined，页面显示「暂无数据」而非编造 0 */
  const media = ref<MediaPerformance | undefined>(undefined)
  /** 业绩口径对应的取数区间（自然月 1 号至今），显示在卡片上以免与上方的近30天混淆 */
  const mediaRange = ref<{ start: string; end: string }>({ start: '', end: '' })

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
  const analyticsError = ref('')

  function last30Days(): { start: string; end: string } {
    const end = new Date()
    const start = new Date()
    start.setDate(start.getDate() - 29)
    const iso = (dt: Date) => toLocalDateString(dt)
    return { start: iso(start), end: iso(end) }
  }

  /**
   * 新媒体业绩必须按**自然月**取数，不能用上面那块「近30天」滚动窗口。
   *
   * 业务口径是「当月」：当月到店奖励、当月核销提成、当月有效成交率。
   * 滚动 30 天会跨月，把上月的到店/核销也算进来 —— 实测同一份数据下
   * 滚动窗口给 2 人到店 / ¥40，自然月给 1 人 / ¥20，**金额会对不上账**。
   * 所以这里单独请求一次本月 1 号至今的区间。
   */
  function currentMonth(): { start: string; end: string } {
    const now = new Date()
    const iso = (dt: Date) => toLocalDateString(dt)
    return { start: iso(new Date(now.getFullYear(), now.getMonth(), 1)), end: iso(now) }
  }

  async function loadTrends() {
    const { start, end } = last30Days()
    trendLoading.value = true
    channelLoading.value = true
    try {
      const t = await apiGet<{
        daily: Record<string, unknown>[]
        visit30: number
        activeCustomers: number
        attendanceSummary: { m1: number; m2: number; m3: number }
      }>('/analytics/trends', { start, end })
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
      // 新媒体业绩单独按自然月取（见 currentMonth() 注释：滚动窗口会跨月导致对不上账）
      const cm = currentMonth()
      const mediaRes = await apiGet<{ summary?: { mediaPerformance?: MediaPerformance } }>(
        '/analytics/trends',
        { start: cm.start, end: cm.end }
      )
      media.value = mediaRes.summary?.mediaPerformance
      mediaRange.value = cm
      const c = await apiGet<{ rows: { channel: string; leads: number }[]; total: number }>('/analytics/channels', { start, end })
      channelRows.value = (c.rows ?? []).sort((a, b) => b.leads - a.leads)
    } catch (e) {
      // 区分「加载失败」与「无数据」，避免故障时 KPI 静默显示 0 误导决策
      const status = (e as { response?: { status?: number } })?.response?.status
      analyticsError.value = status === 401 || status === 403 ? '' : '看板数据加载失败，请稍后重试'
    } finally {
      trendLoading.value = false
      channelLoading.value = false
    }
  }

  onMounted(async () => {
    try {
      const res = await apiGet<Record<string, number>>('/analytics/summary')
      d.value = { ...d.value, ...res }
      analyticsError.value = ''
    } catch (e) {
      const status = (e as { response?: { status?: number } })?.response?.status
      analyticsError.value = status === 401 || status === 403 ? '' : '看板数据加载失败，请稍后重试'
    }
    await loadTrends()
    // 薪酬概括与看板其它数据并行拉取（薪酬计算较重，不阻塞上面的图表）
    await loadPayrollSummary()
  })
</script>

<style scoped lang="scss">
  // 「薪酬计算」入口条：桌面端一行左右分列，手机端换行且按钮撑满
  // （触控 >=44px 由 assets/styles/core/mobile.scss 的全局兜底保证，这里只管布局）
  .payroll-entry {
    &__row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
    }

    &__text {
      min-width: 0;
    }

    &__title {
      font-weight: 500;
    }

    &__desc {
      margin-top: 4px;
      font-size: 12px;
      line-height: 1.6;
      color: var(--art-gray-500);
    }

    &__btn {
      flex: 0 0 auto;
    }

    @media (max-width: 768px) {
      &__row {
        flex-direction: column;
        align-items: stretch;
      }

      &__btn {
        width: 100%;
      }
    }
  }

  // 薪酬概括：桌面端表头左右分列，手机端换行；表格在窄屏横向滚动而不挤压列
  .payroll-summary {
    &__head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 8px;
      margin-bottom: 8px;
      flex-wrap: wrap;
    }

    :deep(.el-table) {
      font-size: 12px;
    }

    @media (max-width: 768px) {
      &__head {
        flex-direction: column;
        align-items: flex-start;
      }
    }
  }
</style>
