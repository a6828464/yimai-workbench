<script setup lang="ts">
/**
 * 训练计划列表（授课老师端）
 *
 * 本轮修复的真机问题：
 *  1. 行点不开 → 整行可点，存本地草稿后进 pages/plan/detail?id=
 *     （后端**没有** GET /training-plans/{id}，详情只能靠列表行传递，见 api/review.ts savePlanDraft）
 *  2. 不能新建/编辑 → 顶部「新建」，详情页内「编辑」
 *  3. 周期/更新时间一直是「—」→ 旧代码读 weeks / updatedAt，后端字段是 stageWeeks / createdAt
 *
 * 数据：GET /training-plans（裸数组，非分页；超管全部 / 店长本店 / 其他人本人的）
 * 权限：requireFeature('plans') = TEACHING（SUPER / MANAGER / TEACHER）
 * 注意：pages.json 里本页没有 enablePullDownRefresh，所以下拉刷新不会触发 —— 改用显式「刷新」。
 */
import { computed, ref } from 'vue'
import { onShow, onShareAppMessage } from '@dcloudio/uni-app'
import { shareToHome } from '@/utils/share'
import { requireFeature } from '@/utils/nav'
import { fetchTrainingPlans, savePlanDraft, type TrainingPlan } from '@/api/review'

const list = ref<TrainingPlan[]>([])
const loading = ref(false)
const loaded = ref(false)
const failed = ref(false)
const errorText = ref('')

const confirmedCount = computed(() => list.value.filter((p) => p.status === '已确认').length)
const pendingCount = computed(() => list.value.filter((p) => p.status !== '已确认').length)

/** 请求在途时又被要求刷新（onShow 紧跟「刷新」按钮）：结束后补跑一次，别丢掉后一次 */
let pendingReload = false
/** 请求序号：旧回包晚到时作废，不覆盖新结果（同 crm/pool.vue 的写法） */
let requestSeq = 0

async function load(): Promise<void> {
  // 旧写法 `if (loading.value) return` 会把「刷新」静默丢掉：按钮点下去没反应，
  // 列表还是旧数据（从详情页改完计划返回时最容易撞上）。
  if (loading.value) {
    pendingReload = true
    return
  }
  const seq = ++requestSeq
  loading.value = true
  failed.value = false
  errorText.value = ''
  try {
    const rows = await fetchTrainingPlans()
    if (seq !== requestSeq) return
    list.value = rows
    loaded.value = true
  } catch (e) {
    // 只认最新一次请求的失败：旧请求报错不该把已经刷出来的新列表换成错误态
    if (seq !== requestSeq) return
    failed.value = true
    errorText.value = e instanceof Error ? e.message : '加载失败'
  } finally {
    loading.value = false
    if (pendingReload) {
      pendingReload = false
      void load()
    }
  }
}

onShow(() => {
  if (!requireFeature('plans')) return
  void load()
})

function openDetail(plan: TrainingPlan) {
  // 整行塞本地草稿：详情页没有 show 接口，这是唯一的数据来源
  savePlanDraft(plan)
  uni.navigateTo({ url: `/pkg-biz/plan/detail?id=${plan.id}` })
}

function openCreate() {
  uni.navigateTo({ url: '/pkg-biz/plan/detail?mode=create' })
}

function statusTone(s?: string): 'brand' | 'gold' | 'warn' | 'muted' {
  if (!s) return 'muted'
  if (s.includes('已确认')) return 'brand'
  if (s.includes('待老师确认')) return 'gold'
  if (s.includes('待')) return 'warn'
  return 'muted'
}

/** 卡片副标题：核心目标优先，没有就用关注点 */
function subtitle(p: TrainingPlan): string {
  return p.coreGoal || p.focus || p.content?.summary || ''
}

/** 周期：优先 stageWeeks（后端独立列），没有就用频次兜底 */
function cycleText(p: TrainingPlan): string {
  const weeks = (p.stageWeeks || '').replace(/[^0-9.]/g, '')
  if (weeks) return `${weeks} 周`
  return p.freq || '—'
}

function sourceText(p: TrainingPlan): string {
  if (p.sourceReviewId) return '课后分析流转'
  if (p.source === 'llm') return 'AI 生成'
  if (p.source === 'fallback') return '规则模板'
  return ''
}

// 转发：员工页统一回首页（分享自己会撞 requireLogin 的登录墙），标题不带客户信息 —— 见 utils/share.ts
onShareAppMessage(() => shareToHome())
</script>

<template>
  <view class="ym-page plan">
    <!-- 摘要 + 新建 -->
    <view class="phead">
      <view class="phead__main">
        <text class="phead__label">训练计划 · 共</text>
        <view class="phead__value-row">
          <text class="phead__value ym-num">{{ list.length }}</text>
          <text class="phead__unit">份 · 已确认 {{ confirmedCount }} · 待确认 {{ pendingCount }}</text>
        </view>
      </view>
      <view class="phead__btn ym-tap" @click="openCreate">
        <wd-icon name="add" size="14px" color="#ffffff" />
        <text>新建</text>
      </view>
    </view>

    <view class="ptoolbar">
      <text class="ptoolbar__hint">超管看全部，店长看本店，其他人只看自己建的</text>
      <text class="ptoolbar__act ym-tap" @click="load">{{ loading ? '刷新中…' : '刷新' }}</text>
    </view>

    <!-- 三态：失败 -->
    <view v-if="failed && !list.length" class="ym-card">
      <ym-empty
        icon="warning"
        text="训练计划加载失败"
        :desc="errorText || '网络或服务异常'"
        action-text="重试"
        @action="load"
      />
    </view>

    <!-- 三态：加载 -->
    <view v-else-if="loading && !list.length" class="ym-card">
      <view v-for="i in 3" :key="i" class="sk-row">
        <wd-skeleton
          theme="text"
          animation="gradient"
          :row-col="[{ width: '46%' }, { width: '68%' }, { width: '38%' }]"
        />
      </view>
    </view>

    <!-- 三态：空 -->
    <ym-empty
      v-else-if="loaded && !list.length"
      icon="books"
      text="暂无训练计划"
      desc="点右上「新建」为会员排一份，或由课后分析流转生成"
      action-text="新建计划"
      @action="openCreate"
    />

    <view v-for="p in list" :key="p.id" class="plcard ym-tap" @click="openDetail(p)">
      <view class="plcard__head">
        <text class="plcard__name ym-ellipsis">{{ p.memberName }}</text>
        <ym-badge v-if="p.status" :text="p.status" :tone="statusTone(p.status)" />
      </view>

      <text v-if="subtitle(p)" class="plcard__sub ym-ellipsis">{{ subtitle(p) }}</text>

      <view class="plcard__meta">
        <text class="plcard__tag">周期 {{ cycleText(p) }}</text>
        <text v-if="p.freq" class="plcard__dot">·</text>
        <text v-if="p.freq" class="plcard__mtext ym-ellipsis">{{ p.freq }}</text>
      </view>

      <view class="plcard__foot">
        <text class="plcard__from">{{ sourceText(p) || p.venue || '—' }}</text>
        <!-- 微信深度合成整改：AI 生成的计划在列表也要显著标识（2026-11-07） -->
        <text v-if="p.source === 'llm' && !p.sourceReviewId" class="plcard__ai">内容由人工智能（AI）生成</text>
        <view class="plcard__more">
          <text>{{ p.createdAt || '查看详情' }}</text>
          <wd-icon name="chevron-right" size="12px" color="#8A948F" />
        </view>
      </view>
    </view>

    <view class="tip">
      <text class="tip__text">点任意一条查看阶段与动作；新建/编辑后电脑端同步可见</text>
    </view>
  </view>
</template>

<style lang="scss" scoped>
@import '@/styles/tokens.scss';

/* ---------- 头部摘要 ---------- */
.phead {
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

.phead__main {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 8rpx;
  min-width: 0;
}

.phead__label {
  font-size: 24rpx;
  color: $ink-500;
  letter-spacing: 0.5rpx;
}

.phead__value-row {
  display: flex;
  flex-wrap: wrap;
  gap: 10rpx;
  align-items: baseline;
}

.phead__value {
  font-size: 48rpx;
  font-weight: 600;
  line-height: 1.15;
  color: $ink-800;
}

.phead__unit {
  font-size: 22rpx;
  color: $ink-400;
}

.phead__btn {
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

/* ---------- 工具条 ---------- */
.ptoolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16rpx;
  padding: 18rpx 30rpx 4rpx;
}

.ptoolbar__hint {
  flex: 1;
  min-width: 0;
  font-size: 21rpx;
  color: $ink-400;
}

.ptoolbar__act {
  flex-shrink: 0;
  min-height: 56rpx;
  padding: 0 20rpx;
  font-size: 23rpx;
  line-height: 56rpx;
  color: $brand-600;
  background: $brand-50;
  border-radius: 999rpx;
}

/* ---------- 骨架 ---------- */
.sk-row {
  padding: 20rpx 0;

  & + & {
    border-top: 2rpx solid $hairline;
  }
}

/* ---------- 计划卡 ---------- */
.plcard {
  padding: 26rpx 28rpx;
  margin: 20rpx 24rpx 0;
  background: $card-bg;
  border-radius: $r-lg;
  box-shadow: $shadow-card;
}

.plcard__head {
  display: flex;
  gap: 14rpx;
  align-items: center;
  min-width: 0;
}

.plcard__name {
  display: block;
  flex: 1;
  min-width: 0;
  font-size: 32rpx;
  font-weight: 600;
  color: $ink-800;
}

.plcard__sub {
  display: block;
  margin-top: 8rpx;
  font-size: 24rpx;
  color: $ink-500;
}

.plcard__meta {
  display: flex;
  gap: 8rpx;
  align-items: center;
  margin-top: 12rpx;
  min-width: 0;
}

.plcard__tag {
  flex-shrink: 0;
  padding: 2rpx 12rpx;
  font-size: 20rpx;
  color: $brand-600;
  background: $brand-50;
  border-radius: 6rpx;
}

.plcard__dot {
  flex-shrink: 0;
  font-size: 22rpx;
  color: $ink-300;
}

.plcard__mtext {
  min-width: 0;
  font-size: 23rpx;
  color: $ink-500;
}

.plcard__foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-top: 16rpx;
  margin-top: 16rpx;
  border-top: 2rpx solid $hairline;
}

.plcard__from {
  font-size: 22rpx;
  color: $ink-400;
}

/* AI 生成标识（微信深度合成整改：显著、可识别） */
.plcard__ai {
  flex: none;
  margin-left: 10rpx;
  padding: 2rpx 10rpx;
  font-size: 20rpx;
  color: #2e7d5b;
  border: 1rpx solid rgba(46, 125, 91, 0.4);
  border-radius: 6rpx;
  background: rgba(46, 125, 91, 0.06);
}

.plcard__more {
  display: flex;
  gap: 4rpx;
  align-items: center;
  font-size: 22rpx;
  color: $ink-400;
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
