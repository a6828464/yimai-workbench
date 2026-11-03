<script setup lang="ts">
import { ref } from 'vue'
import { onShow, onPullDownRefresh } from '@dcloudio/uni-app'
import { requireLogin } from '@/utils/guard'
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

const groups = ref<{ type: string; label: string; items: FlatItem[] }[]>([])
const loading = ref(false)
const acting = ref('')

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
  } catch {
    /* http 层已 toast */
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

onShow(() => {
  if (requireLogin()) load()
})

onPullDownRefresh(async () => {
  await load()
  uni.stopPullDownRefresh()
})
</script>

<template>
  <view class="ym-page">
    <view v-if="!loading && !groups.length" class="ym-empty">今日待办已全部清零 🎉</view>

    <view v-for="g in groups" :key="g.type" class="ym-card">
      <view class="ym-row--between">
        <text class="ym-title">{{ g.label }}</text>
        <text class="ym-muted">{{ g.items.length }} 条</text>
      </view>

      <view v-for="it in g.items" :key="it.key" class="todo-item" :class="{ 'todo-item--done': it.done }">
        <view class="ym-row--between">
          <view class="todo-item__main">
            <text class="todo-item__title">{{ it.title }}</text>
            <text v-if="it.subtitle" class="ym-muted">{{ it.subtitle }}</text>
          </view>
          <view v-if="it.tags.length" class="todo-item__tags">
            <text v-for="t in it.tags" :key="t" class="ym-tag ym-tag--warn">{{ t }}</text>
          </view>
        </view>
        <view v-if="it.done" class="ym-muted todo-item__done-line">已由 {{ it.doneBy || '同事' }} 处理</view>
        <view v-else class="todo-item__actions">
          <button
            v-for="a in it.actions"
            :key="a"
            class="ym-btn ym-btn--plain todo-item__btn"
            :disabled="acting === `${it.type}:${it.key}`"
            @click="act(it, a)"
          >
            {{ a }}
          </button>
        </view>
      </view>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.todo-item {
  padding: 24rpx 0;
  border-bottom: 2rpx solid #f0f1f3;

  &:last-child {
    border-bottom: none;
  }

  &--done {
    opacity: 0.55;
  }

  &__main {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-width: 0;
  }

  &__title {
    font-size: 30rpx;
    font-weight: 500;
    margin-bottom: 6rpx;
  }

  &__tags {
    display: flex;
    flex-direction: column;
    gap: 8rpx;
    margin-left: 16rpx;
  }

  &__done-line {
    margin-top: 10rpx;
  }

  &__actions {
    display: flex;
    gap: 16rpx;
    margin-top: 16rpx;
  }

  &__btn {
    flex: 1;
    min-height: 88rpx;
    font-size: 26rpx;
    margin: 0;
  }
}
</style>
