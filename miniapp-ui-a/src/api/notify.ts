/**
 * 微信订阅消息（模板通知）接口（E 任务独占）
 *
 * 契约唯一来源：`docs/miniapp-migration/11-工作台四板块重构方案.md` §6.3。
 * 后端落地在 A 任务（`WxSubscriptionController`），本文件按契约写死形状，
 * 后端未上线时**不报错也不白屏**——页面按 `configured=false` 整页说明处理。
 *
 * 语义三条（照方案抄）：
 *  1. GET  `{configured, bound, templates:[{key,title,desc,enabled,templateId,accepted,remain,lastAcceptedAt,fields}], updatedAt}`
 *     `configured=false` = 管理员还没配模板 id；此时 templates 里每项 enabled=false、templateId 为空，
 *     页面按「待管理员配置模板」整页说明处理（不是错误）。
 *  2. POST `{accepts:string[], denies:string[]}` —— 传的是**模板 key**（不是 templateId），
 *     accepts 里每个 key 后端 remain+1、total_accepted 累加。
 *  3. POST `/wx/subscriptions/test {key}` → `{sent, message}`；未配置/未绑定后端回 422 可读文案。
 */
import { http } from '@/utils/http'

/**
 * 跨页一次性握手标记：「模板通知」页要引导用户去绑定微信，但「我的」是 tab 页，
 * `switchTab` 会丢掉 query。于是 notify 写这个标记 → profile 的 onShow 读到就展开微信面板并清掉。
 * 它不是接口数据，放这里只是为了让两个页面共用一个常量、不各写一份字面量。
 */
export const WX_BIND_INTENT_KEY = 'yimai-open-wx-bind'

export interface WxTemplateItem {
  /** 模板键，如 leadAssigned / memberAssigned / todoReminder */
  key: string
  title: string
  desc: string
  /** 模板是否启用（管理员可单独关掉某一条） */
  enabled: boolean
  /** 微信后台申请的模板 id；为空 = 未配置 */
  templateId: string
  /** 这个账号是否授权过（== remain 曾 +1 过） */
  accepted: boolean
  /** 剩余可发送次数（一次性订阅每同意一次 +1，发送一次 -1） */
  remain: number
  lastAcceptedAt?: string | null
  /** 模板字段名清单，页面暂不渲染，留给排障看 */
  fields?: string[]
}

export interface WxSubscriptionsResult {
  /** 后端是否配了模板（config/services.php → wechat.templates） */
  configured: boolean
  /** 当前账号是否已绑定微信（未绑定就不可能收到订阅消息） */
  bound: boolean
  templates: WxTemplateItem[]
  updatedAt?: string | null
}

export interface WxSubscriptionReport {
  accepts?: string[]
  denies?: string[]
}

/** POST /wx/subscriptions 的回执：`accepted` 是后端真正记账的键（未登记的键会被静默忽略） */
export interface WxSubscriptionReportResult {
  templates: WxTemplateItem[]
  accepted?: string[]
}

export interface WxSubscriptionTestResult {
  sent: boolean
  message?: string
}

/** GET /wx/subscriptions */
export function fetchWxSubscriptions() {
  return http.get<WxSubscriptionsResult>('/wx/subscriptions', undefined, { silent: true })
}

/** POST /wx/subscriptions：把授权结果回写后端（key 维度） */
export function reportWxSubscriptions(body: WxSubscriptionReport) {
  return http.post<WxSubscriptionReportResult>('/wx/subscriptions', body as Record<string, unknown>, {
    silent: true
  })
}

/** POST /wx/subscriptions/test：给自己发一条测试提醒 */
export function testWxSubscription(key: string) {
  return http.post<WxSubscriptionTestResult>('/wx/subscriptions/test', { key }, { silent: true })
}

/** 「已开启 n 项」口径：启用 + 有模板 id + remain > 0 才算真的能收到 */
export function countEnabledTemplates(res: WxSubscriptionsResult | null): number {
  if (!res) return 0
  return res.templates.filter((t) => t.enabled && !!t.templateId && t.remain > 0).length
}

/** 逐项的阶段文案（未配置 / 已停用 / 未授权 / 已授权剩 n 次） */
export function templateStatus(t: WxTemplateItem): {
  text: string
  tone: 'brand' | 'warn' | 'muted'
} {
  if (!t.templateId) return { text: '未配置', tone: 'muted' }
  if (!t.enabled) return { text: '已停用', tone: 'muted' }
  if (t.remain > 0) return { text: `已授权 · 剩 ${t.remain} 次`, tone: 'brand' }
  // 「从没授权」和「授权过但已用完」引导完全不同（后端 accepted 与 remain 分开给就是这个原因）
  if (t.accepted) return { text: '已用完', tone: 'warn' }
  return { text: '未授权', tone: 'warn' }
}
