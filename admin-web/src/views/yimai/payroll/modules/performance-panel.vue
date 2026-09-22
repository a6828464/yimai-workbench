<!--
  业绩表导入（`POST /payroll/performance/preview` → `/commit`，`GET /payroll/performance`）

  三条硬要求（验收项 4 / 5 / 6）：
  1. 上传 → 预览（逐人归属金额 + 别名解析结果 + 异常行高亮）→ 确认导入；**按门店分别导入**；
     重复导入给出幂等提示。
  2. 预览必须**同时**展示两套金额并标注清楚：① 原始汇总（含 299 活动卡）
     ② 剔除 299 后的提成口径。且要让人看见「299 活动卡 N 行 / ¥X 已从提成中剔除」，
     **不得静默扣除**。
  3. 别名解析结果必须可见：11 个列名里 9 个是别名（苏米→罗柳柳、娟子→徐秀娟…），
     不展示的话使用者无法理解「为什么钱记到了别人名下」。

  ⚠️ commit 要求**重新上传同一份文件**（后端刻意不复用临时文件，避免 TOCTOU），
  并带上 preview 的 `previewSha256`；不一致时后端回 409 PREVIEW_STALE。
  所以这里保留 File 对象本身，确认时再传一次。
-->
<template>
  <div class="perf">
    <!-- 上传区：门店 + 月份必填，一个文件只对应一家店 -->
    <ElCard shadow="never" class="mb-3">
      <template #header>
        <div class="flex-cb">
          <span class="font-500">业绩表导入</span>
          <ElTag size="small" effect="plain">按门店分别导入 · 一个文件只对应一家店</ElTag>
        </div>
      </template>

      <div class="perf-form">
        <div class="perf-form__field">
          <label class="perf-form__label">门店</label>
          <ElSelect v-model="form.venue" placeholder="请选择门店" class="perf-form__ctl">
            <ElOption v-for="v in venues" :key="v" :label="v" :value="v" />
          </ElSelect>
        </div>
        <div class="perf-form__field">
          <label class="perf-form__label">月份</label>
          <ElDatePicker
            v-model="form.month"
            type="month"
            value-format="YYYY-MM"
            placeholder="选择月份"
            class="perf-form__ctl"
          />
        </div>
        <div class="perf-form__field perf-form__field--file">
          <label class="perf-form__label">业绩明细表</label>
          <input
            ref="fileRef"
            type="file"
            accept=".xlsx"
            class="hidden"
            @change="onPickFile"
          />
          <ElButton class="perf-form__ctl" @click="fileRef?.click()">
            {{ pickedFile ? '重新选择文件' : '选择 .xlsx 文件' }}
          </ElButton>
        </div>
      </div>

      <div v-if="pickedFile" class="perf-file">
        <ArtSvgIcon icon="ri:file-excel-2-line" class="perf-file__icon" />
        <span class="perf-file__name">{{ pickedFile.name }}</span>
        <span class="text-gray-400">{{ fileSizeText }}</span>
      </div>

      <div class="perf-actions">
        <ElButton
          type="primary"
          :loading="previewing"
          :disabled="!canPreview"
          @click="doPreview"
        >
          上传并预览（不写入）
        </ElButton>
        <ElButton
          v-if="preview"
          type="success"
          :loading="committing"
          @click="doCommit(false)"
        >
          确认导入
        </ElButton>
        <ElButton
          v-if="preview && preview.exceptions.length"
          type="warning"
          :loading="committing"
          @click="doCommit(true)"
        >
          跳过异常行导入
        </ElButton>
        <span class="text-xs text-gray-400">
          预览<b>不落库</b>；确认导入会重新上传同一份文件并核对指纹，避免「预览看到的」与「写进去的」不是同一份
        </span>
      </div>
    </ElCard>

    <!-- 已导入汇总（选门店+月份后自动查） -->
    <ElCard v-if="summary" shadow="never" class="mb-3">
      <template #header>
        <div class="flex-cb">
          <span class="font-500">{{ summary.venue }} · {{ summary.month }} 已导入业绩</span>
          <ElTag size="small" :type="summary.imported ? 'success' : 'info'">
            {{ summary.imported ? '已导入' : '未导入' }}
          </ElTag>
        </div>
      </template>
      <template v-if="summary.imported">
        <ElDescriptions :column="isHandheld ? 1 : 2" border size="small">
          <ElDescriptionsItem label="源文件">{{ summary.sourceFileName || '—' }}</ElDescriptionsItem>
          <ElDescriptionsItem label="导入时间">{{ summary.importedAt || '—' }}</ElDescriptionsItem>
          <ElDescriptionsItem label="归属条数">{{ summary.allocationCount }}</ElDescriptionsItem>
          <ElDescriptionsItem label="文件指纹">
            <span class="text-xs">{{ (summary.sourceSha256 || '').slice(0, 16) }}…</span>
          </ElDescriptionsItem>
          <ElDescriptionsItem label="个人业绩（提点口径）">
            {{ yuan(summary.totals.forCommission.personal) }}
          </ElDescriptionsItem>
          <ElDescriptionsItem label="门店销售额（提点口径）">
            {{ yuan(summary.totals.forCommission.storeSales) }}
          </ElDescriptionsItem>
          <ElDescriptionsItem label="原始归属（含 299 活动卡）">
            {{ yuan(summary.totals.raw.total) }}
          </ElDescriptionsItem>
          <ElDescriptionsItem label="其中 299 活动卡">
            <span class="text-warning">{{ yuan(summary.totals.raw.activityCardAmount) }} 已剔除</span>
          </ElDescriptionsItem>
        </ElDescriptions>
      </template>
      <div v-else class="text-sm text-gray-400">
        该门店该月还没有业绩数据。未导入时提成<b>不可计算</b>（不是「提成为 0」）。
      </div>
    </ElCard>

    <!-- 预览结果 -->
    <template v-if="preview">
      <!-- 幂等提示：同一份文件重复导入不会重复计钱 -->
      <ElAlert
        v-if="preview.unchanged"
        type="success"
        show-icon
        :closable="false"
        class="mb-3"
        title="与上次导入的文件完全一致（指纹相同），未做任何写入"
      >
        <div class="text-xs">
          同一份表重复导入不会重复计钱 —— 系统按 (门店, 月份) <b>全量替换</b>并核对文件指纹。
        </div>
      </ElAlert>
      <ElAlert
        v-else-if="committed"
        type="success"
        show-icon
        :closable="false"
        class="mb-3"
        :title="`导入成功：写入 ${preview.counts.importedAllocations} 条归属`"
      >
        <div v-if="preview.replaced.rows > 0" class="text-xs">
          已替换该门店该月原有 {{ preview.replaced.rows }} 条归属（按「门店+月份」全量替换，不是追加）。
        </div>
      </ElAlert>

      <!-- 一行都没写进去时，必须说清原因。
           最典型的坑：文件内容属于 8 月，但月份选择器停在 9 月 —— 147 行会因「日期不在目标月份」
           被整体跳过，界面上只剩一片 ¥0.00。不解释的话用户会以为「这个月没有业绩」。 -->
      <ElAlert
        v-if="preview.counts.importedAllocations === 0 && preview.counts.dataRows > 0"
        type="error"
        show-icon
        :closable="false"
        class="mb-3"
        title="本次没有任何归属被导入，请先确认月份是否选对"
      >
        <div class="text-xs">
          文件共解析出 {{ preview.counts.dataRows }} 行数据，但写入 0 条归属。
          <template v-if="preview.counts.skippedOutOfMonthRows > 0">
            其中 <b>{{ preview.counts.skippedOutOfMonthRows }} 行</b>因为「日期不在所选月份
            {{ preview.month }}」被跳过 —— 请把上方「月份」改成文件实际所属的月份
            （{{ preview.sourceFileName }}）后重新预览。
          </template>
          <template v-else>
            另有 {{ preview.counts.skippedZeroAmountRows }} 行金额为 0、{{ preview.counts.exceptions }} 行异常。
          </template>
        </div>
      </ElAlert>

      <!-- 两套金额：必须并列，且 299 剔除要看得见 -->
      <ElCard shadow="never" class="mb-3">
        <template #header>
          <span class="font-500">金额两套口径（{{ preview.venue }} · {{ preview.month }}）</span>
        </template>

        <div class="totals">
          <div class="totals__col totals__col--raw">
            <div class="totals__title">① 原始归属口径<span class="totals__hint">含 299 活动卡</span></div>
            <div class="totals__row">
              <span>个人合计</span><b>{{ yuan(preview.totals.raw.personal) }}</b>
            </div>
            <div class="totals__row">
              <span>会馆合计</span><b>{{ yuan(preview.totals.raw.venue) }}</b>
            </div>
            <div class="totals__row totals__row--strong">
              <span>总计</span><b>{{ yuan(preview.totals.raw.total) }}</b>
            </div>
            <div class="totals__note">用于核对解析是否完整、留档比对</div>
          </div>

          <div class="totals__col totals__col--commission">
            <div class="totals__title">
              ② 提成口径<span class="totals__hint totals__hint--warn">已剔除 299 活动卡</span>
            </div>
            <div class="totals__row">
              <span>个人业绩（计提基数）</span
              ><b>{{ yuan(preview.totals.forCommission.personal) }}</b>
            </div>
            <div class="totals__row">
              <span>门店销售额（分母）</span
              ><b>{{ yuan(preview.totals.forCommission.storeSales) }}</b>
            </div>
            <div
              v-if="preview.totals.forCommission.commissionTotal !== undefined"
              class="totals__row totals__row--strong"
            >
              <span>提成合计</span><b>{{ yuan(preview.totals.forCommission.commissionTotal) }}</b>
            </div>
            <div class="totals__note">提成与门店提成一律走这套（引擎不计 299）</div>
          </div>
        </div>

        <!-- 🔴 299 剔除明细：不得静默扣除 -->
        <ElAlert
          v-if="preview.counts.skippedActivityCardRows > 0"
          type="warning"
          show-icon
          :closable="false"
          class="mt-3"
          :title="`299 活动卡 ${preview.counts.skippedActivityCardRows} 行 / ${yuan(preview.totals.raw.activityCardAmount)} 已从提成中剔除`"
        >
          <div class="text-xs">
            这 {{ preview.counts.skippedActivityCardRows }} 行金额<b>仍计入 ① 原始汇总</b>
            （所以两个口径的差额正是 {{ yuan(preview.totals.raw.activityCardAmount) }}），
            但<b>不计入个人提点与门店提成基数</b> —— 这是生产引擎的既定规则。
          </div>
          <div class="text-xs mt-1">
            差额核对：{{ yuan(preview.totals.raw.personal) }} −
            {{ yuan(preview.totals.forCommission.personal) }} =
            <b>{{ yuan(preview.totals.raw.personal - preview.totals.forCommission.personal) }}</b>
          </div>
        </ElAlert>

        <!-- 计数明细 -->
        <div class="counts mt-3">
          <span v-for="c in countItems" :key="c.label" class="counts__item">
            {{ c.label }} <b>{{ c.value }}</b>
          </span>
        </div>

        <div v-if="preview.notices.length" class="mt-3">
          <ElAlert
            v-for="(n, i) in preview.notices"
            :key="i"
            :type="n.level === 'warn' ? 'warning' : 'info'"
            show-icon
            :closable="false"
            class="mb-2"
          >
            <template #title>
              <span v-for="(seg, i) in richSegments(n.message)" :key="i">
                <b v-if="seg.bold">{{ seg.text }}</b>
                <template v-else>{{ seg.text }}</template>
              </span>
            </template>
          </ElAlert>
        </div>
      </ElCard>

      <!-- 异常行高亮：默认拒绝写入 -->
      <ElCard v-if="preview.exceptions.length" shadow="never" class="mb-3 exceptions-card">
        <template #header>
          <div class="flex-cb">
            <span class="font-500 text-danger">
              异常行 {{ preview.exceptions.length }} 条（默认拒绝写入）
            </span>
            <ElTag size="small" type="danger" effect="dark">需回表修正</ElTag>
          </div>
        </template>
        <div class="text-xs text-gray-500 mb-2">
          异常行<b>不会入库</b>（金额不计入任何口径）。若要强行导入其余正常行，点上方「跳过异常行导入」。
        </div>

        <!-- 手机端：异常也用卡片，表格在 390px 上会横向溢出 -->
        <div v-if="isHandheld" class="m-card-list">
          <MobileCard
            v-for="(e, i) in preview.exceptions"
            :key="`${e.code}-${e.row}-${i}`"
            :title="`第 ${e.row ?? '?'} 行`"
            :subtitle="e.sourceName ? `列名「${e.sourceName}」` : e.memberName || ''"
            :tags="[{ text: exceptionLabel(e.code), type: 'danger', effect: 'dark' }]"
            :metrics="exceptionMetrics(e)"
            :note="e.message"
            note-danger
          />
        </div>

        <ElTable
          v-else
          ref="exceptionTableRef"
          :data="preview.exceptions"
          border
          stripe
          :max-height="exceptionTableMaxHeight"
        >
          <ElTableColumn prop="row" label="行号" width="80" />
          <ElTableColumn label="异常类型" width="140">
            <template #default="{ row }">
              <ElTag size="small" type="danger" effect="dark">{{ exceptionLabel(row.code) }}</ElTag>
            </template>
          </ElTableColumn>
          <ElTableColumn label="列名 / 会员" width="140">
            <template #default="{ row }">{{ row.sourceName || row.memberName || '—' }}</template>
          </ElTableColumn>
          <ElTableColumn prop="message" label="说明" min-width="320" show-overflow-tooltip />
          <ElTableColumn label="金额" width="120" align="right">
            <template #default="{ row }">{{ yuan(row.amount) }}</template>
          </ElTableColumn>
        </ElTable>
      </ElCard>

      <!-- 别名解析结果：表头列名 → 真实姓名 -->
      <ElCard shadow="never" class="mb-3">
        <template #header>
          <div class="flex-cb">
            <span class="font-500">逐人归属与别名解析（{{ preview.byPerson.length }} 人）</span>
            <ElTag size="small" effect="plain">
              {{ aliasCount }} 人的列名是别名，需解析后才归属正确
            </ElTag>
          </div>
        </template>

        <div v-if="isHandheld" class="m-card-list">
          <MobileCard
            v-for="p in preview.byPerson"
            :key="p.profileId"
            :title="p.resolvedName"
            :subtitle="p.sourceName === p.resolvedName ? '表头列名与本名一致' : `表头列名「${p.sourceName}」→ ${p.resolvedName}`"
            :tags="personTags(p)"
            :metrics="personMetrics(p)"
            :note="`原始（含299）${yuan(p.rawAmount)} · 提点基数 ${yuan(p.commissionAmount)}`"
          />
        </div>

        <ElTable
          v-else
          ref="personTableRef"
          :data="preview.byPerson"
          border
          stripe
          :max-height="personTableMaxHeight"
        >
          <ElTableColumn label="表头列名（原样）" width="160">
            <template #default="{ row }">
              <span>{{ row.sourceName }}</span>
              <ElTag
                v-if="row.sourceName !== row.resolvedName"
                size="small"
                type="warning"
                effect="plain"
                class="ml-1"
              >
                别名
              </ElTag>
            </template>
          </ElTableColumn>
          <ElTableColumn label="解析到的真实姓名" width="160">
            <template #default="{ row }">
              <b>{{ row.resolvedName }}</b>
            </template>
          </ElTableColumn>
          <ElTableColumn label="身份标签" width="120">
            <template #default="{ row }">
              <ElTag size="small" effect="plain">{{ row.role || '—' }}</ElTag>
            </template>
          </ElTableColumn>
          <ElTableColumn label="原始金额（含299）" width="160" align="right">
            <template #default="{ row }">{{ yuan(row.rawAmount) }}</template>
          </ElTableColumn>
          <ElTableColumn label="提点基数（剔299）" width="160" align="right">
            <template #default="{ row }">{{ yuan(row.commissionAmount) }}</template>
          </ElTableColumn>
          <ElTableColumn label="提成率" width="100" align="right">
            <template #default="{ row }">{{ percent(row.commissionRate) }}</template>
          </ElTableColumn>
          <ElTableColumn label="提成" width="130" align="right">
            <template #default="{ row }">
              <b :class="row.commission > 0 ? 'text-success' : 'text-gray-400'">
                {{ yuan(row.commission) }}
              </b>
            </template>
          </ElTableColumn>
          <ElTableColumn label="档案" width="90">
            <template #default="{ row }">#{{ row.profileId }}</template>
          </ElTableColumn>
        </ElTable>
      </ElCard>
    </template>
  </div>
</template>

<script setup lang="ts">
  import { computed, ref, watch } from 'vue'
  import {
    commitPayrollPerformance,
    fetchPayrollPerformance,
    payrollErrorMessage,
    previewPayrollPerformance,
    type PayrollPerformanceException,
    type PayrollPerformancePayload,
    type PayrollPerformancePerson,
    type PayrollPerformanceSummary
  } from '@/api/payroll'
  import { useDevice } from '@/hooks/core/useDevice'
  import { useTableHeight } from '@/hooks/core/useTableHeight'
  import type { MobileCardMetric, MobileCardTag } from '@/components/business/mobile-card/types'
  import { percent, richSegments, yuan } from './shared'

  defineOptions({ name: 'PayrollPerformance' })

  const props = defineProps<{
    month: string
    venues: string[]
    initialVenue: string | null
  }>()

  const emit = defineEmits<{ error: [msg: string]; success: [msg: string] }>()

  const { isHandheld } = useDevice()

  // 表格高度自适应：异常行表与逐人归属表各持一个 ref（hook 按 ref 分别测量）
  const { tableMaxHeight: exceptionTableMaxHeight, tableRef: exceptionTableRef } =
    useTableHeight()
  const { tableMaxHeight: personTableMaxHeight, tableRef: personTableRef } = useTableHeight()

  const form = ref({
    venue: props.initialVenue ?? props.venues[0] ?? '',
    month: props.month
  })

  const fileRef = ref<HTMLInputElement>()
  const pickedFile = ref<File | null>(null)
  const previewing = ref(false)
  const committing = ref(false)
  const preview = ref<PayrollPerformancePayload | null>(null)
  const summary = ref<PayrollPerformanceSummary | null>(null)
  const committed = ref(false)

  // 父级切换月份 → 同步到本表单（写 form.month 会触发下面的 watcher，故此处不重复 loadSummary）
  watch(
    () => props.month,
    (m) => {
      if (form.value.month !== m) form.value.month = m
    }
  )

  // 父级切换门店 → 同步初始值
  watch(
    () => props.initialVenue,
    (v) => {
      if (v) form.value.venue = v
    }
  )

  // 门店 / 月份任一变化：作废上一份预览（它属于另一个「门店+月份」，继续提交会写错地方）
  watch(
    () => [form.value.venue, form.value.month],
    () => {
      preview.value = null
      committed.value = false
      void loadSummary()
    },
    { immediate: true }
  )

  const canPreview = computed(() => !!pickedFile.value && !!form.value.venue && !!form.value.month)

  const fileSizeText = computed(() => {
    const f = pickedFile.value
    if (!f) return ''
    const mb = f.size / 1024 / 1024
    return mb >= 1 ? `${mb.toFixed(2)} MB` : `${(f.size / 1024).toFixed(1)} KB`
  })

  /** 有几个人的列名与本名不同（即靠别名解析出来的） */
  const aliasCount = computed(
    () => preview.value?.byPerson.filter((p) => p.sourceName !== p.resolvedName).length ?? 0
  )

  const countItems = computed(() => {
    const c = preview.value?.counts
    if (!c) return []
    return [
      { label: '数据行', value: c.dataRows },
      { label: '写入归属', value: c.importedAllocations },
      { label: '个人归属行', value: c.personalRows },
      { label: '会馆归属行', value: c.venueRows },
      { label: '一行多归属', value: c.multiOwnerRows },
      { label: '解析到的人数', value: c.distinctPeople },
      { label: '剔除 299 行数', value: c.skippedActivityCardRows },
      { label: '非本月跳过', value: c.skippedOutOfMonthRows },
      { label: '金额为 0 跳过', value: c.skippedZeroAmountRows },
      { label: '异常行', value: c.exceptions }
    ]
  })

  function onPickFile(e: Event) {
    const input = e.target as HTMLInputElement
    const f = input.files?.[0] ?? null
    pickedFile.value = f
    preview.value = null
    committed.value = false
  }

  async function loadSummary() {
    if (!form.value.month) return
    try {
      summary.value = await fetchPayrollPerformance(form.value.month, form.value.venue || null)
    } catch {
      // 汇总查不到不算致命：不影响导入本身，静默保持上一次结果
      summary.value = null
    }
  }

  async function doPreview() {
    const file = pickedFile.value
    if (!file) return
    previewing.value = true
    committed.value = false
    try {
      preview.value = await previewPayrollPerformance(file, form.value.venue, form.value.month)
      if (preview.value.exceptions.length) {
        emit(
          'error',
          `预览发现 ${preview.value.exceptions.length} 条异常行，默认拒绝写入，请先核对`
        )
      }
    } catch (e) {
      preview.value = null
      emit('error', payrollErrorMessage(e, '预览失败'))
    } finally {
      previewing.value = false
    }
  }

  async function doCommit(allowExceptions: boolean) {
    const file = pickedFile.value
    const p = preview.value
    if (!file || !p) return
    committing.value = true
    try {
      const res = await commitPayrollPerformance(
        file,
        form.value.venue,
        form.value.month,
        p.sourceSha256,
        allowExceptions
      )
      preview.value = res
      committed.value = true
      emit(
        'success',
        res.unchanged
          ? '与上次导入的文件完全一致，未做任何写入（幂等）'
          : `导入成功：写入 ${res.counts.importedAllocations} 条归属`
      )
      await loadSummary()
    } catch (e) {
      emit('error', payrollErrorMessage(e, '导入失败'))
    } finally {
      committing.value = false
    }
  }

  function exceptionLabel(code: string) {
    switch (code) {
      case 'UNMATCHED_NAME':
        return '姓名对不上'
      case 'AMBIGUOUS_NAME':
        return '姓名歧义'
      case 'ALLOCATION_MISMATCH':
        return '分配不平'
      case 'UNALLOCATED':
        return '无归属'
      case 'INVALID_DATE':
        return '日期无法识别'
      case 'INVALID_FILE':
        return '文件不可解析'
      default:
        return code
    }
  }

  function exceptionMetrics(e: PayrollPerformanceException): MobileCardMetric[] {
    const m: MobileCardMetric[] = []
    if (e.amount !== undefined) m.push({ label: '金额', value: yuan(e.amount), danger: true })
    if (e.allocated !== undefined) m.push({ label: '已分配', value: yuan(e.allocated) })
    return m
  }

  function personTags(p: PayrollPerformancePerson): MobileCardTag[] {
    const tags: MobileCardTag[] = []
    if (p.sourceName !== p.resolvedName) tags.push({ text: '别名解析', type: 'warning', effect: 'plain' })
    if (p.role) tags.push({ text: p.role, effect: 'plain' })
    return tags
  }

  function personMetrics(p: PayrollPerformancePerson): MobileCardMetric[] {
    return [
      { label: '提点基数', value: yuan(p.commissionAmount) },
      { label: '提成率', value: percent(p.commissionRate) },
      { label: '提成', value: yuan(p.commission), danger: p.commission > 0 }
    ]
  }

  /** 供父组件在切 tab 回来时刷新汇总 */
  defineExpose({ refresh: loadSummary })
</script>

<style scoped lang="scss">
  .perf-form {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;

    &__field {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }

    &__label {
      font-size: 12px;
      color: var(--art-gray-500);
    }

    &__ctl {
      width: 200px;
    }

    &__field--file .perf-form__ctl {
      width: auto;
    }
  }

  .perf-file {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 12px;
    padding: 8px 12px;
    font-size: 13px;
    background: var(--art-gray-100);
    border-radius: 6px;

    &__icon {
      color: var(--el-color-success);
    }

    &__name {
      font-weight: 500;
      word-break: break-all;
    }
  }

  .perf-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
    margin-top: 16px;
  }

  .totals {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;

    &__col {
      padding: 12px 16px;
      border: 1px solid var(--art-border-color);
      border-radius: 8px;

      &--raw {
        border-left: 3px solid var(--el-color-info);
      }

      &--commission {
        border-left: 3px solid var(--el-color-success);
      }
    }

    &__title {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 8px;
      font-weight: 500;
    }

    &__hint {
      font-size: 11px;
      font-weight: 400;
      color: var(--art-gray-500);

      &--warn {
        color: var(--el-color-warning);
      }
    }

    &__row {
      display: flex;
      justify-content: space-between;
      padding: 4px 0;
      font-size: 13px;

      &--strong {
        margin-top: 4px;
        padding-top: 8px;
        font-size: 14px;
        border-top: 1px dashed var(--art-border-color);
      }
    }

    &__note {
      margin-top: 8px;
      font-size: 11px;
      color: var(--art-gray-400);
    }
  }

  .counts {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 20px;
    font-size: 12px;
    color: var(--art-gray-600);

    &__item b {
      color: var(--art-gray-900);
    }
  }

  .exceptions-card {
    border-left: 3px solid var(--el-color-danger);
  }

  @media (max-width: 768px) {
    .totals {
      grid-template-columns: 1fr;
    }

    .perf-form {
      flex-direction: column;
      gap: 12px;

      &__ctl {
        width: 100%;
      }
    }

    .perf-actions {
      flex-direction: column;
      align-items: stretch;

      .el-button {
        width: 100%;
        margin-left: 0;
      }
    }
  }
</style>
