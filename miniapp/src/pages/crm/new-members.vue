<script setup lang="ts">
/**
 * 新客培养（入会近 90 天的养成进度）
 *
 * 数据源：GET /new-members/cultivation（一次返回**全量** records + summary + targets）
 *  - 不是分页接口 → 前端切片 20 条 + onReachBottom 追加（方案 §5.3）；
 *  - `summary` 是时间窗内全量新客的统计，`cardType` 只收窄 records（后端就是这么算的），
 *    所以筛课型时页面上要写清「统计不变、只收窄名单」，不然会被当成数据错误；
 *  - 顶部 4 张统计卡本身就是健康度筛选器（idle/cultivating/cultured → 纯前端过滤 records），
 *    默认「全部」，再点一次取消；
 *  - 门店 picker 只给超管：后端对店长锁定本店（传 venue 不生效），
 *    老师/服务/新媒体也被 scope 收窄，给了 picker 就是假选项，故不显示。
 *
 * 筛选纪律：条件只存在本页 state（不落 storage）；重置一次清空；请求带序号，
 * 旧筛选的在途响应回来后直接丢弃，不会把旧口径数据写进新筛选的结果。
 *
 * 权限：requireFeature('newMembers')（ALL 角色，含新媒体）。
 */
import { computed, ref } from 'vue'
import { onPullDownRefresh, onReachBottom, onShow } from '@dcloudio/uni-app'
import { useUserStore } from '@/store/user'
import { goPath, requireFeature } from '@/utils/nav'
import { isManagerOrAbove, isSuper } from '@/utils/permission'
import {
  CULTIVATION_HEALTH_META,
  CULTIVATION_KINDS,
  CULTIVATION_KIND_LABELS,
  CULTIVATION_VENUES,
  fetchNewMemberCultivation,
  type CultivationHealth,
  type CultivationKind,
  type CultivationRecord,
  type CultivationSummary
} from '@/api/cultivation'

/** 前端切片大小（接口非分页） */
const PAGE_SIZE = 20

type StatTone = 'light' | 'dark' | 'brand' | 'gold' | 'plain'

/** 健康度筛选：'' = 全部（不过滤，不是「无健康度」） */
type HealthFilter = '' | CultivationHealth

interface StatCard {
  key: 'total' | 'idle' | 'cultivating' | 'cultured'
  label: string
  value: string | number
  hint: string
  tone: StatTone
  /** 点击后写入 healthFilter 的值；'' = 新客总数（回到全部） */
  health: HealthFilter
  /** 当前是否处于选中态 */
  active: boolean
}

const user = useUserStore()

const records = ref<CultivationRecord[]>([])
const summary = ref<CultivationSummary>({
  total: 0,
  private: 0,
  small: 0,
  group: 0,
  idle: 0,
  cultivating: 0,
  cultured: 0
})
const targets = ref<Record<CultivationKind, number>>({ private: 0, small: 0, group: 0 })
const range = ref<{ start: string; end: string }>({ start: '', end: '' })
const syncTime = ref('')
const visible = ref(PAGE_SIZE)

const keyword = ref('')
const submitted = ref('')
/** segmented 的选中值用中文标签，映射回 cardType 再发请求 */
const kindLabel = ref('全部')
const venue = ref('')
/** 健康度筛选（纯前端，后端没有 health 参数） */
const healthFilter = ref<HealthFilter>('')

const loading = ref(false)
const loaded = ref(false)
const failed = ref(false)
/** 请求在途时又被要求刷新（连续切筛选），结束后补跑一次 */
let pendingReload = false
/** 请求序号：筛选变更后让在途响应作废，旧筛选的数据不混进新结果 */
let requestSeq = 0

const kindValue = computed<CultivationKind | ''>(
  () => CULTIVATION_KINDS.find((item) => item.label === kindLabel.value)?.key ?? ''
)

const kindSegOptions = computed(() => [
  { value: '全部' },
  ...CULTIVATION_KINDS.map((item) => ({ value: item.label }))
])

/**
 * 只有超管能选门店：后端对店长 `hasRole(R_MANAGER)` 直接锁本店（传 venue 不生效），
 * 老师/服务/新媒体也被 scopeCustomersForUser 收窄——给了 picker 就是假选项，所以不显示。
 */
const canPickVenue = computed(() => isSuper(user.roles))

const venueColumns = computed(() => [
  { label: '全部门店', value: '' },
  ...CULTIVATION_VENUES.map((name) => ({ label: name, value: name }))
])

/** 非超管没有门店选择权：写清后端实际生效的范围，别写成「全部门店」误导人 */
const venueText = computed(() => {
  if (canPickVenue.value) return venue.value || '全部门店'
  if (isManagerOrAbove(user.roles)) return `${user.venue || '本店'}（店长锁定本店）`
  return user.venue || '按账号范围'
})

const stats = computed<StatCard[]>(() => {
  const ready = loaded.value
  return [
    {
      key: 'total',
      label: '新客总数',
      value: ready ? summary.value.total : '—',
      hint: '时间窗内入会',
      tone: 'brand',
      health: '',
      active: healthFilter.value === ''
    },
    {
      key: 'idle',
      label: '待激活',
      value: ready ? summary.value.idle : '—',
      hint: '入会后 0 节上课',
      tone: 'plain',
      health: 'idle',
      active: healthFilter.value === 'idle'
    },
    {
      key: 'cultivating',
      label: '养成中',
      value: ready ? summary.value.cultivating : '—',
      hint: '已开课未达标',
      tone: 'dark',
      health: 'cultivating',
      active: healthFilter.value === 'cultivating'
    },
    {
      key: 'cultured',
      label: '已养成',
      value: ready ? summary.value.cultured : '—',
      hint: '参与课型均达标',
      tone: 'gold',
      health: 'cultured',
      active: healthFilter.value === 'cultured'
    }
  ]
})

/** 健康度筛选是纯前端：后端没有 health 参数，records 已按 cardType / 姓名 / 门店收窄过 */
const filtered = computed(() =>
  healthFilter.value ? records.value.filter((row) => row.health === healthFilter.value) : records.value
)
const shown = computed(() => filtered.value.slice(0, visible.value))
const hasMore = computed(() => visible.value < filtered.value.length)

const hasActiveFilter = computed(
  () => !!healthFilter.value || !!kindValue.value || !!submitted.value || !!venue.value
)

/** 健康度 → 中文（筛选行与空态文案共用） */
function healthText(health: HealthFilter): string {
  return health ? (CULTIVATION_HEALTH_META[health]?.label ?? health) : '全部'
}

/** 「当前筛选：xxx（n 人）」，n 是当前实际可见的条数（健康度 + 服务端筛选之后） */
const activeText = computed(() => {
  const parts: string[] = []
  if (healthFilter.value) parts.push(healthText(healthFilter.value))
  if (kindValue.value) parts.push(`课型 ${kindLabel.value}`)
  if (submitted.value) parts.push(`姓名含「${submitted.value}」`)
  if (venue.value) parts.push(venue.value)
  const head = parts.length ? parts.join(' · ') : '全部'
  return `当前筛选：${head}（${filtered.value.length} 人）`
})

const emptyText = computed(() =>
  healthFilter.value ? `没有「${healthText(healthFilter.value)}」状态的新客` : '没有符合条件的新客'
)

const emptyDesc = computed(() =>
  healthFilter.value ? '点「清除筛选」看全部新客，或换个课型' : '换个课型、清空搜索或等新客入会后再看'
)

const filterHint = computed(() => {
  if (kindValue.value) {
    return `已按「${kindLabel.value}」收窄名单；顶部统计仍是时间窗内全量新客`
  }
  const start = range.value.start ? range.value.start.slice(5) : '—'
  const end = range.value.end ? range.value.end.slice(5) : '—'
  const sync = syncTime.value ? ` · 数据截至 ${syncTime.value.slice(0, 16)}` : ''
  return `入会 ${start} ~ ${end}，共 ${records.value.length} 位新客${sync}`
})

/* ---------------- 数据 ---------------- */

async function load(): Promise<void> {
  // 连点筛选时别把后一次丢掉：记下来，本轮结束后补跑一次
  if (loading.value) {
    pendingReload = true
    return
  }
  const seq = ++requestSeq
  loading.value = true
  try {
    const res = await fetchNewMemberCultivation({
      name: submitted.value.trim() || undefined,
      cardType: kindValue.value || undefined,
      venue: venue.value || undefined
    })
    // 响应回来时筛选已经变了 → 丢弃，别把旧口径的数据写进新筛选的结果
    if (seq !== requestSeq) return
    records.value = res.records ?? []
    summary.value = res.summary ?? summary.value
    targets.value = res.targets ?? targets.value
    range.value = res.range ?? range.value
    syncTime.value = res.syncTime ?? ''
    visible.value = PAGE_SIZE
    loaded.value = true
    failed.value = false
  } catch {
    if (seq === requestSeq) failed.value = true
  } finally {
    loading.value = false
    if (pendingReload) {
      pendingReload = false
      void load()
    }
  }
}

/** 服务端筛选变化：作废在途响应后重新拉取（健康度筛选纯前端，不走这里） */
function reload(): void {
  requestSeq += 1
  visible.value = PAGE_SIZE
  load()
}

/* ---------------- 交互 ---------------- */

function search(): void {
  submitted.value = keyword.value.trim()
  reload()
}

function onKindChange(option: unknown): void {
  const next = (option as { value?: unknown } | null | undefined)?.value
  const label = typeof next === 'string' ? next : '全部'
  if (label === kindLabel.value) return
  kindLabel.value = label
  reload()
}

function onVenueConfirm(payload: unknown): void {
  const raw = (payload as { value?: unknown } | null | undefined)?.value
  const picked = Array.isArray(raw) ? String(raw[0] ?? '') : raw === undefined || raw === null ? '' : String(raw)
  if (picked === venue.value) return
  venue.value = picked
  reload()
}

/**
 * 点统计卡切换健康度筛选：选中 → 只看该状态；再点一次 → 回全部；
 * 「新客总数」永远等于全部。纯前端过滤，不重新请求。
 */
function toggleHealth(card: StatCard): void {
  if (!loaded.value) return
  const next: HealthFilter = card.health && healthFilter.value !== card.health ? card.health : ''
  if (next === healthFilter.value) return
  healthFilter.value = next
  visible.value = PAGE_SIZE
}

/** 重置：一次清空全部筛选（健康度 + 课型 + 姓名 + 门店） */
function resetFilters(): void {
  keyword.value = ''
  submitted.value = ''
  kindLabel.value = '全部'
  venue.value = ''
  healthFilter.value = ''
  reload()
}

function openMember(id: number): void {
  goPath(`/pages/members/detail?id=${id}`)
}

/* ---------------- 展示辅助 ---------------- */

function healthMeta(health: CultivationHealth): { label: string; tone: 'brand' | 'gold' | 'warn' | 'danger' | 'info' | 'muted' } {
  return CULTIVATION_HEALTH_META[health] ?? { label: '—', tone: 'muted' }
}

/** 主推课型进度百分比（target 为 0 时不臆造，直接 0） */
function pct(row: CultivationRecord): number {
  const { signed, target } = row.primaryProgress
  if (!target) return 0
  return Math.min(100, Math.round((signed / target) * 100))
}

function daysText(row: CultivationRecord): string {
  if (row.enrolledDays === null || row.enrolledDays === undefined) return '入会天数未知'
  if (row.enrolledDays < 0) return `入会日期在未来（${row.enrolledAt}）`
  return `入会 ${row.enrolledDays} 天`
}

/** 主题 chips：后端已按出现次数降序取前 6，这里不再排序 */
function themesOf(row: CultivationRecord): string[] {
  return (row.themes ?? []).filter(Boolean)
}

onShow(() => {
  if (requireFeature('newMembers')) load()
})

onPullDownRefresh(async () => {
  await load()
  uni.stopPullDownRefresh()
})

onReachBottom(() => {
  if (hasMore.value) visible.value += PAGE_SIZE
})
</script>

<template>
  <view class="ym-page nm">
    <!-- 4 张统计卡：点卡片按健康度筛选（再点一次取消，默认全部） -->
    <view class="nm__stats">
      <view
        v-for="card in stats"
        :key="card.key"
        class="nm__stat"
        :class="{ 'is-active': card.active }"
        @click="toggleHealth(card)"
      >
        <ym-stat-card
          :label="card.label"
          :value="card.value"
          unit="位"
          :hint="card.hint"
          :tone="card.tone"
          :clickable="true"
        />
      </view>
    </view>

    <!-- 当前筛选摘要：文本可横向滚动，清除按钮固定右侧不被挤压 -->
    <view v-if="loaded" class="nm__active">
      <scroll-view class="nm__active-scroll" scroll-x :show-scrollbar="false">
        <view class="nm__active-inner">
          <text class="nm__active-text">{{ activeText }}</text>
        </view>
      </scroll-view>
      <text v-if="hasActiveFilter" class="nm__active-clear" @click="resetFilters">清除筛选</text>
    </view>

    <!-- 筛选：搜索 + 课型 segmented + 门店 picker（仅超管） -->
    <view class="ym-card nm__filters">
      <wd-search
        v-model="keyword"
        placeholder="搜姓名"
        hide-cancel
        @search="search"
        @clear="search"
      />
      <view class="nm__seg">
        <wd-segmented
          :value="kindLabel"
          :options="kindSegOptions"
          size="small"
          @change="onKindChange"
        />
      </view>
      <view v-if="canPickVenue" class="nm__venue">
        <wd-picker
          label="门店"
          title="选择门店"
          :columns="venueColumns"
          :model-value="venue"
          @confirm="onVenueConfirm"
        />
      </view>
      <view v-else class="nm__venue-text">
        <text class="ym-muted">门店：{{ venueText }}</text>
      </view>
      <view class="nm__targets">
        <text class="ym-muted">
          养成目标：私教 {{ targets.private }} 节 · 小班 {{ targets.small }} 节 · 团课 {{ targets.group }} 节
        </text>
      </view>
      <view v-if="loaded" class="nm__opened">
        <text class="ym-muted">
          已开课人数：私教 {{ summary.private }} · 小班 {{ summary.small }} · 团课 {{ summary.group }}
        </text>
      </view>
      <view class="nm__hint">
        <text class="ym-muted">{{ filterHint }}</text>
      </view>
      <view class="nm__tip">
        <text class="ym-muted">点上方统计卡可按健康度筛选，再点一次取消</text>
      </view>
    </view>

    <!-- 三态：加载骨架 / 失败重试 / 空 -->
    <view v-if="loading && !loaded" class="nm__skeleton">
      <view v-for="i in 3" :key="i" class="ym-card">
        <wd-skeleton theme="paragraph" :row-col="[3, 3, 2]" :loading="true" animation="gradient" />
      </view>
    </view>

    <ym-empty
      v-else-if="failed"
      text="加载失败"
      desc="网络或服务异常，可重试"
      action-text="重新加载"
      icon="warning"
      @action="load"
    />

    <ym-empty
      v-else-if="loaded && !filtered.length"
      :text="emptyText"
      :desc="emptyDesc"
      action-text="清除筛选"
      icon="user"
      @action="resetFilters"
    />

    <template v-else>
      <view v-for="row in shown" :key="row.id" class="ym-card nm-card ym-tap" @click="openMember(row.id)">
        <view class="nm-card__head">
          <view class="nm-card__who">
            <text class="nm-card__name">{{ row.name }}</text>
            <text class="nm-card__sub ym-muted">
              {{ row.venue }}<text v-if="row.consultant"> · {{ row.consultant }}</text>
            </text>
          </view>
          <ym-badge :text="healthMeta(row.health).label" :tone="healthMeta(row.health).tone" />
        </view>

        <view class="nm-card__days-row">
          <text class="nm-card__days ym-num">{{ daysText(row) }}</text>
          <text class="ym-muted nm-card__card">{{ row.mainCard || '未持卡' }}</text>
        </view>

        <view class="nm-card__progress-head">
          <text class="nm-card__progress-label">{{ CULTIVATION_KIND_LABELS[row.primaryKind] }}养成进度</text>
          <text class="nm-card__progress-num ym-num">
            {{ row.primaryProgress.signed }} / {{ row.primaryProgress.target }} 节
          </text>
        </view>
        <view class="nm-card__bar">
          <wd-progress :percentage="pct(row)" hide-text />
        </view>

        <view v-if="themesOf(row).length" class="nm-card__themes">
          <text v-for="theme in themesOf(row)" :key="theme" class="ym-tag">{{ theme }}</text>
        </view>

        <view class="nm-card__foot">
          <text class="ym-muted">
            已上 {{ row.totalSigned }} 节 · 已约 {{ row.categories[row.primaryKind].booked }} · 爽约
            {{ row.categories[row.primaryKind].no_show }}
          </text>
          <wd-icon name="chevron-right" size="13px" custom-class="nm-card__arrow" />
        </view>
      </view>

      <wd-loadmore
        v-if="filtered.length && (!hasMore || loading)"
        :state="loading ? 'loading' : 'finished'"
        loading-text="加载中…"
        :finished-text="`已显示全部 ${filtered.length} 位新客`"
      />
      <view v-else-if="hasMore" class="nm__more-tip">
        <text class="ym-muted">继续上滑加载更多（已显示 {{ shown.length }}/{{ filtered.length }}）</text>
      </view>
    </template>
  </view>
</template>

<style lang="scss" scoped>
@import '@/styles/tokens.scss';

.nm {
  &__stats {
    display: flex;
    flex-wrap: wrap;
    gap: 16rpx;
    padding: 24rpx 24rpx 4rpx;
  }

  &__stat {
    width: calc(50% - 8rpx);
    /* 3rpx 内边距常驻：选中时露出品牌色描边，不产生位移抖动 */
    padding: 3rpx;
    background: transparent;
    border-radius: calc(#{$r-lg} + 6rpx);
    transition: background 0.15s ease;

    &.is-active {
      background: $brand-500;
    }
  }

  &__active {
    display: flex;
    flex-wrap: nowrap;
    gap: 12rpx;
    align-items: center;
    padding: 14rpx 28rpx 0;
  }

  &__active-scroll {
    flex: 1;
    min-width: 0;
    white-space: nowrap;
  }

  &__active-inner {
    display: inline-flex;
    align-items: center;
  }

  &__active-text {
    font-size: 22rpx;
    color: $ink-500;
    white-space: nowrap;
  }

  &__active-clear {
    flex-shrink: 0;
    padding: 8rpx 20rpx;
    font-size: 22rpx;
    color: $brand-600;
    background: $brand-50;
    border-radius: 999rpx;
  }

  &__filters {
    margin-top: 20rpx;
  }

  &__seg {
    margin-top: 20rpx;
  }

  &__venue {
    margin-top: 8rpx;
  }

  &__venue-text {
    margin-top: 16rpx;
  }

  &__targets {
    margin-top: 14rpx;
  }

  &__opened {
    margin-top: 6rpx;
  }

  &__hint {
    margin-top: 10rpx;
    line-height: 1.6;
  }

  &__tip {
    margin-top: 6rpx;
  }

  &__skeleton {
    display: flex;
    flex-direction: column;
  }

  &__more-tip {
    padding: 24rpx 0 12rpx;
    text-align: center;
  }
}

.nm-card {
  &__head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16rpx;
  }

  &__who {
    display: flex;
    flex-direction: column;
    gap: 4rpx;
    min-width: 0;
  }

  &__name {
    font-size: 32rpx;
    font-weight: 600;
    color: $ink-800;
  }

  &__sub {
    font-size: 22rpx;
  }

  &__days-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16rpx;
    margin-top: 14rpx;
  }

  &__days {
    font-size: 26rpx;
    font-weight: 600;
    color: $brand-600;
  }

  &__card {
    overflow: hidden;
    max-width: 60%;
    font-size: 22rpx;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  &__progress-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 18rpx;
  }

  &__progress-label {
    font-size: 24rpx;
    color: $ink-600;
  }

  &__progress-num {
    font-size: 24rpx;
    font-weight: 600;
    color: $ink-700;
  }

  /* wd-progress 的默认色是组件库常量（读不到 $brand-500），在此用令牌覆盖；
     组件已声明 styleIsolation: shared，页面样式可穿透。 */
  &__bar {
    margin-top: 8rpx;

    :deep(.wd-progress__inner) {
      background: $brand-500;
    }
  }

  &__themes {
    display: flex;
    flex-wrap: wrap;
    gap: 10rpx;
    margin-top: 18rpx;
  }

  &__foot {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12rpx;
    margin-top: 16rpx;
    padding-top: 14rpx;
    border-top: 2rpx solid $hairline;
  }

  &__arrow {
    flex-shrink: 0;
    color: $ink-200;
  }
}
</style>
