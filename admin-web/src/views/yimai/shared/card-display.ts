/**
 * 待续费 v3.9 · 卡项展示口径（会员管理 / 今日待办共用，**唯一一份**）
 *
 * 设计约束（照 `docs/口径/待续费判定规范-v3.9.md` 执行）：
 *
 * 1. **只消费后端字段，不重算判定**。谁在清单里、命中了哪条规则，全部由后端
 *    `customerDecision()` 决定；本模块只做三件事：把字段翻译成人话、按单位格式化、
 *    在字段缺失时**降级展示**（绝不猜测状态或余量）。
 * 2. **单位唯一来源是 `type`**（§2.3/§2.5）：1=次卡/节、2=期限卡/天、3=储值卡/元。
 *    旧快照把储值卡写成「节」，所以绝不能直接信任 `unit`（D05）。
 * 3. **到期文案由 `expireDateState` 决定句式**（§2.6/§5.5）：`current` 才允许说
 *    「X 天后到期 / 今日到期」，`history` 只能说「已过期 N 天」，`none` 显示「—」。
 *    旧快照没有 `expireDateState` 时只讲事实（「{date} 到期」），
 *    **绝不**用 `expireDays <= 0` 推「今日到期」（D08）。
 * 4. 阈值文案（会员管理弹窗 / 今日待办页头）也从这里取，保证两处同源同文案。
 */
import type {
  CardEntitlement,
  CardInstanceState,
  CardTypeCode,
  CardUnit,
  CustomerCardItem,
  CustomerHistoryCardItem,
  MemberRules,
  YimaiCustomer
} from '@/api/yimai'

/** 卡项实例（当前卡或历史卡的公共形状） */
export type CardLike = CustomerCardItem | CustomerHistoryCardItem

/** 到期日口径（§2.6；`undefined` = 旧后端没给，走降级句式） */
export type ExpireDateState = 'current' | 'history' | 'none'

/* ══════════════════════════ 一、类型 / 单位 / 余量 ══════════════════════════ */

const TYPE_LABELS: Record<CardTypeCode, string> = { '1': '次卡', '2': '期限卡', '3': '储值卡' }
const TYPE_UNITS: Record<CardTypeCode, CardUnit> = { '1': '节', '2': '天', '3': '元' }
const TYPE_TAGS: Record<CardTypeCode, 'primary' | 'success' | 'warning'> = {
  '1': 'primary',
  '2': 'success',
  '3': 'warning'
}

/**
 * 卡类型码（§2.3 `type(i)` 的前两个分支）。
 * 优先读 `type`；旧数据缺失时按 `unit` 回退，最后按次卡处理。
 */
export function cardTypeCode(card?: CardLike | null): CardTypeCode {
  const raw = card?.type
  if (raw === '1' || raw === '2' || raw === '3') return raw
  if (card?.unit === '天') return '2'
  if (card?.unit === '元') return '3'
  return '1'
}

/** 类型徽标文案：次卡 / 期限卡 / 储值卡 */
export function cardTypeLabel(card?: CardLike | null): string {
  return TYPE_LABELS[cardTypeCode(card)]
}

/** 由类型码直接取徽标文案（「按类型聚合」等场景不需要实例对象） */
export function cardTypeLabelByType(type: CardTypeCode): string {
  return TYPE_LABELS[type]
}

/** 类型徽标语义色（三类互不相同，便于扫读） */
export function cardTypeTagType(card?: CardLike | null): 'primary' | 'success' | 'warning' {
  return TYPE_TAGS[cardTypeCode(card)]
}

/**
 * 卡项单位（§2.5 唯一来源）：由 `type` 推导，**不读** `unit` 以外的猜法。
 * 储值卡一律「元」——这正是 D05「储值卡落成节」的修复点。
 */
export function cardUnit(card?: CardLike | null): CardUnit {
  return TYPE_UNITS[cardTypeCode(card)]
}

/** 由类型码直接取单位（给「按类型聚合」这类不需要实例对象的场景用） */
export function cardUnitByType(type: CardTypeCode): CardUnit {
  return TYPE_UNITS[type]
}

/** 金额展示：整数不带小数（200 元），非整数两位（200.50 元） */
function formatAmount(value: number): string {
  return Number.isInteger(value) ? String(value) : value.toFixed(2)
}

/**
 * 「数字 + 单位」的唯一格式化入口（§2.5）。**数字与单位之间必须有空格**（修 D08-①）。
 * `null` 表示「未知」，与 `0`（确有 0）严格区分 —— 不许把未知显示成 0。
 */
export function fmtQty(value: number | null | undefined, unit: CardUnit): string {
  if (value === null || value === undefined || !Number.isFinite(Number(value))) return '—'
  const n = Number(value)
  if (unit === '元') return `${formatAmount(n)} 元`
  return `${Math.trunc(n)} ${unit}`
}

/** 距某日天数（正=未来，负=已过期，0=今天）；按本地民用日比较，避开时区差一天 */
export function daysUntil(date: string | null | undefined): number | null {
  if (!date) return null
  const [y, m, d] = String(date).slice(0, 10).split('-').map(Number)
  if (!y || !m || !d) return null
  const now = new Date()
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime()
  const target = new Date(y, m - 1, d).getTime()
  return Math.round((target - today) / 86400000)
}

/**
 * 单卡有效余量（**展示口径**，§2.4）：
 * - 次卡 / 储值卡：后端 `residue`（节 / 元）
 * - 期限卡：`deadline` 推算的剩余天数（其 `residue` 恒 0，这是 F1 的口径陷阱）；
 *   缺 `deadline` 时才回退 `residueDays`，再不行就照实给 0/null。
 */
export function cardRemaining(card?: CardLike | null): number | null {
  if (!card) return null
  const numeric =
    typeof card.residue === 'number' && Number.isFinite(card.residue) ? card.residue : null
  if (cardTypeCode(card) === '2') {
    const byDeadline = daysUntil(card.deadline)
    if (byDeadline !== null) return Math.max(0, byDeadline)
    if (typeof card.residueDays === 'number' && Number.isFinite(card.residueDays)) {
      return Math.max(0, card.residueDays)
    }
    return numeric
  }
  return numeric
}

/** 单卡「余量 + 单位」文案，如 `8 节` / `300 天` / `200 元` */
export function cardQtyText(card?: CardLike | null): string {
  return fmtQty(cardRemaining(card), cardUnit(card))
}

/* ══════════════════════════ 二、状态 / 身份 / 到期 ══════════════════════════ */

const STATUS_BY_CODE: Record<string, string> = {
  '4': '请假',
  '5': '正常',
  '6': '过期',
  '7': '未开卡',
  '29': '退卡',
  '39': '停卡'
}

const HISTORY_REASON_LABELS: Record<string, string> = {
  expired: '已过期',
  refunded: '已退卡',
  suspended: '停卡中',
  expired_by_clock: '已到期'
}

/**
 * 卡项状态文案。
 *
 * 优先用后端中文 `status`（上游 `status_format`），只有拿到纯数字码时才查表还原 ——
 * 这样「正常 / 请假 / 过期 / 未开卡 / 退卡 / 停卡」的措辞继续由上游决定，
 * 前端不发明新状态（§5.4 反例：把「未开卡」写成「已过期」）。
 */
export function cardStatusText(card?: CardLike | null): string {
  if (!card) return '—'
  const raw = String(card.status ?? '').trim()
  if (raw && !/^\d+$/.test(raw)) return raw
  const code = String(card.statusCode ?? raw ?? '').trim()
  if (!code) return '状态未知'
  return STATUS_BY_CODE[code] ?? `状态 ${code}`
}

/** 历史卡的终止原因文案（`historyReason`，§1.4） */
export function historyReasonText(card?: CardLike | null): string {
  const reason = (card as CustomerHistoryCardItem | null | undefined)?.historyReason
  if (!reason) return ''
  return HISTORY_REASON_LABELS[reason] ?? String(reason)
}

/** 实例状态分档文案（当前 / 未开卡 / 历史 / 不计入） */
export function cardStateLabel(state?: CardInstanceState | null): string {
  if (state === 'current') return '当前'
  if (state === 'unactivated') return '未开卡'
  if (state === 'history') return '历史'
  if (state === 'excluded') return '不计入'
  return ''
}

/** 发卡日（后端命名 createTime / soldAt 二选一） */
export function cardCreateDate(card?: CardLike | null): string | null {
  const raw = card?.createTime ?? card?.soldAt ?? null
  if (!raw) return null
  const text = String(raw).slice(0, 10)
  return /^\d{4}-\d{2}-\d{2}$/.test(text) ? text : null
}

/**
 * 实例身份后缀（§4.4/§5.4）：同名第 k 张 · 卡号 xxx · 发卡 yyyy-mm-dd。
 *
 * 这是「同名多卡可区分」的唯一实现：有卡号用卡号，没有就退到发卡日，
 * 再没有就用实例 id —— 三样全缺时返回空串（此时后端必有 `identity_missing` 降级说明）。
 */
export function cardIdentityText(
  card?: CardLike | null,
  sameName?: { index: number; total: number } | null
): string {
  if (!card) return ''
  const parts: string[] = []
  if (sameName && sameName.total > 1) parts.push(`同名第 ${sameName.index} 张`)
  const cardNo = card.cardNo ? String(card.cardNo).trim() : ''
  if (cardNo) parts.push(`卡号 ${cardNo}`)
  const created = cardCreateDate(card)
  if (created) parts.push(`发卡 ${created}`)
  if (!cardNo && !created) {
    const id = card.id ?? card.instanceId
    if (id !== undefined && id !== null && String(id).trim() !== '') parts.push(`实例 ${id}`)
  }
  return parts.join(' · ')
}

/**
 * 同名多卡归因（§4.4）：同一个 `cardId`（缺失时按卡名）为一组，
 * 组内按发卡日升序编号 → 「同名第 k 张」。
 *
 * 返回 Map（键是卡对象本身），调用方仍拿原对象渲染，不做任何数据改写。
 */
export function sameNameIndexMap(
  cards: CardLike[]
): Map<CardLike, { index: number; total: number }> {
  const groups = new Map<string, CardLike[]>()
  for (const card of cards) {
    const key = card.cardId ? `p:${card.cardId}` : `t:${String(card.title ?? '').trim()}`
    const bucket = groups.get(key)
    if (bucket) bucket.push(card)
    else groups.set(key, [card])
  }
  const out = new Map<CardLike, { index: number; total: number }>()
  for (const bucket of groups.values()) {
    const sorted = [...bucket].sort((a, b) => {
      const byDate = (cardCreateDate(a) ?? '9999-12-31').localeCompare(
        cardCreateDate(b) ?? '9999-12-31'
      )
      if (byDate !== 0) return byDate
      return String(a.cardNo ?? '').localeCompare(String(b.cardNo ?? ''))
    })
    sorted.forEach((card, i) => out.set(card, { index: i + 1, total: bucket.length }))
  }
  return out
}

/**
 * 到期日文案（§2.6/§5.5）—— 句式由 `expireDateState` 决定，**不猜**。
 *
 * - `current`：`今日到期` / `N 天后到期`
 * - `history`：`已过期 N 天` / `历史卡，到期日 {date}`
 * - `none`：`—`（不推算）
 * - 旧后端（`undefined`）：只讲事实 `{date} 到期` / `剩 N 天`，**绝不**说「今日到期」
 */
export function expireDateText(
  state: ExpireDateState | undefined,
  nextExpireDays: number | null | undefined,
  expireDate: string | null | undefined
): string {
  const days =
    typeof nextExpireDays === 'number' && Number.isFinite(nextExpireDays)
      ? nextExpireDays
      : daysUntil(expireDate)

  if (state === 'none') return '—'
  if (state === 'history') {
    if (days !== null && days < 0) return `已过期 ${-days} 天`
    return expireDate ? `历史卡，到期日 ${String(expireDate).slice(0, 10)}` : '历史卡'
  }
  if (state === 'current') {
    if (days === null) return expireDate ? `${String(expireDate).slice(0, 10)} 到期` : '—'
    if (days === 0) return '今日到期'
    if (days > 0) return `${days} 天后到期`
    return `已过期 ${-days} 天`
  }
  // 旧后端：没有口径字段时只陈述事实，避免「已过期 60 天」被写成「今日到期」
  if (days !== null && days > 0) return `${days} 天后到期`
  return expireDate ? `${String(expireDate).slice(0, 10)} 到期` : '—'
}

/** 单卡到期文案（判定依据面板里逐卡用）：历史卡按过期末日算，当前卡按剩余天数算 */
export function cardDeadlineText(card?: CardLike | null): string {
  if (!card) return '—'
  const inHistory = card.state === 'history' || (card as CustomerHistoryCardItem).historyReason
  const days = daysUntil(card.deadline)
  if (!card.deadline) return '到期日未知'
  const date = String(card.deadline).slice(0, 10)
  if (inHistory) {
    return days !== null && days < 0 ? `${date} 到期（已过期 ${-days} 天）` : `${date} 到期`
  }
  if (days === null) return `${date} 到期`
  if (days === 0) return '今日到期'
  if (days > 0) return `${date} 到期（${days} 天）`
  return `${date} 到期（已过期 ${-days} 天）`
}

/* ══════════════════════════ 三、理由码可读映射 ══════════════════════════ */

/**
 * `whyCodes` → 可读短标签（§5.2 枚举 + 后端实现里的兼容码）。
 *
 * ⚠️ 只做**码到文案**的映射：不解析中文、不按索引把 `whyCodes[i]` 配到 `why[i]`
 * （后端只对部分分支压码，按索引配对会错位）。逐条理由自带 code 的是
 * `renewal.hits[]`，那里才可以配对。
 */
export const WHY_CODE_LABELS: Record<string, string> = {
  deadline_near: '临期到期',
  deadline_near_unknown_residue: '临期到期（余量未知）',
  count_card_low: '单卡余量偏低',
  count_card_percent_low: '单卡剩余占比偏低',
  count_total_low: '次卡合计余量偏低',
  count_total_percent_low: '次卡合计占比偏低',
  validity_percent: '期限卡有效期将尽',
  stored_value_low: '储值卡余额偏低',
  count_exhausted: '课时已耗尽',
  unactivated_open: '未开卡',
  unactivated_with_history: '未开卡（另有历史卡）',
  // —— 兼容后端实现用词（t4 计划里的别名），语义等价，不引入新口径 ——
  count_tail: '单卡进入尾段',
  newer_instance: '已有更新的同名卡',
  superseded: '该卡已被新卡接替',
  // —— 历史/P3 区（只出现在 expiredBalance，不进 renewal.why）——
  expired_balance: '过期仍有余额',
  expired_time_card: '期限卡已到期'
}

/** 取理由码的可读文案；未登记的码原样返回，便于快速发现后端新增枚举 */
export function whyCodeLabel(code: string): string {
  return WHY_CODE_LABELS[code] ?? code
}

/* ══════════════════════════ 四、会员级汇总（展示口径） ══════════════════════════ */

/** 会员卡项展示摘要：后端给了 v3.9 字段就用后端的，缺哪个字段就只降级哪一个 */
export interface CustomerCardSummary {
  /** 后端已给出的权益摘要（缺失 = 旧快照） */
  entitlement: CardEntitlement | null
  /** 当前 ∪ 未开卡实例（历史卡不进汇总，但可在判定依据面板看） */
  currentCards: CardLike[]
  /** 历史实例（`historyCards`，缺失时回退旧保留区 `expiredCards`） */
  historyCards: CustomerHistoryCardItem[]
  /** 展示口径次卡节数（= 旧 `remainTimes`） */
  displayCount: number | null
  /** 判定口径次卡节数；旧快照为 null（前端不猜） */
  usableCount: number | null
  /** 储值卡余额合计（元） */
  storedValue: number | null
  mainCard: string
  mainCardState: 'current' | 'unactivated' | 'history' | 'none'
  expireDate: string | null
  /** `undefined` = 旧后端没给口径，展示层走降级句式 */
  expireDateState: ExpireDateState | undefined
  nextExpireDays: number | null
  /** 是否已拿到 v3.9 契约（有 entitlement 或 historyCards 任一） */
  hasV39: boolean
}

/**
 * 按类型聚合余量（§2.5「合计必须按单位分组，禁止跨单位求和」）。
 *
 * 返回 `null` 表示该类型一张都没有（或余量全部未知）——
 * 这与「合计 0」是两件事，界面必须区别对待。
 */
export function sumRemainingByType(cards: CardLike[], type: CardTypeCode): number | null {
  let total = 0
  let hit = false
  for (const card of cards) {
    if (cardTypeCode(card) !== type) continue
    const remaining = cardRemaining(card)
    if (remaining === null) continue
    total += remaining
    hit = true
  }
  return hit ? total : null
}

function deriveMainCardState(cards: CardLike[]): 'current' | 'unactivated' | 'history' | 'none' {
  if (cards.some((c) => c.state === 'current' || !c.state)) return 'current'
  if (cards.some((c) => c.state === 'unactivated')) return 'unactivated'
  if (cards.length) return 'history'
  return 'none'
}

/**
 * 把一个会员的卡项字段整理成「展示用」摘要。
 *
 * 只读后端字段；`cardsList` / `currentCards` 两种键都能读（契约占位，见 §1.4），
 * 历史卡优先读 `historyCards`，旧数据回退 `expiredCards` 保留区。
 */
export function summarizeCustomerCards(customer: YimaiCustomer): CustomerCardSummary {
  const stats = customer.cardStats ?? null
  const entitlement = stats?.entitlement ?? customer.entitlement ?? null
  const rawCurrent = customer.currentCards ?? customer.cardsList ?? []
  const currentCards = rawCurrent.filter((c) => c.state !== 'history' && c.state !== 'excluded')
  const historyCards: CustomerHistoryCardItem[] = stats?.historyCards?.length
    ? stats.historyCards
    : // 旧快照回退：`expiredCards` 只保留 type=1 的过期次卡，缺的字段按次卡口径补齐
      (stats?.expiredCards ?? []).map((c) => ({
        ...c,
        unit: '节' as const,
        bound: null,
        unactivated: false,
        type: c.type,
        state: 'history' as CardInstanceState,
        historyReason: 'expired'
      }))

  const mainCard = entitlement?.mainCard ?? customer.mainCard ?? '—'
  const expireDate = entitlement?.expireDate ?? customer.expireDate ?? null
  const nextExpireDays =
    typeof entitlement?.nextExpireDays === 'number'
      ? entitlement.nextExpireDays
      : daysUntil(expireDate)

  return {
    entitlement,
    currentCards,
    historyCards,
    displayCount: entitlement?.displayCount ?? customer.remainTimes ?? null,
    usableCount: entitlement?.usableCount ?? null,
    storedValue: entitlement?.storedValue ?? sumRemainingByType(currentCards, '3'),
    mainCard,
    mainCardState: entitlement?.mainCardState ?? deriveMainCardState(currentCards),
    expireDate,
    // 旧快照没有 expireDateState：返回 undefined，展示层就不会说「今日到期」
    expireDateState: entitlement?.expireDateState,
    nextExpireDays,
    hasV39: Boolean(entitlement || stats?.historyCards)
  }
}

/** 主卡状态徽标文案 */
export function mainCardStateLabel(state: 'current' | 'unactivated' | 'history' | 'none'): string {
  if (state === 'current') return '当前卡'
  if (state === 'unactivated') return '未开卡'
  if (state === 'history') return '历史卡'
  return '无卡项'
}

/**
 * 「次卡剩余 ≤ 阈值」的**展示**提示（只上色，不决定谁在清单里）。
 *
 * 判定口径是 `usableCount`（当前可用次卡，不含未开卡，§2.6）。
 * 旧快照没有 entitlement 时才退回展示口径 `displayCount`，并把 `exact=false`
 * 交给界面标注「按展示口径近似」——**不静默混用两个口径**（D12）。
 */
export function lowRemainInfo(
  summary: Pick<CustomerCardSummary, 'usableCount' | 'displayCount'>,
  threshold: number
): { low: boolean; value: number | null; exact: boolean } {
  if (summary.usableCount !== null && summary.usableCount !== undefined) {
    return { low: summary.usableCount <= threshold, value: summary.usableCount, exact: true }
  }
  const value = summary.displayCount ?? null
  return { low: value !== null && value <= threshold, value, exact: false }
}

/* ══════════════════════════ 五、阈值文案（两处页面同源） ══════════════════════════ */

/**
 * 生效的「过期有余额回溯窗」（天，0=关闭）。
 *
 * 读取顺序按 §6.3：新键 → 旧键 `renewalExpiredBackfillDays` → 90。
 * 用 `??` 而不是 `||`：**0 是合法值**（关闭），吞掉 0 会把用户的关闭操作变成 90。
 */
export function effectiveExpiredBalanceWindowDays(rules: MemberRules): number {
  const value = rules.expiredBalanceWindowDays ?? rules.renewalExpiredBackfillDays ?? 90
  return Number.isFinite(Number(value)) ? Number(value) : 90
}

/** 待续费阈值摘要（逐条）：会员管理弹窗与今日待办页头共用同一套文案 */
export function renewalRuleParts(rules: MemberRules): string[] {
  const parts = [`次卡剩余 ≤${rules.renewalThreshold ?? 10} 节`]
  if ((rules.renewalCountPercent ?? 0) > 0) parts.push(`次卡占比 ≤${rules.renewalCountPercent}%`)
  parts.push(`到期 ≤${rules.renewalExpireDays ?? 30} 天`)
  if ((rules.renewalExpirePercent ?? 0) > 0) {
    parts.push(`期限卡有效期 ≤${rules.renewalExpirePercent}%`)
  }
  if ((rules.renewalStoredValueThreshold ?? 0) > 0) {
    parts.push(`储值卡余额 ≤${rules.renewalStoredValueThreshold} 元`)
  }
  return parts
}

/** 待续费阈值摘要（一行） */
export function renewalRuleSummary(rules: MemberRules): string {
  return renewalRuleParts(rules).join(' 或 ')
}

/** 过期有余额回溯窗文案：`90 天` / `已关闭` */
export function expiredBalanceWindowText(rules: MemberRules): string {
  const days = effectiveExpiredBalanceWindowDays(rules)
  return days > 0 ? `${days} 天` : '已关闭'
}
