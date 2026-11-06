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
import { computed, ref } from 'vue'
import { onShow, onPullDownRefresh, onReachBottom, onShareAppMessage } from '@dcloudio/uni-app'
import { shareToHome } from '@/utils/share'
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
/** 首屏/重置加载：驱动骨架与「没有数据」三态 */
const loading = ref(false)
/** 触底追加：与 loading 分开，否则「加载更多」的骨架会顶掉整页内容 */
const loadingMore = ref(false)
const loaded = ref(false)
const failed = ref(false)
const acting = ref(0)

/** 还有没有下一页（列表已加载数 < 服务端总数） */
const hasMore = computed(() => list.value.length < total.value)

/**
 * 请求序号：onShow、下拉刷新、触底加载三者会并发。
 * 旧写法没有任何保护，实测会出两种错：
 *  ① 触底拿到 page=2 的数据时，若 onShow 的 reset 请求刚好清空了 list，
 *     第 2 页会被 append 到空列表上 → 首屏只剩 1 条；
 *  ② 反过来，慢一步的 reset 响应回来后把已追加的第 2 页整体覆盖 → 用户滑到底突然只剩 20 条。
 * 与 review/review.vue:66-106 同一套：只认最后一笔，过期响应直接丢弃。
 */
let reqSeq = 0

async function load(reset = true) {
  // 触底追加时若有请求在飞就跳过；reset 允许打断在途请求（靠序号丢弃旧响应）
  if (!reset && (loading.value || loadingMore.value)) return
  const my = ++reqSeq
  if (reset) {
    page.value = 1
    loading.value = true
  } else {
    loadingMore.value = true
  }
  failed.value = false
  try {
    const res = await fetchTasks({
      status: tab.value === '全部' ? undefined : tab.value,
      current: page.value,
      size: 20
    })
    if (my !== reqSeq) return
    total.value = res.total
    list.value = reset ? res.records : [...list.value, ...res.records]
    loaded.value = true
  } catch {
    if (my !== reqSeq) return
    /* http 层已 toast */
    failed.value = true
  } finally {
    if (my === reqSeq) {
      loading.value = false
      loadingMore.value = false
    }
  }
}

function switchTab(t: (typeof TABS)[number]) {
  if (tab.value === t) return
  tab.value = t
  // 切页签立刻清空：否则新页签的数据到达前，屏幕上还挂着上一个页签的任务，
  // 用户会以为「已完成」里还有待接收的单子（reset 请求内部不清空，避免 onShow 时闪白）
  list.value = []
  loaded.value = false
  void load(true)
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
  // 三个条件缺一不可：没更多就别再翻页、任一请求在飞就等它回来（否则页码与数据错位）
  if (!hasMore.value || loading.value || loadingMore.value) return
  page.value += 1
  void load(false)
})

// 转发：员工页统一回首页（分享自己会撞 requireLogin 的登录墙），标题不带客户信息 —— 见 utils/share.ts
onShareAppMessage(() => shareToHome())
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
        <!-- 文案对齐后端口径（后端是「优先级：高」）：原来「高优先」不通顺 -->
        <ym-badge :text="`优先级 ${t.priority}`" :tone="priorityTone(t.priority)" />
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

    <!-- 加载更多只认 loadingMore：首屏重置（loading=true）时不该出现「正在加载更多」 -->
    <wd-loadmore v-if="loadingMore" state="loading" loading-text="正在加载更多" />
    <wd-loadmore
      v-else-if="list.length && !hasMore"
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
