<script setup lang="ts">
/**
 * 价格审批（店长初审 / 老板终审）
 *
 * 读：GET /approvals?size=50（后端按角色下发可见单据，403 = 无店长权限）
 * 写：POST /approvals/{id}/decide { decision }
 *
 * 视觉层（2026-11 重构）：待处理 / 已处理 / 全部 三组（纯前端分组，不新增请求），
 * 价格对比强调申请价，动作按钮按状态机与角色显隐。decide 语义零改动。
 */
import { computed, ref } from 'vue'
import { onShow, onPullDownRefresh } from '@dcloudio/uni-app'
import { requireLogin } from '@/utils/guard'
import { requireFeature } from '@/utils/nav'
import { fetchApprovals, decideApproval } from '@/api/approvals'
import { useUserStore } from '@/store/user'
import type { ApprovalItem } from '@/types'

const user = useUserStore()
const list = ref<ApprovalItem[]>([])
const loading = ref(false)
const loaded = ref(false)
const failed = ref(false)
const acting = ref(0)
const forbidden = ref(false)

const isSuper = computed(() => user.roles.includes('R_SUPER'))

/** 分组：待处理（两类待审）/ 已处理（终态）/ 全部 */
const GROUPS = [
  { key: 'pending', label: '待处理' },
  { key: 'done', label: '已处理' },
  { key: 'all', label: '全部' }
] as const
const group = ref<(typeof GROUPS)[number]['key']>('pending')

function isPending(a: ApprovalItem): boolean {
  return a.status === '待店长初审' || a.status === '待老板终审'
}

const viewList = computed(() => {
  if (group.value === 'pending') return list.value.filter(isPending)
  if (group.value === 'done') return list.value.filter((a) => !isPending(a))
  return list.value
})

const pendingTotal = computed(() => list.value.filter(isPending).length)

function onGroupChange(payload: { name: string | number }) {
  const k = String(payload.name)
  const hit = GROUPS.find((g) => g.key === k)
  if (hit) group.value = hit.key
}

async function load() {
  loading.value = true
  forbidden.value = false
  try {
    const res = await fetchApprovals({ size: 50 })
    list.value = res.records
    loaded.value = true
    failed.value = false
  } catch (e) {
    if ((e as { code?: number })?.code === 403) forbidden.value = true
    else failed.value = true
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
  if (!requireLogin()) return
  if (!requireFeature('approvals')) return
  load()
})

onPullDownRefresh(async () => {
  await load()
  uni.stopPullDownRefresh()
})

function fen(v: number) {
  return (v / 100).toFixed(0)
}

function statusTone(s: string): 'brand' | 'gold' | 'warn' | 'danger' | 'muted' {
  if (s === '已通过' || s === '已关联成交') return 'brand'
  if (s === '已驳回') return 'danger'
  if (s === '待老板终审') return 'gold'
  return 'warn'
}
</script>

<template>
  <view class="ym-page approvals">
    <view v-if="!forbidden" class="filters">
      <wd-tabs :model-value="group" :slidable-num="4" @change="onGroupChange">
        <wd-tab v-for="g in GROUPS" :key="g.key" :title="g.label" :name="g.key" />
      </wd-tabs>
    </view>

    <view v-if="!forbidden" class="hint">
      <text class="hint__text ym-ellipsis">按角色下发：店长初审 / 老板终审</text>
      <text class="hint__count ym-num">{{ pendingTotal }} 单待处理</text>
    </view>

    <!-- 三态 -->
    <ym-empty
      v-if="forbidden"
      icon="lock-on"
      text="仅店长及以上可查看审批"
      desc="当前账号没有价格审批权限"
    />

    <view v-else-if="failed && !list.length" class="ym-card">
      <ym-empty
        icon="warning"
        text="审批单加载失败"
        desc="网络或服务异常，可下拉刷新重试"
        action-text="重试"
        @action="load"
      />
    </view>

    <view v-else-if="loading && !list.length" class="ym-card">
      <view v-for="i in 3" :key="i" class="sk-row">
        <wd-skeleton
          theme="text"
          animation="gradient"
          :row-col="[{ width: '44%' }, { width: '82%' }, { width: '56%' }]"
        />
      </view>
    </view>

    <ym-empty
      v-else-if="loaded && !viewList.length"
      icon="evaluation"
      text="没有待处理的审批单"
      desc="切换分组看看历史单据"
      action-text="刷新"
      @action="load"
    />

    <view v-for="a in viewList" :key="a.id" class="acard">
      <view class="acard__head">
        <text class="acard__name ym-ellipsis">{{ a.customerName }}</text>
        <ym-badge :text="a.status" :tone="statusTone(a.status)" />
      </view>

      <text class="acard__card ym-ellipsis">卡项 · {{ a.cardName }}</text>

      <view class="acard__price">
        <view class="acard__price-cell">
          <text class="acard__price-label">申请价</text>
          <text class="acard__price-value acard__price-value--main ym-num">¥{{ fen(a.requestPrice) }}</text>
        </view>
        <view class="acard__price-divider" />
        <view class="acard__price-cell">
          <text class="acard__price-label">标准价</text>
          <text class="acard__price-value ym-num">¥{{ fen(a.standardPrice) }}</text>
        </view>
      </view>

      <view class="acard__foot">
        <text class="acard__meta ym-ellipsis">{{ a.applicant }} · {{ a.venue }}</text>
        <text class="acard__meta ym-num">{{ a.applyTime }}</text>
      </view>

      <view v-if="a.reason" class="acard__reason">
        <text class="acard__reason-label">申请理由</text>
        <text class="acard__reason-text">{{ a.reason }}</text>
      </view>

      <view v-if="actions(a).length" class="acard__acts">
        <view v-for="act2 in actions(a)" :key="act2.decision" class="acard__actwrap">
          <wd-button
            size="medium"
            block
            :type="act2.danger ? 'error' : 'primary'"
            :plain="act2.danger"
            :loading="acting === a.id"
            :disabled="acting === a.id"
            @click="act(a, act2.decision)"
          >
            {{ act2.label }}
          </wd-button>
        </view>
      </view>
    </view>
  </view>
</template>

<style lang="scss" scoped>
@import '@/styles/tokens.scss';

.approvals {
  --wot-tabs-nav-height: 88rpx;
  --wot-tabs-nav-fs: 27rpx;
  --wot-button-medium-height: 88rpx;
  --wot-button-medium-fs: 27rpx;
}

/* ---------- 分组页签 ---------- */
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

/* ---------- 审批卡 ---------- */
.acard {
  padding: 26rpx 28rpx;
  margin: 20rpx 24rpx;
  background: $card-bg;
  border-radius: $r-lg;
  box-shadow: $shadow-card;
}

.acard__head {
  display: flex;
  gap: 14rpx;
  align-items: center;
  min-width: 0;
}

.acard__name {
  flex: 1;
  min-width: 0;
  font-size: 32rpx;
  font-weight: 600;
  color: $ink-800;
}

.acard__card {
  display: block;
  margin-top: 10rpx;
  font-size: 24rpx;
  color: $ink-500;
}

.acard__price {
  display: flex;
  align-items: center;
  padding: 18rpx 0;
  margin-top: 16rpx;
  background: $ink-50;
  border-radius: $r-sm;
}

.acard__price-cell {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 6rpx;
  align-items: center;
}

.acard__price-divider {
  width: 2rpx;
  height: 44rpx;
  background: $hairline;
}

.acard__price-label {
  font-size: 20rpx;
  color: $ink-400;
}

.acard__price-value {
  font-size: 28rpx;
  font-weight: 500;
  color: $ink-600;
}

.acard__price-value--main {
  font-size: 34rpx;
  font-weight: 600;
  color: $brand-600;
}

.acard__foot {
  display: flex;
  gap: 16rpx;
  align-items: center;
  justify-content: space-between;
  margin-top: 16rpx;
}

.acard__meta {
  display: block;
  min-width: 0;
  font-size: 22rpx;
  color: $ink-400;
}

.acard__reason {
  padding: 16rpx 20rpx;
  margin-top: 16rpx;
  background: $warn-100;
  border-radius: $r-sm;
}

.acard__reason-label {
  display: block;
  margin-bottom: 6rpx;
  font-size: 20rpx;
  color: $warn-600;
}

.acard__reason-text {
  font-size: 24rpx;
  line-height: 1.5;
  color: $ink-700;
}

.acard__acts {
  display: flex;
  gap: 16rpx;
  margin-top: 20rpx;
}

.acard__actwrap {
  flex: 1;
  min-width: 0;
}
</style>
