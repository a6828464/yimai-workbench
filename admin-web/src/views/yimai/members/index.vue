<template>
  <div class="member-page art-full-height">
    <ElCard class="art-table-card !h-auto">
      <!-- Tab 导航 -->
      <ElTabs v-model="activeTab" class="mb-3">
        <ElTabPane v-for="t in TABS" :key="t.key" :name="t.key">
          <template #label>
            <span class="flex items-center gap-1.5">
              {{ t.label }}
              <ElTag v-if="t.key !== 'all'" size="small" :type="t.tag" effect="plain">{{
                listCounts[TAB_KEY_TO_LIST[t.key]] ?? 0
              }}</ElTag>
            </span>
          </template>
        </ElTabPane>
      </ElTabs>

      <!-- 规则设置 -->
      <div class="mb-3 flex flex-wrap items-center gap-3 text-xs text-gray-400">
        <span>{{ scopeHint }}</span>
        <ElButton v-if="isSuper" link type="primary" size="small" @click="rulesDlg = true"
          >调整标签阈值</ElButton
        >
      </div>

      <!-- 筛选：总览与其他清单页签均生效 -->
      <div class="mb-3 flex flex-wrap items-center gap-3">
        <ElInput
          v-model="searchForm.name"
          placeholder="会员姓名"
          clearable
          class="!w-36"
          @change="load"
        />
        <ElInput
          v-model="searchForm.phone"
          placeholder="手机号 / 尾号"
          clearable
          class="!w-40"
          @change="load"
        />
        <ElSelect
          v-if="!isManager"
          v-model="searchForm.venue"
          placeholder="门店"
          clearable
          class="!w-28"
          @change="load"
        >
          <ElOption label="绿地店" value="绿地店" />
          <ElOption label="东部店" value="东部店" />
        </ElSelect>
        <ElSelect
          v-if="activeTab === 'all'"
          v-model="searchForm.list"
          placeholder="运营清单"
          clearable
          class="!w-36"
          @change="load"
        >
          <ElOption v-for="k in LIST_KEYS" :key="k" :label="k" :value="k" />
        </ElSelect>
        <ElSelect
          v-model="searchForm.consultant"
          placeholder="会籍顾问"
          clearable
          class="!w-36"
          @change="load"
        >
          <ElOption value="待分配" label="待分配" />
          <ElOption v-for="c in consultantOptions" :key="c" :label="c" :value="c" />
        </ElSelect>
        <ElSelect
          v-model="searchForm.evaluationStatus"
          placeholder="评估状态"
          clearable
          class="!w-32"
          @change="load"
        >
          <ElOption
            v-for="status in EVALUATION_STATUSES"
            :key="status"
            :label="status"
            :value="status"
          />
        </ElSelect>
        <ElButton type="primary" plain @click="load">查询</ElButton>
      </div>

      <ArtTableHeader :columns="[]" :loading="loading">
        <template #left
          ><span class="text-sm text-gray-400">{{ scopeHint }}</span></template
        >
      </ArtTableHeader>

      <ElTable v-loading="loading" :data="pagedList" border stripe max-height="520">
        <ElTableColumn label="会员" min-width="130" fixed="left">
          <template #default="{ row }">
            <div class="font-500">{{ row.name }}</div>
            <div class="text-xs text-gray-400"
              >{{ row.phone || '尾号' + row.phoneTail
              }}{{ row.source ? ` · ${row.source}` : '' }}</div
            >
          </template>
        </ElTableColumn>

        <ElTableColumn label="门店" width="85">
          <template #default="{ row }">
            <ElTag size="small" effect="plain">{{ row.venue }}</ElTag>
          </template>
        </ElTableColumn>

        <ElTableColumn label="会籍顾问" min-width="100">
          <template #default="{ row }">
            <span v-if="row.consultant" class="text-sm">{{ row.consultant }}</span>
            <ElTag v-else size="small" type="danger" effect="plain">待分配</ElTag>
          </template>
        </ElTableColumn>

        <ElTableColumn label="清单归属" min-width="170">
          <template #default="{ row }">
            <div class="flex flex-wrap gap-1">
              <ElTag
                v-for="l in memberLists(row)"
                :key="l"
                size="small"
                effect="dark"
                :type="listType(l)"
                >{{ l }}</ElTag
              >
              <span v-if="!memberLists(row).length" class="text-xs text-gray-300">—</span>
            </div>
          </template>
        </ElTableColumn>

        <!-- 出勤三列：所有清单通用展示 -->
        <ElTableColumn v-if="activeTab !== 'vip'" label="M1/M2/M3出勤" width="120" align="center">
          <template #default="{ row }">
            <span :class="declining(row) ? 'font-500 text-red-500' : ''">
              {{ row.attendM1 ?? '-' }} / {{ row.attendM2 ?? '-' }} / {{ row.attendM3 ?? '-' }}
            </span>
          </template>
        </ElTableColumn>

        <ElTableColumn
          v-if="activeTab === 'all' || activeTab === 'renewal'"
          label="剩余课时"
          width="90"
          align="center"
        >
          <template #default="{ row }">
            <span
              :class="
                row.remainTimes !== null && row.remainTimes < rules.renewalThreshold
                  ? 'font-500 text-red-500'
                  : ''
              "
            >
              {{ row.remainTimes ?? '—' }}
            </span>
          </template>
        </ElTableColumn>

        <ElTableColumn
          v-if="activeTab === 'all' || activeTab === 'vip'"
          label="累计购买金额"
          width="120"
          align="center"
        >
          <template #default="{ row }">{{
            row.cardPaidAmount != null ? `¥${formatMoney(row.cardPaidAmount)}` : '—'
          }}</template>
        </ElTableColumn>

        <ElTableColumn v-if="activeTab === 'renewal'" label="续费评估" width="145">
          <template #default="{ row }">
            <div v-if="row.evalScore !== null && row.evalScore !== undefined">
              <div class="flex items-center gap-1">
                <span class="text-lg font-600" :class="evaluationColor(row.evalScore)">{{
                  row.evalScore
                }}</span>
                <ElTag size="small" :type="evaluationTag(row.evalScore)" effect="plain">
                  {{ renewalLevelLabel(renewalLevel(row.evalScore)) }}
                </ElTag>
              </div>
              <div
                class="mt-1 text-xs"
                :class="evaluationExpired(row) ? 'text-red-500' : 'text-gray-400'"
              >
                {{ row.evalAt || '日期未知' }}{{ evaluationExpired(row) ? ' · 已过期' : '' }}
              </div>
              <div v-if="row.evalBy" class="text-xs text-gray-400">评估人：{{ row.evalBy }}</div>
            </div>
            <ElTag v-else size="small" type="primary" effect="plain">待评估</ElTag>
          </template>
        </ElTableColumn>

        <ElTableColumn v-if="activeTab !== 'all'" label="下一步动作" min-width="165">
          <template #default="{ row }">
            <div v-if="row.nextAction">
              <div class="text-sm font-500">{{ row.nextAction }}</div>
              <div
                class="mt-1 text-xs"
                :class="actionOverdue(row) ? 'text-red-500' : 'text-gray-400'"
              >
                {{ row.owner || row.consultant || '待分配' }} ·
                {{ row.nextActionTime || '待定时间' }}
              </div>
            </div>
            <span v-else class="text-xs text-orange-500">待明确下一步动作</span>
          </template>
        </ElTableColumn>

        <!-- 清单专属列 -->
        <template v-if="activeTab === 'renewal'">
          <ElTableColumn label="续课计划" min-width="220">
            <template #default="{ row }">
              <div v-if="row.renewalPlan?.time">
                <div
                  >{{ row.renewalPlan.intent }} · {{ row.renewalPlan.time }} ·
                  {{ row.renewalPlan.amount }}</div
                >
                <div class="text-xs text-gray-400">诉求：{{ row.renewalPlan.issue || '—' }}</div>
              </div>
              <span v-else class="text-xs text-orange-500">待教练月度预报</span>
            </template>
          </ElTableColumn>
        </template>

        <template v-if="activeTab === 'decline'">
          <ElTableColumn label="下降原因 / 解决方案" min-width="200">
            <template #default="{ row }">
              <div v-if="row.decline?.reason">
                {{ row.decline.reason
                }}<template v-if="row.decline.solution"> · {{ row.decline.solution }}</template>
              </div>
              <span v-else class="text-xs text-orange-500">待管理层确认原因</span>
            </template>
          </ElTableColumn>
        </template>

        <template v-if="activeTab === 'predrop'">
          <ElTableColumn label="停训情况" min-width="180">
            <template #default="{ row }">
              <div>{{ row.stopReason || '停课原因待回访确认' }}</div>
              <div class="text-xs text-gray-400"
                >最近到店：{{ daysAgo(row.lastVisit) }}天前{{
                  row.expectedReturn ? ` · 预期复活 ${row.expectedReturn}` : ''
                }}</div
              >
            </template>
          </ElTableColumn>
        </template>

        <template v-if="activeTab === 'revive'">
          <ElTableColumn label="复活跟进" min-width="200">
            <template #default="{ row }">
              <div>预期复活：{{ row.expectedReturn || '未确认' }}</div>
              <div
                class="text-xs"
                :class="touchOverdue(row) ? 'text-red-500 font-500' : 'text-gray-400'"
              >
                最近沟通：{{ row.lastTouch || '从未'
                }}{{ row.lastTouch ? `（${daysAgo2(row.lastTouch)}天前）` : '' }} · 需协助
                <ElTag v-if="row.needsHelp" size="small" type="danger">是</ElTag>
              </div>
            </template>
          </ElTableColumn>
        </template>

        <ElTableColumn label="操作" width="230" fixed="right">
          <template #default="{ row }">
            <ElButton link type="primary" size="small" @click="openEval(row)">续费评估</ElButton>
            <ElButton
              v-if="memberLists(row).includes('待续课')"
              link
              type="warning"
              size="small"
              @click="openRenewal(row)"
              >续课计划</ElButton
            >
            <ElButton
              v-if="memberLists(row).includes('出勤降低')"
              link
              type="info"
              size="small"
              @click="openDecline(row)"
              >下降处置</ElButton
            >
            <ElButton
              v-if="memberLists(row).includes('预流失') && !row.inRevive"
              link
              type="success"
              size="small"
              @click="toRevive(row)"
              >转待复活</ElButton
            >
            <ElButton
              v-if="memberLists(row).includes('待复活')"
              link
              type="primary"
              size="small"
              @click="openTouch(row)"
              >记录沟通</ElButton
            >
          </template>
        </ElTableColumn>
      </ElTable>

      <div class="mt-4 flex justify-end">
        <ElPagination
          v-model:current-page="page.current"
          v-model:page-size="page.size"
          :page-sizes="[10, 20, 50, 100]"
          :total="filteredList.length"
          layout="total, sizes, prev, pager, next"
        />
      </div>
    </ElCard>

    <!-- 30天续费经营评估 -->
    <ElDrawer v-model="evalDlg.visible" size="620px" title="30天续费经营评估">
      <template v-if="evalDlg.row">
        <p class="text-sm mb-3">
          评估对象：<span class="font-600">{{ evalDlg.row.name }}</span>
          <span
            v-if="evalDlg.row.evalScore !== null && evalDlg.row.evalScore !== undefined"
            class="ml-2 text-xs text-gray-400"
            >上次：{{ evalDlg.row.evalScore }}分（{{ evalDlg.row.evalAt }}）</span
          >
        </p>
        <ElAlert
          title="系统数据自动计分，人工只判断系统无法读取的服务与沟通信号"
          type="info"
          :closable="false"
          class="mb-3"
        />
        <div v-loading="evalDlg.loading" class="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-4">
          <div class="rounded-md bg-gray-50 dark:bg-gray-800 p-2 text-center">
            <div class="text-lg font-600">{{ evalContext?.attendanceCount ?? '-' }}</div>
            <div class="text-xs text-gray-400">近30天签到</div>
          </div>
          <div class="rounded-md bg-gray-50 dark:bg-gray-800 p-2 text-center">
            <div class="text-lg font-600">{{ evalContext?.remainTimes ?? '—' }}</div>
            <div class="text-xs text-gray-400">剩余课时</div>
          </div>
          <div class="rounded-md bg-gray-50 dark:bg-gray-800 p-2 text-center">
            <div class="text-sm font-600">{{ evalContext?.expireDate ?? '—' }}</div>
            <div class="text-xs text-gray-400">卡项到期</div>
          </div>
          <div class="rounded-md bg-gray-50 dark:bg-gray-800 p-2 text-center">
            <div class="text-sm font-600">
              {{
                evalContext
                  ? `${evalContext.attendM1}/${evalContext.attendM2}/${evalContext.attendM3}`
                  : '-'
              }}
            </div>
            <div class="text-xs text-gray-400">三月出勤</div>
          </div>
        </div>
        <div v-for="dim in EVAL_DIMENSIONS" :key="dim.key" class="mb-4">
          <div class="text-sm font-500 mb-1">{{ dim.title }}</div>
          <div v-if="dim.hint" class="text-xs text-gray-400 mb-1.5">{{ dim.hint }}</div>
          <ElRadioGroup v-model="evalAnswers[dim.key]" class="flex flex-col items-start">
            <ElRadio v-for="op in dim.options" :key="op.key" :value="op.key">
              {{ op.label }}（{{ op.score > 0 ? '+' : '' }}{{ op.score }}）
            </ElRadio>
          </ElRadioGroup>
        </div>
        <div class="mb-4">
          <div class="text-sm font-500 mb-1">风险减分项（可多选）</div>
          <ElCheckboxGroup v-model="evalAnswers.risks" class="flex flex-col items-start">
            <ElCheckbox v-for="risk in EVAL_RISKS" :key="risk.key" :value="risk.key">
              {{ risk.label }}（{{ risk.score }}）
            </ElCheckbox>
          </ElCheckboxGroup>
        </div>
        <ElFormItem label="评估备注">
          <ElInput
            v-model="evalRemark"
            type="textarea"
            :rows="2"
            placeholder="记录主要信号、客户顾虑和建议动作"
          />
        </ElFormItem>
        <div
          class="sticky bottom-0 bg-white dark:bg-[#1a1a1a] pt-3 pb-1 border-t border-gray-100 dark:border-gray-700 flex items-center justify-between"
        >
          <div>
            <span
              class="text-2xl font-600"
              :class="
                evalTotal >= 70
                  ? 'text-green-600'
                  : evalTotal >= 40
                    ? 'text-orange-500'
                    : 'text-red-500'
              "
              >{{ evalTotal }}</span
            >
            <span class="text-xs text-gray-400 ml-2"
              >分 · {{ renewalLevelLabel(renewalLevel(evalTotal)) }}</span
            >
          </div>
          <div class="flex gap-2">
            <span class="text-xs self-center text-gray-400">保存后自动生成下一步任务</span>
            <ElButton type="primary" :loading="evalDlg.saving" @click="saveEval"
              >保存并生成任务</ElButton
            >
          </div>
        </div>
      </template>
    </ElDrawer>

    <!-- 续课计划登记（教练月度预报） -->
    <ElDialog
      v-model="renewalDlg.visible"
      title="续课计划登记（月度会预报口径）"
      width="520px"
      destroy-on-close
    >
      <ElForm label-width="92px">
        <ElFormItem label="续课意愿">
          <ElSelect v-model="renewalDlg.form.intent">
            <ElOption label="确认续课" value="确认续课" />
            <ElOption label="有意向待跟进" value="有意向待跟进" />
            <ElOption label="无法续课（填原因）" value="无法续课" />
          </ElSelect>
        </ElFormItem>
        <ElRow :gutter="10">
          <ElCol :span="8"
            ><ElFormItem label="预计时间"
              ><ElInput v-model="renewalDlg.form.time" placeholder="如：9月中" /></ElFormItem
          ></ElCol>
          <ElCol :span="8"
            ><ElFormItem label="预计金额"
              ><ElInput v-model="renewalDlg.form.amount" placeholder="如：¥6800" /></ElFormItem
          ></ElCol>
          <ElCol :span="8"
            ><ElFormItem label="续课课种"><ElInput v-model="renewalDlg.form.course" /></ElFormItem
          ></ElCol>
        </ElRow>
        <ElFormItem label="客户问题"
          ><ElInput
            v-model="renewalDlg.form.issue"
            type="textarea"
            :rows="2"
            placeholder="客户问题与诉求（首周只确认不解决，下次课处理）"
        /></ElFormItem>
        <ElAlert
          title="流程提醒：首周由管理层每日提醒出勤并确认意愿，问题解决后再由教练执行续课"
          type="info"
          :closable="false"
        />
      </ElForm>
      <template #footer>
        <ElButton @click="renewalDlg.visible = false">取消</ElButton>
        <ElButton type="primary" @click="saveRenewal">保存预报</ElButton>
      </template>
    </ElDialog>

    <!-- 出勤降低处置 -->
    <ElDialog v-model="declineDlg.visible" title="出勤降低处置" width="480px" destroy-on-close>
      <ElForm label-width="92px">
        <ElFormItem label="下降原因">
          <ElRadioGroup v-model="declineDlg.form.reason">
            <ElRadio value="训练意愿降低">训练意愿降低</ElRadio>
            <ElRadio value="工作生活节奏变化">工作生活节奏变化</ElRadio>
          </ElRadioGroup>
        </ElFormItem>
        <ElFormItem label="解决方案"
          ><ElInput v-model="declineDlg.form.solution" type="textarea" :rows="2"
        /></ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="declineDlg.visible = false">取消</ElButton>
        <ElButton type="primary" @click="saveDecline">保存处置</ElButton>
      </template>
    </ElDialog>

    <!-- 待复活沟通记录 -->
    <ElDialog
      v-model="touchDlg.visible"
      title="待复活沟通记录（不低于每2周一次有效传递）"
      width="480px"
      destroy-on-close
    >
      <ElForm label-width="92px">
        <ElFormItem label="预期复活"
          ><ElDatePicker
            v-model="touchDlg.form.expectedReturn"
            type="date"
            value-format="YYYY-MM-DD"
            class="!w-full"
        /></ElFormItem>
        <ElFormItem label="需协助"
          ><ElSwitch v-model="touchDlg.form.needsHelp" /><span class="ml-2 text-xs text-gray-400"
            >预期复活1个月内的会员周会100%检查</span
          ></ElFormItem
        >
      </ElForm>
      <template #footer>
        <ElButton @click="touchDlg.visible = false">取消</ElButton>
        <ElButton type="primary" @click="saveTouch">记录本次沟通</ElButton>
      </template>
    </ElDialog>

    <!-- 阈值设置 -->
    <ElDialog v-model="rulesDlg" title="清单规则阈值（保存后实时重算）" width="480px">
      <ElForm label-width="140px">
        <ElFormItem label="待续课阈值(节)"
          ><ElInputNumber v-model="rulesForm.renewalThreshold" :min="1" :max="50" /><span
            class="ml-2 text-xs text-gray-400"
            >剩余课时 ≤ 该值且最近月有出勤</span
          ></ElFormItem
        >
        <ElFormItem label="VIP阈值(元)"
          ><ElInputNumber
            v-model="rulesForm.vipAmountThreshold"
            :min="1000"
            :max="1000000"
            :step="1000"
          /><span class="ml-2 text-xs text-gray-400">会员卡实收金额 ≥ 该值</span></ElFormItem
        >
        <ElFormItem label="出勤下降口径">
          <ElRadioGroup v-model="rulesForm.declineMode">
            <ElRadio value="strict">连续三月递减</ElRadio>
            <ElRadio value="recent">最近两月下降</ElRadio>
          </ElRadioGroup>
        </ElFormItem>
        <ElFormItem label="预流失(天)">
          <div class="flex items-center gap-2">
            <ElInputNumber v-model="rulesForm.predropMin" :min="1" :max="rulesForm.predropMax" />
            <span class="text-gray-400">~</span>
            <ElInputNumber v-model="rulesForm.predropMax" :min="rulesForm.predropMin" :max="180" />
          </div>
          <div class="text-xs text-gray-400 mt-1">或：上月在训、本月停训（M2&gt;0 且 M3=0）</div>
        </ElFormItem>
        <ElFormItem label="待复活(天)"
          ><ElInputNumber v-model="rulesForm.reviveDays" :min="7" :max="365" /><span
            class="ml-2 text-xs text-gray-400"
            >超过 N 天未到店且有卡项资产</span
          ></ElFormItem
        >
      </ElForm>
      <template #footer>
        <ElButton @click="rulesDlg = false">取消</ElButton>
        <ElButton type="primary" @click="applyRules">保存规则</ElButton>
      </template>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import {
    queryCustomers,
    getMemberRules,
    setMemberRules,
    refreshMemberRules,
    computeMemberLists,
    updateMemberFields,
    queryLeads,
    matchConsultants,
    EVAL_DIMENSIONS,
    EVAL_RISKS,
    evalTotalScore,
    getRenewalEvaluation,
    saveRenewalEvaluation,
    renewalLevel,
    renewalLevelLabel
  } from '@/api/yimai'
  import type {
    YimaiCustomer,
    MemberListKey,
    YimaiLead,
    RenewalEvaluationAnswers,
    RenewalEvaluationContext
  } from '@/api/yimai'
  import { useUserStore } from '@/store/modules/user'
  import { ElMessage, ElTag } from 'element-plus'

  defineOptions({ name: 'YimaiMembers' })

  const userStore = useUserStore()
  const roles = computed(() => userStore.getUserInfo.roles ?? [])
  const isTeacher = computed(() => roles.value.includes('R_TEACHER'))
  const isSuper = computed(() => roles.value.includes('R_SUPER'))
  const isManager = computed(() => roles.value.includes('R_MANAGER'))
  /** 数据范围说明：随当前门店/清单/顾问筛选动态显示 */
  const scopeHint = computed(() => {
    const venue = searchForm.value.venue || userStore.getUserInfo.venue || '双店'
    const parts = [`数据范围：${venue}`]
    if (activeTab.value !== 'all') parts.push(`清单：${TAB_KEY_TO_LIST[activeTab.value]}`)
    else if (searchForm.value.list) parts.push(`清单：${searchForm.value.list}`)
    if (searchForm.value.consultant) parts.push(`顾问：${searchForm.value.consultant}`)
    return parts.join(' · ')
  })

  function formatMoney(v: number): string {
    return Number(v).toLocaleString('zh-CN')
  }

  const LIST_KEYS: MemberListKey[] = ['待续课', '出勤降低', 'VIP', '预流失', '待复活']
  const EVALUATION_STATUSES = ['未评估', '高机会', '重点培育', '风险修复', '已过期']
  const TAB_KEY_TO_LIST: Record<string, MemberListKey> = {
    renewal: '待续课',
    decline: '出勤降低',
    vip: 'VIP',
    predrop: '预流失',
    revive: '待复活'
  }
  const TABS: {
    key: string
    label: string
    tag?: 'danger' | 'warning' | 'success' | 'info' | 'primary'
  }[] = [
    { key: 'all', label: '总览' },
    { key: 'renewal', label: '待续课', tag: 'danger' },
    { key: 'decline', label: '出勤降低', tag: 'warning' },
    { key: 'vip', label: 'VIP', tag: 'success' },
    { key: 'predrop', label: '预流失', tag: 'primary' },
    { key: 'revive', label: '待复活', tag: 'info' }
  ]

  const loading = ref(false)
  const activeTab = ref('all')
  const searchForm = ref({
    name: '',
    phone: '',
    list: '',
    consultant: '',
    venue: '',
    evaluationStatus: ''
  })
  const page = ref({ current: 1, size: 20 })
  const all = ref<YimaiCustomer[]>([])
  const rules = ref(getMemberRules())

  watch(activeTab, (tab) => {
    if (tab !== 'all') searchForm.value.list = ''
    load()
  })

  /** 会籍顾问下拉选项：来自在册会员（含手机号匹配留资）的去重会籍顾问名单 */
  const consultantOptions = computed<string[]>(() => {
    const set = new Set<string>()
    for (const c of all.value) {
      const name = (c.consultant ?? '').trim()
      if (name) set.add(name)
    }
    return [...set].sort((a, b) => a.localeCompare(b, 'zh'))
  })

  function memberLists(row: YimaiCustomer): MemberListKey[] {
    return computeMemberLists(row)
  }

  function declining(row: YimaiCustomer): boolean {
    return memberLists(row).includes('出勤降低')
  }

  function listType(l: string): 'danger' | 'warning' | 'success' | 'info' | 'primary' {
    if (l === '待续课') return 'danger'
    if (l === '出勤降低') return 'warning'
    if (l === 'VIP') return 'success'
    if (l === '预流失') return 'primary'
    return 'info'
  }

  const listCounts = computed<Record<string, number>>(() => {
    const out: Record<string, number> = {}
    for (const k of LIST_KEYS)
      out[k] = filteredBase().filter((c) =>
        computeMemberLists(c).includes(k as MemberListKey)
      ).length
    return out
  })

  function filteredBase(): YimaiCustomer[] {
    let list = all.value
    if (isTeacher.value) {
      // 老师在总览看自己的；五清单页签同样只看自己名下
      list = list.filter(
        (c) =>
          c.owner === userStore.getUserInfo.userName ||
          c.consultant === userStore.getUserInfo.userName
      )
    }
    if (activeTab.value !== 'all') {
      list = list.filter((c) => computeMemberLists(c).includes(TAB_KEY_TO_LIST[activeTab.value]))
    }
    return list
  }

  const filteredList = computed(() => {
    let list = filteredBase()
    if (searchForm.value.name) list = list.filter((c) => c.name.includes(searchForm.value.name))
    if (searchForm.value.phone) {
      const p = searchForm.value.phone
      list = list.filter((c) => (c.phone ?? '').includes(p) || (c.phoneTail ?? '').includes(p))
    }
    if (searchForm.value.venue) list = list.filter((c) => c.venue === searchForm.value.venue)
    if (searchForm.value.consultant) {
      const target = searchForm.value.consultant
      list = list.filter((c) => (c.consultant || '待分配') === target)
    }
    if (searchForm.value.evaluationStatus === '未评估')
      list = list.filter((c) => c.evalScore == null)
    if (searchForm.value.evaluationStatus === '高机会')
      list = list.filter((c) => c.evalLevel === 'high')
    if (searchForm.value.evaluationStatus === '重点培育')
      list = list.filter((c) => c.evalLevel === 'medium')
    if (searchForm.value.evaluationStatus === '风险修复')
      list = list.filter((c) => c.evalLevel === 'low')
    if (searchForm.value.evaluationStatus === '已过期') list = list.filter(evaluationExpired)
    return list
  })

  const pagedList = computed(() =>
    filteredList.value.slice(
      (page.value.current - 1) * page.value.size,
      page.value.current * page.value.size
    )
  )

  async function load() {
    loading.value = true
    try {
      page.value.current = 1
      await refreshMemberRules()
      rules.value = getMemberRules()
      const listFilter = searchForm.value.list
        ? (searchForm.value.list as MemberListKey)
        : undefined
      const [res, leadsRes] = await Promise.all([
        queryCustomers({
          name: searchForm.value.name,
          phone: searchForm.value.phone,
          list: listFilter,
          venue: searchForm.value.venue || undefined,
          consultant: searchForm.value.consultant || undefined,
          evaluationStatus: searchForm.value.evaluationStatus || undefined,
          type: 'member',
          current: 1,
          size: 5000
        }),
        queryLeads({ current: 1, size: 5000 }).catch(() => ({ records: [] as YimaiLead[] }))
      ])
      all.value = matchConsultants(res.records ?? [], leadsRes.records ?? [])
    } finally {
      loading.value = false
    }
  }

  function daysAgo(date: string | null): number {
    if (!date) return 9999
    return Math.floor((Date.now() - new Date(date).getTime()) / 86400000)
  }

  function daysAgo2(date: string): number {
    return Math.floor((Date.now() - new Date(date).getTime()) / 86400000)
  }

  function touchOverdue(row: YimaiCustomer): boolean {
    if (!row.lastTouch) return true
    return daysAgo2(row.lastTouch) > 14
  }

  function actionOverdue(row: YimaiCustomer): boolean {
    if (!row.nextActionTime) return false
    return new Date(row.nextActionTime.replace(' ', 'T')).getTime() < Date.now()
  }

  function evaluationExpired(row: YimaiCustomer): boolean {
    return Boolean(row.evalAt) && daysAgo2(row.evalAt!) > 30
  }

  function evaluationColor(score: number): string {
    return score >= 70 ? 'text-green-600' : score >= 40 ? 'text-orange-500' : 'text-red-500'
  }

  function evaluationTag(score: number): 'success' | 'warning' | 'danger' {
    return score >= 70 ? 'success' : score >= 40 ? 'warning' : 'danger'
  }

  // ---------- 续课计划 ----------
  const renewalDlg = reactive({
    visible: false,
    row: null as YimaiCustomer | null,
    form: { intent: '确认续课', time: '', amount: '', course: '', issue: '' }
  })

  function openRenewal(row: YimaiCustomer) {
    renewalDlg.row = row
    renewalDlg.form = {
      intent: row.renewalPlan?.intent ?? '确认续课',
      time: row.renewalPlan?.time ?? '',
      amount: row.renewalPlan?.amount ?? '',
      course: row.renewalPlan?.course ?? '',
      issue: row.renewalPlan?.issue ?? ''
    }
    renewalDlg.visible = true
  }

  async function saveRenewal() {
    if (!renewalDlg.row) return
    await updateMemberFields(renewalDlg.row.id, { renewalPlan: { ...renewalDlg.form } }, '续课预报')
    renewalDlg.visible = false
    ElMessage.success('已保存，进入首周「先确认不销售」流程')
  }

  // ---------- 出勤降低处置 ----------
  const declineDlg = reactive({
    visible: false,
    row: null as YimaiCustomer | null,
    form: { reason: '训练意愿降低', solution: '' }
  })

  function openDecline(row: YimaiCustomer) {
    declineDlg.row = row
    declineDlg.form = {
      reason: row.decline?.reason ?? '训练意愿降低',
      solution: row.decline?.solution ?? ''
    }
    declineDlg.visible = true
  }

  async function saveDecline() {
    if (!declineDlg.row) return
    await updateMemberFields(declineDlg.row.id, { decline: { ...declineDlg.form } }, '下降处置')
    declineDlg.visible = false
    ElMessage.success('已保存')
  }

  // ---------- 待复活沟通 ----------
  const touchDlg = reactive({
    visible: false,
    row: null as YimaiCustomer | null,
    form: { expectedReturn: '', needsHelp: false }
  })

  function openTouch(row: YimaiCustomer) {
    touchDlg.row = row
    touchDlg.form = { expectedReturn: row.expectedReturn ?? '', needsHelp: row.needsHelp ?? false }
    touchDlg.visible = true
  }

  async function saveTouch() {
    if (!touchDlg.row) return
    const today = new Date().toISOString().slice(0, 10)
    await updateMemberFields(
      touchDlg.row.id,
      {
        expectedReturn: touchDlg.form.expectedReturn,
        needsHelp: touchDlg.form.needsHelp,
        lastTouch: today
      },
      '复活跟进'
    )
    touchDlg.visible = false
    ElMessage.success('已记录，14天后需再次有效触达')
  }

  // ---------- 预流失转待复活 ----------
  async function toRevive(row: YimaiCustomer) {
    await updateMemberFields(
      row.id,
      { inRevive: true, lastTouch: new Date().toISOString().slice(0, 10) },
      '转待复活'
    )
    ElMessage.success(`${row.name} 已转入待复活清单`)
    load()
  }

  // ---------- 30天评估 ----------
  const evalDlg = reactive<{
    visible: boolean
    row: YimaiCustomer | null
    loading: boolean
    saving: boolean
  }>({
    visible: false,
    row: null,
    loading: false,
    saving: false
  })
  const evalAnswers = reactive<RenewalEvaluationAnswers>({
    goal: 'none',
    feedback: 'none',
    wechat: 'no_response',
    intent: 'none',
    service: 'normal',
    risks: []
  })
  const evalContext = ref<RenewalEvaluationContext | null>(null)
  const evalRemark = ref('')

  async function openEval(row: YimaiCustomer) {
    evalDlg.row = row
    evalDlg.visible = true
    evalDlg.loading = true
    try {
      evalContext.value = await getRenewalEvaluation(row.id)
      const latest = evalContext.value.latest
      Object.assign(
        evalAnswers,
        latest?.answers ?? {
          goal: 'none',
          feedback: 'none',
          wechat: 'no_response',
          intent: 'none',
          service: 'normal',
          risks: []
        }
      )
      evalAnswers.attendanceCount = evalContext.value.attendanceCount
      evalAnswers.cardWindow = evalContext.value.cardWindow
      evalRemark.value = latest?.remark ?? ''
    } catch (error) {
      ElMessage.error(String((error as { message?: string }).message ?? error).slice(0, 120))
    } finally {
      evalDlg.loading = false
    }
  }

  const evalTotal = computed(() => evalTotalScore(evalAnswers))

  async function saveEval() {
    if (!evalDlg.row) return
    evalDlg.saving = true
    try {
      const result = await saveRenewalEvaluation(evalDlg.row.id, {
        answers: { ...evalAnswers, risks: [...evalAnswers.risks] },
        remark: evalRemark.value
      })
      evalDlg.visible = false
      ElMessage.success(
        `评估已保存，已生成任务「${result.task.title}」，负责人：${result.task.owner}`
      )
      await load()
    } finally {
      evalDlg.saving = false
    }
  }

  // ---------- 阈值 ----------
  const rulesDlg = ref(false)
  const rulesForm = reactive({ ...getMemberRules() })

  watch(rulesDlg, (v) => {
    if (v) Object.assign(rulesForm, getMemberRules())
  })

  async function applyRules() {
    await setMemberRules({ ...rulesForm })
    rules.value = getMemberRules()
    rulesDlg.value = false
    load()
    ElMessage.success('规则已更新，清单实时重算')
  }

  onMounted(load)
</script>
