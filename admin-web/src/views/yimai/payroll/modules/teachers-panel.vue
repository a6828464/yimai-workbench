<!--
  人员档案（`GET /payroll/profiles`）

  用户原话（v3.3.6）：「门店老师一览，标签改成『人员档案』。仅用来显示设置各门店
  老师的基本信息，比如：身份信息、银行卡信息、归属门店、课时费。」
  —— 课时节数不再在此展示（与「老师课时数」「课时与业绩」重复）。

  ## 展示内容

  - **身份信息**：姓名、身份标签、人员状态、手机号、身份证号、企业微信
  - **银行卡信息**：收款户名、卡号（默认掩码，点「显示」露出）、开户行、联行号、转账类型
  - **归属门店**：绿地店 / 东部店 分组
  - **课时费**：五种课型单价（45 分钟显示生效值，折算保留标注）

  ## 银行卡掩码（防肩窥/截屏误发）

  卡号与身份证号默认只显示后 4 位，点「显示」才露出完整值，再点「隐藏」恢复。
  掩码只是**展示策略**，数据层仍下发完整值（编辑弹窗需要可提交的原文）。

  ⚠️ 枚举一律来自 `catalog.roles`（props 传入），本文件不写死任何一份。
-->
<template>
  <div class="teachers">
    <!-- 按门店分组：每店一节 -->
    <ElCard v-for="group in venueGroups" :key="group.venue" shadow="never" class="mb-3">
      <template #header>
        <div class="flex-cb">
          <span class="font-500">
            {{ group.venue }}
            <span class="text-xs text-gray-400">（{{ group.rows.length }} 人）</span>
          </span>
          <span class="text-xs text-gray-400">
            在职 {{ group.activeCount }} 人<template v-if="group.pendingCount"> · 待完善 {{ group.pendingCount }} 人</template>
          </span>
        </div>
      </template>
      <div class="text-xs text-gray-500 mb-2">
        上传「薪酬人员主档」xlsx（sheet「薪酬人员主档」），把身份标签、底薪、课时费、
        银行卡信息一次性灌入档案。留空金额不覆盖已补录值；重复导入逐字相同。
        主档更新后重新上传即可，不需要逐人手填。
      </div>
      <div class="flex-c gap-2 flex-wrap">
        <ElUpload
          :auto-upload="false"
          :show-file-list="false"
          accept=".xlsx"
          :on-change="(f: any) => onMasterFile(f)"
        >
          <ElButton size="small" :loading="importing">
            <i class="ri-upload-2-line mr-1" />{{ importing ? '导入中…' : '选择主档 xlsx 并预览' }}
          </ElButton>
        </ElUpload>
        <ElButton
          v-if="importPreview"
          size="small"
          type="primary"
          :loading="importing"
          @click="commitMaster"
        >
          确认导入 {{ importPreview.total }} 人
        </ElButton>
        <ElButton v-if="importPreview" size="small" @click="importPreview = null">取消</ElButton>
      </div>
      <div v-if="importPreview" class="mt-3">
        <ElDescriptions :column="isHandheld ? 1 : 3" border size="small">
          <ElDescriptionsItem label="主档行数">{{ importPreview.total }}</ElDescriptionsItem>
          <ElDescriptionsItem label="有效人数">{{ importPreview.valid }}</ElDescriptionsItem>
          <ElDescriptionsItem label="有别名">{{ importPreview.aliasRows }} 人</ElDescriptionsItem>
          <ElDescriptionsItem label="门店分布">
            {{ Object.entries(importPreview.byVenue).map(([v, n]) => `${v} ${n}`).join('、') }}
          </ElDescriptionsItem>
          <ElDescriptionsItem label="双底薪例外">{{ importPreview.dualBase.join('、') || '—' }}</ElDescriptionsItem>
          <ElDescriptionsItem label="资料缺口">
            缺手机 {{ importPreview.blanks['手机'] ?? 0 }} · 身份证
            {{ importPreview.blanks['身份证号'] ?? 0 }} · 银行卡
            {{ importPreview.blanks['银行卡号'] ?? 0 }}
          </ElDescriptionsItem>
        </ElDescriptions>
        <div v-if="importProblems.length" class="mt-2 text-xs text-danger">
          <div v-for="(p, i) in importProblems" :key="i">⚠ {{ p }}</div>
        </div>
      </div>
      <ElAlert
        v-if="importMessage"
        :type="importOk ? 'success' : 'error'"
        :closable="true"
        show-icon
        class="mt-2"
        :title="importMessage"
        @close="importMessage = ''"
      />

      <!-- 桌面端：表格 -->
      <ElTable v-if="!isHandheld" v-loading="loading" :data="group.rows" border stripe size="small">
        <ElTableColumn prop="name" label="姓名" width="100" fixed="left">
          <template #default="{ row }">
            {{ row.name }}
            <ElTag v-if="row.pendingReview" size="small" type="danger" effect="plain" class="ml-1">
              待完善
            </ElTag>
            <ElTag v-if="row.status !== '有效'" size="small" type="info" effect="plain" class="ml-1">
              {{ row.status }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn label="身份标签" width="120">
          <template #default="{ row }">
            <ElTag v-if="row.roleLabel" size="small" effect="plain">{{ row.roleLabel }}</ElTag>
            <ElTag v-else size="small" type="danger" effect="plain">未设置</ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn label="课时费（私教60/45·小班·团课·企业）" min-width="230">
          <template #default="{ row }">
            <div class="fee-cell">
              <span class="fee-cell__item">
                {{ money(row.feePrivate60) }} /
                <span v-if="row.feePrivate45Derived" class="text-warning">
                  {{ money(row.feePrivate45Effective) }}<i class="fee-cell__derived">折算</i>
                </span>
                <template v-else>{{ money(row.feePrivate45) }}</template>
              </span>
              <span class="fee-cell__item">小班 {{ money(row.feeSmall) }}</span>
              <span class="fee-cell__item">团课 {{ money(row.feeGroup) }}</span>
              <span class="fee-cell__item">企业 {{ money(row.feeEnterprise) }}</span>
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn label="手机" width="120">
          <template #default="{ row }">{{ row.phone || '—' }}</template>
        </ElTableColumn>
        <ElTableColumn label="身份证号" width="170">
          <template #default="{ row }">
            <span v-if="row.idCardNo">{{ maskedId(row) }}</span>
            <span v-else class="text-gray-400">—</span>
          </template>
        </ElTableColumn>
        <ElTableColumn label="银行卡" min-width="200">
          <template #default="{ row }">
            <template v-if="row.bankCardNo">
              <div class="bank-cell">
                <span class="bank-cell__no">{{ maskedCard(row) }}</span>
                <ElButton link size="small" @click="toggleReveal(row)">
                  {{ revealed.has(row.id) ? '隐藏' : '显示' }}
                </ElButton>
              </div>
              <div class="text-xs text-gray-400">
                {{ row.bankAccountName }}
                <template v-if="row.bankName"> · {{ row.bankName }}</template>
                <template v-if="row.transferType"> · {{ row.transferType }}</template>
              </div>
            </template>
            <ElTag v-else size="small" type="warning" effect="plain">缺卡号</ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn label="企微" width="110" show-overflow-tooltip>
          <template #default="{ row }">{{ row.wechatWork || '—' }}</template>
        </ElTableColumn>
      </ElTable>

      <!-- 手持设备：卡片列表（身份/银行/门店/课时费全部在卡上） -->
      <div v-if="isHandheld" v-loading="loading" class="m-card-list min-h-[120px]">
        <MobileCard
          v-for="row in group.rows"
          :key="row.id"
          :title="row.name"
          :subtitle="`${row.venue} · ${row.roleLabel || '身份未设置'}`"
          :tags="cardTags(row)"
          :metrics="cardMetrics(row)"
          :note="cardNote(row)"
          :actions="[
            row.bankCardNo
              ? { text: revealed.has(row.id) ? '隐藏卡号' : '显示完整卡号', onClick: () => toggleReveal(row) }
              : { text: '到「课时与业绩」补卡号', onClick: () => emit('go-profiles') }
          ]"
        />
        <div v-if="!loading && !group.rows.length" class="m-card-list__empty">
          该门店暂无老师档案
        </div>
      </div>
    </ElCard>

    <!-- 导入人员主档：界面直接上传 xlsx（与业绩表导入同一模式：预览 → 提交） -->
    <ElCard shadow="never" class="mb-3 import-card">
      <template #header>
        <div class="flex-cb">
          <span class="font-500">导入人员主档</span>
          <ElTag size="small" effect="plain" type="info">幂等 · 按人员编号定位</ElTag>
        </div>
      </template>
      <div class="text-xs text-gray-500 mb-2">
        上传「薪酬人员主档」xlsx（sheet「薪酬人员主档」），把身份标签、底薪、课时费、
        银行卡信息一次性灌入档案。留空金额不覆盖已补录值；重复导入逐字相同。
        主档更新后重新上传即可，不需要逐人手填。
      </div>
      <div class="flex-c gap-2 flex-wrap">
        <ElUpload
          :auto-upload="false"
          :show-file-list="false"
          accept=".xlsx"
          :on-change="(f: any) => onMasterFile(f)"
        >
          <ElButton size="small" :loading="importing">
            <i class="ri-upload-2-line mr-1" />{{ importing ? '导入中…' : '选择主档 xlsx 并预览' }}
          </ElButton>
        </ElUpload>
        <ElButton
          v-if="importPreview"
          size="small"
          type="primary"
          :loading="importing"
          @click="commitMaster"
        >
          确认导入 {{ importPreview.total }} 人
        </ElButton>
        <ElButton v-if="importPreview" size="small" @click="importPreview = null">取消</ElButton>
      </div>
      <div v-if="importPreview" class="mt-3">
        <ElDescriptions :column="isHandheld ? 1 : 3" border size="small">
          <ElDescriptionsItem label="主档行数">{{ importPreview.total }}</ElDescriptionsItem>
          <ElDescriptionsItem label="有效人数">{{ importPreview.valid }}</ElDescriptionsItem>
          <ElDescriptionsItem label="有别名">{{ importPreview.aliasRows }} 人</ElDescriptionsItem>
          <ElDescriptionsItem label="门店分布">
            {{ Object.entries(importPreview.byVenue).map(([v, n]) => `${v} ${n}`).join('、') }}
          </ElDescriptionsItem>
          <ElDescriptionsItem label="双底薪例外">{{ importPreview.dualBase.join('、') || '—' }}</ElDescriptionsItem>
          <ElDescriptionsItem label="资料缺口">
            缺手机 {{ importPreview.blanks['手机'] ?? 0 }} · 身份证
            {{ importPreview.blanks['身份证号'] ?? 0 }} · 银行卡
            {{ importPreview.blanks['银行卡号'] ?? 0 }}
          </ElDescriptionsItem>
        </ElDescriptions>
        <div v-if="importProblems.length" class="mt-2 text-xs text-danger">
          <div v-for="(p, i) in importProblems" :key="i">⚠ {{ p }}</div>
        </div>
      </div>
      <ElAlert
        v-if="importMessage"
        :type="importOk ? 'success' : 'error'"
        :closable="true"
        show-icon
        class="mt-2"
        :title="importMessage"
        @close="importMessage = ''"
      />
    </ElCard>

    <!-- 空态 -->
    <ElCard v-if="!venueGroups.length" shadow="never">
      <ElEmpty description="没有符合条件的老师档案" :image-size="80" />
    </ElCard>

    <!-- 口径说明 -->
    <div class="text-xs text-gray-400 foot-note">
      身份与银行卡信息来自人员主档导入（主档缺手机 41 人 / 身份证 40 人 / 银行卡 5 人）；
      卡号默认掩码显示，点「显示」露出完整卡号；课时费列 45 分钟为生效值（未单独设置时按
      60×{{ fee45Factor }} 折算）。发薪付款前仍需复核账户信息。
    </div>
  </div>
</template>

<script setup lang="ts">
  import { computed, ref, watch } from 'vue'
  import {
    fetchPayrollProfiles,
    importPayrollMaster,
    payrollErrorMessage,
    type PayrollMasterImportStats,
    type PayrollProfileRow,
    type PayrollRolesCatalog
  } from '@/api/payroll'
  import { useDevice } from '@/hooks/core/useDevice'
  import type { MobileCardMetric, MobileCardTag } from '@/components/business/mobile-card/types'
  import { money } from './shared'

  defineOptions({ name: 'PayrollTeachers' })

  const props = defineProps<{
    /** 门店筛选（null = 两店全部显示）。月份不参与本页（无课时数据），保留 prop 兼容父组件传参 */
    month?: string
    venue: string | null
    /** 规则目录（roles 用于身份标签显示、venues 用于分组顺序） */
    catalog: PayrollRolesCatalog | null
  }>()

  const emit = defineEmits<{ error: [msg: string]; 'go-profiles': [] }>()

  const { isHandheld } = useDevice()

  const loading = ref(false)
  const rows = ref<PayrollProfileRow[]>([])

  const roles = computed(() => props.catalog?.roles ?? [])
  const fee45Factor = computed(() => props.catalog?.fee45FallbackFactor ?? 0.75)

  /** 已点「显示」露出完整卡号的档案 id 集合（默认全部掩码） */
  const revealed = ref<Set<number>>(new Set())

  function toggleReveal(row: PayrollProfileRow) {
    const next = new Set(revealed.value)
    if (next.has(row.id)) {
      next.delete(row.id)
    } else {
      next.add(row.id)
    }
    revealed.value = next
  }

  function maskedCard(row: PayrollProfileRow): string {
    if (revealed.value.has(row.id)) return row.bankCardNo
    return `**** **** **** ${row.bankCardNo.slice(-4)}`
  }

  function maskedId(row: PayrollProfileRow): string {
    if (revealed.value.has(row.id)) return row.idCardNo
    return `******************${row.idCardNo.slice(-4)}`
  }

  const venueGroups = computed<
    { venue: string; rows: PayrollProfileRow[]; activeCount: number; pendingCount: number }[]
  >(() => {
    const venues = props.venue ? [props.venue] : (props.catalog?.venues ?? [])
    return venues.map((venue) => {
      const list = rows.value.filter((r) => r.venue === venue)
      return {
        venue,
        rows: list,
        activeCount: list.filter((r) => r.status === '有效').length,
        pendingCount: list.filter((r) => r.pendingReview).length
      }
    })
  })

  function roleLabelOf(value: string): string {
    return roles.value.find((r) => r.value === value)?.label ?? value
  }

  async function load() {
    loading.value = true
    try {
      const res = await fetchPayrollProfiles()
      rows.value = (res.rows ?? []).map((r) => ({ ...r, roleLabel: roleLabelOf(r.role) }))
    } catch (e) {
      rows.value = []
      emit('error', payrollErrorMessage(e, '人员档案加载失败'))
    } finally {
      loading.value = false
    }
  }

  watch(() => [props.venue, props.catalog], load, { immediate: true })

  // ---------- 人员主档导入（预览 → 提交，与业绩表导入同一模式） ----------

  const importing = ref(false)
  const importPreview = ref<PayrollMasterImportStats | null>(null)
  const importProblems = ref<string[]>([])
  const importMessage = ref('')
  const importOk = ref(false)
  /** 预览用的文件对象（commit 时重新上传，不复用临时文件，避免 TOCTOU） */
  let masterFile: File | null = null

  async function onMasterFile(file: { raw?: File }): Promise<void> {
    const raw = file.raw
    if (!raw) return
    masterFile = raw
    importing.value = true
    importMessage.value = ''
    try {
      const res = await importPayrollMaster(raw, true)
      importPreview.value = res.stats
      importProblems.value = []
    } catch (e) {
      importPreview.value = null
      const anyE = e as { response?: { data?: { message?: string } }; message?: string }
      importMessage.value =
        anyE.response?.data?.message || anyE.message || '主档预览失败（检查文件是否为薪酬人员主档 xlsx）'
      importOk.value = false
    } finally {
      importing.value = false
    }
  }

  async function commitMaster(): Promise<void> {
    if (!masterFile) return
    importing.value = true
    importMessage.value = ''
    try {
      const res = await importPayrollMaster(masterFile, false)
      importMessage.value =
        `导入完成：新建 ${res.created}、更新 ${res.updated}，档案现有 ${res.total} 人` +
        (res.problems?.length ? `；${res.problems.length} 条异常行已跳过（见列表）` : '')
      importOk.value = true
      importProblems.value = res.problems ?? []
      importPreview.value = null
      masterFile = null
      await load() // 重新拉取档案列表
    } catch (e) {
      const anyE = e as { response?: { data?: { message?: string } }; message?: string }
      importMessage.value = anyE.response?.data?.message || anyE.message || '导入失败'
      importOk.value = false
    } finally {
      importing.value = false
    }
  }

  // ---------- 手机端卡片 ----------

  function cardTags(row: PayrollProfileRow): MobileCardTag[] {
    const tags: MobileCardTag[] = [
      row.role
        ? { text: row.roleLabel || row.role, effect: 'plain' }
        : { text: '身份未设置', type: 'danger', effect: 'plain' }
    ]
    if (row.pendingReview) tags.push({ text: '待完善', type: 'danger', effect: 'dark' })
    if (row.status !== '有效') tags.push({ text: row.status, type: 'info', effect: 'plain' })
    if (!row.bankCardNo) tags.push({ text: '缺卡号', type: 'warning', effect: 'plain' })
    return tags
  }

  function cardMetrics(row: PayrollProfileRow): MobileCardMetric[] {
    return [
      { label: '私教 60/45', value: `${money(row.feePrivate60)} / ${money(row.feePrivate45Effective)}` },
      { label: '小班/团课', value: `${money(row.feeSmall)} / ${money(row.feeGroup)}` },
      { label: '底薪', value: money(row.baseSalary) }
    ]
  }

  function cardNote(row: PayrollProfileRow): string {
    const parts: string[] = []
    parts.push(`银行卡 ${maskedCard(row)}（${row.bankAccountName || '户名未填'}）`)
    if (row.bankName) parts.push(row.bankName)
    if (row.transferType) parts.push(row.transferType)
    if (row.phone) parts.push(`手机 ${row.phone}`)
    return parts.join('；')
  }

  defineExpose({ reload: load })
</script>

<style scoped lang="scss">
  .fee-cell {
    display: flex;
    flex-wrap: wrap;
    gap: 2px 10px;
    font-size: 12px;
    line-height: 1.7;

    &__item {
      white-space: nowrap;
      color: var(--art-gray-600);
    }

    &__derived {
      margin-left: 2px;
      padding: 0 3px;
      font-size: 10px;
      background: var(--el-color-warning-light-9);
      border-radius: 2px;
    }
  }

  .bank-cell {
    display: flex;
    align-items: center;
    gap: 4px;

    &__no {
      font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
      font-size: 12px;
    }
  }

  .foot-note {
    padding: 4px 8px;
  }

  @media (max-width: 768px) {
    // 手机端 44px 触控兜底（对齐 profiles-panel 的做法）
    :deep(.el-select--small .el-select__wrapper) {
      min-height: 44px !important;
    }
  }
</style>
