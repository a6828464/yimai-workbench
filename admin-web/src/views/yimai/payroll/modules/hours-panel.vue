<!--
  老师课时数（对应 `GET /payroll/hours`）

  口径要点（规格 §6.4 / §6.3.2）：
  - 展示的是**课次**（已按 venue+teacher_name+start_at+course_kind 去重），不是预约行数。
    同时把 `bookingRows`（未去重行数）并列显示，让使用者能自己核对倍数是否合理。
  - `assumed60Count > 0` 的人必须**显式**标出「含 N 节私教按 60 分钟估算」，并在页顶汇总。
    估算值会直接改变课时费金额与底薪奖励档位，静默按 60 计算比报错更危险。
  - `TEACHER_UNRESOLVED` 是一等公民：姓名解析依赖 `payroll_profiles.aliases`，
    而该数据由 seeder 从仓库外主档导入，未跑 seeder 时别名会是空的 → 授课老师大面积对不上。
    必须让使用者立刻看到「有 N 位老师没算进来」，而不是看到一个凭空少人的合计。
  - **双源核验**（v3.3.4 新增）：课时费不再只靠预约记录（`ky_bookings`）单侧核实，
    而是与随心瑜「课时记录」（`course/api/getcoursesummaryrecordstat`）**并列展示**。
    实测一个月东部店课时记录 413 节 vs 预约记录 344 课次 —— 只看预约记录会漏 69 节。
    所以「谁多、谁少、差几节」必须一屏可见，**不得静默取其一**。
    计价口径仍是预约记录（已签到课次），理由由后端 `meta.sources.pricingSourceReason` 下发。
-->
<template>
  <div class="payroll-hours">
    <!-- ============ 双源核验：两源并列 + 差额（置顶，一屏可见） ============ -->
    <ElCard v-if="bySource" shadow="never" class="mb-3">
      <template #header>
        <div class="source-head">
          <span class="font-600">课时费双源核验</span>
          <ElTag size="small" effect="plain" :type="sourceDiffType">
            {{ sourceDiffText }}
          </ElTag>
          <span class="text-xs text-gray-400">
            课时记录来自随心瑜「财务报表 → 课时费统计」，与预约记录是两套独立口径
          </span>
        </div>
      </template>

      <div class="source-grid">
        <div class="source-card source-card--pricing">
          <div class="source-card__label">
            {{ bySource.bookingRecord.label }}
            <ElTag size="small" type="success" effect="dark" class="ml-1">计价口径</ElTag>
          </div>
          <div class="source-card__value">{{ bySource.bookingRecord.sessions }}<span>课次</span></div>
          <div class="source-card__foot">
            老师 {{ bySource.bookingRecord.teachers }} 人 · 来源 ky_bookings（已签到去重）
          </div>
        </div>

        <div class="source-card" :class="{ 'source-card--na': !bySource.courseRecord.available }">
          <div class="source-card__label">{{ bySource.courseRecord.label }}</div>
          <div class="source-card__value">
            {{ bySource.courseRecord.available ? bySource.courseRecord.sessions : '—' }}
            <span v-if="bySource.courseRecord.available">节</span>
          </div>
          <div class="source-card__foot">
            <template v-if="bySource.courseRecord.available">
              老师 {{ bySource.courseRecord.teachers }} 人 · 来源 getcoursesummaryrecordstat
            </template>
            <span v-else class="text-danger">不可用：{{ bySource.courseRecord.error || '未知原因' }}</span>
          </div>
        </div>

        <div class="source-card source-card--diff">
          <div class="source-card__label">差额（课时记录 − 预约记录）</div>
          <div class="source-card__value" :class="diffValueClass">
            {{ signed(bySource.diff.sessions) }}
          </div>
          <div class="source-card__foot">
            涉及 {{ bySource.diff.teacherCount }} 位老师 ·
            {{ bySource.diff.sessions === 0 ? '两源一致' : '逐人明细见下表' }}
          </div>
        </div>
      </div>

      <!-- 分店两源对照：谁多、谁少、差几节 -->
      <ElTable v-if="venueDiffRows.length" :data="venueDiffRows" size="small" border class="mt-3">
        <ElTableColumn prop="venue" label="门店" width="92" />
        <ElTableColumn label="课时记录（节）" width="130" align="right">
          <template #default="{ row }">{{ row.courseRecordSessions }}</template>
        </ElTableColumn>
        <ElTableColumn label="预约记录（课次）" width="140" align="right">
          <template #default="{ row }">{{ row.bookingSessions }}</template>
        </ElTableColumn>
        <ElTableColumn label="差额" width="110" align="right">
          <template #default="{ row }">
            <span :class="diffClass(row.diff)">{{ signed(row.diff) }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn label="谁多" width="140">
          <template #default="{ row }">
            <ElTag size="small" effect="plain" :type="leaderTagType(row.leader)">
              {{ leaderLabel(row.leader) }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn label="老师数（课时记录 / 预约）" min-width="180">
          <template #default="{ row }">
            {{ row.courseRecordTeachers }} / {{ row.bookingTeachers }} 人
          </template>
        </ElTableColumn>
      </ElTable>

      <!-- 逐人差额：谁多、谁少、差几节 -->
      <div v-if="bySource.diff.teachers.length" class="mt-3">
        <div class="text-xs text-gray-500 mb-1">
          逐人差额（按差额绝对值排序，前 10 位）—— 差额集中在私教课时，建议优先核对
        </div>
        <ElTable :data="bySource.diff.teachers.slice(0, 10)" size="small" border>
          <ElTableColumn prop="name" label="老师" width="110" />
          <ElTableColumn prop="venue" label="门店" width="92" />
          <ElTableColumn label="课时记录" width="100" align="right">
            <template #default="{ row }">{{ row.courseRecordSessions }} 节</template>
          </ElTableColumn>
          <ElTableColumn label="预约记录" width="100" align="right">
            <template #default="{ row }">{{ row.bookingSessions }} 课次</template>
          </ElTableColumn>
          <ElTableColumn label="差额" width="100" align="right">
            <template #default="{ row }">
              <b :class="diffClass(row.diff)">{{ signed(row.diff) }}</b>
            </template>
          </ElTableColumn>
          <ElTableColumn label="谁多" min-width="130">
            <template #default="{ row }">
              <ElTag size="small" effect="plain" :type="leaderTagType(row.leader)">
                {{ leaderLabel(row.leader) }}
              </ElTag>
            </template>
          </ElTableColumn>
        </ElTable>
      </div>

      <!-- 显式排除：预约 0 行 = 未开课 = 不计课时 -->
      <ElAlert
        v-if="bySource.excluded.notOpenedCount > 0"
        type="warning"
        show-icon
        :closable="false"
        class="mt-3"
      >
        <template #title>
          {{ bySource.excluded.notOpenedCount }} 位老师有课时记录但预约记录 0 行 → 按「未开课」不计课时费
        </template>
        <div class="text-xs mt-1">{{ bySource.excluded.rule }}</div>
        <div v-for="(e, i) in bySource.excluded.notOpened" :key="`${e.name}-${i}`" class="text-xs mt-1">
          {{ e.name }}（{{ e.venue || '未建档' }}）：{{ e.reason }}
        </div>
      </ElAlert>

      <div class="text-xs text-gray-400 mt-2">
        {{ bySource.diff.directionNote }}
        <template v-if="pricingReason"> 计价口径：{{ pricingReason }}</template>
      </div>
    </ElCard>

    <!-- 时长估算汇总：必须置顶，且写明是**私教**课（小班/团课无时长概念，不参与统计） -->
    <ElAlert
      v-for="w in assumedWarnings"
      :key="w.code"
      type="warning"
      show-icon
      :closable="false"
      class="mb-3"
    >
      <template #title>
        <span v-for="(seg, i) in richSegments(w.message)" :key="i">
          <b v-if="seg.bold">{{ seg.text }}</b>
          <template v-else>{{ seg.text }}</template>
        </span>
      </template>
      <div v-if="w.affectedUsers?.length" class="text-xs mt-1">
        涉及：
        <span v-for="(u, i) in w.affectedUsers" :key="`${u.name}-${i}`" class="mr-2">
          {{ u.name }}（{{ u.assumed60Count ?? u.sessions ?? 0 }} 节）
        </span>
      </div>
      <div class="text-xs mt-1">
        请到随心瑜把课程名补成含 45Min / 60Min 的写法（如「VIP定制私教｜45Min」），补完后本提示自动消失。
      </div>
    </ElAlert>

    <!-- 姓名对不上：会导致合计凭空少人，必须显眼 -->
    <ElAlert
      v-for="w in unresolvedWarnings"
      :key="w.code"
      type="error"
      show-icon
      :closable="false"
      class="mb-3"
    >
      <template #title>{{ w.message }}（{{ w.count ?? w.names?.length ?? 0 }} 位）</template>
      <div v-if="w.names?.length" class="text-xs mt-1">未计入合计：{{ w.names.join('、') }}</div>
      <div class="text-xs mt-1">
        姓名解析依赖薪酬档案的别名数据（由人员主档导入）。请先在「课时费与身份标签」里补齐这些老师的别名或建档。
      </div>
    </ElAlert>

    <!-- 口径说明：让使用者能审计「为什么是 66 节而不是 297」 -->
    <ElCard shadow="never" class="mb-3">
      <div class="hours-meta">
        <span>
          <b>{{ meta.classCount }}</b> 个课次（已去重）· 原始预约行 <b>{{ meta.bookingRows }}</b> 行
          <span v-if="dedupeRatio" class="text-gray-400">
            （放大 {{ dedupeRatio }}×，一节课 N 个会员 = N 行预约，直接数行数会虚增课时费）
          </span>
        </span>
        <ElTooltip placement="top" :content="meta.dedupeKeyReason" :show-after="200">
          <span class="hours-meta__key">
            去重键：{{ meta.dedupeKey.join(' + ') }}
            <ArtSvgIcon icon="ri:question-line" />
          </span>
        </ElTooltip>
        <span class="text-gray-400">
          统计口径：仅 {{ meta.statusFilter === 'signed' ? '已签到' : meta.statusFilter }} 且
          {{ meta.trialExcluded ? '排除体验课' : '含体验课' }}
        </span>
      </div>
    </ElCard>

    <!-- 桌面端：完整表格，12 列一个不丢 -->
    <ElTable
      v-if="!isHandheld"
      ref="tableRef"
      v-loading="loading"
      :data="rows"
      border
      stripe
      :max-height="tableMaxHeight"
    >
      <ElTableColumn type="expand">
        <template #default="{ row }">
          <div class="expand-box">
            <div class="expand-box__row">
              <span class="expand-box__label">档案</span>
              <span v-if="row.profileId">
                #{{ row.profileId }} · 展示岗位 {{ row.roleLabel || '—' }} · 所属门店
                {{ row.venue || '—' }}
              </span>
              <span v-else class="text-danger">未匹配到薪酬档案，课时费无法计算</span>
            </div>
            <div class="expand-box__row">
              <span class="expand-box__label">出现过的名字</span>
              <span>{{ row.sourceNames?.join('、') || row.name }}</span>
            </div>
            <div class="expand-box__row">
              <span class="expand-box__label">时长来源分布</span>
              <span v-if="durationEntries(row).length">
                <ElTag
                  v-for="d in durationEntries(row)"
                  :key="d.source"
                  size="small"
                  :type="isInferredDuration(d.source) ? 'warning' : 'info'"
                  effect="plain"
                  class="mr-1"
                >
                  {{ durationSourceLabel(d.source) }} {{ d.count }} 节
                </ElTag>
              </span>
              <span v-else class="text-gray-400">本月无私教课（小班/团课无时长概念）</span>
            </div>
            <div class="expand-box__row">
              <span class="expand-box__label">课程名样本</span>
              <span>{{ row.sampleCourses?.join('、') || '—' }}</span>
            </div>
            <div class="expand-box__row">
              <span class="expand-box__label">课次 / 预约行</span>
              <span>{{ row.classCount }} 个课次 · {{ row.bookingRows }} 行预约</span>
            </div>
            <div class="expand-box__row">
              <span class="expand-box__label">底薪奖励</span>
              <span v-if="row.accumulatedHours === null" class="text-gray-400">
                未匹配档案，无法判断门槛（两店累计）
              </span>
              <span v-else>
                两店累计有效课时 {{ row.accumulatedHours }} 节 → 档位
                {{ row.baseRewardTier || 0 }} 节 → {{ yuan(row.baseReward) }}
              </span>
            </div>
          </div>
        </template>
      </ElTableColumn>
      <ElTableColumn prop="name" label="姓名" width="100" fixed="left" />
      <ElTableColumn label="门店" width="92">
        <template #default="{ row }">{{ row.venue || '—' }}</template>
      </ElTableColumn>
      <ElTableColumn label="身份标签" width="128">
        <template #default="{ row }">
          <ElTag v-if="row.roleLabel" size="small" effect="plain">{{ row.roleLabel }}</ElTag>
          <span v-else class="text-danger">未建档</span>
        </template>
      </ElTableColumn>
      <ElTableColumn prop="private60" label="私教60" width="82" align="right" />
      <ElTableColumn label="私教45" width="82" align="right">
        <template #default="{ row }">
          {{ row.private45 }}
          <ElTag v-if="row.assumed60Count > 0" size="small" type="warning" effect="plain" class="ml-1">
            估{{ row.assumed60Count }}
          </ElTag>
        </template>
      </ElTableColumn>
      <ElTableColumn prop="small" label="小班" width="72" align="right" />
      <ElTableColumn prop="group" label="团课" width="72" align="right" />
      <ElTableColumn label="企业课" width="80" align="right">
        <template #default="{ row }">{{ row.enterprise }}</template>
      </ElTableColumn>
      <ElTableColumn label="合计" width="82" align="right">
        <template #default="{ row }">
          <b>{{ row.totalHours }}</b>
        </template>
      </ElTableColumn>
      <ElTableColumn label="有效课时" width="92" align="right">
        <template #default="{ row }">
          {{ row.validHours }}
          <div class="text-xs text-gray-400">底薪奖励用</div>
        </template>
      </ElTableColumn>
      <ElTableColumn label="两店累计" width="92" align="right">
        <template #default="{ row }">{{ num(row.accumulatedHours) }}</template>
      </ElTableColumn>
      <ElTableColumn label="底薪奖励" width="100" align="right">
        <template #default="{ row }">
          <span :class="row.baseReward > 0 ? 'text-success font-600' : 'text-gray-400'">
            {{ yuan(row.baseReward) }}
          </span>
        </template>
      </ElTableColumn>
      <ElTableColumn label="时长来源" width="132">
        <template #default="{ row }">
          <ElTag
            size="small"
            :type="isInferredDuration(row.durationSource) ? 'warning' : 'info'"
            effect="plain"
          >
            {{ durationSourceLabel(row.durationSource) }}
          </ElTag>
        </template>
      </ElTableColumn>
      <ElTableColumn label="课次 / 预约行" width="128">
        <template #default="{ row }">
          {{ row.classCount }} / {{ row.bookingRows }}
          <div class="text-xs text-gray-400">已去重 / 原始</div>
        </template>
      </ElTableColumn>
      <ElTableColumn label="课时记录 / 差额" width="150">
        <template #default="{ row }">
          <span>{{ row.courseRecordSessions ?? '—' }} 节</span>
          <ElTag
            v-if="row.sourceDiff"
            size="small"
            effect="plain"
            :type="row.sourceDiff > 0 ? 'warning' : 'danger'"
            class="ml-1"
          >
            {{ signed(row.sourceDiff) }}
          </ElTag>
          <div class="text-xs text-gray-400">{{ leaderLabel(row.sourceLeader) }}</div>
        </template>
      </ElTableColumn>
    </ElTable>

    <!-- 手持设备：卡片列表（规格 §8.1 的层级：标题/副标题/标签/4 指标/note/note-danger） -->
    <div v-if="isHandheld" v-loading="loading" class="m-card-list min-h-[120px]">
      <MobileCard
        v-for="row in rows"
        :key="row.profileId ?? row.name"
        :title="row.name"
        :subtitle="cardSubtitle(row)"
        :tags="cardTags(row)"
        :metrics="cardMetrics(row)"
        :note="cardNote(row)"
        note-label="底薪奖励"
        :note-danger="row.assumed60Count > 0 || !row.profileId"
        :actions="[{ text: '查看明细', type: 'primary', onClick: () => openDetail(row) }]"
      />
      <div v-if="!loading && !rows.length" class="m-card-list__empty">本月暂无课时数据</div>
    </div>

    <!-- 手机端明细弹窗：表格在 390px 上放不下，改用 Descriptions 逐项列出 -->
    <ElDialog v-model="detailVisible" :title="detailRow?.name ?? '课时明细'" width="92%" top="6vh">
      <ElDescriptions v-if="detailRow" :column="1" border size="small">
        <ElDescriptionsItem label="门店">{{ detailRow.venue || '—' }}</ElDescriptionsItem>
        <ElDescriptionsItem label="身份标签">{{ detailRow.roleLabel || '未建档' }}</ElDescriptionsItem>
        <ElDescriptionsItem label="出现过的名字">
          {{ detailRow.sourceNames?.join('、') || detailRow.name }}
        </ElDescriptionsItem>
        <ElDescriptionsItem label="私教 60 分钟">{{ detailRow.private60 }} 节</ElDescriptionsItem>
        <ElDescriptionsItem label="私教 45 分钟">
          {{ detailRow.private45 }} 节
          <span v-if="detailRow.assumed60Count > 0" class="text-warning">
            （含 {{ detailRow.assumed60Count }} 节按 60 分钟估算）
          </span>
        </ElDescriptionsItem>
        <ElDescriptionsItem label="小班">{{ detailRow.small }} 节</ElDescriptionsItem>
        <ElDescriptionsItem label="团课">{{ detailRow.group }} 节</ElDescriptionsItem>
        <ElDescriptionsItem label="企业课">{{ detailRow.enterprise }} 节</ElDescriptionsItem>
        <ElDescriptionsItem label="合计">{{ detailRow.totalHours }} 节</ElDescriptionsItem>
        <ElDescriptionsItem label="有效课时">
          {{ detailRow.validHours }} 节（底薪奖励用）
        </ElDescriptionsItem>
        <ElDescriptionsItem label="两店累计">
          {{ num(detailRow.accumulatedHours) }} 节
        </ElDescriptionsItem>
        <ElDescriptionsItem label="底薪奖励">{{ yuan(detailRow.baseReward) }}</ElDescriptionsItem>
        <ElDescriptionsItem label="课次 / 预约行">
          {{ detailRow.classCount }} 个课次 · {{ detailRow.bookingRows }} 行预约
        </ElDescriptionsItem>
        <ElDescriptionsItem label="课时记录（核验源）">
          {{ detailRow.courseRecordSessions ?? '—' }} 节 ·
          差额 {{ signed(detailRow.sourceDiff) }}（{{ leaderLabel(detailRow.sourceLeader) }}）
          <div v-if="courseRecordKindEntries(detailRow).length" class="text-xs text-gray-500">
            课型分布：
            <span v-for="k in courseRecordKindEntries(detailRow)" :key="k.kind" class="mr-2">
              {{ k.label }} {{ k.count }} 节
            </span>
          </div>
        </ElDescriptionsItem>
        <ElDescriptionsItem label="时长来源">
          <template v-if="durationEntries(detailRow).length">
            <div v-for="d in durationEntries(detailRow)" :key="d.source">
              {{ durationSourceLabel(d.source) }}：{{ d.count }} 节
            </div>
          </template>
          <span v-else class="text-gray-400">本月无私教课</span>
        </ElDescriptionsItem>
        <ElDescriptionsItem label="课程名样本">
          {{ detailRow.sampleCourses?.join('、') || '—' }}
        </ElDescriptionsItem>
      </ElDescriptions>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import { computed, ref, watch } from 'vue'
  import {
    fetchPayrollHours,
    type PayrollHoursBySource,
    type PayrollHoursRow,
    type PayrollHoursWarning
  } from '@/api/payroll'
  import { useDevice } from '@/hooks/core/useDevice'
  import { useTableHeight } from '@/hooks/core/useTableHeight'
  import type { MobileCardMetric, MobileCardTag } from '@/components/business/mobile-card/types'
  import { durationSourceLabel, isInferredDuration, num, richSegments, yuan } from './shared'

  defineOptions({ name: 'PayrollHours' })

  const props = defineProps<{ month: string; venue: string | null }>()

  const emit = defineEmits<{
    error: [msg: string]
    /** 双源核验摘要上抛给页面（顶部常驻展示，不必切到本 tab 才能看到差额） */
    source: [
      summary: {
        bookingSessions: number
        courseRecordSessions: number
        diff: number
        available: boolean
        label: string
      } | null
    ]
  }>()

  const { isHandheld } = useDevice()
  const { tableMaxHeight, tableRef } = useTableHeight()

  const loading = ref(false)
  const rows = ref<PayrollHoursRow[]>([])
  const warnings = ref<PayrollHoursWarning[]>([])
  const bySource = ref<PayrollHoursBySource | null>(null)
  const meta = ref({
    dedupeKey: [] as string[],
    dedupeKeyReason: '',
    classCount: 0,
    bookingRows: 0,
    statusFilter: 'signed',
    trialExcluded: true,
    durationPriority: [] as string[],
    sources: undefined as
      | {
          bookingRecord: { label: string; table: string; endpoints: string[]; rule: string }
          courseRecord: {
            label: string
            endpoint: string
            request: Record<string, string | number>
            rule: string
          }
          pricingSource: string
          pricingSourceReason: string
        }
      | undefined
  })

  /** 只统计**私教**的时长估算告警（小班/团课不参与时长逻辑，后端也不会给假警报） */
  const assumedWarnings = computed(() =>
    warnings.value.filter((w) => w.code === 'DURATION_ASSUMED_60')
  )
  const unresolvedWarnings = computed(() =>
    warnings.value.filter((w) => w.code === 'TEACHER_UNRESOLVED')
  )

  /** 去重倍数：让人一眼看出「297 行 → 66 节」是否合理 */
  const dedupeRatio = computed(() => {
    const { classCount, bookingRows } = meta.value
    if (!classCount || !bookingRows) return ''
    return (bookingRows / classCount).toFixed(1)
  })

  // ---------- 双源核验 ----------

  /** 计价口径的理由：由后端下发，前端只渲染（不写第二份口径） */
  const pricingReason = computed(() => meta.value.sources?.pricingSourceReason ?? '')

  /** 分店两源对照的行（门店维度：谁多、谁少、差几节） */
  const venueDiffRows = computed(() => {
    const byVenue = bySource.value?.diff?.byVenue
    if (!byVenue) return []
    return Object.entries(byVenue).map(([venue, v]) => ({ venue, ...v }))
  })

  const sourceDiffType = computed<'success' | 'warning' | 'danger' | 'info'>(() => {
    const c = bySource.value?.courseRecord
    if (!c) return 'info'
    if (!c.available) return 'danger'
    const d = bySource.value?.diff.sessions ?? 0
    return d === 0 ? 'success' : 'warning'
  })

  const sourceDiffText = computed(() => {
    const c = bySource.value?.courseRecord
    if (!c?.available) return '课时记录源不可用（差额无法计算）'
    const d = bySource.value?.diff.sessions ?? 0
    if (d === 0) return '两源一致'
    return bySource.value?.diff.label ?? ''
  })

  const diffValueClass = computed(() => diffClass(bySource.value?.diff.sessions ?? 0))

  /** 带符号的差额文案：0 也显示 `0`（不显示 `+0`） */
  function signed(n: number | null | undefined): string {
    if (n === null || n === undefined || Number.isNaN(n)) return '—'
    return n > 0 ? `+${n}` : String(n)
  }

  function diffClass(n: number | null | undefined): string {
    if (!n) return 'text-gray-400'
    return n > 0 ? 'text-warning' : 'text-danger'
  }

  function leaderLabel(leader: string | undefined): string {
    if (leader === 'course') return '课时记录多'
    if (leader === 'booking') return '预约记录多'
    if (leader === 'equal') return '两源一致'
    return '—'
  }

  function leaderTagType(leader: string | undefined): 'success' | 'warning' | 'danger' | 'info' {
    if (leader === 'equal') return 'success'
    if (leader === 'course') return 'warning'
    if (leader === 'booking') return 'danger'
    return 'info'
  }

  /** 课时记录侧的课型分布（明细弹窗用） */
  function courseRecordKindEntries(row: PayrollHoursRow) {
    const labels: Record<string, string> = { private: '私教', small: '小班', group: '团课' }
    return Object.entries(row.courseRecordKinds ?? {})
      .filter(([, n]) => Number(n) > 0)
      .map(([kind, count]) => ({ kind, label: labels[kind] ?? kind, count: Number(count) }))
      .sort((a, b) => b.count - a.count)
  }

  async function load() {
    loading.value = true
    try {
      const res = await fetchPayrollHours(props.month, props.venue)
      rows.value = res.rows ?? []
      warnings.value = res.warnings ?? []
      bySource.value = res.bySource ?? null
      meta.value = { ...meta.value, ...(res.meta ?? {}) }
      emit('source', bySource.value
        ? {
            bookingSessions: bySource.value.bookingRecord.sessions,
            courseRecordSessions: bySource.value.courseRecord.sessions,
            diff: bySource.value.diff.sessions,
            available: bySource.value.courseRecord.available,
            label: bySource.value.diff.label
          }
        : null)
    } catch (e) {
      rows.value = []
      warnings.value = []
      bySource.value = null
      emit('source', null)
      emit('error', (e as Error)?.message || '课时数据加载失败')
    } finally {
      loading.value = false
    }
  }

  watch(() => [props.month, props.venue], load, { immediate: true })

  function durationEntries(row: PayrollHoursRow) {
    const src = row.durationSources ?? {}
    return Object.entries(src)
      .filter(([, n]) => Number(n) > 0)
      .map(([source, count]) => ({ source, count: Number(count) }))
      .sort((a, b) => b.count - a.count)
  }

  // ---------- 手机端卡片 ----------

  const detailVisible = ref(false)
  const detailRow = ref<PayrollHoursRow | null>(null)

  function openDetail(row: PayrollHoursRow) {
    detailRow.value = row
    detailVisible.value = true
  }

  function cardSubtitle(row: PayrollHoursRow): string {
    const names = (row.sourceNames ?? []).filter((n) => n && n !== row.name)
    const alias = names.length ? `${names.join('、')} · ` : ''
    return `${alias}${row.venue || '未建档'}`
  }

  function cardTags(row: PayrollHoursRow): MobileCardTag[] {
    const tags: MobileCardTag[] = []
    if (row.roleLabel) tags.push({ text: row.roleLabel, effect: 'plain' })
    else tags.push({ text: '未匹配档案', type: 'danger', effect: 'dark' })
    return tags
  }

  /**
   * 卡片指标（规格 §8.1）：最多 4 个。
   * 合计 / 私教 60·45 / 小班·团课 / 底薪奖励（≥80 节标红提示即将达标）。
   */
  function cardMetrics(row: PayrollHoursRow): MobileCardMetric[] {
    const metrics: MobileCardMetric[] = [
      { label: '合计课时', value: row.totalHours, unit: '节' },
      { label: '私教 60/45', value: `${row.private60} / ${row.private45}` },
      { label: '小班/团课', value: `${row.small} / ${row.group}` }
    ]
    // 双源不一致时优先展示差额（这是最需要人核对的数字），否则回退到原有的底薪奖励/累计
    if (row.sourceDiff) {
      metrics.push({
        label: '课时记录差',
        value: signed(row.sourceDiff),
        unit: '节',
        danger: true
      })
    } else if (row.baseReward > 0) {
      metrics.push({ label: '底薪奖励', value: yuan(row.baseReward), danger: true })
    } else if (row.accumulatedHours !== null) {
      metrics.push({ label: '两店累计', value: row.accumulatedHours, unit: '节' })
    }
    return metrics
  }

  function cardNote(row: PayrollHoursRow): string {
    const parts: string[] = []
    if (row.accumulatedHours === null) {
      parts.push('未匹配薪酬档案，无法判断底薪奖励门槛')
    } else {
      const tiers = [80, 100, 110, 120]
      const next = tiers.find((t) => t > row.accumulatedHours!)
      parts.push(
        next
          ? `两店累计 ${row.accumulatedHours} 节（距 ${next} 节还差 ${next - row.accumulatedHours}）`
          : `两店累计 ${row.accumulatedHours} 节（已达最高档）`
      )
    }
    if (row.assumed60Count > 0) {
      parts.push(`${row.assumed60Count} 节私教课时长按 60 分钟估算`)
    }
    parts.push(`课次 ${row.classCount} · 预约行 ${row.bookingRows}`)
    if (row.sourceDiff) {
      parts.push(`课时记录 ${row.courseRecordSessions} 节（${leaderLabel(row.sourceLeader)} ${signed(row.sourceDiff)}）`)
    }
    return parts.join('；')
  }

  defineExpose({ reload: load })
</script>

<style scoped lang="scss">
  .hours-meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 16px;
    font-size: 12px;

    &__key {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      cursor: help;
      color: var(--art-gray-600);
    }
  }

  // 双源核验卡：三个数字块并列，差额居中醒目
  .source-head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
  }

  .source-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
  }

  .source-card {
    padding: 12px;
    border: 1px solid var(--art-border-color);
    border-radius: 6px;

    &--pricing {
      border-color: var(--el-color-success-light-5);
      background: var(--el-color-success-light-9);
    }

    &--diff {
      border-color: var(--el-color-warning-light-5);
      background: var(--el-color-warning-light-9);
    }

    &--na {
      opacity: 0.7;
    }

    &__label {
      display: flex;
      align-items: center;
      font-size: 12px;
      color: var(--art-gray-600);
    }

    &__value {
      margin-top: 4px;
      font-size: 24px;
      font-weight: 600;
      line-height: 1.2;

      span {
        margin-left: 4px;
        font-size: 12px;
        font-weight: 400;
        color: var(--art-gray-500);
      }
    }

    &__foot {
      margin-top: 4px;
      font-size: 12px;
      color: var(--art-gray-500);
    }
  }

  .expand-box {
    padding: 8px 16px;
    font-size: 12px;
    line-height: 2;

    &__row {
      display: flex;
      gap: 8px;
    }

    &__label {
      flex: 0 0 96px;
      color: var(--art-gray-500);
    }
  }

  @media (max-width: 768px) {
    .hours-meta {
      flex-direction: column;
      align-items: flex-start;
      gap: 6px;
    }

    // 手机上三块并排会挤成一条，改为纵向堆叠
    .source-grid {
      grid-template-columns: minmax(0, 1fr);
    }
  }
</style>
