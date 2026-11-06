<script setup lang="ts">
/**
 * 课后分析列表（体验课交付凭证）
 *
 * 本轮修复的真机问题（授课老师反馈）：
 *  1. 行点不开 → 现在整行可点，进 pages/review/detail?id=
 *  2. 不能新建 → 顶部「新建」进 detail?mode=create 的两步向导
 *  3. 姓名/时间一直是「会员 / —」→ 旧代码读的是 customerName / classDate，
 *     后端实际字段是 studentName / classAt（PostClassReviewController::index）
 *
 * 数据：GET /post-class-reviews（服务端分页 size=20，触底加载）
 * 权限：requireFeature('reviews') = OPS；写权限更窄（SUPER/MANAGER/TEACHER），
 *      服务老师只读 —— 新建按钮按同一口径隐藏。
 */
import { computed, ref } from 'vue'
import { onPullDownRefresh, onReachBottom, onShow, onShareAppMessage } from '@dcloudio/uni-app'
import { shareToHome } from '@/utils/share'
import { useUserStore } from '@/store/user'
import { requireFeature } from '@/utils/nav'
import { hasAnyRole } from '@/utils/permission'
import { fetchReviews, type ReviewRow } from '@/api/review'

const PAGE_SIZE = 20

/** 写权限（与 PostClassReviewController::assertCanWrite 同口径） */
const WRITE_ROLES: Array<'R_SUPER' | 'R_MANAGER' | 'R_TEACHER'> = [
  'R_SUPER',
  'R_MANAGER',
  'R_TEACHER'
]

const user = useUserStore()
const list = ref<ReviewRow[]>([])
const total = ref(0)
const page = ref(1)
const summary = ref({ total: 0, confirmed: 0, redFlag: 0 })
const loading = ref(false)
const loadingMore = ref(false)
const loaded = ref(false)
const failed = ref(false)
const errorText = ref('')

const TABS = [
  { key: 'all', label: '全部' },
  { key: 'pending', label: '待确认' },
  { key: 'confirmed', label: '已确认' }
] as const
type TabKey = (typeof TABS)[number]['key']

const tab = ref<TabKey>('all')

const canWrite = computed(() => hasAnyRole(user.roles, WRITE_ROLES))
const hasMore = computed(() => list.value.length < total.value)
const tabLabel = computed(() => TABS.find((t) => t.key === tab.value)?.label ?? '全部')

/** tab → 后端 status 参数（后端 status 只有「草稿 / 已确认」两值） */
function statusParam(): string | undefined {
  if (tab.value === 'pending') return '草稿'
  if (tab.value === 'confirmed') return '已确认'
  return undefined
}

/**
 * 请求序号：切页签/下拉刷新会打断在途请求，旧响应回来必须丢弃 ——
 * 否则「从待确认切到已确认」时，慢一步的待确认响应会把已确认列表覆盖掉。
 */
let reqSeq = 0

async function load(reset = true) {
  // 触底追加时若有请求在飞就跳过；reset 允许打断在途请求（靠序号丢弃旧响应）
  if (!reset && (loading.value || loadingMore.value)) return
  const my = ++reqSeq
  if (reset) {
    page.value = 1
    loading.value = true
  } else {
    loadingMore.value = true
  }
  failed.value = false
  errorText.value = ''
  try {
    const res = await fetchReviews({
      status: statusParam(),
      current: page.value,
      size: PAGE_SIZE
    })
    if (my !== reqSeq) return
    const records = Array.isArray(res?.records) ? res.records : []
    list.value = reset ? records : [...list.value, ...records]
    total.value = Number(res?.total) || list.value.length
    summary.value = {
      total: Number(res?.summary?.total) || 0,
      confirmed: Number(res?.summary?.confirmed) || 0,
      redFlag: Number(res?.summary?.redFlag) || 0
    }
    loaded.value = true
  } catch (e) {
    if (my !== reqSeq) return
    failed.value = true
    errorText.value = e instanceof Error ? e.message : '加载失败'
  } finally {
    if (my === reqSeq) {
      loading.value = false
      loadingMore.value = false
    }
  }
}

onShow(() => {
  if (!requireFeature('reviews')) return
  void load(true)
})

onPullDownRefresh(async () => {
  await load(true)
  uni.stopPullDownRefresh()
})

onReachBottom(() => {
  if (!hasMore.value || loading.value || loadingMore.value) return
  page.value += 1
  void load(false)
})

function switchTab(next: TabKey) {
  if (tab.value === next) return
  tab.value = next
  list.value = []
  loaded.value = false
  void load(true)
}

function openDetail(row: ReviewRow) {
  uni.navigateTo({ url: `/pkg-biz/review/detail?id=${row.id}` })
}

function openCreate() {
  if (!canWrite.value) {
    uni.showToast({ title: '仅授课老师、店长可填写课后分析', icon: 'none' })
    return
  }
  uni.navigateTo({ url: '/pkg-biz/review/detail?mode=create' })
}

function statusTone(status?: string): 'brand' | 'warn' | 'info' | 'muted' {
  if (!status) return 'muted'
  if (status.includes('已确认')) return 'brand'
  if (status.includes('草稿') || status.includes('待')) return 'warn'
  return 'info'
}

const SCENE_LABELS: Record<string, string> = {
  trial: '体验课',
  private_first: '私教首课',
  private: '私教常规课'
}

function sceneLabel(row: ReviewRow): string {
  return row.sceneLabel || SCENE_LABELS[row.scene] || '课后'
}

/** 副标题：类型 · 课程（缺就省略，不留空分隔符） */
function subtitle(row: ReviewRow): string {
  return [row.studentType, row.courseName].filter((x) => !!x && x !== '—').join(' · ')
}

// 转发：员工页统一回首页（分享自己会撞 requireLogin 的登录墙），标题不带客户信息 —— 见 utils/share.ts
onShareAppMessage(() => shareToHome())
</script>

<template>
  <view class="ym-page review">
    <!-- 摘要 + 新建 -->
    <view class="rhead">
      <view class="rhead__main">
        <text class="rhead__label">{{ tabLabel }} · 共</text>
        <view class="rhead__value-row">
          <text class="rhead__value ym-num">{{ summary.total || total }}</text>
          <text class="rhead__unit">份 · 已确认 {{ summary.confirmed }} · 红线 {{ summary.redFlag }}</text>
        </view>
      </view>
      <view v-if="canWrite" class="rhead__btn ym-tap" @click="openCreate">
        <wd-icon name="add" size="14px" color="#ffffff" />
        <text>新建</text>
      </view>
    </view>

    <!-- 分组页签 -->
    <view class="rfilters">
      <view
        v-for="t in TABS"
        :key="t.key"
        class="rfilters__item ym-tap"
        :class="{ 'is-active': tab === t.key }"
        @click="switchTab(t.key)"
      >
        {{ t.label }}
      </view>
    </view>

    <!-- 三态：失败 -->
    <view v-if="failed && !list.length" class="ym-card">
      <ym-empty
        icon="warning"
        text="课后分析加载失败"
        :desc="errorText || '网络或服务异常，可下拉刷新重试'"
        action-text="重试"
        @action="load(true)"
      />
    </view>

    <!-- 三态：加载 -->
    <view v-else-if="loading && !list.length" class="ym-card">
      <view v-for="i in 3" :key="i" class="sk-row">
        <wd-skeleton
          theme="text"
          animation="gradient"
          :row-col="[{ width: '40%' }, { width: '74%' }, { width: '52%' }]"
        />
      </view>
    </view>

    <!-- 三态：空 -->
    <ym-empty
      v-else-if="loaded && !list.length"
      icon="note"
      text="暂无课后分析"
      :desc="tab === 'all' ? '体验课或私教课后由老师填写' : '这个分组下还没有记录'"
      action-text="刷新"
      @action="load(true)"
    />

    <view v-for="r in list" :key="r.id" class="rcard ym-tap" @click="openDetail(r)">
      <view class="rcard__head">
        <text class="rcard__name ym-ellipsis">{{ r.studentName || '未命名会员' }}</text>
        <ym-badge v-if="r.redFlag" text="红线" tone="danger" />
        <ym-badge v-if="r.status" :text="r.status" :tone="statusTone(r.status)" />
      </view>

      <text v-if="subtitle(r)" class="rcard__sub ym-ellipsis">{{ subtitle(r) }}</text>

      <view class="rcard__meta">
        <text class="rcard__tag">{{ sceneLabel(r) }}</text>
        <text class="rcard__dot">·</text>
        <text class="rcard__mtext ym-ellipsis">{{ r.classAt || '未填上课时间' }}</text>
        <text class="rcard__dot">·</text>
        <text class="rcard__mtext">{{ r.teacherName || '—' }}</text>
      </view>

      <view class="rcard__foot">
        <text class="rcard__venue">{{ r.venue || '—' }}</text>
        <view class="rcard__more">
          <text>查看详情</text>
          <wd-icon name="chevron-right" size="12px" color="#8A948F" />
        </view>
      </view>
    </view>

    <view v-if="loadingMore" class="rload">加载中…</view>
    <view v-else-if="list.length && !hasMore" class="rload">没有更多了</view>

    <view class="tip">
      <text class="tip__text">
        {{
          canWrite
            ? '点任意一条可查看、编辑或确认'
            : '服务老师可查看名下会员的课后分析，填写与确认由授课老师完成'
        }}
      </text>
    </view>
  </view>
</template>

<style lang="scss" scoped>
@import '@/styles/tokens.scss';

/* ---------- 摘要 ---------- */
.rhead {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20rpx;
  padding: 26rpx 28rpx;
  margin: 20rpx 24rpx 0;
  background: linear-gradient(150deg, $brand-50, $white 62%);
  border: 2rpx solid $brand-100;
  border-radius: $r-lg;
}

.rhead__main {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 8rpx;
  min-width: 0;
}

.rhead__label {
  font-size: 24rpx;
  color: $ink-500;
  letter-spacing: 0.5rpx;
}

.rhead__value-row {
  display: flex;
  flex-wrap: wrap;
  gap: 10rpx;
  align-items: baseline;
}

.rhead__value {
  font-size: 48rpx;
  font-weight: 600;
  line-height: 1.15;
  color: $ink-800;
}

.rhead__unit {
  font-size: 22rpx;
  color: $ink-400;
}

.rhead__btn {
  display: flex;
  flex-shrink: 0;
  gap: 6rpx;
  align-items: center;
  justify-content: center;
  min-width: 150rpx;
  min-height: 88rpx;
  padding: 0 26rpx;
  font-size: 26rpx;
  color: $white;
  background: $brand-500;
  border-radius: 999rpx;
}

/* ---------- 页签 ---------- */
.rfilters {
  display: flex;
  gap: 12rpx;
  padding: 20rpx 24rpx 0;
}

.rfilters__item {
  flex: 1;
  min-height: 76rpx;
  font-size: 26rpx;
  line-height: 76rpx;
  color: $ink-500;
  text-align: center;
  background: $white;
  border: 2rpx solid $hairline;
  border-radius: 999rpx;

  &.is-active {
    font-weight: 600;
    color: $brand-600;
    background: $brand-100;
    border-color: $brand-200;
  }
}

/* ---------- 骨架 ---------- */
.sk-row {
  padding: 20rpx 0;

  & + & {
    border-top: 2rpx solid $hairline;
  }
}

/* ---------- 列表卡 ---------- */
.rcard {
  padding: 26rpx 28rpx;
  margin: 20rpx 24rpx 0;
  background: $card-bg;
  border-radius: $r-lg;
  box-shadow: $shadow-card;
}

.rcard__head {
  display: flex;
  gap: 12rpx;
  align-items: center;
  min-width: 0;
}

.rcard__name {
  display: block;
  flex: 1;
  min-width: 0;
  font-size: 32rpx;
  font-weight: 600;
  color: $ink-800;
}

.rcard__sub {
  display: block;
  margin-top: 8rpx;
  font-size: 24rpx;
  color: $ink-500;
}

.rcard__meta {
  display: flex;
  gap: 8rpx;
  align-items: center;
  margin-top: 12rpx;
  min-width: 0;
}

.rcard__tag {
  flex-shrink: 0;
  padding: 2rpx 12rpx;
  font-size: 20rpx;
  color: $brand-600;
  background: $brand-50;
  border-radius: 6rpx;
}

.rcard__dot {
  flex-shrink: 0;
  font-size: 22rpx;
  color: $ink-300;
}

.rcard__mtext {
  min-width: 0;
  font-size: 23rpx;
  color: $ink-500;
}

.rcard__foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-top: 16rpx;
  margin-top: 16rpx;
  border-top: 2rpx solid $hairline;
}

.rcard__venue {
  font-size: 22rpx;
  color: $ink-400;
}

.rcard__more {
  display: flex;
  gap: 4rpx;
  align-items: center;
  font-size: 23rpx;
  color: $brand-600;
}

.rload {
  padding: 28rpx 0;
  font-size: 22rpx;
  color: $ink-400;
  text-align: center;
}

/* ---------- 底部说明 ---------- */
.tip {
  padding: 32rpx 40rpx calc(40rpx + env(safe-area-inset-bottom));
  text-align: center;
}

.tip__text {
  font-size: 22rpx;
  line-height: 1.6;
  color: $ink-400;
}
</style>
