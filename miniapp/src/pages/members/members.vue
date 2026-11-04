<script setup lang="ts">
/**
 * 会员管理（移动端 P0：查 + 看卡项）
 *
 * 口径来自 docs/miniapp-migration/03-页面迁移分级矩阵.md §2.2 #2：
 * 「只搬『查 + 看卡项』，详情编辑留桌面」——所以本页只读，写动作收敛到详情页的
 * 3 个白名单字段（最近沟通/生日/待复活）。
 *
 * 数据同源保证：清单徽标走 /customers/list-counts?type=member，列表走
 * /customers?type=member&list=<清单名>，两者在后端共用 scopedCustomerQuery()，
 * 不会出现「徽标 4、点进去 3 行」。
 *
 * 视觉层（2026-11 重构）：只重做骨架——筛选头（wd-search + wd-tabs + 排序下拉）、
 * 白卡列表、三态（骨架/空/失败）、分页尾。请求参数与后端语义零改动。
 */
import { computed, ref } from 'vue'
import { onLoad, onPullDownRefresh, onReachBottom, onShow } from '@dcloudio/uni-app'
import { requireLogin } from '@/utils/guard'
import { requireFeature } from '@/utils/nav'
import { useVenueFilter } from '@/utils/venue'
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
const failed = ref(false)

/**
 * 门店筛选（口径见 utils/venue.ts，与留资页同一份实现）。
 *
 * 判据：**账号授权门店数 > 1**（不再只按超管）——两个门店权限的账号此前在会员页
 * 看不到切换标签，名单被永久钉在一家店上。单店账号仍只显示锁定标签：
 * 后端 scopeCustomersForUser 对该账号锁本店，给了 picker 也是假选项。
 * 选择结果落 storage，切页返回仍生效。
 */
const venueFilter = useVenueFilter()
const canSwitchVenue = venueFilter.canSwitch
const venueOptions = venueFilter.options
const venue = venueFilter.venue
/** 只有真的选了单店才发 venue 参数（"全部门店" 不发，保持后端默认范围） */
const venueParam = venueFilter.venueParam
/** 单店账号展示的锁定标签 */
const lockedVenue = venueFilter.lockedLabel

/** 排序：纯前端重排已取到的行，不新增接口、不改请求参数 */
const SORTS = [
  { label: '默认排序', value: 'default' },
  { label: '到期最近', value: 'expire' },
  { label: '剩余课时少', value: 'remain' },
  { label: '最近到店', value: 'visit' }
]
const sort = ref('default')

const tabs = computed(() =>
  MEMBER_TABS.map((t) => {
    // 徽标来自 /customers/list-counts（不带 venue），选了门店就不展示，
    // 否则会出现「徽标 4、点进去 3 行」——宁可少一个数字，也不给错数字。
    const n = !venueParam.value && t.key !== 'all' ? String(counts.value[MEMBER_LIST_OF_TAB[t.key]] ?? '') : ''
    return { ...t, count: n, title: n ? `${t.label} ${n}` : t.label }
  })
)

/** 中文清单名 → 页签 key（由 MEMBER_LIST_OF_TAB 反查，口径只有一份） */
const TAB_OF_LIST: Record<string, string> = Object.fromEntries(
  Object.entries(MEMBER_LIST_OF_TAB).map(([key, list]) => [list, key])
)

const hint = computed(() => {
  const base =
    tab.value === 'all'
      ? '全部正式会员（不含未成交客资）'
      : tabs.value.find((t) => t.key === tab.value)?.hint ?? ''
  return venueParam.value ? `${venueParam.value} · ${base}` : base
})

/** 展示顺序（排序只在内存里做，接口顺序仍是后端权威顺序） */
const rows = computed(() => {
  const base = list.value
  if (sort.value === 'default' || base.length < 2) return base
  const copy = [...base]
  if (sort.value === 'expire') {
    copy.sort((a, b) => String(a.expireDate || '9999-12-31').localeCompare(String(b.expireDate || '9999-12-31')))
  } else if (sort.value === 'remain') {
    const n = (v: number | null) => (v === null || v === undefined ? Number.MAX_SAFE_INTEGER : v)
    copy.sort((a, b) => n(a.remainTimes) - n(b.remainTimes))
  } else if (sort.value === 'visit') {
    copy.sort((a, b) => String(b.lastVisit || '').localeCompare(String(a.lastVisit || '')))
  }
  return copy
})

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
      venue: venueParam.value,
      current: page.value,
      size: 20
    })
    total.value = res.total ?? res.records?.length ?? 0
    list.value = reset ? (res.records ?? []) : [...list.value, ...(res.records ?? [])]
    // 理由整体替换：翻页/切页签后旧页的解释必须失效
    reasons.value = res.renewalReasons ?? {}
    loaded.value = true
    failed.value = false
  } catch {
    /* http 层已 toast */
    failed.value = true
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

function onTabChange(payload: { name: string | number }) {
  switchTab(String(payload.name))
}

/** 切换门店：记住这次选择，再回到第一页重新拉（清空已加载行） */
function onVenueChange() {
  venueFilter.commit()
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

/** 分层色：P0/P1 主色，P2 金，P3 信息，P4 警示，P5 静默 */
function layerTone(layer?: string): 'brand' | 'gold' | 'warn' | 'info' | 'muted' {
  if (layer === 'P0' || layer === 'P1') return 'brand'
  if (layer === 'P2') return 'gold'
  if (layer === 'P3') return 'info'
  if (layer === 'P4') return 'warn'
  return 'muted'
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

/**
 * 深链：/pages/members/members?list=待续课&venue=东部店
 * 参数只做**初值**，进入后还能手动改页签/门店/搜索；onShow 不再覆盖用户的选择。
 */
onLoad((options) => {
  const opt = (options ?? {}) as Record<string, string>
  const raw = String(opt.list ?? '').trim()
  // 中文清单名优先反查（?list=待续课 → renewal）；也兼容直接传页签 key（?list=renewal）
  const key = TAB_OF_LIST[raw] || (raw === 'all' || MEMBER_LIST_OF_TAB[raw] ? raw : '')
  if (key) tab.value = key
  // venue 深链是明确意图 → 同样落盘（下次进页面沿用这家店）
  const v = String(opt.venue ?? '').trim()
  if (v && canSwitchVenue.value) venueFilter.apply(v)
})

onShow(() => {
  if (!requireLogin()) return
  if (!requireFeature('members')) return
  loadCounts()
  load(true)
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
    <!-- 筛选头：搜索 + 清单页签 + 排序（全部是既有能力，仅换表现层） -->
    <view class="filters">
      <wd-search
        v-model="keyword"
        placeholder="搜姓名 或 手机号/尾号"
        cancel-txt="搜索"
        @search="search"
        @cancel="search"
        @clear="clearSearch"
      />

      <wd-tabs v-model="tab" :slidable-num="6" @change="onTabChange">
        <wd-tab v-for="t in tabs" :key="t.key" :title="t.title" :name="t.key" />
      </wd-tabs>

      <view class="filters__row">
        <view class="filters__cell">
          <wd-drop-menu v-if="canSwitchVenue">
            <wd-drop-menu-item v-model="venue" :options="venueOptions" @change="onVenueChange" />
          </wd-drop-menu>
          <view v-else class="vlock">
            <wd-icon name="location" size="13px" />
            <text class="vlock__text ym-ellipsis">{{ lockedVenue }}</text>
          </view>
        </view>
        <view class="filters__cell filters__cell--sort">
          <wd-drop-menu>
            <wd-drop-menu-item v-model="sort" :options="SORTS" />
          </wd-drop-menu>
        </view>
      </view>
    </view>

    <view class="hint">
      <text class="hint__text ym-ellipsis">{{ hint }}</text>
      <text class="hint__count ym-num">共 {{ total }} 人</text>
    </view>

    <!-- 三态：失败 / 首次加载骨架 / 空 -->
    <view v-if="failed && !list.length" class="ym-card">
      <ym-empty
        icon="warning"
        text="会员列表加载失败"
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
          :row-col="[{ width: '46%' }, { width: '78%' }, { width: '34%' }]"
        />
      </view>
    </view>

    <ym-empty
      v-else-if="loaded && !rows.length"
      icon="usergroup"
      text="没有符合条件的会员"
      desc="换一个清单，或清空搜索词再试"
      action-text="刷新"
      @action="load(true)"
    />

    <view v-for="row in rows" :key="row.id" class="mcard ym-tap" @click="toDetail(row)">
      <view class="mcard__main">
        <ym-avatar :name="row.name" :size="76" />
        <view class="mcard__id">
          <view class="mcard__name-row">
            <text class="mcard__name ym-ellipsis">{{ row.name }}</text>
            <ym-badge v-if="row.layer" :text="row.layer" :tone="layerTone(row.layer)" />
          </view>
          <text class="mcard__sub ym-ellipsis">
            {{ row.venue }}<text v-if="row.consultant"> · {{ row.consultant }}</text>
          </text>
        </view>
        <view class="mcard__chevron">
          <wd-icon name="chevron-right" size="14px" />
        </view>
      </view>

      <view class="mcard__stats">
        <view class="mcard__stat">
          <text class="mcard__stat-value ym-num">{{ remainText(row) }}</text>
          <text class="mcard__stat-label">剩余课时</text>
        </view>
        <view class="mcard__stat">
          <text class="mcard__stat-value ym-num">{{ expireText(row) }}</text>
          <text class="mcard__stat-label">到期</text>
        </view>
        <view class="mcard__stat">
          <text class="mcard__stat-value ym-num">{{ attendText(row) }}</text>
          <text class="mcard__stat-label">近30天出勤</text>
        </view>
      </view>

      <view v-if="listTag(row) || row.mainCard" class="mcard__foot">
        <ym-badge v-if="listTag(row)" :text="listTag(row)" tone="danger" plain />
        <text v-if="row.mainCard" class="mcard__card-name ym-ellipsis">主卡 · {{ row.mainCard }}</text>
      </view>

      <view v-if="reasonLine(row)" class="mcard__reason">
        <text class="mcard__reason-label">为什么在清单里</text>
        <text class="mcard__reason-text">{{ reasonLine(row) }}</text>
      </view>
    </view>

    <wd-loadmore v-if="loading && list.length" state="loading" loading-text="正在加载更多" />
    <wd-loadmore
      v-else-if="rows.length && list.length >= total"
      state="finished"
      finished-text="已经到底了"
    />
  </view>
</template>

<style lang="scss" scoped>
@import '@/styles/tokens.scss';

.members {
  /* Wot UI 控件尺寸对齐触控规范（≥88rpx） */
  --wot-search-padding: 18rpx 0 6rpx 24rpx;
  --wot-search-input-height: 68rpx;
  --wot-tabs-nav-height: 88rpx;
  --wot-tabs-nav-fs: 27rpx;
}

/* ---------- 筛选头 ---------- */
.filters {
  background: $white;
  border-bottom: 2rpx solid $hairline;
}

.filters__row {
  display: flex;
  align-items: stretch;
  border-top: 2rpx solid $hairline;
}

.filters__cell {
  flex: 1;
  min-width: 0;
}

.filters__cell--sort {
  border-left: 2rpx solid $hairline;
}

/* 门店锁定（店长/服务/授课）：只展示本店，不给切换入口 */
.vlock {
  display: flex;
  gap: 8rpx;
  align-items: center;
  justify-content: center;
  min-height: 96rpx;
  padding: 0 16rpx;
  color: $ink-500;
}

.vlock__text {
  min-width: 0;
  font-size: 26rpx;
}

/* ---------- 说明行 ---------- */
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

/* ---------- 会员卡 ---------- */
.mcard {
  padding: 26rpx 28rpx;
  margin: 20rpx 24rpx;
  background: $card-bg;
  border-radius: $r-lg;
  box-shadow: $shadow-card;
}

.mcard__main {
  display: flex;
  gap: 20rpx;
  align-items: center;
  min-height: 88rpx;
}

.mcard__id {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 8rpx;
  min-width: 0;
}

.mcard__name-row {
  display: flex;
  gap: 12rpx;
  align-items: center;
  min-width: 0;
}

.mcard__name {
  display: block;
  flex-shrink: 1;
  min-width: 0;
  font-size: 32rpx;
  font-weight: 600;
  color: $ink-800;
}

.mcard__sub {
  display: block;
  font-size: 24rpx;
  color: $ink-400;
}

.mcard__chevron {
  display: flex;
  flex-shrink: 0;
  align-items: center;
  color: $ink-300;
}

.mcard__stats {
  display: flex;
  padding: 18rpx 0;
  margin-top: 18rpx;
  background: $ink-50;
  border-radius: $r-sm;
}

.mcard__stat {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 6rpx;
  align-items: center;
  min-width: 0;
}

.mcard__stat-value {
  font-size: 30rpx;
  font-weight: 600;
  line-height: 1.2;
  color: $ink-800;
}

.mcard__stat-label {
  font-size: 20rpx;
  color: $ink-400;
}

.mcard__foot {
  display: flex;
  gap: 12rpx;
  align-items: center;
  margin-top: 16rpx;
  min-width: 0;
}

.mcard__card-name {
  flex: 1;
  min-width: 0;
  font-size: 22rpx;
  color: $ink-400;
}

.mcard__reason {
  padding: 16rpx 20rpx;
  margin-top: 16rpx;
  background: $warn-100;
  border-radius: $r-sm;
}

.mcard__reason-label {
  display: block;
  margin-bottom: 6rpx;
  font-size: 20rpx;
  color: $warn-600;
}

.mcard__reason-text {
  font-size: 24rpx;
  line-height: 1.5;
  color: $ink-700;
}
</style>
