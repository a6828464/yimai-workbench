<script setup lang="ts">
/**
 * 今日待办（七组合并视图）
 *
 * 读：GET /today/todo → {date, rules, bookings:{items,trialCount}, renewals[], churnRisks[],
 *                        birthdays[], trials[], reviews[], newLeads[]}
 * 写：POST /today/todo/action（type / key / action / customerId / leadId / touch）
 *
 * 视觉层（2026-11 重构）：每组一张 ym-section 卡，行内动作换 wd-button，
 * 三态（骨架 / 空 / 失败）。分组、动作分支、touch 语义全部原样保留。
 */
import { computed, ref } from 'vue'
import { onLoad, onShow, onPullDownRefresh } from '@dcloudio/uni-app'
import { requireLogin } from '@/utils/guard'
import { requireFeature } from '@/utils/nav'
import { fetchTodayTodo, todoAction } from '@/api/today'

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
  customerId?: number
  leadId?: number
  actions: string[]
}

const GROUPS: { type: string; label: string }[] = [
  { type: 'bookings', label: '今日预约' },
  { type: 'renewals', label: '待续费' },
  { type: 'churnRisks', label: '流失风险' },
  { type: 'birthdays', label: '生日关怀' },
  { type: 'trials', label: '体验课' },
  { type: 'reviews', label: '课后分析' },
  { type: 'newLeads', label: '新客首响' }
]

/** 各组默认可执行动作（与后端 todoAction 的流转分支对齐） */
const DEFAULT_ACTIONS: Record<string, string[]> = {
  bookings: ['已到店', '已联系'],
  renewals: ['已沟通'],
  churnRisks: ['已沟通'],
  birthdays: ['已祝福'],
  trials: ['已接待', '爽约'],
  reviews: ['已提交'],
  newLeads: ['已首响', '无效客资']
}

/** 顶部标签条：全部 + 七组，顺序即 GROUPS 顺序（不按数量排序） */
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

function normalize(raw: RawTodo) {
  return GROUPS.map(({ type, label }) => {
    const section = raw[type]
    const arr = Array.isArray(section)
      ? section
      : Array.isArray((section as { items?: unknown[] })?.items)
        ? ((section as { items: unknown[] }).items)
        : []
    const items: FlatItem[] = (arr as Record<string, unknown>[]).map((it) => ({
      type,
      key: String(it.key ?? it.id ?? ''),
      title: String(it.name ?? it.customerName ?? it.title ?? it.memberName ?? '—'),
      subtitle: String(it.time ?? it.course ?? it.cardName ?? it.source ?? it.note ?? ''),
      tags: Array.isArray(it.flags) ? (it.flags as string[]) : Array.isArray(it.tags) ? (it.tags as string[]) : [],
      done: Boolean(it.done),
      doneBy: it.doneBy ? String(it.doneBy) : undefined,
      customerId: typeof it.customerId === 'number' ? it.customerId : undefined,
      leadId: typeof it.leadId === 'number' ? it.leadId : undefined,
      actions: DEFAULT_ACTIONS[type] ?? ['已处理']
    }))
    return { type, label, items }
  }).filter((g) => g.items.length > 0)
}

async function load() {
  loading.value = true
  try {
    const raw = (await fetchTodayTodo()) as unknown as RawTodo
    groups.value = normalize(raw ?? {})
    loaded.value = true
    failed.value = false
  } catch {
    /* http 层已 toast */
    failed.value = true
  } finally {
    loading.value = false
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

// 深链：/pages/todo/todo?tab=bookings（或 ?tab=newLeads）→ 直接落在对应分组；未知值回落「全部」
onLoad((options) => {
  const t = String((options as Record<string, string>)?.tab ?? '')
  if (t && TAB_TYPES.has(t)) active.value = t
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

    <!-- 顶部固定分组标签条：全部 + 七组，横向可滚 -->
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
      <view
        v-for="it in g.items"
        :key="it.key"
        class="titem"
        :class="{ 'is-done': it.done }"
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

        <view v-else class="titem__actions">
          <view v-for="a in it.actions" :key="a" class="titem__btnwrap">
            <wd-button
              size="medium"
              plain
              block
              :disabled="acting === `${it.type}:${it.key}`"
              @click="act(it, a)"
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
