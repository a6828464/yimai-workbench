<!--
  课时费与身份标签设置（`GET|PUT /payroll/profiles`）

  身份标签是薪酬计算方式的分叉点（规格 §2.2），所以编辑弹窗里**必须**把该标签的
  规则说明一并展示 —— 用户改「全职老师 → 兼职老师」时得知道底薪/绩效会被强制归零。

  v3.3.5：身份标签支持**表格内联编辑**（点 Tag 直接变下拉，change 只提交 `{role}` 一个
  字段 —— 后端 PUT 对未提交字段保持原值），成功用 res.profile 整行替换（含
  assertSameProfile 防错人），失败回滚。编辑弹窗保留（承担课时费等完整编辑）。
  另新增「课时费」列：五种课型单价紧凑并排，45 分钟显示生效值并保留折算标注。

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
            <!-- 建档入口。此前**只有 PUT 没有 POST**、界面上也没有任何新增按钮，
                 主档（高敏 xlsx，仓库外）之外的老师根本无从建档 -->
            <ElButton size="small" type="primary" @click="openCreate">
              <i class="ri-add-line" /> 新增建档
            </ElButton>
            <ElButton size="small" :loading="prefilling" @click="runPrefill(true)">
              从系统已知信息预填
            </ElButton>
          </div>
        </div>
      </template>

      <!-- 待完善档案：显式提示，并说明为什么不参与计算 -->
      <ElAlert v-if="pendingCount > 0" type="warning" show-icon :closable="false" class="mb-3">
        <template #title>有 {{ pendingCount }} 条「待完善」档案，暂不参与工资计算</template>
        <div class="text-xs mt-1">
          身份标签决定底薪 / 绩效 / 课时费 / 提成 / 底薪奖励 / 门店提成六项算法，
          填错会把钱算错人。所以这些档案<b>整行跳过计算</b>，只在「薪酬计算」页的
          「无法计算的项目」里列出。补齐身份标签后点保存即视为已确认。
        </div>
      </ElAlert>

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
            <ElTag
              v-if="row.dualBaseSalary"
              size="small"
              type="warning"
              effect="plain"
              class="ml-1"
            >
              双底薪
            </ElTag>
            <ElTag v-if="row.pendingReview" size="small" type="danger" effect="plain" class="ml-1">
              待完善
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn label="所属门店" width="92">
          <template #default="{ row }">{{ row.venue }}</template>
        </ElTableColumn>
        <!-- 身份标签：内联编辑（v3.3.5 需求「表格里直接改，不用进编辑弹窗」）。
             PUT profiles 已支持只提交 {role} 一个字段，其余字段后端保持原值。 -->
        <ElTableColumn label="身份标签" width="150">
          <template #default="{ row }">
            <div class="role-cell">
              <template v-if="inlineEditingId === row.id">
                <ElSelect
                  :model-value="inlineDraftRole"
                  size="small"
                  class="role-cell__select"
                  :loading="inlineSaving"
                  @change="(v: string) => commitInlineRole(row, v)"
                  @visible-change="(open: boolean) => !open && cancelInlineRole()"
                >
                  <ElOption v-for="r in roles" :key="r.value" :label="r.label" :value="r.value" />
                </ElSelect>
              </template>
              <template v-else>
                <ElTag
                  v-if="!row.role"
                  size="small"
                  type="danger"
                  effect="plain"
                  class="role-cell__tag"
                  @click="startInlineRole(row)"
                >
                  未设置
                </ElTag>
                <ElTooltip
                  v-else
                  :content="ruleSummary(row.role)"
                  placement="top"
                  :show-after="300"
                >
                  <ElTag
                    size="small"
                    effect="plain"
                    class="role-cell__tag"
                    @click="startInlineRole(row)"
                  >
                    {{ roleLabel(row.role) }}
                    <i class="ri-edit-line role-cell__icon" />
                  </ElTag>
                </ElTooltip>
                <ElIcon v-if="inlineSavingId === row.id" class="role-cell__loading is-loading">
                  <i class="ri-loader-4-line" />
                </ElIcon>
              </template>
            </div>
          </template>
        </ElTableColumn>
        <!-- 课时费（各课型单价紧凑展示）：把单价并成一列，一眼对全五种课型。
             45 分钟显示生效值，derived 时保留「折算」标注（与私教45列同一口径）。 -->
        <ElTableColumn label="课时费" min-width="210">
          <template #default="{ row }">
            <div class="fee-cell">
              <span class="fee-cell__item">私教60 {{ money(row.feePrivate60) }}</span>
              <span class="fee-cell__item">
                私教45
                <span v-if="row.feePrivate45Derived" class="text-warning">
                  {{ money(row.feePrivate45Effective) }}<i class="fee-cell__derived">折算</i>
                </span>
                <template v-else>{{ money(row.feePrivate45) }}</template>
              </span>
              <span class="fee-cell__item">小班 {{ money(row.feeSmall) }}</span>
              <span class="fee-cell__item">团课 {{ money(row.feeGroup) }}</span>
              <span class="fee-cell__item">企业课 {{ money(row.feeEnterprise) }}</span>
            </div>
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
          v-if="editing.pendingReview"
          type="warning"
          show-icon
          :closable="false"
          class="mb-3"
          title="这条档案尚未确认，暂不参与工资计算"
        >
          <div class="text-xs">
            请确认<b>身份标签</b>后保存 —— 身份标签决定六项算法，是算钱的分叉点。
            保存（身份标签合法）即视为已确认，之后本档案才会进入工资计算。
          </div>
        </ElAlert>

        <ElAlert
          v-if="currentRoleRule"
          type="info"
          show-icon
          :closable="false"
          class="mb-3"
          :title="`「${roleLabel(form.role)}」的薪酬计算方式`"
        >
          <div v-for="(v, k) in currentRoleRule" :key="String(k)" class="text-xs">
            <b>{{ ruleKeyLabel(String(k)) }}</b
            >：{{ v }}
          </div>
        </ElAlert>

        <ElFormItem label="身份标签" :required="true">
          <ElSelect v-model="form.role" style="width: 100%" @change="onRoleChange">
            <ElOption v-for="r in roles" :key="r.value" :label="r.label" :value="r.value" />
          </ElSelect>
          <div class="form-hint">
            身份标签决定底薪 / 绩效 / 课时费 / 提成 / 底薪奖励 /
            门店提成六项算法，改动会直接影响工资。
            <b>必填</b
            >：后端不接受空身份标签（空标签会被默认成「全职老师」，按实际课时发底薪奖励）。
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
          <div class="form-hint"> 覆盖阶梯提成，如 0.07 = 固定 7%。清空则按身份标签走阶梯 </div>
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

    <!-- 新增建档：只收「已知的」身份信息；金额一律留空，保存后到编辑弹窗里补 -->
    <ElDialog
      v-model="createVisible"
      title="新增建档"
      :width="isHandheld ? '94%' : '520px'"
      destroy-on-close
    >
      <ElAlert type="info" show-icon :closable="false" class="mb-3">
        <template #title>新档案会标为「待完善」，不参与工资计算</template>
        <div class="text-xs mt-1">
          身份标签决定六项算法（底薪 / 绩效 / 课时费 / 提成 / 底薪奖励 / 门店提成），
          填错会把钱算错人。所以新档案先不参与计算 —— 建好后在列表里点「编辑」补齐
          身份标签与课时费，保存即视为已确认。
        </div>
      </ElAlert>

      <ElForm label-width="88px" label-position="right">
        <ElFormItem label="姓名" required>
          <ElInput v-model="createForm.name" placeholder="真实姓名（别名请到编辑弹窗登记）" />
        </ElFormItem>
        <ElFormItem label="所属门店" required>
          <ElSelect v-model="createForm.venue" style="width: 100%">
            <ElOption v-for="v in venues" :key="v" :label="v" :value="v" />
          </ElSelect>
          <div class="form-hint">工资所属门店（可与账号绑定门店不同）</div>
        </ElFormItem>
        <ElFormItem label="身份标签">
          <ElSelect
            v-model="createForm.role"
            clearable
            placeholder="不确定就留空，稍后补"
            style="width: 100%"
          >
            <ElOption v-for="r in roles" :key="r.value" :label="r.label" :value="r.value" />
          </ElSelect>
          <div class="form-hint">留空 = 待完善，不会参与计算；确定后填上即视为已确认</div>
        </ElFormItem>
        <ElFormItem label="备注">
          <ElInput
            v-model="createForm.note"
            maxlength="200"
            show-word-limit
            type="textarea"
            :rows="2"
          />
        </ElFormItem>
      </ElForm>

      <template #footer>
        <ElButton @click="createVisible = false">取消</ElButton>
        <ElButton type="primary" :loading="creating" @click="submitCreate">建立档案</ElButton>
      </template>
    </ElDialog>

    <!-- 预填预览：先给用户看清单再落库（建档是写操作，不能悄悄发生） -->
    <ElDialog
      v-model="prefillVisible"
      title="从系统已知信息预填建档"
      :width="isHandheld ? '94%' : '680px'"
      top="6vh"
      destroy-on-close
    >
      <div v-if="prefillResult">
        <ElAlert type="info" show-icon :closable="false" class="mb-3">
          <template #title>
            扫描到 {{ prefillResult.willCreate.length }} 位尚未建档的人员
          </template>
          <div class="text-xs mt-1">
            来源＝系统里真实出现过的人：<b>上过课的老师</b>（随心瑜预约）、
            <b>有登录账号的员工</b>、<b>留资里登记的上课老师</b>。 姓名与门店会填好；<b
              >身份标签留空、底薪与课时费一律留 0 并标「待完善」</b
            >， 由你补齐 —— 金额是算钱的输入，系统绝不替你猜。
          </div>
        </ElAlert>

        <ElTable :data="prefillResult.willCreate" border stripe size="small" max-height="300">
          <ElTableColumn prop="name" label="姓名" width="140" />
          <ElTableColumn prop="venue" label="门店" width="100" />
          <ElTableColumn label="来源" min-width="220">
            <template #default="{ row }">
              <ElTag v-for="s in row.sources" :key="s" size="small" effect="plain" class="mr-1">
                {{ sourceLabel(s) }}{{ row.counts?.[s] ? ` ${row.counts[s]}` : '' }}
              </ElTag>
            </template>
          </ElTableColumn>
        </ElTable>

        <div v-if="prefillResult.skipped.length" class="mt-3">
          <div class="text-xs font-500 mb-1"
            >已跳过 {{ prefillResult.skipped.length }} 项（不会重复建档）</div
          >
          <div v-for="(s, i) in prefillResult.skipped" :key="i" class="text-xs text-gray-500">
            {{ s.venue }} {{ s.name }} —— {{ s.reason }}
          </div>
        </div>

        <ElEmpty
          v-if="!prefillResult.willCreate.length"
          description="系统里出现过的人都已经建档"
          :image-size="60"
        />
      </div>
      <div v-else v-loading="prefilling" class="min-h-[120px]" />

      <template #footer>
        <ElButton @click="prefillVisible = false">取消</ElButton>
        <ElButton
          type="primary"
          :disabled="!prefillResult?.willCreate.length"
          :loading="prefilling"
          @click="runPrefill(false)"
        >
          确认建立 {{ prefillResult?.willCreate.length ?? 0 }} 条档案
        </ElButton>
      </template>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import { computed, ref, watch } from 'vue'
  import {
    assertSameProfile,
    createPayrollProfile,
    fetchPayrollProfiles,
    payrollErrorMessage,
    prefillPayrollProfiles,
    updatePayrollProfile,
    type PayrollPrefillResult,
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
    // 与后端 ROLE_NOT_ALLOWED 一致：空身份标签会被拒。提前拦住，省一次失败往返
    if (!form.value.role) {
      emit('error', '请先选择身份标签（决定六项算法，不能留空）')
      return
    }
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
        res.changed
          ? `已保存「${res.profile.name}」的课时费与身份标签`
          : `「${res.profile.name}」没有改动`
      )
    } catch (e) {
      emit('error', payrollErrorMessage(e, '保存失败'))
    } finally {
      saving.value = false
    }
  }

  // ---------- 身份标签内联编辑（表格里直接改，不进弹窗） ----------

  /** 当前处于下拉编辑态的行 id；null = 无行在编辑 */
  const inlineEditingId = ref<number | null>(null)
  /** 正在提交 PUT 的行 id（单元格旁转圈）；失败回滚后清除 */
  const inlineSavingId = ref<number | null>(null)
  const inlineSaving = ref(false)
  /** 下拉打开瞬间的展示值快照（change 前的 model-value），用于失败回滚 */
  const inlineDraftRole = ref('')

  function startInlineRole(row: PayrollProfileRow) {
    if (inlineSaving.value) return // 上一次提交还没落定，不许并发开第二个
    inlineDraftRole.value = row.role
    inlineEditingId.value = row.id
  }

  function cancelInlineRole() {
    // 下拉收起且没有触发 change：退出编辑态即可，行数据从未被改过
    if (!inlineSaving.value) inlineEditingId.value = null
  }

  /**
   * 就地提交身份标签。只发 `{role}` 一个字段（后端 PUT 保持未提交字段原值），
   * 成功用 res.profile 整行替换并走 assertSameProfile 防错人，失败回滚并上抛错误。
   */
  async function commitInlineRole(row: PayrollProfileRow, newRole: string) {
    if (newRole === row.role) {
      inlineEditingId.value = null
      return
    }
    inlineSaving.value = true
    inlineSavingId.value = row.id
    try {
      const res = await updatePayrollProfile(row.id, { role: newRole })
      // 与 save() 同一防线：后端两段式查找可能错人，先核对再落界面
      assertSameProfile(row, res.profile)
      const idx = rows.value.findIndex((r) => r.id === res.profile.id)
      if (idx >= 0) rows.value[idx] = res.profile
      emit(
        'success',
        res.changed
          ? `已把「${res.profile.name}」的身份标签改为「${roleLabel(res.profile.role)}」`
          : `「${res.profile.name}」的身份标签没有改动`
      )
    } catch (e) {
      // 回滚：行数据未被直接改过（下拉只存 draft），退出编辑态即还原显示
      emit('error', payrollErrorMessage(e, '身份标签保存失败'))
    } finally {
      inlineSaving.value = false
      inlineSavingId.value = null
      inlineEditingId.value = null
    }
  }

  // ---------- 新增建档 ----------

  const createVisible = ref(false)
  const creating = ref(false)
  const createForm = ref<{ name: string; venue: string; role: string; note: string }>({
    name: '',
    venue: '',
    role: '',
    note: ''
  })

  /** 待完善条数：用于顶部提示「这些档案不参与计算」 */
  const pendingCount = computed(() => rows.value.filter((r) => r.pendingReview).length)

  function openCreate() {
    createForm.value = {
      name: '',
      venue: props.venue || venues.value[0] || '',
      role: '',
      note: ''
    }
    createVisible.value = true
  }

  async function submitCreate() {
    const f = createForm.value
    if (!f.name.trim()) {
      emit('error', '请填写姓名')
      return
    }
    if (!f.venue) {
      emit('error', '请选择所属门店')
      return
    }
    creating.value = true
    try {
      const res = await createPayrollProfile({
        name: f.name.trim(),
        venue: f.venue,
        // 空身份标签不提交：后端会归一化成空串并保持「待完善」
        ...(f.role ? { role: f.role } : {}),
        ...(f.note ? { note: f.note } : {})
      })
      createVisible.value = false
      await load()
      emit(
        'success',
        `已建立「${res.profile.name}」的档案（待完善，暂不参与计算；请点「编辑」补齐身份标签与课时费）`
      )
    } catch (e) {
      emit('error', payrollErrorMessage(e, '建档失败'))
    } finally {
      creating.value = false
    }
  }

  // ---------- 从系统已知信息预填 ----------

  const prefillVisible = ref(false)
  const prefilling = ref(false)
  const prefillResult = ref<PayrollPrefillResult | null>(null)

  function sourceLabel(src: string): string {
    const map: Record<string, string> = {
      bookings: '上过课',
      users: '有账号',
      leads: '留资登记'
    }
    return map[src] ?? src
  }

  /**
   * `dryRun = true`：只扫清单给用户看（不写库）。
   * `dryRun = false`：确认后落库，并把清单换成本次结果。
   */
  async function runPrefill(dryRun: boolean) {
    prefilling.value = true
    if (dryRun) {
      prefillResult.value = null
      prefillVisible.value = true
    }
    try {
      const res = await prefillPayrollProfiles(dryRun)
      if (dryRun) {
        prefillResult.value = res
        if (!res.willCreate.length) {
          emit('success', '系统里出现过的人都已经建档，无需预填')
        }
      } else {
        prefillVisible.value = false
        await load()
        emit(
          'success',
          `已预填 ${res.created} 条档案（全部标为「待完善」，不参与计算）。请逐条补齐身份标签与课时费。`
        )
      }
    } catch (e) {
      if (dryRun) prefillVisible.value = false
      emit('error', payrollErrorMessage(e, dryRun ? '预填扫描失败' : '预填建档失败'))
    } finally {
      prefilling.value = false
    }
  }

  // ---------- 手机端卡片 ----------
  function profileTags(row: PayrollProfileRow): MobileCardTag[] {
    const tags: MobileCardTag[] = [
      row.role
        ? { text: row.roleLabel || row.role, effect: 'plain' }
        : { text: '身份标签未设置', type: 'danger', effect: 'plain' }
    ]
    if (row.pendingReview) tags.push({ text: '待完善', type: 'danger', effect: 'dark' })
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
      parts.push(
        `45 分钟未单独设置，按 60×${fee45Factor.value} 折算为 ${yuan(row.feePrivate45Effective)}`
      )
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

  // 身份标签内联编辑：Tag 点击切换成下拉，失败回滚（行数据未被改过）
  .role-cell {
    display: flex;
    align-items: center;
    gap: 4px;

    &__tag {
      cursor: pointer;
    }

    &__icon {
      margin-left: 4px;
      font-size: 12px;
      color: var(--art-gray-400);
    }

    &__select {
      width: 120px;
    }

    &__loading {
      font-size: 14px;
      color: var(--el-color-primary);
    }
  }

  // 课时费紧凑列：五种课型单价并排，45 分钟折算沿用「text-warning + 小字标注」口径
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
      font-style: normal;
      color: var(--el-color-warning);
      background: var(--el-color-warning-light-9);
      border-radius: 2px;
    }
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
