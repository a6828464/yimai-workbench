<script setup lang="ts">
import { ref, computed } from 'vue'
import { onShow, onPullDownRefresh } from '@dcloudio/uni-app'
import { requireLogin } from '@/utils/guard'
import { fetchApprovals, decideApproval } from '@/api/approvals'
import { useUserStore } from '@/store/user'
import type { ApprovalItem } from '@/types'

const user = useUserStore()
const list = ref<ApprovalItem[]>([])
const loading = ref(false)
const acting = ref(0)
const forbidden = ref(false)

const isSuper = computed(() => user.roles.includes('R_SUPER'))

async function load() {
  loading.value = true
  forbidden.value = false
  try {
    const res = await fetchApprovals({ size: 50 })
    list.value = res.records
  } catch (e) {
    if ((e as { code?: number })?.code === 403) forbidden.value = true
  } finally {
    loading.value = false
  }
}

/** 与 ApprovalController::decide 的状态机对齐 */
function actions(a: ApprovalItem): { label: string; decision: '初审通过' | '终审通过' | '驳回'; danger?: boolean }[] {
  if (a.status === '待店长初审') {
    return [
      { label: '初审通过', decision: '初审通过' },
      { label: '驳回', decision: '驳回', danger: true }
    ]
  }
  if (a.status === '待老板终审' && isSuper.value) {
    return [
      { label: '终审通过', decision: '终审通过' },
      { label: '驳回', decision: '驳回', danger: true }
    ]
  }
  return []
}

async function act(a: ApprovalItem, decision: '初审通过' | '终审通过' | '驳回') {
  if (acting.value === a.id) return
  acting.value = a.id
  try {
    await decideApproval(a.id, decision)
    uni.showToast({ title: '已提交', icon: 'success' })
    await load()
  } catch {
    /* http 层已 toast */
  } finally {
    acting.value = 0
  }
}

onShow(() => {
  if (requireLogin()) load()
})

onPullDownRefresh(async () => {
  await load()
  uni.stopPullDownRefresh()
})

function fen(v: number) {
  return (v / 100).toFixed(0)
}
</script>

<template>
  <view class="ym-page">
    <view v-if="forbidden" class="ym-empty">仅店长及以上可查看审批</view>
    <view v-else-if="!loading && !list.length" class="ym-empty">暂无审批单</view>

    <view v-for="a in list" :key="a.id" class="ym-card">
      <view class="ym-row--between">
        <text class="ym-title">{{ a.customerName }}</text>
        <text class="ym-tag" :class="{ 'ym-tag--warn': a.status.startsWith('待') }">{{ a.status }}</text>
      </view>
      <text class="ym-muted approvals__line">卡项：{{ a.cardName }}</text>
      <text class="ym-muted approvals__line">
        申请价 ¥{{ fen(a.requestPrice) }} / 标准价 ¥{{ fen(a.standardPrice) }}
      </text>
      <text class="ym-muted approvals__line">申请人：{{ a.applicant }} · {{ a.venue }} · {{ a.applyTime }}</text>
      <text v-if="a.reason" class="ym-muted approvals__line">理由：{{ a.reason }}</text>

      <view v-if="actions(a).length" class="approvals__actions">
        <button
          v-for="act2 in actions(a)"
          :key="act2.decision"
          class="ym-btn approvals__btn"
          :class="{ 'approvals__btn--danger': act2.danger }"
          :disabled="acting === a.id"
          @click="act(a, act2.decision)"
        >
          {{ act2.label }}
        </button>
      </view>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.approvals__line {
  display: block;
  margin-top: 10rpx;
}

.approvals__actions {
  display: flex;
  gap: 16rpx;
  margin-top: 20rpx;
}

.approvals__btn {
  flex: 1;
  min-height: 88rpx;
  font-size: 26rpx;
  margin: 0;

  &--danger {
    background: #fff;
    color: #c0392b;
    border: 2rpx solid #c0392b;
  }
}
</style>
