<script setup lang="ts">
/**
 * 工作台首页（tab 1 / dashboard）
 *
 * 布局契约：docs/miniapp-migration/11-工作台四板块重构方案.md §5.1
 *   hero → 2×2 指标卡 → 30 天走势 → 转化漏斗 → 今日待办摘要 → 快捷入口宫格
 *
 * 三个数据源，各自职责不能互相替代：
 *   1. `/today/summary`     今日事实（预约/体验/新客资/待跟进/临期/风险/待审批）
 *   2. `/analytics/trends`  近 30 天按日 × 门店的走势 + 区间汇总（漏斗与折线都取它）
 *   3. `/analytics/summary` 区间无关的经营指标（页面只借用续费已处理率做副行提示）
 * `/today/todo` 与课后分析角标属于「锦上添花」：单独 catch，失败不拖垮首屏。
 *
 * 设计约束：颜色只用 $brand / $ink 令牌与既有组件；数字统一 tabular-nums（ym-num）。
 */
import { computed, ref } from 'vue'
import { onPullDownRefresh, onShow } from '@dcloudio/uni-app'
import { useUserStore } from '@/store/user'
import { requireLogin } from '@/utils/guard'
import { goFeature, goPath, isTabPage } from '@/utils/nav'
import {
  hasAnyRole,
  isManagerOrAbove,
  isMediaOnly,
  visibleFeatures,
  type FeatureDef
} from '@/utils/permission'
import { fetchTodaySummary, fetchTodayTodo } from '@/api/today'
import { fetchReviewPendingCount } from '@/api/review'
import {
  fetchAnalyticsSummary,
  fetchTrends,
  type AnalyticsSummary,
  type TrendDay,
  type TrendsResult
} from '@/api/analytics'

const user = useUserStore()

/* ==========================================================================
 * 状态
 * ========================================================================== */

const loading = ref(true)
const errorText = ref('')
/** /today/summary 原样保留（字段多且后端会扩），取值一律走 num()/venueSum() 兜底 */
const todaySummary = ref<Record<string, unknown>>({})
const anaSummary = ref<AnalyticsSummary | null>(null)
const trends = ref<TrendsResult | null>(null)
const todoRaw = ref<unknown>(null)
const pendingReviews = ref(0)

/** 有任意一份主数据就算「有内容」，用于区分首屏骨架与刷新失败 */
const hasData = computed(() => !!trends.value || Object.keys(todaySummary.value).length > 0)

/* ==========================================================================
 * 取值工具（后端字段可能缺失/换形状，页面上所有数字必须经这里）
 * ========================================================================== */

function num(v: unknown): number {
  return typeof v === 'number' && Number.isFinite(v) ? v : 0
}

function text(v: unknown): string {
  return typeof v === 'string' ? v.trim() : ''
}

/** `{绿地店: 3, 东部店: 5}` → 8；万一后端改成直接给数字也能吃 */
function venueSum(v: unknown): number {
  if (typeof v === 'number') return Number.isFinite(v) ? v : 0
  if (v && typeof v === 'object') {
    return Object.values(v as Record<string, unknown>).reduce<number>((sum, x) => sum + num(x), 0)
  }
  return 0
}

/** 百分比文案：整数不带小数点，小数保留一位 */
function pct(v: unknown): string {
  const n = num(v)
  return Number.isInteger(n) ? String(n) : n.toFixed(1)
}

function dateOffset(offsetDays: number): string {
  const d = new Date()
  d.setDate(d.getDate() + offsetDays)
  const m = `${d.getMonth() + 1}`.padStart(2, '0')
  const day = `${d.getDate()}`.padStart(2, '0')
  return `${d.getFullYear()}-${m}-${day}`
}

/* ==========================================================================
 * 顶部 hero
 * ========================================================================== */

const greeting = computed(() => {
  const h = new Date().getHours()
  const word = h < 6 ? '夜深了' : h < 12 ? '早上好' : h < 18 ? '下午好' : '晚上好'
  const name = user.userName || user.staffName
  return name ? `${word}，${name}` : word
})

/** 门店口径（后端 scopeLabel：'双店' 或 '本店 · 绿地店'） */
const heroEyebrow = computed(
  () => text(todaySummary.value.scopeLabel) || user.venue || '一麦瑜伽'
)

/**
 * 「数据截至」：后端 snapshotTime 是各店快照抓取时间的拼接串，可能为空串。
 * 空串就显示「实时」——绝不伪造一个时间点。
 */
const snapshotLabel = computed(() => {
  const raw = text(todaySummary.value.snapshotTime)
  if (!raw) return '实时'
  const times = raw.match(/\d{2}:\d{2}/g)
  if (times && times.length) return Array.from(new Set(times)).join(' / ')
  return raw.length > 20 ? raw.slice(0, 20) : raw
})

const heroSubtitle = computed(() => `${user.roleText || '未分配角色'} · 数据截至 ${snapshotLabel.value}`)

/* ==========================================================================
 * 2×2 指标卡
 * ========================================================================== */

interface StatCard {
  key: string
  label: string
  value: number
  unit: string
  hint: string
  trend: string
  trendTone: 'up' | 'down' | 'flat'
  tone: 'light' | 'brand' | 'gold' | 'plain'
  /** 点击目标：todo / leads / members 都是子页，query 由目标页自行读取 */
  to: string
}

const isMediaAccount = computed(() => isMediaOnly(user.roles))

/**
 * 老师侧口径（服务老师 / 授课老师，且不含 SUPER / MANAGER）。
 *
 * 后端本轮把 /today/summary 与 /analytics/trends 收窄到「本人客资 + 本人排课」，
 * 前端只负责把口径写清楚：否则老师会把门店数字当自己的业绩看（真机上已出现过这个误解）。
 */
const isTeacherSide = computed(
  () =>
    !isMediaAccount.value &&
    !isManagerOrAbove(user.roles) &&
    hasAnyRole(user.roles, ['R_SERVICE', 'R_TEACHER'])
)

const cards = computed<StatCard[]>(() => {
  const s = todaySummary.value
  const bookings = venueSum(s.todayBookings)
  const trials = venueSum(s.trialBookings)
  const newLeads = num(s.newLeads)
  const followup = num(s.pendingFollowup)
  const expiring = num(s.expiringMembers)
  const risk = num(s.riskCount)
  const approvals = num(s.pendingApprovals)
  const snapshotAvailable = s.snapshotAvailable !== false
  const ana = anaSummary.value

  // 今日预约副行：老师侧只说「本人排课」，0 就是真的没排课——
  // 快照缺失的「未同步」提示是门店口径的顾虑（老师侧数据不走门店快照），不能套到老师头上
  let bookingsHint = '今日暂无体验课'
  if (isTeacherSide.value) {
    bookingsHint = bookings > 0 ? '本人今日排课' : '今日暂无排课'
  } else if (!snapshotAvailable) {
    bookingsHint = '今日快照未同步'
  } else if (trials > 0) {
    bookingsHint = `其中体验课 ${trials} 人`
  }

  return [
    {
      key: 'bookings',
      label: '今日预约',
      value: bookings,
      unit: '人',
      hint: bookingsHint,
      trend: !isTeacherSide.value && !snapshotAvailable ? '未同步' : '',
      trendTone: 'flat',
      tone: 'brand',
      to: '/pages/todo/todo?tab=bookings'
    },
    {
      key: 'leads',
      label: '新客资',
      value: newLeads,
      unit: '人',
      hint: risk > 0 ? `另有 ${risk} 项风险待处理` : '暂无风险项',
      trend: '',
      trendTone: 'flat',
      tone: risk > 0 ? 'gold' : 'light',
      to: '/pages/leads/leads?status=新留资'
    },
    {
      key: 'followup',
      label: '待跟进',
      value: followup,
      unit: '人',
      // 新媒体（R_MEDIA）后端把 pendingApprovals 恒置 0，这里不渲染无意义的 0
      hint: isMediaAccount.value
        ? '今日需完成首次响应'
        : approvals > 0
          ? `另有 ${approvals} 单待审批`
          : '暂无待审批',
      trend: '',
      trendTone: 'flat',
      tone: 'light',
      // 待跟进 = 今日待办里的「新客首响」组；无数据也允许进空页，不在这里判空
      to: '/pages/todo/todo?tab=newLeads'
    },
    {
      key: 'expiring',
      label: '卡项临期',
      value: expiring,
      unit: '人',
      hint:
        ana && ana.renewalTasks > 0
          ? `续费已处理 ${pct(ana.renewalRate)}%`
          : '关注到期前 30 天',
      trend: '',
      trendTone: 'flat',
      tone: 'light',
      to: '/pages/members/members?list=待续课'
    }
  ]
})

/**
 * 指标卡跳转（四张卡都可点，不再有「点了没反应」的死卡）。
 *
 * 统一走 goPath()（自动判 switchTab / navigateTo，避免 todo 这类子页被当成 tab 页），
 * 但 nav 层没有 fail 形参 —— 目标页没注册时 navigateTo 会静默失败。这里用页面栈兜底观测：
 * 子页跳转成功栈长度必 +1，500ms 后仍没变化就给一句可读提示，不留死按钮。
 */
function openCard(to: string): void {
  const clean = to.split('?')[0]
  const before = getCurrentPages().length
  try {
    goPath(to)
  } catch {
    uni.showToast({ title: '页面跳转失败，请稍后重试', icon: 'none' })
    return
  }
  if (isTabPage(clean)) return
  setTimeout(() => {
    if (getCurrentPages().length <= before) {
      uni.showToast({ title: '该入口暂未开放，请联系管理员', icon: 'none' })
    }
  }, 500)
}

/* ==========================================================================
 * 30 天走势（wd-segmented 切换序列）
 * ========================================================================== */

/**
 * 三个序列全部取自 daily 的同名指标：
 *   留资 = leads（CRM 留资条数）
 *   到店 = experienced（体验课签到去重人数）
 *   成交 = deals（留资状态机成交条数）
 * 数据只按日 × 门店平铺，聚合必须遍历「非 date 键」求和（见 analytics.ts 的 TrendDay 注释）。
 */
const TREND_TABS = [
  { label: '留资', metric: 'leads', unit: '人' },
  { label: '到店', metric: 'experienced', unit: '人' },
  { label: '成交', metric: 'deals', unit: '单' }
] as const

const trendTab = ref<string>(TREND_TABS[0].label)
const trendLabels: string[] = TREND_TABS.map((t) => t.label)

const activeTrend = computed(() => TREND_TABS.find((t) => t.label === trendTab.value) ?? TREND_TABS[0])

function onTrendChange(payload: { value: string | number }): void {
  trendTab.value = String(payload?.value ?? '')
}

const dailyRows = computed<TrendDay[]>(() => trends.value?.daily ?? [])

/** 门店键下的单指标取值（值可能是对象，也可能是未来新增的标量，一律兜底 0） */
function metricOf(value: unknown, metric: string): number {
  if (!value || typeof value !== 'object') return 0
  return num((value as Record<string, unknown>)[metric])
}

function sumDay(day: TrendDay, metric: string): number {
  let total = 0
  for (const [key, value] of Object.entries(day)) {
    if (key === 'date') continue
    total += metricOf(value, metric)
  }
  return total
}

const daySeries = computed<number[]>(() =>
  dailyRows.value.map((d) => sumDay(d, activeTrend.value.metric))
)

/** MM-DD（后端给 YYYY-MM-DD） */
const dayLabels = computed<string[]>(() => dailyRows.value.map((d) => text(d.date).slice(5)))

/**
 * 30 天走势区块文案随角色口径切换（数据范围由后端收窄，前端只把「这是谁的数字」说清楚）：
 *   老师侧（R_SERVICE / R_TEACHER）→ 我的 30 天经营走势 · 仅统计本人客资与排课
 *   新媒体（R_MEDIA）           → 我记录的线上客资
 *   管理侧（SUPER / MANAGER）    → 门店/双店口径 + 区间活跃会员数
 */
const trendTitle = computed(() => (isTeacherSide.value ? '我的 30 天经营走势' : '30 天经营走势'))

const trendDesc = computed(() => {
  if (isTeacherSide.value) return '仅统计本人客资与排课'
  if (isMediaAccount.value) return '我记录的线上客资'
  const t = trends.value
  return t ? `近 30 天 · 活跃会员 ${num(t.activeCustomers)} 人` : '近 30 天'
})

const trendEmptyText = computed(() =>
  isTeacherSide.value ? '本人近 30 天暂无排课与客资数据' : '近 30 天暂无数据'
)

/* ==========================================================================
 * 转化漏斗（留资 → 到店 → 成交，纯 CSS 横条）
 * ========================================================================== */

interface FunnelRow {
  key: string
  label: string
  value: number
  width: number
  rateLabel: string
  tone: 'brand' | 'mid' | 'gold'
}

const funnel = computed(() => {
  const s = trends.value?.summary
  const leads = num(s?.leadCount)
  const visits = num(s?.visitCount)
  const deals = num(s?.dealCount)
  // 分母取三者最大值：到店人数含「只约课没留资」的人，可能大于留资数，按留资当 100% 会溢出
  const base = Math.max(leads, visits, deals, 1)
  const width = (v: number): number => (v > 0 ? Math.max(4, Math.round((v / base) * 100)) : 0)

  const rows: FunnelRow[] = [
    { key: 'leads', label: '留资', value: leads, width: width(leads), rateLabel: '漏斗起点', tone: 'brand' },
    {
      key: 'visits',
      label: '到店',
      value: visits,
      width: width(visits),
      rateLabel: `留资 → 到店 ${pct(s?.leadToVisitRate)}%`,
      tone: 'mid'
    },
    {
      key: 'deals',
      label: '成交',
      value: deals,
      width: width(deals),
      rateLabel: `到店 → 成交 ${pct(s?.dealRate)}%`,
      tone: 'gold'
    }
  ]

  return {
    empty: leads + visits + deals === 0,
    rows,
    // 留资为 0 时整体转化率没有分母，显示 — 而不是 0%（有成交却报 0% 会误导）
    overall: leads > 0 ? `${pct((deals / leads) * 100)}%` : '—'
  }
})

/** 漏斗口径说明：老师侧与新媒体的数据都是「我的」，只有管理侧才是门店/双店口径 */
const funnelDesc = computed(() => {
  if (isTeacherSide.value) return '我的留资 → 到店 → 成交'
  if (isMediaAccount.value) return '我记录的线上客资'
  return '近 30 天 · 按人去重'
})

const funnelEmptyText = computed(() =>
  isTeacherSide.value ? '本人近 30 天暂无转化数据' : '近 30 天暂无转化数据'
)

/* ==========================================================================
 * 今日待办摘要
 * ========================================================================== */

interface TodoRow {
  key: string
  type: string
  label: string
  title: string
  hint: string
}

/** `/today/todo` 的分组 → 中文标签（与 TodoController::todoAction 的 typeLabels 同源） */
const TODO_LABELS: Record<string, string> = {
  bookings: '今日预约',
  renewals: '待续费',
  churnRisks: '流失风险',
  birthdays: '生日关怀',
  trials: '体验课',
  reviews: '课后分析',
  newLeads: '新客首响',
  tasks: '今日任务'
}

/** 非分组字段（返回体里的元信息） */
const TODO_META_KEYS = new Set(['date', 'rules', 'counts', 'generatedAt'])

function firstText(row: Record<string, unknown>, keys: string[]): string {
  for (const k of keys) {
    const v = row[k]
    if (typeof v === 'string' && v.trim()) return v.trim()
  }
  return ''
}

/** 时间字段形态不一：预约是 '10:00'，任务截止是 '2026-11-04 18:00'，统一压成 HH:mm */
function normalizeTime(raw: string): string {
  const m = raw.match(/(\d{2}:\d{2})/)
  return m ? m[1] : raw
}

/** 各分组的字段名不统一（预约是 memberName、留资是 name、体验课是 topic），统一收口到一行标题 */
function todoTitle(row: Record<string, unknown>): string {
  const name = firstText(row, ['title', 'memberName', 'member_name', 'name', 'topic', 'course'])
  const rawTime = firstText(row, ['time', 'startTime', 'deadline'])
  const time = rawTime ? normalizeTime(rawTime) : ''
  if (time && name) return `${time} · ${name}`
  return name || time || '待处理事项'
}

function todoHint(row: Record<string, unknown>): string {
  for (const k of ['subtitle', 'mainCard', 'course', 'topic', 'customerName', 'remark']) {
    const v = row[k]
    if (typeof v === 'string' && v.trim()) return v.trim()
  }
  const lists = row.lists
  if (Array.isArray(lists) && lists.length) {
    const joined = lists.filter((x): x is string => typeof x === 'string').join(' / ')
    if (joined) return joined
  }
  const tail = text(row.phoneTail)
  if (tail) return `手机尾号 ${tail}`
  return ''
}

/**
 * 兼容两种返回：
 *   数组形态 `[{type, label, items}]`（旧类型声明）
 *   对象形态 `{bookings:{items}, renewals:[...], counts:{...}}`（后端当前实现）
 * 已处理（done=true）的条目直接丢弃 —— 摘要位只放还要动手的事。
 */
function collectTodoItems(raw: unknown): TodoRow[] {
  const out: TodoRow[] = []

  const push = (type: string, items: unknown): void => {
    if (!Array.isArray(items)) return
    items.forEach((it, index) => {
      if (!it || typeof it !== 'object') return
      const row = it as Record<string, unknown>
      if (row.done === true) return
      out.push({
        key: text(row.key) || `${type}:${index}`,
        type,
        label: TODO_LABELS[type] ?? '待办',
        title: todoTitle(row),
        hint: todoHint(row)
      })
    })
  }

  if (Array.isArray(raw)) {
    for (const group of raw) {
      if (!group || typeof group !== 'object') continue
      const g = group as Record<string, unknown>
      push(text(g.type), g.items)
    }
    return out
  }

  if (raw && typeof raw === 'object') {
    for (const [key, value] of Object.entries(raw as Record<string, unknown>)) {
      if (TODO_META_KEYS.has(key) || !value || typeof value !== 'object') continue
      if (Array.isArray(value)) {
        push(key, value)
        continue
      }
      const inner = (value as Record<string, unknown>).items
      if (Array.isArray(inner)) push(key, inner)
    }
  }

  return out
}

const todoAll = computed<TodoRow[]>(() => collectTodoItems(todoRaw.value))
const todoTop = computed<TodoRow[]>(() => todoAll.value.slice(0, 3))
const todoDesc = computed(() =>
  todoAll.value.length > 0 ? `${todoAll.value.length} 项待处理` : '今日已清空'
)

/* ---------- 经营概览 / 双店对比（与网页端 boss-dashboard 同源接口） ----------
 *
 * 网页端超管工作台有「经营总览 / 双店对比 / 收入结构」，小程序此前只有 4 张今日卡 +
 * 趋势 + 漏斗。这里用**已有接口**（/today/summary、/analytics/summary）补齐，
 * 不新增后端：口径完全一致，不会出现"两个端说的不是一回事"。
 */
const kpiRows = computed(() => {
  const a = anaSummary.value
  if (!a) return []
  const fmt = (v: unknown) => (typeof v === 'number' && Number.isFinite(v) ? v : 0)
  return [
    { label: '会员总数', value: fmt(a.totalMembers), unit: '人' },
    { label: '客户总数', value: fmt(a.totalCustomers), unit: '人' },
    { label: '待续课', value: fmt(a.renewalTasks), unit: '人' },
    { label: '未分配', value: fmt(a.unassigned), unit: '人' },
    { label: '留资分配率', value: fmt(a.assignRate), unit: '%' },
    { label: '客户闭环率', value: fmt(a.closureRate), unit: '%' },
    { label: '续费处理率', value: fmt(a.renewalRate), unit: '%' },
    { label: '任务完成率', value: fmt(a.taskRate), unit: '%' }
  ]
})

const revenueRows = computed(() => {
  const mix = anaSummary.value?.revenueMix
  if (!mix) return []
  return [mix.private, mix.small, mix.group].map((x) => ({
    label: x.label,
    amount: num(x.amount),
    share: Math.max(0, Math.min(100, Math.round(num(x.share))))
  }))
})

/**
 * 双店对比：后端 todayBookings 在超管/双店账号下是 `{绿地店:n, 东部店:n}`，
 * 单店账号是数字（这里直接不渲染 —— 一个数没有"对比"的意义）。
 */
const venueRows = computed(() => {
  const raw = todaySummary.value.todayBookings
  if (!raw || typeof raw !== 'object') return []
  const trials = (todaySummary.value.trialBookings ?? {}) as Record<string, unknown>
  return Object.entries(raw as Record<string, unknown>).map(([venue, count]) => ({
    venue,
    bookings: num(count),
    trials: num(trials?.[venue])
  }))
})

function goTodo(): void {
  goPath('/pages/todo/todo')
}

/* ==========================================================================
 * 快捷入口宫格（权限收敛，最多 8 个）
 * ========================================================================== */

const gridFeatures = computed<FeatureDef[]>(() =>
  visibleFeatures(user.roles)
    .filter((f) => f.area !== 'me')
    .slice(0, 8)
)

/** 只给「课后分析」挂角标：其余入口要么没有计数源，要么计数在子页里更准 */
function badgeOf(key: string): number {
  return key === 'reviews' ? pendingReviews.value : 0
}

function onFeature(key: string): void {
  goFeature(key)
}

/* ==========================================================================
 * 加载
 * ========================================================================== */

/** 请求序号：连续 onShow / 下拉刷新时只认最后一笔，避免旧响应覆盖新数据 */
let requestSeq = 0

async function load(options: { silent?: boolean } = {}): Promise<void> {
  const seq = ++requestSeq
  if (!options.silent) loading.value = true
  errorText.value = ''

  try {
    const [today, trendRes, anaRes, todoRes, reviewRes] = await Promise.all([
      fetchTodaySummary(),
      fetchTrends({ start: dateOffset(-29), end: dateOffset(0) }),
      fetchAnalyticsSummary().catch(() => null),
      fetchTodayTodo().catch(() => null),
      fetchReviewPendingCount().catch(() => null)
    ])
    if (seq !== requestSeq) return

    todaySummary.value = today
    trends.value = trendRes
    anaSummary.value = anaRes
    todoRaw.value = todoRes
    pendingReviews.value =
      typeof reviewRes === 'number' ? reviewRes : num((reviewRes as { count?: number } | null)?.count)
  } catch (e) {
    if (seq !== requestSeq) return
    errorText.value = e instanceof Error ? e.message : '数据加载失败'
  } finally {
    if (seq === requestSeq) loading.value = false
  }
}

function onRetry(): void {
  void load()
}

onShow(() => {
  if (!requireLogin()) {
    loading.value = false
    return
  }
  // 角色可能被后台改过：静默拉一次 /me，权限宫格随 store 响应式重算
  void user.refreshMe()
  void load()
})

onPullDownRefresh(async () => {
  try {
    await load({ silent: true })
  } finally {
    uni.stopPullDownRefresh()
  }
})

/** 品牌首页不在 tabBar 里，只能 reLaunch（switchTab 会报 can not switch to no-tabBar page） */
function goBrandHome(): void {
  uni.reLaunch({ url: '/pages/home/home' })
}
</script>

<template>
  <view class="wb ym-page">
    <ym-hero :eyebrow="heroEyebrow" :title="greeting" :subtitle="heroSubtitle">
      <template #badge>
        <view class="wb__home ym-tap" @click="goBrandHome">
          <text class="wb__home-text">品牌首页</text>
        </view>
      </template>
    </ym-hero>

    <!-- 首屏骨架：几何尺寸与真实卡片对齐，加载完不跳版 -->
    <view v-if="loading && !hasData" class="wb__skeleton">
      <view class="wb__sk-stats">
        <view v-for="i in 4" :key="i" class="wb__sk-card">
          <view class="wb__sk-bar is-sm" />
          <view class="wb__sk-bar is-num" />
          <view class="wb__sk-bar is-xs" />
        </view>
      </view>
      <view class="wb__sk-block" />
      <view class="wb__sk-block is-tall" />
    </view>

    <!-- 首屏就失败：整页错误态 + 重试（不是白屏） -->
    <ym-empty
      v-else-if="errorText && !hasData"
      icon="warning"
      text="工作台数据加载失败"
      :desc="errorText"
      action-text="重新加载"
      @action="onRetry"
    />

    <template v-else>
      <!-- 刷新失败但仍有旧数据：顶部细条提示，不抹掉已有内容 -->
      <view v-if="errorText" class="wb__banner">
        <text class="wb__banner-text ym-ellipsis">{{ errorText }}</text>
        <text class="wb__banner-action" @click="onRetry">重试</text>
      </view>

      <!-- 四张卡全部可点：ym-stat-card 没有 click 事件 → 按压态与点击都挂在页面这层 -->
      <view class="wb__stats">
        <view
          v-for="c in cards"
          :key="c.key"
          class="wb__stat"
          hover-class="wb__stat--hover"
          :hover-stay-time="80"
          @click="openCard(c.to)"
        >
          <ym-stat-card
            :label="c.label"
            :value="c.value"
            :unit="c.unit"
            :hint="c.hint"
            :trend="c.trend"
            :trend-tone="c.trendTone"
            :tone="c.tone"
          >
            <view class="wb__stat-more">
              <text class="wb__stat-more-text">查看</text>
              <wd-icon name="chevron-right" size="11px" />
            </view>
          </ym-stat-card>
        </view>
      </view>

      <ym-section :title="trendTitle" :desc="trendDesc">
        <view class="wb__seg">
          <wd-segmented :value="trendTab" :options="trendLabels" size="small" @change="onTrendChange" />
        </view>
        <ym-chart
          type="line"
          :data="daySeries"
          :labels="dayLabels"
          :height="300"
          :unit="activeTrend.unit"
          :empty-text="trendEmptyText"
        />
      </ym-section>

      <ym-section title="转化漏斗" :desc="funnelDesc">
        <ym-empty v-if="funnel.empty" compact :text="funnelEmptyText" desc="有留资或体验课后自动生成" />
        <template v-else>
          <view v-for="row in funnel.rows" :key="row.key" class="wb__funnel">
            <view class="wb__funnel-head">
              <text class="wb__funnel-label">{{ row.label }}</text>
              <view class="wb__funnel-num">
                <text class="wb__funnel-value ym-num">{{ row.value }}</text>
                <text class="wb__funnel-unit">人</text>
              </view>
            </view>
            <view class="wb__funnel-track">
              <view class="wb__funnel-bar" :class="`is-${row.tone}`" :style="{ width: row.width + '%' }" />
            </view>
            <text class="wb__funnel-rate">{{ row.rateLabel }}</text>
          </view>
          <view class="wb__funnel-total">
            <text class="wb__funnel-total-label">整体转化（留资 → 成交）</text>
            <text class="wb__funnel-total-value ym-num">{{ funnel.overall }}</text>
          </view>
        </template>
      </ym-section>

      <ym-section
        v-if="venueRows.length"
        title="双店对比"
        desc="今日预约 / 体验预约（门店键由后端下发）"
      >
        <view class="vrows">
          <view v-for="v in venueRows" :key="v.venue" class="vrow">
            <text class="vrow__name ym-ellipsis">{{ v.venue }}</text>
            <view class="vrow__stat">
              <text class="vrow__num ym-num">{{ v.bookings }}</text>
              <text class="vrow__unit">预约</text>
            </view>
            <view class="vrow__stat">
              <text class="vrow__num ym-num">{{ v.trials }}</text>
              <text class="vrow__unit">体验</text>
            </view>
          </view>
        </view>
      </ym-section>

      <ym-section v-if="kpiRows.length" title="经营概览" desc="与网页端同一份口径，按可见范围收窄">
        <view class="kpis">
          <view v-for="k in kpiRows" :key="k.label" class="kpis__cell">
            <text class="kpis__value ym-num"
              >{{ k.value }}<text class="kpis__unit">{{ k.unit }}</text></text
            >
            <text class="kpis__label">{{ k.label }}</text>
          </view>
        </view>
        <view v-if="revenueRows.length" class="rev">
          <view class="rev__head">
            <text class="rev__title">收入结构</text>
            <text class="rev__total ym-num">售卡 {{ num(anaSummary?.revenueMix?.cardSales) }} 元</text>
          </view>
          <view v-for="r in revenueRows" :key="r.label" class="rev__row">
            <text class="rev__label">{{ r.label }}</text>
            <view class="rev__bar">
              <view class="rev__fill" :style="{ width: r.share + '%' }" />
            </view>
            <text class="rev__num ym-num">{{ r.share }}%</text>
          </view>
        </view>
      </ym-section>

      <ym-section title="今日待办" :desc="todoDesc" action-text="全部" @action="goTodo">
        <ym-empty v-if="!todoTop.length" compact text="今日待办已清空" desc="没有需要现在处理的事项" />
        <view v-else class="wb__todos">
          <view v-for="row in todoTop" :key="row.key" class="wb__todo ym-tap" @click="goTodo">
            <view class="wb__todo-main">
              <text class="wb__todo-title ym-ellipsis">{{ row.title }}</text>
              <text v-if="row.hint" class="wb__todo-hint ym-ellipsis">{{ row.hint }}</text>
            </view>
            <ym-badge :text="row.label" tone="muted" />
            <wd-icon name="chevron-right" size="14px" />
          </view>
        </view>
      </ym-section>

      <ym-section title="快捷入口" desc="按账号权限显示">
        <view v-if="gridFeatures.length" class="wb__grid">
          <view
            v-for="f in gridFeatures"
            :key="f.key"
            class="wb__grid-item ym-tap"
            @click="onFeature(f.key)"
          >
            <view class="wb__grid-icon">
              <wd-icon :name="f.icon" size="19px" />
              <text v-if="badgeOf(f.key)" class="wb__grid-badge ym-num">{{ badgeOf(f.key) }}</text>
            </view>
            <text class="wb__grid-text">{{ f.title }}</text>
          </view>
        </view>
        <ym-empty v-else compact text="当前账号暂无可用入口" />
      </ym-section>
    </template>
  </view>
</template>

<style lang="scss" scoped>
@import '@/styles/tokens.scss';

/* ---------- 双店对比 ---------- */
.vrows {
  display: flex;
  flex-direction: column;
  gap: 12rpx;
}

.vrow {
  display: flex;
  align-items: center;
  gap: 16rpx;
  padding: 16rpx 18rpx;
  background: $ink-50;
  border-radius: 12rpx;
}

.vrow__name {
  flex: 1;
  min-width: 0;
  font-size: 25rpx;
  color: $ink-800;
}

.vrow__stat {
  display: flex;
  align-items: baseline;
  gap: 4rpx;
  min-width: 110rpx;
  justify-content: flex-end;
}

.vrow__num {
  font-size: 30rpx;
  font-weight: 600;
  color: $brand-600;
}

.vrow__unit {
  font-size: 21rpx;
  color: $ink-400;
}

/* ---------- 经营概览 ---------- */
.kpis {
  display: flex;
  flex-wrap: wrap;
  gap: 12rpx;
}

.kpis__cell {
  width: calc(25% - 9rpx);
  padding: 14rpx 10rpx;
  text-align: center;
  background: $ink-50;
  border-radius: 12rpx;
}

.kpis__value {
  display: block;
  font-size: 30rpx;
  font-weight: 600;
  color: $ink-800;
}

.kpis__unit {
  font-size: 20rpx;
  color: $ink-400;
}

.kpis__label {
  display: block;
  margin-top: 4rpx;
  font-size: 20rpx;
  color: $ink-400;
}

.rev {
  margin-top: 20rpx;
  padding-top: 16rpx;
  border-top: 2rpx solid $hairline;
}

.rev__head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  margin-bottom: 10rpx;
}

.rev__title {
  font-size: 24rpx;
  font-weight: 600;
  color: $ink-600;
}

.rev__total {
  font-size: 21rpx;
  color: $ink-400;
}

.rev__row {
  display: flex;
  align-items: center;
  gap: 12rpx;
  margin-top: 10rpx;
}

.rev__label {
  flex-shrink: 0;
  width: 80rpx;
  font-size: 22rpx;
  color: $ink-500;
}

.rev__bar {
  flex: 1;
  height: 12rpx;
  background: $ink-100;
  border-radius: 999rpx;
  overflow: hidden;
}

.rev__fill {
  height: 100%;
  background: linear-gradient(90deg, $brand-400, $brand-600);
  border-radius: 999rpx;
}

.rev__num {
  flex-shrink: 0;
  width: 66rpx;
  text-align: right;
  font-size: 21rpx;
  color: $ink-500;
}

.wb {
  padding-bottom: calc(40rpx + env(safe-area-inset-bottom));
}

/* ---------- hero 右侧「品牌首页」 ---------- */
.wb__home {
  display: flex;
  flex-shrink: 0;
  align-items: center;
  padding: 8rpx 16rpx;
  margin-top: 10rpx;
  border: 2rpx solid rgba(255, 255, 255, 0.28);
  border-radius: 999rpx;
}

.wb__home-text {
  font-size: 20rpx;
  line-height: 1.4;
  color: $white;
  white-space: nowrap;
}

/* ---------- 刷新失败提示条 ---------- */
.wb__banner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16rpx;
  padding: 16rpx 24rpx;
  margin: 20rpx 24rpx 0;
  font-size: 22rpx;
  color: $danger-600;
  background: $danger-100;
  border-radius: $r-sm;
}

.wb__banner-text {
  flex: 1;
  min-width: 0;
}

.wb__banner-action {
  flex-shrink: 0;
  color: $brand-600;
}

/* ---------- 2×2 指标卡 ---------- */
.wb__stats {
  display: flex;
  flex-wrap: wrap;
  gap: 20rpx;
  padding: 0 24rpx;
  margin-top: 20rpx;
}

.wb__stat {
  width: calc(50% - 10rpx);
  border-radius: $r-md;
  transition: opacity 0.12s ease;

  /* 触摸按压态（WeChat 里 :active 不可靠，统一用 hover-class 触发的类） */
  &.wb__stat--hover {
    opacity: 0.68;
  }
}

/* 卡片底部「查看 ›」：给可点击一个明确暗示，否则看起来是纯展示块 */
.wb__stat-more {
  display: flex;
  gap: 2rpx;
  align-items: center;
  align-self: flex-end;
  padding-top: 10rpx;
  margin-top: auto;
  color: $ink-300;
}

.wb__stat-more-text {
  font-size: 20rpx;
  color: $ink-400;
}

/* ---------- 走势切换 ---------- */
.wb__seg {
  display: flex;
  margin-bottom: 16rpx;
}

/* ---------- 漏斗 ---------- */
.wb__funnel + .wb__funnel {
  margin-top: 22rpx;
}

.wb__funnel-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
}

.wb__funnel-label {
  font-size: 26rpx;
  color: $ink-700;
}

.wb__funnel-num {
  display: flex;
  align-items: baseline;
  gap: 4rpx;
}

.wb__funnel-value {
  font-size: 32rpx;
  font-weight: 600;
  color: $ink-800;
}

.wb__funnel-unit {
  font-size: 20rpx;
  color: $ink-400;
}

.wb__funnel-track {
  height: 20rpx;
  margin-top: 10rpx;
  overflow: hidden;
  background: $ink-50;
  border-radius: 999rpx;
}

.wb__funnel-bar {
  height: 100%;
  border-radius: 999rpx;
  transition: width 0.3s ease;

  &.is-brand {
    background: $brand-500;
  }

  &.is-mid {
    background: $brand-400;
  }

  &.is-gold {
    background: $gold-500;
  }
}

.wb__funnel-rate {
  display: block;
  margin-top: 8rpx;
  font-size: 21rpx;
  color: $ink-400;
}

.wb__funnel-total {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-top: 22rpx;
  margin-top: 24rpx;
  border-top: 2rpx solid $hairline;
}

.wb__funnel-total-label {
  font-size: 24rpx;
  color: $ink-500;
}

.wb__funnel-total-value {
  font-size: 30rpx;
  font-weight: 600;
  color: $brand-600;
}

/* ---------- 待办摘要 ---------- */
.wb__todo {
  display: flex;
  align-items: center;
  gap: 14rpx;
  min-height: 96rpx;
  color: $ink-300;

  & + & {
    border-top: 2rpx solid $hairline;
  }
}

.wb__todo-main {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 4rpx;
  min-width: 0;
}

.wb__todo-title {
  font-size: 27rpx;
  color: $ink-800;
}

.wb__todo-hint {
  font-size: 21rpx;
  color: $ink-400;
}

/* ---------- 快捷入口宫格 ---------- */
.wb__grid {
  display: flex;
  flex-wrap: wrap;
}

.wb__grid-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  width: 25%;
  min-height: 156rpx;
  gap: 10rpx;
}

.wb__grid-icon {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 76rpx;
  height: 76rpx;
  color: $brand-600;
  background: $brand-50;
  border-radius: 24rpx;
}

.wb__grid-badge {
  position: absolute;
  top: -8rpx;
  right: -10rpx;
  min-width: 30rpx;
  padding: 0 8rpx;
  font-size: 18rpx;
  line-height: 30rpx;
  color: $white;
  text-align: center;
  background: $danger-500;
  border-radius: 999rpx;
}

.wb__grid-text {
  max-width: 100%;
  font-size: 23rpx;
  color: $ink-700;
  text-align: center;
}

/* ---------- 骨架 ---------- */
.wb__skeleton {
  padding: 20rpx 24rpx 0;
}

.wb__sk-stats {
  display: flex;
  flex-wrap: wrap;
  gap: 20rpx;
}

.wb__sk-card {
  display: flex;
  flex-direction: column;
  gap: 14rpx;
  width: calc(50% - 10rpx);
  padding: 24rpx;
  background: $white;
  border-radius: $r-md;
}

.wb__sk-bar {
  height: 20rpx;
  background: $ink-50;
  border-radius: 999rpx;

  &.is-sm {
    width: 40%;
    height: 18rpx;
  }

  &.is-num {
    width: 62%;
    height: 44rpx;
    margin: 6rpx 0 2rpx;
  }

  &.is-xs {
    width: 52%;
    height: 18rpx;
  }
}

.wb__sk-block {
  height: 300rpx;
  margin-top: 20rpx;
  background: $white;
  border-radius: $r-lg;

  &.is-tall {
    height: 360rpx;
  }
}
</style>
