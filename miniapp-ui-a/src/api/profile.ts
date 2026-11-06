/**
 * 个人中心接口（E/t13 任务独占）
 *
 * 后端：`backend/app/Http/Controllers/ProfileController.php`
 *  - GET  /my/profile → `profilePayload($user)`：{name,nickname,phone,avatar,email,role,roles,venues,profile}
 *  - PUT  /my/profile → 同上（`array_key_exists` 逐字段判断，**没传的字段不动**）
 *  - PUT  /my/password `{oldPassword,newPassword}` → {ok:true}，改密后其他设备 token 全删
 *
 * ## 邮箱已在小程序端下线（2026-11，产品口径）
 * 后端仍会在 `profilePayload` 里返回 `email`（老账号有值），但小程序**不展示、不提交**：
 *  - `ProfileFormValues` 里没有 email；
 *  - `buildProfilePatch` 永不产出 email 键（PUT 时该字段保持原值，不会被清空）；
 *  - 页面（我的 / 个人信息）都不再出现邮箱。
 * 这样管理员改成别的登录标识时，不需要跟着改小程序。
 *
 * ## 三处口径直接照后端抄，别在页面里重写
 *  1. nickname `nullable|string|max:30`，空串由后端 trim 成 null（显示名回落 name）；
 *  2. phone 必须匹配 `/^\+?[0-9][0-9\s-]{5,19}$/`；
 *  3. avatar 是 `data:image/(jpeg|png|webp);base64,...`，字符数 > 300000 直接 422。
 */
import { http } from '@/utils/http'
import { getJSON, setJSON } from '@/utils/storage'
import type { MyProfile } from '@/store/user'

export type { MyProfile }

/**
 * 人设的本地快照（key: yimai-persona-cache）。
 *
 * 为什么要在 api 层落一份：营销工具的提示词构建是**同步**的，它读不到异步请求。
 * 走 pinia 的话冷启动时 `user.profile` 还是 null（只有访问过「我的/人设/通知」页才被填），
 * 于是同一台设备上「先开营销工具」和「先开个人信息」会得到不同的提示词 —— 这种不一致最难查。
 * 这里把每次 GET/PUT /my/profile 的 persona 落进 storage，营销侧就能稳定读到上次的值。
 */
const PERSONA_CACHE_KEY = 'yimai-persona-cache'

function cachePersona(p: MyProfile | null): void {
  try {
    const settings = personaFromProfile(p)
    setJSON(PERSONA_CACHE_KEY, { role: settings.role, audiences: settings.audiences })
  } catch {
    /* 缓存失败不影响主流程 */
  }
}

/** 读本地人设快照（没有或损坏都返回 null，由调用方兜底） */
export function cachedPersonaSnapshot(): { role: string; audiences: string[] } | null {
  const raw = getJSON<{ role?: unknown; audiences?: unknown }>(PERSONA_CACHE_KEY)
  if (!raw || typeof raw !== 'object') return null
  const role = typeof raw.role === 'string' ? raw.role : ''
  const audiences = Array.isArray(raw.audiences)
    ? raw.audiences.map((x) => (typeof x === 'string' ? x : '')).filter(Boolean)
    : []
  if (!role && !audiences.length) return null
  return { role, audiences }
}

/** GET /my/profile */
export function fetchMyProfile() {
  return http.get<MyProfile>('/my/profile', undefined, { silent: true }).then((p) => {
    cachePersona(p)
    return p
  })
}

/**
 * PUT /my/profile 的白名单字段（后端只认这几个）。
 * 刻意**不含 email**：小程序端已下线邮箱，避免任何调用点顺手把它写回去。
 */
export interface MyProfilePatch {
  nickname?: string | null
  phone?: string | null
  avatar?: string | null
  profile?: Record<string, unknown>
}

/** PUT /my/profile：只提交改动字段 */
export function updateMyProfile(patch: MyProfilePatch) {
  return http
    .put<MyProfile>('/my/profile', patch as Record<string, unknown>, { silent: true })
    .then((p) => {
      cachePersona(p)
      return p
    })
}

export interface PasswordForm {
  oldPassword: string
  newPassword: string
}

/** PUT /my/password */
export function updateMyPassword(form: PasswordForm) {
  return http.put<{ ok: boolean }>('/my/password', form as unknown as Record<string, unknown>, {
    silent: true
  })
}

/* ==========================================================================
 * 个人信息表单（昵称 / 手机 / 头像）
 * ========================================================================== */

/** 表单里编辑中的字段（与 MyProfile 解耦，避免把 role/roles/email 带进请求体） */
export interface ProfileFormValues {
  nickname: string
  phone: string
  avatar: string
}

/** 后端的手机号正则（逐字符对齐 ProfileController::updateMyProfile） */
export const PHONE_PATTERN = /^\+?[0-9][0-9\s-]{5,19}$/

/**
 * 用 `GET /my/profile` 的结果回填表单。
 * 昵称为空时用 name 兜底——后端也是这么显示的（显示名 = 昵称 ?: 姓名）。
 */
export function profileToForm(p: MyProfile | null): ProfileFormValues {
  return {
    nickname: (p?.nickname ?? p?.name ?? '').toString(),
    phone: (p?.phone ?? '').toString(),
    avatar: (p?.avatar ?? '').toString()
  }
}

/**
 * 只把**真正改了**的字段挑出来（后端对未传字段是「不修改」语义，
 * 所以整表 PUT 会把用户没碰过的字段一起覆盖，属于多余写操作）。
 *
 * 两条边界：
 *  - 昵称清空 → 传 null（后端 trim 成 null，显示名回落姓名）；
 *  - 手机清空 → 传 null。
 * 邮箱不参与（已在小程序端下线，见文件头）。
 */
export function buildProfilePatch(origin: MyProfile | null, next: ProfileFormValues): MyProfilePatch {
  const patch: MyProfilePatch = {}

  const originNickname = (origin?.nickname ?? origin?.name ?? '').toString().trim()
  const nextNickname = next.nickname.trim()
  if (nextNickname !== originNickname) {
    patch.nickname = nextNickname === '' ? null : nextNickname
  }

  const originPhone = (origin?.phone ?? '').toString().trim()
  const nextPhone = next.phone.trim()
  if (nextPhone !== originPhone) {
    patch.phone = nextPhone === '' ? null : nextPhone
  }

  const originAvatar = (origin?.avatar ?? '').toString()
  const nextAvatar = next.avatar.toString().trim()
  if (nextAvatar !== originAvatar) {
    patch.avatar = nextAvatar === '' ? null : nextAvatar
  }

  return patch
}

export interface ProfileFormError {
  field: keyof ProfileFormValues
  message: string
}

/**
 * 前端预校验（后端还会再校验一次，这里只为「不白跑一趟网络」+ 错误落在具体输入框）。
 * 顺序与表单视觉顺序一致，返回第一个错误。
 */
export function validateProfileForm(form: ProfileFormValues): ProfileFormError | null {
  const nickname = form.nickname.trim()
  // 后端 max:30 是字符数；用 Array.from 按码点算，中文不会算成 2 个
  if (Array.from(nickname).length > 30) {
    return { field: 'nickname', message: '昵称最多 30 个字' }
  }
  const phone = form.phone.trim()
  if (phone && !PHONE_PATTERN.test(phone)) {
    return { field: 'phone', message: '手机号格式不对，例如 13800138000' }
  }
  const avatar = form.avatar.trim()
  if (avatar.startsWith('data:') && avatar.length > 300000) {
    return { field: 'avatar', message: '头像图片过大，请重新选择' }
  }
  return null
}

/* ==========================================================================
 * 人设设置（profile.persona / profile.xhs）
 *
 * 字段口径来自后端 ProfileController::updateMyProfile 的校验规则：
 *  profile.persona.role        string|max:30
 *  profile.persona.audiences   array|max:20，每项 string|max:60
 *  profile.xhs.ipType          in:个人IP,门店IP
 *  profile.xhs.accountName     string|max:60
 *  profile.xhs.role            string|max:30   （本页只读带过，不编辑）
 *  profile.xhs.audiences       array|max:20    （本页只读带过，不编辑）
 *  profile.xhs.style           string|max:30
 *  profile.xhs.conversion      string|max:30
 *  profile.xhs.localFocus      boolean
 * ========================================================================== */

/** 身份角色（picker 选项）。与 admin-web 的 PERSONA_ROLES / api/marketing.ts 的 PERSONA_ROLES 同值。 */
export const PERSONA_ROLE_OPTIONS = ['会籍顾问', '全职老师', '门店前台', '管理层', '主理人']

/** 目标人群（多选 chips，常见八类；后端不限枚举，允许以后扩充） */
export const PERSONA_AUDIENCES = [
  '减脂塑形',
  '产后修复',
  '肩颈腰背',
  '体态改善',
  '瑜伽入门',
  '普拉提器械',
  '中老年',
  '孕产'
]

/** 小红书 IP 类型（后端 in: 只有这两个值） */
export const XHS_IP_TYPES = ['个人IP', '门店IP']

/** 小红书表达风格（后端只限长 30 字，这里是运营常用档） */
export const XHS_STYLES = ['温暖治愈', '专业干货', '轻松幽默', '真实自然', '元气满满']

/** 小红书转化目标（同上，自由文本，这里是常用档） */
export const XHS_CONVERSIONS = ['私信咨询', '到店体验', '加微信', '购买体验课', '关注账号']

export interface PersonaXhsSettings {
  ipType: string
  accountName: string
  style: string
  conversion: string
  localFocus: boolean
}

export interface PersonaSettings {
  role: string
  audiences: string[]
  /** 仅当原 profile.xhs 存在时才有值 —— 页面据此决定要不要显示小红书区块 */
  xhs: PersonaXhsSettings | null
}

function obj(v: unknown): Record<string, unknown> {
  return v && typeof v === 'object' && !Array.isArray(v) ? (v as Record<string, unknown>) : {}
}

function str(v: unknown, fallback = ''): string {
  return typeof v === 'string' ? v : v === null || v === undefined ? fallback : String(v)
}

function strList(v: unknown): string[] {
  if (!Array.isArray(v)) return []
  return v.map((x) => str(x).trim()).filter(Boolean)
}

/** 读 profile.persona / profile.xhs（字段缺失全部给空值，不伪造） */
export function personaFromProfile(p: MyProfile | null): PersonaSettings {
  const raw = obj(p?.profile)
  const persona = obj(raw.persona)
  const xhs = obj(raw.xhs)
  const hasXhs = Object.keys(xhs).length > 0

  return {
    role: str(persona.role).trim(),
    audiences: strList(persona.audiences),
    xhs: hasXhs
      ? {
          ipType: str(xhs.ipType).trim(),
          accountName: str(xhs.accountName).trim(),
          style: str(xhs.style).trim(),
          conversion: str(xhs.conversion).trim(),
          localFocus: xhs.localFocus === true
        }
      : null
  }
}

/**
 * 只提交本页字段。
 *
 * 关键点：后端 `array_merge($user->profile, $patch['profile'])` 是**整键替换**，
 * profile.xhs 整块被替换。所以本页没编辑的 xhs.role / xhs.audiences 必须原样带回去，
 * 否则用户只改了一下账号名，小红书人设就被抹掉了。
 */
export function buildPersonaPatch(
  settings: PersonaSettings,
  origin: MyProfile | null
): MyProfilePatch {
  const raw = obj(origin?.profile)
  const originPersona = obj(raw.persona)
  const originXhs = obj(raw.xhs)

  const persona: Record<string, unknown> = {
    ...originPersona,
    role: settings.role.trim(),
    audiences: settings.audiences
  }
  const profile: Record<string, unknown> = { persona }

  if (settings.xhs) {
    const xhs: Record<string, unknown> = { ...originXhs }
    // ipType 后端是 in: 枚举，空串会被判不合法 → 没选就不带这个键
    if (settings.xhs.ipType) xhs.ipType = settings.xhs.ipType
    xhs.accountName = settings.xhs.accountName.trim()
    xhs.style = settings.xhs.style.trim()
    xhs.conversion = settings.xhs.conversion.trim()
    xhs.localFocus = settings.xhs.localFocus
    profile.xhs = xhs
  }

  return { profile }
}
