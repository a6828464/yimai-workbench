/**
 * 权限矩阵（唯一事实来源）
 *
 * 口径逐条对齐 admin-web/src/router/modules/yimai.ts 的 meta.roles：
 *   ALL      = SUPER / MANAGER / SERVICE / TEACHER / MEDIA
 *   OPS      = SUPER / MANAGER / SERVICE / TEACHER（不含新媒体）
 *   TEACHING = SUPER / MANAGER / TEACHER
 *   MGMT     = SUPER / MANAGER
 *   SUPER    = R_SUPER
 *
 * 为什么在小程序里再写一份而不是请求后端菜单：
 *  1. 后端 /me 只下发 roles，没有菜单接口；小程序 tab 与页面入口必须在冷启动时同步渲染，
 *     不能等一次网络往返（否则先闪出无权入口再消失，比不显示更糟）。
 *  2. 真正的数据边界仍由后端 scopeCustomersForUser / scopeLeadsForUser 等守卫，
 *     本文件只决定「显不显示入口」——前端裁剪永远不是安全边界，只是体验。
 */

export type RoleCode = 'R_SUPER' | 'R_MANAGER' | 'R_SERVICE' | 'R_TEACHER' | 'R_MEDIA'

export const ALL_ROLES: RoleCode[] = ['R_SUPER', 'R_MANAGER', 'R_SERVICE', 'R_TEACHER', 'R_MEDIA']
/** 运营岗：除新媒体外的全部角色 */
export const OPS_ROLES: RoleCode[] = ['R_SUPER', 'R_MANAGER', 'R_SERVICE', 'R_TEACHER']
export const TEACHING_ROLES: RoleCode[] = ['R_SUPER', 'R_MANAGER', 'R_TEACHER']
export const MGMT_ROLES: RoleCode[] = ['R_SUPER', 'R_MANAGER']
export const SUPER_ROLES: RoleCode[] = ['R_SUPER']

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

/**
 * 功能注册表。新增页面必须在这里登记角色，否则 visibleFeatures() 看不到它。
 */
export const FEATURES: Record<string, FeatureDef> = {
  /* ---------- 客户经营 ---------- */
  todo: {
    key: 'todo',
    title: '今日待办',
    desc: '今天必须闭环的动作',
    icon: 'check-circle',
    path: '/pages/todo/todo',
    area: 'crm',
    roles: ALL_ROLES,
    badge: true,
    tone: 'brand'
  },
  members: {
    key: 'members',
    title: '会员管理',
    desc: '七清单 · 客户 360',
    icon: 'usergroup',
    path: '/pages/members/members',
    area: 'crm',
    roles: OPS_ROLES,
    tone: 'brand'
  },
  newMembers: {
    key: 'newMembers',
    title: '新客培养',
    desc: '入会 90 天养成进度',
    icon: 'gift',
    path: '/pages/crm/new-members',
    area: 'crm',
    roles: ALL_ROLES,
    tone: 'brand'
  },
  leads: {
    key: 'leads',
    title: '留资管理',
    desc: '客资登记与跟进',
    icon: 'chat',
    path: '/pages/leads/leads',
    area: 'crm',
    roles: ALL_ROLES,
    tone: 'gold'
  },
  pool: {
    key: 'pool',
    title: '客户经营池',
    desc: '全量客户 · 分层筛选',
    icon: 'layers',
    path: '/pages/crm/pool',
    area: 'crm',
    roles: SUPER_ROLES,
    tone: 'gold'
  },

  /* ---------- 业务执行 ---------- */
  tasks: {
    key: 'tasks',
    title: '任务中心',
    desc: '任务接收与闭环',
    icon: 'file',
    path: '/pages/tasks/tasks',
    area: 'biz',
    roles: OPS_ROLES
  },
  approvals: {
    key: 'approvals',
    title: '价格审批',
    desc: '初审 / 终审 / 驳回',
    icon: 'evaluation',
    path: '/pages/approvals/approvals',
    area: 'biz',
    roles: MGMT_ROLES
  },
  reviews: {
    key: 'reviews',
    title: '课后分析',
    desc: '体验课交付凭证',
    icon: 'note',
    path: '/pages/review/review',
    area: 'biz',
    roles: OPS_ROLES,
    badge: true
  },
  plans: {
    key: 'plans',
    title: '训练计划',
    desc: '会员训练编排',
    icon: 'books',
    path: '/pages/plan/plan',
    area: 'biz',
    roles: TEACHING_ROLES
  },

  /* ---------- AI 助手 ---------- */
  marketing: {
    key: 'marketing',
    title: '营销工具',
    desc: '朋友圈 / 小红书文案',
    icon: 'chat1',
    path: '/pages/ai/marketing',
    area: 'ai',
    roles: ALL_ROLES,
    tone: 'brand'
  },
  sales: {
    key: 'sales',
    title: '谈单工具',
    desc: '话术库 + 客户分享页',
    icon: 'money-circle',
    path: '/pages/ai/sales',
    area: 'ai',
    roles: MGMT_ROLES,
    tone: 'gold'
  },

  /* ---------- 我的 ---------- */
  profileEdit: {
    key: 'profileEdit',
    title: '个人信息',
    desc: '昵称 / 手机 / 头像',
    icon: 'edit',
    path: '/pages/profile/edit',
    area: 'me',
    roles: ALL_ROLES
  },
  notifications: {
    key: 'notifications',
    title: '模板通知',
    desc: '新客资 / 客户分配提醒',
    icon: 'notification',
    path: '/pages/profile/notify',
    area: 'me',
    roles: ALL_ROLES
  },
  persona: {
    key: 'persona',
    title: '人设设置',
    desc: '营销文案用的人设与受众',
    icon: 'user-avatar',
    path: '/pages/profile/persona',
    area: 'me',
    roles: ALL_ROLES
  },
  password: {
    key: 'password',
    title: '登录密码',
    desc: '修改密码',
    icon: 'lock-on',
    path: '/pages/profile/edit?tab=password',
    area: 'me',
    roles: ALL_ROLES
  }
}

/** 角色是否命中允许集合（allowed 为空 = 不限制） */
export function hasAnyRole(roles: string[] | undefined | null, allowed?: RoleCode[]): boolean {
  if (!allowed || allowed.length === 0) return true
  const mine = roles ?? []
  return allowed.some((r) => mine.includes(r))
}

/** 主角色（与后端 primaryRole 同序：超管 > 店长 > 服务 > 授课 > 新媒体） */
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
