/**
 * 系统信息（「我的 → 关于」的更新要点）
 *
 * 后端：`GET /system/changelog`（SystemController::changelog）
 *
 * ## 为什么解析逻辑写得这么宽容
 * 这个接口已经被后端改成「结构化 + 可限量」的形状（`?limit=3` → `records`），
 * 但小程序与后端的发版不同步，老服务器上还是 `{content, latest}`：
 *  - 新形状（v3.11，SystemController::changelog）：
 *    `{records:[{version,title,date}], content:'<原文>', latest:{…}}`
 *    —— 注意 `records[].version` 是**不带 v** 的（后端正则 `v(\d+\.\d+…)` 只取数字），
 *    且 `title` 仍带 `fix: v3.10.1 …` 前缀，这里统一清洗成「v3.10.1 / 人员身份归一…」。
 *  - 旧形状：只有 `content`（整份 CHANGELOG.md）+ 空 `latest` 哨兵。
 * 所以按 `records` → 解析 `content` 的 `## ` 标题 → 单条 `latest` 三路兜底。
 * 任何一路拿到东西就够用；全空就返回空数组，页面只显示版本号（**不报错、不弹窗**）。
 *
 * 另外一个现实约束：`latest` 背后的实现会去请求 Gitee / GitHub（各 12s 超时），
 * 这个接口在生产上可能很慢，所以调用方必须「先开弹窗，再异步填更新要点」。
 */
import { http } from '@/utils/http'

/** 一条更新要点：关于弹窗只显示「版本 · 标题」 */
export interface ChangelogEntry {
  /** 版本号（`v3.10.1`）或日期（旧日志没有版本号时用日期兜底） */
  version: string
  title: string
  date: string
}

type Dict = Record<string, unknown>

const VERSION_RE = /v\d+(?:\.\d+)+/i
const DATE_RE = /\d{4}-\d{2}-\d{2}/
/** `## 2026-11-04 ｜ fix: v3.10.1 人员身份归一…` —— 分隔符全角半角都见过 */
const HEAD_RE = /^##\s+(.+)$/
const TYPE_RE = /^(feat|fix|perf|refactor|docs|style|test|chore|build|ci)\s*[:：]\s*/i

function dict(v: unknown): Dict {
  return v !== null && typeof v === 'object' && !Array.isArray(v) ? (v as Dict) : {}
}

function list(v: unknown): unknown[] {
  return Array.isArray(v) ? v : []
}

function text(v: unknown): string {
  if (typeof v === 'string') return v.trim()
  if (typeof v === 'number') return String(v)
  return ''
}

function firstText(...vals: unknown[]): string {
  for (const v of vals) {
    const s = text(v)
    if (s) return s
  }
  return ''
}

/**
 * 标题清洗：去掉类型前缀（`fix: `）与开头重复的版本号（`v3.10.1 …`）。
 * 后端 records 的 title 是 `## 2026-11-04 ｜ fix: v3.10.1 人员身份归一——…` 分隔符后那段，
 * 直接把原文丢进 UI 会出现「版本列 v3.10.1 + 标题里又一个 v3.10.1」的重复。
 */
function cleanTitle(raw: string): string {
  let t = raw.replace(TYPE_RE, '').trim()
  t = t.replace(/^v?\d+(?:\.\d+)+[\s:：·|｜\-]*/i, '').trim()
  return t
}

/** 版本号统一带 `v`（后端 records 里是不带的数字串） */
function normalizeVersion(raw: string): string {
  const v = raw.trim()
  if (!v) return ''
  if (/^v/i.test(v)) return v
  return /^\d/.test(v) ? `v${v}` : v
}

/** 一条结构化记录 → 展示项（字段名按后端/历史命名多路兜底） */
function toEntry(raw: unknown): ChangelogEntry | null {
  const d = dict(raw)
  const version = normalizeVersion(firstText(d.version, d.tag, d.tagName, d.tag_name))
  const title = cleanTitle(firstText(d.title, d.name, d.summary, d.message, d.desc))
  const date = firstText(d.date, d.publishedAt, d.published_at, d.time, d.created_at)
  if (!version && !title) return null
  return { version: version || date, title: title || '更新', date }
}

/**
 * 从 CHANGELOG.md 原文里切出前 limit 条 `## ` 标题。
 * 形如：`## 2026-11-04 ｜ fix: v3.10.1 人员身份归一——三处姓名统一…`
 *  → {version:'v3.10.1', title:'人员身份归一——三处姓名统一…', date:'2026-11-04'}
 */
function fromMarkdown(md: string, limit: number): ChangelogEntry[] {
  if (!md) return []
  const out: ChangelogEntry[] = []
  for (const line of md.split(/\r?\n/)) {
    const m = HEAD_RE.exec(line.trim())
    if (!m) continue
    const head = m[1].trim()
    const date = DATE_RE.exec(head)?.[0] ?? ''
    const version = normalizeVersion(VERSION_RE.exec(head)?.[0] ?? '')

    // 去掉日期前缀 → 取分隔符后的正文 → 去掉类型前缀与重复版本号（版本单独一列展示）
    let title = head.replace(/^[\d\-\s]+/, '')
    const sep = title.search(/[｜|]/)
    if (sep >= 0) title = title.slice(sep + 1).trim()
    title = cleanTitle(title)

    if (!title && !version && !date) continue
    out.push({ version: version || date, title: title || head, date })
    if (out.length >= limit) break
  }
  return out
}

/** 把任意形状的响应收敛成最多 limit 条展示项（纯函数，页面不关心后端长什么样） */
export function normalizeChangelog(data: unknown, limit = 3): ChangelogEntry[] {
  const root = dict(data)

  const entries = list(root.records).length
    ? list(root.records)
    : list(root.entries).length
      ? list(root.entries)
      : list(root.releases).length
        ? list(root.releases)
        : list(root.list)

  if (entries.length) {
    const mapped = entries
      .map(toEntry)
      .filter((e): e is ChangelogEntry => e !== null)
      .slice(0, limit)
    if (mapped.length) return mapped
  }

  const fromContent = fromMarkdown(text(root.content), limit)
  if (fromContent.length) return fromContent

  const latest = toEntry(root.latest)
  return latest ? [latest] : []
}

/** GET /system/changelog?limit=3 → 最多 3 条「版本 · 标题」 */
export function fetchChangelog(limit = 3): Promise<ChangelogEntry[]> {
  return http
    .get<unknown>('/system/changelog', { limit }, { silent: true, timeout: 15000 })
    .then((data) => normalizeChangelog(data, limit))
}
