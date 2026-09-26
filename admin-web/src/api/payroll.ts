/**
 * 薪酬计算 API（**仅超管**）
 *
 * 口径与端点契约见 `docs/薪酬/薪酬计算栏目规格.md` §7。本文件只做「类型定义 + 请求封装」，
 * **不在这里写任何计算规则** —— 所有金额、档位、三态语义一律由后端下发，前端只负责渲染。
 *
 * ## 为什么身份标签枚举必须从 `/payroll/roles` 取
 *
 * 规格 §7.4 明令：`GET /payroll/roles` 是**枚举唯一下发点**，前端**禁止**在 `payroll/`
 * 下再写一份硬编码列表。写两份就会出现第二套口径 —— 后端加一个标签、前端不知道，
 * 编辑弹窗里选不到，人就永远配不上。所以本文件里没有 `ROLES = [...]` 这样的常量。
 *
 * ## 错误码
 *
 * 后端错误形态是 `{message, code}`（规格 §7.8）。axios 拦截器只归一化了 403 的 message，
 * 其余情况要拿 `code` 必须自己从 `error.response.data` 里读，故提供 {@link payrollErrorCode}。
 */
import { apiGet, apiPost, apiPut } from './backend'

// ---------------------------------------------------------------------------
// 身份标签 / 规则目录（GET /payroll/roles）
// ---------------------------------------------------------------------------

/** 单个身份标签的薪酬规则说明（后端下发的原文，前端只展示不解释） */
export interface PayrollSalaryRules {
  base?: string
  performance?: string
  hourly?: string
  hourlyIncentive?: string
  commission?: string
  baseReward?: string
  storeCommission?: string
  [key: string]: string | undefined
}

export interface PayrollRoleOption {
  /** 存储层枚举值，提交时用它 */
  value: string
  /** 展示文案（馆主会写成「馆主（显示为「管理层」）」） */
  label: string
  salaryRules: PayrollSalaryRules
}

/** 社保三态选项（后端下发，含中文说明） */
export interface PayrollSocialModeOption {
  value: PayrollSocialMode
  label: string
}

/** 社保三态：`set` 本月已设（含显式 0）/ `inherit` 本月未操作 / `off` 本月不缴 */
export type PayrollSocialMode = 'set' | 'inherit' | 'off'

export interface PayrollRolesCatalog {
  roles: PayrollRoleOption[]
  venues: string[]
  statuses: string[]
  dualBaseSalaryWhitelist: string[]
  activityTypes: Record<string, number>
  socialSecurityModes: PayrollSocialModeOption[]
  commissionTiers: { threshold: number; rate: number }[]
  baseRewardTiers: { hours: number; amount: number }[]
  hourlyIncentiveTiers: { threshold: number; addOn: number }[]
  /** 45 分钟单价折算系数（基数 = 课时费） */
  fee45FallbackFactor: number
  /** 私教激励 45 分钟系数（基数 = 加价）。与上一个**不是同一个东西**，禁止合并显示 */
  incentive45Factor: number
}

export function fetchPayrollRoles(): Promise<PayrollRolesCatalog> {
  return apiGet<PayrollRolesCatalog>('/payroll/roles')
}

// ---------------------------------------------------------------------------
// 课时统计（GET /payroll/hours）
// ---------------------------------------------------------------------------

/** 时长来源：课程名解析 / raw 起止时间 / 人工 / **按 60 分钟估算** */
export type PayrollDurationSource = 'name_regex' | 'raw_end_time' | 'manual' | 'assumed_60'

export interface PayrollHoursRow {
  userId: number | null
  profileId: number | null
  /** 真实姓名（解析后的档案姓名）；未命中档案时是授课老师原名 */
  name: string
  /** 该人所有出现过的名字（含别名/授课名） */
  sourceNames: string[]
  venue: string
  role: string
  roleLabel: string
  private60: number
  private45: number
  small: number
  group: number
  enterprise: number
  /** 私教60 + 私教45 + 小班 + 团课（底薪奖励用） */
  validHours: number
  /** 全部课型合计（含企业课等，展示用） */
  totalHours: number
  /** **课次**口径（已按 venue+teacher_name+start_at+course_kind 去重） */
  classCount: number
  /** 未去重的预约行数，供人工核对倍数 */
  bookingRows: number
  durationSource: PayrollDurationSource
  durationSources: Partial<Record<PayrollDurationSource, number>>
  /** **私教**课中课程名未含时长、降级为 60 分钟档的节数 */
  assumed60Count: number
  sampleCourses: string[]
  /** 两店累计有效课时（底薪奖励门槛判断用）；未命中档案时为 null */
  accumulatedHours: number | null
  baseRewardTier: number
  baseReward: number
  // ---- 双源核验（课时记录 vs 预约记录）----
  /** 预约记录侧课次 = `classCount`（计价口径） */
  bookingSessions: number
  /** 课时记录侧节数（`course/api/getcoursesummaryrecordstat`） */
  courseRecordSessions: number
  /** 差额 = courseRecordSessions - bookingSessions（正数 = 课时记录多） */
  sourceDiff: number
  /** 谁多：course=课时记录多 / booking=预约记录多 / equal=一致 */
  sourceLeader: 'course' | 'booking' | 'equal'
  /** 课时记录里是否出现过此人 */
  courseRecordSeen: boolean
  courseRecordKinds: Partial<Record<'private' | 'small' | 'group', number>>
}

export interface PayrollHoursWarning {
  code: string
  count?: number
  message: string
  names?: string[]
  affectedUsers?: {
    userId: number | null
    profileId: number | null
    name: string
    venue?: string
    assumed60Count?: number
    sessions?: number
    sampleCourses?: string[]
  }[]
  /** `HOURS_SOURCE_DIFF`：逐人差额明细（谁多、谁少、差几节） */
  diffTeachers?: PayrollSourceDiffTeacher[]
}

/** 逐人两源差额（谁多、谁少、差几节） */
export interface PayrollSourceDiffTeacher {
  userId: number | null
  profileId: number | null
  name: string
  venue: string
  courseRecordSessions: number
  bookingSessions: number
  /** courseRecordSessions - bookingSessions */
  diff: number
  leader: 'course' | 'booking' | 'equal'
  courseRecordSeen: boolean
}

/** 单侧来源的汇总（课时记录 / 预约记录） */
export interface PayrollHoursSourceSide {
  available: boolean
  label: string
  source: string
  /** 该侧总节数 */
  sessions: number
  /** 该侧涉及老师数 */
  teachers: number
  /** 按上课门店的节数小计 */
  byVenue: Record<string, number>
  isPricingSource: boolean
  error?: string | null
}

/** 一家门店的两源对照 */
export interface PayrollHoursVenueDiff {
  courseRecordSessions: number
  bookingSessions: number
  diff: number
  leader: 'course' | 'booking' | 'equal'
  courseRecordTeachers: number
  bookingTeachers: number
  teacherCourseRecord: Record<string, number>
}

/** 双源核验结果（`GET /payroll/hours` 的 `bySource`） */
export interface PayrollHoursBySource {
  bookingRecord: PayrollHoursSourceSide
  courseRecord: PayrollHoursSourceSide
  diff: {
    /** 恒等于 courseRecord.sessions - bookingRecord.sessions */
    sessions: number
    teachers: PayrollSourceDiffTeacher[]
    teacherCount: number
    byVenue: Record<string, PayrollHoursVenueDiff>
    label: string
    directionNote: string
  }
  excluded: {
    /** 课时记录有节数、预约记录 0 行 ⇒ 未开课，不计课时费 */
    notOpened: {
      userId: number | null
      profileId: number | null
      name: string
      venue: string
      courseRecordSessions: number
      bookingSessions: number
      reason: string
    }[]
    notOpenedCount: number
    rule: string
  }
  unmatched: { courseRecordOnly: string[] }
}

export interface PayrollHoursMeta {
  /** 本次实际采用的去重键，回显使口径可被审计 */
  dedupeKey: string[]
  dedupeKeyReason: string
  classCount: number
  bookingRows: number
  statusFilter: string
  trialExcluded: boolean
  durationPriority: string[]
  /** 双源口径与来源说明（前端只渲染，不写第二份口径） */
  sources?: {
    bookingRecord: { label: string; table: string; endpoints: string[]; rule: string }
    courseRecord: {
      label: string
      endpoint: string
      /** 实际发出的四个参数（含 start/end/page_index/page_size），供用户核对 */
      request: Record<string, string | number>
      rule: string
    }
    pricingSource: string
    pricingSourceReason: string
  }
}

export interface PayrollHoursResult {
  month: string
  venue: string | null
  rows: PayrollHoursRow[]
  warnings: PayrollHoursWarning[]
  meta: PayrollHoursMeta
  /** 双源核验：两源并列 + 差额 + 显式排除 */
  bySource: PayrollHoursBySource
}

export function fetchPayrollHours(
  month: string,
  venue?: string | null
): Promise<PayrollHoursResult> {
  return apiGet<PayrollHoursResult>('/payroll/hours', { month, venue: venue ?? undefined })
}

// ---------------------------------------------------------------------------
// 薪酬档案（GET /payroll/profiles、PUT /payroll/profiles/{userId}）
// ---------------------------------------------------------------------------

export interface PayrollProfileRow {
  id: number
  /** 登录账号 id；大量薪酬人员没有账号，故可为 null */
  userId: number | null
  externalId: string | null
  name: string
  venue: string
  /** 存储层枚举（提交用） */
  role: string
  /** 展示层岗位（馆主→管理层、跨店→{门店}全职老师） */
  roleLabel: string
  baseSalary: number
  performance: number
  feePrivate60: number
  /** 档案里**显式配置**的 45 分钟单价；为 0 表示未单独设置 */
  feePrivate45: number
  /** 实际生效的 45 分钟单价（档案为 0 时按 60×0.75 折算） */
  feePrivate45Effective: number
  /** 上者是折算来的（前端要显示「折算 ¥X」，不能显示成人工配置值） */
  feePrivate45Derived: boolean
  feeSmall: number
  feeGroup: number
  feeEnterprise: number
  dualBaseSalary: boolean
  storeCommissionRate: number
  commissionFixedRate: number | null
  status: string
  alert: string
  accountStatus: string
  // ── 收款账户与联系方式（用户决策 2026-09-26 完整入库；展示层做掩码）──
  /** 收款户名（可能与真实姓名不同，如代发亲属卡） */
  bankAccountName: string
  /** 银行卡号（完整值；界面默认掩码只显示后 4 位，点「显示」露出） */
  bankCardNo: string
  /** 开户行/网点 */
  bankName: string
  /** 联行号（跨行转账用） */
  bankCnaps: string
  /** 转账类型（行内/行外） */
  transferType: string
  /** 手机号（主档缺 41 人，空串 = 未提供） */
  phone: string
  /** 身份证号（完整值；界面掩码显示） */
  idCardNo: string
  /** 企业微信账号 */
  wechatWork: string
  /**
   * 待完善：档案已建但身份标签/单价未经确认，**不参与工资计算**。
   *
   * 「字段留空」在计算侧不等于「不参与计算」—— 空身份标签会被当成全职老师
   * 按实际课时发底薪奖励，所以待完善的档案整行跳过，只在计算页的
   * 「无法计算的项目」里列出。保存（身份标签合法）后自动解除。
   */
  pendingReview: boolean
  note: string
  aliases: string[]
}

export interface PayrollProfilesResult {
  rows: PayrollProfileRow[]
  roles: PayrollRoleOption[]
  venues: string[]
}

export function fetchPayrollProfiles(params?: {
  venue?: string | null
  status?: string
  role?: string
}): Promise<PayrollProfilesResult> {
  return apiGet<PayrollProfilesResult>('/payroll/profiles', {
    venue: params?.venue ?? undefined,
    status: params?.status || undefined,
    role: params?.role || undefined
  })
}

/** 更新载荷。只提交要改的字段即可，未提交的字段后端保持原值。 */
export interface PayrollProfileUpdate {
  venue?: string
  role?: string
  baseSalary?: number
  performance?: number
  feePrivate60?: number
  feePrivate45?: number
  feeSmall?: number
  feeGroup?: number
  feeEnterprise?: number
  dualBaseSalary?: boolean
  storeCommissionRate?: number
  commissionFixedRate?: number | null
  status?: string
  alert?: string
  accountStatus?: string
  // 收款账户与联系方式（用户决策 2026-09-26 完整入库）
  bankAccountName?: string
  bankCardNo?: string
  bankName?: string
  bankCnaps?: string
  transferType?: string
  phone?: string
  idCardNo?: string
  wechatWork?: string
  /** 待完善标记：一般不必手动传（身份标签合法时保存即自动解除） */
  pendingReview?: boolean
  note?: string
  aliases?: string[]
}

/**
 * 保存单个人员档案。
 *
 * ⚠️ `targetId` 传 **`profile.id`**（不是 `userId`）。
 *
 * 后端 `PayrollController::updateProfile` 的查找顺序是「先按 `user_id`，找不到再按
 * `profile.id`」。本系统 55 个薪酬人员**全部没有登录账号**（`user_id` 为 null），
 * 所以按 `user_id` 一定找不到，实际生效的永远是 `profile.id` 这条路。
 *
 * 副作用：当某人的 `user_id` 恰好等于**另一个人**的 `profile.id` 时，传 profile.id
 * 会被第一分支截胡、改错人。调用方必须用 {@link assertSameProfile} 兜底核对返回值。
 */
export function updatePayrollProfile(
  targetId: number,
  body: PayrollProfileUpdate
): Promise<{ profile: PayrollProfileRow; changed: boolean }> {
  return apiPut<{ profile: PayrollProfileRow; changed: boolean }>(
    `/payroll/profiles/${targetId}`,
    body as Record<string, unknown>
  )
}

/**
 * 保存后核对「改的是不是同一个人」。
 *
 * 见 {@link updatePayrollProfile} 的说明：后端两段式查找存在 id 空间混用的可能，
 * 一旦命中错误的档案，保存会**静默改到别人头上**（工资数据，代价极高）。
 * 这里做一次极便宜的返回体核对，不一致就明确报错而不是当成功。
 */
export function assertSameProfile(expected: PayrollProfileRow, actual: PayrollProfileRow): void {
  if (expected.id !== actual.id) {
    throw new Error(
      `保存返回的档案与目标不一致（期望 #${expected.id}「${expected.name}」，实际 #${actual.id}「${actual.name}」），已中止以免改错人，请刷新后重试`
    )
  }
}

// ---------------------------------------------------------------------------
// 新增建档 / 系统已知信息预填（POST /payroll/profiles、POST /payroll/profiles/prefill）
// ---------------------------------------------------------------------------

/**
 * 新增一条薪酬档案（建档）。
 *
 * 后端建出来的一定是 `pendingReview = true`：此时身份标签与单价都还没确认，
 * 而「字段留空」在计算侧**不等于**「不参与计算」—— 空身份标签会被 `role` 列的
 * 默认值当成「全职老师」，按实际课时发 200~1000 元底薪奖励（不看档案金额）。
 * 所以待完善的档案整行不参与计算，只在「薪酬计算」页的「无法计算的项目」里列出。
 */
export function createPayrollProfile(body: {
  name: string
  venue: string
  role?: string
  status?: string
  note?: string
  aliases?: string[]
}): Promise<{ profile: PayrollProfileRow }> {
  return apiPost<{ profile: PayrollProfileRow }>('/payroll/profiles', body as Record<string, unknown>)
}

/** 预填扫描结果里的一个人（来源说明他为什么进候选名单） */
export interface PayrollPrefillCandidate {
  name: string
  venue: string
  /** bookings = 上过课 / users = 有账号 / leads = 留资里登记的上课老师 */
  sources: string[]
  counts: Record<string, number>
}

export interface PayrollPrefillResult {
  dryRun: boolean
  created: number
  willCreate: PayrollPrefillCandidate[]
  skipped: { name: string; venue: string; reason: string }[]
}

/**
 * 从系统已知信息批量预填建档。
 *
 * 只把系统里**真实出现过的人**扫出来（上过课的老师 / 有账号的员工 /
 * 留资里登记的上课老师），姓名与门店填好，身份标签留空、金额一律 0 并标待完善。
 *
 * 命中既有档案（含**别名**命中的，如「苏米」→罗柳柳）一律跳过：
 * 给别名再建一条会让一个名字对应两个档案，解析器判定歧义后本人会从课时统计里消失。
 *
 * `dryRun = true` 只看清单不落库，供界面先给用户确认。
 */
export function prefillPayrollProfiles(dryRun = false): Promise<PayrollPrefillResult> {
  return apiPost<PayrollPrefillResult>('/payroll/profiles/prefill', { dryRun })
}

// ---------------------------------------------------------------------------
// 业绩表导入（preview / commit / 已导入汇总）
// ---------------------------------------------------------------------------

export interface PayrollPerformanceCounts {
  dataRows: number
  importedAllocations: number
  skippedActivityCardRows: number
  skippedOutOfMonthRows: number
  skippedZeroAmountRows: number
  personalRows: number
  venueRows: number
  multiOwnerRows: number
  distinctPeople: number
  exceptions: number
}

/** 导入金额的**两套口径**，必须并列展示（规格「附：与既有验收标准的差异说明」） */
export interface PayrollPerformanceTotals {
  /** ① 原始归属口径（**含** 299 活动卡）—— 用于核对与留档 */
  raw: {
    personal: number
    venue: number
    total: number
    /** 被剔除的 299 活动卡金额，必须让使用者看见 */
    activityCardAmount: number
  }
  /** ② 提点口径（**已剔除** 299）—— 提成与门店提成一律用这套 */
  forCommission: {
    personal: number
    storeSales: number
    /** 仅 preview 返回：按逐笔 ROUND_HALF_UP 算出的提成合计 */
    commissionTotal?: number
  }
}

export interface PayrollPerformancePerson {
  /** 表头里的列名（**9 个是别名**，如「苏米」） */
  sourceName: string
  /** 解析到的真实姓名（如「罗柳柳」） */
  resolvedName: string
  userId: number | null
  profileId: number
  role: string
  /** 含 299 活动卡的原始金额 */
  rawAmount: number
  /** 剔除 299 后的提点基数 */
  commissionAmount: number
  commissionRate: number
  commission: number
}

export interface PayrollPerformanceException {
  code: string
  row?: number
  sourceName?: string
  message: string
  amount?: number
  allocated?: number
  personal?: number
  venue?: number
  memberName?: string
}

export interface PayrollPerformanceNotice {
  level: string
  code: string
  message: string
}

export interface PayrollPerformancePayload {
  venue: string
  month: string
  sourceFileName: string
  sourceSha256: string
  counts: PayrollPerformanceCounts
  totals: PayrollPerformanceTotals
  byPerson: PayrollPerformancePerson[]
  exceptions: PayrollPerformanceException[]
  notices: PayrollPerformanceNotice[]
  /** preview 为 true（**未落库**） */
  dryRun: boolean
  /** commit 为 true 表示与上次同一份文件、**未做任何写入**（幂等提示） */
  unchanged: boolean
  replaced: { rows: number }
}

/** 上传并解析，**不落库**（POST /payroll/performance/preview） */
export function previewPayrollPerformance(
  file: File,
  venue: string,
  month: string
): Promise<PayrollPerformancePayload> {
  const form = new FormData()
  form.append('file', file)
  form.append('venue', venue)
  form.append('month', month)
  return apiPost<PayrollPerformancePayload>(
    '/payroll/performance/preview',
    form as unknown as Record<string, unknown>,
    120000
  )
}

// ---------------------------------------------------------------------------
// 人员主档导入（POST /payroll/profiles/import-master，v3.3.6）
// ---------------------------------------------------------------------------

/** 主档导入统计（dryRun 预览与提交共用） */
export interface PayrollMasterImportStats {
  total: number
  valid: number
  byVenue: Record<string, number>
  dualBase: string[]
  aliasRows: number
  moneyMissing: Record<string, number>
  blanks: Record<string, number>
}

export interface PayrollMasterImportResult {
  dryRun: boolean
  created?: number
  updated?: number
  total?: number
  stats: PayrollMasterImportStats
  problems?: string[]
}

/** 上传人员主档 xlsx（dryRun=true 只解析预览，不落库） */
export function importPayrollMaster(
  file: File,
  dryRun: boolean
): Promise<PayrollMasterImportResult> {
  const form = new FormData()
  form.append('file', file)
  if (dryRun) form.append('dryRun', '1')
  return apiPost<PayrollMasterImportResult>(
    '/payroll/profiles/import-master',
    form as unknown as Record<string, unknown>,
    120000
  )
}

/**
 * 确认导入（POST /payroll/performance/commit）。
 *
 * **必须重新上传文件**（后端刻意不复用临时文件，避免 TOCTOU），并带上 preview 的
 * `previewSha256` —— 两者不一致时后端回 409 `PREVIEW_STALE`，防止「预览看到的」和
 * 「写进去的」不是同一份文件。
 */
export function commitPayrollPerformance(
  file: File,
  venue: string,
  month: string,
  previewSha256: string,
  allowExceptions = false
): Promise<PayrollPerformancePayload> {
  const form = new FormData()
  form.append('file', file)
  form.append('venue', venue)
  form.append('month', month)
  form.append('previewSha256', previewSha256)
  form.append('allowExceptions', allowExceptions ? '1' : '0')
  return apiPost<PayrollPerformancePayload>(
    '/payroll/performance/commit',
    form as unknown as Record<string, unknown>,
    120000
  )
}

export interface PayrollPerformanceSummary {
  month: string
  venue: string | null
  imported: boolean
  sourceFileName: string
  sourceSha256: string
  importedAt: string | null
  allocationCount: number
  totals: PayrollPerformanceTotals
  byPerson: PayrollPerformancePerson[]
  venues: string[]
}

/** 已导入的业绩汇总（GET /payroll/performance） */
export function fetchPayrollPerformance(
  month: string,
  venue?: string | null
): Promise<PayrollPerformanceSummary> {
  return apiGet<PayrollPerformanceSummary>('/payroll/performance', {
    month,
    venue: venue ?? undefined
  })
}

// ---------------------------------------------------------------------------
// 月度输入：考勤 / 社保 / 个税（GET|PUT /payroll/monthly-inputs）
// ---------------------------------------------------------------------------

export interface PayrollMonthlyInputRow {
  userId: number | null
  profileId: number
  name: string
  /** 本行**实际生效的门店**（社保按门店扣），不是人员的所属门店 */
  venue: string
  homeVenue: string
  role: string
  attendanceDays: number | null
  personalLeaveHours: number | null
  sickLeaveHours: number | null
  /** 考勤未填 → 按全勤处理（请假扣款 0），前端要显示成「按全勤」而不是 0 */
  attendanceIsDefault: boolean
  /** 社保**生效值**。`inherit` 时是沿用来的金额，**不是** 0 */
  socialSecurity: number
  /** 本行显式存储的金额（`inherit`/`off` 时为 null）。用于区分「显式 0」与「未操作」 */
  socialSecurityRaw: number | null
  socialSecurityMode: PayrollSocialMode
  /** 仅 `inherit` 且命中历史时返回，用于显示「沿用 2026-07 的 ¥557.76」 */
  socialSecurityInheritedFrom: string | null
  /** 后端给的中文标签，前端优先用它，避免自己拼措辞 */
  socialSecurityLabel: string
  tax: number
  taxIsDefault: boolean
  subsidy: number
  previousAdjustment: number
  otherDeduction: number
  fixedSalaryOverride: number | null
  baseSalaryZeroed: boolean
  storeCommissionAddon: number
  note: string
  updatedBy: number | null
}

export interface PayrollMonthlyInputsResult {
  month: string
  venue: string | null
  rows: PayrollMonthlyInputRow[]
  /** PUT 时回显被改动的人名 */
  updated?: string[]
  /** copy-from-previous 时回显来源月与条数 */
  copiedFrom?: string
  copied?: number
}

export function fetchPayrollMonthlyInputs(
  month: string,
  venue?: string | null
): Promise<PayrollMonthlyInputsResult> {
  return apiGet<PayrollMonthlyInputsResult>('/payroll/monthly-inputs', {
    month,
    venue: venue ?? undefined
  })
}

/** 单行提交体。`socialSecurityMode='set'` 时**必须**带 `socialSecurity`（显式 0 也要传） */
export interface PayrollMonthlyInputUpdateRow {
  profileId: number
  attendanceDays?: number | null
  personalLeaveHours?: number | null
  sickLeaveHours?: number | null
  socialSecurity?: number
  socialSecurityMode: PayrollSocialMode
  tax?: number | null
  subsidy?: number | null
  previousAdjustment?: number | null
  otherDeduction?: number | null
  fixedSalaryOverride?: number | null
  baseSalaryZeroed?: boolean
  storeCommissionAddon?: number | null
  note?: string
}

/** 批量 upsert。响应回显**最终生效值**（含 inherit 解析结果），不能只信请求体。 */
export function savePayrollMonthlyInputs(
  month: string,
  venue: string | null,
  rows: PayrollMonthlyInputUpdateRow[]
): Promise<PayrollMonthlyInputsResult> {
  return apiPut<PayrollMonthlyInputsResult>('/payroll/monthly-inputs', {
    month,
    venue: venue ?? undefined,
    rows: rows as unknown as Record<string, unknown>[]
  })
}

export type PayrollCopyField =
  | 'socialSecurity'
  | 'attendanceDays'
  | 'personalLeaveHours'
  | 'sickLeaveHours'
  | 'tax'
  | 'subsidy'

/** 把上月**显式设置**的值复制为本月 `mode='set'` 记录（一键「跟上月一样」） */
export function copyPayrollMonthlyInputsFromPrevious(
  month: string,
  venue: string | null,
  fields: PayrollCopyField[]
): Promise<PayrollMonthlyInputsResult> {
  return apiPost<PayrollMonthlyInputsResult>('/payroll/monthly-inputs/copy-from-previous', {
    month,
    venue: venue ?? undefined,
    fields
  })
}

// ---------------------------------------------------------------------------
// 薪酬计算（GET /payroll/calculate）
// ---------------------------------------------------------------------------

export interface PayrollCalcRow {
  userId: number | null
  profileId: number
  name: string
  /** 展示层岗位（馆主→管理层、跨店→{门店}全职老师） */
  displayRole: string
  role: string
  venue: string
  baseSalary: number
  performance: number
  baseReward: number
  accumulatedValidHours: number
  hours: {
    private60: number
    private45: number
    small: number
    group: number
    enterprise: number
  }
  totalHours: number
  baseHourlyFee: number
  feePrivate60: number
  feePrivate45: number
  /** `profile` = 档案独立配置；`derived_60x0.75` = 档案配 0 时的折算值 */
  feePrivate45Source: 'profile' | 'derived_60x0.75'
  feePrivate45Derived: boolean
  baseHourlyPrivate60: number
  baseHourlyPrivate45: number
  /** 当月加价/节（按两店累计业绩取档 × 活动月门槛倍数） */
  hourlyIncentiveAddOn: number
  hourlyIncentive: number
  hourlyIncentivePrivate60: number
  hourlyIncentivePrivate45: number
  hourlyFee: number
  personalPerformance: number
  commissionRate: number
  commission: number
  storeCommissionRate: number
  storeCommission: number
  subsidy: number
  previousAdjustment: number
  leaveDeduction: number
  otherDeduction: number
  gross: number
  socialSecurity: number
  socialSecurityMode: PayrollSocialMode
  socialSecurityInheritedFrom: string | null
  tax: number
  net: number
  /** 哪些项是**真实输入**（false = 默认值，必须显式告知） */
  inputsReal: {
    attendance: boolean
    socialSecurity: boolean
    tax: boolean
    performance: boolean
  }
}

export interface PayrollCalcWarning {
  code: string
  count?: number
  message: string
  level?: string
  names?: string[]
  from?: string
  details?: { name: string; from: string }[]
  affectedUsers?: PayrollHoursWarning['affectedUsers']
}

/** 本次**无法计算**的项目及原因。系统内确实没有这些数据，**不得显示为 0 或省略** */
export interface PayrollCalcUnavailable {
  item: string
  reason: string
}

export interface PayrollCalcResult {
  month: string
  venue: string | null
  /** `waiting_tax` 待财务个税 / `waiting_confirm` 待确认 */
  stage: string
  activityType: string
  activityThresholdMultiplier: number
  storeSales: number
  storeSalesRaw: number
  storeSalesSource: string
  rows: PayrollCalcRow[]
  storeTotal: {
    headcount: number
    hours: number
    gross: number
    socialSecurity: number
    tax: number
    net: number
  }
  warnings: PayrollCalcWarning[]
  unavailable: PayrollCalcUnavailable[]
  /** 非空时该店结果**不可用于交付** */
  blocked: { code: string; message: string }[]
  /**
   * 待完善（未参与计算）的人员姓名。
   *
   * 这些人的档案尚未确认身份标签，**整行未参与本次计算** —— 界面必须显式提示，
   * 否则用户会以为工资已经算全（实际少人）。同时 `unavailable` 里也有一条说明。
   */
  pendingNames?: string[]
}

export function fetchPayrollCalculate(
  month: string,
  venue?: string | null
): Promise<PayrollCalcResult> {
  return apiGet<PayrollCalcResult>('/payroll/calculate', {
    month,
    venue: venue ?? undefined
  })
}

// ---------------------------------------------------------------------------
// 错误处理
// ---------------------------------------------------------------------------

/**
 * 取后端错误码（规格 §7.8）。
 *
 * axios 拦截器只归一化了 403 的 message，`code` 要自己从 `error.response.data` 读。
 * 返回 `''` 表示没有结构化错误码（网络错误 / 500 等）。
 */
export function payrollErrorCode(error: unknown): string {
  const code = (error as { response?: { data?: { code?: unknown } } })?.response?.data?.code

  return typeof code === 'string' ? code : ''
}

/** 错误码 → 使用者能看懂的中文说明（后端 message 已很具体，这里只补可操作建议） */
export const PAYROLL_ERROR_HINTS: Record<string, string> = {
  PREVIEW_STALE: '文件与预览时不是同一份（可能已被替换），请重新选择文件再预览一次',
  INVALID_MONTH: '月份格式应为 YYYY-MM',
  INVALID_VENUE: '门店只能是「东部店」或「绿地店」',
  INVALID_FILE: '只支持 .xlsx，且需含「明细总表」与「金额」列',
  PARTTIME_FIXED_SALARY: '兼职老师只按课时计费，底薪与绩效必须为 0',
  DUAL_BASE_NOT_ALLOWED: '双底薪仅限例外名单内的人员（蒙澍南 / 谭婷婷 / 张卫玉）',
  ROLE_NOT_ALLOWED: '身份标签不在枚举内，请从下拉里选',
  SOCIAL_SECURITY_MODE_INVALID: '社保为「本月已设」时必须填金额（显式 0 也要填）',
  AMBIGUOUS_NAME: '该别名已属于其他人员，一个名字只能对一个人',
  COMMIT_HAS_EXCEPTIONS: '本次解析存在异常行，默认拒绝写入；请先核对异常清单',
  PROFILE_NOT_FOUND: '找不到该薪酬档案，请刷新列表',
  ACTIVITY_RULE_UNCONFIRMED: '当月活动月激励规则未确认',
  ALLOCATION_MISMATCH: '业绩行分配不平（金额 ≠ Σ销售员 + 会馆）'
}

/** 统一错误文案：优先用后端 message，取不到再退回通用文案 */
export function payrollErrorMessage(error: unknown, fallback = '操作失败'): string {
  const msg = (error as { message?: unknown })?.message

  return typeof msg === 'string' && msg.trim() !== '' ? msg : fallback
}
