/**
 * 小程序侧的功能注册表（**只管页面元信息，不管角色**）
 *
 * 角色 → 角色集合 → 功能可见性的唯一事实来源是仓库根的
 * `packages/shared-types/roles.json`，网页端路由（admin-web/src/router/modules/yimai.ts）
 * 与本文件都从它派生。本文件**不允许**再出现「'R_SUPER', 'R_MANAGER' …」这样的角色数组字面量 ——
 * 那正是收敛前四份事实源的来源（网页端 router、网页端 api、本文件、后端内联判定）。
 *
 * 为什么不是请求后端菜单（`/me` 下发 features[]）：
 *  1. 后端 /me 只下发 roles（`profilePayload()`），没有功能/入口概念 —— 它的鉴权是数据范围
 *     （scopeCustomersForUser / scopeLeadsForUser / userHasRole 逐处内联），从没有「功能 id → 角色」
 *     这张表。让 /me 下发 features[] 等于让后端**新造**一张 PHP 侧功能表：那是增加第四份事实源，
 *     不是收敛。
 *  2. 而且它删不掉网页端 31 条路由的 meta.roles（31 条路由 ≠ 15 个小程序功能），两端仍然各自定义。
 *  3. 部署拓扑上 packages/ 不进后端发布包（make-release.sh 只打 backend/），后端运行时读不到它。
 *  所以采用「两端构建时共同引用同一份 JSON」。详见 roles.json 的 _readme。
 *
 * 冷启动同步渲染仍是硬要求：roles.json 是**编译期**依赖（打进包里），不是网络往返，
 * 因此不会出现「先闪出无权入口再消失」。
 * 真正的数据边界仍由后端守卫，本文件只决定「显不显示入口」——前端裁剪永远不是安全边界，只是体验。
 */

import rolesMatrix from '../../../packages/shared-types/roles.json'

export type RoleCode = 'R_SUPER' | 'R_MANAGER' | 'R_SERVICE' | 'R_TEACHER' | 'R_MEDIA'

type GroupName = 'ALL' | 'OPS' | 'TEACHING' | 'MGMT' | 'SUPER'

/**
 * 具名角色集合（来自 roles.json）。
 *
 * 断言只看 roles.json 是否存在这些键：JSON 的静态类型是 `string[]`，所以这里必须显式收窄成
 * `RoleCode[]`。若 roles.json 里混进了不存在的角色码，`verify/permission-matrix.test.mjs`
 * 的「5 个角色常量齐全」用例会当场失败（它把这个数组与期望值逐字比对），不需要在这里再抄一份。
 */
const GROUPS = rolesMatrix.groups as Record<GroupName, RoleCode[]>

/** 功能 → 具名组（roles.json 的 featureGroups）。这里只查表，不写角色码。 */
const FEATURE_GROUP = rolesMatrix.featureGroups as Record<string, GroupName>

/** 取某个功能的允许角色集合。组名写错时**当场抛错**，不要静默退化成「人人可见」。 */
function rolesOfFeature(key: string): RoleCode[] {
  const group = FEATURE_GROUP[key]
  const roles = group ? GROUPS[group] : undefined
  if (!roles) {
    throw new Error(`[permission] 功能 ${key} 的角色组 ${String(group)} 未在 roles.json 定义`)
  }
  // 返回副本：调用方（页面）若对返回值排序/裁剪，不能污染全局注册表。
  return [...roles]
}

export const ALL_ROLES: RoleCode[] = [...GROUPS.ALL]
/** 运营岗：除新媒体外的全部角色 */
export const OPS_ROLES: RoleCode[] = [...GROUPS.OPS]
export const TEACHING_ROLES: RoleCode[] = [...GROUPS.TEACHING]
export const MGMT_ROLES: RoleCode[] = [...GROUPS.MGMT]
export const SUPER_ROLES: RoleCode[] = [...GROUPS.SUPER]

export const ROLE_LABELS: Record<RoleCode, string> = {
  R_SUPER: '超级管理员',
  R_MANAGER: '店长',
  R_SERVICE: '服务老师',
  R_TEACHER: '授课老师',
  R_MEDIA: '新媒体'
}

/** 角色短标签（空间有限处用） */
export const ROLE_SHORT: Record<RoleCode, string> = {
  R_SUPER: '超管',
  R_MANAGER: '店长',
  R_SERVICE: '服务',
  R_TEACHER: '授课',
  R_MEDIA: '新媒体'
}

export type AreaKey = 'crm' | 'biz' | 'ai' | 'me'

export interface FeatureDef {
  key: string
  title: string
  desc: string
  /** wd-icon 名称（已核对组件库字体表，勿写不存在的名字） */
  icon: string
  path: string
  area: AreaKey
  roles: RoleCode[]
  /** 是否支持角标（页面自行填数） */
  badge?: boolean
  /** 入口主色（视觉分层用） */
  tone?: 'brand' | 'gold' | 'info' | 'plain'
}

type FeatureMeta = Omit<FeatureDef, 'roles'>

/**
 * 功能注册表。新增页面必须在这里登记 **元信息**，并在 roles.json 的 featureGroups 里登记它走哪个
 * 具名角色组；漏了任一边都会被 verify/permission-matrix.test.mjs 拦下。
 *
 * 角色不写在下面每个字面量里，而是由 withRoles() 从 roles.json 统一挂上 —— 这样「15 个功能各自
 * 谁能看见」只在一处（JSON）维护，本文件改不动权限，只改文案/图标/路径。
 */
function withRoles(meta: Record<string, FeatureMeta>): Record<string, FeatureDef> {
  const out: Record<string, FeatureDef> = {}
  for (const key of Object.keys(meta)) out[key] = { ...meta[key], roles: rolesOfFeature(key) }
  return out
}

export const FEATURES: Record<string, FeatureDef> = withRoles({
  /* ---------- 客户经营 ---------- */
  todo: {
    key: 'todo',
    title: '今日待办',
    desc: '今天必须闭环的动作',
    icon: 'check-circle',
    path: '/pkg-biz/todo/todo',
    area: 'crm',
    badge: true,
    tone: 'brand'
  },
  members: {
    key: 'members',
    title: '会员管理',
    desc: '七清单 · 客户 360',
    icon: 'usergroup',
    path: '/pkg-crm/members/members',
    area: 'crm',
    tone: 'brand'
  },
  newMembers: {
    key: 'newMembers',
    title: '新客培养',
    desc: '入会 90 天养成进度',
    icon: 'gift',
    path: '/pkg-crm/crm/new-members',
    area: 'crm',
    tone: 'brand'
  },
  leads: {
    key: 'leads',
    title: '留资管理',
    desc: '客资登记与跟进',
    icon: 'chat',
    path: '/pkg-crm/leads/leads',
    area: 'crm',
    tone: 'gold'
  },
  pool: {
    key: 'pool',
    title: '客户经营池',
    desc: '全量客户 · 分层筛选',
    icon: 'layers',
    path: '/pkg-crm/crm/pool',
    area: 'crm',
    tone: 'gold'
  },

  /* ---------- 业务执行 ---------- */
  tasks: {
    key: 'tasks',
    title: '任务中心',
    desc: '任务接收与闭环',
    icon: 'file',
    path: '/pkg-biz/tasks/tasks',
    area: 'biz'
  },
  approvals: {
    key: 'approvals',
    title: '价格审批',
    desc: '初审 / 终审 / 驳回',
    icon: 'evaluation',
    path: '/pkg-biz/approvals/approvals',
    area: 'biz'
  },
  reviews: {
    key: 'reviews',
    title: '课后分析',
    desc: '体验课交付凭证',
    icon: 'note',
    path: '/pkg-biz/review/review',
    area: 'biz',
    badge: true
  },
  plans: {
    key: 'plans',
    title: '训练计划',
    desc: '会员训练编排',
    icon: 'books',
    path: '/pkg-biz/plan/plan',
    area: 'biz'
  },

  /* ---------- AI 助手 ---------- */
  marketing: {
    key: 'marketing',
    title: '营销工具',
    desc: '朋友圈 / 小红书文案',
    icon: 'chat1',
    path: '/pkg-ai/ai/marketing',
    area: 'ai',
    tone: 'brand'
  },
  sales: {
    key: 'sales',
    title: '谈单工具',
    desc: '话术库 + 客户分享页',
    icon: 'money-circle',
    path: '/pkg-ai/ai/sales',
    area: 'ai',
    tone: 'gold'
  },

  /* ---------- 我的 ---------- */
  profileEdit: {
    key: 'profileEdit',
    title: '个人信息',
    desc: '昵称 / 手机 / 头像',
    icon: 'edit',
    path: '/pkg-me/profile/edit',
    area: 'me'
  },
  notifications: {
    key: 'notifications',
    title: '模板通知',
    desc: '新客资 / 客户分配提醒',
    icon: 'notification',
    path: '/pkg-me/profile/notify',
    area: 'me'
  },
  persona: {
    key: 'persona',
    title: '人设设置',
    desc: '营销文案用的人设与受众',
    icon: 'user-avatar',
    path: '/pkg-me/profile/persona',
    area: 'me'
  },
  password: {
    key: 'password',
    title: '登录密码',
    desc: '修改密码',
    icon: 'lock-on',
    path: '/pkg-me/profile/edit?tab=password',
    area: 'me'
  }
})

/** 角色是否命中允许集合（allowed 为空 = 不限制） */
export function hasAnyRole(roles: string[] | undefined | null, allowed?: RoleCode[]): boolean {
  if (!allowed || allowed.length === 0) return true
  const mine = roles ?? []
  return allowed.some((r) => mine.includes(r))
}

/**
 * 主角色：超管 > 店长 > 服务 > 授课 > 新媒体。
 *
 * ⚠️ 这个顺序**故意没有**放进 roles.json，也**故意不与后端一致**：
 * 后端 `helpers.php` 的 `ROLE_PRECEDENCE` 是 `SUPER > MANAGER > TEACHER > SERVICE > MEDIA`
 * （授课在服务之前），本文件是 `SUPER > MANAGER > SERVICE > TEACHER > MEDIA`。
 * 对「同时拥有 R_SERVICE + R_TEACHER」的账号，两端会给出不同的主角色标签
 * （后端写进 `profilePayload()['role']`；小程序用于「超管 · 店长」这类短标签）。
 * 这是收敛前就存在的既存分歧，本次收敛**不修**：改任何一端都会改变用户看到的角色文案，
 * 属于「改变现有用户可见行为」，须先由馆主确认哪个顺序才对。已登记为审计发现。
 */
export function primaryRole(roles: string[] | undefined | null): RoleCode | null {
  const mine = roles ?? []
  const order: RoleCode[] = ['R_SUPER', 'R_MANAGER', 'R_SERVICE', 'R_TEACHER', 'R_MEDIA']
  return order.find((r) => mine.includes(r)) ?? null
}

export function roleLabel(roles: string[] | undefined | null): string {
  const mine = roles ?? []
  if (!mine.length) return '未分配角色'
  return mine
    .map((r) => ROLE_SHORT[r as RoleCode] ?? r)
    .filter(Boolean)
    .join(' · ')
}

export function canUse(roles: string[] | undefined | null, key: string): boolean {
  const f = FEATURES[key]
  if (!f) return false
  return hasAnyRole(roles, f.roles)
}

/** 按模块取可见入口（保持注册表顺序，= 产品定义的优先级） */
export function visibleFeatures(roles: string[] | undefined | null, area?: AreaKey): FeatureDef[] {
  return Object.values(FEATURES).filter(
    (f) => (!area || f.area === area) && hasAnyRole(roles, f.roles)
  )
}

/** 是否有任一角色（页面内做细粒度区块控制用） */
export function isSuper(roles: string[] | undefined | null): boolean {
  return (roles ?? []).includes('R_SUPER')
}

export function isManagerOrAbove(roles: string[] | undefined | null): boolean {
  return hasAnyRole(roles, MGMT_ROLES)
}

export function isMediaOnly(roles: string[] | undefined | null): boolean {
  const mine = roles ?? []
  return mine.includes('R_MEDIA') && !mine.some((r) => r !== 'R_MEDIA')
}
