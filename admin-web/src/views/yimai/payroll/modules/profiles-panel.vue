<!--
  课时费与身份标签设置（`GET|PUT /payroll/profiles`）

  身份标签是薪酬计算方式的分叉点（规格 §2.2），所以编辑弹窗里**必须**把该标签的
  规则说明一并展示 —— 用户改「全职老师 → 兼职老师」时得知道底薪/绩效会被强制归零。

  ⚠️ 枚举一律来自 `GET /payroll/roles`（规格 §7.4 的唯一下发点），本文件不写死列表。

  ⚠️ 45 分钟课时费有两层语义，界面必须分开：
    - 档案值 `feePrivate45`：人工配置；为 0 表示「未单独设置」
    - 生效值 `feePrivate45Effective`：档案为 0 时按 60×0.75 折算（后端算好下发）
  显示「折算 ¥X」而不是把折算值当成人工配置值，否则用户会以为系统里本来就配了 120。
-->
<template>
  <div class="profiles">
    <ElCard shadow="never" class="mb-3">
      <template #header>
        <div class="flex-cb">
          <span class="font-500">老师课时费与身份标签（{{ rows.length }} 人）</span>
          <div class="flex-c gap-2">
            <ElSelect
              v-model="roleFilter"
              placeholder="全部身份标签"
              clearable
              size="small"
              style="width: 180px"
              @change="load"
            >
              <ElOption v-for="r in roles" :key="r.value" :label="r.label" :value="r.value" />
            </ElSelect>
            <ElButton size="small" @click="load">刷新</ElButton>
          </div>
        </div>
      </template>

      <!-- 桌面端：表格列完整，不丢字段 -->
      <ElTable
        v-if="!isHandheld"
        ref="tableRef"
        v-loading="loading"
        :data="rows"
        border
        stripe
        :max-height="tableMaxHeight"
      >
        <ElTableColumn prop="name" label="姓名" width="100" fixed="left">
          <template #default="{ row }">
            {{ row.name }}
            <ElTag v-if="row.dualBaseSalary" size="small" type="warning" effect="plain" class="ml-1">
              双底薪
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn label="所属门店" width="92">
          <template #default="{ row }">{{ row.venue }}</template>
        </ElTableColumn>
        <ElTableColumn label="身份标签" width="130">
          <template #default="{ row }">
            <ElTooltip :content="ruleSummary(row.role)" placement="top" :show-after="300">
              <ElTag size="small" effect="plain">{{ roleLabel(row.role) }}</ElTag>
            </ElTooltip>
          </template>
        </ElTableColumn>
        <ElTableColumn label="基本底薪" width="110" align="right">
          <template #default="{ row }">{{ yuan(row.baseSalary) }}</template>
        </ElTableColumn>
        <ElTableColumn label="绩效" width="110" align="right">
          <template #default="{ row }">{{ yuan(row.performance) }}</template>
        </ElTableColumn>
        <ElTableColumn label="私教60" width="110" align="right">
          <template #default="{ row }">{{ yuan(row.feePrivate60) }}</template>
        </ElTableColumn>
        <ElTableColumn label="私教45" width="130" align="right">
          <template #default="{ row }">
            <span v-if="row.feePrivate45Derived" class="text-warning">
              {{ yuan(row.feePrivate45Effective) }}
              <div class="text-xs">按 60×{{ fee45Factor }} 折算</div>
            </span>
            <span v-else>{{ yuan(row.feePrivate45) }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn label="小班" width="110" align="right">
          <template #default="{ row }">{{ yuan(row.feeSmall) }}</template>
        </ElTableColumn>
        <ElTableColumn label="团课" width="110" align="right">
          <template #default="{ row }">{{ yuan(row.feeGroup) }}</template>
        </ElTableColumn>
        <ElTableColumn label="企业课" width="110" align="right">
          <template #default="{ row }">{{ yuan(row.feeEnterprise) }}</template>
        </ElTableColumn>
        <ElTableColumn label="门店提成率" width="110" align="right">
          <template #default="{ row }">{{ percent(row.storeCommissionRate) }}</template>
        </ElTableColumn>
        <ElTableColumn label="固定提成率" width="110" align="right">
          <template #default="{ row }">
            {{ row.commissionFixedRate === null ? '—' : percent(row.commissionFixedRate) }}
          </template>
        </ElTableColumn>
        <ElTableColumn label="人员状态" width="100">
          <template #default="{ row }">
            <ElTag size="small" :type="row.status === '有效' ? 'success' : 'info'">
              {{ row.status }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn label="别名" min-width="140" show-overflow-tooltip>
          <template #default="{ row }">
            <span v-if="row.aliases?.length">{{ row.aliases.join('、') }}</span>
            <span v-else class="text-gray-400">—</span>
          </template>
        </ElTableColumn>
        <ElTableColumn label="操作" width="90" fixed="right">
          <template #default="{ row }">
            <ElButton link type="primary" size="small" @click="openEdit(row)">编辑</ElButton>
          </template>
        </ElTableColumn>
      </ElTable>

      <!-- 手持设备：卡片列表 -->
      <div v-if="isHandheld" v-loading="loading" class="m-card-list min-h-[120px]">
        <MobileCard
          v-for="row in rows"
          :key="row.id"
          :title="row.name"
          :subtitle="`${row.venue} · ${row.status}`"
          :tags="profileTags(row)"
          :metrics="profileMetrics(row)"
          :note="profileNote(row)"
          :actions="[{ text: '编辑课时费与标签', type: 'primary', onClick: () => openEdit(row) }]"
        />
        <div v-if="!loading && !rows.length" class="m-card-list__empty">没有符合条件的人员</div>
      </div>
    </ElCard>

    <!-- 编辑弹窗 -->
    <ElDialog
      v-model="dialogVisible"
      :title="`编辑：${editing?.name ?? ''}`"
      :width="isHandheld ? '94%' : '720px'"
      top="5vh"
      destroy-on-close
    >
      <ElForm v-if="editing" label-width="118px" label-position="right">
        <ElAlert
          v-if="currentRoleRule"
          type="info"
          show-icon
          :closable="false"
          class="mb-3"
          :title="`「${roleLabel(form.role)}」的薪酬计算方式`"
        >
          <div v-for="(v, k) in currentRoleRule" :key="String(k)" class="text-xs">
            <b>{{ ruleKeyLabel(String(k)) }}</b>：{{ v }}
          </div>
        </ElAlert>

        <ElFormItem label="身份标签">
          <ElSelect v-model="form.role" style="width: 100%" @change="onRoleChange">
            <ElOption v-for="r in roles" :key="r.value" :label="r.label" :value="r.value" />
          </ElSelect>
          <div class="form-hint">
            身份标签决定底薪 / 绩效 / 课时费 / 提成 / 底薪奖励 / 门店提成六项算法，改动会直接影响工资
          </div>
        </ElFormItem>

        <ElFormItem label="所属门店">
          <ElSelect v-model="form.venue" style="width: 100%">
            <ElOption v-for="v in venues" :key="v" :label="v" :value="v" />
          </ElSelect>
        </ElFormItem>

        <ElFormItem label="人员状态">
          <ElSelect v-model="form.status" style="width: 100%">
            <ElOption v-for="s in statuses" :key="s" :label="s" :value="s" />
          </ElSelect>
        </ElFormItem>

        <div class="form-section">底薪与绩效</div>
        <ElFormItem label="基本底薪">
          <ElInputNumber v-model="form.baseSalary" :min="0" :max="999999" :precision="2" />
          <div v-if="isPartTime" class="form-hint form-hint--warn">
            兼职老师只按课时计费，底薪与绩效<b>必须为 0</b>（否则后端拒绝保存）
          </div>
        </ElFormItem>
        <ElFormItem label="绩效">
          <ElInputNumber v-model="form.performance" :min="0" :max="999999" :precision="2" />
        </ElFormItem>
        <ElFormItem label="双底薪">
          <ElSwitch v-model="form.dualBaseSalary" />
          <div class="form-hint">
            两店各发一份底薪。仅例外名单（{{ dualWhitelist.join(' / ') || '—' }}）可开启
          </div>
        </ElFormItem>

        <div class="form-section">课时费（元/节）</div>
        <ElFormItem label="私教 60 分钟">
          <ElInputNumber v-model="form.feePrivate60" :min="0" :max="999999" :precision="2" />
        </ElFormItem>
        <ElFormItem label="私教 45 分钟">
          <ElInputNumber v-model="form.feePrivate45" :min="0" :max="999999" :precision="2" />
          <div class="form-hint">
            留 0 = 未单独设置，计算时按「私教 60 × {{ fee45Factor }}」折算 =
            <b>{{ yuan(derived45) }}</b>
            <span class="text-warning">
              （折算发生在计算时，<b>不写回档案</b> —— 写回就再也分不清「人工填了 0」与「没填」）
            </span>
          </div>
        </ElFormItem>
        <ElFormItem label="小班">
          <ElInputNumber v-model="form.feeSmall" :min="0" :max="999999" :precision="2" />
        </ElFormItem>
        <ElFormItem label="团课">
          <ElInputNumber v-model="form.feeGroup" :min="0" :max="999999" :precision="2" />
        </ElFormItem>
        <ElFormItem label="企业课">
          <ElInputNumber v-model="form.feeEnterprise" :min="0" :max="999999" :precision="2" />
          <div class="form-hint">
            企业课 / 总监私教 / 短期集训类目前无法从同步数据里区分，该单价暂不生效
          </div>
        </ElFormItem>

        <div class="form-section">提成率</div>
        <ElFormItem label="门店提成率">
          <ElInputNumber
            v-model="form.storeCommissionRate"
            :min="0"
            :max="1"
            :step="0.01"
            :precision="4"
          />
          <div class="form-hint">小数形式，如 0.02 = 2%。店长默认 2%，留 0 表示不参与门店提成</div>
        </ElFormItem>
        <ElFormItem label="固定提成率">
          <ElInputNumber
            v-model="form.commissionFixedRate"
            :min="0"
            :max="1"
            :step="0.01"
            :precision="4"
          />
          <div class="form-hint">
            覆盖阶梯提成，如 0.07 = 固定 7%。清空则按身份标签走阶梯
          </div>
        </ElFormItem>

        <div class="form-section">姓名别名</div>
        <ElFormItem label="别名">
          <ElSelect
            v-model="form.aliases"
            multiple
            filterable
            allow-create
            default-first-option
            :reserve-keyword="false"
            placeholder="输入别名后回车，如「苏米」"
            style="width: 100%"
          >
            <ElOption v-for="a in form.aliases" :key="a" :label="a" :value="a" />
          </ElSelect>
          <div class="form-hint">
            业绩表的列名经常不是本名（苏米→罗柳柳、娟子→徐秀娟…）。<b>别名登记在这里</b>，
            否则导入时该列金额会因「姓名对不上」被拒。本名无需登记。一个名字只能对一个人。
          </div>
        </ElFormItem>

        <ElFormItem label="备注">
          <ElInput v-model="form.note" maxlength="200" show-word-limit type="textarea" :rows="2" />
        </ElFormItem>
      </ElForm>

      <template #footer>
        <ElButton @click="dialogVisible = false">取消</ElButton>
        <ElButton type="primary" :loading="saving" @click="save">保存</ElButton>
      </template>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import { computed, ref, watch } from 'vue'
  import {
    assertSameProfile,
    fetchPayrollProfiles,
    payrollErrorMessage,
    updatePayrollProfile,
    type PayrollProfileRow,
    type PayrollProfileUpdate,
    type PayrollRoleOption,
    type PayrollRolesCatalog
  } from '@/api/payroll'
  import { useDevice } from '@/hooks/core/useDevice'
  import { useTableHeight } from '@/hooks/core/useTableHeight'
  import type { MobileCardMetric, MobileCardTag } from '@/components/business/mobile-card/types'
  import { money, percent, yuan } from './shared'

  defineOptions({ name: 'PayrollProfiles' })

  const props = defineProps<{
    venue: string | null
    catalog: PayrollRolesCatalog | null
  }>()

  const emit = defineEmits<{ error: [msg: string]; success: [msg: string] }>()

  const { isHandheld } = useDevice()
  const { tableMaxHeight, tableRef } = useTableHeight()

  const loading = ref(false)
  const saving = ref(false)
  const rows = ref<PayrollProfileRow[]>([])
  const roleFilter = ref('')

  /** 枚举一律来自后端；catalog 未加载时退回空数组（宁可空，也不写死一份） */
  const roles = computed<PayrollRoleOption[]>(() => props.catalog?.roles ?? [])
  const venues = computed(() => props.catalog?.venues ?? [])
  const statuses = computed(() => props.catalog?.statuses ?? [])
  const dualWhitelist = computed(() => props.catalog?.dualBaseSalaryWhitelist ?? [])
  const fee45Factor = computed(() => props.catalog?.fee45FallbackFactor ?? 0.75)

  watch(() => props.venue, load, { immediate: true })
  watch(() => roleFilter.value, load)

  async function load() {
    loading.value = true
    try {
      const res = await fetchPayrollProfiles({
        venue: props.venue,
        role: roleFilter.value || undefined
      })
      rows.value = res.rows ?? []
    } catch (e) {
      rows.value = []
      emit('error', payrollErrorMessage(e, '薪酬档案加载失败'))
    } finally {
      loading.value = false
    }
  }

  function roleLabel(value: string): string {
    return roles.value.find((r) => r.value === value)?.label ?? value
  }

  function ruleSummary(role: string): string {
    const rules = roles.value.find((r) => r.value === role)?.salaryRules
    if (!rules) return ''
    return Object.entries(rules)
      .filter(([, v]) => v)
      .map(([k, v]) => `${ruleKeyLabel(k)}：${v}`)
      .join('\n')
  }

  function ruleKeyLabel(key: string): string {
    const map: Record<string, string> = {
      base: '底薪',
      performance: '绩效',
      hourly: '课时费',
      hourlyIncentive: '私教激励',
      commission: '销售提成',
      baseReward: '底薪奖励',
      storeCommission: '门店提成'
    }
    return map[key] ?? key
  }

  // ---------- 编辑 ----------

  const dialogVisible = ref(false)
  const editing = ref<PayrollProfileRow | null>(null)

  const form = ref<{
    role: string
    venue: string
    status: string
    baseSalary: number
    performance: number
    dualBaseSalary: boolean
    feePrivate60: number
    feePrivate45: number
    feeSmall: number
    feeGroup: number
    feeEnterprise: number
    storeCommissionRate: number
    commissionFixedRate: number | null
    aliases: string[]
    note: string
  }>({
    role: '',
    venue: '',
    status: '有效',
    baseSalary: 0,
    performance: 0,
    dualBaseSalary: false,
    feePrivate60: 0,
    feePrivate45: 0,
    feeSmall: 0,
    feeGroup: 0,
    feeEnterprise: 0,
    storeCommissionRate: 0,
    commissionFixedRate: null,
    aliases: [],
    note: ''
  })

  const isPartTime = computed(() => form.value.role === '兼职老师')

  const currentRoleRule = computed(
    () => roles.value.find((r) => r.value === form.value.role)?.salaryRules ?? null
  )

  /** 45 分钟折算预览：与后端同一算式（60 × 0.75），仅用于输入时的即时反馈 */
  const derived45 = computed(() => form.value.feePrivate60 * fee45Factor.value)

  function openEdit(row: PayrollProfileRow) {
    editing.value = row
    form.value = {
      role: row.role,
      venue: row.venue,
      status: row.status,
      baseSalary: row.baseSalary,
      performance: row.performance,
      dualBaseSalary: row.dualBaseSalary,
      feePrivate60: row.feePrivate60,
      feePrivate45: row.feePrivate45,
      feeSmall: row.feeSmall,
      feeGroup: row.feeGroup,
      feeEnterprise: row.feeEnterprise,
      storeCommissionRate: row.storeCommissionRate,
      commissionFixedRate: row.commissionFixedRate,
      aliases: [...(row.aliases ?? [])],
      note: row.note
    }
    dialogVisible.value = true
  }

  /** 切成兼职老师时把底薪/绩效归零，省得用户保存时被后端拒一次 */
  function onRoleChange(role: string) {
    if (role === '兼职老师') {
      form.value.baseSalary = 0
      form.value.performance = 0
    }
  }

  async function save() {
    const row = editing.value
    if (!row) return
    saving.value = true
    try {
      const body: PayrollProfileUpdate = {
        venue: form.value.venue,
        role: form.value.role,
        status: form.value.status,
        baseSalary: form.value.baseSalary,
        performance: form.value.performance,
        dualBaseSalary: form.value.dualBaseSalary,
        feePrivate60: form.value.feePrivate60,
        feePrivate45: form.value.feePrivate45,
        feeSmall: form.value.feeSmall,
        feeGroup: form.value.feeGroup,
        feeEnterprise: form.value.feeEnterprise,
        storeCommissionRate: form.value.storeCommissionRate,
        aliases: form.value.aliases,
        note: form.value.note
      }
      // 固定提成率：清空表示「不覆盖」，不提交该字段（后端据此保持原值）
      if (form.value.commissionFixedRate !== null) {
        body.commissionFixedRate = form.value.commissionFixedRate
      }

      const res = await updatePayrollProfile(row.id, body)
      // 后端查找顺序是「先 user_id 再 profile.id」，存在 id 空间混用的可能；
      // 工资数据改错人代价极高，所以核对返回体再落库到界面
      assertSameProfile(row, res.profile)

      const idx = rows.value.findIndex((r) => r.id === res.profile.id)
      if (idx >= 0) rows.value[idx] = res.profile
      dialogVisible.value = false
      emit(
        'success',
        res.changed ? `已保存「${res.profile.name}」的课时费与身份标签` : `「${res.profile.name}」没有改动`
      )
    } catch (e) {
      emit('error', payrollErrorMessage(e, '保存失败'))
    } finally {
      saving.value = false
    }
  }

  // ---------- 手机端卡片 ----------

  function profileTags(row: PayrollProfileRow): MobileCardTag[] {
    const tags: MobileCardTag[] = [{ text: row.roleLabel || row.role, effect: 'plain' }]
    if (row.dualBaseSalary) tags.push({ text: '双底薪', type: 'warning', effect: 'dark' })
    return tags
  }

  function profileMetrics(row: PayrollProfileRow): MobileCardMetric[] {
    return [
      { label: '底薪', value: yuan(row.baseSalary) },
      {
        label: '私教 60/45',
        value: `${money(row.feePrivate60)} / ${money(row.feePrivate45Derived ? row.feePrivate45Effective : row.feePrivate45)}`
      },
      { label: '小班/团课', value: `${money(row.feeSmall)} / ${money(row.feeGroup)}` }
    ]
  }

  function profileNote(row: PayrollProfileRow): string {
    const parts: string[] = []
    if (row.feePrivate45Derived) {
      parts.push(`45 分钟未单独设置，按 60×${fee45Factor.value} 折算为 ${yuan(row.feePrivate45Effective)}`)
    }
    if (row.storeCommissionRate > 0) parts.push(`门店提成 ${percent(row.storeCommissionRate)}`)
    if (row.aliases?.length) parts.push(`别名：${row.aliases.join('、')}`)
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

  @media (max-width: 768px) {
    :deep(.el-form-item__label) {
      width: auto !important;
    }

    :deep(.el-form-item) {
      display: block;
    }

    // 表头的「身份标签」筛选是 size="small"（24px），而 assets/styles/core/mobile.scss
    // 的全局 44px 兜底只覆盖默认尺寸的 .el-select__wrapper，small 尺寸不在其中。
    // 触屏上 24px 高的下拉几乎点不中，这里在本页范围内补上（不改全局样式，避免与 t8 冲突）。
    :deep(.el-select--small .el-select__wrapper) {
      min-height: 44px !important;
    }
  }
</style>
