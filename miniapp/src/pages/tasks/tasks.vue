<script setup lang="ts">
import { ref } from 'vue'
import { onShow, onPullDownRefresh, onReachBottom } from '@dcloudio/uni-app'
import { requireLogin } from '@/utils/guard'
import { fetchTasks, updateTask } from '@/api/tasks'
import { useUserStore } from '@/store/user'
import type { TaskItem } from '@/types'

const user = useUserStore()
const TABS = ['全部', '待接收', '进行中', '待验收', '已完成'] as const
const tab = ref<(typeof TABS)[number]>('全部')
const list = ref<TaskItem[]>([])
const total = ref(0)
const page = ref(1)
const loading = ref(false)
const acting = ref(0)

async function load(reset = false) {
  if (reset) {
    page.value = 1
    list.value = []
  }
  loading.value = true
  try {
    const res = await fetchTasks({
      status: tab.value === '全部' ? undefined : tab.value,
      current: page.value,
      size: 20
    })
    total.value = res.total
    list.value = reset ? res.records : [...list.value, ...res.records]
  } catch {
    /* http 层已 toast */
  } finally {
    loading.value = false
  }
}

function switchTab(t: (typeof TABS)[number]) {
  tab.value = t
  load(true)
}

/** 老师/服务角色的合法流转（与 TaskController::update 的状态机对齐） */
function nextActions(t: TaskItem): { label: string; status: TaskItem['status'] }[] {
  const mine = t.owner === user.staffName || t.owner === '未分配'
  if (!mine) return []
  if (t.status === '待接收') return [{ label: '认领', status: '进行中' }]
  if (t.status === '进行中') return [{ label: '提报验收', status: '待验收' }]
  if (t.status === '已退回') return [{ label: '重新提报', status: '待验收' }]
  return []
}

async function act(t: TaskItem, status: TaskItem['status']) {
  if (acting.value === t.id) return
  acting.value = t.id
  try {
    await updateTask(t.id, { status })
    uni.showToast({ title: '已更新', icon: 'success' })
    await load(true)
  } catch {
    /* http 层已 toast */
  } finally {
    acting.value = 0
  }
}

onShow(() => {
  if (requireLogin()) load(true)
})

onPullDownRefresh(async () => {
  await load(true)
  uni.stopPullDownRefresh()
})

onReachBottom(() => {
  if (!loading.value && list.value.length < total.value) {
    page.value += 1
    load()
  }
})
</script>

<template>
  <view class="ym-page">
    <view class="tasks-tabs">
      <text
        v-for="t in TABS"
        :key="t"
        class="tasks-tabs__item"
        :class="{ 'tasks-tabs__item--active': tab === t }"
        @click="switchTab(t)"
      >
        {{ t }}
      </text>
    </view>

    <view v-if="!loading && !list.length" class="ym-empty">暂无任务</view>

    <view v-for="t in list" :key="t.id" class="ym-card">
      <view class="ym-row--between">
        <text class="ym-title">{{ t.title }}</text>
        <text class="ym-tag" :class="{ 'ym-tag--danger': t.priority === '高', 'ym-tag--warn': t.priority === '中' }">
          {{ t.priority }}
        </text>
      </view>
      <text class="ym-muted tasks__line">会员：{{ t.customerName }} · {{ t.venue }}</text>
      <text class="ym-muted tasks__line">负责人：{{ t.owner }} · 截止 {{ t.deadline || '—' }}</text>
      <text v-if="t.standard" class="ym-muted tasks__line">验收：{{ t.standard }}</text>
      <view class="ym-row--between tasks__foot">
        <text class="ym-tag">{{ t.status }}</text>
        <view class="tasks__actions">
          <button
            v-for="a in nextActions(t)"
            :key="a.status"
            class="ym-btn tasks__btn"
            :disabled="acting === t.id"
            @click="act(t, a.status)"
          >
            {{ a.label }}
          </button>
        </view>
      </view>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.tasks-tabs {
  display: flex;
  background: #fff;
  padding: 0 12rpx;
  position: sticky;
  top: 0;
  z-index: 2;

  &__item {
    flex: 1;
    text-align: center;
    padding: 24rpx 0;
    font-size: 26rpx;
    color: #8a8f99;
    border-bottom: 4rpx solid transparent;

    &--active {
      color: #2e7d5b;
      font-weight: 600;
      border-bottom-color: #2e7d5b;
    }
  }
}

.tasks__line {
  display: block;
  margin-top: 10rpx;
}

.tasks__foot {
  margin-top: 20rpx;
}

.tasks__actions {
  display: flex;
  gap: 16rpx;
}

.tasks__btn {
  min-height: 88rpx;
  padding: 0 36rpx;
  font-size: 26rpx;
  margin: 0;
}
</style>
