<template>
  <ElCard shadow="never" :class="variant === 'full' ? '' : 'mb-4'">
    <template #header>
      <div class="flex-cb">
        <div class="flex flex-wrap items-center gap-2">
          <span class="font-500">今日待办</span>
          <ElTag size="small" effect="plain">{{ todo?.date ?? todayIso() }}</ElTag>
          <span class="text-xs text-gray-400">
            {{
              variant === 'full'
                ? '逐项处理：标记后当天消隐并自动流转（留资状态/最近触达）'
                : '今日预约会员 + 需要服务的客户汇总'
            }}
          </span>
        </div>
        <div class="flex items-center gap-2">
          <span v-if="todo?.generatedAt" class="text-xs text-gray-400"
            >生成于 {{ todo.generatedAt.slice(11) }}</span
          >
          <ElButton link type="primary" :loading="loading" @click="reload">刷新</ElButton>
        </div>
      </div>
      <div class="mt-1 text-xs text-gray-400">
        清单阈值（随「会员管理 → 调整标签阈值」实时同步）：待续费 {{ renewalRuleText }} · 预流失
        {{ activeRules.predropMin }}-{{ activeRules.predropMax }} 天未到店 · 待复活 &gt;{{
          activeRules.reviveDays
        }}
        天 · VIP 实收 ≥{{ formatMoney(activeRules.vipAmountThreshold) }}
        元
      </div>
    </template>

    <!-- 概要模式（工作台）：分组统计 + 待处理样例，点击进入完整待办页 -->
    <div v-if="variant === 'summary'" v-loading="loading" class="min-h-20">
      <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-3">
        <div
          v-for="tab in visibleTabs"
          :key="tab.key"
          class="cursor-pointer rounded-lg bg-gray-50 dark:bg-gray-800 p-3 text-center transition hover:bg-gray-100 dark:hover:bg-gray-700"
          @click="$router.push({ path: '/yimai/todo', query: { group: tab.key } })"
        >
          <div
            class="text-2xl font-600"
            :class="pendingCounts[tab.key] > 0 ? 'text-primary' : 'text-gray-300'"
          >
            {{ pendingCounts[tab.key] }}
          </div>
          <div class="mt-0.5 text-xs text-gray-500">{{ tab.label }}</div>
          <div class="mt-1 h-4 truncate text-xs text-gray-400">
            {{ pendingNames(tab.key) || '暂无' }}
          </div>
          <div v-if="doneCounts[tab.key] > 0" class="text-xs text-green-500">
            已处理 {{ doneCounts[tab.key] }}
          </div>
        </div>
      </div>
    </div>

    <!-- 完整模式（客户经营 → 今日待办）：分组列表 + 逐项操作 -->
    <ElTabs v-else v-model="activeTab">
      <ElTabPane v-for="tab in visibleTabs" :key="tab.key" :name="tab.key">
        <template #label>
          <ElBadge
            :value="pendingCounts[tab.key]"
            :max="99"
            :hidden="!pendingCounts[tab.key]"
            type="danger"
          >
            <span class="px-1">{{ tab.label }}</span>
          </ElBadge>
        </template>

        <div v-loading="loading" class="min-h-24">
          <!-- 今日预约 -->
          <template v-if="tab.key === 'bookings'">
            <div
              v-for="b in shownBy('bookings', pendingItems(bookings))"
              :key="b.key"
              class="todo-item flex items-center gap-3 py-2.5"
            >
              <span class="w-11 shrink-0 text-sm font-600 tabular-nums text-primary">{{
                b.time || '--:--'
              }}</span>
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-1.5">
                  <span class="font-500">{{ b.memberName || '未留会员名' }}</span>
                  <span class="text-xs tabular-nums text-gray-500">{{ b.phone || '' }}</span>
                  <ElTag v-if="b.birthdayToday" size="small" type="danger" effect="dark" round
                    >🎂 今日生日</ElTag
                  >
                  <ElTag v-for="flag in b.lists" :key="flag" size="small" :type="flagType(flag)">{{
                    flag
                  }}</ElTag>
                </div>
                <div class="mt-0.5 truncate text-xs text-gray-500">
                  {{ b.course || '课程未命名' }} · {{ b.teacher || '老师待定' }} · {{ b.venue }}
                </div>
              </div>
              <ElTag size="small" :type="b.isTrial ? 'warning' : statusType(b.status)">
                {{ b.isTrial ? '体验课' : statusLabel(b.status) }}
              </ElTag>
              <TodoActions
                :actions="actionMenus.bookings"
                @pick="(a) => onMark('bookings', b, a)"
              />
            </div>
          </template>

          <!-- 待续费 -->
          <template v-else-if="tab.key === 'renewals'">
            <div
              v-for="c in shownBy('renewals', pendingItems(renewals))"
              :key="c.key"
              class="todo-item flex items-center gap-3 py-2.5"
            >
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-1.5">
                  <span class="font-500">{{ c.name }}</span>
                  <span class="text-xs tabular-nums text-gray-500">{{ c.phone || '' }}</span>
                  <ElTag v-if="c.urgent" size="small" type="danger" effect="dark">7天内到期</ElTag>
                  <ElTag
                    v-for="flag in c.lists.filter((f) => f !== '待续课')"
                    :key="flag"
                    size="small"
                    :type="flagType(flag)"
                    >{{ flag }}</ElTag
                  >
                </div>
                <div class="mt-0.5 truncate text-xs text-gray-500">
                  {{ c.mainCard }} · {{ c.expireDate ? `${c.expireDate} 到期` : '到期日未知' }} ·
                  {{ c.consultant || c.owner }} ·
                  {{
                    c.hasRenewalPlan ? '已建续费计划，按方案推进' : '建议：今天到店时当面推进续费'
                  }}
                </div>
              </div>
              <div class="shrink-0 text-right text-xs">
                <div
                  :class="
                    (c.remainTimes ?? 99) <= (activeRules.renewalThreshold ?? 10)
                      ? 'text-red-500 font-600'
                      : 'text-gray-500'
                  "
                >
                  剩余 {{ c.remainTimes ?? '-' }} 节
                </div>
                <div v-if="c.expireDays !== null" class="text-gray-400">
                  {{ c.expireDays <= 0 ? '今日到期' : `剩 ${c.expireDays} 天` }}
                </div>
              </div>
              <TodoActions
                :actions="actionMenus.renewals"
                @pick="(a) => onMark('renewals', c, a)"
              />
            </div>
          </template>

          <!-- 流失风险 -->
          <template v-else-if="tab.key === 'churnRisks'">
            <div
              v-for="c in shownBy('churnRisks', pendingItems(churnRisks))"
              :key="c.key"
              class="todo-item flex items-center gap-3 py-2.5"
            >
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-1.5">
                  <span class="font-500">{{ c.name }}</span>
                  <span class="text-xs tabular-nums text-gray-500">{{ c.phone || '' }}</span>
                  <ElTag v-for="flag in c.lists" :key="flag" size="small" :type="flagType(flag)">{{
                    flag
                  }}</ElTag>
                  <ElTag v-if="c.needsHelp" size="small" type="danger">需协助</ElTag>
                  <ElTag v-if="c.evalLevel === 'low'" size="small" type="danger" effect="plain"
                    >评估低分</ElTag
                  >
                </div>
                <div class="mt-0.5 truncate text-xs text-gray-500">
                  {{ c.consultant || c.owner }} ·
                  {{
                    c.stopReason
                      ? `停练原因：${c.stopReason}`
                      : '建议：预约一次当面沟通，重建训练节奏'
                  }}
                </div>
              </div>
              <div
                class="shrink-0 text-right text-xs"
                :class="(c.lastVisitDays ?? 0) > 30 ? 'text-red-500 font-600' : 'text-orange-500'"
              >
                {{ c.lastVisitDays !== null ? `${c.lastVisitDays}天未到店` : '无到店记录' }}
              </div>
              <TodoActions
                :actions="actionMenus.churnRisks"
                @pick="(a) => onMark('churnRisks', c, a)"
              />
            </div>
          </template>

          <!-- 生日关怀 -->
          <template v-else-if="tab.key === 'birthdays'">
            <div
              v-for="c in shownBy('birthdays', pendingItems(birthdays))"
              :key="c.key"
              class="todo-item flex items-center gap-3 py-2.5"
            >
              <span class="shrink-0 text-lg">🎂</span>
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-1.5">
                  <span class="font-500">{{ c.name }}</span>
                  <span class="text-xs tabular-nums text-gray-500">{{ c.phone || '' }}</span>
                  <ElTag v-if="c.isToday" size="small" type="danger" effect="dark">今天生日</ElTag>
                  <ElTag v-else size="small" type="warning" effect="plain"
                    >{{ c.daysLater }}天后生日</ElTag
                  >
                  <ElTag v-for="flag in c.lists" :key="flag" size="small" :type="flagType(flag)">{{
                    flag
                  }}</ElTag>
                </div>
                <div class="mt-0.5 truncate text-xs text-gray-500">
                  {{ c.birthday }} · 满 {{ c.age }} 岁 · {{ c.venue }} ·
                  {{ c.consultant || c.owner }}
                </div>
              </div>
              <div class="shrink-0 text-xs text-gray-400">
                {{ c.isToday ? '送祝福 + 到店礼' : '提前安排生日关怀' }}
              </div>
              <TodoActions
                :actions="actionMenus.birthdays"
                @pick="(a) => onMark('birthdays', c, a)"
              />
            </div>
          </template>

          <!-- 体验课 -->
          <template v-else-if="tab.key === 'trials'">
            <div
              v-for="t in shownBy('trials', pendingItems(trials))"
              :key="t.key"
              class="todo-item flex items-center gap-3 py-2.5"
            >
              <span class="w-11 shrink-0 text-sm font-600 tabular-nums text-primary">{{
                t.time || '--:--'
              }}</span>
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-1.5">
                  <span class="font-500">{{ t.name }}</span>
                  <span class="text-xs tabular-nums text-gray-500">{{ t.phone || '' }}</span>
                  <ElTag
                    size="small"
                    :type="t.source === 'ky' ? 'primary' : 'warning'"
                    effect="plain"
                  >
                    {{ t.source === 'ky' ? '随心瑜预约' : '留资登记' }}
                  </ElTag>
                </div>
                <div class="mt-0.5 truncate text-xs text-gray-500">
                  {{ t.topic || '体验主题待定' }} · {{ t.teacher || '老师待定' }} · {{ t.venue }}
                </div>
              </div>
              <ElTag size="small" :type="statusType(t.status)">{{ statusLabel(t.status) }}</ElTag>
              <TodoActions :actions="actionMenus.trials" @pick="(a) => onMark('trials', t, a)" />
            </div>
          </template>

          <!-- 新客首响 -->
          <template v-else-if="tab.key === 'newLeads'">
            <div
              v-for="l in shownBy('newLeads', pendingItems(newLeads))"
              :key="l.key"
              class="todo-item flex items-center gap-3 py-2.5"
            >
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-1.5">
                  <span class="font-500">{{ l.name }}</span>
                  <span class="text-xs tabular-nums text-gray-500">{{ l.phone || '' }}</span>
                  <ElTag v-if="l.stale" size="small" type="danger" effect="dark"
                    >超24小时未首响</ElTag
                  >
                  <ElTag v-if="l.grade" size="small" type="warning">{{ l.grade }} 级</ElTag>
                </div>
                <div class="mt-0.5 truncate text-xs text-gray-500">
                  {{ l.source }} · {{ l.venue }} · {{ l.leadDate }} ·
                  {{ l.serviceTeacher || '待分配服务老师' }}
                </div>
              </div>
              <div class="shrink-0 text-xs text-gray-400">{{ l.demand || '需求待补充' }}</div>
              <TodoActions
                :actions="actionMenus.newLeads"
                @pick="(a) => onMark('newLeads', l, a)"
              />
            </div>
          </template>

          <!-- 今日任务 -->
          <template v-else-if="tab.key === 'tasks'">
            <div
              v-for="t in shownBy('tasks', tasks)"
              :key="t.id"
              class="todo-item flex items-center gap-3 py-2.5"
            >
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-1.5">
                  <span class="font-500">{{ t.title }}</span>
                  <span class="text-xs text-gray-400">{{ t.customerName }}</span>
                  <ElTag v-if="t.overdue" size="small" type="danger" effect="dark">已逾期</ElTag>
                </div>
                <div class="mt-0.5 truncate text-xs text-gray-500">
                  {{ t.venue }} · 负责人 {{ t.owner }} · 截止 {{ t.deadline || '未设' }}
                </div>
              </div>
              <ElTag
                size="small"
                :type="t.priority === '高' ? 'danger' : t.priority === '中' ? 'warning' : 'info'"
              >
                {{ t.priority }}
              </ElTag>
              <ElTag size="small" :type="t.status === '待验收' ? 'success' : 'info'">{{
                t.status
              }}</ElTag>
            </div>
            <div v-if="counts.tasks > 0" class="pt-2 text-right">
              <ElButton link type="primary" @click="$router.push('/yimai/tasks')"
                >进入任务中心处理</ElButton
              >
            </div>
          </template>

          <ElEmpty
            v-if="!loading && pendingCounts[tab.key] === 0 && doneCounts[tab.key] === 0"
            :description="emptyText[tab.key]"
            :image-size="60"
          />
          <div v-else-if="pendingCounts[tab.key] > visibleCount" class="pt-2 text-center">
            <ElButton link type="primary" @click="expanded[tab.key] = !expanded[tab.key]">
              {{ expanded[tab.key] ? '收起' : `展开全部 ${pendingCounts[tab.key]} 条` }}
            </ElButton>
          </div>
          <div v-if="doneCounts[tab.key] > 0" class="pt-1 text-center text-xs text-gray-400">
            <ElButton link size="small" @click="showDone[tab.key] = !showDone[tab.key]">
              {{
                showDone[tab.key] ? '收起已处理' : `已处理 ${doneCounts[tab.key]} 条（点击展开）`
              }}
            </ElButton>
          </div>
          <template v-if="showDone[tab.key]">
            <div
              v-for="row in doneRows(tab.key)"
              :key="row.key"
              class="todo-item flex items-center gap-3 py-2 opacity-60"
            >
              <ElTag size="small" type="success" effect="plain">{{ row.doneAction }}</ElTag>
              <div class="min-w-0 flex-1 truncate text-xs text-gray-500">
                {{ row.name || row.memberName }} · {{ row.doneBy }} {{ row.doneAt }}
                <span v-if="row.doneRemark">· {{ row.doneRemark }}</span>
              </div>
            </div>
          </template>
          <div
            v-if="
              tab.key !== 'bookings' &&
              tab.key !== 'trials' &&
              tab.key !== 'newLeads' &&
              tab.key !== 'tasks' &&
              pendingCounts[tab.key] + doneCounts[tab.key] > 0
            "
            class="pt-1 text-right"
          >
            <ElButton link type="primary" @click="$router.push('/yimai/members')"
              >进入会员管理</ElButton
            >
          </div>
        </div>
      </ElTabPane>
    </ElTabs>

    <!-- 续课计划登记（从今日待办流转） -->
    <ElDialog
      v-model="planDlg.visible"
      title="续课计划登记（保存后自动标记待办已处理）"
      width="480px"
      destroy-on-close
    >
      <ElForm label-width="92px">
        <ElFormItem label="会员">
          <span class="font-500">{{ planDlg.row?.name || planDlg.row?.memberName }}</span>
        </ElFormItem>
        <ElFormItem label="续课意愿">
          <ElSelect v-model="planDlg.form.intent">
            <ElOption label="确认续课" value="确认续课" />
            <ElOption label="有意向待跟进" value="有意向待跟进" />
            <ElOption label="无法续课（填原因）" value="无法续课" />
          </ElSelect>
        </ElFormItem>
        <ElRow :gutter="10">
          <ElCol :span="8"
            ><ElFormItem label="预计时间"
              ><ElInput v-model="planDlg.form.time" placeholder="如：9月中" /></ElFormItem
          ></ElCol>
          <ElCol :span="8"
            ><ElFormItem label="预计金额"
              ><ElInput v-model="planDlg.form.amount" placeholder="如：¥6800" /></ElFormItem
          ></ElCol>
          <ElCol :span="8"
            ><ElFormItem label="续课课种"><ElInput v-model="planDlg.form.course" /></ElFormItem
          ></ElCol>
        </ElRow>
        <ElFormItem label="客户问题"
          ><ElInput v-model="planDlg.form.issue" type="textarea" :rows="2"
        /></ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="planDlg.visible = false">取消</ElButton>
        <ElButton type="primary" :loading="planDlg.saving" @click="savePlan"
          >保存并标记已处理</ElButton
        >
      </template>
    </ElDialog>

    <!-- 下降处置（从今日待办流转） -->
    <ElDialog
      v-model="declineDlg.visible"
      title="出勤降低处置（保存后自动标记待办已处理）"
      width="480px"
      destroy-on-close
    >
      <ElForm label-width="92px">
        <ElFormItem label="会员">
          <span class="font-500">{{ declineDlg.row?.name }}</span>
        </ElFormItem>
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
        <ElButton type="primary" :loading="declineDlg.saving" @click="saveDecline"
          >保存并标记已处理</ElButton
        >
      </template>
    </ElDialog>
  </ElCard>
</template>

<script setup lang="ts">
  import {
    getTodayTodo,
    getMemberRules,
    refreshMemberRules,
    markTodoAction,
    updateMemberFields,
    type TodayTodo,
    type TodayTodoBookingItem,
    type TodayTodoRenewalItem,
    type TodayTodoChurnItem,
    type TodayTodoBirthdayItem,
    type TodayTodoTrialItem,
    type TodayTodoLeadItem,
    type TodayTodoTaskItem,
    type MemberRules
  } from '@/api/yimai'
  import { useUserStore } from '@/store/modules/user'
  import { ElMessage } from 'element-plus'
  import TodoActions from './todo-actions.vue'

  defineOptions({ name: 'YimaiTodayTodo' })

  const props = withDefaults(
    defineProps<{
      /** summary=工作台概要卡（点击进完整页）；full=完整待办页（默认） */
      variant?: 'summary' | 'full'
      /** 完整模式初始分组（来自 /yimai/todo?group=xxx） */
      initialGroup?: string
    }>(),
    { variant: 'full', initialGroup: '' }
  )

  type TabKey =
    | 'bookings'
    | 'renewals'
    | 'churnRisks'
    | 'birthdays'
    | 'trials'
    | 'newLeads'
    | 'tasks'

  interface TodoRow {
    key: string
    id?: number
    name?: string
    memberName?: string
    done?: boolean
    doneAction?: string
    doneBy?: string
    doneAt?: string
    doneRemark?: string
    customerId?: number | null
  }

  const userStore = useUserStore()
  const isMedia = computed(() => (userStore.getUserInfo.roles ?? []).includes('R_MEDIA'))

  const loading = ref(true)
  const todo = ref<TodayTodo | null>(null)
  const activeTab = ref<TabKey>('bookings')
  const visibleCount = 8
  const expanded = reactive<Record<string, boolean>>({})
  const showDone = reactive<Record<string, boolean>>({})
  // 当前生效的清单阈值：优先取本次聚合结果携带的口径（后端/本地一致），展示与会员管理同步
  const activeRules = ref<MemberRules>(getMemberRules())

  function formatMoney(v: number): string {
    return Number(v).toLocaleString('zh-CN')
  }

  /** 待续费阈值摘要：次卡节数/占比 + 到期天数（+ 有效期占比，启用时） */
  const renewalRuleText = computed(() => {
    const r = activeRules.value
    const parts = [`次卡剩余 ≤${r.renewalThreshold ?? 10} 节`]
    if ((r.renewalCountPercent ?? 0) > 0) parts.push(`占比 ≤${r.renewalCountPercent}%`)
    parts.push(`到期 ≤${r.renewalExpireDays ?? 30} 天`)
    if ((r.renewalExpirePercent ?? 0) > 0)
      parts.push(`时间卡有效期剩余 ≤${r.renewalExpirePercent}%`)
    return parts.join(' 或 ')
  })

  const allTabs: { key: TabKey; label: string }[] = [
    { key: 'bookings', label: '今日预约' },
    { key: 'renewals', label: '待续费' },
    { key: 'churnRisks', label: '流失风险' },
    { key: 'birthdays', label: '生日关怀' },
    { key: 'trials', label: '体验课' },
    { key: 'newLeads', label: '新客首响' },
    { key: 'tasks', label: '今日任务' }
  ]
  const visibleTabs = computed(() =>
    isMedia.value ? allTabs.filter((t) => ['newLeads', 'trials'].includes(t.key)) : allTabs
  )

  const bookings = computed<TodayTodoBookingItem[]>(() => todo.value?.bookings.items ?? [])
  const renewals = computed<TodayTodoRenewalItem[]>(() => todo.value?.renewals ?? [])
  const churnRisks = computed<TodayTodoChurnItem[]>(() => todo.value?.churnRisks ?? [])
  const birthdays = computed<TodayTodoBirthdayItem[]>(() => todo.value?.birthdays ?? [])
  const trials = computed<TodayTodoTrialItem[]>(() => todo.value?.trials ?? [])
  const newLeads = computed<TodayTodoLeadItem[]>(() => todo.value?.newLeads ?? [])
  const tasks = computed<TodayTodoTaskItem[]>(() => todo.value?.tasks ?? [])

  const groupRows = computed<Record<TabKey, TodoRow[]>>(() => ({
    bookings: bookings.value as unknown as TodoRow[],
    renewals: renewals.value as unknown as TodoRow[],
    churnRisks: churnRisks.value as unknown as TodoRow[],
    birthdays: birthdays.value as unknown as TodoRow[],
    trials: trials.value as unknown as TodoRow[],
    newLeads: newLeads.value as unknown as TodoRow[],
    tasks: tasks.value as unknown as TodoRow[]
  }))

  const pendingItems = <T extends { done?: boolean }>(rows: T[]): T[] => rows.filter((r) => !r.done)
  const doneRows = (group: TabKey): TodoRow[] =>
    (groupRows.value[group] ?? []).filter((r) => r.done)

  const counts = computed<Record<TabKey, number>>(() => {
    const c = todo.value?.counts
    return {
      bookings: c?.bookings ?? 0,
      renewals: c?.renewals ?? 0,
      churnRisks: c?.churnRisks ?? 0,
      birthdays: c?.birthdays ?? 0,
      trials: c?.trials ?? 0,
      newLeads: c?.newLeads ?? 0,
      tasks: c?.tasks ?? 0
    }
  })

  /** 待处理数 = 总数 - 今日已标记处理 */
  const pendingCounts = computed<Record<TabKey, number>>(() => {
    const out = {} as Record<TabKey, number>
    for (const tab of allTabs) {
      const rows = groupRows.value[tab.key] ?? []
      out[tab.key] = rows.length ? rows.filter((r) => !r.done).length : (counts.value[tab.key] ?? 0)
    }
    return out
  })
  const doneCounts = computed<Record<TabKey, number>>(() => {
    const out = {} as Record<TabKey, number>
    for (const tab of allTabs) {
      out[tab.key] = (groupRows.value[tab.key] ?? []).filter((r) => r.done).length
    }
    return out
  })

  const pendingNames = (group: TabKey): string =>
    pendingItems(groupRows.value[group] ?? [])
      .slice(0, 2)
      .map((r) => r.name || r.memberName || '')
      .filter(Boolean)
      .join('、')

  const emptyText: Record<TabKey, string> = {
    bookings: '今天暂无预约课程',
    renewals: '暂无待续费会员',
    churnRisks: '暂无流失风险会员',
    birthdays: '近期没有会员生日',
    trials: '今天暂无体验课',
    newLeads: '暂无待首响新客资',
    tasks: '今天没有到期任务'
  }

  /** 每组可执行的动作：action=文案；flow=额外业务流转（打开对应表单/更新留资状态） */
  const actionMenus: Record<
    string,
    { action: string; flow?: 'plan' | 'decline' | 'revive' | 'leadContact' | 'leadLost' }[]
  > = {
    bookings: [{ action: '已接待' }, { action: '已沟通' }],
    renewals: [
      { action: '已沟通待跟进' },
      { action: '登记续课计划', flow: 'plan' },
      { action: '暂不续费' }
    ],
    churnRisks: [
      { action: '已沟通待跟进' },
      { action: '登记下降处置', flow: 'decline' },
      { action: '转待复活', flow: 'revive' }
    ],
    birthdays: [{ action: '已送祝福' }, { action: '已邀约到店' }],
    trials: [{ action: '已接待' }, { action: '爽约' }],
    newLeads: [
      { action: '已首响', flow: 'leadContact' },
      { action: '无效客资', flow: 'leadLost' }
    ],
    tasks: []
  }

  function shownBy<T>(key: TabKey, rows: T[]): T[] {
    return expanded[key] ? rows : rows.slice(0, visibleCount)
  }

  function statusLabel(status: string): string {
    return (
      (
        {
          booked: '已约',
          signed: '已签到',
          cancelled: '已取消',
          no_show: '爽约',
          unknown: '待确认'
        } as Record<string, string>
      )[status] ?? status
    )
  }
  function statusType(status: string): 'success' | 'primary' | 'info' | 'danger' {
    if (status === 'signed') return 'success'
    if (status === 'cancelled' || status === 'no_show') return 'danger'
    if (status === 'unknown') return 'info'
    return 'primary'
  }
  function flagType(flag: string): 'danger' | 'warning' | 'success' | 'info' | 'primary' {
    if (['待续课', '出勤降低'].includes(flag)) return 'warning'
    if (['预流失', '待复活', '评估低分', '需协助', '7天内到期'].includes(flag)) return 'danger'
    if (flag === 'VIP') return 'success'
    return 'info'
  }

  function todayIso(): string {
    const now = new Date()
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
  }

  async function doMark(group: TabKey, row: TodoRow, action: string): Promise<void> {
    // 今日任务有独立状态机，走任务中心处理，不在待办页标记
    if (group === 'tasks') return
    const menu = (actionMenus[group] ?? []).find((m) => m.action === action)
    if (menu?.flow === 'plan') {
      planDlg.row = row
      planDlg.form = { intent: '确认续课', time: '', amount: '', course: '', issue: '' }
      planDlg.visible = true

      return
    }
    if (menu?.flow === 'decline') {
      declineDlg.row = row
      declineDlg.form = { reason: '训练意愿降低', solution: '' }
      declineDlg.visible = true

      return
    }
    try {
      await markTodoAction({
        type: group,
        key: row.key,
        action,
        customerId:
          group === 'renewals' || group === 'churnRisks' ? row.id : (row.customerId ?? null),
        leadId: group === 'newLeads' ? row.id : null,
        touch: group === 'renewals' || group === 'churnRisks'
      })
      ElMessage.success(`已标记：${action}`)
      await reload()
    } catch (e) {
      console.error('[today-todo.mark]', e)
      ElMessage.error('标记失败，请稍后重试')
    }
  }

  function onMark(group: TabKey, row: TodoRow, action: string): void {
    void doMark(group, row, action)
  }

  // 续课计划 / 下降处置 弹窗
  const planDlg = reactive({
    visible: false,
    saving: false,
    row: null as TodoRow | null,
    form: { intent: '确认续课', time: '', amount: '', course: '', issue: '' }
  })
  const declineDlg = reactive({
    visible: false,
    saving: false,
    row: null as TodoRow | null,
    form: { reason: '训练意愿降低', solution: '' }
  })

  async function savePlan(): Promise<void> {
    const row = planDlg.row
    if (!row?.id) return
    planDlg.saving = true
    try {
      await updateMemberFields(row.id, { renewalPlan: { ...planDlg.form } }, '续课预报')
      await markTodoAction({
        type: 'renewals',
        key: row.key,
        action: '已建续费计划',
        customerId: row.id
      })
      planDlg.visible = false
      ElMessage.success('已保存续课计划，待办已标记处理')
      await reload()
    } catch (e) {
      console.error('[today-todo.savePlan]', e)
      ElMessage.error('保存失败，请稍后重试')
    } finally {
      planDlg.saving = false
    }
  }

  async function saveDecline(): Promise<void> {
    const row = declineDlg.row
    if (!row?.id) return
    declineDlg.saving = true
    try {
      await updateMemberFields(row.id, { decline: { ...declineDlg.form } }, '下降处置')
      await markTodoAction({
        type: 'churnRisks',
        key: row.key,
        action: '已建下降处置',
        customerId: row.id,
        touch: true
      })
      declineDlg.visible = false
      ElMessage.success('已保存处置，待办已标记处理')
      await reload()
    } catch (e) {
      console.error('[today-todo.saveDecline]', e)
      ElMessage.error('保存失败，请稍后重试')
    } finally {
      declineDlg.saving = false
    }
  }

  async function reload(): Promise<void> {
    loading.value = true
    try {
      // 先拉最新清单阈值，保证待续费/流失风险等分组与「会员管理 → 调整标签阈值」同口径
      await refreshMemberRules().catch(() => {})
      activeRules.value = getMemberRules()
      todo.value = await getTodayTodo()
      if (todo.value?.rules) {
        activeRules.value = todo.value.rules
      }
      // 完整页尊重 initialGroup；否则默认落在第一个有待处理的分组
      const wanted = props.initialGroup as TabKey | ''
      const wantedValid = wanted && visibleTabs.value.some((t) => t.key === wanted)
      if (wantedValid) {
        activeTab.value = wanted as TabKey
      } else {
        const firstNonEmpty = visibleTabs.value.find((t) => pendingCounts.value[t.key] > 0)
        const current = activeTab.value
        const currentPending = visibleTabs.value.some(
          (t) => t.key === current && pendingCounts.value[t.key] > 0
        )
        if (!current || (!currentPending && firstNonEmpty)) {
          activeTab.value = firstNonEmpty?.key ?? visibleTabs.value[0]?.key ?? 'bookings'
        }
      }
    } finally {
      loading.value = false
    }
  }

  onMounted(reload)
</script>

<style scoped lang="scss">
  .todo-item {
    border-bottom: 1px solid var(--el-border-color-lighter);

    &:last-of-type {
      border-bottom: 0;
    }
  }
</style>
