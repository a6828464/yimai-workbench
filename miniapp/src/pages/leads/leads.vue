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
 *
 * 视觉层（2026-11 重构）：筛选头（wd-search + 状态页签）、白卡列表、三态、
 * 悬浮「记一条」。请求参数与分页口径零改动。
 */
import { computed, ref } from 'vue'
import { onLoad, onPullDownRefresh, onReachBottom, onShow } from '@dcloudio/uni-app'
import { requireLogin } from '@/utils/guard'
import { requireFeature } from '@/utils/nav'
import { canUse } from '@/utils/permission'
import { useVenueFilter } from '@/utils/venue'
import { useUserStore } from '@/store/user'
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
const failed = ref(false)

const user = useUserStore()

/**
 * 门店筛选（口径见 utils/venue.ts，全小程序一份）。
 *
 * 判据从「超管 / 新媒体」改成 **账号授权门店数 > 1**：两个门店权限的普通账号
 * 以前在任何页面都看不到切换标签，数据被固定在一家店上（v3.11 修的就是这条）。
 *  - 双店账号 → 全部 / 绿地店 / 东部店，切了才发 venue 参数；
 *  - 单店账号 → 后端 scopeLeadsForUser 已锁本店，只展示锁定标签（不给假选项）；
 *  - 选择结果落 storage，切页返回、第二天再进都还是上次那家店。
 */
const venueFilter = useVenueFilter()
const canSwitchVenue = venueFilter.canSwitch
const venueOptions = venueFilter.options
const venue = venueFilter.venue
/** 只有真的选了单店才发 venue 参数 */
const venueParam = venueFilter.venueParam
/** 单店账号展示的锁定标签 */
const lockedVenue = venueFilter.lockedLabel

/** 新建入口按功能权限判断（不写死角色）：能看留资就能记一条，后端 store 不再拦截 */
const canCreate = computed(() => canUse(user.roles, 'leads'))
/** 当前是否处于「被筛选过」的状态——决定空态是「没有客资」还是「没有匹配」 */
const hasFilter = computed(() => Boolean(submitted.value || status.value || venueParam.value))

const statusChips = computed(() => ['全部', ...LEAD_STATUSES])
/** 页签显示值：status 为空时落在「全部」 */
const activeChip = computed(() => status.value || '全部')

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
      venue: venueParam.value,
      current: page.value,
      size: 20
    })
    total.value = res.total ?? res.records?.length ?? 0
    list.value = reset ? (res.records ?? []) : [...list.value, ...(res.records ?? [])]
    if (res.enums?.sources?.length) enums.value = res.enums
    loaded.value = true
    failed.value = false
  } catch {
    /* http 层已 toast */
    failed.value = true
  } finally {
    loading.value = false
  }
}

function pickStatus(s: string) {
  status.value = s === '全部' ? '' : s
  load(true)
}

function onTabChange(payload: { name: string | number }) {
  pickStatus(String(payload.name))
}

/** 切换门店：记住这次选择，然后回到第一页重新拉 */
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

/** 状态色（纯视觉映射，状态取值仍以后端为准） */
function statusTone(s: string): 'brand' | 'gold' | 'warn' | 'danger' | 'info' | 'muted' {
  if (s === '已成交') return 'brand'
  if (s === '已流失') return 'muted'
  if (s === '爽约') return 'danger'
  if (s === '新留资') return 'warn'
  if (s === '已联系') return 'info'
  return 'gold'
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

/**
 * 深链：/pages/leads/leads?status=新留资&venue=绿地店
 * 参数只做**初值**，进入后仍可改状态/门店/搜索；onShow 不重置用户选择。
 */
onLoad((options) => {
  const opt = (options ?? {}) as Record<string, string>
  const st = String(opt.status ?? '').trim()
  if (st && (LEAD_STATUSES as readonly string[]).includes(st)) status.value = st
  // venue 深链是「有人从工作台卡片点进来」的明确意图 → 同样落盘（下次进页面沿用）
  const v = String(opt.venue ?? '').trim()
  if (v && canSwitchVenue.value) venueFilter.apply(v)
})

onShow(() => {
  if (!requireLogin()) return
  if (!requireFeature('leads')) return
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
  <view class="ym-page leads">
    <!-- 筛选头 -->
    <view class="filters">
      <wd-search
        v-model="keyword"
        placeholder="搜姓名 或 手机号/尾号/微信"
        cancel-txt="搜索"
        @search="search"
        @cancel="search"
        @clear="clearSearch"
      />

      <wd-tabs :model-value="activeChip" :slidable-num="5" @change="onTabChange">
        <wd-tab v-for="s in statusChips" :key="s" :title="s" :name="s" />
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
        <view v-if="canCreate" class="create-btn ym-tap" @click="createNew">
          <wd-icon name="add" size="14px" />
          <text class="create-btn__text">记一条</text>
        </view>
      </view>
    </view>

    <view class="hint">
      <text class="hint__text ym-ellipsis">按人隔离：只显示你有权限跟进的客资</text>
      <text class="hint__count ym-num">共 {{ total }} 条</text>
    </view>

    <!-- 三态 -->
    <view v-if="failed && !list.length" class="ym-card">
      <ym-empty
        icon="warning"
        text="留资列表加载失败"
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
          :row-col="[{ width: '40%' }, { width: '70%' }, { width: '88%' }]"
        />
      </view>
    </view>

    <ym-empty
      v-else-if="loaded && !list.length"
      icon="chat"
      :text="hasFilter ? '没有符合条件的客资' : '还没有客资'"
      :desc="
        hasFilter
          ? '换个状态或门店再看，也可以点右下角「记一条」'
          : '点右下角「记一条」，30 秒登记一位到店客户'
      "
      :action-text="canCreate ? '记一条' : ''"
      @action="createNew"
    />

    <view v-for="row in list" :key="row.id" class="lcard ym-tap" @click="edit(row)">
      <view class="lcard__main">
        <view class="lcard__id">
          <view class="lcard__name-row">
            <text class="lcard__name ym-ellipsis">{{ row.name }}</text>
            <ym-badge :text="row.status" :tone="statusTone(row.status)" />
            <ym-badge v-if="row.grade" :text="`${row.grade} 级`" tone="gold" plain />
          </view>
          <text class="lcard__meta ym-ellipsis">{{ metaLine(row) }}</text>
        </view>
        <text class="lcard__phone ym-num">{{ row.phone || `尾号${row.phoneTail || ''}` }}</text>
        <view class="lcard__chevron"><wd-icon name="chevron-right" size="14px" /></view>
      </view>

      <view v-if="row.demand || trialLine(row) || row.dealAmount || row.remark" class="lcard__body">
        <view v-if="row.demand" class="lcard__line">
          <text class="lcard__label">需求</text>
          <text class="lcard__text">{{ row.demand }}</text>
        </view>
        <view v-if="trialLine(row)" class="lcard__line">
          <text class="lcard__label">体验课</text>
          <text class="lcard__text">{{ trialLine(row) }}</text>
        </view>
        <view v-if="row.dealAmount" class="lcard__line">
          <text class="lcard__label">成交</text>
          <text class="lcard__text lcard__text--deal ym-num">
            ¥{{ row.dealAmount }}<text v-if="row.dealCard"> · {{ row.dealCard }}</text>
          </text>
        </view>
        <view v-if="row.remark" class="lcard__line">
          <text class="lcard__label">备注</text>
          <text class="lcard__text">{{ row.remark }}</text>
        </view>
      </view>
    </view>

    <wd-loadmore v-if="loading && list.length" state="loading" loading-text="正在加载更多" />
    <wd-loadmore
      v-else-if="list.length && list.length >= total"
      state="finished"
      finished-text="已经到底了"
    />

    <view v-if="canCreate" class="fab ym-tap" @click="createNew">
      <wd-icon name="add" size="18px" />
      <text class="fab__text">记一条</text>
    </view>
  </view>
</template>

<style lang="scss" scoped>
@import '@/styles/tokens.scss';

.leads {
  padding-bottom: calc(200rpx + env(safe-area-inset-bottom));

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
  gap: 16rpx;
  align-items: center;
  padding-right: 20rpx;
  border-top: 2rpx solid $hairline;
}

.filters__cell {
  flex: 1;
  min-width: 0;
}

/* 门店锁定（店长/服务/授课）：只展示本店，不给切换入口 */
.vlock {
  display: flex;
  gap: 8rpx;
  align-items: center;
  min-height: 96rpx;
  padding: 0 24rpx;
  color: $ink-500;
}

.vlock__text {
  min-width: 0;
  font-size: 26rpx;
}

/* 顶部「记一条」：与右下角悬浮按钮同源，避免只依赖一个固定定位元素 */
.create-btn {
  display: flex;
  flex-shrink: 0;
  gap: 6rpx;
  align-items: center;
  height: 88rpx;
  padding: 0 26rpx;
  color: $white;
  background: linear-gradient(135deg, $brand-500, $brand-700);
  border-radius: 999rpx;
  box-shadow: 0 6rpx 16rpx rgba(28, 96, 71, 0.24);
}

.create-btn__text {
  font-size: 26rpx;
  font-weight: 500;
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

/* ---------- 留资卡 ---------- */
.lcard {
  padding: 26rpx 28rpx;
  margin: 20rpx 24rpx;
  background: $card-bg;
  border-radius: $r-lg;
  box-shadow: $shadow-card;
}

.lcard__main {
  display: flex;
  gap: 16rpx;
  align-items: center;
  min-height: 88rpx;
}

.lcard__id {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 8rpx;
  min-width: 0;
}

.lcard__name-row {
  display: flex;
  gap: 12rpx;
  align-items: center;
  min-width: 0;
}

.lcard__name {
  display: block;
  flex-shrink: 1;
  min-width: 0;
  font-size: 32rpx;
  font-weight: 600;
  color: $ink-800;
}

.lcard__meta {
  display: block;
  font-size: 23rpx;
  color: $ink-400;
}

.lcard__phone {
  flex-shrink: 0;
  font-size: 24rpx;
  color: $ink-500;
}

.lcard__chevron {
  display: flex;
  flex-shrink: 0;
  align-items: center;
  color: $ink-300;
}

.lcard__body {
  padding-top: 16rpx;
  margin-top: 16rpx;
  border-top: 2rpx solid $hairline;
}

.lcard__line {
  display: flex;
  gap: 12rpx;
  margin-top: 10rpx;

  &:first-child {
    margin-top: 0;
  }
}

.lcard__label {
  flex-shrink: 0;
  width: 108rpx;
  font-size: 23rpx;
  color: $ink-400;
}

.lcard__text {
  flex: 1;
  min-width: 0;
  font-size: 26rpx;
  line-height: 1.5;
  color: $ink-700;
  word-break: break-all;
}

.lcard__text--deal {
  color: $gold-600;
  font-weight: 600;
}

/* ---------- 悬浮记一条 ---------- */
.fab {
  position: fixed;
  right: 32rpx;
  /* 先给不支持 env() 的机型一个兜底，再用安全区覆盖 */
  bottom: 48rpx;
  bottom: calc(48rpx + env(safe-area-inset-bottom));
  z-index: 20;
  display: flex;
  gap: 8rpx;
  align-items: center;
  height: 96rpx;
  padding: 0 34rpx;
  color: $white;
  background: linear-gradient(135deg, $brand-500, $brand-700);
  border-radius: 999rpx;
  box-shadow: 0 12rpx 28rpx rgba(28, 96, 71, 0.32);
}

.fab__text {
  font-size: 28rpx;
  font-weight: 500;
}
</style>
