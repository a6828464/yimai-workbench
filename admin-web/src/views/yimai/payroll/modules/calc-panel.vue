<!--
  薪酬计算预览（`GET /payroll/calculate`）

  两条不能妥协的展示要求（任务书第 4 条 + 验收项 9）：

  1. **`unavailable` 必须显式展示**（本次无法计算的项目及原因）。这些是系统内确实
     没有的数据（业绩表未导入、企业课分类缺失、299 活动奖励无公式）。**不得显示为 0
     或省略** —— 显示成 0 会让使用者以为「这项算出来是 0」，从而按错误的工资发放。

  2. **`warnings` 必须显式展示，且要区分「真实输入」与「默认值」**。
     每个人行上按 `inputsReal` 四个维度打标：考勤 / 社保 / 个税 / 业绩。
     `false` 表示该项是默认值而非真实输入（如「未填考勤，按全勤处理」），
     使用者必须能一眼看出哪些数字是「系统兜底」而不是「人填的」。
-->
<template>
  <div class="calc">
    <!-- 阻塞项：非空时该店结果不可用于交付 -->
    <ElAlert
      v-for="b in result?.blocked ?? []"
      :key="b.code"
      type="error"
      show-icon
      :closable="false"
      class="mb-3"
      :title="`结果不可用于交付：${b.message}`"
    >
      <div class="text-xs">请先处理该项后重新计算。</div>
    </ElAlert>

    <!-- 🔴 无法计算的项目：显式列出，不显示为 0 -->
    <ElCard v-if="result?.unavailable?.length" shadow="never" class="mb-3 unavailable-card">
      <template #header>
        <div class="flex-cb">
          <span class="font-500">本次无法计算的项目（{{ result.unavailable.length }} 项）</span>
          <ElTag size="small" type="warning" effect="dark">不是 0，是「算不了」</ElTag>
        </div>
      </template>
      <div class="text-xs text-gray-500 mb-2">
        以下项目<b>在系统里确实没有数据来源</b>，因此既不是 0 也无法估算。它们不计入下方任何金额，
        请人工确认后再发放 —— 若当成 0 发放会少发工资。
      </div>
      <div v-for="(u, i) in result.unavailable" :key="i" class="unavailable-item">
        <div class="unavailable-item__title">
          <ArtSvgIcon icon="ri:error-warning-line" class="text-warning mr-1" />
          {{ u.item }}
        </div>
        <div class="unavailable-item__reason">
          <span v-for="(seg, i) in richSegments(u.reason)" :key="i">
            <b v-if="seg.bold">{{ seg.text }}</b>
            <template v-else>{{ seg.text }}</template>
          </span>
        </div>
      </div>
    </ElCard>

    <!-- 汇总 -->
    <ElCard v-if="result" shadow="never" class="mb-3">
      <template #header>
        <div class="flex-cb">
          <span class="font-500">
            {{ result.venue ?? '两店合并' }} · {{ result.month }} 工资计算
          </span>
          <div class="flex-c gap-2">
            <ElTag size="small" :type="stageTagType(result.stage)" effect="dark">
              {{ stageLabel(result.stage) }}
            </ElTag>
            <ElButton size="small" @click="load">重新计算</ElButton>
          </div>
        </div>
      </template>

      <div class="summary">
        <div class="summary__item">
          <div class="summary__label">人数</div>
          <div class="summary__value">{{ result.storeTotal.headcount }}</div>
        </div>
        <div class="summary__item">
          <div class="summary__label">总课时</div>
          <div class="summary__value">{{ result.storeTotal.hours }}<span>节</span></div>
        </div>
        <div class="summary__item">
          <div class="summary__label">应发合计</div>
          <div class="summary__value">{{ yuan(result.storeTotal.gross) }}</div>
        </div>
        <div class="summary__item">
          <div class="summary__label">社保合计</div>
          <div class="summary__value">{{ yuan(result.storeTotal.socialSecurity) }}</div>
        </div>
        <div class="summary__item">
          <div class="summary__label">个税合计</div>
          <div class="summary__value">{{ yuan(result.storeTotal.tax) }}</div>
        </div>
        <div class="summary__item">
          <div class="summary__label">实发合计</div>
          <div class="summary__value summary__value--strong">{{ yuan(result.storeTotal.net) }}</div>
        </div>
      </div>

      <ElDescriptions :column="isHandheld ? 1 : 3" border size="small" class="mt-3">
        <ElDescriptionsItem label="门店销售额（提成分母）">
          {{ yuan(result.storeSales) }}
          <span class="text-xs text-gray-400">（来源：{{ result.storeSalesSource }}）</span>
        </ElDescriptionsItem>
        <ElDescriptionsItem label="含 299 的原始销售额">
          <span class="text-xs">{{ yuan(result.storeSalesRaw) }}</span>
        </ElDescriptionsItem>
        <ElDescriptionsItem label="活动月规则">
          {{ result.activityType }} × {{ result.activityThresholdMultiplier }}
          <span class="text-xs text-gray-400">（只放大门槛，不放大业绩）</span>
        </ElDescriptionsItem>
      </ElDescriptions>

      <!-- warnings：必须区分「真实输入」与「默认值」 -->
      <div v-if="result.warnings.length" class="mt-3">
        <div class="warn-title">⚠️ 以下数据为<b>默认值</b>而非真实输入，请核对后再发放</div>
        <ElAlert
          v-for="w in result.warnings"
          :key="w.code"
          :type="w.level === 'high' ? 'error' : 'warning'"
          show-icon
          :closable="false"
          class="mb-2"
        >
          <template #title>
            <span v-for="(seg, i) in warnSegments(w)" :key="i">
              <b v-if="seg.bold">{{ seg.text }}</b>
              <template v-else>{{ seg.text }}</template>
            </span>
          </template>
          <div v-if="w.names?.length" class="text-xs">涉及：{{ w.names.join('、') }}</div>
          <div v-if="w.details?.length" class="text-xs">
            涉及：
            <span v-for="(d, i) in w.details" :key="i" class="mr-2">{{ d.name }}（沿用 {{ d.from }}）</span>
          </div>
          <div v-if="w.affectedUsers?.length" class="text-xs">
            涉及：
            <span v-for="(u, i) in w.affectedUsers" :key="i" class="mr-2">
              {{ u.name }}（{{ u.assumed60Count ?? u.sessions ?? 0 }} 节）
            </span>
          </div>
        </ElAlert>
      </div>
    </ElCard>

    <!-- 明细：桌面端表格（列完整） -->
    <ElCard shadow="never">
      <template #header>
        <span class="font-500">逐人明细（{{ result?.rows.length ?? 0 }} 人）</span>
      </template>

      <ElTable
        v-if="!isHandheld"
        ref="tableRef"
        v-loading="loading"
        :data="result?.rows ?? []"
        border
        stripe
        :max-height="tableMaxHeight"
      >
        <ElTableColumn type="expand">
          <template #default="{ row }">
            <div class="expand-box">
              <div class="expand-box__grid">
                <div><span>底薪</span>{{ yuan(row.baseSalary) }}</div>
                <div><span>绩效</span>{{ yuan(row.performance) }}</div>
                <div><span>底薪奖励</span>{{ yuan(row.baseReward) }}（两店累计 {{ row.accumulatedValidHours }} 节）</div>
                <div>
                  <span>基础课时费</span>{{ yuan(row.baseHourlyFee) }}
                  <div class="text-xs text-gray-400">
                    私教60 {{ row.hours.private60 }}×{{ yuan(row.feePrivate60) }} +
                    私教45 {{ row.hours.private45 }}×{{ yuan(row.feePrivate45) }}
                    <span v-if="row.feePrivate45Derived" class="text-warning">
                      （45 分钟单价按 60×0.75 折算）
                    </span>
                    + 小班 {{ row.hours.small }} + 团课 {{ row.hours.group }}
                  </div>
                </div>
                <div>
                  <span>私教激励</span>{{ yuan(row.hourlyIncentive) }}
                  <div class="text-xs text-gray-400">
                    当月加价 {{ yuan(row.hourlyIncentiveAddOn) }}/节 × 课时；
                    60 分钟 {{ yuan(row.hourlyIncentivePrivate60) }} + 45 分钟
                    {{ yuan(row.hourlyIncentivePrivate45) }}（45 档固定 ×0.75，与课时费单价无关）
                  </div>
                </div>
                <div>
                  <span>销售提成</span>{{ yuan(row.commission) }}
                  <div class="text-xs text-gray-400">
                    个人业绩 {{ yuan(row.personalPerformance) }} × {{ percent(row.commissionRate) }}
                  </div>
                </div>
                <div>
                  <span>门店提成</span>{{ yuan(row.storeCommission) }}
                  <div class="text-xs text-gray-400">× {{ percent(row.storeCommissionRate) }}</div>
                </div>
                <div><span>补贴调整</span>{{ yuan(row.subsidy) }}</div>
                <div><span>上月调整</span>{{ yuan(row.previousAdjustment) }}</div>
                <div><span>请假扣款</span>{{ yuan(row.leaveDeduction) }}</div>
                <div><span>其他扣款</span>{{ yuan(row.otherDeduction) }}</div>
                <div>
                  <span>社保</span>{{ yuan(row.socialSecurity) }}
                  <div class="text-xs text-gray-400">
                    {{ socialLabelOf(row) }}
                  </div>
                </div>
                <div><span>个税</span>{{ yuan(row.tax) }}</div>
                <div class="expand-box__total"><span>应发</span>{{ yuan(row.gross) }}</div>
                <div class="expand-box__total"><span>实发</span>{{ yuan(row.net) }}</div>
              </div>
              <div class="expand-box__flags">
                <span>数据来源：</span>
                <ElTag size="small" :type="row.inputsReal.attendance ? 'success' : 'warning'" effect="plain">
                  考勤{{ row.inputsReal.attendance ? '已填' : '默认全勤' }}
                </ElTag>
                <ElTag size="small" :type="row.inputsReal.socialSecurity ? 'success' : 'warning'" effect="plain">
                  社保{{ row.inputsReal.socialSecurity ? '已设' : '未操作' }}
                </ElTag>
                <ElTag size="small" :type="row.inputsReal.tax ? 'success' : 'warning'" effect="plain">
                  个税{{ row.inputsReal.tax ? '已填' : '默认 0' }}
                </ElTag>
                <ElTag size="small" :type="row.inputsReal.performance ? 'success' : 'warning'" effect="plain">
                  业绩{{ row.inputsReal.performance ? '已导入' : '未导入' }}
                </ElTag>
              </div>
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn prop="name" label="姓名" width="100" fixed="left" />
        <ElTableColumn label="展示岗位" width="140">
          <template #default="{ row }">{{ row.displayRole }}</template>
        </ElTableColumn>
        <ElTableColumn label="底薪" width="105" align="right">
          <template #default="{ row }">{{ yuan(row.baseSalary) }}</template>
        </ElTableColumn>
        <ElTableColumn label="底薪奖励" width="105" align="right">
          <template #default="{ row }">{{ yuan(row.baseReward) }}</template>
        </ElTableColumn>
        <ElTableColumn label="课时" width="80" align="right">
          <template #default="{ row }">{{ row.totalHours }}</template>
        </ElTableColumn>
        <ElTableColumn label="课时费" width="115" align="right">
          <template #default="{ row }">{{ yuan(row.hourlyFee) }}</template>
        </ElTableColumn>
        <ElTableColumn label="提成" width="115" align="right">
          <template #default="{ row }">{{ yuan(row.commission) }}</template>
        </ElTableColumn>
        <ElTableColumn label="门店提成" width="110" align="right">
          <template #default="{ row }">{{ yuan(row.storeCommission) }}</template>
        </ElTableColumn>
        <ElTableColumn label="请假扣款" width="105" align="right">
          <template #default="{ row }">
            <span :class="row.leaveDeduction > 0 ? 'text-danger' : ''">
              {{ yuan(row.leaveDeduction) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn label="应发" width="120" align="right">
          <template #default="{ row }"><b>{{ yuan(row.gross) }}</b></template>
        </ElTableColumn>
        <ElTableColumn label="社保" width="115" align="right">
          <template #default="{ row }">
            {{ yuan(row.socialSecurity) }}
            <div class="text-xs" :class="row.socialSecurityMode === 'off' ? 'text-danger' : 'text-gray-400'">
              {{ row.socialSecurityMode === 'off' ? '不缴' : row.socialSecurityInheritedFrom ? `沿用${row.socialSecurityInheritedFrom}` : '' }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn label="个税" width="105" align="right">
          <template #default="{ row }">{{ yuan(row.tax) }}</template>
        </ElTableColumn>
        <ElTableColumn label="实发" width="125" align="right" fixed="right">
          <template #default="{ row }">
            <b class="text-success">{{ yuan(row.net) }}</b>
          </template>
        </ElTableColumn>
        <ElTableColumn label="数据来源" width="130">
          <template #default="{ row }">
            <ElTag
              v-for="f in falseFlags(row)"
              :key="f"
              size="small"
              type="warning"
              effect="plain"
              class="mr-1 mb-1"
            >
              {{ f }}
            </ElTag>
            <span v-if="!falseFlags(row).length" class="text-xs text-success">全部真实输入</span>
          </template>
        </ElTableColumn>
      </ElTable>

      <!-- 手机端卡片（规格 §8.4） -->
      <div v-if="isHandheld" v-loading="loading" class="m-card-list min-h-[120px]">
        <MobileCard
          v-for="row in result?.rows ?? []"
          :key="row.profileId"
          :title="row.name"
          :subtitle="row.displayRole"
          :tags="calcTags(row)"
          :metrics="calcMetrics(row)"
          :note="calcNote(row)"
          :note-danger="falseFlags(row).length > 0"
          :actions="[{ text: '查看明细', type: 'primary', onClick: () => openDetail(row) }]"
        />
        <div v-if="!loading && !result?.rows.length" class="m-card-list__empty">
          没有可计算的人员（本月既无课时、无业绩、无月度输入）
        </div>
      </div>
    </ElCard>

    <!-- 手机端明细弹窗 -->
    <ElDialog
      v-model="detailVisible"
      :title="`${detailRow?.name ?? ''} 工资明细`"
      width="94%"
      top="5vh"
    >
      <ElDescriptions v-if="detailRow" :column="1" border size="small">
        <ElDescriptionsItem label="展示岗位">{{ detailRow.displayRole }}</ElDescriptionsItem>
        <ElDescriptionsItem label="底薪">{{ yuan(detailRow.baseSalary) }}</ElDescriptionsItem>
        <ElDescriptionsItem label="绩效">{{ yuan(detailRow.performance) }}</ElDescriptionsItem>
        <ElDescriptionsItem label="底薪奖励">
          {{ yuan(detailRow.baseReward) }}（两店累计 {{ detailRow.accumulatedValidHours }} 节）
        </ElDescriptionsItem>
        <ElDescriptionsItem label="课时">
          私教60 {{ detailRow.hours.private60 }} / 私教45 {{ detailRow.hours.private45 }} /
          小班 {{ detailRow.hours.small }} / 团课 {{ detailRow.hours.group }}
        </ElDescriptionsItem>
        <ElDescriptionsItem label="基础课时费">
          {{ yuan(detailRow.baseHourlyFee) }}
          <div class="text-xs text-gray-400">
            私教60 {{ yuan(detailRow.baseHourlyPrivate60) }} + 私教45
            {{ yuan(detailRow.baseHourlyPrivate45) }}
            <span v-if="detailRow.feePrivate45Derived" class="text-warning">（45 档按 60×0.75 折算）</span>
          </div>
        </ElDescriptionsItem>
        <ElDescriptionsItem label="私教激励">
          {{ yuan(detailRow.hourlyIncentive) }}
          <div class="text-xs text-gray-400">当月加价 {{ yuan(detailRow.hourlyIncentiveAddOn) }}/节</div>
        </ElDescriptionsItem>
        <ElDescriptionsItem label="销售提成">
          {{ yuan(detailRow.commission) }}
          <div class="text-xs text-gray-400">
            {{ yuan(detailRow.personalPerformance) }} × {{ percent(detailRow.commissionRate) }}
          </div>
        </ElDescriptionsItem>
        <ElDescriptionsItem label="门店提成">
          {{ yuan(detailRow.storeCommission) }}（× {{ percent(detailRow.storeCommissionRate) }}）
        </ElDescriptionsItem>
        <ElDescriptionsItem label="补贴调整">{{ yuan(detailRow.subsidy) }}</ElDescriptionsItem>
        <ElDescriptionsItem label="上月调整">{{ yuan(detailRow.previousAdjustment) }}</ElDescriptionsItem>
        <ElDescriptionsItem label="请假扣款">{{ yuan(detailRow.leaveDeduction) }}</ElDescriptionsItem>
        <ElDescriptionsItem label="其他扣款">{{ yuan(detailRow.otherDeduction) }}</ElDescriptionsItem>
        <ElDescriptionsItem label="社保">
          {{ yuan(detailRow.socialSecurity) }}
          <div class="text-xs text-gray-400">{{ socialLabelOf(detailRow) }}</div>
        </ElDescriptionsItem>
        <ElDescriptionsItem label="个税">{{ yuan(detailRow.tax) }}</ElDescriptionsItem>
        <ElDescriptionsItem label="应发"><b>{{ yuan(detailRow.gross) }}</b></ElDescriptionsItem>
        <ElDescriptionsItem label="实发">
          <b class="text-success">{{ yuan(detailRow.net) }}</b>
        </ElDescriptionsItem>
        <ElDescriptionsItem label="数据来源">
          <ElTag
            v-for="f in falseFlags(detailRow)"
            :key="f"
            size="small"
            type="warning"
            effect="plain"
            class="mr-1"
          >
            {{ f }}
          </ElTag>
          <span v-if="!falseFlags(detailRow).length" class="text-success">全部真实输入</span>
        </ElDescriptionsItem>
      </ElDescriptions>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import { ref, watch } from 'vue'
  import {
    fetchPayrollCalculate,
    payrollErrorMessage,
    type PayrollCalcResult,
    type PayrollCalcRow,
    type PayrollCalcWarning
  } from '@/api/payroll'
  import { useDevice } from '@/hooks/core/useDevice'
  import { useTableHeight } from '@/hooks/core/useTableHeight'
  import type { MobileCardMetric, MobileCardTag } from '@/components/business/mobile-card/types'
  import { percent, richSegments, stageLabel, stageTagType, yuan } from './shared'

  defineOptions({ name: 'PayrollCalculate' })

  const props = defineProps<{ month: string; venue: string | null }>()

  const emit = defineEmits<{ error: [msg: string] }>()

  const { isHandheld } = useDevice()

  // 表格高度自适应。本页的表格嵌在 `.list-page > .el-card__body` 这个内层滚动区里，
  // hook 量到「填满容器」为负数时会退回视口比例，不会给出固定 px。
  const { tableMaxHeight, tableRef } = useTableHeight()

  const loading = ref(false)
  const result = ref<PayrollCalcResult | null>(null)

  watch(() => [props.month, props.venue], load, { immediate: true })

  async function load() {
    loading.value = true
    try {
      result.value = await fetchPayrollCalculate(props.month, props.venue)
    } catch (e) {
      result.value = null
      emit('error', payrollErrorMessage(e, '薪酬计算失败'))
    } finally {
      loading.value = false
    }
  }

  function warnTitle(w: PayrollCalcWarning): string {
    return w.count === undefined ? w.message : `${w.message}（${w.count} 人）`
  }

  /** warnings 的 message 也可能含后端 `**` 强调（如 ACTIVITY_RULE_DEFAULT） */
  function warnSegments(w: PayrollCalcWarning) {
    const title = warnTitle(w)
    return richSegments(title)
  }

  function socialLabelOf(row: PayrollCalcRow): string {
    if (row.socialSecurityMode === 'off') return '本月显式停缴'
    if (row.socialSecurityMode === 'set') return '本月已设'
    return row.socialSecurityInheritedFrom ? `沿用 ${row.socialSecurityInheritedFrom}` : '无历史设置（按 0）'
  }

  /** 哪些维度是默认值（不是真实输入）—— 逐行暴露，避免使用者把兜底值当人工填写 */
  function falseFlags(row: PayrollCalcRow): string[] {
    const out: string[] = []
    if (!row.inputsReal.attendance) out.push('考勤默认全勤')
    if (!row.inputsReal.socialSecurity) out.push('社保未操作')
    if (!row.inputsReal.tax) out.push('个税默认0')
    if (!row.inputsReal.performance) out.push('业绩未导入')
    return out
  }

  // ---------- 手机端 ----------

  const detailVisible = ref(false)
  const detailRow = ref<PayrollCalcRow | null>(null)

  function openDetail(row: PayrollCalcRow) {
    detailRow.value = row
    detailVisible.value = true
  }

  function calcTags(row: PayrollCalcRow): MobileCardTag[] {
    const tags: MobileCardTag[] = [{ text: stageLabel(result.value?.stage ?? ''), type: 'info', effect: 'plain' }]
    if (!row.inputsReal.performance) tags.push({ text: '业绩未导入', type: 'warning', effect: 'dark' })
    return tags
  }

  function calcMetrics(row: PayrollCalcRow): MobileCardMetric[] {
    return [
      { label: '应发工资', value: yuan(row.gross), unit: '元' },
      { label: '实发工资', value: yuan(row.net), unit: '元' },
      { label: '社保+个税', value: yuan(row.socialSecurity + row.tax) }
    ]
  }

  function calcNote(row: PayrollCalcRow): string {
    const parts: string[] = []
    const comps: string[] = []
    if (row.baseSalary > 0) comps.push(`底薪${row.baseSalary}`)
    if (row.baseReward > 0) comps.push(`奖励${row.baseReward}`)
    if (row.hourlyFee > 0) comps.push(`课时费${row.hourlyFee}`)
    if (row.commission > 0) comps.push(`提成${row.commission}`)
    if (row.storeCommission > 0) comps.push(`门店提成${row.storeCommission}`)
    if (comps.length) parts.push(`构成：${comps.join(' + ')}`)
    const flags = falseFlags(row)
    if (flags.length) parts.push(`默认值：${flags.join('、')}`)
    return parts.join('；')
  }

  defineExpose({ reload: load })
</script>

<style scoped lang="scss">
  .unavailable-card {
    border-left: 3px solid var(--el-color-warning);
  }

  .unavailable-item {
    padding: 8px 0;

    & + & {
      border-top: 1px dashed var(--art-border-color);
    }

    &__title {
      display: flex;
      align-items: center;
      font-size: 13px;
      font-weight: 500;
    }

    &__reason {
      margin-top: 4px;
      font-size: 12px;
      line-height: 1.7;
      color: var(--art-gray-500);
    }
  }

  .summary {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 12px;

    &__item {
      text-align: center;
    }

    &__label {
      font-size: 12px;
      color: var(--art-gray-500);
    }

    &__value {
      margin-top: 6px;
      font-size: 18px;
      font-weight: 600;

      span {
        margin-left: 2px;
        font-size: 12px;
        font-weight: 400;
        color: var(--art-gray-500);
      }

      &--strong {
        color: var(--el-color-success);
      }
    }
  }

  .warn-title {
    margin-bottom: 8px;
    font-size: 13px;
    font-weight: 500;
    color: var(--el-color-warning);
  }

  .expand-box {
    padding: 8px 16px;

    &__grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 8px 16px;
      font-size: 12px;

      > div > span:first-child {
        display: inline-block;
        min-width: 72px;
        color: var(--art-gray-500);
      }
    }

    &__total {
      font-weight: 600;
    }

    &__flags {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 6px;
      margin-top: 12px;
      padding-top: 8px;
      font-size: 12px;
      color: var(--art-gray-500);
      border-top: 1px dashed var(--art-border-color);
    }
  }

  @media (max-width: 768px) {
    .summary {
      grid-template-columns: repeat(2, 1fr);
    }

    .expand-box__grid {
      grid-template-columns: 1fr;
    }
  }
</style>
