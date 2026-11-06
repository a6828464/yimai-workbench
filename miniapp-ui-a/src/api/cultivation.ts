/**
 * 新客培养（GET /new-members/cultivation）
 *
 * 口径对齐 backend/app/Http/Controllers/CustomerController.php::newMemberCultivation：
 *  - 窗口默认「今天往前 89 天 ~ 今天」（= 近 90 天入会），入会时间落在窗口内的客户才算新客；
 *  - 可见范围复用 scopeCustomersForUser()：店长锁本店、服务/授课老师锁本人，
 *    所以 `venue` 参数只对超管（及未被 scope 收窄的角色）真正生效；
 *  - **接口一次返回全量 records（非分页）**，分页只能在前端切片（方案 §5.3）；
 *  - `summary` 是窗口内全量新客的统计，`cardType` 只收窄 records，不改 summary——
 *    页面必须把这件事说明白，否则「筛了私教但统计没变」会被当成 bug。
 *
 * 类型只保留页面真正渲染的字段，不把后端整行拖进来。
 */

import { http } from '@/utils/http'

/** 课型：与后端 validate `cardType in:private,small,group` 一一对应 */
export type CultivationKind = 'private' | 'small' | 'group'

/** 健康度：0 节上课=idle，参与课型全部达标=cultured，其余=cultivating */
export type CultivationHealth = 'idle' | 'cultivating' | 'cultured'

/** 单课型统计：signed=已上，booked=已约未上，no_show=爽约，target=该课型养成目标节数 */
export interface CultivationCategory {
  signed: number
  booked: number
  no_show: number
  target: number
}

export interface CultivationRecord {
  id: number
  name: string
  /** 完整手机号（内部系统口径）；后端允许为空 */
  phone?: string | null
  phoneTail: string
  venue: string
  /** 入会日期 YYYY-MM-DD */
  enrolledAt: string
  /** 入会天数（后端 diffInDays，可能为负——入会日期填成未来时会显负数，页面按原样展示） */
  enrolledDays: number | null
  consultant: string
  mainCard: string
  categories: Record<CultivationKind, CultivationCategory>
  totalSigned: number
  /** 上课最多的课型（并列时按 private/small/group 顺序） */
  primaryKind: CultivationKind
  primaryProgress: { signed: number; target: number }
  /** 已上课程名 top6（后端已按次数降序） */
  themes: string[]
  health: CultivationHealth
}

export interface CultivationSummary {
  total: number
  /** 上过私教 / 小班 / 团课的**人数**（不是节数） */
  private: number
  small: number
  group: number
  idle: number
  cultivating: number
  cultured: number
}

export interface CultivationResult {
  records: CultivationRecord[]
  summary: CultivationSummary
  /** 养成目标节数（后台可调，来自 rules()） */
  targets: Record<CultivationKind, number>
  range: { start: string; end: string }
  /** KeepYoga 最近同步时间（非实时，页面标注「数据截至」用） */
  syncTime: string
}

export interface CultivationQuery {
  /** YYYY-MM-DD，默认今天往前 89 天 */
  start?: string
  /** YYYY-MM-DD，默认今天 */
  end?: string
  /** 仅绿地店 / 东部店（后端 in 校验，其它值 422） */
  venue?: string
  /** 姓名模糊 */
  name?: string
  cardType?: CultivationKind
}

/** 课型展示顺序与文案（页面 segmented、进度标签共用这一份） */
export const CULTIVATION_KINDS: { key: CultivationKind; label: string }[] = [
  { key: 'private', label: '私教' },
  { key: 'small', label: '小班' },
  { key: 'group', label: '团课' }
]

export const CULTIVATION_KIND_LABELS: Record<CultivationKind, string> = {
  private: '私教',
  small: '小班',
  group: '团课'
}

/** 健康度 → 文案 + 徽标语义色（ym-badge 的 tone 联合类型） */
export const CULTIVATION_HEALTH_META: Record<
  CultivationHealth,
  { label: string; tone: 'brand' | 'gold' | 'warn' | 'danger' | 'info' | 'muted' }
> = {
  idle: { label: '待激活', tone: 'muted' },
  cultivating: { label: '养成中', tone: 'brand' },
  cultured: { label: '已养成', tone: 'gold' }
}

/** 门店白名单：与后端 validate `venue in:绿地店,东部店` 同源 */
export const CULTIVATION_VENUES = ['绿地店', '东部店'] as const

/** GET /new-members/cultivation：空值不传（后端把空串当「不筛选」，但仍少一次编码更干净） */
export function fetchNewMemberCultivation(query: CultivationQuery = {}) {
  const data: Record<string, unknown> = {}
  Object.entries(query).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') data[key] = value
  })
  return http.get<CultivationResult>('/new-members/cultivation', data)
}
