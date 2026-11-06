/**
 * 归属选人（老师 / 会籍顾问）
 *
 * 后端：`GET /staff/options`（AccountController::staffOptions，**所有登录用户可读**）
 * 返回 `{staff:[{id,name,aliases,roles,venue,self}]}`。
 *
 * 为什么归属要选账号而不是手输：归属列（`leads.service_teacher` / `customers.consultant`）
 * 存的是**姓名字符串**，手输一个后端不认识的写法就等于「这条数据没有主人」——
 * 本人永远看不到。选账号写进去的是**规范名**，后端能立刻解析成 user_id。
 *
 * 手输仍然保留（历史写法 / 临时名字 / 接口不可用），但那是兜底，不是主路径。
 */
import { http } from '@/utils/http'

export interface StaffOption {
  id: number
  /** **规范名**：提交给后端的就是它（不是昵称） */
  name: string
  /** 本名 + 别名 + 安全昵称：搜索命中用，展示不用 */
  aliases: string[]
  roles: string[]
  venue: string | null
  /** 是否当前登录人 */
  self: boolean
}

type Dict = Record<string, unknown>

function dict(v: unknown): Dict {
  return v !== null && typeof v === 'object' && !Array.isArray(v) ? (v as Dict) : {}
}

function text(v: unknown): string {
  if (typeof v === 'string') return v.trim()
  if (typeof v === 'number') return String(v)
  return ''
}

function strList(v: unknown): string[] {
  if (!Array.isArray(v)) return []
  return v.map((x) => text(x)).filter(Boolean)
}

/**
 * 把响应收敛成 StaffOption[]（纯函数）。
 * 形状不对 / 空清单都返回 `[]`，调用方据此退回手输 —— 选人接口挂了不能挡住录入。
 */
export function normalizeStaffOptions(data: unknown): StaffOption[] {
  const root = dict(data)
  const raw = Array.isArray(root.staff) ? root.staff : Array.isArray(data) ? data : []
  const out: StaffOption[] = []
  for (const item of raw) {
    const d = dict(item)
    const name = text(d.name)
    if (!name) continue
    const id = typeof d.id === 'number' ? d.id : Number.parseInt(text(d.id), 10)
    out.push({
      id: Number.isFinite(id) ? id : 0,
      name,
      aliases: strList(d.aliases).filter((a) => a !== name),
      roles: strList(d.roles),
      venue: text(d.venue) || null,
      self: d.self === true
    })
  }
  return out
}

/** GET /staff/options（静默：失败只影响选人面板，不弹错误） */
export function fetchStaffOptions(): Promise<StaffOption[]> {
  return http
    .get<unknown>('/staff/options', undefined, { silent: true })
    .then((data) => normalizeStaffOptions(data))
}

/**
 * 关键字过滤：命中规范名或任一别名（不区分大小写）。
 * 空关键字返回原清单（顺序仍是后端给的 id 顺序，不做重排）。
 */
export function matchStaff(list: StaffOption[], keyword: string): StaffOption[] {
  const k = (keyword ?? '').trim().toLowerCase()
  if (!k) return list
  return list.filter((s) =>
    [s.name, ...s.aliases].some((n) => n.toLowerCase().includes(k))
  )
}
