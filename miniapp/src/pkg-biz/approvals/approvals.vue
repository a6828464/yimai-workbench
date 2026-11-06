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
import { onShow, onPullDownRefresh, onReachBottom, onShareAppMessage } from '@dcloudio/uni-app'
import { shareToHome } from '@/utils/share'
import { requireLogin } from '@/utils/guard'
import { requireFeature } from '@/utils/nav'
import { createApproval, fetchApprovals, decideApproval, type CreateApprovalPayload } from '@/api/approvals'
// 别名导入：本页要保持变量名 isSuper（脚本里 isSuper.value、模板里 v-if="isSuper" 都在用）
import { isSuper as isSuperRole } from '@/utils/permission'
import { useUserStore } from '@/store/user'
import type { ApprovalItem } from '@/types'

const user = useUserStore()

/**
 * 分页口径（修「第 51 单起静默消失」）。
 *
 * 旧实现是一次性把前 50 条拉完、没有任何分页 —— 后端 size 上限 100，
 * 于是第 51 单开始既看不到、也没有任何提示；而审批单只会随时间累积，
 * 漏掉一单 = 有人永远等不到审批结果，这是数据完整性问题不是体验问题。
 * 现在按 20/页翻页：小页让首屏更快，触底继续拉，底部明示「已加载 N/M 单」。
 */
const PAGE_SIZE = 20

const list = ref<ApprovalItem[]>([])
/** 服务端总数（GET /approvals 的响应带 total） */
const total = ref(0)
const page = ref(1)
const loading = ref(false)
/** 触底追加：与首屏 loading 分开，否则「加载更多」提示会顶掉整页骨架 */
const loadingMore = ref(false)
const loaded = ref(false)
const failed = ref(false)
const acting = ref(0)
const forbidden = ref(false)

/** 还有没有下一页（已加载条数 < 服务端总数） */
const hasMore = computed(() => list.value.length < total.value)

/**
 * F21：是否超管 —— 走 permission.ts 的单一来源（页面不碰角色编码字面量）。
 * 变量名保持不变，脚本里的 isSuper.value 与模板里的 v-if="isSuper" 都不用改。
 */
const isSuper = computed(() => isSuperRole(user.roles))

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

/**
 * 请求序号：onShow / 下拉刷新 / 触底加载三者会并发，与 review/review.vue 同一套 ——
 * 只认最后一笔，过期响应直接丢弃。审批页尤其不能错序：错序会把刚审完的单子
 * 又按旧快照显示成「待处理」，店长会重复审同一单。
 */
let reqSeq = 0

/** 请求序号 + loading/loadingMore 分离的分页加载（reset=true 重新从第 1 页拉） */
async function load(reset = true) {
  // 触底追加时若有请求在飞就跳过；reset 允许打断在途请求（靠序号丢弃旧响应）
  if (!reset && (loading.value || loadingMore.value)) return
  const my = ++reqSeq
  if (reset) {
    page.value = 1
    loading.value = true
    forbidden.value = false
  } else {
    loadingMore.value = true
  }
  try {
    const res = await fetchApprovals({ current: page.value, size: PAGE_SIZE })
    if (my !== reqSeq) return
    total.value = res.total ?? 0
    list.value = reset ? res.records : [...list.value, ...res.records]
    loaded.value = true
    failed.value = false
  } catch (e) {
    if (my !== reqSeq) return
    if ((e as { code?: number })?.code === 403) forbidden.value = true
    else failed.value = true
  } finally {
    if (my === reqSeq) {
      loading.value = false
      loadingMore.value = false
    }
  }
  // 首屏（或下拉刷新）之后，如果「待处理」这一屏还是空的，自动往后补几页（见 ensurePendingVisible）
  if (reset) void ensurePendingVisible()
}

/**
 * 「待处理」组的自动续页。
 *
 * 为什么需要：后端 index 是 `orderBy('id')` **升序**（老单在前）。历史积累之后，
 * 第 1 页很可能全是已处理的老单 —— 前端一分组，pending 组就是空，
 * 用户看到「没有待处理的审批单」就走了，而真正要审的单还躺在第 2、3 页里。
 * 这正是「静默不可见」的另一种形态，光有分页挡不住。
 *
 * 所以：待处理组、还有更多、当前一页里一条 pending 都没有 → 自动再拉一页。
 * 上限 3 页（60 单），避免弱网下变成不停转圈的自动翻页；
 * 超过上限仍没找到时，空态会显示「已加载 N/M 单，继续下拉加载」而不是「没有待处理」。
 */
const AUTO_FILL_PAGES = 3

async function ensurePendingVisible(): Promise<void> {
  let guard = 0
  while (
    group.value === 'pending' &&
    hasMore.value &&
    !list.value.some(isPending) &&
    guard < AUTO_FILL_PAGES &&
    !loading.value &&
    !loadingMore.value
  ) {
    guard += 1
    page.value += 1
    await load(false)
  }
}

/** 触底/按钮共用的「加载下一页」（模板里不能直接写 page.value += 1） */
function loadMore(): void {
  if (!hasMore.value || loading.value || loadingMore.value) return
  page.value += 1
  void load(false)
}

onReachBottom(loadMore)

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

/**
 * 金额显示（顺带修掉的存量 P1）。
 *
 * 后端 `standard_price` / `request_price` 是 `unsignedBigInteger`、模型无 casts，
 * web 端 `fmtPrice(v) => \`¥${v.toLocaleString()}\`` 直接展示 —— 全链路单位就是「元」。
 * 本页此前写成 `(v / 100).toFixed(0)`（当成分），于是 3200 元的卡项在手机上显示成 ¥32，
 * 审批人按 ¥32 决策、店长以为自己填错了。现按元显示，与 web / 后端一致。
 */
function amount(v: number): string {
  const n = Number(v)
  return Number.isFinite(n) ? n.toLocaleString('zh-CN') : '0'
}

/* ==========================================================================
 * 发起价格审批（F08）
 *
 * 为什么要在小程序里做：店长在门店谈单让价是最高频的现场动作，此前只能回电脑发起，
 * 客户在等。字段与校验全部照后端 ApprovalController::store：
 *   customerName ≤20 · cardName ≤40 · standardPrice/requestPrice 为「元」的整数 ·
 *   reason ≤200 · requestPrice 必须 < standardPrice（否则后端 422「申请价应低于标准价」）。
 * 角色闸在后端（非 SUPER/MANAGER 返 403「仅店长及以上可发起价格审批」），
 * 与本页 requireFeature('approvals') 的 MGMT 口径一致，不额外放开给一线。
 * ========================================================================== */

const createOpen = ref(false)
const submitting = ref(false)
/** 表单错误内联展示：http 层的 toast 一闪就没了，这里留一个不消失的说明位 */
const createError = ref('')

/** 门店候选：与后端 `in:绿地店,东部店` 一致。只有超管选了才生效（店长被后端强制本店） */
const VENUE_OPTIONS = ['绿地店', '东部店']

const blankForm = () => ({
  customerName: '',
  cardName: '',
  standardPrice: '',
  requestPrice: '',
  reason: '',
  venue: ''
})
const form = ref(blankForm())

function openCreate() {
  form.value = blankForm()
  createError.value = ''
  createOpen.value = true
}

function closeCreate() {
  // 提交中不允许关：关了用户会以为没提交，回头重填一遍
  if (submitting.value) return
  createOpen.value = false
}

/** 「元」→ 整数；空串/非数字返回 NaN，交给 validateCreate 报错 */
function toInt(v: string): number {
  const n = Number(String(v ?? '').replace(/[,\s]/g, ''))
  return Number.isFinite(n) ? Math.round(n) : NaN
}

function validateCreate(): string {
  const f = form.value
  const name = f.customerName.trim()
  if (!name) return '请填写客户姓名'
  if (name.length > 20) return '客户姓名最多 20 字'
  const card = f.cardName.trim()
  if (!card) return '请填写卡项名称'
  if (card.length > 40) return '卡项名称最多 40 字'
  const std = toInt(f.standardPrice)
  const req = toInt(f.requestPrice)
  if (!Number.isFinite(std) || std < 0) return '标准价请填整数（元）'
  if (!Number.isFinite(req) || req < 0) return '申请价请填整数（元）'
  if (req >= std) return '申请价应低于标准价'
  if (f.reason.trim().length > 200) return '申请原因最多 200 字'
  return ''
}

async function submitCreate() {
  if (submitting.value) return
  const msg = validateCreate()
  if (msg) {
    createError.value = msg
    uni.showToast({ title: msg, icon: 'none' })
    return
  }
  submitting.value = true
  createError.value = ''
  try {
    const f = form.value
    const payload: CreateApprovalPayload = {
      customerName: f.customerName.trim(),
      cardName: f.cardName.trim(),
      standardPrice: toInt(f.standardPrice),
      requestPrice: toInt(f.requestPrice),
      reason: f.reason.trim() || undefined,
      // 空串必须不发：后端 venue 规则是 nullable|in:绿地店,东部店，传 '' 会撞 in 校验（422）
      venue: f.venue === '' ? undefined : (f.venue as '绿地店' | '东部店')
    }
    await createApproval(payload)
    createOpen.value = false
    uni.showToast({ title: '已提交审批', icon: 'success' })
    // 新单状态是「待店长初审」→ 切回待处理分组再刷新，用户立刻能看到自己刚发的那单
    group.value = 'pending'
    await load()
  } catch (e) {
    createError.value = e instanceof Error ? e.message : '提交失败，请稍后重试'
  } finally {
    submitting.value = false
  }
}

/** 门店选择（只有超管显示；店长提交时后端会强制本人门店，给 picker 就是假选项） */
function onPickVenue(e: { value: string | number }) {
  form.value.venue = String(e.value)
}

function statusTone(s: string): 'brand' | 'gold' | 'warn' | 'danger' | 'muted' {
  if (s === '已通过' || s === '已关联成交') return 'brand'
  if (s === '已驳回') return 'danger'
  if (s === '待老板终审') return 'gold'
  return 'warn'
}

// 转发：员工页统一回首页（分享自己会撞 requireLogin 的登录墙），标题不带客户信息 —— 见 utils/share.ts
onShareAppMessage(() => shareToHome())
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
      <!-- 分页后「N 单待处理」只是**已加载**部分，必须把已加载/总数一起写明，否则又会变成新的误导 -->
      <text class="hint__count ym-num">{{ pendingTotal }} 单待处理 · 已加载 {{ list.length }}/{{ total }}</text>
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

    <!-- 本页没有待处理但仍未加载完：绝不能说「没有待处理的审批单」——
         后端 id 升序（老单在前），pending 很可能在后几页 -->
    <ym-empty
      v-else-if="loaded && !viewList.length && hasMore"
      icon="evaluation"
      text="本页暂未发现待处理单据"
      :desc="`已加载 ${list.length}/${total} 单，继续加载后面的单据`"
      action-text="继续加载"
      @action="loadMore"
    />

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
          <text class="acard__price-value acard__price-value--main ym-num">¥{{ amount(a.requestPrice) }}</text>
        </view>
        <view class="acard__price-divider" />
        <view class="acard__price-cell">
          <text class="acard__price-label">标准价</text>
          <text class="acard__price-value ym-num">¥{{ amount(a.standardPrice) }}</text>
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

    <!-- 分页尾：明示「共 N 单且已全部加载」，这就是「第 51 单不再静默消失」的可观测保证 -->
    <wd-loadmore v-if="loadingMore" state="loading" loading-text="正在加载更多" />
    <wd-loadmore
      v-else-if="list.length && !hasMore"
      state="finished"
      :finished-text="`已全部加载（共 ${total} 单）`"
    />

    <!-- 发起价格审批（F08）：底部悬浮入口 + 自绘弹层（遮罩 + 卡片），不引 wd-popup 以免版本差异 -->
    <view v-if="!forbidden" class="fab ym-tap" @click="openCreate">
      <wd-icon name="add" size="18px" />
      <text class="fab__text">发起审批</text>
    </view>

    <view v-if="createOpen" class="sheet" @click="closeCreate">
      <!-- 内层 .stop：点表单区域不应该关掉弹层 -->
      <view class="sheet__panel" @click.stop>
        <view class="sheet__head">
          <text class="sheet__title">发起价格审批</text>
          <text class="sheet__sub">申请价必须低于标准价；提交后进入店长初审</text>
        </view>

        <wd-input
          v-model="form.customerName"
          label="客户姓名"
          required
          :maxlength="20"
          placeholder="必填"
          label-width="170rpx"
        />
        <wd-input
          v-model="form.cardName"
          label="卡项名称"
          required
          :maxlength="40"
          placeholder="如 VIP私教50节"
          label-width="170rpx"
        />
        <wd-input
          v-model="form.standardPrice"
          label="标准价(元)"
          required
          type="number"
          placeholder="如 3200"
          label-width="170rpx"
        />
        <wd-input
          v-model="form.requestPrice"
          label="申请价(元)"
          required
          type="number"
          placeholder="如 3000"
          label-width="170rpx"
        />
        <wd-textarea
          v-model="form.reason"
          label="申请原因"
          :maxlength="200"
          placeholder="写清让价背景，利于审批（选填）"
          label-width="170rpx"
        />
        <!-- 门店只有超管可选：店长提交时后端强制本人门店，给了也是假选项 -->
        <wd-picker
          v-if="isSuper"
          label="门店"
          :columns="VENUE_OPTIONS"
          :model-value="form.venue"
          placeholder="默认双店"
          label-width="170rpx"
          @confirm="onPickVenue"
        />

        <view v-if="createError" class="sheet__err">
          <text>{{ createError }}</text>
        </view>

        <view class="sheet__acts">
          <wd-button block plain :disabled="submitting" @click="closeCreate">取消</wd-button>
          <wd-button block type="primary" :loading="submitting" @click="submitCreate">
            提交审批
          </wd-button>
        </view>
      </view>
    </view>
  </view>
</template>

<style lang="scss" scoped>
@import '@/styles/tokens.scss';

.approvals {
  /* 底部悬浮「发起审批」按钮会盖住最后一张卡，留出让位 + 安全区 */
  padding-bottom: calc(200rpx + env(safe-area-inset-bottom));

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

/* ---------- 发起审批：悬浮入口（F08） ---------- */
.fab {
  position: fixed;
  right: 32rpx;
  /* 先给不支持 env() 的机型兜底，再用安全区覆盖（iPhone 底部横条） */
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

/* ---------- 发起审批：底部弹层 ---------- */
.sheet {
  position: fixed;
  inset: 0;
  z-index: 60;
  display: flex;
  align-items: flex-end;
  background: rgba(16, 24, 21, 0.5);
}

.sheet__panel {
  width: 100%;
  /* 表单 5-6 行，小屏要能滚，否则「提交」按钮会被顶出屏幕 */
  max-height: 86vh;
  padding: 32rpx 24rpx calc(24rpx + env(safe-area-inset-bottom));
  overflow-y: auto;
  background: $white;
  border-radius: 28rpx 28rpx 0 0;
}

.sheet__head {
  display: flex;
  flex-direction: column;
  gap: 6rpx;
  padding: 0 8rpx 18rpx;
}

.sheet__title {
  font-size: 32rpx;
  font-weight: 600;
  color: $ink-800;
}

.sheet__sub {
  font-size: 22rpx;
  color: $ink-400;
}

.sheet__err {
  padding: 14rpx 18rpx;
  margin-top: 16rpx;
  font-size: 23rpx;
  line-height: 1.5;
  color: $danger-500;
  background: rgba(192, 69, 59, 0.08);
  border-radius: 12rpx;
}

.sheet__acts {
  display: flex;
  gap: 16rpx;
  margin-top: 26rpx;
}
</style>
