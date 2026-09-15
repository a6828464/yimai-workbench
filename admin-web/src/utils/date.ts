/**
 * 时间显示统一格式化。
 *
 * 背景：后端 Carbon 序列化 datetime 时会转成 UTC ISO（如 2026-09-14T19:00:00.000000Z），
 * 直接渲染看到的是 UTC 时间，比本地时间早 8 小时。表现就是同一批任务
 * 「批次号写着 09-15 03:00，完成时间却显示 9 月 14 号」——两个时间其实是同一时刻。
 *
 * 规则：
 * - 带时区标记（Z / ±HH:MM）的按浏览器本地时区转换后显示；
 * - 不带时区的（后端已格式化成 'YYYY-MM-DD HH:mm:ss' 的文本）视为本地时间，只做裁剪。
 */
export function formatDateTime(value?: string | null, withSeconds = false): string {
  if (value === null || value === undefined) return '—'
  const raw = String(value).trim()
  if (raw === '') return '—'

  const hasTimezone = /(Z|[+-]\d{2}:?\d{2})$/.test(raw)
  if (!hasTimezone) {
    return withSeconds ? raw.slice(0, 19) : raw.slice(0, 16)
  }

  const d = new Date(raw)
  if (Number.isNaN(d.getTime())) return raw
  const p = (n: number) => String(n).padStart(2, '0')
  const base = `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`

  return withSeconds ? `${base}:${p(d.getSeconds())}` : base
}

/** 只取日期部分（本地时区） */
export function formatDate(value?: string | null): string {
  const full = formatDateTime(value)
  return full === '—' ? full : full.slice(0, 10)
}
