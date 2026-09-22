/**
 * 薪酬计算页的共用工具（金额格式化、月份、状态判定）
 *
 * 放在 `modules/` 下而不是 `utils/` 里，是因为这些约定只服务薪酬页；
 * 全站通用工具属别的 owner，本任务不改 `utils/`。
 */

/** 当前自然月 `YYYY-MM`（本地时区，不用 toISOString —— UTC+8 早间会跨月少一天） */
export function currentMonth(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
}

/** 上一个月 `YYYY-MM` */
export function previousMonth(month: string): string {
  const [y, m] = month.split('-').map((x) => Number(x))
  if (!y || !m) return month
  const d = new Date(y, m - 2, 1)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}

/**
 * 金额格式化：千分位 + 固定两位小数。
 *
 * 薪酬数据**一律保留两位小数**（后端 decimal(10,2)），不做「整数就不显示小数」的
 * 智能省略 —— 工资表上 `4000` 与 `4000.00` 混排会让逐项相加时难以对齐核对。
 */
export function money(value: number | null | undefined): string {
  if (value === null || value === undefined || Number.isNaN(value)) return '—'
  return value.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

/** 带 ¥ 前缀的金额；无值显示 `—` 而**不是** `¥0.00`（区分「没有」与「是 0」） */
export function yuan(value: number | null | undefined): string {
  if (value === null || value === undefined || Number.isNaN(value)) return '—'
  return `¥${money(value)}`
}

/** 百分数：小数 0.07 → `7%`（提成率、门店提成率用） */
export function percent(rate: number | null | undefined): string {
  if (rate === null || rate === undefined || Number.isNaN(rate)) return '—'
  const p = rate * 100
  return `${Number.isInteger(p) ? p : p.toFixed(2)}%`
}

/** 数值直出（课时数等整数），null 显示 `—` */
export function num(value: number | null | undefined): string {
  if (value === null || value === undefined || Number.isNaN(value)) return '—'
  return String(value)
}

/** 是否已配置：`null` / `''` / 未填 都算「未配置」 */
export function isBlank(value: unknown): boolean {
  return value === null || value === undefined || value === ''
}

/**
 * 把 `el-input-number` 的 `null`（清空）与 `0` 区分开。
 *
 * Element Plus 的 input-number 清空后是 `null` 而不是 0，直接传给后端会变成
 * 「不提交该字段」= 保持原值 —— 用户以为清空了、实际没改。所以提交前要显式判定。
 */
export function numberOrNull(value: number | null | undefined): number | null {
  return value === null || value === undefined || Number.isNaN(value) ? null : value
}

/** 结算阶段文案（规格 §8.4：`待财务个税`(warning) / `待南哥确认`(info)） */
export function stageLabel(stage: string): string {
  if (stage === 'waiting_confirm') return '待南哥确认'
  if (stage === 'waiting_tax') return '待财务个税'
  return stage || '—'
}

/** 结算阶段的标签语义色 */
export function stageTagType(stage: string): 'warning' | 'info' | 'success' {
  if (stage === 'waiting_tax') return 'warning'
  if (stage === 'waiting_confirm') return 'info'
  return 'success'
}

/**
 * 时长来源文案（规格 §6.3 / §6.3.2）。
 *
 * 🔴 `assumed_60` 必须**显式**说成「按 60 分钟估算」，不得显示成普通来源 ——
 * 估算值会直接改变课时费金额与底薪奖励档位，用户看不见就等于被误导。
 */
export function durationSourceLabel(source: string): string {
  switch (source) {
    case 'name_regex':
      return '课程名含时长'
    case 'raw_end_time':
      return '按起止时间推算'
    case 'manual':
      return '人工指定'
    case 'assumed_60':
      return '按 60 分钟估算'
    default:
      return source || '—'
  }
}

/** 时长来源是否为推断（需要向使用者示警） */
export function isInferredDuration(source: string): boolean {
  return source === 'assumed_60' || source === 'raw_end_time'
}

/**
 * 社保三态 → 展示标签（规格 §4.2）。
 *
 * 🔴 三态**必须分别渲染**：`set` 显示实际金额、`inherit` 显示「沿用 X 的 ¥Y」、
 * `off` 显示「本月不缴」。**禁止**把 `inherit` 渲染成 0 —— 「本月没操作」与
 * 「本月明确设为 0」是两件不同的事，混同会让财务以为社保停了。
 */
export function socialTagType(mode: string): 'primary' | 'info' | 'danger' {
  if (mode === 'set') return 'primary'
  if (mode === 'off') return 'danger'
  return 'info'
}

/** 异常行是否属于「别名对不上」（前端要单独归类，便于用户去补别名） */
export function isNameException(code: string): boolean {
  return code === 'UNMATCHED_NAME' || code === 'AMBIGUOUS_NAME'
}

/**
 * 把后端文案里的 `**强调**` 拆成可安全渲染的片段。
 *
 * 后端若干 message（如 `DURATION_ASSUMED_60`、`unavailable[].reason`）里带 Markdown
 * 风格的 `**`，直接插值会把星号原样显示给用户。这里拆成片段由模板用 `<b>` 渲染 ——
 * **不用 `v-html`**：那些文案来自后端，`v-html` 会把其中的 HTML 当代码执行。
 */
export interface RichSegment {
  text: string
  bold: boolean
}

export function richSegments(text: unknown): RichSegment[] {
  const raw = typeof text === 'string' ? text : String(text ?? '')
  if (raw === '') return []
  return raw
    .split('**')
    .map((t, i) => ({ text: t, bold: i % 2 === 1 }))
    .filter((seg) => seg.text !== '')
}
