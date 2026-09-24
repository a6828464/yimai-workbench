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
            <div class="flex-cb media-head">
              <span class="font-500">新媒体线上运营业绩（当月）</span>
              <div class="flex items-center gap-2 flex-wrap">
                <ElTag size="small" effect="plain">时效内线上新客</ElTag>
                <!-- 门店视图：只作用于新媒体这两块（上方合计 + 下方拆分），本页其它图表不受影响。
                     「双店合计」是默认态，与本页改动前完全一致（不传 venue）。 -->
                <ElRadioGroup v-model="mediaVenue" @change="loadMedia">
                  <ElRadioButton value="双店">双店合计</ElRadioButton>
                  <ElRadioButton value="绿地店">绿地店</ElRadioButton>
                  <ElRadioButton value="东部店">东部店</ElRadioButton>
                </ElRadioGroup>
              </div>
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

    <!-- 新媒体数据 · 分店拆分。
         用户反馈：「新媒体数据：目前只有一个总和，再给我拆分出东部店和绿地店的数据」。

         仅在「双店」视图显示：单店视图下上方那块合计**本身就是这一家店**，
         再拆一次等于把同一行数字抄第二遍（纯噪声）。

         口径：与工作台 today/modules/boss-dashboard.vue 的「新媒体数据 · 分店拆分」
         **完全同源** —— 都是复用既有 /analytics/trends 的 venue 参数按店各取一次，
         不另写聚合（另写一份就会出现第二套口径）。两页只有**取数窗口**不同，
         这是刻意的：本页新媒体块走自然月 currentMonth()（见该函数注释），
         工作台走它自己的滚动区间。拆分沿用**本页**窗口，否则两页对不上账。 -->
    <ElRow v-if="isDualStore" :gutter="16" class="mb-4 media-split">
      <ElCol :span="24" class="mb-4">
        <ElCard shadow="never" data-test="media-split">
          <template #header>
            <div class="flex-cb">
              <span class="font-500">新媒体数据 · 分店拆分</span>
              <ElTag size="small" effect="plain">仅新媒体登记来源</ElTag>
            </div>
          </template>
          <div v-if="mediaSplitError" class="text-sm text-orange-500">{{ mediaSplitError }}</div>
          <template v-else>
            <ElTable :data="mediaSplitRows" border stripe size="small">
              <ElTableColumn prop="venue" label="门店" width="96" />
              <ElTableColumn prop="leads" label="留资人数" align="right" />
              <ElTableColumn prop="visits" label="到店人数" align="right" />
              <ElTableColumn prop="deals" label="成交人数" align="right" />
              <ElTableColumn label="线上成交率" align="right">
                <!-- 分母为 0 时显示「—」而不是 0%：没有到店客人时成交率不是一个数 -->
                <template #default="{ row }">
                  {{ row.visits > 0 ? `${row.dealRate}%` : '—' }}
                </template>
              </ElTableColumn>
              <ElTableColumn label="到店奖励" align="right">
                <template #default="{ row }">¥{{ money(row.visitRewardAmount) }}</template>
              </ElTableColumn>
              <ElTableColumn label="核销提成" align="right">
                <template #default="{ row }">¥{{ money(row.commissionAmount) }}</template>
              </ElTableColumn>
            </ElTable>

            <!-- 对账：逐项给出「两店相加 vs 合计」。到店/成交按人去重，
                 同一人两店都留资时相加会合理地大于合计 —— 如实显示差额而不是把它藏起来 -->
            <div class="mt-2 text-xs text-gray-400">
              <template v-if="mediaSplitRange.start">
                取数区间：{{ mediaSplitRange.start }} ~
                {{ mediaSplitRange.end }}（自然月至今，与本页上方新媒体块同一窗口）
              </template>
              <div v-for="c in mediaSplitChecks" :key="c.metric">
                {{ c.metric }}：{{ c.sum }}（两店相加）
                <span :class="c.ok ? '' : 'text-warning'">
                  {{ c.ok ? '=' : '≠' }} {{ c.total }}（上方合计）
                </span>
                <span v-if="!c.ok" class="text-warning">
                  —— 该客人两家店都有记录，各自门店分别计入，不跨店去重
                </span>
              </div>
              <div>
                成交率的分母是「线上到店」而非「线上留资」；到店奖励与核销提成只算 2
                个月时效内的线上新客（{{ media?.params.validMonths ?? 2 }} 个月，单价
                {{ media?.params.visitReward ?? 20 }} 元/人）。
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
  import { getDashboardSeries, mediaSplitRowFrom, checkMediaSplitSums } from '@/api/yimai'
  import { toLocalDateString } from '@/utils'
  import { useRouter } from 'vue-router'
  import type { MediaPerformance, MediaSplitRow, DashboardSummary } from '@/api/yimai'

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

  // ---------- 新媒体数据 · 分店拆分 ----------
  //
  // 门店视图只作用于上方「新媒体线上运营业绩（当月）」合计 + 下方拆分表；
  // 本页其它图表（留资走势 / 来源分布 / 活跃度）走各自既有口径，不受这里影响。
  const mediaVenue = ref<'双店' | '绿地店' | '东部店'>('双店')
  /** 只在「双店合计」视图才拆 —— 单店视图下合计本身就是那一家店，再拆是同一行抄两遍 */
  const isDualStore = computed(() => mediaVenue.value === '双店')
  const mediaSplitRows = ref<MediaSplitRow[]>([])
  const mediaSplitError = ref('')
  const mediaSplitRange = ref<{ start: string; end: string }>({ start: '', end: '' })

  /**
   * 逐项对账「两店相加 vs 合计」。合计值取**本页已经取回的那一份** summary，
   * 不为对账多发一次请求。
   *
   * 没有合计（本次请求失败，或单店视图/缺一店故不作结论）时返回空数组 ——
   * 否则会拿 undefined 当 0，显示成「两店相加 ≠ 0（上方合计）」这种误导性结论。
   */
  const mediaSplitChecks = computed(() =>
    mediaSplitScopeSummary.value
      ? checkMediaSplitSums(mediaSplitScopeSummary.value, mediaSplitRows.value)
      : []
  )
  /** 「双店合计」视图下那次请求的 summary，供对账用（不额外请求） */
  const mediaSplitScopeSummary = ref<DashboardSummary | undefined>(undefined)

  const MEDIA_SPLIT_VENUES = ['绿地店', '东部店'] as const

  /**
   * 取新媒体业绩 + （双店时）分店拆分。
   *
   * 口径与工作台 today/modules/boss-dashboard.vue 的 `loadMediaSplit()` 同源：
   * 拆分的每一行都来自**既有** getDashboardSeries(start, end, venue) —— 即后端
   * /analytics/trends 的 venue 参数，由 applyVenueScope 收窄留资/到店/成交/核销四路。
   * 不新写聚合、不新写公式：`mediaSplitRowFrom()` 只是字段映射。
   *
   * 窗口固定用本页的 currentMonth()（自然月至今），**不是**工作台的滚动区间 ——
   * 两页各自的窗口是刻意的（见 currentMonth() 注释），换了就两页对不上账。
   *
   * 并发数：双店视图 3 个请求（合计 + 两店），单店视图 1 个。成交率直接取各店响应
   * 自带的 onlineDealRate，**不额外发第 4 个重请求**。
   */
  async function loadMedia(): Promise<void> {
    const cm = currentMonth()
    mediaRange.value = cm
    mediaSplitRange.value = cm
    mediaSplitError.value = ''

    const scope = mediaVenue.value
    const isDual = scope === '双店'
    // 单店视图下清空，避免残留上一次双店的对账结论
    mediaSplitRows.value = []
    mediaSplitScopeSummary.value = undefined

    const [total, ...perVenue] = await Promise.allSettled([
      getDashboardSeries(cm.start, cm.end, scope),
      ...(isDual ? MEDIA_SPLIT_VENUES.map((v) => getDashboardSeries(cm.start, cm.end, v)) : [])
    ])

    if (total.status === 'fulfilled') {
      media.value = total.value.summary?.mediaPerformance
      // 对账基准只在双店视图有意义（单店视图的合计就是那一家店，相减恒为 0）
      if (isDual) mediaSplitScopeSummary.value = total.value.summary
    } else {
      media.value = undefined
    }

    if (!isDual) return

    const rows: MediaSplitRow[] = []
    const errors: string[] = []
    perVenue.forEach((r, i) => {
      const venue = MEDIA_SPLIT_VENUES[i]
      if (r.status !== 'fulfilled') {
        errors.push(`${venue}读取失败`)
        return
      }
      // 纯字段映射：这一行的数字全部来自该店自己的响应，没有第二套公式
      rows.push(mediaSplitRowFrom(venue, r.value.summary))
    })
    mediaSplitRows.value = rows
    mediaSplitError.value = errors.join('；')

    // 失败时不给「两店相加」下结论（缺一店的相加必然不等于合计，会误导）
    if (errors.length > 0) {
      mediaSplitScopeSummary.value = undefined
    }

    // 自校验：可加三项两店相加必须等于合计。窗口本身没数据时（全为 0）跳过，
    // 那只说明这月还没有新媒体留资，不是口径出错。
    if (mediaSplitScopeSummary.value && rows.length === MEDIA_SPLIT_VENUES.length) {
      const checks = checkMediaSplitSums(mediaSplitScopeSummary.value, rows)
      const mismatched = checks.filter((c) => !c.ok && (c.total > 0 || c.sum > 0))
      if (import.meta.env.DEV && mismatched.length > 0) {
        console.warn(
          '[analytics] 新媒体分店拆分与合计不一致，请核对两店归属：',
          mismatched.map((c) => `${c.metric} 合计${c.total} vs 两店相加${c.sum}`)
        )
      }
    }
  }

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
      // 新媒体业绩 + 分店拆分单独按自然月取（见 currentMonth() 注释：滚动窗口会跨月导致对不上账）
      await loadMedia()
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

  // 新媒体块表头：桌面端标题与门店切换左右分列，窄屏换行（触控尺寸由全局 mobile.scss 兜底）
  .media-head {
    flex-wrap: wrap;
    gap: 8px;
  }

  // 分店拆分：表格在窄屏横向滚动而不挤压列（与薪酬概括一致）
  .media-split {
    :deep(.el-table) {
      font-size: 12px;
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
