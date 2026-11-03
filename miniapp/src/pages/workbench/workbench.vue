<script setup lang="ts">
import { ref, computed } from 'vue'
import { onShow, onPullDownRefresh } from '@dcloudio/uni-app'
import { useUserStore } from '@/store/user'
import { requireLogin } from '@/utils/guard'
import { fetchTodaySummary } from '@/api/today'
import { fetchReviewPendingCount } from '@/api/review'

const user = useUserStore()
const summary = ref<Record<string, unknown>>({})
const pendingReviews = ref<number | null>(null)
const loading = ref(false)

const greeting = computed(() => {
  const h = new Date().getHours()
  const word = h < 6 ? '夜深了' : h < 12 ? '早上好' : h < 18 ? '下午好' : '晚上好'
  return `${word}，${user.info?.userName ?? ''}`
})

/** 汇总卡里挑 4 个最关键的字段展示（字段名以后端实际返回为准，宽匹配） */
const cards = computed(() => {
  const s = summary.value as Record<string, unknown>
  const pick = (...keys: string[]) => {
    for (const k of keys) {
      const v = s[k]
      if (typeof v === 'number') return v
    }
    return null
  }
  return [
    { label: '今日预约', value: pick('bookingsCount', 'bookings', 'todayBookings') },
    { label: '待续费', value: pick('renewalsCount', 'renewals') },
    { label: '体验课', value: pick('trialsCount', 'trials') },
    { label: '新客首响', value: pick('newLeadsCount', 'newLeads') }
  ].filter((c) => c.value !== null)
})

async function load() {
  loading.value = true
  try {
    await user.refreshMe()
    const [s] = await Promise.all([fetchTodaySummary()])
    summary.value = s
    fetchReviewPendingCount()
      .then((r) => {
        pendingReviews.value = typeof r === 'number' ? r : (r as { count?: number })?.count ?? null
      })
      .catch(() => {})
  } catch {
    /* http 层已 toast */
  } finally {
    loading.value = false
  }
}

onShow(() => {
  if (requireLogin()) load()
})

onPullDownRefresh(async () => {
  await load()
  uni.stopPullDownRefresh()
})

function go(url: string, tab = false) {
  if (tab) uni.switchTab({ url })
  else uni.navigateTo({ url })
}
</script>

<template>
  <view class="ym-page workbench" :style="{ paddingBottom: '40rpx' }">
    <view class="workbench__head">
      <view>
        <text class="workbench__hello">{{ greeting }}</text>
        <text class="ym-muted">{{ user.venue || '一麦瑜伽' }} · {{ (user.roles || []).join(' / ') }}</text>
      </view>
    </view>

    <view v-if="cards.length" class="workbench__cards">
      <view v-for="c in cards" :key="c.label" class="workbench__num-card">
        <text class="workbench__num">{{ c.value }}</text>
        <text class="workbench__num-label">{{ c.label }}</text>
      </view>
    </view>

    <view class="ym-card">
      <text class="ym-title">客户经营</text>
      <view class="workbench__grid">
        <view class="workbench__cell" @click="go('/pages/members/members')">
          <text class="workbench__cell-icon">☺</text>
          <text>会员管理</text>
        </view>
        <view class="workbench__cell" @click="go('/pages/leads/leads')">
          <text class="workbench__cell-icon">✚</text>
          <text>留资管理</text>
        </view>
        <view class="workbench__cell" @click="go('/pages/todo/todo', true)">
          <text class="workbench__cell-icon">✓</text>
          <text>今日待办</text>
        </view>
        <view class="workbench__cell" @click="go('/pages/tasks/tasks', true)">
          <text class="workbench__cell-icon">▤</text>
          <text>任务中心</text>
        </view>
        <view class="workbench__cell" @click="go('/pages/review/review')">
          <text class="workbench__cell-icon">✎</text>
          <text>课后分析</text>
          <text v-if="pendingReviews" class="workbench__badge">{{ pendingReviews }}</text>
        </view>
        <view class="workbench__cell" @click="go('/pages/plan/plan')">
          <text class="workbench__cell-icon">☷</text>
          <text>训练计划</text>
        </view>
        <view class="workbench__cell" @click="go('/pages/approvals/approvals')">
          <text class="workbench__cell-icon">✦</text>
          <text>审批</text>
        </view>
      </view>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.workbench {
  &__head {
    padding: 40rpx 24rpx 10rpx;
  }

  &__hello {
    display: block;
    font-size: 40rpx;
    font-weight: 600;
    margin-bottom: 8rpx;
  }

  &__cards {
    display: flex;
    padding: 10rpx 14rpx;
  }

  &__num-card {
    flex: 1;
    margin: 10rpx;
    background: #fff;
    border-radius: 20rpx;
    padding: 28rpx 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    box-shadow: 0 2rpx 12rpx rgba(0, 0, 0, 0.04);
  }

  &__num {
    font-size: 44rpx;
    font-weight: 700;
    color: #2e7d5b;
  }

  &__num-label {
    margin-top: 8rpx;
    font-size: 22rpx;
    color: #8a8f99;
  }

  &__grid {
    display: flex;
    flex-wrap: wrap;
    margin-top: 20rpx;
  }

  &__cell {
    width: 25%;
    min-height: 88rpx;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    font-size: 24rpx;
    color: #1f2329;
    position: relative;
  }

  &__cell-icon {
    font-size: 40rpx;
    margin-bottom: 10rpx;
    color: #2e7d5b;
  }

  &__badge {
    position: absolute;
    top: 0;
    right: 18rpx;
    background: #c0392b;
    color: #fff;
    font-size: 18rpx;
    min-width: 32rpx;
    height: 32rpx;
    line-height: 32rpx;
    text-align: center;
    border-radius: 999rpx;
  }
}
</style>
