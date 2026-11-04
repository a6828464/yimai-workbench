<script setup lang="ts">
/**
 * 任务中心（老师 / 服务的任务接收与闭环）
 *
 * 读：GET /tasks（服务端分页，status = 全部 / 待接收 / 进行中 / 待验收 / 已完成）
 * 写：PATCH /tasks/{id} { status } —— 只走 TaskController::update 的状态机
 *
 * 视觉层（2026-11 重构）：状态页签（wd-tabs）、任务白卡、三态、触底加载。
 * 状态机 nextActions() 与分页口径零改动。
 */
import { ref } from 'vue'
import { onShow, onPullDownRefresh, onReachBottom } from '@dcloudio/uni-app'
import { requireLogin } from '@/utils/guard'
import { requireFeature } from '@/utils/nav'
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
const loaded = ref(false)
const failed = ref(false)
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
    loaded.value = true
    failed.value = false
  } catch {
    /* http 层已 toast */
    failed.value = true
  } finally {
    loading.value = false
  }
}

function switchTab(t: (typeof TABS)[number]) {
  tab.value = t
  load(true)
}

function onTabChange(payload: { name: string | number }) {
  const next = String(payload.name) as (typeof TABS)[number]
  if (!TABS.includes(next)) return
  switchTab(next)
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

function statusTone(s: string): 'brand' | 'gold' | 'warn' | 'danger' | 'info' | 'muted' {
  if (s === '待接收') return 'warn'
  if (s === '进行中') return 'info'
  if (s === '待验收') return 'gold'
  if (s === '已退回') return 'danger'
  if (s === '已完成') return 'brand'
  return 'muted'
}

function priorityTone(p: TaskItem['priority']): 'danger' | 'warn' | 'muted' {
  if (p === '高') return 'danger'
  if (p === '中') return 'warn'
  return 'muted'
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
  if (!requireLogin()) return
  if (!requireFeature('tasks')) return
  load(true)
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
  <view class="ym-page tasks">
    <view class="filters">
      <wd-tabs :model-value="tab" :slidable-num="5" @change="onTabChange">
        <wd-tab v-for="t in TABS" :key="t" :title="t" :name="t" />
      </wd-tabs>
    </view>

    <view class="hint">
      <text class="hint__text ym-ellipsis">只显示你有权限处理的任务</text>
      <text class="hint__count ym-num">共 {{ total }} 条</text>
    </view>

    <!-- 三态 -->
    <view v-if="failed && !list.length" class="ym-card">
      <ym-empty
        icon="warning"
        text="任务加载失败"
        desc="网络或服务异常，可下拉刷新重试"
        action-text="重试"
        @action="load(true)"
      />
    </view>

    <view v-else-if="loading && !list.length" class="ym-card">
      <view v-for="i in 3" :key="i" class="sk-row">
        <wd-skeleton
          theme="text"
          animation="gradient"
          :row-col="[{ width: '52%' }, { width: '76%' }, { width: '40%' }]"
        />
      </view>
    </view>

    <ym-empty
      v-else-if="loaded && !list.length"
      icon="file"
      text="暂无任务"
      desc="当前状态下没有分给你的任务"
      action-text="刷新"
      @action="load(true)"
    />

    <view v-for="t in list" :key="t.id" class="tcard">
      <view class="tcard__head">
        <text class="tcard__title ym-ellipsis">{{ t.title }}</text>
        <ym-badge :text="`${t.priority}优先`" :tone="priorityTone(t.priority)" />
      </view>

      <view class="tcard__meta">
        <ym-badge :text="t.status" :tone="statusTone(t.status)" />
        <text class="tcard__owner ym-ellipsis">
          {{ t.owner }} · 截止 <text class="ym-num">{{ t.deadline || '—' }}</text>
        </text>
      </view>

      <view class="tcard__lines">
        <view class="tline">
          <text class="tline__key">会员</text>
          <text class="tline__val ym-ellipsis">{{ t.customerName }} · {{ t.venue }}</text>
        </view>
        <view v-if="t.standard" class="tline">
          <text class="tline__key">验收</text>
          <text class="tline__val">{{ t.standard }}</text>
        </view>
      </view>

      <view v-if="nextActions(t).length" class="tcard__acts">
        <view v-for="a in nextActions(t)" :key="a.status" class="tcard__actwrap">
          <wd-button
            size="medium"
            block
            :loading="acting === t.id"
            :disabled="acting === t.id"
            @click="act(t, a.status)"
          >
            {{ a.label }}
          </wd-button>
        </view>
      </view>
    </view>

    <wd-loadmore v-if="loading && list.length" state="loading" loading-text="正在加载更多" />
    <wd-loadmore
      v-else-if="list.length && list.length >= total"
      state="finished"
      finished-text="已经到底了"
    />
  </view>
</template>

<style lang="scss" scoped>
@import '@/styles/tokens.scss';

.tasks {
  --wot-tabs-nav-height: 88rpx;
  --wot-tabs-nav-fs: 27rpx;
  --wot-button-medium-height: 88rpx;
  --wot-button-medium-fs: 27rpx;
}

/* ---------- 状态页签 ---------- */
.filters {
  background: $white;
  border-bottom: 2rpx solid $hairline;
}

.hint {
  display: flex;
  gap: 16rpx;
  align-items: center;
  justify-content: space-between;
  padding: 18rpx 30rpx 4rpx;
}

.hint__text {
  flex: 1;
  min-width: 0;
  font-size: 22rpx;
  color: $ink-400;
}

.hint__count {
  flex-shrink: 0;
  font-size: 22rpx;
  color: $ink-400;
}

/* ---------- 骨架 ---------- */
.sk-row {
  padding: 20rpx 0;

  & + & {
    border-top: 2rpx solid $hairline;
  }
}

/* ---------- 任务卡 ---------- */
.tcard {
  padding: 26rpx 28rpx;
  margin: 20rpx 24rpx;
  background: $card-bg;
  border-radius: $r-lg;
  box-shadow: $shadow-card;
}

.tcard__head {
  display: flex;
  gap: 14rpx;
  align-items: center;
  min-width: 0;
}

.tcard__title {
  display: block;
  flex: 1;
  min-width: 0;
  font-size: 32rpx;
  font-weight: 600;
  color: $ink-800;
}

.tcard__meta {
  display: flex;
  gap: 12rpx;
  align-items: center;
  margin-top: 14rpx;
  min-width: 0;
}

.tcard__owner {
  display: block;
  flex: 1;
  min-width: 0;
  font-size: 23rpx;
  color: $ink-400;
}

.tcard__lines {
  padding-top: 14rpx;
  margin-top: 16rpx;
  border-top: 2rpx solid $hairline;
}

.tline {
  display: flex;
  gap: 12rpx;
  margin-top: 8rpx;

  &:first-child {
    margin-top: 0;
  }
}

.tline__key {
  flex-shrink: 0;
  width: 88rpx;
  font-size: 23rpx;
  color: $ink-400;
}

.tline__val {
  display: block;
  flex: 1;
  min-width: 0;
  font-size: 26rpx;
  line-height: 1.5;
  color: $ink-700;
}

.tcard__acts {
  display: flex;
  gap: 16rpx;
  margin-top: 20rpx;
}

.tcard__actwrap {
  flex: 1;
  min-width: 0;
}
</style>
