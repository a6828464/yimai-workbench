/** 与 backend User::userInfo() 对齐 */
export interface UserInfo {
  userId: number
  /** 展示名（优先昵称），仅用于界面呈现 */
  userName: string
  /** 归属用规范名：写入/比较归属必须用它 */
  staffName: string
  roles: string[]
  venue: string | null
  venues: string[]
  buttons: string[]
  /**
   * 邮箱：小程序端已下线（不展示、不提交）。后端可能仍下发老值，
   * 因此这里保留可选字段只为兼容响应，页面不要读它。
   */
  email?: string | null
  phone?: string | null
  avatar?: string | null
  status?: string
}

export interface LoginResult {
  token: string
  refreshToken: string
  userInfo: UserInfo
}

/** 分页契约（TaskController/ApprovalController 的 index） */
export interface PageResult<T> {
  records: T[]
  total: number
  current: number
  size: number
}

export interface TaskItem {
  id: number
  title: string
  customerName: string
  venue: string
  owner: string
  priority: '高' | '中' | '低'
  deadline: string
  standard: string
  status: '待接收' | '进行中' | '待验收' | '已完成' | '已退回'
}

export interface ApprovalItem {
  id: number
  customerName: string
  applicant: string
  venue: string
  cardName: string
  standardPrice: number
  requestPrice: number
  reason: string
  status: '待店长初审' | '待老板终审' | '已通过' | '已驳回' | '已关联成交'
  applyTime: string
}

/** 今日待办（TodayController::todo 的分组结构，字段随组类型不同） */
export interface TodoGroup {
  // tasks 也是真实存在的一组（后端 `'tasks' => $tasks`、工作台 TODO_LABELS.tasks = '今日任务'）；
  // 早先漏在这里导致「今日任务」被类型层当成不可能的值。
  type: 'bookings' | 'renewals' | 'churnRisks' | 'birthdays' | 'trials' | 'reviews' | 'newLeads' | 'tasks'
  label: string
  items: TodoItem[]
}

export interface TodoItem {
  key: string
  title: string
  subtitle?: string
  tags?: string[]
  done?: boolean
  doneBy?: string
  doneAt?: string
  customerId?: number
  leadId?: number

  /* ---- v3.19.0 信息行契约（全部可选：后端没落地时页面整行不显示，不做假数据） ---- */

  /*
   * 以下每个键名都**逐字对齐后端 TodayController::todo() 实际下发的键**，
   * 注释里的行号即溯源（改后端时按行号回查）。为什么不留「别名兜底」：
   * 声明或读取后端从不下发的键，等于给后来的人留一条假路径 —— 真改名了也照样静默失效，
   * 排查时还会以为「兜底分支在工作」。宁可读一个键、取不到就整段不显示。
   */

  /** 「为什么在这组」的现成句子（renewals L915 / churnRisks L967 / reviews L1173 / newLeads L1123） */
  whyText?: string
  /** 待续费的卡项提示，如 `私教小班 · 剩 3 节 · 7 天后到期`（renewals L914） */
  cardHint?: string
  /** 卡项名（renewals L913）；bookings / churnRisks 的卡名走 `mainCard`（L722 闭包 + L830 展开 / L962） */
  cardName?: string
  /** 主卡名（bookings L830 展开自 L722；renewals L889；churnRisks L962） */
  mainCard?: string
  /**
   * 卡项余量（bookings L830 展开自 L722；renewals L890；churnRisks L963）。
   * 单位由服务端在 whyText / cardHint 里写死为「节」（`$cardQty` 的默认单位），
   * 这三个组**不下发 unit** —— 所以没有 `cardUnit` 这个键，别再声明它。
   */
  remainTimes?: number | null
  /** 到期天数，负数为已过期（renewals L892；另有 L903 的 nextExpireDays 指「下一张卡」） */
  expireDays?: number | null
  /** 下一张卡的到期天数（renewals L903，与 expireDays 同源，前端暂未使用但确有其键） */
  nextExpireDays?: number | null
  /** 会员主卡到期日 `YYYY-MM-DD`（churnRisks L966，空值为 null） */
  expireDate?: string | null
  /** 上次到店日期（churnRisks）；前端只取 `MM-DD` 展示 */
  lastVisit?: string | null
  /** 距上次到店天数（churnRisks L954；负数为已过期，前端不读负值） */
  lastVisitDays?: number | null
  /** 生日组距离生日的天数（birthdays L1002；注意不是 expireDays） */
  daysLater?: number | null
  /** 生日组：`10-08`（birthdays L1008，后端只给 MM-DD，不带「生日」二字） */
  birthdayText?: string
  /** 生日组：`29 岁`（birthdays L1009） */
  ageText?: string
  /** 生日组：今天是否就是生日（birthdays L1001；由后端按门店时区判，前端不比本地时间） */
  isToday?: boolean
  /** 今日预约：近三个月出勤，左→右由远到近，与会员详情「近三档出勤」同口径（bookings L824） */
  attend?: { m1?: number | null; m2?: number | null; m3?: number | null }
  /** 今日预约：是否匹配到会员档案（bookings L829）。false 时 attend 三项全 0，不可当作「没来」 */
  attendKnown?: boolean
  /**
   * 课程事实。bookings：time L806 / course L812 / kind L813 / teacher L814；
   * trials：KY 分支 time L1042、course L1061、kind L1062、teacher L1048，
   * 留资分支 time L1084、course L1100、kind L1101、teacher L1090（`kind` 在留资卡缺课型时为空串）。
   */
  kind?: string
  course?: string
  time?: string
  teacher?: string
  [key: string]: unknown
}

/* ==========================================================================
 * 会员管理（GET /customers 系列）
 * 字段名来自后端 camel() 转换；类型与 admin-web 的 YimaiCustomer 对齐，
 * 但只保留小程序实际渲染的字段，避免把 2,621 行页面里的桌面专用列拖进来。
 * ========================================================================== */

export interface MiniappCustomer {
  id: number
  name: string
  /** 完整手机号（内部系统全量显示） */
  phone?: string
  phoneTail: string
  venue: string
  source: string
  consultant?: string
  mainCard: string
  remainTimes: number | null
  expireDate: string | null
  lastVisit: string | null
  birthday?: string | null
  /** 经营分层 P0–P5（后端 customerLayerFor 派生的权威列） */
  layer: 'P0' | 'P1' | 'P2' | 'P3' | 'P4' | 'P5'
  /**
   * 会员备注（详情页头部展示）。
   * 后端 `show()` 是把整行 `camel($c)` 透传的，所以 customers 表一旦有 remark 列就会自动出现；
   * 当前库里还没有这一列 → 线上这行不会渲染（空值不占位），属于预期。
   */
  remark?: string | null
  status: string
  owner: string
  nextAction: string
  nextActionTime: string
  attendM1?: number
  attendM2?: number
  attendM3?: number
  totalPurchased?: number | null
  cardPaidAmount?: number | null
  renewalPlan?: { time: string; amount: string; course: string; issue: string; intent: string }
  decline?: { reason: string; solution: string }
  stopReason?: string
  expectedReturn?: string
  lastTouch?: string
  needsHelp?: boolean
  inRevive?: boolean
  evalScore?: number | null
  evalAt?: string
  evalLevel?: 'high' | 'medium' | 'low' | null
  evalBy?: string
}

/** 待续费「命中理由」（后端 renewalReasons，仅待续费清单里的人有值） */
export interface RenewalReason {
  why?: string[]
  bucket?: string
  urgent?: boolean
  revive?: boolean
  primary?: string
  coLists?: string[]
  degraded?: string[]
}

export interface AttendanceMonths {
  m1: string
  m2: string
  m3: string
  m1Range?: string
  m2Range?: string
  m3Range?: string
}

export interface CustomerListResult extends PageResult<MiniappCustomer> {
  renewalReasons?: Record<string, RenewalReason>
  attendanceMonths?: AttendanceMonths
}

export interface MemberListCounts {
  counts: Record<string, number>
}

/** GET /customers/{id}：客户 360 */
export interface CustomerDetail {
  customer: MiniappCustomer
  /** 会员管理模块的操作留痕（最近的在前） */
  logs: {
    id: number
    action: string
    operator?: string
    detail?: string
    createdAt?: string
    [key: string]: unknown
  }[]
  /** 按手机号关联的前端客资 */
  leads: MiniappLead[]
  /**
   * 该会员的**全部卡项**（三态：在用 current / 未开卡 unactivated / 已过期 history）。
   *
   * 与 `customer.cardsList` 的区别：后者是判定层输入（只有在用 + 未开卡），
   * 这里由 `GET /customers/{id}` 的顶层下发，详情页要展示"主卡以外还有哪些卡"就读它。
   */
  cardsList?: CustomerCard[]
  /** 各态卡项数量（active/unactivated/expired/total），标题上的计数用 */
  cardGroups?: { active?: number; unactivated?: number; expired?: number; total?: number }
}

/** 会员卡项实例（后端 cards_list / historyCards 的归一化形状，只读） */
export interface CustomerCard {
  id?: number | string | null
  cardNo?: string | null
  title?: string
  type?: string
  unit?: string
  statusCode?: string | null
  /** current=在用 / unactivated=未开卡 / history=已过期 */
  state?: 'current' | 'unactivated' | 'history' | string
  /** 中文态名（后端下发；缺失时前端按 state 兜底） */
  stateLabel?: string
  /** 是否为当前主卡 */
  isMain?: boolean
  remaining?: number | null
  /** 剩余天数（期限卡）：后端 CardLifecycle 直接算好的余额，比 remaining 更能说明「还剩几天」 */
  residueDays?: number | null
  /**
   * 卡项「总量」，v3.19.0 后端新增（`CustomerController::cardBalance()` 算，会员详情用）。
   *
   * 各卡型的真实取值（不要照抄成「储值卡=总金额」，那是错的）：
   *  · 次卡 = 原始行 `bound`（同步侧写的「剩余 + 已耗次数」）；
   *  · 期限卡 = `validDays`（原始行 `bound` 恒 null，后端退回有效期总天数）；
   *  · 储值卡 = **恒 null**（上游不给储值卡初始充值额，也没有 `consumed` 可兜底），
   *    所以界面上储值卡只会显示「剩 1200 元」，没有「总 3000 元」。
   * 取不到就是 null（未知 ≠ 0）。前端**不猜**、不拿 `bound` 自己算，缺失时只显示剩余量
   * （见 detail.vue 的 cardRemain() 函数）。
   */
  boundTotal?: number | null
  bound?: number | null
  validDays?: number | null
  deadline?: string | null
  daysUntil?: number | null
  /**
   * 卡项备注：后端从原始行 pick（`remark / card_remark / note / memo`），取不到是 null。
   * 所以这一行**源数据有才出现**，真实数据下不显示属于预期，不是没生效。
   */
  remark?: string | null
  [key: string]: unknown
}

/* ==========================================================================
 * 留资管理（GET/POST/PATCH /leads）
 * ========================================================================== */

export type LeadStatus =
  | '新留资'
  | '已联系'
  | '已约体验'
  | '已体验'
  | '已成交'
  | '已流失'
  | '爽约'

export interface MiniappLead {
  id: number
  leadDate: string
  name: string
  phone?: string
  phoneTail?: string
  wechat?: string
  demand: string
  source: string
  referrer?: string | null
  orderPlatform?: string
  venue: string
  serviceTeacher: string
  status: LeadStatus
  // grade 是「体验课类型」，与 admin-web 的 TRIAL_TYPES 同源（定制私教/私教小班/精品团课/其他）。
  // 早先是「客户分级 A/B/C」，网页端改语义后小程序没跟上，两端曾各说各话（v3.14.0 修复）。
  // 用 string 而非联合类型：库里可能残留 'A'/'B'/'C' 历史值，窄类型会让「历史值」分支编译不过。
  grade: string
  dealCard: string
  dealAmount: number | null
  dealAt?: string | null
  redeemAmount: number | null
  voucherCode?: string
  couponName?: string
  couponTotal?: number | null
  couponRemaining?: number | null
  trialCards?: Array<{
    session: number
    /** 卡片日期（部分历史卡片只有 time 没有 date） */
    date?: string
    time: string
    topic: string
    teacher: string
    couponName: string
    voucherCode: string
    platform: string
    total?: number | null
    /** 券剩余次数（与 total 配对展示「剩 N / 共 M 次」） */
    remaining?: number | null
    /** 单节核销金额（元）；行级 redeemAmount 是各节之和 */
    redeem?: number | null
    /** 到店/爽约/取消三态：网页端「体验课 / 券码」列按这三项打标签 */
    attended?: boolean
    noShow?: boolean
    cancelled?: boolean
  }>
  remark?: string
  createdBy?: string
  /** 后端下发的跟进时限（有则显示，缺失不伪造） */
  followDeadline?: string | null
  [key: string]: unknown
}

/** 后端随列表下发的渠道权威清单 */
export interface LeadEnums {
  sources: string[]
  orderPlatforms: string[]
}

export interface LeadListResult extends PageResult<MiniappLead> {
  enums?: LeadEnums
}

/** GET /leads/check */
export interface LeadCheckResult {
  exists: boolean
  matches: Array<{
    kind: '会员' | '留资' | '已有留资'
    name: string
    venue: string
    detail: string
    id?: number
  }>
}
