<script setup lang="ts">
/**
 * 今日待办（八组合并视图）
 *
 * 读：GET /today/todo → {date, rules, bookings:{items,trialCount}, renewals[], churnRisks[],
 *                        birthdays[], trials[], reviews[], newLeads[], tasks[]}
 * 行内点击：进会员 360 / 留资 / 课后分析 / 任务中心（见 openCustomer 的四档分流）
 * 写：POST /today/todo/action（type / key / action / customerId / leadId / touch）
 *     注意 type 白名单不含 tasks —— 任务状态只能走 TaskController::update
 *
 * 视觉层（2026-11 重构）：每组一张 ym-section 卡，行内动作换 wd-button，
 * 三态（骨架 / 空 / 失败）。分组、动作分支、touch 语义全部原样保留。
 */
import { computed, ref } from 'vue'
import { onLoad, onShow, onPullDownRefresh } from '@dcloudio/uni-app'
import { requireLogin } from '@/utils/guard'
// goPath：跳转统一收口（自动判 switchTab / navigateTo），页面里不直接调 uni.navigateTo
import { goPath, requireFeature } from '@/utils/nav'
// canUse：点行进客户 360 之前先按同一份注册表挡一次。
// members 属 OPS_ROLES（不含新媒体），如果让新媒体账号点进去再由 requireFeature 弹回，
// 体验比「不可点」更差；这里提前判，给一句能看懂的说明。
import { canUse } from '@/utils/permission'
import { useUserStore } from '@/store/user'
import { fetchTodayTodo, todoAction } from '@/api/today'

const user = useUserStore()

/** 后端 /today/todo 返回：{date, rules, bookings:{items,trialCount}, renewals[], churnRisks[], birthdays[], trials[], reviews[], newLeads[]} */
type RawTodo = Record<string, unknown>

interface FlatItem {
  type: string
  key: string
  title: string
  subtitle: string
  tags: string[]
  done: boolean
  doneBy?: string
  /** 后端下发的会员 id：既用于 todoAction 回执，也用于「点行进会员 360」 */
  customerId?: number
  /** 后端下发的留资 id：用于 todoAction 回执 + 点行跳留资列表 */
  leadId?: number
  actions: string[]
}

/**
 * 分组定义 = 后端 /today/todo 返回的 8 个业务键，顺序即产品定义的优先级。
 *
 * ⚠️ 2026-11-06 补 `tasks`：此前只有 7 组，后端 `return ok([... 'tasks' => $tasks])`
 * 与工作台 `TODO_LABELS.tasks = '今日任务'` 都有这一组，唯独本页的 GROUPS 漏了 →
 * 「今日任务」在待办页完全不渲染（数据到了、页面不显示），而且 `?tab=tasks` 深链
 * 命中不了 TAB_TYPES 会静默回落「全部」。属于「数据存在但看不见」的 P1。
 */
const GROUPS: { type: string; label: string }[] = [
  { type: 'bookings', label: '今日预约' },
  { type: 'renewals', label: '待续费' },
  { type: 'churnRisks', label: '流失风险' },
  { type: 'birthdays', label: '生日关怀' },
  { type: 'trials', label: '体验课' },
  { type: 'reviews', label: '课后分析' },
  { type: 'newLeads', label: '新客首响' },
  { type: 'tasks', label: '今日任务' }
]

/**
 * 各组的行内动作（与后端 todoAction 的流转分支对齐）。
 *
 * `tasks` 刻意是空数组：后端 `todoAction` 的 `type` 校验是
 * `in:bookings,renewals,churnRisks,birthdays,trials,reviews,newLeads` —— 不含 tasks，
 * 在这里放任何按钮都会 422。任务的流转只走 TaskController::update 状态机，
 * 所以这一组的正确动作是「点行进任务中心」，而不是在待办页改状态。
 */
const DEFAULT_ACTIONS: Record<string, string[]> = {
  bookings: ['已到店', '已联系'],
  renewals: ['已沟通'],
  churnRisks: ['已沟通'],
  birthdays: ['已祝福'],
  trials: ['已接待', '爽约'],
  reviews: ['已提交'],
  newLeads: ['已首响', '无效客资'],
  tasks: []
}

/** 顶部标签条：全部 + 8 组，顺序即 GROUPS 顺序（不按数量排序） */
const ALL_TAB = { type: 'all', label: '全部' }
const TABS: { type: string; label: string }[] = [ALL_TAB, ...GROUPS]
const TAB_TYPES = new Set(TABS.map((t) => t.type))

const groups = ref<{ type: string; label: string; items: FlatItem[] }[]>([])
const loading = ref(false)
const failed = ref(false)
const loaded = ref(false)
const acting = ref('')
/** 当前选中的分组（all = 依次展示全部非空组） */
const active = ref(ALL_TAB.type)

/** 全组未完成总数（页头一句话讲清今天还剩多少） */
const pendingCount = computed(() =>
  groups.value.reduce((n, g) => n + g.items.filter((it) => !it.done).length, 0)
)

function pendingOf(g: { items: FlatItem[] }): number {
  return g.items.filter((it) => !it.done).length
}

/** 当前标签下要渲染的组（全部 = 按 GROUPS 顺序，空的组 normalize 时已剔除） */
const visibleGroups = computed(() =>
  active.value === ALL_TAB.type ? groups.value : groups.value.filter((g) => g.type === active.value)
)

/** 当前标签的未完成数（全部 = 总数） */
const activePending = computed(() => {
  if (active.value === ALL_TAB.type) return pendingCount.value
  const g = groups.value.find((x) => x.type === active.value)
  return g ? pendingOf(g) : 0
})

const activeLabel = computed(() => TABS.find((t) => t.type === active.value)?.label ?? '全部')

/** tab 角标用（0 不显示，避免一条待办都没有还挂个 0） */
function tabCount(type: string): number {
  if (type === ALL_TAB.type) return pendingCount.value
  const g = groups.value.find((x) => x.type === type)
  return g ? pendingOf(g) : 0
}

function selectTab(type: string) {
  if (!TAB_TYPES.has(type)) return
  active.value = type
}

/**
 * 行内三件套的取值（八组字段并不统一，尤其 tasks 组）。
 *
 * tasks 组的元素是 `camel($task) + overdue`：title 是**任务标题**、customerName 是会员名。
 * 若沿用其他组的 `name ?? customerName ?? title` 顺序，今日任务会显示成会员名，
 * 与工作台摘要的口径对不上。副标题也用「截止 · 负责人」，而不是 time/course 那串。
 */
function titleOf(it: Record<string, unknown>, type: string): string {
  if (type === 'tasks') return String(it.title ?? it.customerName ?? '—')
  return String(it.name ?? it.customerName ?? it.title ?? it.memberName ?? '—')
}

function subtitleOf(it: Record<string, unknown>, type: string): string {
  if (type === 'tasks') {
    return [it.deadline, it.owner].filter((x) => typeof x === 'string' && x !== '').join(' · ')
  }
  return String(it.time ?? it.course ?? it.cardName ?? it.source ?? it.note ?? '')
}

function tagsOf(it: Record<string, unknown>, type: string): string[] {
  // tasks 组后端只给一个可直接展示的事实：overdue（今天到期或已逾期）
  if (type === 'tasks') return it.overdue === true ? ['已逾期'] : []
  return Array.isArray(it.flags) ? (it.flags as string[]) : Array.isArray(it.tags) ? (it.tags as string[]) : []
}

/**
 * 从 key 前缀解析「这条待办指向谁」。
 *
 * 为什么不能只读字段：后端 TodayController::todo 的八组字段并不统一，`id` 在不同组
 * 甚至是不同实体 ——
 *   bookings   有 customerId（未匹配到会员档案时为 null），id 是预约 id
 *   renewals   只有 id，**就是会员 id**（key = renewal:{id}）
 *   churnRisks 只有 id，会员 id（key = churn:{id}）
 *   birthdays  只有 id，会员 id（key = birthday:{id}）
 *   newLeads   只有 id，**是留资 id**（key = lead:{id}）
 *   trials     留资卡带 leadId（key = trial:lead-{leadId}-{session}）；KY 预约两者都没有
 *   reviews    只有 bookingId，推不出会员
 *   tasks      只有任务 id（key 由 Task 行自带），既不是会员也不是留资
 * 所以「统一把 it.id 当会员 id」会把留资 id 当会员 id 用，跳过去 404。
 * 用 key 前缀判类型最可靠：key 是后端按类型拼的，本身就带语义，且不受字段改名影响。
 */
function jumpFromKey(key: string): { customerId?: number; leadId?: number } {
  const member = /^(?:renewal|churn|birthday):(\d+)$/.exec(key)
  if (member) return { customerId: Number(member[1]) }
  const lead = /^lead:(\d+)$/.exec(key)
  if (lead) return { leadId: Number(lead[1]) }
  const trialLead = /^trial:lead-(\d+)-\d+$/.exec(key)
  if (trialLead) return { leadId: Number(trialLead[1]) }
  return {}
}

function normalize(raw: RawTodo) {
  return GROUPS.map(({ type, label }) => {
    const section = raw[type]
    const arr = Array.isArray(section)
      ? section
      : Array.isArray((section as { items?: unknown[] })?.items)
        ? ((section as { items: unknown[] }).items)
        : []
    const items: FlatItem[] = (arr as Record<string, unknown>[]).map((it) => {
      const key = String(it.key ?? it.id ?? '')
      const fromKey = jumpFromKey(key)
      // 后端显式给的优先，缺失时按 key 前缀兜底（renewals/churnRisks/birthdays 的 id 即会员 id）
      const explicitCustomer = typeof it.customerId === 'number' ? it.customerId : undefined
      const explicitLead = typeof it.leadId === 'number' ? it.leadId : undefined
      return {
        type,
        key,
        title: titleOf(it, type),
        subtitle: subtitleOf(it, type),
        tags: tagsOf(it, type),
        done: Boolean(it.done),
        doneBy: it.doneBy ? String(it.doneBy) : undefined,
        customerId: explicitCustomer ?? fromKey.customerId,
        leadId: explicitLead ?? fromKey.leadId,
        actions: DEFAULT_ACTIONS[type] ?? ['已处理']
      }
    })
    return { type, label, items }
  }).filter((g) => g.items.length > 0)
}

/**
 * 请求序号：本页的 load() 有三个触发源——onShow、下拉刷新、以及动作提交成功后的立刻重拉。
 * 三者可以并发（点完「已沟通」马上又下拉刷新），旧响应回来会把新结果盖掉。
 * 与 tasks.vue / review.vue 同一套处理：只认最后一笔，过期响应直接丢弃。
 */
let loadSeq = 0

async function load() {
  const my = ++loadSeq
  loading.value = true
  try {
    const raw = (await fetchTodayTodo()) as unknown as RawTodo
    // 过期响应不改状态：否则「提交动作 → 刷新」与「下拉刷新」交叉时会出现
    // 「刚标记完已沟通，列表又变回未处理」
    if (my !== loadSeq) return
    groups.value = normalize(raw ?? {})
    loaded.value = true
    failed.value = false
  } catch {
    if (my !== loadSeq) return
    /* http 层已 toast */
    failed.value = true
  } finally {
    if (my === loadSeq) loading.value = false
  }
}

async function act(item: FlatItem, action: string) {
  const opId = `${item.type}:${item.key}`
  if (acting.value === opId) return
  acting.value = opId
  try {
    await todoAction({
      type: item.type,
      key: item.key,
      action,
      customerId: item.customerId,
      leadId: item.leadId,
      touch: action === '已沟通' ? true : undefined
    })
    uni.showToast({ title: '已记录', icon: 'success' })
    await load()
  } catch {
    /* http 层已 toast */
  } finally {
    acting.value = ''
  }
}

/**
 * 点待办行进「客户 360」（本次修复的主链路）。
 *
 * 为什么需要它：normalize() 早就把 customerId / leadId 解析出来了，但此前只当
 * todoAction 的入参用，行本身不可点。店长看到「待续费 · 张三」想确认
 * 「她还剩几节课、最近来过没」，只能退出去走「客户经营 → 会员管理 → 搜名字」，
 * 3 步变 6 步，而这是每天点几十次的路径。
 *
 * 分五档，尽量进到「人」，进不到就退到「能找到这个人的那一页」，绝不留死点击：
 *  ① customerId  → /pkg-crm/members/detail（会员 360：卡项/出勤/续费评估/留痕都在那儿）
 *  ② 只有 leadId → 留资列表。**不能直达单条**：后端没有 GET /leads/{id}，
 *     leads 页 onLoad 也只认 status / venue（不认 focus），所以只能落到列表让人搜；
 *     等后端补单条详情接口后再改深链（档案 §5 已把它列为 P2 待办）。
 *  ③ reviews 组（只有 bookingId）→ 课后分析列表：这批待办本来就是「该补一条课后分析」，
 *     落点就是那个功能的列表页，进去点「新建」时的候选里正是今天上过课的人。
 *  ④ tasks 组（只有任务 id）→ 任务中心：任务是独立实体，且待办页不能改它的状态
 *     （后端 todoAction 的 type 白名单不含 tasks），状态机只在 TaskController。
 *  ⑤ trials 组的 KY 预约（只有手机号）→ 会员管理列表，按姓名/手机号搜。
 *  ⑥ 什么都没有 → 明确告知，不留「点了没反应」的死点击。
 *
 * 权限先判：members / reviews / tasks 都是 OPS_ROLES，新媒体账号进去会被 requireFeature
 * toast + navigateBack，那种「点了又被弹回来」比不可点更糟，所以这里提前挡并给说明。
 * （实测不会误伤：后端对新媒体根本不产出 trials / reviews / renewals / tasks 等组）
 */
function openCustomer(item: FlatItem): void {
  const roles = user.roles

  if (item.customerId) {
    if (!canUse(roles, 'members')) {
      uni.showToast({ title: '当前账号没有会员档案权限', icon: 'none' })
      return
    }
    goPath(`/pkg-crm/members/detail?id=${item.customerId}`)
    return
  }

  if (item.leadId) {
    if (!canUse(roles, 'leads')) {
      uni.showToast({ title: '当前账号没有留资权限', icon: 'none' })
      return
    }
    goPath('/pkg-crm/leads/leads')
    return
  }

  if (item.type === 'reviews') {
    if (!canUse(roles, 'reviews')) {
      uni.showToast({ title: '当前账号没有课后分析权限', icon: 'none' })
      return
    }
    goPath('/pkg-biz/review/review')
    return
  }

  // 今日任务：任务是独立实体（Task 行），既不是会员也不是留资 ——
  // 待办页也不能改它的状态（后端 todoAction 的 type 白名单不含 tasks），
  // 所以正确落点是任务中心，在那里走 TaskController 的状态机。
  if (item.type === 'tasks') {
    if (!canUse(roles, 'tasks')) {
      uni.showToast({ title: '当前账号没有任务中心权限', icon: 'none' })
      return
    }
    goPath('/pkg-biz/tasks/tasks')
    return
  }

  if (item.type === 'trials') {
    if (!canUse(roles, 'members')) {
      uni.showToast({ title: '当前账号没有会员档案权限', icon: 'none' })
      return
    }
    goPath('/pkg-crm/members/members')
    return
  }

  uni.showToast({ title: '这条待办没有关联客户档案', icon: 'none' })
}

// 深链：/pkg-biz/todo/todo?tab=bookings（或 ?tab=newLeads）→ 直接落在对应分组
onLoad((options) => {
  const t = String((options as Record<string, string>)?.tab ?? '')
  // 未知 / 空值一律回落「全部」：工作台指标卡和待办摘要都会带 ?tab= 进来，
  // 传错一个 type 就整屏空白是最糟的失败方式 —— 宁可让人看全量。
  // （此前是「不认识就不动 active」，效果相同但意图不明，这里写死回落值）
  active.value = t && TAB_TYPES.has(t) ? t : ALL_TAB.type
})

onShow(() => {
  if (!requireLogin()) return
  if (!requireFeature('todo')) return
  load()
})

onPullDownRefresh(async () => {
  await load()
  uni.stopPullDownRefresh()
})
</script>

<template>
  <view class="ym-page todo">
    <!-- 顶部摘要：数字跟随当前标签 -->
    <view class="thero">
      <view class="thero__main">
        <text class="thero__label">{{ active === 'all' ? '今日待办' : activeLabel }}</text>
        <view class="thero__value-row">
          <text class="thero__value ym-num">{{ activePending }}</text>
          <text class="thero__unit">项待处理</text>
        </view>
      </view>
      <view class="thero__side">
        <ym-badge
          :text="active === 'all' ? `${groups.length} 组有线索` : `${visibleGroups[0]?.items.length ?? 0} 项`"
          tone="brand"
          plain
        />
      </view>
    </view>

    <!-- 顶部固定分组标签条：全部 + 8 组，横向可滚 -->
    <view class="tabbar">
      <scroll-view class="tabbar__scroll" scroll-x :show-scrollbar="false">
        <view class="tabbar__inner">
          <view
            v-for="t in TABS"
            :key="t.type"
            class="tabbar__item ym-tap"
            :class="{ 'is-active': active === t.type }"
            @click="selectTab(t.type)"
          >
            <text class="tabbar__label">{{ t.label }}</text>
            <text v-if="tabCount(t.type)" class="tabbar__count ym-num">{{ tabCount(t.type) }}</text>
          </view>
        </view>
      </scroll-view>
    </view>

    <!-- 三态 -->
    <view v-if="failed && !groups.length" class="ym-card">
      <ym-empty
        icon="warning"
        text="今日待办加载失败"
        desc="网络或服务异常，可下拉刷新重试"
        action-text="重试"
        @action="load"
      />
    </view>

    <view v-else-if="loading && !groups.length" class="ym-card">
      <view v-for="i in 3" :key="i" class="sk-row">
        <wd-skeleton
          theme="text"
          animation="gradient"
          :row-col="[{ width: '34%' }, { width: '72%' }, { width: '46%' }]"
        />
      </view>
    </view>

    <ym-empty
      v-else-if="loaded && !groups.length"
      icon="check-circle"
      text="今日待办已全部清零"
      desc="没有需要闭环的动作，保持节奏"
      action-text="重新拉取"
      @action="load"
    />

    <ym-empty
      v-else-if="!visibleGroups.length"
      icon="check-circle"
      :text="`${activeLabel} 今日无待办`"
      desc="切到「全部」看看其他组"
      action-text="看全部"
      @action="selectTab('all')"
    />

    <ym-section
      v-for="g in visibleGroups"
      :key="g.type"
      :title="g.label"
      :desc="pendingOf(g) ? `${pendingOf(g)} 项待处理` : '本组已闭环'"
    >
      <!-- 整行可点：进会员 360 / 留资（openCustomer 内按 id 类型分流并先判权限）。
           ym-tap 给按压反馈，否则「能点」这件事在视觉上没有交代。 -->
      <view
        v-for="it in g.items"
        :key="it.key"
        class="titem ym-tap"
        :class="{ 'is-done': it.done }"
        @click="openCustomer(it)"
      >
        <view class="titem__head">
          <view class="titem__main">
            <text class="titem__title ym-ellipsis">{{ it.title }}</text>
            <text v-if="it.subtitle" class="titem__sub ym-ellipsis">{{ it.subtitle }}</text>
          </view>
          <view v-if="it.tags.length" class="titem__tags">
            <ym-badge v-for="t in it.tags" :key="t" :text="t" tone="warn" />
          </view>
          <ym-badge v-if="it.done" text="已完成" tone="brand" plain />
        </view>

        <text v-if="it.done" class="titem__done">已由 {{ it.doneBy || '同事' }} 处理</text>

        <!-- tasks 组的 actions 是空数组（后端 todoAction 的 type 白名单不含 tasks，
             放任何按钮都会 422）：没有动作时不要渲染空容器，否则行尾多出 16rpx 空白 -->
        <view v-else-if="it.actions.length" class="titem__actions">
          <view v-for="a in it.actions" :key="a" class="titem__btnwrap">
            <wd-button
              size="medium"
              plain
              block
              :disabled="acting === `${it.type}:${it.key}`"
              @click.stop="act(it, a)"
            >
              {{ a }}
            </wd-button>
          </view>
        </view>
      </view>
    </ym-section>
  </view>
</template>

<style lang="scss" scoped>
@import '@/styles/tokens.scss';

.todo {
  --wot-button-medium-height: 88rpx;
  --wot-button-medium-fs: 26rpx;
}

/* ---------- 顶部固定分组标签条 ---------- */
.tabbar {
  position: sticky;
  top: 0;
  z-index: 6;
  background: $white;
  border-top: 2rpx solid $hairline;
  border-bottom: 2rpx solid $hairline;
}

.tabbar__scroll {
  width: 100%;
  white-space: nowrap;
}

.tabbar__inner {
  display: inline-flex;
  gap: 12rpx;
  padding: 14rpx 24rpx;
}

.tabbar__item {
  display: inline-flex;
  flex-shrink: 0;
  gap: 8rpx;
  align-items: center;
  height: 80rpx;
  padding: 0 26rpx;
  background: $ink-50;
  border-radius: 999rpx;
}

.tabbar__item.is-active {
  background: $brand-500;
}

.tabbar__label {
  font-size: 26rpx;
  color: $ink-600;
}

.tabbar__item.is-active .tabbar__label {
  font-weight: 600;
  color: $white;
}

.tabbar__count {
  min-width: 34rpx;
  height: 34rpx;
  padding: 0 8rpx;
  font-size: 20rpx;
  line-height: 34rpx;
  text-align: center;
  color: $brand-700;
  background: $brand-100;
  border-radius: 999rpx;
}

.tabbar__item.is-active .tabbar__count {
  background: $white;
}

/* ---------- 头部摘要 ---------- */
.thero {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 26rpx 28rpx;
  margin: 20rpx 24rpx 0;
  background: linear-gradient(150deg, $brand-50, $white 62%);
  border: 2rpx solid $brand-100;
  border-radius: $r-lg;
}

.thero__main {
  display: flex;
  flex-direction: column;
  gap: 8rpx;
}

.thero__label {
  font-size: 24rpx;
  color: $ink-500;
  letter-spacing: 0.5rpx;
}

.thero__value-row {
  display: flex;
  gap: 8rpx;
  align-items: baseline;
}

.thero__value {
  font-size: 52rpx;
  font-weight: 600;
  line-height: 1.15;
  color: $ink-800;
}

.thero__unit {
  font-size: 22rpx;
  color: $ink-400;
}

.thero__side {
  display: flex;
  align-items: center;
}

/* ---------- 骨架 ---------- */
.sk-row {
  padding: 20rpx 0;

  & + & {
    border-top: 2rpx solid $hairline;
  }
}

/* ---------- 待办行 ---------- */
.titem {
  padding: 20rpx 0;
  border-top: 2rpx solid $hairline;

  &:first-child {
    border-top: none;
    padding-top: 4rpx;
  }

  &.is-done {
    opacity: 0.55;
  }
}

.titem__head {
  display: flex;
  gap: 12rpx;
  align-items: flex-start;
  min-height: 44rpx;
}

.titem__main {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 6rpx;
  min-width: 0;
}

.titem__title {
  display: block;
  font-size: 30rpx;
  font-weight: 500;
  color: $ink-800;
}

.titem__sub {
  font-size: 23rpx;
  color: $ink-400;
}

.titem__tags {
  display: flex;
  flex-shrink: 0;
  flex-direction: column;
  gap: 8rpx;
  align-items: flex-end;
}

.titem__done {
  display: block;
  margin-top: 10rpx;
  font-size: 22rpx;
  color: $ink-400;
}

.titem__actions {
  display: flex;
  gap: 16rpx;
  margin-top: 16rpx;
}

.titem__btnwrap {
  flex: 1;
  min-width: 0;
}
</style>
