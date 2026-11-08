/**
 * 博客栏目（到店指引页底部 / 选门店中间页底部）。
 *
 * 数据链路：小程序 → oa.nbyimai.com/api/miniapp/blog（Laravel 代理 + 5 分钟缓存）
 * → 同机官网 WP REST。**小程序不直连 WP**：request 合法域名不用加，WP 也不吃
 * 小程序流量（详见后端 MiniappBlogController 的头注释）。
 *
 * 本文件只做「拿到什么原样交出去」；空态（WP 挂了 → items: []）由页面判「不渲染栏目」，
 * 这里不抛错不 toast —— 顾客页面永远不因博客挂了而报错（与到店指引同一条降级哲学）。
 */
import { API_BASE, HttpError, getToken } from '@/utils/http'

/** 栏目卡片（guide 页用；cover 可能为空 = 无特色图，页面按无图样式渲染） */
export interface BlogCard {
  id: number
  title: string
  /** YYYY-MM-DD（后端已截断到天） */
  date: string
  /** 纯文本摘要（后端已剥 HTML、80 字截断） */
  excerpt: string
  cover: string
  /** WP 原文链接（站内文章页兜底「到官网看原文」用） */
  link: string
  sticky: boolean
}

/** 单篇全文（文章页用；content 是 WP 渲染好的 HTML，rich-text 直接吃） */
export interface BlogArticle extends BlogCard {
  content: string
}

export interface BlogListResult {
  items: BlogCard[]
  /** live=刚问过 WP；cache=命中缓存；empty=WP 不可用（页面应隐藏栏目） */
  source: 'live' | 'cache' | 'empty'
}

const DEFAULT_TIMEOUT = 6000

/** GET /miniapp/blog?limit=3 —— 失败一律返回 { items: [], source: 'empty' }，绝不 reject */
export function fetchBlogCards(limit = 3, timeout = DEFAULT_TIMEOUT): Promise<BlogListResult> {
  const token = getToken()
  return new Promise<BlogListResult>((resolve) => {
    uni.request({
      url: `${API_BASE}/miniapp/blog?limit=${limit}`,
      method: 'GET',
      timeout,
      header: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(token ? { Authorization: `Bearer ${token}` } : {})
      },
      success: (res) => {
        const status = res.statusCode
        if (status < 200 || status >= 300) {
          // 不 reject：栏目是可选项，失败 = 今天不显示
          resolve({ items: [], source: 'empty' })
          return
        }
        const body = res.data as { code?: number; data?: { items?: unknown; source?: unknown } }
        const rawItems = Array.isArray(body?.data?.items) ? body.data.items : []
        resolve({
          items: rawItems.map(normalizeCard).filter((c): c is BlogCard => c !== null),
          source: body?.data?.source === 'live' || body?.data?.source === 'cache' ? body.data.source : 'empty'
        })
      },
      fail: () => resolve({ items: [], source: 'empty' })
    })
  })
}

/** GET /miniapp/blog/{id} —— 单篇全文；失败 reject（文章页有明确的错误态要展示） */
export function fetchBlogArticle(id: number, timeout = 10000): Promise<BlogArticle> {
  return new Promise<BlogArticle>((resolve, reject) => {
    uni.request({
      url: `${API_BASE}/miniapp/blog/${id}`,
      method: 'GET',
      timeout,
      header: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      success: (res) => {
        const status = res.statusCode
        if (status === 404) {
          reject(new HttpError('文章不存在或已下架', 404, 404))
          return
        }
        if (status < 200 || status >= 300) {
          reject(new HttpError(`文章加载失败（${status}）`, status, status))
          return
        }
        const body = res.data as { code?: number; data?: unknown }
        const a = normalizeArticle(body?.data)
        if (!a) {
          reject(new HttpError('文章数据格式异常', -1, -1))
          return
        }
        resolve(a)
      },
      fail: () => reject(new HttpError('文章请求失败', -1, -1))
    })
  })
}

// ------------------------------------------------------------------ 归一

function str(v: unknown): string {
  if (typeof v === 'string') return v.trim()
  if (v === null || v === undefined) return ''
  return String(v).trim()
}

function normalizeCard(raw: unknown): BlogCard | null {
  const o = (raw ?? {}) as Record<string, unknown>
  const id = Number(o.id)
  const title = str(o.title)
  if (!Number.isFinite(id) || id <= 0 || !title) return null

  return {
    id,
    title,
    date: str(o.date),
    excerpt: str(o.excerpt),
    cover: str(o.cover),
    link: str(o.link),
    sticky: o.sticky === true
  }
}

function normalizeArticle(raw: unknown): BlogArticle | null {
  const o = (raw ?? {}) as Record<string, unknown>
  const card = normalizeCard(o)
  if (!card) return null

  return { ...card, content: str(o.content) }
}
