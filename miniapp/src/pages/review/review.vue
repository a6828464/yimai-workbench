<script setup lang="ts">
import { ref } from 'vue'
import { onShow, onPullDownRefresh } from '@dcloudio/uni-app'
import { requireLogin } from '@/utils/guard'
import { fetchReviews } from '@/api/review'

interface ReviewRow {
  id: number
  customerName?: string
  memberName?: string
  teacherName?: string
  courseName?: string
  classDate?: string
  status?: string
  createdAt?: string
  [key: string]: unknown
}

const list = ref<ReviewRow[]>([])
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    const res = await fetchReviews({ size: 50 })
    const r = res as unknown as { records?: ReviewRow[]; items?: ReviewRow[] }
    list.value = r.records ?? r.items ?? (Array.isArray(res) ? (res as unknown as ReviewRow[]) : [])
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

function statusTag(s?: string) {
  if (!s) return ''
  if (s.includes('已确认') || s.includes('已分享')) return 'ym-tag'
  if (s.includes('待')) return 'ym-tag ym-tag--warn'
  return 'ym-tag'
}
</script>

<template>
  <view class="ym-page">
    <view v-if="!loading && !list.length" class="ym-empty">暂无课后分析</view>

    <view v-for="r in list" :key="r.id" class="ym-card">
      <view class="ym-row--between">
        <text class="ym-title">{{ r.customerName || r.memberName || '会员' }}</text>
        <text v-if="r.status" :class="statusTag(r.status)">{{ r.status }}</text>
      </view>
      <text class="ym-muted review__line">课程：{{ r.courseName || '—' }} · {{ r.classDate || '—' }}</text>
      <text class="ym-muted review__line">老师：{{ r.teacherName || '—' }}</text>
      <text class="ym-muted review__line">创建：{{ r.createdAt || '—' }}</text>
    </view>

    <view class="review__tip ym-muted">编辑与确认请在电脑端完成，手机端先看列表与状态</view>
  </view>
</template>

<style lang="scss" scoped>
.review__line {
  display: block;
  margin-top: 10rpx;
}

.review__tip {
  text-align: center;
  padding: 40rpx 0;
}
</style>
