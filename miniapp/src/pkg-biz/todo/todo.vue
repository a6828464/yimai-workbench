<script setup lang="ts">
/**
 * 今日待办（八组合并视图）
 *
 * 读：GET /today/todo → {date, rules, bookings:{items,trialCount}, renewals[], churnRisks[],
 *                        birthdays[], trials[], reviews[], newLeads[], tasks[]}
 * 行内点击：进会员 360 / 留资 / 课后分析 / 任务中心（见 openCustomer 的四档分流）
 * 写：POST /today/todo/action（type / key / action / customerId / leadId / touch）
 *     注意 type 白名单不含 tasks —— 任务状态只能走 TaskController::update
 *
 * 视觉层（2026-11 重构）：每组一张 ym-section 卡，行内动作换 wd-button，
 * 三态（骨架 / 空 / 失败）。分组、动作分支、touch 语义全部原样保留。
 */
import { computed, ref } from 'vue'
import { onLoad, onShow, onPullDownRefresh, onShareAppMessage } from '@dcloudio/uni-app'
import { shareToHome } from '@/utils/share'
import { requireLogin } from '@/utils/guard'
// goPath：跳转统一收口（自动判 switchTab / navigateTo），页面里不直接调 uni.navigateTo
import { goPath, requireFeature } from '@/utils/nav'
// canUse：点行进客户 360 之前先按同一份注册表挡一次。
// members 属 OPS_ROLES（不含新媒体），如果让新媒体账号点进去再由 requireFeature 弹回，
// 体验比「不可点」更差；这里提前判，给一句能看懂的说明。
import { canUse } from '@/utils/permission'
import { useUserStore } from '@/store/user'
import { fetchTodayTodo, todoAction } from '@/api/today'

const user = useUserStore()

/** 后端 /today/todo 返回：{date, rules, bookings:{items,trialCount}, renewals[], churnRisks[], birthdays[], trials[], reviews[], newLeads[]} */
type RawTodo = Record<string, unknown>

interface FlatItem {
  type: string
  key: string
  title: string
  subtitle: string
  /**
   * 「这个人为什么在这一组」的事实行（1–2 行，2026-11 馆主反馈新增）。
   *
   * 为什么放在 normalize 里算好，而不是模板里现拼：六组的字段完全不同（预约看 kind/course/
   * time/teacher + attend，待续费看 cardHint，生日看 birthdayText/ageText/isToday…），
   * 模板里写 6 个 v-if 既难维护又容易漏过滤 → 出现 undefined。这里统一压成 string[]，
   * 空串已被过滤，模板只管遍历渲染；数组为空就整行不出现。
   */
  info: string[]
  tags: string[]
  done: boolean
  doneBy?: string
  /**
   * 处理后留下的沟通备注（后端 `doneRemark`）。
   *
   * 同一组待办是**全店共享**的：谁先处理，其他人再打开就看到「已由 嘉欣 处理」。
   * 不带这行备注的话，「她上次到底说了什么」只能点进会员 360 才看得到，
   * 而员工反馈的正是「对这个客户还是不清楚」。
   */
  doneRemark?: string
  /** 后端下发的会员 id：既用于 todoAction 回执，也用于「点行进会员 360」 */
  customerId?: number
  /** 后端下发的留资 id：用于 todoAction 回执 + 点行跳留资列表 */
  leadId?: number
  actions: string[]
}

/**
 * 分组定义 = 后端 /today/todo 返回的 8 个业务键，顺序即产品定义的优先级。
 *
 * ⚠️ 2026-11-06 补 `tasks`：此前只有 7 组，后端 `return ok([... 'tasks' => $tasks])`
 * 与工作台 `TODO_LABELS.tasks = '今日任务'` 都有这一组，唯独本页的 GROUPS 漏了 →
 * 「今日任务」在待办页完全不渲染（数据到了、页面不显示）。属于「数据存在但看不见」的 P1。
 *
 * ⚠️ 2026-11（v3.19.0）馆主砍掉了顶部「全部」分类：原来的 tab 条第一项是「全部」，
 * 语义等于「不分组滚动全部」，与下面的分组卡片完全重复。现在**进入即按本数组顺序
 * 铺开所有有线索的组**（顺序即优先级，不做折叠、不按数量排序）。
 */
const GROUPS: { type: string; label: string }[] = [
  { type: 'bookings', label: '今日预约' },
  { type: 'renewals', label: '待续费' },
  { type: 'churnRisks', label: '流失风险' },
  { type: 'birthdays', label: '生日关怀' },
  { type: 'trials', label: '体验课' },
  { type: 'reviews', label: '课后分析' },
  { type: 'newLeads', label: '新客首响' },
  { type: 'tasks', label: '今日任务' }
]

/**
 * 各组的行内动作（与后端 todoAction 的流转分支对齐）。
 *
 * `tasks` 刻意是空数组：后端 `todoAction` 的 `type` 校验是
 * `in:bookings,renewals,churnRisks,birthdays,trials,reviews,newLeads` —— 不含 tasks，
 * 在这里放任何按钮都会 422。任务的流转只走 TaskController::update 状态机，
 * 所以这一组的正确动作是「点行进任务中心」，而不是在待办页改状态。
 */
const DEFAULT_ACTIONS: Record<string, string[]> = {
  /**
   * 今日预约**不给动作按钮**（v3.19.0，馆主原话：「『已到店』『已联系』这两个按钮没有用，
   * 反而遮蔽了前面显示的信息，可以直接删掉」）。
   *
   * 只删渲染层：后端 `todoAction` 的 bookings/trials 流转语义一个字没动 ——
   * 体验课组的「已接待」仍走它，工作台其它入口也可能在用。
   * 这一组现在只读：课程 · 时间 · 老师 · 近三月出勤 · 卡项余量。
   */
  bookings: [],
  renewals: ['已沟通'],
  churnRisks: ['已沟通'],
  birthdays: ['已祝福'],
  trials: ['已接待', '爽约'],
  reviews: ['已提交'],
  newLeads: ['已首响', '无效客资'],
  tasks: []
}

/** 深链 ?tab=xxx 想选中的组：onLoad 收下，首次 load 成功后消费一次（见 applyInitialTab） */
const pendingTab = ref('')

const groups = ref<{ type: string; label: string; items: FlatItem[] }[]>([])
const loading = ref(false)
const failed = ref(false)
const loaded = ref(false)
const acting = ref('')

/**
 * 分类条（v3.19.0 二次修订）。
 *
 * ## 为什么「去掉『全部』」但「保留分类条」
 * 馆主的原话是「『今日代办』里面有一个『全部』的分类没有意义存在」—— 他要去掉的是那个
 * **内容等于「所有组堆在一起」的 tab**：它和下面的分组卡片完全重复，而且默认选中它时
 * 一眼看下去六组糊成一屏。
 * 但分类条本身是这一页的**导航骨架**：删掉之后「按场景看数据」就没有入口了
 * （馆主后来原话：「现在一个分类都没有，怎么看数据」）。所以：
 *   · 分类项 = GROUPS 原样（顺序即优先级，不折叠、不排序、不加「全部」）；
 *   · 内容区只渲染当前选中的那一组（不再全量铺开）。
 */
const TAB_TYPES = new Set(GROUPS.map((g) => g.type))

/** 当前选中的分类。初值取第一项，真正落哪个由 applyInitialTab 决定 */
const active = ref(GROUPS[0].type)

/**
 * 用户是否已经「定住」了分类。
 * 目的：页面每次 onShow 都会重拉一次（从会员详情返回也会），重拉后不能把用户刚选的
 * 分类重置回默认组。所以只有第一次（或深链进来时）才自动挑，之后一律保留当前选择。
 */
let tabPinned = false

/** 全组未完成总数（页头一句话讲清今天还剩多少） */
const pendingCount = computed(() =>
  groups.value.reduce((n, g) => n + g.items.filter((it) => !it.done).length, 0)
)

function pendingOf(g: { items: FlatItem[] }): number {
  return g.items.filter((it) => !it.done).length
}

/** 组名查表（弹窗标题「待续费 · 张女士」用，省得每处再 find 一遍） */
const GROUP_LABEL: Record<string, string> = Object.fromEntries(GROUPS.map((g) => [g.type, g.label]))

/** 当前组（内容区只渲染这一组；groups 恒为 8 项，所以正常一定有值） */
const currentGroup = computed(() => groups.value.find((g) => g.type === active.value) ?? null)

/** 当前分类名（页头 / 空态文案用） */
const activeLabel = computed(() => GROUP_LABEL[active.value] ?? '')

/** 有线索的组数（页头角标；空组也占一个 tab，但不参与这个数字） */
const groupsWithItems = computed(() => groups.value.filter((g) => g.items.length > 0).length)

/** 全组条目数（判断「今天是不是真的清零」用） */
const totalItems = computed(() => groups.value.reduce((n, g) => n + g.items.length, 0))

/**
 * tab 角标：该组未完成数，与组内 `pendingOf` **同一个口径**
 * （`done` 的条目算已完成、不计入）—— 两处各写一遍必然出现「tab 上 3、进去只有 2」。
 */
function tabCount(type: string): number {
  const g = groups.value.find((x) => x.type === type)
  return g ? pendingOf(g) : 0
}

function selectTab(type: string): void {
  if (!TAB_TYPES.has(type)) return
  active.value = type
  tabPinned = true
}

/**
 * 决定进页面看哪一组（每次 load 成功后调一次）：
 *  1. 深链 `?tab=xxx` 命中 → 直接选它（工作台指标卡/待办摘要会带这个参数进来）。
 *     未知值/空值不报错，走第 2 步 —— 决不能让传错一个 type 变成整页空白。
 *  2. 用户已经手动切过（或已经自动选过）→ 保留当前选择，不因为刷新把人踢回默认组。
 *  3. 否则选**第一个有线索的组**（items 非空）：不固定「今日预约」，
 *     免得进页面就是一个空组还得自己找数据。
 *  4. 全部为空 → 落 GROUPS[0]（今日预约），由模板显示正常空态（不是白屏）。
 */
function applyInitialTab(): void {
  const wanted = pendingTab.value
  pendingTab.value = ''
  if (wanted && TAB_TYPES.has(wanted)) {
    active.value = wanted
    tabPinned = true
    return
  }
  if (tabPinned) return
  const first = groups.value.find((g) => g.items.length > 0)
  active.value = first?.type ?? GROUPS[0].type
  // 自动选一次之后就定住：后续下拉刷新/从详情返回不再跳分类
  tabPinned = true
}

/**
 * 行内三件套的取值（八组字段并不统一，尤其 tasks 组）。
 *
 * tasks 组的元素是 `camel($task) + overdue`：title 是**任务标题**、customerName 是会员名。
 * 若沿用其他组的 `name ?? customerName ?? title` 顺序，今日任务会显示成会员名，
 * 与工作台摘要的口径对不上。副标题也用「截止 · 负责人」，而不是 time/course 那串。
 */
function titleOf(it: Record<string, unknown>, type: string): string {
  if (type === 'tasks') return String(it.title ?? it.customerName ?? '—')
  return String(it.name ?? it.customerName ?? it.title ?? it.memberName ?? '—')
}

function subtitleOf(it: Record<string, unknown>, type: string): string {
  if (type === 'tasks') {
    return [it.deadline, it.owner].filter((x) => typeof x === 'string' && x !== '').join(' · ')
  }
  return String(it.time ?? it.course ?? it.cardName ?? it.source ?? it.note ?? '')
}

function tagsOf(it: Record<string, unknown>, type: string): string[] {
  // tasks 组后端只给一个可直接展示的事实：overdue（今天到期或已逾期）
  if (type === 'tasks') return it.overdue === true ? ['已逾期'] : []
  return Array.isArray(it.flags) ? (it.flags as string[]) : Array.isArray(it.tags) ? (it.tags as string[]) : []
}

/* ==========================================================================
 * 信息行：把「这个人为什么出现在这一组」压成 1–2 行事实（v3.19.0）
 *
 * 起因（馆主真机反馈）：六组卡片只有姓名和时间，看不出判读依据 ——
 * 「待续费」不写剩几节、为什么该续；「流失风险」不写多久没来；「生日」不写几岁；
 * 「体验课」不写几点、什么主题、哪位教练。员工只能点进会员 360 才能判断，
 * 而这条路径一天要走几十次。
 *
 * 三条硬约束：
 *  ① 缺字段就整段不显示：宁可少一行，也绝不在界面上出现 undefined / null / NaN；
 *  ② 只读后端（t1 契约）下发的字段，前端不自己算口径（天数、剩余次数、是否今天都以后端为准）；
 *  ③ 字段名驼峰优先、下划线兜底 —— 后端 camel() 是主流，但历史接口两种都出现过，
 *     读错一个名字的代价是「整行消失」，所以 pick() 一次给两个候选。
 * ==========================================================================
 */

/**
 * 可展示文本：null / undefined / 空串，以及字符串形态的 'undefined' / 'null' / 'NaN' 一律当「没有」。
 *
 * 为什么要拦字符串形态：历史数据在 json_encode 之后真的出现过 `"null"` 这种字面值，
 * 直接拼进模板会渲染成「私教小班 · null」，比少一行难看得多。
 */
function txt(v: unknown): string {
  if (v === null || v === undefined) return ''
  const s = String(v).trim()
  if (!s) return ''
  const low = s.toLowerCase()
  if (low === 'undefined' || low === 'null' || low === 'nan') return ''
  return s
}

/** 数值兜底：非有限数当「没有」，防止 NaN 渲染到页面上 */
function num(v: unknown): number | null {
  if (v === null || v === undefined || v === '') return null
  const n = Number(v)
  return Number.isFinite(n) ? n : null
}

/** 按顺序取第一个「有值」的字段（驼峰 / 下划线两种命名都试） */
function pick(it: Record<string, unknown>, ...names: string[]): unknown {
  for (const name of names) {
    const v = it[name]
    if (v !== null && v !== undefined && v !== '') return v
  }
  return undefined
}

/** `2025-09-22` → `9-22`：卡片行里不出现年份，与后端 whyText 的写法保持一致 */
function monthDay(v: unknown): string {
  const s = txt(v)
  const m = /^(\d{4})-(\d{1,2})-(\d{1,2})/.exec(s)
  return m ? `${Number(m[2])}-${m[3]}` : s
}

/**
 * 卡项短句：`私教小班 剩 3 节`（流失组传 prefix='卡：' 时是 `卡：私教小班 剩 3 节`）。
 * 只有卡名、没有余量时只说卡名 —— 不硬凑一个「剩」字，避免「剩 undefined 节」。
 */
function cardShort(it: Record<string, unknown>, prefix: string): string {
  // 卡名：renewals 下发 cardName（TodayController L913），bookings / churnRisks 下发 mainCard
  // （bookings 由 L722 的 $memberCardBrief 闭包产出、L830 展开；churnRisks 见 L962）。
  // 后端已把 '—' / '待同步卡项' 归一成空串，所以这里只需判空。
  const name = txt(pick(it, 'cardName', 'mainCard'))
  if (!name) return ''
  // 余量：三组统一是 remainTimes（bookings L830 展开 / renewals L890 / churnRisks L963，
  // 值即 customers.remain_times，主卡口径）。**没有** cardRemain 这个键 —— 早先按它读，
  // 因为 whyText / cardHint 恒存在、兜底分支从不执行，所以一直没暴露。
  const remain = num(pick(it, 'remainTimes'))
  // 单位：这三组都不下发 unit（服务端在自己的 whyText/cardHint 里把单位写死成「节」，
  // 见 L720 的 $cardQty 默认参数），所以前端也用「节」，不读不存在的 cardUnit/unit。
  return remain === null ? `${prefix}${name}` : `${prefix}${name} 剩 ${remain} 节`
}

/** 今日预约：`私教 · 哈他瑜伽 · 14:00 · 李老师`，第二行补出勤与卡项余额 */
function bookingsInfo(it: Record<string, unknown>): string[] {
  const main = [
    txt(pick(it, 'kind', 'courseKind', 'course_kind')),
    txt(pick(it, 'course', 'courseName', 'course_name')),
    txt(it.time),
    txt(it.teacher)
  ]
    .filter(Boolean)
    .join(' · ')

  // 出勤：后端 attend{m1,m2,m3}，左→右由远到近，与会员详情「近三档出勤」同一口径。
  // 三个数齐了才显示 —— 只给一两个月反而会被读成「这三个月几乎没来」。
  //
  // attendKnown（后端 v3.19.0 新增）必须判：预约手机号没匹配到会员档案时，后端照样给
  // 三个 0 但置 attendKnown=false。直接把 0/0/0 渲染出来就是**把「查不到人」误报成
  // 「近三个月没来」**——这两件事要采取的动作完全相反（前者去建档，后者去关怀）。
  // 出勤对象：bookings[] 的键是 attend（TodayController L824），没有 attendance 这个别名
  const attend = it.attend as Record<string, unknown> | undefined
  const m1 = num(attend?.m1)
  const m2 = num(attend?.m2)
  const m3 = num(attend?.m3)
  const attendKnown = it.attendKnown === false ? false : true
  const attendText = !attendKnown
    ? '无会员档案'
    : m1 !== null && m2 !== null && m3 !== null
      ? `近3个月出勤 ${m1}/${m2}/${m3}`
      : ''

  const secondary = [attendText, cardShort(it, '')].filter(Boolean).join(' · ')
  return [main, secondary].filter(Boolean)
}

/**
 * 待续费：`私教小班 · 剩 3 节 · 7 天后到期`。
 *
 * 优先用后端现成句子（cardHint / whyText）——「为什么待续费」的判定口径在 CardLifecycle，
 * 前端不重算；只有后端没给句子时才用卡名字段拼，拼不全就整行不显示。
 */
function renewalsInfo(it: Record<string, unknown>): string[] {
  // cardHint / whyText 都是后端真实键（TodayController L914 / L915），有就直接用
  const sentence = txt(pick(it, 'cardHint', 'whyText'))
  if (sentence) return [sentence]

  // ── 兜底拼装：只在后端没给句子时走（现在恒给，所以这段是保险，不是主路径）──
  // 每个键都按后端实际下发名读，注释标了来源行；**故意不写别名**——
  // 读一个后端永不产出的键（早先这里读 daysUntil），等于兜底永远静默失效。
  const remain = num(pick(it, 'remainTimes'))
  const days = num(pick(it, 'expireDays')) // renewals[] 的到期天数键 = expireDays（TodayController L892）
  const parts = [
    txt(pick(it, 'cardName')), // L913
    remain !== null ? `剩 ${remain} 节` : '',
    days !== null ? `${days} 天后到期` : ''
  ].filter(Boolean)
  return parts.length ? [parts.join(' · ')] : []
}

/**
 * 流失风险：`62 天没到店（上次 9-22）· 卡：私教小班 剩 3 节`。
 *
 * 同样优先用后端 whyText（TodayController L967，它已经把「多久没来 + 上次哪天 + 卡项」写成一个句子）；
 * 缺句子时用 lastVisitDays / lastVisit / 卡项三个事实兜底拼，缺哪个就少哪段。
 */
function churnInfo(it: Record<string, unknown>): string[] {
  const sentence = txt(pick(it, 'whyText'))
  if (sentence) return [sentence]

  // 未到店天数：churnRisks[] 的键是 lastVisitDays（TodayController L954）。
  // 早先读的 noVisitDays 后端从不下发 —— whyText 恒存在所以兜底分支没被执行过，
  // 属于「后端哪天不写 whyText 就整行走空」的静默失效，故删掉别名、只读真名。
  const days = num(pick(it, 'lastVisitDays'))
  const last = monthDay(pick(it, 'lastVisit')) // L953
  const head =
    days === null
      ? ''
      : `${days} 天没到店${last ? `（上次 ${last}）` : ''}`
  const line = [head, cardShort(it, '卡：')].filter(Boolean).join(' · ')
  return line ? [line] : []
}

/**
 * 生日关怀：`10-08 生日 · 今天满 29 岁`；不是今天则 `10-08 生日 · 还有 3 天 · 29 岁`。
 *
 * 「是不是今天」由后端 isToday 判（时区与当天口径都在后端），前端只决定用哪种说法，
 * 不拿本地时间自己比 —— 免得跨零点时前后端各说一套。
 */
function birthdaysInfo(it: Record<string, unknown>): string[] {
  // 后端 birthdayText 是 `MM-DD`（substr($birthday, 5, 5)），不带「生日」二字；
  // 只在确实长得像 MM-DD 时补后缀，避免把后端将来改成的整句再叠一次。
  const rawBday = txt(pick(it, 'birthdayText', 'birthday_text'))
  const bday = /^\d{1,2}-\d{1,2}$/.test(rawBday) ? `${rawBday} 生日` : rawBday
  const age = txt(pick(it, 'ageText', 'age_text'))
  // 天数：生日组的键是 daysLater（TodayController L1002）。
  // 注意它既不是 renewals 的 expireDays、也不是卡项的 daysUntil —— 三组三个名字，
  // 所以这里只读 daysLater，不写别名（写了也永远取不到）。
  const days = num(pick(it, 'daysLater'))
  const isToday = it.isToday === true || days === 0

  const tail = isToday
    ? age
      ? `今天满 ${age}`
      : '今天'
    : [days !== null ? `还有 ${days} 天` : '', age].filter(Boolean).join(' · ')

  const line = [bday, tail].filter(Boolean).join(' · ')
  return line ? [line] : []
}

/** 体验课：`15:00 · 私教体验 · 主题：肩颈修复 · 王教练` */
function trialsInfo(it: Record<string, unknown>): string[] {
  const topic = txt(pick(it, 'course', 'courseName', 'course_name', 'topic', 'subject'))
  const line = [
    txt(it.time),
    txt(pick(it, 'kind', 'trialType', 'trial_type')),
    topic ? `主题：${topic}` : '',
    txt(it.teacher)
  ]
    .filter(Boolean)
    .join(' · ')
  return line ? [line] : []
}

/**
 * 课后分析 / 新客首响：后端 whyText 一行说明为什么该处理。
 * 后端没给 whyText 时才退回旧副标题（source / note 这类真实字段），仍然不会出现空串。
 */
function whyInfo(it: Record<string, unknown>, fallback: string): string[] {
  const why = txt(pick(it, 'whyText', 'why_text', 'cardHint', 'card_hint'))
  if (why) return [why]
  return fallback ? [fallback] : []
}

/** 六组 → 信息行；tasks 组沿用旧副标题（「截止 · 负责人」），因为它没有 t1 契约里的新字段 */
function infoOf(it: Record<string, unknown>, type: string, fallback: string): string[] {
  switch (type) {
    case 'bookings':
      return bookingsInfo(it)
    case 'renewals':
      return renewalsInfo(it)
    case 'churnRisks':
      return churnInfo(it)
    case 'birthdays':
      return birthdaysInfo(it)
    case 'trials':
      return trialsInfo(it)
    case 'reviews':
    case 'newLeads':
      return whyInfo(it, fallback)
    default:
      return fallback ? [fallback] : []
  }
}

/**
 * 从 key 前缀解析「这条待办指向谁」。
 *
 * 为什么不能只读字段：后端 TodayController::todo 的八组字段并不统一，`id` 在不同组
 * 甚至是不同实体 ——
 *   bookings   有 customerId（未匹配到会员档案时为 null），id 是预约 id
 *   renewals   只有 id，**就是会员 id**（key = renewal:{id}）
 *   churnRisks 只有 id，会员 id（key = churn:{id}）
 *   birthdays  只有 id，会员 id（key = birthday:{id}）
 *   newLeads   只有 id，**是留资 id**（key = lead:{id}）
 *   trials     留资卡带 leadId（key = trial:lead-{leadId}-{session}）；KY 预约两者都没有
 *   reviews    只有 bookingId，推不出会员
 *   tasks      只有任务 id（key 由 Task 行自带），既不是会员也不是留资
 * 所以「统一把 it.id 当会员 id」会把留资 id 当会员 id 用，跳过去 404。
 * 用 key 前缀判类型最可靠：key 是后端按类型拼的，本身就带语义，且不受字段改名影响。
 */
function jumpFromKey(key: string): { customerId?: number; leadId?: number } {
  const member = /^(?:renewal|churn|birthday):(\d+)$/.exec(key)
  if (member) return { customerId: Number(member[1]) }
  const lead = /^lead:(\d+)$/.exec(key)
  if (lead) return { leadId: Number(lead[1]) }
  const trialLead = /^trial:lead-(\d+)-\d+$/.exec(key)
  if (trialLead) return { leadId: Number(trialLead[1]) }
  return {}
}

function normalize(raw: RawTodo) {
  return GROUPS.map(({ type, label }) => {
    const section = raw[type]
    const arr = Array.isArray(section)
      ? section
      : Array.isArray((section as { items?: unknown[] })?.items)
        ? ((section as { items: unknown[] }).items)
        : []
    const items: FlatItem[] = (arr as Record<string, unknown>[]).map((it) => {
      const key = String(it.key ?? it.id ?? '')
      const fromKey = jumpFromKey(key)
      // 后端显式给的优先，缺失时按 key 前缀兜底（renewals/churnRisks/birthdays 的 id 即会员 id）
      const explicitCustomer = typeof it.customerId === 'number' ? it.customerId : undefined
      const explicitLead = typeof it.leadId === 'number' ? it.leadId : undefined
      const subtitle = subtitleOf(it, type)
      return {
        type,
        key,
        title: titleOf(it, type),
        subtitle,
        // 信息行统一在这里算好（空串已过滤），模板不承担字段兼容逻辑
        info: infoOf(it, type, subtitle),
        tags: tagsOf(it, type),
        done: Boolean(it.done),
        doneBy: it.doneBy ? String(it.doneBy) : undefined,
        doneRemark: it.doneRemark ? String(it.doneRemark) : undefined,
        customerId: explicitCustomer ?? fromKey.customerId,
        leadId: explicitLead ?? fromKey.leadId,
        actions: DEFAULT_ACTIONS[type] ?? ['已处理']
      }
    })
    return { type, label, items }
  })
  // 注意：**不再 filter 掉空组**。分类条要恒有 8 项（空组也要能点、也要能显示计数 0 与空态），
  // 过滤掉就等于「今天这组没线索 → 连分类都消失」，与「按场景看」相悖。
}

/**
 * 请求序号：本页的 load() 有三个触发源——onShow、下拉刷新、以及动作提交成功后的立刻重拉。
 * 三者可以并发（点完「已沟通」马上又下拉刷新），旧响应回来会把新结果盖掉。
 * 与 tasks.vue / review.vue 同一套处理：只认最后一笔，过期响应直接丢弃。
 */
let loadSeq = 0

async function load() {
  const my = ++loadSeq
  loading.value = true
  try {
    const raw = (await fetchTodayTodo()) as unknown as RawTodo
    // 过期响应不改状态：否则「提交动作 → 刷新」与「下拉刷新」交叉时会出现
    // 「刚标记完已沟通，列表又变回未处理」
    if (my !== loadSeq) return
    groups.value = normalize(raw ?? {})
    loaded.value = true
    failed.value = false
    // 数据到位后再决定看哪一组（深链优先 → 否则第一个有线索的组）
    applyInitialTab()
  } catch {
    if (my !== loadSeq) return
    /* http 层已 toast */
    failed.value = true
  } finally {
    if (my === loadSeq) loading.value = false
  }
}

/**
 * 标记弹窗的快捷短语：**按场景（分组）+ 动作**给，不再共用一套。
 *
 * 起因（2026-11 馆主原话）：「标记会员这边有一个弹窗，里面是『这次沟通说了什么』。
 * 可以给上面的不同场景分类，都放两个简单的快捷短语进去」。
 * 之前六条全是续费语气（「在考虑，月底答复」），点到生日祝福上完全不搭；
 * 标题也统一叫「这次沟通说了什么」，看不出自己在标记哪一组。
 *
 * 三条设计口径：
 *  ① 一张表管到底：短语、是否弹窗、标题、placeholder 全从这里推，模板里不写条件分支；
 *  ② 「有短语 = 需要留痕」：表里给了这一组这个动作的短语，就点开面板；没给就一键闭环
 *     （已提交 / 已首响 / 无效客资 这类高频动作不该多一次点击）；
 *  ③ 短语只描述**这一组最常见的结果**，文案不预设结论，也不跨场景借用。
 *
 * 新增组或动作时只改这张表；`已到店 / 已联系`（今日预约）已按馆主要求整组去掉，见
 * DEFAULT_ACTIONS.bookings。
 */
const SCENE_PRESETS: Record<string, Record<string, string[]>> = {
  // 待续费：只剩「约到店」和「还在考虑」两种真实结局
  renewals: {
    已沟通: ['已电话沟通，本周到店', '在考虑，月底答复']
  },
  // 流失风险：召回成功 / 召回无回音 / 明确不练了，三种都要能被一句话说清
  churnRisks: {
    已沟通: ['已微信召回，等她回', '已电话，说忙完这阵来', '暂时不练了，先放着']
  },
  // 生日关怀：门店真实就这四种送法，前两条最常用所以排前面
  birthdays: {
    已祝福: ['已微信祝福', '已电联祝福', '已准备礼物', '已当面祝福']
  },
  // 体验课：接待结果只有「有戏 / 没戏」，爽约则记一句改约
  trials: {
    已接待: ['已接待，体验不错', '已接待，暂不报名'],
    爽约: ['已联系改约']
  }
}

/** 取某组某动作的快捷短语；没有就返回空数组（= 该动作一键闭环、不弹面板） */
function presetsOf(type: string, action: string): string[] {
  return SCENE_PRESETS[type]?.[action] ?? []
}

/** 是否需要先写一句留痕：有场景短语就需要（口径见 SCENE_PRESETS 注释②） */
function needsNote(type: string, action: string): boolean {
  return presetsOf(type, action).length > 0
}

const noteOpen = ref(false)
const noteText = ref('')
const noteItem = ref<FlatItem | null>(null)
const noteAction = ref('')

/** 短语跟着「组 + 动作」走（体验课的「已接待」与「爽约」短语不同） */
const notePresets = computed(() => {
  const it = noteItem.value
  return it ? presetsOf(it.type, noteAction.value) : []
})

/** 标题带场景：`待续费 · 张女士`（馆主要求，不再统一叫「这次沟通说了什么」） */
const noteTitle = computed(() => {
  const it = noteItem.value
  if (!it) return ''
  return [GROUP_LABEL[it.type] ?? '', it.title].filter(Boolean).join(' · ')
})

/**
 * 副标题用这条待办自己的「为什么在这里」（info 第一行：剩几节 / 多久没来 / 几点上课）。
 * 写备注时最需要的正是这层上下文，而不是再重复一遍名字。
 */
const noteSubtitle = computed(() => {
  const it = noteItem.value
  if (!it) return ''
  return it.info[0] || noteAction.value
})

/** 输入框引导语也按场景给：生日问「怎么送的」，体验课问「上得怎么样」 */
const notePlaceholder = computed(() => {
  const type = noteItem.value?.type ?? ''
  if (type === 'birthdays') return '也可以补一句：送的什么、她回了什么、要不要下周再问候…'
  if (type === 'trials') return '也可以补一句：上了什么课、学员反馈、下次要不要跟进…'
  if (type === 'churnRisks') return '也可以补一句：为什么走、有没有可能回来、什么时候再问…'
  return '也可以自己写：她的顾虑、约好的下一步、下次跟进时间…'
})

const noteTips = computed(() =>
  noteItem.value?.type === 'birthdays'
    ? '会写进这个会员的操作留痕 —— 明年这个时候能查到今年是怎么送的。'
    : '会写进这个会员的操作留痕，换老师接手也看得到。'
)

/**
 * 动作入口：需要留痕的动作先开面板（绝不再先跳会员详情），其余动作保持「一键闭环」。
 * 「需不需要留痕」由 SCENE_PRESETS 决定（见 needsNote），不再维护第二份动作白名单。
 */
function onAction(it: FlatItem, action: string): void {
  if (!needsNote(it.type, action)) {
    void submit(it, action)

    return
  }
  noteItem.value = it
  noteAction.value = action
  noteText.value = ''
  noteOpen.value = true
}

/** 点快捷短语：填入 / 再点取消（同一句不重复堆叠） */
function togglePreset(text: string): void {
  if (noteText.value.includes(text)) {
    noteText.value = noteText.value.replace(text, '').replace(/^；|；$/g, '').trim()

    return
  }
  noteText.value = noteText.value ? `${noteText.value}；${text}` : text
}

/**
 * 备注面板的两个出口：
 *  - `withRemark = true`  → 保存内容并标记（remark 会写进会员档案的操作留痕）
 *  - `withRemark = false` → 只标记，不写内容（等同旧行为，但不会静默丢字）
 *
 * 无论走哪条都先关面板：小程序里 popup 盖着调接口，成功后一行 toast 会被遮住。
 * 提交成功后**只刷新当前页**（submit 里的 load()），不再顺带跳会员详情 ——
 * 「点个按钮却被带到别的页面」正是这次要修的那个 bug，出口行为必须可预期。
 */
function confirmNote(withRemark: boolean): void {
  const it = noteItem.value
  const action = noteAction.value
  const text = withRemark ? noteText.value.trim() : ''
  noteOpen.value = false
  if (!it) return
  void submit(it, action, text)
}

async function submit(item: FlatItem, action: string, remark = '') {
  const opId = `${item.type}:${item.key}`
  if (acting.value === opId) return
  acting.value = opId
  try {
    await todoAction({
      type: item.type,
      key: item.key,
      action,
      // 空字符串不发：后端 `nullable` 收到 '' 也会落一条空备注，
      // 而空备注在会员留痕里是纯噪音（后端只在非空时写会员留痕）。
      remark: remark !== '' ? remark : undefined,
      customerId: item.customerId,
      leadId: item.leadId,
      touch: action === '已沟通' ? true : undefined
    })
    uni.showToast({ title: '已记录', icon: 'success' })
    // 成功后只重拉当前列表（原位刷新），**不** goPath 进会员详情：
    // 动作按钮是一键闭环，写完就该停在待办页继续处理下一条。
    await load()
  } catch {
    /* http 层已 toast */
  } finally {
    acting.value = ''
  }
}

/**
 * 点待办行进「客户 360」（本次修复的主链路）。
 *
 * 为什么需要它：normalize() 早就把 customerId / leadId 解析出来了，但此前只当
 * todoAction 的入参用，行本身不可点。店长看到「待续费 · 张三」想确认
 * 「她还剩几节课、最近来过没」，只能退出去走「客户经营 → 会员管理 → 搜名字」，
 * 3 步变 6 步，而这是每天点几十次的路径。
 *
 * 分五档，尽量进到「人」，进不到就退到「能找到这个人的那一页」，绝不留死点击：
 *  ① customerId  → /pkg-crm/members/detail（会员 360：卡项/出勤/续费评估/留痕都在那儿）
 *  ② 只有 leadId → 留资列表。**不能直达单条**：后端没有 GET /leads/{id}，
 *     leads 页 onLoad 也只认 status / venue（不认 focus），所以只能落到列表让人搜；
 *     等后端补单条详情接口后再改深链（档案 §5 已把它列为 P2 待办）。
 *  ③ reviews 组（只有 bookingId）→ 课后分析列表：这批待办本来就是「该补一条课后分析」，
 *     落点就是那个功能的列表页，进去点「新建」时的候选里正是今天上过课的人。
 *  ④ tasks 组（只有任务 id）→ 任务中心：任务是独立实体，且待办页不能改它的状态
 *     （后端 todoAction 的 type 白名单不含 tasks），状态机只在 TaskController。
 *  ⑤ trials 组的 KY 预约（只有手机号）→ 会员管理列表，按姓名/手机号搜。
 *  ⑥ 什么都没有 → 明确告知，不留「点了没反应」的死点击。
 *
 * 权限先判：members / reviews / tasks 都是 OPS_ROLES，新媒体账号进去会被 requireFeature
 * toast + navigateBack，那种「点了又被弹回来」比不可点更糟，所以这里提前挡并给说明。
 * （实测不会误伤：后端对新媒体根本不产出 trials / reviews / renewals / tasks 等组）
 */
function openCustomer(item: FlatItem): void {
  const roles = user.roles

  if (item.customerId) {
    if (!canUse(roles, 'members')) {
      uni.showToast({ title: '当前账号没有会员档案权限', icon: 'none' })
      return
    }
    goPath(`/pkg-crm/members/detail?id=${item.customerId}`)
    return
  }

  if (item.leadId) {
    if (!canUse(roles, 'leads')) {
      uni.showToast({ title: '当前账号没有留资权限', icon: 'none' })
      return
    }
    goPath('/pkg-crm/leads/leads')
    return
  }

  if (item.type === 'reviews') {
    if (!canUse(roles, 'reviews')) {
      uni.showToast({ title: '当前账号没有课后分析权限', icon: 'none' })
      return
    }
    goPath('/pkg-biz/review/review')
    return
  }

  // 今日任务：任务是独立实体（Task 行），既不是会员也不是留资 ——
  // 待办页也不能改它的状态（后端 todoAction 的 type 白名单不含 tasks），
  // 所以正确落点是任务中心，在那里走 TaskController 的状态机。
  if (item.type === 'tasks') {
    if (!canUse(roles, 'tasks')) {
      uni.showToast({ title: '当前账号没有任务中心权限', icon: 'none' })
      return
    }
    goPath('/pkg-biz/tasks/tasks')
    return
  }

  if (item.type === 'trials') {
    if (!canUse(roles, 'members')) {
      uni.showToast({ title: '当前账号没有会员档案权限', icon: 'none' })
      return
    }
    goPath('/pkg-crm/members/members')
    return
  }

  uni.showToast({ title: '这条待办没有关联客户档案', icon: 'none' })
}

/**
 * 深链：/pkg-biz/todo/todo?tab=renewals → 进页面后**选中**「待续费」分类。
 *
 * 语义是「选中分类」，不是「滚到某个区块」：分类条回来了，内容区只渲染选中那一组，
 * 所以 ?tab= 就是初始 active。传错 / 传空 → 不报错，交给 applyInitialTab 落默认组
 * （绝不再出现「整屏空白」这种失败）。
 */
onLoad((options) => {
  pendingTab.value = String((options as Record<string, string>)?.tab ?? '')
})

onShow(() => {
  if (!requireLogin()) return
  if (!requireFeature('todo')) return
  load()
})

onPullDownRefresh(async () => {
  await load()
  uni.stopPullDownRefresh()
})

// 转发：员工页统一回首页（分享自己会撞 requireLogin 的登录墙），标题不带客户信息 —— 见 utils/share.ts
onShareAppMessage(() => shareToHome())
</script>

<template>
  <view class="ym-page todo">
    <!-- 顶部摘要：数字跟随当前分类，角标给全店总量（两个数字都不浪费） -->
    <view class="thero">
      <view class="thero__main">
        <text class="thero__label">{{ activeLabel }}</text>
        <view class="thero__value-row">
          <text class="thero__value ym-num">{{ tabCount(active) }}</text>
          <text class="thero__unit">项待处理</text>
        </view>
      </view>
      <view class="thero__side">
        <ym-badge :text="`今日共 ${pendingCount} 项`" tone="brand" plain />
      </view>
    </view>

    <!-- 分类条：8 项 = GROUPS 原样，**不含「全部」**（理由见 GROUPS/TAB_TYPES 注释）。
         横滑 + sticky，切一下只换内容区，不重新请求。 -->
    <view class="tabbar">
      <scroll-view class="tabbar__scroll" scroll-x :show-scrollbar="false">
        <view class="tabbar__inner">
          <view
            v-for="t in GROUPS"
            :key="t.type"
            class="tabbar__item ym-tap"
            :class="{ 'is-active': active === t.type }"
            @click="selectTab(t.type)"
          >
            <text class="tabbar__label">{{ t.label }}</text>
            <text v-if="tabCount(t.type)" class="tabbar__count ym-num">{{ tabCount(t.type) }}</text>
          </view>
        </view>
      </scroll-view>
    </view>

    <!-- 三态 -->
    <view v-if="failed && !groups.length" class="ym-card">
      <ym-empty
        icon="warning"
        text="今日待办加载失败"
        desc="网络或服务异常，可下拉刷新重试"
        action-text="重试"
        @action="load"
      />
    </view>

    <view v-else-if="loading && !groups.length" class="ym-card">
      <view v-for="i in 3" :key="i" class="sk-row">
        <wd-skeleton
          theme="text"
          animation="gradient"
          :row-col="[{ width: '34%' }, { width: '72%' }, { width: '46%' }]"
        />
      </view>
    </view>

    <ym-empty
      v-else-if="loaded && totalItems === 0"
      icon="check-circle"
      text="今日待办已全部清零"
      desc="没有需要闭环的动作，保持节奏"
      action-text="重新拉取"
      @action="load"
    />

    <!-- 单个分类为空是正常状态（分类常在、数据不一定有），给一句能看懂的话，别白屏 -->
    <ym-empty
      v-else-if="currentGroup && !currentGroup.items.length"
      icon="check-circle"
      :text="`「${activeLabel}」今天没有待办`"
      desc="切到上方其它分类看看，或下拉刷新"
      action-text="重新拉取"
      @action="load"
    />

    <!-- 内容区只渲染当前分类（v3.19.0：不再把六组全量铺开 —— 那是旧「全部」tab 的行为） -->
    <view v-if="currentGroup && currentGroup.items.length" class="gsec">
      <ym-section
        :title="currentGroup.label"
        :desc="pendingOf(currentGroup) ? `${pendingOf(currentGroup)} 项待处理` : '本组已闭环'"
      >
      <!-- ⚠️ 事件顺序（2026-11 真机 bug 修复，馆主反馈「点已沟通先跳详情页，返回后才弹面板」）
           旧写法：整行 <view @click="openCustomer(it)"> 包住按钮，指望 wd-button 上的
           `@click.stop` 拦住冒泡。**拦不住**，原因有两层：
             ① wd-button 是**自定义组件**，uni-app 对它身上的 `@click` 走的是组件自定义事件
                （$emit 出来的），`.stop` 修饰符只对原生 tap 生效，编译不到 catchtap；
             ② 于是有渲染树里行容器仍是可点区域的祖先，点按钮 = 先触发行点击 → 跳会员详情。
           现在两条一起做，缺一条都会复发：
             1. 行跳转收进 `.titem__jump`（只有姓名 + 信息那一片可点），按钮**不在它的子树里**；
             2. 按钮外层 `.titem__acts` 用 `@tap.stop` —— 在 view 上是原生事件，uni-app 会编译成
                `catchtap`，在微信小程序里由框架层截断，事件到不了任何祖先节点。
           这样「先弹窗/先提交」在结构上成立，不依赖 wot 组件是否透传 stop。 -->
      <view
        v-for="it in currentGroup.items"
        :key="it.key"
        class="titem"
        :class="{ 'is-done': it.done }"
      >
        <view class="titem__head">
          <view class="titem__jump ym-tap" @click="openCustomer(it)">
            <view class="titem__line">
              <text class="titem__title ym-ellipsis">{{ it.title }}</text>
              <ym-badge v-for="t in it.tags" :key="t" :text="t" tone="warn" />
            </view>

            <!-- 信息行：每组都答「这个人为什么在这一组」（字段缺失时整行不渲染，见 infoOf） -->
            <text v-for="(line, i) in it.info" :key="i" class="titem__info ym-ellipsis">
              {{ line }}
            </text>

            <text v-if="it.done" class="titem__done">已由 {{ it.doneBy || '同事' }} 处理</text>
          </view>

          <!-- 动作按钮：small + 不 block，横向并排贴右，取代原来整行一个 mega 按钮 -->
          <view v-if="!it.done && it.actions.length" class="titem__acts" @tap.stop>
            <wd-button
              v-for="a in it.actions"
              :key="a"
              size="small"
              plain
              :block="false"
              :disabled="acting === `${it.type}:${it.key}`"
              @click="onAction(it, a)"
            >
              {{ a }}
            </wd-button>
          </view>
          <ym-badge v-else-if="it.done" text="已完成" tone="brand" plain />
        </view>

        <!-- 同事写下的沟通内容，同一行里直接看得到（后端 doneRemark） -->
        <text v-if="it.done && it.doneRemark" class="titem__donenote ym-ellipsis">{{ it.doneRemark }}</text>
      </view>
      </ym-section>
    </view>

    <!-- 留痕面板（2026-11 员工反馈 / v3.19.0 按场景分短语）：
         只有 SCENE_PRESETS 里配了短语的「组 + 动作」才会弹出（needsNote），其余一键闭环。
         标题带场景（`待续费 · 张女士`）、副标题带「为什么在这里」、短语按场景给，
         内容写进会员档案的操作留痕（后端 module=会员管理）。 -->
    <wd-popup
      v-model="noteOpen"
      position="bottom"
      safe-area-inset-bottom
      custom-style="border-radius: 28rpx 28rpx 0 0;"
    >
      <view class="note">
        <view class="note__head">
          <text class="note__title">{{ noteTitle }}</text>
          <text class="note__sub ym-ellipsis">{{ noteSubtitle }}</text>
        </view>

        <view class="note__presets">
          <view
            v-for="p in notePresets"
            :key="p"
            class="note__chip ym-tap"
            :class="{ 'is-on': noteText.includes(p) }"
            @click="togglePreset(p)"
          >
            {{ p }}
          </view>
        </view>

        <wd-textarea
          v-model="noteText"
          :maxlength="200"
          show-word-limit
          :placeholder="notePlaceholder"
          custom-class="note__input"
        />

        <text class="note__tips">{{ noteTips }}</text>

        <view class="note__btns">
          <view class="note__btnwrap">
            <wd-button plain block @click="confirmNote(false)">只标记</wd-button>
          </view>
          <view class="note__btnwrap">
            <wd-button type="primary" block @click="confirmNote(true)">保存并标记</wd-button>
          </view>
        </view>
      </view>
    </wd-popup>
  </view>
</template>

<style lang="scss" scoped>
@import '@/styles/tokens.scss';

.todo {
  --wot-button-medium-height: 88rpx;
  --wot-button-medium-fs: 26rpx;
  /* 行内动作改用 small（v3.19.0）：默认 small 是 28px/0 12px，落地到微信里偏松，
     这里压到 52rpx 高、20rpx 内边距 —— 一行里塞得下两个动作，且单条待办回到
     「两行文字 + 右侧小按钮」的高度，一屏能多看到 1–2 条。 */
  --wot-button-small-height: 52rpx;
  --wot-button-small-fs: 24rpx;
  --wot-button-small-padding: 0 20rpx;
  --wot-button-small-radius: 10rpx;
}

/* ---------- 分类条（横向滑动 + 吸顶） ----------
 * 视觉沿用 v3.19.0 第一版那一套：胶囊标签 + 计数角标，选中态用品牌色实心。
 * 只保留 8 个业务分类，没有「全部」—— 理由见 script 里 TAB_TYPES 的注释。 */
.tabbar {
  position: sticky;
  top: 0;
  z-index: 6;
  background: $white;
  border-top: 2rpx solid $hairline;
  border-bottom: 2rpx solid $hairline;
}

.tabbar__scroll {
  width: 100%;
  white-space: nowrap;
}

.tabbar__inner {
  display: inline-flex;
  gap: 12rpx;
  padding: 14rpx 24rpx;
}

.tabbar__item {
  display: inline-flex;
  flex-shrink: 0;
  gap: 8rpx;
  align-items: center;
  height: 80rpx;
  padding: 0 26rpx;
  background: $ink-50;
  border-radius: 999rpx;
}

.tabbar__item.is-active {
  background: $brand-500;
}

.tabbar__label {
  font-size: 26rpx;
  color: $ink-600;
}

.tabbar__item.is-active .tabbar__label {
  font-weight: 600;
  color: $white;
}

.tabbar__count {
  min-width: 34rpx;
  height: 34rpx;
  padding: 0 8rpx;
  font-size: 20rpx;
  line-height: 34rpx;
  text-align: center;
  color: $brand-700;
  background: $brand-100;
  border-radius: 999rpx;
}

.tabbar__item.is-active .tabbar__count {
  color: $brand-700;
  background: $white;
}

/* ---------- 当前分类的内容区 ----------
 * 只渲染一组，所以不需要「组间距」；保留一层容器是为了将来能加区块动效/锚点，
 * 不再承担深链定位（滚动定位已随「全量铺开」一起删掉，见 applyInitialTab）。 */
.gsec {
  padding-top: 8rpx;
}

/* ---------- 头部摘要 ---------- */
.thero {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 26rpx 28rpx;
  margin: 20rpx 24rpx 0;
  background: linear-gradient(150deg, $brand-50, $white 62%);
  border: 2rpx solid $brand-100;
  border-radius: $r-lg;
}

.thero__main {
  display: flex;
  flex-direction: column;
  gap: 8rpx;
}

.thero__label {
  font-size: 24rpx;
  color: $ink-500;
  letter-spacing: 0.5rpx;
}

.thero__value-row {
  display: flex;
  gap: 8rpx;
  align-items: baseline;
}

.thero__value {
  font-size: 52rpx;
  font-weight: 600;
  line-height: 1.15;
  color: $ink-800;
}

.thero__unit {
  font-size: 22rpx;
  color: $ink-400;
}

.thero__side {
  display: flex;
  align-items: center;
}

/* ---------- 骨架 ---------- */
.sk-row {
  padding: 20rpx 0;

  & + & {
    border-top: 2rpx solid $hairline;
  }
}

/* ---------- 待办行 ----------
 * v3.19.0 行高口径：单条 = 内边距 + 标题行（动作按钮就在这一行右侧）+ 信息行，
 * 目标 ≤ 旧版（两行文字 + 整行 88rpx 按钮）的 2/3。因此：
 *  - padding 20→16 / border 保留（分组要能一眼切开）
 *  - 动作按钮不再单占一行，改到标题行右侧垂直居中
 */
.titem {
  /* 14rpx：加上分隔线，单条仍在 ~136rpx（含两行信息），旧版是 ~224rpx */
  padding: 14rpx 0;
  border-top: 2rpx solid $hairline;

  &:first-child {
    border-top: none;
    padding-top: 4rpx;
  }

  &.is-done {
    opacity: 0.55;
  }
}

.titem__head {
  display: flex;
  flex-direction: row;
  gap: 16rpx;
  /* flex-start 而不是 center：两行信息的行里，居中会让按钮浮在两行中间、
     看起来跟姓名没绑定；顶对齐后「姓名 + 右侧动作」读作同一行，信息行在下面 */
  align-items: flex-start;
}

/* 可点区：只有姓名 + 信息这一片进会员 360。
   动作按钮被刻意排除在这个子树之外 —— 这是「按钮先于行跳转生效」的结构保证，
   改模板时不要把按钮挪回这里（详见模板顶部的注释）。 */
.titem__jump {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 3rpx;
  min-width: 0;
}

.titem__line {
  display: flex;
  gap: 10rpx;
  align-items: center;
  min-width: 0;
}

.titem__title {
  flex-shrink: 1;
  min-width: 0;
  font-size: 30rpx;
  font-weight: 500;
  color: $ink-800;
}

/* 信息行：比标题小一档、比纯灰深一点（它是要被读的事实，不是装饰） */
.titem__info {
  display: block;
  font-size: 23rpx;
  /* 行高收到 1.35：信息行是「扫一眼」的事实，不需要宽松的阅读节奏 */
  line-height: 1.35;
  color: $ink-500;
}

/* 动作按钮容器：catchtap（@tap.stop）在这一层，按钮本身只发自定义事件 */
.titem__acts {
  display: flex;
  flex-shrink: 0;
  gap: 10rpx;
  align-items: center;
  /* 与标题（30rpx/约 42rpx 行高）视觉居中对齐：small 按钮 52rpx，上移 2rpx 更贴基线 */
  margin-top: 2rpx;
}

.titem__done {
  display: block;
  font-size: 22rpx;
  color: $ink-400;
}

/* 同事写下的沟通内容：与「已由 X 处理」同色系但再浅一档，长内容单行截断，
   全文去会员 360 的「操作留痕」看 —— 列表里要的是「有人说过什么」，不是完整纪要。 */
.titem__donenote {
  display: block;
  margin-top: 6rpx;
  font-size: 22rpx;
  line-height: 1.5;
  color: $ink-600;
}

/* ---------- 沟通备注面板 ---------- */
.note {
  display: flex;
  flex-direction: column;
  gap: 18rpx;
  padding: 32rpx 28rpx calc(28rpx + env(safe-area-inset-bottom));
  background: $white;
}

.note__head {
  display: flex;
  flex-direction: column;
  gap: 8rpx;
}

.note__title {
  font-size: 32rpx;
  font-weight: 600;
  color: $ink-900;
}

.note__sub {
  font-size: 24rpx;
  color: $ink-400;
}

.note__presets {
  display: flex;
  flex-wrap: wrap;
  gap: 14rpx;
}

.note__chip {
  padding: 12rpx 20rpx;
  font-size: 24rpx;
  color: $ink-600;
  background: $ink-50;
  border: 2rpx solid $hairline;
  border-radius: 999rpx;
}

.note__chip.is-on {
  color: $brand-700;
  background: $brand-100;
  border-color: $brand-500;
}

.note__input :deep(.wd-textarea__inner) {
  min-height: 160rpx;
  font-size: 26rpx;
}

.note__tips {
  font-size: 22rpx;
  line-height: 1.5;
  color: $ink-400;
}

.note__btns {
  display: flex;
  gap: 16rpx;
  margin-top: 6rpx;
}

.note__btnwrap {
  flex: 1;
  min-width: 0;
}
</style>
