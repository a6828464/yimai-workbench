<!--
  考勤 / 社保 / 个税月度输入（`GET|PUT /payroll/monthly-inputs`）

  🔴 本页最重要的一条：**社保三态必须分别渲染**（规格 §4.2）
    - `set`     本月已设 → 显示实际金额（**含显式 0**）
    - `inherit` 本月未操作 → 显示「沿用 2026-07 ¥557.76」
    - `off`     本月不缴 → 显示「本月不缴」
  **禁止**把 `inherit` 渲染成 0。用户原话是「这个月设置了社保扣减，下个月如果没有操作，
  就默认按照这个执行」——「没操作」与「明确设为 0」是两件不同的事：前者会沿用历史金额，
  后者是真的不扣。渲染成 0 会让财务以为社保停了，进而漏缴。

  同理，考勤未填是「按全勤处理（请假扣款 0）」而不是「出勤 0 天」，两者也要分开说。

  ⚠️ 提交时必须显式带上 `socialSecurityMode`：`set` 且金额为 0 时**也要传**
  `socialSecurity: 0`，否则后端无法区分「显式 0」与「未操作」（会回 422）。
-->
<template>
  <div class="inputs">
    <ElCard shadow="never" class="mb-3">
      <template #header>
        <div class="flex-cb">
          <span class="font-500">考勤 / 社保 / 个税（{{ month }} · {{ rows.length }} 人）</span>
          <div class="flex-c gap-2">
            <ElButton size="small" :loading="copying" @click="doCopy">照抄上月</ElButton>
            <ElButton size="small" @click="load">刷新</ElButton>
            <ElButton type="primary" size="small" :loading="saving" @click="saveAll">
              保存全部改动
            </ElButton>
          </div>
        </div>
      </template>

      <ElAlert
        v-if="dirtyCount > 0"
        type="warning"
        show-icon
        :closable="false"
        class="mb-3"
        :title="`有 ${dirtyCount} 人的改动尚未保存`"
      />

      <!-- 桌面端表格 -->
      <ElTable
        v-if="!isHandheld"
        ref="tableRef"
        v-loading="loading"
        :data="rows"
        border
        stripe
        :max-height="tableMaxHeight"
      >
        <ElTableColumn prop="name" label="姓名" width="100" fixed="left" />
        <ElTableColumn label="门店" width="92">
          <template #default="{ row }">
            {{ row.venue }}
            <ElTooltip
              v-if="row.venue !== row.homeVenue"
              content="社保按门店扣：本行是跨店输入，门店与所属门店不同"
              placement="top"
            >
              <ArtSvgIcon icon="ri:question-line" class="text-gray-400 ml-1" />
            </ElTooltip>
          </template>
        </ElTableColumn>
        <ElTableColumn label="应出勤天数" width="128">
          <template #default="{ row }">
            <ElInputNumber
              v-model="row.attendanceDays"
              :min="0"
              :max="31"
              :precision="2"
              size="small"
              controls-position="right"
              style="width: 100%"
              @change="markDirty(row)"
            />
          </template>
        </ElTableColumn>
        <ElTableColumn label="事假小时" width="118">
          <template #default="{ row }">
            <ElInputNumber
              v-model="row.personalLeaveHours"
              :min="0"
              :max="744"
              :precision="2"
              size="small"
              controls-position="right"
              style="width: 100%"
              @change="markDirty(row)"
            />
          </template>
        </ElTableColumn>
        <ElTableColumn label="病假小时" width="118">
          <template #default="{ row }">
            <ElInputNumber
              v-model="row.sickLeaveHours"
              :min="0"
              :max="744"
              :precision="2"
              size="small"
              controls-position="right"
              style="width: 100%"
              @change="markDirty(row)"
            />
          </template>
        </ElTableColumn>
        <ElTableColumn label="社保状态" width="132">
          <template #default="{ row }">
            <ElSelect
              v-model="row.socialSecurityMode"
              size="small"
              style="width: 100%"
              @change="onModeChange(row)"
            >
              <ElOption
                v-for="m in socialModes"
                :key="m.value"
                :label="modeShortLabel(m.value)"
                :value="m.value"
              />
            </ElSelect>
          </template>
        </ElTableColumn>
        <ElTableColumn label="社保金额" width="140">
          <template #default="{ row }">
            <!-- set：可编辑的真实金额（含显式 0） -->
            <ElInputNumber
              v-if="row.socialSecurityMode === 'set'"
              v-model="row.socialSecurity"
              :min="0"
              :max="999999"
              :precision="2"
              size="small"
              controls-position="right"
              style="width: 100%"
              @change="markDirty(row)"
            />
            <!-- off：明确不缴 -->
            <ElTag v-else-if="row.socialSecurityMode === 'off'" size="small" type="danger" effect="dark">
              本月不缴
            </ElTag>
            <!-- inherit：沿用历史金额，**显示金额而不是 0** -->
            <span v-else>
              <span :class="row.socialSecurityInheritedFrom ? '' : 'text-gray-400'">
                {{ yuan(row.socialSecurity) }}
              </span>
              <div v-if="row.socialSecurityInheritedFrom" class="text-xs text-gray-400">
                沿用 {{ row.socialSecurityInheritedFrom }}
              </div>
              <div v-else class="text-xs text-gray-400">无历史设置（按 0）</div>
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn label="个税" width="130">
          <template #default="{ row }">
            <ElInputNumber
              v-model="row.tax"
              :min="0"
              :max="999999"
              :precision="2"
              size="small"
              controls-position="right"
              style="width: 100%"
              @change="markDirty(row)"
            />
            <div v-if="row.taxIsDefault" class="text-xs text-gray-400">未填（按 0，不沿用上月）</div>
          </template>
        </ElTableColumn>
        <ElTableColumn label="补贴调整" width="130">
          <template #default="{ row }">
            <ElInputNumber
              v-model="row.subsidy"
              :min="-999999"
              :max="999999"
              :precision="2"
              size="small"
              controls-position="right"
              style="width: 100%"
              @change="markDirty(row)"
            />
          </template>
        </ElTableColumn>
        <ElTableColumn label="其他扣款" width="130">
          <template #default="{ row }">
            <ElInputNumber
              v-model="row.otherDeduction"
              :min="0"
              :max="999999"
              :precision="2"
              size="small"
              controls-position="right"
              style="width: 100%"
              @change="markDirty(row)"
            />
          </template>
        </ElTableColumn>
        <ElTableColumn label="上月调整" width="130">
          <template #default="{ row }">
            <ElInputNumber
              v-model="row.previousAdjustment"
              :min="-999999"
              :max="999999"
              :precision="2"
              size="small"
              controls-position="right"
              style="width: 100%"
              @change="markDirty(row)"
            />
          </template>
        </ElTableColumn>
        <ElTableColumn label="生效社保" width="130" align="right" fixed="right">
          <template #default="{ row }">
            <b>{{ yuan(row.socialSecurity) }}</b>
            <div class="text-xs" :class="socialTextClass(row)">
              {{ row.socialSecurityLabel }}
            </div>
          </template>
        </ElTableColumn>
      </ElTable>

      <!-- 手持设备卡片（规格 §8.3） -->
      <div v-if="isHandheld" v-loading="loading" class="m-card-list min-h-[120px]">
        <MobileCard
          v-for="row in rows"
          :key="`${row.profileId}-${row.venue}`"
          :title="row.name"
          :subtitle="row.venue"
          :tags="inputTags(row)"
          :metrics="inputMetrics(row)"
          :note="inputNote(row)"
          :note-danger="row.attendanceIsDefault || row.taxIsDefault"
          :actions="[{ text: '录入考勤与社保', type: 'primary', onClick: () => openEdit(row) }]"
        />
        <div v-if="!loading && !rows.length" class="m-card-list__empty">没有需要录入的人员</div>
      </div>
    </ElCard>

    <!-- 手机端录入弹窗 -->
    <ElDialog
      v-model="editVisible"
      :title="`录入：${editing?.name ?? ''}`"
      :width="isHandheld ? '94%' : '560px'"
      top="5vh"
      destroy-on-close
    >
      <ElForm v-if="editing" label-width="110px">
        <ElAlert type="info" show-icon :closable="false" class="mb-3" :title="`门店 ${editing.venue}`">
          <div class="text-xs">社保按门店扣；本行实际生效门店为 {{ editing.venue }}（所属门店 {{ editing.homeVenue }}）</div>
        </ElAlert>

        <div class="form-section">考勤</div>
        <ElFormItem label="应出勤天数">
          <ElInputNumber v-model="editing.attendanceDays" :min="0" :max="31" :precision="2" />
        </ElFormItem>
        <ElFormItem label="事假小时">
          <ElInputNumber v-model="editing.personalLeaveHours" :min="0" :max="744" :precision="2" />
        </ElFormItem>
        <ElFormItem label="病假小时">
          <ElInputNumber v-model="editing.sickLeaveHours" :min="0" :max="744" :precision="2" />
        </ElFormItem>
        <div class="form-hint">未填写考勤 = 全勤 = 请假扣款 0（不是「出勤 0 天」）</div>

        <div class="form-section">社保</div>
        <ElFormItem label="社保状态">
          <ElRadioGroup v-model="editing.socialSecurityMode" @change="onEditModeChange">
            <ElRadioButton v-for="m in socialModes" :key="m.value" :value="m.value">
              {{ modeShortLabel(m.value) }}
            </ElRadioButton>
          </ElRadioGroup>
        </ElFormItem>
        <ElFormItem v-if="editing.socialSecurityMode === 'set'" label="社保金额">
          <ElInputNumber
            v-model="editing.socialSecurity"
            :min="0"
            :max="999999"
            :precision="2"
          />
          <div class="form-hint">填 0 表示<b>本月明确不扣</b>（与「未操作」不同，未操作会沿用上月）</div>
        </ElFormItem>
        <div v-else-if="editing.socialSecurityMode === 'off'" class="form-hint form-hint--warn">
          本月不缴：会<b>打断继承链</b>，不会沿用历史非零值
        </div>
        <div v-else class="form-hint">
          本月未操作：按 (门店, 人) 往更早月份找<b>最近一条</b>显式设置。
          <template v-if="editing.socialSecurityInheritedFrom">
            当前命中 {{ editing.socialSecurityInheritedFrom }} 的 <b>{{ yuan(editing.socialSecurity) }}</b>
          </template>
          <template v-else>当前无历史设置，按 0 处理</template>
        </div>

        <div class="form-section">个税与调整</div>
        <ElFormItem label="个税">
          <ElInputNumber v-model="editing.tax" :min="0" :max="999999" :precision="2" />
          <div class="form-hint">手动输入，<b>不沿用上月</b>（S6:§I）</div>
        </ElFormItem>
        <ElFormItem label="补贴调整">
          <ElInputNumber v-model="editing.subsidy" :min="-999999" :max="999999" :precision="2" />
        </ElFormItem>
        <ElFormItem label="其他扣款">
          <ElInputNumber
            v-model="editing.otherDeduction"
            :min="0"
            :max="999999"
            :precision="2"
          />
        </ElFormItem>
        <ElFormItem label="上月调整">
          <ElInputNumber
            v-model="editing.previousAdjustment"
            :min="-999999"
            :max="999999"
            :precision="2"
          />
        </ElFormItem>
      </ElForm>

      <template #footer>
        <ElButton @click="editVisible = false">取消</ElButton>
        <ElButton type="primary" :loading="saving" @click="saveOne">保存</ElButton>
      </template>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import { computed, ref, watch } from 'vue'
  import {
    copyPayrollMonthlyInputsFromPrevious,
    fetchPayrollMonthlyInputs,
    payrollErrorMessage,
    savePayrollMonthlyInputs,
    type PayrollMonthlyInputRow,
    type PayrollMonthlyInputUpdateRow,
    type PayrollSocialMode,
    type PayrollSocialModeOption
  } from '@/api/payroll'
  import { useDevice } from '@/hooks/core/useDevice'
  import { useTableHeight } from '@/hooks/core/useTableHeight'
  import type { MobileCardMetric, MobileCardTag } from '@/components/business/mobile-card/types'
  import { num, yuan } from './shared'

  defineOptions({ name: 'PayrollMonthlyInputs' })

  const props = defineProps<{
    month: string
    venue: string | null
    socialModes: PayrollSocialModeOption[]
  }>()

  const emit = defineEmits<{ error: [msg: string]; success: [msg: string] }>()

  const { isHandheld } = useDevice()
  const { tableMaxHeight, tableRef } = useTableHeight()

  const loading = ref(false)
  const saving = ref(false)
  const copying = ref(false)
  const rows = ref<PayrollMonthlyInputRow[]>([])
  /** 有未保存改动的人（profileId+venue），用于提示与「保存全部」 */
  const dirty = ref<Set<string>>(new Set())

  const socialModes = computed(() => props.socialModes ?? [])

  const dirtyCount = computed(() => dirty.value.size)

  watch(() => [props.month, props.venue], load, { immediate: true })

  function keyOf(row: PayrollMonthlyInputRow) {
    return `${row.profileId}|${row.venue}`
  }

  function markDirty(row: PayrollMonthlyInputRow) {
    dirty.value.add(keyOf(row))
    dirty.value = new Set(dirty.value)
  }

  /** 切换社保模式：从 inherit 切到 set 时把沿用值带进来，避免用户从 0 开始填 */
  function onModeChange(row: PayrollMonthlyInputRow) {
    if (row.socialSecurityMode === 'set' && row.socialSecurityRaw === null) {
      row.socialSecurity = row.socialSecurity
    }
    markDirty(row)
  }

  async function load() {
    loading.value = true
    try {
      const res = await fetchPayrollMonthlyInputs(props.month, props.venue)
      rows.value = res.rows ?? []
      dirty.value = new Set()
    } catch (e) {
      rows.value = []
      emit('error', payrollErrorMessage(e, '月度输入加载失败'))
    } finally {
      loading.value = false
    }
  }

  /** 组装提交体：`set` 必须显式带金额（含 0），否则后端无法区分「显式 0」与「未操作」 */
  function toUpdateRow(row: PayrollMonthlyInputRow): PayrollMonthlyInputUpdateRow {
    const body: PayrollMonthlyInputUpdateRow = {
      profileId: row.profileId,
      socialSecurityMode: row.socialSecurityMode,
      attendanceDays: row.attendanceDays,
      personalLeaveHours: row.personalLeaveHours,
      sickLeaveHours: row.sickLeaveHours,
      tax: row.tax,
      subsidy: row.subsidy,
      previousAdjustment: row.previousAdjustment,
      otherDeduction: row.otherDeduction,
      note: row.note
    }
    if (row.socialSecurityMode === 'set') {
      body.socialSecurity = row.socialSecurity
    }
    return body
  }

  async function saveAll() {
    if (!rows.value.length) return
    saving.value = true
    try {
      const res = await savePayrollMonthlyInputs(
        props.month,
        props.venue,
        rows.value.map(toUpdateRow)
      )
      // 用后端回显的**最终生效值**覆盖本地（含 inherit 解析结果），不能只信请求体
      rows.value = res.rows ?? []
      dirty.value = new Set()
      emit('success', `已保存 ${res.updated?.length ?? rows.value.length} 人的月度输入`)
    } catch (e) {
      emit('error', payrollErrorMessage(e, '保存失败'))
    } finally {
      saving.value = false
    }
  }

  async function saveOne() {
    const row = editing.value
    if (!row) return
    saving.value = true
    try {
      const res = await savePayrollMonthlyInputs(props.month, props.venue, [toUpdateRow(row)])
      const fresh = (res.rows ?? []).find((r) => keyOf(r) === keyOf(row))
      if (fresh) {
        const idx = rows.value.findIndex((r) => keyOf(r) === keyOf(row))
        if (idx >= 0) rows.value[idx] = fresh
      }
      dirty.value.delete(keyOf(row))
      dirty.value = new Set(dirty.value)
      editVisible.value = false
      emit('success', `已保存「${row.name}」的月度输入`)
    } catch (e) {
      emit('error', payrollErrorMessage(e, '保存失败'))
    } finally {
      saving.value = false
    }
  }

  async function doCopy() {
    copying.value = true
    try {
      const res = await copyPayrollMonthlyInputsFromPrevious(props.month, props.venue, [
        'socialSecurity',
        'attendanceDays'
      ])
      rows.value = res.rows ?? []
      dirty.value = new Set()
      emit(
        'success',
        `已把 ${res.copiedFrom ?? '上月'} 的社保与考勤复制为本月的显式设置（共 ${res.copied ?? 0} 条）`
      )
    } catch (e) {
      emit('error', payrollErrorMessage(e, '复制失败'))
    } finally {
      copying.value = false
    }
  }

  // ---------- 手机端 ----------

  const editVisible = ref(false)
  const editing = ref<PayrollMonthlyInputRow | null>(null)

  function openEdit(row: PayrollMonthlyInputRow) {
    // 深拷贝：取消时不影响列表
    editing.value = { ...row }
    editVisible.value = true
  }

  function onEditModeChange() {
    // 仅作即时反馈，实际校验交给后端（set 必须带金额）
  }

  function modeShortLabel(mode: string): string {
    if (mode === 'set') return '本月已设'
    if (mode === 'off') return '本月不缴'
    return '沿用上月'
  }

  function socialTextClass(row: PayrollMonthlyInputRow) {
    if (row.socialSecurityMode === 'off') return 'text-danger'
    if (row.socialSecurityMode === 'set') return 'text-primary'
    return 'text-gray-400'
  }

  function inputTags(row: PayrollMonthlyInputRow): MobileCardTag[] {
    if (row.socialSecurityMode === 'set') {
      return [{ text: '本月已设', type: 'primary', effect: 'plain' }]
    }
    if (row.socialSecurityMode === 'off') {
      return [{ text: '本月不缴', type: 'danger', effect: 'dark' }]
    }
    return [
      {
        text: row.socialSecurityInheritedFrom ? `沿用 ${row.socialSecurityInheritedFrom}` : '无历史设置',
        type: 'info',
        effect: 'plain'
      }
    ]
  }

  function inputMetrics(row: PayrollMonthlyInputRow): MobileCardMetric[] {
    return [
      { label: '社保', value: yuan(row.socialSecurity) },
      { label: '个税', value: row.taxIsDefault ? '—' : yuan(row.tax) },
      { label: '应出勤', value: row.attendanceDays === null ? '—' : num(row.attendanceDays), unit: '天' }
    ]
  }

  function inputNote(row: PayrollMonthlyInputRow): string {
    const parts: string[] = []
    parts.push(row.socialSecurityLabel)
    if (row.attendanceIsDefault) parts.push('考勤未填，按全勤处理')
    if (row.taxIsDefault) parts.push('个税未填（按 0，不沿用上月）')
    return parts.join('；')
  }

  defineExpose({ reload: load })
</script>

<style scoped lang="scss">
  .form-section {
    margin: 16px 0 12px;
    padding-left: 8px;
    font-size: 13px;
    font-weight: 500;
    color: var(--art-gray-600);
    border-left: 3px solid var(--el-color-primary);
  }

  .form-hint {
    margin-top: 4px;
    font-size: 12px;
    line-height: 1.6;
    color: var(--art-gray-500);

    &--warn {
      color: var(--el-color-warning);
    }
  }
</style>
