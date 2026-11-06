<script setup lang="ts">
/**
 * 留资 新增 / 编辑（移动端）
 *
 * 新增：POST /leads（name / venue / source 必填；status 缺省后端补「新留资」）
 * 编辑：PATCH /leads/{id} —— **只提交本次改动的字段**
 *
 * 为什么编辑只提交改动：
 * ① 后端 update 对 source/orderPlatform 的语义是「改动才校验」——原样带回的
 *    历史值放行，改动必须落在枚举内。少提交就不存在把历史值改坏的机会。
 * ② web 端编辑弹窗提交整个表单，是它自己的历史包袱；小程序没有必须照抄的理由。
 *
 * 编辑态数据来源：后端没有 GET /leads/{id}，列表页把整行放进本地草稿
 * （stageLeadDraft / takeLeadDraft，键 yimai-lead-draft），用完即删。
 * 拿不到 origin 时**禁止提交**：没有基线就只能整表覆盖，成交金额/体验课卡片会被清空。
 *
 * 另一份「写了一半」的草稿：yimai-lead-form-draft-<mode>-<id|new>（F04）。
 * 它和上面那份列表行草稿是两件事——列表行草稿负责 diff 基线，这份负责保命：
 * 401 被踢走的是 reLaunch（http.ts），页面栈全销毁，没草稿就等于白填。
 *
 * 视觉层（2026-11 重构）：分组 ym-section + wd-input / wd-textarea / wd-picker，
 * 底部固定提交条；字段白名单、diff 提交、查重软提示全部原样保留。
 */
import { computed, ref, watch } from 'vue'
import { onLoad, onShow, onUnload } from '@dcloudio/uni-app'
import { requireLogin } from '@/utils/guard'
import { requireFeature } from '@/utils/nav'
// 长表单草稿：只用现成的 getJSON / setJSON / storage（storage.ts 已导出，不新增工具函数）
import { getJSON, setJSON, storage } from '@/utils/storage'
import { useUserStore } from '@/store/user'
import {
  FALLBACK_LEAD_ENUMS,
  LEAD_STATUSES,
  LEAD_TRIAL_TYPES,
  checkLead,
  createLead,
  fetchLeads,
  takeLeadDraft,
  updateLead,
  type LeadPayload
} from '@/api/leads'
import { fetchStaffOptions, matchStaff, type StaffOption } from '@/api/staff'
import { ROLE_SHORT, type RoleCode } from '@/utils/permission'
import type { MiniappLead } from '@/types'

const user = useUserStore()
const VENUES = ['绿地店', '东部店']
/**
 * 体验课类型下拉的选项：首项「未设置」= 提交空串（历史默认值），其余与网页端逐字一致。
 * 为什么不叫「等级」：`leads.grade` 这一列网页端早就改成体验课类型了，小程序按「客户分级」
 * 渲染会凭空多出「级」字、picker 也对不上旧值（详见 api/leads.ts 的 LEAD_TRIAL_TYPES 注释）。
 */
const TRIAL_TYPE_UNSET = '未设置'
const TRIAL_TYPE_OPTIONS: string[] = [TRIAL_TYPE_UNSET, ...LEAD_TRIAL_TYPES]
/** wd-picker 的 columns 需要可变数组 */
const STATUS_OPTIONS: string[] = [...LEAD_STATUSES]

const mode = ref<'create' | 'edit'>('create')
const id = ref(0)
const origin = ref<MiniappLead | null>(null)
/**
 * origin 卡片的基线签名（见 cardSignature）。
 * 编辑态只靠它判断「体验课卡片到底有没有被改过」——卡片是数组，直接比对象会被键顺序绊住。
 */
const originCardSig = ref('')
const saving = ref(false)
const dupTip = ref('')
const inited = ref(false)
/** onLoad 的原始参数（等权限通过后再消费） */
const opts = ref<Record<string, string> | null>(null)

const sources = ref<string[]>([...FALLBACK_LEAD_ENUMS.sources])
const platforms = ref<string[]>([...FALLBACK_LEAD_ENUMS.orderPlatforms])

/** 逐节体验课卡片（与网页端同一结构：后端 trial_cards JSON 透传） */
type TrialCardDraft = {
  session: number
  date: string
  time: string
  topic: string
  teacher: string
  couponName: string
  voucherCode: string
  redeem: string
  total: string
  attended: boolean
  noShow: boolean
  cancelled: boolean
  /**
   * 后端原始卡片（回填时整块留存）。
   * 本页只编辑上面这些字段，而后端 trial_cards 是**整体替换**语义：提交时不把
   * 网页端写入的 platform / remaining 等附加字段原样带回，等于把它们抹掉。
   * 提交映射时把它摊在最前面，让本页编辑过的字段覆盖掉旧值。
   */
  raw?: Record<string, unknown>
}

const form = ref({
  trialCards: [] as TrialCardDraft[],
  name: '',
  phone: '',
  wechat: '',
  venue: (user.venue as string) || '绿地店',
  source: '',
  orderPlatform: '',
  demand: '',
  serviceTeacher: '',
  status: '新留资',
  grade: '',
  dealCard: '',
  dealAmount: '',
  // 平台核销券码：券名 / 券码 / 核销金额（逐节券码由后端 trial_cards 维护，这里录整单）
  couponName: '',
  voucherCode: '',
  redeemAmount: '',
  remark: ''
})

/** 表单快照类型：草稿读写与恢复共用同一份结构，避免两处各写一遍字段表 */
type LeadFormState = typeof form.value

/**
 * 历史脏值：A/B/C 时代写进 `grade` 的那些行。
 * 不静默丢掉 —— 下拉里追加一项「A（历史值）」，用户能看到它、也能改成新选项；
 * 不主动改就保持原值提交，避免一次编辑把老数据洗成空。
 */
const legacyTrialType = computed(() => {
  const v = form.value.grade
  return v && !(LEAD_TRIAL_TYPES as readonly string[]).includes(v) ? v : ''
})

/** picker 的候选：常规选项 + （必要时）历史值那一项 */
const trialTypeOptions = computed(() =>
  legacyTrialType.value ? [...TRIAL_TYPE_OPTIONS, `${legacyTrialType.value}（历史值）`] : TRIAL_TYPE_OPTIONS
)

/** picker 的显示值：'' → 未设置；新取值原样显示；历史值 → 「A（历史值）」 */
const trialTypeLabel = computed(() => {
  const v = form.value.grade
  if (!v) return TRIAL_TYPE_UNSET
  return (LEAD_TRIAL_TYPES as readonly string[]).includes(v) ? v : `${v}（历史值）`
})

/* ---------- 老师/顾问：选人（/staff/options）+ 手输兜底 ---------- */
const staffSheet = ref(false)
const staffList = ref<StaffOption[]>([])
const staffLoading = ref(false)
/** 人员清单请求是否成功返回过（成功=可以判定「这个名字不在账号里」） */
const staffFetched = ref(false)
/** 清单里是否真的有人（决定面板渲染列表还是空提示） */
const staffLoaded = computed(() => staffList.value.length > 0)
const staffKeyword = ref('')
const manualTeacher = ref('')

const staffFiltered = computed(() => matchStaff(staffList.value, staffKeyword.value))

/**
 * 当前值是不是「不在账号清单里」的历史写法。
 * 只有清单加载成功后才敢判断 —— 接口失败时一律当真（不能把历史名字说成不存在）。
 */
const teacherHistorical = computed(
  () =>
    staffFetched.value &&
    !!form.value.serviceTeacher &&
    !staffList.value.some((s) => s.name === form.value.serviceTeacher)
)

function staffMeta(s: StaffOption): string {
  const roles = s.roles
    .map((r) => ROLE_SHORT[r as RoleCode] ?? r)
    .filter(Boolean)
    .join('/')
  return [roles, s.venue || ''].filter(Boolean).join(' · ')
}

/** 打开面板：首次打开才拉清单（一次会话内不重复打接口） */
function openStaffPicker(): void {
  staffSheet.value = true
  staffKeyword.value = ''
  manualTeacher.value = ''
  void loadStaff()
}

function closeStaffPicker(): void {
  staffSheet.value = false
}

async function loadStaff(): Promise<void> {
  if (staffFetched.value || staffLoading.value) return
  staffLoading.value = true
  try {
    staffList.value = await fetchStaffOptions()
    staffFetched.value = true
  } catch {
    /* 静默：接口不可用就只剩手输，不阻塞录入 */
  } finally {
    staffLoading.value = false
  }
}

/** 选中账号 → 写入**规范名**（后端靠它解析归属 user_id） */
function pickStaff(s: StaffOption): void {
  form.value.serviceTeacher = s.name
  staffSheet.value = false
}

/** 手输兜底：历史写法 / 临时名字 / 接口挂了都能录 */
function applyManual(): void {
  const v = manualTeacher.value.trim()
  if (!v) {
    uni.showToast({ title: '请先输入名字', icon: 'none' })
    return
  }
  form.value.serviceTeacher = v
  staffSheet.value = false
}

function clearTeacher(): void {
  form.value.serviceTeacher = ''
  manualTeacher.value = ''
  staffSheet.value = false
}

/** 首屏把渠道清单补全（列表接口随响应下发 enums，是唯一权威来源） */
async function loadEnums() {
  try {
    const res = await fetchLeads({ current: 1, size: 1 })
    if (res.enums?.sources?.length) sources.value = res.enums.sources
    if (res.enums?.orderPlatforms?.length) platforms.value = res.enums.orderPlatforms
  } catch {
    /* 兜底清单已在，加载失败不阻塞录入 */
  }
}

function hydrate(lead: MiniappLead) {
  form.value = {
    name: lead.name ?? '',
    phone: lead.phone ?? '',
    wechat: lead.wechat ?? '',
    venue: lead.venue || '绿地店',
    source: lead.source || '',
    orderPlatform: lead.orderPlatform || '',
    demand: lead.demand || '',
    serviceTeacher: lead.serviceTeacher || '',
    status: lead.status || '新留资',
    grade: lead.grade || '',
    dealCard: lead.dealCard || '',
    dealAmount: lead.dealAmount ? String(lead.dealAmount) : '',
    couponName: lead.couponName || '',
    voucherCode: lead.voucherCode || '',
    redeemAmount:
      lead.redeemAmount !== null && lead.redeemAmount !== undefined ? String(lead.redeemAmount) : '',
    remark: (lead.remark as string) || '',
    trialCards: (lead.trialCards ?? []).map((c, i) => {
      const raw = String(c.time || '')
      // 后端 time 常是 "2026-11-03 19:20"（日期+时间），拆成两个 picker 的初值
      const m = raw.match(/^(\d{4}-\d{2}-\d{2})[ T]?(\d{2}:\d{2})?/)
      return {
        session: c.session ?? i + 1,
        date: m?.[1] || String(c.date || ''),
        time: m?.[2] || '',
        topic: c.topic || '',
        teacher: c.teacher || '',
        couponName: c.couponName || '',
        voucherCode: c.voucherCode || '',
        redeem: c.redeem !== null && c.redeem !== undefined ? String(c.redeem) : '',
        total: c.total !== null && c.total !== undefined ? String(c.total) : '',
        attended: Boolean(c.attended),
        noShow: Boolean(c.noShow),
        cancelled: Boolean(c.cancelled),
        // 整块留存原始卡片：本页不编辑的 platform / remaining 等字段靠它原样带回
        raw: { ...c }
      }
    })
  }
  // 基线签名要在回填完成时取：之后 buildPatch 拿「当前卡片」与它比较，判断卡片有没有被改过
  originCardSig.value = cardSignature(form.value.trialCards)
}

type PickerPayload = { value: string | number | string[] | number[] }

function onPickVenue(e: PickerPayload) {
  form.value.venue = String(e.value)
}

function onPickSource(e: PickerPayload) {
  form.value.source = String(e.value)
}

function onPickPlatform(e: PickerPayload) {
  form.value.orderPlatform = String(e.value)
}

/* ---------- 体验课卡片（上课记录 / 券码 / 核销） ----------
 * 网页端留资编辑弹窗里是「逐节卡片」，手机端此前只能看不能录。
 * 这里把同一份 trial_cards 结构搬过来：日期/时间/课程/老师/券名/券码/总次数/核销金额/状态。
 * 提交时 session 按数组顺序重排，后端 withStaffIds 会把第一节老师镜像到 trial_teacher。
 */
const CARD_STATES = ['未开始', '已上课', '已爽约', '已取消']

function cardStateIndex(c: TrialCardDraft): number {
  if (c.noShow) return 2
  if (c.attended) return 1
  if (c.cancelled) return 3
  return 0
}

function cardStateLabel(c: TrialCardDraft): string {
  return CARD_STATES[cardStateIndex(c)]
}

function onCardState(i: number, e: PickerPayload): void {
  const v = Number(e.value)
  const c = form.value.trialCards[i]
  if (!c) return
  c.attended = v === 1
  c.noShow = v === 2
  c.cancelled = v === 3
}

function onCardDate(i: number, e: PickerPayload): void {
  const c = form.value.trialCards[i]
  if (c) c.date = String(e.value)
}

function onCardTime(i: number, e: PickerPayload): void {
  const c = form.value.trialCards[i]
  if (c) c.time = String(e.value)
}

function addCard(): void {
  const today = new Date()
  const p = (n: number) => String(n).padStart(2, '0')
  form.value.trialCards.push({
    session: form.value.trialCards.length + 1,
    date: `${today.getFullYear()}-${p(today.getMonth() + 1)}-${p(today.getDate())}`,
    time: '',
    topic: '',
    teacher: form.value.serviceTeacher || '',
    couponName: '',
    voucherCode: '',
    redeem: '',
    total: '',
    attended: false,
    noShow: false,
    cancelled: false
  })
}

function removeCard(i: number): void {
  form.value.trialCards.splice(i, 1)
}

/** 单节卡片提交后的形状（与 LeadPayload.trialCards 的元素同型） */
type TrialCardPayload = NonNullable<LeadPayload['trialCards']>[number]

/**
 * UI 卡片 → 提交结构。
 * 新建体与编辑体的 trialCards 都走这一份映射：后端 trial_cards 是**整体替换**，
 * 两处各写一遍必然漂移（新建漏了 session 重排、编辑忘了拼 time 之类都属于静默丢数据）。
 */
function toTrialPayload(cards: TrialCardDraft[]): TrialCardPayload[] {
  return cards.map((c, i) => ({
    // 原始附加字段先摊开，下面本页编辑过的字段随后覆盖：既保住 platform / remaining，
    // 又不会让旧值盖掉用户这次改的内容
    ...(c.raw ?? {}),
    session: i + 1,
    date: c.date || undefined,
    time: [c.date, c.time].filter(Boolean).join(' '),
    topic: c.topic.trim(),
    teacher: c.teacher.trim(),
    couponName: c.couponName.trim(),
    voucherCode: c.voucherCode.trim(),
    redeem: c.redeem === '' ? null : Number(c.redeem),
    total: c.total === '' ? null : Number(c.total),
    attended: c.attended,
    noShow: c.noShow,
    cancelled: c.cancelled
  }))
}

/**
 * 卡片签名：只用于「有没有改过」的判断。
 * 直接 JSON.stringify 两边对象会被键顺序影响（回填体与新建体的字面量顺序不同），
 * 所以统一先过一遍 toTrialPayload，再比字符串。
 */
function cardSignature(cards: TrialCardDraft[]): string {
  return JSON.stringify(toTrialPayload(cards))
}

function onPickStatus(e: PickerPayload) {
  form.value.status = String(e.value)
}

/**
 * 选中体验课类型。
 * 「XX（历史值）」只是回显用的占位，**不能写回库里**（否则会存出「A（历史值）」这种新脏值），
 * 命中它就还原成原来的裸值。
 */
function onPickTrialType(e: PickerPayload) {
  const v = String(e.value)
  if (v === TRIAL_TYPE_UNSET) {
    form.value.grade = ''
    return
  }
  const legacy = `${legacyTrialType.value}（历史值）`
  form.value.grade = legacyTrialType.value && v === legacy ? legacyTrialType.value : v
}

/** 手机号查重：命中会员或已有留资时给出**事实**，不阻断录入 */
async function checkDup() {
  const phone = form.value.phone.trim()
  dupTip.value = ''
  if (phone.length < 4) return
  try {
    const res = await checkLead(phone)
    if (!res?.exists) return
    const lines = (res.matches ?? []).slice(0, 3).map((m) => `${m.kind}：${m.name}（${m.detail}）`)
    dupTip.value = lines.join('；')
  } catch {
    /* 查重失败不阻塞 */
  }
}

function validate(): string {
  if (!form.value.name.trim()) return '请填写客户姓名'
  if (!form.value.venue) return '请选择门店'
  if (!form.value.source) return '请选择来源渠道'
  return ''
}

function buildCreatePayload(): LeadPayload {
  const f = form.value
  return {
    name: f.name.trim(),
    venue: f.venue,
    source: f.source,
    phone: f.phone.trim() || undefined,
    wechat: f.wechat.trim() || undefined,
    demand: f.demand.trim() || undefined,
    orderPlatform: f.orderPlatform || undefined,
    serviceTeacher: f.serviceTeacher.trim() || undefined,
    status: f.status,
    grade: f.grade || undefined,
    dealCard: f.dealCard.trim() || undefined,
    dealAmount: f.dealAmount === '' ? null : Number(f.dealAmount),
    couponName: f.couponName.trim() || undefined,
    voucherCode: f.voucherCode.trim() || undefined,
    redeemAmount: f.redeemAmount === '' ? null : Number(f.redeemAmount),
    // 与编辑态共用同一份卡片映射（含 session 重排、time 归一、raw 附加字段带回）
    trialCards: toTrialPayload(f.trialCards),
    remark: f.remark.trim() || undefined
  }
}

/**
 * 只把真正变化的字段放进 PATCH。
 *
 * 返回 null = 编辑态没拿到原始行（列表草稿丢了 / 直接进页面）：调用方必须中止。
 * 这里**不能**再退化成 buildCreatePayload()：新建体会把 deal_amount / redeem_amount
 * 置 null、trial_cards 清空，一条已成交客资会被洗成空白。
 */
function buildPatch(): Partial<LeadPayload> | null {
  const f = form.value
  const o = origin.value
  if (!o) return null
  const patch: Partial<LeadPayload> = {}
  const cmp = (a: unknown, b: unknown) => (a ?? '') !== (b ?? '')
  if (cmp(f.name.trim(), o.name)) patch.name = f.name.trim()
  if (cmp(f.phone.trim(), o.phone)) patch.phone = f.phone.trim()
  if (cmp(f.wechat.trim(), o.wechat)) patch.wechat = f.wechat.trim()
  if (cmp(f.venue, o.venue)) patch.venue = f.venue
  if (cmp(f.source, o.source)) patch.source = f.source
  if (cmp(f.orderPlatform, o.orderPlatform)) patch.orderPlatform = f.orderPlatform
  if (cmp(f.demand.trim(), o.demand)) patch.demand = f.demand.trim()
  if (cmp(f.serviceTeacher.trim(), o.serviceTeacher)) patch.serviceTeacher = f.serviceTeacher.trim()
  if (cmp(f.status, o.status)) patch.status = f.status
  if (cmp(f.grade, o.grade)) patch.grade = f.grade
  if (cmp(f.dealCard.trim(), o.dealCard)) patch.dealCard = f.dealCard.trim()
  const oAmount = o.dealAmount === null || o.dealAmount === undefined ? '' : String(o.dealAmount)
  if (cmp(f.dealAmount, oAmount)) patch.dealAmount = f.dealAmount === '' ? null : Number(f.dealAmount)
  if (cmp(f.couponName.trim(), o.couponName)) patch.couponName = f.couponName.trim()
  if (cmp(f.voucherCode.trim(), o.voucherCode)) patch.voucherCode = f.voucherCode.trim()
  const oRedeem =
    o.redeemAmount === null || o.redeemAmount === undefined ? '' : String(o.redeemAmount)
  if (cmp(f.redeemAmount, oRedeem)) {
    patch.redeemAmount = f.redeemAmount === '' ? null : Number(f.redeemAmount)
  }
  if (cmp(f.remark.trim(), o.remark)) patch.remark = f.remark.trim()
  // 体验课卡片：后端 trial_cards 是**整体替换**，所以不比单节、只比整段签名 ——
  // 有差异就整段提交，没差异就不带这个键（不带才不会覆盖原值）。
  // 之前这里缺了 trialCards：只改卡片 → patch 为空 → 误报「没有改动」；
  // 连带改别的字段 → 提示「已保存」，但卡片还是旧的（最恶劣的假成功）。
  if (cardSignature(f.trialCards) !== originCardSig.value) {
    patch.trialCards = toTrialPayload(f.trialCards)
  }
  return patch
}

async function submit() {
  if (saving.value) return
  const err = validate()
  if (err) {
    uni.showToast({ title: err, icon: 'none' })
    return
  }
  // 编辑态必须先有原始行：没有基线就无从「只提交改动」，宁可让用户回列表重进，
  // 也不拿一份空表单去覆盖已有的成交/卡片数据
  if (mode.value === 'edit' && id.value && !origin.value) {
    uni.showModal({
      title: '没有原始数据',
      content: '未取到原始数据，请从列表重新进入',
      showCancel: false
    })
    return
  }
  saving.value = true
  try {
    if (mode.value === 'edit' && id.value) {
      const patch = buildPatch()
      // 上面已经拦过 origin 缺失；这里只是类型收窄的兜底，正常不会走到
      if (patch === null) {
        uni.showToast({ title: '未取到原始数据，请从列表重新进入', icon: 'none' })
        return
      }
      if (!Object.keys(patch).length) {
        uni.showToast({ title: '没有改动', icon: 'none' })
        saving.value = false
        return
      }
      const res = await updateLead(id.value, patch)
      // 提交成功即收工：草稿不清的话，下次进这条客资还会被问「要不要继续上次填写」
      clearFormDraft()
      if (res?.warnings?.length) {
        uni.showModal({
          title: '已保存（含提示）',
          content: res.warnings.map((w) => w.message).join('\n'),
          showCancel: false
        })
      } else {
        uni.showToast({ title: '已保存', icon: 'success' })
      }
    } else {
      const res = await createLead(buildCreatePayload())
      clearFormDraft()
      if (res?.warnings?.length) {
        uni.showModal({
          title: '已录入（含提示）',
          content: res.warnings.map((w) => w.message).join('\n'),
          showCancel: false,
          success: () => uni.navigateBack()
        })
        return
      }
      uni.showToast({ title: '已录入', icon: 'success' })
    }
    setTimeout(() => uni.navigateBack(), 500)
  } catch {
    /* http 层已 toast（422 的枚举错误文案会带允许值） */
  } finally {
    saving.value = false
  }
}

/* ---------- 长表单草稿（F04） ----------
 * 这张表单 17 个字段 + 任意多节体验课卡片，而编辑态的数据来源是「列表行一次性塞进
 * 本地草稿」（takeLeadDraft 读后即删）。一旦 401 被踢，http 层走的是 reLaunch，
 * 整条页面栈销毁 —— 不落草稿，用户刚录的一整页就没了。
 */
const DRAFT_PREFIX = 'yimai-lead-form-draft'
/** 节流窗口：输入过程中不要每敲一个字都写一次 storage */
const DRAFT_THROTTLE_MS = 5000
/** 草稿版本：结构改了就 +1，旧版本草稿直接丢弃（比逐字段校验便宜且不会漏） */
const DRAFT_VERSION = 1

/** 草稿体：整份表单快照 + 保存时刻（后者只用于恢复提示里给个「多久以前」） */
type FormDraft = {
  v: number
  form: LeadFormState
  savedAt: number
}

/** 按「模式 + id」隔离：新增录入与不同客资的草稿互不覆盖 */
function draftKey(): string {
  const tail = mode.value === 'edit' && id.value ? String(id.value) : 'new'
  return `${DRAFT_PREFIX}-${mode.value}-${tail}`
}

/**
 * 草稿是否可写。回填 / 恢复完成前必须关着：否则 onShow 一进来就按默认空表单
 * 定时写一次，把真正的草稿盖掉。
 */
let draftArmed = false
let draftTimer: ReturnType<typeof setTimeout> | null = null

/**
 * 表单签名：固定顺序取值，避开「逐字段比较要写十几行」和「对象键顺序不同被误判成有改动」。
 * 卡片走 cardSignature（与提交结构同一口径）。
 */
function formSignature(f: LeadFormState): string {
  return JSON.stringify([
    f.name.trim(),
    f.phone.trim(),
    f.wechat.trim(),
    f.venue,
    f.source,
    f.orderPlatform,
    f.demand.trim(),
    f.serviceTeacher.trim(),
    f.status,
    f.grade,
    f.dealCard.trim(),
    f.dealAmount,
    f.couponName.trim(),
    f.voucherCode.trim(),
    f.redeemAmount,
    f.remark.trim(),
    cardSignature(f.trialCards)
  ])
}

/** 同步落一次草稿（setStorageSync 是同步的，onUnload 里也来得及） */
function saveFormDraft(): void {
  if (!draftArmed) return
  setJSON(draftKey(), { v: DRAFT_VERSION, form: form.value, savedAt: Date.now() })
}

/** 节流存：改动后 5s 落一次；真正的兜底是 onUnload 的补存 */
function scheduleDraftSave(): void {
  if (!draftArmed || draftTimer) return
  draftTimer = setTimeout(() => {
    draftTimer = null
    saveFormDraft()
  }, DRAFT_THROTTLE_MS)
}

function removeFormDraft(): void {
  if (draftTimer) {
    clearTimeout(draftTimer)
    draftTimer = null
  }
  storage.removeItem(draftKey())
}

/** 提交成功：草稿使命结束，同时关掉写入口（否则 navigateBack 触发的 onUnload 会把它写回来） */
function clearFormDraft(): void {
  draftArmed = false
  removeFormDraft()
}

/** 把草稿盖回表单；以当前表单为底合并，跨版本残留的缺字段草稿会走默认值 */
function applyFormDraft(snap: LeadFormState): void {
  form.value = {
    ...form.value,
    ...snap,
    // 三态布尔在存储里可能被写成别的形态：恢复时统一归一，避免出现两个状态同时为真
    trialCards: snap.trialCards.map((c, i) => ({
      ...c,
      session: i + 1,
      attended: Boolean(c.attended),
      noShow: Boolean(c.noShow),
      cancelled: Boolean(c.cancelled)
    }))
  }
}

/** 恢复提示里的时间戳（只到分钟，够判断「多久以前」） */
function fmtSavedAt(ts: number): string {
  const d = new Date(ts)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}

/**
 * 进页面后问一次：继续上次填写 / 重新开始。
 * 判断口径是 formSignature —— 与当前（origin 回填后的）表单一致，就说明上次其实
 * 没改出东西，直接丢掉，不打扰用户。
 */
function maybeRestoreDraft(): void {
  // 草稿是锦上添花：存储里躺着结构异常的数据时清掉它即可，
  // 绝不让一条坏草稿把整个表单页的初始化搞崩
  try {
    restoreDraft()
  } catch {
    removeFormDraft()
    draftArmed = true
  }
}

function restoreDraft(): void {
  const saved = getJSON<FormDraft>(draftKey())
  if (!saved || saved.v !== DRAFT_VERSION || !saved.form || !Array.isArray(saved.form.trialCards)) {
    // 结构不认识的旧草稿直接清掉，别让它下次进来继续弹
    if (saved) removeFormDraft()
    draftArmed = true
    return
  }
  const snap = saved.form
  if (formSignature(snap) === formSignature(form.value)) {
    removeFormDraft()
    draftArmed = true
    return
  }
  const when = Number.isFinite(saved.savedAt) ? `（${fmtSavedAt(saved.savedAt)}）` : ''
  uni.showModal({
    title: '发现未提交的草稿',
    content: `上次填写的内容还没提交${when}，要继续填写吗？`,
    confirmText: '继续填写',
    cancelText: '重新开始',
    confirmColor: '#2E7D5B',
    success: (r) => {
      if (r.confirm) applyFormDraft(snap)
      else removeFormDraft()
      // 无论选哪边，从此页面上的改动才开始落草稿
      draftArmed = true
    }
  })
}

// 任何字段（含卡片增删改）变动 → 节流落草稿
watch(form, scheduleDraftSave, { deep: true })

onLoad((options) => {
  opts.value = (options ?? {}) as Record<string, string>
})

/** 误触返回 / 401 被 reLaunch 踢出：节流定时器多半还没到点，这里同步补存一次 */
onUnload(() => {
  saveFormDraft()
  if (draftTimer) {
    clearTimeout(draftTimer)
    draftTimer = null
  }
})

/**
 * 编辑态回填：只在权限通过后执行一次。
 * 本地草稿 takeLeadDraft() 是「读后即删」的，不能在校验之前消费掉。
 */
function applyDraft() {
  const opt = opts.value
  if (!opt || opt.mode !== 'edit' || !opt.id) return
  mode.value = 'edit'
  id.value = Number(opt.id)
  const draft = takeLeadDraft()
  if (draft) {
    origin.value = draft
    hydrate(draft)
  }
}

// 权限：先登录，再校验「留资管理」功能权限；首个 show 才回填草稿 + 补渠道清单
onShow(() => {
  if (!requireLogin()) return
  if (!requireFeature('leads')) return
  if (!inited.value) {
    inited.value = true
    applyDraft()
    // 草稿键依赖 mode / id（由 applyDraft 决定），判断「草稿 vs 当前表单」又要等回填完成，
    // 所以恢复只能排在这之后
    maybeRestoreDraft()
    loadEnums()
    // 顺手预取人员清单：点开选人面板时通常已经加载好（失败也不影响录入）
    void loadStaff()
  }
})
</script>

<template>
  <view class="ym-page lead-form">
    <view class="fhead">
      <text class="fhead__title">{{ mode === 'edit' ? '编辑留资' : '新增留资' }}</text>
      <text class="fhead__desc">
        {{ mode === 'edit' ? '只提交你改动过的字段，未动的保持原值' : '带 * 为必填，手机号可查重（只提示不拦截）' }}
      </text>
    </view>

    <ym-section title="客户信息">
      <wd-input
        v-model="form.name"
        label="姓名"
        required
        placeholder="客户姓名"
        label-width="200rpx"
      />
      <wd-input
        v-model="form.phone"
        label="手机号"
        type="number"
        :maxlength="11"
        placeholder="11 位完整手机号"
        label-width="200rpx"
        @blur="checkDup"
      />
      <view v-if="dupTip" class="dup">
        <text class="dup__title">已有记录</text>
        <text class="dup__text">{{ dupTip }}</text>
      </view>
      <wd-input
        v-model="form.wechat"
        label="微信号"
        placeholder="选填"
        label-width="200rpx"
      />
    </ym-section>

    <ym-section title="归属与渠道" desc="渠道清单以后端下发为准">
      <wd-picker
        label="门店"
        required
        :columns="VENUES"
        :model-value="form.venue"
        placeholder="请选择"
        label-width="200rpx"
        @confirm="onPickVenue"
      />
      <wd-picker
        label="来源渠道"
        required
        :columns="sources"
        :model-value="form.source"
        placeholder="请选择"
        label-width="200rpx"
        @confirm="onPickSource"
      />
      <wd-picker
        label="下单平台"
        :columns="platforms"
        :model-value="form.orderPlatform"
        placeholder="选填"
        label-width="200rpx"
        @confirm="onPickPlatform"
      />
      <!-- 老师/顾问：从账号里选（写规范名），历史写法可手输 -->
      <view class="pick-row ym-tap" @click="openStaffPicker">
        <text class="pick-row__label">老师/顾问</text>
        <view class="pick-row__value">
          <text class="pick-row__text" :class="{ 'pick-row__text--ph': !form.serviceTeacher }">
            {{ form.serviceTeacher || '选填 · 点这里选人' }}
          </text>
          <text v-if="teacherHistorical" class="pick-row__flag">手输值</text>
          <wd-icon name="arrow-right" size="14px" color="#8A948F" />
        </view>
      </view>
    </ym-section>

    <ym-section title="需求与跟进">
      <wd-input
        v-model="form.demand"
        label="需求/痛点"
        placeholder="体态调整 / 产后修复 / 体式提升"
        label-width="200rpx"
      />
      <wd-picker
        label="状态"
        :columns="STATUS_OPTIONS"
        :model-value="form.status"
        placeholder="请选择"
        label-width="200rpx"
        @confirm="onPickStatus"
      />
      <!-- 体验课类型（不是客户等级）：与网页端同一组字面量，旧 A/B/C 会显示成「A（历史值）」 -->
      <wd-picker
        label="体验课类型"
        :columns="trialTypeOptions"
        :model-value="trialTypeLabel"
        placeholder="未设置"
        label-width="200rpx"
        @confirm="onPickTrialType"
      />
    </ym-section>

    <ym-section
      title="体验课 / 上课记录"
      :desc="`共 ${form.trialCards.length} 节；录完在客资卡片上能看到每节状态、课程与券码`"
    >
      <view v-for="(c, i) in form.trialCards" :key="i" class="tcard">
        <view class="tcard__head">
          <text class="tcard__title">第 {{ i + 1 }} 节</text>
          <view class="tcard__del ym-tap" @click="removeCard(i)">
            <wd-icon name="delete" size="14px" />
            <text class="tcard__del-text">删除</text>
          </view>
        </view>

        <view class="tcard__row">
          <picker mode="date" :value="c.date" @change="onCardDate(i, $event)">
            <view class="tcard__cell ym-tap">{{ c.date || '选日期' }}</view>
          </picker>
          <picker mode="time" :value="c.time" @change="onCardTime(i, $event)">
            <view class="tcard__cell ym-tap">{{ c.time || '选时间' }}</view>
          </picker>
          <picker
            :range="CARD_STATES"
            :value="cardStateIndex(c)"
            @change="onCardState(i, $event)"
          >
            <view class="tcard__cell ym-tap">{{ cardStateLabel(c) }}</view>
          </picker>
        </view>

        <wd-input v-model="c.topic" label="课程" placeholder="如 内观流 / 核心床小班" label-width="140rpx" />
        <wd-input v-model="c.teacher" label="老师" placeholder="上课老师" label-width="140rpx" />
        <wd-input v-model="c.couponName" label="券名" placeholder="平台核销券名称（选填）" label-width="140rpx" />
        <wd-input v-model="c.voucherCode" label="券码" placeholder="券码（选填）" label-width="140rpx" />
        <view class="tcard__row">
          <wd-input v-model="c.total" label="总次数" type="digit" placeholder="选填" label-width="140rpx" class="tcard__half" />
          <wd-input v-model="c.redeem" label="核销额" type="digit" placeholder="元" label-width="140rpx" class="tcard__half" />
        </view>
      </view>

      <view class="tcard__add ym-tap" @click="addCard">
        <wd-icon name="add" size="14px" />
        <text class="tcard__add-text">添加一节体验课</text>
      </view>
    </ym-section>

    <ym-section title="成交与备注">
      <wd-input
        v-model="form.dealCard"
        label="成交卡项"
        placeholder="成交时填写"
        label-width="200rpx"
      />
      <wd-input
        v-model="form.dealAmount"
        label="成交金额"
        type="digit"
        placeholder="未成交可留空"
        label-width="200rpx"
      />
      <wd-input
        v-model="form.couponName"
        label="券名"
        placeholder="平台核销券名称，如体验课次卡"
        label-width="200rpx"
      />
      <wd-input
        v-model="form.voucherCode"
        label="券码"
        placeholder="平台核销券码（可选）"
        label-width="200rpx"
      />
      <wd-input
        v-model="form.redeemAmount"
        label="核销金额"
        type="digit"
        placeholder="已核销金额，可留空"
        label-width="200rpx"
      />
      <wd-textarea
        v-model="form.remark"
        label="备注"
        placeholder="沟通记录、客户关注点等"
        :maxlength="500"
      />
    </ym-section>

    <!-- 选人面板：可搜索 + 手输兜底 + 清除 -->
    <view v-if="staffSheet" class="picker" @click="closeStaffPicker">
      <view class="picker__panel" @click.stop>
        <view class="picker__head">
          <text class="picker__title">老师/顾问</text>
          <view class="picker__close ym-tap" @click="closeStaffPicker">
            <wd-icon name="close" size="16px" color="#8A948F" />
          </view>
        </view>
        <text class="picker__hint">
          选中后写入账号规范名，本人才能在「我的客资」里看到这条记录。
        </text>

        <view class="picker__search">
          <wd-icon name="search" size="14px" color="#8A948F" />
          <input
            v-model="staffKeyword"
            class="picker__input"
            placeholder="搜名字 / 花名"
            confirm-type="search"
          />
        </view>

        <scroll-view v-if="staffLoaded" scroll-y class="picker__list">
          <view
            v-for="s in staffFiltered"
            :key="s.id || s.name"
            class="sitem ym-tap"
            @click="pickStaff(s)"
          >
            <view class="sitem__main">
              <view class="sitem__row">
                <text class="sitem__name">{{ s.name }}</text>
                <text v-if="s.self" class="sitem__tag">我</text>
              </view>
              <text class="sitem__meta ym-ellipsis">{{ staffMeta(s) }}</text>
            </view>
            <wd-icon
              v-if="form.serviceTeacher === s.name"
              name="check"
              size="16px"
              color="#2E7D5B"
            />
          </view>
          <view v-if="!staffFiltered.length" class="picker__empty">
            没有匹配的账号，可直接在下面手输
          </view>
        </scroll-view>
        <view v-else class="picker__state">
          {{
            staffLoading
              ? '正在读取人员清单…'
              : staffFetched
                ? '还没有可选的账号，可直接在下面手输'
                : '人员清单读取失败，可在下面手输'
          }}
        </view>

        <view class="picker__manual">
          <input
            v-model="manualTeacher"
            class="picker__input picker__input--manual"
            placeholder="手输名字（历史写法 / 临时名字）"
            confirm-type="done"
            @confirm="applyManual"
          />
          <view class="picker__manual-btn ym-tap" @click="applyManual">
            <text class="picker__manual-text">用手输</text>
          </view>
        </view>

        <view v-if="form.serviceTeacher" class="picker__clear ym-tap" @click="clearTeacher">
          <text class="picker__clear-text">清除当前选择</text>
        </view>
      </view>
    </view>

    <view class="actions">
      <wd-button type="primary" size="large" block :loading="saving" @click="submit">
        {{ saving ? '提交中…' : mode === 'edit' ? '保存改动' : '录入留资' }}
      </wd-button>
    </view>
  </view>
</template>

<style lang="scss" scoped>
@import '@/styles/tokens.scss';

.lead-form {
  padding-bottom: calc(200rpx + env(safe-area-inset-bottom));

  --wot-button-large-height: 96rpx;

  /* 表单行：横向内边距交给 ym-section，纵向撑到 96rpx 触摸高度 */
  --wot-input-padding: 0;
  --wot-textarea-padding: 0;
  --wot-input-fs: 28rpx;
  --wot-textarea-fs: 28rpx;
  --wot-input-cell-padding: 12px;
  --wot-textarea-cell-padding: 12px;
  --wot-cell-title-fs: 28rpx;
}

/* ---------- 页头 ---------- */
.fhead {
  display: flex;
  flex-direction: column;
  gap: 8rpx;
  padding: 28rpx 30rpx 6rpx;
}

.fhead__title {
  font-size: 40rpx;
  font-weight: 600;
  color: $ink-800;
}

.fhead__desc {
  display: block;
  font-size: 23rpx;
  line-height: 1.5;
  color: $ink-400;
}

/* ---------- 查重软提示 ---------- */
.dup {
  padding: 16rpx 20rpx;
  margin: 6rpx 0 18rpx;
  background: $warn-100;
  border-radius: $r-sm;
}

.dup__title {
  display: block;
  margin-bottom: 6rpx;
  font-size: 20rpx;
  color: $warn-600;
}

.dup__text {
  font-size: 24rpx;
  line-height: 1.5;
  color: $ink-700;
}

/* ---------- 底部提交条 ---------- */
.actions {
  position: fixed;
  right: 0;
  bottom: 0;
  left: 0;
  z-index: 8;
  padding: 16rpx 24rpx calc(16rpx + env(safe-area-inset-bottom));
  background: $white;
  border-top: 2rpx solid $hairline;
  box-shadow: 0 -8rpx 24rpx rgba(16, 24, 21, 0.04);
}

/* ---------- 老师/顾问：选人行 ---------- */
.pick-row {
  display: flex;
  gap: 16rpx;
  align-items: center;
  justify-content: space-between;
  min-height: 96rpx;
  padding: 12px 0;
  border-bottom: 2rpx solid $hairline;
}

.pick-row__label {
  flex-shrink: 0;
  width: 200rpx;
  font-size: 28rpx;
  color: $ink-700;
}

.pick-row__value {
  display: flex;
  flex: 1;
  gap: 10rpx;
  align-items: center;
  justify-content: flex-end;
  min-width: 0;
}

.pick-row__text {
  flex-shrink: 1;
  min-width: 0;
  overflow: hidden;
  font-size: 28rpx;
  color: $ink-800;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.pick-row__text--ph {
  color: $ink-300;
}

.pick-row__flag {
  flex-shrink: 0;
  padding: 2rpx 10rpx;
  font-size: 20rpx;
  color: $warn-600;
  background: $warn-100;
  border-radius: 999rpx;
}

/* ---------- 选人面板 ---------- */
.picker {
  position: fixed;
  inset: 0;
  z-index: 40;
  display: flex;
  align-items: flex-end;
  background: rgba(16, 24, 21, 0.5);
}

.picker__panel {
  width: 100%;
  padding: 30rpx 28rpx calc(24rpx + env(safe-area-inset-bottom));
  background: $white;
  border-radius: $r-xl $r-xl 0 0;
  box-shadow: $shadow-float;
}

.picker__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.picker__title {
  font-size: 33rpx;
  font-weight: 600;
  color: $ink-800;
}

.picker__close {
  padding: 8rpx;
}

.picker__hint {
  display: block;
  margin-top: 8rpx;
  font-size: 21rpx;
  line-height: 1.5;
  color: $ink-400;
}

.picker__search {
  display: flex;
  gap: 12rpx;
  align-items: center;
  height: 84rpx;
  padding: 0 22rpx;
  margin-top: 18rpx;
  background: $ink-50;
  border-radius: 999rpx;
}

.picker__input {
  flex: 1;
  min-width: 0;
  font-size: 27rpx;
  color: $ink-800;
}

.picker__list {
  height: 44vh;
  margin-top: 14rpx;
}

.sitem {
  display: flex;
  gap: 16rpx;
  align-items: center;
  justify-content: space-between;
  min-height: 104rpx;
  padding: 16rpx 6rpx;
  border-bottom: 2rpx solid $hairline;
}

.sitem__main {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 6rpx;
  min-width: 0;
}

.sitem__row {
  display: flex;
  gap: 10rpx;
  align-items: center;
  min-width: 0;
}

.sitem__name {
  font-size: 29rpx;
  font-weight: 500;
  color: $ink-800;
}

.sitem__tag {
  padding: 0 10rpx;
  font-size: 19rpx;
  line-height: 1.7;
  color: $brand-700;
  background: $brand-100;
  border-radius: 999rpx;
}

.sitem__meta {
  font-size: 21rpx;
  color: $ink-400;
}

.picker__empty,
.picker__state {
  display: block;
  padding: 36rpx 6rpx;
  font-size: 23rpx;
  color: $ink-400;
  text-align: center;
}

.picker__manual {
  display: flex;
  gap: 14rpx;
  align-items: center;
  padding-top: 18rpx;
  margin-top: 8rpx;
  border-top: 2rpx solid $hairline;
}

.picker__input--manual {
  height: 84rpx;
  padding: 0 22rpx;
  background: $ink-50;
  border-radius: 999rpx;
}

.picker__manual-btn {
  display: flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  height: 84rpx;
  padding: 0 30rpx;
  color: $white;
  background: linear-gradient(135deg, $brand-500, $brand-700);
  border-radius: 999rpx;
}

.picker__manual-text {
  font-size: 26rpx;
  font-weight: 500;
}

.picker__clear {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 76rpx;
  margin-top: 12rpx;
}

.picker__clear-text {
  font-size: 24rpx;
  color: $danger-600;
}

/* ---------- 体验课卡片编辑 ---------- */
.tcard {
  padding: 16rpx 0;
  border-bottom: 2rpx solid $hairline;

  &:last-of-type {
    border-bottom: none;
  }
}

.tcard__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 10rpx;
}

.tcard__title {
  font-size: 25rpx;
  font-weight: 600;
  color: $ink-700;
}

.tcard__del {
  display: flex;
  align-items: center;
  gap: 4rpx;
  font-size: 21rpx;
  color: $ink-400;
}

.tcard__del-text {
  font-size: 21rpx;
}

.tcard__row {
  display: flex;
  gap: 12rpx;
  margin-bottom: 10rpx;
}

.tcard__cell {
  flex: 1;
  padding: 14rpx 12rpx;
  font-size: 23rpx;
  text-align: center;
  color: $ink-600;
  background: $ink-50;
  border-radius: 10rpx;
}

.tcard__half {
  flex: 1;
}

.tcard__add {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8rpx;
  margin-top: 16rpx;
  padding: 18rpx;
  font-size: 24rpx;
  color: $brand-600;
  background: rgba(38, 113, 79, 0.06);
  border: 2rpx dashed rgba(38, 113, 79, 0.35);
  border-radius: 12rpx;
}

.tcard__add-text {
  font-size: 24rpx;
}
</style>
