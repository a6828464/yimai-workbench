<script setup lang="ts">
/**
 * 会员管理（移动端 P0：查 + 看卡项）
 *
 * 口径来自 docs/miniapp-migration/03-页面迁移分级矩阵.md §2.2 #2：
 * 「只搬『查 + 看卡项』，详情编辑留桌面」——所以本页只读，写动作收敛到详情页的
 * 4 个白名单字段（最近沟通/生日/待复活/续课预报）。
 *
 * 数据同源保证：清单徽标走 /customers/list-counts?type=member，列表走
 * /customers?type=member&list=<清单名>，两者在后端共用 scopedCustomerQuery()，
 * 不会出现「徽标 4、点进去 3 行」。
 */
import { computed, ref } from 'vue'
import { onPullDownRefresh, onReachBottom, onShow } from '@dcloudio/uni-app'
import { requireLogin } from '@/utils/guard'
import {
  MEMBER_LIST_OF_TAB,
  MEMBER_TABS,
  fetchCustomers,
  fetchMemberListCounts
} from '@/api/members'
import type { MiniappCustomer, RenewalReason } from '@/types'

const tab = ref('all')
const keyword = ref('')
/** 已提交的搜索词（与输入框解耦，避免边打字边发请求） */
const submitted = ref('')
const list = ref<MiniappCustomer[]>([])
const counts = ref<Record<string, number>>({})
const reasons = ref<Record<string, RenewalReason>>({})
const total = ref(0)
const page = ref(1)
const loading = ref(false)
const loaded = ref(false)

const tabs = computed(() =>
  MEMBER_TABS.map((t) => ({
    ...t,
    count: t.key === 'all' ? '' : String(counts.value[MEMBER_LIST_OF_TAB[t.key]] ?? '')
  }))
)

/** 纯数字按手机号查，其余按姓名查——后端是两个独立参数 */
function keywordParams(): { name?: string; phone?: string } {
  const k = submitted.value.trim()
  if (!k) return {}
  return /^\d+$/.test(k) ? { phone: k } : { name: k }
}

async function load(reset = false) {
  if (loading.value) return
  if (reset) {
    page.value = 1
  }
  loading.value = true
  try {
    const res = await fetchCustomers({
      ...keywordParams(),
      list: tab.value === 'all' ? undefined : MEMBER_LIST_OF_TAB[tab.value],
      current: page.value,
      size: 20
    })
    total.value = res.total ?? res.records?.length ?? 0
    list.value = reset ? (res.records ?? []) : [...list.value, ...(res.records ?? [])]
    // 理由整体替换：翻页/切页签后旧页的解释必须失效
    reasons.value = res.renewalReasons ?? {}
    loaded.value = true
  } catch {
    /* http 层已 toast */
  } finally {
    loading.value = false
  }
}

async function loadCounts() {
  try {
    const res = await fetchMemberListCounts('member')
    counts.value = res.counts ?? {}
  } catch {
    /* 徽标失败不阻塞列表 */
  }
}

function switchTab(key: string) {
  if (tab.value === key) return
  tab.value = key
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

function toDetail(row: MiniappCustomer) {
  uni.navigateTo({ url: `/pages/members/detail?id=${row.id}` })
}

/** 清单归属标签：优先用后端给的 primary，避免前端自己猜规则 */
function listTag(row: MiniappCustomer): string {
  const r = reasons.value[row.id]
  return r?.primary || (row.inRevive ? '待复活' : '')
}

function remainText(row: MiniappCustomer): string {
  if (row.remainTimes === null || row.remainTimes === undefined) return '—'
  return `${row.remainTimes}`
}

function expireText(row: MiniappCustomer): string {
  if (!row.expireDate) return '—'
  return row.expireDate.slice(0, 10)
}

function attendText(row: MiniappCustomer): string {
  return `${row.attendM3 ?? 0}`
}

function reasonLine(row: MiniappCustomer): string {
  const r = reasons.value[row.id]
  if (!r) return ''
  const first = (r.why ?? [])[0]
  return first || ''
}

onShow(() => {
  if (requireLogin()) {
    loadCounts()
    load(true)
  }
})

onPullDownRefresh(async () => {
  await Promise.all([loadCounts(), load(true)])
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
  <view class="ym-page members">
    <view class="members__search">
      <input
        v-model="keyword"
        class="members__input"
        type="text"
        placeholder="搜姓名 或 手机号/尾号"
        confirm-type="search"
        @confirm="search"
      />
      <text v-if="keyword" class="members__clear" @click="clearSearch">✕</text>
      <text class="members__go" @click="search">搜索</text>
    </view>

    <scroll-view class="members__tabs" scroll-x :show-scrollbar="false">
      <view class="members__tabs-inner">
        <view
          v-for="t in tabs"
          :key="t.key"
          class="members__tab"
          :class="{ 'members__tab--active': tab === t.key }"
          @click="switchTab(t.key)"
        >
          <text>{{ t.label }}</text>
          <text v-if="t.count" class="members__tab-count">{{ t.count }}</text>
        </view>
      </view>
    </scroll-view>

    <view class="members__hint">
      <text class="ym-muted">{{ tab === 'all' ? '全部正式会员（不含未成交客资）' : tabs.find((t) => t.key === tab)?.hint }}</text>
    </view>

    <view v-for="row in list" :key="row.id" class="ym-card member-card" @click="toDetail(row)">
      <view class="member-card__head">
        <text class="member-card__name">{{ row.name }}</text>
        <text v-if="listTag(row)" class="ym-tag ym-tag--danger">{{ listTag(row) }}</text>
        <text class="member-card__phone">{{ row.phone || `尾号${row.phoneTail}` }}</text>
      </view>

      <view class="member-card__sub">
        <text class="ym-muted">{{ row.venue }}<text v-if="row.consultant"> · {{ row.consultant }}</text></text>
        <text class="ym-muted member-card__layer">{{ row.layer }}</text>
      </view>

      <view class="member-card__metrics">
        <view class="member-card__metric">
          <text class="member-card__metric-value">{{ remainText(row) }}</text>
          <text class="member-card__metric-label">剩余课时</text>
        </view>
        <view class="member-card__metric">
          <text class="member-card__metric-value">{{ expireText(row) }}</text>
          <text class="member-card__metric-label">到期</text>
        </view>
        <view class="member-card__metric">
          <text class="member-card__metric-value">{{ attendText(row) }}</text>
          <text class="member-card__metric-label">近30天出勤</text>
        </view>
      </view>

      <view v-if="row.mainCard" class="member-card__card-name">
        <text class="ym-muted">主卡：{{ row.mainCard }}</text>
      </view>

      <view v-if="reasonLine(row)" class="member-card__reason">
        <text class="member-card__reason-label">为什么在清单里</text>
        <text class="member-card__reason-text">{{ reasonLine(row) }}</text>
      </view>
    </view>

    <view v-if="loaded && !list.length" class="ym-empty">没有符合条件的会员</view>
    <view v-if="loading" class="ym-empty">加载中…</view>
    <view v-else-if="list.length && list.length >= total" class="members__end">
      <text class="ym-muted">共 {{ total }} 位会员</text>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.members {
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

  &__tab-count {
    margin-left: 10rpx;
    font-size: 22rpx;
    opacity: 0.85;
  }

  &__hint {
    padding: 16rpx 30rpx 0;
  }

  &__end {
    text-align: center;
    padding: 30rpx 0 10rpx;
  }
}

.member-card {
  &__head {
    display: flex;
    align-items: center;
    gap: 12rpx;
  }

  &__name {
    font-size: 32rpx;
    font-weight: 600;
  }

  &__phone {
    margin-left: auto;
    font-size: 24rpx;
    color: #4e5969;
  }

  &__sub {
    display: flex;
    align-items: center;
    margin-top: 8rpx;
  }

  &__layer {
    margin-left: auto;
  }

  &__metrics {
    display: flex;
    margin-top: 20rpx;
    background: #f7f8fa;
    border-radius: 14rpx;
    padding: 18rpx 0;
  }

  &__metric {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
  }

  &__metric-value {
    font-size: 30rpx;
    font-weight: 600;
    color: #1f2329;
  }

  &__metric-label {
    margin-top: 6rpx;
    font-size: 20rpx;
    color: #8a8f99;
  }

  &__card-name {
    margin-top: 14rpx;
  }

  &__reason {
    margin-top: 14rpx;
    padding: 14rpx 18rpx;
    background: #fdf0e6;
    border-radius: 12rpx;
  }

  &__reason-label {
    display: block;
    font-size: 20rpx;
    color: #c26a1b;
    margin-bottom: 6rpx;
  }

  &__reason-text {
    font-size: 24rpx;
    color: #8a4a10;
    line-height: 1.5;
  }
}
</style>
