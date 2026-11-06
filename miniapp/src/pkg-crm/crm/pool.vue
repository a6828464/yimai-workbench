<script setup lang="ts">
/**
 * 客户经营池（超管）：全量客户 + 门店 / 分层 / 归属 / 评估筛选
 *
 * 数据源：GET /customers（服务端分页 PageResult<MiniappCustomer>）
 *  - 不带 type：经营池看全量（含 layer=P5 的未成交客资），与会员管理（type=member）分工明确；
 *  - 搜索一次只发一个字段：姓名 → name，手机 → phone（后端同时匹配 phone 与 phone_tail）；
 *  - 门店 = venue（绿地店 / 东部店；后端是 where 直等匹配，不做白名单校验）；
 *  - 分层 = 后端派生列 layer（P0–P5，口径见 helpers.php::layerDefinitions()）；
 *  - 归属 = 顾问 consultant，'待分配' 是后端的特殊值（表示空归属），不能传空串；
 *  - 评估 = evaluationStatus（未评估 / 高机会 / 重点培育 / 风险修复 / 已过期）。
 *
 * 筛选纪律：筛选条件只存在本页 state（不落 storage）；任何筛选变更先清空已加载列表再拉
 * current=1，配合请求序号丢弃在途的旧响应，避免新旧口径的数据混在一张列表里。
 * 下拉刷新只刷新、不动筛选。
 *
 * 权限：requireSuper()（后端 /customers 无角色门，靠前端 + 超管语义收口）。
 */
import { computed, ref } from 'vue'
import { onPullDownRefresh, onReachBottom, onShow, onShareAppMessage } from '@dcloudio/uni-app'
import { shareToHome } from '@/utils/share'
import { goPath, requireSuper } from '@/utils/nav'
import { canSwitchVenue } from '@/utils/venue'
import { useUserStore } from '@/store/user'
import {
  POOL_CONSULTANT_UNASSIGNED,
  POOL_EVALUATIONS,
  POOL_LAYERS,
  POOL_LAYER_MAP,
  POOL_VENUES,
  fetchCustomerOptions,
  fetchCustomerPool,
  type PoolEvaluation,
  type PoolLayer
} from '@/api/pool'
import type { MiniappCustomer } from '@/types'

const PAGE_SIZE = 20

type SearchField = 'name' | 'phone'
type BadgeTone = 'brand' | 'gold' | 'warn' | 'danger' | 'info' | 'muted'

const user = useUserStore()

const keyword = ref('')
const submitted = ref('')
const searchField = ref<SearchField>('name')
/** 门店：'' = 全部门店（后端把空串当「不筛选」） */
const venue = ref('')
const layer = ref<string>('')
const consultant = ref<string>('')
const evaluation = ref<string>('')

const list = ref<MiniappCustomer[]>([])
const total = ref(0)
const page = ref(1)
const loading = ref(false)
const loaded = ref(false)
const failed = ref(false)
const consultants = ref<string[]>([])
/** 请求在途时又被要求重置（连续切筛选），结束后补跑一次 */
let pendingReset = false
/** 请求序号：筛选变更后让在途响应作废，旧筛选的数据不混进新列表 */
let requestSeq = 0

/** 门店选择权：判据同留资/会员页（授权门店数 > 1）；本页另由 requireSuper() 收口 */
const canPickVenue = computed(() => canSwitchVenue(user.venues, user.roles))

const venueOptions = computed(() => [
  { label: '全部门店', value: '' },
  ...POOL_VENUES.map((name) => ({ label: name, value: name }))
])

const fieldLabel = computed(() => (searchField.value === 'name' ? '姓名' : '手机'))
const fieldOptions = [{ value: '姓名' }, { value: '手机' }]

const layerOptions = computed(() => [
  { label: '全部分层', value: '' },
  ...POOL_LAYERS.map((item) => ({
    label: `${item.key} ${item.label}`,
    value: item.key,
    tip: item.desc
  }))
])

const consultantOptions = computed(() => [
  { label: '全部归属', value: '' },
  { label: '待分配', value: POOL_CONSULTANT_UNASSIGNED, tip: '顾问为空的客户' },
  ...consultants.value.map((name) => ({ label: name, value: name }))
])

const evalOptions = computed(() => [
  { label: '全部评估', value: '' },
  ...POOL_EVALUATIONS.map((item) => ({ label: item, value: item }))
])

const hasFilter = computed(
  () =>
    !!venue.value ||
    !!layer.value ||
    !!consultant.value ||
    !!evaluation.value ||
    !!submitted.value
)

/** 已生效的筛选（chip 形式，逐个可读；长串由横向滚动承载，不挤压重置按钮） */
const activeChips = computed(() => {
  const chips: string[] = []
  if (venue.value) chips.push(`门店 ${venue.value}`)
  if (layer.value) chips.push(`分层 ${layer.value}`)
  if (consultant.value) chips.push(`归属 ${consultant.value}`)
  if (evaluation.value) chips.push(`评估 ${evaluation.value}`)
  if (submitted.value) {
    chips.push(`${searchField.value === 'phone' ? '手机' : '姓名'} ${submitted.value}`)
  }
  return chips
})

const summaryLine = computed(() => (loaded.value ? `共 ${total.value} 位客户` : '加载中…'))

const showLoadmore = computed(
  () =>
    list.value.length > 0 &&
    (loading.value || failed.value || list.value.length >= total.value)
)

const loadmoreState = computed<'loading' | 'finished' | 'error'>(() => {
  if (failed.value) return 'error'
  if (loading.value) return 'loading'
  return 'finished'
})

/* ---------------- 数据 ---------------- */

function keywordParams(): { name?: string; phone?: string } {
  const text = submitted.value.trim()
  if (!text) return {}
  return searchField.value === 'phone' ? { phone: text } : { name: text }
}

async function load(reset = false): Promise<void> {
  // 筛选连点时不能把后一次丢掉：记下「还有一次重置请求」，本轮结束后补跑，
  // 否则界面显示新筛选、列表还是旧结果。
  if (loading.value) {
    if (reset) pendingReset = true
    return
  }
  const seq = ++requestSeq
  if (reset) page.value = 1
  loading.value = true
  try {
    const res = await fetchCustomerPool({
      ...keywordParams(),
      venue: venue.value || undefined,
      layer: (layer.value || undefined) as PoolLayer | undefined,
      consultant: consultant.value || undefined,
      evaluationStatus: (evaluation.value || undefined) as PoolEvaluation | undefined,
      current: page.value,
      size: PAGE_SIZE
    })
    // 回来时筛选已经变了（reloadByFilter 已推高 requestSeq）→ 丢弃，旧口径不进新列表
    if (seq !== requestSeq) return
    total.value = res.total ?? 0
    list.value = reset ? (res.records ?? []) : [...list.value, ...(res.records ?? [])]
    loaded.value = true
    failed.value = false
  } catch {
    if (seq === requestSeq) {
      /** http 层已 toast；有数据时由 wd-loadmore 的 error 态兜底，无数据时走整页空态 */
      failed.value = true
    }
  } finally {
    loading.value = false
    if (pendingReset) {
      pendingReset = false
      void load(true)
    }
  }
}

/**
 * 筛选变更：先清空已加载列表（避免旧筛选的行混进新结果），再以 current=1 重新拉。
 * 在途请求的响应会被 requestSeq 判为过期并丢弃。
 */
function reloadByFilter(): void {
  requestSeq += 1
  page.value = 1
  list.value = []
  total.value = 0
  loaded.value = false
  load(true)
}

async function loadConsultants(): Promise<void> {
  try {
    const res = await fetchCustomerOptions()
    consultants.value = res.consultants ?? []
  } catch {
    /* 归属下拉缺数据不阻塞列表 */
  }
}

/* ---------------- 交互 ---------------- */

function search(): void {
  submitted.value = keyword.value.trim()
  reloadByFilter()
}

function onFieldChange(option: unknown): void {
  const next = (option as { value?: unknown } | null | undefined)?.value
  const field: SearchField = next === '手机' ? 'phone' : 'name'
  if (field === searchField.value) return
  searchField.value = field
  // 同一串在「姓名 / 手机」下语义不同，切换后按已输入的词重新查一次
  submitted.value = keyword.value.trim()
  reloadByFilter()
}

/** 门店 / 分层 / 归属 / 评估共用：v-model 已把新值写进 ref，这里只负责重置 + 重拉 */
function onFilterChange(): void {
  reloadByFilter()
}

/** 重置：一次清空全部筛选（门店 + 分层 + 归属 + 评估 + 关键词） */
function resetFilters(): void {
  keyword.value = ''
  submitted.value = ''
  venue.value = ''
  layer.value = ''
  consultant.value = ''
  evaluation.value = ''
  reloadByFilter()
}

function openDetail(row: MiniappCustomer): void {
  goPath(`/pkg-crm/members/detail?id=${row.id}`)
}

/* ---------------- 展示辅助 ---------------- */

function layerTone(value: string): BadgeTone {
  switch (value) {
    case 'P0':
      return 'danger'
    case 'P1':
      return 'warn'
    case 'P2':
      return 'info'
    case 'P3':
      return 'gold'
    case 'P4':
      return 'brand'
    default:
      return 'muted'
  }
}

function layerLabel(value: string): string {
  return POOL_LAYER_MAP[value as PoolLayer]?.label ?? ''
}

function remainText(row: MiniappCustomer): string {
  if (row.remainTimes === null || row.remainTimes === undefined) return '—'
  return String(row.remainTimes)
}

function dateText(value?: string | null): string {
  if (!value) return '—'
  return value.slice(0, 10)
}

onShow(() => {
  if (requireSuper()) {
    loadConsultants()
    load(true)
  }
})

onPullDownRefresh(async () => {
  await Promise.all([loadConsultants(), load(true)])
  uni.stopPullDownRefresh()
})

onReachBottom(() => {
  if (!loading.value && list.value.length < total.value) {
    page.value += 1
    load()
  }
})

// 转发：员工页统一回首页（分享自己会撞 requireLogin 的登录墙），标题不带客户信息 —— 见 utils/share.ts
onShareAppMessage(() => shareToHome())
</script>

<template>
  <view class="ym-page pool">
    <!-- 筛选区 -->
    <view class="pool__filters">
      <wd-search
        v-model="keyword"
        hide-cancel
        :placeholder="searchField === 'name' ? '搜姓名' : '搜手机号 / 尾号'"
        @search="search"
        @clear="search"
      />
      <view class="pool__field">
        <wd-segmented
          :value="fieldLabel"
          :options="fieldOptions"
          size="small"
          @change="onFieldChange"
        />
      </view>

      <wd-drop-menu custom-class="pool__menu">
        <wd-drop-menu-item
          v-if="canPickVenue"
          v-model="venue"
          title="门店"
          :options="venueOptions"
          @change="onFilterChange"
        />
        <wd-drop-menu-item
          v-model="layer"
          title="分层"
          :options="layerOptions"
          @change="onFilterChange"
        />
        <wd-drop-menu-item
          v-model="consultant"
          title="归属"
          :options="consultantOptions"
          @change="onFilterChange"
        />
        <wd-drop-menu-item
          v-model="evaluation"
          title="评估"
          :options="evalOptions"
          @change="onFilterChange"
        />
      </wd-drop-menu>

      <!-- 已生效筛选：chip 行横向滚动，重置按钮固定右侧不被挤压 -->
      <view class="pool__summary">
        <scroll-view class="pool__summary-scroll" scroll-x :show-scrollbar="false">
          <view class="pool__summary-inner">
            <text class="pool__summary-text">{{ summaryLine }}</text>
            <text v-for="chip in activeChips" :key="chip" class="pool__chip">{{ chip }}</text>
          </view>
        </scroll-view>
        <text v-if="hasFilter" class="pool__reset" @click="resetFilters">重置筛选</text>
      </view>
    </view>

    <!-- 三态：加载骨架 / 整页失败 / 空 -->
    <view v-if="loading && !loaded" class="pool__skeleton">
      <view v-for="i in 3" :key="i" class="ym-card">
        <wd-skeleton theme="paragraph" :row-col="[3, 3, 2]" :loading="true" animation="gradient" />
      </view>
    </view>

    <ym-empty
      v-else-if="failed && !list.length"
      text="加载失败"
      desc="网络或服务异常，可重试"
      action-text="重新加载"
      icon="warning"
      @action="load(true)"
    />

    <ym-empty
      v-else-if="loaded && !list.length"
      text="没有符合条件的客户"
      desc="放宽分层 / 归属 / 评估条件，或清空关键词再试"
      action-text="清空筛选"
      icon="search"
      @action="resetFilters"
    />

    <template v-else>
      <view v-for="row in list" :key="row.id" class="ym-card pool-card ym-tap" @click="openDetail(row)">
        <view class="pool-card__head">
          <text class="pool-card__name">{{ row.name }}</text>
          <ym-badge :text="row.layer" :tone="layerTone(row.layer)" size="md" />
          <text class="pool-card__phone">{{ row.phone || `尾号 ${row.phoneTail}` }}</text>
        </view>

        <view class="pool-card__meta">
          <text class="ym-muted">
            {{ row.venue }}<text v-if="layerLabel(row.layer)"> · {{ layerLabel(row.layer) }}</text>
          </text>
          <text class="ym-muted">{{ row.consultant || POOL_CONSULTANT_UNASSIGNED }}</text>
        </view>

        <view class="pool-card__metrics">
          <view class="pool-card__metric">
            <text class="pool-card__metric-value ym-num">{{ remainText(row) }}</text>
            <text class="pool-card__metric-label">剩余课时</text>
          </view>
          <view class="pool-card__metric">
            <text class="pool-card__metric-value ym-num">{{ dateText(row.expireDate) }}</text>
            <text class="pool-card__metric-label">到期</text>
          </view>
          <view class="pool-card__metric">
            <text class="pool-card__metric-value ym-num">{{ dateText(row.lastVisit) }}</text>
            <text class="pool-card__metric-label">最近到店</text>
          </view>
        </view>

        <view class="pool-card__foot">
          <text class="ym-muted">{{ row.status || '—' }}<text v-if="row.mainCard"> · {{ row.mainCard }}</text></text>
          <view class="pool-card__owner">
            <text class="ym-muted">{{ row.owner || '未归属' }}</text>
            <wd-icon name="chevron-right" size="13px" custom-class="pool-card__arrow" />
          </view>
        </view>
      </view>

      <wd-loadmore
        v-if="showLoadmore"
        :state="loadmoreState"
        loading-text="加载更多…"
        error-text="加载失败，点击重试"
        :finished-text="`已加载全部 ${total} 位客户`"
        @reload="load()"
      />
    </template>
  </view>
</template>

<style lang="scss" scoped>
@import '@/styles/tokens.scss';

.pool {
  &__filters {
    padding: 20rpx 24rpx 0;
    background: $white;
    border-bottom: 2rpx solid $hairline;
  }

  &__field {
    margin-top: 16rpx;
  }

  /* 4 项下拉在 375px 下均分，标题单行省略，绝不换行撑高筛选区 */
  &__menu {
    :deep(.wd-drop-menu__item-title-text) {
      overflow: hidden;
      font-size: 24rpx;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
  }

  &__summary {
    display: flex;
    flex-wrap: nowrap;
    gap: 12rpx;
    align-items: center;
    padding: 16rpx 4rpx 18rpx;
  }

  &__summary-scroll {
    flex: 1;
    min-width: 0;
    white-space: nowrap;
  }

  &__summary-inner {
    display: inline-flex;
    gap: 12rpx;
    align-items: center;
  }

  &__summary-text {
    font-size: 22rpx;
    color: $ink-400;
    white-space: nowrap;
  }

  &__chip {
    padding: 4rpx 14rpx;
    font-size: 20rpx;
    color: $brand-600;
    white-space: nowrap;
    background: $brand-50;
    border-radius: 999rpx;
  }

  &__reset {
    flex-shrink: 0;
    padding: 6rpx 18rpx;
    font-size: 22rpx;
    color: $brand-600;
    background: $brand-50;
    border-radius: 999rpx;
  }

  &__skeleton {
    display: flex;
    flex-direction: column;
  }
}

.pool-card {
  &__head {
    display: flex;
    align-items: center;
    gap: 12rpx;
  }

  &__name {
    font-size: 32rpx;
    font-weight: 600;
    color: $ink-800;
  }

  &__phone {
    margin-left: auto;
    font-size: 24rpx;
    color: $ink-500;
  }

  &__meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16rpx;
    margin-top: 10rpx;
  }

  &__metrics {
    display: flex;
    padding: 18rpx 0;
    margin-top: 16rpx;
    background: $ink-50;
    border-radius: 14rpx;
  }

  &__metric {
    display: flex;
    flex: 1;
    flex-direction: column;
    align-items: center;
    gap: 6rpx;
  }

  &__metric-value {
    font-size: 28rpx;
    font-weight: 600;
    color: $ink-800;
  }

  &__metric-label {
    font-size: 20rpx;
    color: $ink-400;
  }

  &__foot {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16rpx;
    margin-top: 14rpx;
  }

  &__owner {
    display: flex;
    flex-shrink: 0;
    gap: 4rpx;
    align-items: center;
  }

  &__arrow {
    color: $ink-200;
  }
}
</style>
