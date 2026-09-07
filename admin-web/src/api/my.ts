/**
 * 个人中心 /「我的资料」统一数据层。
 * - 后端模式：资料（手机/头像/性别/年龄/年限/专业/发圈人设/小红书人设）按账号存服务端，多设备共用
 * - 演示模式：回退 localStorage
 */
import { ref } from 'vue'
import { USE_BACKEND, apiGet, apiPut, apiPost, apiDelete } from './backend'

export interface MomentsPersonaPrefs {
  role: string
  audiences: string[]
}

export interface XhsPersonaPrefs {
  ipType: '个人IP' | '门店IP'
  accountName: string
  role: string
  audiences: string[]
  style: string
  conversion: string
  localFocus: boolean
}

/** 用户自助资料（个人中心维护，营销工具自动取用） */
export interface MyProfile {
  name: string
  phone: string
  avatar: string
  email: string
  role: string
  venues: string[]
  gender: string
  age: string
  years: string
  specialties: string[]
  persona: MomentsPersonaPrefs
  xhs: XhsPersonaPrefs
}

export interface MarketingHistoryItem {
  id: number
  platform: string
  title: string
  content: string
  reply: string
  source: string
  createdAt: string
}

export const MY_PROFILE_DEFAULTS = {
  phone: '',
  avatar: '',
  gender: '女',
  age: '',
  years: '',
  specialties: ['瑜伽', '普拉提'],
  persona: { role: '全职老师', audiences: ['上班族', '想改善体态的人'] },
  xhs: {
    ipType: '个人IP' as const,
    accountName: '',
    role: '全职老师',
    audiences: ['零基础女性', '久坐肩颈人群'],
    style: '真实接地气',
    conversion: '评论区留言',
    localFocus: true
  }
}

/** 完整默认资料（组件初始渲染兜底，fetchMyProfile 后覆盖） */
export function defaultMyProfile(): MyProfile {
  return {
    name: '',
    email: '',
    role: '',
    venues: [],
    ...clone(MY_PROFILE_DEFAULTS)
  }
}

function clone<T>(v: T): T {
  return JSON.parse(JSON.stringify(v)) as T
}

// ---------- 演示模式本地回退 ----------
const local = ref<MyProfile | null>(null)

function readLocal(): MyProfile {
  if (local.value) return local.value
  try {
    local.value = JSON.parse(localStorage.getItem('yimai-my-profile') || 'null')
  } catch {
    local.value = null
  }
  return local.value ?? defaultMyProfile()
}

function mergeProfile(base: Partial<MyProfile>): MyProfile {
  const cur = readLocal()
  return {
    ...cur,
    ...base,
    persona: { ...cur.persona, ...(base.persona ?? {}) },
    xhs: { ...cur.xhs, ...(base.xhs ?? {}) }
  }
}

function toPayload(p: MyProfile): Record<string, unknown> {
  return {
    phone: p.phone,
    avatar: p.avatar,
    profile: {
      gender: p.gender,
      age: p.age,
      years: p.years,
      specialties: p.specialties,
      persona: p.persona,
      xhs: p.xhs
    }
  }
}

// ---------- 我的资料 ----------

let cached: MyProfile | null = null

/** 拉取我的资料（模块级缓存；force 重拉） */
export async function fetchMyProfile(force = false): Promise<MyProfile> {
  if (cached && !force) return cached
  if (!USE_BACKEND) {
    cached = readLocal()
    return cached
  }
  try {
    const d = await apiGet<{
      name: string
      phone: string | null
      avatar: string | null
      email: string | null
      role: string
      venues: string[]
      profile: Partial<{
        gender: string
        age: string
        years: string
        specialties: string[]
        persona: Partial<MomentsPersonaPrefs>
        xhs: Partial<XhsPersonaPrefs>
      }> | null
    }>('/my/profile')
    cached = {
      name: d.name ?? '',
      phone: d.phone ?? '',
      avatar: d.avatar ?? '',
      email: d.email ?? '',
      role: d.role ?? '',
      venues: d.venues ?? [],
      gender: d.profile?.gender ?? MY_PROFILE_DEFAULTS.gender,
      age: d.profile?.age ?? '',
      years: d.profile?.years ?? '',
      specialties: d.profile?.specialties?.length
        ? d.profile.specialties
        : clone(MY_PROFILE_DEFAULTS.specialties),
      persona: { ...clone(MY_PROFILE_DEFAULTS.persona), ...(d.profile?.persona ?? {}) },
      xhs: { ...clone(MY_PROFILE_DEFAULTS.xhs), ...(d.profile?.xhs ?? {}) }
    }
    return cached
  } catch {
    cached = readLocal()
    return cached
  }
}

/** 保存我的资料（局部字段合并后整体提交） */
export async function saveMyProfile(patch: Partial<MyProfile>): Promise<MyProfile> {
  const next = mergeProfile({ ...(cached ?? readLocal()), ...patch })
  if (!USE_BACKEND) {
    localStorage.setItem('yimai-my-profile', JSON.stringify(next))
    cached = next
    return next
  }
  await apiPut('/my/profile', toPayload(next))
  cached = next
  return next
}

export async function changeMyPassword(oldPassword: string, newPassword: string): Promise<void> {
  if (!USE_BACKEND) {
    await new Promise((r) => setTimeout(r, 300))
    return
  }
  await apiPut('/my/password', { oldPassword, newPassword })
}

// ---------- 生成历史 ----------

const localHistory = ref<MarketingHistoryItem[] | null>(null)
let localHistoryId = 1

function readLocalHistory(): MarketingHistoryItem[] {
  if (localHistory.value) return localHistory.value
  try {
    localHistory.value = JSON.parse(localStorage.getItem('yimai-marketing-history') || '[]')
  } catch {
    localHistory.value = []
  }
  return localHistory.value!
}

export async function fetchMarketingHistory(platform: string): Promise<MarketingHistoryItem[]> {
  if (USE_BACKEND) {
    try {
      return await apiGet<MarketingHistoryItem[]>('/marketing/history', { platform })
    } catch {
      /* 退回本地 */
    }
  }
  return readLocalHistory().filter((h) => h.platform === platform)
}

/** 生成成功后落历史（静默失败，不打断主流程） */
export async function saveMarketingHistory(item: {
  platform: string
  title: string
  content: string
  reply?: string
  source: string
}): Promise<void> {
  if (USE_BACKEND) {
    try {
      await apiPost('/marketing/history', item)
      return
    } catch {
      /* 落到本地 */
    }
  }
  const d = new Date()
  const p = (n: number) => String(n).padStart(2, '0')
  const list = readLocalHistory()
  list.unshift({
    ...item,
    id: localHistoryId++,
    reply: item.reply ?? '',
    createdAt: `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
  })
  if (list.length > 300) list.length = 300
  localStorage.setItem('yimai-marketing-history', JSON.stringify(list))
}

export async function removeMarketingHistory(id: number): Promise<void> {
  if (USE_BACKEND) {
    try {
      await apiDelete(`/marketing/history/${id}`)
      return
    } catch {
      /* 本地也删一次 */
    }
  }
  const list = readLocalHistory().filter((h) => h.id !== id)
  localStorage.setItem('yimai-marketing-history', JSON.stringify(list))
}
