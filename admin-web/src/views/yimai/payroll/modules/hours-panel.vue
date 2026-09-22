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
-->
<template>
  <div class="payroll-hours">
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
  import { fetchPayrollHours, type PayrollHoursRow, type PayrollHoursWarning } from '@/api/payroll'
  import { useDevice } from '@/hooks/core/useDevice'
  import { useTableHeight } from '@/hooks/core/useTableHeight'
  import type { MobileCardMetric, MobileCardTag } from '@/components/business/mobile-card/types'
  import { durationSourceLabel, isInferredDuration, num, richSegments, yuan } from './shared'

  defineOptions({ name: 'PayrollHours' })

  const props = defineProps<{ month: string; venue: string | null }>()

  const emit = defineEmits<{ error: [msg: string] }>()

  const { isHandheld } = useDevice()
  const { tableMaxHeight, tableRef } = useTableHeight()

  const loading = ref(false)
  const rows = ref<PayrollHoursRow[]>([])
  const warnings = ref<PayrollHoursWarning[]>([])
  const meta = ref({
    dedupeKey: [] as string[],
    dedupeKeyReason: '',
    classCount: 0,
    bookingRows: 0,
    statusFilter: 'signed',
    trialExcluded: true,
    durationPriority: [] as string[]
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

  async function load() {
    loading.value = true
    try {
      const res = await fetchPayrollHours(props.month, props.venue)
      rows.value = res.rows ?? []
      warnings.value = res.warnings ?? []
      meta.value = { ...meta.value, ...(res.meta ?? {}) }
    } catch (e) {
      rows.value = []
      warnings.value = []
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
    if (row.baseReward > 0) {
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
  }
</style>
