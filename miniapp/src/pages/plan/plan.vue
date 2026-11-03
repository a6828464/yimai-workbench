<script setup lang="ts">
import { ref } from 'vue'
import { onShow, onPullDownRefresh } from '@dcloudio/uni-app'
import { requireLogin } from '@/utils/guard'
import { fetchTrainingPlans } from '@/api/review'

interface PlanRow {
  id: number
  customerName?: string
  memberName?: string
  title?: string
  weeks?: number
  status?: string
  updatedAt?: string
  [key: string]: unknown
}

const list = ref<PlanRow[]>([])
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    const res = await fetchTrainingPlans({ size: 50 })
    list.value = Array.isArray(res) ? (res as unknown as PlanRow[]) : ((res as { records?: PlanRow[] })?.records ?? [])
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
</script>

<template>
  <view class="ym-page">
    <view v-if="!loading && !list.length" class="ym-empty">暂无训练计划</view>

    <view v-for="p in list" :key="p.id" class="ym-card">
      <view class="ym-row--between">
        <text class="ym-title">{{ p.customerName || p.memberName || p.title || '训练计划' }}</text>
        <text v-if="p.status" class="ym-tag">{{ p.status }}</text>
      </view>
      <text v-if="p.weeks" class="ym-muted plan__line">周期：{{ p.weeks }} 周</text>
      <text class="ym-muted plan__line">更新：{{ p.updatedAt || '—' }}</text>
    </view>

    <view class="plan__tip ym-muted">计划的编排与调整请在电脑端完成</view>
  </view>
</template>

<style lang="scss" scoped>
.plan__line {
  display: block;
  margin-top: 10rpx;
}

.plan__tip {
  text-align: center;
  padding: 40rpx 0;
}
</style>
