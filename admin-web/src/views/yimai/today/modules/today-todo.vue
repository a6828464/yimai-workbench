<template>
  <ElCard shadow="never" class="mb-4">
    <template #header>
      <div class="flex-cb">
        <div class="flex flex-wrap items-center gap-2">
          <span class="font-500">今日待办</span>
          <ElTag size="small" effect="plain">{{ todo?.date ?? todayIso() }}</ElTag>
          <span class="text-xs text-gray-400">今日预约会员 + 需要服务的客户汇总</span>
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

    <ElTabs v-model="activeTab">
      <ElTabPane v-for="tab in visibleTabs" :key="tab.key" :name="tab.key">
        <template #label>
          <ElBadge :value="counts[tab.key]" :max="99" :hidden="!counts[tab.key]" type="danger">
            <span class="px-1">{{ tab.label }}</span>
          </ElBadge>
        </template>

        <div v-loading="loading" class="min-h-24">
          <!-- 今日预约 -->
          <template v-if="tab.key === 'bookings'">
            <div
              v-for="b in shownBy('bookings', bookings)"
              :key="b.id"
              class="todo-item flex items-center gap-3 py-2.5"
            >
              <span class="w-11 shrink-0 text-sm font-600 tabular-nums text-primary">{{
                b.time || '--:--'
              }}</span>
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-1.5">
                  <span class="font-500">{{ b.memberName || '未留会员名' }}</span>
                  <span v-if="b.phoneTail" class="text-xs text-gray-400"
                    >尾号{{ b.phoneTail }}</span
                  >
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
            </div>
          </template>

          <!-- 待续费 -->
          <template v-else-if="tab.key === 'renewals'">
            <div
              v-for="c in shownBy('renewals', renewals)"
              :key="c.id"
              class="todo-item flex items-center gap-3 py-2.5"
            >
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-1.5">
                  <span class="font-500">{{ c.name }}</span>
                  <span class="text-xs text-gray-400">尾号{{ c.phoneTail }}</span>
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
                  :class="(c.remainTimes ?? 99) <= 5 ? 'text-red-500 font-600' : 'text-gray-500'"
                >
                  剩余 {{ c.remainTimes ?? '-' }} 节
                </div>
                <div v-if="c.expireDays !== null" class="text-gray-400">
                  {{ c.expireDays <= 0 ? '今日到期' : `剩 ${c.expireDays} 天` }}
                </div>
              </div>
            </div>
          </template>

          <!-- 流失风险 -->
          <template v-else-if="tab.key === 'churnRisks'">
            <div
              v-for="c in shownBy('churnRisks', churnRisks)"
              :key="c.id"
              class="todo-item flex items-center gap-3 py-2.5"
            >
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-1.5">
                  <span class="font-500">{{ c.name }}</span>
                  <span class="text-xs text-gray-400">尾号{{ c.phoneTail }}</span>
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
            </div>
          </template>

          <!-- 生日关怀 -->
          <template v-else-if="tab.key === 'birthdays'">
            <div
              v-for="c in shownBy('birthdays', birthdays)"
              :key="c.id"
              class="todo-item flex items-center gap-3 py-2.5"
            >
              <span class="shrink-0 text-lg">🎂</span>
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-1.5">
                  <span class="font-500">{{ c.name }}</span>
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
            </div>
          </template>

          <!-- 体验课 -->
          <template v-else-if="tab.key === 'trials'">
            <div
              v-for="t in shownBy('trials', trials)"
              :key="t.key"
              class="todo-item flex items-center gap-3 py-2.5"
            >
              <span class="w-11 shrink-0 text-sm font-600 tabular-nums text-primary">{{
                t.time || '--:--'
              }}</span>
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-1.5">
                  <span class="font-500">{{ t.name }}</span>
                  <span v-if="t.phoneTail" class="text-xs text-gray-400"
                    >尾号{{ t.phoneTail }}</span
                  >
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
            </div>
          </template>

          <!-- 新客首响 -->
          <template v-else-if="tab.key === 'newLeads'">
            <div
              v-for="l in shownBy('newLeads', newLeads)"
              :key="l.id"
              class="todo-item flex items-center gap-3 py-2.5"
            >
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-1.5">
                  <span class="font-500">{{ l.name }}</span>
                  <span class="text-xs text-gray-400">尾号{{ l.phoneTail }}</span>
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
          </template>

          <ElEmpty
            v-if="!loading && counts[tab.key] === 0"
            :description="emptyText[tab.key]"
            :image-size="60"
          />
          <div v-else-if="counts[tab.key] > visibleCount" class="pt-2 text-center">
            <ElButton link type="primary" @click="expanded[tab.key] = !expanded[tab.key]">
              {{ expanded[tab.key] ? '收起' : `展开全部 ${counts[tab.key]} 条` }}
            </ElButton>
          </div>
          <div
            v-if="
              tab.key !== 'bookings' &&
              tab.key !== 'trials' &&
              tab.key !== 'newLeads' &&
              tab.key !== 'tasks' &&
              counts[tab.key] > 0
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
  </ElCard>
</template>

<script setup lang="ts">
  import {
    getTodayTodo,
    getMemberRules,
    refreshMemberRules,
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

  defineOptions({ name: 'YimaiTodayTodo' })

  type TabKey =
    | 'bookings'
    | 'renewals'
    | 'churnRisks'
    | 'birthdays'
    | 'trials'
    | 'newLeads'
    | 'tasks'

  const userStore = useUserStore()
  const isMedia = computed(() => (userStore.getUserInfo.roles ?? []).includes('R_MEDIA'))

  const loading = ref(true)
  const todo = ref<TodayTodo | null>(null)
  const activeTab = ref<TabKey>('bookings')
  const visibleCount = 8
  const expanded = reactive<Record<string, boolean>>({})
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

  const emptyText: Record<TabKey, string> = {
    bookings: '今天暂无预约课程',
    renewals: '暂无待续费会员',
    churnRisks: '暂无流失风险会员',
    birthdays: '近期没有会员生日',
    trials: '今天暂无体验课',
    newLeads: '暂无待首响新客资',
    tasks: '今天没有到期任务'
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

  async function reload() {
    loading.value = true
    try {
      // 先拉最新清单阈值，保证待续费/流失风险等分组与「会员管理 → 调整标签阈值」同口径
      await refreshMemberRules().catch(() => {})
      activeRules.value = getMemberRules()
      todo.value = await getTodayTodo()
      if (todo.value?.rules) {
        activeRules.value = todo.value.rules
      }
      // 默认落在第一个有待办的分组
      const firstNonEmpty = visibleTabs.value.find((t) => counts.value[t.key] > 0)
      const current = activeTab.value
      const currentEmpty = !visibleTabs.value.some(
        (t) => t.key === current && counts.value[t.key] > 0
      )
      if (!current || (currentEmpty && firstNonEmpty)) {
        activeTab.value = firstNonEmpty?.key ?? visibleTabs.value[0]?.key ?? 'bookings'
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
