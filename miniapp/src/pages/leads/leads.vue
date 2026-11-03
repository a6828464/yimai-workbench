<script setup lang="ts">
/**
 * 留资管理（移动端 P0：店门口记一条 + 现场改状态）
 *
 * 读：GET /leads（服务端分页，响应带 enums = 渠道权威清单）
 * 写：新增/编辑在 pages/leads/form；本页只负责查与进入
 *
 * 权限：按人隔离走后端 scopeLeadsForUser()（服务老师=本人名下+待承接池，
 * 授课老师=本人客资+上过体验课的+私教学员，新媒体=本人录入）。
 * 前端不做二次过滤——过滤只能有一处，否则口径迟早分叉。
 */
import { computed, ref } from 'vue'
import { onPullDownRefresh, onReachBottom, onShow } from '@dcloudio/uni-app'
import { requireLogin } from '@/utils/guard'
import { LEAD_STATUSES, fetchLeads, stageLeadDraft } from '@/api/leads'
import type { LeadEnums, MiniappLead } from '@/types'

const keyword = ref('')
const submitted = ref('')
const status = ref('')
const list = ref<MiniappLead[]>([])
const enums = ref<LeadEnums | null>(null)
const total = ref(0)
const page = ref(1)
const loading = ref(false)
const loaded = ref(false)

const statusChips = computed(() => ['全部', ...LEAD_STATUSES])

function keywordParams(): { name?: string; phone?: string } {
  const k = submitted.value.trim()
  if (!k) return {}
  return /^\d+$/.test(k) ? { phone: k } : { name: k }
}

async function load(reset = false) {
  if (loading.value) return
  if (reset) page.value = 1
  loading.value = true
  try {
    const res = await fetchLeads({
      ...keywordParams(),
      status: status.value || undefined,
      current: page.value,
      size: 20
    })
    total.value = res.total ?? res.records?.length ?? 0
    list.value = reset ? (res.records ?? []) : [...list.value, ...(res.records ?? [])]
    if (res.enums?.sources?.length) enums.value = res.enums
    loaded.value = true
  } catch {
    /* http 层已 toast */
  } finally {
    loading.value = false
  }
}

function pickStatus(s: string) {
  status.value = s === '全部' ? '' : s
  load(true)
}

function search() {
  submitted.value = keyword.value
  load(true)
}

function clearSearch() {
  keyword.value = ''
  submitted.value = ''
  load(true)
}

function statusClass(s: string): string {
  if (s === '已成交') return 'lead-card__status--ok'
  if (s === '已流失') return 'lead-card__status--mute'
  if (s === '爽约' || s === '新留资') return 'lead-card__status--danger'
  return 'lead-card__status--warn'
}

function createNew() {
  uni.navigateTo({ url: '/pages/leads/form' })
}

function edit(row: MiniappLead) {
  // 后端没有 GET /leads/{id}，列表行就是完整记录 → 本地草稿带过去
  stageLeadDraft(row)
  uni.navigateTo({ url: `/pages/leads/form?mode=edit&id=${row.id}` })
}

function metaLine(row: MiniappLead): string {
  const parts = [row.leadDate?.slice(0, 10) || '', row.source || '', row.venue || '']
  return parts.filter(Boolean).join(' · ')
}

function trialLine(row: MiniappLead): string {
  const cards = row.trialCards ?? []
  if (!cards.length) return ''
  return cards
    .map((c) => `${c.time || '待定'} ${c.topic || ''} ${c.teacher || ''}`.trim())
    .filter(Boolean)
    .join('；')
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
  <view class="ym-page leads">
    <view class="leads__search">
      <input
        v-model="keyword"
        class="leads__input"
        type="text"
        placeholder="搜姓名 或 手机号/尾号/微信"
        confirm-type="search"
        @confirm="search"
      />
      <text v-if="keyword" class="leads__clear" @click="clearSearch">✕</text>
      <text class="leads__go" @click="search">搜索</text>
    </view>

    <scroll-view class="leads__tabs" scroll-x :show-scrollbar="false">
      <view class="leads__tabs-inner">
        <view
          v-for="s in statusChips"
          :key="s"
          class="leads__tab"
          :class="{ 'leads__tab--active': (status || '全部') === s }"
          @click="pickStatus(s)"
        >
          {{ s }}
        </view>
      </view>
    </scroll-view>

    <view v-for="row in list" :key="row.id" class="ym-card lead-card" @click="edit(row)">
      <view class="lead-card__head">
        <text class="lead-card__name">{{ row.name }}</text>
        <text class="lead-card__status" :class="statusClass(row.status)">{{ row.status }}</text>
        <text class="lead-card__phone">{{ row.phone || `尾号${row.phoneTail || ''}` }}</text>
      </view>

      <text class="ym-muted lead-card__meta">{{ metaLine(row) }}</text>

      <view v-if="row.demand" class="lead-card__line">
        <text class="lead-card__label">需求：</text>
        <text>{{ row.demand }}</text>
      </view>
      <view v-if="trialLine(row)" class="lead-card__line">
        <text class="lead-card__label">体验课：</text>
        <text>{{ trialLine(row) }}</text>
      </view>
      <view v-if="row.dealAmount" class="lead-card__line">
        <text class="lead-card__label">成交：</text>
        <text>¥{{ row.dealAmount }}<text v-if="row.dealCard"> · {{ row.dealCard }}</text></text>
      </view>
      <view v-if="row.remark" class="lead-card__line">
        <text class="lead-card__label">备注：</text>
        <text>{{ row.remark }}</text>
      </view>
    </view>

    <view v-if="loaded && !list.length" class="ym-empty">没有符合条件的留资</view>
    <view v-if="loading" class="ym-empty">加载中…</view>
    <view v-else-if="list.length && list.length >= total" class="leads__end">
      <text class="ym-muted">共 {{ total }} 条留资</text>
    </view>

    <view class="leads__fab" @click="createNew">
      <text class="leads__fab-plus">＋</text>
      <text class="leads__fab-text">记一条</text>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.leads {
  padding-bottom: 200rpx;

  &__search {
    display: flex;
    align-items: center;
    background: #fff;
    margin: 20rpx 24rpx 0;
    padding: 0 24rpx;
    height: 84rpx;
    border-radius: 16rpx;
  }

  &__input {
    flex: 1;
    font-size: 28rpx;
  }

  &__clear {
    color: #b8bcc4;
    font-size: 28rpx;
    padding: 0 16rpx;
  }

  &__go {
    color: #2e7d5b;
    font-size: 28rpx;
    padding-left: 16rpx;
  }

  &__tabs {
    margin-top: 18rpx;
    white-space: nowrap;
  }

  &__tabs-inner {
    display: inline-flex;
    padding: 0 24rpx;
  }

  &__tab {
    display: inline-flex;
    align-items: center;
    height: 68rpx;
    padding: 0 24rpx;
    margin-right: 16rpx;
    border-radius: 999rpx;
    background: #fff;
    font-size: 26rpx;
    color: #4e5969;
  }

  &__tab--active {
    background: #2e7d5b;
    color: #fff;
  }

  &__end {
    text-align: center;
    padding: 30rpx 0 10rpx;
  }

  &__fab {
    position: fixed;
    right: 32rpx;
    bottom: 60rpx;
    display: flex;
    align-items: center;
    gap: 8rpx;
    height: 96rpx;
    padding: 0 32rpx;
    border-radius: 999rpx;
    background: #2e7d5b;
    color: #fff;
    box-shadow: 0 8rpx 24rpx rgba(46, 125, 91, 0.35);
  }

  &__fab-plus {
    font-size: 40rpx;
    line-height: 1;
  }

  &__fab-text {
    font-size: 28rpx;
  }
}

.lead-card {
  &__head {
    display: flex;
    align-items: center;
    gap: 14rpx;
  }

  &__name {
    font-size: 32rpx;
    font-weight: 600;
  }

  &__status {
    font-size: 20rpx;
    padding: 4rpx 14rpx;
    border-radius: 999rpx;
  }

  &__status--ok {
    background: #e8f5ee;
    color: #1f7a4c;
  }

  &__status--warn {
    background: #eef4f1;
    color: #2e7d5b;
  }

  &__status--danger {
    background: #fdeceb;
    color: #c0392b;
  }

  &__status--mute {
    background: #f2f3f5;
    color: #8a8f99;
  }

  &__phone {
    margin-left: auto;
    font-size: 24rpx;
    color: #4e5969;
  }

  &__meta {
    display: block;
    margin-top: 10rpx;
  }

  &__line {
    margin-top: 12rpx;
    font-size: 26rpx;
    line-height: 1.5;
  }

  &__label {
    color: #8a8f99;
  }
}
</style>
