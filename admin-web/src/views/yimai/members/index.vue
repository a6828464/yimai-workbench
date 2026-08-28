<template>
  <div class="member-page art-full-height">
    <ElCard class="art-table-card !h-auto">
      <!-- Tab 导航 -->
      <ElTabs v-model="activeTab" class="mb-3">
        <ElTabPane v-for="t in TABS" :key="t.key" :name="t.key">
          <template #label>
            <span class="flex items-center gap-1.5">
              {{ t.label }}
              <ElTag v-if="t.key !== 'all'" size="small" :type="t.tag" effect="plain">{{ listCounts[TAB_KEY_TO_LIST[t.key]] ?? 0 }}</ElTag>
            </span>
          </template>
        </ElTabPane>
      </ElTabs>

      <!-- 规则设置 -->
      <div class="mb-3 flex flex-wrap items-center gap-3 text-xs text-gray-400">
        <span>{{ scopeHint }}</span>
        <ElButton link type="primary" size="small" @click="rulesDlg = true">调整续费/流失阈值</ElButton>
      </div>

      <!-- 总览筛选 -->
      <div v-if="activeTab === 'all'" class="mb-3 flex flex-wrap items-center gap-3">
        <ElInput v-model="searchForm.name" placeholder="会员姓名" clearable class="!w-40" @change="load" />
        <ElSelect v-model="searchForm.venue" placeholder="门店" clearable class="!w-28" @change="load">
          <ElOption label="绿地店" value="绿地店" />
          <ElOption label="东部店" value="东部店" />
        </ElSelect>
        <ElSelect v-model="searchForm.list" placeholder="运营清单" clearable class="!w-36" @change="load">
          <ElOption v-for="k in LIST_KEYS" :key="k" :label="k" :value="k" />
        </ElSelect>
        <ElSelect v-model="searchForm.consultant" placeholder="会籍顾问" clearable class="!w-36" @change="load">
          <ElOption value="待分配" label="待分配" />
          <ElOption v-for="c in consultantOptions" :key="c" :label="c" :value="c" />
        </ElSelect>
        <ElButton type="primary" plain @click="load">查询</ElButton>
      </div>

      <ArtTableHeader :columns="[]" :loading="loading">
        <template #left><span class="text-sm text-gray-400">{{ scopeHint }}</span></template>
      </ArtTableHeader>

      <ElTable v-loading="loading" :data="pagedList" border stripe max-height="520">
        <ElTableColumn label="会员" min-width="130" fixed="left">
          <template #default="{ row }">
            <div class="font-500">{{ row.name }}</div>
            <div class="text-xs text-gray-400">{{ row.phone || '尾号' + row.phoneTail }}{{ row.source ? ` · ${row.source}` : '' }}</div>
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
              <ElTag v-for="l in memberLists(row)" :key="l" size="small" effect="dark" :type="listType(l)">{{ l }}</ElTag>
              <span v-if="!memberLists(row).length" class="text-xs text-gray-300">—</span>
            </div>
          </template>
        </ElTableColumn>

        <!-- 出勤三列：所有清单通用展示 -->
        <ElTableColumn label="M1/M2/M3出勤" width="120" align="center">
          <template #default="{ row }">
            <span :class="declining(row) ? 'font-500 text-red-500' : ''">
              {{ row.attendM1 ?? '-' }} / {{ row.attendM2 ?? '-' }} / {{ row.attendM3 ?? '-' }}
            </span>
          </template>
        </ElTableColumn>

        <ElTableColumn label="剩余课时" width="90" align="center">
          <template #default="{ row }">
            <span :class="row.remainTimes !== null && row.remainTimes < rules.renewalThreshold ? 'font-500 text-red-500' : ''">
              {{ row.remainTimes ?? '—' }}
            </span>
          </template>
        </ElTableColumn>

        <ElTableColumn label="累计购买" width="90" align="center">
          <template #default="{ row }">{{ row.totalPurchased ?? '—' }}</template>
        </ElTableColumn>

        <!-- 清单专属列 -->
        <template v-if="activeTab === 'renewal'">
          <ElTableColumn label="续课计划" min-width="220">
            <template #default="{ row }">
              <div v-if="row.renewalPlan?.time">
                <div>{{ row.renewalPlan.intent }} · {{ row.renewalPlan.time }} · {{ row.renewalPlan.amount }}</div>
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
                {{ row.decline.reason }}<template v-if="row.decline.solution"> · {{ row.decline.solution }}</template>
              </div>
              <span v-else class="text-xs text-orange-500">待管理层确认原因</span>
            </template>
          </ElTableColumn>
        </template>

        <template v-if="activeTab === 'predrop'">
          <ElTableColumn label="停训情况" min-width="180">
            <template #default="{ row }">
              <div>{{ row.stopReason || '停课原因待回访确认' }}</div>
              <div class="text-xs text-gray-400">最近到店：{{ daysAgo(row.lastVisit) }}天前{{ row.expectedReturn ? ` · 预期复活 ${row.expectedReturn}` : '' }}</div>
            </template>
          </ElTableColumn>
        </template>

        <template v-if="activeTab === 'revive'">
          <ElTableColumn label="复活跟进" min-width="200">
            <template #default="{ row }">
              <div>预期复活：{{ row.expectedReturn || '未确认' }}</div>
              <div class="text-xs" :class="touchOverdue(row) ? 'text-red-500 font-500' : 'text-gray-400'">
                最近沟通：{{ row.lastTouch || '从未' }}{{ row.lastTouch ? `（${daysAgo2(row.lastTouch)}天前）` : '' }} · 需协助
                <ElTag v-if="row.needsHelp" size="small" type="danger">是</ElTag>
              </div>
            </template>
          </ElTableColumn>
        </template>

        <ElTableColumn label="操作" width="230" fixed="right">
          <template #default="{ row }">
            <ElButton link type="primary" size="small" @click="openEval(row)">续费评估</ElButton>
            <ElButton v-if="memberLists(row).includes('待续课')" link type="warning" size="small" @click="openRenewal(row)">续课计划</ElButton>
            <ElButton v-if="memberLists(row).includes('出勤降低')" link type="info" size="small" @click="openDecline(row)">下降处置</ElButton>
            <ElButton v-if="memberLists(row).includes('预流失') && !row.inRevive" link type="success" size="small" @click="toRevive(row)">转待复活</ElButton>
            <ElButton v-if="memberLists(row).includes('待复活')" link type="primary" size="small" @click="openTouch(row)">记录沟通</ElButton>
          </template>
        </ElTableColumn>
      </ElTable>

      <div class="mt-4 flex justify-end">
        <ElPagination v-model:current-page="page.current" v-model:page-size="page.size" :page-sizes="[10, 20, 50, 100]" :total="filteredList.length" layout="total, sizes, prev, pager, next" />
      </div>
    </ElCard>

    <!-- 30天续费评估 -->
    <ElDrawer v-model="evalDlg.visible" size="560px" title="30天续费评估（仅统计最近30天）">
      <template v-if="evalDlg.row">
        <p class="text-sm mb-3">
          评估对象：<span class="font-600">{{ evalDlg.row.name }}</span>
          <span v-if="evalDlg.row.evalScore !== null && evalDlg.row.evalScore !== undefined" class="ml-2 text-xs text-gray-400">上次：{{ evalDlg.row.evalScore }}分（{{ evalDlg.row.evalAt }}）</span>
        </p>
        <div v-for="(dim, di) in EVAL_DIMENSIONS" :key="dim.key" class="mb-4">
          <div class="text-sm font-500 mb-1">{{ dim.title }}</div>
          <div v-if="dim.hint" class="text-xs text-gray-400 mb-1.5">{{ dim.hint }}</div>
          <ElCheckboxGroup v-model="evalAnswers[dim.key]">
            <ElCheckbox v-for="(op, oi) in dim.options" :key="oi" :value="oi">{{ op.label }}（{{ op.score > 0 ? '+' : '' }}{{ op.score }}）</ElCheckbox>
          </ElCheckboxGroup>
        </div>
        <div class="sticky bottom-0 bg-white dark:bg-[#1a1a1a] pt-3 pb-1 border-t border-gray-100 dark:border-gray-700 flex items-center justify-between">
          <div>
            <span class="text-2xl font-600" :class="evalTotal >= 70 ? 'text-green-600' : evalTotal >= 40 ? 'text-orange-500' : 'text-red-500'">{{ evalTotal }}</span>
            <span class="text-xs text-gray-400 ml-2">分 · 规则分非真实概率</span>
          </div>
          <div class="flex gap-2">
            <span class="text-xs self-center text-gray-400">≥70高概率 · 40-69中等 · &lt;40低概率</span>
            <ElButton type="primary" @click="saveEval">保存评估</ElButton>
          </div>
        </div>
      </template>
    </ElDrawer>

    <!-- 续课计划登记（教练月度预报） -->
    <ElDialog v-model="renewalDlg.visible" title="续课计划登记（月度会预报口径）" width="520px" destroy-on-close>
      <ElForm label-width="92px">
        <ElFormItem label="续课意愿">
          <ElSelect v-model="renewalDlg.form.intent">
            <ElOption label="确认续课" value="确认续课" />
            <ElOption label="有意向待跟进" value="有意向待跟进" />
            <ElOption label="无法续课（填原因）" value="无法续课" />
          </ElSelect>
        </ElFormItem>
        <ElRow :gutter="10">
          <ElCol :span="8"><ElFormItem label="预计时间"><ElInput v-model="renewalDlg.form.time" placeholder="如：9月中" /></ElFormItem></ElCol>
          <ElCol :span="8"><ElFormItem label="预计金额"><ElInput v-model="renewalDlg.form.amount" placeholder="如：¥6800" /></ElFormItem></ElCol>
          <ElCol :span="8"><ElFormItem label="续课课种"><ElInput v-model="renewalDlg.form.course" /></ElFormItem></ElCol>
        </ElRow>
        <ElFormItem label="客户问题"><ElInput v-model="renewalDlg.form.issue" type="textarea" :rows="2" placeholder="客户问题与诉求（首周只确认不解决，下次课处理）" /></ElFormItem>
        <ElAlert title="流程提醒：首周由管理层每日提醒出勤并确认意愿，问题解决后再由教练执行续课" type="info" :closable="false" />
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
        <ElFormItem label="解决方案"><ElInput v-model="declineDlg.form.solution" type="textarea" :rows="2" /></ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="declineDlg.visible = false">取消</ElButton>
        <ElButton type="primary" @click="saveDecline">保存处置</ElButton>
      </template>
    </ElDialog>

    <!-- 待复活沟通记录 -->
    <ElDialog v-model="touchDlg.visible" title="待复活沟通记录（不低于每2周一次有效传递）" width="480px" destroy-on-close>
      <ElForm label-width="92px">
        <ElFormItem label="预期复活"><ElDatePicker v-model="touchDlg.form.expectedReturn" type="date" value-format="YYYY-MM-DD" class="!w-full" /></ElFormItem>
        <ElFormItem label="需协助"><ElSwitch v-model="touchDlg.form.needsHelp" /><span class="ml-2 text-xs text-gray-400">预期复活1个月内的会员周会100%检查</span></ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="touchDlg.visible = false">取消</ElButton>
        <ElButton type="primary" @click="saveTouch">记录本次沟通</ElButton>
      </template>
    </ElDialog>

    <!-- 阈值设置 -->
    <ElDialog v-model="rulesDlg" title="清单规则阈值" width="420px">
      <ElForm label-width="120px">
        <ElFormItem label="待续课阈值(节)"><ElInputNumber v-model="rulesForm.renewalThreshold" :min="1" :max="50" /></ElFormItem>
        <ElFormItem label="VIP阈值(节)"><ElInputNumber v-model="rulesForm.vipThreshold" :min="10" :max="500" /></ElFormItem>
        <ElFormItem label="出勤下降口径">
          <ElRadioGroup v-model="rulesForm.declineMode">
            <ElRadio value="strict">连续三月递减</ElRadio>
            <ElRadio value="recent">最近两月下降</ElRadio>
          </ElRadioGroup>
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="rulesDlg = false">取消</ElButton>
        <ElButton type="primary" @click="applyRules">保存规则</ElButton>
      </template>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import { queryCustomers, getMemberRules, setMemberRules, refreshMemberRules, computeMemberLists, updateMemberFields, queryLeads, matchConsultants, EVAL_DIMENSIONS, evalTotalScore } from '@/api/yimai'
  import type { YimaiCustomer, MemberListKey, YimaiLead } from '@/api/yimai'
  import { useUserStore } from '@/store/modules/user'
  import { ElMessage, ElTag } from 'element-plus'

  defineOptions({ name: 'YimaiMembers' })

  const userStore = useUserStore()
  const roles = computed(() => userStore.getUserInfo.roles ?? [])
  const isTeacher = computed(() => roles.value.includes('R_TEACHER'))
  const scopeHint = computed(() => {
    if (isTeacher.value) return `数据范围：我的会员（${userStore.getUserInfo.venue ?? '双店'}）`
    return `数据范围：${userStore.getUserInfo.venue ?? '双店'}`
  })

  const LIST_KEYS: MemberListKey[] = ['待续课', '出勤降低', 'VIP', '预流失', '待复活']
  const TAB_KEY_TO_LIST: Record<string, MemberListKey> = {
    renewal: '待续课',
    decline: '出勤降低',
    vip: 'VIP',
    predrop: '预流失',
    revive: '待复活'
  }
  const TABS: { key: string; label: string; tag?: 'danger' | 'warning' | 'success' | 'info' | 'primary' }[] = [
    { key: 'all', label: '总览' },
    { key: 'renewal', label: '待续课', tag: 'danger' },
    { key: 'decline', label: '出勤降低', tag: 'warning' },
    { key: 'vip', label: 'VIP', tag: 'success' },
    { key: 'predrop', label: '预流失', tag: 'primary' },
    { key: 'revive', label: '待复活', tag: 'info' }
  ]

  const loading = ref(false)
  const activeTab = ref('all')
  const searchForm = ref({ name: '', list: '', consultant: '', venue: '' })
  const page = ref({ current: 1, size: 20 })
  const all = ref<YimaiCustomer[]>([])
  const rules = ref(getMemberRules())

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
    for (const k of LIST_KEYS) out[k] = filteredBase().filter((c) => computeMemberLists(c).includes(k as MemberListKey)).length
    return out
  })

  function filteredBase(): YimaiCustomer[] {
    let list = all.value
    if (isTeacher.value) {
      // 老师在总览看自己的；五清单页签同样只看自己名下
      if (activeTab.value !== 'all') list = list.filter((c) => c.owner === userStore.getUserInfo.userName || c.consultant === userStore.getUserInfo.userName)
    }
    if (activeTab.value !== 'all') {
      list = list.filter((c) => computeMemberLists(c).includes(TAB_KEY_TO_LIST[activeTab.value]))
    }
    return list
  }

  const filteredList = computed(() => {
    let list = filteredBase()
    if (searchForm.value.name) list = list.filter((c) => c.name.includes(searchForm.value.name))
    if (searchForm.value.venue) list = list.filter((c) => c.venue === searchForm.value.venue)
    if (searchForm.value.consultant) {
      const target = searchForm.value.consultant
      list = list.filter((c) => (c.consultant || '待分配') === target)
    }
    return list
  })

  const pagedList = computed(() =>
    filteredList.value.slice((page.value.current - 1) * page.value.size, page.value.current * page.value.size)
  )

  async function load() {
    loading.value = true
    try {
      page.value.current = 1
      await refreshMemberRules()
      rules.value = getMemberRules()
      const listFilter = searchForm.value.list ? (searchForm.value.list as MemberListKey) : undefined
      const [res, leadsRes] = await Promise.all([
        queryCustomers({
          name: searchForm.value.name,
          list: listFilter,
          venue: searchForm.value.venue || undefined,
          consultant: searchForm.value.consultant || undefined,
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

  function saveRenewal() {
    if (!renewalDlg.row) return
    updateMemberFields(renewalDlg.row.id, { renewalPlan: { ...renewalDlg.form } }, '续课预报')
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
    declineDlg.form = { reason: row.decline?.reason ?? '训练意愿降低', solution: row.decline?.solution ?? '' }
    declineDlg.visible = true
  }

  function saveDecline() {
    if (!declineDlg.row) return
    updateMemberFields(declineDlg.row.id, { decline: { ...declineDlg.form } }, '下降处置')
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

  function saveTouch() {
    if (!touchDlg.row) return
    const today = new Date().toISOString().slice(0, 10)
    updateMemberFields(touchDlg.row.id, { expectedReturn: touchDlg.form.expectedReturn, needsHelp: touchDlg.form.needsHelp, lastTouch: today }, '复活跟进')
    touchDlg.visible = false
    ElMessage.success('已记录，14天后需再次有效触达')
  }

  // ---------- 预流失转待复活 ----------
  function toRevive(row: YimaiCustomer) {
    updateMemberFields(row.id, { inRevive: true, lastTouch: new Date().toISOString().slice(0, 10) }, '转待复活')
    ElMessage.success(`${row.name} 已转入待复活清单`)
    load()
  }

  // ---------- 30天评估 ----------
  const evalDlg = reactive<{ visible: boolean; row: YimaiCustomer | null }>({ visible: false, row: null })
  const evalAnswers = reactive<Record<string, number[]>>({})

  function openEval(row: YimaiCustomer) {
    evalDlg.row = row
    Object.keys(evalAnswers).forEach((k) => delete evalAnswers[k])
    EVAL_DIMENSIONS.forEach((d) => (evalAnswers[d.key] = []))
    evalDlg.visible = true
  }

  const evalTotal = computed(() => evalTotalScore(evalAnswers))

  function saveEval() {
    if (!evalDlg.row) return
    updateMemberFields(
      evalDlg.row.id,
      { evalScore: evalTotal.value, evalAt: new Date().toISOString().slice(0, 10) },
      '续费评估'
    )
    evalDlg.visible = false
    ElMessage.success(`已保存：${evalDlg.row.name} 评估 ${evalTotal.value} 分`)
    load()
  }

  // ---------- 阈值 ----------
  const rulesDlg = ref(false)
  const rulesForm = reactive({ ...getMemberRules() })

  watch(rulesDlg, (v) => {
    if (v) Object.assign(rulesForm, getMemberRules())
  })

  function applyRules() {
    setMemberRules({ ...rulesForm })
    rules.value = getMemberRules()
    rulesDlg.value = false
    load()
    ElMessage.success('规则已更新，清单实时重算')
  }

  onMounted(load)
</script>
