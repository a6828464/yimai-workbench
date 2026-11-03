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
  email: string | null
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
  type: 'bookings' | 'renewals' | 'churnRisks' | 'birthdays' | 'trials' | 'reviews' | 'newLeads'
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
  grade: '' | 'A' | 'B' | 'C'
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
    time: string
    topic: string
    teacher: string
    couponName: string
    voucherCode: string
    platform: string
    total?: number | null
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
